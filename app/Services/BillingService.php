<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Sequence;

/** Bills and their effects on visits. All amounts are computed server-side. */
final class BillingService
{
    public const ACTIVE_STATUSES = ['pending', 'discount_requested', 'cancellation_requested'];

    public const STATUS_LABELS = [
        'pending' => 'Pending',
        'discount_requested' => 'Discount requested',
        'cancellation_requested' => 'Cancellation requested',
        'paid' => 'Paid',
        'exempted' => 'Exempted',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
        'partially_refunded' => 'Partly refunded',
    ];

    /**
     * @param list<array{price_item_id:?int, description:string, quantity:float, unit_price:float}> $items
     */
    public static function createBill(
        ?int $patientId,
        ?int $visitId,
        string $billType,
        string $description,
        array $items,
        bool $exempt,
        ?string $exemptionReason,
        bool $deferred,
        int $userId,
        string $sourceType = 'visit',
        ?int $sourceId = null,
        ?int $walkinId = null,
    ): int {
        $gross = round(array_sum(array_map(static fn (array $i) => $i['quantity'] * $i['unit_price'], $items)), 2);
        $key = Format::localDateKey();
        $waived = $exempt || $gross <= 0;
        $id = Database::insert(
            'INSERT INTO bills (bill_no, patient_id, walkin_id, visit_id, bill_type, source_type, source_id, description, status,
                                gross_amount, discount_amount, discount_type, exemption_reason, is_deferred, created_at, created_by)
             VALUES (:no, :patient, :walkin, :visit, :type, :stype, :sid, :description, :status,
                     :gross, :discount, :dtype, :reason, :deferred, UTC_TIMESTAMP(3), :by)',
            [
                'no' => sprintf('PAY-%s-%03d', $key, Sequence::next("bill:$key")),
                'patient' => $patientId,
                'walkin' => $walkinId,
                'visit' => $visitId,
                'type' => $billType,
                'stype' => $sourceType,
                'sid' => $sourceType === 'visit' ? $visitId : $sourceId,
                'description' => mb_substr($description, 0, 255),
                'status' => $waived ? 'exempted' : 'pending',
                'gross' => $gross,
                'discount' => $exempt ? $gross : 0,
                'dtype' => $exempt ? 'Exemption (automatic)' : null,
                'reason' => $exemptionReason,
                'deferred' => $deferred ? 1 : 0,
                'by' => $userId,
            ]
        );
        foreach ($items as $item) {
            Database::execute(
                'INSERT INTO bill_items (bill_id, price_item_id, description, quantity, unit_price) VALUES (:b, :p, :d, :q, :u)',
                ['b' => $id, 'p' => $item['price_item_id'], 'd' => mb_substr($item['description'], 0, 255), 'q' => $item['quantity'], 'u' => $item['unit_price']]
            );
        }
        return $id;
    }

    /** Locks a bill row for the rest of the transaction. */
    public static function lockBill(int $billId): array
    {
        return Database::one('SELECT * FROM bills WHERE id = :id FOR UPDATE', ['id' => $billId])
            ?? throw HttpException::notFound('Bill not found.');
    }

    /** Once a visit's bill is settled (paid or fully waived) the patient joins the destination queue. */
    public static function releaseVisit(?int $visitId, int $userId, string $note): void
    {
        if ($visitId === null) {
            return;
        }
        $visit = Database::one('SELECT id, status, destination FROM visits WHERE id = :id FOR UPDATE', ['id' => $visitId]);
        if ($visit !== null && $visit['status'] === 'pending_payment') {
            VisitService::changeStatus((int) $visit['id'], 'pending_payment', VisitService::waitingStatus($visit['destination']), $userId, $note);
        }
    }

    public static function notPayableMessage(string $status): string
    {
        return match ($status) {
            'discount_requested' => 'This bill is waiting for a discount decision. It can be paid once the request is approved or rejected.',
            'cancellation_requested' => 'This bill is waiting for a cancellation decision.',
            'paid', 'partially_refunded', 'refunded' => 'This bill has already been paid.',
            'exempted' => 'This bill is exempted — nothing to pay.',
            'cancelled' => 'This bill has been cancelled.',
            default => 'This bill cannot be paid in its current state.',
        };
    }
}
