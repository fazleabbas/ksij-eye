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
use App\Services\FrameService;
use App\Services\ProcurementService;
use App\Services\StoreService;

/** Stores: stock, requisitions and issues, quotations, purchase orders and goods received. */
final class StoreController
{
    public function options(): array
    {
        return [
            'categories' => array_column(Database::all(
                "SELECT label FROM lookup_values WHERE list_code = 'store_category' AND is_active = 1 ORDER BY sort_order, label"
            ), 'label'),
            'units' => array_column(Database::all(
                "SELECT label FROM lookup_values WHERE list_code = 'store_unit' AND is_active = 1 ORDER BY sort_order, label"
            ), 'label'),
            'departments' => array_map(static fn (array $r) => ['id' => (int) $r['id'], 'name' => $r['name']], Database::all(
                'SELECT id, name FROM departments ORDER BY name'
            )),
            'suppliers' => ProcurementService::suppliers(),
            'drugs' => array_map(static fn (array $r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'unit_price' => (float) $r['unit_price']], Database::all(
                'SELECT id, name, unit_price FROM drugs WHERE is_active = 1 ORDER BY name'
            )),
            'frames' => array_map(static fn (array $f) => ['id' => $f['id'], 'name' => $f['label']], FrameService::list()),
            'items' => array_map(static fn (array $i) => [
                'id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'unit' => $i['unit'],
                'quantity' => $i['quantity'], 'cost_price' => $i['cost_price'],
            ], StoreService::items([])),
        ];
    }

    /**
     * What any department needs to ask the store for goods: the departments and the items
     * with how many are in the store. Costs, suppliers and pharmacy prices stay with the store.
     */
    public function requisitionOptions(): array
    {
        $full = $this->options();
        return [
            'categories' => $full['categories'],
            'units' => $full['units'],
            'departments' => $full['departments'],
            'suppliers' => [],
            'drugs' => [],
            'items' => array_map(static fn (array $i) => array_diff_key($i, ['cost_price' => true]), $full['items']),
        ];
    }

    // ─── Stock ───────────────────────────────────────────────────────────

    public function stock(Request $request): array
    {
        $items = StoreService::items([
            'search' => (string) $request->query('search'),
            'category' => (string) $request->query('category'),
            'status' => (string) $request->query('status'),
            'include_inactive' => $request->query('include_inactive') === '1',
        ]);
        $counts = ['in_stock' => 0, 'low_stock' => 0, 'out_of_stock' => 0];
        $value = 0.0;
        $expiring = 0;
        foreach ($items as $item) {
            $counts[$item['stock_status']]++;
            $value += $item['stock_value'];
            if (in_array($item['expiry_status'], ['expired', 'expiring_soon'], true)) {
                $expiring++;
            }
        }
        return ['items' => $items, 'count' => count($items), 'counts' => $counts,
                'stock_value' => round($value, 2), 'expiring' => $expiring];
    }

    public function showItem(Request $request, array $params): array
    {
        return ['item' => StoreService::findItem((int) $params['id'])];
    }

    public function storeItem(Request $request): array
    {
        $ctx = Auth::require();
        $input = $this->itemInput($request, true);
        $item = StoreService::saveItem(null, $input, $ctx);
        Audit::log('stores', 'store.item_added', "Added {$item['name']} to the store catalogue", 'store_item', $item['code']);
        $this->logDrugChange($input, $item);
        return ['item' => $item];
    }

    public function updateItem(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $input = $this->itemInput($request, false);
        $item = StoreService::saveItem((int) $params['id'], $input, $ctx);
        Audit::log('stores', 'store.item_updated', "Updated {$item['name']}", 'store_item', $item['code']);
        $this->logDrugChange($input, $item);
        return ['item' => $item];
    }

    private function logDrugChange(array $input, array $item): void
    {
        if ($input['new_drug'] !== null) {
            Audit::log('settings', 'drug.created', "Added medicine {$item['name']} from store item {$item['code']}", 'drug', $item['drug_id'],
                ['unit_price' => ['to' => (float) $input['new_drug']['new_drug_price']]]);
        }
        if ($input['drug_price'] !== null) {
            Audit::log('settings', 'drug.updated', "Changed the selling price of {$item['drug_name']} from store item {$item['code']}",
                'drug', $item['drug_id'], ['unit_price' => $input['drug_price']]);
        }
    }

    public function adjustStock(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->int('change', true, -1000000, 1000000)
            ->enum('reason', ['adjusted', 'returned', 'written_off'])
            ->string('note', true, 190, 3)
            ->validate();
        $item = StoreService::adjustStock((int) $params['id'], (int) $v['change'], $v['reason'], $v['note'], $ctx);
        Audit::log('stores', 'store.stock_adjusted',
            sprintf('%s %+d of %s — now %d', ucfirst(str_replace('_', ' ', $v['reason'])), (int) $v['change'], $item['name'], $item['quantity']),
            'store_item', $item['code'], ['reason' => $v['reason'], 'change' => (int) $v['change'], 'balance' => $item['quantity']]);
        return ['item' => $item];
    }

    // ─── Requisitions ────────────────────────────────────────────────────

    public function requisitions(Request $request): array
    {
        $ctx = Auth::require();
        $scope = $request->query('scope');
        $filters = [
            'search' => (string) $request->query('search'),
            'status' => (string) $request->query('status'),
            'department_id' => (int) $request->query('department_id'),
            'from' => $this->boundary($request->query('from'), false),
            'to' => $this->boundary($request->query('to'), true),
        ];
        if ($scope === 'mine') {
            $filters['mine'] = true;
        } elseif ($scope === 'open') {
            if (!$ctx->can('requisitions.manage')) {
                throw new HttpException(403, 'forbidden', 'You cannot see other departments’ requisitions.');
            }
            $filters['open_only'] = true;
        } elseif (!$ctx->can('requisitions.manage')) {
            $filters['mine'] = true;
        }
        $requisitions = StoreService::listRequisitions($filters, $ctx);
        return ['requisitions' => $requisitions, 'count' => count($requisitions)];
    }

    public function showRequisition(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $requisition = StoreService::findRequisition((int) $params['id']);
        if (!$ctx->can('requisitions.manage') && $requisition['department_id'] !== $ctx->departmentId
            && $requisition['created_by'] !== $ctx->displayName) {
            throw new HttpException(403, 'forbidden', 'This requisition belongs to another department.');
        }
        return ['requisition' => $requisition];
    }

    public function storeRequisition(Request $request): array
    {
        $ctx = Auth::require();
        $data = $this->requisitionInput($request);
        $submit = (bool) ($request->all()['submit'] ?? false);
        $requisition = StoreService::saveRequisition(null, $data, $submit, $ctx);
        Audit::log('stores', $submit ? 'requisition.submitted' : 'requisition.drafted',
            ($submit ? 'Submitted requisition ' : 'Saved draft requisition ') . $requisition['requisition_no'],
            'requisition', $requisition['requisition_no']);
        return ['requisition' => $requisition];
    }

    public function updateRequisition(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $data = $this->requisitionInput($request) + ['row_version' => (int) ($request->all()['row_version'] ?? 0)];
        $submit = (bool) ($request->all()['submit'] ?? false);
        $requisition = StoreService::saveRequisition((int) $params['id'], $data, $submit, $ctx);
        Audit::log('stores', $submit ? 'requisition.submitted' : 'requisition.updated',
            ($submit ? 'Submitted requisition ' : 'Updated requisition ') . $requisition['requisition_no'],
            'requisition', $requisition['requisition_no']);
        return ['requisition' => $requisition];
    }

    public function decideRequisition(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $body = $request->all();
        $v = Validator::make($body)
            ->enum('decision', ['approved', 'rejected'])
            ->string('reason', false, 500)
            ->validate();
        $lines = [];
        foreach (is_array($body['items'] ?? null) ? $body['items'] : [] as $row) {
            $lines[] = ['item_id' => (int) ($row['item_id'] ?? 0), 'qty_approved' => (int) ($row['qty_approved'] ?? 0)];
        }
        $requisition = StoreService::decideRequisition((int) $params['id'], $v['decision'], $lines, $v['reason'] ?? null, $ctx);
        Audit::log('stores', 'requisition.' . $v['decision'],
            ucfirst($v['decision']) . " requisition {$requisition['requisition_no']}", 'requisition', $requisition['requisition_no']);
        return ['requisition' => $requisition];
    }

    public function issueRequisition(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $body = $request->all();
        $v = Validator::make($body)->string('remarks', false, 500)->validate();
        $lines = [];
        foreach (is_array($body['items'] ?? null) ? $body['items'] : [] as $row) {
            $lines[] = ['item_id' => (int) ($row['item_id'] ?? 0), 'quantity' => (int) ($row['quantity'] ?? 0)];
        }
        $requisition = StoreService::issue((int) $params['id'], $lines, $v['remarks'] ?? null, $ctx);
        Audit::log('stores', 'requisition.issued',
            "Issued goods against {$requisition['requisition_no']}", 'requisition', $requisition['requisition_no']);
        return ['requisition' => $requisition];
    }

    // ─── Suppliers ───────────────────────────────────────────────────────

    public function suppliers(Request $request): array
    {
        return ['suppliers' => ProcurementService::suppliers($request->query('include_inactive') === '1')];
    }

    public function storeSupplier(Request $request): array
    {
        $ctx = Auth::require();
        $supplier = ProcurementService::saveSupplier(null, $this->supplierInput($request), $ctx);
        Audit::log('stores', 'supplier.added', "Added supplier {$supplier['name']}", 'supplier', $supplier['id']);
        return ['supplier' => $supplier];
    }

    public function updateSupplier(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $supplier = ProcurementService::saveSupplier((int) $params['id'], $this->supplierInput($request), $ctx);
        Audit::log('stores', 'supplier.updated', "Updated supplier {$supplier['name']}", 'supplier', $supplier['id']);
        return ['supplier' => $supplier];
    }

    // ─── Quotations ──────────────────────────────────────────────────────

    public function quotations(Request $request): array
    {
        $quotations = ProcurementService::quotations([
            'status' => (string) $request->query('status'),
            'supplier_id' => (int) $request->query('supplier_id'),
        ]);
        return ['quotations' => $quotations, 'count' => count($quotations)];
    }

    public function showQuotation(Request $request, array $params): array
    {
        return ['quotation' => ProcurementService::findQuotation((int) $params['id'])];
    }

    public function storeQuotation(Request $request): array
    {
        $ctx = Auth::require();
        $submit = (bool) ($request->all()['submit'] ?? false);
        $quotation = ProcurementService::saveQuotation(null, $this->quotationInput($request), $submit, $ctx);
        Audit::log('stores', 'quotation.saved', "Recorded quotation {$quotation['quotation_no']} from {$quotation['supplier']['name']}",
            'quotation', $quotation['quotation_no']);
        return ['quotation' => $quotation];
    }

    public function updateQuotation(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $submit = (bool) ($request->all()['submit'] ?? false);
        $data = $this->quotationInput($request) + ['row_version' => (int) ($request->all()['row_version'] ?? 0)];
        $quotation = ProcurementService::saveQuotation((int) $params['id'], $data, $submit, $ctx);
        Audit::log('stores', 'quotation.saved', "Updated quotation {$quotation['quotation_no']}", 'quotation', $quotation['quotation_no']);
        return ['quotation' => $quotation];
    }

    public function selectQuotation(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $quotation = ProcurementService::selectQuotation((int) $params['id'], $ctx);
        Audit::log('stores', 'quotation.selected',
            "Chose {$quotation['supplier']['name']} on {$quotation['quotation_no']} — " . number_format($quotation['total_amount']) . ' TZS',
            'quotation', $quotation['quotation_no']);
        return ['quotation' => $quotation];
    }

    public function comparison(): array
    {
        return ProcurementService::comparison();
    }

    // ─── Purchase orders ─────────────────────────────────────────────────

    public function orders(Request $request): array
    {
        $orders = ProcurementService::orders([
            'status' => (string) $request->query('status'),
            'supplier_id' => (int) $request->query('supplier_id'),
        ]);
        return ['orders' => $orders, 'count' => count($orders)];
    }

    public function showOrder(Request $request, array $params): array
    {
        return ['order' => ProcurementService::findOrder((int) $params['id'])];
    }

    public function storeOrder(Request $request): array
    {
        $ctx = Auth::require();
        $body = $request->all();
        $v = Validator::make($body)->int('quotation_id', true, 1)->validate();
        $expected = $body['expected_delivery'] ?? null;
        if ($expected !== null && $expected !== '' && !Format::isDate((string) $expected)) {
            throw HttpException::validation(['expected_delivery' => 'Give the date as YYYY-MM-DD.']);
        }
        $order = ProcurementService::orderFromQuotation((int) $v['quotation_id'], ($expected ?: null), $ctx);
        Audit::log('stores', 'purchase_order.created',
            "Raised {$order['order_no']} to {$order['supplier']['name']} — " . number_format($order['total_amount']) . ' TZS',
            'purchase_order', $order['order_no']);
        return ['order' => $order];
    }

    public function approveOrder(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $step = $request->all()['step'] ?? '';
        if (!in_array($step, ['admin', 'store'], true)) {
            throw HttpException::validation(['step' => 'Unknown approval step.']);
        }
        if ($step === 'admin' && !$ctx->can('stores.lpo.approve_admin')) {
            throw new HttpException(403, 'forbidden', 'You cannot give the administration approval.');
        }
        if ($step === 'store' && !$ctx->can('stores.lpo.approve_store')) {
            throw new HttpException(403, 'forbidden', 'You cannot give the store manager approval.');
        }
        $order = ProcurementService::approveOrder((int) $params['id'], $step, $ctx);
        Audit::log('stores', 'purchase_order.approved',
            ($step === 'admin' ? 'Administration approved ' : 'Store manager approved ') . $order['order_no'],
            'purchase_order', $order['order_no']);
        return ['order' => $order];
    }

    public function sendOrder(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $order = ProcurementService::markOrderSent((int) $params['id'], $ctx);
        Audit::log('stores', 'purchase_order.sent', "Sent {$order['order_no']} to {$order['supplier']['name']}",
            'purchase_order', $order['order_no']);
        return ['order' => $order];
    }

    public function cancelOrder(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('reason', true, 300, 5)->validate();
        $order = ProcurementService::cancelOrder((int) $params['id'], $v['reason'], $ctx);
        Audit::log('stores', 'purchase_order.cancelled', "Cancelled {$order['order_no']}", 'purchase_order', $order['order_no']);
        return ['order' => $order];
    }

    // ─── Goods received ──────────────────────────────────────────────────

    public function deliveries(Request $request): array
    {
        $deliveries = ProcurementService::deliveries(
            ((int) $request->query('order_id')) ?: null,
            ['status' => (string) $request->query('status')]
        );
        return ['deliveries' => $deliveries, 'count' => count($deliveries)];
    }

    public function storeDelivery(Request $request): array
    {
        $ctx = Auth::require();
        $body = $request->all();
        $v = Validator::make($body)
            ->int('order_id', true, 1)
            ->string('delivery_note', false, 60)
            ->string('remarks', false, 500)
            ->validate();
        $receivedOn = (string) ($body['received_on'] ?? '');
        if (!Format::isDate($receivedOn)) {
            throw HttpException::validation(['received_on' => 'Give the date the goods arrived.']);
        }
        $items = [];
        foreach (is_array($body['items'] ?? null) ? $body['items'] : [] as $index => $row) {
            $expiry = $row['expiry_date'] ?? null;
            if ($expiry !== null && $expiry !== '' && !Format::isDate((string) $expiry)) {
                throw HttpException::validation(["items.$index.expiry_date" => 'Give the expiry as YYYY-MM-DD.']);
            }
            $items[] = [
                'item_id' => (int) ($row['item_id'] ?? 0),
                'quantity' => (int) ($row['quantity'] ?? 0),
                'rejected_qty' => (int) ($row['rejected_qty'] ?? 0),
                'batch_no' => ($row['batch_no'] ?? '') === '' ? null : mb_substr((string) $row['batch_no'], 0, 60),
                'expiry_date' => ($expiry === null || $expiry === '') ? null : (string) $expiry,
                'unit_price' => isset($row['unit_price']) && $row['unit_price'] !== '' ? (float) $row['unit_price'] : null,
                'remarks' => isset($row['remarks']) ? mb_substr((string) $row['remarks'], 0, 190) : null,
            ];
        }
        $delivery = ProcurementService::receive((int) $v['order_id'], [
            'received_on' => $receivedOn,
            'delivery_note' => $v['delivery_note'] ?? null,
            'remarks' => $v['remarks'] ?? null,
            'items' => $items,
        ], $ctx);
        Audit::log('stores', 'goods_received.recorded',
            "Recorded {$delivery['grn_no']} against {$delivery['order_no']}", 'goods_received', $delivery['grn_no']);
        return ['delivery' => $delivery];
    }

    public function decideDelivery(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $step = $request->all()['step'] ?? '';
        if (!in_array($step, ['verify', 'approve'], true)) {
            throw HttpException::validation(['step' => 'Unknown step.']);
        }
        $delivery = ProcurementService::decideDelivery((int) $params['id'], $step, $ctx);
        Audit::log('stores', 'goods_received.' . $step . 'd',
            ($step === 'verify' ? 'Checked ' : 'Approved and took into stock ') . $delivery['grn_no'],
            'goods_received', $delivery['grn_no']);
        return ['delivery' => $delivery];
    }

    // ─── Input ───────────────────────────────────────────────────────────

    private function itemInput(Request $request, bool $creating): array
    {
        $body = $request->all();
        $rules = Validator::make($body)
            ->string('name', true, 150, 2)
            ->string('category', true, 60)
            ->string('unit', true, 40)
            ->int('reorder_level', false, 0, 1000000)
            ->decimal('cost_price', false, 0, 99999999);
        if (!$creating) {
            $rules->bool('is_active')->int('row_version', true, 1);
        }
        $v = $rules->validate();
        // 'new' adds the item to the pharmacy's medicine list under the same name, so it can be issued there.
        $newDrug = null;
        $drugId = null;
        $drugPrice = null;
        if (($body['drug_id'] ?? '') === 'new') {
            $newDrug = Validator::make($body)
                ->decimal('new_drug_price', true, 1, 99999999)
                ->string('new_drug_form', false, 40)
                ->validate();
            if (Database::value('SELECT 1 FROM drugs WHERE name = :n', ['n' => $v['name']])) {
                throw HttpException::validation(['drug_id' => 'The pharmacy already has a medicine with this name. Choose it from the list.']);
            }
        } elseif (($body['drug_id'] ?? '') !== '') {
            $drugId = (int) $body['drug_id'];
            $current = Database::value('SELECT unit_price FROM drugs WHERE id = :id AND is_active = 1', ['id' => $drugId])
                ?? throw HttpException::validation(['drug_id' => 'Choose a medicine from the catalogue.']);
            // The selling price of a linked medicine is shown here, but only the catalogue managers may change it.
            if (($body['drug_price'] ?? '') !== '') {
                $price = (float) Validator::make($body)->decimal('drug_price', true, 1, 99999999)->validate()['drug_price'];
                if (abs($price - (float) $current) >= 0.005) {
                    if (!Auth::require()->can('catalogues.manage')) {
                        throw new HttpException(403, 'forbidden', 'Only an administrator can change a pharmacy selling price.');
                    }
                    $drugPrice = ['from' => (float) $current, 'to' => $price];
                }
            }
        }
        $supplierId = ($body['supplier_id'] ?? '') === '' ? null : (int) $body['supplier_id'];
        if ($supplierId !== null && !Database::value('SELECT 1 FROM suppliers WHERE id = :id AND is_active = 1', ['id' => $supplierId])) {
            throw HttpException::validation(['supplier_id' => 'Choose a supplier from the list.']);
        }
        $frameId = ($body['frame_id'] ?? '') === '' ? null : (int) $body['frame_id'];
        if ($frameId !== null && !Database::value('SELECT 1 FROM frames WHERE id = :id AND is_active = 1', ['id' => $frameId])) {
            throw HttpException::validation(['frame_id' => 'Choose a frame from the optical list.']);
        }
        if ($frameId !== null && (($body['drug_id'] ?? '') !== '')) {
            throw HttpException::validation(['frame_id' => 'An item is either a pharmacy medicine or a frame, not both.']);
        }
        return array_merge($v, [
            'frame_id' => $frameId,
            'drug_id' => $drugId, 'new_drug' => $newDrug, 'drug_price' => $drugPrice, 'supplier_id' => $supplierId,
            'reorder_level' => (int) ($v['reorder_level'] ?? 0),
            'cost_price' => (float) ($v['cost_price'] ?? 0),
            'is_active' => $creating ? true : !empty($v['is_active']),
        ]);
    }

    private function requisitionInput(Request $request): array
    {
        $body = $request->all();
        $v = Validator::make($body)
            ->int('department_id', true, 1)
            ->enum('priority', ['normal', 'urgent', 'emergency'])
            ->string('remarks', false, 500)
            ->validate();
        if (!Database::value('SELECT 1 FROM departments WHERE id = :id', ['id' => $v['department_id']])) {
            throw HttpException::validation(['department_id' => 'Choose a department.']);
        }
        $items = [];
        foreach (is_array($body['items'] ?? null) ? $body['items'] : [] as $index => $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            $quantity = (int) ($row['quantity'] ?? 0);
            if ($itemId === 0) {
                continue;
            }
            if ($quantity < 1) {
                throw HttpException::validation(["items.$index.quantity" => 'Ask for at least one.']);
            }
            if (!Database::value('SELECT 1 FROM store_items WHERE id = :id AND is_active = 1', ['id' => $itemId])) {
                throw HttpException::validation(["items.$index.item_id" => 'That item is no longer in the store catalogue.']);
            }
            $items[] = ['item_id' => $itemId, 'quantity' => $quantity, 'remarks' => $row['remarks'] ?? null];
        }
        if ($items === []) {
            throw HttpException::validation(['items' => 'Add at least one item.']);
        }
        return $v + ['items' => $items, 'remarks' => $v['remarks'] ?? null];
    }

    private function quotationInput(Request $request): array
    {
        $body = $request->all();
        $v = Validator::make($body)
            ->int('supplier_id', true, 1)
            ->string('remarks', false, 500)
            ->validate();
        if (!Database::value('SELECT 1 FROM suppliers WHERE id = :id AND is_active = 1', ['id' => $v['supplier_id']])) {
            throw HttpException::validation(['supplier_id' => 'Choose a supplier.']);
        }
        foreach (['quoted_on', 'valid_until'] as $field) {
            $value = $body[$field] ?? null;
            if ($value !== null && $value !== '' && !Format::isDate((string) $value)) {
                throw HttpException::validation([$field => 'Give the date as YYYY-MM-DD.']);
            }
        }
        $items = [];
        foreach (is_array($body['items'] ?? null) ? $body['items'] : [] as $index => $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            if ($itemId === 0) {
                continue;
            }
            $quantity = (int) ($row['quantity'] ?? 0);
            $price = (float) ($row['unit_price'] ?? -1);
            if ($quantity < 1) {
                throw HttpException::validation(["items.$index.quantity" => 'Give the quantity quoted.']);
            }
            if ($price < 0) {
                throw HttpException::validation(["items.$index.unit_price" => 'Give the price quoted.']);
            }
            if (!Database::value('SELECT 1 FROM store_items WHERE id = :id AND is_active = 1', ['id' => $itemId])) {
                throw HttpException::validation(["items.$index.item_id" => 'That item is no longer in the store catalogue.']);
            }
            $items[] = [
                'item_id' => $itemId, 'quantity' => $quantity, 'unit_price' => round($price, 2),
                'tax_percent' => round((float) ($row['tax_percent'] ?? 0), 2), 'remarks' => $row['remarks'] ?? null,
            ];
        }
        if ($items === []) {
            throw HttpException::validation(['items' => 'Add at least one item.']);
        }
        return $v + [
            'quoted_on' => ($body['quoted_on'] ?? '') === '' ? null : (string) $body['quoted_on'],
            'valid_until' => ($body['valid_until'] ?? '') === '' ? null : (string) $body['valid_until'],
            'remarks' => $v['remarks'] ?? null,
            'items' => $items,
        ];
    }

    private function supplierInput(Request $request): array
    {
        $v = Validator::make($request->all())
            ->string('name', true, 150, 2)
            ->string('contact_name', false, 120)
            ->string('phone', false, 40)
            ->email('email')
            ->string('address', false, 255)
            ->bool('is_preferred')
            ->bool('is_active')
            ->int('row_version', false, 1)
            ->validate();
        return $v + ['is_active' => array_key_exists('is_active', $request->all()) ? !empty($v['is_active']) : true];
    }

    private function boundary(mixed $value, bool $endExclusive): string
    {
        return is_string($value) && Format::isDate($value) ? Format::localDateToUtc($value, $endExclusive) : '';
    }
}
