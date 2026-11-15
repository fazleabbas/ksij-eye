<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Validator;
use App\Services\ClinicalCatalog;
use App\Services\ClinicalOrders;
use App\Services\ClinicalService;
use App\Core\FileStore;
use App\Core\Http\Response;
use App\Services\PatientRecordService;
use App\Services\PatientService;

/** Choices for the clinical forms, a patient's clinical history, and follow-ups due at reception. */
final class ClinicalController
{
    public function options(): array
    {
        $ctx = Auth::requireAny('triage.view', 'optometry.view', 'ophthalmology.view');
        $findings = [];
        foreach (Database::all('SELECT id, section, label, is_normal FROM exam_findings WHERE is_active = 1 ORDER BY sort_order, label') as $f) {
            $findings[$f['section']][] = ['id' => (int) $f['id'], 'label' => $f['label'], 'is_normal' => (bool) $f['is_normal']];
        }
        $sections = [];
        foreach (ClinicalCatalog::EXAM_SECTIONS as $code => $label) {
            $sections[] = ['code' => $code, 'label' => $label, 'findings' => $findings[$code] ?? []];
        }
        $priceItems = static fn (string $condition): array => array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'category' => $r['category'], 'price' => (float) $r['unit_price'],
        ], Database::all("SELECT id, code, name, category, unit_price FROM price_items WHERE is_active = 1 AND $condition ORDER BY name"));

        return [
            'today' => Format::localToday(),
            'va_values' => ClinicalService::VA_VALUES,
            'emergency_reasons' => ClinicalCatalog::lookupValues('emergency_reason'),
            'follow_up_reasons' => ClinicalCatalog::lookupValues('follow_up_reason'),
            'frequencies' => ClinicalCatalog::lookupValues('drug_frequency'),
            'rx_labels' => ClinicalCatalog::lookupValues('rx_label'),
            'icd10' => array_map(static fn (array $r) => ['id' => (int) $r['id'], 'code' => $r['code'], 'description' => $r['description'], 'category' => $r['category']],
                Database::all('SELECT id, code, description, category FROM icd10_codes WHERE is_active = 1 ORDER BY sort_order, code')),
            'drugs' => array_map(static fn (array $r) => [
                'id' => (int) $r['id'], 'name' => $r['name'], 'category' => $r['category'], 'dosage_form' => $r['dosage_form'],
                'default_dose' => $r['default_dose'], 'default_frequency' => $r['default_frequency'], 'default_duration' => $r['default_duration'],
            ], Database::all('SELECT id, name, category, dosage_form, default_dose, default_frequency, default_duration FROM drugs WHERE is_active = 1 ORDER BY name')),
            'exam_sections' => $sections,
            'lens_items' => $priceItems("category = 'lens'"),
            'investigation_items' => $priceItems("category = 'investigation'"),
            'surgery_items' => $priceItems("category IN ('surgery', 'procedure')"),
            'diagram_colors' => ClinicalCatalog::DIAGRAM_COLORS,
            'edit_window_hours' => ClinicalService::editHours(),
            'can' => [
                'request_investigations' => $ctx->can('ophthalmology.investigations.request'),
                'recommend_surgery' => $ctx->can('ophthalmology.surgery.recommend'),
                'view_files' => $ctx->can('patients.files.view'),
            ],
        ];
    }

    /** Completed clinical records of earlier visits, plus orders, for the history panel. */
    public function history(Request $request, array $params): array
    {
        $ctx = Auth::requireAny('triage.view', 'optometry.view', 'ophthalmology.view');
        $patientId = (int) $params['id'];
        if (!Database::value('SELECT 1 FROM patients WHERE id = :id', ['id' => $patientId])) {
            throw HttpException::notFound('Patient not found.');
        }
        $exceptVisit = (int) $request->query('except_visit', '0');
        // The panel beside a consultation shows the last ten; Patient History shows them all.
        $limit = $request->query('all') === '1' ? 500 : 10;
        $visits = Database::all(
            "SELECT v.id, v.visit_no, v.visit_date, v.visit_type, v.sub_type, v.status
               FROM visits v
              WHERE v.patient_id = :p AND v.id <> :except
                AND (EXISTS (SELECT 1 FROM triage_assessments t WHERE t.visit_id = v.id AND t.status = 'completed')
                  OR EXISTS (SELECT 1 FROM optometry_assessments o WHERE o.visit_id = v.id AND o.status = 'completed')
                  OR EXISTS (SELECT 1 FROM ophthalmology_consultations c WHERE c.visit_id = v.id AND c.status = 'completed')
                  OR EXISTS (SELECT 1 FROM diagnostic_bookings d WHERE d.visit_id = v.id AND d.status = 'completed'))
              ORDER BY v.visit_date DESC, v.id DESC LIMIT $limit",
            ['p' => $patientId, 'except' => $exceptVisit]
        );
        Audit::access($patientId, 'clinical', 'view', 'clinical_history', $patientId);

        return [
            'visits' => array_map(static fn (array $v) => [
                'id' => (int) $v['id'], 'visit_no' => $v['visit_no'], 'visit_date' => $v['visit_date'], 'visit_type' => $v['visit_type'],
                'sub_type' => $v['sub_type'], 'status' => $v['status'],
                'triage' => TriageController::completedSummary((int) $v['id']),
                'optometry' => OptometryController::completedSummary((int) $v['id']),
                'ophthalmology' => OphthalmologyController::completedSummary((int) $v['id']),
            ], $visits),
            'investigations' => ClinicalOrders::patientInvestigations($patientId),
            'surgery' => ClinicalOrders::patientSurgery($patientId),
            // The rest of the record. Results are opened one at a time; theatre notes only for doctors.
            'can_view_results' => $canSee = $ctx->can(PatientRecordService::PERMISSION),
            'diagnostics' => PatientRecordService::diagnostics($patientId),
            'theatre' => PatientRecordService::theatre($patientId, $canSee),
            'medicines' => PatientRecordService::medicines($patientId),
            'optical' => PatientRecordService::optical($patientId),
        ];
    }

    // ─── Test results (doctors) ──────────────────────────────────────────

    /** One test with its report; opening it as the referring doctor clears the "result ready" alert. */
    public function result(Request $request, array $params): array
    {
        $ctx = Auth::require();
        return ['result' => Database::transaction(fn () => PatientRecordService::result((int) $params['id'], $ctx))];
    }

    /** Results waiting to be opened by the signed-in doctor (the bell). Polled: not user activity. */
    public function unseenResults(): array
    {
        $results = PatientRecordService::unseen(Auth::require());
        return ['results' => $results, 'count' => count($results)];
    }

    public function resultFile(Request $request, array $params): never
    {
        $row = Database::one(
            'SELECT rf.file_id, r.patient_id FROM diagnostic_result_files rf
               JOIN diagnostic_results r ON r.id = rf.result_id
              WHERE r.booking_id = :b AND rf.file_id = :f AND rf.voided_at IS NULL',
            ['b' => $params['id'], 'f' => $params['fileId']]
        ) ?? throw HttpException::notFound('File not found.');
        $file = FileStore::read((int) $row['file_id']);
        Audit::access((int) $row['patient_id'], 'clinical', 'download', 'diagnostic_file', (int) $row['file_id']);
        Response::file($file['content'], $file['mime_type'], $file['name']);
    }

    // ─── Follow-ups (reception) ──────────────────────────────────────────

    public function followUps(Request $request): array
    {
        $status = $request->query('status', 'due');
        $where = [];
        $params = [];
        if (in_array($status, ['due', 'booked', 'cancelled'], true)) {
            $where[] = 'f.status = :status';
            $params['status'] = $status;
        } else {
            $where[] = "f.status <> 'draft'";
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $value = $request->query($key);
            if ($value === '') {
                continue;
            }
            if (!Format::isDate($value)) {
                throw HttpException::validation([$key => 'Use a valid date.']);
            }
            $where[] = "f.due_date $operator :$key";
            $params[$key] = $value;
        }
        if ($condition = PatientService::searchCondition($request->query('search'))) {
            [$where[], $searchParams] = $condition;
            $params += $searchParams;
        }
        $sqlWhere = ' WHERE ' . implode(' AND ', $where);
        $perPage = $request->perPage(25, 100);
        $page = $request->page();
        $offset = ($page - 1) * $perPage;
        $total = (int) Database::value("SELECT COUNT(*) FROM follow_ups f JOIN patients p ON p.id = f.patient_id$sqlWhere", $params);
        $rows = Database::all(
            "SELECT f.id, f.source, f.due_date, f.priority, f.reason, f.notes_enc, f.status, f.created_at, f.row_version,
                    sv.visit_no AS source_visit_no, bv.visit_no AS booked_visit_no,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS created_by,
                    p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc, p.phone_enc
               FROM follow_ups f
               JOIN patients p ON p.id = f.patient_id
               LEFT JOIN visits sv ON sv.id = f.source_visit_id
               LEFT JOIN visits bv ON bv.id = f.booked_visit_id
               JOIN users u ON u.id = f.created_by
             $sqlWhere
              ORDER BY f.due_date IS NULL, f.due_date, f.priority = 'urgent' DESC, f.id
              LIMIT $perPage OFFSET $offset",
            $params
        );
        $today = Format::localToday();
        $counts = Database::one(
            "SELECT COUNT(*) AS due, SUM(due_date < :t1) AS overdue, SUM(due_date = :t2) AS today FROM follow_ups WHERE status = 'due'",
            ['t1' => $today, 't2' => $today]
        );

        return [
            'follow_ups' => array_map(static fn (array $r) => [
                'id' => (int) $r['id'], 'source' => $r['source'], 'due_date' => $r['due_date'], 'priority' => $r['priority'], 'reason' => $r['reason'],
                'notes' => Crypto::decrypt($r['notes_enc'], 'follow_ups.notes'), 'status' => $r['status'],
                'is_overdue' => $r['status'] === 'due' && $r['due_date'] !== null && $r['due_date'] < $today,
                'source_visit_no' => $r['source_visit_no'], 'booked_visit_no' => $r['booked_visit_no'],
                'created_by' => $r['created_by'], 'created_at' => Format::utc($r['created_at']), 'row_version' => (int) $r['row_version'],
                'patient' => PatientService::brief($r) + ['phone' => Crypto::decrypt($r['phone_enc'], 'patients.phone')],
            ], $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'counts' => ['due' => (int) $counts['due'], 'overdue' => (int) $counts['overdue'], 'today' => (int) $counts['today']],
        ];
    }

    public function cancelFollowUp(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('reason', true, 500, 3)->validate();
        $regNo = Database::transaction(function () use ($params, $v, $ctx): string {
            $followUp = Database::one(
                'SELECT f.id, f.status, p.reg_no FROM follow_ups f JOIN patients p ON p.id = f.patient_id WHERE f.id = :id FOR UPDATE',
                ['id' => $params['id']]
            ) ?? throw HttpException::notFound('Follow-up not found.');
            if ($followUp['status'] !== 'due') {
                throw HttpException::conflict('follow_up_not_due', 'Only follow-ups that are due can be cancelled.');
            }
            Database::execute(
                "UPDATE follow_ups SET status = 'cancelled', cancel_reason_enc = :r, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                  WHERE id = :id",
                ['r' => Crypto::encrypt($v['reason'], 'follow_ups.cancel_reason'), 'by' => $ctx->userId, 'id' => (int) $followUp['id']]
            );
            return $followUp['reg_no'];
        });
        Audit::log('visits', 'follow_up.cancelled', "Cancelled a follow-up for $regNo", 'follow_up', (int) $params['id']);
        return ['cancelled' => true];
    }
}
