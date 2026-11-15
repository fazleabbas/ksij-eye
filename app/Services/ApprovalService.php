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
 * Approval requests (discounts, bill cancellations, refunds) and the effect of each decision.
 * A request can never be decided by the person who raised it.
 */
final class ApprovalService
{
    public const TYPE_LABELS = [
        'discount' => 'Discount',
        'bill_cancellation' => 'Bill cancellation',
        'refund' => 'Refund',
        'diagnostic_cancellation' => 'Diagnostic cancellation',
        'optical_cancellation' => 'Spectacle order cancellation',
        'theatre_cancellation' => 'Theatre booking cancellation',
    ];

    public static function create(
        string $type,
        ?int $patientId,
        ?int $billId,
        ?int $refundId,
        ?float $amount,
        ?string $discountType,
        ?string $sponsor,
        ?string $remarks,
        int $userId,
        ?int $bookingId = null,
        ?int $opticalOrderId = null,
        ?int $theatreBookingId = null,
    ): array {
        $key = Format::localDateKey();
        $number = sprintf('APR-%s-%03d', $key, Sequence::next("approval:$key"));
        $id = Database::insert(
            'INSERT INTO approval_requests (approval_no, type, status, patient_id, bill_id, refund_id, booking_id, optical_order_id, theatre_booking_id, requested_amount,
                                            discount_type, sponsor, request_remarks_enc, requested_at, requested_by)
             VALUES (:no, :type, :status, :patient, :bill, :refund, :booking, :optical, :theatre, :amount, :dtype, :sponsor, :remarks, UTC_TIMESTAMP(3), :by)',
            [
                'no' => $number, 'type' => $type, 'status' => 'pending', 'patient' => $patientId, 'bill' => $billId,
                'refund' => $refundId, 'booking' => $bookingId, 'optical' => $opticalOrderId, 'theatre' => $theatreBookingId, 'amount' => $amount, 'dtype' => $discountType, 'sponsor' => $sponsor,
                'remarks' => $remarks === null ? null : Crypto::encrypt($remarks, 'approval_requests.request_remarks'),
                'by' => $userId,
            ]
        );
        return ['id' => $id, 'approval_no' => $number];
    }

    public static function approve(int $id, ?float $approvedAmount, ?string $remarks, int $rowVersion, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $approvedAmount, $remarks, $rowVersion, $ctx): array {
            $request = self::lockPending($id, $rowVersion, $ctx);
            $amount = match ($request['type']) {
                'discount' => self::approveDiscount($request, $approvedAmount, $ctx),
                'bill_cancellation' => self::approveCancellation($request, $ctx),
                'diagnostic_cancellation' => self::approveBookingCancellation($request, $ctx),
                'optical_cancellation' => self::approveOpticalCancellation($request, $ctx),
                'theatre_cancellation' => self::approveTheatreCancellation($request, $ctx),
                'refund' => self::approveRefund($request, $approvedAmount),
            };
            self::decide($request, 'approved', $amount, $remarks, $ctx);
            return $request + ['approved_amount_final' => $amount];
        });
    }

    public static function reject(int $id, string $remarks, int $rowVersion, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $remarks, $rowVersion, $ctx): array {
            $request = self::lockPending($id, $rowVersion, $ctx);
            if ($request['type'] === 'diagnostic_cancellation') {
                DiagnosticService::onCancellationDecision($request, false, $ctx);
            } elseif ($request['type'] === 'optical_cancellation') {
                OpticalService::onCancellationDecision($request, false, $ctx);
            } elseif ($request['type'] === 'theatre_cancellation') {
                TheatreService::onCancellationDecision($request, false, $ctx);
            } elseif ($request['type'] === 'refund') {
                Database::execute(
                    "UPDATE refunds SET status = 'rejected', row_version = row_version + 1 WHERE id = :id AND status = 'requested'",
                    ['id' => (int) $request['refund_id']]
                );
            } else {
                $expected = $request['type'] === 'discount' ? 'discount_requested' : 'cancellation_requested';
                $bill = BillingService::lockBill((int) $request['bill_id']);
                if ($bill['status'] === $expected) {
                    Database::execute(
                        "UPDATE bills SET status = 'pending', updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id",
                        ['by' => $ctx->userId, 'id' => (int) $bill['id']]
                    );
                }
            }
            self::decide($request, 'rejected', null, $remarks, $ctx);
            return $request;
        });
    }

    private static function lockPending(int $id, int $rowVersion, AuthContext $ctx): array
    {
        $request = Database::one('SELECT * FROM approval_requests WHERE id = :id FOR UPDATE', ['id' => $id])
            ?? throw HttpException::notFound('Approval request not found.');
        if ($request['status'] !== 'pending') {
            throw HttpException::conflict('already_decided', 'This request has already been decided.');
        }
        if ((int) $request['row_version'] !== $rowVersion) {
            throw HttpException::conflict('stale_record', 'This request changed meanwhile. Reload and try again.');
        }
        // Two people decide money matters, unless this person has been trusted to decide their own.
        if ((int) $request['requested_by'] === $ctx->userId && !$ctx->can('approvals.decide_own')) {
            throw new HttpException(403, 'self_approval', 'You raised this request, so another approver must decide it.');
        }
        return $request;
    }

    private static function decide(array $request, string $status, ?float $amount, ?string $remarks, AuthContext $ctx): void
    {
        Database::execute(
            'UPDATE approval_requests SET status = :s, approved_amount = :amount, decision_remarks_enc = :r,
                    decided_at = UTC_TIMESTAMP(3), decided_by = :by, row_version = row_version + 1
              WHERE id = :id',
            [
                's' => $status, 'amount' => $amount, 'by' => $ctx->userId, 'id' => (int) $request['id'],
                'r' => $remarks === null ? null : Crypto::encrypt($remarks, 'approval_requests.decision_remarks'),
            ]
        );
    }

    private static function approveDiscount(array $request, ?float $amount, AuthContext $ctx): float
    {
        $requested = (float) $request['requested_amount'];
        $amount ??= $requested;
        // Some approvers may give more than the cashier asked for, never more than is owed.
        $mayIncrease = $ctx->can('approvals.increase');
        if ($amount <= 0 || (!$mayIncrease && $amount > $requested + 0.001)) {
            throw HttpException::validation(['approved_amount' => 'Approve more than zero and no more than the amount requested.']);
        }
        $bill = BillingService::lockBill((int) $request['bill_id']);
        if ($bill['status'] !== 'discount_requested') {
            throw HttpException::conflict('bill_state', 'The bill is no longer waiting for this discount.');
        }
        $remaining = (float) $bill['gross_amount'] - (float) $bill['discount_amount'];
        if ($amount > $remaining + 0.001) {
            throw HttpException::validation(['approved_amount' => 'The discount cannot exceed the amount due.']);
        }
        $fullyWaived = $remaining - $amount <= 0.004;
        Database::execute(
            'UPDATE bills SET discount_amount = discount_amount + :amount, discount_type = :type, discount_sponsor = :sponsor, status = :status,
                    updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
              WHERE id = :id',
            [
                'amount' => round($amount, 2), 'type' => $request['discount_type'], 'sponsor' => $request['sponsor'],
                'status' => $fullyWaived ? 'exempted' : 'pending', 'by' => $ctx->userId, 'id' => (int) $bill['id'],
            ]
        );
        if ($fullyWaived) {
            BillingService::releaseVisit($bill['visit_id'] === null ? null : (int) $bill['visit_id'], $ctx->userId, "Fully waived by {$request['approval_no']}");
            ClinicalOrders::onBillSettled($bill, 'paid');
        }
        return round($amount, 2);
    }

    /** Cancels the theatre booking, and with it any bill that has not been paid. */
    private static function approveTheatreCancellation(array $request, AuthContext $ctx): ?float
    {
        TheatreService::onCancellationDecision($request, true, $ctx);
        return null;
    }

    /** Cancels the spectacle order, and with it any bill that has not been paid. */
    private static function approveOpticalCancellation(array $request, AuthContext $ctx): ?float
    {
        OpticalService::onCancellationDecision($request, true, $ctx);
        return null;
    }

    /** Cancels the diagnostic booking, and with it any bill that has not been paid. */
    private static function approveBookingCancellation(array $request, AuthContext $ctx): ?float
    {
        DiagnosticService::onCancellationDecision($request, true, $ctx);
        return null;
    }

    private static function approveCancellation(array $request, AuthContext $ctx): ?float
    {
        $bill = BillingService::lockBill((int) $request['bill_id']);
        if ($bill['status'] !== 'cancellation_requested') {
            throw HttpException::conflict('bill_state', 'The bill is no longer waiting for this cancellation.');
        }
        Database::execute(
            "UPDATE bills SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(3), cancelled_by = :by,
                    updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
              WHERE id = :id",
            ['by' => $ctx->userId, 'by2' => $ctx->userId, 'id' => (int) $bill['id']]
        );
        ClinicalOrders::onBillSettled($bill, 'cancelled');
        if ($bill['visit_id'] !== null) {
            $visit = Database::one('SELECT status FROM visits WHERE id = :id', ['id' => (int) $bill['visit_id']]);
            $otherPaid = (int) Database::value(
                "SELECT COUNT(*) FROM bills WHERE visit_id = :v AND id <> :b AND status IN ('paid', 'partially_refunded', 'refunded')",
                ['v' => (int) $bill['visit_id'], 'b' => (int) $bill['id']]
            );
            if ($visit !== null && $visit['status'] === 'pending_payment' && $otherPaid === 0) {
                VisitService::cancel((int) $bill['visit_id'], "Bill {$bill['bill_no']} cancelled by approval {$request['approval_no']}", $ctx->userId);
            }
        }
        return null;
    }

    private static function approveRefund(array $request, ?float $amount): float
    {
        $refund = Database::one('SELECT * FROM refunds WHERE id = :id FOR UPDATE', ['id' => (int) $request['refund_id']])
            ?? throw HttpException::notFound('Refund not found.');
        if ($refund['status'] !== 'requested') {
            throw HttpException::conflict('refund_state', 'The refund is no longer waiting for approval.');
        }
        $requested = (float) $refund['requested_amount'];
        $amount ??= $requested;
        if ($amount <= 0 || $amount > $requested + 0.001) {
            throw HttpException::validation(['approved_amount' => 'Approve more than zero and no more than the amount requested.']);
        }
        Database::execute(
            "UPDATE refunds SET status = 'approved', approved_amount = :a, row_version = row_version + 1 WHERE id = :id",
            ['a' => round($amount, 2), 'id' => (int) $refund['id']]
        );
        return round($amount, 2);
    }
}
