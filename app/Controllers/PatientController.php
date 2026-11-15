<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\FileStore;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Sequence;
use App\Core\Settings;
use App\Core\Validator;
use App\Services\PatientService;

/** Patient registration, records, and scanned files. Opening a record or file is written to the access log. */
final class PatientController
{
    public function index(Request $request): array
    {
        $where = [];
        $params = [];
        if ($search = PatientService::searchCondition($request->query('search'))) {
            [$where[], $searchParams] = $search;
            $params += $searchParams;
        }
        foreach (['registered_from' => '>=', 'registered_to' => '<'] as $key => $operator) {
            $value = $request->query($key);
            if ($value === '') {
                continue;
            }
            if (!Format::isDate($value)) {
                throw HttpException::validation([$key => 'Use a valid date.']);
            }
            $where[] = "p.registered_at $operator :$key";
            $params[$key] = Format::localDateToUtc($value, $key === 'registered_to');
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $perPage = $request->perPage(25, 100);
        $page = $request->page();
        $offset = ($page - 1) * $perPage;

        $total = (int) Database::value("SELECT COUNT(*) FROM patients p$sqlWhere", $params);
        $rows = Database::all(
            "SELECT p.id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
                    p.phone_enc, p.status, p.registered_at,
                    (SELECT COUNT(*) FROM visits v WHERE v.patient_id = p.id AND v.status <> 'cancelled') AS visit_count,
                    (SELECT MAX(v.visit_date) FROM visits v WHERE v.patient_id = p.id AND v.status <> 'cancelled') AS last_visit_date
               FROM patients p$sqlWhere
              ORDER BY p.id DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        return [
            'patients' => array_map(static fn (array $r) => PatientService::present($r) + [
                'visit_count' => (int) $r['visit_count'],
                'last_visit_date' => $r['last_visit_date'],
            ], $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function show(Request $request, array $params): array
    {
        $row = $this->findRow($params['id']);
        Audit::access((int) $row['id'], 'patients', 'view', 'patient', $row['reg_no']);

        $visits = Database::all(
            'SELECT v.id, v.visit_no, v.visit_date, v.visit_type, v.sub_type, v.destination, v.room, v.status, v.is_emergency, pi.name AS investigation
               FROM visits v LEFT JOIN price_items pi ON pi.id = v.investigation_item_id
              WHERE v.patient_id = :id ORDER BY v.id DESC LIMIT 50',
            ['id' => (int) $row['id']]
        );
        $bills = Database::all(
            'SELECT b.id, b.bill_no, b.bill_type, b.description, b.gross_amount, b.discount_amount, b.net_amount, b.paid_amount,
                    b.refunded_amount, b.status, b.created_at, b.paid_at, v.visit_no
               FROM bills b LEFT JOIN visits v ON v.id = b.visit_id
              WHERE b.patient_id = :id ORDER BY b.id DESC LIMIT 50',
            ['id' => (int) $row['id']]
        );

        return [
            'patient' => PatientService::present($row, true) + ['registered_by' => $row['registered_by_name']],
            'visits' => array_map(static fn (array $v) => [
                'id' => (int) $v['id'], 'visit_no' => $v['visit_no'], 'visit_date' => $v['visit_date'], 'visit_type' => $v['visit_type'],
                'sub_type' => $v['sub_type'], 'destination' => $v['destination'], 'room' => $v['room'], 'investigation' => $v['investigation'],
                'status' => $v['status'], 'is_emergency' => (bool) $v['is_emergency'],
            ], $visits),
            'bills' => array_map(static fn (array $b) => [
                'id' => (int) $b['id'], 'bill_no' => $b['bill_no'], 'bill_type' => $b['bill_type'], 'description' => $b['description'],
                'gross_amount' => (float) $b['gross_amount'], 'discount_amount' => (float) $b['discount_amount'],
                'net_amount' => (float) $b['net_amount'], 'paid_amount' => (float) $b['paid_amount'], 'refunded_amount' => (float) $b['refunded_amount'],
                'status' => $b['status'], 'visit_no' => $b['visit_no'], 'created_at' => Format::utc($b['created_at']), 'paid_at' => Format::utc($b['paid_at']),
            ], $bills),
        ];
    }

    public function store(Request $request): array
    {
        $ctx = Auth::require();
        $d = PatientService::validate($request->all(), true);
        if (empty($d['confirm_duplicate']) && ($duplicates = PatientService::possibleDuplicates($d))) {
            throw new HttpException(409, 'possible_duplicate', 'A patient with matching details is already registered. Check the list before registering again.', ['duplicates' => $duplicates]);
        }

        $id = Database::transaction(function () use ($d, $ctx): int {
            $paper = ($d['paper_file_no'] ?? null) !== null;
            $values = PatientService::storageValues($d) + [
                // An existing paper patient keeps their file number; anyone else continues the series above it.
                'reg_no' => $paper ? PatientService::assertPaperNumber((string) $d['paper_file_no']) : self::nextNumber(),
                'paper_file' => $paper ? 1 : 0,
                'registered_by' => $ctx->userId,
            ];
            $columns = array_keys($values);
            $id = Database::insert(
                'INSERT INTO patients (' . implode(', ', $columns) . ', registered_at) VALUES (:' . implode(', :', $columns) . ', UTC_TIMESTAMP(3))',
                $values
            );
            PatientService::syncSearchTokens($id, $d);
            return $id;
        });

        $row = $this->findRow($id);
        Audit::log('patients', 'patient.registered', "Registered patient {$row['reg_no']}", 'patient', $row['reg_no']);
        return ['patient' => PatientService::present($row, true) + ['registered_by' => $row['registered_by_name']]];
    }

    /** The next number in the series; always above the paper range, never one already taken. */
    private static function nextNumber(): string
    {
        $paperMax = Settings::int('patients.paper_max', 0);
        do {
            $number = (string) Sequence::next('patient_reg');
        } while ((int) $number <= $paperMax || Database::value('SELECT 1 FROM patients WHERE reg_no = :n', ['n' => $number]));
        return $number;
    }

    /**
     * An administrator puts right a patient's file number: a paper number typed wrongly, or a
     * patient registered as new who turns out to have a paper file. Always with a reason.
     */
    public function correctFileNumber(Request $request, array $params): array
    {
        $before = $this->findRow($params['id']);
        $v = Validator::make($request->all())
            ->string('paper_file_no', true, 9, 1, '/^\d+$/', message: 'Enter the file number in digits only.')
            ->string('reason', true, 255, 3)
            ->validate();
        $number = PatientService::assertPaperNumber($v['paper_file_no'], (int) $before['id']);
        Database::execute(
            'UPDATE patients SET reg_no = :n, paper_file = 1, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id',
            ['n' => $number, 'by' => Auth::require()->userId, 'id' => (int) $before['id']]
        );
        Audit::log('patients', 'patient.file_number_corrected', "Changed file number {$before['reg_no']} to $number", 'patient', $number,
            ['reg_no' => ['from' => $before['reg_no'], 'to' => $number], 'reason' => $v['reason']]);
        $row = $this->findRow($before['id']);
        return ['patient' => PatientService::present($row, true) + ['registered_by' => $row['registered_by_name']]];
    }

    public function update(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $beforeRow = $this->findRow($params['id']);
        $before = PatientService::present($beforeRow, true);
        $d = PatientService::validate($request->all(), false);

        $identityChanged = $before['first_name'] !== $d['first_name'] || $before['last_name'] !== $d['last_name']
            || $before['date_of_birth'] !== $d['date_of_birth'] || $before['phone'] !== $d['phone'];
        if ($identityChanged && empty($d['confirm_duplicate']) && ($duplicates = PatientService::possibleDuplicates($d, $before['id']))) {
            throw new HttpException(409, 'possible_duplicate', 'Another patient already has matching details. Confirm this is not a duplicate record.', ['duplicates' => $duplicates]);
        }

        Database::transaction(function () use ($d, $before, $ctx): void {
            $values = PatientService::storageValues($d);
            $assignments = implode(', ', array_map(static fn (string $c) => "$c = :$c", array_keys($values)));
            $updated = Database::execute(
                "UPDATE patients SET $assignments, updated_at = UTC_TIMESTAMP(3), updated_by = :updated_by, row_version = row_version + 1
                  WHERE id = :id AND row_version = :version",
                $values + ['updated_by' => $ctx->userId, 'id' => $before['id'], 'version' => $d['row_version']]
            );
            if ($updated === 0) {
                throw HttpException::conflict('stale_record', 'This patient record was changed by someone else. Reload and try again.');
            }
            PatientService::syncSearchTokens($before['id'], $d);
        });

        $changed = array_values(array_filter(
            ['first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'phone', 'address', 'kin_name', 'kin_relationship',
             'kin_relationship_other', 'kin_phone'],
            static fn (string $field) => (string) ($before[$field] ?? '') !== (string) ($d[$field] ?? '')
        ));
        // Field names only — patient values never go into the audit log.
        Audit::log('patients', 'patient.updated', "Updated patient {$before['reg_no']}", 'patient', $before['reg_no'], $changed ? ['fields_changed' => $changed] : null);
        $row = $this->findRow($before['id']);
        return ['patient' => PatientService::present($row, true) + ['registered_by' => $row['registered_by_name']]];
    }

    // ─── Scanned records ─────────────────────────────────────────────────

    public function files(Request $request, array $params): array
    {
        $this->findRow($params['id']);
        $rows = Database::all(
            'SELECT pf.id, pf.category, pf.created_at, pf.voided_at, pf.void_reason, sf.original_name_enc, sf.mime_type, sf.size_bytes,
                    TRIM(CONCAT_WS(" ", u.first_name, u.last_name)) AS uploaded_by
               FROM patient_files pf
               JOIN stored_files sf ON sf.id = pf.file_id
               JOIN users u ON u.id = pf.created_by
              WHERE pf.patient_id = :id
              ORDER BY pf.voided_at IS NOT NULL, pf.id DESC',
            ['id' => $params['id']]
        );
        return ['files' => array_map(static fn (array $f) => [
            'id' => (int) $f['id'],
            'name' => FileStore::decryptName($f['original_name_enc']),
            'mime_type' => $f['mime_type'],
            'size_bytes' => (int) $f['size_bytes'],
            'category' => $f['category'],
            'uploaded_by' => $f['uploaded_by'],
            'uploaded_at' => Format::utc($f['created_at']),
            'voided' => $f['voided_at'] !== null,
            'void_reason' => $f['void_reason'],
        ], $rows)];
    }

    public function upload(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $patient = $this->findRow($params['id']);
        $file = FileStore::store($request->file('file'), FileStore::SCANNED_RECORD_TYPES, 20 * 1048576, $ctx->userId);
        $id = Database::insert(
            'INSERT INTO patient_files (patient_id, file_id, category, created_at, created_by) VALUES (:p, :f, :c, UTC_TIMESTAMP(3), :by)',
            ['p' => (int) $patient['id'], 'f' => $file['id'], 'c' => 'scanned_record', 'by' => $ctx->userId]
        );
        Audit::log('patients', 'patient.file_uploaded', "Uploaded a scanned record for {$patient['reg_no']}", 'patient_file', $id, ['size_bytes' => $file['size_bytes'], 'type' => $file['mime_type']]);
        return ['file' => ['id' => $id, 'name' => $file['name'], 'mime_type' => $file['mime_type'], 'size_bytes' => $file['size_bytes']]];
    }

    public function download(Request $request, array $params): never
    {
        $row = Database::one(
            'SELECT pf.id, pf.file_id, pf.voided_at, p.id AS patient_id, p.reg_no
               FROM patient_files pf JOIN patients p ON p.id = pf.patient_id
              WHERE pf.patient_id = :p AND pf.id = :f',
            ['p' => $params['id'], 'f' => $params['fileId']]
        ) ?? throw HttpException::notFound('File not found.');
        if ($row['voided_at'] !== null) {
            throw new HttpException(410, 'file_removed', 'This file was removed from the record.');
        }
        $file = FileStore::read((int) $row['file_id']);
        Audit::access((int) $row['patient_id'], 'patients', 'download', 'patient_file', (int) $row['id']);
        Response::file($file['content'], $file['mime_type'], $file['name']);
    }

    public function void(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('reason', true, 255, 3)->validate();
        $row = Database::one(
            'SELECT pf.id, p.reg_no FROM patient_files pf JOIN patients p ON p.id = pf.patient_id WHERE pf.patient_id = :p AND pf.id = :f AND pf.voided_at IS NULL',
            ['p' => $params['id'], 'f' => $params['fileId']]
        ) ?? throw HttpException::notFound('File not found.');
        Database::execute(
            'UPDATE patient_files SET voided_at = UTC_TIMESTAMP(3), voided_by = :by, void_reason = :r WHERE id = :id',
            ['by' => $ctx->userId, 'r' => $v['reason'], 'id' => (int) $row['id']]
        );
        Audit::log('patients', 'patient.file_removed', "Removed a scanned record from {$row['reg_no']}", 'patient_file', (int) $row['id'], ['reason' => $v['reason']]);
        return ['removed' => true];
    }

    private function findRow(int $id): array
    {
        return Database::one(
            'SELECT ' . PatientService::COLUMNS . ', TRIM(CONCAT_WS(" ", u.first_name, u.last_name)) AS registered_by_name
               FROM patients p JOIN users u ON u.id = p.registered_by WHERE p.id = :id',
            ['id' => $id]
        ) ?? throw HttpException::notFound('Patient not found.');
    }
}
