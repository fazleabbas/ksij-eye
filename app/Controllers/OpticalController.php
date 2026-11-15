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
use App\Services\OpticalService;

/** Optical room: incoming prescriptions, active orders, dispensing and completed orders. */
final class OpticalController
{
    public function options(): array
    {
        return [
            'suppliers' => array_map(static fn (array $r) => ['id' => (int) $r['id'], 'label' => $r['label']], Database::all(
                "SELECT id, label FROM lookup_values WHERE list_code = 'optical_supplier' AND is_active = 1 ORDER BY sort_order, label"
            )),
            'discount_types' => array_map(static fn (array $r) => $r['label'], Database::all(
                "SELECT label FROM lookup_values WHERE list_code = 'discount_type' AND is_active = 1 ORDER BY sort_order, label"
            )),
            'frames' => FrameService::list(),
            'lenses' => OpticalService::lensItems(),
            'frame_prices' => [
                'patient' => (float) (Database::value("SELECT unit_price FROM price_items WHERE code = 'FRAME-INTERNAL' AND is_active = 1") ?? 0),
                'external' => (float) (Database::value("SELECT unit_price FROM price_items WHERE code = 'FRAME-EXTERNAL' AND is_active = 1") ?? 0),
            ],
        ];
    }

    // ─── Frames ──────────────────────────────────────────────────────────

    public function frames(Request $request): array
    {
        return ['frames' => FrameService::list($request->query('include_inactive') === '1')];
    }

    public function storeFrame(Request $request): array
    {
        $ctx = Auth::require();
        $frame = FrameService::save(null, $this->frameInput($request, true), $ctx);
        Audit::log('optical', 'frame.created', "Added frame {$frame['label']}", 'frame', $frame['code']);
        return ['frame' => $frame];
    }

    public function updateFrame(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $before = FrameService::find((int) $params['id']);
        $frame = FrameService::save((int) $params['id'], $this->frameInput($request, false), $ctx);
        $keys = ['name', 'brand', 'colour', 'size', 'reorder_level', 'is_active'];
        Audit::log('optical', 'frame.updated', "Updated frame {$frame['label']}", 'frame', $frame['code'],
            Audit::diff(array_intersect_key($before, array_flip($keys)), array_intersect_key($frame, array_flip($keys))) ?: null);
        return ['frame' => $frame];
    }

    /** A count corrected by hand, or frames returned; always with a reason. */
    public function adjustFrame(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->int('change', true, -10000, 10000)
            ->enum('reason', ['adjusted', 'returned'])
            ->string('note', true, 190, 3)
            ->validate();
        $frame = FrameService::adjust((int) $params['id'], (int) $v['change'], $v['reason'], 'Manual', $v['note'], $ctx->userId);
        Audit::log('optical', 'frame.stock_adjusted',
            sprintf('%s %+d of %s — now %d', ucfirst($v['reason']), (int) $v['change'], $frame['label'], $frame['quantity']),
            'frame', $frame['code'], ['note' => $v['note']]);
        return ['frame' => $frame];
    }

    public function frameMovements(Request $request, array $params): array
    {
        return ['frame' => FrameService::find((int) $params['id']), 'movements' => FrameService::movements((int) $params['id'])];
    }

    private function frameInput(Request $request, bool $creating): array
    {
        $rules = Validator::make($request->all())
            ->string('name', true, 150, 2)
            ->string('brand', false, 80)
            ->string('colour', false, 40)
            ->string('size', false, 20)
            ->int('reorder_level', false, 0, 10000);
        if (!$creating) {
            $rules->bool('is_active')->int('row_version', true, 1);
        }
        $v = $rules->validate();
        return array_merge($v, [
            'brand' => $v['brand'] ?? null, 'colour' => $v['colour'] ?? null, 'size' => $v['size'] ?? null,
            'reorder_level' => (int) ($v['reorder_level'] ?? 0), 'is_active' => $creating || !empty($v['is_active']),
        ]);
    }

    // ─── External sale and frame change ──────────────────────────────────

    /** Someone who is not a patient buys spectacles; the bill goes to the cashier. */
    public function storeExternal(Request $request): array
    {
        $ctx = Auth::require();
        $body = $request->all();
        $v = Validator::make($body)
            ->string('name', true, 160, 2)
            ->string('phone', true, 20, 9, '/^\+?[0-9 ]{9,20}$/', message: 'Enter a valid phone number, e.g. 255712345678.')
            ->enum('gender', ['male', 'female'], false)
            ->int('age', false, 0, 120)
            ->int('frame_id', false, 1)
            ->validate();
        $lensIds = array_values(array_filter(array_map('intval', is_array($body['lens_ids'] ?? null) ? $body['lens_ids'] : [])));
        $order = OpticalService::createExternal(
            ['name' => $v['name'], 'phone' => $v['phone'], 'gender' => $v['gender'] ?? null, 'age' => $v['age'] ?? null],
            $v['frame_id'] ?? null, $lensIds, $ctx
        );
        Audit::log('optical', 'optical.external_sale',
            "External spectacle sale {$order['order_no']} — bill {$order['bill']['bill_no']}", 'optical_order', $order['order_no']);
        return ['order' => $order];
    }

    /** Another pair on the same prescription, for the same patient. */
    public function extraPair(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->int('frame_id', false, 1)->int('set_no', false, 1, 1000)->validate();
        $order = OpticalService::createExtraPair((int) $params['id'], $v['frame_id'] ?? null, (int) ($v['set_no'] ?? 1), $ctx);
        Audit::log('optical', 'optical.extra_pair',
            "Extra pair {$order['order_no']} with {$order['original_order_no']} — bill {$order['bill']['bill_no']}", 'optical_order', $order['order_no']);
        return ['order' => $order];
    }

    public function changeFrame(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->int('frame_id', true, 1)
            ->string('old_frame', false, 190)
            ->string('reason', true, 500, 3)
            ->validate();
        $order = OpticalService::createFrameChange((int) $params['id'], (int) $v['frame_id'], $v['old_frame'] ?? null, $v['reason'], $ctx);
        Audit::log('optical', 'optical.frame_change',
            "Frame change {$order['order_no']} on {$order['original_order_no']} — bill {$order['bill']['bill_no']}", 'optical_order', $order['order_no']);
        return ['order' => $order];
    }

    /** Prescriptions optometry has referred that have no order yet. */
    public function incoming(Request $request): array
    {
        $referrals = OpticalService::incoming([
            'search' => (string) $request->query('search'),
            'from' => $this->boundary($request->query('from'), false),
            'to' => $this->boundary($request->query('to'), true),
        ]);
        return ['referrals' => $referrals, 'count' => count($referrals)];
    }

    /** The prescription behind a referral, for the Rx view and its printout. */
    public function prescription(Request $request, array $params): array
    {
        $assessmentId = (int) $params['id'];
        $row = Database::one(
            'SELECT a.id, a.patient_id, v.visit_no, a.completed_at,
                    TRIM(CONCAT_WS(\' \', u.first_name, u.last_name)) AS optometrist_name,
                    p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc
               FROM optometry_assessments a
               JOIN visits v ON v.id = a.visit_id
               JOIN patients p ON p.id = a.patient_id
               LEFT JOIN users u ON u.id = a.completed_by
              WHERE a.id = :a',
            ['a' => $assessmentId]
        ) ?? throw HttpException::notFound('Prescription not found.');
        Audit::access((int) $row['patient_id'], 'optical', 'view', 'optometry_prescription', $assessmentId);
        return [
            'prescription' => OpticalService::prescription($assessmentId) + [
                'visit_no' => $row['visit_no'],
                'optometrist' => $row['optometrist_name'] ?: '—',
                'lenses' => OpticalService::lensChoice($assessmentId),
                'patient' => \App\Services\PatientService::brief($row) + ['id' => (int) $row['patient_id']],
            ],
        ];
    }

    public function index(Request $request): array
    {
        $scope = in_array($request->query('scope'), ['active', 'dispensing', 'completed'], true) ? $request->query('scope') : 'active';
        $orders = OpticalService::listOrders($scope, [
            'search' => (string) $request->query('search'),
            'status' => (string) $request->query('status'),
            'supplier_id' => (int) $request->query('supplier_id'),
            'payment' => (string) $request->query('payment'),
            'from' => $this->boundary($request->query('from'), false),
            'to' => $this->boundary($request->query('to'), true),
        ]);
        return ['orders' => $orders, 'count' => count($orders)];
    }

    public function show(Request $request, array $params): array
    {
        return ['order' => OpticalService::show((int) $params['id'], Auth::require())];
    }

    /** Confirm the referral as an order; the bill goes to the cashier. */
    public function store(Request $request): array
    {
        $ctx = Auth::require();
        $body = $request->all();
        $v = Validator::make($body)->int('assessment_id', true, 1)->int('frame_id', false, 1)->validate();
        // One frame per pair: [{set_no, frame_id}]; a single frame_id is the first prescription's.
        $frames = [];
        foreach (is_array($body['frames'] ?? null) ? $body['frames'] : [] as $row) {
            if (is_array($row) && (int) ($row['frame_id'] ?? 0) > 0) {
                $frames[max(1, (int) ($row['set_no'] ?? 1))] = (int) $row['frame_id'];
            }
        }
        if ($frames === [] && ($v['frame_id'] ?? null) !== null) {
            $frames[1] = (int) $v['frame_id'];
        }
        // Lenses the patient wants instead of Optometry's: [{set_no, lens_ids, reason}], a reason each.
        $lensChanges = [];
        foreach (is_array($body['lenses'] ?? null) ? $body['lenses'] : [] as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $reason = trim((string) ($row['reason'] ?? ''));
            if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
                throw HttpException::validation(["lenses.$i.reason" => 'Say why the lens is being changed.', 'lens_reason' => 'Say why the lens is being changed.']);
            }
            $lensChanges[max(1, (int) ($row['set_no'] ?? 1))] = [
                'lens_ids' => array_values(array_filter(array_map('intval', is_array($row['lens_ids'] ?? null) ? $row['lens_ids'] : []))),
                'reason' => $reason,
            ];
        }
        $order = OpticalService::createOrder((int) $v['assessment_id'], $frames, $ctx, $lensChanges);
        Audit::log('optical', 'optical.order_created',
            "Raised spectacle order {$order['order_no']} — bill {$order['bill']['bill_no']}"
            . ($lensChanges ? ' (lens changed from the one Optometry chose)' : ''), 'optical_order', $order['order_no']);
        return ['order' => $order];
    }

    /** The patient wants another lens on an order not yet sent to the supplier and not paid. */
    public function changeLens(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $body = $request->all();
        $v = Validator::make($body)
            ->int('set_no', false, 1, 1000)
            ->string('reason', true, 500, 3)
            ->int('row_version', true, 1)
            ->validate();
        $lensIds = array_values(array_filter(array_map('intval', is_array($body['lens_ids'] ?? null) ? $body['lens_ids'] : [])));
        $order = OpticalService::changeLens((int) $params['id'], $v['set_no'] ?? null, $lensIds, $v['reason'], (int) $v['row_version'], $ctx);
        $change = end($order['lens_changes']);
        Audit::log('optical', 'optical.lens_changed',
            "Changed the lens on {$order['order_no']}: {$change['from']} → {$change['to']} — bill {$order['old_bill_no']} replaced by {$order['bill']['bill_no']}",
            'optical_order', $order['order_no']);
        unset($order['old_bill_no']);
        return ['order' => $order];
    }

    /** Supplier and production tracking. */
    public function update(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->string('supplier_ref', false, 190)
            ->int('row_version', true, 1)
            ->validate();
        $body = $request->all();
        $order = OpticalService::track((int) $params['id'], [
            'supplier_id' => $this->supplierId($body['supplier_id'] ?? null),
            'supplier_order_date' => $this->date($body['supplier_order_date'] ?? null, 'supplier_order_date'),
            'supplier_ref' => $v['supplier_ref'] ?? null,
            'lens_received_date' => $this->date($body['lens_received_date'] ?? null, 'lens_received_date'),
            'ready_date' => $this->date($body['ready_date'] ?? null, 'ready_date'),
            'row_version' => $v['row_version'],
        ], $ctx);
        Audit::log('optical', 'optical.order_updated', "Updated spectacle order {$order['order_no']}", 'optical_order', $order['order_no']);
        return ['order' => $order];
    }

    public function dispense(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $order = OpticalService::dispense((int) $params['id'], $ctx);
        Audit::log('optical', 'optical.dispensed',
            "Handed over spectacles for {$order['order_no']}", 'optical_order', $order['order_no']);
        return ['order' => $order];
    }

    public function requestDiscount(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->decimal('amount', true, 0.01, 99999999)
            ->string('discount_type', true, 50)
            ->string('sponsor', false, 150)
            ->string('remarks', true, 500, 5)
            ->validate();
        $this->assertDiscountType($v['discount_type']);
        $order = OpticalService::requestDiscount(
            (int) $params['id'], (float) $v['amount'], $v['discount_type'], $v['sponsor'] ?? null, $v['remarks'], $ctx
        );
        Audit::log('optical', 'optical.discount_requested',
            "Asked for a discount on {$order['order_no']} ({$order['approval_no']})", 'optical_order', $order['order_no']);
        return ['order' => $order];
    }

    public function requestCancellation(Request $request, array $params): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())->string('reason', true, 500, 5)->validate();
        $order = OpticalService::requestCancellation((int) $params['id'], $v['reason'], $ctx);
        Audit::log('optical', 'optical.cancellation_requested',
            "Requested cancellation of {$order['order_no']} ({$order['approval_no']})", 'optical_order', $order['order_no']);
        return ['order' => $order];
    }

    /** Every spectacle order for one patient, for the patient history panel. */
    public function patientOrders(Request $request, array $params): array
    {
        $patientId = (int) $params['id'];
        $orders = array_values(array_filter(
            array_merge(OpticalService::listOrders('active', []), OpticalService::listOrders('completed', [])),
            static fn (array $o) => $o['patient']['id'] === $patientId
        ));
        Audit::access($patientId, 'optical', 'view', 'patient_optical', $patientId);
        return ['orders' => $orders];
    }

    // ─── Input helpers ───────────────────────────────────────────────────

    private function supplierId(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }
        $id = (int) Database::value(
            "SELECT id FROM lookup_values WHERE id = :id AND list_code = 'optical_supplier' AND is_active = 1",
            ['id' => (int) $value]
        ) ?: throw HttpException::validation(['supplier_id' => 'Choose a supplier from the list.']);
        return $id;
    }

    private function date(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !Format::isDate($value)) {
            throw HttpException::validation([$field => 'Give the date as YYYY-MM-DD.']);
        }
        return $value;
    }

    private function assertDiscountType(string $label): void
    {
        if (!Database::value(
            "SELECT 1 FROM lookup_values WHERE list_code = 'discount_type' AND label = :l AND is_active = 1",
            ['l' => $label]
        )) {
            throw HttpException::validation(['discount_type' => 'Choose a discount type from the list.']);
        }
    }

    private function boundary(mixed $value, bool $endExclusive): string
    {
        return is_string($value) && Format::isDate($value) ? Format::localDateToUtc($value, $endExclusive) : '';
    }
}
