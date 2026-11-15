<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuthContext;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Sequence;

/**
 * The store room: what is held, what departments ask for and receive, and how it is bought.
 *
 * Stock is held as batches so an expiry date belongs to the delivery it came with; issues take
 * from whichever batch expires first. Every change leaves a movement row, so a quantity can
 * always be explained. Medicine issued to the pharmacy is added to the pharmacy's own shelf.
 */
final class StoreService
{
    public const LOW_STOCK_WARNING_DAYS = 60;

    // ─── Items and stock ─────────────────────────────────────────────────

    public static function items(array $filters): array
    {
        $where = [];
        $params = [];
        if (($filters['category'] ?? '') !== '') {
            $where[] = 'i.category = :category';
            $params['category'] = $filters['category'];
        }
        if (empty($filters['include_inactive'])) {
            $where[] = 'i.is_active = 1';
        }
        $rows = Database::all(
            'SELECT i.*, s.name AS supplier_name, d.name AS drug_name, f.name AS frame_name,
                    (SELECT MIN(b.expiry_date) FROM store_item_batches b
                      WHERE b.item_id = i.id AND b.quantity > 0 AND b.expiry_date IS NOT NULL) AS earliest_expiry
               FROM store_items i
               LEFT JOIN suppliers s ON s.id = i.supplier_id
               LEFT JOIN drugs d ON d.id = i.drug_id
               LEFT JOIN frames f ON f.id = i.frame_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY i.name',
            $params
        );
        $items = array_map(static fn (array $r) => self::presentItem($r), $rows);
        if (($filters['status'] ?? '') !== '') {
            $items = array_values(array_filter($items, static fn (array $i) => $i['stock_status'] === $filters['status']));
        }
        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter($items, static fn (array $i) => str_contains(mb_strtolower($i['name']), $needle)
                || str_contains(mb_strtolower($i['code']), $needle)
                || str_contains(mb_strtolower((string) $i['category']), $needle)));
        }
        return $items;
    }

    private static function presentItem(array $r): array
    {
        $quantity = (int) $r['quantity'];
        $reorder = (int) $r['reorder_level'];
        return [
            'id' => (int) $r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'category' => $r['category'],
            'unit' => $r['unit'],
            'drug_id' => $r['drug_id'] === null ? null : (int) $r['drug_id'],
            'drug_name' => $r['drug_name'],
            'frame_id' => isset($r['frame_id']) ? (int) $r['frame_id'] : null,
            'frame_name' => $r['frame_name'] ?? null,
            'supplier_id' => $r['supplier_id'] === null ? null : (int) $r['supplier_id'],
            'supplier_name' => $r['supplier_name'],
            'reorder_level' => $reorder,
            'quantity' => $quantity,
            'cost_price' => (float) $r['cost_price'],
            'stock_value' => round($quantity * (float) $r['cost_price'], 2),
            'earliest_expiry' => $r['earliest_expiry'],
            'expiry_status' => self::expiryStatus($r['earliest_expiry']),
            'is_active' => (bool) $r['is_active'],
            'stock_status' => self::stockStatus($quantity, $reorder),
            'row_version' => (int) $r['row_version'],
        ];
    }

    public static function stockStatus(int $quantity, int $reorderLevel): string
    {
        if ($quantity <= 0) {
            return 'out_of_stock';
        }
        return $quantity <= $reorderLevel ? 'low_stock' : 'in_stock';
    }

    public static function expiryStatus(?string $expiry): string
    {
        if ($expiry === null) {
            return 'none';
        }
        $days = (int) (new \DateTimeImmutable(Format::localToday()))->diff(new \DateTimeImmutable($expiry))->format('%r%a');
        if ($days < 0) {
            return 'expired';
        }
        return $days <= self::LOW_STOCK_WARNING_DAYS ? 'expiring_soon' : 'ok';
    }

    public static function findItem(int $id): array
    {
        $row = Database::one(
            'SELECT i.*, s.name AS supplier_name, d.name AS drug_name, f.name AS frame_name,
                    (SELECT MIN(b.expiry_date) FROM store_item_batches b
                      WHERE b.item_id = i.id AND b.quantity > 0 AND b.expiry_date IS NOT NULL) AS earliest_expiry
               FROM store_items i
               LEFT JOIN suppliers s ON s.id = i.supplier_id
               LEFT JOIN drugs d ON d.id = i.drug_id
               LEFT JOIN frames f ON f.id = i.frame_id
              WHERE i.id = :id',
            ['id' => $id]
        ) ?? throw HttpException::notFound('Item not found.');
        return self::presentItem($row) + ['batches' => self::batches($id), 'movements' => self::movements($id)];
    }

    public static function batches(int $itemId): array
    {
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'batch_no' => $r['batch_no'], 'expiry_date' => $r['expiry_date'],
            'quantity' => (int) $r['quantity'], 'received_at' => Format::utc($r['received_at']),
            'expiry_status' => self::expiryStatus($r['expiry_date']),
        ], Database::all(
            'SELECT * FROM store_item_batches WHERE item_id = :i AND quantity > 0
              ORDER BY expiry_date IS NULL, expiry_date, id',
            ['i' => $itemId]
        ));
    }

    public static function movements(int $itemId): array
    {
        return array_map(static fn (array $r) => [
            'change_qty' => (int) $r['change_qty'], 'balance_after' => (int) $r['balance_after'],
            'reason' => $r['reason'], 'reference' => $r['reference'], 'note' => $r['note'],
            'created_at' => Format::utc($r['created_at']), 'created_by' => $r['created_by_name'],
        ], Database::all(
            "SELECT m.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS created_by_name
               FROM store_stock_movements m JOIN users u ON u.id = m.created_by
              WHERE m.item_id = :i ORDER BY m.id DESC LIMIT 50",
            ['i' => $itemId]
        ));
    }

    public static function saveItem(?int $id, array $data, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $data, $ctx): array {
            if (($data['new_drug'] ?? null) !== null) {
                $data['drug_id'] = Database::insert(
                    'INSERT INTO drugs (name, category, dosage_form, unit_price, created_at, created_by)
                     VALUES (:n, :c, :f, :p, UTC_TIMESTAMP(3), :by)',
                    ['n' => $data['name'], 'c' => $data['category'], 'f' => $data['new_drug']['new_drug_form'],
                     'p' => $data['new_drug']['new_drug_price'], 'by' => $ctx->userId]
                );
            } elseif (($data['drug_price'] ?? null) !== null) {
                Database::execute(
                    'UPDATE drugs SET unit_price = :p, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id',
                    ['p' => $data['drug_price']['to'], 'by' => $ctx->userId, 'id' => $data['drug_id']]
                );
            }
            if ($id === null) {
                $code = sprintf('STK-%04d', Sequence::next('store_item'));
                $id = Database::insert(
                    'INSERT INTO store_items (code, name, category, unit, drug_id, frame_id, supplier_id, reorder_level, cost_price,
                                              is_active, created_at, created_by)
                     VALUES (:code, :name, :category, :unit, :drug, :frame, :supplier, :reorder, :cost, 1, UTC_TIMESTAMP(3), :by)',
                    [
                        'code' => $code, 'name' => $data['name'], 'category' => $data['category'], 'unit' => $data['unit'],
                        'drug' => $data['drug_id'], 'frame' => $data['frame_id'] ?? null, 'supplier' => $data['supplier_id'], 'reorder' => $data['reorder_level'],
                        'cost' => $data['cost_price'], 'by' => $ctx->userId,
                    ]
                );
            } else {
                $existing = Database::one('SELECT * FROM store_items WHERE id = :id FOR UPDATE', ['id' => $id])
                    ?? throw HttpException::notFound('Item not found.');
                if ((int) $existing['row_version'] !== (int) ($data['row_version'] ?? 0)) {
                    throw HttpException::conflict('stale', 'Someone else changed this item. Reload and try again.');
                }
                // The quantity is never edited here; it only moves through receipts, issues and corrections.
                Database::execute(
                    'UPDATE store_items SET name = :name, category = :category, unit = :unit, drug_id = :drug, frame_id = :frame,
                            supplier_id = :supplier, reorder_level = :reorder, cost_price = :cost, is_active = :active,
                            updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                      WHERE id = :id',
                    [
                        'name' => $data['name'], 'category' => $data['category'], 'unit' => $data['unit'],
                        'drug' => $data['drug_id'], 'frame' => $data['frame_id'] ?? null, 'supplier' => $data['supplier_id'], 'reorder' => $data['reorder_level'],
                        'cost' => $data['cost_price'], 'active' => !empty($data['is_active']) ? 1 : 0,
                        'by' => $ctx->userId, 'id' => $id,
                    ]
                );
            }
            return self::findItem($id);
        });
    }

    /** Corrects a count, or writes stock off. Both leave a record of who and why. */
    public static function adjustStock(int $itemId, int $change, string $reason, string $note, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($itemId, $change, $reason, $note, $ctx): array {
            if ($change === 0) {
                throw HttpException::validation(['change' => 'Enter how many to add or take off.']);
            }
            if ($change > 0) {
                $batchId = self::addBatch($itemId, $change, null, null, null);
                self::recordMovement($itemId, $batchId, $change, $reason, 'Manual', $note, $ctx->userId);
            } else {
                self::takeFromBatches($itemId, -$change, $reason, 'Manual', $note, $ctx->userId);
            }
            return self::findItem($itemId);
        });
    }

    /** Adds a delivery to the shelf and returns the batch it went into. */
    private static function addBatch(int $itemId, int $quantity, ?string $batchNo, ?string $expiry, ?int $grnId): int
    {
        $existing = Database::one(
            'SELECT id FROM store_item_batches WHERE item_id = :i AND COALESCE(batch_no, :b1) = COALESCE(:b2, batch_no, :b3)
               AND (expiry_date <=> :e) FOR UPDATE',
            ['i' => $itemId, 'b1' => '~none~', 'b2' => $batchNo, 'b3' => '~none~', 'e' => $expiry]
        );
        if ($existing !== null) {
            Database::execute('UPDATE store_item_batches SET quantity = quantity + :q WHERE id = :id',
                ['q' => $quantity, 'id' => (int) $existing['id']]);
            $batchId = (int) $existing['id'];
        } else {
            $batchId = Database::insert(
                'INSERT INTO store_item_batches (item_id, batch_no, expiry_date, quantity, received_at, grn_id)
                 VALUES (:i, :b, :e, :q, UTC_TIMESTAMP(3), :g)',
                ['i' => $itemId, 'b' => $batchNo, 'e' => $expiry, 'q' => $quantity, 'g' => $grnId]
            );
        }
        Database::execute('UPDATE store_items SET quantity = quantity + :q, updated_at = UTC_TIMESTAMP(3) WHERE id = :i',
            ['q' => $quantity, 'i' => $itemId]);
        return $batchId;
    }

    /** Takes stock off, oldest expiry first, and records each batch it came from. */
    private static function takeFromBatches(int $itemId, int $quantity, string $reason, string $reference, ?string $note, int $userId): void
    {
        $available = (int) Database::value('SELECT quantity FROM store_items WHERE id = :i FOR UPDATE', ['i' => $itemId]);
        if ($quantity > $available) {
            $name = (string) Database::value('SELECT name FROM store_items WHERE id = :i', ['i' => $itemId]);
            throw HttpException::validation(['quantity' => "$name: only $available in the store."]);
        }
        $left = $quantity;
        $batches = Database::all(
            'SELECT id, quantity FROM store_item_batches WHERE item_id = :i AND quantity > 0
              ORDER BY expiry_date IS NULL, expiry_date, id FOR UPDATE',
            ['i' => $itemId]
        );
        foreach ($batches as $batch) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, (int) $batch['quantity']);
            Database::execute('UPDATE store_item_batches SET quantity = quantity - :q WHERE id = :id',
                ['q' => $take, 'id' => (int) $batch['id']]);
            Database::execute('UPDATE store_items SET quantity = quantity - :q, updated_at = UTC_TIMESTAMP(3) WHERE id = :i',
                ['q' => $take, 'i' => $itemId]);
            self::recordMovement($itemId, (int) $batch['id'], -$take, $reason, $reference, $note, $userId);
            $left -= $take;
        }
        if ($left > 0) {
            // Stock held without a batch row: take the remainder off the item itself.
            Database::execute('UPDATE store_items SET quantity = quantity - :q, updated_at = UTC_TIMESTAMP(3) WHERE id = :i',
                ['q' => $left, 'i' => $itemId]);
            self::recordMovement($itemId, null, -$left, $reason, $reference, $note, $userId);
        }
    }

    private static function recordMovement(int $itemId, ?int $batchId, int $change, string $reason, ?string $reference, ?string $note, int $userId): void
    {
        $balance = (int) Database::value('SELECT quantity FROM store_items WHERE id = :i', ['i' => $itemId]);
        Database::execute(
            'INSERT INTO store_stock_movements (item_id, batch_id, change_qty, balance_after, reason, reference, note, created_at, created_by)
             VALUES (:i, :b, :c, :bal, :r, :ref, :n, UTC_TIMESTAMP(3), :by)',
            [
                'i' => $itemId, 'b' => $batchId, 'c' => $change, 'bal' => $balance, 'r' => $reason,
                'ref' => $reference, 'n' => $note === null || $note === '' ? null : mb_substr($note, 0, 190), 'by' => $userId,
            ]
        );
    }

    /** Puts an approved delivery on the shelf as its own batch. */
    public static function receiveIntoStock(int $itemId, int $quantity, ?string $batchNo, ?string $expiry,
                                            int $grnId, string $reference, float $unitPrice, int $userId): void
    {
        $batchId = self::addBatch($itemId, $quantity, $batchNo, $expiry, $grnId);
        self::recordMovement($itemId, $batchId, $quantity, 'received', $reference,
            $batchNo === null ? null : "Batch $batchNo", $userId);
        if ($unitPrice > 0) {
            Database::execute('UPDATE store_items SET cost_price = :p, updated_at = UTC_TIMESTAMP(3) WHERE id = :i',
                ['p' => $unitPrice, 'i' => $itemId]);
        }
    }

    // ─── Requisitions ────────────────────────────────────────────────────

    private const REQUISITION_SELECT = "SELECT r.*, d.name AS department_name,
                TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)) AS created_by_name,
                TRIM(CONCAT_WS(' ', du.first_name, du.last_name)) AS decided_by_name
           FROM store_requisitions r
           JOIN departments d ON d.id = r.department_id
           JOIN users cu ON cu.id = r.created_by
           LEFT JOIN users du ON du.id = r.decided_by";

    public static function listRequisitions(array $filters, AuthContext $ctx): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['mine'])) {
            $where[] = '(r.created_by = :me OR r.department_id = :dept)';
            $params['me'] = $ctx->userId;
            $params['dept'] = $ctx->departmentId ?? 0;
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'r.status = :status';
            $params['status'] = $filters['status'];
        } elseif (!empty($filters['open_only'])) {
            $where[] = "r.status IN ('submitted', 'approved', 'partly_issued')";
        }
        if (($filters['department_id'] ?? 0) > 0) {
            $where[] = 'r.department_id = :department';
            $params['department'] = (int) $filters['department_id'];
        }
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'r.created_at >= :from';
            $params['from'] = $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'r.created_at < :to';
            $params['to'] = $filters['to'];
        }
        $rows = Database::all(
            self::REQUISITION_SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY r.created_at DESC, r.id DESC LIMIT 300',
            $params
        );
        $requisitions = array_map(static fn (array $r) => self::presentRequisition($r), $rows);
        foreach ($requisitions as &$requisition) {
            $requisition['items'] = self::requisitionItems($requisition['id']);
        }
        unset($requisition);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return $requisitions;
        }
        $needle = mb_strtolower($search);
        return array_values(array_filter($requisitions, static fn (array $r) => str_contains(mb_strtolower($r['requisition_no']), $needle)
            || str_contains(mb_strtolower($r['department']), $needle)
            || str_contains(mb_strtolower($r['created_by']), $needle)));
    }

    private static function presentRequisition(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'requisition_no' => $r['requisition_no'],
            'department_id' => (int) $r['department_id'],
            'department' => $r['department_name'],
            'priority' => $r['priority'],
            'status' => $r['status'],
            'remarks' => $r['remarks'],
            'submitted_at' => Format::utc($r['submitted_at']),
            'decided_at' => Format::utc($r['decided_at']),
            'decided_by' => $r['decided_by_name'],
            'decision_reason' => $r['decision_reason'],
            'created_at' => Format::utc($r['created_at']),
            'created_by' => $r['created_by_name'],
            'row_version' => (int) $r['row_version'],
        ];
    }

    public static function requisitionItems(int $requisitionId): array
    {
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'item_id' => (int) $r['item_id'],
            'name' => $r['name'],
            'unit' => $r['unit'],
            'in_store' => (int) $r['in_store'],
            'qty_requested' => (int) $r['qty_requested'],
            'qty_approved' => $r['qty_approved'] === null ? null : (int) $r['qty_approved'],
            'qty_issued' => (int) $r['qty_issued'],
            'outstanding' => max(0, (int) ($r['qty_approved'] ?? $r['qty_requested']) - (int) $r['qty_issued']),
            'remarks' => $r['remarks'],
        ], Database::all(
            'SELECT ri.*, i.name, i.unit, i.quantity AS in_store
               FROM store_requisition_items ri JOIN store_items i ON i.id = ri.item_id
              WHERE ri.requisition_id = :r ORDER BY i.name',
            ['r' => $requisitionId]
        ));
    }

    public static function findRequisition(int $id): array
    {
        $row = Database::one(self::REQUISITION_SELECT . ' WHERE r.id = :id', ['id' => $id])
            ?? throw HttpException::notFound('Requisition not found.');
        return self::presentRequisition($row) + [
            'items' => self::requisitionItems($id),
            'issues' => self::issues($id),
        ];
    }

    public static function saveRequisition(?int $id, array $data, bool $submit, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $data, $submit, $ctx): array {
            if ($id === null) {
                $key = Format::localDateKey();
                $id = Database::insert(
                    'INSERT INTO store_requisitions (requisition_no, department_id, priority, status, remarks, submitted_at, created_at, created_by)
                     VALUES (:no, :dept, :priority, :status, :remarks, :submitted, UTC_TIMESTAMP(3), :by)',
                    [
                        'no' => sprintf('REQ-%s-%03d', $key, Sequence::next("requisition:$key")),
                        'dept' => $data['department_id'], 'priority' => $data['priority'],
                        'status' => $submit ? 'submitted' : 'draft', 'remarks' => $data['remarks'],
                        'submitted' => $submit ? gmdate('Y-m-d H:i:s') : null, 'by' => $ctx->userId,
                    ]
                );
            } else {
                $existing = Database::one('SELECT * FROM store_requisitions WHERE id = :id FOR UPDATE', ['id' => $id])
                    ?? throw HttpException::notFound('Requisition not found.');
                if (!in_array($existing['status'], ['draft', 'submitted'], true)) {
                    throw HttpException::conflict('requisition_state', 'This requisition can no longer be changed.');
                }
                if ((int) $existing['created_by'] !== $ctx->userId && !$ctx->can('requisitions.manage')) {
                    throw new HttpException(403, 'forbidden', 'Only the person who raised this requisition can change it.');
                }
                if ((int) $existing['row_version'] !== (int) ($data['row_version'] ?? 0)) {
                    throw HttpException::conflict('stale', 'Someone else changed this requisition. Reload and try again.');
                }
                Database::execute(
                    'UPDATE store_requisitions SET department_id = :dept, priority = :priority, remarks = :remarks,
                            status = :status, submitted_at = COALESCE(submitted_at, :submitted),
                            updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                      WHERE id = :id',
                    [
                        'dept' => $data['department_id'], 'priority' => $data['priority'], 'remarks' => $data['remarks'],
                        'status' => $submit ? 'submitted' : $existing['status'],
                        'submitted' => $submit ? gmdate('Y-m-d H:i:s') : null,
                        'by' => $ctx->userId, 'id' => $id,
                    ]
                );
            }
            Database::execute('DELETE FROM store_requisition_items WHERE requisition_id = :r', ['r' => $id]);
            foreach ($data['items'] as $line) {
                Database::execute(
                    'INSERT INTO store_requisition_items (requisition_id, item_id, qty_requested, remarks) VALUES (:r, :i, :q, :m)',
                    ['r' => $id, 'i' => $line['item_id'], 'q' => $line['quantity'], 'm' => $line['remarks'] ?? null]
                );
            }
            return self::findRequisition($id);
        });
    }

    /** The approver may cut quantities back; a reason is required if anything changes or is refused. */
    public static function decideRequisition(int $id, string $decision, array $lines, ?string $reason, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $decision, $lines, $reason, $ctx): array {
            $requisition = Database::one('SELECT * FROM store_requisitions WHERE id = :id FOR UPDATE', ['id' => $id])
                ?? throw HttpException::notFound('Requisition not found.');
            if ($requisition['status'] !== 'submitted') {
                throw HttpException::conflict('requisition_state', 'Only a submitted requisition can be decided.');
            }
            if ($decision === 'rejected') {
                if (($reason ?? '') === '') {
                    throw HttpException::validation(['reason' => 'Say why the requisition is refused.']);
                }
                Database::execute(
                    "UPDATE store_requisitions SET status = 'rejected', decided_at = UTC_TIMESTAMP(3), decided_by = :by,
                            decision_reason = :reason, updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
                      WHERE id = :id",
                    ['by' => $ctx->userId, 'by2' => $ctx->userId, 'reason' => $reason, 'id' => $id]
                );
                return self::findRequisition($id);
            }
            $items = [];
            foreach (Database::all('SELECT * FROM store_requisition_items WHERE requisition_id = :r', ['r' => $id]) as $item) {
                $items[(int) $item['id']] = $item;
            }
            $changed = false;
            $anyApproved = false;
            foreach ($lines as $line) {
                $item = $items[$line['item_id']] ?? throw HttpException::validation(['items' => 'A line is no longer on this requisition.']);
                $approved = max(0, min((int) $line['qty_approved'], (int) $item['qty_requested']));
                if ($approved !== (int) $item['qty_requested']) {
                    $changed = true;
                }
                if ($approved > 0) {
                    $anyApproved = true;
                }
                Database::execute('UPDATE store_requisition_items SET qty_approved = :q WHERE id = :id',
                    ['q' => $approved, 'id' => (int) $item['id']]);
            }
            if (!$anyApproved) {
                throw HttpException::validation(['items' => 'Approve at least one item, or refuse the requisition.']);
            }
            if ($changed && ($reason ?? '') === '') {
                throw HttpException::validation(['reason' => 'Say why less than was asked for is being approved.']);
            }
            Database::execute(
                "UPDATE store_requisitions SET status = 'approved', decided_at = UTC_TIMESTAMP(3), decided_by = :by,
                        decision_reason = :reason, updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
                  WHERE id = :id",
                ['by' => $ctx->userId, 'by2' => $ctx->userId, 'reason' => $reason, 'id' => $id]
            );
            return self::findRequisition($id);
        });
    }

    public static function issues(int $requisitionId): array
    {
        $rows = Database::all(
            "SELECT i.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS issued_by_name
               FROM store_issues i JOIN users u ON u.id = i.issued_by
              WHERE i.requisition_id = :r ORDER BY i.id",
            ['r' => $requisitionId]
        );
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'issue_no' => $r['issue_no'], 'remarks' => $r['remarks'],
            'issued_at' => Format::utc($r['issued_at']), 'issued_by' => $r['issued_by_name'],
            'items' => array_map(static fn (array $l) => [
                'item_id' => (int) $l['item_id'], 'name' => $l['name'], 'unit' => $l['unit'], 'quantity' => (int) $l['quantity'],
            ], Database::all(
                'SELECT ii.item_id, ii.quantity, si.name, si.unit FROM store_issue_items ii
                   JOIN store_items si ON si.id = ii.item_id WHERE ii.issue_id = :i ORDER BY si.name',
                ['i' => (int) $r['id']]
            )),
        ], $rows);
    }

    /**
     * Hands goods over. Each issue is its own note, so the rest can be issued later.
     * Medicine going to the pharmacy is added to the pharmacy's shelf.
     */
    public static function issue(int $requisitionId, array $lines, ?string $remarks, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($requisitionId, $lines, $remarks, $ctx): array {
            $requisition = Database::one('SELECT * FROM store_requisitions WHERE id = :id FOR UPDATE', ['id' => $requisitionId])
                ?? throw HttpException::notFound('Requisition not found.');
            if (!in_array($requisition['status'], ['approved', 'partly_issued'], true)) {
                throw HttpException::conflict('requisition_state', 'Only an approved requisition can be issued.');
            }
            $items = [];
            foreach (self::requisitionItems($requisitionId) as $item) {
                $items[$item['id']] = $item;
            }
            $giving = [];
            foreach ($lines as $line) {
                $item = $items[$line['item_id']] ?? throw HttpException::validation(['items' => 'A line is no longer on this requisition.']);
                $quantity = max(0, (int) $line['quantity']);
                if ($quantity === 0) {
                    continue;
                }
                if ($quantity > $item['outstanding']) {
                    throw HttpException::validation(["items.{$item['id']}" => "Only {$item['outstanding']} still to be issued for {$item['name']}."]);
                }
                $giving[] = ['line' => $item, 'quantity' => $quantity];
            }
            if ($giving === []) {
                throw HttpException::validation(['items' => 'Enter at least one quantity to issue.']);
            }
            $key = Format::localDateKey();
            $issueId = Database::insert(
                'INSERT INTO store_issues (issue_no, requisition_id, remarks, issued_at, issued_by)
                 VALUES (:no, :r, :remarks, UTC_TIMESTAMP(3), :by)',
                [
                    'no' => sprintf('ISS-%s-%03d', $key, Sequence::next("store_issue:$key")),
                    'r' => $requisitionId, 'remarks' => $remarks, 'by' => $ctx->userId,
                ]
            );
            $pharmacyDepartment = (string) Database::value('SELECT name FROM departments WHERE id = :d', ['d' => (int) $requisition['department_id']]);
            $toPharmacy = str_contains(mb_strtolower($pharmacyDepartment), 'pharmac');
            $toOptical = str_contains(mb_strtolower($pharmacyDepartment), 'optic');
            $issueNo = (string) Database::value('SELECT issue_no FROM store_issues WHERE id = :id', ['id' => $issueId]);

            foreach ($giving as $give) {
                $line = $give['line'];
                Database::execute(
                    'INSERT INTO store_issue_items (issue_id, item_id, quantity) VALUES (:i, :item, :q)',
                    ['i' => $issueId, 'item' => $line['item_id'], 'q' => $give['quantity']]
                );
                Database::execute(
                    'UPDATE store_requisition_items SET qty_issued = qty_issued + :q WHERE id = :id',
                    ['q' => $give['quantity'], 'id' => $line['id']]
                );
                self::takeFromBatches($line['item_id'], $give['quantity'], 'issued', $issueNo, $pharmacyDepartment, $ctx->userId);

                $drugId = Database::value('SELECT drug_id FROM store_items WHERE id = :i', ['i' => $line['item_id']]);
                if ($toPharmacy && $drugId !== null) {
                    PharmacyService::adjustStock((int) $drugId, $give['quantity'], 'received', "From the store, $issueNo", null, null, $ctx);
                }
                // Frames issued to the Optical Room go onto its frame stock.
                $frameId = Database::value('SELECT frame_id FROM store_items WHERE id = :i', ['i' => $line['item_id']]);
                if ($toOptical && $frameId !== null) {
                    FrameService::adjust((int) $frameId, $give['quantity'], 'received', $issueNo, 'From the store', $ctx->userId);
                }
            }
            $outstanding = (int) Database::value(
                'SELECT COALESCE(SUM(GREATEST(COALESCE(qty_approved, qty_requested) - qty_issued, 0)), 0)
                   FROM store_requisition_items WHERE requisition_id = :r',
                ['r' => $requisitionId]
            );
            Database::execute(
                'UPDATE store_requisitions SET status = :s, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                  WHERE id = :id',
                ['s' => $outstanding > 0 ? 'partly_issued' : 'issued', 'by' => $ctx->userId, 'id' => $requisitionId]
            );
            return self::findRequisition($requisitionId);
        });
    }
}
