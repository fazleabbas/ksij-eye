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
use App\Services\PharmacyService;

/** Pharmacy: the order queue, dispensing, refills, walk-in sales and stock. */
final class PharmacyController
{
    public function options(): array
    {
        return [
            'drugs' => array_map(static fn (array $r) => [
                'id' => (int) $r['id'], 'name' => $r['name'], 'category' => $r['category'],
                'unit_price' => (float) $r['unit_price'], 'in_stock' => (int) $r['quantity'],
            ], Database::all(
                'SELECT d.id, d.name, d.category, d.unit_price, COALESCE(s.quantity, 0) AS quantity
                   FROM drugs d LEFT JOIN pharmacy_stock s ON s.drug_id = d.id
                  WHERE d.is_active = 1 ORDER BY d.name'
            )),
            'categories' => array_column(Database::all(
                "SELECT DISTINCT category FROM drugs WHERE is_active = 1 AND category <> '' ORDER BY category"
            ), 'category'),
        ];
    }

    // ─── Orders ──────────────────────────────────────────────────────────

    public function index(Request $request): array
    {
        $ctx = Auth::require();
        $scope = in_array($request->query('scope'), ['queue', 'dispensing', 'partial', 'completed'], true)
            ? $request->query('scope') : 'queue';
        $orders = PharmacyService::listOrders($scope, [
            'search' => (string) $request->query('search'),
            'status' => (string) $request->query('status'),
            'source' => (string) $request->query('source'),
            'from' => $this->boundary($request->query('from'), false),
            'to' => $this->boundary($request->query('to'), true),
        ], $ctx->userId);
        return ['orders' => $orders, 'count' => count($orders)];
    }

    public function show(Request $request, array $params): array
    {
        return ['order' => PharmacyService::show((int) $params['id'], Auth::require())];
    }

    /** The pharmacy says what it can supply; that raises the bill. */
    public function review(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $order = PharmacyService::review((int) $params['id'], $this->lines($request), $ctx);
        Audit::log('pharmacy', 'pharmacy.reviewed',
            "Reviewed {$order['order_no']} — bill {$order['bill']['bill_no']}", 'pharmacy_order', $order['order_no']);
        return ['order' => $order];
    }

    public function dispense(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('notes', false, 500)->bool('refill')->validate();
        $refill = !empty($v['refill']);
        $order = PharmacyService::dispense((int) $params['id'], $this->lines($request), $v['notes'] ?? null, $refill, $ctx);
        Audit::log('pharmacy', $refill ? 'pharmacy.refilled' : 'pharmacy.dispensed',
            ($refill ? 'Refilled ' : 'Dispensed ') . $order['order_no'], 'pharmacy_order', $order['order_no']);
        return ['order' => $order];
    }

    /** Stops supplying the rest; a paid remainder raises a refund request. */
    public function cancelRemaining(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('reason', true, 500, 5)->validate();
        $order = PharmacyService::cancelRemaining((int) $params['id'], $v['reason'], $ctx);
        Audit::log('pharmacy', 'pharmacy.cancelled',
            "Cancelled what was left of {$order['order_no']}" . ($order['refund'] ? " — refund {$order['refund']['refund_no']}" : ''),
            'pharmacy_order', $order['order_no']);
        return ['order' => $order];
    }

    // ─── Walk-in sales ───────────────────────────────────────────────────

    public function walkInSale(Request $request): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->string('name', true, 160, 2)
            ->string('phone', true, 20, 9)
            ->enum('gender', ['male', 'female'], false)
            ->int('age', false, 0, 120)
            ->validate();
        $body = $request->all();
        $lines = [];
        foreach (is_array($body['items'] ?? null) ? $body['items'] : [] as $index => $row) {
            $quantity = (int) ($row['quantity'] ?? 0);
            $price = (float) ($row['unit_price'] ?? -1);
            if ($quantity < 1) {
                throw HttpException::validation(["items.$index.quantity" => 'Enter how many are being sold.']);
            }
            if ($price < 0) {
                throw HttpException::validation(["items.$index.unit_price" => 'Enter a price of zero or more.']);
            }
            $lines[] = ['drug_id' => (int) ($row['drug_id'] ?? 0), 'quantity' => $quantity, 'unit_price' => round($price, 2)];
        }
        if ($lines === []) {
            throw HttpException::validation(['items' => 'Add at least one medicine.']);
        }
        $order = PharmacyService::walkInSale([
            'name' => $v['name'], 'phone' => $v['phone'],
            'gender' => $v['gender'] ?? null, 'age' => $v['age'] ?? null,
        ], $lines, $ctx);
        // The price may be set at the counter, so record what was charged against the catalogue price.
        $changed = array_values(array_filter(
            PharmacyService::items($order['id']),
            static fn (array $i) => abs($i['unit_price'] - $i['catalogue_price']) > 0.001
        ));
        Audit::log('pharmacy', 'pharmacy.walk_in_sale',
            "Counter sale {$order['order_no']} — " . number_format($order['total_amount']) . ' TZS',
            'pharmacy_order', $order['order_no'],
            $changed === [] ? null : ['prices_changed' => array_map(
                static fn (array $i) => ['item' => $i['description'], 'from' => $i['catalogue_price'], 'to' => $i['unit_price']],
                $changed
            )]);
        return ['order' => $order];
    }

    // ─── Stock ───────────────────────────────────────────────────────────

    public function stock(Request $request): array
    {
        $items = PharmacyService::stock([
            'search' => (string) $request->query('search'),
            'category' => (string) $request->query('category'),
            'status' => (string) $request->query('status'),
        ]);
        $counts = ['in_stock' => 0, 'low_stock' => 0, 'out_of_stock' => 0];
        foreach ($items as $item) {
            $counts[$item['stock_status']]++;
        }
        return ['items' => $items, 'count' => count($items), 'counts' => $counts];
    }

    public function movements(Request $request, array $params): array
    {
        return ['movements' => PharmacyService::movements((int) $params['id'])];
    }

    /** Record stock received, or correct a count. Both are recorded with a reason. */
    public function adjustStock(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->int('change', true, -100000, 100000)
            ->enum('reason', ['received', 'adjusted', 'returned'])
            ->string('note', false, 190)
            ->int('reorder_level', false, 0, 100000)
            ->validate();
        $body = $request->all();
        $expiry = $body['expiry_date'] ?? null;
        if ($expiry !== null && $expiry !== '' && !Format::isDate((string) $expiry)) {
            throw HttpException::validation(['expiry_date' => 'Give the date as YYYY-MM-DD.']);
        }
        $drugId = (int) $params['id'];
        if (!Database::value('SELECT 1 FROM drugs WHERE id = :id AND is_active = 1', ['id' => $drugId])) {
            throw HttpException::notFound('Medicine not found.');
        }
        if ((int) $v['change'] === 0 && !isset($v['reorder_level']) && ($expiry === null || $expiry === '')) {
            throw HttpException::validation(['change' => 'Enter a quantity, a reorder level or an expiry date.']);
        }
        $stock = PharmacyService::adjustStock(
            $drugId, (int) $v['change'], $v['reason'], $v['note'] ?? null,
            isset($v['reorder_level']) ? (int) $v['reorder_level'] : null,
            ($expiry === null || $expiry === '') ? null : (string) $expiry,
            $ctx
        );
        Audit::log('pharmacy', 'pharmacy.stock_changed',
            sprintf('%s %+d of %s — now %d', ucfirst($v['reason']), (int) $v['change'], $stock['name'], $stock['quantity']),
            'drug', $drugId, ['reason' => $v['reason'], 'change' => (int) $v['change'], 'balance' => $stock['quantity']]);
        return ['item' => $stock];
    }

    /** Every pharmacy order for one patient, for the patient history panel. */
    public function patientOrders(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $patientId = (int) $params['id'];
        $orders = array_values(array_filter(
            array_merge(
                PharmacyService::listOrders('queue', [], $ctx->userId),
                PharmacyService::listOrders('completed', [], $ctx->userId)
            ),
            static fn (array $o) => $o['customer']['patient_id'] === $patientId
        ));
        Audit::access($patientId, 'pharmacy', 'view', 'patient_pharmacy', $patientId);
        return ['orders' => $orders];
    }

    // ─── Input helpers ───────────────────────────────────────────────────

    /** @return array<int, array{item_id:int, quantity:int}> */
    private function lines(Request $request): array
    {
        $body = $request->all();
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        if ($items === []) {
            throw HttpException::validation(['items' => 'Enter the quantities.']);
        }
        $lines = [];
        foreach ($items as $index => $row) {
            $quantity = (int) ($row['quantity'] ?? 0);
            if ($quantity < 0) {
                throw HttpException::validation(["items.$index.quantity" => 'A quantity cannot be negative.']);
            }
            $lines[] = ['item_id' => (int) ($row['item_id'] ?? 0), 'quantity' => $quantity];
        }
        return $lines;
    }

    private function boundary(mixed $value, bool $endExclusive): string
    {
        return is_string($value) && Format::isDate($value) ? Format::localDateToUtc($value, $endExclusive) : '';
    }
}
