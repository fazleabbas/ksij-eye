<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuthContext;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;

/**
 * Reports over the live data.
 *
 * Money is counted the same way everywhere: **billed** is what was charged, **collected** is what
 * was actually received, and **outstanding** is what is still owed. A patient counts as VIP for a
 * day if they had a fast-track visit or were operated on in a theatre room marked VIP, so every
 * charge of theirs that day is read the same way.
 *
 * Each report returns the same shape: title, kpis, columns and rows, so one screen renders them
 * all and one exporter writes them all to CSV.
 */
final class ReportService
{
    /** Every report, what it needs to be seen, and how far back it looks by default. */
    public const REPORTS = [
        'collections' => ['title' => 'Daily Collections', 'permission' => 'reports.financial', 'days' => 0],
        'financial' => ['title' => 'Financial Summary', 'permission' => 'reports.financial', 'days' => 30],
        'waivers' => ['title' => 'Discounts and Waivers', 'permission' => 'reports.financial', 'days' => 30],
        'reception' => ['title' => 'Reception and Visits', 'permission' => 'reports.view', 'days' => 7],
        'patients' => ['title' => 'Patients', 'permission' => 'reports.view', 'days' => 30],
        'diagnostics' => ['title' => 'Diagnostic Tests', 'permission' => 'reports.clinical', 'days' => 30],
        'theatre' => ['title' => 'Surgery', 'permission' => 'reports.clinical', 'days' => 30],
        'optical' => ['title' => 'Optical Orders', 'permission' => 'reports.view', 'days' => 30],
        'pharmacy' => ['title' => 'Pharmacy', 'permission' => 'reports.view', 'days' => 30],
        'stores' => ['title' => 'Stores and Procurement', 'permission' => 'reports.view', 'days' => 30],
        'mtuha_main' => ['title' => 'Main MTUHA Register', 'permission' => 'mtuha.view', 'days' => 30],
        'mtuha_surgery' => ['title' => 'Surgery MTUHA Register', 'permission' => 'mtuha.view', 'days' => 30],
        'pediatric' => ['title' => 'Pediatric Report', 'permission' => 'mtuha.view', 'days' => 30],
        'glaucoma' => ['title' => 'Glaucoma Report', 'permission' => 'mtuha.view', 'days' => 30],
    ];

    public static function run(string $type, array $filters, AuthContext $ctx): array
    {
        $definition = self::REPORTS[$type] ?? throw HttpException::notFound('Unknown report.');
        if (!$ctx->can($definition['permission'])) {
            throw new \App\Core\Http\HttpException(403, 'forbidden', 'You cannot see this report.');
        }
        [$from, $to] = self::period($filters, (int) $definition['days']);
        $report = match ($type) {
            'collections' => self::collections($from, $to, $filters),
            'financial' => self::financial($from, $to, $filters),
            'waivers' => self::waivers($from, $to, $filters),
            'reception' => self::reception($from, $to, $filters),
            'patients' => self::patients($from, $to, $filters),
            'diagnostics' => self::diagnostics($from, $to, $filters),
            'theatre' => self::theatre($from, $to, $filters),
            'optical' => self::optical($from, $to, $filters),
            'pharmacy' => self::pharmacy($from, $to, $filters),
            'stores' => self::stores($from, $to, $filters),
            'mtuha_main' => self::mtuhaMain($from, $to, $filters),
            'mtuha_surgery' => self::mtuhaSurgery($from, $to, $filters),
            'pediatric' => self::clinicRegister('pediatric', $from, $to, $filters),
            'glaucoma' => self::clinicRegister('glaucoma', $from, $to, $filters),
        };
        return $report + [
            'type' => $type,
            'title' => $definition['title'],
            'from' => $from,
            'to' => $to,
            'generated_at' => Format::utc(gmdate('Y-m-d H:i:s')),
        ];
    }

    /** @return array{0:string,1:string} the local dates the report covers */
    private static function period(array $filters, int $days): array
    {
        $to = Format::isDate((string) ($filters['to'] ?? '')) ? (string) $filters['to'] : Format::localToday();
        $from = Format::isDate((string) ($filters['from'] ?? '')) ? (string) $filters['from'] : Format::localToday(-$days);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }

    private static function utcRange(string $from, string $to): array
    {
        return ['from' => Format::localDateToUtc($from), 'to' => Format::localDateToUtc($to, true)];
    }

    /** Patients who were fast-tracked or operated on in a VIP theatre, by local date. */
    private static function vipDays(string $from, string $to): array
    {
        $range = self::utcRange($from, $to);
        $rows = Database::all(
            "SELECT DISTINCT v.patient_id, DATE(CONVERT_TZ(v.created_at, '+00:00', '+03:00')) AS day
               FROM visits v
              WHERE v.is_fast_track = 1 AND v.created_at >= :from AND v.created_at < :to
              UNION
             SELECT DISTINCT b.patient_id, DATE(CONVERT_TZ(b.scheduled_for, '+00:00', '+03:00')) AS day
               FROM theatre_bookings b
               JOIN lookup_values room ON room.id = b.theatre_room_id
              WHERE room.label LIKE '%VIP%' AND b.scheduled_for >= :from2 AND b.scheduled_for < :to2",
            ['from' => $range['from'], 'to' => $range['to'], 'from2' => $range['from'], 'to2' => $range['to']]
        );
        $vip = [];
        foreach ($rows as $row) {
            $vip[$row['patient_id'] . '|' . $row['day']] = true;
        }
        return $vip;
    }

    private static function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    // ─── Daily collections ───────────────────────────────────────────────

    /**
     * The cash sheet: one row per patient per day, with a money column for each kind of bill.
     * Columns follow the bill categories, so they always match what the clinic actually sells.
     */
    private static function collections(string $from, string $to, array $filters): array
    {
        $range = self::utcRange($from, $to);
        $types = array_column(Database::all('SELECT DISTINCT bill_type FROM bills ORDER BY bill_type'), 'bill_type');
        if ($types === []) {
            $types = ['consultation', 'investigation', 'optical', 'surgery', 'pharmacy', 'other'];
        }
        $rows = Database::all(
            "SELECT p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender,
                    p.date_of_birth_enc, p.phone_enc,
                    w.id AS walkin_id, w.walkin_no, w.name_enc AS walkin_name_enc, w.phone_enc AS walkin_phone_enc,
                    w.gender AS walkin_gender, w.age_years,
                    DATE(CONVERT_TZ(pay.received_at, '+00:00', '+03:00')) AS day,
                    b.bill_type, b.gross_amount, b.discount_amount, b.net_amount, b.discount_type, b.discount_sponsor,
                    b.description, pay.receipt_no, pay.method, pay.txn_ref,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS cashier
               FROM payments pay
               JOIN bills b ON b.id = pay.bill_id
               -- Counter sales are paid by walk-in customers, who are not patients; they still count.
               LEFT JOIN patients p ON p.id = pay.patient_id
               LEFT JOIN pharmacy_walkins w ON w.id = pay.walkin_id
               JOIN users u ON u.id = pay.received_by
              WHERE pay.voided_at IS NULL AND pay.received_at >= :from AND pay.received_at < :to
              ORDER BY day DESC, COALESCE(p.reg_no, w.walkin_no)",
            $range
        );
        $vip = self::vipDays($from, $to);
        $sheet = [];
        foreach ($rows as $r) {
            $walkIn = $r['patient_id'] === null;
            $key = ($walkIn ? 'w' . $r['walkin_id'] : $r['patient_id']) . '|' . $r['day'];
            if (!isset($sheet[$key])) {
                $patient = $walkIn ? [
                    'reg_no' => (string) $r['walkin_no'],
                    'full_name' => (string) Crypto::decrypt($r['walkin_name_enc'], 'pharmacy_walkins.name'),
                    'gender' => $r['walkin_gender'],
                    'age' => $r['age_years'] === null ? '' : (int) $r['age_years'],
                ] : PatientService::brief($r);
                $sheet[$key] = [
                    'date' => $r['day'],
                    'reg_no' => $patient['reg_no'],
                    'patient' => $patient['full_name'],
                    'gender' => $patient['gender'],
                    'age' => $patient['age'],
                    'phone' => $walkIn
                        ? Crypto::decrypt($r['walkin_phone_enc'], 'pharmacy_walkins.phone')
                        : Crypto::decrypt($r['phone_enc'], 'patients.phone'),
                    'is_vip' => isset($vip[$key]) ? 'Yes' : '',
                    'receipts' => [],
                    'reference' => [],
                    'tags' => [],
                    'sponsors' => [],
                    'details' => [],
                    'cashiers' => [],
                    'billed' => 0.0,
                    'discount' => 0.0,
                    'collected' => 0.0,
                ];
                foreach ($types as $type) {
                    $sheet[$key][$type] = 0.0;
                }
            }
            $row = &$sheet[$key];
            $row[$r['bill_type']] = self::money(($row[$r['bill_type']] ?? 0) + (float) $r['net_amount']);
            $row['billed'] = self::money($row['billed'] + (float) $r['gross_amount']);
            $row['discount'] = self::money($row['discount'] + (float) $r['discount_amount']);
            $row['collected'] = self::money($row['collected'] + (float) $r['net_amount']);
            $row['receipts'][$r['receipt_no']] = true;
            if ($r['txn_ref']) {
                $row['reference'][$r['txn_ref']] = true;
            }
            if ($r['discount_type']) {
                $row['tags'][$r['discount_type']] = true;
            }
            if ($r['discount_sponsor']) {
                $row['sponsors'][$r['discount_sponsor']] = true;
            }
            $row['details'][$r['description']] = true;
            $row['cashiers'][$r['cashier']] = true;
            unset($row);
        }
        $out = [];
        foreach ($sheet as $row) {
            foreach (['receipts', 'reference', 'tags', 'sponsors', 'details', 'cashiers'] as $list) {
                $row[$list] = implode(', ', array_keys($row[$list]));
            }
            $out[] = $row;
        }
        $out = self::search($out, $filters['search'] ?? '', ['reg_no', 'patient', 'details']);

        $columns = [
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'reg_no', 'label' => 'File no.'],
            ['key' => 'patient', 'label' => 'Patient'],
            ['key' => 'gender', 'label' => 'Sex'],
            ['key' => 'age', 'label' => 'Age'],
            ['key' => 'phone', 'label' => 'Phone'],
            ['key' => 'is_vip', 'label' => 'VIP'],
        ];
        foreach ($types as $type) {
            $columns[] = ['key' => $type, 'label' => ucfirst($type), 'money' => true];
        }
        $columns = array_merge($columns, [
            ['key' => 'billed', 'label' => 'Billed', 'money' => true],
            ['key' => 'discount', 'label' => 'Discount', 'money' => true],
            ['key' => 'collected', 'label' => 'Collected', 'money' => true],
            ['key' => 'reference', 'label' => 'Lipa reference'],
            ['key' => 'tags', 'label' => 'Discount type'],
            ['key' => 'sponsors', 'label' => 'Sponsor'],
            ['key' => 'details', 'label' => 'Details'],
            ['key' => 'receipts', 'label' => 'Receipts'],
            ['key' => 'cashiers', 'label' => 'Taken by'],
        ]);
        return [
            'kpis' => [
                ['label' => 'Patient days', 'value' => count($out)],
                ['label' => 'Billed', 'value' => self::money(array_sum(array_column($out, 'billed'))), 'money' => true],
                ['label' => 'Discounts', 'value' => self::money(array_sum(array_column($out, 'discount'))), 'money' => true],
                ['label' => 'Collected', 'value' => self::money(array_sum(array_column($out, 'collected'))), 'money' => true],
            ],
            'columns' => $columns,
            'rows' => $out,
        ];
    }

    // ─── Financial summary ───────────────────────────────────────────────

    private static function financial(string $from, string $to, array $filters): array
    {
        $range = self::utcRange($from, $to);
        $bills = Database::all(
            "SELECT b.id, b.bill_type, b.status, b.gross_amount, b.discount_amount, b.net_amount, b.paid_amount,
                    b.description, b.created_at, b.patient_id,
                    DATE(CONVERT_TZ(b.created_at, '+00:00', '+03:00')) AS day,
                    p.id AS patient_id_joined, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc,
                    p.gender, p.date_of_birth_enc, w.walkin_no
               FROM bills b
               LEFT JOIN patients p ON p.id = b.patient_id
               LEFT JOIN pharmacy_walkins w ON w.id = b.walkin_id
              WHERE b.created_at >= :from AND b.created_at < :to
              ORDER BY b.created_at DESC",
            $range
        );
        $vip = self::vipDays($from, $to);
        $lines = self::byKey(
            'SELECT bi.bill_id, COALESCE(pi.name, bi.description) AS item, bi.line_total
               FROM bill_items bi LEFT JOIN price_items pi ON pi.id = bi.price_item_id WHERE bi.bill_id IN (:ids)',
            array_map(static fn ($b) => (int) $b['id'], $bills), 'bill_id');
        $byType = [];
        $byItem = [];
        $billed = $collected = $outstanding = $discounts = 0.0;
        $rows = [];
        foreach ($bills as $b) {
            $isVip = $b['patient_id'] !== null && isset($vip[$b['patient_id'] . '|' . $b['day']]);
            $live = !in_array($b['status'], ['cancelled'], true);
            if ($live) {
                // What was collected on a bill is shared across its lines by their value.
                $billLines = $lines[$b['id']] ?? [];
                $lineTotal = array_sum(array_column($billLines, 'line_total'));
                foreach ($billLines as $line) {
                    $share = $lineTotal > 0 ? (float) $b['paid_amount'] * (float) $line['line_total'] / $lineTotal : 0.0;
                    $item = &$byItem[$b['bill_type']][$line['item']];
                    $item ??= ['vip' => 0.0, 'normal' => 0.0, 'total' => 0.0, 'bills' => []];
                    $item[$isVip ? 'vip' : 'normal'] += $share;
                    $item['total'] += $share;
                    $item['bills'][$b['id']] = true;
                    unset($item);
                }
                $billed += (float) $b['gross_amount'];
                $discounts += (float) $b['discount_amount'];
                $collected += (float) $b['paid_amount'];
                if (in_array($b['status'], ['pending', 'discount_requested', 'cancellation_requested'], true)) {
                    $outstanding += (float) $b['net_amount'] - (float) $b['paid_amount'];
                }
                $type = $b['bill_type'];
                $byType[$type] ??= ['type' => ucfirst($type), 'vip' => 0.0, 'normal' => 0.0, 'total' => 0.0, 'bills' => 0];
                $byType[$type][$isVip ? 'vip' : 'normal'] += (float) $b['paid_amount'];
                $byType[$type]['total'] += (float) $b['paid_amount'];
                $byType[$type]['bills']++;
            }
            if (($filters['status'] ?? '') !== '' && $b['status'] !== $filters['status']) {
                continue;
            }
            $rows[] = [
                'date' => $b['day'],
                'bill_no' => (string) Database::value('SELECT bill_no FROM bills WHERE id = :id', ['id' => (int) $b['id']]),
                'patient' => $b['patient_id'] === null ? 'Counter sale' : PatientService::brief($b)['full_name'],
                'reg_no' => $b['patient_id'] === null ? (string) $b['walkin_no'] : $b['reg_no'],
                'type' => ucfirst($b['bill_type']),
                'tier' => $isVip ? 'VIP' : 'Normal',
                'description' => $b['description'],
                'billed' => self::money($b['gross_amount']),
                'discount' => self::money($b['discount_amount']),
                'collected' => self::money($b['paid_amount']),
                'status' => $b['status'],
            ];
        }
        $rows = self::search($rows, $filters['search'] ?? '', ['reg_no', 'patient', 'bill_no', 'description']);
        // Each category's total, then what it was made of: one line per item with its number of cases.
        $summary = [];
        foreach ($byType as $type => $group) {
            $summary[] = ['type' => "{$group['type']} — total", 'level' => 0, 'vip' => self::money($group['vip']),
                'normal' => self::money($group['normal']), 'total' => self::money($group['total']), 'bills' => $group['bills']];
            $items = $byItem[$type] ?? [];
            uasort($items, static fn (array $a, array $b) => $b['total'] <=> $a['total']);
            foreach ($items as $name => $item) {
                $cases = count($item['bills']);
                $summary[] = ['type' => "$name ($cases " . ($cases === 1 ? 'case' : 'cases') . ')', 'level' => 1,
                    'vip' => self::money($item['vip']), 'normal' => self::money($item['normal']),
                    'total' => self::money($item['total']), 'bills' => $cases];
            }
        }
        return [
            'kpis' => [
                ['label' => 'Billed', 'value' => self::money($billed), 'money' => true],
                ['label' => 'Collected', 'value' => self::money($collected), 'money' => true],
                ['label' => 'Discounts given', 'value' => self::money($discounts), 'money' => true],
                ['label' => 'Still owed', 'value' => self::money($outstanding), 'money' => true],
            ],
            'summary' => [
                'title' => 'Collected by category and item — VIP and normal',
                'columns' => [
                    ['key' => 'type', 'label' => 'Category / item'],
                    ['key' => 'vip', 'label' => 'VIP', 'money' => true],
                    ['key' => 'normal', 'label' => 'Normal', 'money' => true],
                    ['key' => 'total', 'label' => 'Total', 'money' => true],
                    ['key' => 'bills', 'label' => 'Bills'],
                ],
                'rows' => $summary,
            ],
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'bill_no', 'label' => 'Bill'],
                ['key' => 'patient', 'label' => 'Patient'],
                ['key' => 'reg_no', 'label' => 'File no.'],
                ['key' => 'type', 'label' => 'Category'],
                ['key' => 'tier', 'label' => 'Tier'],
                ['key' => 'description', 'label' => 'For'],
                ['key' => 'billed', 'label' => 'Billed', 'money' => true],
                ['key' => 'discount', 'label' => 'Discount', 'money' => true],
                ['key' => 'collected', 'label' => 'Collected', 'money' => true],
                ['key' => 'status', 'label' => 'Status', 'label_group' => 'billStatus'],
            ],
            'rows' => $rows,
        ];
    }

    // ─── Discounts and waivers ───────────────────────────────────────────

    /** Every scheme in one report: filter by discount type, or by age for the children's scheme. */
    private static function waivers(string $from, string $to, array $filters): array
    {
        $range = self::utcRange($from, $to);
        $where = ['b.created_at >= :from', 'b.created_at < :to', 'b.discount_amount > 0'];
        $params = $range;
        if (($filters['scheme'] ?? '') !== '') {
            $where[] = 'b.discount_type = :scheme';
            $params['scheme'] = $filters['scheme'];
        }
        $bills = Database::all(
            "SELECT b.bill_no, b.bill_type, b.status, b.gross_amount, b.discount_amount, b.net_amount, b.paid_amount,
                    b.discount_type, b.discount_sponsor, b.description, b.patient_id,
                    DATE(CONVERT_TZ(b.created_at, '+00:00', '+03:00')) AS day,
                    (SELECT CONCAT(IF(ar.requested_by = ar.decided_by, 'Self-approved — ', ''), TRIM(CONCAT_WS(' ', du.first_name, du.last_name)))
                       FROM approval_requests ar JOIN users du ON du.id = ar.decided_by
                      WHERE ar.bill_id = b.id AND ar.type = 'discount' AND ar.status = 'approved'
                      ORDER BY ar.decided_at DESC LIMIT 1) AS approved_by,
                    p.id AS patient_id_joined, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc,
                    p.gender, p.date_of_birth_enc
               FROM bills b
               LEFT JOIN patients p ON p.id = b.patient_id
              WHERE " . implode(' AND ', $where) . '
              ORDER BY b.created_at DESC',
            $params
        );
        $maxAge = ($filters['max_age'] ?? '') === '' ? null : (int) $filters['max_age'];
        $rows = [];
        $schemes = [];
        $patients = [];
        foreach ($bills as $b) {
            $patient = $b['patient_id'] === null ? null : PatientService::brief($b);
            if ($maxAge !== null && ($patient === null || $patient['age'] > $maxAge)) {
                continue;
            }
            $scheme = $b['discount_type'] ?: 'Not stated';
            $schemes[$scheme] ??= ['scheme' => $scheme, 'patients' => [], 'bills' => 0, 'billed' => 0.0, 'discount' => 0.0, 'collected' => 0.0];
            $schemes[$scheme]['bills']++;
            $schemes[$scheme]['billed'] += (float) $b['gross_amount'];
            $schemes[$scheme]['discount'] += (float) $b['discount_amount'];
            $schemes[$scheme]['collected'] += (float) $b['paid_amount'];
            if ($b['patient_id'] !== null) {
                $schemes[$scheme]['patients'][$b['patient_id']] = true;
                $patients[$b['patient_id']] = true;
            }
            $rows[] = [
                'date' => $b['day'],
                'bill_no' => $b['bill_no'],
                'patient' => $patient['full_name'] ?? 'Counter sale',
                'reg_no' => $patient['reg_no'] ?? '',
                'age' => $patient['age'] ?? '',
                'scheme' => $scheme,
                'sponsor' => $b['discount_sponsor'],
                'type' => ucfirst($b['bill_type']),
                'description' => $b['description'],
                'billed' => self::money($b['gross_amount']),
                'discount' => self::money($b['discount_amount']),
                'collected' => self::money($b['paid_amount']),
                'approved_by' => (string) $b['approved_by'],
                'status' => $b['status'],
            ];
        }
        $rows = self::search($rows, $filters['search'] ?? '', ['reg_no', 'patient', 'bill_no', 'scheme', 'approved_by']);
        $summary = array_map(static fn (array $s) => [
            'scheme' => $s['scheme'],
            'patients' => count($s['patients']),
            'bills' => $s['bills'],
            'billed' => self::money($s['billed']),
            'discount' => self::money($s['discount']),
            'collected' => self::money($s['collected']),
        ], array_values($schemes));
        return [
            'kpis' => [
                ['label' => 'Patients helped', 'value' => count($patients)],
                ['label' => 'Bills', 'value' => count($rows)],
                ['label' => 'Given up', 'value' => self::money(array_sum(array_column($summary, 'discount'))), 'money' => true],
                ['label' => 'Still collected', 'value' => self::money(array_sum(array_column($summary, 'collected'))), 'money' => true],
                ['label' => 'Self-approved', 'value' => count(array_filter($rows, static fn (array $r) => str_starts_with($r['approved_by'], 'Self-approved')))],
            ],
            'summary' => [
                'title' => 'By scheme',
                'columns' => [
                    ['key' => 'scheme', 'label' => 'Scheme'],
                    ['key' => 'patients', 'label' => 'Patients'],
                    ['key' => 'bills', 'label' => 'Bills'],
                    ['key' => 'billed', 'label' => 'Billed', 'money' => true],
                    ['key' => 'discount', 'label' => 'Given up', 'money' => true],
                    ['key' => 'collected', 'label' => 'Collected', 'money' => true],
                ],
                'rows' => $summary,
            ],
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'bill_no', 'label' => 'Bill'],
                ['key' => 'patient', 'label' => 'Patient'],
                ['key' => 'reg_no', 'label' => 'File no.'],
                ['key' => 'age', 'label' => 'Age'],
                ['key' => 'scheme', 'label' => 'Scheme'],
                ['key' => 'sponsor', 'label' => 'Sponsor'],
                ['key' => 'type', 'label' => 'Category'],
                ['key' => 'description', 'label' => 'For'],
                ['key' => 'billed', 'label' => 'Billed', 'money' => true],
                ['key' => 'discount', 'label' => 'Given up', 'money' => true],
                ['key' => 'collected', 'label' => 'Collected', 'money' => true],
                ['key' => 'approved_by', 'label' => 'Approved by'],
                ['key' => 'status', 'label' => 'Status', 'label_group' => 'billStatus'],
            ],
            'rows' => $rows,
        ];
    }

    // ─── Reception and visits ────────────────────────────────────────────

    private static function reception(string $from, string $to, array $filters): array
    {
        $range = self::utcRange($from, $to);
        $where = ['v.created_at >= :from', 'v.created_at < :to'];
        $params = $range;
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'v.status = :status';
            $params['status'] = $filters['status'];
        }
        $visits = Database::all(
            "SELECT v.visit_no, v.visit_type, v.sub_type, v.destination, v.room, v.status, v.is_emergency, v.created_at,
                    DATE(CONVERT_TZ(v.created_at, '+00:00', '+03:00')) AS day,
                    p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender,
                    p.date_of_birth_enc, p.phone_enc,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS registered_by,
                    (SELECT COALESCE(SUM(b.gross_amount), 0) FROM bills b WHERE b.visit_id = v.id) AS billed,
                    (SELECT COALESCE(SUM(b.paid_amount), 0) FROM bills b WHERE b.visit_id = v.id) AS collected
               FROM visits v
               JOIN patients p ON p.id = v.patient_id
               JOIN users u ON u.id = v.created_by
              WHERE " . implode(' AND ', $where) . '
              ORDER BY v.created_at DESC',
            $params
        );
        $rows = array_map(static function (array $v): array {
            $patient = PatientService::brief($v);
            return [
                'date' => $v['day'],
                'visit_no' => $v['visit_no'],
                'patient' => $patient['full_name'],
                'reg_no' => $patient['reg_no'],
                'gender' => $patient['gender'],
                'age' => $patient['age'],
                'phone' => Crypto::decrypt($v['phone_enc'], 'patients.phone'),
                'visit_type' => $v['visit_type'],
                'sub_type' => $v['sub_type'],
                'destination' => $v['destination'],
                'room' => $v['room'],
                'emergency' => $v['is_emergency'] ? 'Yes' : '',
                'registered_by' => $v['registered_by'],
                'billed' => self::money($v['billed']),
                'collected' => self::money($v['collected']),
                'status' => $v['status'],
            ];
        }, $visits);
        $rows = self::search($rows, $filters['search'] ?? '', ['reg_no', 'patient', 'visit_no']);
        $newPatients = (int) Database::value(
            'SELECT COUNT(*) FROM patients WHERE registered_at >= :from AND registered_at < :to',
            $range
        );
        return [
            'kpis' => [
                ['label' => 'Visits', 'value' => count($rows)],
                ['label' => 'New patients', 'value' => $newPatients],
                ['label' => 'Still in progress', 'value' => count(array_filter($rows, static fn (array $r) => !in_array($r['status'], ['completed', 'cancelled'], true)))],
                ['label' => 'Collected', 'value' => self::money(array_sum(array_column($rows, 'collected'))), 'money' => true],
            ],
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'visit_no', 'label' => 'Visit'],
                ['key' => 'patient', 'label' => 'Patient'],
                ['key' => 'reg_no', 'label' => 'File no.'],
                ['key' => 'gender', 'label' => 'Sex', 'label_group' => 'gender'],
                ['key' => 'age', 'label' => 'Age'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'visit_type', 'label' => 'Type', 'label_group' => 'visitType'],
                ['key' => 'sub_type', 'label' => 'Sub-type', 'label_group' => 'subType'],
                ['key' => 'destination', 'label' => 'Sent to', 'label_group' => 'destination'],
                ['key' => 'room', 'label' => 'Room'],
                ['key' => 'emergency', 'label' => 'Emergency'],
                ['key' => 'registered_by', 'label' => 'Registered by'],
                ['key' => 'billed', 'label' => 'Billed', 'money' => true],
                ['key' => 'collected', 'label' => 'Collected', 'money' => true],
                ['key' => 'status', 'label' => 'Status', 'label_group' => 'visitStatus'],
            ],
            'rows' => $rows,
        ];
    }

    // ─── Patients ────────────────────────────────────────────────────────

    private static function patients(string $from, string $to, array $filters): array
    {
        $range = self::utcRange($from, $to);
        $patients = Database::all(
            "SELECT p.id, p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender,
                    p.date_of_birth_enc, p.phone_enc, p.address_enc, p.status, p.registered_at,
                    DATE(CONVERT_TZ(p.registered_at, '+00:00', '+03:00')) AS day,
                    (SELECT COUNT(*) FROM visits v WHERE v.patient_id = p.id) AS visits,
                    (SELECT MAX(v.created_at) FROM visits v WHERE v.patient_id = p.id) AS last_visit
               FROM patients p
              WHERE p.registered_at >= :from AND p.registered_at < :to
              ORDER BY p.registered_at DESC",
            $range
        );
        $rows = array_map(static function (array $p): array {
            $brief = PatientService::brief($p);
            return [
                'registered' => $p['day'],
                'reg_no' => $brief['reg_no'],
                'patient' => $brief['full_name'],
                'gender' => $brief['gender'],
                'age' => $brief['age'],
                'phone' => Crypto::decrypt($p['phone_enc'], 'patients.phone'),
                'address' => Crypto::decrypt($p['address_enc'], 'patients.address'),
                'visits' => (int) $p['visits'],
                'last_visit' => $p['last_visit'] === null ? '' : substr((string) $p['last_visit'], 0, 10),
                'status' => $p['status'],
            ];
        }, $patients);
        $rows = self::search($rows, $filters['search'] ?? '', ['reg_no', 'patient', 'phone']);
        $total = (int) Database::value('SELECT COUNT(*) FROM patients');
        return [
            'kpis' => [
                ['label' => 'Registered in this period', 'value' => count($rows)],
                ['label' => 'Female', 'value' => count(array_filter($rows, static fn (array $r) => $r['gender'] === 'female'))],
                ['label' => 'Male', 'value' => count(array_filter($rows, static fn (array $r) => $r['gender'] === 'male'))],
                ['label' => 'Patients on file', 'value' => $total],
                // The paper series and the system series are one: the top number is how far it has reached.
                ['label' => 'Highest file number', 'value' => max(
                    (int) (Database::value('SELECT MAX(CAST(reg_no AS UNSIGNED)) FROM patients') ?? 0),
                    (int) (Database::value("SELECT setting_value FROM settings WHERE setting_key = 'patients.paper_max'") ?? 0)
                )],
            ],
            'columns' => [
                ['key' => 'registered', 'label' => 'Registered'],
                ['key' => 'reg_no', 'label' => 'File no.'],
                ['key' => 'patient', 'label' => 'Patient'],
                ['key' => 'gender', 'label' => 'Sex', 'label_group' => 'gender'],
                ['key' => 'age', 'label' => 'Age'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'address', 'label' => 'Address'],
                ['key' => 'visits', 'label' => 'Visits'],
                ['key' => 'last_visit', 'label' => 'Last seen'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $rows,
        ];
    }

    // ─── Clinical and module reports ─────────────────────────────────────

    private static function diagnostics(string $from, string $to, array $filters): array
    {
        $range = self::utcRange($from, $to);
        $where = ['b.scheduled_for >= :from', 'b.scheduled_for < :to'];
        $params = $range;
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'b.status = :status';
            $params['status'] = $filters['status'];
        }
        $bookings = Database::all(
            "SELECT b.booking_no, b.status, b.eye, b.source, b.scheduled_for, b.completed_at,
                    DATE(CONVERT_TZ(b.scheduled_for, '+00:00', '+03:00')) AS day,
                    pi.name AS procedure_name, lt.label AS laser_type,
                    p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
                    TRIM(CONCAT_WS(' ', ru.first_name, ru.last_name)) AS referred_by,
                    TRIM(CONCAT_WS(' ', dr.first_name, dr.last_name)) AS reported_by,
                    bill.status AS bill_status, bill.net_amount
               FROM diagnostic_bookings b
               JOIN price_items pi ON pi.id = b.price_item_id
               JOIN patients p ON p.id = b.patient_id
          LEFT JOIN users ru ON ru.id = b.referring_user_id
               LEFT JOIN lookup_values lt ON lt.id = b.laser_type_id
               LEFT JOIN diagnostic_results r ON r.booking_id = b.id
               LEFT JOIN users dr ON dr.id = r.reporting_user_id
               LEFT JOIN bills bill ON bill.id = b.bill_id
              WHERE " . implode(' AND ', $where) . '
              ORDER BY b.scheduled_for DESC',
            $params
        );
        $rows = array_map(static function (array $b): array {
            $patient = PatientService::brief($b);
            return [
                'date' => $b['day'],
                'booking_no' => $b['booking_no'],
                'patient' => $patient['full_name'],
                'reg_no' => $patient['reg_no'],
                'test' => $b['procedure_name'] . ($b['laser_type'] ? " ({$b['laser_type']})" : ''),
                'eye' => $b['eye'],
                'source' => $b['source'],
                'referred_by' => $b['referred_by'],
                'reported_by' => $b['reported_by'] ?? '',
                'charged' => self::money($b['net_amount'] ?? 0),
                'bill_status' => $b['bill_status'] ?? '',
                'status' => $b['status'],
            ];
        }, $bookings);
        $rows = self::search($rows, $filters['search'] ?? '', ['reg_no', 'patient', 'booking_no', 'test']);
        $byTest = [];
        foreach ($rows as $row) {
            $byTest[$row['test']] ??= ['test' => $row['test'], 'count' => 0, 'charged' => 0.0];
            $byTest[$row['test']]['count']++;
            $byTest[$row['test']]['charged'] = self::money($byTest[$row['test']]['charged'] + $row['charged']);
        }
        return [
            'kpis' => [
                ['label' => 'Tests booked', 'value' => count($rows)],
                ['label' => 'Reported', 'value' => count(array_filter($rows, static fn (array $r) => $r['status'] === 'completed'))],
                ['label' => 'Still to do', 'value' => count(array_filter($rows, static fn (array $r) => in_array($r['status'], ['scheduled', 'arrived'], true)))],
                ['label' => 'Charged', 'value' => self::money(array_sum(array_column($rows, 'charged'))), 'money' => true],
            ],
            'summary' => [
                'title' => 'By test',
                'columns' => [
                    ['key' => 'test', 'label' => 'Test'],
                    ['key' => 'count', 'label' => 'Number'],
                    ['key' => 'charged', 'label' => 'Charged', 'money' => true],
                ],
                'rows' => array_values($byTest),
            ],
            'columns' => [
                ['key' => 'date', 'label' => 'Scheduled'],
                ['key' => 'booking_no', 'label' => 'Booking'],
                ['key' => 'patient', 'label' => 'Patient'],
                ['key' => 'reg_no', 'label' => 'File no.'],
                ['key' => 'test', 'label' => 'Test'],
                ['key' => 'eye', 'label' => 'Eye', 'label_group' => 'eye'],
                ['key' => 'source', 'label' => 'Booked by', 'label_group' => 'diagnosticSource'],
                ['key' => 'referred_by', 'label' => 'Referred by'],
                ['key' => 'reported_by', 'label' => 'Reported by'],
                ['key' => 'charged', 'label' => 'Charged', 'money' => true],
                ['key' => 'bill_status', 'label' => 'Bill', 'label_group' => 'billStatus'],
                ['key' => 'status', 'label' => 'Status', 'label_group' => 'diagnosticStatus'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * The clinic's Surgery report: every theatre case scheduled in the period, with the team,
     * the retrobulbar block, the medicine given in theatre and where the bill stands.
     */
    private static function theatre(string $from, string $to, array $filters): array
    {
        $range = self::utcRange($from, $to);
        $where = ['b.scheduled_for >= :from', 'b.scheduled_for < :to'];
        $params = $range;
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'b.status = :status';
            $params['status'] = $filters['status'];
        }
        $cases = Database::all(
            "SELECT b.id AS booking_id, b.booking_no, b.status, b.total_amount,
                    DATE(CONVERT_TZ(b.scheduled_for, '+00:00', '+03:00')) AS day,
                    si.name AS surgery,
                    p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender,
                    p.date_of_birth_enc, p.address_enc,
                    TRIM(CONCAT_WS(' ', su.first_name, su.last_name)) AS booked_surgeon,
                    o.id AS operation_id, o.retrobulbar_notes_enc, o.prescription_id,
                    pre.retrobulbar_notes_enc AS preop_retrobulbar_enc,
                    bill.status AS bill_status
               FROM theatre_bookings b
               JOIN patients p ON p.id = b.patient_id
               JOIN users su ON su.id = b.surgeon_user_id
               LEFT JOIN price_items si ON si.id = b.surgery_item_id
               LEFT JOIN theatre_operations o ON o.booking_id = b.id
               LEFT JOIN theatre_preop_assessments pre ON pre.booking_id = b.id
               LEFT JOIN bills bill ON bill.id = b.bill_id
              WHERE " . implode(' AND ', $where) . '
              ORDER BY b.scheduled_for DESC',
            $params
        );
        $bookingIds = array_map(static fn ($c) => (int) $c['booking_id'], $cases);
        $procedures = self::byKey(
            'SELECT tp.booking_id, pi.name FROM theatre_booking_procedures tp JOIN price_items pi ON pi.id = tp.price_item_id
              WHERE tp.booking_id IN (:ids) ORDER BY pi.name', $bookingIds, 'booking_id');
        $team = self::byKey(
            "SELECT t.operation_id, t.role, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS name
               FROM theatre_operation_team t JOIN users u ON u.id = t.user_id WHERE t.operation_id IN (:ids)",
            array_values(array_filter(array_map(static fn ($c) => (int) $c['operation_id'], $cases))), 'operation_id');
        $medicines = self::byKey(
            "SELECT i.prescription_id, d.name, i.dose, i.frequency FROM prescription_items i JOIN drugs d ON d.id = i.drug_id
              WHERE i.prescription_id IN (:ids) ORDER BY i.sort_order",
            array_values(array_filter(array_map(static fn ($c) => (int) $c['prescription_id'], $cases))), 'prescription_id');

        $rows = array_map(static function (array $c) use ($procedures, $team, $medicines): array {
            $members = static fn (string $role) => implode(', ', array_column(
                array_filter($team[$c['operation_id']] ?? [], static fn ($t) => $t['role'] === $role), 'name'));
            $retrobulbar = Crypto::decrypt($c['retrobulbar_notes_enc'], 'theatre_operations.retrobulbar_notes')
                ?? Crypto::decrypt($c['preop_retrobulbar_enc'], 'theatre_preop.retrobulbar_notes');
            $day = (string) $c['day'];
            return ['date' => $day] + self::registerPatient($c, $day) + [
                'booking_no' => $c['booking_no'],
                'surgery' => $c['surgery'] ?? 'Procedure only',
                'procedure' => implode(', ', array_filter([$c['surgery'], ...array_column($procedures[$c['booking_id']] ?? [], 'name')])),
                'surgeon' => $members('surgeon') ?: $c['booked_surgeon'],
                'assistant' => $members('assistant'),
                'runner' => $members('runner'),
                'retrobulbar' => self::clip($retrobulbar, 120),
                'medicine' => implode(', ', array_map(static fn ($m) => trim("{$m['name']} " . ($m['dose'] ?? '') . ' ' . ($m['frequency'] ?? '')),
                    $medicines[$c['prescription_id']] ?? [])),
                'payment' => $c['bill_status'] ?? 'no_bill',
                'charged' => self::money($c['total_amount']),
                'status' => $c['status'],
            ];
        }, $cases);
        $rows = self::search($rows, $filters['search'] ?? '', ['reg_no', 'name', 'booking_no', 'procedure', 'surgeon', 'date']);

        $bySurgery = [];
        foreach ($rows as $row) {
            $bySurgery[$row['surgery']] ??= ['surgery' => $row['surgery'], 'count' => 0, 'charged' => 0.0];
            $bySurgery[$row['surgery']]['count']++;
            $bySurgery[$row['surgery']]['charged'] = self::money($bySurgery[$row['surgery']]['charged'] + $row['charged']);
        }
        $operated = array_filter($rows, static fn (array $r) => in_array($r['status'], ['post_op', 'discharged'], true));
        $unpaid = array_filter($rows, static fn (array $r) => in_array($r['payment'], ['pending', 'discount_requested', 'no_bill'], true));
        return [
            'kpis' => [
                ['label' => 'Cases', 'value' => count($rows)],
                ['label' => 'Operated', 'value' => count($operated)],
                ['label' => 'Not yet paid', 'value' => count($unpaid)],
                ['label' => 'Charged', 'value' => self::money(array_sum(array_column($rows, 'charged'))), 'money' => true],
            ],
            'summary' => [
                'title' => 'By operation',
                'columns' => [
                    ['key' => 'surgery', 'label' => 'Operation'],
                    ['key' => 'count', 'label' => 'Number'],
                    ['key' => 'charged', 'label' => 'Charged', 'money' => true],
                ],
                'rows' => array_values($bySurgery),
            ],
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'reg_no', 'label' => 'Reg no'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'age', 'label' => 'Age'],
                ['key' => 'gender', 'label' => 'Sex'],
                ['key' => 'procedure', 'label' => 'Procedure'],
                ['key' => 'surgeon', 'label' => 'Surgeon'],
                ['key' => 'assistant', 'label' => 'Assistant nurse'],
                ['key' => 'runner', 'label' => 'Runner'],
                ['key' => 'retrobulbar', 'label' => 'Retrobulbar'],
                ['key' => 'medicine', 'label' => 'Medicine'],
                ['key' => 'payment', 'label' => 'Payment (status)', 'label_group' => 'billStatus'],
            ],
            'rows' => $rows,
        ];
    }

    private static function optical(string $from, string $to, array $filters): array
    {
        $range = self::utcRange($from, $to);
        $where = ['o.created_at >= :from', 'o.created_at < :to'];
        $params = $range;
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'o.status = :status';
            $params['status'] = $filters['status'];
        }
        $orders = Database::all(
            "SELECT o.order_no, o.status, o.total_amount, o.supplier_order_date, o.lens_received_date, o.dispensed_at,
                    DATE(CONVERT_TZ(o.created_at, '+00:00', '+03:00')) AS day,
                    sup.label AS supplier,
                    p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
                    bill.status AS bill_status, bill.paid_amount,
                    (SELECT GROUP_CONCAT(i.description SEPARATOR ', ') FROM optical_order_items i WHERE i.order_id = o.id) AS lenses
               FROM optical_orders o
               JOIN patients p ON p.id = o.patient_id
               LEFT JOIN lookup_values sup ON sup.id = o.supplier_id
               LEFT JOIN bills bill ON bill.id = o.bill_id
              WHERE " . implode(' AND ', $where) . '
              ORDER BY o.created_at DESC',
            $params
        );
        $rows = array_map(static function (array $o): array {
            $patient = PatientService::brief($o);
            return [
                'date' => $o['day'],
                'order_no' => $o['order_no'],
                'patient' => $patient['full_name'],
                'reg_no' => $patient['reg_no'],
                'lenses' => $o['lenses'],
                'supplier' => $o['supplier'] ?? '',
                'sent_on' => $o['supplier_order_date'] ?? '',
                'received_on' => $o['lens_received_date'] ?? '',
                'handed_over' => $o['dispensed_at'] === null ? '' : substr((string) $o['dispensed_at'], 0, 10),
                'charged' => self::money($o['total_amount']),
                'collected' => self::money($o['paid_amount'] ?? 0),
                'status' => $o['status'],
            ];
        }, $orders);
        $rows = self::search($rows, $filters['search'] ?? '', ['reg_no', 'patient', 'order_no', 'lenses']);
        return [
            'kpis' => [
                ['label' => 'Orders', 'value' => count($rows)],
                ['label' => 'Handed over', 'value' => count(array_filter($rows, static fn (array $r) => $r['status'] === 'dispensed'))],
                ['label' => 'Waiting', 'value' => count(array_filter($rows, static fn (array $r) => !in_array($r['status'], ['dispensed', 'cancelled'], true)))],
                ['label' => 'Collected', 'value' => self::money(array_sum(array_column($rows, 'collected'))), 'money' => true],
            ],
            'columns' => [
                ['key' => 'date', 'label' => 'Ordered'],
                ['key' => 'order_no', 'label' => 'Order'],
                ['key' => 'patient', 'label' => 'Patient'],
                ['key' => 'reg_no', 'label' => 'File no.'],
                ['key' => 'lenses', 'label' => 'Lens'],
                ['key' => 'supplier', 'label' => 'Supplier'],
                ['key' => 'sent_on', 'label' => 'Sent to supplier'],
                ['key' => 'received_on', 'label' => 'Lens received'],
                ['key' => 'handed_over', 'label' => 'Handed over'],
                ['key' => 'charged', 'label' => 'Charged', 'money' => true],
                ['key' => 'collected', 'label' => 'Collected', 'money' => true],
                ['key' => 'status', 'label' => 'Status', 'label_group' => 'opticalStatus'],
            ],
            'rows' => $rows,
        ];
    }

    private static function pharmacy(string $from, string $to, array $filters): array
    {
        $range = self::utcRange($from, $to);
        $where = ['o.created_at >= :from', 'o.created_at < :to'];
        $params = $range;
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'o.status = :status';
            $params['status'] = $filters['status'];
        }
        $orders = Database::all(
            "SELECT o.order_no, o.source, o.status, o.total_amount, o.refill_count, o.created_at,
                    DATE(CONVERT_TZ(o.created_at, '+00:00', '+03:00')) AS day,
                    p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc,
                    w.walkin_no, w.name_enc AS walkin_name_enc,
                    bill.status AS bill_status, bill.paid_amount,
                    (SELECT GROUP_CONCAT(CONCAT(i.description, ' x', i.quantity) SEPARATOR ', ')
                       FROM pharmacy_order_items i WHERE i.order_id = o.id) AS medicines
               FROM pharmacy_orders o
               LEFT JOIN patients p ON p.id = o.patient_id
               LEFT JOIN pharmacy_walkins w ON w.id = o.walkin_id
               LEFT JOIN bills bill ON bill.id = o.bill_id
              WHERE " . implode(' AND ', $where) . '
              ORDER BY o.created_at DESC',
            $params
        );
        $rows = array_map(static function (array $o): array {
            $isWalkIn = $o['source'] === 'walk_in';
            return [
                'date' => $o['day'],
                'order_no' => $o['order_no'],
                'customer' => $isWalkIn
                    ? (string) Crypto::decrypt($o['walkin_name_enc'], 'pharmacy_walkins.name')
                    : PatientService::brief($o)['full_name'],
                'reference' => $isWalkIn ? (string) $o['walkin_no'] : (string) $o['reg_no'],
                'source' => $isWalkIn ? 'Counter sale' : 'Prescription',
                'medicines' => $o['medicines'],
                'refills' => (int) $o['refill_count'],
                'charged' => self::money($o['total_amount']),
                'collected' => self::money($o['paid_amount'] ?? 0),
                'status' => $o['status'],
            ];
        }, $orders);
        $rows = self::search($rows, $filters['search'] ?? '', ['reference', 'customer', 'order_no', 'medicines']);
        return [
            'kpis' => [
                ['label' => 'Orders', 'value' => count($rows)],
                ['label' => 'Counter sales', 'value' => count(array_filter($rows, static fn (array $r) => $r['source'] === 'Counter sale'))],
                ['label' => 'Completed', 'value' => count(array_filter($rows, static fn (array $r) => $r['status'] === 'completed'))],
                ['label' => 'Collected', 'value' => self::money(array_sum(array_column($rows, 'collected'))), 'money' => true],
            ],
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'order_no', 'label' => 'Order'],
                ['key' => 'customer', 'label' => 'Patient or buyer'],
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'source', 'label' => 'From'],
                ['key' => 'medicines', 'label' => 'Medicines'],
                ['key' => 'refills', 'label' => 'Refills'],
                ['key' => 'charged', 'label' => 'Charged', 'money' => true],
                ['key' => 'collected', 'label' => 'Collected', 'money' => true],
                ['key' => 'status', 'label' => 'Status', 'label_group' => 'pharmacyStatus'],
            ],
            'rows' => $rows,
        ];
    }

    private static function stores(string $from, string $to, array $filters): array
    {
        $range = self::utcRange($from, $to);
        $requisitions = Database::all(
            "SELECT r.requisition_no, r.status, r.priority, r.created_at,
                    DATE(CONVERT_TZ(r.created_at, '+00:00', '+03:00')) AS day,
                    d.name AS department,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS raised_by,
                    (SELECT COUNT(*) FROM store_requisition_items i WHERE i.requisition_id = r.id) AS line_count,
                    (SELECT COALESCE(SUM(i.qty_issued * si.cost_price), 0)
                       FROM store_requisition_items i JOIN store_items si ON si.id = i.item_id
                      WHERE i.requisition_id = r.id) AS issued_value
               FROM store_requisitions r
               JOIN departments d ON d.id = r.department_id
               JOIN users u ON u.id = r.created_by
              WHERE r.created_at >= :from AND r.created_at < :to
              ORDER BY r.created_at DESC",
            $range
        );
        $rows = array_map(static fn (array $r) => [
            'date' => $r['day'],
            'requisition_no' => $r['requisition_no'],
            'department' => $r['department'],
            'raised_by' => $r['raised_by'],
            'priority' => $r['priority'],
            'lines' => (int) $r['line_count'],
            'issued_value' => self::money($r['issued_value']),
            'status' => $r['status'],
        ], $requisitions);
        $rows = self::search($rows, $filters['search'] ?? '', ['requisition_no', 'department', 'raised_by']);
        $purchases = Database::all(
            "SELECT COALESCE(SUM(o.total_amount), 0) AS ordered,
                    COUNT(*) AS orders
               FROM store_purchase_orders o
              WHERE o.created_at >= :from AND o.created_at < :to AND o.status <> 'cancelled'",
            $range
        )[0];
        $lowStock = (int) Database::value(
            'SELECT COUNT(*) FROM store_items WHERE is_active = 1 AND quantity <= reorder_level'
        );
        return [
            'kpis' => [
                ['label' => 'Requisitions', 'value' => count($rows)],
                ['label' => 'Value issued', 'value' => self::money(array_sum(array_column($rows, 'issued_value'))), 'money' => true],
                ['label' => 'Purchase orders', 'value' => (int) $purchases['orders']],
                ['label' => 'Ordered from suppliers', 'value' => self::money($purchases['ordered']), 'money' => true],
                ['label' => 'Items at or below reorder level', 'value' => $lowStock],
            ],
            'columns' => [
                ['key' => 'date', 'label' => 'Raised'],
                ['key' => 'requisition_no', 'label' => 'Number'],
                ['key' => 'department', 'label' => 'Department'],
                ['key' => 'raised_by', 'label' => 'Raised by'],
                ['key' => 'priority', 'label' => 'Priority', 'label_group' => 'requisitionPriority'],
                ['key' => 'lines', 'label' => 'Items'],
                ['key' => 'issued_value', 'label' => 'Value issued', 'money' => true],
                ['key' => 'status', 'label' => 'Status', 'label_group' => 'requisitionStatus'],
            ],
            'rows' => $rows,
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    // ─── MTUHA registers ─────────────────────────────────────────────────

    /** Statuses the Main MTUHA outcome filter offers. */
    public const MTUHA_OUTCOMES = ['admission', 'referral', 'follow_up', 'discharged', 'pending', 'cancelled'];

    private const MTUHA_OUTCOME_LABELS = [
        'admission' => 'Admission (surgery)', 'referral' => 'Referral', 'follow_up' => 'Discharged — follow-up',
        'discharged' => 'Discharged', 'pending' => 'In queue / pending', 'cancelled' => 'Cancelled',
    ];

    private static function clip(?string $text, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', (string) $text));
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }

    /** Patient identity as the register shows it: name, place, and age on the day of the entry. */
    private static function registerPatient(array $r, string $day): array
    {
        $patient = PatientService::brief($r);
        $dob = (string) Crypto::decrypt($r['date_of_birth_enc'], 'patients.date_of_birth');
        return [
            'name' => $patient['full_name'],
            'reg_no' => $patient['reg_no'],
            'place' => (string) Crypto::decrypt($r['address_enc'], 'patients.address'),
            'age' => $dob === '' ? '' : Format::ageOn($dob, $day),
            'gender' => ucfirst((string) $r['gender']),
        ];
    }

    /** ✓ under RE and/or LE for a right / left / both eye. */
    private static function eyeTicks(array $eyes): array
    {
        $right = in_array('right', $eyes, true) || in_array('both', $eyes, true);
        $left = in_array('left', $eyes, true) || in_array('both', $eyes, true);
        return ['eye_re' => $right ? '✓' : '', 'eye_le' => $left ? '✓' : ''];
    }

    /** Rows of a query over a list of ids, grouped by one column. */
    private static function byKey(string $sql, array $ids, string $key, array $params = []): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $inParams] = Database::in('k', $ids);
        $grouped = [];
        foreach (Database::all(str_replace(':ids', $in, $sql), $params + $inParams) as $row) {
            $grouped[$row[$key]][] = $row;
        }
        return $grouped;
    }

    /**
     * One row per patient per day, built from that day's visits only (triage, optometry, the
     * doctor, investigations, prescriptions, optical and payments). The outpatient registers
     * (Main MTUHA, Pediatric, Glaucoma) are this, filtered and laid out their own way.
     */
    private static function visitDays(string $from, string $to): array
    {
        $range = self::utcRange($from, $to);
        $visits = Database::all(
            "SELECT v.id, v.status, DATE(CONVERT_TZ(v.created_at, '+00:00', '+03:00')) AS day,
                    p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender,
                    p.date_of_birth_enc, p.address_enc
               FROM visits v JOIN patients p ON p.id = v.patient_id
              WHERE v.created_at >= :from AND v.created_at < :to
              ORDER BY v.created_at",
            $range
        );
        $days = [];
        foreach ($visits as $visit) {
            $key = $visit['patient_id'] . '|' . $visit['day'];
            $days[$key] ??= ['first' => $visit, 'visits' => []];
            $days[$key]['visits'][] = $visit;
        }
        $ids = array_map(static fn (array $v) => (int) $v['id'], $visits);

        $triage = self::byKey('SELECT visit_id, weight_kg, height_m, va_unaided_right, va_unaided_left FROM triage_assessments WHERE visit_id IN (:ids)', $ids, 'visit_id');
        $optometry = self::byKey('SELECT visit_id, va_right, va_left, management_plan_enc FROM optometry_assessments WHERE visit_id IN (:ids)', $ids, 'visit_id');
        $consults = self::byKey('SELECT visit_id, clinical_notes_enc, referred_out, referral_facility_enc FROM ophthalmology_consultations WHERE visit_id IN (:ids)', $ids, 'visit_id');
        $diagnoses = self::byKey(
            "SELECT d.visit_id, d.source, d.kind, c.code, c.description FROM clinical_diagnoses d
               JOIN icd10_codes c ON c.id = d.icd10_code_id WHERE d.visit_id IN (:ids) ORDER BY d.id", $ids, 'visit_id');
        $abnormalEyes = self::byKey(
            'SELECT oc.visit_id, f.eye FROM ophthalmology_findings f
               JOIN exam_findings e ON e.id = f.finding_id AND e.is_normal = 0
               JOIN ophthalmology_consultations oc ON oc.id = f.consultation_id WHERE oc.visit_id IN (:ids)', $ids, 'visit_id');
        $surgery = self::byKey(
            "SELECT s.visit_id, i.name, s.urgency FROM surgery_recommendations s JOIN price_items i ON i.id = s.price_item_id
              WHERE s.status IN ('recommended', 'booked') AND s.visit_id IN (:ids)", $ids, 'visit_id');
        $medicines = self::byKey(
            "SELECT p.visit_id, COALESCE(d.name, CONCAT(i.outside_name, ' (buy outside)')) AS name, i.dose, i.frequency FROM prescriptions p
               JOIN prescription_items i ON i.prescription_id = p.id LEFT JOIN drugs d ON d.id = i.drug_id
              WHERE p.status = 'issued' AND p.visit_id IN (:ids) ORDER BY i.sort_order", $ids, 'visit_id');
        $followUps = self::byKey("SELECT source_visit_id FROM follow_ups WHERE status IN ('due', 'booked') AND source_visit_id IN (:ids)", $ids, 'source_visit_id');
        $requested = self::byKey(
            "SELECT r.visit_id, i.name FROM investigation_requests r JOIN price_items i ON i.id = r.price_item_id
              WHERE r.status IN ('awaiting_payment', 'paid') AND r.visit_id IN (:ids)", $ids, 'visit_id');
        $tests = self::byKey(
            "SELECT COALESCE(b.visit_id, r.visit_id) AS visit_id, b.request_id, b.status, i.name, res.result_enc
               FROM diagnostic_bookings b
               JOIN price_items i ON i.id = b.price_item_id
               LEFT JOIN investigation_requests r ON r.id = b.request_id
               LEFT JOIN diagnostic_results res ON res.booking_id = b.id
              WHERE b.status <> 'cancelled' AND COALESCE(b.visit_id, r.visit_id) IN (:ids)", $ids, 'visit_id');
        $bills = self::byKey(
            "SELECT b.visit_id, b.status, b.discount_amount, b.discount_type, pay.method FROM bills b
               LEFT JOIN payments pay ON pay.bill_id = b.id
              WHERE b.status NOT IN ('cancelled') AND b.visit_id IN (:ids)", $ids, 'visit_id');
        $opticalOrders = self::byKey(
            "SELECT o.visit_id, o.status, GROUP_CONCAT(i.description ORDER BY i.id SEPARATOR ', ') AS lenses
               FROM optical_orders o JOIN optical_order_items i ON i.order_id = o.id
              WHERE o.status <> 'cancelled' AND o.visit_id IN (:ids) GROUP BY o.id", $ids, 'visit_id');
        $lensPrescribed = self::byKey(
            "SELECT a.visit_id, CONCAT(COALESCE(CONCAT(r.label, ': '), ''), pi.name) AS name FROM optometry_assessments a
               JOIN optometry_lens_items l ON l.assessment_id = a.id JOIN price_items pi ON pi.id = l.price_item_id
               LEFT JOIN optometry_refractions r ON r.assessment_id = a.id AND r.kind = 'final' AND r.set_no = l.set_no
              WHERE a.refer_optical = 1 AND a.visit_id IN (:ids) ORDER BY l.set_no", $ids, 'visit_id');
        // Tests and procedures actually done, by patient and day: desk bookings carry no visit.
        $done = [];
        foreach (Database::all(
            "SELECT b.patient_id, DATE(CONVERT_TZ(b.completed_at, '+00:00', '+03:00')) AS day, i.name, lt.label AS laser
               FROM diagnostic_bookings b JOIN price_items i ON i.id = b.price_item_id
               LEFT JOIN lookup_values lt ON lt.id = b.laser_type_id
              WHERE b.status = 'completed' AND b.completed_at >= :from AND b.completed_at < :to",
            $range
        ) as $test) {
            $done[$test['patient_id'] . '|' . $test['day']][] = $test['name'] . ($test['laser'] ? " ({$test['laser']})" : '');
        }

        $rows = [];
        foreach ($days as $group) {
            $first = $group['first'];
            $day = (string) $first['day'];
            $take = static function (array $source) use ($group): array {
                $out = [];
                foreach ($group['visits'] as $v) {
                    array_push($out, ...($source[$v['id']] ?? []));
                }
                return $out;
            };
            $firstValue = static fn (array $records, string $field) => array_values(array_filter(array_column($records, $field), static fn ($x) => $x !== null && $x !== ''))[0] ?? null;

            $tri = $take($triage);
            $opt = $take($optometry);
            $con = $take($consults);
            $vaRight = $firstValue($opt, 'va_right') ?? $firstValue($tri, 'va_unaided_right');
            $vaLeft = $firstValue($opt, 'va_left') ?? $firstValue($tri, 'va_unaided_left');

            // The doctor's final diagnosis, else the provisional one, else what optometry recorded.
            $dx = $take($diagnoses);
            $pick = static fn (string $source, string $kind) => array_values(array_filter($dx, static fn ($d) => $d['source'] === $source && $d['kind'] === $kind));
            $chosen = $pick('ophthalmology', 'final') ?: $pick('ophthalmology', 'provisional') ?: $pick('optometry', 'final') ?: $pick('optometry', 'provisional');
            $diagnosis = implode('; ', array_unique(array_map(static fn ($d) => "{$d['code']} {$d['description']}", $chosen)));

            $treatments = [];
            foreach ($take($surgery) as $s) {
                $treatments[] = "Surgery — {$s['name']}" . ($s['urgency'] !== 'elective' ? " ({$s['urgency']})" : '');
            }
            $meds = array_map(static fn ($m) => trim("{$m['name']} " . ($m['dose'] ?? '') . ' ' . ($m['frequency'] ?? '')), $take($medicines));
            if ($meds) {
                $treatments[] = implode(', ', $meds);
            }
            if (!$treatments && $opt) {
                $plan = Crypto::decrypt($firstValue($opt, 'management_plan_enc'), 'optometry_assessments.management_plan');
                if ($plan) {
                    $treatments[] = self::clip($plan, 120);
                }
            }

            $testRows = $take($tests);
            $ordered = array_merge(array_column($take($requested), 'name'),
                array_column(array_filter($testRows, static fn ($t) => $t['request_id'] === null), 'name'));
            $results = array_map(static function (array $t): string {
                $result = Crypto::decrypt($t['result_enc'], 'diagnostic_results.result');
                return $t['name'] . ': ' . ($result ? self::clip($result, 70) : ($t['status'] === 'completed' ? 'done' : 'not yet done'));
            }, $testRows);

            // Outcome, most decisive first: surgery, referral, follow-up, then where the visit ended.
            $referral = array_values(array_filter($con, static fn ($c) => (int) $c['referred_out'] === 1))[0] ?? null;
            $statuses = array_column($group['visits'], 'status');
            $outcome = match (true) {
                (bool) $take($surgery) => 'admission',
                $referral !== null => 'referral',
                (bool) $take($followUps) => 'follow_up',
                !array_diff($statuses, ['cancelled']) => 'cancelled',
                !array_diff($statuses, ['completed', 'cancelled']) => 'discharged',
                default => 'pending',
            };
            $outcomeText = self::MTUHA_OUTCOME_LABELS[$outcome];
            if ($referral !== null) {
                $outcomeText .= ' — ' . Crypto::decrypt($referral['referral_facility_enc'], 'ophthalmology_consultations.referral_facility');
            }

            $billRows = $take($bills);
            $payment = [];
            foreach ($billRows as $b) {
                if ($b['method'] === 'cash') {
                    $payment['Cash'] = true;
                } elseif ($b['method'] === 'lipa_number') {
                    $payment['Mobile (Lipa)'] = true;
                }
                if ((float) $b['discount_amount'] > 0 || $b['status'] === 'exempted') {
                    $payment['Discount' . ($b['discount_type'] ? " ({$b['discount_type']})" : '')] = true;
                }
                if (in_array($b['status'], ['pending', 'discount_requested'], true)) {
                    $payment['Not paid'] = true;
                }
            }

            $optical = array_map(static fn ($o) => "{$o['lenses']} — " . str_replace('_', ' ', $o['status']), $take($opticalOrders));
            if (!$optical && ($lenses = array_column($take($lensPrescribed), 'name'))) {
                $optical[] = implode(', ', $lenses) . ' — prescribed, not ordered';
            }

            $notes = Crypto::decrypt($firstValue($con, 'clinical_notes_enc'), 'ophthalmology_consultations.clinical_notes');
            $rows[] = ['date' => $day] + self::registerPatient($first, $day) + [
                'weight' => $firstValue($tri, 'weight_kg') ?? '',
                'height' => $firstValue($tri, 'height_m') ?? '',
                'va_re' => $vaRight ?? '',
                'va_le' => $vaLeft ?? '',
                'tests_ordered' => implode(', ', array_unique($ordered)),
                'test_results' => implode(' | ', $results),
                'diagnosis' => $diagnosis,
            ] + self::eyeTicks(array_column($take($abnormalEyes), 'eye')) + [
                'treatments' => implode(' | ', $treatments),
                'outcome_code' => $outcome,
                'outcome' => $outcomeText,
                'payment' => implode(' + ', array_keys($payment)),
                'suggestion' => self::clip($notes, 120),
                'medicine' => implode(', ', $meds),
                'procedures_done' => implode(', ', $done[$first['patient_id'] . '|' . $day] ?? []),
                'optical' => implode(' | ', $optical),
                'codes' => array_values(array_unique(array_column($dx, 'code'))),
            ];
        }
        return $rows;
    }

    /** The outpatient register: one row per patient per day. */
    private static function mtuhaMain(string $from, string $to, array $filters): array
    {
        $rows = self::visitDays($from, $to);

        usort($rows, static fn (array $a, array $b) => strcmp($b['date'], $a['date']) ?: strcmp($a['name'], $b['name']));
        $rows = self::search($rows, $filters['search'] ?? '', ['name', 'reg_no', 'diagnosis', 'place', 'date']);
        if (in_array($filters['status'] ?? '', self::MTUHA_OUTCOMES, true)) {
            $rows = array_values(array_filter($rows, static fn (array $r) => $r['outcome_code'] === $filters['status']));
        }
        $count = static fn (string ...$codes) => count(array_filter($rows, static fn (array $r) => in_array($r['outcome_code'], $codes, true)));
        return [
            'kpis' => [
                ['label' => 'Patients', 'value' => count($rows)],
                ['label' => 'Admissions (surgery)', 'value' => $count('admission')],
                ['label' => 'Referrals', 'value' => $count('referral')],
                ['label' => 'Discharged / follow-up', 'value' => $count('discharged', 'follow_up')],
                ['label' => 'In queue / pending', 'value' => $count('pending')],
            ],
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'reg_no', 'label' => 'Reg no'],
                ['key' => 'name', 'label' => 'Patient name'],
                ['key' => 'place', 'label' => 'Place'],
                ['key' => 'age', 'label' => 'Age'],
                ['key' => 'gender', 'label' => 'Gender'],
                ['key' => 'weight', 'label' => 'Weight (kg)'],
                ['key' => 'height', 'label' => 'Height (m)'],
                ['key' => 'va_re', 'label' => 'RE', 'group' => 'Visual acuity'],
                ['key' => 'va_le', 'label' => 'LE', 'group' => 'Visual acuity'],
                ['key' => 'tests_ordered', 'label' => 'Investigation ordered'],
                ['key' => 'test_results', 'label' => 'Investigation results'],
                ['key' => 'diagnosis', 'label' => 'Diagnosis'],
                ['key' => 'eye_re', 'label' => 'RE', 'group' => 'Eye'],
                ['key' => 'eye_le', 'label' => 'LE', 'group' => 'Eye'],
                ['key' => 'treatments', 'label' => 'Treatments'],
                ['key' => 'optical', 'label' => 'Optical'],
                ['key' => 'outcome', 'label' => 'Outcome'],
                ['key' => 'payment', 'label' => 'Payment'],
                ['key' => 'suggestion', 'label' => 'Suggestion'],
            ],
            'rows' => $rows,
        ];
    }

    /** Diagnosis codes that make a visit a glaucoma visit: H40, H42, and congenital glaucoma Q15.0. */
    private static function isGlaucoma(array $codes): bool
    {
        foreach ($codes as $code) {
            if (preg_match('/^(H40|H42|Q15\.0)/', (string) $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The clinic's Pediatric (aged 14 and under on the day) and Glaucoma registers: one row
     * per patient per day, in the clinic's own columns.
     */
    private static function clinicRegister(string $kind, string $from, string $to, array $filters): array
    {
        $rows = array_values(array_filter(self::visitDays($from, $to), static fn (array $r) => $kind === 'pediatric'
            ? $r['age'] !== '' && $r['age'] <= 14
            : self::isGlaucoma($r['codes'])));
        usort($rows, static fn (array $a, array $b) => strcmp($b['date'], $a['date']) ?: strcmp($a['name'], $b['name']));
        $rows = self::search($rows, $filters['search'] ?? '', ['name', 'reg_no', 'diagnosis', 'date']);
        return [
            'kpis' => [
                ['label' => 'Visits', 'value' => count($rows)],
                ['label' => 'Patients', 'value' => count(array_unique(array_column($rows, 'reg_no')))],
                ['label' => 'Given medicine', 'value' => count(array_filter($rows, static fn ($r) => $r['medicine'] !== ''))],
                ['label' => 'Optical', 'value' => count(array_filter($rows, static fn ($r) => $r['optical'] !== ''))],
            ],
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'reg_no', 'label' => 'Reg no'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'age', 'label' => 'Age'],
                ['key' => 'gender', 'label' => 'Sex'],
                ['key' => 'diagnosis', 'label' => 'Diagnosis'],
                ['key' => 'medicine', 'label' => 'Medicine'],
                ['key' => 'procedures_done', 'label' => 'Procedure'],
                ['key' => 'optical', 'label' => 'Optical'],
                ['key' => 'payment', 'label' => 'Payment'],
            ],
            'rows' => $rows,
        ];
    }

    /** The theatre register: one row per operation performed, dated by the day of surgery. */
    private static function mtuhaSurgery(string $from, string $to, array $filters): array
    {
        $range = self::utcRange($from, $to);
        $operations = Database::all(
            "SELECT o.id AS operation_id, o.booking_id, o.completed_as_planned, o.actual_procedure_enc, o.complications_enc, o.remarks_enc,
                    DATE(CONVERT_TZ(COALESCE(o.started_at, b.scheduled_for), '+00:00', '+03:00')) AS day,
                    b.eye, b.discharge_remarks_enc, si.name AS surgery,
                    TRIM(CONCAT_WS(' ', su.first_name, su.last_name)) AS booked_surgeon,
                    p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender,
                    p.date_of_birth_enc, p.address_enc
               FROM theatre_operations o
               JOIN theatre_bookings b ON b.id = o.booking_id
               JOIN patients p ON p.id = b.patient_id
               JOIN users su ON su.id = b.surgeon_user_id
               LEFT JOIN price_items si ON si.id = b.surgery_item_id
              WHERE b.status <> 'cancelled' AND (o.started_at IS NOT NULL OR o.status = 'completed')
                AND COALESCE(o.started_at, b.scheduled_for) >= :from AND COALESCE(o.started_at, b.scheduled_for) < :to
              ORDER BY COALESCE(o.started_at, b.scheduled_for) DESC",
            $range
        );
        $team = self::byKey(
            "SELECT t.operation_id, t.role, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS name
               FROM theatre_operation_team t JOIN users u ON u.id = t.user_id WHERE t.operation_id IN (:ids)",
            array_map(static fn ($o) => (int) $o['operation_id'], $operations), 'operation_id');
        $postOp = self::byKey(
            "SELECT booking_id, complications_enc, recovery_notes_enc FROM theatre_postop_visits
              WHERE status = 'completed' AND booking_id IN (:ids) ORDER BY visit_number DESC",
            array_map(static fn ($o) => (int) $o['booking_id'], $operations), 'booking_id');

        $rows = [];
        foreach ($operations as $o) {
            $members = static fn (string $role) => implode(', ', array_column(
                array_filter($team[$o['operation_id']] ?? [], static fn ($t) => $t['role'] === $role), 'name'));
            $actual = Crypto::decrypt($o['actual_procedure_enc'], 'theatre_operations.actual_procedure');
            $notes = [];
            if ($text = Crypto::decrypt($o['complications_enc'], 'theatre_operations.complications')) {
                $notes[] = 'Intra-op: ' . self::clip($text, 80);
            }
            if ($text = Crypto::decrypt($o['remarks_enc'], 'theatre_operations.remarks')) {
                $notes[] = self::clip($text, 80);
            }
            $latest = ($postOp[$o['booking_id']] ?? [])[0] ?? null;
            if ($latest && ($text = Crypto::decrypt($latest['complications_enc'], 'theatre_postop.complications'))) {
                $notes[] = 'Post-op: ' . self::clip($text, 80);
            }
            if ($latest && ($text = Crypto::decrypt($latest['recovery_notes_enc'], 'theatre_postop.recovery_notes'))) {
                $notes[] = self::clip($text, 80);
            }
            if ($text = Crypto::decrypt($o['discharge_remarks_enc'], 'theatre_bookings.discharge_remarks')) {
                $notes[] = 'Discharge: ' . self::clip($text, 80);
            }
            $day = (string) $o['day'];
            $rows[] = ['date' => $day, 'year' => substr($day, 0, 4)] + self::registerPatient($o, $day) + [
                'surgery' => $actual ?: ($o['surgery'] ?? 'Procedure only'),
            ] + self::eyeTicks([$o['eye']]) + [
                'surgeon' => $members('surgeon') ?: $o['booked_surgeon'],
                'scrub_nurse' => $members('scrub_nurse'),
                'anaesthesia_nurse' => $members('anaesthesia_nurse'),
                'results' => implode(' | ', $notes) ?: ($o['completed_as_planned'] === null ? '' : ((int) $o['completed_as_planned'] ? 'Completed as planned' : 'Not as planned')),
                'complicated' => $notes !== [] && str_contains(implode(' ', $notes), '-op:'),
            ];
        }
        $rows = self::search($rows, $filters['search'] ?? '', ['name', 'reg_no', 'surgery', 'place', 'surgeon']);

        $bySurgery = [];
        foreach ($rows as $row) {
            $bySurgery[$row['surgery']] = ($bySurgery[$row['surgery']] ?? 0) + 1;
        }
        arsort($bySurgery);
        $top = array_key_first($bySurgery);
        return [
            'kpis' => [
                ['label' => 'Operations', 'value' => count($rows)],
                ['label' => 'With complications', 'value' => count(array_filter($rows, static fn ($r) => $r['complicated']))],
                ['label' => 'Most common', 'value' => $top === null ? '—' : "$top ({$bySurgery[$top]})"],
            ],
            'summary' => [
                'title' => 'By operation',
                'columns' => [['key' => 'surgery', 'label' => 'Operation'], ['key' => 'count', 'label' => 'Number']],
                'rows' => array_map(static fn ($name, $count) => ['surgery' => $name, 'count' => $count], array_keys($bySurgery), $bySurgery),
            ],
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'year', 'label' => 'Year'],
                ['key' => 'name', 'label' => 'Patient name'],
                ['key' => 'place', 'label' => 'Place'],
                ['key' => 'age', 'label' => 'Age'],
                ['key' => 'gender', 'label' => 'Gender'],
                ['key' => 'surgery', 'label' => 'Surgery type'],
                ['key' => 'eye_re', 'label' => 'RE', 'group' => 'Eye'],
                ['key' => 'eye_le', 'label' => 'LE', 'group' => 'Eye'],
                ['key' => 'surgeon', 'label' => 'Surgeon doctor'],
                ['key' => 'scrub_nurse', 'label' => 'Scrub nurse'],
                ['key' => 'anaesthesia_nurse', 'label' => 'Anaesthesia nurse'],
                ['key' => 'results', 'label' => 'Results and remarks'],
            ],
            'rows' => $rows,
        ];
    }

    private static function search(array $rows, string $search, array $fields): array
    {
        $search = trim($search);
        if ($search === '') {
            return $rows;
        }
        $needle = mb_strtolower($search);
        return array_values(array_filter($rows, static function (array $row) use ($needle, $fields): bool {
            foreach ($fields as $field) {
                if (str_contains(mb_strtolower((string) ($row[$field] ?? '')), $needle)) {
                    return true;
                }
            }
            return false;
        }));
    }

    /** The report as CSV, summary panel included — the prototype's export dropped it. */
    public static function csv(array $report): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, [$report['title']]);
        fputcsv($out, ['Period', $report['from'] . ' to ' . $report['to']]);
        fputcsv($out, ['Produced', $report['generated_at']]);
        fputcsv($out, []);
        foreach ($report['kpis'] as $kpi) {
            fputcsv($out, [$kpi['label'], $kpi['value']]);
        }
        fputcsv($out, []);
        if (isset($report['summary'])) {
            fputcsv($out, [$report['summary']['title']]);
            fputcsv($out, array_column($report['summary']['columns'], 'label'));
            foreach ($report['summary']['rows'] as $row) {
                fputcsv($out, array_map(static fn (array $c) => $row[$c['key']] ?? '', $report['summary']['columns']));
            }
            fputcsv($out, []);
        }
        // A grouped column (Visual acuity → RE / LE) is written as "Visual acuity RE".
        fputcsv($out, array_map(static fn (array $c) => trim(($c['group'] ?? '') . ' ' . $c['label']), $report['columns']));
        foreach ($report['rows'] as $row) {
            fputcsv($out, array_map(static fn (array $c) => $row[$c['key']] ?? '', $report['columns']));
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }
}
