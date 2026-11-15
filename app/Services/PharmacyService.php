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
 * Pharmacy: dispensing medicine against an issued prescription, and selling to walk-in customers.
 *
 * The pharmacy reviews what can actually be supplied, which raises the cashier bill; medicine is
 * handed over once that bill is settled, in full or in part. Cancelling a paid remainder raises a
 * refund request. Refills repeat a completed order free of charge, as the clinic does today.
 * Stock is a running balance and every change is recorded with its reason.
 */
final class PharmacyService
{
    public const OPEN_STATUSES = ['pending_review', 'awaiting_payment', 'paid', 'partially_dispensed'];

    private const ORDER_SELECT = "SELECT o.id, o.order_no, o.source, o.status, o.total_amount, o.refill_count, o.prescription_id,
                o.refill_of_order_id, ro.order_no AS refill_of_no,
                o.patient_id, o.walkin_id, o.visit_id, o.bill_id, o.reviewed_at, o.completed_at, o.cancelled_at,
                o.cancel_reason_enc, o.created_at, o.row_version, o.refund_id,
                o.balance_closed_at, o.balance_close_reason_enc,
                (SELECT COALESCE(SUM(GREATEST(CAST(bi.prescribed_qty AS SIGNED) - CAST(bi.quantity AS SIGNED), 0)), 0)
                   FROM pharmacy_order_items bi WHERE bi.order_id = o.id) AS balance_qty,
                TRIM(CONCAT_WS(' ', bcu.first_name, bcu.last_name)) AS balance_closed_by_name,
                v.visit_no, v.is_fast_track AS visit_fast_track,
                p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc, p.phone_enc,
                w.walkin_no, w.name_enc AS walkin_name_enc, w.phone_enc AS walkin_phone_enc, w.gender AS walkin_gender, w.age_years,
                b.bill_no, b.status AS bill_status, b.net_amount AS bill_amount, b.paid_amount,
                pr.prescription_no, pr.pharmacy_instructions_enc, pr.amended_at, pr.amend_reason_enc,
                TRIM(CONCAT_WS(' ', au.first_name, au.last_name)) AS amended_by_name,
                TRIM(CONCAT_WS(' ', iu.first_name, iu.last_name)) AS prescribed_by,
                TRIM(CONCAT_WS(' ', ru.first_name, ru.last_name)) AS reviewed_by_name,
                TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)) AS created_by_name,
                r.refund_no, r.status AS refund_status
           FROM pharmacy_orders o
           JOIN users cu ON cu.id = o.created_by
           LEFT JOIN patients p ON p.id = o.patient_id
           LEFT JOIN pharmacy_walkins w ON w.id = o.walkin_id
           LEFT JOIN visits v ON v.id = o.visit_id
           LEFT JOIN bills b ON b.id = o.bill_id
           LEFT JOIN pharmacy_orders ro ON ro.id = o.refill_of_order_id
           LEFT JOIN prescriptions pr ON pr.id = COALESCE(o.prescription_id, ro.prescription_id)
           LEFT JOIN users iu ON iu.id = pr.issued_by
           LEFT JOIN users au ON au.id = pr.amended_by
           LEFT JOIN users ru ON ru.id = o.reviewed_by
           LEFT JOIN refunds r ON r.id = o.refund_id
           LEFT JOIN users bcu ON bcu.id = o.balance_closed_by";

    // ─── Queue and lists ─────────────────────────────────────────────────

    /** Issued prescriptions the pharmacy has not taken up yet become orders on first sight. */
    public static function syncPrescriptions(int $userId): int
    {
        $pending = Database::all(
            "SELECT pr.id, pr.patient_id, pr.visit_id
               FROM prescriptions pr
               LEFT JOIN pharmacy_orders o ON o.prescription_id = pr.id
              WHERE pr.status = 'issued' AND o.id IS NULL
                -- Medicines typed by the doctor are bought outside; only stocked ones come here.
                AND EXISTS (SELECT 1 FROM prescription_items i WHERE i.prescription_id = pr.id AND i.drug_id IS NOT NULL)
              ORDER BY pr.issued_at"
        );
        foreach ($pending as $prescription) {
            self::orderFromPrescription(
                (int) $prescription['id'], (int) $prescription['patient_id'],
                $prescription['visit_id'] === null ? null : (int) $prescription['visit_id'],
                $userId
            );
        }
        return count($pending);
    }

    private static function orderFromPrescription(int $prescriptionId, int $patientId, ?int $visitId, int $userId): int
    {
        $key = Format::localDateKey();
        $orderId = Database::insert(
            'INSERT INTO pharmacy_orders (order_no, source, prescription_id, patient_id, visit_id, status, created_at, created_by)
             VALUES (:no, :source, :prescription, :patient, :visit, :status, UTC_TIMESTAMP(3), :by)',
            [
                'no' => sprintf('PHM-%s-%03d', $key, Sequence::next("pharmacy:$key")),
                'source' => 'prescription', 'prescription' => $prescriptionId, 'patient' => $patientId,
                'visit' => $visitId, 'status' => 'pending_review', 'by' => $userId,
            ]
        );
        self::fillItems($orderId, $prescriptionId);
        return $orderId;
    }

    /** Copies the stocked medicines of a prescription onto an order at today's catalogue price; returns how many. */
    private static function fillItems(int $orderId, int $prescriptionId): int
    {
        $items = Database::all(
            'SELECT i.drug_id, i.quantity, i.dose, i.frequency, i.duration, i.instructions_enc, d.name, d.unit_price
               FROM prescription_items i JOIN drugs d ON d.id = i.drug_id
              WHERE i.prescription_id = :p ORDER BY i.sort_order, i.id',
            ['p' => $prescriptionId]
        );
        foreach ($items as $item) {
            Database::execute(
                'INSERT INTO pharmacy_order_items (order_id, drug_id, description, dose, frequency, duration, instructions_enc,
                                                   quantity, prescribed_qty, unit_price, catalogue_price)
                 VALUES (:o, :drug, :desc, :dose, :freq, :duration, :instructions, :qty, :qty2, :price, :price2)',
                [
                    'o' => $orderId, 'drug' => (int) $item['drug_id'], 'desc' => mb_substr($item['name'], 0, 190),
                    'dose' => $item['dose'], 'freq' => $item['frequency'], 'duration' => $item['duration'],
                    'instructions' => $item['instructions_enc'], 'qty' => (int) $item['quantity'], 'qty2' => (int) $item['quantity'],
                    'price' => (float) $item['unit_price'], 'price2' => (float) $item['unit_price'],
                ]
            );
        }
        return count($items);
    }

    /**
     * The doctor changed the stocked medicines of an issued prescription. An order not yet
     * reviewed is rebuilt; one billed but unpaid has its bill cancelled and goes back to review.
     * Anything paid for or handed over cannot change.
     *
     * @return array{effect:string, order_id:?int, bill_id:?int}
     */
    public static function onPrescriptionAmended(int $prescriptionId, string $reason, int $userId): array
    {
        $order = Database::one('SELECT * FROM pharmacy_orders WHERE prescription_id = :p FOR UPDATE', ['p' => $prescriptionId]);
        if ($order === null) {
            // Not taken up yet: the queue copies the new medicines when it next loads.
            return ['effect' => 'none', 'order_id' => null, 'bill_id' => null];
        }
        $orderId = (int) $order['id'];
        $cancelledBill = null;
        // A later part of the balance being billed: earlier parts were handed over, so nothing may change.
        if (Database::value('SELECT 1 FROM pharmacy_dispensings WHERE order_id = :o LIMIT 1', ['o' => $orderId])) {
            throw HttpException::conflict('pharmacy_locked', 'The pharmacy has already handed some of this medicine over, so the medicines it stocks can no longer change. '
                . 'Ask the pharmacy to cancel what has not been handed over.');
        }
        if ($order['status'] === 'awaiting_payment') {
            $bill = BillingService::lockBill((int) $order['bill_id']);
            if ($bill['status'] !== 'pending') {
                throw HttpException::conflict('pharmacy_locked', $bill['status'] === 'paid' || $bill['status'] === 'exempted'
                    ? 'The pharmacy bill for this prescription has already been settled, so its medicines can no longer change.'
                    : "The pharmacy bill {$bill['bill_no']} has a request waiting at Approvals. Wait for it to be decided, then try again.");
            }
            Database::execute(
                "UPDATE bills SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(3), cancelled_by = :by,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1 WHERE id = :id",
                ['by' => $userId, 'by2' => $userId, 'id' => (int) $bill['id']]
            );
            $cancelledBill = (int) $bill['id'];
        } elseif ($order['status'] !== 'pending_review') {
            throw HttpException::conflict('pharmacy_locked', match ($order['status']) {
                'cancelled' => 'The pharmacy has cancelled this order, so its medicines can no longer change.',
                default => 'The pharmacy has already been paid or has handed medicine over, so the medicines it stocks can no longer change. '
                    . 'Ask the pharmacy to cancel what has not been handed over.',
            });
        }

        Database::execute('DELETE FROM pharmacy_order_items WHERE order_id = :o', ['o' => $orderId]);
        $count = self::fillItems($orderId, $prescriptionId);
        if ($count === 0) {
            Database::execute(
                "UPDATE pharmacy_orders SET status = 'cancelled', bill_id = NULL, total_amount = 0, cancel_reason_enc = :r,
                        cancelled_at = UTC_TIMESTAMP(3), cancelled_by = :by, updated_at = UTC_TIMESTAMP(3), updated_by = :by2,
                        row_version = row_version + 1
                  WHERE id = :id",
                [
                    'r' => Crypto::encrypt('Prescription changed by the doctor — no stocked medicine left. ' . $reason, 'pharmacy_orders.cancel_reason'),
                    'by' => $userId, 'by2' => $userId, 'id' => $orderId,
                ]
            );
            return ['effect' => 'order_cancelled', 'order_id' => $orderId, 'bill_id' => $cancelledBill];
        }
        Database::execute(
            "UPDATE pharmacy_orders SET status = 'pending_review', bill_id = NULL, total_amount = 0, reviewed_at = NULL, reviewed_by = NULL,
                    updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
              WHERE id = :id",
            ['by' => $userId, 'id' => $orderId]
        );
        return ['effect' => $cancelledBill !== null ? 'bill_cancelled' : 'order_rebuilt', 'order_id' => $orderId, 'bill_id' => $cancelledBill];
    }

    public static function listOrders(string $scope, array $filters, int $userId): array
    {
        if ($scope === 'queue') {
            self::syncPrescriptions($userId);
        }
        [$in, $params] = match ($scope) {
            'dispensing' => Database::in('s', ['paid', 'partially_dispensed']),
            'partial' => Database::in('s', ['partially_dispensed']),
            'completed' => Database::in('s', ['completed', 'cancelled']),
            'balance' => Database::in('s', ['awaiting_payment', 'paid', 'partially_dispensed', 'completed']),
            default => Database::in('s', self::OPEN_STATUSES),
        };
        $where = ["o.status IN ($in)"];
        if ($scope === 'balance') {
            // Prescriptions with medicine still to collect.
            $where[] = "o.source = 'prescription' AND o.balance_closed_at IS NULL
                AND EXISTS (SELECT 1 FROM pharmacy_order_items bx WHERE bx.order_id = o.id AND bx.prescribed_qty > bx.quantity)";
        }
        if ($scope === 'queue' && ($filters['status'] ?? '') !== '' && in_array($filters['status'], self::OPEN_STATUSES, true)) {
            $where = ['o.status = :status'];
            $params = ['status' => $filters['status']];
        }
        if (($filters['source'] ?? '') !== '' && in_array($filters['source'], ['prescription', 'walk_in'], true)) {
            $where[] = 'o.source = :source';
            $params['source'] = $filters['source'];
        }
        $dateColumn = $scope === 'completed' ? 'o.completed_at' : 'o.created_at';
        if (($filters['from'] ?? '') !== '') {
            $where[] = "$dateColumn >= :from";
            $params['from'] = $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = "$dateColumn < :to";
            $params['to'] = $filters['to'];
        }
        $rows = Database::all(
            self::ORDER_SELECT . ' WHERE ' . implode(' AND ', $where)
            . ($scope === 'completed' ? ' ORDER BY o.completed_at DESC, o.id DESC' : ' ORDER BY v.is_emergency DESC, v.is_fast_track DESC, o.created_at, o.id')
            . ' LIMIT 500',
            $params
        );
        $orders = array_map(static fn (array $r) => self::present($r), $rows);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return $orders;
        }
        $needle = mb_strtolower($search);
        return array_values(array_filter($orders, static fn (array $o) => str_contains(mb_strtolower($o['customer']['name']), $needle)
            || str_contains(mb_strtolower($o['customer']['reference']), $needle)
            || str_contains(mb_strtolower($o['order_no']), $needle)));
    }

    public static function find(int $id): array
    {
        return Database::one(self::ORDER_SELECT . ' WHERE o.id = :id', ['id' => $id])
            ?? throw HttpException::notFound('Order not found.');
    }

    public static function show(int $id, AuthContext $ctx): array
    {
        $row = self::find($id);
        $order = self::present($row);
        $order['items'] = self::items($id);
        $order['dispensings'] = self::dispensings($id);
        $order['balance_supplies'] = self::balanceSupplies($id);
        $order['outside_items'] = $row['prescription_id'] === null ? [] : array_map(static fn (array $i) => [
            'name' => $i['outside_name'], 'quantity' => (int) $i['quantity'],
            'directions' => implode(' · ', array_filter([$i['dose'], $i['frequency'], $i['duration']])),
        ], Database::all(
            'SELECT outside_name, quantity, dose, frequency, duration FROM prescription_items
              WHERE prescription_id = :p AND drug_id IS NULL ORDER BY sort_order, id',
            ['p' => (int) $row['prescription_id']]
        ));
        if ($row['patient_id'] !== null) {
            \App\Core\Audit::access((int) $row['patient_id'], 'pharmacy', 'view', 'pharmacy_order', $row['order_no']);
        }
        return $order;
    }

    private static function present(array $r): array
    {
        $walkIn = $r['source'] === 'walk_in';
        $customer = $walkIn
            ? [
                'name' => (string) Crypto::decrypt($r['walkin_name_enc'], 'pharmacy_walkins.name'),
                'reference' => (string) $r['walkin_no'],
                'phone' => Crypto::decrypt($r['walkin_phone_enc'], 'pharmacy_walkins.phone'),
                'gender' => $r['walkin_gender'],
                'age' => $r['age_years'] === null ? null : (int) $r['age_years'],
                'patient_id' => null,
            ]
            : (static function (array $row): array {
                $brief = PatientService::brief($row);
                return [
                    'name' => $brief['full_name'], 'reference' => $brief['reg_no'],
                    'phone' => Crypto::decrypt($row['phone_enc'], 'patients.phone'),
                    'gender' => $brief['gender'], 'age' => $brief['age'], 'patient_id' => (int) $row['patient_id'],
                ];
            })($r);

        return [
            'id' => (int) $r['id'],
            'order_no' => $r['order_no'],
            'source' => $r['source'],
            'status' => $r['status'],
            'total_amount' => (float) $r['total_amount'],
            'refill_count' => (int) $r['refill_count'],
            'refill_of' => $r['refill_of_no'],
            'prescription_no' => $r['prescription_no'],
            'prescribed_by' => $r['prescribed_by'] ?: null,
            'pharmacy_instructions' => Crypto::decrypt($r['pharmacy_instructions_enc'], 'prescriptions.pharmacy_instructions'),
            'amendment' => $r['amended_at'] === null ? null : [
                'at' => Format::utc($r['amended_at']),
                'by' => $r['amended_by_name'] ?: null,
                'reason' => Crypto::decrypt($r['amend_reason_enc'], 'prescriptions.amend_reason'),
            ],
            'visit_no' => $r['visit_no'],
            'is_fast_track' => (bool) $r['visit_fast_track'],
            'is_paid' => in_array($r['bill_status'], ['paid', 'exempted'], true),
            'bill' => $r['bill_id'] === null ? null : [
                'id' => (int) $r['bill_id'], 'bill_no' => $r['bill_no'], 'status' => $r['bill_status'],
                'amount' => (float) $r['bill_amount'], 'paid_amount' => (float) $r['paid_amount'],
            ],
            'refund' => $r['refund_no'] === null ? null : ['refund_no' => $r['refund_no'], 'status' => $r['refund_status']],
            // Medicine still to collect on a later day (none once closed or cancelled).
            'balance_qty' => $r['balance_closed_at'] === null && $r['status'] !== 'cancelled' && $r['status'] !== 'pending_review' ? (int) $r['balance_qty'] : 0,
            'balance_closed' => $r['balance_closed_at'] === null ? null : [
                'at' => Format::utc($r['balance_closed_at']), 'by' => $r['balance_closed_by_name'] ?: null,
                'reason' => Crypto::decrypt($r['balance_close_reason_enc'], 'pharmacy_orders.balance_close_reason'),
            ],
            'reviewed_at' => Format::utc($r['reviewed_at']),
            'reviewed_by' => $r['reviewed_by_name'] ?: null,
            'completed_at' => Format::utc($r['completed_at']),
            'cancelled_at' => Format::utc($r['cancelled_at']),
            'cancel_reason' => Crypto::decrypt($r['cancel_reason_enc'], 'pharmacy_orders.cancel_reason'),
            'created_at' => Format::utc($r['created_at']),
            'created_by' => $r['created_by_name'],
            'row_version' => (int) $r['row_version'],
            'customer' => $customer,
        ];
    }

    /** Order lines with what is left to hand over and what is on the shelf. */
    public static function items(int $orderId): array
    {
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'drug_id' => (int) $r['drug_id'],
            'description' => $r['description'],
            'dose' => $r['dose'],
            'frequency' => $r['frequency'],
            'duration' => $r['duration'],
            'instructions' => Crypto::decrypt($r['instructions_enc'], 'prescription_items.instructions'),
            'quantity' => (int) $r['quantity'],
            'prescribed_qty' => (int) $r['prescribed_qty'],
            'balance' => max(0, (int) $r['prescribed_qty'] - (int) $r['quantity']),
            'dispensed_qty' => (int) $r['dispensed_qty'],
            'remaining' => max(0, (int) $r['quantity'] - (int) $r['dispensed_qty']),
            'unit_price' => (float) $r['unit_price'],
            'catalogue_price' => (float) $r['catalogue_price'],
            'in_stock' => (int) ($r['quantity_in_stock'] ?? 0),
        ], Database::all(
            'SELECT i.*, COALESCE(s.quantity, 0) AS quantity_in_stock
               FROM pharmacy_order_items i
               LEFT JOIN pharmacy_stock s ON s.drug_id = i.drug_id
              WHERE i.order_id = :o ORDER BY i.id',
            ['o' => $orderId]
        ));
    }

    public static function dispensings(int $orderId): array
    {
        $rows = Database::all(
            "SELECT d.id, d.dispensing_no, d.kind, d.notes_enc, d.dispensed_at,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS dispensed_by
               FROM pharmacy_dispensings d JOIN users u ON u.id = d.dispensed_by
              WHERE d.order_id = :o ORDER BY d.id",
            ['o' => $orderId]
        );
        return array_map(static fn (array $d) => [
            'id' => (int) $d['id'],
            'dispensing_no' => $d['dispensing_no'],
            'kind' => $d['kind'],
            'notes' => Crypto::decrypt($d['notes_enc'], 'pharmacy_dispensings.notes'),
            'dispensed_at' => Format::utc($d['dispensed_at']),
            'dispensed_by' => $d['dispensed_by'],
            'items' => array_map(static fn (array $i) => [
                'drug_id' => (int) $i['drug_id'], 'description' => $i['name'], 'quantity' => (int) $i['quantity'],
            ], Database::all(
                'SELECT di.drug_id, di.quantity, dr.name FROM pharmacy_dispensing_items di
                   JOIN drugs dr ON dr.id = di.drug_id WHERE di.dispensing_id = :d ORDER BY dr.name',
                ['d' => (int) $d['id']]
            )),
        ], $rows);
    }

    public static function lock(int $id): array
    {
        return Database::one('SELECT * FROM pharmacy_orders WHERE id = :id FOR UPDATE', ['id' => $id])
            ?? throw HttpException::notFound('Order not found.');
    }

    // ─── Review ──────────────────────────────────────────────────────────

    /**
     * The pharmacy says what it can actually supply; that raises the bill.
     * A quantity of zero drops the line from the order.
     *
     * @param array<int, array{item_id:int, quantity:int}> $lines
     */
    public static function review(int $id, array $lines, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $lines, $ctx): array {
            $order = self::lock($id);
            if ($order['status'] !== 'pending_review') {
                throw HttpException::conflict('order_state', 'This order has already been reviewed.');
            }
            $items = Database::all('SELECT * FROM pharmacy_order_items WHERE order_id = :o', ['o' => $id]);
            $byId = [];
            foreach ($items as $item) {
                $byId[(int) $item['id']] = $item;
            }
            $kept = [];
            foreach ($lines as $line) {
                $item = $byId[$line['item_id']] ?? throw HttpException::validation(['items' => 'An item is no longer on this order.']);
                $quantity = max(0, $line['quantity']);
                // Less than prescribed (or none now) leaves a balance to collect on a later day.
                Database::execute(
                    'UPDATE pharmacy_order_items SET quantity = :q WHERE id = :id',
                    ['q' => $quantity, 'id' => (int) $item['id']]
                );
                if ($quantity === 0) {
                    continue;
                }
                $kept[] = ['item' => $item, 'quantity' => $quantity];
            }
            if ($kept === []) {
                throw HttpException::validation(['items' => 'Supply at least one medicine, or cancel the order.']);
            }
            $total = round(array_sum(array_map(
                static fn (array $k) => $k['quantity'] * (float) $k['item']['unit_price'],
                $kept
            )), 2);
            $billId = BillingService::createBill(
                $order['patient_id'] === null ? throw HttpException::conflict('walk_in', 'A walk-in sale is billed when it is recorded.') : (int) $order['patient_id'],
                $order['visit_id'] === null ? null : (int) $order['visit_id'],
                'pharmacy',
                'Medicine — ' . implode(', ', array_map(static fn (array $k) => $k['item']['description'], $kept)),
                array_map(static fn (array $k) => [
                    'price_item_id' => null, 'description' => $k['item']['description'],
                    'quantity' => $k['quantity'], 'unit_price' => (float) $k['item']['unit_price'],
                ], $kept),
                false, null, false, $ctx->userId, 'pharmacy_order', $id
            );
            Database::execute(
                "UPDATE pharmacy_orders SET status = 'awaiting_payment', bill_id = :bill, total_amount = :total,
                        reviewed_at = UTC_TIMESTAMP(3), reviewed_by = :by, updated_at = UTC_TIMESTAMP(3), updated_by = :by2,
                        row_version = row_version + 1
                  WHERE id = :id",
                ['bill' => $billId, 'total' => $total, 'by' => $ctx->userId, 'by2' => $ctx->userId, 'id' => $id]
            );
            self::refreshStatus($id, $ctx->userId);
            return self::present(self::find($id));
        });
    }

    /** A settled bill lets the medicine be handed over. */
    public static function refreshStatus(int $id, int $userId): void
    {
        $order = Database::one(
            'SELECT o.*, b.status AS bill_status FROM pharmacy_orders o LEFT JOIN bills b ON b.id = o.bill_id WHERE o.id = :id FOR UPDATE',
            ['id' => $id]
        );
        if ($order === null || !in_array($order['status'], ['awaiting_payment', 'paid'], true)) {
            return;
        }
        $paid = in_array($order['bill_status'], ['paid', 'exempted'], true);
        $status = $paid ? 'paid' : 'awaiting_payment';
        if ($status !== $order['status']) {
            Database::execute(
                'UPDATE pharmacy_orders SET status = :s, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id',
                ['s' => $status, 'by' => $userId, 'id' => $id]
            );
        }
    }

    public static function onBillSettled(array $bill, string $outcome): void
    {
        if (($bill['source_type'] ?? '') !== 'pharmacy_order' || $bill['source_id'] === null) {
            return;
        }
        $userId = (int) ($bill['updated_by'] ?? $bill['created_by']);
        if ($outcome === 'cancelled' && self::undoBalanceSupply((int) $bill['id'], $userId)) {
            return;
        }
        if ($outcome === 'cancelled') {
            Database::execute(
                "UPDATE pharmacy_orders SET status = 'cancelled', bill_id = NULL, cancelled_at = UTC_TIMESTAMP(3), cancelled_by = :by,
                        updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1
                  WHERE id = :id AND status IN ('awaiting_payment', 'paid')",
                ['by' => $userId, 'id' => (int) $bill['source_id']]
            );
            return;
        }
        self::refreshStatus((int) $bill['source_id'], $userId);
    }

    // ─── Dispensing ──────────────────────────────────────────────────────

    /**
     * Hands medicine over and takes it off the shelf. A refill repeats a completed
     * order free of charge and starts the counts again.
     *
     * @param array<int, array{item_id:int, quantity:int}> $lines
     */
    public static function dispense(int $id, array $lines, ?string $notes, bool $refill, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $lines, $notes, $refill, $ctx): array {
            $order = self::lock($id);
            if ($refill) {
                // Refills are paid for now: they are raised as their own order and bill (createRefill).
                throw HttpException::conflict('refill_billed', 'Refills are billed now: use Refill to raise the refill bill, then hand it over once paid.');
            } elseif (!in_array($order['status'], ['paid', 'partially_dispensed'], true)) {
                throw HttpException::conflict('order_state', 'This order is not ready to be handed over.');
            }

            $items = [];
            foreach (Database::all('SELECT * FROM pharmacy_order_items WHERE order_id = :o FOR UPDATE', ['o' => $id]) as $item) {
                $items[(int) $item['id']] = $item;
            }
            $given = [];
            foreach ($lines as $line) {
                $item = $items[$line['item_id']] ?? throw HttpException::validation(['items' => 'An item is no longer on this order.']);
                $quantity = max(0, $line['quantity']);
                if ($quantity === 0) {
                    continue;
                }
                $remaining = (int) $item['quantity'] - (int) $item['dispensed_qty'];
                if ($quantity > $remaining) {
                    throw HttpException::validation(["items.{$item['id']}" => "Only $remaining left on this order."]);
                }
                $stock = self::lockStock((int) $item['drug_id']);
                if ($quantity > $stock['quantity']) {
                    throw HttpException::validation(["items.{$item['id']}" => "Only {$stock['quantity']} in stock."]);
                }
                $given[] = ['item' => $item, 'quantity' => $quantity, 'stock' => $stock];
            }
            if ($given === []) {
                throw HttpException::validation(['items' => 'Enter at least one quantity to hand over.']);
            }

            $key = Format::localDateKey();
            $dispensingId = Database::insert(
                'INSERT INTO pharmacy_dispensings (dispensing_no, order_id, kind, notes_enc, dispensed_at, dispensed_by)
                 VALUES (:no, :o, :kind, :notes, UTC_TIMESTAMP(3), :by)',
                [
                    'no' => sprintf('PHD-%s-%03d', $key, Sequence::next("dispensing:$key")),
                    'o' => $id, 'kind' => $refill ? 'refill' : 'dispense',
                    'notes' => ($notes ?? '') === '' ? null : Crypto::encrypt($notes, 'pharmacy_dispensings.notes'),
                    'by' => $ctx->userId,
                ]
            );
            foreach ($given as $line) {
                Database::execute(
                    'INSERT INTO pharmacy_dispensing_items (dispensing_id, drug_id, quantity) VALUES (:d, :drug, :q)',
                    ['d' => $dispensingId, 'drug' => (int) $line['item']['drug_id'], 'q' => $line['quantity']]
                );
                Database::execute(
                    'UPDATE pharmacy_order_items SET dispensed_qty = dispensed_qty + :q WHERE id = :id',
                    ['q' => $line['quantity'], 'id' => (int) $line['item']['id']]
                );
                self::moveStock((int) $line['item']['drug_id'], -$line['quantity'], 'dispensed', null, $id, $ctx->userId);
            }

            $outstanding = (int) Database::value(
                'SELECT COALESCE(SUM(quantity - dispensed_qty), 0) FROM pharmacy_order_items WHERE order_id = :o',
                ['o' => $id]
            );
            Database::execute(
                'UPDATE pharmacy_orders SET status = :s, refill_count = refill_count + :refill,
                        completed_at = CASE WHEN :s2 = \'completed\' THEN UTC_TIMESTAMP(3) ELSE completed_at END,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                  WHERE id = :id',
                [
                    's' => $outstanding > 0 ? 'partially_dispensed' : 'completed',
                    's2' => $outstanding > 0 ? 'partially_dispensed' : 'completed',
                    'refill' => $refill ? 1 : 0, 'by' => $ctx->userId, 'id' => $id,
                ]
            );
            return self::present(self::find($id)) + ['dispensing_id' => $dispensingId];
        });
    }

    /**
     * Stops supplying what is left. If the patient has already paid for it,
     * a refund request is raised for the undispensed part.
     */
    public static function cancelRemaining(int $id, string $reason, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $reason, $ctx): array {
            $order = self::lock($id);
            if (!in_array($order['status'], ['awaiting_payment', 'paid', 'partially_dispensed'], true)) {
                throw HttpException::conflict('order_state', 'There is nothing left to cancel on this order.');
            }
            $outstandingValue = (float) Database::value(
                'SELECT COALESCE(SUM((quantity - dispensed_qty) * unit_price), 0) FROM pharmacy_order_items WHERE order_id = :o',
                ['o' => $id]
            );
            $refundId = null;
            if ($order['bill_id'] !== null && $outstandingValue > 0) {
                $bill = BillingService::lockBill((int) $order['bill_id']);
                if (in_array($bill['status'], ['paid', 'partially_refunded'], true)) {
                    $refundId = self::raiseRefund($bill, $outstandingValue, $reason, $ctx);
                } elseif (in_array($bill['status'], ['pending', 'discount_requested'], true)) {
                    Database::execute(
                        "UPDATE bills SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(3), cancelled_by = :by,
                                updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1 WHERE id = :id",
                        ['by' => $ctx->userId, 'by2' => $ctx->userId, 'id' => (int) $bill['id']]
                    );
                }
            }
            $anyDispensed = (int) Database::value(
                'SELECT COALESCE(SUM(dispensed_qty), 0) FROM pharmacy_order_items WHERE order_id = :o',
                ['o' => $id]
            ) > 0;
            Database::execute(
                'UPDATE pharmacy_orders SET status = :s, cancel_reason_enc = :reason, cancelled_at = UTC_TIMESTAMP(3), cancelled_by = :by,
                        completed_at = CASE WHEN :s2 = \'completed\' THEN UTC_TIMESTAMP(3) ELSE completed_at END,
                        refund_id = COALESCE(:refund, refund_id), updated_at = UTC_TIMESTAMP(3), updated_by = :by2,
                        balance_closed_at = COALESCE(balance_closed_at, UTC_TIMESTAMP(3)), balance_closed_by = COALESCE(balance_closed_by, :by3),
                        balance_close_reason_enc = COALESCE(balance_close_reason_enc, :reason2),
                        row_version = row_version + 1
                  WHERE id = :id',
                [
                    's' => $anyDispensed ? 'completed' : 'cancelled',
                    's2' => $anyDispensed ? 'completed' : 'cancelled',
                    'reason' => Crypto::encrypt($reason, 'pharmacy_orders.cancel_reason'),
                    'by' => $ctx->userId, 'by2' => $ctx->userId, 'refund' => $refundId, 'id' => $id,
                    'by3' => $ctx->userId, 'reason2' => Crypto::encrypt($reason, 'pharmacy_orders.balance_close_reason'),
                ]
            );
            return self::present(self::find($id));
        });
    }

    // ─── Refills ─────────────────────────────────────────────────────────

    /**
     * The patient comes back for more of a completed prescription. The refill is its own order,
     * at today's catalogue price, billed to the cashier and handed over once paid like any other.
     *
     * @param array<int, array{item_id:int, quantity:int}> $lines
     */
    public static function createRefill(int $id, array $lines, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $lines, $ctx): array {
            $original = self::lock($id);
            if ($original['source'] !== 'prescription' || $original['status'] !== 'completed') {
                throw HttpException::conflict('order_state', 'Only a completed prescription can be refilled.');
            }
            $items = [];
            foreach (Database::all('SELECT * FROM pharmacy_order_items WHERE order_id = :o', ['o' => $id]) as $item) {
                $items[(int) $item['id']] = $item;
            }
            $taken = [];
            foreach ($lines as $line) {
                $item = $items[$line['item_id']] ?? throw HttpException::validation(['items' => 'An item is no longer on this order.']);
                $quantity = max(0, $line['quantity']);
                if ($quantity === 0) {
                    continue;
                }
                $drug = Database::one('SELECT id, name, unit_price FROM drugs WHERE id = :d AND is_active = 1', ['d' => (int) $item['drug_id']])
                    ?? throw HttpException::validation(["items.{$item['id']}" => "{$item['description']} is no longer in the catalogue."]);
                $taken[] = ['item' => $item, 'quantity' => $quantity, 'price' => (float) $drug['unit_price']];
            }
            if ($taken === []) {
                throw HttpException::validation(['items' => 'Enter at least one quantity to refill.']);
            }
            $total = round(array_sum(array_map(static fn (array $t) => $t['quantity'] * $t['price'], $taken)), 2);
            $key = Format::localDateKey();
            $refillId = Database::insert(
                'INSERT INTO pharmacy_orders (order_no, source, refill_of_order_id, patient_id, visit_id, status, total_amount,
                                             reviewed_at, reviewed_by, created_at, created_by)
                 VALUES (:no, :source, :of, :patient, :visit, :status, :total, UTC_TIMESTAMP(3), :by, UTC_TIMESTAMP(3), :by2)',
                [
                    'no' => sprintf('PHM-%s-%03d', $key, Sequence::next("pharmacy:$key")), 'source' => 'refill', 'of' => $id,
                    'patient' => (int) $original['patient_id'], 'visit' => $original['visit_id'] === null ? null : (int) $original['visit_id'],
                    'status' => 'awaiting_payment', 'total' => $total, 'by' => $ctx->userId, 'by2' => $ctx->userId,
                ]
            );
            foreach ($taken as $t) {
                Database::execute(
                    'INSERT INTO pharmacy_order_items (order_id, drug_id, description, dose, frequency, duration, instructions_enc,
                                                       quantity, prescribed_qty, unit_price, catalogue_price)
                     VALUES (:o, :drug, :desc, :dose, :freq, :duration, :instructions, :qty, :qty2, :price, :price2)',
                    [
                        'o' => $refillId, 'drug' => (int) $t['item']['drug_id'], 'desc' => $t['item']['description'],
                        'dose' => $t['item']['dose'], 'freq' => $t['item']['frequency'], 'duration' => $t['item']['duration'],
                        'instructions' => $t['item']['instructions_enc'], 'qty' => $t['quantity'], 'qty2' => $t['quantity'],
                        'price' => $t['price'], 'price2' => $t['price'],
                    ]
                );
            }
            $billId = BillingService::createBill(
                (int) $original['patient_id'], $original['visit_id'] === null ? null : (int) $original['visit_id'], 'pharmacy',
                "Medicine refill of {$original['order_no']} — " . implode(', ', array_map(static fn (array $t) => $t['item']['description'], $taken)),
                array_map(static fn (array $t) => [
                    'price_item_id' => null, 'description' => $t['item']['description'], 'quantity' => $t['quantity'], 'unit_price' => $t['price'],
                ], $taken),
                false, null, false, $ctx->userId, 'pharmacy_order', $refillId
            );
            Database::execute('UPDATE pharmacy_orders SET bill_id = :b WHERE id = :id', ['b' => $billId, 'id' => $refillId]);
            Database::execute(
                'UPDATE pharmacy_orders SET refill_count = refill_count + 1, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id',
                ['by' => $ctx->userId, 'id' => $id]
            );
            self::refreshStatus($refillId, $ctx->userId);
            return self::show($refillId, $ctx);
        });
    }

    // ─── Balance collected on a later day ───────────────────────────────

    /**
     * The patient comes back for medicine they could not take before. Up to the balance of each
     * line, at the price locked at the first visit; a bill of its own for the cashier, then handed
     * over as usual. Only once everything billed so far has been handed over.
     *
     * @param array<int, array{item_id:int, quantity:int}> $lines
     */
    public static function supplyBalance(int $id, array $lines, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $lines, $ctx): array {
            $order = self::lock($id);
            if ($order['source'] !== 'prescription' || $order['balance_closed_at'] !== null || $order['status'] === 'cancelled') {
                throw HttpException::conflict('no_balance', 'This order has no balance to collect.');
            }
            if ($order['status'] !== 'completed') {
                throw HttpException::conflict('order_state', 'Finish the current part first: it must be paid and handed over before more is billed.');
            }
            $items = [];
            foreach (Database::all('SELECT * FROM pharmacy_order_items WHERE order_id = :o FOR UPDATE', ['o' => $id]) as $item) {
                $items[(int) $item['id']] = $item;
            }
            $taken = [];
            foreach ($lines as $line) {
                $item = $items[$line['item_id']] ?? throw HttpException::validation(['items' => 'An item is no longer on this order.']);
                $quantity = max(0, $line['quantity']);
                if ($quantity === 0) {
                    continue;
                }
                $balance = max(0, (int) $item['prescribed_qty'] - (int) $item['quantity']);
                if ($quantity > $balance) {
                    throw HttpException::validation(["items.{$item['id']}" => "Only $balance left to collect."]);
                }
                $taken[] = ['item' => $item, 'quantity' => $quantity];
            }
            if ($taken === []) {
                throw HttpException::validation(['items' => 'Enter at least one quantity to supply now.']);
            }
            $total = round(array_sum(array_map(static fn (array $t) => $t['quantity'] * (float) $t['item']['unit_price'], $taken)), 2);
            $billId = BillingService::createBill(
                (int) $order['patient_id'], $order['visit_id'] === null ? null : (int) $order['visit_id'], 'pharmacy',
                "Medicine (balance of {$order['order_no']}) — " . implode(', ', array_map(static fn (array $t) => $t['item']['description'], $taken)),
                array_map(static fn (array $t) => [
                    'price_item_id' => null, 'description' => $t['item']['description'],
                    'quantity' => $t['quantity'], 'unit_price' => (float) $t['item']['unit_price'],
                ], $taken),
                false, null, false, $ctx->userId, 'pharmacy_order', $id
            );
            $supplyId = Database::insert(
                'INSERT INTO pharmacy_balance_supplies (order_id, bill_id, previous_bill_id, total_amount, created_at, created_by)
                 VALUES (:o, :b, :prev, :t, UTC_TIMESTAMP(3), :by)',
                ['o' => $id, 'b' => $billId, 'prev' => $order['bill_id'], 't' => $total, 'by' => $ctx->userId]
            );
            foreach ($taken as $t) {
                Database::execute('INSERT INTO pharmacy_balance_supply_items (supply_id, item_id, quantity) VALUES (:s, :i, :q)',
                    ['s' => $supplyId, 'i' => (int) $t['item']['id'], 'q' => $t['quantity']]);
                Database::execute('UPDATE pharmacy_order_items SET quantity = quantity + :q WHERE id = :id',
                    ['q' => $t['quantity'], 'id' => (int) $t['item']['id']]);
            }
            Database::execute(
                "UPDATE pharmacy_orders SET status = 'awaiting_payment', bill_id = :b, total_amount = total_amount + :t,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id",
                ['b' => $billId, 't' => $total, 'by' => $ctx->userId, 'id' => $id]
            );
            self::refreshStatus($id, $ctx->userId);
            return self::show($id, $ctx);
        });
    }

    /** Nothing more will be collected (bought elsewhere, no longer needed); the reason is kept. */
    public static function closeBalance(int $id, string $reason, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $reason, $ctx): array {
            $order = self::lock($id);
            $balance = (int) Database::value(
                'SELECT COALESCE(SUM(GREATEST(CAST(prescribed_qty AS SIGNED) - CAST(quantity AS SIGNED), 0)), 0) FROM pharmacy_order_items WHERE order_id = :o',
                ['o' => $id]
            );
            if ($order['source'] !== 'prescription' || $order['balance_closed_at'] !== null || $order['status'] === 'cancelled' || $balance === 0) {
                throw HttpException::conflict('no_balance', 'This order has no balance to close.');
            }
            Database::execute(
                'UPDATE pharmacy_orders SET balance_closed_at = UTC_TIMESTAMP(3), balance_closed_by = :by, balance_close_reason_enc = :r,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1 WHERE id = :id',
                ['by' => $ctx->userId, 'by2' => $ctx->userId, 'r' => Crypto::encrypt($reason, 'pharmacy_orders.balance_close_reason'), 'id' => $id]
            );
            return self::show($id, $ctx);
        });
    }

    /**
     * The bill of a later part was cancelled at the cashier: that part goes back to the balance
     * and the order returns to where it was, rather than the whole order being cancelled.
     */
    private static function undoBalanceSupply(int $billId, int $userId): bool
    {
        $supply = Database::one("SELECT * FROM pharmacy_balance_supplies WHERE bill_id = :b AND status = 'billed' FOR UPDATE", ['b' => $billId]);
        if ($supply === null) {
            return false;
        }
        foreach (Database::all('SELECT item_id, quantity FROM pharmacy_balance_supply_items WHERE supply_id = :s', ['s' => (int) $supply['id']]) as $line) {
            Database::execute('UPDATE pharmacy_order_items SET quantity = quantity - :q WHERE id = :id AND quantity >= :q2',
                ['q' => (int) $line['quantity'], 'q2' => (int) $line['quantity'], 'id' => (int) $line['item_id']]);
        }
        Database::execute("UPDATE pharmacy_balance_supplies SET status = 'cancelled' WHERE id = :id", ['id' => (int) $supply['id']]);
        Database::execute(
            "UPDATE pharmacy_orders SET status = 'completed', bill_id = :prev, total_amount = total_amount - :t,
                    updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
              WHERE id = :id AND bill_id = :b",
            ['prev' => $supply['previous_bill_id'], 't' => (float) $supply['total_amount'], 'by' => $userId, 'id' => (int) $supply['order_id'], 'b' => $billId]
        );
        return true;
    }

    public static function balanceSupplies(int $orderId): array
    {
        $supplies = Database::all(
            "SELECT s.id, s.status, s.total_amount, s.created_at, b.bill_no, b.status AS bill_status,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS created_by_name
               FROM pharmacy_balance_supplies s JOIN bills b ON b.id = s.bill_id JOIN users u ON u.id = s.created_by
              WHERE s.order_id = :o ORDER BY s.id",
            ['o' => $orderId]
        );
        return array_map(static fn (array $s) => [
            'status' => $s['status'], 'total_amount' => (float) $s['total_amount'], 'created_at' => Format::utc($s['created_at']),
            'created_by' => $s['created_by_name'], 'bill_no' => $s['bill_no'], 'bill_status' => $s['bill_status'],
            'items' => array_map(static fn (array $i) => ['description' => $i['description'], 'quantity' => (int) $i['quantity']], Database::all(
                'SELECT i.description, si.quantity FROM pharmacy_balance_supply_items si JOIN pharmacy_order_items i ON i.id = si.item_id
                  WHERE si.supply_id = :s ORDER BY i.id',
                ['s' => (int) $s['id']]
            )),
        ], $supplies);
    }

    /** The money for medicine paid for but not handed over, through the usual refund queue. */
    private static function raiseRefund(array $bill, float $amount, string $reason, AuthContext $ctx): ?int
    {
        $refundable = round((float) $bill['paid_amount'] - (float) $bill['refunded_amount'], 2);
        $amount = round(min($amount, $refundable), 2);
        if ($amount <= 0) {
            return null;
        }
        if ((int) Database::value("SELECT COUNT(*) FROM refunds WHERE bill_id = :b AND status IN ('requested', 'approved')", ['b' => (int) $bill['id']]) > 0) {
            return null;
        }
        $paymentId = (int) Database::value(
            'SELECT id FROM payments WHERE bill_id = :b AND voided_at IS NULL ORDER BY id DESC LIMIT 1',
            ['b' => (int) $bill['id']]
        );
        if ($paymentId === 0) {
            return null;
        }
        $key = Format::localDateKey();
        $refundId = Database::insert(
            'INSERT INTO refunds (refund_no, bill_id, payment_id, patient_id, requested_amount, reason_enc, status, requested_at, requested_by)
             VALUES (:no, :bill, :payment, :patient, :amount, :reason, :status, UTC_TIMESTAMP(3), :by)',
            [
                'no' => sprintf('REF-%s-%03d', $key, Sequence::next("refund:$key")),
                'bill' => (int) $bill['id'], 'payment' => $paymentId, 'patient' => (int) $bill['patient_id'],
                'amount' => $amount, 'reason' => Crypto::encrypt($reason, 'refunds.reason'),
                'status' => 'requested', 'by' => $ctx->userId,
            ]
        );
        ApprovalService::create('refund', (int) $bill['patient_id'], (int) $bill['id'], $refundId, $amount, null, null, $reason, $ctx->userId);
        return $refundId;
    }

    // ─── Walk-in sales ───────────────────────────────────────────────────

    /**
     * Someone buying without being a registered patient (pharmacy counter, optical shop).
     * Name and phone are personal details, so they are encrypted like a patient's.
     *
     * @param array{name:string, phone:string, gender:?string, age:?int} $customer
     */
    public static function createWalkin(array $customer, int $userId): int
    {
        $key = Format::localDateKey();
        return Database::insert(
            'INSERT INTO pharmacy_walkins (walkin_no, name_enc, phone_enc, gender, age_years, created_at, created_by)
             VALUES (:no, :name, :phone, :gender, :age, UTC_TIMESTAMP(3), :by)',
            [
                'no' => sprintf('EXT-%s-%03d', $key, Sequence::next("walkin:$key")),
                'name' => Crypto::encrypt($customer['name'], 'pharmacy_walkins.name'),
                'phone' => Crypto::encrypt($customer['phone'], 'pharmacy_walkins.phone'),
                'gender' => $customer['gender'] ?? null, 'age' => $customer['age'] ?? null, 'by' => $userId,
            ]
        );
    }

    /**
     * Someone buying over the counter. The price may be set at the counter; the
     * catalogue price is stored beside it so any difference can be seen later.
     *
     * @param array<int, array{drug_id:int, quantity:int, unit_price:float}> $lines
     */
    public static function walkInSale(array $customer, array $lines, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($customer, $lines, $ctx): array {
            $key = Format::localDateKey();
            $walkinId = self::createWalkin($customer, $ctx->userId);
            $orderId = Database::insert(
                'INSERT INTO pharmacy_orders (order_no, source, walkin_id, status, created_at, created_by)
                 VALUES (:no, :source, :walkin, :status, UTC_TIMESTAMP(3), :by)',
                [
                    'no' => sprintf('PHM-%s-%03d', $key, Sequence::next("pharmacy:$key")),
                    'source' => 'walk_in', 'walkin' => $walkinId, 'status' => 'awaiting_payment', 'by' => $ctx->userId,
                ]
            );
            $total = 0.0;
            foreach ($lines as $line) {
                $drug = Database::one('SELECT id, name, unit_price FROM drugs WHERE id = :id AND is_active = 1', ['id' => $line['drug_id']])
                    ?? throw HttpException::validation(['items' => 'A medicine is no longer in the catalogue.']);
                $stock = self::lockStock((int) $drug['id']);
                if ($line['quantity'] > $stock['quantity']) {
                    throw HttpException::validation(['items' => "{$drug['name']}: only {$stock['quantity']} in stock."]);
                }
                Database::execute(
                    'INSERT INTO pharmacy_order_items (order_id, drug_id, description, quantity, prescribed_qty, unit_price, catalogue_price)
                     VALUES (:o, :drug, :desc, :qty, :qty2, :price, :catalogue)',
                    [
                        'o' => $orderId, 'drug' => (int) $drug['id'], 'desc' => mb_substr($drug['name'], 0, 190),
                        'qty' => $line['quantity'], 'qty2' => $line['quantity'], 'price' => $line['unit_price'], 'catalogue' => (float) $drug['unit_price'],
                    ]
                );
                $total += $line['quantity'] * $line['unit_price'];
            }
            if ($lines === []) {
                throw HttpException::validation(['items' => 'Add at least one medicine.']);
            }
            $total = round($total, 2);
            $billId = BillingService::createBill(
                null, null, 'pharmacy',
                'Counter sale — ' . implode(', ', array_map(static fn (array $l) => (string) Database::value('SELECT name FROM drugs WHERE id = :id', ['id' => $l['drug_id']]), $lines)),
                array_map(static fn (array $l) => [
                    'price_item_id' => null,
                    'description' => (string) Database::value('SELECT name FROM drugs WHERE id = :id', ['id' => $l['drug_id']]),
                    'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
                ], $lines),
                false, null, false, $ctx->userId, 'pharmacy_order', $orderId, $walkinId
            );
            Database::execute(
                'UPDATE pharmacy_orders SET total_amount = :t, bill_id = :b WHERE id = :id',
                ['t' => $total, 'b' => $billId, 'id' => $orderId]
            );
            self::refreshStatus($orderId, $ctx->userId);
            return self::present(self::find($orderId)) + ['walkin_id' => $walkinId, 'total_amount' => $total];
        });
    }

    // ─── Stock ───────────────────────────────────────────────────────────

    public static function stock(array $filters): array
    {
        $where = ['d.is_active = 1'];
        $params = [];
        if (($filters['category'] ?? '') !== '') {
            $where[] = 'd.category = :category';
            $params['category'] = $filters['category'];
        }
        $rows = Database::all(
            'SELECT d.id, d.name, d.category, d.dosage_form, d.unit_price,
                    COALESCE(s.quantity, 0) AS quantity, COALESCE(s.reorder_level, 0) AS reorder_level,
                    s.expiry_date, s.row_version
               FROM drugs d LEFT JOIN pharmacy_stock s ON s.drug_id = d.id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY d.name',
            $params
        );
        $items = array_map(static fn (array $r) => [
            'drug_id' => (int) $r['id'],
            'name' => $r['name'],
            'category' => $r['category'],
            'dosage_form' => $r['dosage_form'],
            'unit_price' => (float) $r['unit_price'],
            'quantity' => (int) $r['quantity'],
            'reorder_level' => (int) $r['reorder_level'],
            'expiry_date' => $r['expiry_date'],
            'row_version' => (int) ($r['row_version'] ?? 1),
            'stock_status' => self::stockStatus((int) $r['quantity'], (int) $r['reorder_level']),
        ], $rows);
        if (($filters['status'] ?? '') !== '') {
            $items = array_values(array_filter($items, static fn (array $i) => $i['stock_status'] === $filters['status']));
        }
        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter($items, static fn (array $i) => str_contains(mb_strtolower($i['name']), $needle)
                || str_contains(mb_strtolower((string) $i['category']), $needle)));
        }
        return $items;
    }

    public static function stockStatus(int $quantity, int $reorderLevel): string
    {
        if ($quantity <= 0) {
            return 'out_of_stock';
        }
        return $quantity <= $reorderLevel ? 'low_stock' : 'in_stock';
    }

    private static function lockStock(int $drugId): array
    {
        $stock = Database::one('SELECT * FROM pharmacy_stock WHERE drug_id = :d FOR UPDATE', ['d' => $drugId]);
        if ($stock === null) {
            Database::execute(
                'INSERT INTO pharmacy_stock (drug_id, quantity, reorder_level, updated_at) VALUES (:d, 0, 0, UTC_TIMESTAMP(3))',
                ['d' => $drugId]
            );
            $stock = Database::one('SELECT * FROM pharmacy_stock WHERE drug_id = :d FOR UPDATE', ['d' => $drugId]);
        }
        $stock['quantity'] = (int) $stock['quantity'];
        return $stock;
    }

    /** Receiving stock, or correcting a count. Both leave a record of who and why. */
    public static function adjustStock(int $drugId, int $change, string $reason, ?string $note, ?int $reorderLevel, ?string $expiry, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($drugId, $change, $reason, $note, $reorderLevel, $expiry, $ctx): array {
            $stock = self::lockStock($drugId);
            if ($change !== 0) {
                self::moveStock($drugId, $change, $reason, $note, null, $ctx->userId);
            }
            if ($reorderLevel !== null || $expiry !== null) {
                Database::execute(
                    'UPDATE pharmacy_stock SET reorder_level = COALESCE(:level, reorder_level), expiry_date = :expiry,
                            updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                      WHERE drug_id = :d',
                    ['level' => $reorderLevel, 'expiry' => $expiry, 'by' => $ctx->userId, 'd' => $drugId]
                );
            }
            $row = Database::one(
                'SELECT d.id, d.name, s.quantity, s.reorder_level, s.expiry_date, s.row_version
                   FROM drugs d JOIN pharmacy_stock s ON s.drug_id = d.id WHERE d.id = :d',
                ['d' => $drugId]
            );
            return [
                'drug_id' => (int) $row['id'], 'name' => $row['name'], 'quantity' => (int) $row['quantity'],
                'reorder_level' => (int) $row['reorder_level'], 'expiry_date' => $row['expiry_date'],
                'row_version' => (int) $row['row_version'],
                'stock_status' => self::stockStatus((int) $row['quantity'], (int) $row['reorder_level']),
            ];
        });
    }

    /** Applies a change to the shelf and records it. Never lets the balance go below zero. */
    private static function moveStock(int $drugId, int $change, string $reason, ?string $note, ?int $orderId, int $userId): void
    {
        $stock = self::lockStock($drugId);
        $balance = $stock['quantity'] + $change;
        if ($balance < 0) {
            throw HttpException::validation(['quantity' => "Only {$stock['quantity']} in stock."]);
        }
        Database::execute(
            'UPDATE pharmacy_stock SET quantity = :q, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
              WHERE drug_id = :d',
            ['q' => $balance, 'by' => $userId, 'd' => $drugId]
        );
        Database::execute(
            'INSERT INTO pharmacy_stock_movements (drug_id, change_qty, balance_after, reason, note, order_id, created_at, created_by)
             VALUES (:d, :change, :balance, :reason, :note, :order, UTC_TIMESTAMP(3), :by)',
            [
                'd' => $drugId, 'change' => $change, 'balance' => $balance, 'reason' => $reason,
                'note' => $note === null ? null : mb_substr($note, 0, 190), 'order' => $orderId, 'by' => $userId,
            ]
        );
    }

    public static function movements(int $drugId): array
    {
        return array_map(static fn (array $r) => [
            'change_qty' => (int) $r['change_qty'],
            'balance_after' => (int) $r['balance_after'],
            'reason' => $r['reason'],
            'note' => $r['note'],
            'order_no' => $r['order_no'],
            'created_at' => Format::utc($r['created_at']),
            'created_by' => $r['created_by_name'],
        ], Database::all(
            "SELECT m.change_qty, m.balance_after, m.reason, m.note, m.created_at, o.order_no,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS created_by_name
               FROM pharmacy_stock_movements m
               JOIN users u ON u.id = m.created_by
               LEFT JOIN pharmacy_orders o ON o.id = m.order_id
              WHERE m.drug_id = :d ORDER BY m.id DESC LIMIT 50",
            ['d' => $drugId]
        ));
    }
}
