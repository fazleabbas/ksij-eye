<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\FileStore;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Validator;
use App\Services\ApprovalService;
use App\Services\PatientService;

/** Approval queue and decisions, plus supporting documents attached by the requester. */
final class ApprovalController
{
    private const SELECT = "SELECT a.id, a.approval_no, a.type, a.status, a.requested_amount, a.approved_amount, a.discount_type, a.sponsor,
            a.request_remarks_enc, a.decision_remarks_enc, a.requested_at, a.requested_by, a.decided_at, a.decided_by, a.row_version,
            p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
            b.id AS bill_id, b.bill_no, b.bill_type, b.description AS bill_description, b.gross_amount, b.discount_amount AS bill_discount,
            b.net_amount, b.paid_amount, b.status AS bill_status,
            v.visit_no,
            r.refund_no, r.reason_enc AS refund_reason_enc, r.status AS refund_status,
            bk.booking_no, bk.eye AS booking_eye, bk.scheduled_for, bk.status AS booking_status, bki.name AS booking_procedure,
            oo.order_no AS optical_order_no, oo.status AS optical_status, oo.total_amount AS optical_total,
            tb.booking_no AS theatre_booking_no, tb.status AS theatre_status, tb.eye AS theatre_eye, tbi.name AS theatre_surgery,
            pay.receipt_no,
            TRIM(CONCAT_WS(' ', ru.first_name, ru.last_name)) AS requested_by_name,
            TRIM(CONCAT_WS(' ', du.first_name, du.last_name)) AS decided_by_name,
            (SELECT COUNT(*) FROM approval_files af WHERE af.approval_id = a.id) AS file_count
       FROM approval_requests a
       JOIN users ru ON ru.id = a.requested_by
       LEFT JOIN users du ON du.id = a.decided_by
       LEFT JOIN patients p ON p.id = a.patient_id
       LEFT JOIN bills b ON b.id = a.bill_id
       LEFT JOIN visits v ON v.id = b.visit_id
       LEFT JOIN refunds r ON r.id = a.refund_id
       LEFT JOIN payments pay ON pay.id = r.payment_id
       LEFT JOIN diagnostic_bookings bk ON bk.id = a.booking_id
       LEFT JOIN price_items bki ON bki.id = bk.price_item_id
       LEFT JOIN optical_orders oo ON oo.id = a.optical_order_id
       LEFT JOIN theatre_bookings tb ON tb.id = a.theatre_booking_id
       LEFT JOIN price_items tbi ON tbi.id = tb.surgery_item_id";

    public function index(Request $request): array
    {
        $status = $request->query('status', 'pending');
        $where = [];
        $params = [];
        $dateColumn = 'a.requested_at';
        if ($status === 'pending') {
            $where[] = "a.status = 'pending'";
        } elseif (in_array($status, ['approved', 'rejected'], true)) {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
            $dateColumn = 'a.decided_at';
        } else {
            $where[] = "a.status <> 'pending'";
            $dateColumn = 'a.decided_at';
        }
        if (array_key_exists($request->query('type'), ApprovalService::TYPE_LABELS)) {
            $where[] = 'a.type = :type';
            $params['type'] = $request->query('type');
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            $value = $request->query($key);
            if ($value === '') {
                continue;
            }
            if (!Format::isDate($value)) {
                throw HttpException::validation([$key => 'Use a valid date.']);
            }
            $where[] = "$dateColumn $operator :$key";
            $params[$key] = Format::localDateToUtc($value, $key === 'to');
        }
        $search = $request->query('search');
        if (preg_match('/^(APR|PAY|REF)-/i', $search)) {
            $where[] = '(a.approval_no LIKE :doc1 OR b.bill_no LIKE :doc2 OR r.refund_no LIKE :doc3)';
            $params['doc1'] = $params['doc2'] = $params['doc3'] = '%' . addcslashes(strtoupper($search), '%_\\') . '%';
        } elseif ($condition = PatientService::searchCondition($search)) {
            [$where[], $searchParams] = $condition;
            $params += $searchParams;
        }

        $sqlWhere = ' WHERE ' . implode(' AND ', $where);
        $perPage = $request->perPage(25, 100);
        $page = $request->page();
        $offset = ($page - 1) * $perPage;
        $from = substr(self::SELECT, strpos(self::SELECT, ' FROM approval_requests a'));
        $total = (int) Database::value("SELECT COUNT(*) $from$sqlWhere", $params);
        $order = $status === 'pending' ? 'a.id ASC' : 'a.decided_at DESC';
        $rows = Database::all(self::SELECT . "$sqlWhere ORDER BY $order LIMIT $perPage OFFSET $offset", $params);

        return ['approvals' => array_map([$this, 'present'], $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage] + $this->count();
    }

    /** Pending counts by type (sidebar badge and queue cards). */
    public function count(): array
    {
        $counts = array_fill_keys(array_keys(ApprovalService::TYPE_LABELS), 0);
        foreach (Database::all("SELECT type, COUNT(*) AS c FROM approval_requests WHERE status = 'pending' GROUP BY type") as $row) {
            $counts[$row['type']] = (int) $row['c'];
        }
        return ['pending_counts' => $counts + ['all' => array_sum($counts)]];
    }

    public function show(Request $request, array $params): array
    {
        $approval = $this->find($params['id']);
        if ($approval['patient']) {
            Audit::access($approval['patient']['id'], 'approvals', 'view', 'approval', $approval['approval_no']);
        }
        $files = Database::all(
            'SELECT sf.id, sf.original_name_enc, sf.mime_type, sf.size_bytes, sf.uploaded_at
               FROM approval_files af JOIN stored_files sf ON sf.id = af.file_id WHERE af.approval_id = :id ORDER BY sf.id',
            ['id' => $params['id']]
        );
        return ['approval' => $approval + ['files' => array_map(static fn (array $f) => [
            'id' => (int) $f['id'], 'name' => FileStore::decryptName($f['original_name_enc']), 'mime_type' => $f['mime_type'],
            'size_bytes' => (int) $f['size_bytes'], 'uploaded_at' => Format::utc($f['uploaded_at']),
        ], $files)]];
    }

    public function approve(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->decimal('approved_amount', false, 0, 100000000)
            ->string('remarks', false, 500)
            ->int('row_version', true, 1)
            ->validate();
        $result = ApprovalService::approve($params['id'], $v['approved_amount'], $v['remarks'], $v['row_version'], $ctx);
        $amount = $result['approved_amount_final'];
        Audit::log('approvals', 'approval.approved',
            ((int) $result['requested_by'] === $ctx->userId ? 'Self-approved ' : 'Approved ')
            . strtolower(ApprovalService::TYPE_LABELS[$result['type']]) . " request {$result['approval_no']}" . ($amount !== null ? ' — TZS ' . number_format($amount) : ''),
            'approval', $result['approval_no'],
            $amount !== null && (float) $result['requested_amount'] !== $amount ? ['amount' => ['from' => (float) $result['requested_amount'], 'to' => $amount]] : null
        );
        return $this->show($request, $params);
    }

    public function reject(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('remarks', true, 500, 3)->int('row_version', true, 1)->validate();
        $result = ApprovalService::reject($params['id'], $v['remarks'], $v['row_version'], $ctx);
        Audit::log('approvals', 'approval.rejected', ((int) $result['requested_by'] === $ctx->userId ? 'Withdrew (rejected own) ' : 'Rejected ') . strtolower(ApprovalService::TYPE_LABELS[$result['type']]) . " request {$result['approval_no']}", 'approval', $result['approval_no']);
        return $this->show($request, $params);
    }

    /** Requester attaches a supporting document (e.g. sponsor letter) while the request is pending. */
    public function uploadFile(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $approval = Database::one('SELECT id, approval_no, status, requested_by FROM approval_requests WHERE id = :id', ['id' => $params['id']])
            ?? throw HttpException::notFound('Approval request not found.');
        if ((int) $approval['requested_by'] !== $ctx->userId || $approval['status'] !== 'pending') {
            throw new HttpException(403, 'forbidden', 'Documents can only be added by the requester while the request is pending.');
        }
        if ((int) Database::value('SELECT COUNT(*) FROM approval_files WHERE approval_id = :id', ['id' => (int) $approval['id']]) >= 5) {
            throw HttpException::validation(['file' => 'A request can have at most 5 documents.']);
        }
        $file = FileStore::store($request->file('file'), FileStore::SUPPORTING_DOCUMENT_TYPES, 10 * 1048576, $ctx->userId);
        Database::execute('INSERT INTO approval_files (approval_id, file_id) VALUES (:a, :f)', ['a' => (int) $approval['id'], 'f' => $file['id']]);
        Audit::log('approvals', 'approval.document_added', "Attached a supporting document to {$approval['approval_no']}", 'approval', $approval['approval_no']);
        return ['file' => ['id' => $file['id'], 'name' => $file['name'], 'mime_type' => $file['mime_type'], 'size_bytes' => $file['size_bytes']]];
    }

    public function downloadFile(Request $request, array $params): never
    {
        $row = Database::one(
            'SELECT af.file_id, a.approval_no, a.patient_id FROM approval_files af JOIN approval_requests a ON a.id = af.approval_id
              WHERE af.approval_id = :a AND af.file_id = :f',
            ['a' => $params['id'], 'f' => $params['fileId']]
        ) ?? throw HttpException::notFound('File not found.');
        $file = FileStore::read((int) $row['file_id']);
        if ($row['patient_id'] !== null) {
            Audit::access((int) $row['patient_id'], 'approvals', 'download', 'approval_file', (int) $row['file_id']);
        }
        Response::file($file['content'], $file['mime_type'], $file['name']);
    }

    private function find(int $id): array
    {
        $row = Database::one(self::SELECT . ' WHERE a.id = :id', ['id' => $id]) ?? throw HttpException::notFound('Approval request not found.');
        return $this->present($row);
    }

    private function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'approval_no' => $r['approval_no'],
            'type' => $r['type'],
            'type_label' => ApprovalService::TYPE_LABELS[$r['type']],
            'status' => $r['status'],
            'requested_amount' => $r['requested_amount'] === null ? null : (float) $r['requested_amount'],
            'approved_amount' => $r['approved_amount'] === null ? null : (float) $r['approved_amount'],
            'discount_type' => $r['discount_type'],
            'sponsor' => $r['sponsor'],
            'request_remarks' => Crypto::decrypt($r['request_remarks_enc'], 'approval_requests.request_remarks')
                ?? Crypto::decrypt($r['refund_reason_enc'], 'refunds.reason'),
            'decision_remarks' => Crypto::decrypt($r['decision_remarks_enc'], 'approval_requests.decision_remarks'),
            'requested_at' => Format::utc($r['requested_at']),
            'requested_by' => $r['requested_by_name'],
            'is_own_request' => (int) $r['requested_by'] === Auth::require()->userId,
            'is_self_approved' => $r['decided_by'] !== null && (int) $r['decided_by'] === (int) $r['requested_by'],
            'decided_at' => Format::utc($r['decided_at']),
            'decided_by' => $r['decided_by_name'] ?: null,
            'row_version' => (int) $r['row_version'],
            'file_count' => (int) $r['file_count'],
            'patient' => $r['patient_id'] === null ? null : PatientService::brief($r),
            'bill' => $r['bill_id'] === null ? null : [
                'id' => (int) $r['bill_id'], 'bill_no' => $r['bill_no'], 'bill_type' => $r['bill_type'], 'description' => $r['bill_description'],
                'gross_amount' => (float) $r['gross_amount'], 'discount_amount' => (float) $r['bill_discount'], 'net_amount' => (float) $r['net_amount'],
                'paid_amount' => (float) $r['paid_amount'], 'status' => $r['bill_status'], 'visit_no' => $r['visit_no'],
            ],
            'refund' => $r['refund_no'] === null ? null : ['refund_no' => $r['refund_no'], 'status' => $r['refund_status'], 'receipt_no' => $r['receipt_no']],
            'theatre_booking' => $r['theatre_booking_no'] === null ? null : [
                'booking_no' => $r['theatre_booking_no'], 'status' => $r['theatre_status'],
                'surgery' => $r['theatre_surgery'], 'eye' => $r['theatre_eye'],
            ],
            'optical_order' => $r['optical_order_no'] === null ? null : [
                'order_no' => $r['optical_order_no'], 'status' => $r['optical_status'], 'total_amount' => (float) $r['optical_total'],
            ],
            'booking' => $r['booking_no'] === null ? null : [
                'booking_no' => $r['booking_no'], 'procedure' => $r['booking_procedure'], 'eye' => $r['booking_eye'],
                'scheduled_for' => Format::utc($r['scheduled_for']), 'status' => $r['booking_status'],
            ],
        ];
    }
}
