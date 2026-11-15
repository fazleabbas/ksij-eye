<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Sequence;
use App\Core\Validator;

/** Staff accounts: registration, profile, roles and designations, status, password resets, sessions. */
final class UserController
{
    private const SELECT = 'SELECT u.id, u.staff_no, u.username, u.first_name, u.middle_name, u.last_name, u.phone, u.email,
                u.department_id, d.name AS department, u.status, u.must_change_password,
                (u.locked_until IS NOT NULL AND u.locked_until > UTC_TIMESTAMP(3)) AS is_locked,
                u.last_login_at, u.created_at, u.row_version
           FROM users u
           LEFT JOIN departments d ON d.id = u.department_id';

    public function index(Request $request): array
    {
        $where = [];
        $params = [];
        $search = $request->query('search');
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(u.first_name LIKE :q1 OR u.middle_name LIKE :q2 OR u.last_name LIKE :q3
                         OR u.username LIKE :q4 OR u.phone LIKE :q5 OR u.staff_no LIKE :q6)';
            for ($i = 1; $i <= 6; $i++) {
                $params["q$i"] = $like;
            }
        }
        if (($departmentId = (int) $request->query('department_id', '0')) > 0) {
            $where[] = 'u.department_id = :dept';
            $params['dept'] = $departmentId;
        }
        if (($roleId = (int) $request->query('role_id', '0')) > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = :role)';
            $params['role'] = $roleId;
        }
        $status = $request->query('status');
        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }

        $sql = self::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY u.first_name, u.last_name LIMIT 1000';
        return ['users' => $this->present(Database::all($sql, $params))];
    }

    public function show(Request $request, array $params): array
    {
        return ['user' => $this->find($params['id']) ?? throw HttpException::notFound('User not found.')];
    }

    public function store(Request $request): array
    {
        $ctx = Auth::require();
        $v = $this->validateUser($request->all(), creating: true);
        Auth::assertPasswordPolicy($v['password'], $v['username']);
        $this->assertReferences($v);
        if (Database::value('SELECT 1 FROM users WHERE username = :u', ['u' => $v['username']])) {
            throw HttpException::validation(['username' => 'This username is already taken.']);
        }

        try {
            $id = Database::transaction(function () use ($v, $ctx): int {
                $id = Database::insert(
                    'INSERT INTO users (staff_no, username, password_hash, first_name, middle_name, last_name, phone, email,
                                        department_id, status, must_change_password, password_changed_at, created_at, created_by)
                     VALUES (:staff, :username, :hash, :first, :middle, :last, :phone, :email,
                             :dept, :status, 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), :by)',
                    [
                        'staff' => sprintf('USR-%04d', Sequence::next('user')),
                        'username' => $v['username'], 'hash' => Auth::hashPassword($v['password']),
                        'first' => $v['first_name'], 'middle' => $v['middle_name'], 'last' => $v['last_name'],
                        'phone' => $v['phone'], 'email' => $v['email'], 'dept' => $v['department_id'],
                        'status' => $v['status'], 'by' => $ctx->userId,
                    ]
                );
                $this->syncLinks($id, $v['role_ids'], $v['designation_ids'], $ctx->userId);
                return $id;
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw HttpException::validation(['username' => 'This username is already taken.']);
            }
            throw $e;
        }

        $user = $this->find($id);
        Audit::log('users', 'user.created', "Registered staff account {$user['username']} ({$user['staff_no']})", 'user', $id, [
            'roles' => ['to' => array_column($user['roles'], 'name')],
            'designations' => ['to' => array_column($user['designations'], 'name')],
            'status' => ['to' => $user['status']],
        ]);
        return ['user' => $user];
    }

    public function update(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $before = $this->find($params['id']) ?? throw HttpException::notFound('User not found.');
        $v = $this->validateUser($request->all(), creating: false);
        $this->assertReferences($v);

        $oldRoleIds = array_column($before['roles'], 'id');
        sort($oldRoleIds);
        $rolesChanged = $v['role_ids'] !== $oldRoleIds;
        if ($before['id'] === $ctx->userId && ($rolesChanged || $v['status'] !== $before['status'])) {
            throw HttpException::conflict('self_change_forbidden', 'You cannot change your own roles or account status. Ask another administrator.');
        }
        $this->assertAdminRemains($before['id'], $v['role_ids'], $v['status']);

        Database::transaction(function () use ($v, $before, $ctx): void {
            $updated = Database::execute(
                'UPDATE users SET first_name = :first, middle_name = :middle, last_name = :last, phone = :phone, email = :email,
                        department_id = :dept, status = :status, updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                        row_version = row_version + 1
                  WHERE id = :id AND row_version = :version',
                [
                    'first' => $v['first_name'], 'middle' => $v['middle_name'], 'last' => $v['last_name'],
                    'phone' => $v['phone'], 'email' => $v['email'], 'dept' => $v['department_id'], 'status' => $v['status'],
                    'by' => $ctx->userId, 'id' => $before['id'], 'version' => $v['row_version'],
                ]
            );
            if ($updated === 0) {
                throw HttpException::conflict('stale_record', 'This account was changed by someone else. Reload and try again.');
            }
            $this->syncLinks($before['id'], $v['role_ids'], $v['designation_ids'], $ctx->userId);
        });

        if ($before['status'] === 'active' && $v['status'] === 'inactive') {
            Auth::revokeUserSessions($before['id'], null, 'user_deactivated');
        }
        $after = $this->find($before['id']);
        $changes = Audit::diff($this->snapshot($before), $this->snapshot($after));
        Audit::log('users', 'user.updated', "Updated staff account {$after['username']}", 'user', $after['id'], $changes ?: null);
        return ['user' => $after];
    }

    public function setStatus(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $user = $this->find($params['id']) ?? throw HttpException::notFound('User not found.');
        $v = Validator::make($request->all())->enum('status', ['active', 'inactive'])->validate();
        if ($user['id'] === $ctx->userId) {
            throw HttpException::conflict('self_change_forbidden', 'You cannot change your own account status.');
        }
        if ($v['status'] === $user['status']) {
            return ['user' => $user];
        }
        $this->assertAdminRemains($user['id'], array_column($user['roles'], 'id'), $v['status']);

        Database::execute(
            'UPDATE users SET status = :status, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id',
            ['status' => $v['status'], 'by' => $ctx->userId, 'id' => $user['id']]
        );
        if ($v['status'] === 'inactive') {
            Auth::revokeUserSessions($user['id'], null, 'user_deactivated');
        }
        Audit::log('users', $v['status'] === 'active' ? 'user.activated' : 'user.deactivated',
            ($v['status'] === 'active' ? 'Activated' : 'Deactivated') . " staff account {$user['username']}",
            'user', $user['id'], ['status' => ['from' => $user['status'], 'to' => $v['status']]]);
        return ['user' => $this->find($user['id'])];
    }

    public function resetPassword(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $user = $this->find($params['id']) ?? throw HttpException::notFound('User not found.');
        if ($user['id'] === $ctx->userId) {
            throw HttpException::conflict('self_change_forbidden', 'Use "Change password" to change your own password.');
        }
        $v = Validator::make($request->all())->string('password', true, 128, trim: false)->validate();
        Auth::assertPasswordPolicy($v['password'], $user['username']);

        Database::execute(
            'UPDATE users SET password_hash = :h, must_change_password = 1, password_changed_at = UTC_TIMESTAMP(3),
                    failed_login_count = 0, locked_until = NULL, updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                    row_version = row_version + 1
              WHERE id = :id',
            ['h' => Auth::hashPassword($v['password']), 'by' => $ctx->userId, 'id' => $user['id']]
        );
        $revoked = Auth::revokeUserSessions($user['id'], null, 'password_reset');
        Audit::log('users', 'user.password_reset', "Set a temporary password for {$user['username']}" . ($revoked ? " and signed out $revoked session(s)" : ''), 'user', $user['id']);
        return ['user' => $this->find($user['id'])];
    }

    public function unlock(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $user = $this->find($params['id']) ?? throw HttpException::notFound('User not found.');
        Database::execute(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL, updated_at = UTC_TIMESTAMP(3), updated_by = :by WHERE id = :id',
            ['by' => $ctx->userId, 'id' => $user['id']]
        );
        Audit::log('users', 'user.unlocked', "Unlocked staff account {$user['username']}", 'user', $user['id']);
        return ['user' => $this->find($user['id'])];
    }

    public function sessions(Request $request, array $params): array
    {
        $user = $this->find($params['id']) ?? throw HttpException::notFound('User not found.');
        $rows = Database::all(
            'SELECT id, ip_address, user_agent, created_at, last_activity_at
               FROM user_sessions
              WHERE user_id = :u AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(3)
                AND last_activity_at > UTC_TIMESTAMP(3) - INTERVAL :idle SECOND
              ORDER BY last_activity_at DESC',
            ['u' => $user['id'], 'idle' => Auth::idleSeconds()]
        );
        $current = Auth::require()->sessionId;
        return ['sessions' => array_map(static fn (array $s) => [
            'id' => (int) $s['id'],
            'ip_address' => $s['ip_address'],
            'user_agent' => $s['user_agent'],
            'created_at' => Format::utc($s['created_at']),
            'last_activity_at' => Format::utc($s['last_activity_at']),
            'is_current' => (int) $s['id'] === $current,
        ], $rows)];
    }

    public function revokeSessions(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $user = $this->find($params['id']) ?? throw HttpException::notFound('User not found.');
        $except = $user['id'] === $ctx->userId ? $ctx->sessionId : null;
        $count = Auth::revokeUserSessions($user['id'], $except, 'revoked_by_admin');
        Audit::log('users', 'sessions.revoked', "Signed out $count session(s) of {$user['username']}", 'user', $user['id']);
        return ['revoked' => $count];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function validateUser(array $input, bool $creating): array
    {
        $v = Validator::make($input)
            ->string('first_name', true, 80)
            ->string('middle_name', false, 80)
            ->string('last_name', true, 80)
            ->string('phone', true, 20, 9, '/^\+?[0-9 ]{9,20}$/', message: 'Enter a valid phone number, e.g. 255712345678.')
            ->email('email')
            ->int('department_id', true, 1)
            ->intArray('role_ids', true, 1, 20)
            ->intArray('designation_ids', false, 0, 20)
            ->enum('status', ['active', 'inactive'], false);
        if ($creating) {
            $v->string('username', true, 50, 3, '/^[A-Za-z0-9._-]+$/', message: 'Use 3–50 letters, digits, dots, dashes or underscores.')
              ->string('password', true, 128, trim: false);
        } else {
            $v->int('row_version', true, 1);
        }
        $data = $v->validate();
        $data['status'] ??= 'active';
        $data['phone'] = str_replace(' ', '', (string) $data['phone']);
        return $data;
    }

    private function assertReferences(array $v): void
    {
        $errors = [];
        if (!Database::value('SELECT 1 FROM departments WHERE id = :id AND is_active = 1', ['id' => $v['department_id']])) {
            $errors['department_id'] = 'Choose an active department.';
        }
        [$in, $params] = Database::in('r', $v['role_ids']);
        if ((int) Database::value("SELECT COUNT(*) FROM roles WHERE id IN ($in)", $params) !== count($v['role_ids'])) {
            $errors['role_ids'] = 'One or more roles no longer exist.';
        }
        if ($v['designation_ids']) {
            [$in, $params] = Database::in('g', $v['designation_ids']);
            if ((int) Database::value("SELECT COUNT(*) FROM designations WHERE id IN ($in) AND is_active = 1", $params) !== count($v['designation_ids'])) {
                $errors['designation_ids'] = 'One or more designations are not available.';
            }
        }
        if ($errors) {
            throw HttpException::validation($errors);
        }
    }

    /** Refuses changes that would leave the system without an active administrator. */
    private function assertAdminRemains(int $userId, array $newRoleIds, string $newStatus): void
    {
        $adminRoleId = (int) Database::value('SELECT id FROM roles WHERE code = :code', ['code' => 'admin']);
        $isActiveAdmin = Database::value(
            'SELECT 1 FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.user_id = :id AND ur.role_id = :role AND u.status = :active',
            ['id' => $userId, 'role' => $adminRoleId, 'active' => 'active']
        );
        if (!$isActiveAdmin || (in_array($adminRoleId, $newRoleIds, true) && $newStatus === 'active')) {
            return;
        }
        $others = (int) Database::value(
            'SELECT COUNT(*) FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.role_id = :role AND u.status = :active AND u.id <> :id',
            ['role' => $adminRoleId, 'active' => 'active', 'id' => $userId]
        );
        if ($others === 0) {
            throw HttpException::conflict('last_admin', 'At least one active administrator must remain.');
        }
    }

    private function syncLinks(int $userId, array $roleIds, array $designationIds, int $by): void
    {
        Database::execute('DELETE FROM user_roles WHERE user_id = :u', ['u' => $userId]);
        foreach ($roleIds as $roleId) {
            Database::execute(
                'INSERT INTO user_roles (user_id, role_id, assigned_at, assigned_by) VALUES (:u, :r, UTC_TIMESTAMP(3), :by)',
                ['u' => $userId, 'r' => $roleId, 'by' => $by]
            );
        }
        Database::execute('DELETE FROM user_designations WHERE user_id = :u', ['u' => $userId]);
        foreach ($designationIds as $designationId) {
            Database::execute(
                'INSERT INTO user_designations (user_id, designation_id, assigned_at, assigned_by) VALUES (:u, :g, UTC_TIMESTAMP(3), :by)',
                ['u' => $userId, 'g' => $designationId, 'by' => $by]
            );
        }
    }

    private function snapshot(array $user): array
    {
        return [
            'first_name' => $user['first_name'], 'middle_name' => $user['middle_name'], 'last_name' => $user['last_name'],
            'phone' => $user['phone'], 'email' => $user['email'], 'department' => $user['department'], 'status' => $user['status'],
            'roles' => array_column($user['roles'], 'name'), 'designations' => array_column($user['designations'], 'name'),
        ];
    }

    private function find(int $id): ?array
    {
        return $this->present(Database::all(self::SELECT . ' WHERE u.id = :id', ['id' => $id]))[0] ?? null;
    }

    private function present(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        [$in, $params] = Database::in('u', array_map(static fn (array $r) => (int) $r['id'], $rows));
        $roles = [];
        foreach (Database::all("SELECT ur.user_id, r.id, r.code, r.name, r.color FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id IN ($in) ORDER BY r.name", $params) as $r) {
            $roles[(int) $r['user_id']][] = ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'color' => $r['color']];
        }
        $designations = [];
        foreach (Database::all("SELECT ud.user_id, g.id, g.code, g.name FROM user_designations ud JOIN designations g ON g.id = ud.designation_id WHERE ud.user_id IN ($in) ORDER BY g.name", $params) as $g) {
            $designations[(int) $g['user_id']][] = ['id' => (int) $g['id'], 'code' => $g['code'], 'name' => $g['name']];
        }

        return array_map(static function (array $r) use ($roles, $designations): array {
            $id = (int) $r['id'];
            return [
                'id' => $id,
                'staff_no' => $r['staff_no'],
                'username' => $r['username'],
                'first_name' => $r['first_name'],
                'middle_name' => $r['middle_name'],
                'last_name' => $r['last_name'],
                'full_name' => Format::fullName($r['first_name'], $r['middle_name'], $r['last_name']),
                'initials' => Format::initials($r['first_name'], $r['last_name']),
                'phone' => $r['phone'],
                'email' => $r['email'],
                'department_id' => $r['department_id'] === null ? null : (int) $r['department_id'],
                'department' => $r['department'],
                'status' => $r['status'],
                'must_change_password' => (bool) $r['must_change_password'],
                'is_locked' => (bool) $r['is_locked'],
                'last_login_at' => Format::utc($r['last_login_at']),
                'created_at' => Format::utc($r['created_at']),
                'row_version' => (int) $r['row_version'],
                'roles' => $roles[$id] ?? [],
                'designations' => $designations[$id] ?? [],
            ];
        }, $rows);
    }
}
