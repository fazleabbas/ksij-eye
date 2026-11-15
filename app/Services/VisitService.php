<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Settings;

/** Visit rules: fees and free follow-ups, queue status changes (with history), cancellation. */
final class VisitService
{
    public const SUB_TYPES = [
        'consultation' => ['normal', 'fast_track'],
        'follow_up' => ['doctor_follow_up', 'surgery_follow_up'],
    ];

    public const CONSULTATION_PRICE_CODES = [
        'normal' => 'CONS-NORMAL',
        'fast_track' => 'CONS-FAST',
        'doctor_follow_up' => 'CONS-FOLLOWUP',
        'surgery_follow_up' => 'CONS-SURG-FOLLOWUP',
    ];

    /** Fast track for any follow-up, doctor or surgery, has one price. */
    public const FOLLOW_UP_FAST_TRACK_CODE = 'CONS-FOLLOWUP-FAST';

    public const OPEN_STATUSES = ['pending_payment', 'waiting_triage', 'waiting_doctor', 'waiting_investigation', 'waiting_optometry', 'waiting_ophthalmology'];

    public static function waitingStatus(string $destination): string
    {
        return match ($destination) {
            'doctor_room' => 'waiting_doctor',
            'triage' => 'waiting_triage',
            'investigation' => 'waiting_investigation',
        };
    }

    /**
     * The bill a new visit would raise.
     * Follow-ups are free when the patient paid for a consultation within the configured window;
     * the window counts from a paid consultation, so free follow-ups never extend it.
     * A fast-track follow-up is never free: the patient pays the full fast-track price.
     */
    public static function quote(int $patientId, string $visitType, string $subType, string $destination, ?int $investigationItemId, bool $fastTrack = false): array
    {
        // A visit for an investigation pays the consultation here; the test has its own bill,
        // raised when the patient checks in at Diagnostics.
        if ($destination === 'investigation' && !Database::value(
            'SELECT 1 FROM price_items WHERE id = :id AND category = :c AND is_active = 1',
            ['id' => (int) $investigationItemId, 'c' => 'investigation']
        )) {
            throw HttpException::validation(['investigation_item_id' => 'Choose an available investigation.']);
        }

        $item = self::priceItem($visitType === 'follow_up' && $fastTrack ? self::FOLLOW_UP_FAST_TRACK_CODE : self::CONSULTATION_PRICE_CODES[$subType]);

        $days = Settings::int('visits.revisit_exemption_days', 14);
        if ($visitType === 'follow_up' && !$fastTrack && $days > 0) {
            $paid = Database::one(
                "SELECT v.visit_no, v.visit_date
                   FROM bills b JOIN visits v ON v.id = b.visit_id
                  WHERE b.patient_id = :p AND b.bill_type = 'consultation' AND v.visit_type = 'consultation'
                    AND b.status IN ('paid', 'partially_refunded') AND b.net_amount > 0 AND v.visit_date >= :since
                  ORDER BY v.visit_date DESC, v.id DESC LIMIT 1",
                ['p' => $patientId, 'since' => Format::localToday(-$days)]
            );
            if ($paid !== null) {
                return self::quoteResult('consultation', $item, true, "Free follow-up: within $days days of paid consultation {$paid['visit_no']}");
            }
        }
        return self::quoteResult('consultation', $item, false, null);
    }

    private static function quoteResult(string $billType, array $item, bool $exempt, ?string $reason): array
    {
        $price = (float) $item['unit_price'];
        return [
            'bill_type' => $billType,
            'price_item_id' => (int) $item['id'],
            'price_code' => $item['code'],
            'description' => $item['name'],
            'gross_amount' => $price,
            'net_amount' => $exempt ? 0.0 : $price,
            'exempt' => $exempt,
            'exemption_reason' => $reason,
        ];
    }

    private static function priceItem(string $code): array
    {
        return Database::one('SELECT id, code, name, unit_price FROM price_items WHERE code = :code AND is_active = 1', ['code' => $code])
            ?? throw HttpException::conflict('price_not_configured', "There is no active price for $code. An administrator must set it in the Price List.");
    }

    // ─── Fast track upgrade (cashier) ────────────────────────────────────

    /**
     * What it costs to move a visit to fast track: the fast-track price less what the visit was
     * charged. "Charged" is the consultation price before any discount (the patient keeps the
     * discount); a free follow-up was charged nothing.
     */
    public static function fastTrackQuote(int $visitId): array
    {
        $visit = Database::one(
            'SELECT id, visit_no, patient_id, visit_type, status, is_emergency, is_fast_track, fee_exempt, fast_track_bill_id
               FROM visits WHERE id = :id',
            ['id' => $visitId]
        ) ?? throw HttpException::notFound('Visit not found.');
        $item = self::priceItem($visit['visit_type'] === 'follow_up' ? self::FOLLOW_UP_FAST_TRACK_CODE : self::CONSULTATION_PRICE_CODES['fast_track']);
        $bill = self::consultationBill($visitId);
        $charged = ($bill === null || $visit['fee_exempt'] || in_array($bill['status'], ['exempted', 'cancelled'], true)) ? 0.0 : (float) $bill['gross_amount'];
        $price = (float) $item['unit_price'];
        $pending = $visit['fast_track_bill_id'] === null ? null : Database::one(
            'SELECT id, bill_no, status, net_amount FROM bills WHERE id = :id',
            ['id' => (int) $visit['fast_track_bill_id']]
        );

        $blocked = match (true) {
            (bool) $visit['is_fast_track'] => 'This visit is already fast track.',
            (bool) $visit['is_emergency'] => 'Emergencies are already seen first.',
            $visit['status'] === 'cancelled' => 'This visit has been cancelled.',
            $pending !== null => "Fast track is waiting for payment of bill {$pending['bill_no']}.",
            $bill !== null && in_array($bill['status'], ['discount_requested', 'cancellation_requested'], true)
                => "Bill {$bill['bill_no']} is waiting for an approval decision. Try again once it is decided.",
            default => null,
        };
        return [
            'visit_id' => (int) $visit['id'],
            'visit_no' => $visit['visit_no'],
            'price_item_id' => (int) $item['id'],
            'description' => $item['name'],
            'fast_track_price' => $price,
            'charged' => $charged,
            'balance' => max(0.0, round($price - $charged, 2)),
            // An unpaid first bill is re-priced rather than adding a second bill.
            'reprice_bill' => $bill !== null && $bill['status'] === 'pending' ? $bill['bill_no'] : null,
            'pending_bill' => $pending === null ? null : ['id' => (int) $pending['id'], 'bill_no' => $pending['bill_no'], 'amount' => (float) $pending['net_amount']],
            'blocked' => $blocked,
        ];
    }

    /**
     * Raises the fast-track charge. The visit moves up only once that charge is paid; when
     * nothing is owed it moves up at once.
     */
    public static function requestFastTrack(int $visitId, int $userId): array
    {
        return Database::transaction(function () use ($visitId, $userId): array {
            $visit = Database::one('SELECT id, patient_id FROM visits WHERE id = :id FOR UPDATE', ['id' => $visitId])
                ?? throw HttpException::notFound('Visit not found.');
            $first = self::consultationBill($visitId, true);
            $quote = self::fastTrackQuote($visitId);
            if ($quote['blocked'] !== null) {
                throw HttpException::conflict('fast_track_unavailable', $quote['blocked']);
            }
            $balance = $quote['balance'];
            if ($balance <= 0) {
                self::activateFastTrack($visitId, $userId, null);
                return $quote + ['bill_id' => null, 'bill_no' => null, 'activated' => true];
            }

            $line = ['price_item_id' => $quote['price_item_id'], 'description' => "Fast track upgrade ({$quote['description']})", 'quantity' => 1, 'unit_price' => $balance];
            if ($first !== null && $first['status'] === 'pending') {
                Database::execute(
                    'INSERT INTO bill_items (bill_id, price_item_id, description, quantity, unit_price) VALUES (:b, :p, :d, 1, :u)',
                    ['b' => (int) $first['id'], 'p' => $line['price_item_id'], 'd' => mb_substr($line['description'], 0, 255), 'u' => $balance]
                );
                Database::execute(
                    'UPDATE bills SET gross_amount = gross_amount + :add, description = :d, updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                            row_version = row_version + 1 WHERE id = :id',
                    ['add' => $balance, 'd' => mb_substr($quote['description'], 0, 255), 'by' => $userId, 'id' => (int) $first['id']]
                );
                $billId = (int) $first['id'];
            } else {
                $billId = BillingService::createBill(
                    (int) $visit['patient_id'], $visitId, 'consultation', "Fast track upgrade — {$quote['visit_no']}", [$line],
                    false, null, false, $userId, 'fast_track', $visitId
                );
            }
            Database::execute(
                // Who asked is kept with the request; the visit moves up when the bill is paid.
                'UPDATE visits SET fast_track_bill_id = :b, fast_tracked_by = :by, updated_at = UTC_TIMESTAMP(3), updated_by = :by2,
                        row_version = row_version + 1 WHERE id = :id',
                ['b' => $billId, 'by' => $userId, 'by2' => $userId, 'id' => $visitId]
            );
            $billNo = (string) Database::value('SELECT bill_no FROM bills WHERE id = :id', ['id' => $billId]);
            return ['bill_id' => $billId, 'bill_no' => $billNo, 'activated' => false] + $quote;
        });
    }

    /** Payment of the fast-track charge moves the visit up; cancelling the charge withdraws the request. */
    public static function onBillSettled(array $bill, string $outcome): void
    {
        $visitId = Database::value('SELECT id FROM visits WHERE fast_track_bill_id = :b AND is_fast_track = 0', ['b' => (int) $bill['id']]);
        if ($visitId === null || $visitId === false) {
            return;
        }
        if ($outcome === 'paid') {
            self::activateFastTrack((int) $visitId, null, (int) $bill['id']);
        } elseif ($outcome === 'cancelled') {
            Database::execute('UPDATE visits SET fast_track_bill_id = NULL, fast_tracked_by = NULL, row_version = row_version + 1 WHERE id = :id', ['id' => (int) $visitId]);
        }
    }

    private static function activateFastTrack(int $visitId, ?int $userId, ?int $billId): void
    {
        Database::execute(
            'UPDATE visits SET is_fast_track = 1, fast_track_bill_id = :b, fast_tracked_at = UTC_TIMESTAMP(3), fast_tracked_by = COALESCE(:by, fast_tracked_by),
                    updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1 WHERE id = :id',
            ['b' => $billId, 'by' => $userId, 'id' => $visitId]
        );
        $visit = Database::one('SELECT status, fast_tracked_by FROM visits WHERE id = :id', ['id' => $visitId]);
        self::recordStatus($visitId, $visit['status'], $visit['status'], $visit['fast_tracked_by'] === null ? null : (int) $visit['fast_tracked_by'], 'Upgraded to fast track');
    }

    /** The bill raised when the visit was registered. */
    private static function consultationBill(int $visitId, bool $lock = false): ?array
    {
        return Database::one(
            "SELECT id, bill_no, status, gross_amount FROM bills
              WHERE visit_id = :v AND bill_type = 'consultation' AND source_type = 'visit' ORDER BY id LIMIT 1" . ($lock ? ' FOR UPDATE' : ''),
            ['v' => $visitId]
        );
    }

    /** Moves a visit between statuses (optimistic on the expected current status) and records history. */
    public static function changeStatus(int $visitId, string $from, string $to, int $userId, ?string $note = null): void
    {
        $updated = Database::execute(
            'UPDATE visits SET status = :to, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
              WHERE id = :id AND status = :from',
            ['to' => $to, 'by' => $userId, 'id' => $visitId, 'from' => $from]
        );
        if ($updated === 0) {
            throw HttpException::conflict('stale_record', 'The visit changed meanwhile. Reload and try again.');
        }
        self::recordStatus($visitId, $from, $to, $userId, $note);
    }

    public static function recordStatus(int $visitId, ?string $from, string $to, ?int $userId, ?string $note): void
    {
        Database::execute(
            'INSERT INTO visit_status_history (visit_id, from_status, to_status, changed_at, changed_by, note)
             VALUES (:v, :f, :t, UTC_TIMESTAMP(3), :by, :n)',
            ['v' => $visitId, 'f' => $from, 't' => $to, 'by' => $userId, 'n' => $note === null ? null : mb_substr($note, 0, 255)]
        );
    }

    /**
     * Cancels an open visit: its unpaid bills are cancelled and their pending approval requests closed.
     * Callers must first make sure no bill of the visit has been paid.
     */
    public static function cancel(int $visitId, string $reason, int $userId): void
    {
        $visit = Database::one('SELECT id, status FROM visits WHERE id = :id FOR UPDATE', ['id' => $visitId])
            ?? throw HttpException::notFound('Visit not found.');
        if (!in_array($visit['status'], self::OPEN_STATUSES, true)) {
            throw HttpException::conflict('visit_not_open', 'Only visits that have not started can be cancelled.');
        }
        Database::execute(
            'UPDATE visits SET status = :s, cancelled_at = UTC_TIMESTAMP(3), cancelled_by = :by, cancel_reason_enc = :r,
                    updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
              WHERE id = :id',
            ['s' => 'cancelled', 'by' => $userId, 'by2' => $userId, 'r' => Crypto::encrypt($reason, 'visits.cancel_reason'), 'id' => $visitId]
        );
        self::recordStatus($visitId, $visit['status'], 'cancelled', $userId, 'Visit cancelled');

        $openBills = array_map('intval', array_column(Database::all(
            "SELECT id FROM bills WHERE visit_id = :v AND status IN ('pending', 'discount_requested', 'cancellation_requested', 'exempted') FOR UPDATE",
            ['v' => $visitId]
        ), 'id'));
        if ($openBills) {
            [$in, $params] = Database::in('b', $openBills);
            Database::execute(
                "UPDATE approval_requests SET status = 'rejected', decided_at = UTC_TIMESTAMP(3), decided_by = :by,
                        decision_remarks_enc = :r, row_version = row_version + 1
                  WHERE status = 'pending' AND bill_id IN ($in)",
                $params + ['by' => $userId, 'r' => Crypto::encrypt('Closed automatically: the visit was cancelled.', 'approval_requests.decision_remarks')]
            );
            Database::execute(
                "UPDATE bills SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(3), cancelled_by = :by,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
                  WHERE id IN ($in)",
                $params + ['by' => $userId, 'by2' => $userId]
            );
        }
    }
}
