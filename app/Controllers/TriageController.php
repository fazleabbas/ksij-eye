<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthContext;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Http\HttpException;
use App\Services\ClinicalCatalog;
use App\Services\ClinicalInput;
use App\Services\ClinicalService;

/** Triage: vital signs, visual acuity and eye pressure, emergency flag, and where the patient goes next. */
final class TriageController extends ClinicalStageController
{
    protected const STAGE = 'triage';

    private const VA_FIELDS = ['va_unaided_right', 'va_unaided_left', 'va_aided_right', 'va_aided_left'];
    private const TEXT_FIELDS = ['remarks', 'emergency_notes'];

    protected function validate(ClinicalInput $in, bool $complete, AuthContext $ctx): array
    {
        $d = [
            'bp_systolic' => $in->int('bp_systolic', 50, 260),
            'bp_diastolic' => $in->int('bp_diastolic', 30, 160),
            'blood_sugar_type' => $in->enum('blood_sugar_type', ['fasting', 'random']),
            'blood_sugar_mmol' => $in->decimal('blood_sugar_mmol', 1, 40, 0.1),
            'weight_kg' => $in->decimal('weight_kg', 1, 300, 0.1),
            'height_m' => $in->decimal('height_m', 0.3, 2.5, 0.01),
            'iop_right' => $in->int('iop_right', 0, 80),
            'iop_left' => $in->int('iop_left', 0, 80),
            'remarks' => $in->text('remarks', 1000),
            'is_emergency' => $in->bool('is_emergency'),
            'emergency_reason' => $in->text('emergency_reason', 100),
            'emergency_notes' => $in->text('emergency_notes', 1000),
            'route_to' => $in->enum('route_to', ['optometry', 'ophthalmology']),
        ];
        foreach (self::VA_FIELDS as $field) {
            $d[$field] = $in->va($field);
        }

        if (($d['bp_systolic'] === null) !== ($d['bp_diastolic'] === null) && !$in->hasError('bp_systolic') && !$in->hasError('bp_diastolic')) {
            $in->error($d['bp_systolic'] === null ? 'bp_systolic' : 'bp_diastolic', 'Enter both blood pressure values.');
        } elseif ($d['bp_systolic'] !== null && $d['bp_diastolic'] !== null && $d['bp_systolic'] <= $d['bp_diastolic']) {
            $in->error('bp_systolic', 'Systolic must be higher than diastolic.');
        }
        if ($d['blood_sugar_mmol'] !== null && $d['blood_sugar_type'] === null && !$in->hasError('blood_sugar_type')) {
            $in->error('blood_sugar_type', 'Choose fasting or random.');
        }
        if (!$d['is_emergency']) {
            $d['emergency_reason'] = $d['emergency_notes'] = null;
        } elseif ($d['emergency_reason'] !== null && !in_array($d['emergency_reason'], ClinicalCatalog::lookupValues('emergency_reason'), true)) {
            $in->error('emergency_reason', 'Choose a reason from the list.');
        }

        if ($complete) {
            if ($d['bp_systolic'] === null && !$in->hasError('bp_systolic') && !$in->hasError('bp_diastolic')) {
                $in->error('bp_systolic', 'Record the blood pressure.');
            }
            if (!array_filter(array_intersect_key($d, array_flip(self::VA_FIELDS))) && !$in->hasError('va_unaided_right')) {
                $in->error('va_unaided_right', 'Record at least one visual acuity.');
            }
            if ($d['route_to'] === null && !$in->hasError('route_to')) {
                $in->error('route_to', 'Choose where the patient goes next.');
            }
        }
        $in->throwIfInvalid();
        return $d;
    }

    protected function write(array $visit, ?array $existing, array $d, AuthContext $ctx, bool $correction): array
    {
        $plain = array_intersect_key($d, array_flip([
            'bp_systolic', 'bp_diastolic', 'blood_sugar_type', 'blood_sugar_mmol', 'weight_kg', 'height_m', 'iop_right', 'iop_left', ...self::VA_FIELDS,
        ]));
        if (!$correction) {
            $plain['route_to'] = $d['route_to'];
        }
        $texts = array_intersect_key($d, array_flip(self::TEXT_FIELDS));
        $columns = $plain;
        foreach ($texts as $field => $text) {
            $columns["{$field}_enc"] = Crypto::encrypt($text, "triage_assessments.$field");
        }

        $changes = [];
        if ($existing === null) {
            $id = self::insertRecord('triage_assessments', $visit, $columns, $ctx->userId);
        } else {
            $id = (int) $existing['id'];
            $before = array_intersect_key($existing, $plain);
            foreach (self::TEXT_FIELDS as $field) {
                $before[$field] = Crypto::decrypt($existing["{$field}_enc"], "triage_assessments.$field");
            }
            $changes = ClinicalService::changedFields($before, $plain + $texts);
            self::updateRecord('triage_assessments', $id, $columns, $ctx->userId);
        }
        if (!$correction) {
            $changes = array_merge($changes, $this->syncEmergency($visit, $d, $ctx));
        }
        return [$id, $changes];
    }

    /** The emergency flag lives on the visit, shared with reception. */
    private function syncEmergency(array $visit, array $d, AuthContext $ctx): array
    {
        $wasEmergency = (bool) $visit['is_emergency'];
        if ($d['is_emergency']) {
            $currentReason = Crypto::decrypt($visit['emergency_reason_enc'], 'visits.emergency_reason');
            $newReason = $d['emergency_reason'] !== null && $d['emergency_reason'] !== $currentReason ? $d['emergency_reason'] : null;
            if ($wasEmergency && $newReason === null) {
                return [];
            }
            Database::execute(
                'UPDATE visits SET is_emergency = 1, emergency_reason_enc = COALESCE(:reason, emergency_reason_enc),
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                  WHERE id = :id',
                ['reason' => Crypto::encrypt($newReason, 'visits.emergency_reason'), 'by' => $ctx->userId, 'id' => (int) $visit['id']]
            );
            return [$wasEmergency ? 'emergency_reason' : 'is_emergency'];
        }
        if ($wasEmergency) {
            Database::execute(
                'UPDATE visits SET is_emergency = 0, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id',
                ['by' => $ctx->userId, 'id' => (int) $visit['id']]
            );
            return ['is_emergency'];
        }
        return [];
    }

    protected function onComplete(array $visit, int $recordId, array $data, AuthContext $ctx): array
    {
        $current = Database::one('SELECT is_emergency, emergency_reason_enc FROM visits WHERE id = :id', ['id' => (int) $visit['id']]);
        if ((int) $current['is_emergency'] === 1 && $current['emergency_reason_enc'] === null) {
            throw HttpException::validation(['emergency_reason' => 'Choose the emergency reason.']);
        }
        return $data['route_to'] === 'optometry'
            ? ['waiting_optometry', 'Triage complete — sent to Optometry']
            : ['waiting_ophthalmology', 'Triage complete — sent to Ophthalmology'];
    }

    public static function loadRecord(int $visitId): ?array
    {
        $r = Database::one(
            'SELECT r.*, ' . ClinicalService::AUDIT_COLUMNS . ' FROM triage_assessments r ' . ClinicalService::AUDIT_JOINS . ' WHERE r.visit_id = :v',
            ['v' => $visitId]
        );
        if ($r === null) {
            return null;
        }
        $record = ClinicalService::recordBase($r) + [
            'bp_systolic' => ClinicalService::toInt($r['bp_systolic']),
            'bp_diastolic' => ClinicalService::toInt($r['bp_diastolic']),
            'blood_sugar_type' => $r['blood_sugar_type'],
            'blood_sugar_mmol' => ClinicalService::toFloat($r['blood_sugar_mmol']),
            'weight_kg' => ClinicalService::toFloat($r['weight_kg']),
            'height_m' => ClinicalService::toFloat($r['height_m']),
            'bmi' => ClinicalService::toFloat($r['bmi']),
            'iop_right' => ClinicalService::toInt($r['iop_right']),
            'iop_left' => ClinicalService::toInt($r['iop_left']),
            'route_to' => $r['route_to'],
        ];
        foreach (self::VA_FIELDS as $field) {
            $record[$field] = $r[$field];
        }
        foreach (self::TEXT_FIELDS as $field) {
            $record[$field] = Crypto::decrypt($r["{$field}_enc"], "triage_assessments.$field");
        }
        return $record;
    }

    /** Completed triage of a visit without internal fields, for later stages and history. */
    public static function completedSummary(int $visitId): ?array
    {
        $record = self::loadRecord($visitId);
        if ($record === null || $record['status'] !== 'completed') {
            return null;
        }
        unset($record['_row']);
        return $record;
    }
}
