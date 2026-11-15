<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Validator;
use App\Services\ClinicalCatalog;

/**
 * Administrator-managed clinical catalogues: ICD-10 codes, drugs, examination findings and dropdown lists.
 * Entries are deactivated rather than deleted, so existing records keep their meaning.
 */
final class CatalogueController
{
    /** Dropdown lists administrators may edit (value stays fixed; label, order and active flag change). */
    public const EDITABLE_LISTS = [
        'emergency_reason' => 'Emergency reasons',
        'follow_up_reason' => 'Follow-up reasons',
        'drug_frequency' => 'Medication frequencies',
        'consultation_room' => 'Consultation rooms',
        'discount_type' => 'Discount types',
        'kin_relationship' => 'Next-of-kin relationships',
        'diagnostic_laser_type' => 'Laser types',
        'diagnostic_laser_subtype' => 'Retina laser sub-types',
        'no_charge_reason' => 'Reasons for not charging',
        'optical_supplier' => 'Spectacle suppliers',
        'rx_label' => 'Spectacle prescription labels',
        'theatre_room' => 'Theatre rooms',
        'store_category' => 'Store categories',
        'store_unit' => 'Store units',
        'postop_counselling' => 'Post-op counselling topics',
    ];

    // ─── ICD-10 ──────────────────────────────────────────────────────────

    public function icdIndex(): array
    {
        return ['codes' => array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'code' => $r['code'], 'description' => $r['description'], 'category' => $r['category'],
            'is_active' => (bool) $r['is_active'], 'usage' => (int) $r['usage'],
        ], Database::all(
            'SELECT c.*, (SELECT COUNT(*) FROM clinical_diagnoses d WHERE d.icd10_code_id = c.id) AS `usage` FROM icd10_codes c ORDER BY c.sort_order, c.code'
        ))];
    }

    public function icdStore(Request $request): array
    {
        $v = $this->validateIcd($request, false);
        $this->assertFree('icd10_codes', 'code', $v['code']);
        $id = Database::insert(
            'INSERT INTO icd10_codes (code, description, category, sort_order, created_at, created_by)
             VALUES (:c, :d, :cat, (SELECT COALESCE(MAX(x.sort_order), 0) + 10 FROM icd10_codes x), UTC_TIMESTAMP(3), :by)',
            ['c' => $v['code'], 'd' => $v['description'], 'cat' => $v['category'], 'by' => Auth::require()->userId]
        );
        Audit::log('settings', 'icd10.created', "Added ICD-10 code {$v['code']}", 'icd10_code', $id);
        return ['id' => $id];
    }

    public function icdUpdate(Request $request, array $params): array
    {
        $before = Database::one('SELECT * FROM icd10_codes WHERE id = :id', ['id' => $params['id']]) ?? throw HttpException::notFound('Code not found.');
        $v = $this->validateIcd($request, true);
        $this->assertFree('icd10_codes', 'code', $v['code'], (int) $before['id']);
        Database::execute(
            'UPDATE icd10_codes SET code = :c, description = :d, category = :cat, is_active = :a, updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                    row_version = row_version + 1 WHERE id = :id',
            ['c' => $v['code'], 'd' => $v['description'], 'cat' => $v['category'], 'a' => $v['is_active'] ? 1 : 0, 'by' => Auth::require()->userId, 'id' => (int) $before['id']]
        );
        $changes = Audit::diff(
            ['code' => $before['code'], 'description' => $before['description'], 'category' => $before['category'], 'is_active' => (bool) $before['is_active']],
            ['code' => $v['code'], 'description' => $v['description'], 'category' => $v['category'], 'is_active' => $v['is_active']]
        );
        Audit::log('settings', 'icd10.updated', "Updated ICD-10 code {$v['code']}", 'icd10_code', (int) $before['id'], $changes ?: null);
        return ['id' => (int) $before['id']];
    }

    private function validateIcd(Request $request, bool $update): array
    {
        $v = Validator::make($request->all())
            ->string('code', true, 12, 3, '/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/', true, 'Use the ICD-10 format, e.g. H40.1.')
            ->string('description', true, 255, 3)
            ->string('category', false, 80);
        if ($update) {
            $v->bool('is_active', true);
        }
        return $v->validate();
    }

    // ─── Drugs ───────────────────────────────────────────────────────────

    public function drugIndex(): array
    {
        return ['drugs' => array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'category' => $r['category'], 'dosage_form' => $r['dosage_form'],
            'default_dose' => $r['default_dose'], 'default_frequency' => $r['default_frequency'], 'default_duration' => $r['default_duration'],
            'unit_price' => (float) $r['unit_price'], 'is_active' => (bool) $r['is_active'],
        ], Database::all('SELECT * FROM drugs ORDER BY name'))];
    }

    public function drugStore(Request $request): array
    {
        $v = $this->validateDrug($request, false);
        $this->assertFree('drugs', 'name', $v['name']);
        $id = Database::insert(
            'INSERT INTO drugs (name, category, dosage_form, default_dose, default_frequency, default_duration, unit_price, created_at, created_by)
             VALUES (:n, :c, :f, :d, :q, :u, :p, UTC_TIMESTAMP(3), :by)',
            ['n' => $v['name'], 'c' => $v['category'], 'f' => $v['dosage_form'], 'd' => $v['default_dose'], 'q' => $v['default_frequency'],
             'u' => $v['default_duration'], 'p' => $v['unit_price'], 'by' => Auth::require()->userId]
        );
        Audit::log('settings', 'drug.created', "Added medicine {$v['name']}", 'drug', $id);
        return ['id' => $id];
    }

    public function drugUpdate(Request $request, array $params): array
    {
        $before = Database::one('SELECT * FROM drugs WHERE id = :id', ['id' => $params['id']]) ?? throw HttpException::notFound('Medicine not found.');
        $v = $this->validateDrug($request, true);
        $this->assertFree('drugs', 'name', $v['name'], (int) $before['id']);
        Database::execute(
            'UPDATE drugs SET name = :n, category = :c, dosage_form = :f, default_dose = :d, default_frequency = :q, default_duration = :u,
                    unit_price = :p, is_active = :a, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id',
            ['n' => $v['name'], 'c' => $v['category'], 'f' => $v['dosage_form'], 'd' => $v['default_dose'], 'q' => $v['default_frequency'],
             'u' => $v['default_duration'], 'p' => $v['unit_price'], 'a' => $v['is_active'] ? 1 : 0, 'by' => Auth::require()->userId, 'id' => (int) $before['id']]
        );
        $fields = ['name', 'category', 'dosage_form', 'default_dose', 'default_frequency', 'default_duration', 'unit_price'];
        $changes = Audit::diff(array_intersect_key($before, array_flip($fields)) + ['is_active' => (bool) $before['is_active']], array_intersect_key($v, array_flip([...$fields, 'is_active'])));
        Audit::log('settings', 'drug.updated', "Updated medicine {$v['name']}", 'drug', (int) $before['id'], $changes ?: null);
        return ['id' => (int) $before['id']];
    }

    private function validateDrug(Request $request, bool $update): array
    {
        $v = Validator::make($request->all())
            ->string('name', true, 150, 2)
            ->string('category', true, 60, 2)
            ->string('dosage_form', false, 40)
            ->string('default_dose', false, 40)
            ->string('default_frequency', false, 20)
            ->string('default_duration', false, 40)
            ->decimal('unit_price', false, 0, 99999999);
        if ($update) {
            $v->bool('is_active', true);
        }
        $values = $v->validate();
        $values['unit_price'] = (float) ($values['unit_price'] ?? 0);
        return $values;
    }

    // ─── Examination findings ────────────────────────────────────────────

    public function findingIndex(): array
    {
        return [
            'sections' => array_map(static fn (string $code, string $label) => ['code' => $code, 'label' => $label], array_keys(ClinicalCatalog::EXAM_SECTIONS), ClinicalCatalog::EXAM_SECTIONS),
            'findings' => array_map(static fn (array $r) => [
                'id' => (int) $r['id'], 'section' => $r['section'], 'label' => $r['label'], 'is_normal' => (bool) $r['is_normal'],
                'sort_order' => (int) $r['sort_order'], 'is_active' => (bool) $r['is_active'],
            ], Database::all('SELECT * FROM exam_findings ORDER BY section, sort_order, label')),
        ];
    }

    public function findingStore(Request $request): array
    {
        $v = $this->validateFinding($request, false);
        $this->assertFindingFree($v['section'], $v['label']);
        $id = Database::insert(
            'INSERT INTO exam_findings (section, label, is_normal, sort_order, created_at, created_by)
             VALUES (:s, :l, :n, (SELECT COALESCE(MAX(x.sort_order), 0) + 10 FROM exam_findings x WHERE x.section = :s2), UTC_TIMESTAMP(3), :by)',
            ['s' => $v['section'], 's2' => $v['section'], 'l' => $v['label'], 'n' => $v['is_normal'] ? 1 : 0, 'by' => Auth::require()->userId]
        );
        Audit::log('settings', 'exam_finding.created', "Added examination finding {$v['label']} (" . ClinicalCatalog::EXAM_SECTIONS[$v['section']] . ')', 'exam_finding', $id);
        return ['id' => $id];
    }

    public function findingUpdate(Request $request, array $params): array
    {
        $before = Database::one('SELECT * FROM exam_findings WHERE id = :id', ['id' => $params['id']]) ?? throw HttpException::notFound('Finding not found.');
        $v = $this->validateFinding($request, true);
        $this->assertFindingFree($v['section'], $v['label'], (int) $before['id']);
        Database::execute(
            'UPDATE exam_findings SET section = :s, label = :l, is_normal = :n, is_active = :a, updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                    row_version = row_version + 1 WHERE id = :id',
            ['s' => $v['section'], 'l' => $v['label'], 'n' => $v['is_normal'] ? 1 : 0, 'a' => $v['is_active'] ? 1 : 0, 'by' => Auth::require()->userId, 'id' => (int) $before['id']]
        );
        $changes = Audit::diff(
            ['section' => $before['section'], 'label' => $before['label'], 'is_normal' => (bool) $before['is_normal'], 'is_active' => (bool) $before['is_active']],
            ['section' => $v['section'], 'label' => $v['label'], 'is_normal' => $v['is_normal'], 'is_active' => $v['is_active']]
        );
        Audit::log('settings', 'exam_finding.updated', "Updated examination finding {$v['label']}", 'exam_finding', (int) $before['id'], $changes ?: null);
        return ['id' => (int) $before['id']];
    }

    private function validateFinding(Request $request, bool $update): array
    {
        $v = Validator::make($request->all())
            ->enum('section', array_keys(ClinicalCatalog::EXAM_SECTIONS))
            ->string('label', true, 100, 2)
            ->bool('is_normal', true);
        if ($update) {
            $v->bool('is_active', true);
        }
        return $v->validate();
    }

    private function assertFindingFree(string $section, string $label, int $exceptId = 0): void
    {
        if (Database::value('SELECT 1 FROM exam_findings WHERE section = :s AND label = :l AND id <> :id', ['s' => $section, 'l' => $label, 'id' => $exceptId])) {
            throw HttpException::validation(['label' => 'This finding already exists in the section.']);
        }
    }

    // ─── Dropdown lists ──────────────────────────────────────────────────

    public function listIndex(): array
    {
        [$in, $params] = Database::in('l', array_keys(self::EDITABLE_LISTS));
        $values = [];
        foreach (Database::all("SELECT id, list_code, value, label, sort_order, is_active FROM lookup_values WHERE list_code IN ($in) ORDER BY sort_order, label", $params) as $r) {
            $values[$r['list_code']][] = [
                'id' => (int) $r['id'], 'value' => $r['value'], 'label' => $r['label'], 'sort_order' => (int) $r['sort_order'], 'is_active' => (bool) $r['is_active'],
            ];
        }
        $lists = [];
        foreach (self::EDITABLE_LISTS as $code => $name) {
            $lists[] = ['code' => $code, 'name' => $name, 'values' => $values[$code] ?? []];
        }
        return ['lists' => $lists];
    }

    public function listStore(Request $request): array
    {
        $v = Validator::make($request->all())->enum('list_code', array_keys(self::EDITABLE_LISTS))->string('label', true, 100, 1)->validate();
        if (Database::value('SELECT 1 FROM lookup_values WHERE list_code = :l AND (value = :v OR label = :v2)', ['l' => $v['list_code'], 'v' => $v['label'], 'v2' => $v['label']])) {
            throw HttpException::validation(['label' => 'This entry already exists in the list.']);
        }
        $id = Database::insert(
            'INSERT INTO lookup_values (list_code, value, label, sort_order, created_at, created_by)
             VALUES (:l, :v, :lab, (SELECT COALESCE(MAX(x.sort_order), 0) + 10 FROM lookup_values x WHERE x.list_code = :l2), UTC_TIMESTAMP(3), :by)',
            ['l' => $v['list_code'], 'l2' => $v['list_code'], 'v' => $v['label'], 'lab' => $v['label'], 'by' => Auth::require()->userId]
        );
        Audit::log('settings', 'lookup.created', 'Added "' . $v['label'] . '" to ' . self::EDITABLE_LISTS[$v['list_code']], 'lookup_value', $id);
        return ['id' => $id];
    }

    public function listUpdate(Request $request, array $params): array
    {
        [$in, $inParams] = Database::in('l', array_keys(self::EDITABLE_LISTS));
        $before = Database::one("SELECT * FROM lookup_values WHERE id = :id AND list_code IN ($in)", ['id' => $params['id']] + $inParams)
            ?? throw HttpException::notFound('Entry not found.');
        $v = Validator::make($request->all())->string('label', true, 100, 1)->int('sort_order', true, 0, 60000)->bool('is_active', true)->validate();
        if (Database::value('SELECT 1 FROM lookup_values WHERE list_code = :l AND label = :lab AND id <> :id', ['l' => $before['list_code'], 'lab' => $v['label'], 'id' => (int) $before['id']])) {
            throw HttpException::validation(['label' => 'This entry already exists in the list.']);
        }
        Database::execute(
            'UPDATE lookup_values SET label = :lab, sort_order = :o, is_active = :a, updated_at = UTC_TIMESTAMP(3), updated_by = :by WHERE id = :id',
            ['lab' => $v['label'], 'o' => $v['sort_order'], 'a' => $v['is_active'] ? 1 : 0, 'by' => Auth::require()->userId, 'id' => (int) $before['id']]
        );
        $changes = Audit::diff(
            ['label' => $before['label'], 'sort_order' => (int) $before['sort_order'], 'is_active' => (bool) $before['is_active']],
            ['label' => $v['label'], 'sort_order' => $v['sort_order'], 'is_active' => $v['is_active']]
        );
        Audit::log('settings', 'lookup.updated', 'Updated "' . $v['label'] . '" in ' . self::EDITABLE_LISTS[$before['list_code']], 'lookup_value', (int) $before['id'], $changes ?: null);
        return ['id' => (int) $before['id']];
    }

    private function assertFree(string $table, string $column, string $value, int $exceptId = 0): void
    {
        [$table, $column] = match ("$table.$column") {
            'icd10_codes.code' => ['icd10_codes', 'code'],
            'drugs.name' => ['drugs', 'name'],
        };
        if (Database::value("SELECT 1 FROM $table WHERE $column = :v AND id <> :id", ['v' => $value, 'id' => $exceptId])) {
            throw HttpException::validation([$column => 'This already exists.']);
        }
    }
}
