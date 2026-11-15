<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuthContext;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Sequence;

/**
 * Theatre: one surgical case from booking to discharge.
 *
 * The case status lives on the booking and only moves forward —
 * booked → cleared → in_theatre → post_op → discharged — with cancellation decided in the
 * approval queue. Surgery waits for the bill unless an emergency case is explicitly allowed
 * through. Clinical free text is encrypted; measurements stay plain so they can be reported on.
 */
final class TheatreService
{
    public const OPEN_STATUSES = ['booked', 'cleared', 'in_theatre', 'post_op', 'cancellation_requested'];
    public const TEAM_ROLES = ['surgeon', 'assistant', 'anaesthetist', 'scrub_nurse', 'anaesthesia_nurse', 'runner'];

    private const BOOKING_SELECT = "SELECT b.id, b.booking_no, b.status, b.eye, b.scheduled_for, b.timing, b.total_amount,
                b.patient_id, b.visit_id, b.recommendation_id, b.surgery_item_id, b.surgeon_user_id, b.theatre_room_id,
                b.bill_id, b.bill_deferred, b.defer_reason_enc, b.cancelled_at, b.discharged_at, b.discharge_remarks_enc,
                b.created_at, b.row_version,
                si.name AS surgery_name, si.unit_price AS surgery_price,
                room.label AS theatre_room,
                v.visit_no, v.is_fast_track AS visit_fast_track,
                p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc, p.phone_enc,
                bill.bill_no, bill.status AS bill_status, bill.net_amount AS bill_amount, bill.paid_amount,
                TRIM(CONCAT_WS(' ', su.first_name, su.last_name)) AS surgeon_name,
                TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)) AS created_by_name,
                TRIM(CONCAT_WS(' ', du.first_name, du.last_name)) AS discharged_by_name,
                pre.status AS preop_status, pre.id AS preop_id,
                op.status AS operation_status, op.id AS operation_id, op.started_at, op.ended_at,
                (SELECT COUNT(*) FROM theatre_postop_visits pv WHERE pv.booking_id = b.id) AS postop_visits,
                (SELECT MAX(pv.visit_date) FROM theatre_postop_visits pv WHERE pv.booking_id = b.id) AS last_review_date,
                (SELECT f.due_date FROM theatre_postop_visits pv JOIN follow_ups f ON f.id = pv.follow_up_id
                  WHERE pv.booking_id = b.id ORDER BY pv.visit_number DESC LIMIT 1) AS next_review_date
           FROM theatre_bookings b
           JOIN patients p ON p.id = b.patient_id
           JOIN users su ON su.id = b.surgeon_user_id
           JOIN users cu ON cu.id = b.created_by
           LEFT JOIN users du ON du.id = b.discharged_by
           LEFT JOIN price_items si ON si.id = b.surgery_item_id
           LEFT JOIN lookup_values room ON room.id = b.theatre_room_id
           LEFT JOIN visits v ON v.id = b.visit_id
           LEFT JOIN bills bill ON bill.id = b.bill_id
           LEFT JOIN theatre_preop_assessments pre ON pre.booking_id = b.id
           LEFT JOIN theatre_operations op ON op.booking_id = b.id";

    // ─── Lists ───────────────────────────────────────────────────────────

    /**
     * @param string $scope bookings | preop | theatre | postop | history
     */
    public static function listBookings(string $scope, array $filters): array
    {
        // Pre-op and theatre are worklists: by default only the patients still waiting at that
        // step; "sent" shows those already moved on and still under theatre care.
        $sent = ($filters['stage'] ?? '') === 'sent';
        [$in, $params] = match ($scope) {
            'preop' => Database::in('s', $sent ? ['cleared', 'in_theatre', 'post_op'] : ['booked']),
            'theatre' => Database::in('s', $sent ? ['post_op'] : ['cleared', 'in_theatre']),
            'postop' => Database::in('s', ['post_op']),
            'history' => Database::in('s', ['discharged', 'cancelled']),
            default => Database::in('s', self::OPEN_STATUSES),
        };
        $where = ["b.status IN ($in)"];
        if ($scope === 'bookings' && ($filters['status'] ?? '') !== '' && in_array($filters['status'], self::OPEN_STATUSES, true)) {
            $where = ['b.status = :status'];
            $params = ['status' => $filters['status']];
        }
        if (($filters['surgeon_user_id'] ?? 0) > 0) {
            $where[] = 'b.surgeon_user_id = :surgeon';
            $params['surgeon'] = (int) $filters['surgeon_user_id'];
        }
        if (($filters['surgery_item_id'] ?? 0) > 0) {
            $where[] = 'b.surgery_item_id = :item';
            $params['item'] = (int) $filters['surgery_item_id'];
        }
        if (($filters['payment'] ?? '') === 'paid') {
            $where[] = "bill.status IN ('paid', 'exempted')";
        } elseif (($filters['payment'] ?? '') === 'unpaid') {
            $where[] = "(bill.id IS NULL OR bill.status NOT IN ('paid', 'exempted'))";
        }
        $dateColumn = $scope === 'history' ? 'b.discharged_at' : 'b.scheduled_for';
        if (($filters['from'] ?? '') !== '') {
            $where[] = "$dateColumn >= :from";
            $params['from'] = $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = "$dateColumn < :to";
            $params['to'] = $filters['to'];
        }
        $rows = Database::all(
            self::BOOKING_SELECT . ' WHERE ' . implode(' AND ', $where)
            . ($scope === 'history' ? ' ORDER BY b.discharged_at DESC, b.id DESC' : ' ORDER BY DATE(b.scheduled_for), v.is_emergency DESC, v.is_fast_track DESC, b.scheduled_for, b.id')
            . ' LIMIT 500',
            $params
        );
        $bookings = array_map(static fn (array $r) => self::present($r), $rows);
        foreach ($bookings as &$booking) {
            $booking['procedures'] = self::procedures($booking['id']);
        }
        unset($booking);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return $bookings;
        }
        $needle = mb_strtolower($search);
        return array_values(array_filter($bookings, static fn (array $b) => str_contains(mb_strtolower($b['patient']['full_name']), $needle)
            || str_contains(mb_strtolower($b['patient']['reg_no']), $needle)
            || str_contains(mb_strtolower($b['booking_no']), $needle)));
    }

    public static function find(int $id): array
    {
        return Database::one(self::BOOKING_SELECT . ' WHERE b.id = :id', ['id' => $id])
            ?? throw HttpException::notFound('Booking not found.');
    }

    /** The whole case: booking, biometry, pre-op, the operation and every post-op visit. */
    public static function show(int $id, AuthContext $ctx): array
    {
        $row = self::find($id);
        $booking = self::present($row);
        $booking['procedures'] = self::procedures($id);
        $booking['biometry'] = self::biometry($id);
        $booking['preop'] = self::preop($id, $ctx);
        $booking['operation'] = self::operation($id, $ctx);
        $booking['postop_visits'] = self::postopVisits($id, $ctx);
        $booking['template'] = self::operationTemplate($row['surgery_item_id'] === null ? null : (int) $row['surgery_item_id'],
            array_column($booking['procedures'], 'price_item_id'));
        \App\Core\Audit::access((int) $row['patient_id'], 'theatre', 'view', 'theatre_booking', $row['booking_no']);
        return $booking;
    }

    private static function present(array $r): array
    {
        $paid = in_array($r['bill_status'], ['paid', 'exempted'], true);
        return [
            'id' => (int) $r['id'],
            'booking_no' => $r['booking_no'],
            'status' => $r['status'],
            'eye' => $r['eye'],
            'surgery' => $r['surgery_item_id'] === null ? null : [
                'id' => (int) $r['surgery_item_id'], 'name' => $r['surgery_name'], 'unit_price' => (float) $r['surgery_price'],
            ],
            'surgeon' => ['id' => (int) $r['surgeon_user_id'], 'name' => $r['surgeon_name']],
            'theatre_room' => $r['theatre_room'],
            'theatre_room_id' => $r['theatre_room_id'] === null ? null : (int) $r['theatre_room_id'],
            'scheduled_for' => Format::utc($r['scheduled_for']),
            'timing' => $r['timing'],
            'total_amount' => (float) $r['total_amount'],
            'visit_id' => $r['visit_id'] === null ? null : (int) $r['visit_id'],
            'visit_no' => $r['visit_no'],
            'is_fast_track' => (bool) $r['visit_fast_track'],
            'recommendation_id' => $r['recommendation_id'] === null ? null : (int) $r['recommendation_id'],
            'is_paid' => $paid,
            'bill_deferred' => (bool) $r['bill_deferred'],
            'defer_reason' => Crypto::decrypt($r['defer_reason_enc'], 'theatre_bookings.defer_reason'),
            'may_operate' => $paid || (bool) $r['bill_deferred'],
            'bill' => $r['bill_id'] === null ? null : [
                'id' => (int) $r['bill_id'], 'bill_no' => $r['bill_no'], 'status' => $r['bill_status'],
                'amount' => (float) $r['bill_amount'], 'paid_amount' => (float) $r['paid_amount'],
            ],
            'preop_status' => $r['preop_status'],
            'operation_status' => $r['operation_status'],
            'started_at' => Format::utc($r['started_at']),
            'ended_at' => Format::utc($r['ended_at']),
            'postop_visit_count' => (int) $r['postop_visits'],
            'last_review_date' => $r['last_review_date'],
            'next_review_date' => $r['next_review_date'],
            'cancelled_at' => Format::utc($r['cancelled_at']),
            'discharged_at' => Format::utc($r['discharged_at']),
            'discharged_by' => $r['discharged_by_name'] ?: null,
            'discharge_remarks' => Crypto::decrypt($r['discharge_remarks_enc'], 'theatre_bookings.discharge_remarks'),
            'created_at' => Format::utc($r['created_at']),
            'created_by' => $r['created_by_name'],
            'row_version' => (int) $r['row_version'],
            'patient' => PatientService::brief($r) + ['id' => (int) $r['patient_id'], 'phone' => Crypto::decrypt($r['phone_enc'], 'patients.phone')],
        ];
    }

    /**
     * The starting text for the operation record and the first post-op review: the templates of
     * the booking's surgery, then of its procedures, joined. Medicines are merged (first one wins
     * for a medicine on two templates); the earliest review day is kept. Null when none applies.
     */
    public static function operationTemplate(?int $surgeryItemId, array $procedureItemIds): ?array
    {
        $itemIds = array_values(array_unique(array_filter([$surgeryItemId, ...array_map('intval', $procedureItemIds)])));
        if ($itemIds === []) {
            return null;
        }
        [$in, $params] = Database::in('i', $itemIds);
        $rows = Database::all(
            "SELECT t.id, t.price_item_id, t.operation_notes, t.postop_instructions, t.review_days, pi.name
               FROM operation_templates t JOIN price_items pi ON pi.id = t.price_item_id
              WHERE t.is_active = 1 AND t.price_item_id IN ($in)",
            $params
        );
        if ($rows === []) {
            return null;
        }
        $order = array_flip($itemIds);
        usort($rows, static fn (array $a, array $b) => $order[(int) $a['price_item_id']] <=> $order[(int) $b['price_item_id']]);
        $several = count($rows) > 1;
        $notes = $instructions = $medicines = [];
        $reviewDays = null;
        foreach ($rows as $row) {
            if (trim((string) $row['operation_notes']) !== '') {
                $notes[] = ($several ? "{$row['name']}:\n" : '') . trim($row['operation_notes']);
            }
            if (trim((string) $row['postop_instructions']) !== '') {
                $instructions[] = trim($row['postop_instructions']);
            }
            if ($row['review_days'] !== null) {
                $reviewDays = $reviewDays === null ? (int) $row['review_days'] : min($reviewDays, (int) $row['review_days']);
            }
            foreach (Database::all(
                'SELECT m.drug_id, d.name, m.quantity, m.dose, m.frequency, m.duration FROM operation_template_medicines m
                   JOIN drugs d ON d.id = m.drug_id WHERE m.template_id = :t AND d.is_active = 1 ORDER BY m.sort_order, d.name',
                ['t' => (int) $row['id']]
            ) as $m) {
                $medicines[(int) $m['drug_id']] ??= [
                    'drug_id' => (int) $m['drug_id'], 'name' => $m['name'], 'quantity' => (int) $m['quantity'],
                    'dose' => $m['dose'], 'frequency' => $m['frequency'], 'duration' => $m['duration'],
                ];
            }
        }
        return [
            'names' => array_column($rows, 'name'),
            'operation_notes' => $notes ? implode("\n\n", $notes) : null,
            'postop_instructions' => $instructions ? implode("\n", $instructions) : null,
            'review_days' => $reviewDays,
            'medicines' => array_values($medicines),
        ];
    }

    public static function procedures(int $bookingId): array
    {
        return array_map(static fn (array $r) => [
            'price_item_id' => (int) $r['price_item_id'], 'name' => $r['name'],
            'quantity' => (int) $r['quantity'], 'unit_price' => (float) $r['unit_price'],
        ], Database::all(
            'SELECT tp.price_item_id, tp.quantity, tp.unit_price, pi.name
               FROM theatre_booking_procedures tp JOIN price_items pi ON pi.id = tp.price_item_id
              WHERE tp.booking_id = :b ORDER BY pi.name',
            ['b' => $bookingId]
        ));
    }

    public static function biometry(int $bookingId): ?array
    {
        $r = Database::one('SELECT * FROM theatre_cataract_biometry WHERE booking_id = :b', ['b' => $bookingId]);
        if ($r === null) {
            return null;
        }
        return [
            'iol_model' => $r['iol_model'],
            'iol_power' => $r['iol_power'] === null ? null : (float) $r['iol_power'],
            'a_constant' => $r['a_constant'] === null ? null : (float) $r['a_constant'],
            'steep_k' => $r['steep_k'] === null ? null : (float) $r['steep_k'],
            'flat_k' => $r['flat_k'] === null ? null : (float) $r['flat_k'],
            'axial_length' => $r['axial_length'] === null ? null : (float) $r['axial_length'],
            'corneal_thickness' => $r['corneal_thickness'] === null ? null : (int) $r['corneal_thickness'],
            'acd' => $r['acd'] === null ? null : (float) $r['acd'],
            'lens_thickness' => $r['lens_thickness'] === null ? null : (float) $r['lens_thickness'],
            'white_to_white' => $r['white_to_white'] === null ? null : (float) $r['white_to_white'],
            'remarks' => Crypto::decrypt($r['remarks_enc'], 'theatre_cataract_biometry.remarks'),
        ];
    }

    public static function lock(int $id): array
    {
        return Database::one('SELECT * FROM theatre_bookings WHERE id = :id FOR UPDATE', ['id' => $id])
            ?? throw HttpException::notFound('Booking not found.');
    }

    // ─── Booking ─────────────────────────────────────────────────────────

    /** Surgery the doctor recommended that has not been booked into theatre yet. */
    public static function recommendations(): array
    {
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'recommendation_no' => $r['recommendation_no'],
            'patient' => PatientService::brief($r) + ['id' => (int) $r['patient_id']],
            'visit_id' => (int) $r['visit_id'],
            'visit_no' => $r['visit_no'],
            'surgery' => ['id' => (int) $r['price_item_id'], 'name' => $r['name'], 'unit_price' => (float) $r['unit_price']],
            'eye' => $r['eye'],
            'urgency' => $r['urgency'],
            'indication' => Crypto::decrypt($r['indication_enc'], 'surgery_recommendations.indication'),
            'recommended_at' => Format::utc($r['recommended_at']),
            'recommended_by' => $r['recommended_by_name'],
        ], Database::all(
            "SELECT r.id, r.recommendation_no, r.patient_id, r.visit_id, r.price_item_id, r.eye, r.urgency,
                    r.indication_enc, r.recommended_at, pi.name, pi.unit_price, v.visit_no,
                    p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS recommended_by_name
               FROM surgery_recommendations r
               JOIN price_items pi ON pi.id = r.price_item_id
               JOIN visits v ON v.id = r.visit_id
               JOIN patients p ON p.id = r.patient_id
               LEFT JOIN users u ON u.id = r.recommended_by
               LEFT JOIN theatre_bookings b ON b.recommendation_id = r.id
              WHERE r.status = 'recommended' AND b.id IS NULL
              ORDER BY FIELD(r.urgency, 'emergency', 'urgent', 'elective'), r.recommended_at"
        ));
    }

    /**
     * @param array $data patient_id, surgery_item_id, procedures[], eye, surgeon_user_id,
     *                    theatre_room_id, scheduled_for, timing, visit_id, recommendation_id, biometry
     */
    public static function book(array $data, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($data, $ctx): array {
            $key = Format::localDateKey();
            $bookingId = Database::insert(
                'INSERT INTO theatre_bookings (booking_no, patient_id, visit_id, recommendation_id, surgery_item_id, eye,
                                               surgeon_user_id, theatre_room_id, scheduled_for, timing, status, created_at, created_by)
                 VALUES (:no, :patient, :visit, :recommendation, :surgery, :eye, :surgeon, :room, :scheduled, :timing, :status,
                         UTC_TIMESTAMP(3), :by)',
                [
                    'no' => sprintf('THTR-%s-%03d', $key, Sequence::next("theatre:$key")),
                    'patient' => $data['patient_id'], 'visit' => $data['visit_id'] ?? null,
                    'recommendation' => $data['recommendation_id'] ?? null, 'surgery' => $data['surgery_item_id'] ?? null,
                    'eye' => $data['eye'], 'surgeon' => $data['surgeon_user_id'], 'room' => $data['theatre_room_id'] ?? null,
                    'scheduled' => $data['scheduled_for'], 'timing' => $data['timing'], 'status' => 'booked', 'by' => $ctx->userId,
                ]
            );
            self::replaceProcedures($bookingId, $data['procedures'] ?? []);
            self::saveBiometry($bookingId, $data['biometry'] ?? null, $ctx->userId);
            self::recalculateTotal($bookingId);
            if (($data['recommendation_id'] ?? null) !== null) {
                Database::execute(
                    "UPDATE surgery_recommendations SET status = 'booked', updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1
                      WHERE id = :id AND status = 'recommended'",
                    ['id' => (int) $data['recommendation_id']]
                );
            }
            return self::present(self::find($bookingId)) + ['procedures' => self::procedures($bookingId)];
        });
    }

    public static function update(int $id, array $data, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $data, $ctx): array {
            $booking = self::lock($id);
            if (!in_array($booking['status'], ['booked', 'cleared'], true)) {
                throw HttpException::conflict('booking_state', 'This case can no longer be changed.');
            }
            if ((int) $booking['row_version'] !== (int) $data['row_version']) {
                throw HttpException::conflict('stale', 'Someone else changed this booking. Reload and try again.');
            }
            Database::execute(
                'UPDATE theatre_bookings SET surgery_item_id = :surgery, eye = :eye, surgeon_user_id = :surgeon,
                        theatre_room_id = :room, scheduled_for = :scheduled, timing = :timing,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                  WHERE id = :id',
                [
                    'surgery' => $data['surgery_item_id'] ?? null, 'eye' => $data['eye'], 'surgeon' => $data['surgeon_user_id'],
                    'room' => $data['theatre_room_id'] ?? null, 'scheduled' => $data['scheduled_for'], 'timing' => $data['timing'],
                    'by' => $ctx->userId, 'id' => $id,
                ]
            );
            self::replaceProcedures($id, $data['procedures'] ?? []);
            self::saveBiometry($id, $data['biometry'] ?? null, $ctx->userId);
            self::recalculateTotal($id);
            // A bill already raised is kept in step, unless it has been paid.
            if ($booking['bill_id'] !== null) {
                $bill = BillingService::lockBill((int) $booking['bill_id']);
                if ($bill['status'] === 'pending') {
                    Database::execute(
                        "UPDATE bills SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(3), cancelled_by = :by,
                                updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1 WHERE id = :id",
                        ['by' => $ctx->userId, 'by2' => $ctx->userId, 'id' => (int) $bill['id']]
                    );
                    Database::execute('UPDATE theatre_bookings SET bill_id = NULL WHERE id = :id', ['id' => $id]);
                    self::raiseBill($id, $ctx);
                }
            }
            return self::present(self::find($id)) + ['procedures' => self::procedures($id)];
        });
    }

    private static function replaceProcedures(int $bookingId, array $procedures): void
    {
        Database::execute('DELETE FROM theatre_booking_procedures WHERE booking_id = :b', ['b' => $bookingId]);
        foreach ($procedures as $procedure) {
            $item = Database::one(
                "SELECT id, unit_price FROM price_items WHERE id = :id AND is_active = 1 AND category IN ('procedure', 'surgery')",
                ['id' => (int) $procedure['price_item_id']]
            ) ?? throw HttpException::validation(['procedures' => 'A procedure is no longer offered.']);
            Database::execute(
                'INSERT INTO theatre_booking_procedures (booking_id, price_item_id, quantity, unit_price) VALUES (:b, :p, :q, :u)',
                ['b' => $bookingId, 'p' => (int) $item['id'], 'q' => max(1, (int) ($procedure['quantity'] ?? 1)), 'u' => (float) $item['unit_price']]
            );
        }
    }

    private static function saveBiometry(int $bookingId, ?array $biometry, int $userId): void
    {
        if ($biometry === null) {
            return;
        }
        $values = [
            'b' => $bookingId,
            'model' => $biometry['iol_model'] ?? null,
            'power' => $biometry['iol_power'] ?? null,
            'constant' => $biometry['a_constant'] ?? null,
            'steep' => $biometry['steep_k'] ?? null,
            'flat' => $biometry['flat_k'] ?? null,
            'axial' => $biometry['axial_length'] ?? null,
            'cct' => $biometry['corneal_thickness'] ?? null,
            'acd' => $biometry['acd'] ?? null,
            'lt' => $biometry['lens_thickness'] ?? null,
            'wtw' => $biometry['white_to_white'] ?? null,
            'remarks' => ($biometry['remarks'] ?? '') === '' ? null : Crypto::encrypt((string) $biometry['remarks'], 'theatre_cataract_biometry.remarks'),
            'by' => $userId,
        ];
        Database::execute(
            'INSERT INTO theatre_cataract_biometry (booking_id, iol_model, iol_power, a_constant, steep_k, flat_k, axial_length,
                                                    corneal_thickness, acd, lens_thickness, white_to_white, remarks_enc, updated_at, updated_by)
             VALUES (:b, :model, :power, :constant, :steep, :flat, :axial, :cct, :acd, :lt, :wtw, :remarks, UTC_TIMESTAMP(3), :by)
             ON DUPLICATE KEY UPDATE iol_model = VALUES(iol_model), iol_power = VALUES(iol_power), a_constant = VALUES(a_constant),
                    steep_k = VALUES(steep_k), flat_k = VALUES(flat_k), axial_length = VALUES(axial_length),
                    corneal_thickness = VALUES(corneal_thickness), acd = VALUES(acd), lens_thickness = VALUES(lens_thickness),
                    white_to_white = VALUES(white_to_white), remarks_enc = VALUES(remarks_enc),
                    updated_at = UTC_TIMESTAMP(3), updated_by = VALUES(updated_by)',
            $values
        );
    }

    private static function recalculateTotal(int $bookingId): float
    {
        $surgery = (float) Database::value(
            'SELECT COALESCE(pi.unit_price, 0) FROM theatre_bookings b
               LEFT JOIN price_items pi ON pi.id = b.surgery_item_id WHERE b.id = :b',
            ['b' => $bookingId]
        );
        $procedures = (float) Database::value(
            'SELECT COALESCE(SUM(quantity * unit_price), 0) FROM theatre_booking_procedures WHERE booking_id = :b',
            ['b' => $bookingId]
        );
        $total = round($surgery + $procedures, 2);
        Database::execute('UPDATE theatre_bookings SET total_amount = :t WHERE id = :id', ['t' => $total, 'id' => $bookingId]);
        return $total;
    }

    /** Sends the case to the cashier. */
    public static function raiseBill(int $id, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $ctx): array {
            $booking = self::lock($id);
            if ($booking['bill_id'] !== null) {
                throw HttpException::conflict('already_billed', 'This case already has a bill.');
            }
            if (in_array($booking['status'], ['cancelled', 'cancellation_requested'], true)) {
                throw HttpException::conflict('booking_state', 'This case is cancelled.');
            }
            $items = [];
            if ($booking['surgery_item_id'] !== null) {
                $surgery = Database::one('SELECT id, name, unit_price FROM price_items WHERE id = :id', ['id' => (int) $booking['surgery_item_id']]);
                $items[] = ['price_item_id' => (int) $surgery['id'], 'description' => $surgery['name'], 'quantity' => 1, 'unit_price' => (float) $surgery['unit_price']];
            }
            foreach (self::procedures($id) as $procedure) {
                $items[] = [
                    'price_item_id' => $procedure['price_item_id'], 'description' => $procedure['name'],
                    'quantity' => $procedure['quantity'], 'unit_price' => $procedure['unit_price'],
                ];
            }
            if ($items === []) {
                throw HttpException::conflict('nothing_to_bill', 'Choose a surgery or a procedure before raising the bill.');
            }
            $eyeLabel = $booking['eye'] === 'both' ? 'Both eyes' : ucfirst($booking['eye']) . ' eye';
            $billId = BillingService::createBill(
                (int) $booking['patient_id'],
                $booking['visit_id'] === null ? null : (int) $booking['visit_id'],
                'surgery',
                ($items[0]['description'] ?? 'Surgery') . " — $eyeLabel ({$booking['booking_no']})",
                $items, false, null, (bool) $booking['bill_deferred'], $ctx->userId, 'theatre_booking', $id
            );
            Database::execute(
                'UPDATE theatre_bookings SET bill_id = :bill, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                  WHERE id = :id',
                ['bill' => $billId, 'by' => $ctx->userId, 'id' => $id]
            );
            return self::present(self::find($id));
        });
    }

    /** An emergency case may go ahead before the bill is settled; who allowed it is recorded. */
    public static function deferPayment(int $id, string $reason, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $reason, $ctx): array {
            $booking = self::lock($id);
            if (!in_array($booking['status'], ['booked', 'cleared'], true)) {
                throw HttpException::conflict('booking_state', 'This case is past the point where that matters.');
            }
            Database::execute(
                'UPDATE theatre_bookings SET bill_deferred = 1, defer_reason_enc = :reason, deferred_by = :by,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
                  WHERE id = :id',
                [
                    'reason' => Crypto::encrypt($reason, 'theatre_bookings.defer_reason'),
                    'by' => $ctx->userId, 'by2' => $ctx->userId, 'id' => $id,
                ]
            );
            if ($booking['bill_id'] !== null) {
                Database::execute('UPDATE bills SET is_deferred = 1 WHERE id = :id', ['id' => (int) $booking['bill_id']]);
            }
            return self::present(self::find($id));
        });
    }

    public static function onBillSettled(array $bill, string $outcome): void
    {
        if (($bill['source_type'] ?? '') !== 'theatre_booking' || $bill['source_id'] === null || $outcome !== 'cancelled') {
            return;
        }
        Database::execute(
            'UPDATE theatre_bookings SET bill_id = NULL, updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1
              WHERE id = :id AND bill_id = :bill',
            ['id' => (int) $bill['source_id'], 'bill' => (int) $bill['id']]
        );
    }

    // ─── Cancellation ────────────────────────────────────────────────────

    public static function requestCancellation(int $id, string $reason, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $reason, $ctx): array {
            $booking = self::lock($id);
            if (!in_array($booking['status'], ['booked', 'cleared'], true)) {
                throw HttpException::conflict('booking_state', 'A case that has reached theatre cannot be cancelled.');
            }
            $approval = ApprovalService::create(
                'theatre_cancellation', (int) $booking['patient_id'],
                $booking['bill_id'] === null ? null : (int) $booking['bill_id'],
                null, null, null, null, $reason, $ctx->userId, null, null, $id
            );
            Database::execute(
                "UPDATE theatre_bookings SET status = 'cancellation_requested', cancel_approval_id = :a,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id",
                ['a' => $approval['id'], 'by' => $ctx->userId, 'id' => $id]
            );
            return self::present(self::find($id)) + ['approval_no' => $approval['approval_no']];
        });
    }

    public static function onCancellationDecision(array $request, bool $approved, AuthContext $ctx): void
    {
        $bookingId = (int) ($request['theatre_booking_id'] ?? 0);
        if ($bookingId === 0) {
            return;
        }
        $booking = self::lock($bookingId);
        if ($booking['status'] !== 'cancellation_requested') {
            return;
        }
        if (!$approved) {
            $preopStatus = (string) Database::value('SELECT status FROM theatre_preop_assessments WHERE booking_id = :b', ['b' => $bookingId]);
            Database::execute(
                'UPDATE theatre_bookings SET status = :s, cancel_approval_id = NULL, updated_at = UTC_TIMESTAMP(3),
                        updated_by = :by, row_version = row_version + 1 WHERE id = :id',
                ['s' => $preopStatus === 'cleared' ? 'cleared' : 'booked', 'by' => $ctx->userId, 'id' => $bookingId]
            );
            return;
        }
        Database::execute(
            "UPDATE theatre_bookings SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3),
                    updated_by = :by, row_version = row_version + 1 WHERE id = :id",
            ['by' => $ctx->userId, 'id' => $bookingId]
        );
        if ($booking['bill_id'] !== null) {
            $bill = BillingService::lockBill((int) $booking['bill_id']);
            if (in_array($bill['status'], ['pending', 'discount_requested'], true)) {
                Database::execute(
                    "UPDATE bills SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(3), cancelled_by = :by,
                            updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1 WHERE id = :id",
                    ['by' => $ctx->userId, 'by2' => $ctx->userId, 'id' => (int) $bill['id']]
                );
            }
        }
        if ($booking['recommendation_id'] !== null) {
            Database::execute(
                "UPDATE surgery_recommendations SET status = 'cancelled', updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1
                  WHERE id = :id AND status = 'booked'",
                ['id' => (int) $booking['recommendation_id']]
            );
        }
    }

    // ─── Pre-op ──────────────────────────────────────────────────────────

    public static function preop(int $bookingId, AuthContext $ctx): ?array
    {
        $r = Database::one(
            "SELECT a.*, c.code AS icd_code, c.description AS icd_description,
                    TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)) AS created_by_name,
                    TRIM(CONCAT_WS(' ', clu.first_name, clu.last_name)) AS cleared_by_name
               FROM theatre_preop_assessments a
               JOIN users cu ON cu.id = a.created_by
               LEFT JOIN users clu ON clu.id = a.cleared_by
               LEFT JOIN icd10_codes c ON c.id = a.icd10_code_id
              WHERE a.booking_id = :b",
            ['b' => $bookingId]
        );
        if ($r === null) {
            return null;
        }
        return [
            'id' => (int) $r['id'],
            'status' => $r['status'],
            'va_right' => $r['va_right'], 'va_left' => $r['va_left'],
            'iop_right' => $r['iop_right'] === null ? null : (int) $r['iop_right'],
            'iop_left' => $r['iop_left'] === null ? null : (int) $r['iop_left'],
            'bp_systolic' => $r['bp_systolic'] === null ? null : (int) $r['bp_systolic'],
            'bp_diastolic' => $r['bp_diastolic'] === null ? null : (int) $r['bp_diastolic'],
            'blood_sugar' => $r['blood_sugar'] === null ? null : (float) $r['blood_sugar'],
            'fasting_since' => Format::utc($r['fasting_since']),
            'icd10_code_id' => $r['icd10_code_id'] === null ? null : (int) $r['icd10_code_id'],
            'diagnosis' => $r['icd_code'] === null ? null : "{$r['icd_code']} — {$r['icd_description']}",
            'findings' => Crypto::decrypt($r['findings_enc'], 'theatre_preop.findings'),
            'allergies' => Crypto::decrypt($r['allergies_enc'], 'theatre_preop.allergies'),
            'anaesthesia_type' => $r['anaesthesia_type'],
            'retrobulbar_notes' => Crypto::decrypt($r['retrobulbar_notes_enc'], 'theatre_preop.retrobulbar_notes'),
            'consent_given' => (bool) $r['consent_given'],
            'consent_notes' => Crypto::decrypt($r['consent_notes_enc'], 'theatre_preop.consent_notes'),
            'checks' => [
                'identity' => (bool) $r['check_identity'],
                'procedure' => (bool) $r['check_procedure'],
                'site_marked' => (bool) $r['check_site_marked'],
                'consent_signed' => (bool) $r['check_consent_signed'],
                'allergies' => (bool) $r['check_allergies'],
            ],
            'surgeon_note' => Crypto::decrypt($r['surgeon_note_enc'], 'theatre_preop.surgeon_note'),
            'hold_reason' => Crypto::decrypt($r['hold_reason_enc'], 'theatre_preop.hold_reason'),
            'cancel_reason' => Crypto::decrypt($r['cancel_reason_enc'], 'theatre_preop.cancel_reason'),
            'cleared_at' => Format::utc($r['cleared_at']),
            'cleared_by' => $r['cleared_by_name'],
            'created_by' => $r['created_by_name'],
            'row_version' => (int) $r['row_version'],
            'correctable_until' => Format::utc(ClinicalService::correctableUntil(
                ['status' => $r['status'] === 'cleared' ? 'completed' : 'draft', 'completed_at' => $r['completed_at'], 'completed_by' => $r['completed_by']],
                $ctx
            )),
            'addenda' => ClinicalService::addenda('theatre_preop', (int) $r['id']),
        ];
    }

    /** Saves the assessment. $clear also clears the patient for theatre. */
    public static function savePreop(int $bookingId, array $data, bool $clear, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($bookingId, $data, $clear, $ctx): array {
            $booking = self::lock($bookingId);
            if (!in_array($booking['status'], ['booked', 'cleared'], true)) {
                throw HttpException::conflict('booking_state', 'This case is not in pre-op.');
            }
            $existing = Database::one('SELECT * FROM theatre_preop_assessments WHERE booking_id = :b FOR UPDATE', ['b' => $bookingId]);
            if ($existing !== null && $existing['status'] === 'cleared'
                && ClinicalService::correctableUntil(['status' => 'completed', 'completed_at' => $existing['completed_at'], 'completed_by' => $existing['completed_by']], $ctx) === null) {
                throw HttpException::conflict('locked', 'The time for correcting this assessment has passed. Add an addendum instead.');
            }
            if ($clear) {
                self::assertClearable($booking, $data);
            }
            $values = [
                'booking' => $bookingId, 'patient' => (int) $booking['patient_id'],
                'status' => $clear ? 'cleared' : ($existing['status'] ?? 'draft'),
                'va_right' => $data['va_right'], 'va_left' => $data['va_left'],
                'iop_right' => $data['iop_right'], 'iop_left' => $data['iop_left'],
                'bp_sys' => $data['bp_systolic'], 'bp_dia' => $data['bp_diastolic'], 'sugar' => $data['blood_sugar'],
                'fasting' => $data['fasting_since'], 'icd' => $data['icd10_code_id'],
                'findings' => self::enc($data['findings'], 'theatre_preop.findings'),
                'allergies' => self::enc($data['allergies'], 'theatre_preop.allergies'),
                'anaesthesia' => $data['anaesthesia_type'],
                'retro' => self::enc($data['retrobulbar_notes'], 'theatre_preop.retrobulbar_notes'),
                'consent' => !empty($data['consent_given']) ? 1 : 0,
                'consent_notes' => self::enc($data['consent_notes'], 'theatre_preop.consent_notes'),
                'c_identity' => !empty($data['checks']['identity']) ? 1 : 0,
                'c_procedure' => !empty($data['checks']['procedure']) ? 1 : 0,
                'c_site' => !empty($data['checks']['site_marked']) ? 1 : 0,
                'c_consent' => !empty($data['checks']['consent_signed']) ? 1 : 0,
                'c_allergies' => !empty($data['checks']['allergies']) ? 1 : 0,
                'note' => self::enc($data['surgeon_note'], 'theatre_preop.surgeon_note'),
                'by' => $ctx->userId,
            ];
            if ($existing === null) {
                Database::insert(
                    'INSERT INTO theatre_preop_assessments (booking_id, patient_id, status, va_right, va_left, iop_right, iop_left,
                            bp_systolic, bp_diastolic, blood_sugar, fasting_since, icd10_code_id, findings_enc, allergies_enc,
                            anaesthesia_type, retrobulbar_notes_enc, consent_given, consent_notes_enc,
                            check_identity, check_procedure, check_site_marked, check_consent_signed, check_allergies,
                            surgeon_note_enc, created_at, created_by)
                     VALUES (:booking, :patient, :status, :va_right, :va_left, :iop_right, :iop_left, :bp_sys, :bp_dia, :sugar,
                             :fasting, :icd, :findings, :allergies, :anaesthesia, :retro, :consent, :consent_notes,
                             :c_identity, :c_procedure, :c_site, :c_consent, :c_allergies, :note, UTC_TIMESTAMP(3), :by)',
                    $values
                );
            } else {
                if ((int) $existing['row_version'] !== (int) ($data['row_version'] ?? 0)) {
                    throw HttpException::conflict('stale', 'Someone else changed this assessment. Reload and try again.');
                }
                Database::execute(
                    'UPDATE theatre_preop_assessments SET status = :status, va_right = :va_right, va_left = :va_left,
                            iop_right = :iop_right, iop_left = :iop_left, bp_systolic = :bp_sys, bp_diastolic = :bp_dia,
                            blood_sugar = :sugar, fasting_since = :fasting, icd10_code_id = :icd, findings_enc = :findings,
                            allergies_enc = :allergies, anaesthesia_type = :anaesthesia, retrobulbar_notes_enc = :retro,
                            consent_given = :consent, consent_notes_enc = :consent_notes, check_identity = :c_identity,
                            check_procedure = :c_procedure, check_site_marked = :c_site, check_consent_signed = :c_consent,
                            check_allergies = :c_allergies, surgeon_note_enc = :note,
                            updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                      WHERE booking_id = :booking',
                    array_diff_key($values, ['patient' => null])
                );
            }
            if ($clear) {
                Database::execute(
                    "UPDATE theatre_preop_assessments SET cleared_at = UTC_TIMESTAMP(3), cleared_by = :by,
                            completed_at = UTC_TIMESTAMP(3), completed_by = :by2 WHERE booking_id = :b",
                    ['by' => $ctx->userId, 'by2' => $ctx->userId, 'b' => $bookingId]
                );
                Database::execute(
                    "UPDATE theatre_bookings SET status = 'cleared', updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                            row_version = row_version + 1 WHERE id = :id AND status = 'booked'",
                    ['by' => $ctx->userId, 'id' => $bookingId]
                );
            }
            return self::preop($bookingId, $ctx);
        });
    }

    /** Payment, consent, safety checks and cataract biometry all have to line up. */
    private static function assertClearable(array $booking, array $data): void
    {
        $errors = [];
        $paid = $booking['bill_id'] !== null
            && in_array((string) Database::value('SELECT status FROM bills WHERE id = :id', ['id' => (int) $booking['bill_id']]), ['paid', 'exempted'], true);
        if (!$paid && !(bool) $booking['bill_deferred']) {
            $errors['payment'] = 'The bill is not settled. Either take payment, or allow this emergency case through without it.';
        }
        if (empty($data['consent_given'])) {
            $errors['consent_given'] = 'Consent has to be given before the patient can be cleared.';
        }
        if (($data['anaesthesia_type'] ?? null) === null) {
            $errors['anaesthesia_type'] = 'Record which anaesthesia is planned.';
        }
        foreach (['identity' => 'Confirm the patient’s identity.', 'procedure' => 'Confirm the procedure and the eye.',
                  'site_marked' => 'Confirm the site is marked.', 'consent_signed' => 'Confirm the consent form is signed.',
                  'allergies' => 'Confirm allergies have been checked.'] as $check => $message) {
            if (empty($data['checks'][$check])) {
                $errors["checks.$check"] = $message;
            }
        }
        if ($booking['surgery_item_id'] !== null) {
            $isCataract = str_contains(mb_strtolower((string) Database::value('SELECT name FROM price_items WHERE id = :id', ['id' => (int) $booking['surgery_item_id']])), 'cataract');
            if ($isCataract) {
                $biometry = Database::one('SELECT axial_length, steep_k FROM theatre_cataract_biometry WHERE booking_id = :b', ['b' => (int) $booking['id']]);
                if ($biometry === null || ($biometry['axial_length'] === null && $biometry['steep_k'] === null)) {
                    $errors['biometry'] = 'A cataract case needs its biometry (axial length or steep K) before clearance.';
                }
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
    }

    public static function holdPreop(int $bookingId, string $status, string $reason, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($bookingId, $status, $reason, $ctx): array {
            $booking = self::lock($bookingId);
            if (!in_array($booking['status'], ['booked', 'cleared'], true)) {
                throw HttpException::conflict('booking_state', 'This case is not in pre-op.');
            }
            if (Database::value('SELECT 1 FROM theatre_preop_assessments WHERE booking_id = :b', ['b' => $bookingId]) === null) {
                throw HttpException::conflict('no_assessment', 'Record the assessment first.');
            }
            $column = $status === 'on_hold' ? 'hold_reason_enc' : 'cancel_reason_enc';
            Database::execute(
                "UPDATE theatre_preop_assessments SET status = :s, $column = :reason, cleared_at = NULL, cleared_by = NULL,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                  WHERE booking_id = :b",
                [
                    's' => $status,
                    'reason' => Crypto::encrypt($reason, $status === 'on_hold' ? 'theatre_preop.hold_reason' : 'theatre_preop.cancel_reason'),
                    'by' => $ctx->userId, 'b' => $bookingId,
                ]
            );
            if ($booking['status'] === 'cleared') {
                Database::execute(
                    "UPDATE theatre_bookings SET status = 'booked', updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                            row_version = row_version + 1 WHERE id = :id",
                    ['by' => $ctx->userId, 'id' => $bookingId]
                );
            }
            return self::preop($bookingId, $ctx);
        });
    }

    // ─── The operation ───────────────────────────────────────────────────

    public static function operation(int $bookingId, AuthContext $ctx): ?array
    {
        $r = Database::one(
            "SELECT o.*, TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)) AS created_by_name,
                    TRIM(CONCAT_WS(' ', xu.first_name, xu.last_name)) AS completed_by_name,
                    pr.prescription_no
               FROM theatre_operations o
               JOIN users cu ON cu.id = o.created_by
               LEFT JOIN users xu ON xu.id = o.completed_by
               LEFT JOIN prescriptions pr ON pr.id = o.prescription_id
              WHERE o.booking_id = :b",
            ['b' => $bookingId]
        );
        if ($r === null) {
            return null;
        }
        return [
            'id' => (int) $r['id'],
            'status' => $r['status'],
            'anaesthesia_type' => $r['anaesthesia_type'],
            'retrobulbar_notes' => Crypto::decrypt($r['retrobulbar_notes_enc'], 'theatre_operations.retrobulbar_notes'),
            'started_at' => Format::utc($r['started_at']),
            'ended_at' => Format::utc($r['ended_at']),
            'completed_as_planned' => $r['completed_as_planned'] === null ? null : (bool) $r['completed_as_planned'],
            'actual_procedure' => Crypto::decrypt($r['actual_procedure_enc'], 'theatre_operations.actual_procedure'),
            'change_reason' => Crypto::decrypt($r['change_reason_enc'], 'theatre_operations.change_reason'),
            'surgical_notes' => Crypto::decrypt($r['surgical_notes_enc'], 'theatre_operations.surgical_notes'),
            'complications' => Crypto::decrypt($r['complications_enc'], 'theatre_operations.complications'),
            'remarks' => Crypto::decrypt($r['remarks_enc'], 'theatre_operations.remarks'),
            'prescription_no' => $r['prescription_no'],
            'team' => self::team((int) $r['id']),
            'completed_at' => Format::utc($r['completed_at']),
            'completed_by' => $r['completed_by_name'],
            'created_by' => $r['created_by_name'],
            'row_version' => (int) $r['row_version'],
            'correctable_until' => Format::utc(ClinicalService::correctableUntil(
                ['status' => $r['status'], 'completed_at' => $r['completed_at'], 'completed_by' => $r['completed_by']],
                $ctx
            )),
            'addenda' => ClinicalService::addenda('theatre_operation', (int) $r['id']),
        ];
    }

    public static function team(int $operationId): array
    {
        return array_map(static fn (array $r) => [
            'user_id' => (int) $r['user_id'], 'name' => $r['name'], 'role' => $r['role'],
        ], Database::all(
            "SELECT t.user_id, t.role, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS name
               FROM theatre_operation_team t JOIN users u ON u.id = t.user_id
              WHERE t.operation_id = :o ORDER BY FIELD(t.role, 'surgeon','assistant','anaesthetist','scrub_nurse','anaesthesia_nurse','runner')",
            ['o' => $operationId]
        ));
    }

    public static function saveOperation(int $bookingId, array $data, bool $complete, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($bookingId, $data, $complete, $ctx): array {
            $booking = self::lock($bookingId);
            if (!in_array($booking['status'], ['cleared', 'in_theatre', 'post_op'], true)) {
                throw HttpException::conflict('booking_state', 'This case has not been cleared for theatre.');
            }
            $paid = $booking['bill_id'] !== null
                && in_array((string) Database::value('SELECT status FROM bills WHERE id = :id', ['id' => (int) $booking['bill_id']]), ['paid', 'exempted'], true);
            if (!$paid && !(bool) $booking['bill_deferred']) {
                throw HttpException::conflict('payment_required', 'The bill is not settled and this case has not been allowed through as an emergency.');
            }
            $existing = Database::one('SELECT * FROM theatre_operations WHERE booking_id = :b FOR UPDATE', ['b' => $bookingId]);
            if ($existing !== null && $existing['status'] === 'completed'
                && ClinicalService::correctableUntil($existing, $ctx) === null) {
                throw HttpException::conflict('locked', 'The time for correcting this record has passed. Add an addendum instead.');
            }
            if ($complete) {
                self::assertOperationComplete($data);
            }
            $values = [
                'booking' => $bookingId, 'patient' => (int) $booking['patient_id'],
                'status' => $complete ? 'completed' : 'draft',
                'anaesthesia' => $data['anaesthesia_type'],
                'retro' => self::enc($data['retrobulbar_notes'], 'theatre_operations.retrobulbar_notes'),
                'started' => $data['started_at'], 'ended' => $data['ended_at'],
                'planned' => $data['completed_as_planned'] === null ? null : (int) (bool) $data['completed_as_planned'],
                'actual' => self::enc($data['actual_procedure'], 'theatre_operations.actual_procedure'),
                'change' => self::enc($data['change_reason'], 'theatre_operations.change_reason'),
                'notes' => self::enc($data['surgical_notes'], 'theatre_operations.surgical_notes'),
                'complications' => self::enc($data['complications'], 'theatre_operations.complications'),
                'remarks' => self::enc($data['remarks'], 'theatre_operations.remarks'),
                'by' => $ctx->userId,
            ];
            if ($existing === null) {
                $operationId = Database::insert(
                    'INSERT INTO theatre_operations (booking_id, patient_id, status, anaesthesia_type, retrobulbar_notes_enc,
                            started_at, ended_at, completed_as_planned, actual_procedure_enc, change_reason_enc,
                            surgical_notes_enc, complications_enc, remarks_enc, created_at, created_by)
                     VALUES (:booking, :patient, :status, :anaesthesia, :retro, :started, :ended, :planned, :actual, :change,
                             :notes, :complications, :remarks, UTC_TIMESTAMP(3), :by)',
                    $values
                );
            } else {
                if ((int) $existing['row_version'] !== (int) ($data['row_version'] ?? 0)) {
                    throw HttpException::conflict('stale', 'Someone else changed this record. Reload and try again.');
                }
                $operationId = (int) $existing['id'];
                Database::execute(
                    'UPDATE theatre_operations SET status = :status, anaesthesia_type = :anaesthesia, retrobulbar_notes_enc = :retro,
                            started_at = :started, ended_at = :ended, completed_as_planned = :planned,
                            actual_procedure_enc = :actual, change_reason_enc = :change, surgical_notes_enc = :notes,
                            complications_enc = :complications, remarks_enc = :remarks,
                            updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                      WHERE booking_id = :booking',
                    array_diff_key($values, ['patient' => null])
                );
            }
            self::replaceTeam($operationId, $data['team'] ?? [], (int) $booking['surgeon_user_id']);

            if ($booking['status'] === 'cleared') {
                Database::execute(
                    "UPDATE theatre_bookings SET status = 'in_theatre', updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                            row_version = row_version + 1 WHERE id = :id",
                    ['by' => $ctx->userId, 'id' => $bookingId]
                );
            }
            if ($complete) {
                Database::execute(
                    'UPDATE theatre_operations SET completed_at = UTC_TIMESTAMP(3), completed_by = :by WHERE id = :id',
                    ['by' => $ctx->userId, 'id' => $operationId]
                );
                if (($data['medicines'] ?? []) !== []) {
                    $prescriptionId = self::issuePrescription(
                        'theatre_operation', $operationId, (int) $booking['patient_id'],
                        $booking['visit_id'] === null ? null : (int) $booking['visit_id'],
                        $data['medicines'], $data['pharmacy_instructions'] ?? null, $ctx->userId
                    );
                    Database::execute('UPDATE theatre_operations SET prescription_id = :p WHERE id = :id', ['p' => $prescriptionId, 'id' => $operationId]);
                }
                Database::execute(
                    "UPDATE theatre_bookings SET status = 'post_op', updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                            row_version = row_version + 1 WHERE id = :id AND status IN ('cleared', 'in_theatre')",
                    ['by' => $ctx->userId, 'id' => $bookingId]
                );
            }
            return self::operation($bookingId, $ctx);
        });
    }

    private static function assertOperationComplete(array $data): void
    {
        $errors = [];
        if (($data['anaesthesia_type'] ?? null) === null) {
            $errors['anaesthesia_type'] = 'Record the anaesthesia used.';
        }
        if (($data['started_at'] ?? null) === null) {
            $errors['started_at'] = 'Record when surgery started.';
        }
        if (($data['ended_at'] ?? null) === null) {
            $errors['ended_at'] = 'Record when surgery finished.';
        }
        if ($data['completed_as_planned'] === null) {
            $errors['completed_as_planned'] = 'Say whether the surgery went as planned.';
        } elseif (!$data['completed_as_planned']) {
            if (($data['actual_procedure'] ?? '') === '') {
                $errors['actual_procedure'] = 'Record what was actually done.';
            }
            if (($data['change_reason'] ?? '') === '') {
                $errors['change_reason'] = 'Record why the plan changed.';
            }
        }
        if (($data['surgical_notes'] ?? '') === '') {
            $errors['surgical_notes'] = 'The operation notes are required.';
        }
        if (($data['complications'] ?? '') === '') {
            $errors['complications'] = 'Record any complications, or write that there were none.';
        }
        $roles = array_column($data['team'] ?? [], 'role');
        if (!in_array('surgeon', $roles, true)) {
            $errors['team'] = 'Record who operated.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
    }

    private static function replaceTeam(int $operationId, array $team, int $defaultSurgeon): void
    {
        Database::execute('DELETE FROM theatre_operation_team WHERE operation_id = :o', ['o' => $operationId]);
        $rows = $team;
        if (!in_array('surgeon', array_column($rows, 'role'), true)) {
            $rows[] = ['user_id' => $defaultSurgeon, 'role' => 'surgeon'];
        }
        $seen = [];
        foreach ($rows as $member) {
            $key = $member['role'] . ':' . $member['user_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            Database::execute(
                'INSERT INTO theatre_operation_team (operation_id, user_id, role) VALUES (:o, :u, :r)',
                ['o' => $operationId, 'u' => (int) $member['user_id'], 'r' => $member['role']]
            );
        }
    }

    // ─── Post-op ─────────────────────────────────────────────────────────

    public static function postopVisits(int $bookingId, AuthContext $ctx): array
    {
        $rows = Database::all(
            "SELECT v.*, TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)) AS created_by_name, pr.prescription_no, f.due_date
               FROM theatre_postop_visits v
               JOIN users cu ON cu.id = v.created_by
               LEFT JOIN prescriptions pr ON pr.id = v.prescription_id
               LEFT JOIN follow_ups f ON f.id = v.follow_up_id
              WHERE v.booking_id = :b ORDER BY v.visit_number",
            ['b' => $bookingId]
        );
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'visit_number' => (int) $r['visit_number'],
            'visit_date' => $r['visit_date'],
            'status' => $r['status'],
            'recovery_notes' => Crypto::decrypt($r['recovery_notes_enc'], 'theatre_postop.recovery_notes'),
            'va_right' => $r['va_right'], 'va_left' => $r['va_left'],
            'iop_right' => $r['iop_right'] === null ? null : (int) $r['iop_right'],
            'iop_left' => $r['iop_left'] === null ? null : (int) $r['iop_left'],
            'bp_systolic' => $r['bp_systolic'] === null ? null : (int) $r['bp_systolic'],
            'bp_diastolic' => $r['bp_diastolic'] === null ? null : (int) $r['bp_diastolic'],
            'blood_sugar' => $r['blood_sugar'] === null ? null : (float) $r['blood_sugar'],
            'pain_score' => $r['pain_score'] === null ? null : (int) $r['pain_score'],
            'wound_status' => $r['wound_status'],
            'eye_exam' => Crypto::decrypt($r['eye_exam_enc'], 'theatre_postop.eye_exam'),
            'complications' => Crypto::decrypt($r['complications_enc'], 'theatre_postop.complications'),
            'additional_findings' => Crypto::decrypt($r['additional_findings_enc'], 'theatre_postop.additional_findings'),
            'counselling_type' => $r['counselling_type'],
            'counselling_notes' => Crypto::decrypt($r['counselling_notes_enc'], 'theatre_postop.counselling_notes'),
            'follow_up_notes' => Crypto::decrypt($r['follow_up_notes_enc'], 'theatre_postop.follow_up_notes'),
            'follow_up_date' => $r['due_date'],
            'prescription_no' => $r['prescription_no'],
            'created_by' => $r['created_by_name'],
            'completed_at' => Format::utc($r['completed_at']),
            'row_version' => (int) $r['row_version'],
            'correctable_until' => Format::utc(ClinicalService::correctableUntil($r, $ctx)),
            'addenda' => ClinicalService::addenda('theatre_postop', (int) $r['id']),
        ], $rows);
    }

    /** Records a post-op review. Medicine goes to the pharmacy; a review date becomes a follow-up. */
    public static function savePostopVisit(int $bookingId, ?int $visitId, array $data, bool $complete, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($bookingId, $visitId, $data, $complete, $ctx): array {
            $booking = self::lock($bookingId);
            if ($booking['status'] !== 'post_op') {
                throw HttpException::conflict('booking_state', 'This case is not in post-op care.');
            }
            $existing = $visitId === null ? null
                : Database::one('SELECT * FROM theatre_postop_visits WHERE id = :id AND booking_id = :b FOR UPDATE', ['id' => $visitId, 'b' => $bookingId]);
            if ($existing !== null && $existing['status'] === 'completed' && ClinicalService::correctableUntil($existing, $ctx) === null) {
                throw HttpException::conflict('locked', 'The time for correcting this visit has passed. Add an addendum instead.');
            }
            $values = [
                'booking' => $bookingId, 'patient' => (int) $booking['patient_id'],
                'status' => $complete ? 'completed' : 'draft',
                'date' => $data['visit_date'],
                'recovery' => self::enc($data['recovery_notes'], 'theatre_postop.recovery_notes'),
                'va_right' => $data['va_right'], 'va_left' => $data['va_left'],
                'iop_right' => $data['iop_right'], 'iop_left' => $data['iop_left'],
                'bp_sys' => $data['bp_systolic'], 'bp_dia' => $data['bp_diastolic'], 'sugar' => $data['blood_sugar'],
                'pain' => $data['pain_score'], 'wound' => $data['wound_status'],
                'exam' => self::enc($data['eye_exam'], 'theatre_postop.eye_exam'),
                'complications' => self::enc($data['complications'], 'theatre_postop.complications'),
                'findings' => self::enc($data['additional_findings'], 'theatre_postop.additional_findings'),
                'counselling' => $data['counselling_type'],
                'counselling_notes' => self::enc($data['counselling_notes'], 'theatre_postop.counselling_notes'),
                'fu_notes' => self::enc($data['follow_up_notes'], 'theatre_postop.follow_up_notes'),
                'by' => $ctx->userId,
            ];
            if ($existing === null) {
                $number = (int) Database::value('SELECT COALESCE(MAX(visit_number), 0) + 1 FROM theatre_postop_visits WHERE booking_id = :b', ['b' => $bookingId]);
                $values['number'] = $number;
                $postopId = Database::insert(
                    'INSERT INTO theatre_postop_visits (booking_id, patient_id, visit_number, visit_date, status, recovery_notes_enc,
                            va_right, va_left, iop_right, iop_left, bp_systolic, bp_diastolic, blood_sugar, pain_score, wound_status,
                            eye_exam_enc, complications_enc, additional_findings_enc, counselling_type, counselling_notes_enc,
                            follow_up_notes_enc, created_at, created_by)
                     VALUES (:booking, :patient, :number, :date, :status, :recovery, :va_right, :va_left, :iop_right, :iop_left,
                             :bp_sys, :bp_dia, :sugar, :pain, :wound, :exam, :complications, :findings, :counselling,
                             :counselling_notes, :fu_notes, UTC_TIMESTAMP(3), :by)',
                    $values
                );
            } else {
                if ((int) $existing['row_version'] !== (int) ($data['row_version'] ?? 0)) {
                    throw HttpException::conflict('stale', 'Someone else changed this visit. Reload and try again.');
                }
                $postopId = (int) $existing['id'];
                $values['id'] = $postopId;
                Database::execute(
                    'UPDATE theatre_postop_visits SET status = :status, visit_date = :date, recovery_notes_enc = :recovery,
                            va_right = :va_right, va_left = :va_left, iop_right = :iop_right, iop_left = :iop_left,
                            bp_systolic = :bp_sys, bp_diastolic = :bp_dia, blood_sugar = :sugar, pain_score = :pain,
                            wound_status = :wound, eye_exam_enc = :exam, complications_enc = :complications,
                            additional_findings_enc = :findings, counselling_type = :counselling,
                            counselling_notes_enc = :counselling_notes, follow_up_notes_enc = :fu_notes,
                            updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                      WHERE id = :id',
                    array_diff_key($values, ['booking' => null, 'patient' => null])
                );
            }
            if ($complete) {
                Database::execute(
                    'UPDATE theatre_postop_visits SET completed_at = UTC_TIMESTAMP(3), completed_by = :by WHERE id = :id',
                    ['by' => $ctx->userId, 'id' => $postopId]
                );
                if (($data['medicines'] ?? []) !== []) {
                    $prescriptionId = self::issuePrescription(
                        'theatre_postop', $postopId, (int) $booking['patient_id'],
                        $booking['visit_id'] === null ? null : (int) $booking['visit_id'],
                        $data['medicines'], $data['pharmacy_instructions'] ?? null, $ctx->userId
                    );
                    Database::execute('UPDATE theatre_postop_visits SET prescription_id = :p WHERE id = :id', ['p' => $prescriptionId, 'id' => $postopId]);
                }
                if (($data['follow_up_date'] ?? null) !== null) {
                    $followUpId = self::raiseFollowUp($postopId, (int) $booking['patient_id'],
                        $booking['visit_id'] === null ? null : (int) $booking['visit_id'],
                        (string) $data['follow_up_date'], $data['follow_up_notes'] ?? null, $ctx->userId);
                    Database::execute('UPDATE theatre_postop_visits SET follow_up_id = :f WHERE id = :id', ['f' => $followUpId, 'id' => $postopId]);
                }
            }
            return self::postopVisits($bookingId, $ctx);
        });
    }

    /** Ends surgical care for this case. */
    public static function discharge(int $bookingId, ?string $remarks, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($bookingId, $remarks, $ctx): array {
            $booking = self::lock($bookingId);
            if ($booking['status'] !== 'post_op') {
                throw HttpException::conflict('booking_state', 'Only a case in post-op care can be discharged.');
            }
            if ((int) Database::value('SELECT COUNT(*) FROM theatre_postop_visits WHERE booking_id = :b', ['b' => $bookingId]) === 0) {
                throw HttpException::conflict('no_visit', 'Record at least one post-op review before discharging the patient.');
            }
            Database::execute(
                "UPDATE theatre_bookings SET status = 'discharged', discharged_at = UTC_TIMESTAMP(3), discharged_by = :by,
                        discharge_remarks_enc = :remarks, updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
                  WHERE id = :id",
                [
                    'by' => $ctx->userId, 'by2' => $ctx->userId, 'id' => $bookingId,
                    'remarks' => self::enc($remarks, 'theatre_bookings.discharge_remarks'),
                ]
            );
            return self::present(self::find($bookingId));
        });
    }

    // ─── Shared helpers ──────────────────────────────────────────────────

    private static function enc(?string $value, string $context): ?string
    {
        return ($value === null || $value === '') ? null : Crypto::encrypt($value, $context);
    }

    private static function issuePrescription(string $source, int $recordId, int $patientId, ?int $visitId, array $medicines, ?string $instructions, int $userId): int
    {
        return ClinicalOrders::issueDirect($source, $recordId, $patientId, $visitId, $medicines, $instructions, $userId, $userId);
    }

    /** A review date becomes a real follow-up, so reception can book the visit. */
    private static function raiseFollowUp(int $recordId, int $patientId, ?int $visitId, string $dueDate, ?string $notes, int $userId): int
    {
        $existing = Database::value("SELECT id FROM follow_ups WHERE source = 'theatre_postop' AND record_id = :r", ['r' => $recordId]);
        if ($existing !== null) {
            Database::execute(
                "UPDATE follow_ups SET due_date = :due, notes_enc = :notes, status = 'due', updated_at = UTC_TIMESTAMP(3),
                        updated_by = :by, row_version = row_version + 1 WHERE id = :id",
                ['due' => $dueDate, 'notes' => self::enc($notes, 'follow_ups.notes'), 'by' => $userId, 'id' => (int) $existing]
            );
            return (int) $existing;
        }
        return Database::insert(
            'INSERT INTO follow_ups (patient_id, source_visit_id, source, record_id, due_date, priority, reason, notes_enc,
                                     status, created_at, created_by)
             VALUES (:patient, :visit, :source, :record, :due, :priority, :reason, :notes, :status, UTC_TIMESTAMP(3), :by)',
            [
                'patient' => $patientId, 'visit' => $visitId, 'source' => 'theatre_postop', 'record' => $recordId,
                'due' => $dueDate, 'priority' => 'routine', 'reason' => 'Post-Operative Review',
                'notes' => self::enc($notes, 'follow_ups.notes'), 'status' => 'due', 'by' => $userId,
            ]
        );
    }
}
