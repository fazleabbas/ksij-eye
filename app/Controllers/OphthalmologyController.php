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

/**
 * Ophthalmology consultation: examination findings per section and eye, pupils, eye diagrams, diagnoses,
 * notes, prescription, follow-up, investigation requests (billed at the cashier) and surgery recommendations.
 */
final class OphthalmologyController extends ClinicalStageController
{
    protected const STAGE = 'ophthalmology';

    private const PUPIL = [
        'size' => null, 'shape' => ['round', 'irregular', 'oval', 'keyhole'], 'light' => ['brisk', 'sluggish', 'non_reactive', 'rapd'],
        'consensual' => ['present', 'absent'],
    ];
    private const TEXT_LIMITS = ['provisional_dx_notes' => 1000, 'final_dx_notes' => 1000, 'clinical_notes' => 5000];
    private const DIAGRAMS = ['iris', 'cross_section'];

    protected function validate(ClinicalInput $in, bool $complete, AuthContext $ctx): array
    {
        $d = [
            'findings' => [],
            'section_notes' => [],
            'pupil' => [],
            'provisional_icd_ids' => ClinicalOrders::icdIds($in, 'provisional_icd_ids'),
            'final_icd_ids' => ClinicalOrders::icdIds($in, 'final_icd_ids'),
            'diagrams' => $this->validateDiagrams($in),
            'prescription' => ClinicalOrders::validatePrescription($in),
            'follow_up' => ClinicalOrders::validateFollowUp($in, $complete),
            'investigations' => ClinicalOrders::validateInvestigations($in, $ctx),
            'surgery' => ClinicalOrders::validateSurgery($in, $ctx),
        ];
        foreach (self::TEXT_LIMITS as $field => $max) {
            $d[$field] = $in->text($field, $max);
        }
        // Referred to another facility: where to is only kept while the box is ticked.
        $referred = $in->bool('referral.referred');
        $d['referral'] = ['referred' => $referred, 'facility' => $referred ? $in->text('referral.facility', 200) : null];
        if ($referred && $d['referral']['facility'] === null && !$in->hasError('referral.facility')) {
            $in->error('referral.facility', 'Say where the patient is referred to.');
        }

        foreach ($in->rows('findings', 400) as $i => $_) {
            $findingId = $in->int("findings.$i.finding_id", 1, 2147483647);
            $eye = $in->enum("findings.$i.eye", ClinicalOrders::EYES);
            if ($findingId === null || $eye === null) {
                $in->error('findings', 'Invalid examination finding.');
                continue;
            }
            $d['findings']["$findingId|$eye"] = ['finding_id' => $findingId, 'eye' => $eye];
        }
        $d['findings'] = array_values($d['findings']);
        $findingIds = array_values(array_unique(array_column($d['findings'], 'finding_id')));
        if ($findingIds && count(ClinicalCatalog::activeIds('exam_findings', $findingIds)) !== count($findingIds)) {
            $in->error('findings', 'An examination finding is no longer in the list.');
        }

        foreach (array_keys(ClinicalCatalog::EXAM_SECTIONS) as $section) {
            $note = $in->text("section_notes.$section", 200);
            if ($note !== null) {
                $d['section_notes'][$section] = $note;
            }
        }
        foreach (['right', 'left'] as $eye) {
            $d['pupil']["pupil_size_$eye"] = $in->decimal("pupil.size_$eye", 0.5, 12, 0.5);
            foreach (['shape', 'light', 'consensual'] as $aspect) {
                $d['pupil']["pupil_{$aspect}_$eye"] = $in->enum("pupil.{$aspect}_$eye", self::PUPIL[$aspect]);
            }
        }

        // The clinic asked for both diagnoses to be on the record before the doctor finishes.
        if ($complete && !$d['provisional_icd_ids'] && $d['provisional_dx_notes'] === null && !$in->hasError('provisional_icd_ids')) {
            $in->error('provisional_icd_ids', 'Record the provisional diagnosis.');
        }
        if ($complete && !$d['final_icd_ids'] && $d['final_dx_notes'] === null && !$in->hasError('final_icd_ids')) {
            $in->error('final_icd_ids', 'Record the final diagnosis.');
        }
        $in->throwIfInvalid();
        return $d;
    }

    /** Drawing annotations: only known shapes, colours, coordinates and short labels are kept. */
    private function validateDiagrams(ClinicalInput $in): ?array
    {
        $out = [];
        $number = static fn (mixed $v): ?float => (is_int($v) || is_float($v)) && $v >= -50 && $v <= 1200 ? round((float) $v, 1) : null;
        foreach (self::DIAGRAMS as $diagram) {
            $out[$diagram] = [];
            foreach ($in->rows("diagrams.$diagram", 200) as $i => $a) {
                $type = $a['type'] ?? null;
                $color = $a['color'] ?? null;
                if (!in_array($type, ['pen', 'arrow', 'circle', 'text', 'marker'], true) || !in_array($color, ClinicalCatalog::DIAGRAM_COLORS, true)) {
                    $in->error('diagrams', 'The drawing contains an unsupported mark.');
                    return null;
                }
                $mark = ['type' => $type, 'color' => $color];
                $keys = match ($type) {
                    'arrow' => ['x1', 'y1', 'x2', 'y2'],
                    'circle' => ['cx', 'cy', 'rx', 'ry'],
                    'text', 'marker' => ['x', 'y'],
                    'pen' => [],
                };
                foreach ($keys as $key) {
                    $mark[$key] = $number($a[$key] ?? null);
                    if ($mark[$key] === null) {
                        $in->error('diagrams', 'The drawing contains an invalid position.');
                        return null;
                    }
                }
                if ($type === 'text') {
                    $mark['text'] = $in->text("diagrams.$diagram.$i.text", 60) ?? '';
                }
                if ($type === 'pen') {
                    $path = $a['path'] ?? null;
                    if (!is_array($path) || count($path) < 1 || count($path) > 2000) {
                        $in->error('diagrams', 'A drawn line is too long.');
                        return null;
                    }
                    foreach ($path as $point) {
                        $x = is_array($point) ? $number($point[0] ?? null) : null;
                        $y = is_array($point) ? $number($point[1] ?? null) : null;
                        if ($x === null || $y === null) {
                            $in->error('diagrams', 'The drawing contains an invalid position.');
                            return null;
                        }
                        $mark['path'][] = [$x, $y];
                    }
                }
                $out[$diagram][] = $mark;
            }
        }
        return array_filter($out) ? $out : null;
    }

    protected function write(array $visit, ?array $existing, array $d, AuthContext $ctx, bool $correction): array
    {
        $plain = $d['pupil'];
        $texts = array_intersect_key($d, self::TEXT_LIMITS);
        $columns = $plain + [
            'diagrams_enc' => $d['diagrams'] === null ? null : Crypto::encrypt(json_encode($d['diagrams'], JSON_UNESCAPED_UNICODE), 'ophthalmology_consultations.diagrams'),
            'referred_out' => $d['referral']['referred'] ? 1 : 0,
            'referral_facility_enc' => Crypto::encrypt($d['referral']['facility'], 'ophthalmology_consultations.referral_facility'),
        ];
        foreach ($texts as $field => $text) {
            $columns["{$field}_enc"] = Crypto::encrypt($text, "ophthalmology_consultations.$field");
        }

        $changes = [];
        if ($existing === null) {
            $id = self::insertRecord('ophthalmology_consultations', $visit, $columns, $ctx->userId);
        } else {
            $id = (int) $existing['id'];
            $before = array_intersect_key($existing, $plain);
            foreach (array_keys(self::TEXT_LIMITS) as $field) {
                $before[$field] = Crypto::decrypt($existing["{$field}_enc"], "ophthalmology_consultations.$field");
            }
            $before['diagrams'] = self::decodeDiagrams($existing['diagrams_enc']);
            $before['referral'] = self::referral($existing);
            $before['findings'] = array_map(static fn (array $f) => $f['finding_id'] . '|' . $f['eye'], self::findings($id));
            $before['section_notes'] = self::sectionNotes($id);
            $diagnoses = ClinicalOrders::diagnoses('ophthalmology', $id);
            $before['provisional_icd_ids'] = array_column($diagnoses['provisional'], 'id');
            $before['final_icd_ids'] = array_column($diagnoses['final'], 'id');
            $changes = ClinicalService::changedFields($before, $plain + $texts + [
                'diagrams' => $d['diagrams'],
                'referral' => $d['referral'],
                'findings' => array_map(static fn (array $f) => $f['finding_id'] . '|' . $f['eye'], $d['findings']),
                'section_notes' => $d['section_notes'],
                'provisional_icd_ids' => $d['provisional_icd_ids'],
                'final_icd_ids' => $d['final_icd_ids'],
            ]);
            self::updateRecord('ophthalmology_consultations', $id, $columns, $ctx->userId);
        }

        Database::execute('DELETE FROM ophthalmology_findings WHERE consultation_id = :c', ['c' => $id]);
        foreach ($d['findings'] as $finding) {
            Database::execute(
                'INSERT INTO ophthalmology_findings (consultation_id, finding_id, eye) VALUES (:c, :f, :e)',
                ['c' => $id, 'f' => $finding['finding_id'], 'e' => $finding['eye']]
            );
        }
        Database::execute('DELETE FROM ophthalmology_section_notes WHERE consultation_id = :c', ['c' => $id]);
        foreach ($d['section_notes'] as $section => $note) {
            Database::execute(
                'INSERT INTO ophthalmology_section_notes (consultation_id, section, note_enc) VALUES (:c, :s, :n)',
                ['c' => $id, 's' => $section, 'n' => Crypto::encrypt($note, 'ophthalmology_section_notes.note')]
            );
        }
        ClinicalOrders::saveDiagnoses('ophthalmology', $id, $visit, $d['provisional_icd_ids'], $d['final_icd_ids'], $ctx->userId);

        if (!$correction) {
            ClinicalOrders::savePrescriptionDraft('ophthalmology', $id, $visit, $d['prescription'], $ctx->userId);
            ClinicalOrders::saveFollowUpDraft('ophthalmology', $id, $visit, $d['follow_up'], $ctx->userId);
            ClinicalOrders::saveInvestigationDrafts($id, $visit, $d['investigations'], $ctx->userId);
            ClinicalOrders::saveSurgeryDrafts($id, $visit, $d['surgery'], $ctx->userId);
        }
        return [$id, $changes];
    }

    protected function onComplete(array $visit, int $recordId, array $data, AuthContext $ctx): array
    {
        $parts = [];
        if ($rx = ClinicalOrders::issuePrescription('ophthalmology', $recordId, $ctx->userId)) {
            $parts[] = "prescription $rx";
        }
        if ($count = ClinicalOrders::submitInvestigations($recordId, $ctx->userId)) {
            $parts[] = "$count investigation" . ($count === 1 ? '' : 's') . ' sent to the cashier';
        }
        if ($count = ClinicalOrders::submitSurgery($recordId, $ctx->userId)) {
            $parts[] = "$count surgery recommendation" . ($count === 1 ? '' : 's');
        }
        if (ClinicalOrders::activateFollowUp('ophthalmology', $recordId, $ctx->userId)) {
            $parts[] = 'follow-up booked for reception';
        }
        if ($data['referral']['referred']) {
            $parts[] = 'referred to ' . $data['referral']['facility'];
        }
        return ['completed', 'Ophthalmology consultation complete' . ($parts ? ' (' . implode(', ', $parts) . ')' : '')];
    }

    public static function loadRecord(int $visitId): ?array
    {
        $r = Database::one(
            'SELECT r.*, ' . ClinicalService::AUDIT_COLUMNS . ' FROM ophthalmology_consultations r ' . ClinicalService::AUDIT_JOINS . ' WHERE r.visit_id = :v',
            ['v' => $visitId]
        );
        if ($r === null) {
            return null;
        }
        $id = (int) $r['id'];
        $pupil = [];
        foreach (['right', 'left'] as $eye) {
            $pupil["size_$eye"] = ClinicalService::toFloat($r["pupil_size_$eye"]);
            foreach (['shape', 'light', 'consensual'] as $aspect) {
                $pupil["{$aspect}_$eye"] = $r["pupil_{$aspect}_$eye"];
            }
        }
        $record = ClinicalService::recordBase($r) + [
            'findings' => self::findings($id),
            'section_notes' => (object) self::sectionNotes($id),
            'pupil' => $pupil,
            'diagrams' => self::decodeDiagrams($r['diagrams_enc']),
            'diagnoses' => ClinicalOrders::diagnoses('ophthalmology', $id),
            'prescription' => ClinicalOrders::prescription('ophthalmology', $id),
            'follow_up' => ClinicalOrders::followUp('ophthalmology', $id),
            'investigations' => ClinicalOrders::investigations($id),
            'surgery' => ClinicalOrders::surgery($id),
            'referral' => self::referral($r),
        ];
        foreach (array_keys(self::TEXT_LIMITS) as $field) {
            $record[$field] = Crypto::decrypt($r["{$field}_enc"], "ophthalmology_consultations.$field");
        }
        return $record;
    }

    public static function completedSummary(int $visitId): ?array
    {
        $record = self::loadRecord($visitId);
        if ($record === null || $record['status'] !== 'completed') {
            return null;
        }
        unset($record['_row'], $record['diagrams']);
        return $record;
    }

    protected function upstream(int $visitId): array
    {
        return [
            'triage' => TriageController::completedSummary($visitId),
            'optometry' => OptometryController::completedSummary($visitId),
        ];
    }

    private static function findings(int $consultationId): array
    {
        return array_map(static fn (array $f) => [
            'finding_id' => (int) $f['finding_id'], 'eye' => $f['eye'], 'section' => $f['section'], 'label' => $f['label'], 'is_normal' => (bool) $f['is_normal'],
        ], Database::all(
            'SELECT f.finding_id, f.eye, e.section, e.label, e.is_normal FROM ophthalmology_findings f JOIN exam_findings e ON e.id = f.finding_id
              WHERE f.consultation_id = :c ORDER BY e.section, e.sort_order, f.eye',
            ['c' => $consultationId]
        ));
    }

    private static function sectionNotes(int $consultationId): array
    {
        $notes = [];
        foreach (Database::all('SELECT section, note_enc FROM ophthalmology_section_notes WHERE consultation_id = :c', ['c' => $consultationId]) as $row) {
            $notes[$row['section']] = Crypto::decrypt($row['note_enc'], 'ophthalmology_section_notes.note');
        }
        ksort($notes);
        return $notes;
    }

    /** @return array{referred:bool, facility:?string} */
    private static function referral(array $row): array
    {
        return [
            'referred' => (bool) $row['referred_out'],
            'facility' => Crypto::decrypt($row['referral_facility_enc'], 'ophthalmology_consultations.referral_facility'),
        ];
    }

    private static function decodeDiagrams(?string $blob): ?array
    {
        $json = Crypto::decrypt($blob, 'ophthalmology_consultations.diagrams');
        return $json === null ? null : json_decode($json, true);
    }
}
