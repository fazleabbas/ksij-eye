<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuthContext;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Settings;

/**
 * Workflow shared by Triage, Optometry and Ophthalmology: the queues, which clinician is attending a
 * patient, moving the visit on when a record is completed, the author's correction window and addenda.
 */
final class ClinicalService
{
    public const STAGES = [
        'triage' => [
            'label' => 'Triage', 'waiting' => ['waiting_triage'], 'return_to' => 'waiting_triage',
            'table' => 'triage_assessments', 'view' => 'triage.view', 'record' => 'triage.record',
        ],
        'optometry' => [
            'label' => 'Optometry', 'waiting' => ['waiting_optometry'], 'return_to' => 'waiting_optometry',
            'table' => 'optometry_assessments', 'view' => 'optometry.view', 'record' => 'optometry.record',
        ],
        'ophthalmology' => [
            'label' => 'Ophthalmology', 'waiting' => ['waiting_ophthalmology', 'waiting_doctor'], 'return_to' => 'waiting_ophthalmology',
            'table' => 'ophthalmology_consultations', 'view' => 'ophthalmology.view', 'record' => 'ophthalmology.record',
        ],
    ];

    public const VA_VALUES = ['6/5', '6/6', '6/7.5', '6/9', '6/12', '6/18', '6/24', '6/36', '6/60', '5/60', '4/60', '3/60', '2/60', '1/60', 'CF', 'HM', 'PL', 'NPL'];

    /** Author names for a clinical record aliased r (use with AUDIT_JOINS). */
    public const AUDIT_COLUMNS = "TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)) AS created_by_name,
            TRIM(CONCAT_WS(' ', uu.first_name, uu.last_name)) AS updated_by_name,
            TRIM(CONCAT_WS(' ', ku.first_name, ku.last_name)) AS completed_by_name";
    public const AUDIT_JOINS = 'JOIN users cu ON cu.id = r.created_by LEFT JOIN users uu ON uu.id = r.updated_by LEFT JOIN users ku ON ku.id = r.completed_by';

    // ─── Queues ──────────────────────────────────────────────────────────

    /** Patients waiting for, or being seen at, a stage: emergencies first, then fast track, then arrival. */
    public static function queue(string $stage, array $filters, AuthContext $ctx): array
    {
        $def = self::STAGES[$stage];
        [$waitingIn, $params] = Database::in('w', $def['waiting']);
        [$enteredIn, $enteredParams] = Database::in('e', $def['waiting']);
        $params += $enteredParams + ['stage' => $stage];
        $where = ["(v.status IN ($waitingIn) OR (v.status = 'in_progress' AND v.clinical_stage = :stage))"];
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (($filters[$key] ?? '') !== '') {
                $where[] = "v.visit_date $operator :$key";
                $params[$key] = $filters[$key];
            }
        }
        if ($condition = PatientService::searchCondition((string) ($filters['search'] ?? ''))) {
            [$where[], $searchParams] = $condition;
            $params += $searchParams;
        }
        $table = $def['table'];
        $rows = Database::all(
            "SELECT v.id, v.visit_no, v.visit_date, v.visit_type, v.sub_type, v.status, v.room, v.is_emergency, v.is_fast_track,
                    v.attending_user_id, v.attending_since,
                    p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
                    TRIM(CONCAT_WS(' ', au.first_name, au.last_name)) AS attending_name,
                    r.status AS record_status,
                    (SELECT MAX(h.changed_at) FROM visit_status_history h WHERE h.visit_id = v.id AND h.to_status IN ($enteredIn)) AS queued_at,
                    EXISTS (SELECT 1 FROM triage_assessments t WHERE t.visit_id = v.id AND t.status = 'completed') AS triaged,
                    EXISTS (SELECT 1 FROM optometry_assessments o WHERE o.visit_id = v.id AND o.status = 'completed') AS optometry_done
               FROM visits v
               JOIN patients p ON p.id = v.patient_id
               LEFT JOIN users au ON au.id = v.attending_user_id
               LEFT JOIN $table r ON r.visit_id = v.id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY v.is_emergency DESC, v.is_fast_track DESC, queued_at IS NULL, queued_at, v.id
              LIMIT 300",
            $params
        );

        $items = array_map(static function (array $r) use ($stage, $ctx): array {
            $source = 'Reception';
            if ($stage === 'ophthalmology' && (int) $r['optometry_done'] === 1) {
                $source = 'Optometry';
            } elseif ($stage !== 'triage' && (int) $r['triaged'] === 1) {
                $source = 'Triage';
            }
            return [
                'visit_id' => (int) $r['id'],
                'visit_no' => $r['visit_no'],
                'visit_date' => $r['visit_date'],
                'visit_type' => $r['visit_type'],
                'sub_type' => $r['sub_type'],
                'status' => $r['status'],
                'room' => $r['room'],
                'is_emergency' => (bool) $r['is_emergency'],
                'is_fast_track' => (bool) $r['is_fast_track'],
                'queued_at' => Format::utc($r['queued_at']),
                'attending' => $r['attending_user_id'] === null ? null : [
                    'id' => (int) $r['attending_user_id'], 'name' => $r['attending_name'], 'since' => Format::utc($r['attending_since']),
                ],
                'is_mine' => $r['attending_user_id'] !== null && (int) $r['attending_user_id'] === $ctx->userId,
                'has_draft' => $r['record_status'] === 'draft',
                'source' => $source,
                'patient' => PatientService::brief($r),
            ];
        }, $rows);

        return [
            'queue' => $items,
            'counts' => [
                'total' => count($items),
                'emergency' => count(array_filter($items, static fn (array $i) => $i['is_emergency'])),
                'drafts' => count(array_filter($items, static fn (array $i) => $i['has_draft'])),
                'mine' => count(array_filter($items, static fn (array $i) => $i['is_mine'])),
            ],
        ];
    }

    // ─── Attending ───────────────────────────────────────────────────────

    public static function lockVisit(int $visitId): array
    {
        return Database::one('SELECT * FROM visits WHERE id = :id FOR UPDATE', ['id' => $visitId])
            ?? throw HttpException::notFound('Visit not found.');
    }

    /**
     * Opens a patient for recording. A patient already being seen by someone else can only be
     * taken over explicitly. Returns the id of the clinician taken over from, if any.
     */
    public static function claim(string $stage, int $visitId, bool $takeOver, AuthContext $ctx): ?int
    {
        $def = self::STAGES[$stage];
        $visit = self::lockVisit($visitId);

        if (in_array($visit['status'], $def['waiting'], true)) {
            Database::execute(
                "UPDATE visits SET status = 'in_progress', clinical_stage = :stage, attending_user_id = :u, attending_since = UTC_TIMESTAMP(3),
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                  WHERE id = :id",
                ['stage' => $stage, 'u' => $ctx->userId, 'by' => $ctx->userId, 'id' => $visitId]
            );
            VisitService::recordStatus($visitId, $visit['status'], 'in_progress', $ctx->userId, "{$def['label']} started");
            return null;
        }

        if ($visit['status'] === 'in_progress' && $visit['clinical_stage'] === $stage) {
            $current = $visit['attending_user_id'] === null ? null : (int) $visit['attending_user_id'];
            if ($current === $ctx->userId) {
                return null;
            }
            if ($current !== null && !$takeOver) {
                throw HttpException::conflict('attended_by_other', self::userName($current) . ' is already seeing this patient.');
            }
            Database::execute(
                'UPDATE visits SET attending_user_id = :u, attending_since = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                        row_version = row_version + 1
                  WHERE id = :id',
                ['u' => $ctx->userId, 'by' => $ctx->userId, 'id' => $visitId]
            );
            VisitService::recordStatus($visitId, 'in_progress', 'in_progress', $ctx->userId,
                $current === null ? "{$def['label']} resumed" : "{$def['label']} taken over from " . self::userName($current));
            return $current;
        }

        throw HttpException::conflict('not_in_queue', "This patient is not waiting for {$def['label']}.");
    }

    /** Puts the patient back in the stage queue; any draft is kept. */
    public static function release(string $stage, int $visitId, AuthContext $ctx): array
    {
        $def = self::STAGES[$stage];
        $visit = self::lockVisit($visitId);
        self::assertAttending($visit, $stage, $ctx);
        Database::execute(
            'UPDATE visits SET status = :s, clinical_stage = NULL, attending_user_id = NULL, attending_since = NULL,
                    updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
              WHERE id = :id',
            ['s' => $def['return_to'], 'by' => $ctx->userId, 'id' => $visitId]
        );
        VisitService::recordStatus($visitId, 'in_progress', $def['return_to'], $ctx->userId, "Returned to the {$def['label']} queue");
        return $visit;
    }

    public static function assertAttending(array $visit, string $stage, AuthContext $ctx): void
    {
        if ($visit['status'] !== 'in_progress' || $visit['clinical_stage'] !== $stage) {
            throw HttpException::conflict('not_attending', 'Open this patient from the queue before recording.');
        }
        if ((int) $visit['attending_user_id'] !== $ctx->userId) {
            throw HttpException::conflict('attended_by_other', 'Another clinician has taken over this patient.');
        }
    }

    /** Moves the visit on after a stage is completed. */
    public static function finish(array $visit, string $nextStatus, int $userId, string $note): void
    {
        Database::execute(
            'UPDATE visits SET status = :s, clinical_stage = NULL, attending_user_id = NULL, attending_since = NULL,
                    completed_at = IF(:done = 1, UTC_TIMESTAMP(3), completed_at),
                    updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
              WHERE id = :id',
            ['s' => $nextStatus, 'done' => $nextStatus === 'completed' ? 1 : 0, 'by' => $userId, 'id' => (int) $visit['id']]
        );
        VisitService::recordStatus((int) $visit['id'], 'in_progress', $nextStatus, $userId, $note);
    }

    // ─── Corrections & addenda ───────────────────────────────────────────

    public static function editHours(): int
    {
        return Settings::int('clinical.record_edit_hours', 24);
    }

    /**
     * Until when the signed-in author may still correct a completed record (DB datetime, UTC), or null.
     * Expects the raw record row (status, completed_by, completed_at).
     */
    public static function correctableUntil(array $row, AuthContext $ctx): ?string
    {
        if ($row['status'] !== 'completed' || $row['completed_at'] === null || (int) $row['completed_by'] !== $ctx->userId) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $until = (new \DateTimeImmutable($row['completed_at'], $utc))->modify('+' . self::editHours() . ' hours');
        return $until > new \DateTimeImmutable('now', $utc) ? $until->format('Y-m-d H:i:s.v') : null;
    }

    public static function addAddendum(string $source, array $record, string $reason, string $note, int $userId): void
    {
        Database::execute(
            'INSERT INTO clinical_addenda (patient_id, visit_id, source, record_id, reason_enc, note_enc, created_at, created_by)
             VALUES (:p, :v, :s, :r, :reason, :note, UTC_TIMESTAMP(3), :by)',
            [
                'p' => (int) $record['patient_id'], 'v' => $record['visit_id'] === null ? null : (int) $record['visit_id'],
                's' => $source, 'r' => (int) $record['id'],
                'reason' => Crypto::encrypt($reason, 'clinical_addenda.reason'), 'note' => Crypto::encrypt($note, 'clinical_addenda.note'),
                'by' => $userId,
            ]
        );
    }

    public static function addenda(string $source, int $recordId): array
    {
        $rows = Database::all(
            "SELECT a.reason_enc, a.note_enc, a.created_at, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS created_by
               FROM clinical_addenda a JOIN users u ON u.id = a.created_by
              WHERE a.source = :s AND a.record_id = :r ORDER BY a.id",
            ['s' => $source, 'r' => $recordId]
        );
        return array_map(static fn (array $a) => [
            'reason' => Crypto::decrypt($a['reason_enc'], 'clinical_addenda.reason'),
            'note' => Crypto::decrypt($a['note_enc'], 'clinical_addenda.note'),
            'created_at' => Format::utc($a['created_at']),
            'created_by' => $a['created_by'],
        ], $rows);
    }

    // ─── Presentation helpers ────────────────────────────────────────────

    /** Visit header shown above every clinical form. */
    public static function visitContext(int $visitId, AuthContext $ctx): array
    {
        $r = Database::one(
            "SELECT v.id, v.visit_no, v.visit_date, v.visit_type, v.sub_type, v.destination, v.room, v.status, v.clinical_stage,
                    v.is_emergency, v.is_fast_track, v.emergency_reason_enc, v.patient_age_years, v.attending_user_id, v.attending_since, v.created_at,
                    p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
                    TRIM(CONCAT_WS(' ', au.first_name, au.last_name)) AS attending_name
               FROM visits v
               JOIN patients p ON p.id = v.patient_id
               LEFT JOIN users au ON au.id = v.attending_user_id
              WHERE v.id = :id",
            ['id' => $visitId]
        ) ?? throw HttpException::notFound('Visit not found.');

        return [
            'id' => (int) $r['id'],
            'visit_no' => $r['visit_no'],
            'visit_date' => $r['visit_date'],
            'visit_type' => $r['visit_type'],
            'sub_type' => $r['sub_type'],
            'destination' => $r['destination'],
            'room' => $r['room'],
            'status' => $r['status'],
            'clinical_stage' => $r['clinical_stage'],
            'is_emergency' => (bool) $r['is_emergency'],
            'is_fast_track' => (bool) $r['is_fast_track'],
            'emergency_reason' => Crypto::decrypt($r['emergency_reason_enc'], 'visits.emergency_reason'),
            'patient_age_years' => (int) $r['patient_age_years'],
            'attending' => $r['attending_user_id'] === null ? null : [
                'id' => (int) $r['attending_user_id'], 'name' => $r['attending_name'], 'since' => Format::utc($r['attending_since']),
            ],
            'is_mine' => $r['attending_user_id'] !== null && (int) $r['attending_user_id'] === $ctx->userId,
            'patient' => PatientService::brief($r),
        ];
    }

    /** Common fields of a clinical record row (joined with AUDIT_COLUMNS). The raw row is kept for correction checks. */
    public static function recordBase(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'visit_id' => (int) $r['visit_id'],
            'status' => $r['status'],
            'row_version' => (int) $r['row_version'],
            'created_by' => $r['created_by_name'],
            'created_at' => Format::utc($r['created_at']),
            'updated_by' => $r['updated_by_name'] ?: null,
            'updated_at' => Format::utc($r['updated_at']),
            'completed_by' => $r['completed_by_name'] ?: null,
            'completed_at' => Format::utc($r['completed_at']),
            '_row' => ['status' => $r['status'], 'completed_by' => $r['completed_by'], 'completed_at' => $r['completed_at'],
                       'patient_id' => $r['patient_id'], 'visit_id' => $r['visit_id'], 'id' => $r['id']],
        ];
    }

    public static function toInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    public static function toFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /** Names of fields whose values differ (numbers compared numerically, lists as sets). */
    public static function changedFields(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $field => $value) {
            if (self::normalise($before[$field] ?? null) !== self::normalise($value)) {
                $changed[] = $field;
            }
        }
        return $changed;
    }

    private static function normalise(mixed $value): string
    {
        if (is_array($value)) {
            $items = array_map([self::class, 'normalise'], $value);
            if (array_is_list($value)) {
                sort($items);
            } else {
                ksort($items);
            }
            return json_encode($items);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }
        return (string) $value;
    }

    public static function userName(int $userId): string
    {
        return (string) (Database::value("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) FROM users WHERE id = :id", ['id' => $userId]) ?: 'Another clinician');
    }
}
