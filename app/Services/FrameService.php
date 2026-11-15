<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuthContext;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Sequence;

/**
 * Spectacle frames and their stock in the Optical Room.
 *
 * Frames arrive from the store and leave when spectacles are handed over. A frame on an
 * order that is still open is set aside: it stays on the shelf and in the count, but it
 * cannot be put on another order. Every change to the count is recorded with a reason.
 */
final class FrameService
{
    /** Order statuses whose frame is still waiting to be handed over. */
    private const OPEN_ORDER = "o.status NOT IN ('dispensed', 'cancelled')";

    public static function list(bool $includeInactive = false): array
    {
        return array_map([self::class, 'present'], Database::all(
            'SELECT f.*, COALESCE(s.quantity, 0) AS quantity, COALESCE(s.reorder_level, 0) AS reorder_level,
                    (SELECT COUNT(*) FROM optical_order_items i JOIN optical_orders o ON o.id = i.order_id
                      WHERE i.frame_id = f.id AND ' . self::OPEN_ORDER . ') AS set_aside
               FROM frames f LEFT JOIN optical_frame_stock s ON s.frame_id = f.id'
            . ($includeInactive ? '' : ' WHERE f.is_active = 1') . ' ORDER BY f.name, f.code'
        ));
    }

    public static function find(int $id): array
    {
        $row = Database::one(
            'SELECT f.*, COALESCE(s.quantity, 0) AS quantity, COALESCE(s.reorder_level, 0) AS reorder_level,
                    (SELECT COUNT(*) FROM optical_order_items i JOIN optical_orders o ON o.id = i.order_id
                      WHERE i.frame_id = f.id AND ' . self::OPEN_ORDER . ') AS set_aside
               FROM frames f LEFT JOIN optical_frame_stock s ON s.frame_id = f.id WHERE f.id = :id',
            ['id' => $id]
        ) ?? throw HttpException::notFound('Frame not found.');
        return self::present($row);
    }

    private static function present(array $r): array
    {
        $quantity = (int) $r['quantity'];
        $setAside = (int) $r['set_aside'];
        return [
            'id' => (int) $r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'brand' => $r['brand'],
            'colour' => $r['colour'],
            'size' => $r['size'],
            'label' => self::label($r),
            'quantity' => $quantity,
            'set_aside' => $setAside,
            'available' => max(0, $quantity - $setAside),
            'reorder_level' => (int) $r['reorder_level'],
            'is_active' => (bool) $r['is_active'],
            'row_version' => (int) $r['row_version'],
        ];
    }

    /** "Classic 52 — Ray-Ban, black (FR-0001)": how a frame is named on orders and bills. */
    public static function label(array $r): string
    {
        $details = implode(', ', array_filter([$r['brand'] ?? null, $r['colour'] ?? null, $r['size'] ?? null]));
        return mb_substr($r['name'] . ($details !== '' ? " — $details" : '') . " ({$r['code']})", 0, 190);
    }

    public static function save(?int $id, array $data, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $data, $ctx): array {
            if ($id === null) {
                $id = Database::insert(
                    'INSERT INTO frames (code, name, brand, colour, size, created_at, created_by)
                     VALUES (:code, :name, :brand, :colour, :size, UTC_TIMESTAMP(3), :by)',
                    [
                        'code' => sprintf('FR-%04d', Sequence::next('frame')), 'name' => $data['name'], 'brand' => $data['brand'],
                        'colour' => $data['colour'], 'size' => $data['size'], 'by' => $ctx->userId,
                    ]
                );
                Database::execute(
                    'INSERT INTO optical_frame_stock (frame_id, quantity, reorder_level, updated_at, updated_by) VALUES (:f, 0, :r, UTC_TIMESTAMP(3), :by)',
                    ['f' => $id, 'r' => $data['reorder_level'], 'by' => $ctx->userId]
                );
            } else {
                $updated = Database::execute(
                    'UPDATE frames SET name = :name, brand = :brand, colour = :colour, size = :size, is_active = :active,
                            updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                      WHERE id = :id AND row_version = :version',
                    [
                        'name' => $data['name'], 'brand' => $data['brand'], 'colour' => $data['colour'], 'size' => $data['size'],
                        'active' => $data['is_active'] ? 1 : 0, 'by' => $ctx->userId, 'id' => $id, 'version' => $data['row_version'],
                    ]
                );
                if ($updated === 0) {
                    self::find($id);
                    throw HttpException::conflict('stale', 'Someone else changed this frame. Reload and try again.');
                }
                self::lockStock($id);
                Database::execute(
                    'UPDATE optical_frame_stock SET reorder_level = :r, updated_at = UTC_TIMESTAMP(3), updated_by = :by WHERE frame_id = :f',
                    ['r' => $data['reorder_level'], 'by' => $ctx->userId, 'f' => $id]
                );
            }
            return self::find($id);
        });
    }

    /** The stock row, created empty if it is missing, locked for the rest of the transaction. */
    private static function lockStock(int $frameId): array
    {
        $stock = Database::one('SELECT * FROM optical_frame_stock WHERE frame_id = :f FOR UPDATE', ['f' => $frameId]);
        if ($stock === null) {
            Database::execute('INSERT INTO optical_frame_stock (frame_id, quantity, updated_at) VALUES (:f, 0, UTC_TIMESTAMP(3))', ['f' => $frameId]);
            $stock = Database::one('SELECT * FROM optical_frame_stock WHERE frame_id = :f FOR UPDATE', ['f' => $frameId]);
        }
        return $stock;
    }

    /**
     * Changes the count and records why. Positive for frames received or returned, negative for
     * frames sold or written off; the count can never go below what is set aside on open orders
     * when it is being corrected by hand.
     */
    public static function adjust(int $frameId, int $change, string $reason, ?string $reference, ?string $note, int $userId): array
    {
        return Database::transaction(function () use ($frameId, $change, $reason, $reference, $note, $userId): array {
            if ($change === 0) {
                throw HttpException::validation(['change' => 'Enter how many to add or take off.']);
            }
            $stock = self::lockStock($frameId);
            $balance = (int) $stock['quantity'] + $change;
            if ($balance < 0) {
                throw HttpException::conflict('frame_stock', 'There are not enough of this frame in the Optical Room.');
            }
            if ($reason === 'adjusted' && $change < 0 && $balance < self::find($frameId)['set_aside']) {
                throw HttpException::conflict('frame_set_aside', 'Some of these frames are set aside for open orders; cancel or hand those over first.');
            }
            Database::execute(
                'UPDATE optical_frame_stock SET quantity = :q, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                  WHERE frame_id = :f',
                ['q' => $balance, 'by' => $userId, 'f' => $frameId]
            );
            Database::execute(
                'INSERT INTO optical_frame_movements (frame_id, change_qty, balance_after, reason, reference, note, created_at, created_by)
                 VALUES (:f, :c, :b, :r, :ref, :note, UTC_TIMESTAMP(3), :by)',
                ['f' => $frameId, 'c' => $change, 'b' => $balance, 'r' => $reason, 'ref' => $reference, 'note' => $note, 'by' => $userId]
            );
            return self::find($frameId);
        });
    }

    /** Throws unless enough of this frame are free to go on a new order. Call inside the order's transaction. */
    public static function assertAvailable(int $frameId, int $needed = 1): array
    {
        self::lockStock($frameId);
        $frame = self::find($frameId);
        if (!$frame['is_active']) {
            throw HttpException::validation(['frame_id' => 'This frame is no longer offered.']);
        }
        if ($frame['available'] < $needed) {
            throw HttpException::validation(['frame_id' => $needed > 1
                ? "Only {$frame['available']} {$frame['name']} frames are free, but $needed were chosen."
                : "No {$frame['name']} frame is free: {$frame['quantity']} in stock, {$frame['set_aside']} set aside for other orders."]);
        }
        return $frame;
    }

    /** The price of a frame for this customer, from the Price List. */
    public static function price(bool $externalCustomer): array
    {
        $code = $externalCustomer ? 'FRAME-EXTERNAL' : 'FRAME-INTERNAL';
        return Database::one('SELECT id, name, unit_price FROM price_items WHERE code = :c AND is_active = 1', ['c' => $code])
            ?? throw HttpException::conflict('price_not_configured', "There is no active price for $code. An administrator must set it in the Price List.");
    }

    public static function movements(int $frameId): array
    {
        return array_map(static fn (array $r) => [
            'change_qty' => (int) $r['change_qty'], 'balance_after' => (int) $r['balance_after'], 'reason' => $r['reason'],
            'reference' => $r['reference'], 'note' => $r['note'], 'created_at' => Format::utc($r['created_at']),
            'created_by' => $r['created_by_name'],
        ], Database::all(
            "SELECT m.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS created_by_name
               FROM optical_frame_movements m JOIN users u ON u.id = m.created_by
              WHERE m.frame_id = :f ORDER BY m.id DESC LIMIT 50",
            ['f' => $frameId]
        ));
    }
}
