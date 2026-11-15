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
 * Diagnostics: bookings, check-in and reports.
 *
 * A booking carries no charge — the bill is raised when the patient arrives on the test day,
 * and the test itself is never blocked by payment. "No charge" is a full discount request on
 * that bill, so it is decided in the existing approval queue. Free text is encrypted.
 */
final class DiagnosticService
{
    public const STATUSES = ['scheduled', 'arrived', 'completed', 'cancellation_requested', 'cancelled'];

    /** Bookings still on the desk's working list. */
    public const OPEN_STATUSES = ['scheduled', 'arrived', 'cancellation_requested'];

    private const BOOKING_SELECT = "SELECT b.id, b.booking_no, b.source, b.request_id, b.eye, b.scheduled_for, b.timing, b.remark_enc,
                b.status, b.bill_id, b.arrived_at, b.completed_at, b.cancelled_at, b.row_version, b.created_at, b.visit_id,
                b.price_item_id, b.laser_type_id, b.patient_id,
                pi.name AS procedure_name, pi.unit_price, pi.is_laser,
                lt.label AS laser_type,
                v.visit_no, v.is_fast_track AS visit_fast_track,
                p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
                TRIM(CONCAT_WS(' ', ru.first_name, ru.last_name)) AS referring_name, b.referring_user_id,
                TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)) AS created_by_name,
                bl.bill_no, bl.status AS bill_status, bl.net_amount AS bill_amount,
                r.id AS result_id, r.completed_at AS reported_at,
                TRIM(CONCAT_WS(' ', dr.first_name, dr.last_name)) AS reporting_name
           FROM diagnostic_bookings b
           JOIN price_items pi ON pi.id = b.price_item_id
           JOIN patients p ON p.id = b.patient_id
      LEFT JOIN users ru ON ru.id = b.referring_user_id
           JOIN users cu ON cu.id = b.created_by
           LEFT JOIN lookup_values lt ON lt.id = b.laser_type_id
           LEFT JOIN visits v ON v.id = b.visit_id
           LEFT JOIN bills bl ON bl.id = b.bill_id
           LEFT JOIN diagnostic_results r ON r.booking_id = b.id
           LEFT JOIN users dr ON dr.id = r.reporting_user_id";

    // ─── Lists ───────────────────────────────────────────────────────────

    /**
     * @param string $scope bookings (desk list), queue (waiting for a report) or history (reported)
     */
    public static function listBookings(string $scope, array $filters, AuthContext $ctx): array
    {
        [$in, $params] = match ($scope) {
            'queue' => Database::in('s', ['scheduled', 'arrived']),
            'history' => Database::in('s', ['completed']),
            default => Database::in('s', self::OPEN_STATUSES),
        };
        $where = ["b.status IN ($in)"];
        if ($scope === 'bookings' && ($filters['status'] ?? '') !== '' && in_array($filters['status'], self::OPEN_STATUSES, true)) {
            $where = ['b.status = :status'];
            $params = ['status' => $filters['status']];
        }
        if (($filters['price_item_id'] ?? 0) > 0) {
            $where[] = 'b.price_item_id = :item';
            $params['item'] = (int) $filters['price_item_id'];
        }
        $dateColumn = $scope === 'history' ? 'r.completed_at' : 'b.scheduled_for';
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
            . ($scope === 'history' ? ' ORDER BY r.completed_at DESC, b.id DESC' : ' ORDER BY DATE(b.scheduled_for), v.is_emergency DESC, v.is_fast_track DESC, b.scheduled_for, b.id')
            . ' LIMIT 500',
            $params
        );
        $bookings = array_map(static fn (array $r) => self::present($r), $rows);
        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $needle = mb_strtolower($search);
            $bookings = array_values(array_filter($bookings, static fn (array $b) => str_contains(mb_strtolower($b['patient']['full_name']), $needle)
                || str_contains(mb_strtolower($b['patient']['reg_no']), $needle)
                || str_contains(mb_strtolower($b['booking_no']), $needle)));
        }
        return $bookings;
    }

    public static function find(int $id): array
    {
        return Database::one(self::BOOKING_SELECT . ' WHERE b.id = :id', ['id' => $id])
            ?? throw HttpException::notFound('Booking not found.');
    }

    /** One booking with its laser sub-types, report and addenda. */
    public static function show(int $id, AuthContext $ctx): array
    {
        $row = self::find($id);
        $booking = self::present($row);
        $booking['laser_subtypes'] = self::laserSubtypes($id);
        $booking['report'] = self::report($id, $ctx);
        \App\Core\Audit::access((int) $row['patient_id'], 'diagnostics', 'view', 'diagnostic_booking', $row['booking_no']);
        return $booking;
    }

    private static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'booking_no' => $r['booking_no'],
            'source' => $r['source'],
            'visit_id' => $r['visit_id'] === null ? null : (int) $r['visit_id'],
            'visit_no' => $r['visit_no'],
            'is_fast_track' => (bool) $r['visit_fast_track'],
            'procedure' => ['id' => (int) $r['price_item_id'], 'name' => $r['procedure_name'], 'unit_price' => (float) $r['unit_price'], 'is_laser' => (bool) $r['is_laser']],
            'laser_type' => $r['laser_type'],
            'laser_type_id' => $r['laser_type_id'] === null ? null : (int) $r['laser_type_id'],
            'eye' => $r['eye'],
            'referring' => $r['referring_user_id'] === null
                ? ['id' => null, 'name' => 'Reception — no referring doctor']
                : ['id' => (int) $r['referring_user_id'], 'name' => $r['referring_name']],
            'scheduled_for' => Format::utc($r['scheduled_for']),
            'timing' => $r['timing'],
            'remark' => Crypto::decrypt($r['remark_enc'], 'diagnostic_bookings.remark'),
            'status' => $r['status'],
            'bill' => $r['bill_id'] === null ? null : [
                'id' => (int) $r['bill_id'], 'bill_no' => $r['bill_no'], 'status' => $r['bill_status'], 'amount' => (float) $r['bill_amount'],
            ],
            'arrived_at' => Format::utc($r['arrived_at']),
            'completed_at' => Format::utc($r['completed_at']),
            'cancelled_at' => Format::utc($r['cancelled_at']),
            'reported_at' => Format::utc($r['reported_at']),
            'reporting_doctor' => $r['reporting_name'] ?: null,
            'has_report' => $r['result_id'] !== null,
            'created_at' => Format::utc($r['created_at']),
            'created_by' => $r['created_by_name'],
            'row_version' => (int) $r['row_version'],
            'patient' => PatientService::brief($r) + ['id' => (int) $r['patient_id']],
        ];
    }

    private static function laserSubtypes(int $bookingId): array
    {
        return Database::all(
            'SELECT lv.id, lv.label FROM diagnostic_booking_laser_subtypes s JOIN lookup_values lv ON lv.id = s.lookup_value_id
              WHERE s.booking_id = :b ORDER BY lv.sort_order, lv.label',
            ['b' => $bookingId]
        );
    }

    // ─── Booking ─────────────────────────────────────────────────────────

    /**
     * Creates one booking per procedure — and, for laser therapy, one per laser type,
     * exactly as the desk books them.
     *
     * @param array $data patient_id, referring_user_id, eye, timing, scheduled_for, procedures[]
     * @return array<int, array> the bookings created
     */
    public static function book(array $data, AuthContext $ctx, string $source = 'desk', ?int $visitId = null, ?int $requestId = null): array
    {
        $created = [];
        foreach ($data['procedures'] as $procedure) {
            $laserTypes = $procedure['laser_type_ids'] ?? [];
            foreach ($laserTypes ?: [null] as $laserTypeId) {
                $id = self::insertBooking([
                    'patient' => $data['patient_id'],
                    'visit' => $visitId,
                    'source' => $source,
                    'request' => $requestId,
                    'item' => $procedure['price_item_id'],
                    'laser' => $laserTypeId,
                    'eye' => $data['eye'],
                    'referring' => $data['referring_user_id'],
                    'scheduled' => $data['scheduled_for'],
                    'timing' => $data['timing'],
                    'remark' => $procedure['remark'] ?? null,
                ], $ctx->userId);
                if ($laserTypeId !== null) {
                    self::replaceSubtypes($id, $procedure['laser_subtype_ids'] ?? []);
                }
                $created[] = self::present(self::find($id));
            }
        }
        return $created;
    }

    private static function insertBooking(array $values, int $userId): int
    {
        $key = Format::localDateKey();
        return Database::insert(
            'INSERT INTO diagnostic_bookings (booking_no, patient_id, visit_id, source, request_id, price_item_id, laser_type_id, eye,
                                              referring_user_id, scheduled_for, timing, remark_enc, status, created_at, created_by)
             VALUES (:no, :patient, :visit, :source, :request, :item, :laser, :eye, :referring, :scheduled, :timing, :remark, :status, UTC_TIMESTAMP(3), :by)',
            [
                'no' => sprintf('DIAG-%s-%03d', $key, Sequence::next("diagnostic:$key")),
                'patient' => $values['patient'], 'visit' => $values['visit'], 'source' => $values['source'], 'request' => $values['request'],
                'item' => $values['item'], 'laser' => $values['laser'], 'eye' => $values['eye'], 'referring' => $values['referring'],
                'scheduled' => $values['scheduled'], 'timing' => $values['timing'],
                'remark' => $values['remark'] === null || $values['remark'] === ''
                    ? null : Crypto::encrypt($values['remark'], 'diagnostic_bookings.remark'),
                'status' => 'scheduled', 'by' => $userId,
            ]
        );
    }

    private static function replaceSubtypes(int $bookingId, array $ids): void
    {
        Database::execute('DELETE FROM diagnostic_booking_laser_subtypes WHERE booking_id = :b', ['b' => $bookingId]);
        foreach (array_unique(array_map('intval', $ids)) as $valueId) {
            Database::execute(
                'INSERT INTO diagnostic_booking_laser_subtypes (booking_id, lookup_value_id) VALUES (:b, :v)',
                ['b' => $bookingId, 'v' => $valueId]
            );
        }
    }

    /** The patient and the procedure identify a booking, so only the rest can be changed. */
    public static function update(int $id, array $data, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $data, $ctx): array {
            $row = self::lock($id);
            if (!in_array($row['status'], ['scheduled', 'arrived'], true)) {
                throw HttpException::conflict('booking_state', 'This booking can no longer be changed.');
            }
            if ((int) $row['row_version'] !== (int) $data['row_version']) {
                throw HttpException::conflict('stale', 'Someone else changed this booking. Reload and try again.');
            }
            Database::execute(
                'UPDATE diagnostic_bookings SET referring_user_id = :referring, eye = :eye, scheduled_for = :scheduled, timing = :timing,
                        laser_type_id = :laser, remark_enc = :remark, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                  WHERE id = :id',
                [
                    'referring' => $data['referring_user_id'], 'eye' => $data['eye'], 'scheduled' => $data['scheduled_for'],
                    'timing' => $data['timing'], 'laser' => $data['laser_type_id'],
                    'remark' => ($data['remark'] ?? '') === '' ? null : Crypto::encrypt((string) $data['remark'], 'diagnostic_bookings.remark'),
                    'by' => $ctx->userId, 'id' => $id,
                ]
            );
            if ($data['laser_type_id'] !== null) {
                self::replaceSubtypes($id, $data['laser_subtype_ids'] ?? []);
            }
            return self::present(self::find($id));
        });
    }

    public static function lock(int $id): array
    {
        return Database::one('SELECT * FROM diagnostic_bookings WHERE id = :id FOR UPDATE', ['id' => $id])
            ?? throw HttpException::notFound('Booking not found.');
    }

    // ─── Check-in and charging ───────────────────────────────────────────

    /**
     * Marks the patient as arrived and raises the bill. With $noChargeReason the bill is raised
     * and sent to the approval queue as a full discount; the test still goes ahead either way.
     */
    public static function checkIn(int $id, ?string $noChargeReason, ?string $remarks, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $noChargeReason, $remarks, $ctx): array {
            $row = self::lock($id);
            if (!in_array($row['status'], ['scheduled', 'cancellation_requested'], true) || $row['bill_id'] !== null) {
                throw HttpException::conflict('booking_state', 'This booking has already been checked in.');
            }
            $billId = self::raiseBill($row, $ctx);
            Database::execute(
                "UPDATE diagnostic_bookings SET status = 'arrived', bill_id = :bill, arrived_at = UTC_TIMESTAMP(3), arrived_by = :by,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
                  WHERE id = :id",
                ['bill' => $billId, 'by' => $ctx->userId, 'by2' => $ctx->userId, 'id' => $id]
            );
            if ($noChargeReason !== null) {
                self::requestNoCharge($billId, $noChargeReason, $remarks, $ctx);
            }
            return self::present(self::find($id));
        });
    }

    /** Raises the test bill at the price-list rate. Returns the bill id. */
    private static function raiseBill(array $booking, AuthContext $ctx): int
    {
        $item = Database::one('SELECT id, name, unit_price FROM price_items WHERE id = :id', ['id' => (int) $booking['price_item_id']])
            ?? throw HttpException::conflict('price_item', 'This procedure is no longer on the price list.');
        $eyeLabel = $booking['eye'] === 'both' ? 'Both eyes' : ucfirst($booking['eye']) . ' eye';
        return BillingService::createBill(
            (int) $booking['patient_id'],
            $booking['visit_id'] === null ? null : (int) $booking['visit_id'],
            'investigation',
            "{$item['name']} — $eyeLabel ({$booking['booking_no']})",
            [['price_item_id' => (int) $item['id'], 'description' => $item['name'], 'quantity' => 1, 'unit_price' => (float) $item['unit_price']]],
            false,
            null,
            true,
            $ctx->userId,
            'diagnostic_booking',
            (int) $booking['id'],
        );
    }

    /** "No charge": a full discount request an administrator decides on. */
    private static function requestNoCharge(int $billId, string $reason, ?string $remarks, AuthContext $ctx): void
    {
        if (!$ctx->can('diagnostics.nocharge.request')) {
            throw new HttpException(403, 'forbidden', 'You are not allowed to request a test without charge.');
        }
        $bill = BillingService::lockBill($billId);
        $due = (float) $bill['net_amount'] - (float) $bill['paid_amount'];
        if ($due <= 0) {
            return;
        }
        ApprovalService::create('discount', (int) $bill['patient_id'], $billId, null, $due, $reason, null, $remarks, $ctx->userId);
        Database::execute(
            "UPDATE bills SET status = 'discount_requested', updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
              WHERE id = :id",
            ['by' => $ctx->userId, 'id' => $billId]
        );
    }

    /** Keeps a booking in step with its bill: a cancelled bill leaves the test to be charged again. */
    public static function onBillSettled(array $bill, string $outcome): void
    {
        if (($bill['source_type'] ?? '') !== 'diagnostic_booking' || $bill['source_id'] === null || $outcome !== 'cancelled') {
            return;
        }
        Database::execute(
            'UPDATE diagnostic_bookings SET bill_id = NULL, updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1
              WHERE id = :id AND bill_id = :bill',
            ['id' => (int) $bill['source_id'], 'bill' => (int) $bill['id']]
        );
    }

    // ─── Cancellation ────────────────────────────────────────────────────

    /** The desk asks; an administrator decides. The booking stays visible until then. */
    public static function requestCancellation(int $id, string $reason, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $reason, $ctx): array {
            $row = self::lock($id);
            if (!in_array($row['status'], ['scheduled', 'arrived'], true)) {
                throw HttpException::conflict('booking_state', 'This booking cannot be cancelled.');
            }
            $approval = ApprovalService::create(
                'diagnostic_cancellation', (int) $row['patient_id'],
                $row['bill_id'] === null ? null : (int) $row['bill_id'],
                null, null, null, null, $reason, $ctx->userId, $id
            );
            Database::execute(
                "UPDATE diagnostic_bookings SET status = 'cancellation_requested', cancel_approval_id = :a,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                  WHERE id = :id",
                ['a' => $approval['id'], 'by' => $ctx->userId, 'id' => $id]
            );
            return self::present(self::find($id)) + ['approval_no' => $approval['approval_no']];
        });
    }

    /** Called by the approval queue once an administrator has decided. */
    public static function onCancellationDecision(array $request, bool $approved, AuthContext $ctx): void
    {
        $bookingId = (int) ($request['booking_id'] ?? 0);
        if ($bookingId === 0) {
            return;
        }
        $booking = self::lock($bookingId);
        if ($booking['status'] !== 'cancellation_requested') {
            return;
        }
        if (!$approved) {
            Database::execute(
                "UPDATE diagnostic_bookings SET status = :s, cancel_approval_id = NULL, updated_at = UTC_TIMESTAMP(3),
                        updated_by = :by, row_version = row_version + 1 WHERE id = :id",
                ['s' => $booking['arrived_at'] === null ? 'scheduled' : 'arrived', 'by' => $ctx->userId, 'id' => $bookingId]
            );
            return;
        }
        Database::execute(
            "UPDATE diagnostic_bookings SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3),
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
        if ($booking['request_id'] !== null) {
            Database::execute(
                "UPDATE investigation_requests SET status = 'cancelled', updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1
                  WHERE id = :id AND status = 'booked'",
                ['id' => (int) $booking['request_id']]
            );
        }
    }

    // ─── Report ──────────────────────────────────────────────────────────

    /**
     * A patient sent from reception just for a test is done once it is reported: the visit ends.
     * An unpaid consultation bill stays open at the cashier.
     */
    private static function endInvestigationVisit(array $booking, AuthContext $ctx): void
    {
        if ($booking['source'] !== 'visit' || $booking['visit_id'] === null) {
            return;
        }
        $visit = Database::one('SELECT id, status, destination FROM visits WHERE id = :id FOR UPDATE', ['id' => (int) $booking['visit_id']]);
        if ($visit !== null && $visit['destination'] === 'investigation'
            && in_array($visit['status'], ['pending_payment', 'waiting_investigation'], true)) {
            VisitService::changeStatus((int) $visit['id'], $visit['status'], 'completed', $ctx->userId, "Investigation {$booking['booking_no']} reported");
        }
    }

    /**
     * Writes or corrects the report. The booking is completed with it; corrections stay open to
     * the author for the clinical edit window, after which only an addendum may be added.
     */
    public static function saveReport(int $bookingId, array $data, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($bookingId, $data, $ctx): array {
            $booking = self::lock($bookingId);
            if (in_array($booking['status'], ['cancelled', 'cancellation_requested'], true)) {
                throw HttpException::conflict('booking_state', 'This booking is cancelled.');
            }
            $existing = Database::one('SELECT * FROM diagnostic_results WHERE booking_id = :b FOR UPDATE', ['b' => $bookingId]);
            $result = $data['result'] === '' ? null : Crypto::encrypt($data['result'], 'diagnostic_results.result');
            $comments = ($data['comments'] ?? '') === '' ? null : Crypto::encrypt((string) $data['comments'], 'diagnostic_results.comments');

            if ($existing === null) {
                $resultId = Database::insert(
                    'INSERT INTO diagnostic_results (booking_id, patient_id, technician_user_id, reporting_user_id, result_enc, comments_enc,
                                                     completed_at, completed_by)
                     VALUES (:b, :p, :tech, :doctor, :result, :comments, UTC_TIMESTAMP(3), :by)',
                    [
                        'b' => $bookingId, 'p' => (int) $booking['patient_id'], 'tech' => $data['technician_user_id'],
                        'doctor' => $data['reporting_user_id'], 'result' => $result, 'comments' => $comments, 'by' => $ctx->userId,
                    ]
                );
                Database::execute(
                    "UPDATE diagnostic_bookings SET status = 'completed', completed_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3),
                            updated_by = :by, row_version = row_version + 1 WHERE id = :id",
                    ['by' => $ctx->userId, 'id' => $bookingId]
                );
                if ($booking['request_id'] !== null) {
                    Database::execute(
                        "UPDATE investigation_requests SET status = 'booked', updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1
                          WHERE id = :id",
                        ['id' => (int) $booking['request_id']]
                    );
                }
                self::endInvestigationVisit($booking, $ctx);
            } else {
                self::assertCorrectable($existing, $ctx);
                if ((int) $existing['row_version'] !== (int) ($data['row_version'] ?? 0)) {
                    throw HttpException::conflict('stale', 'Someone else changed this report. Reload and try again.');
                }
                $resultId = (int) $existing['id'];
                Database::execute(
                    'UPDATE diagnostic_results SET technician_user_id = :tech, reporting_user_id = :doctor, result_enc = :result,
                            comments_enc = :comments, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                      WHERE id = :id',
                    [
                        'tech' => $data['technician_user_id'], 'doctor' => $data['reporting_user_id'], 'result' => $result,
                        'comments' => $comments, 'by' => $ctx->userId, 'id' => $resultId,
                    ]
                );
            }
            $prescriptionNo = null;
            if (($data['medicines'] ?? []) !== []) {
                if ($existing !== null && $existing['prescription_id'] !== null) {
                    throw HttpException::conflict('prescribed', 'Medicine for this report has already been sent to the pharmacy.');
                }
                // Same road as every prescription: pharmacy review, bill at today's price, cashier, hand over.
                $prescriptionId = ClinicalOrders::issueDirect(
                    'diagnostic', $resultId, (int) $booking['patient_id'],
                    $booking['visit_id'] === null ? null : (int) $booking['visit_id'],
                    $data['medicines'], $data['pharmacy_instructions'] ?? null, $ctx->userId, (int) $data['reporting_user_id']
                );
                Database::execute('UPDATE diagnostic_results SET prescription_id = :p WHERE id = :id', ['p' => $prescriptionId, 'id' => $resultId]);
                $prescriptionNo = Database::value('SELECT prescription_no FROM prescriptions WHERE id = :id', ['id' => $prescriptionId]);
            }
            return ['result_id' => $resultId, 'created' => $existing === null, 'prescription_no' => $prescriptionNo];
        });
    }

    /** Until when the author may still correct the report (null once the window has passed). */
    public static function correctableUntil(array $result, AuthContext $ctx): ?string
    {
        return ClinicalService::correctableUntil(
            ['status' => 'completed', 'completed_at' => $result['completed_at'], 'completed_by' => $result['completed_by']],
            $ctx
        );
    }

    private static function assertCorrectable(array $result, AuthContext $ctx): void
    {
        if (self::correctableUntil($result, $ctx) === null) {
            throw HttpException::conflict(
                'locked',
                'The time for correcting this report has passed. Add an addendum instead.'
            );
        }
    }

    public static function report(int $bookingId, AuthContext $ctx): ?array
    {
        $r = Database::one(
            "SELECT r.*, TRIM(CONCAT_WS(' ', d.first_name, d.last_name)) AS reporting_name,
                    TRIM(CONCAT_WS(' ', t.first_name, t.last_name)) AS technician_name,
                    TRIM(CONCAT_WS(' ', c.first_name, c.last_name)) AS completed_by_name, pr.prescription_no
               FROM diagnostic_results r
               JOIN users d ON d.id = r.reporting_user_id
               JOIN users c ON c.id = r.completed_by
               LEFT JOIN users t ON t.id = r.technician_user_id
               LEFT JOIN prescriptions pr ON pr.id = r.prescription_id
              WHERE r.booking_id = :b",
            ['b' => $bookingId]
        );
        if ($r === null) {
            return null;
        }
        return [
            'id' => (int) $r['id'],
            'technician' => $r['technician_user_id'] === null ? null : ['id' => (int) $r['technician_user_id'], 'name' => $r['technician_name']],
            'reporting_doctor' => ['id' => (int) $r['reporting_user_id'], 'name' => $r['reporting_name']],
            'result' => Crypto::decrypt($r['result_enc'], 'diagnostic_results.result'),
            'comments' => Crypto::decrypt($r['comments_enc'], 'diagnostic_results.comments'),
            'prescription_no' => $r['prescription_no'],
            'medicines' => $r['prescription_id'] === null ? [] : self::prescribedMedicines((int) $r['prescription_id']),
            'completed_at' => Format::utc($r['completed_at']),
            'completed_by' => $r['completed_by_name'],
            'updated_at' => Format::utc($r['updated_at']),
            'row_version' => (int) $r['row_version'],
            'correctable_until' => Format::utc(self::correctableUntil($r, $ctx)),
            'files' => self::files((int) $r['id']),
            'addenda' => ClinicalService::addenda('diagnostic', (int) $r['id']),
        ];
    }

    /** The medicines a report sent to the pharmacy, as prescribed. */
    private static function prescribedMedicines(int $prescriptionId): array
    {
        return array_map(static fn (array $m) => [
            'name' => $m['name'], 'quantity' => (int) $m['quantity'], 'dose' => $m['dose'],
            'frequency' => $m['frequency'], 'duration' => $m['duration'],
        ], Database::all(
            'SELECT d.name, i.quantity, i.dose, i.frequency, i.duration
               FROM prescription_items i JOIN drugs d ON d.id = i.drug_id
              WHERE i.prescription_id = :p ORDER BY i.sort_order, i.id',
            ['p' => $prescriptionId]
        ));
    }

    public static function files(int $resultId): array
    {
        $rows = Database::all(
            "SELECT f.id, f.original_name_enc, f.mime_type, f.size_bytes, f.uploaded_at,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS uploaded_by
               FROM diagnostic_result_files rf
               JOIN stored_files f ON f.id = rf.file_id
               JOIN users u ON u.id = f.uploaded_by
              WHERE rf.result_id = :r AND rf.voided_at IS NULL ORDER BY f.id",
            ['r' => $resultId]
        );
        return array_map(static fn (array $f) => [
            'id' => (int) $f['id'],
            'name' => \App\Core\FileStore::decryptName($f['original_name_enc']),
            'mime_type' => $f['mime_type'],
            'size_bytes' => (int) $f['size_bytes'],
            'uploaded_at' => Format::utc($f['uploaded_at']),
            'uploaded_by' => $f['uploaded_by'],
        ], $rows);
    }

    /** The record an addendum is attached to (the report itself, not the booking). */
    public static function resultRecord(int $bookingId): array
    {
        return Database::one(
            'SELECT r.id, r.patient_id, b.visit_id FROM diagnostic_results r JOIN diagnostic_bookings b ON b.id = r.booking_id
              WHERE r.booking_id = :b',
            ['b' => $bookingId]
        ) ?? throw HttpException::notFound('This booking has no report yet.');
    }
}
