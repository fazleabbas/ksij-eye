<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Validator;

/** Departments, staff designations, dropdown lookups and staff pickers. */
final class ReferenceController
{
    public function departments(): array
    {
        $rows = Database::all(
            'SELECT d.id, d.name, d.is_active, (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) AS user_count
               FROM departments d ORDER BY d.name'
        );
        return ['departments' => array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'is_active' => (bool) $r['is_active'], 'user_count' => (int) $r['user_count'],
        ], $rows)];
    }

    public function storeDepartment(Request $request): array
    {
        $v = Validator::make($request->all())->string('name', true, 100, 2)->validate();
        $this->assertUnique('departments', $v['name']);
        $id = Database::insert(
            'INSERT INTO departments (name, is_active, created_at, created_by) VALUES (:n, 1, UTC_TIMESTAMP(3), :by)',
            ['n' => $v['name'], 'by' => Auth::require()->userId]
        );
        Audit::log('settings', 'department.created', "Added department {$v['name']}", 'department', $id);
        return ['id' => $id];
    }

    public function updateDepartment(Request $request, array $params): array
    {
        $before = Database::one('SELECT id, name, is_active FROM departments WHERE id = :id', ['id' => $params['id']])
            ?? throw HttpException::notFound('Department not found.');
        $v = Validator::make($request->all())->string('name', true, 100, 2)->bool('is_active', true)->validate();
        $this->assertUnique('departments', $v['name'], (int) $before['id']);
        Database::execute(
            'UPDATE departments SET name = :n, is_active = :a, updated_at = UTC_TIMESTAMP(3), updated_by = :by WHERE id = :id',
            ['n' => $v['name'], 'a' => $v['is_active'] ? 1 : 0, 'by' => Auth::require()->userId, 'id' => (int) $before['id']]
        );
        $changes = Audit::diff(['name' => $before['name'], 'is_active' => (bool) $before['is_active']], ['name' => $v['name'], 'is_active' => $v['is_active']]);
        Audit::log('settings', 'department.updated', "Updated department {$v['name']}", 'department', (int) $before['id'], $changes ?: null);
        return ['id' => (int) $before['id']];
    }

    public function designations(): array
    {
        $rows = Database::all(
            'SELECT g.id, g.code, g.name, g.is_active, (SELECT COUNT(*) FROM user_designations ud WHERE ud.designation_id = g.id) AS user_count
               FROM designations g ORDER BY g.name'
        );
        return ['designations' => array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'is_active' => (bool) $r['is_active'], 'user_count' => (int) $r['user_count'],
        ], $rows)];
    }

    public function storeDesignation(Request $request): array
    {
        $v = Validator::make($request->all())->string('name', true, 100, 2)->validate();
        $this->assertUnique('designations', $v['name']);
        $base = substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($v['name'])), '_'), 0, 36) ?: 'designation';
        $code = $base;
        for ($i = 2; Database::value('SELECT 1 FROM designations WHERE code = :c', ['c' => $code]); $i++) {
            $code = "{$base}_$i";
        }
        $id = Database::insert(
            'INSERT INTO designations (code, name, is_active, created_at, created_by) VALUES (:c, :n, 1, UTC_TIMESTAMP(3), :by)',
            ['c' => $code, 'n' => $v['name'], 'by' => Auth::require()->userId]
        );
        Audit::log('settings', 'designation.created', "Added designation {$v['name']}", 'designation', $id);
        return ['id' => $id];
    }

    public function updateDesignation(Request $request, array $params): array
    {
        $before = Database::one('SELECT id, name, is_active FROM designations WHERE id = :id', ['id' => $params['id']])
            ?? throw HttpException::notFound('Designation not found.');
        $v = Validator::make($request->all())->string('name', true, 100, 2)->bool('is_active', true)->validate();
        $this->assertUnique('designations', $v['name'], (int) $before['id']);
        Database::execute(
            'UPDATE designations SET name = :n, is_active = :a, updated_at = UTC_TIMESTAMP(3), updated_by = :by WHERE id = :id',
            ['n' => $v['name'], 'a' => $v['is_active'] ? 1 : 0, 'by' => Auth::require()->userId, 'id' => (int) $before['id']]
        );
        $changes = Audit::diff(['name' => $before['name'], 'is_active' => (bool) $before['is_active']], ['name' => $v['name'], 'is_active' => $v['is_active']]);
        Audit::log('settings', 'designation.updated', "Updated designation {$v['name']}", 'designation', (int) $before['id'], $changes ?: null);
        return ['id' => (int) $before['id']];
    }

    public function lookups(Request $request): array
    {
        $list = $request->query('list');
        if (!preg_match('/^[a-z_]{2,50}$/', $list)) {
            throw HttpException::validation(['list' => 'Unknown list.']);
        }
        return ['values' => Database::all(
            'SELECT value, label FROM lookup_values WHERE list_code = :l AND is_active = 1 ORDER BY sort_order, label',
            ['l' => $list]
        )];
    }

    /** Active staff holding a designation, e.g. ?designation=surgeon for the surgeon picker. */
    public function staff(Request $request): array
    {
        $code = $request->query('designation');
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $code)) {
            throw HttpException::validation(['designation' => 'Unknown designation.']);
        }
        $rows = Database::all(
            'SELECT u.id, u.first_name, u.middle_name, u.last_name
               FROM users u
               JOIN user_designations ud ON ud.user_id = u.id
               JOIN designations g ON g.id = ud.designation_id
              WHERE g.code = :c AND u.status = :active
              ORDER BY u.first_name, u.last_name',
            ['c' => $code, 'active' => 'active']
        );
        return ['staff' => array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'name' => Format::fullName($r['first_name'], $r['middle_name'], $r['last_name']),
        ], $rows)];
    }

    private function assertUnique(string $table, string $name, int $exceptId = 0): void
    {
        $table = match ($table) {
            'departments', 'designations' => $table,
        };
        if (Database::value("SELECT 1 FROM $table WHERE name = :n AND id <> :id", ['n' => $name, 'id' => $exceptId])) {
            throw HttpException::validation(['name' => 'This name already exists.']);
        }
    }
}
