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
use App\Services\PatientService;

/** Refunds: cashier request → approver decision (may lower the amount) → cashier records the payout. */
final class RefundController
{
    private const SELECT = "SELECT r.id, r.refund_no, r.requested_amount, r.approved_amount, r.reason_enc, r.status, r.requested_at,
            r.paid_out_at, r.payout_method, r.payout_ref, r.row_version,
            b.id AS bill_id, b.bill_no, b.bill_type, b.description, b.paid_amount, b.refunded_amount,
            pay.receipt_no,
            a.approval_no, a.decided_at, a.decision_remarks_enc,
            p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
            TRIM(CONCAT_WS(' ', ru.first_name, ru.last_name)) AS requested_by_name,
            TRIM(CONCAT_WS(' ', du.first_name, du.last_name)) AS decided_by_name,
            TRIM(CONCAT_WS(' ', ou.first_name, ou.last_name)) AS paid_out_by_name
       FROM refunds r
       JOIN bills b ON b.id = r.bill_id
       JOIN payments pay ON pay.id = r.payment_id
       JOIN patients p ON p.id = r.patient_id
       JOIN users ru ON ru.id = r.requested_by
       LEFT JOIN approval_requests a ON a.id = r.approval_id
       LEFT JOIN users du ON du.id = a.decided_by
       LEFT JOIN users ou ON ou.id = r.paid_out_by";

    public function index(Request $request): array
    {
        $tab = $request->query('tab', 'pending');
        $where = [$tab === 'history' ? "r.status IN ('rejected', 'paid_out')" : "r.status IN ('requested', 'approved')"];
        $params = [];
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            $value = $request->query($key);
            if ($value === '') {
                continue;
            }
            if (!Format::isDate($value)) {
                throw HttpException::validation([$key => 'Use a valid date.']);
            }
            $where[] = "r.requested_at $operator :$key";
            $params[$key] = Format::localDateToUtc($value, $key === 'to');
        }
        if ($condition = PatientService::searchCondition($request->query('search'))) {
            [$where[], $searchParams] = $condition;
            $params += $searchParams;
        }
        $rows = Database::all(self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC LIMIT 200', $params);
        return ['refunds' => array_map([$this, 'present'], $rows)];
    }

    /** Paid bills of a patient that can still be refunded. */
    public function eligible(Request $request): array
    {
        $patientId = (int) $request->query('patient_id', '0');
        if ($patientId <= 0) {
            throw HttpException::validation(['patient_id' => 'Choose a patient.']);
        }
        $rows = Database::all(
            "SELECT b.id, b.bill_no, b.bill_type, b.description, b.net_amount, b.paid_amount, b.refunded_amount, b.paid_at, v.visit_no,
                    (SELECT pay.receipt_no FROM payments pay WHERE pay.bill_id = b.id AND pay.voided_at IS NULL ORDER BY pay.id DESC LIMIT 1) AS receipt_no
               FROM bills b LEFT JOIN visits v ON v.id = b.visit_id
              WHERE b.patient_id = :p AND b.status IN ('paid', 'partially_refunded') AND b.paid_amount > b.refunded_amount
                AND NOT EXISTS (SELECT 1 FROM refunds r WHERE r.bill_id = b.id AND r.status IN ('requested', 'approved'))
              ORDER BY b.paid_at DESC LIMIT 50",
            ['p' => $patientId]
        );
        return ['bills' => array_map(static fn (array $b) => [
            'id' => (int) $b['id'], 'bill_no' => $b['bill_no'], 'bill_type' => $b['bill_type'], 'description' => $b['description'],
            'paid_amount' => (float) $b['paid_amount'], 'refunded_amount' => (float) $b['refunded_amount'],
            'refundable' => round((float) $b['paid_amount'] - (float) $b['refunded_amount'], 2),
            'receipt_no' => $b['receipt_no'], 'visit_no' => $b['visit_no'], 'paid_at' => Format::utc($b['paid_at']),
        ], $rows)];
    }

    public function store(Request $request): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->int('bill_id', true, 1)
            ->decimal('amount', true, 1, 100000000)
            ->string('reason', true, 500, 5)
            ->validate();

        $result = Database::transaction(function () use ($v, $ctx): array {
            $bill = BillingService::lockBill($v['bill_id']);
            if (!in_array($bill['status'], ['paid', 'partially_refunded'], true)) {
                throw HttpException::conflict('bill_state', 'Only paid bills can be refunded.');
            }
            if ((int) Database::value("SELECT COUNT(*) FROM refunds WHERE bill_id = :b AND status IN ('requested', 'approved')", ['b' => (int) $bill['id']]) > 0) {
                throw HttpException::conflict('refund_open', 'This bill already has a refund in progress.');
            }
            $refundable = round((float) $bill['paid_amount'] - (float) $bill['refunded_amount'], 2);
            if ($v['amount'] > $refundable) {
                throw HttpException::validation(['amount' => 'The refund cannot exceed TZS ' . number_format($refundable) . ' (amount paid and not yet refunded).']);
            }
            $paymentId = (int) Database::value('SELECT id FROM payments WHERE bill_id = :b AND voided_at IS NULL ORDER BY id DESC LIMIT 1', ['b' => (int) $bill['id']]);
            $key = Format::localDateKey();
            $refundNo = sprintf('REF-%s-%03d', $key, Sequence::next("refund:$key"));
            $refundId = Database::insert(
                'INSERT INTO refunds (refund_no, bill_id, payment_id, patient_id, requested_amount, reason_enc, status, requested_at, requested_by)
                 VALUES (:no, :bill, :payment, :patient, :amount, :reason, :status, UTC_TIMESTAMP(3), :by)',
                ['no' => $refundNo, 'bill' => (int) $bill['id'], 'payment' => $paymentId, 'patient' => (int) $bill['patient_id'], 'amount' => $v['amount'],
                 'reason' => Crypto::encrypt($v['reason'], 'refunds.reason'), 'status' => 'requested', 'by' => $ctx->userId]
            );
            $approval = ApprovalService::create('refund', (int) $bill['patient_id'], (int) $bill['id'], $refundId, $v['amount'], null, null, null, $ctx->userId);
            Database::execute('UPDATE refunds SET approval_id = :a WHERE id = :id', ['a' => $approval['id'], 'id' => $refundId]);
            return ['refund_id' => $refundId, 'refund_no' => $refundNo, 'approval_no' => $approval['approval_no'], 'bill_no' => $bill['bill_no']];
        });

        Audit::log('cashier', 'refund.requested', "Requested refund {$result['refund_no']} of TZS " . number_format($v['amount']) . " on {$result['bill_no']} ({$result['approval_no']})", 'refund', $result['refund_no']);
        return ['refund' => $this->find($result['refund_id'])];
    }

    public function payout(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->enum('method', ['cash', 'lipa_number'])
            ->string('reference', false, 60)
            ->int('row_version', true, 1)
            ->validate();
        $reference = $v['method'] === 'lipa_number' ? strtoupper((string) preg_replace('/\s+/', '', (string) $v['reference'])) : null;
        if ($v['method'] === 'lipa_number' && !preg_match('/^[A-Z0-9-]{4,60}$/', (string) $reference)) {
            throw HttpException::validation(['reference' => 'Enter the mobile money transaction reference.']);
        }

        $refundNo = Database::transaction(function () use ($params, $v, $reference, $ctx): string {
            $refund = Database::one('SELECT * FROM refunds WHERE id = :id FOR UPDATE', ['id' => $params['id']])
                ?? throw HttpException::notFound('Refund not found.');
            if ((int) $refund['row_version'] !== $v['row_version']) {
                throw HttpException::conflict('stale_record', 'This refund changed meanwhile. Reload and try again.');
            }
            if ($refund['status'] !== 'approved') {
                throw HttpException::conflict('refund_state', 'Only approved refunds can be paid out.');
            }
            $bill = BillingService::lockBill((int) $refund['bill_id']);
            $refunded = round((float) $bill['refunded_amount'] + (float) $refund['approved_amount'], 2);
            if ($refunded > (float) $bill['paid_amount'] + 0.001) {
                throw HttpException::conflict('refund_exceeds_payment', 'This refund would exceed the amount paid.');
            }
            Database::execute(
                "UPDATE refunds SET status = 'paid_out', paid_out_at = UTC_TIMESTAMP(3), paid_out_by = :by, payout_method = :m, payout_ref = :r,
                        row_version = row_version + 1 WHERE id = :id",
                ['by' => $ctx->userId, 'm' => $v['method'], 'r' => $reference, 'id' => (int) $refund['id']]
            );
            Database::execute(
                'UPDATE bills SET refunded_amount = :refunded, status = :status, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id',
                ['refunded' => $refunded, 'status' => $refunded >= (float) $bill['paid_amount'] - 0.004 ? 'refunded' : 'partially_refunded', 'by' => $ctx->userId, 'id' => (int) $bill['id']]
            );
            return $refund['refund_no'];
        });

        Audit::log('cashier', 'refund.paid_out', "Paid out refund $refundNo", 'refund', $refundNo, ['method' => $v['method']]);
        return ['refund' => $this->find($params['id'])];
    }

    private function find(int $id): array
    {
        $row = Database::one(self::SELECT . ' WHERE r.id = :id', ['id' => $id]) ?? throw HttpException::notFound('Refund not found.');
        return $this->present($row);
    }

    private function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'refund_no' => $r['refund_no'],
            'status' => $r['status'],
            'requested_amount' => (float) $r['requested_amount'],
            'approved_amount' => $r['approved_amount'] === null ? null : (float) $r['approved_amount'],
            'reason' => Crypto::decrypt($r['reason_enc'], 'refunds.reason'),
            'requested_at' => Format::utc($r['requested_at']),
            'requested_by' => $r['requested_by_name'],
            'approval_no' => $r['approval_no'],
            'decided_at' => Format::utc($r['decided_at']),
            'decided_by' => $r['decided_by_name'] ?: null,
            'decision_remarks' => Crypto::decrypt($r['decision_remarks_enc'], 'approval_requests.decision_remarks'),
            'paid_out_at' => Format::utc($r['paid_out_at']),
            'paid_out_by' => $r['paid_out_by_name'] ?: null,
            'payout_method' => $r['payout_method'],
            'payout_ref' => $r['payout_ref'],
            'row_version' => (int) $r['row_version'],
            'bill' => [
                'id' => (int) $r['bill_id'], 'bill_no' => $r['bill_no'], 'bill_type' => $r['bill_type'], 'description' => $r['description'],
                'paid_amount' => (float) $r['paid_amount'], 'refunded_amount' => (float) $r['refunded_amount'], 'receipt_no' => $r['receipt_no'],
            ],
            'patient' => PatientService::brief($r),
        ];
    }
}
