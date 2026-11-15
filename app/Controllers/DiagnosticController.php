<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\FileStore;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Validator;
use App\Services\ClinicalService;
use App\Services\DiagnosticService;

/** Diagnostics: the booking desk, the reporting queue and the report history. */
final class DiagnosticController
{
    private const EYES = ['right', 'left', 'both'];

    /** Everything the booking and report forms need to fill their pickers. */
    public function options(): array
    {
        return [
            'procedures' => array_map(static fn (array $r) => [
                'id' => (int) $r['id'], 'name' => $r['name'], 'unit_price' => (float) $r['unit_price'], 'is_laser' => (bool) $r['is_laser'],
            ], Database::all("SELECT id, name, unit_price, is_laser FROM price_items WHERE category = 'investigation' AND is_active = 1 ORDER BY name")),
            'laser_types' => $this->lookup('diagnostic_laser_type'),
            'laser_subtypes' => $this->lookup('diagnostic_laser_subtype'),
            'no_charge_reasons' => $this->lookup('no_charge_reason'),
            'doctors' => $this->staff(['ophthalmologist', 'surgeon', 'optometrist']),
            'technicians' => $this->staff(['diagnostic_technician', 'nurse']),
            'edit_hours' => ClinicalService::editHours(),
        ];
    }

    private function lookup(string $list): array
    {
        return array_map(static fn (array $r) => ['id' => (int) $r['id'], 'label' => $r['label']], Database::all(
            'SELECT id, label FROM lookup_values WHERE list_code = :l AND is_active = 1 ORDER BY sort_order, label',
            ['l' => $list]
        ));
    }

    private function staff(array $designations): array
    {
        [$in, $params] = Database::in('d', $designations);
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'name' => Format::fullName($r['first_name'], $r['middle_name'], $r['last_name']),
        ], Database::all(
            "SELECT DISTINCT u.id, u.first_name, u.middle_name, u.last_name
               FROM users u
               JOIN user_designations ud ON ud.user_id = u.id
               JOIN designations g ON g.id = ud.designation_id
              WHERE g.code IN ($in) AND u.status = 'active'
              ORDER BY u.first_name, u.last_name",
            $params
        ));
    }

    // ─── Bookings ────────────────────────────────────────────────────────

    public function index(Request $request): array
    {
        $scope = in_array($request->query('scope'), ['bookings', 'queue', 'history'], true) ? $request->query('scope') : 'bookings';
        $bookings = DiagnosticService::listBookings($scope, [
            'status' => (string) $request->query('status'),
            'price_item_id' => (int) $request->query('price_item_id'),
            'from' => $this->dateBoundary($request->query('from'), false),
            'to' => $this->dateBoundary($request->query('to'), true),
            'search' => (string) $request->query('search'),
        ], Auth::require());
        return ['bookings' => $bookings, 'count' => count($bookings)];
    }

    public function show(Request $request, array $params): array
    {
        return ['booking' => DiagnosticService::show((int) $params['id'], Auth::require())];
    }

    /** One booking per procedure, and per laser type where the procedure is laser therapy. */
    public function store(Request $request): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->int('patient_id', true, 1)
            ->int('referring_user_id', true, 1)
            ->enum('eye', self::EYES)
            ->enum('timing', ['now', 'later'])
            ->validate();
        $patient = Database::one('SELECT id, reg_no FROM patients WHERE id = :id AND status = :s', ['id' => $v['patient_id'], 's' => 'active'])
            ?? throw HttpException::validation(['patient_id' => 'Choose a patient.']);
        $data = $v + [
            'scheduled_for' => $this->scheduledFor($request, $v['timing']),
            'procedures' => $this->procedures($request),
        ];
        $this->assertReferrer((int) $v['referring_user_id']);

        $bookings = Database::transaction(static fn (): array => DiagnosticService::book($data, $ctx));
        Audit::log('diagnostics', 'diagnostic.booked', 'Booked ' . count($bookings) . " diagnostic test(s) for {$patient['reg_no']}",
            'patient', (int) $patient['id'], ['bookings' => array_column($bookings, 'booking_no')]);
        return ['bookings' => $bookings];
    }

    /** The patient and the procedure identify a booking; the rest can be corrected. */
    public function update(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->int('referring_user_id', true, 1)
            ->enum('eye', self::EYES)
            ->enum('timing', ['now', 'later'])
            ->string('remark', false, 1000)
            ->int('row_version', true, 1)
            ->intArray('laser_subtype_ids', false, 0, 10)
            ->validate();
        $this->assertReferrer((int) $v['referring_user_id']);
        $booking = DiagnosticService::find((int) $params['id']);
        $laserTypeId = $this->laserTypeFor($request->all()['laser_type_id'] ?? null, (bool) $booking['is_laser']);

        $updated = DiagnosticService::update((int) $params['id'], [
            'referring_user_id' => $v['referring_user_id'],
            'eye' => $v['eye'],
            'timing' => $v['timing'],
            'scheduled_for' => $this->scheduledFor($request, $v['timing']),
            'remark' => $v['remark'] ?? '',
            'laser_type_id' => $laserTypeId,
            'laser_subtype_ids' => $this->subtypeIds($v['laser_subtype_ids'] ?? [], $laserTypeId),
            'row_version' => $v['row_version'],
        ], $ctx);
        Audit::log('diagnostics', 'diagnostic.booking_updated', "Updated booking {$updated['booking_no']}", 'diagnostic_booking', $updated['booking_no']);
        return ['booking' => $updated];
    }

    /** Check-in on the test day: the bill is raised here, or sent for a no-charge decision. */
    public function checkIn(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->bool('no_charge')
            ->string('no_charge_reason', false, 100)
            ->string('remarks', false, 500)
            ->validate();
        $reason = null;
        if (!empty($v['no_charge'])) {
            $reason = $this->assertReason((string) ($v['no_charge_reason'] ?? ''));
        }
        $booking = DiagnosticService::checkIn((int) $params['id'], $reason, $v['remarks'] ?? null, $ctx);
        Audit::log('diagnostics', 'diagnostic.checked_in',
            "Checked in {$booking['booking_no']}" . ($reason === null ? '' : " — no charge requested ($reason)"),
            'diagnostic_booking', $booking['booking_no']);
        return ['booking' => $booking];
    }

    public function requestCancellation(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('reason', true, 500, 5)->validate();
        $booking = DiagnosticService::requestCancellation((int) $params['id'], $v['reason'], $ctx);
        Audit::log('diagnostics', 'diagnostic.cancellation_requested',
            "Requested cancellation of {$booking['booking_no']} ({$booking['approval_no']})", 'diagnostic_booking', $booking['booking_no']);
        return ['booking' => $booking];
    }

    // ─── Report ──────────────────────────────────────────────────────────

    public function saveReport(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->int('reporting_user_id', true, 1)
            ->string('result', false, 8000)
            ->string('comments', false, 4000)
            ->int('row_version', false, 0)
            ->validate();
        $technicianId = $request->all()['technician_user_id'] ?? null;
        $technicianId = $technicianId === null || $technicianId === '' ? null : (int) $technicianId;
        $this->assertStaff((int) $v['reporting_user_id'], 'reporting_user_id');
        if ($technicianId !== null) {
            $this->assertStaff($technicianId, 'technician_user_id');
        }
        $saved = DiagnosticService::saveReport((int) $params['id'], [
            'reporting_user_id' => $v['reporting_user_id'],
            'technician_user_id' => $technicianId,
            'result' => (string) ($v['result'] ?? ''),
            'comments' => (string) ($v['comments'] ?? ''),
            'row_version' => $v['row_version'] ?? 0,
        ], $ctx);
        $booking = DiagnosticService::show((int) $params['id'], $ctx);
        Audit::log('diagnostics', $saved['created'] ? 'diagnostic.reported' : 'diagnostic.report_corrected',
            ($saved['created'] ? 'Reported ' : 'Corrected the report for ') . $booking['booking_no'],
            'diagnostic_booking', $booking['booking_no']);
        return ['booking' => $booking, 'unbilled' => $booking['bill'] === null];
    }

    public function addAddendum(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->string('reason', true, 300, 3)
            ->string('note', true, 4000, 3)
            ->validate();
        $record = DiagnosticService::resultRecord((int) $params['id']);
        ClinicalService::addAddendum('diagnostic', $record, $v['reason'], $v['note'], $ctx->userId);
        $booking = DiagnosticService::find((int) $params['id']);
        Audit::log('diagnostics', 'diagnostic.addendum_added', "Added an addendum to {$booking['booking_no']}",
            'diagnostic_booking', $booking['booking_no']);
        return ['booking' => DiagnosticService::show((int) $params['id'], $ctx)];
    }

    // ─── Report files ────────────────────────────────────────────────────

    public function uploadFile(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $record = DiagnosticService::resultRecord((int) $params['id']);
        if ((int) Database::value('SELECT COUNT(*) FROM diagnostic_result_files WHERE result_id = :r', ['r' => (int) $record['id']]) >= 10) {
            throw HttpException::validation(['file' => 'A report can have at most 10 files.']);
        }
        $file = FileStore::store($request->file('file'), FileStore::SCANNED_RECORD_TYPES, 20 * 1048576, $ctx->userId);
        Database::execute(
            'INSERT INTO diagnostic_result_files (result_id, file_id) VALUES (:r, :f)',
            ['r' => (int) $record['id'], 'f' => $file['id']]
        );
        Audit::log('diagnostics', 'diagnostic.file_uploaded', 'Attached a file to a diagnostic report',
            'diagnostic_result', (int) $record['id'], ['size_bytes' => $file['size_bytes'], 'type' => $file['mime_type']]);
        return ['files' => DiagnosticService::files((int) $record['id'])];
    }

    /**
     * Takes a file back off a report when it was attached by mistake. The link row stays
     * so the audit trail still shows the file was there; only the report stops listing it.
     */
    public function removeFile(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $record = DiagnosticService::resultRecord((int) $params['id']);
        $removed = Database::execute(
            'UPDATE diagnostic_result_files SET voided_at = UTC_TIMESTAMP(3), voided_by = :by
              WHERE result_id = :r AND file_id = :f AND voided_at IS NULL',
            ['by' => $ctx->userId, 'r' => (int) $record['id'], 'f' => (int) $params['fileId']]
        );
        if ($removed === 0) {
            throw HttpException::notFound('File not found.');
        }
        Audit::log('diagnostics', 'diagnostic.file_removed', 'Removed a file attached to a diagnostic report by mistake',
            'diagnostic_result', (int) $record['id'], ['file_id' => (int) $params['fileId']]);
        return ['files' => DiagnosticService::files((int) $record['id'])];
    }

    public function downloadFile(Request $request, array $params): never
    {
        $row = Database::one(
            'SELECT rf.file_id, r.patient_id FROM diagnostic_result_files rf
               JOIN diagnostic_results r ON r.id = rf.result_id
              WHERE r.booking_id = :b AND rf.file_id = :f AND rf.voided_at IS NULL',
            ['b' => $params['id'], 'f' => $params['fileId']]
        ) ?? throw HttpException::notFound('File not found.');
        $file = FileStore::read((int) $row['file_id']);
        Audit::access((int) $row['patient_id'], 'diagnostics', 'download', 'diagnostic_file', (int) $row['file_id']);
        Response::file($file['content'], $file['mime_type'], $file['name']);
    }

    /** Everything a patient has had done, for the clinical history panel. */
    public function patientHistory(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $patientId = (int) $params['id'];
        $rows = DiagnosticService::listBookings('history', [], $ctx);
        $mine = array_values(array_filter($rows, static fn (array $b) => $b['patient']['id'] === $patientId));
        Audit::access($patientId, 'diagnostics', 'view', 'patient_diagnostics', $patientId);
        return ['bookings' => $mine];
    }

    // ─── Input helpers ───────────────────────────────────────────────────

    /** @return array<int, array{price_item_id:int, remark:?string, laser_type_ids:array, laser_subtype_ids:array}> */
    private function procedures(Request $request): array
    {
        $input = $request->all()['procedures'] ?? null;
        if (!is_array($input) || $input === []) {
            throw HttpException::validation(['procedures' => 'Choose at least one procedure.']);
        }
        if (count($input) > 10) {
            throw HttpException::validation(['procedures' => 'Book at most 10 procedures at a time.']);
        }
        $procedures = [];
        foreach ($input as $i => $row) {
            $itemId = (int) ($row['price_item_id'] ?? 0);
            $item = Database::one("SELECT id, is_laser FROM price_items WHERE id = :id AND is_active = 1 AND category = 'investigation'", ['id' => $itemId])
                ?? throw HttpException::validation(["procedures.$i.price_item_id" => 'This procedure is no longer offered.']);
            $laserTypeIds = [];
            if ((bool) $item['is_laser']) {
                $laserTypeIds = $this->activeLookupIds($row['laser_type_ids'] ?? [], 'diagnostic_laser_type');
                if ($laserTypeIds === []) {
                    throw HttpException::validation(["procedures.$i.laser_type_ids" => 'Choose at least one laser type.']);
                }
            }
            $procedures[] = [
                'price_item_id' => $itemId,
                'remark' => isset($row['remark']) ? mb_substr(trim((string) $row['remark']), 0, 1000) : null,
                'laser_type_ids' => $laserTypeIds,
                'laser_subtype_ids' => $this->activeLookupIds($row['laser_subtype_ids'] ?? [], 'diagnostic_laser_subtype'),
            ];
        }
        return $procedures;
    }

    private function activeLookupIds(mixed $ids, string $list): array
    {
        if (!is_array($ids) || $ids === []) {
            return [];
        }
        [$in, $params] = Database::in('v', array_map('intval', array_slice($ids, 0, 10)));
        return array_map('intval', array_column(Database::all(
            "SELECT id FROM lookup_values WHERE id IN ($in) AND list_code = :l AND is_active = 1",
            $params + ['l' => $list]
        ), 'id'));
    }

    private function laserTypeFor(mixed $value, bool $isLaser): ?int
    {
        if (!$isLaser) {
            return null;
        }
        $ids = $this->activeLookupIds([(int) $value], 'diagnostic_laser_type');
        return $ids[0] ?? throw HttpException::validation(['laser_type_id' => 'Choose the laser type.']);
    }

    private function subtypeIds(array $ids, ?int $laserTypeId): array
    {
        return $laserTypeId === null ? [] : $this->activeLookupIds($ids, 'diagnostic_laser_subtype');
    }

    private function scheduledFor(Request $request, string $timing): string
    {
        if ($timing === 'now') {
            return gmdate('Y-m-d H:i:s');
        }
        $body = $request->all();
        $date = (string) ($body['scheduled_date'] ?? '');
        $time = (string) ($body['scheduled_time'] ?? '' ?: '08:00');
        if (!Format::isDate($date)) {
            throw HttpException::validation(['scheduled_date' => 'Choose the date of the test.']);
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw HttpException::validation(['scheduled_time' => 'Give the time as HH:MM.']);
        }
        return (new \DateTimeImmutable("$date $time:00", new \DateTimeZone(Format::LOCAL_TZ)))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function assertReferrer(int $userId): void
    {
        $this->assertStaff($userId, 'referring_user_id');
    }

    private function assertStaff(int $userId, string $field): void
    {
        if (!Database::value("SELECT 1 FROM users WHERE id = :id AND status = 'active'", ['id' => $userId])) {
            throw HttpException::validation([$field => 'Choose a member of staff.']);
        }
    }

    private function assertReason(string $reason): string
    {
        $label = Database::value(
            "SELECT label FROM lookup_values WHERE list_code = 'no_charge_reason' AND label = :l AND is_active = 1",
            ['l' => $reason]
        ) ?? throw HttpException::validation(['no_charge_reason' => 'Choose why the test is not charged.']);
        return (string) $label;
    }

    /** A local calendar date from the filter bar as a UTC boundary for the query. */
    private function dateBoundary(mixed $value, bool $endExclusive): string
    {
        return is_string($value) && Format::isDate($value) ? Format::localDateToUtc($value, $endExclusive) : '';
    }
}
