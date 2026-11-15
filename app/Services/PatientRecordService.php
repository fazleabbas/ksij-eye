<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\AuthContext;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;

/**
 * The rest of a patient's record for the clinical history views: diagnostic tests and their
 * results, theatre cases, medicines handed over by the pharmacy and spectacle orders.
 *
 * Results and theatre notes are clinical detail: only holders of PERMISSION (doctors and
 * administrators) see them; everyone else who can open patient history sees the status.
 * A doctor is told when a result for a test they referred is ready, until they open it.
 */
final class PatientRecordService
{
    public const PERMISSION = 'ophthalmology.results.view';

    // ─── Diagnostic tests ────────────────────────────────────────────────

    /** Every test the patient has had or is booked for, from any source; results are opened one by one. */
    public static function diagnostics(int $patientId): array
    {
        return array_map(static fn (array $r) => [
            'booking_id' => (int) $r['id'],
            'booking_no' => $r['booking_no'],
            'name' => $r['name'],
            'eye' => $r['eye'],
            'source' => $r['source'],
            'visit_id' => $r['visit_id'] === null ? null : (int) $r['visit_id'],
            'visit_no' => $r['visit_no'],
            'scheduled_for' => $r['scheduled_for'],
            'status' => $r['status'],
            'referring' => $r['referring_name'] ?: null,
            'result_ready' => $r['result_id'] !== null,
            'reported_at' => Format::utc($r['reported_at']),
        ], Database::all(
            "SELECT b.id, b.booking_no, b.eye, b.source, b.visit_id, b.scheduled_for, b.status, pi.name, v.visit_no,
                    TRIM(CONCAT_WS(' ', ru.first_name, ru.last_name)) AS referring_name, r.id AS result_id, r.completed_at AS reported_at
               FROM diagnostic_bookings b
               JOIN price_items pi ON pi.id = b.price_item_id
               LEFT JOIN visits v ON v.id = b.visit_id
               LEFT JOIN users ru ON ru.id = b.referring_user_id
               LEFT JOIN diagnostic_results r ON r.booking_id = b.id
              WHERE b.patient_id = :p
              ORDER BY b.scheduled_for DESC, b.id DESC LIMIT 200",
            ['p' => $patientId]
        ));
    }

    /**
     * One test with its report. Opening it is logged; when the referring doctor (or, for a test
     * without one, any doctor) opens it, it stops being "new".
     */
    public static function result(int $bookingId, AuthContext $ctx): array
    {
        $b = Database::one(
            "SELECT b.id, b.booking_no, b.patient_id, b.eye, b.status, b.scheduled_for, b.referring_user_id, pi.name, v.visit_no,
                    TRIM(CONCAT_WS(' ', ru.first_name, ru.last_name)) AS referring_name,
                    p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc, p.id AS patient_id_joined
               FROM diagnostic_bookings b
               JOIN price_items pi ON pi.id = b.price_item_id
               JOIN patients p ON p.id = b.patient_id
               LEFT JOIN visits v ON v.id = b.visit_id
               LEFT JOIN users ru ON ru.id = b.referring_user_id
              WHERE b.id = :id",
            ['id' => $bookingId]
        ) ?? throw HttpException::notFound('Test not found.');
        $report = DiagnosticService::report($bookingId, $ctx);

        if ($report !== null && ($b['referring_user_id'] === null || (int) $b['referring_user_id'] === $ctx->userId)) {
            Database::execute(
                'UPDATE diagnostic_results SET seen_at = UTC_TIMESTAMP(3), seen_by = :u WHERE booking_id = :b AND seen_at IS NULL',
                ['u' => $ctx->userId, 'b' => $bookingId]
            );
        }
        Audit::access((int) $b['patient_id'], 'clinical', 'view', 'diagnostic_result', $b['booking_no']);
        if ($report !== null) {
            unset($report['row_version'], $report['correctable_until']);
        }
        return [
            'booking_id' => (int) $b['id'],
            'booking_no' => $b['booking_no'],
            'name' => $b['name'],
            'eye' => $b['eye'],
            'status' => $b['status'],
            'scheduled_for' => $b['scheduled_for'],
            'visit_no' => $b['visit_no'],
            'referring' => $b['referring_name'] ?: null,
            'patient' => PatientService::brief(['patient_id' => $b['patient_id']] + $b),
            'report' => $report,
        ];
    }

    /** Results the signed-in doctor has not opened yet: tests they referred, and tests with no referring doctor. */
    public static function unseen(AuthContext $ctx): array
    {
        $rows = Database::all(
            "SELECT b.id, b.booking_no, b.eye, b.referring_user_id, pi.name, r.completed_at,
                    TRIM(CONCAT_WS(' ', d.first_name, d.last_name)) AS reporting_name,
                    p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc
               FROM diagnostic_results r
               JOIN diagnostic_bookings b ON b.id = r.booking_id
               JOIN price_items pi ON pi.id = b.price_item_id
               JOIN patients p ON p.id = b.patient_id
               JOIN users d ON d.id = r.reporting_user_id
              WHERE r.seen_at IS NULL AND b.status = 'completed'
                AND (b.referring_user_id = :u OR b.referring_user_id IS NULL)
                AND r.completed_at >= UTC_TIMESTAMP(3) - INTERVAL 60 DAY
              ORDER BY r.completed_at DESC LIMIT 100",
            ['u' => $ctx->userId]
        );
        return array_map(static fn (array $r) => [
            'booking_id' => (int) $r['id'],
            'booking_no' => $r['booking_no'],
            'name' => $r['name'],
            'eye' => $r['eye'],
            'reported_at' => Format::utc($r['completed_at']),
            'reported_by' => $r['reporting_name'],
            'mine' => $r['referring_user_id'] !== null,
            'patient' => PatientService::brief($r),
        ], $rows);
    }

    // ─── Theatre ─────────────────────────────────────────────────────────

    /** Theatre cases; what was done (operation, team, post-op reviews, discharge) only when $full. */
    public static function theatre(int $patientId, bool $full): array
    {
        $rows = Database::all(
            "SELECT b.id, b.booking_no, b.eye, b.scheduled_for, b.status, b.discharged_at, b.discharge_remarks_enc, v.visit_no,
                    si.name AS surgery_name, room.label AS room, TRIM(CONCAT_WS(' ', su.first_name, su.last_name)) AS surgeon
               FROM theatre_bookings b
               JOIN users su ON su.id = b.surgeon_user_id
               LEFT JOIN price_items si ON si.id = b.surgery_item_id
               LEFT JOIN lookup_values room ON room.id = b.theatre_room_id
               LEFT JOIN visits v ON v.id = b.visit_id
              WHERE b.patient_id = :p
              ORDER BY b.scheduled_for DESC, b.id DESC LIMIT 100",
            ['p' => $patientId]
        );
        $ids = array_map(static fn (array $r) => (int) $r['id'], $rows);
        $procedures = self::grouped('SELECT tp.booking_id AS k, pi.name FROM theatre_booking_procedures tp JOIN price_items pi ON pi.id = tp.price_item_id
            WHERE tp.booking_id IN (:ids) ORDER BY tp.id', $ids);
        $operations = $full ? self::grouped("SELECT o.booking_id AS k, o.* FROM theatre_operations o WHERE o.status = 'completed' AND o.booking_id IN (:ids)", $ids) : [];
        $postop = $full ? self::grouped("SELECT pv.booking_id AS k, pv.* FROM theatre_postop_visits pv WHERE pv.status = 'completed' AND pv.booking_id IN (:ids)
            ORDER BY pv.visit_number", $ids) : [];

        return array_map(static function (array $r) use ($full, $procedures, $operations, $postop): array {
            $id = (int) $r['id'];
            $case = [
                'booking_id' => $id,
                'booking_no' => $r['booking_no'],
                'procedures' => array_values(array_filter([$r['surgery_name'], ...array_column($procedures[$id] ?? [], 'name')])),
                'eye' => $r['eye'],
                'scheduled_for' => $r['scheduled_for'],
                'status' => $r['status'],
                'surgeon' => $r['surgeon'],
                'room' => $r['room'],
                'visit_no' => $r['visit_no'],
            ];
            if (!$full) {
                return $case;
            }
            $op = $operations[$id][0] ?? null;
            $case['operation'] = $op === null ? null : [
                'started_at' => Format::utc($op['started_at']),
                'ended_at' => Format::utc($op['ended_at']),
                'anaesthesia' => $op['anaesthesia_type'],
                'as_planned' => $op['completed_as_planned'] === null ? null : (bool) $op['completed_as_planned'],
                'actual_procedure' => Crypto::decrypt($op['actual_procedure_enc'], 'theatre_operations.actual_procedure'),
                'notes' => Crypto::decrypt($op['surgical_notes_enc'], 'theatre_operations.surgical_notes'),
                'complications' => Crypto::decrypt($op['complications_enc'], 'theatre_operations.complications'),
                'remarks' => Crypto::decrypt($op['remarks_enc'], 'theatre_operations.remarks'),
                'team' => array_map(static fn (array $t) => ['role' => $t['role'], 'name' => $t['name']], Database::all(
                    "SELECT t.role, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS name FROM theatre_operation_team t JOIN users u ON u.id = t.user_id
                      WHERE t.operation_id = :o ORDER BY FIELD(t.role, 'surgeon', 'assistant', 'anaesthetist', 'scrub_nurse', 'anaesthesia_nurse', 'runner')",
                    ['o' => (int) $op['id']]
                )),
            ];
            $case['postop'] = array_map(static fn (array $p) => [
                'visit_number' => (int) $p['visit_number'],
                'visit_date' => $p['visit_date'],
                'va_right' => $p['va_right'], 'va_left' => $p['va_left'],
                'iop_right' => $p['iop_right'] === null ? null : (int) $p['iop_right'],
                'iop_left' => $p['iop_left'] === null ? null : (int) $p['iop_left'],
                'wound' => $p['wound_status'],
                'pain_score' => $p['pain_score'] === null ? null : (int) $p['pain_score'],
                'complications' => Crypto::decrypt($p['complications_enc'], 'theatre_postop.complications'),
                'follow_up_notes' => Crypto::decrypt($p['follow_up_notes_enc'], 'theatre_postop.follow_up_notes'),
            ], $postop[$id] ?? []);
            $case['discharged_at'] = Format::utc($r['discharged_at']);
            $case['discharge_remarks'] = Crypto::decrypt($r['discharge_remarks_enc'], 'theatre_bookings.discharge_remarks');
            return $case;
        }, $rows);
    }

    // ─── Pharmacy and optical ────────────────────────────────────────────

    /** Medicines the pharmacy actually handed over (first issue and refills). */
    public static function medicines(int $patientId): array
    {
        $rows = Database::all(
            "SELECT d.id, d.dispensing_no, d.kind, d.dispensed_at, o.order_no, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS dispensed_by
               FROM pharmacy_dispensings d
               JOIN pharmacy_orders o ON o.id = d.order_id
               JOIN users u ON u.id = d.dispensed_by
              WHERE o.patient_id = :p
              ORDER BY d.dispensed_at DESC, d.id DESC LIMIT 100",
            ['p' => $patientId]
        );
        $items = self::grouped('SELECT di.dispensing_id AS k, dr.name, di.quantity FROM pharmacy_dispensing_items di JOIN drugs dr ON dr.id = di.drug_id
            WHERE di.dispensing_id IN (:ids) ORDER BY dr.name', array_map(static fn (array $r) => (int) $r['id'], $rows));
        return array_map(static fn (array $r) => [
            'dispensing_no' => $r['dispensing_no'],
            'kind' => $r['kind'],
            'dispensed_at' => Format::utc($r['dispensed_at']),
            'dispensed_by' => $r['dispensed_by'],
            'order_no' => $r['order_no'],
            'items' => array_map(static fn (array $i) => ['name' => $i['name'], 'quantity' => (int) $i['quantity']], $items[(int) $r['id']] ?? []),
        ], $rows);
    }

    /** Spectacle orders: what was ordered and where the order stands. */
    public static function optical(int $patientId): array
    {
        $rows = Database::all(
            'SELECT o.id, o.order_no, o.order_type, o.status, o.created_at, o.dispensed_at, v.visit_no
               FROM optical_orders o LEFT JOIN visits v ON v.id = o.visit_id
              WHERE o.patient_id = :p
              ORDER BY o.created_at DESC, o.id DESC LIMIT 100',
            ['p' => $patientId]
        );
        $items = self::grouped('SELECT i.order_id AS k, i.kind, i.description, i.rx_label FROM optical_order_items i
            WHERE i.order_id IN (:ids) ORDER BY i.set_no, i.kind DESC, i.id', array_map(static fn (array $r) => (int) $r['id'], $rows));
        return array_map(static fn (array $r) => [
            'order_no' => $r['order_no'],
            'order_type' => $r['order_type'],
            'status' => $r['status'],
            'ordered_at' => Format::utc($r['created_at']),
            'dispensed_at' => Format::utc($r['dispensed_at']),
            'visit_no' => $r['visit_no'],
            'items' => array_map(static fn (array $i) => ($i['rx_label'] ? "{$i['rx_label']}: " : '') . $i['description'], $items[(int) $r['id']] ?? []),
        ], $rows);
    }

    /** Rows of $sql (which selects the grouping key as k) grouped by k; ':ids' is expanded to $ids. */
    private static function grouped(string $sql, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        [$in, $params] = Database::in('g', $ids);
        $out = [];
        foreach (Database::all(str_replace(':ids', $in, $sql), $params) as $row) {
            $out[(int) $row['k']][] = $row;
        }
        return $out;
    }
}
