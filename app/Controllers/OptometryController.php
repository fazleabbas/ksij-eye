<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthContext;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Format;
use App\Services\ClinicalCatalog;
use App\Services\ClinicalInput;
use App\Services\ClinicalOrders;
use App\Services\ClinicalService;

/** Optometry: vision, refractions, lens recommendation, clinical assessment, diagnoses, prescription, follow-up and referral. */
final class OptometryController extends ClinicalStageController
{
    protected const STAGE = 'optometry';

    public const REFRACTION_KINDS = ['dry_ark', 'cyclo', 'pmt', 'final'];
    private const TEXT_LIMITS = [
        'va_remarks' => 500, 'pmt_notes' => 500, 'pcp' => 500, 'anterior_segment' => 2000, 'posterior_segment' => 2000,
        'management_plan' => 2000, 'orthoptic' => 2000, 'low_vision' => 2000, 'remarks' => 2000,
    ];

    protected function validate(ClinicalInput $in, bool $complete, AuthContext $ctx): array
    {
        $d = [
            'va_right' => $in->va('va_right'),
            'va_left' => $in->va('va_left'),
            'iop_right' => $in->int('iop_right', 0, 80),
            'iop_left' => $in->int('iop_left', 0, 80),
            'refractions' => [],
            'lens_item_ids' => $in->ids('lens_item_ids', 10),
            'provisional_icd_ids' => ClinicalOrders::icdIds($in, 'provisional_icd_ids'),
            'final_icd_ids' => ClinicalOrders::icdIds($in, 'final_icd_ids'),
            'refer_optical' => $in->bool('refer_optical'),
            'refer_ophthalmology' => $in->bool('refer_ophthalmology'),
            'prescription' => ClinicalOrders::validatePrescription($in),
            'follow_up' => ClinicalOrders::validateFollowUp($in, $complete),
            'final_label' => self::rxLabel($in, 'final_label'),
            'extra_rx' => [],
            'investigations' => ClinicalOrders::validateInvestigations($in, $ctx, 'optometry.investigations.request'),
            'biometry' => self::biometryInput($in),
        ];
        foreach (self::TEXT_LIMITS as $field => $max) {
            $d[$field] = $in->text($field, $max);
        }
        foreach (self::REFRACTION_KINDS as $kind) {
            $d['refractions'][$kind] = self::refractionRow($in, "refractions.$kind");
        }
        self::assertLenses($in, 'lens_item_ids', $d['lens_item_ids']);

        // Further spectacle prescriptions (e.g. reading glasses beside bifocals): each its own
        // final refraction, label and lenses, held to the same rules as the first.
        foreach ($in->rows('extra_rx', 50) as $i => $_) {
            $p = "extra_rx.$i";
            $set = [
                'label' => self::rxLabel($in, "$p.label"),
                'refraction' => self::refractionRow($in, "$p.refraction"),
                'lens_item_ids' => $in->ids("$p.lens_item_ids", 10),
            ];
            self::assertLenses($in, "$p.lens_item_ids", $set['lens_item_ids']);
            if ($complete && $set['refraction']['sph_right'] === null && $set['refraction']['sph_left'] === null && !$in->hasError("$p.refraction.sph_right")) {
                $in->error("$p.refraction.sph_right", 'Record the SPH for at least one eye in this prescription.');
            }
            $d['extra_rx'][] = $set;
        }

        if ($complete) {
            if (!$d['provisional_icd_ids'] && !$in->hasError('provisional_icd_ids')) {
                $in->error('provisional_icd_ids', 'Choose at least one provisional diagnosis.');
            }
            $final = $d['refractions']['final'];
            if ($final['sph_right'] === null && $final['sph_left'] === null && !$in->hasError('refractions.final.sph_right')) {
                $in->error('refractions.final.sph_right', 'Record the final refraction SPH for at least one eye.');
            }
        }
        $in->throwIfInvalid();
        return $d;
    }

    /** One refraction: sphere, cylinder, axis, corrected VA and add per eye, with PD and NPC. */
    private static function refractionRow(ClinicalInput $in, string $p): array
    {
        $row = [];
        foreach (['right', 'left'] as $eye) {
            $row["sph_$eye"] = $in->decimal("$p.sph_$eye", -30, 30, 0.25);
            $row["cyl_$eye"] = $in->decimal("$p.cyl_$eye", -15, 15, 0.25);
            $row["axis_$eye"] = $in->int("$p.axis_$eye", 0, 180);
            $row["cva_$eye"] = $in->va("$p.cva_$eye");
            $row["add_$eye"] = $in->decimal("$p.add_$eye", 0, 4, 0.25);
        }
        $row['pd_mm'] = $in->decimal("$p.pd_mm", 40, 80, 0.5);
        $row['npc'] = $in->text("$p.npc", 20);
        return $row;
    }

    /** A prescription label must come from the administrator's list (Bifocal, Reading, …). */
    private static function rxLabel(ClinicalInput $in, string $path): ?string
    {
        $label = $in->text($path, 60);
        if ($label !== null && !in_array($label, ClinicalCatalog::lookupValues('rx_label'), true)) {
            $in->error($path, 'Choose a label from the list.');
        }
        return $label;
    }

    private static function assertLenses(ClinicalInput $in, string $path, array $ids): void
    {
        if ($ids && count(ClinicalCatalog::activeIds('price_items', $ids, "category = 'lens'")) !== count($ids)) {
            $in->error($path, 'Choose lenses from the price list.');
        }
    }

    protected function write(array $visit, ?array $existing, array $d, AuthContext $ctx, bool $correction): array
    {
        $plain = ['va_right' => $d['va_right'], 'va_left' => $d['va_left'], 'iop_right' => $d['iop_right'], 'iop_left' => $d['iop_left']];
        if (!$correction) {
            $plain['refer_optical'] = $d['refer_optical'] ? 1 : 0;
            // Also after the doctor sent the patient here: the optometrist may send them back.
            $plain['refer_ophthalmology'] = $d['refer_ophthalmology'] ? 1 : 0;
        }
        $texts = array_intersect_key($d, self::TEXT_LIMITS);
        $columns = $plain;
        foreach ($texts as $field => $text) {
            $columns["{$field}_enc"] = Crypto::encrypt($text, "optometry_assessments.$field");
        }

        $changes = [];
        if ($existing === null) {
            $id = self::insertRecord('optometry_assessments', $visit, $columns, $ctx->userId);
        } else {
            $id = (int) $existing['id'];
            $before = array_intersect_key($existing, $plain);
            foreach (array_keys(self::TEXT_LIMITS) as $field) {
                $before[$field] = Crypto::decrypt($existing["{$field}_enc"], "optometry_assessments.$field");
            }
            $before['refractions'] = self::refractions($id);
            $before['final_label'] = Database::value(
                "SELECT label FROM optometry_refractions WHERE assessment_id = :a AND kind = 'final' AND set_no = 1", ['a' => $id]);
            $before['extra_rx'] = array_map(static fn (array $x) => ['label' => $x['label'], 'refraction' => $x['refraction']], self::extraSets($id));
            $diagnoses = ClinicalOrders::diagnoses('optometry', $id);
            $before['provisional_icd_ids'] = array_column($diagnoses['provisional'], 'id');
            $before['final_icd_ids'] = array_column($diagnoses['final'], 'id');
            $before['biometry'] = self::biometry($id);
            $changes = ClinicalService::changedFields($before, $plain + $texts + [
                'biometry' => $d['biometry'],
                'refractions' => $d['refractions'], 'provisional_icd_ids' => $d['provisional_icd_ids'], 'final_icd_ids' => $d['final_icd_ids'],
                'final_label' => $d['final_label'],
                'extra_rx' => array_map(static fn (array $x) => ['label' => $x['label'], 'refraction' => $x['refraction']], $d['extra_rx']),
            ]);
            self::updateRecord('optometry_assessments', $id, $columns, $ctx->userId);
        }

        foreach ($d['refractions'] as $kind => $row) {
            self::saveRefraction($id, $kind, 1, $kind === 'final' ? $row + ['label' => $d['final_label']] : $row);
        }
        // Further prescriptions are sets 2, 3, … of the final refraction. A correction may change
        // their values and labels; adding or removing one is only possible before completion.
        if ($correction) {
            foreach ($d['extra_rx'] as $i => $set) {
                if (Database::value("SELECT 1 FROM optometry_refractions WHERE assessment_id = :a AND kind = 'final' AND set_no = :n", ['a' => $id, 'n' => $i + 2])) {
                    self::saveRefraction($id, 'final', $i + 2, $set['refraction'] + ['label' => $set['label']]);
                }
            }
        } else {
            Database::execute("DELETE FROM optometry_refractions WHERE assessment_id = :a AND kind = 'final' AND set_no > 1", ['a' => $id]);
            foreach ($d['extra_rx'] as $i => $set) {
                self::saveRefraction($id, 'final', $i + 2, $set['refraction'] + ['label' => $set['label']]);
            }
        }
        ClinicalOrders::saveDiagnoses('optometry', $id, $visit, $d['provisional_icd_ids'], $d['final_icd_ids'], $ctx->userId);
        // Biometry is a measurement, so a correction may change it too.
        self::saveBiometry($id, $d['biometry']);

        if (!$correction) {
            Database::execute('DELETE FROM optometry_lens_items WHERE assessment_id = :a', ['a' => $id]);
            $lensSets = [1 => $d['lens_item_ids']];
            foreach ($d['extra_rx'] as $i => $set) {
                $lensSets[$i + 2] = $set['lens_item_ids'];
            }
            foreach ($lensSets as $setNo => $itemIds) {
                foreach (array_unique($itemIds) as $itemId) {
                    Database::execute('INSERT INTO optometry_lens_items (assessment_id, set_no, price_item_id) VALUES (:a, :s, :i)',
                        ['a' => $id, 's' => $setNo, 'i' => $itemId]);
                }
            }
            ClinicalOrders::savePrescriptionDraft('optometry', $id, $visit, $d['prescription'], $ctx->userId);
            ClinicalOrders::saveFollowUpDraft('optometry', $id, $visit, $d['follow_up'], $ctx->userId);
            ClinicalOrders::saveInvestigationDrafts($id, $visit, $d['investigations'], $ctx->userId, 'optometry');
        }
        return [$id, $changes];
    }

    protected function onComplete(array $visit, int $recordId, array $data, AuthContext $ctx): array
    {
        $parts = [];
        if ($rx = ClinicalOrders::issuePrescription('optometry', $recordId, $ctx->userId)) {
            $parts[] = "prescription $rx";
        }
        if (ClinicalOrders::activateFollowUp('optometry', $recordId, $ctx->userId)) {
            $parts[] = 'follow-up booked for reception';
        }
        if ($count = ClinicalOrders::submitInvestigations($recordId, $ctx->userId, 'optometry')) {
            $parts[] = "$count investigation" . ($count === 1 ? '' : 's') . ' booked at Diagnostics';
        }
        if ($data['refer_optical']) {
            $parts[] = 'referred to the Optical Room';
        }
        $extra = $parts ? ' (' . implode(', ', $parts) . ')' : '';
        // The patient may go to and fro between the doctor and Optometry as often as needed: a
        // referral back reopens the doctor's completed consultation to continue in.
        $fromDoctor = self::doctorReferred((int) $visit['id']);
        if ($data['refer_ophthalmology']) {
            $reopened = OphthalmologyController::reopenForOptometry((int) $visit['id'], $ctx->userId);
            return ['waiting_ophthalmology', 'Optometry complete — ' . ($fromDoctor ? 'referred back to Ophthalmology' : 'referred to Ophthalmology')
                . $extra . ($reopened ? ' (consultation reopened)' : '')];
        }
        return $fromDoctor
            ? ['completed', "Optometry complete after the doctor's referral$extra"]
            : ['completed', "Optometry complete$extra"];
    }

    public static function loadRecord(int $visitId): ?array
    {
        $r = Database::one(
            'SELECT r.*, ' . ClinicalService::AUDIT_COLUMNS . ' FROM optometry_assessments r ' . ClinicalService::AUDIT_JOINS . ' WHERE r.visit_id = :v',
            ['v' => $visitId]
        );
        if ($r === null) {
            return null;
        }
        $id = (int) $r['id'];
        $record = ClinicalService::recordBase($r) + [
            'reopened_at' => Format::utc($r['reopened_at']),
            'reopened_by' => $r['reopened_by'] === null ? null : ClinicalService::userName((int) $r['reopened_by']),
            'va_right' => $r['va_right'],
            'va_left' => $r['va_left'],
            'iop_right' => ClinicalService::toInt($r['iop_right']),
            'iop_left' => ClinicalService::toInt($r['iop_left']),
            'refer_optical' => (bool) $r['refer_optical'],
            'refer_ophthalmology' => (bool) $r['refer_ophthalmology'],
            'refractions' => self::refractions($id),
            'final_label' => Database::value("SELECT label FROM optometry_refractions WHERE assessment_id = :a AND kind = 'final' AND set_no = 1", ['a' => $id]),
            'lens_items' => self::lensItems($id, 1),
            'extra_rx' => self::extraSets($id),
            'diagnoses' => ClinicalOrders::diagnoses('optometry', $id),
            'prescription' => ClinicalOrders::prescription('optometry', $id),
            'follow_up' => ClinicalOrders::followUp('optometry', $id),
            'investigations' => ClinicalOrders::investigations($id, 'optometry'),
            'biometry' => self::biometry($id),
        ];
        foreach (array_keys(self::TEXT_LIMITS) as $field) {
            $record[$field] = Crypto::decrypt($r["{$field}_enc"], "optometry_assessments.$field");
        }
        return $record;
    }

    public static function completedSummary(int $visitId): ?array
    {
        $record = self::loadRecord($visitId);
        if ($record === null || $record['status'] !== 'completed') {
            return null;
        }
        unset($record['_row']);
        return $record;
    }

    protected function upstream(int $visitId): array
    {
        return [
            'triage' => TriageController::completedSummary($visitId),
            // Why the doctor sent the patient here: their completed consultation.
            'ophthalmology' => self::doctorReferred($visitId) ? OphthalmologyController::completedSummary($visitId) : null,
        ];
    }

    /** The doctor completed the consultation of this visit and sent the patient to Optometry for spectacles. */
    public static function doctorReferred(int $visitId): bool
    {
        return (bool) Database::value(
            "SELECT 1 FROM ophthalmology_consultations WHERE visit_id = :v AND status = 'completed' AND refer_optometry = 1",
            ['v' => $visitId]
        );
    }

    /**
     * The doctor sends the patient back for spectacles: an optometry record already completed in
     * this visit becomes a draft again, for the optometrist to add or change the refraction and
     * lenses. An issued prescription and a booked follow-up stay as they are. The earlier
     * referral to Ophthalmology is cleared: the optometrist decides afresh whether to send the
     * patient back to the doctor.
     */
    public static function reopenForDoctor(int $visitId, int $userId): bool
    {
        return Database::execute(
            "UPDATE optometry_assessments SET status = 'draft', refer_ophthalmology = 0, reopened_at = UTC_TIMESTAMP(3), reopened_by = :by,
                    updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
              WHERE visit_id = :v AND status = 'completed'",
            ['by' => $userId, 'by2' => $userId, 'v' => $visitId]
        ) > 0;
    }

    // ─── Biometry (cataract) ─────────────────────────────────────────────

    /** Field => [min, max, step]; null bounds mark the text fields. */
    public const BIOMETRY_FIELDS = [
        'axial_length' => [15, 40, 0.01], 'steep_k' => [30, 65, 0.01], 'flat_k' => [30, 65, 0.01], 'acd' => [1, 6, 0.01],
        'corneal_thickness' => [300, 800, 1], 'lens_thickness' => [2, 7, 0.01], 'white_to_white' => [9, 15, 0.01],
        'a_constant' => [110, 125, 0.01], 'iol_model' => null, 'iol_power' => [-10, 40, 0.01], 'remarks' => null,
    ];

    /** Both eyes, every field (null when not measured). */
    private static function biometryInput(ClinicalInput $in): array
    {
        $out = [];
        foreach (['right', 'left'] as $eye) {
            foreach (self::BIOMETRY_FIELDS as $field => $range) {
                $path = "biometry.$eye.$field";
                $out[$eye][$field] = match (true) {
                    $field === 'iol_model' => $in->text($path, 60),
                    $field === 'remarks' => $in->text($path, 200),
                    $field === 'corneal_thickness' => $in->int($path, $range[0], $range[1]),
                    default => $in->decimal($path, $range[0], $range[1], $range[2]),
                };
            }
        }
        return $out;
    }

    /** Biometry of an assessment, both eyes, every field. */
    public static function biometry(int $assessmentId): array
    {
        $out = ['right' => array_fill_keys(array_keys(self::BIOMETRY_FIELDS), null), 'left' => array_fill_keys(array_keys(self::BIOMETRY_FIELDS), null)];
        foreach (Database::all('SELECT * FROM optometry_biometry WHERE assessment_id = :a', ['a' => $assessmentId]) as $row) {
            $out[$row['eye']] = self::biometryRow($row);
        }
        return $out;
    }

    public static function biometryRow(array $row): array
    {
        $out = [];
        foreach (array_keys(self::BIOMETRY_FIELDS) as $field) {
            $out[$field] = match ($field) {
                'iol_model' => $row['iol_model'],
                'remarks' => Crypto::decrypt($row['remarks_enc'], 'optometry_biometry.remarks'),
                'corneal_thickness' => ClinicalService::toInt($row['corneal_thickness']),
                default => ClinicalService::toFloat($row[$field]),
            };
        }
        return $out;
    }

    private static function saveBiometry(int $assessmentId, array $biometry): void
    {
        Database::execute('DELETE FROM optometry_biometry WHERE assessment_id = :a', ['a' => $assessmentId]);
        foreach ($biometry as $eye => $values) {
            if (!array_filter($values, static fn ($v) => $v !== null)) {
                continue;
            }
            $columns = $values;
            unset($columns['remarks']);
            $columns['remarks_enc'] = Crypto::encrypt($values['remarks'], 'optometry_biometry.remarks');
            $names = array_keys($columns);
            Database::execute(
                'INSERT INTO optometry_biometry (assessment_id, eye, ' . implode(', ', $names) . ') VALUES (:_a, :_e, :' . implode(', :', $names) . ')',
                $columns + ['_a' => $assessmentId, '_e' => $eye]
            );
        }
    }

    private static function saveRefraction(int $assessmentId, string $kind, int $setNo, array $row): void
    {
        $names = array_keys($row);
        Database::execute(
            'INSERT INTO optometry_refractions (assessment_id, kind, set_no, ' . implode(', ', $names) . ') VALUES (:_a, :_k, :_s, :' . implode(', :', $names) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $c) => "$c = VALUES($c)", $names)),
            $row + ['_a' => $assessmentId, '_k' => $kind, '_s' => $setNo]
        );
    }

    /** Refraction rows keyed by kind, every kind present (empty values as null). The final one is prescription 1. */
    private static function refractions(int $assessmentId): array
    {
        $out = array_fill_keys(self::REFRACTION_KINDS, self::refractionValues(null));
        foreach (Database::all('SELECT * FROM optometry_refractions WHERE assessment_id = :a AND set_no = 1', ['a' => $assessmentId]) as $row) {
            $out[$row['kind']] = self::refractionValues($row);
        }
        return $out;
    }

    private static function refractionValues(?array $row): array
    {
        $out = [];
        foreach (['right', 'left'] as $eye) {
            $out["sph_$eye"] = ClinicalService::toFloat($row["sph_$eye"] ?? null);
            $out["cyl_$eye"] = ClinicalService::toFloat($row["cyl_$eye"] ?? null);
            $out["axis_$eye"] = ClinicalService::toInt($row["axis_$eye"] ?? null);
            $out["cva_$eye"] = $row["cva_$eye"] ?? null;
            $out["add_$eye"] = ClinicalService::toFloat($row["add_$eye"] ?? null);
        }
        $out['pd_mm'] = ClinicalService::toFloat($row['pd_mm'] ?? null);
        $out['npc'] = $row['npc'] ?? null;
        return $out;
    }

    private static function lensItems(int $assessmentId, int $setNo): array
    {
        return array_map(static fn (array $l) => ['id' => (int) $l['id'], 'code' => $l['code'], 'name' => $l['name'], 'price' => (float) $l['unit_price']], Database::all(
            'SELECT pi.id, pi.code, pi.name, pi.unit_price FROM optometry_lens_items l JOIN price_items pi ON pi.id = l.price_item_id
              WHERE l.assessment_id = :a AND l.set_no = :s ORDER BY pi.name',
            ['a' => $assessmentId, 's' => $setNo]
        ));
    }

    /** Prescriptions 2, 3, … of the visit: label, final refraction and lenses. */
    private static function extraSets(int $assessmentId): array
    {
        return array_map(static fn (array $row) => [
            'set_no' => (int) $row['set_no'],
            'label' => $row['label'],
            'refraction' => self::refractionValues($row),
            'lens_items' => self::lensItems($assessmentId, (int) $row['set_no']),
        ], Database::all(
            "SELECT * FROM optometry_refractions WHERE assessment_id = :a AND kind = 'final' AND set_no > 1 ORDER BY set_no",
            ['a' => $assessmentId]
        ));
    }
}
