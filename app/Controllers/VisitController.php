<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Sequence;
use App\Core\Settings;
use App\Core\Validator;
use App\Services\BillingService;
use App\Services\DiagnosticService;
use App\Services\PatientService;
use App\Services\VisitService;

/** Visits: creation (with its bill), lists and daily summary, detail, cancellation. */
final class VisitController
{
    /** Destinations whose room is chosen at reception, and the administrator's list of rooms for each. */
    private const ROOM_LISTS = ['doctor_room' => 'consultation_room', 'optometry' => 'optometry_room'];

    private const SELECT = 'SELECT v.id, v.visit_no, v.visit_date, v.visit_type, v.sub_type, v.destination, v.room, v.status,
            v.is_emergency, v.is_fast_track, v.fast_track_bill_id, v.fee_exempt, v.patient_age_years, v.created_at, v.row_version,
            p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
            pi.name AS investigation_name,
            b.id AS bill_id, b.bill_no, b.status AS bill_status, b.net_amount, b.is_deferred,
            TRIM(CONCAT_WS(" ", u.first_name, u.last_name)) AS created_by_name
       FROM visits v
       JOIN patients p ON p.id = v.patient_id
       JOIN users u ON u.id = v.created_by
       LEFT JOIN price_items pi ON pi.id = v.investigation_item_id
       LEFT JOIN bills b ON b.id = (SELECT MAX(b2.id) FROM bills b2 WHERE b2.visit_id = v.id)';

    private const STATUSES = ['pending_payment', 'waiting_triage', 'waiting_doctor', 'waiting_investigation', 'waiting_optometry',
        'waiting_ophthalmology', 'in_progress', 'awaiting_services', 'completed', 'cancelled'];

    public function index(Request $request): array
    {
        VisitService::settleWaiting();
        $where = [];
        $params = [];
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            $value = $request->query($key);
            if ($value !== '') {
                if (!Format::isDate($value)) {
                    throw HttpException::validation([$key => 'Use a valid date.']);
                }
                $where[] = "v.visit_date $operator :$key";
                $params[$key] = $value;
            }
        }
        foreach (['visit_type' => ['consultation', 'follow_up'], 'destination' => ['doctor_room', 'triage', 'investigation', 'optometry'], 'status' => self::STATUSES] as $key => $allowed) {
            $value = $request->query($key);
            if (in_array($value, $allowed, true)) {
                $where[] = "v.$key = :$key";
                $params[$key] = $value;
            }
        }
        if (($patientId = (int) $request->query('patient_id', '0')) > 0) {
            $where[] = 'v.patient_id = :patient_id';
            $params['patient_id'] = $patientId;
        }
        $search = $request->query('search');
        if (preg_match('/^\d{8}\/\d+$/', $search)) {
            $where[] = 'v.visit_no = :visit_no';
            $params['visit_no'] = $search;
        } elseif ($condition = PatientService::searchCondition($search)) {
            [$where[], $searchParams] = $condition;
            $params += $searchParams;
        }

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $perPage = $request->perPage(25, 100);
        $page = $request->page();
        $offset = ($page - 1) * $perPage;
        $total = (int) Database::value("SELECT COUNT(*) FROM visits v JOIN patients p ON p.id = v.patient_id$sqlWhere", $params);
        $rows = Database::all(self::SELECT . "$sqlWhere ORDER BY v.id DESC LIMIT $perPage OFFSET $offset", $params);

        return ['visits' => array_map([$this, 'present'], $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** Counts by status for one day (today by default). */
    public function summary(Request $request): array
    {
        $date = $request->query('date') ?: Format::localToday();
        if (!Format::isDate($date)) {
            throw HttpException::validation(['date' => 'Use a valid date.']);
        }
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (Database::all('SELECT status, COUNT(*) AS c FROM visits WHERE visit_date = :d GROUP BY status', ['d' => $date]) as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }
        return ['date' => $date, 'counts' => $counts, 'total' => array_sum($counts)];
    }

    /** Choices for the New Visit form. */
    public function options(): array
    {
        return [
            'today' => Format::localToday(),
            'rooms' => array_column(Database::all('SELECT value FROM lookup_values WHERE list_code = :l AND is_active = 1 ORDER BY sort_order', ['l' => 'consultation_room']), 'value'),
            'optometry_rooms' => array_column(Database::all('SELECT value FROM lookup_values WHERE list_code = :l AND is_active = 1 ORDER BY sort_order', ['l' => 'optometry_room']), 'value'),
            'investigations' => array_map(static fn (array $r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'price' => (float) $r['unit_price']], Database::all(
                "SELECT id, name, unit_price FROM price_items WHERE category = 'investigation' AND is_active = 1 ORDER BY name"
            )),
            'doctors' => array_map(static fn (array $r) => ['id' => (int) $r['id'], 'name' => Format::fullName($r['first_name'], $r['middle_name'], $r['last_name'])], Database::all(
                "SELECT DISTINCT u.id, u.first_name, u.middle_name, u.last_name FROM users u
                   JOIN user_designations ud ON ud.user_id = u.id JOIN designations g ON g.id = ud.designation_id
                  WHERE g.code IN ('ophthalmologist', 'surgeon', 'optometrist') AND u.status = 'active' ORDER BY u.first_name, u.last_name"
            )),
            'can_emergency' => Auth::require()->can('visits.emergency'),
            'free_follow_up_days' => Settings::int('visits.revisit_exemption_days', 14),
        ];
    }

    /** Fee preview before saving: amount and whether a free follow-up applies. */
    public function quote(Request $request): array
    {
        $d = $this->validate($request->all() + $this->queryInput($request), true);
        $this->activePatient($d['patient_id']);
        return ['quote' => VisitService::quote($d['patient_id'], $d['visit_type'], $d['sub_type'], $d['destination'], $d['investigation_item_id'], $d['fast_track'])];
    }

    /** Cashier: what moving this visit to fast track would cost now. */
    public function fastTrackQuote(Request $request, array $params): array
    {
        return ['quote' => VisitService::fastTrackQuote($params['id'])];
    }

    /** Cashier: raise the fast-track charge; the visit moves up once it is paid. */
    public function fastTrack(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $result = VisitService::requestFastTrack($params['id'], $ctx->userId);
        $visit = $this->find($params['id']);
        Audit::log('cashier', 'visit.fast_track', $result['activated']
            ? "Moved visit {$visit['visit_no']} to fast track (nothing to pay)"
            : "Fast track for visit {$visit['visit_no']}: TZS " . number_format($result['balance']) . " on bill {$result['bill_no']}",
            'visit', $visit['visit_no'], ['bill' => $result['bill_no'], 'balance' => $result['balance'], 'charged' => $result['charged']]);
        return ['result' => $result, 'visit' => $visit];
    }

    public function store(Request $request): array
    {
        $ctx = Auth::require();
        $d = $this->validate($request->all(), false);
        $patient = $this->activePatient($d['patient_id']);
        $today = Format::localToday();

        if (empty($d['confirm_open_visit'])) {
            $open = Database::one(
                "SELECT visit_no FROM visits WHERE patient_id = :p AND visit_date = :d AND status NOT IN ('completed', 'cancelled') ORDER BY id DESC LIMIT 1",
                ['p' => (int) $patient['id'], 'd' => $today]
            );
            if ($open !== null) {
                throw HttpException::conflict('open_visit_exists', "This patient already has an open visit today ({$open['visit_no']}).");
            }
        }

        [$visitId, $visitNo, $quote] = Database::transaction(function () use ($d, $patient, $ctx, $today): array {
            if ($d['follow_up_id']) {
                $followUp = Database::one('SELECT id, patient_id, status FROM follow_ups WHERE id = :id FOR UPDATE', ['id' => $d['follow_up_id']]);
                if ($followUp === null || (int) $followUp['patient_id'] !== (int) $patient['id']) {
                    throw HttpException::validation(['follow_up_id' => 'This follow-up belongs to another patient.']);
                }
                if ($followUp['status'] !== 'due') {
                    throw HttpException::conflict('follow_up_not_due', 'This follow-up has already been booked or cancelled.');
                }
            }
            $quote = VisitService::quote((int) $patient['id'], $d['visit_type'], $d['sub_type'], $d['destination'], $d['investigation_item_id'], $d['fast_track']);
            $emergency = (bool) $d['is_emergency'];
            $status = ($quote['exempt'] || $emergency) ? VisitService::waitingStatus($d['destination']) : 'pending_payment';
            $key = str_replace('-', '', $today);
            $visitNo = sprintf('%s/%03d', $key, Sequence::next("visit:$key"));
            $dob = (string) Crypto::decrypt($patient['date_of_birth_enc'], 'patients.date_of_birth');

            $visitId = Database::insert(
                'INSERT INTO visits (visit_no, patient_id, visit_date, visit_type, sub_type, destination, room, investigation_item_id,
                                     investigation_eye, referring_doctor_id, investigation_reason_enc, status, patient_age_years,
                                     is_emergency, emergency_reason_enc, is_fast_track, fast_tracked_at, fast_tracked_by,
                                     fee_exempt, exemption_reason, follow_up_id, created_at, created_by)
                 VALUES (:no, :patient, :date, :type, :sub, :dest, :room, :inv, :eye, :doctor, :reason, :status, :age,
                         :emergency, :emergency_reason, :fast, IF(:fast2 = 1, UTC_TIMESTAMP(3), NULL), :fast_by,
                         :exempt, :exempt_reason, :follow_up, UTC_TIMESTAMP(3), :by)',
                [
                    'no' => $visitNo, 'patient' => (int) $patient['id'], 'date' => $today, 'type' => $d['visit_type'], 'sub' => $d['sub_type'],
                    'dest' => $d['destination'], 'room' => $d['room'], 'inv' => $d['investigation_item_id'], 'eye' => $d['investigation_eye'],
                    'doctor' => $d['referring_doctor_id'],
                    'reason' => $d['investigation_reason'] === null ? null : Crypto::encrypt($d['investigation_reason'], 'visits.investigation_reason'),
                    'status' => $status, 'age' => min(255, Format::ageOn($dob, $today)), 'emergency' => $emergency ? 1 : 0,
                    'emergency_reason' => $emergency ? Crypto::encrypt((string) $d['emergency_reason'], 'visits.emergency_reason') : null,
                    'fast' => $d['fast_track'] ? 1 : 0, 'fast2' => $d['fast_track'] ? 1 : 0, 'fast_by' => $d['fast_track'] ? $ctx->userId : null,
                    'exempt' => $quote['exempt'] ? 1 : 0, 'exempt_reason' => $quote['exemption_reason'], 'follow_up' => $d['follow_up_id'],
                    'by' => $ctx->userId,
                ]
            );
            if ($d['follow_up_id']) {
                Database::execute(
                    "UPDATE follow_ups SET status = 'booked', booked_visit_id = :v, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                      WHERE id = :id",
                    ['v' => $visitId, 'by' => $ctx->userId, 'id' => $d['follow_up_id']]
                );
            }
            VisitService::recordStatus($visitId, null, $status, $ctx->userId,
                $emergency ? 'Emergency: sent for treatment before payment' : ($quote['exempt'] ? $quote['exemption_reason'] : 'Waiting for payment'));

            BillingService::createBill(
                (int) $patient['id'], $visitId, $quote['bill_type'], $quote['description'],
                [['price_item_id' => $quote['price_item_id'], 'description' => $quote['description'], 'quantity' => 1, 'unit_price' => $quote['gross_amount']]],
                $quote['exempt'], $quote['exemption_reason'], $emergency, $ctx->userId
            );
            // The test goes straight onto the Diagnostics list as arrived, and its own bill goes to the
            // cashier now, beside the consultation bill, so the patient pays both in one trip.
            if ($d['destination'] === 'investigation') {
                $bookings = DiagnosticService::book([
                    'patient_id' => (int) $patient['id'], 'referring_user_id' => $d['referring_doctor_id'],
                    'eye' => $d['investigation_eye'], 'timing' => 'now', 'scheduled_for' => gmdate('Y-m-d H:i:s'),
                    'procedures' => [['price_item_id' => (int) $d['investigation_item_id'], 'remark' => $d['investigation_reason']]],
                ], $ctx, 'visit', $visitId);
                foreach ($bookings as $booking) {
                    DiagnosticService::checkIn((int) $booking['id'], null, null, $ctx);
                }
            }
            return [$visitId, $visitNo, $quote];
        });

        Audit::log('visits', 'visit.created', "Created visit $visitNo for {$patient['reg_no']}" . ($d['is_emergency'] ? ' (emergency, payment taken later)' : ''), 'visit', $visitNo, [
            'destination' => $d['destination'], 'fee' => $quote['gross_amount'], 'exempt' => $quote['exempt'], 'fast_track' => $d['fast_track'],
        ]);
        return ['visit' => $this->find($visitId)];
    }

    public function show(Request $request, array $params): array
    {
        $visit = $this->find($params['id']);
        $extra = Database::one(
            'SELECT v.investigation_eye, v.investigation_reason_enc, v.emergency_reason_enc, v.exemption_reason, v.cancel_reason_enc, v.cancelled_at,
                    v.completed_at, v.end_reason_enc, v.readmit_count, v.readmitted_at, v.readmit_reason_enc,
                    TRIM(CONCAT_WS(" ", d.first_name, d.last_name)) AS referring_doctor,
                    TRIM(CONCAT_WS(" ", eu.first_name, eu.last_name)) AS ended_by,
                    TRIM(CONCAT_WS(" ", ru.first_name, ru.last_name)) AS readmitted_by
               FROM visits v LEFT JOIN users d ON d.id = v.referring_doctor_id
               LEFT JOIN users eu ON eu.id = v.ended_by LEFT JOIN users ru ON ru.id = v.readmitted_by WHERE v.id = :id',
            ['id' => $params['id']]
        );
        $history = Database::all(
            'SELECT h.from_status, h.to_status, h.changed_at, h.note, TRIM(CONCAT_WS(" ", u.first_name, u.last_name)) AS changed_by
               FROM visit_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.visit_id = :id ORDER BY h.id',
            ['id' => $params['id']]
        );
        Audit::access($visit['patient']['id'], 'visits', 'view', 'visit', $visit['visit_no']);
        return [
            'visit' => $visit + [
                'investigation_eye' => $extra['investigation_eye'],
                'investigation_reason' => Crypto::decrypt($extra['investigation_reason_enc'], 'visits.investigation_reason'),
                'referring_doctor' => $extra['referring_doctor'] ?: null,
                'emergency_reason' => Crypto::decrypt($extra['emergency_reason_enc'], 'visits.emergency_reason'),
                'exemption_reason' => $extra['exemption_reason'],
                'cancel_reason' => Crypto::decrypt($extra['cancel_reason_enc'], 'visits.cancel_reason'),
                'cancelled_at' => Format::utc($extra['cancelled_at']),
                'discharged_at' => $visit['status'] === 'completed' ? Format::utc($extra['completed_at']) : null,
                'ended_by' => $extra['ended_by'] ?: null,
                'end_reason' => Crypto::decrypt($extra['end_reason_enc'], 'visits.end_reason'),
                'readmit_count' => (int) $extra['readmit_count'],
                'readmitted_at' => Format::utc($extra['readmitted_at']),
                'readmitted_by' => $extra['readmitted_by'] ?: null,
                'readmit_reason' => Crypto::decrypt($extra['readmit_reason_enc'], 'visits.readmit_reason'),
                // What is still to be done today, for Reception to see before ending the visit.
                'outstanding' => in_array($visit['status'], ['completed', 'cancelled'], true) ? [] : VisitService::outstanding($visit['id']),
                'can_end' => in_array($visit['status'], VisitService::ENDABLE_STATUSES, true),
                'can_readmit' => in_array($visit['status'], ['completed', 'awaiting_services'], true)
                    && $visit['visit_date'] >= (new \DateTimeImmutable(Format::localToday()))->modify('-' . VisitService::readmitDays() . ' days')->format('Y-m-d'),
                'readmit_days' => VisitService::readmitDays(),
            ],
            'history' => array_map(static fn (array $h) => [
                'from' => $h['from_status'], 'to' => $h['to_status'], 'at' => Format::utc($h['changed_at']),
                'by' => $h['changed_by'] ?: 'System', 'note' => $h['note'],
            ], $history),
        ];
    }

    public function cancel(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('reason', true, 500, 3)->validate();
        $visitNo = Database::transaction(function () use ($params, $v, $ctx): string {
            $visit = Database::one('SELECT id, visit_no FROM visits WHERE id = :id FOR UPDATE', ['id' => $params['id']])
                ?? throw HttpException::notFound('Visit not found.');
            $paid = (int) Database::value(
                "SELECT COUNT(*) FROM bills WHERE visit_id = :v AND status IN ('paid', 'partially_refunded', 'refunded')",
                ['v' => (int) $visit['id']]
            );
            if ($paid > 0) {
                throw HttpException::conflict('refund_required', 'This visit has a paid bill. The cashier must process a refund before the visit can be cancelled.');
            }
            VisitService::cancel((int) $visit['id'], $v['reason'], $ctx->userId);
            return $visit['visit_no'];
        });
        Audit::log('visits', 'visit.cancelled', "Cancelled visit $visitNo", 'visit', $visitNo);
        return ['visit' => $this->find($params['id'])];
    }

    /** Reception discharges an open visit by hand, with a reason (e.g. the patient left). */
    public function end(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('reason', true, 500, 3)->validate();
        [$visitNo, $outstanding] = Database::transaction(function () use ($params, $v, $ctx): array {
            $outstanding = VisitService::end($params['id'], $v['reason'], $ctx->userId);
            return [(string) Database::value('SELECT visit_no FROM visits WHERE id = :id', ['id' => $params['id']]), $outstanding];
        });
        Audit::log('visits', 'visit.ended', "Ended (discharged) visit $visitNo" . ($outstanding ? ' with ' . count($outstanding) . ' service(s) outstanding' : ''),
            'visit', $visitNo, $outstanding ? ['outstanding' => array_column($outstanding, 'kind')] : null);
        return ['visit' => $this->find($params['id'])];
    }

    /** Reception re-admits a discharged visit to Triage, Optometry or the Doctor; that stage's record reopens. */
    public function readmit(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->enum('stage', array_keys(VisitService::READMIT_STAGES))
            ->string('reason', true, 500, 3)
            ->validate();
        [$visitNo, $reopened] = Database::transaction(function () use ($params, $v, $ctx): array {
            $reopened = VisitService::readmit($params['id'], $v['stage'], $v['reason'], $ctx->userId);
            return [(string) Database::value('SELECT visit_no FROM visits WHERE id = :id', ['id' => $params['id']]), $reopened];
        });
        Audit::log('visits', 'visit.readmitted', "Re-admitted visit $visitNo to " . VisitService::READMIT_STAGES[$v['stage']]['label']
            . ($reopened ? ' (record reopened)' : ''), 'visit', $visitNo);
        return ['visit' => $this->find($params['id']), 'reopened' => $reopened];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function validate(array $input, bool $forQuote): array
    {
        $v = Validator::make($input)
            ->int('patient_id', true, 1)
            ->enum('visit_type', ['consultation', 'follow_up'])
            ->enum('sub_type', ['normal', 'fast_track', 'doctor_follow_up', 'surgery_follow_up'])
            ->enum('destination', ['doctor_room', 'triage', 'investigation', 'optometry'])
            ->int('investigation_item_id', false, 1)
            ->bool('fast_track');
        if (!$forQuote) {
            $v->string('room', false, 30)
              ->enum('investigation_eye', ['right', 'left', 'both'], false)
              ->int('referring_doctor_id', false, 1)
              ->string('investigation_reason', false, 500)
              ->bool('is_emergency')
              ->string('emergency_reason', false, 255)
              ->bool('confirm_open_visit')
              ->int('follow_up_id', false, 1);
        }
        $d = $v->validate();

        $errors = [];
        if (!in_array($d['sub_type'], VisitService::SUB_TYPES[$d['visit_type']], true)) {
            $errors['sub_type'] = 'Choose a sub-type that matches the visit type.';
        }
        // A consultation is fast track by its sub-type; a follow-up by the fast-track choice.
        $d['fast_track'] = $d['visit_type'] === 'consultation' ? $d['sub_type'] === 'fast_track' : (bool) $d['fast_track'];
        if ($d['destination'] === 'investigation' && !$d['investigation_item_id']) {
            $errors['investigation_item_id'] = 'Choose the investigation.';
        }
        if (!$forQuote) {
            // The Doctor Room and Optometry each have their own list of rooms.
            if (isset(self::ROOM_LISTS[$d['destination']])) {
                $rooms = array_column(Database::all('SELECT value FROM lookup_values WHERE list_code = :l AND is_active = 1', ['l' => self::ROOM_LISTS[$d['destination']]]), 'value');
                if (!in_array($d['room'], $rooms, true)) {
                    $errors['room'] = $d['destination'] === 'optometry' ? 'Choose an optometry room.' : 'Choose a consultation room.';
                }
            } else {
                $d['room'] = null;
            }
            if ($d['destination'] === 'investigation') {
                if (!$d['investigation_eye']) {
                    $errors['investigation_eye'] = 'Choose which eye.';
                }
                if (!$d['investigation_reason']) {
                    $errors['investigation_reason'] = 'Give the clinical reason for the investigation.';
                }
                if ($d['referring_doctor_id'] && !Database::value("SELECT 1 FROM users WHERE id = :id AND status = 'active'", ['id' => $d['referring_doctor_id']])) {
                    $errors['referring_doctor_id'] = 'Choose an active doctor.';
                }
            } else {
                $d['investigation_item_id'] = $d['investigation_eye'] = $d['referring_doctor_id'] = $d['investigation_reason'] = null;
            }
            if ($d['is_emergency']) {
                if (!Auth::require()->can('visits.emergency')) {
                    throw new HttpException(403, 'forbidden', 'You are not allowed to send patients for treatment before payment.');
                }
                if (in_array($d['destination'], ['investigation', 'optometry'], true)) {
                    $errors['is_emergency'] = 'Emergencies go to Triage or the Doctor Room.';
                }
                if (!$d['emergency_reason']) {
                    $errors['emergency_reason'] = 'Describe the emergency.';
                }
            } else {
                $d['emergency_reason'] = null;
            }
        }
        if ($errors) {
            throw HttpException::validation($errors);
        }
        return $d;
    }

    private function queryInput(Request $request): array
    {
        $input = [];
        foreach (['patient_id', 'visit_type', 'sub_type', 'destination', 'investigation_item_id', 'fast_track'] as $key) {
            if ($request->query($key) !== '') {
                $input[$key] = $request->query($key);
            }
        }
        return $input;
    }

    private function activePatient(int $id): array
    {
        $patient = Database::one('SELECT id, reg_no, status, date_of_birth_enc FROM patients WHERE id = :id', ['id' => $id])
            ?? throw HttpException::validation(['patient_id' => 'Choose a registered patient.']);
        if ($patient['status'] !== 'active') {
            throw HttpException::conflict('patient_inactive', 'This patient record is inactive.');
        }
        return $patient;
    }

    private function find(int $id): array
    {
        $row = Database::one(self::SELECT . ' WHERE v.id = :id', ['id' => $id]) ?? throw HttpException::notFound('Visit not found.');
        return $this->present($row);
    }

    private function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'visit_no' => $r['visit_no'],
            'visit_date' => $r['visit_date'],
            'visit_type' => $r['visit_type'],
            'sub_type' => $r['sub_type'],
            'destination' => $r['destination'],
            'room' => $r['room'],
            'investigation' => $r['investigation_name'],
            'status' => $r['status'],
            'is_emergency' => (bool) $r['is_emergency'],
            'is_fast_track' => (bool) $r['is_fast_track'],
            'fast_track_pending' => $r['fast_track_bill_id'] !== null && !$r['is_fast_track'],
            'fee_exempt' => (bool) $r['fee_exempt'],
            'patient_age_years' => (int) $r['patient_age_years'],
            'created_at' => Format::utc($r['created_at']),
            'created_by' => $r['created_by_name'],
            'row_version' => (int) $r['row_version'],
            'patient' => PatientService::brief($r),
            'bill' => $r['bill_id'] === null ? null : [
                'id' => (int) $r['bill_id'],
                'bill_no' => $r['bill_no'],
                'status' => $r['bill_status'],
                'net_amount' => (float) $r['net_amount'],
                'is_deferred' => (bool) $r['is_deferred'],
            ],
        ];
    }
}
