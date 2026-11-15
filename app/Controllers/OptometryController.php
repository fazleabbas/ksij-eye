<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthContext;
use App\Core\Crypto;
use App\Core\Database;
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
            $changes = ClinicalService::changedFields($before, $plain + $texts + [
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
        if ($data['refer_optical']) {
            $parts[] = 'referred to the Optical Room';
        }
        $extra = $parts ? ' (' . implode(', ', $parts) . ')' : '';
        return $data['refer_ophthalmology']
            ? ['waiting_ophthalmology', "Optometry complete — referred to Ophthalmology$extra"]
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
        return ['triage' => TriageController::completedSummary($visitId)];
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
