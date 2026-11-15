<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Validator;
use App\Services\ClinicalOrders;
use App\Services\ClinicalService;
use App\Services\TheatreService;

/** Theatre: bookings, pre-op clearance, the operation, post-op care and history. */
final class TheatreController
{
    private const EYES = ['right', 'left', 'both'];

    /**
     * The patient's latest biometry from Optometry for each eye, however old (the date is shown),
     * so a new cataract booking starts from it. The booking keeps its own copy.
     */
    public function latestBiometry(Request $request, array $params): array
    {
        $patientId = (int) $params['id'];
        if (!Database::value('SELECT 1 FROM patients WHERE id = :id', ['id' => $patientId])) {
            throw HttpException::notFound('Patient not found.');
        }
        $out = [];
        foreach (['right', 'left'] as $eye) {
            $row = Database::one(
                "SELECT ob.*, a.completed_at, v.visit_no, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS recorded_by
                   FROM optometry_biometry ob
                   JOIN optometry_assessments a ON a.id = ob.assessment_id
                   JOIN visits v ON v.id = a.visit_id
                   LEFT JOIN users u ON u.id = a.completed_by
                  WHERE a.patient_id = :p AND a.status = 'completed' AND ob.eye = :e
                  ORDER BY a.completed_at DESC, a.id DESC LIMIT 1",
                ['p' => $patientId, 'e' => $eye]
            );
            $out[$eye] = $row === null ? null : OptometryController::biometryRow($row) + [
                'recorded_at' => Format::utc($row['completed_at']), 'recorded_by' => $row['recorded_by'], 'visit_no' => $row['visit_no'],
            ];
        }
        Audit::access($patientId, 'theatre', 'view', 'optometry_biometry', $patientId);
        return ['biometry' => $out];
    }

    public function options(): array
    {
        $staff = static function (array $designations): array {
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
        };
        $items = static fn (string $category): array => array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'unit_price' => (float) $r['unit_price'],
        ], Database::all(
            'SELECT id, name, unit_price FROM price_items WHERE is_active = 1 AND category = :c ORDER BY name',
            ['c' => $category]
        ));
        return [
            'surgeries' => $items('surgery'),
            'procedures' => $items('procedure'),
            'surgeons' => $staff(['surgeon', 'ophthalmologist']),
            'anaesthetists' => $staff(['anaesthesia_nurse', 'nurse']),
            'nurses' => $staff(['scrub_nurse', 'nurse', 'theatre_runner']),
            'rooms' => array_map(static fn (array $r) => ['id' => (int) $r['id'], 'label' => $r['label']], Database::all(
                "SELECT id, label FROM lookup_values WHERE list_code = 'theatre_room' AND is_active = 1 ORDER BY sort_order, label"
            )),
            'counselling' => array_column(Database::all(
                "SELECT label FROM lookup_values WHERE list_code = 'postop_counselling' AND is_active = 1 ORDER BY sort_order, label"
            ), 'label'),
            'team_roles' => TheatreService::TEAM_ROLES,
            'edit_hours' => ClinicalService::editHours(),
        ];
    }

    /** Surgery the doctors have recommended but nobody has booked yet. */
    public function recommendations(): array
    {
        return ['recommendations' => TheatreService::recommendations()];
    }

    // ─── Bookings ────────────────────────────────────────────────────────

    public function index(Request $request): array
    {
        $scope = in_array($request->query('scope'), ['bookings', 'preop', 'theatre', 'postop', 'history'], true)
            ? $request->query('scope') : 'bookings';
        $bookings = TheatreService::listBookings($scope, [
            'search' => (string) $request->query('search'),
            'status' => (string) $request->query('status'),
            'surgeon_user_id' => (int) $request->query('surgeon_user_id'),
            'surgery_item_id' => (int) $request->query('surgery_item_id'),
            'payment' => (string) $request->query('payment'),
            'stage' => (string) $request->query('stage'),
            'from' => $this->boundary($request->query('from'), false),
            'to' => $this->boundary($request->query('to'), true),
        ]);
        return ['bookings' => $bookings, 'count' => count($bookings)];
    }

    public function show(Request $request, array $params): array
    {
        return ['booking' => TheatreService::show((int) $params['id'], Auth::require())];
    }

    public function store(Request $request): array
    {
        $ctx = Auth::require();
        $data = $this->bookingInput($request, true);
        $booking = TheatreService::book($data, $ctx);
        Audit::log('theatre', 'theatre.booked', "Booked {$booking['booking_no']} for theatre", 'theatre_booking', $booking['booking_no']);
        return ['booking' => $booking];
    }

    public function update(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $data = $this->bookingInput($request, false);
        $booking = TheatreService::update((int) $params['id'], $data, $ctx);
        Audit::log('theatre', 'theatre.booking_updated', "Updated booking {$booking['booking_no']}", 'theatre_booking', $booking['booking_no']);
        return ['booking' => $booking];
    }

    public function raiseBill(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $booking = TheatreService::raiseBill((int) $params['id'], $ctx);
        Audit::log('theatre', 'theatre.bill_raised',
            "Raised the bill for {$booking['booking_no']} — {$booking['bill']['bill_no']}", 'theatre_booking', $booking['booking_no']);
        return ['booking' => $booking];
    }

    /** Lets an emergency case go ahead before the bill is settled. */
    public function deferPayment(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('reason', true, 500, 5)->validate();
        $booking = TheatreService::deferPayment((int) $params['id'], $v['reason'], $ctx);
        Audit::log('theatre', 'theatre.payment_deferred',
            "Allowed {$booking['booking_no']} to go ahead before payment", 'theatre_booking', $booking['booking_no'],
            ['reason' => 'recorded']);
        return ['booking' => $booking];
    }

    public function requestCancellation(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('reason', true, 500, 5)->validate();
        $booking = TheatreService::requestCancellation((int) $params['id'], $v['reason'], $ctx);
        Audit::log('theatre', 'theatre.cancellation_requested',
            "Requested cancellation of {$booking['booking_no']} ({$booking['approval_no']})", 'theatre_booking', $booking['booking_no']);
        return ['booking' => $booking];
    }

    // ─── Pre-op ──────────────────────────────────────────────────────────

    public function savePreop(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $clear = (bool) ($request->all()['clear'] ?? false);
        $preop = TheatreService::savePreop((int) $params['id'], $this->preopInput($request), $clear, $ctx);
        $booking = TheatreService::find((int) $params['id']);
        Audit::log('theatre', $clear ? 'theatre.preop_cleared' : 'theatre.preop_saved',
            ($clear ? 'Cleared for theatre: ' : 'Recorded the pre-op assessment for ') . $booking['booking_no'],
            'theatre_booking', $booking['booking_no']);
        return ['preop' => $preop, 'booking' => TheatreService::show((int) $params['id'], $ctx)];
    }

    public function holdPreop(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->enum('status', ['on_hold', 'cancelled'])
            ->string('reason', true, 500, 5)
            ->validate();
        $preop = TheatreService::holdPreop((int) $params['id'], $v['status'], $v['reason'], $ctx);
        $booking = TheatreService::find((int) $params['id']);
        Audit::log('theatre', 'theatre.preop_' . $v['status'],
            ($v['status'] === 'on_hold' ? 'Put on hold in pre-op: ' : 'Stopped in pre-op: ') . $booking['booking_no'],
            'theatre_booking', $booking['booking_no']);
        return ['preop' => $preop, 'booking' => TheatreService::show((int) $params['id'], $ctx)];
    }

    // ─── The operation ───────────────────────────────────────────────────

    public function saveOperation(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $complete = (bool) ($request->all()['complete'] ?? false);
        $operation = TheatreService::saveOperation((int) $params['id'], $this->operationInput($request), $complete, $ctx);
        $booking = TheatreService::find((int) $params['id']);
        Audit::log('theatre', $complete ? 'theatre.operation_completed' : 'theatre.operation_saved',
            ($complete ? 'Completed surgery for ' : 'Recorded surgery notes for ') . $booking['booking_no'],
            'theatre_booking', $booking['booking_no']);
        return ['operation' => $operation, 'booking' => TheatreService::show((int) $params['id'], $ctx)];
    }

    // ─── Post-op ─────────────────────────────────────────────────────────

    public function savePostopVisit(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $body = $request->all();
        $complete = (bool) ($body['complete'] ?? false);
        $visitId = isset($body['visit_id']) && $body['visit_id'] !== null && $body['visit_id'] !== '' ? (int) $body['visit_id'] : null;
        $visits = TheatreService::savePostopVisit((int) $params['id'], $visitId, $this->postopInput($request), $complete, $ctx);
        $booking = TheatreService::find((int) $params['id']);
        Audit::log('theatre', 'theatre.postop_recorded', "Recorded a post-op review for {$booking['booking_no']}",
            'theatre_booking', $booking['booking_no']);
        return ['visits' => $visits, 'booking' => TheatreService::show((int) $params['id'], $ctx)];
    }

    public function discharge(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('remarks', false, 1000)->validate();
        $booking = TheatreService::discharge((int) $params['id'], $v['remarks'] ?? null, $ctx);
        Audit::log('theatre', 'theatre.discharged', "Discharged {$booking['booking_no']} from surgical care",
            'theatre_booking', $booking['booking_no']);
        return ['booking' => $booking];
    }

    // ─── Addenda ─────────────────────────────────────────────────────────

    public function addAddendum(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->enum('source', ['theatre_preop', 'theatre_operation', 'theatre_postop'])
            ->int('record_id', true, 1)
            ->string('reason', true, 300, 3)
            ->string('note', true, 4000, 3)
            ->validate();
        $booking = TheatreService::find((int) $params['id']);
        $table = ['theatre_preop' => 'theatre_preop_assessments', 'theatre_operation' => 'theatre_operations',
                  'theatre_postop' => 'theatre_postop_visits'][$v['source']];
        $record = Database::one(
            "SELECT id, patient_id, booking_id FROM $table WHERE id = :id AND booking_id = :b",
            ['id' => $v['record_id'], 'b' => (int) $booking['id']]
        ) ?? throw HttpException::notFound('That record is not part of this case.');
        ClinicalService::addAddendum($v['source'], $record + ['visit_id' => $booking['visit_id']], $v['reason'], $v['note'], $ctx->userId);
        Audit::log('theatre', 'theatre.addendum_added', "Added an addendum to {$booking['booking_no']}",
            'theatre_booking', $booking['booking_no']);
        return ['booking' => TheatreService::show((int) $booking['id'], $ctx)];
    }

    /** Every theatre case for one patient, for the patient history panel. */
    public function patientCases(Request $request, array $params): array
    {
        $patientId = (int) $params['id'];
        $cases = array_values(array_filter(
            array_merge(TheatreService::listBookings('bookings', []), TheatreService::listBookings('history', [])),
            static fn (array $b) => $b['patient']['id'] === $patientId
        ));
        Audit::access($patientId, 'theatre', 'view', 'patient_theatre', $patientId);
        return ['bookings' => $cases];
    }

    // ─── Input ───────────────────────────────────────────────────────────

    private function bookingInput(Request $request, bool $creating): array
    {
        $rules = Validator::make($request->all())
            ->enum('eye', self::EYES)
            ->int('surgeon_user_id', true, 1)
            ->enum('timing', ['now', 'later']);
        if ($creating) {
            $rules->int('patient_id', true, 1);
        } else {
            $rules->int('row_version', true, 1);
        }
        $v = $rules->validate();
        $body = $request->all();

        $surgeryId = $this->priceItem($body['surgery_item_id'] ?? null, 'surgery');
        $procedures = [];
        foreach (is_array($body['procedures'] ?? null) ? $body['procedures'] : [] as $index => $row) {
            $id = $this->priceItem($row['price_item_id'] ?? null, 'procedure');
            if ($id === null) {
                throw HttpException::validation(["procedures.$index" => 'Choose a procedure from the price list.']);
            }
            $procedures[] = ['price_item_id' => $id, 'quantity' => max(1, (int) ($row['quantity'] ?? 1))];
        }
        if ($surgeryId === null && $procedures === []) {
            throw HttpException::validation(['surgery_item_id' => 'Choose a surgery, a procedure, or both.']);
        }
        $this->assertStaff((int) $v['surgeon_user_id'], 'surgeon_user_id');

        $data = [
            'surgery_item_id' => $surgeryId,
            'procedures' => $procedures,
            'eye' => $v['eye'],
            'surgeon_user_id' => (int) $v['surgeon_user_id'],
            'theatre_room_id' => $this->lookup($body['theatre_room_id'] ?? null, 'theatre_room', 'theatre_room_id'),
            'timing' => $v['timing'],
            'scheduled_for' => $this->scheduledFor($request, $v['timing']),
            'biometry' => $this->biometryInput($body['biometry'] ?? null),
        ];
        if ($creating) {
            $patient = Database::one("SELECT id FROM patients WHERE id = :id AND status = 'active'", ['id' => $v['patient_id']])
                ?? throw HttpException::validation(['patient_id' => 'Choose a patient.']);
            $data['patient_id'] = (int) $patient['id'];
            $data['visit_id'] = $this->visitId($body['visit_id'] ?? null, (int) $patient['id']);
            $data['recommendation_id'] = $this->recommendationId($body['recommendation_id'] ?? null, (int) $patient['id']);
        } else {
            $data['row_version'] = (int) $v['row_version'];
        }
        return $data;
    }

    private function preopInput(Request $request): array
    {
        $body = $request->all();
        $v = Validator::make($body)
            ->string('va_right', false, 8)
            ->string('va_left', false, 8)
            ->int('iop_right', false, 0, 80)
            ->int('iop_left', false, 0, 80)
            ->int('bp_systolic', false, 50, 300)
            ->int('bp_diastolic', false, 30, 200)
            ->decimal('blood_sugar', false, 0, 60)
            ->string('findings', false, 4000)
            ->string('allergies', false, 1000)
            ->enum('anaesthesia_type', ['local', 'general'], false)
            ->string('retrobulbar_notes', false, 1000)
            ->bool('consent_given')
            ->string('consent_notes', false, 1000)
            ->string('surgeon_note', false, 1000)
            ->int('icd10_code_id', false, 1)
            ->int('row_version', false, 0)
            ->validate();
        $checks = is_array($body['checks'] ?? null) ? $body['checks'] : [];
        return $v + [
            'fasting_since' => $this->dateTime($body['fasting_since'] ?? null, 'fasting_since'),
            'checks' => [
                'identity' => !empty($checks['identity']),
                'procedure' => !empty($checks['procedure']),
                'site_marked' => !empty($checks['site_marked']),
                'consent_signed' => !empty($checks['consent_signed']),
                'allergies' => !empty($checks['allergies']),
            ],
            'icd10_code_id' => isset($v['icd10_code_id']) ? $this->icdCode((int) $v['icd10_code_id']) : null,
        ];
    }

    private function operationInput(Request $request): array
    {
        $body = $request->all();
        $v = Validator::make($body)
            ->enum('anaesthesia_type', ['local', 'general'], false)
            ->string('retrobulbar_notes', false, 1000)
            ->bool('completed_as_planned')
            ->string('actual_procedure', false, 1000)
            ->string('change_reason', false, 1000)
            ->string('surgical_notes', false, 8000)
            ->string('complications', false, 4000)
            ->string('remarks', false, 4000)
            ->string('pharmacy_instructions', false, 1000)
            ->int('row_version', false, 0)
            ->validate();
        $team = [];
        foreach (is_array($body['team'] ?? null) ? $body['team'] : [] as $index => $member) {
            $role = (string) ($member['role'] ?? '');
            if (!in_array($role, TheatreService::TEAM_ROLES, true)) {
                throw HttpException::validation(["team.$index.role" => 'Unknown role.']);
            }
            $userId = (int) ($member['user_id'] ?? 0);
            if ($userId === 0) {
                continue;
            }
            $this->assertStaff($userId, "team.$index.user_id");
            $team[] = ['user_id' => $userId, 'role' => $role];
        }
        return $v + [
            'completed_as_planned' => array_key_exists('completed_as_planned', $body) && $body['completed_as_planned'] !== null
                ? (bool) $body['completed_as_planned'] : null,
            'started_at' => $this->dateTime($body['started_at'] ?? null, 'started_at'),
            'ended_at' => $this->dateTime($body['ended_at'] ?? null, 'ended_at'),
            'team' => $team,
            'medicines' => $this->medicines($body['medicines'] ?? null),
        ];
    }

    private function postopInput(Request $request): array
    {
        $body = $request->all();
        $v = Validator::make($body)
            ->string('recovery_notes', false, 4000)
            ->string('va_right', false, 8)
            ->string('va_left', false, 8)
            ->int('iop_right', false, 0, 80)
            ->int('iop_left', false, 0, 80)
            ->int('bp_systolic', false, 50, 300)
            ->int('bp_diastolic', false, 30, 200)
            ->decimal('blood_sugar', false, 0, 60)
            ->int('pain_score', false, 0, 10)
            ->enum('wound_status', ['clean', 'inflamed', 'leaking', 'other'], false)
            ->string('eye_exam', false, 4000)
            ->string('complications', false, 4000)
            ->string('additional_findings', false, 4000)
            ->string('counselling_type', false, 60)
            ->string('counselling_notes', false, 4000)
            ->string('follow_up_notes', false, 1000)
            ->string('pharmacy_instructions', false, 1000)
            ->int('row_version', false, 0)
            ->validate();
        $visitDate = (string) ($body['visit_date'] ?? '');
        if (!Format::isDate($visitDate)) {
            throw HttpException::validation(['visit_date' => 'Give the date of the review.']);
        }
        $followUp = $body['follow_up_date'] ?? null;
        if ($followUp !== null && $followUp !== '' && !Format::isDate((string) $followUp)) {
            throw HttpException::validation(['follow_up_date' => 'Give the review date as YYYY-MM-DD.']);
        }
        return $v + [
            'visit_date' => $visitDate,
            'follow_up_date' => ($followUp === null || $followUp === '') ? null : (string) $followUp,
            'medicines' => $this->medicines($body['medicines'] ?? null),
        ];
    }

    private function medicines(mixed $input): array
    {
        return ClinicalOrders::directMedicines($input);
    }

    private function biometryInput(mixed $input): ?array
    {
        if (!is_array($input)) {
            return null;
        }
        $number = static fn (string $key) => ($input[$key] ?? '') === '' || !is_numeric($input[$key]) ? null : (float) $input[$key];
        return [
            'iol_model' => isset($input['iol_model']) ? mb_substr((string) $input['iol_model'], 0, 60) : null,
            'iol_power' => $number('iol_power'),
            'a_constant' => $number('a_constant'),
            'steep_k' => $number('steep_k'),
            'flat_k' => $number('flat_k'),
            'axial_length' => $number('axial_length'),
            'corneal_thickness' => $number('corneal_thickness') === null ? null : (int) $number('corneal_thickness'),
            'acd' => $number('acd'),
            'lens_thickness' => $number('lens_thickness'),
            'white_to_white' => $number('white_to_white'),
            'remarks' => isset($input['remarks']) ? mb_substr((string) $input['remarks'], 0, 500) : null,
        ];
    }

    private function priceItem(mixed $value, string $category): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }
        $id = Database::value(
            'SELECT id FROM price_items WHERE id = :id AND is_active = 1 AND category = :c',
            ['id' => (int) $value, 'c' => $category]
        );
        return $id === null ? throw HttpException::validation([$category === 'surgery' ? 'surgery_item_id' : 'procedures' => 'This is no longer on the price list.']) : (int) $id;
    }

    private function lookup(mixed $value, string $list, string $field): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }
        $id = Database::value(
            'SELECT id FROM lookup_values WHERE id = :id AND list_code = :l AND is_active = 1',
            ['id' => (int) $value, 'l' => $list]
        );
        return $id === null ? throw HttpException::validation([$field => 'Choose one from the list.']) : (int) $id;
    }

    private function icdCode(int $id): ?int
    {
        return Database::value('SELECT id FROM icd10_codes WHERE id = :id AND is_active = 1', ['id' => $id]) === null
            ? throw HttpException::validation(['icd10_code_id' => 'Choose a diagnosis from the list.'])
            : $id;
    }

    private function visitId(mixed $value, int $patientId): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }
        return Database::value('SELECT id FROM visits WHERE id = :id AND patient_id = :p', ['id' => (int) $value, 'p' => $patientId]) === null
            ? throw HttpException::validation(['visit_id' => 'That visit belongs to another patient.'])
            : (int) $value;
    }

    private function recommendationId(mixed $value, int $patientId): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }
        $row = Database::one(
            "SELECT id FROM surgery_recommendations WHERE id = :id AND patient_id = :p AND status = 'recommended'",
            ['id' => (int) $value, 'p' => $patientId]
        ) ?? throw HttpException::validation(['recommendation_id' => 'That recommendation is not open for this patient.']);
        return (int) $row['id'];
    }

    private function assertStaff(int $userId, string $field): void
    {
        if (!Database::value("SELECT 1 FROM users WHERE id = :id AND status = 'active'", ['id' => $userId])) {
            throw HttpException::validation([$field => 'Choose a member of staff.']);
        }
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
            throw HttpException::validation(['scheduled_date' => 'Choose the date of surgery.']);
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw HttpException::validation(['scheduled_time' => 'Give the time as HH:MM.']);
        }
        return (new \DateTimeImmutable("$date $time:00", new \DateTimeZone(Format::LOCAL_TZ)))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function dateTime(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = str_replace('T', ' ', (string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2} ([01]\d|2[0-3]):[0-5]\d(:\d{2})?$/', $text)) {
            throw HttpException::validation([$field => 'Give the date and time as YYYY-MM-DD HH:MM.']);
        }
        return (new \DateTimeImmutable($text, new \DateTimeZone(Format::LOCAL_TZ)))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function boundary(mixed $value, bool $endExclusive): string
    {
        return is_string($value) && Format::isDate($value) ? Format::localDateToUtc($value, $endExclusive) : '';
    }
}
