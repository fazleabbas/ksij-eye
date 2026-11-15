<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\AuthContext;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Sequence;

/**
 * What a clinical record leads to: diagnoses, prescriptions, follow-ups, investigation requests and
 * surgery recommendations. They are kept as drafts while the record is a draft and take effect when
 * the record is completed (numbers assigned, investigations billed, follow-ups listed at reception).
 */
final class ClinicalOrders
{
    public const FOLLOW_UP_PRIORITIES = ['routine', 'important', 'urgent'];
    public const EYES = ['right', 'left', 'both'];

    // ─── Diagnoses ───────────────────────────────────────────────────────

    public static function icdIds(ClinicalInput $in, string $path): array
    {
        $ids = $in->ids($path, 20);
        if ($ids && count(ClinicalCatalog::activeIds('icd10_codes', $ids)) !== count($ids)) {
            $in->error($path, 'Choose diagnoses from the list.');
            return [];
        }
        return $ids;
    }

    public static function saveDiagnoses(string $source, int $recordId, array $visit, array $provisional, array $final, int $userId): void
    {
        Database::execute('DELETE FROM clinical_diagnoses WHERE source = :s AND record_id = :r', ['s' => $source, 'r' => $recordId]);
        foreach (['provisional' => $provisional, 'final' => $final] as $kind => $ids) {
            foreach ($ids as $icdId) {
                Database::execute(
                    'INSERT INTO clinical_diagnoses (patient_id, visit_id, source, record_id, kind, icd10_code_id, created_at, created_by)
                     VALUES (:p, :v, :s, :r, :k, :i, UTC_TIMESTAMP(3), :by)',
                    ['p' => (int) $visit['patient_id'], 'v' => (int) $visit['id'], 's' => $source, 'r' => $recordId, 'k' => $kind, 'i' => $icdId, 'by' => $userId]
                );
            }
        }
    }

    public static function diagnoses(string $source, int $recordId): array
    {
        $out = ['provisional' => [], 'final' => []];
        $rows = Database::all(
            'SELECT d.kind, c.id, c.code, c.description FROM clinical_diagnoses d JOIN icd10_codes c ON c.id = d.icd10_code_id
              WHERE d.source = :s AND d.record_id = :r ORDER BY d.id',
            ['s' => $source, 'r' => $recordId]
        );
        foreach ($rows as $row) {
            $out[$row['kind']][] = ['id' => (int) $row['id'], 'code' => $row['code'], 'description' => $row['description']];
        }
        return $out;
    }

    // ─── Prescriptions ───────────────────────────────────────────────────

    public static function validatePrescription(ClinicalInput $in): array
    {
        $items = [];
        foreach ($in->rows('prescription.items', 20) as $i => $_) {
            $p = "prescription.items.$i";
            $drugId = $in->int("$p.drug_id", 1, 2147483647);
            // A medicine the pharmacy does not stock is kept by name; the patient buys it outside.
            $outside = $drugId === null ? $in->text("$p.outside_name", 150) : null;
            if ($drugId === null && $outside === null && !$in->hasError("$p.drug_id")) {
                $in->error("$p.drug_id", 'Choose a medicine, or type its name.');
            }
            $items[] = [
                'drug_id' => $drugId,
                'outside_name' => $outside,
                'quantity' => $in->int("$p.quantity", 1, 999) ?? 1,
                'dose' => $in->text("$p.dose", 40),
                'frequency' => $in->text("$p.frequency", 20),
                'duration' => $in->text("$p.duration", 40),
                'instructions' => $in->text("$p.instructions", 200),
            ];
        }
        $drugIds = array_values(array_unique(array_filter(array_column($items, 'drug_id'))));
        if ($drugIds && count(ClinicalCatalog::activeIds('drugs', $drugIds)) !== count($drugIds)) {
            $in->error('prescription.items', 'A medicine is no longer in the catalogue.');
        }
        return ['items' => $items, 'pharmacy_instructions' => $in->text('prescription.pharmacy_instructions', 500)];
    }

    /** Replaces the draft prescription of a record; issued prescriptions are never changed here. */
    public static function savePrescriptionDraft(string $source, int $recordId, array $visit, array $rx, int $userId): void
    {
        $existing = Database::one('SELECT id, status FROM prescriptions WHERE source = :s AND record_id = :r FOR UPDATE', ['s' => $source, 'r' => $recordId]);
        if ($existing !== null && $existing['status'] !== 'draft') {
            return;
        }
        if ($existing !== null) {
            Database::execute('DELETE FROM prescription_items WHERE prescription_id = :id', ['id' => (int) $existing['id']]);
        }
        if (!$rx['items']) {
            if ($existing !== null) {
                Database::execute("DELETE FROM prescriptions WHERE id = :id AND status = 'draft'", ['id' => (int) $existing['id']]);
            }
            return;
        }
        $instructions = Crypto::encrypt($rx['pharmacy_instructions'], 'prescriptions.pharmacy_instructions');
        if ($existing !== null) {
            $id = (int) $existing['id'];
            Database::execute(
                'UPDATE prescriptions SET pharmacy_instructions_enc = :i, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id',
                ['i' => $instructions, 'by' => $userId, 'id' => $id]
            );
        } else {
            $id = Database::insert(
                "INSERT INTO prescriptions (patient_id, visit_id, source, record_id, status, pharmacy_instructions_enc, created_at, created_by)
                 VALUES (:p, :v, :s, :r, 'draft', :i, UTC_TIMESTAMP(3), :by)",
                ['p' => (int) $visit['patient_id'], 'v' => (int) $visit['id'], 's' => $source, 'r' => $recordId, 'i' => $instructions, 'by' => $userId]
            );
        }
        self::writeItems($id, $rx['items']);
    }

    private static function writeItems(int $prescriptionId, array $items): void
    {
        foreach ($items as $i => $item) {
            Database::execute(
                'INSERT INTO prescription_items (prescription_id, drug_id, outside_name, quantity, dose, frequency, duration, instructions_enc, sort_order)
                 VALUES (:rx, :d, :out, :q, :dose, :f, :du, :ins, :o)',
                [
                    'rx' => $prescriptionId, 'd' => $item['drug_id'], 'out' => $item['outside_name'] ?? null,
                    'q' => $item['quantity'], 'dose' => $item['dose'], 'f' => $item['frequency'],
                    'du' => $item['duration'], 'ins' => Crypto::encrypt($item['instructions'], 'prescription_items.instructions'), 'o' => $i,
                ]
            );
        }
    }

    public static function issuePrescription(string $source, int $recordId, int $userId): ?string
    {
        $rx = Database::one("SELECT id FROM prescriptions WHERE source = :s AND record_id = :r AND status = 'draft' FOR UPDATE", ['s' => $source, 'r' => $recordId]);
        if ($rx === null) {
            return null;
        }
        $key = Format::localDateKey();
        $number = sprintf('RX-%s-%03d', $key, Sequence::next("prescription:$key"));
        Database::execute(
            "UPDATE prescriptions SET status = 'issued', prescription_no = :no, issued_at = UTC_TIMESTAMP(3), issued_by = :by,
                    updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
              WHERE id = :id",
            ['no' => $number, 'by' => $userId, 'by2' => $userId, 'id' => (int) $rx['id']]
        );
        return $number;
    }

    /** Until when the issuing doctor may still change an issued prescription; null when they may not. */
    public static function amendableUntil(array $row, ?AuthContext $ctx): ?string
    {
        if ($ctx === null || $row['status'] !== 'issued' || $row['issued_at'] === null || (int) $row['issued_by'] !== $ctx->userId) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $until = (new \DateTimeImmutable($row['issued_at'], $utc))->modify('+' . ClinicalService::editHours() . ' hours');
        return $until > new \DateTimeImmutable('now', $utc) ? $until->format('Y-m-d H:i:s.v') : null;
    }

    /**
     * The issuing doctor changes an issued prescription. The RX number stays; the before and after
     * are kept, and the pharmacy order follows (see PharmacyService::onPrescriptionAmended).
     * Call inside a transaction.
     *
     * @return array{prescription_no:string, effect:string, order_id:?int}
     */
    public static function amendPrescription(string $source, int $recordId, array $rx, string $reason, ?int $rowVersion, AuthContext $ctx): array
    {
        $row = Database::one('SELECT * FROM prescriptions WHERE source = :s AND record_id = :r FOR UPDATE', ['s' => $source, 'r' => $recordId]);
        if ($row === null || $row['status'] !== 'issued') {
            throw HttpException::conflict('not_issued', 'There is no issued prescription on this record.');
        }
        if ($rowVersion !== null && $rowVersion !== (int) $row['row_version']) {
            throw HttpException::conflict('stale_record', 'This prescription was changed meanwhile. Reload it and try again.');
        }
        if (self::amendableUntil($row, $ctx) === null) {
            throw HttpException::conflict('record_locked', (int) $row['issued_by'] === $ctx->userId
                ? 'Prescriptions can only be changed within ' . ClinicalService::editHours() . ' hours of being issued.'
                : 'Only the doctor who issued this prescription can change it.');
        }
        if (!$rx['items']) {
            throw HttpException::validation(['prescription.items' => 'Keep at least one medicine on the prescription.']);
        }
        $id = (int) $row['id'];
        $before = self::snapshot($id, Crypto::decrypt($row['pharmacy_instructions_enc'], 'prescriptions.pharmacy_instructions'));
        $after = [
            'items' => array_map(static fn (array $i) => self::itemShape($i), $rx['items']),
            'pharmacy_instructions' => self::norm($rx['pharmacy_instructions']),
        ];
        if ($before === $after) {
            throw HttpException::validation(['prescription.items' => 'Nothing was changed.']);
        }

        Database::execute('DELETE FROM prescription_items WHERE prescription_id = :id', ['id' => $id]);
        self::writeItems($id, $rx['items']);
        Database::execute(
            'UPDATE prescriptions SET pharmacy_instructions_enc = :i, amend_count = amend_count + 1, amended_at = UTC_TIMESTAMP(3),
                    amended_by = :by, amend_reason_enc = :reason, updated_at = UTC_TIMESTAMP(3), updated_by = :by2,
                    row_version = row_version + 1
              WHERE id = :id',
            [
                'i' => Crypto::encrypt($rx['pharmacy_instructions'], 'prescriptions.pharmacy_instructions'),
                'by' => $ctx->userId, 'by2' => $ctx->userId,
                'reason' => Crypto::encrypt($reason, 'prescriptions.amend_reason'), 'id' => $id,
            ]
        );

        // Only the medicines the pharmacy stocks reach the pharmacy order; typed ones never do.
        $stocked = static fn (array $snap) => array_values(array_filter($snap['items'], static fn (array $i) => $i['drug_id'] !== null));
        $pharmacy = ['effect' => 'none', 'order_id' => null, 'bill_id' => null];
        if ($stocked($before) !== $stocked($after)) {
            $pharmacy = PharmacyService::onPrescriptionAmended($id, $reason, $ctx->userId);
        }

        Database::insert(
            'INSERT INTO prescription_amendments (prescription_id, reason_enc, before_enc, after_enc, pharmacy_effect,
                                                 pharmacy_order_id, cancelled_bill_id, amended_at, amended_by)
             VALUES (:rx, :reason, :before, :after, :effect, :order, :bill, UTC_TIMESTAMP(3), :by)',
            [
                'rx' => $id,
                'reason' => Crypto::encrypt($reason, 'prescription_amendments.reason'),
                'before' => Crypto::encrypt(json_encode($before, JSON_UNESCAPED_UNICODE), 'prescription_amendments.before'),
                'after' => Crypto::encrypt(json_encode($after, JSON_UNESCAPED_UNICODE), 'prescription_amendments.after'),
                'effect' => $pharmacy['effect'], 'order' => $pharmacy['order_id'], 'bill' => $pharmacy['bill_id'], 'by' => $ctx->userId,
            ]
        );
        return ['prescription_no' => (string) $row['prescription_no'], 'effect' => $pharmacy['effect'], 'order_id' => $pharmacy['order_id']];
    }

    /** The prescription as stored, in the same shape as a validated one, for comparing and keeping. */
    private static function snapshot(int $prescriptionId, ?string $pharmacyInstructions): array
    {
        $items = Database::all(
            'SELECT drug_id, outside_name, quantity, dose, frequency, duration, instructions_enc
               FROM prescription_items WHERE prescription_id = :id ORDER BY sort_order, id',
            ['id' => $prescriptionId]
        );
        return [
            'items' => array_map(static fn (array $i) => self::itemShape([
                'drug_id' => $i['drug_id'] === null ? null : (int) $i['drug_id'],
                'outside_name' => $i['outside_name'], 'quantity' => (int) $i['quantity'],
                'dose' => $i['dose'], 'frequency' => $i['frequency'], 'duration' => $i['duration'],
                'instructions' => Crypto::decrypt($i['instructions_enc'], 'prescription_items.instructions'),
            ]), $items),
            'pharmacy_instructions' => self::norm($pharmacyInstructions),
        ];
    }

    private static function itemShape(array $i): array
    {
        return [
            'drug_id' => $i['drug_id'] === null ? null : (int) $i['drug_id'],
            'outside_name' => $i['drug_id'] === null ? self::norm($i['outside_name'] ?? null) : '',
            'quantity' => (int) $i['quantity'],
            'dose' => self::norm($i['dose']), 'frequency' => self::norm($i['frequency']),
            'duration' => self::norm($i['duration']), 'instructions' => self::norm($i['instructions']),
        ];
    }

    private static function norm(?string $value): string
    {
        return trim((string) $value);
    }

    public static function prescription(string $source, int $recordId): ?array
    {
        $rx = Database::one(
            "SELECT p.id, p.prescription_no, p.status, p.pharmacy_instructions_enc, p.issued_at, p.issued_by AS issued_by_id,
                    p.amend_count, p.amended_at, p.amend_reason_enc, p.row_version,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS issued_by,
                    TRIM(CONCAT_WS(' ', au.first_name, au.last_name)) AS amended_by,
                    o.status AS pharmacy_status
               FROM prescriptions p
               LEFT JOIN users u ON u.id = p.issued_by
               LEFT JOIN users au ON au.id = p.amended_by
               LEFT JOIN pharmacy_orders o ON o.prescription_id = p.id
              WHERE p.source = :s AND p.record_id = :r",
            ['s' => $source, 'r' => $recordId]
        );
        if ($rx === null) {
            return null;
        }
        $items = Database::all(
            'SELECT i.drug_id, i.outside_name, i.quantity, i.dose, i.frequency, i.duration, i.instructions_enc,
                    COALESCE(d.name, i.outside_name) AS name, d.dosage_form
               FROM prescription_items i LEFT JOIN drugs d ON d.id = i.drug_id
              WHERE i.prescription_id = :id ORDER BY i.sort_order, i.id',
            ['id' => (int) $rx['id']]
        );
        return [
            'id' => (int) $rx['id'],
            'prescription_no' => $rx['prescription_no'],
            'status' => $rx['status'],
            'issued_at' => Format::utc($rx['issued_at']),
            'issued_by' => $rx['issued_by'] ?: null,
            'row_version' => (int) $rx['row_version'],
            'amendable_until' => Format::utc(self::amendableUntil(
                ['status' => $rx['status'], 'issued_at' => $rx['issued_at'], 'issued_by' => $rx['issued_by_id']], Auth::user()
            )),
            'pharmacy_status' => $rx['pharmacy_status'],
            'amendment' => $rx['amended_at'] === null ? null : [
                'count' => (int) $rx['amend_count'], 'at' => Format::utc($rx['amended_at']), 'by' => $rx['amended_by'] ?: null,
                'reason' => Crypto::decrypt($rx['amend_reason_enc'], 'prescriptions.amend_reason'),
            ],
            'pharmacy_instructions' => Crypto::decrypt($rx['pharmacy_instructions_enc'], 'prescriptions.pharmacy_instructions'),
            'items' => array_map(static fn (array $i) => [
                'drug_id' => $i['drug_id'] === null ? null : (int) $i['drug_id'], 'outside' => $i['drug_id'] === null,
                'name' => $i['name'], 'dosage_form' => $i['dosage_form'], 'quantity' => (int) $i['quantity'],
                'dose' => $i['dose'], 'frequency' => $i['frequency'], 'duration' => $i['duration'],
                'instructions' => Crypto::decrypt($i['instructions_enc'], 'prescription_items.instructions'),
            ], $items),
        ];
    }

    // ─── Follow-ups ──────────────────────────────────────────────────────

    public static function validateFollowUp(ClinicalInput $in, bool $complete): ?array
    {
        if (!$in->bool('follow_up.required')) {
            return null;
        }
        $followUp = [
            'due_date' => $in->date('follow_up.due_date', Format::localToday()),
            'priority' => $in->enum('follow_up.priority', self::FOLLOW_UP_PRIORITIES) ?? 'routine',
            'reason' => $in->text('follow_up.reason', 100),
            'notes' => $in->text('follow_up.notes', 500),
        ];
        if ($followUp['reason'] !== null && !in_array($followUp['reason'], ClinicalCatalog::lookupValues('follow_up_reason'), true)) {
            $in->error('follow_up.reason', 'Choose a reason from the list.');
        }
        if ($complete && $followUp['due_date'] === null && !$in->hasError('follow_up.due_date')) {
            $in->error('follow_up.due_date', 'Choose the follow-up date.');
        }
        return $followUp;
    }

    public static function saveFollowUpDraft(string $source, int $recordId, array $visit, ?array $followUp, int $userId): void
    {
        $existing = Database::one('SELECT id, status FROM follow_ups WHERE source = :s AND record_id = :r FOR UPDATE', ['s' => $source, 'r' => $recordId]);
        if ($existing !== null && $existing['status'] !== 'draft') {
            return;
        }
        if ($followUp === null) {
            if ($existing !== null) {
                Database::execute("DELETE FROM follow_ups WHERE id = :id AND status = 'draft'", ['id' => (int) $existing['id']]);
            }
            return;
        }
        $values = [
            'd' => $followUp['due_date'], 'pr' => $followUp['priority'], 're' => $followUp['reason'],
            'n' => Crypto::encrypt($followUp['notes'], 'follow_ups.notes'), 'by' => $userId,
        ];
        if ($existing !== null) {
            Database::execute(
                'UPDATE follow_ups SET due_date = :d, priority = :pr, reason = :re, notes_enc = :n, updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                        row_version = row_version + 1
                  WHERE id = :id',
                $values + ['id' => (int) $existing['id']]
            );
        } else {
            Database::execute(
                "INSERT INTO follow_ups (patient_id, source_visit_id, source, record_id, due_date, priority, reason, notes_enc, status, created_at, created_by)
                 VALUES (:p, :v, :s, :r, :d, :pr, :re, :n, 'draft', UTC_TIMESTAMP(3), :by)",
                $values + ['p' => (int) $visit['patient_id'], 'v' => (int) $visit['id'], 's' => $source, 'r' => $recordId]
            );
        }
    }

    public static function activateFollowUp(string $source, int $recordId, int $userId): bool
    {
        return Database::execute(
            "UPDATE follow_ups SET status = 'due', updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
              WHERE source = :s AND record_id = :r AND status = 'draft'",
            ['by' => $userId, 's' => $source, 'r' => $recordId]
        ) > 0;
    }

    public static function followUp(string $source, int $recordId): ?array
    {
        $f = Database::one(
            "SELECT f.id, f.due_date, f.priority, f.reason, f.notes_enc, f.status, bv.visit_no AS booked_visit_no
               FROM follow_ups f LEFT JOIN visits bv ON bv.id = f.booked_visit_id
              WHERE f.source = :s AND f.record_id = :r",
            ['s' => $source, 'r' => $recordId]
        );
        return $f === null ? null : [
            'id' => (int) $f['id'], 'due_date' => $f['due_date'], 'priority' => $f['priority'], 'reason' => $f['reason'],
            'notes' => Crypto::decrypt($f['notes_enc'], 'follow_ups.notes'), 'status' => $f['status'], 'booked_visit_no' => $f['booked_visit_no'],
        ];
    }

    // ─── Investigation requests ──────────────────────────────────────────

    public static function validateInvestigations(ClinicalInput $in, AuthContext $ctx): array
    {
        $rows = [];
        foreach ($in->rows('investigations', 10) as $i => $_) {
            $p = "investigations.$i";
            $row = [
                'price_item_id' => $in->int("$p.price_item_id", 1, 2147483647),
                'eye' => $in->enum("$p.eye", self::EYES),
                'priority' => $in->enum("$p.priority", ['routine', 'urgent']) ?? 'routine',
                'preferred_date' => $in->date("$p.preferred_date", Format::localToday()),
                'reason' => $in->text("$p.reason", 500),
            ];
            foreach (['price_item_id' => 'Choose the investigation.', 'eye' => 'Choose which eye.', 'reason' => 'Give the clinical reason.'] as $field => $message) {
                if ($row[$field] === null && !$in->hasError("$p.$field")) {
                    $in->error("$p.$field", $message);
                }
            }
            $rows[] = $row;
        }
        if ($rows && !$ctx->can('ophthalmology.investigations.request')) {
            $in->error('investigations', 'You are not allowed to request investigations.');
        }
        $ids = array_values(array_unique(array_filter(array_column($rows, 'price_item_id'))));
        if ($ids && count(ClinicalCatalog::activeIds('price_items', $ids, "category = 'investigation'")) !== count($ids)) {
            $in->error('investigations', 'An investigation is no longer offered.');
        }
        return $rows;
    }

    public static function saveInvestigationDrafts(int $consultationId, array $visit, array $rows, int $userId): void
    {
        Database::execute("DELETE FROM investigation_requests WHERE consultation_id = :c AND status = 'draft'", ['c' => $consultationId]);
        foreach ($rows as $row) {
            Database::execute(
                "INSERT INTO investigation_requests (patient_id, visit_id, consultation_id, price_item_id, eye, priority, preferred_date, reason_enc,
                                                     status, created_at, created_by)
                 VALUES (:p, :v, :c, :item, :eye, :pr, :d, :reason, 'draft', UTC_TIMESTAMP(3), :by)",
                [
                    'p' => (int) $visit['patient_id'], 'v' => (int) $visit['id'], 'c' => $consultationId, 'item' => $row['price_item_id'],
                    'eye' => $row['eye'], 'pr' => $row['priority'], 'd' => $row['preferred_date'],
                    'reason' => Crypto::encrypt((string) $row['reason'], 'investigation_requests.reason'), 'by' => $userId,
                ]
            );
        }
    }

    /** Numbers the drafts and books each investigation at the diagnostic desk, unbilled. Returns how many were sent. */
    public static function submitInvestigations(int $consultationId, int $userId): int
    {
        $drafts = Database::all(
            "SELECT r.id, r.patient_id, r.visit_id, r.price_item_id, r.preferred_date, r.priority
               FROM investigation_requests r
              WHERE r.consultation_id = :c AND r.status = 'draft' ORDER BY r.id FOR UPDATE",
            ['c' => $consultationId]
        );
        $ctx = Auth::require();
        foreach ($drafts as $draft) {
            $key = Format::localDateKey();
            $number = sprintf('INV-%s-%03d', $key, Sequence::next("investigation:$key"));
            Database::execute(
                'UPDATE investigation_requests SET request_no = :no, status = :s, requested_at = UTC_TIMESTAMP(3), requested_by = :by,
                        updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1
                  WHERE id = :id',
                ['no' => $number, 's' => 'booked', 'by' => $userId, 'id' => (int) $draft['id']]
            );
            $row = Database::one('SELECT eye, reason_enc FROM investigation_requests WHERE id = :id', ['id' => (int) $draft['id']]);
            DiagnosticService::book(
                [
                    'patient_id' => (int) $draft['patient_id'],
                    'referring_user_id' => $userId,
                    'eye' => $row['eye'],
                    'timing' => $draft['preferred_date'] === null ? 'now' : 'later',
                    'scheduled_for' => ($draft['preferred_date'] ?? Format::localToday()) . ' 08:00:00',
                    'procedures' => [[
                        'price_item_id' => (int) $draft['price_item_id'],
                        'remark' => Crypto::decrypt($row['reason_enc'], 'investigation_requests.reason'),
                    ]],
                ],
                $ctx,
                'ophthalmology',
                (int) $draft['visit_id'],
                (int) $draft['id'],
            );
        }
        return count($drafts);
    }

    public static function investigations(int $consultationId): array
    {
        return self::presentInvestigations(Database::all(self::INVESTIGATION_SELECT . ' WHERE r.consultation_id = :c ORDER BY r.id', ['c' => $consultationId]));
    }

    public static function patientInvestigations(int $patientId): array
    {
        return self::presentInvestigations(Database::all(
            self::INVESTIGATION_SELECT . " WHERE r.patient_id = :p AND r.status <> 'draft' ORDER BY r.id DESC LIMIT 50",
            ['p' => $patientId]
        ));
    }

    private const INVESTIGATION_SELECT = "SELECT r.id, r.request_no, r.price_item_id, r.eye, r.priority, r.preferred_date, r.reason_enc, r.status,
            r.requested_at, pi.name, d.id AS booking_id, d.booking_no, d.status AS booking_status, d.scheduled_for, b.bill_no, b.status AS bill_status, v.visit_no,
            TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS requested_by
       FROM investigation_requests r
       JOIN price_items pi ON pi.id = r.price_item_id
       JOIN visits v ON v.id = r.visit_id
       LEFT JOIN diagnostic_bookings d ON d.request_id = r.id
       LEFT JOIN bills b ON b.id = d.bill_id
       LEFT JOIN users u ON u.id = r.requested_by";

    private static function presentInvestigations(array $rows): array
    {
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'request_no' => $r['request_no'], 'price_item_id' => (int) $r['price_item_id'], 'name' => $r['name'],
            'eye' => $r['eye'], 'priority' => $r['priority'], 'preferred_date' => $r['preferred_date'],
            'reason' => Crypto::decrypt($r['reason_enc'], 'investigation_requests.reason'), 'status' => $r['status'],
            'requested_at' => Format::utc($r['requested_at']), 'requested_by' => $r['requested_by'] ?: null,
            'bill_no' => $r['bill_no'], 'bill_status' => $r['bill_status'], 'visit_no' => $r['visit_no'],
            'booking_no' => $r['booking_no'], 'booking_status' => $r['booking_status'], 'scheduled_for' => Format::utc($r['scheduled_for']),
            // The test has been reported: doctors can open the result from the consultation.
            'booking_id' => $r['booking_id'] === null ? null : (int) $r['booking_id'],
            'result_ready' => $r['booking_status'] === 'completed',
        ], $rows);
    }

    /** Keeps diagnostic bookings, spectacle orders and fast-track upgrades in step with their cashier bill. */
    public static function onBillSettled(array $bill, string $outcome): void
    {
        DiagnosticService::onBillSettled($bill, $outcome);
        OpticalService::onBillSettled($bill, $outcome);
        PharmacyService::onBillSettled($bill, $outcome);
        TheatreService::onBillSettled($bill, $outcome);
        VisitService::onBillSettled($bill, $outcome);
    }

    // ─── Surgery recommendations ─────────────────────────────────────────

    public static function validateSurgery(ClinicalInput $in, AuthContext $ctx): array
    {
        $rows = [];
        foreach ($in->rows('surgery', 5) as $i => $_) {
            $p = "surgery.$i";
            $row = [
                'price_item_id' => $in->int("$p.price_item_id", 1, 2147483647),
                'eye' => $in->enum("$p.eye", self::EYES),
                'urgency' => $in->enum("$p.urgency", ['elective', 'urgent', 'emergency']) ?? 'elective',
                'indication' => $in->text("$p.indication", 500),
            ];
            foreach (['price_item_id' => 'Choose the procedure.', 'eye' => 'Choose which eye.', 'indication' => 'Give the indication.'] as $field => $message) {
                if ($row[$field] === null && !$in->hasError("$p.$field")) {
                    $in->error("$p.$field", $message);
                }
            }
            $rows[] = $row;
        }
        if ($rows && !$ctx->can('ophthalmology.surgery.recommend')) {
            $in->error('surgery', 'You are not allowed to recommend surgery.');
        }
        $ids = array_values(array_unique(array_filter(array_column($rows, 'price_item_id'))));
        if ($ids && count(ClinicalCatalog::activeIds('price_items', $ids, "category IN ('surgery', 'procedure')")) !== count($ids)) {
            $in->error('surgery', 'A procedure is no longer offered.');
        }
        return $rows;
    }

    public static function saveSurgeryDrafts(int $consultationId, array $visit, array $rows, int $userId): void
    {
        Database::execute("DELETE FROM surgery_recommendations WHERE consultation_id = :c AND status = 'draft'", ['c' => $consultationId]);
        foreach ($rows as $row) {
            Database::execute(
                "INSERT INTO surgery_recommendations (patient_id, visit_id, consultation_id, price_item_id, eye, urgency, indication_enc, status, created_at, created_by)
                 VALUES (:p, :v, :c, :item, :eye, :u, :ind, 'draft', UTC_TIMESTAMP(3), :by)",
                [
                    'p' => (int) $visit['patient_id'], 'v' => (int) $visit['id'], 'c' => $consultationId, 'item' => $row['price_item_id'],
                    'eye' => $row['eye'], 'u' => $row['urgency'], 'ind' => Crypto::encrypt((string) $row['indication'], 'surgery_recommendations.indication'),
                    'by' => $userId,
                ]
            );
        }
    }

    public static function submitSurgery(int $consultationId, int $userId): int
    {
        $drafts = Database::all("SELECT id FROM surgery_recommendations WHERE consultation_id = :c AND status = 'draft' ORDER BY id FOR UPDATE", ['c' => $consultationId]);
        foreach ($drafts as $draft) {
            $key = Format::localDateKey();
            Database::execute(
                "UPDATE surgery_recommendations SET recommendation_no = :no, status = 'recommended', recommended_at = UTC_TIMESTAMP(3), recommended_by = :by,
                        updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1
                  WHERE id = :id",
                ['no' => sprintf('SRG-%s-%03d', $key, Sequence::next("surgery:$key")), 'by' => $userId, 'id' => (int) $draft['id']]
            );
        }
        return count($drafts);
    }

    public static function surgery(int $consultationId): array
    {
        return self::presentSurgery(Database::all(self::SURGERY_SELECT . ' WHERE s.consultation_id = :c ORDER BY s.id', ['c' => $consultationId]));
    }

    public static function patientSurgery(int $patientId): array
    {
        return self::presentSurgery(Database::all(
            self::SURGERY_SELECT . " WHERE s.patient_id = :p AND s.status <> 'draft' ORDER BY s.id DESC LIMIT 50",
            ['p' => $patientId]
        ));
    }

    private const SURGERY_SELECT = "SELECT s.id, s.recommendation_no, s.price_item_id, s.eye, s.urgency, s.indication_enc, s.status, s.recommended_at,
            pi.name, v.visit_no, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS recommended_by
       FROM surgery_recommendations s
       JOIN price_items pi ON pi.id = s.price_item_id
       JOIN visits v ON v.id = s.visit_id
       LEFT JOIN users u ON u.id = s.recommended_by";

    private static function presentSurgery(array $rows): array
    {
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'recommendation_no' => $r['recommendation_no'], 'price_item_id' => (int) $r['price_item_id'], 'name' => $r['name'],
            'eye' => $r['eye'], 'urgency' => $r['urgency'], 'indication' => Crypto::decrypt($r['indication_enc'], 'surgery_recommendations.indication'),
            'status' => $r['status'], 'recommended_at' => Format::utc($r['recommended_at']), 'recommended_by' => $r['recommended_by'] ?: null,
            'visit_no' => $r['visit_no'],
        ], $rows);
    }
}
