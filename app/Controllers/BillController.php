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
use App\Core\Validator;
use App\Services\ApprovalService;
use App\Services\BillingService;
use App\Services\ClinicalOrders;
use App\Services\PatientService;

/** Cashier: payment queue, history, payment, discount and cancellation requests, receipts. */
final class BillController
{
    private const SELECT = "SELECT b.id, b.bill_no, b.bill_type, b.description, b.status, b.gross_amount, b.discount_amount, b.net_amount,
            b.paid_amount, b.refunded_amount, b.discount_type, b.discount_sponsor, b.exemption_reason, b.is_deferred,
            b.created_at, b.paid_at, b.row_version,
            v.id AS visit_id, v.visit_no,
            p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
            w.id AS walkin_id, w.walkin_no, w.name_enc AS walkin_name_enc, w.gender AS walkin_gender, w.age_years,
            TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)) AS created_by_name,
            pay.id AS payment_id, pay.receipt_no, pay.method AS pay_method, pay.txn_ref, pay.received_at,
            TRIM(CONCAT_WS(' ', ru.first_name, ru.last_name)) AS cashier_name,
            (SELECT COUNT(*) FROM refunds r WHERE r.bill_id = b.id AND r.status IN ('requested', 'approved')) AS open_refunds
       FROM bills b
       LEFT JOIN patients p ON p.id = b.patient_id
       LEFT JOIN pharmacy_walkins w ON w.id = b.walkin_id
       JOIN users cu ON cu.id = b.created_by
       LEFT JOIN visits v ON v.id = b.visit_id
       LEFT JOIN payments pay ON pay.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.bill_id = b.id AND p2.voided_at IS NULL)
       LEFT JOIN users ru ON ru.id = pay.received_by";

    private const TYPES = ['consultation', 'investigation', 'optical', 'surgery', 'pharmacy', 'other'];

    /** Active payment queue: bills awaiting payment or a decision. */
    public function index(Request $request): array
    {
        $where = ["b.status IN ('pending', 'discount_requested', 'cancellation_requested')"];
        $params = [];
        $status = $request->query('status');
        if (in_array($status, BillingService::ACTIVE_STATUSES, true)) {
            $where[] = 'b.status = :status';
            $params['status'] = $status;
        }
        $this->commonFilters($request, 'b.created_at', $where, $params);
        $counts = array_fill_keys(self::TYPES, 0);
        foreach (Database::all("SELECT bill_type, COUNT(*) AS c FROM bills WHERE status IN ('pending', 'discount_requested', 'cancellation_requested') GROUP BY bill_type") as $row) {
            $counts[$row['bill_type']] = (int) $row['c'];
        }
        return $this->page($request, $where, $params, 'b.is_deferred DESC, b.id ASC') + ['counts' => $counts + ['all' => array_sum($counts)]];
    }

    /** Payment history: paid, unpaid or all bills. */
    public function history(Request $request): array
    {
        $tab = $request->query('tab', 'paid');
        $where = [];
        $params = [];
        $dateColumn = 'b.created_at';
        if ($tab === 'paid') {
            $where[] = "b.status IN ('paid', 'partially_refunded', 'refunded')";
            $dateColumn = 'b.paid_at';
        } elseif ($tab === 'unpaid') {
            $where[] = "b.status IN ('pending', 'discount_requested', 'cancellation_requested')";
        }
        if (in_array($request->query('status'), array_keys(BillingService::STATUS_LABELS), true)) {
            $where[] = 'b.status = :status';
            $params['status'] = $request->query('status');
        }
        $this->commonFilters($request, $dateColumn, $where, $params);
        return $this->page($request, $where, $params, $tab === 'paid' ? 'b.paid_at DESC' : 'b.id DESC');
    }

    public function show(Request $request, array $params): array
    {
        Auth::requireAny('payments.view', 'payments.history');
        $row = Database::one(self::SELECT . ' WHERE b.id = :id', ['id' => $params['id']]) ?? throw HttpException::notFound('Bill not found.');
        $id = (int) $row['id'];

        $items = Database::all('SELECT description, quantity, unit_price, line_total FROM bill_items WHERE bill_id = :id ORDER BY id', ['id' => $id]);
        $payments = Database::all(
            "SELECT pay.id, pay.receipt_no, pay.amount, pay.method, pay.txn_ref, pay.received_at, pay.voided_at,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS cashier
               FROM payments pay JOIN users u ON u.id = pay.received_by WHERE pay.bill_id = :id ORDER BY pay.id",
            ['id' => $id]
        );
        $approvals = Database::all(
            "SELECT a.id, a.approval_no, a.type, a.status, a.requested_amount, a.approved_amount, a.requested_at, a.decided_at,
                    TRIM(CONCAT_WS(' ', ru.first_name, ru.last_name)) AS requested_by, TRIM(CONCAT_WS(' ', du.first_name, du.last_name)) AS decided_by
               FROM approval_requests a JOIN users ru ON ru.id = a.requested_by LEFT JOIN users du ON du.id = a.decided_by
              WHERE a.bill_id = :id ORDER BY a.id",
            ['id' => $id]
        );
        $refunds = Database::all(
            'SELECT refund_no, status, requested_amount, approved_amount, requested_at, paid_out_at FROM refunds WHERE bill_id = :id ORDER BY id',
            ['id' => $id]
        );

        return ['bill' => $this->present($row) + [
            'items' => array_map(static fn (array $i) => [
                'description' => $i['description'], 'quantity' => (float) $i['quantity'], 'unit_price' => (float) $i['unit_price'], 'line_total' => (float) $i['line_total'],
            ], $items),
            'payments' => array_map(static fn (array $p) => [
                'id' => (int) $p['id'], 'receipt_no' => $p['receipt_no'], 'amount' => (float) $p['amount'], 'method' => $p['method'],
                'txn_ref' => $p['txn_ref'], 'received_at' => Format::utc($p['received_at']), 'cashier' => $p['cashier'], 'voided' => $p['voided_at'] !== null,
            ], $payments),
            'approvals' => array_map(static fn (array $a) => [
                'id' => (int) $a['id'], 'approval_no' => $a['approval_no'], 'type' => $a['type'], 'status' => $a['status'],
                'requested_amount' => $a['requested_amount'] === null ? null : (float) $a['requested_amount'],
                'approved_amount' => $a['approved_amount'] === null ? null : (float) $a['approved_amount'],
                'requested_by' => $a['requested_by'], 'decided_by' => $a['decided_by'] ?: null,
                'requested_at' => Format::utc($a['requested_at']), 'decided_at' => Format::utc($a['decided_at']),
            ], $approvals),
            'refunds' => array_map(static fn (array $r) => [
                'refund_no' => $r['refund_no'], 'status' => $r['status'], 'requested_amount' => (float) $r['requested_amount'],
                'approved_amount' => $r['approved_amount'] === null ? null : (float) $r['approved_amount'],
                'requested_at' => Format::utc($r['requested_at']), 'paid_out_at' => Format::utc($r['paid_out_at']),
            ], $refunds),
        ]];
    }

    /** Full payment of a pending bill (partial payments are not allowed). */
    public function pay(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->enum('method', ['cash', 'lipa_number'])
            ->string('txn_ref', false, 60)
            ->int('row_version', true, 1)
            ->validate();
        $reference = null;
        if ($v['method'] === 'lipa_number') {
            $reference = strtoupper((string) preg_replace('/\s+/', '', (string) $v['txn_ref']));
            if (!preg_match('/^[A-Z0-9-]{4,60}$/', $reference)) {
                throw HttpException::validation(['txn_ref' => 'Enter the Lipa Number transaction reference (letters, digits and dashes).']);
            }
        }

        try {
            [$paymentId, $bill, $receiptNo] = Database::transaction(function () use ($params, $v, $reference, $ctx): array {
                $bill = BillingService::lockBill($params['id']);
                if ((int) $bill['row_version'] !== $v['row_version']) {
                    throw HttpException::conflict('stale_record', 'This bill changed meanwhile. Reload and try again.');
                }
                if ($bill['status'] !== 'pending') {
                    throw HttpException::conflict('not_payable', BillingService::notPayableMessage($bill['status']));
                }
                $amount = (float) $bill['net_amount'];
                if ($amount <= 0) {
                    throw HttpException::conflict('not_payable', 'Nothing is due on this bill.');
                }
                if ($reference !== null && ($used = Database::value('SELECT receipt_no FROM payments WHERE method = :m AND txn_ref = :r', ['m' => 'lipa_number', 'r' => $reference]))) {
                    throw HttpException::validation(['txn_ref' => "This transaction reference was already used for receipt $used."]);
                }
                $key = Format::localDateKey();
                $receiptNo = sprintf('RCP-%s-%03d', $key, Sequence::next("receipt:$key"));
                $paymentId = Database::insert(
                    'INSERT INTO payments (receipt_no, bill_id, patient_id, walkin_id, amount, method, txn_ref, received_at, received_by)
                     VALUES (:no, :bill, :patient, :walkin, :amount, :method, :ref, UTC_TIMESTAMP(3), :by)',
                    ['no' => $receiptNo, 'bill' => (int) $bill['id'],
                     'patient' => $bill['patient_id'] === null ? null : (int) $bill['patient_id'],
                     'walkin' => $bill['walkin_id'] === null ? null : (int) $bill['walkin_id'],
                     'amount' => $amount, 'method' => $v['method'], 'ref' => $reference, 'by' => $ctx->userId]
                );
                Database::execute(
                    "UPDATE bills SET status = 'paid', paid_amount = :amount, paid_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3),
                            updated_by = :by, row_version = row_version + 1 WHERE id = :id",
                    ['amount' => $amount, 'by' => $ctx->userId, 'id' => (int) $bill['id']]
                );
                BillingService::releaseVisit($bill['visit_id'] === null ? null : (int) $bill['visit_id'], $ctx->userId, "Paid — receipt $receiptNo");
                ClinicalOrders::onBillSettled($bill, 'paid');
                return [$paymentId, $bill, $receiptNo];
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw HttpException::validation(['txn_ref' => 'This transaction reference has already been used.']);
            }
            throw $e;
        }

        Audit::log('cashier', 'bill.paid', "Received TZS " . number_format((float) $bill['net_amount']) . " for {$bill['bill_no']} — receipt $receiptNo", 'bill', $bill['bill_no'], ['method' => $v['method']]);
        return ['receipt' => $this->receiptData($paymentId)];
    }

    public function discountRequest(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $types = array_column(Database::all('SELECT value FROM lookup_values WHERE list_code = :l AND is_active = 1', ['l' => 'discount_type']), 'value');
        $v = Validator::make($request->all())
            ->enum('discount_type', $types)
            ->decimal('amount', true, 1, 100000000)
            ->string('sponsor', false, 150)
            ->string('remarks', false, 500)
            ->int('row_version', true, 1)
            ->validate();

        $result = Database::transaction(function () use ($params, $v, $ctx): array {
            $bill = $this->lockPending($params['id'], $v['row_version']);
            if ((float) $bill['discount_amount'] > 0) {
                throw HttpException::conflict('already_discounted', 'This bill already has a discount.');
            }
            if ($v['amount'] > (float) $bill['net_amount']) {
                throw HttpException::validation(['amount' => 'The discount cannot be more than the amount due.']);
            }
            $approval = ApprovalService::create('discount', BillingService::optionalId($bill['patient_id']), (int) $bill['id'], null, $v['amount'], $v['discount_type'], $v['sponsor'], $v['remarks'], $ctx->userId);
            $this->setStatus((int) $bill['id'], 'discount_requested', $ctx->userId);
            return ['approval' => $approval, 'bill_no' => $bill['bill_no']];
        });
        Audit::log('cashier', 'discount.requested', "Requested TZS " . number_format($v['amount']) . " {$v['discount_type']} on {$result['bill_no']} ({$result['approval']['approval_no']})", 'bill', $result['bill_no']);
        return ['approval' => $result['approval']];
    }

    public function cancelRequest(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('reason', true, 500, 5)->int('row_version', true, 1)->validate();
        $result = Database::transaction(function () use ($params, $v, $ctx): array {
            $bill = $this->lockPending($params['id'], $v['row_version']);
            $approval = ApprovalService::create('bill_cancellation', BillingService::optionalId($bill['patient_id']), (int) $bill['id'], null, (float) $bill['net_amount'], null, null, $v['reason'], $ctx->userId);
            $this->setStatus((int) $bill['id'], 'cancellation_requested', $ctx->userId);
            return ['approval' => $approval, 'bill_no' => $bill['bill_no']];
        });
        Audit::log('cashier', 'cancellation.requested', "Requested cancellation of {$result['bill_no']} ({$result['approval']['approval_no']})", 'bill', $result['bill_no']);
        return ['approval' => $result['approval']];
    }

    public function receipt(Request $request, array $params): array
    {
        Auth::requireAny('payments.view', 'payments.history');
        $receipt = $this->receiptData($params['id']) ?? throw HttpException::notFound('Receipt not found.');
        if ($receipt['patient']['id'] !== null) {
            Audit::access($receipt['patient']['id'], 'cashier', 'print', 'receipt', $receipt['receipt_no']);
        }
        return ['receipt' => $receipt];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function lockPending(int $billId, int $rowVersion): array
    {
        $bill = BillingService::lockBill($billId);
        if ((int) $bill['row_version'] !== $rowVersion) {
            throw HttpException::conflict('stale_record', 'This bill changed meanwhile. Reload and try again.');
        }
        if ($bill['status'] !== 'pending') {
            throw HttpException::conflict('bill_state', 'Only pending bills can have a discount or cancellation requested.');
        }
        return $bill;
    }

    private function setStatus(int $billId, string $status, int $userId): void
    {
        Database::execute(
            'UPDATE bills SET status = :s, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id',
            ['s' => $status, 'by' => $userId, 'id' => $billId]
        );
    }

    private function commonFilters(Request $request, string $dateColumn, array &$where, array &$params): void
    {
        $type = $request->query('bill_type');
        if (in_array($type, self::TYPES, true)) {
            $where[] = 'b.bill_type = :bill_type';
            $params['bill_type'] = $type;
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            $value = $request->query($key);
            if ($value === '') {
                continue;
            }
            if (!Format::isDate($value)) {
                throw HttpException::validation([$key => 'Use a valid date.']);
            }
            $where[] = "$dateColumn $operator :$key";
            $params[$key] = Format::localDateToUtc($value, $key === 'to');
        }
        $search = $request->query('search');
        if (preg_match('/^(PAY|RCP)-/i', $search)) {
            $where[] = '(b.bill_no LIKE :doc1 OR pay.receipt_no LIKE :doc2)';
            $params['doc1'] = $params['doc2'] = '%' . addcslashes(strtoupper($search), '%_\\') . '%';
        } elseif ($condition = PatientService::searchCondition($search)) {
            [$where[], $searchParams] = $condition;
            $params += $searchParams;
        }
    }

    private function page(Request $request, array $where, array $params, string $order): array
    {
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $perPage = $request->perPage(25, 100);
        $page = $request->page();
        $offset = ($page - 1) * $perPage;
        $from = substr(self::SELECT, strpos(self::SELECT, ' FROM bills b'));
        $total = (int) Database::value("SELECT COUNT(*) $from$sqlWhere", $params);
        $rows = Database::all(self::SELECT . "$sqlWhere ORDER BY $order LIMIT $perPage OFFSET $offset", $params);
        return ['bills' => array_map([$this, 'present'], $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** A bill belongs to a patient or to a pharmacy walk-in; both are shown the same way. */
    private function billCustomer(array $r): array
    {
        if ($r['patient_id'] !== null) {
            return PatientService::brief($r);
        }
        return [
            'id' => null,
            'reg_no' => (string) $r['walkin_no'],
            'full_name' => (string) Crypto::decrypt($r['walkin_name_enc'], 'pharmacy_walkins.name'),
            'gender' => $r['walkin_gender'],
            'age' => $r['age_years'] === null ? null : (int) $r['age_years'],
            'is_walk_in' => true,
        ];
    }

    private function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'bill_no' => $r['bill_no'],
            'bill_type' => $r['bill_type'],
            'description' => $r['description'],
            'status' => $r['status'],
            'gross_amount' => (float) $r['gross_amount'],
            'discount_amount' => (float) $r['discount_amount'],
            'net_amount' => (float) $r['net_amount'],
            'paid_amount' => (float) $r['paid_amount'],
            'refunded_amount' => (float) $r['refunded_amount'],
            'discount_type' => $r['discount_type'],
            'discount_sponsor' => $r['discount_sponsor'],
            'exemption_reason' => $r['exemption_reason'],
            'is_deferred' => (bool) $r['is_deferred'],
            'has_open_refund' => (int) $r['open_refunds'] > 0,
            'created_at' => Format::utc($r['created_at']),
            'created_by' => $r['created_by_name'],
            'paid_at' => Format::utc($r['paid_at']),
            'row_version' => (int) $r['row_version'],
            'visit_no' => $r['visit_no'],
            'patient' => $this->billCustomer($r),
            'payment' => $r['payment_id'] === null ? null : [
                'id' => (int) $r['payment_id'], 'receipt_no' => $r['receipt_no'], 'method' => $r['pay_method'],
                'txn_ref' => $r['txn_ref'], 'received_at' => Format::utc($r['received_at']), 'cashier' => $r['cashier_name'],
            ],
        ];
    }

    private function receiptData(int $paymentId): ?array
    {
        $r = Database::one(
            "SELECT pay.id, pay.receipt_no, pay.amount, pay.method, pay.txn_ref, pay.received_at, pay.voided_at,
                    b.id AS bill_id, b.bill_no, b.bill_type, b.description, b.gross_amount, b.discount_amount, b.net_amount, b.discount_type,
                    v.visit_no, p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
                    w.walkin_no, w.name_enc AS walkin_name_enc, w.gender AS walkin_gender, w.age_years,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS cashier_name
               FROM payments pay
               JOIN bills b ON b.id = pay.bill_id
               -- A counter sale is paid by a walk-in customer, not a patient.
               LEFT JOIN patients p ON p.id = pay.patient_id
               LEFT JOIN pharmacy_walkins w ON w.id = pay.walkin_id
               JOIN users u ON u.id = pay.received_by
               LEFT JOIN visits v ON v.id = b.visit_id
              WHERE pay.id = :id",
            ['id' => $paymentId]
        );
        if ($r === null) {
            return null;
        }
        $items = Database::all('SELECT description, quantity, unit_price, line_total FROM bill_items WHERE bill_id = :id ORDER BY id', ['id' => (int) $r['bill_id']]);
        return [
            'id' => (int) $r['id'],
            'receipt_no' => $r['receipt_no'],
            'amount' => (float) $r['amount'],
            'method' => $r['method'],
            'txn_ref' => $r['txn_ref'],
            'received_at' => Format::utc($r['received_at']),
            'voided' => $r['voided_at'] !== null,
            'cashier' => $r['cashier_name'],
            'bill_no' => $r['bill_no'],
            'bill_type' => $r['bill_type'],
            'description' => $r['description'],
            'gross_amount' => (float) $r['gross_amount'],
            'discount_amount' => (float) $r['discount_amount'],
            'net_amount' => (float) $r['net_amount'],
            'discount_type' => $r['discount_type'],
            'visit_no' => $r['visit_no'],
            'patient' => $this->billCustomer($r),
            'items' => array_map(static fn (array $i) => [
                'description' => $i['description'], 'quantity' => (float) $i['quantity'], 'unit_price' => (float) $i['unit_price'], 'line_total' => (float) $i['line_total'],
            ], $items),
        ];
    }
}
