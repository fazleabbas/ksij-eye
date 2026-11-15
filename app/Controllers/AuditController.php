<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Http\Request;

/** Read-only views of the audit trail and the patient-record access log. */
final class AuditController
{
    public function index(Request $request): array
    {
        $where = [];
        $params = [];
        if (($search = $request->query('search')) !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(a.summary LIKE :q1 OR a.action LIKE :q2 OR a.username LIKE :q3 OR a.user_display LIKE :q4 OR a.entity_id LIKE :q5)';
            for ($i = 1; $i <= 5; $i++) {
                $params["q$i"] = $like;
            }
        }
        if (($module = $request->query('module')) !== '') {
            $where[] = 'a.module = :module';
            $params['module'] = $module;
        }
        if (($userId = (int) $request->query('user_id', '0')) > 0) {
            $where[] = 'a.user_id = :uid';
            $params['uid'] = $userId;
        }
        $this->dateRange($request, 'a.occurred_at', $where, $params);

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $perPage = $request->perPage(50, 200);
        $page = $request->page();
        $offset = ($page - 1) * $perPage;
        $total = (int) Database::value("SELECT COUNT(*) FROM audit_log a$sqlWhere", $params);
        $rows = Database::all(
            "SELECT a.id, a.occurred_at, a.user_id, a.username, a.user_display, a.ip_address, a.module, a.action,
                    a.entity_type, a.entity_id, a.summary, a.changes, a.request_id
               FROM audit_log a$sqlWhere ORDER BY a.id DESC LIMIT $perPage OFFSET $offset",
            $params
        );

        return [
            'entries' => array_map(static fn (array $r) => [
                'id' => (int) $r['id'],
                'occurred_at' => Format::utc($r['occurred_at']),
                'user_id' => $r['user_id'] === null ? null : (int) $r['user_id'],
                'username' => $r['username'],
                'user_display' => $r['user_display'],
                'ip_address' => $r['ip_address'],
                'module' => $r['module'],
                'action' => $r['action'],
                'entity_type' => $r['entity_type'],
                'entity_id' => $r['entity_id'],
                'summary' => $r['summary'],
                'changes' => $r['changes'] ? json_decode($r['changes'], true) : null,
                'request_id' => $r['request_id'],
            ], $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'stats' => [
                'total' => (int) Database::value('SELECT COUNT(*) FROM audit_log'),
                'today' => (int) Database::value('SELECT COUNT(*) FROM audit_log WHERE occurred_at >= :t', ['t' => Format::localTodayUtcStart()]),
                'users' => (int) Database::value('SELECT COUNT(DISTINCT user_id) FROM audit_log WHERE user_id IS NOT NULL'),
            ],
        ];
    }

    public function filters(): array
    {
        return [
            'modules' => array_column(Database::all('SELECT DISTINCT module FROM audit_log ORDER BY module'), 'module'),
            'users' => array_map(static fn (array $r) => ['id' => (int) $r['user_id'], 'name' => $r['name']], Database::all(
                'SELECT user_id, MAX(user_display) AS name FROM audit_log WHERE user_id IS NOT NULL GROUP BY user_id ORDER BY name'
            )),
        ];
    }

    public function accessLog(Request $request): array
    {
        $where = [];
        $params = [];
        if (($patientId = (int) $request->query('patient_id', '0')) > 0) {
            $where[] = 'l.patient_id = :pid';
            $params['pid'] = $patientId;
        }
        if (($userId = (int) $request->query('user_id', '0')) > 0) {
            $where[] = 'l.user_id = :uid';
            $params['uid'] = $userId;
        }
        $this->dateRange($request, 'l.occurred_at', $where, $params);
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $perPage = $request->perPage(50, 200);
        $offset = ($request->page() - 1) * $perPage;
        $rows = Database::all(
            "SELECT l.id, l.occurred_at, l.user_id, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS user_name, l.patient_id,
                    l.module, l.action, l.entity_type, l.entity_id, l.ip_address
               FROM record_access_log l JOIN users u ON u.id = l.user_id$sqlWhere
              ORDER BY l.id DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        return [
            'entries' => array_map(static fn (array $r) => [
                'id' => (int) $r['id'], 'occurred_at' => Format::utc($r['occurred_at']), 'user_id' => (int) $r['user_id'],
                'user_name' => $r['user_name'], 'patient_id' => (int) $r['patient_id'], 'module' => $r['module'],
                'action' => $r['action'], 'entity_type' => $r['entity_type'], 'entity_id' => $r['entity_id'], 'ip_address' => $r['ip_address'],
            ], $rows),
            'total' => (int) Database::value("SELECT COUNT(*) FROM record_access_log l$sqlWhere", $params),
        ];
    }

    private function dateRange(Request $request, string $column, array &$where, array &$params): void
    {
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            $value = $request->query($key);
            if ($value === '') {
                continue;
            }
            if (!Format::isDate($value)) {
                throw HttpException::validation([$key => 'Use a valid date.']);
            }
            $where[] = "$column $operator :$key";
            $params[$key] = Format::localDateToUtc($value, $key === 'to');
        }
    }
}
