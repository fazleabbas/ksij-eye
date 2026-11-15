<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\AuthContext;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Validator;
use App\Services\ClinicalInput;
use App\Services\ClinicalOrders;
use App\Services\ClinicalService;
use App\Services\PatientService;

/**
 * Endpoints shared by the Triage, Optometry and Ophthalmology screens: queue, recent records,
 * opening a patient, saving a draft, completing, correcting and addenda. Each stage supplies how
 * its record is validated, written, completed and presented.
 */
abstract class ClinicalStageController
{
    /** 'triage' | 'optometry' | 'ophthalmology' */
    protected const STAGE = '';

    /** Validates the submitted form; $complete adds the checks required to finish. */
    abstract protected function validate(ClinicalInput $in, bool $complete, AuthContext $ctx): array;

    /**
     * Inserts or updates the record and its child rows; returns [record id, changed field names].
     * During a correction orders and routing are left untouched.
     */
    abstract protected function write(array $visit, ?array $existing, array $data, AuthContext $ctx, bool $correction): array;

    /** Effects of completing (orders issued); returns [next visit status, history note]. */
    abstract protected function onComplete(array $visit, int $recordId, array $data, AuthContext $ctx): array;

    /** The stage record of a visit ready for the client (with '_row' for correction checks), or null. */
    abstract public static function loadRecord(int $visitId): ?array;

    /** Completed records of earlier stages of the same visit. */
    protected function upstream(int $visitId): array
    {
        return [];
    }

    public function queue(Request $request): array
    {
        $ctx = Auth::require();
        foreach (['from', 'to'] as $key) {
            if ($request->query($key) !== '' && !Format::isDate($request->query($key))) {
                throw HttpException::validation([$key => 'Use a valid date.']);
            }
        }
        $data = ClinicalService::queue(static::STAGE, [
            'from' => $request->query('from'), 'to' => $request->query('to'), 'search' => $request->query('search'),
        ], $ctx);
        $filter = $request->query('filter', 'all');
        $keep = match ($filter) {
            'emergency' => static fn (array $i) => $i['is_emergency'],
            'draft' => static fn (array $i) => $i['has_draft'],
            'mine' => static fn (array $i) => $i['is_mine'],
            default => null,
        };
        if ($keep !== null) {
            $data['queue'] = array_values(array_filter($data['queue'], $keep));
        }
        return $data;
    }

    /** Records this clinician completed that can still be corrected. */
    public function recent(): array
    {
        $ctx = Auth::require();
        $table = ClinicalService::STAGES[static::STAGE]['table'];
        $hours = ClinicalService::editHours();
        $rows = Database::all(
            "SELECT r.visit_id, r.completed_at, v.visit_no, v.visit_date,
                    p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc
               FROM $table r JOIN visits v ON v.id = r.visit_id JOIN patients p ON p.id = r.patient_id
              WHERE r.status = 'completed' AND r.completed_by = :u AND r.completed_at >= UTC_TIMESTAMP(3) - INTERVAL $hours HOUR
              ORDER BY r.completed_at DESC LIMIT 50",
            ['u' => $ctx->userId]
        );
        return ['records' => array_map(static fn (array $r) => [
            'visit_id' => (int) $r['visit_id'], 'visit_no' => $r['visit_no'], 'visit_date' => $r['visit_date'],
            'completed_at' => Format::utc($r['completed_at']), 'patient' => PatientService::brief($r),
        ], $rows), 'edit_window_hours' => $hours];
    }

    public function show(Request $request, array $params): array
    {
        return $this->workspace((int) $params['id'], Auth::require());
    }

    public function claim(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->bool('take_over')->validate();
        $visitId = (int) $params['id'];
        $previous = Database::transaction(fn () => ClinicalService::claim(static::STAGE, $visitId, (bool) $v['take_over'], $ctx));
        if ($previous !== null) {
            $visitNo = (string) Database::value('SELECT visit_no FROM visits WHERE id = :id', ['id' => $visitId]);
            Audit::log(static::STAGE, 'patient.taken_over', 'Took over ' . ClinicalService::STAGES[static::STAGE]['label'] . " for visit $visitNo from " . ClinicalService::userName($previous), 'visit', $visitNo);
        }
        return $this->workspace($visitId, $ctx);
    }

    public function release(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $visit = Database::transaction(fn () => ClinicalService::release(static::STAGE, (int) $params['id'], $ctx));
        Audit::log(static::STAGE, 'patient.returned_to_queue', "Returned visit {$visit['visit_no']} to the queue", 'visit', $visit['visit_no']);
        return ['released' => true];
    }

    public function save(Request $request, array $params): array
    {
        return $this->persist($request, (int) $params['id'], false);
    }

    public function complete(Request $request, array $params): array
    {
        return $this->persist($request, (int) $params['id'], true);
    }

    public function addendum(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('reason', true, 200, 3)->string('note', true, 2000, 3)->validate();
        $table = ClinicalService::STAGES[static::STAGE]['table'];
        $record = Database::one(
            "SELECT r.id, r.patient_id, r.visit_id, r.status, v.visit_no FROM $table r JOIN visits v ON v.id = r.visit_id WHERE r.visit_id = :v",
            ['v' => (int) $params['id']]
        ) ?? throw HttpException::notFound('There is no record for this visit.');
        if ($record['status'] !== 'completed') {
            throw HttpException::conflict('record_not_completed', 'Addenda are added to completed records. Change the draft instead.');
        }
        ClinicalService::addAddendum(static::STAGE, $record, $v['reason'], $v['note'], $ctx->userId);
        Audit::log(static::STAGE, 'record.addendum', "Added an addendum to the record of visit {$record['visit_no']}", 'visit', $record['visit_no']);
        return ['addenda' => ClinicalService::addenda(static::STAGE, (int) $record['id'])];
    }

    /** The issuing doctor changes an issued prescription (optometry and ophthalmology only). */
    public function amendPrescription(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $input = $request->all();
        $v = Validator::make($input)->string('reason', true, 300, 3)->validate();
        $in = new ClinicalInput($input);
        $rx = ClinicalOrders::validatePrescription($in);
        $in->throwIfInvalid();
        $rowVersion = isset($input['row_version']) && is_numeric($input['row_version']) ? (int) $input['row_version'] : null;
        $visitId = (int) $params['id'];
        $table = ClinicalService::STAGES[static::STAGE]['table'];

        $result = Database::transaction(function () use ($visitId, $table, $rx, $v, $rowVersion, $ctx): array {
            $record = Database::one("SELECT r.id, v.visit_no FROM $table r JOIN visits v ON v.id = r.visit_id WHERE r.visit_id = :v", ['v' => $visitId])
                ?? throw HttpException::notFound('There is no record for this visit.');
            return ClinicalOrders::amendPrescription(static::STAGE, (int) $record['id'], $rx, $v['reason'], $rowVersion, $ctx)
                + ['visit_no' => $record['visit_no']];
        });

        $effects = [
            'none' => '', 'order_rebuilt' => ' — pharmacy order updated',
            'bill_cancelled' => ' — unpaid pharmacy bill cancelled, order back to review',
            'order_cancelled' => ' — pharmacy order cancelled (no stocked medicine left)',
        ];
        Audit::log(static::STAGE, 'prescription.amended',
            "Changed prescription {$result['prescription_no']} of visit {$result['visit_no']}" . $effects[$result['effect']],
            'prescription', $result['prescription_no']);
        return $this->workspace($visitId, $ctx, false) + ['result' => ['action' => 'prescription_amended', 'effect' => $result['effect']]];
    }

    // ─── Internals ───────────────────────────────────────────────────────

    protected function workspace(int $visitId, AuthContext $ctx, bool $logAccess = true): array
    {
        $visit = ClinicalService::visitContext($visitId, $ctx);
        $record = static::loadRecord($visitId);
        if ($logAccess) {
            Audit::access($visit['patient']['id'], static::STAGE, 'view', 'visit', $visit['visit_no']);
        }
        $canRecord = $ctx->can(ClinicalService::STAGES[static::STAGE]['record']);
        $correctableUntil = null;
        if ($record !== null) {
            $correctableUntil = ClinicalService::correctableUntil($record['_row'], $ctx);
            unset($record['_row']);
        }
        $attendingHere = $visit['status'] === 'in_progress' && $visit['clinical_stage'] === static::STAGE;
        $mode = 'read';
        if ($canRecord && $record !== null && $record['status'] === 'completed') {
            $mode = $correctableUntil !== null ? 'correct' : 'read';
        } elseif ($canRecord && $attendingHere && $visit['is_mine']) {
            $mode = 'edit';
        }
        return [
            'visit' => $visit,
            'record' => $record,
            'mode' => $mode,
            'correctable_until' => Format::utc($correctableUntil),
            'upstream' => $this->upstream($visitId),
            'addenda' => $record !== null ? ClinicalService::addenda(static::STAGE, $record['id']) : [],
        ];
    }

    private function persist(Request $request, int $visitId, bool $complete): array
    {
        $ctx = Auth::require();
        $input = $request->all();
        $data = $this->validate(new ClinicalInput($input), $complete, $ctx);
        $rowVersion = isset($input['row_version']) && is_numeric($input['row_version']) ? (int) $input['row_version'] : null;
        $def = ClinicalService::STAGES[static::STAGE];

        $result = Database::transaction(function () use ($visitId, $data, $ctx, $complete, $rowVersion, $def): array {
            $visit = ClinicalService::lockVisit($visitId);
            $table = $def['table'];
            $existing = Database::one("SELECT * FROM $table WHERE visit_id = :v FOR UPDATE", ['v' => $visitId]);
            if ($existing !== null && $rowVersion !== (int) $existing['row_version']) {
                throw HttpException::conflict('stale_record', 'This record was changed meanwhile. Reload it and try again.');
            }

            if ($existing !== null && $existing['status'] === 'completed') {
                if ($complete) {
                    throw HttpException::conflict('already_completed', 'This record is already complete.');
                }
                if (ClinicalService::correctableUntil($existing, $ctx) === null) {
                    throw HttpException::conflict('record_locked', 'This record can no longer be changed. Add an addendum instead.');
                }
                [, $changes] = $this->write($visit, $existing, $data, $ctx, true);
                return ['action' => 'corrected', 'visit_no' => $visit['visit_no'], 'changes' => $changes];
            }

            ClinicalService::assertAttending($visit, static::STAGE, $ctx);
            [$recordId, $changes] = $this->write($visit, $existing, $data, $ctx, false);
            if (!$complete) {
                return ['action' => 'saved', 'visit_no' => $visit['visit_no'], 'changes' => $changes];
            }
            Database::execute(
                "UPDATE $table SET status = 'completed', completed_at = UTC_TIMESTAMP(3), completed_by = :by, row_version = row_version + 1 WHERE id = :id",
                ['by' => $ctx->userId, 'id' => $recordId]
            );
            [$next, $note] = $this->onComplete($visit, $recordId, $data, $ctx);
            ClinicalService::finish($visit, $next, $ctx->userId, $note);
            return ['action' => 'completed', 'visit_no' => $visit['visit_no'], 'next_status' => $next, 'note' => $note, 'changes' => $changes];
        });

        $label = $def['label'];
        match ($result['action']) {
            // Changed fields are listed too, so changes to a record reopened for the doctor can be traced.
            'saved' => Audit::log(static::STAGE, 'record.saved', "Saved $label draft for visit {$result['visit_no']}", 'visit', $result['visit_no'],
                $result['changes'] ? ['fields' => $result['changes']] : null),
            'completed' => Audit::log(static::STAGE, 'record.completed', "Completed $label for visit {$result['visit_no']} — {$result['note']}", 'visit', $result['visit_no'],
                $result['changes'] ? ['fields' => $result['changes']] : null),
            'corrected' => Audit::log(static::STAGE, 'record.corrected', "Corrected $label record of visit {$result['visit_no']}", 'visit', $result['visit_no'],
                $result['changes'] ? ['fields' => $result['changes']] : null),
        };
        unset($result['changes']);
        return $this->workspace($visitId, $ctx, false) + ['result' => $result];
    }

    /** INSERT of a new draft record; column names come from code, never from input. */
    protected static function insertRecord(string $table, array $visit, array $columns, int $userId): int
    {
        $columns += ['visit_id' => (int) $visit['id'], 'patient_id' => (int) $visit['patient_id'], 'status' => 'draft', 'created_by' => $userId];
        $names = array_keys($columns);
        return Database::insert(
            "INSERT INTO $table (" . implode(', ', $names) . ', created_at) VALUES (:' . implode(', :', $names) . ', UTC_TIMESTAMP(3))',
            $columns
        );
    }

    protected static function updateRecord(string $table, int $id, array $columns, int $userId): void
    {
        $set = implode(', ', array_map(static fn (string $c) => "$c = :$c", array_keys($columns)));
        Database::execute(
            "UPDATE $table SET $set, updated_at = UTC_TIMESTAMP(3), updated_by = :_by, row_version = row_version + 1 WHERE id = :_id",
            $columns + ['_by' => $userId, '_id' => $id]
        );
    }
}
