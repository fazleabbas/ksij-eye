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

/**
 * Price list. Every price change is kept in price_history with who, when and why;
 * bills copy the price at the time they are raised, so history never rewrites old invoices.
 */
final class PriceController
{
    // Medicine prices are kept on the pharmacy's medicine list, not here.
    private const CATEGORIES = ['consultation', 'investigation', 'surgery', 'procedure', 'lens', 'other'];

    public function index(Request $request): array
    {
        $where = [];
        $params = [];
        $category = $request->query('category');
        if (in_array($category, self::CATEGORIES, true)) {
            $where[] = 'p.category = :category';
            $params['category'] = $category;
        }
        if (($search = $request->query('search')) !== '') {
            $where[] = '(p.name LIKE :q1 OR p.code LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . addcslashes($search, '%_\\') . '%';
        }
        $order = "FIELD(p.category, '" . implode("','", self::CATEGORIES) . "'), p.name";
        $rows = Database::all(
            'SELECT p.id, p.code, p.category, p.name, p.unit_price, p.currency, p.is_confirmed, p.is_active, p.updated_at, p.row_version,
                    TRIM(CONCAT_WS(" ", u.first_name, u.last_name)) AS updated_by_name
               FROM price_items p LEFT JOIN users u ON u.id = p.updated_by'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY $order",
            $params
        );
        return ['items' => array_map([$this, 'present'], $rows), 'categories' => self::CATEGORIES];
    }

    public function store(Request $request): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->string('code', true, 40, 2, '/^[A-Z0-9-]+$/', message: 'Use capital letters, digits and dashes.')
            ->enum('category', self::CATEGORIES)
            ->string('name', true, 150, 2)
            ->decimal('unit_price', true, 0, 100000000)
            ->bool('is_confirmed', false)
            ->validate();
        if (Database::value('SELECT 1 FROM price_items WHERE code = :c', ['c' => $v['code']])) {
            throw HttpException::validation(['code' => 'This code already exists.']);
        }
        if (Database::value('SELECT 1 FROM price_items WHERE category = :cat AND name = :n', ['cat' => $v['category'], 'n' => $v['name']])) {
            throw HttpException::validation(['name' => 'An item with this name already exists in the category.']);
        }

        $id = Database::transaction(function () use ($v, $ctx): int {
            $id = Database::insert(
                'INSERT INTO price_items (code, category, name, unit_price, currency, is_confirmed, is_active, created_at, created_by, updated_at, updated_by)
                 VALUES (:code, :cat, :name, :price, :cur, :confirmed, 1, UTC_TIMESTAMP(3), :by1, UTC_TIMESTAMP(3), :by2)',
                ['code' => $v['code'], 'cat' => $v['category'], 'name' => $v['name'], 'price' => $v['unit_price'], 'cur' => 'TZS',
                 'confirmed' => $v['is_confirmed'] ? 1 : 0, 'by1' => $ctx->userId, 'by2' => $ctx->userId]
            );
            Database::execute(
                'INSERT INTO price_history (price_item_id, old_price, new_price, changed_at, changed_by, reason) VALUES (:id, NULL, :p, UTC_TIMESTAMP(3), :by, :r)',
                ['id' => $id, 'p' => $v['unit_price'], 'by' => $ctx->userId, 'r' => 'Item created']
            );
            return $id;
        });
        Audit::log('prices', 'price.created', "Added price item {$v['code']} — {$v['name']}", 'price_item', $id, ['unit_price' => ['to' => $v['unit_price']]]);
        return ['item' => $this->find($id)];
    }

    public function update(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $item = $this->find($params['id']) ?? throw HttpException::notFound('Price item not found.');
        $v = Validator::make($request->all())
            ->enum('category', self::CATEGORIES, false)
            ->string('name', true, 150, 2)
            ->decimal('unit_price', true, 0, 100000000)
            ->bool('is_confirmed', true)
            ->bool('is_active', true)
            ->string('reason', false, 255)
            ->int('row_version', true, 1)
            ->validate();
        $priceChanged = abs($v['unit_price'] - $item['unit_price']) >= 0.005;
        if ($priceChanged && $v['reason'] === null) {
            throw HttpException::validation(['reason' => 'Give a reason for the price change.']);
        }
        // Bills copy the description and price, so moving an item to another category leaves old bills as they were.
        $category = $v['category'] ?? $item['category'];
        if (Database::value('SELECT 1 FROM price_items WHERE category = :cat AND name = :n AND id <> :id', ['cat' => $category, 'n' => $v['name'], 'id' => $item['id']])) {
            throw HttpException::validation(['name' => 'An item with this name already exists in the category.']);
        }

        Database::transaction(function () use ($v, $item, $ctx, $priceChanged, $category): void {
            $updated = Database::execute(
                'UPDATE price_items SET category = :cat, name = :name, unit_price = :price, is_confirmed = :confirmed, is_active = :active,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                  WHERE id = :id AND row_version = :version',
                ['cat' => $category, 'name' => $v['name'], 'price' => $v['unit_price'], 'confirmed' => $v['is_confirmed'] ? 1 : 0, 'active' => $v['is_active'] ? 1 : 0,
                 'by' => $ctx->userId, 'id' => $item['id'], 'version' => $v['row_version']]
            );
            if ($updated === 0) {
                throw HttpException::conflict('stale_record', 'This price was changed by someone else. Reload and try again.');
            }
            if ($priceChanged) {
                Database::execute(
                    'INSERT INTO price_history (price_item_id, old_price, new_price, changed_at, changed_by, reason) VALUES (:id, :old, :new, UTC_TIMESTAMP(3), :by, :r)',
                    ['id' => $item['id'], 'old' => $item['unit_price'], 'new' => $v['unit_price'], 'by' => $ctx->userId, 'r' => $v['reason']]
                );
            }
        });

        $after = $this->find($item['id']);
        $keys = ['category', 'name', 'unit_price', 'is_confirmed', 'is_active'];
        $changes = Audit::diff(array_intersect_key($item, array_flip($keys)), array_intersect_key($after, array_flip($keys)));
        if ($priceChanged) {
            $changes['reason'] = $v['reason'];
        }
        Audit::log('prices', 'price.updated', "Updated price item {$item['code']} — {$after['name']}", 'price_item', $item['id'], $changes ?: null);
        return ['item' => $after];
    }

    public function history(Request $request, array $params): array
    {
        $item = $this->find($params['id']) ?? throw HttpException::notFound('Price item not found.');
        $rows = Database::all(
            'SELECT h.old_price, h.new_price, h.changed_at, h.reason, TRIM(CONCAT_WS(" ", u.first_name, u.last_name)) AS changed_by_name
               FROM price_history h LEFT JOIN users u ON u.id = h.changed_by
              WHERE h.price_item_id = :id ORDER BY h.id DESC',
            ['id' => $item['id']]
        );
        return ['item' => $item, 'history' => array_map(static fn (array $h) => [
            'old_price' => $h['old_price'] === null ? null : (float) $h['old_price'],
            'new_price' => (float) $h['new_price'],
            'changed_at' => Format::utc($h['changed_at']),
            'changed_by' => $h['changed_by_name'] ?: 'System',
            'reason' => $h['reason'],
        ], $rows)];
    }

    private function find(int $id): ?array
    {
        $row = Database::one(
            'SELECT p.id, p.code, p.category, p.name, p.unit_price, p.currency, p.is_confirmed, p.is_active, p.updated_at, p.row_version,
                    TRIM(CONCAT_WS(" ", u.first_name, u.last_name)) AS updated_by_name
               FROM price_items p LEFT JOIN users u ON u.id = p.updated_by WHERE p.id = :id',
            ['id' => $id]
        );
        return $row === null ? null : $this->present($row);
    }

    private function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'code' => $r['code'],
            'category' => $r['category'],
            'name' => $r['name'],
            'unit_price' => (float) $r['unit_price'],
            'currency' => $r['currency'],
            'is_confirmed' => (bool) $r['is_confirmed'],
            'is_active' => (bool) $r['is_active'],
            'updated_at' => Format::utc($r['updated_at']),
            'updated_by' => $r['updated_by_name'] ?: null,
            'row_version' => (int) $r['row_version'],
        ];
    }
}
