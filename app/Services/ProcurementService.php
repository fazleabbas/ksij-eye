<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuthContext;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Sequence;

/**
 * Buying for the store: quotations from suppliers, the purchase order, and goods coming in.
 *
 * A purchase order needs two approvals — administration then the store manager — and the same
 * person may not give both. Stock only moves when a goods received note is approved, and each
 * delivery brings its own batch number and expiry.
 */
final class ProcurementService
{
    // ─── Suppliers ───────────────────────────────────────────────────────

    public static function suppliers(bool $includeInactive = false): array
    {
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'contact_name' => $r['contact_name'],
            'phone' => $r['phone'], 'email' => $r['email'], 'address' => $r['address'],
            'is_preferred' => (bool) $r['is_preferred'], 'is_active' => (bool) $r['is_active'],
            'row_version' => (int) $r['row_version'],
        ], Database::all(
            'SELECT * FROM suppliers' . ($includeInactive ? '' : ' WHERE is_active = 1') . ' ORDER BY is_preferred DESC, name'
        ));
    }

    public static function saveSupplier(?int $id, array $data, AuthContext $ctx): array
    {
        $values = [
            'name' => $data['name'], 'contact' => $data['contact_name'], 'phone' => $data['phone'],
            'email' => $data['email'], 'address' => $data['address'],
            'preferred' => !empty($data['is_preferred']) ? 1 : 0, 'by' => $ctx->userId,
        ];
        if ($id === null) {
            $id = Database::insert(
                'INSERT INTO suppliers (name, contact_name, phone, email, address, is_preferred, created_at, created_by)
                 VALUES (:name, :contact, :phone, :email, :address, :preferred, UTC_TIMESTAMP(3), :by)',
                $values
            );
        } else {
            $existing = Database::one('SELECT row_version FROM suppliers WHERE id = :id', ['id' => $id])
                ?? throw HttpException::notFound('Supplier not found.');
            if ((int) $existing['row_version'] !== (int) ($data['row_version'] ?? 0)) {
                throw HttpException::conflict('stale', 'Someone else changed this supplier. Reload and try again.');
            }
            Database::execute(
                'UPDATE suppliers SET name = :name, contact_name = :contact, phone = :phone, email = :email, address = :address,
                        is_preferred = :preferred, is_active = :active, updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                        row_version = row_version + 1
                  WHERE id = :id',
                $values + ['active' => !empty($data['is_active']) ? 1 : 0, 'id' => $id]
            );
        }
        $row = Database::one('SELECT * FROM suppliers WHERE id = :id', ['id' => $id]);
        return [
            'id' => (int) $row['id'], 'name' => $row['name'], 'contact_name' => $row['contact_name'],
            'phone' => $row['phone'], 'email' => $row['email'], 'address' => $row['address'],
            'is_preferred' => (bool) $row['is_preferred'], 'is_active' => (bool) $row['is_active'],
            'row_version' => (int) $row['row_version'],
        ];
    }

    // ─── Quotations ──────────────────────────────────────────────────────

    public static function quotations(array $filters): array
    {
        $where = [];
        $params = [];
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'q.status = :status';
            $params['status'] = $filters['status'];
        }
        if (($filters['supplier_id'] ?? 0) > 0) {
            $where[] = 'q.supplier_id = :supplier';
            $params['supplier'] = (int) $filters['supplier_id'];
        }
        $rows = Database::all(
            "SELECT q.*, s.name AS supplier_name, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS created_by_name,
                    (SELECT COUNT(*) FROM store_purchase_orders o WHERE o.quotation_id = q.id) AS order_count
               FROM store_quotations q
               JOIN suppliers s ON s.id = q.supplier_id
               JOIN users u ON u.id = q.created_by"
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY q.created_at DESC LIMIT 300',
            $params
        );
        $quotations = array_map(static fn (array $r) => self::presentQuotation($r), $rows);
        foreach ($quotations as &$quotation) {
            $quotation['items'] = self::quotationItems($quotation['id']);
        }
        unset($quotation);
        return $quotations;
    }

    private static function presentQuotation(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'quotation_no' => $r['quotation_no'],
            'supplier' => ['id' => (int) $r['supplier_id'], 'name' => $r['supplier_name']],
            'quoted_on' => $r['quoted_on'],
            'valid_until' => $r['valid_until'],
            'status' => $r['status'],
            'total_amount' => (float) $r['total_amount'],
            'remarks' => $r['remarks'],
            'has_order' => (int) $r['order_count'] > 0,
            'created_at' => Format::utc($r['created_at']),
            'created_by' => $r['created_by_name'],
            'row_version' => (int) $r['row_version'],
        ];
    }

    public static function quotationItems(int $quotationId): array
    {
        return array_map(static fn (array $r) => [
            'item_id' => (int) $r['item_id'], 'name' => $r['name'], 'unit' => $r['unit'],
            'quantity' => (int) $r['quantity'], 'unit_price' => (float) $r['unit_price'],
            'tax_percent' => (float) $r['tax_percent'], 'line_total' => (float) $r['line_total'],
            'remarks' => $r['remarks'],
        ], Database::all(
            'SELECT qi.*, i.name, i.unit FROM store_quotation_items qi JOIN store_items i ON i.id = qi.item_id
              WHERE qi.quotation_id = :q ORDER BY i.name',
            ['q' => $quotationId]
        ));
    }

    public static function findQuotation(int $id): array
    {
        $row = Database::one(
            "SELECT q.*, s.name AS supplier_name, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS created_by_name,
                    (SELECT COUNT(*) FROM store_purchase_orders o WHERE o.quotation_id = q.id) AS order_count
               FROM store_quotations q JOIN suppliers s ON s.id = q.supplier_id JOIN users u ON u.id = q.created_by
              WHERE q.id = :id",
            ['id' => $id]
        ) ?? throw HttpException::notFound('Quotation not found.');
        return self::presentQuotation($row) + ['items' => self::quotationItems($id)];
    }

    public static function saveQuotation(?int $id, array $data, bool $submit, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $data, $submit, $ctx): array {
            if ($id === null) {
                $key = Format::localDateKey();
                $id = Database::insert(
                    'INSERT INTO store_quotations (quotation_no, supplier_id, quoted_on, valid_until, status, remarks, created_at, created_by)
                     VALUES (:no, :supplier, :quoted, :valid, :status, :remarks, UTC_TIMESTAMP(3), :by)',
                    [
                        'no' => sprintf('QUO-%s-%03d', $key, Sequence::next("quotation:$key")),
                        'supplier' => $data['supplier_id'], 'quoted' => $data['quoted_on'], 'valid' => $data['valid_until'],
                        'status' => $submit ? 'under_review' : 'draft', 'remarks' => $data['remarks'], 'by' => $ctx->userId,
                    ]
                );
            } else {
                $existing = Database::one('SELECT * FROM store_quotations WHERE id = :id FOR UPDATE', ['id' => $id])
                    ?? throw HttpException::notFound('Quotation not found.');
                if (!in_array($existing['status'], ['draft', 'under_review'], true)) {
                    throw HttpException::conflict('quotation_state', 'This quotation can no longer be changed.');
                }
                if ((int) $existing['row_version'] !== (int) ($data['row_version'] ?? 0)) {
                    throw HttpException::conflict('stale', 'Someone else changed this quotation. Reload and try again.');
                }
                Database::execute(
                    'UPDATE store_quotations SET supplier_id = :supplier, quoted_on = :quoted, valid_until = :valid,
                            status = :status, remarks = :remarks, updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                            row_version = row_version + 1
                      WHERE id = :id',
                    [
                        'supplier' => $data['supplier_id'], 'quoted' => $data['quoted_on'], 'valid' => $data['valid_until'],
                        'status' => $submit ? 'under_review' : $existing['status'], 'remarks' => $data['remarks'],
                        'by' => $ctx->userId, 'id' => $id,
                    ]
                );
            }
            Database::execute('DELETE FROM store_quotation_items WHERE quotation_id = :q', ['q' => $id]);
            foreach ($data['items'] as $line) {
                Database::execute(
                    'INSERT INTO store_quotation_items (quotation_id, item_id, quantity, unit_price, tax_percent, remarks)
                     VALUES (:q, :i, :qty, :price, :tax, :remarks)',
                    [
                        'q' => $id, 'i' => $line['item_id'], 'qty' => $line['quantity'],
                        'price' => $line['unit_price'], 'tax' => $line['tax_percent'] ?? 0, 'remarks' => $line['remarks'] ?? null,
                    ]
                );
            }
            self::refreshQuotationTotal($id);
            return self::findQuotation($id);
        });
    }

    private static function refreshQuotationTotal(int $id): void
    {
        Database::execute(
            'UPDATE store_quotations SET total_amount = COALESCE((SELECT SUM(line_total) FROM store_quotation_items WHERE quotation_id = :q), 0)
              WHERE id = :id',
            ['q' => $id, 'id' => $id]
        );
    }

    /** Picks the supplier to buy from. Any other quotation under review is refused. */
    public static function selectQuotation(int $id, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $ctx): array {
            $quotation = Database::one('SELECT * FROM store_quotations WHERE id = :id FOR UPDATE', ['id' => $id])
                ?? throw HttpException::notFound('Quotation not found.');
            if ($quotation['status'] !== 'under_review') {
                throw HttpException::conflict('quotation_state', 'Only a quotation under review can be chosen.');
            }
            Database::execute(
                "UPDATE store_quotations SET status = 'selected', updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                        row_version = row_version + 1 WHERE id = :id",
                ['by' => $ctx->userId, 'id' => $id]
            );
            return self::findQuotation($id);
        });
    }

    /** Side-by-side prices for everything currently under review. */
    public static function comparison(): array
    {
        $quotations = array_values(array_filter(self::quotations([]), static fn (array $q) => in_array($q['status'], ['under_review', 'selected'], true)));
        $items = [];
        foreach ($quotations as $quotation) {
            foreach ($quotation['items'] as $line) {
                $items[$line['item_id']] ??= ['item_id' => $line['item_id'], 'name' => $line['name'], 'unit' => $line['unit'], 'prices' => []];
                $items[$line['item_id']]['prices'][$quotation['id']] = [
                    'unit_price' => $line['unit_price'], 'quantity' => $line['quantity'], 'line_total' => $line['line_total'],
                ];
            }
        }
        foreach ($items as &$item) {
            $prices = array_column($item['prices'], 'unit_price');
            $item['cheapest'] = $prices === [] ? null : min($prices);
        }
        unset($item);
        $totals = array_map(static fn (array $q) => ['quotation_id' => $q['id'], 'total' => $q['total_amount']], $quotations);
        return [
            'quotations' => array_map(static fn (array $q) => [
                'id' => $q['id'], 'quotation_no' => $q['quotation_no'], 'supplier' => $q['supplier']['name'],
                'status' => $q['status'], 'total_amount' => $q['total_amount'],
            ], $quotations),
            'items' => array_values($items),
            'cheapest_total' => $totals === [] ? null : min(array_column($totals, 'total')),
        ];
    }

    // ─── Purchase orders ─────────────────────────────────────────────────

    private const ORDER_SELECT = "SELECT o.*, s.name AS supplier_name, q.quotation_no,
                TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)) AS created_by_name,
                TRIM(CONCAT_WS(' ', au.first_name, au.last_name)) AS admin_approved_by_name,
                TRIM(CONCAT_WS(' ', su.first_name, su.last_name)) AS store_approved_by_name
           FROM store_purchase_orders o
           JOIN suppliers s ON s.id = o.supplier_id
           JOIN users cu ON cu.id = o.created_by
           LEFT JOIN store_quotations q ON q.id = o.quotation_id
           LEFT JOIN users au ON au.id = o.admin_approved_by
           LEFT JOIN users su ON su.id = o.store_approved_by";

    public static function orders(array $filters): array
    {
        $where = [];
        $params = [];
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'o.status = :status';
            $params['status'] = $filters['status'];
        }
        if (($filters['supplier_id'] ?? 0) > 0) {
            $where[] = 'o.supplier_id = :supplier';
            $params['supplier'] = (int) $filters['supplier_id'];
        }
        $rows = Database::all(
            self::ORDER_SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY o.created_at DESC LIMIT 300',
            $params
        );
        $orders = array_map(static fn (array $r) => self::presentOrder($r), $rows);
        foreach ($orders as &$order) {
            $order['items'] = self::orderItems($order['id']);
        }
        unset($order);
        return $orders;
    }

    private static function presentOrder(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'order_no' => $r['order_no'],
            'quotation_id' => $r['quotation_id'] === null ? null : (int) $r['quotation_id'],
            'quotation_no' => $r['quotation_no'],
            'supplier' => ['id' => (int) $r['supplier_id'], 'name' => $r['supplier_name']],
            'expected_delivery' => $r['expected_delivery'],
            'status' => $r['status'],
            'total_amount' => (float) $r['total_amount'],
            'remarks' => $r['remarks'],
            'admin_approved_at' => Format::utc($r['admin_approved_at']),
            'admin_approved_by' => $r['admin_approved_by_name'],
            'admin_approved_by_id' => $r['admin_approved_by'] === null ? null : (int) $r['admin_approved_by'],
            'store_approved_at' => Format::utc($r['store_approved_at']),
            'store_approved_by' => $r['store_approved_by_name'],
            'sent_at' => Format::utc($r['sent_at']),
            'closed_at' => Format::utc($r['closed_at']),
            'created_at' => Format::utc($r['created_at']),
            'created_by' => $r['created_by_name'],
            'row_version' => (int) $r['row_version'],
        ];
    }

    public static function orderItems(int $orderId): array
    {
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'item_id' => (int) $r['item_id'], 'name' => $r['name'], 'unit' => $r['unit'],
            'quantity' => (int) $r['quantity'], 'unit_price' => (float) $r['unit_price'],
            'tax_percent' => (float) $r['tax_percent'],
            'qty_received' => (int) $r['qty_received'], 'outstanding' => max(0, (int) $r['quantity'] - (int) $r['qty_received']),
            'line_total' => (float) $r['line_total'], 'remarks' => $r['remarks'],
        ], Database::all(
            'SELECT oi.*, i.name, i.unit FROM store_purchase_order_items oi JOIN store_items i ON i.id = oi.item_id
              WHERE oi.order_id = :o ORDER BY i.name',
            ['o' => $orderId]
        ));
    }

    public static function findOrder(int $id): array
    {
        $row = Database::one(self::ORDER_SELECT . ' WHERE o.id = :id', ['id' => $id])
            ?? throw HttpException::notFound('Purchase order not found.');
        return self::presentOrder($row) + [
            'items' => self::orderItems($id),
            'deliveries' => self::deliveries($id),
        ];
    }

    /** Turns the chosen quotation into an order waiting for approval. */
    public static function orderFromQuotation(int $quotationId, ?string $expectedDelivery, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($quotationId, $expectedDelivery, $ctx): array {
            $quotation = Database::one('SELECT * FROM store_quotations WHERE id = :id FOR UPDATE', ['id' => $quotationId])
                ?? throw HttpException::notFound('Quotation not found.');
            if ($quotation['status'] !== 'selected') {
                throw HttpException::conflict('quotation_state', 'Choose the quotation first.');
            }
            if (Database::value('SELECT 1 FROM store_purchase_orders WHERE quotation_id = :q', ['q' => $quotationId])) {
                throw HttpException::conflict('already_ordered', 'An order has already been raised from this quotation.');
            }
            $key = Format::localDateKey();
            $orderId = Database::insert(
                'INSERT INTO store_purchase_orders (order_no, quotation_id, supplier_id, expected_delivery, status, remarks,
                                                    created_at, created_by)
                 VALUES (:no, :quotation, :supplier, :expected, :status, :remarks, UTC_TIMESTAMP(3), :by)',
                [
                    'no' => sprintf('LPO-%s-%03d', $key, Sequence::next("purchase_order:$key")),
                    'quotation' => $quotationId, 'supplier' => (int) $quotation['supplier_id'],
                    'expected' => $expectedDelivery, 'status' => 'pending_admin',
                    'remarks' => $quotation['remarks'], 'by' => $ctx->userId,
                ]
            );
            foreach (self::quotationItems($quotationId) as $line) {
                Database::execute(
                    'INSERT INTO store_purchase_order_items (order_id, item_id, quantity, unit_price, tax_percent)
                     VALUES (:o, :i, :q, :p, :tax)',
                    [
                        'o' => $orderId, 'i' => $line['item_id'], 'q' => $line['quantity'],
                        'p' => $line['unit_price'], 'tax' => $line['tax_percent'],
                    ]
                );
            }
            self::refreshOrderTotal($orderId);
            return self::findOrder($orderId);
        });
    }

    private static function refreshOrderTotal(int $id): void
    {
        Database::execute(
            'UPDATE store_purchase_orders SET total_amount = COALESCE((SELECT SUM(line_total) FROM store_purchase_order_items WHERE order_id = :o), 0)
              WHERE id = :id',
            ['o' => $id, 'id' => $id]
        );
    }

    /** Administration approves, then the store manager — and never the same person twice. */
    public static function approveOrder(int $id, string $step, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $step, $ctx): array {
            $order = Database::one('SELECT * FROM store_purchase_orders WHERE id = :id FOR UPDATE', ['id' => $id])
                ?? throw HttpException::notFound('Purchase order not found.');
            if ($step === 'admin') {
                if ($order['status'] !== 'pending_admin') {
                    throw HttpException::conflict('order_state', 'This order is not waiting for administration approval.');
                }
                Database::execute(
                    "UPDATE store_purchase_orders SET status = 'pending_store', admin_approved_at = UTC_TIMESTAMP(3),
                            admin_approved_by = :by, updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
                      WHERE id = :id",
                    ['by' => $ctx->userId, 'by2' => $ctx->userId, 'id' => $id]
                );
            } else {
                if ($order['status'] !== 'pending_store') {
                    throw HttpException::conflict('order_state', 'This order is not waiting for the store manager.');
                }
                if ((int) $order['admin_approved_by'] === $ctx->userId) {
                    throw new HttpException(403, 'self_approval', 'You gave the first approval, so someone else must give the second.');
                }
                Database::execute(
                    "UPDATE store_purchase_orders SET status = 'approved', store_approved_at = UTC_TIMESTAMP(3),
                            store_approved_by = :by, updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
                      WHERE id = :id",
                    ['by' => $ctx->userId, 'by2' => $ctx->userId, 'id' => $id]
                );
            }
            return self::findOrder($id);
        });
    }

    public static function markOrderSent(int $id, AuthContext $ctx): array
    {
        $order = Database::one('SELECT status FROM store_purchase_orders WHERE id = :id', ['id' => $id])
            ?? throw HttpException::notFound('Purchase order not found.');
        if ($order['status'] !== 'approved') {
            throw HttpException::conflict('order_state', 'Only an approved order can be sent to the supplier.');
        }
        Database::execute(
            "UPDATE store_purchase_orders SET status = 'sent', sent_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3),
                    updated_by = :by, row_version = row_version + 1 WHERE id = :id",
            ['by' => $ctx->userId, 'id' => $id]
        );
        return self::findOrder($id);
    }

    public static function cancelOrder(int $id, string $reason, AuthContext $ctx): array
    {
        $order = Database::one('SELECT status FROM store_purchase_orders WHERE id = :id', ['id' => $id])
            ?? throw HttpException::notFound('Purchase order not found.');
        if (in_array($order['status'], ['closed', 'cancelled'], true)) {
            throw HttpException::conflict('order_state', 'This order is already finished.');
        }
        Database::execute(
            "UPDATE store_purchase_orders SET status = 'cancelled', remarks = CONCAT_WS(' · ', remarks, :reason),
                    updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id",
            ['reason' => 'Cancelled: ' . $reason, 'by' => $ctx->userId, 'id' => $id]
        );
        return self::findOrder($id);
    }

    // ─── Goods received ──────────────────────────────────────────────────

    public static function deliveries(?int $orderId = null, array $filters = []): array
    {
        $where = [];
        $params = [];
        if ($orderId !== null) {
            $where[] = 'g.order_id = :order';
            $params['order'] = $orderId;
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'g.status = :status';
            $params['status'] = $filters['status'];
        }
        $rows = Database::all(
            "SELECT g.*, o.order_no, s.name AS supplier_name,
                    TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)) AS created_by_name,
                    TRIM(CONCAT_WS(' ', vu.first_name, vu.last_name)) AS verified_by_name,
                    TRIM(CONCAT_WS(' ', au.first_name, au.last_name)) AS approved_by_name
               FROM store_goods_received g
               JOIN store_purchase_orders o ON o.id = g.order_id
               JOIN suppliers s ON s.id = o.supplier_id
               JOIN users cu ON cu.id = g.created_by
               LEFT JOIN users vu ON vu.id = g.verified_by
               LEFT JOIN users au ON au.id = g.approved_by"
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY g.received_on DESC, g.id DESC LIMIT 300',
            $params
        );
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'grn_no' => $r['grn_no'],
            'order_id' => (int) $r['order_id'],
            'order_no' => $r['order_no'],
            'supplier' => $r['supplier_name'],
            'received_on' => $r['received_on'],
            'status' => $r['status'],
            'delivery_note' => $r['delivery_note'],
            'remarks' => $r['remarks'],
            'verified_at' => Format::utc($r['verified_at']),
            'verified_by' => $r['verified_by_name'],
            'verified_by_id' => $r['verified_by'] === null ? null : (int) $r['verified_by'],
            'approved_at' => Format::utc($r['approved_at']),
            'approved_by' => $r['approved_by_name'],
            'created_at' => Format::utc($r['created_at']),
            'created_by' => $r['created_by_name'],
            'row_version' => (int) $r['row_version'],
            'items' => self::deliveryItems((int) $r['id']),
        ], $rows);
    }

    public static function deliveryItems(int $grnId): array
    {
        return array_map(static fn (array $r) => [
            'item_id' => (int) $r['item_id'], 'name' => $r['name'], 'unit' => $r['unit'],
            'quantity' => (int) $r['quantity'], 'rejected_qty' => (int) $r['rejected_qty'],
            'batch_no' => $r['batch_no'], 'expiry_date' => $r['expiry_date'],
            'unit_price' => (float) $r['unit_price'], 'remarks' => $r['remarks'],
        ], Database::all(
            'SELECT gi.*, i.name, i.unit FROM store_goods_received_items gi JOIN store_items i ON i.id = gi.item_id
              WHERE gi.grn_id = :g ORDER BY i.name',
            ['g' => $grnId]
        ));
    }

    public static function findDelivery(int $id): array
    {
        $rows = self::deliveries(null, []);
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        throw HttpException::notFound('Goods received note not found.');
    }

    /** Records what arrived. Stock does not move until the note is approved. */
    public static function receive(int $orderId, array $data, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($orderId, $data, $ctx): array {
            $order = Database::one('SELECT * FROM store_purchase_orders WHERE id = :id FOR UPDATE', ['id' => $orderId])
                ?? throw HttpException::notFound('Purchase order not found.');
            if (!in_array($order['status'], ['approved', 'sent'], true)) {
                throw HttpException::conflict('order_state', 'Goods can only be received against an approved order.');
            }
            $lines = [];
            foreach (self::orderItems($orderId) as $line) {
                $lines[$line['item_id']] = $line;
            }
            $received = [];
            foreach ($data['items'] as $row) {
                $line = $lines[(int) $row['item_id']] ?? throw HttpException::validation(['items' => 'That item is not on this order.']);
                $quantity = max(0, (int) $row['quantity']);
                if ($quantity === 0) {
                    continue;
                }
                if ($quantity > $line['outstanding']) {
                    throw HttpException::validation(["items.{$line['item_id']}" => "Only {$line['outstanding']} of {$line['name']} is still outstanding."]);
                }
                $received[] = [
                    'item_id' => $line['item_id'], 'quantity' => $quantity,
                    'rejected_qty' => max(0, (int) ($row['rejected_qty'] ?? 0)),
                    'batch_no' => $row['batch_no'] ?? null, 'expiry_date' => $row['expiry_date'] ?? null,
                    'unit_price' => $row['unit_price'] ?? $line['unit_price'], 'remarks' => $row['remarks'] ?? null,
                ];
            }
            if ($received === []) {
                throw HttpException::validation(['items' => 'Record at least one quantity received.']);
            }
            $key = Format::localDateKey();
            $grnId = Database::insert(
                'INSERT INTO store_goods_received (grn_no, order_id, received_on, status, delivery_note, remarks, created_at, created_by)
                 VALUES (:no, :o, :received, :status, :note, :remarks, UTC_TIMESTAMP(3), :by)',
                [
                    'no' => sprintf('GRN-%s-%03d', $key, Sequence::next("grn:$key")),
                    'o' => $orderId, 'received' => $data['received_on'], 'status' => 'recorded',
                    'note' => $data['delivery_note'], 'remarks' => $data['remarks'], 'by' => $ctx->userId,
                ]
            );
            foreach ($received as $line) {
                Database::execute(
                    'INSERT INTO store_goods_received_items (grn_id, item_id, quantity, rejected_qty, batch_no, expiry_date, unit_price, remarks)
                     VALUES (:g, :i, :q, :rejected, :batch, :expiry, :price, :remarks)',
                    [
                        'g' => $grnId, 'i' => $line['item_id'], 'q' => $line['quantity'], 'rejected' => $line['rejected_qty'],
                        'batch' => $line['batch_no'], 'expiry' => $line['expiry_date'], 'price' => $line['unit_price'],
                        'remarks' => $line['remarks'],
                    ]
                );
            }
            return self::findDelivery($grnId);
        });
    }

    /** Checked by one person, approved by another; stock moves on approval. */
    public static function decideDelivery(int $id, string $step, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $step, $ctx): array {
            $grn = Database::one('SELECT * FROM store_goods_received WHERE id = :id FOR UPDATE', ['id' => $id])
                ?? throw HttpException::notFound('Goods received note not found.');
            if ($step === 'verify') {
                if ($grn['status'] !== 'recorded') {
                    throw HttpException::conflict('grn_state', 'This note has already been checked.');
                }
                Database::execute(
                    "UPDATE store_goods_received SET status = 'verified', verified_at = UTC_TIMESTAMP(3), verified_by = :by,
                            row_version = row_version + 1 WHERE id = :id",
                    ['by' => $ctx->userId, 'id' => $id]
                );
                return self::findDelivery($id);
            }
            if ($grn['status'] !== 'verified') {
                throw HttpException::conflict('grn_state', 'The note has to be checked before it is approved.');
            }
            Database::execute(
                "UPDATE store_goods_received SET status = 'approved', approved_at = UTC_TIMESTAMP(3), approved_by = :by,
                        row_version = row_version + 1 WHERE id = :id",
                ['by' => $ctx->userId, 'id' => $id]
            );
            // Now the goods go on the shelf, each delivery as its own batch.
            foreach (self::deliveryItems($id) as $line) {
                StoreService::receiveIntoStock(
                    $line['item_id'], $line['quantity'], $line['batch_no'], $line['expiry_date'],
                    $id, (string) $grn['grn_no'], $line['unit_price'], $ctx->userId
                );
                Database::execute(
                    'UPDATE store_purchase_order_items SET qty_received = qty_received + :q WHERE order_id = :o AND item_id = :i',
                    ['q' => $line['quantity'], 'o' => (int) $grn['order_id'], 'i' => $line['item_id']]
                );
            }
            $outstanding = (int) Database::value(
                'SELECT COALESCE(SUM(GREATEST(quantity - qty_received, 0)), 0) FROM store_purchase_order_items WHERE order_id = :o',
                ['o' => (int) $grn['order_id']]
            );
            if ($outstanding === 0) {
                Database::execute(
                    "UPDATE store_purchase_orders SET status = 'closed', closed_at = UTC_TIMESTAMP(3),
                            updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id",
                    ['by' => $ctx->userId, 'id' => (int) $grn['order_id']]
                );
            }
            return self::findDelivery($id);
        });
    }
}
