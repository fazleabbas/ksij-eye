<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Validator;

/**
 * Roles bundle per-action permissions. System roles cannot be renamed or deleted; the Admin
 * role is locked and always holds every permission (kept in sync by the seeder).
 */
final class RoleController
{
    private const COLORS = ['teal', 'blue', 'amber', 'green', 'purple', 'red'];

    public function index(): array
    {
        $rows = Database::all(
            'SELECT r.id, r.code, r.name, r.description, r.color, r.is_system, r.is_locked, r.row_version,
                    (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count
               FROM roles r ORDER BY r.is_system DESC, r.name'
        );
        $permissions = $this->permissionMap();
        return [
            'roles' => array_map(fn (array $r) => $this->present($r, $permissions[(int) $r['id']] ?? []), $rows),
            'permission_count' => (int) Database::value('SELECT COUNT(*) FROM permissions'),
        ];
    }

    public function permissions(): array
    {
        $groups = [];
        foreach (Database::all('SELECT code, category, label, description FROM permissions ORDER BY sort_order, id') as $p) {
            $groups[$p['category']] ??= ['category' => $p['category'], 'permissions' => []];
            $groups[$p['category']]['permissions'][] = ['code' => $p['code'], 'label' => $p['label'], 'description' => $p['description']];
        }
        return ['groups' => array_values($groups)];
    }

    public function store(Request $request): array
    {
        $ctx = Auth::require();
        $v = $this->validate($request->all(), updating: false, locked: false);
        if (Database::value('SELECT 1 FROM roles WHERE name = :n', ['n' => $v['name']])) {
            throw HttpException::validation(['name' => 'A role with this name already exists.']);
        }
        $permissionIds = $this->permissionIds($v['permissions']);

        $id = Database::transaction(function () use ($v, $permissionIds, $ctx): int {
            $id = Database::insert(
                'INSERT INTO roles (code, name, description, color, is_system, is_locked, created_at, created_by)
                 VALUES (:code, :name, :description, :color, 0, 0, UTC_TIMESTAMP(3), :by)',
                ['code' => $this->uniqueCode($v['name']), 'name' => $v['name'], 'description' => $v['description'], 'color' => $v['color'], 'by' => $ctx->userId]
            );
            $this->syncPermissions($id, $permissionIds, $ctx->userId);
            return $id;
        });

        Audit::log('roles', 'role.created', "Created role {$v['name']}", 'role', $id, ['permissions' => ['to' => $v['permissions']]]);
        return ['role' => $this->find($id)];
    }

    public function update(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $role = $this->find($params['id']) ?? throw HttpException::notFound('Role not found.');
        $v = $this->validate($request->all(), updating: true, locked: $role['is_locked']);
        $name = $role['is_system'] ? $role['name'] : $v['name'];
        if (!$role['is_system'] && Database::value('SELECT 1 FROM roles WHERE name = :n AND id <> :id', ['n' => $name, 'id' => $role['id']])) {
            throw HttpException::validation(['name' => 'A role with this name already exists.']);
        }
        $permissionIds = $role['is_locked'] ? [] : $this->permissionIds($v['permissions']);

        Database::transaction(function () use ($role, $v, $name, $permissionIds, $ctx): void {
            $updated = Database::execute(
                'UPDATE roles SET name = :name, description = :description, color = :color, updated_at = UTC_TIMESTAMP(3),
                        updated_by = :by, row_version = row_version + 1
                  WHERE id = :id AND row_version = :version',
                ['name' => $name, 'description' => $v['description'], 'color' => $v['color'], 'by' => $ctx->userId, 'id' => $role['id'], 'version' => $v['row_version']]
            );
            if ($updated === 0) {
                throw HttpException::conflict('stale_record', 'This role was changed by someone else. Reload and try again.');
            }
            if (!$role['is_locked']) {
                $this->syncPermissions($role['id'], $permissionIds, $ctx->userId);
            }
        });

        $after = $this->find($role['id']);
        $changes = Audit::diff(
            ['name' => $role['name'], 'description' => $role['description'], 'color' => $role['color']],
            ['name' => $after['name'], 'description' => $after['description'], 'color' => $after['color']]
        );
        if ($added = array_values(array_diff($after['permissions'], $role['permissions']))) {
            $changes['permissions_added'] = $added;
        }
        if ($removed = array_values(array_diff($role['permissions'], $after['permissions']))) {
            $changes['permissions_removed'] = $removed;
        }
        Audit::log('roles', 'role.updated', "Updated role {$after['name']}", 'role', $role['id'], $changes ?: null);
        return ['role' => $after];
    }

    public function destroy(Request $request, array $params): array
    {
        $role = $this->find($params['id']) ?? throw HttpException::notFound('Role not found.');
        if ($role['is_system']) {
            throw HttpException::conflict('system_role', 'System roles cannot be deleted.');
        }
        if ($role['user_count'] > 0) {
            throw HttpException::conflict('role_in_use', 'Remove this role from every user before deleting it.');
        }
        Database::execute('DELETE FROM roles WHERE id = :id', ['id' => $role['id']]);
        Audit::log('roles', 'role.deleted', "Deleted role {$role['name']}", 'role', $role['id'], ['permissions' => ['from' => $role['permissions']]]);
        return ['deleted' => true];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function validate(array $input, bool $updating, bool $locked): array
    {
        $v = Validator::make($input)
            ->string('name', true, 80, 2)
            ->string('description', false, 255)
            ->enum('color', self::COLORS);
        if (!$locked) {
            $v->stringArray('permissions', '/^[a-z_]+(\.[a-z_]+)+$/', true, 1);
        }
        if ($updating) {
            $v->int('row_version', true, 1);
        }
        return $v->validate();
    }

    private function permissionIds(array $codes): array
    {
        [$in, $params] = Database::in('p', $codes);
        $rows = Database::all("SELECT id, code FROM permissions WHERE code IN ($in)", $params);
        if (count($rows) !== count($codes)) {
            throw HttpException::validation(['permissions' => 'One or more permissions are unknown.']);
        }
        return array_map(static fn (array $r) => (int) $r['id'], $rows);
    }

    private function syncPermissions(int $roleId, array $permissionIds, int $by): void
    {
        Database::execute('DELETE FROM role_permissions WHERE role_id = :r', ['r' => $roleId]);
        foreach ($permissionIds as $permissionId) {
            Database::execute(
                'INSERT INTO role_permissions (role_id, permission_id, granted_at, granted_by) VALUES (:r, :p, UTC_TIMESTAMP(3), :by)',
                ['r' => $roleId, 'p' => $permissionId, 'by' => $by]
            );
        }
    }

    private function uniqueCode(string $name): string
    {
        $base = substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_'), 0, 40) ?: 'role';
        $code = $base;
        for ($i = 2; Database::value('SELECT 1 FROM roles WHERE code = :c', ['c' => $code]); $i++) {
            $code = $base . '_' . $i;
        }
        return $code;
    }

    private function permissionMap(): array
    {
        $map = [];
        foreach (Database::all('SELECT rp.role_id, p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id ORDER BY p.sort_order') as $row) {
            $map[(int) $row['role_id']][] = $row['code'];
        }
        return $map;
    }

    private function find(int $id): ?array
    {
        $row = Database::one(
            'SELECT r.id, r.code, r.name, r.description, r.color, r.is_system, r.is_locked, r.row_version,
                    (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count
               FROM roles r WHERE r.id = :id',
            ['id' => $id]
        );
        if ($row === null) {
            return null;
        }
        $codes = array_column(Database::all(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :id ORDER BY p.sort_order',
            ['id' => $id]
        ), 'code');
        return $this->present($row, $codes);
    }

    private function present(array $r, array $permissions): array
    {
        return [
            'id' => (int) $r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'description' => $r['description'],
            'color' => $r['color'],
            'is_system' => (bool) $r['is_system'],
            'is_locked' => (bool) $r['is_locked'],
            'user_count' => (int) $r['user_count'],
            'row_version' => (int) $r['row_version'],
            'permissions' => $permissions,
        ];
    }
}
