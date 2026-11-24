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

    /** Visits Reception may end by hand: waiting somewhere, or seen and waiting for the day's other services. */
    public const ENDABLE_STATUSES = ['waiting_triage', 'waiting_doctor', 'waiting_investigation', 'waiting_optometry', 'waiting_ophthalmology', 'awaiting_services'];

    /** Where a re-admitted visit goes: the queue it waits in, and the stage record that reopens. */
    public const READMIT_STAGES = [
        'triage' => ['status' => 'waiting_triage', 'table' => 'triage_assessments', 'label' => 'Triage'],
        'optometry' => ['status' => 'waiting_optometry', 'table' => 'optometry_assessments', 'label' => 'Optometry'],
        'ophthalmology' => ['status' => 'waiting_ophthalmology', 'table' => 'ophthalmology_consultations', 'label' => 'the Doctor'],
    ];

    public static function waitingStatus(string $destination): string
    {
        return match ($destination) {
            'doctor_room' => 'waiting_doctor',
            'triage' => 'waiting_triage',
            'investigation' => 'waiting_investigation',
            'optometry' => 'waiting_optometry',
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
        // raised alongside it when the visit is created.
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

    // ─── Discharge and re-admission ──────────────────────────────────────

    /**
     * The day's services of this visit that are not done yet, besides the clinical stages:
     * tests booked up to today, issued prescriptions not yet collected at the pharmacy, and
     * spectacles the optometrist referred for that the Optical Room has not ordered.
     * Each item is ['kind' => diagnostic|pharmacy|optical, 'label' => text].
     */
    public static function outstanding(int $visitId): array
    {
        $items = [];
        foreach (Database::all(
            "SELECT b.booking_no, b.status, pi.name FROM diagnostic_bookings b JOIN price_items pi ON pi.id = b.price_item_id
              WHERE b.visit_id = :v AND b.status IN ('scheduled', 'arrived', 'cancellation_requested') AND DATE(b.scheduled_for) <= :today
              ORDER BY b.id",
            ['v' => $visitId, 'today' => Format::localToday()]
        ) as $b) {
            $items[] = ['kind' => 'diagnostic', 'label' => "{$b['name']} ({$b['booking_no']}) — " . ($b['status'] === 'arrived' ? 'not yet reported' : 'not yet done')];
        }
        foreach (Database::all(
            "SELECT pr.prescription_no, o.status AS order_status
               FROM prescriptions pr
               LEFT JOIN pharmacy_orders o ON o.prescription_id = pr.id
              WHERE pr.visit_id = :v AND pr.status = 'issued'
                AND EXISTS (SELECT 1 FROM prescription_items i WHERE i.prescription_id = pr.id AND i.drug_id IS NOT NULL)
                AND (o.id IS NULL OR o.status IN ('pending_review', 'awaiting_payment', 'paid'))
              ORDER BY pr.id",
            ['v' => $visitId]
        ) as $p) {
            $items[] = ['kind' => 'pharmacy', 'label' => 'Medicines' . ($p['prescription_no'] ? " ({$p['prescription_no']})" : '') . ' — not yet collected at the Pharmacy'];
        }
        $spectacles = (int) Database::value(
            "SELECT COUNT(*) FROM optometry_assessments a
              WHERE a.visit_id = :v AND a.status = 'completed' AND a.refer_optical = 1
                AND NOT EXISTS (SELECT 1 FROM optical_orders o WHERE o.assessment_id = a.id AND o.order_type = 'referral')",
            ['v' => $visitId]
        );
        if ($spectacles > 0) {
            $items[] = ['kind' => 'optical', 'label' => 'Spectacles — not yet ordered at the Optical Room'];
        }
        return $items;
    }

    /** The status a visit takes when its clinician finishes: discharged, or waiting for the day's other services. */
    public static function dischargeStatus(int $visitId): string
    {
        return self::outstanding($visitId) ? 'awaiting_services' : 'completed';
    }

    /**
     * Discharges a visit waiting for other services once none is left. Called after a test is
     * reported, medicines are handed over or spectacles are ordered. Returns true when discharged.
     */
    public static function settle(?int $visitId, ?int $userId, string $reason = 'All services done'): bool
    {
        if ($visitId === null) {
            return false;
        }
        $visit = Database::one('SELECT id, status FROM visits WHERE id = :id FOR UPDATE', ['id' => $visitId]);
        if ($visit === null || $visit['status'] !== 'awaiting_services' || self::outstanding($visitId)) {
            return false;
        }
        Database::execute(
            "UPDATE visits SET status = 'completed', completed_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                    row_version = row_version + 1
              WHERE id = :id",
            ['by' => $userId, 'id' => $visitId]
        );
        self::recordStatus($visitId, 'awaiting_services', 'completed', $userId, "$reason — discharged");
        return true;
    }

    /**
     * Re-checks recent visits waiting for other services, so one is discharged even when its last
     * service was settled somewhere that does not call settle() (e.g. a test cancelled through approvals).
     */
    public static function settleWaiting(): void
    {
        $ids = array_map('intval', array_column(Database::all(
            "SELECT id FROM visits WHERE status = 'awaiting_services' AND visit_date >= :since ORDER BY id LIMIT 200",
            ['since' => (new \DateTimeImmutable(Format::localToday()))->modify('-30 days')->format('Y-m-d')]
        ), 'id'));
        foreach ($ids as $id) {
            if (!self::outstanding($id)) {
                Database::transaction(static fn () => self::settle($id, null));
            }
        }
    }

    /** Reception ends (discharges) an open visit by hand — e.g. the patient left. Returns what was still outstanding. */
    public static function end(int $visitId, string $reason, int $userId): array
    {
        $visit = Database::one('SELECT id, status, clinical_stage FROM visits WHERE id = :id FOR UPDATE', ['id' => $visitId])
            ?? throw HttpException::notFound('Visit not found.');
        match (true) {
            $visit['status'] === 'in_progress' => throw HttpException::conflict('visit_in_progress',
                'A clinician is seeing this patient now. They must complete the record or return the patient to the queue first.'),
            $visit['status'] === 'pending_payment' => throw HttpException::conflict('visit_unpaid',
                'This visit has not been paid for, so nothing has started. Cancel the visit instead.'),
            $visit['status'] === 'completed' => throw HttpException::conflict('visit_discharged', 'This visit has already been discharged.'),
            $visit['status'] === 'cancelled' => throw HttpException::conflict('visit_cancelled', 'This visit has been cancelled.'),
            default => null,
        };
        $outstanding = self::outstanding($visitId);
        Database::execute(
            "UPDATE visits SET status = 'completed', completed_at = UTC_TIMESTAMP(3), ended_by = :by, end_reason_enc = :r,
                    updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
              WHERE id = :id",
            ['by' => $userId, 'by2' => $userId, 'r' => Crypto::encrypt($reason, 'visits.end_reason'), 'id' => $visitId]
        );
        $left = $visit['status'] === 'awaiting_services' ? [] : ['the ' . self::stageName($visit['status'])];
        if ($outstanding) {
            $left[] = count($outstanding) . ' other service' . (count($outstanding) === 1 ? '' : 's');
        }
        self::recordStatus($visitId, $visit['status'], 'completed', $userId,
            'Visit ended by Reception' . ($left ? ' — still outstanding: ' . implode(', ', $left) : ''));
        return $outstanding;
    }

    /**
     * Brings a discharged visit back: it waits again for Triage, Optometry or the Doctor, with no
     * new fee, and that stage's completed record reopens as a draft for the clinician to continue.
     * Returns whether a record was reopened.
     */
    public static function readmit(int $visitId, string $stage, string $reason, int $userId): bool
    {
        $target = self::READMIT_STAGES[$stage] ?? throw HttpException::validation(['stage' => 'Choose where the patient goes.']);
        $visit = Database::one('SELECT id, patient_id, visit_no, visit_date, status FROM visits WHERE id = :id FOR UPDATE', ['id' => $visitId])
            ?? throw HttpException::notFound('Visit not found.');
        if (!in_array($visit['status'], ['completed', 'awaiting_services'], true)) {
            throw HttpException::conflict('visit_not_discharged', $visit['status'] === 'cancelled'
                ? 'A cancelled visit cannot be re-admitted. Create a new visit.'
                : 'This visit is still open. Only a discharged visit can be re-admitted.');
        }
        $days = self::readmitDays();
        $earliest = (new \DateTimeImmutable(Format::localToday()))->modify("-$days days")->format('Y-m-d');
        if ($visit['visit_date'] < $earliest) {
            throw HttpException::conflict('readmit_too_late', "Only visits from the last $days days can be re-admitted. Create a new visit.");
        }
        $other = Database::value(
            "SELECT visit_no FROM visits WHERE patient_id = :p AND id <> :id AND visit_date = :d AND status NOT IN ('completed', 'cancelled') LIMIT 1",
            ['p' => (int) $visit['patient_id'], 'id' => $visitId, 'd' => Format::localToday()]
        );
        if ($other) {
            throw HttpException::conflict('open_visit_exists', "This patient already has an open visit today ($other). Continue with that visit.");
        }

        Database::execute(
            'UPDATE visits SET status = :s, clinical_stage = NULL, attending_user_id = NULL, attending_since = NULL, completed_at = NULL,
                    ended_by = NULL, end_reason_enc = NULL, readmit_count = readmit_count + 1, readmitted_at = UTC_TIMESTAMP(3),
                    readmitted_by = :by, readmit_reason_enc = :r, updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
              WHERE id = :id',
            ['s' => $target['status'], 'by' => $userId, 'by2' => $userId, 'r' => Crypto::encrypt($reason, 'visits.readmit_reason'), 'id' => $visitId]
        );
        $table = $target['table'];
        $reopened = Database::execute(
            "UPDATE $table SET status = 'draft', reopened_at = UTC_TIMESTAMP(3), reopened_by = :by,
                    updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
              WHERE visit_id = :v AND status = 'completed'",
            ['by' => $userId, 'by2' => $userId, 'v' => $visitId]
        ) > 0;
        self::recordStatus($visitId, $visit['status'], $target['status'], $userId,
            "Re-admitted to {$target['label']}" . ($reopened ? ' (earlier record reopened)' : ''));
        return $reopened;
    }

    public static function readmitDays(): int
    {
        return Settings::int('visits.readmit_days', 7);
    }

    private static function stageName(string $status): string
    {
        return match ($status) {
            'waiting_triage' => 'Triage',
            'waiting_doctor', 'waiting_ophthalmology' => 'Doctor',
            'waiting_investigation' => 'investigation',
            'waiting_optometry' => 'Optometry',
            default => 'visit',
        };
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
        // A test booked by this visit at reception goes with it; tests booked elsewhere stay.
        Database::execute(
            "UPDATE approval_requests ar JOIN diagnostic_bookings db ON db.id = ar.booking_id
                SET ar.status = 'rejected', ar.decided_at = UTC_TIMESTAMP(3), ar.decided_by = :by,
                    ar.decision_remarks_enc = :r, ar.row_version = ar.row_version + 1
              WHERE ar.status = 'pending' AND db.visit_id = :v AND db.source = 'visit'",
            ['by' => $userId, 'v' => $visitId, 'r' => Crypto::encrypt('Closed automatically: the visit was cancelled.', 'approval_requests.decision_remarks')]
        );
        Database::execute(
            "UPDATE diagnostic_bookings SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3),
                    updated_by = :by, row_version = row_version + 1
              WHERE visit_id = :v AND source = 'visit' AND status IN ('scheduled', 'arrived', 'cancellation_requested')",
            ['by' => $userId, 'v' => $visitId]
        );
    }
}
