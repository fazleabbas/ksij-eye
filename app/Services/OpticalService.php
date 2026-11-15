<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AuthContext;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\HttpException;
use App\Core\Sequence;

/**
 * Optical room: spectacle orders.
 *
 * Three kinds: a referral from optometry (the optometrist chose the lens and its price is
 * copied onto the order), an external sale to someone who is not a patient, and a frame
 * change on earlier spectacles. Raising an order raises the cashier bill; spectacles are
 * handed over only once that bill is settled, and that is when a frame leaves the stock.
 * Discounts and cancellations are decided in the approval queue.
 */
final class OpticalService
{
    /** Orders still being worked on. */
    public const ACTIVE_STATUSES = ['ordered', 'sent_to_supplier', 'lens_received', 'ready_for_dispensing', 'cancellation_requested'];

    private const ORDER_SELECT = "SELECT o.id, o.order_no, o.order_type, o.status, o.total_amount, o.supplier_id, o.supplier_order_date, o.supplier_ref,
                o.lens_received_date, o.ready_date, o.dispensed_at, o.cancelled_at, o.row_version, o.created_at,
                o.patient_id, o.walkin_id, o.visit_id, o.assessment_id, o.bill_id, o.original_order_id, o.old_frame, o.change_reason_enc,
                orig.order_no AS original_order_no,
                sup.label AS supplier,
                v.visit_no, v.is_fast_track AS visit_fast_track,
                p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc, p.phone_enc,
                w.walkin_no, w.name_enc AS walkin_name_enc, w.phone_enc AS walkin_phone_enc, w.gender AS walkin_gender, w.age_years,
                b.bill_no, b.status AS bill_status, b.net_amount AS bill_amount, b.paid_amount,
                TRIM(CONCAT_WS(' ', du.first_name, du.last_name)) AS dispensed_by_name,
                TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)) AS created_by_name,
                TRIM(CONCAT_WS(' ', ou.first_name, ou.last_name)) AS optometrist_name,
                a.completed_at AS prescription_date,
                (SELECT GROUP_CONCAT(i.description ORDER BY i.id SEPARATOR ', ') FROM optical_order_items i WHERE i.order_id = o.id) AS item_names
           FROM optical_orders o
           LEFT JOIN patients p ON p.id = o.patient_id
           LEFT JOIN pharmacy_walkins w ON w.id = o.walkin_id
           LEFT JOIN visits v ON v.id = o.visit_id
           LEFT JOIN optometry_assessments a ON a.id = o.assessment_id
           LEFT JOIN optical_orders orig ON orig.id = o.original_order_id
           JOIN users cu ON cu.id = o.created_by
           LEFT JOIN users ou ON ou.id = a.completed_by
           LEFT JOIN users du ON du.id = o.dispensed_by
           LEFT JOIN lookup_values sup ON sup.id = o.supplier_id
           LEFT JOIN bills b ON b.id = o.bill_id";

    // ─── Incoming referrals ──────────────────────────────────────────────

    /**
     * Completed optometry assessments that referred the patient to the optical room
     * and have no order yet.
     */
    public static function incoming(array $filters): array
    {
        // Not ordered yet, or ordered in part with pairs still waiting.
        $where = ["a.status = 'completed'", 'a.refer_optical = 1',
            "(o.id IS NULL OR EXISTS (SELECT 1 FROM optical_waiting_pairs wp WHERE wp.assessment_id = a.id AND wp.status = 'waiting'))"];
        $params = [];
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'a.completed_at >= :from';
            $params['from'] = $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'a.completed_at < :to';
            $params['to'] = $filters['to'];
        }
        $rows = Database::all(
            "SELECT a.id AS assessment_id, a.completed_at, a.visit_id, v.visit_no, v.is_fast_track, v.patient_id,
                    p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc, p.gender, p.date_of_birth_enc, p.phone_enc,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS optometrist_name
               FROM optometry_assessments a
               JOIN visits v ON v.id = a.visit_id
               JOIN patients p ON p.id = v.patient_id
               LEFT JOIN users u ON u.id = a.completed_by
               LEFT JOIN optical_orders o ON o.assessment_id = a.id AND o.order_type = 'referral'
                    AND o.id = (SELECT MIN(o2.id) FROM optical_orders o2 WHERE o2.assessment_id = a.id AND o2.order_type = 'referral')
              WHERE " . implode(' AND ', $where) . '
              ORDER BY v.is_emergency DESC, v.is_fast_track DESC, a.completed_at
              LIMIT 300',
            $params
        );
        $referrals = array_map(static fn (array $r) => [
            'assessment_id' => (int) $r['assessment_id'],
            'visit_id' => (int) $r['visit_id'],
            'visit_no' => $r['visit_no'],
            'is_fast_track' => (bool) $r['is_fast_track'],
            'prescription_date' => Format::utc($r['completed_at']),
            'optometrist' => $r['optometrist_name'] ?: '—',
            'patient' => PatientService::brief($r) + ['id' => (int) $r['patient_id']],
            'lenses' => self::lensChoice((int) $r['assessment_id']),
        ], $rows);
        // Partly ordered: offer only the waiting pairs, at their locked price.
        foreach ($referrals as &$referral) {
            $waiting = self::waitingPairs($referral['assessment_id']);
            $referral['waiting'] = $waiting === [] ? null : $waiting;
            if ($waiting !== []) {
                $referral['lenses'] = array_merge(...array_map(static fn (array $pair) => $pair['lenses'], $waiting));
                $referral['ordered_orders'] = array_column(Database::all(
                    "SELECT order_no FROM optical_orders WHERE assessment_id = :a AND order_type = 'referral' AND status <> 'cancelled' ORDER BY id",
                    ['a' => $referral['assessment_id']]
                ), 'order_no');
            }
        }
        unset($referral);
        return self::searchFilter($referrals, (string) ($filters['search'] ?? ''));
    }

    /**
     * The lens items the optometrist picked, priced from the price list as it stands now, with
     * the prescription each belongs to (set 1 is the final refraction; 2, 3, … the further ones).
     */
    public static function lensChoice(int $assessmentId, ?int $setNo = null): array
    {
        return array_map(static fn (array $r) => [
            'price_item_id' => (int) $r['id'], 'name' => $r['name'], 'unit_price' => (float) $r['unit_price'],
            'set_no' => (int) $r['set_no'], 'label' => $r['label'],
        ], Database::all(
            "SELECT pi.id, pi.name, pi.unit_price, li.set_no, r.label FROM optometry_lens_items li
               JOIN price_items pi ON pi.id = li.price_item_id
               LEFT JOIN optometry_refractions r ON r.assessment_id = li.assessment_id AND r.kind = 'final' AND r.set_no = li.set_no
              WHERE li.assessment_id = :a" . ($setNo === null ? '' : ' AND li.set_no = :s') . '
              ORDER BY li.set_no, pi.name',
            $setNo === null ? ['a' => $assessmentId] : ['a' => $assessmentId, 's' => $setNo]
        ));
    }

    /** The prescription as Optometry recorded it: final refraction, PD, NPC and pressures. */
    public static function prescription(int $assessmentId): array
    {
        $assessment = Database::one(
            'SELECT a.id, a.va_right, a.va_left, a.iop_right, a.iop_left, a.completed_at
               FROM optometry_assessments a WHERE a.id = :a',
            ['a' => $assessmentId]
        ) ?? throw HttpException::notFound('The optometry record is missing.');
        $refractions = Database::all(
            'SELECT * FROM optometry_refractions WHERE assessment_id = :a ORDER BY kind, set_no',
            ['a' => $assessmentId]
        );
        $values = static fn (array $r) => [
            'sph_right' => $r['sph_right'], 'cyl_right' => $r['cyl_right'], 'axis_right' => $r['axis_right'],
            'cva_right' => $r['cva_right'], 'add_right' => $r['add_right'],
            'sph_left' => $r['sph_left'], 'cyl_left' => $r['cyl_left'], 'axis_left' => $r['axis_left'],
            'cva_left' => $r['cva_left'], 'add_left' => $r['add_left'],
            'pd_mm' => $r['pd_mm'], 'npc' => $r['npc'],
        ];
        $byKind = [];
        $sets = [];
        $lenses = self::lensChoice($assessmentId);
        foreach ($refractions as $r) {
            if ((int) $r['set_no'] === 1) {
                $byKind[$r['kind']] = $values($r);
            }
            // Every spectacle prescription in the visit: the final refraction and any further ones.
            if ($r['kind'] === 'final') {
                $setNo = (int) $r['set_no'];
                $sets[] = [
                    'set_no' => $setNo, 'label' => $r['label'], 'refraction' => $values($r),
                    'lenses' => array_values(array_filter($lenses, static fn (array $l) => $l['set_no'] === $setNo)),
                ];
            }
        }
        return [
            'final' => $byKind['final'] ?? null,
            'refractions' => $byKind,
            'sets' => $sets,
            'va' => ['right' => $assessment['va_right'], 'left' => $assessment['va_left']],
            'iop' => ['right' => $assessment['iop_right'], 'left' => $assessment['iop_left']],
            'prescription_date' => Format::utc($assessment['completed_at']),
        ];
    }

    // ─── Orders ──────────────────────────────────────────────────────────

    public static function listOrders(string $scope, array $filters): array
    {
        [$in, $params] = match ($scope) {
            'dispensing' => Database::in('s', ['ready_for_dispensing']),
            'completed' => Database::in('s', ['dispensed', 'cancelled']),
            default => Database::in('s', self::ACTIVE_STATUSES),
        };
        $where = ["o.status IN ($in)"];
        if ($scope === 'active' && ($filters['status'] ?? '') !== '' && in_array($filters['status'], self::ACTIVE_STATUSES, true)) {
            $where = ['o.status = :status'];
            $params = ['status' => $filters['status']];
        }
        if (($filters['supplier_id'] ?? 0) > 0) {
            $where[] = 'o.supplier_id = :supplier';
            $params['supplier'] = (int) $filters['supplier_id'];
        }
        if (($filters['payment'] ?? '') === 'paid') {
            $where[] = "b.status IN ('paid', 'exempted')";
        } elseif (($filters['payment'] ?? '') === 'unpaid') {
            $where[] = "(b.status IS NULL OR b.status NOT IN ('paid', 'exempted'))";
        }
        $dateColumn = $scope === 'completed' ? 'o.dispensed_at' : 'o.created_at';
        if (($filters['from'] ?? '') !== '') {
            $where[] = "$dateColumn >= :from";
            $params['from'] = $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = "$dateColumn < :to";
            $params['to'] = $filters['to'];
        }
        $rows = Database::all(
            self::ORDER_SELECT . ' WHERE ' . implode(' AND ', $where)
            . ($scope === 'completed' ? ' ORDER BY o.dispensed_at DESC, o.id DESC' : ' ORDER BY v.is_emergency DESC, v.is_fast_track DESC, o.created_at, o.id')
            . ' LIMIT 500',
            $params
        );
        return self::searchFilter(array_map(static fn (array $r) => self::present($r), $rows), (string) ($filters['search'] ?? ''));
    }

    private static function searchFilter(array $rows, string $search): array
    {
        if (trim($search) === '') {
            return $rows;
        }
        $needle = mb_strtolower(trim($search));
        return array_values(array_filter($rows, static fn (array $row) => str_contains(mb_strtolower($row['patient']['full_name']), $needle)
            || str_contains(mb_strtolower($row['patient']['reg_no']), $needle)
            || str_contains(mb_strtolower((string) ($row['patient']['phone'] ?? '')), $needle)
            || str_contains(mb_strtolower($row['order_no'] ?? ''), $needle)
            || str_contains(mb_strtolower($row['bill']['bill_no'] ?? ''), $needle)));
    }

    public static function find(int $id): array
    {
        return Database::one(self::ORDER_SELECT . ' WHERE o.id = :id', ['id' => $id])
            ?? throw HttpException::notFound('Order not found.');
    }

    public static function show(int $id, AuthContext $ctx): array
    {
        $row = self::find($id);
        $order = self::present($row);
        $order['items'] = self::items($id);
        $order['prescription'] = $row['assessment_id'] === null ? null : self::prescription((int) $row['assessment_id']);
        $order['change_reason'] = Crypto::decrypt($row['change_reason_enc'], 'optical_orders.change_reason');
        $order['lens_changes'] = self::lensChanges($id);
        $order['lens_change_blocked'] = self::lensChangeBlocked($row, $order['bill']);
        if ($row['patient_id'] !== null) {
            \App\Core\Audit::access((int) $row['patient_id'], 'optical', 'view', 'optical_order', $row['order_no']);
        }
        return $order;
    }

    private static function present(array $r): array
    {
        $paid = in_array($r['bill_status'], ['paid', 'exempted'], true);
        // An external customer is shown in the same shape as a patient, with their EXT number.
        $customer = $r['patient_id'] !== null
            ? PatientService::brief($r) + ['id' => (int) $r['patient_id'], 'phone' => Crypto::decrypt($r['phone_enc'], 'patients.phone')]
            : [
                'id' => null, 'reg_no' => $r['walkin_no'], 'gender' => $r['walkin_gender'],
                'full_name' => (string) Crypto::decrypt($r['walkin_name_enc'], 'pharmacy_walkins.name'),
                'age' => $r['age_years'] === null ? null : (int) $r['age_years'],
                'phone' => Crypto::decrypt($r['walkin_phone_enc'], 'pharmacy_walkins.phone'),
            ];
        return [
            'id' => (int) $r['id'],
            'order_no' => $r['order_no'],
            'order_type' => $r['order_type'],
            'customer_type' => $r['patient_id'] !== null ? 'patient' : 'external',
            'original_order_no' => $r['original_order_no'],
            'old_frame' => $r['old_frame'],
            'item_names' => $r['item_names'] ?? '',
            'status' => $r['status'],
            'total_amount' => (float) $r['total_amount'],
            'supplier' => $r['supplier'],
            'supplier_id' => $r['supplier_id'] === null ? null : (int) $r['supplier_id'],
            'supplier_order_date' => $r['supplier_order_date'],
            'supplier_ref' => $r['supplier_ref'],
            'lens_received_date' => $r['lens_received_date'],
            'ready_date' => $r['ready_date'],
            'dispensed_at' => Format::utc($r['dispensed_at']),
            'dispensed_by' => $r['dispensed_by_name'] ?: null,
            'cancelled_at' => Format::utc($r['cancelled_at']),
            'visit_id' => $r['visit_id'] === null ? null : (int) $r['visit_id'],
            'visit_no' => $r['visit_no'],
            'is_fast_track' => (bool) $r['visit_fast_track'],
            'assessment_id' => $r['assessment_id'] === null ? null : (int) $r['assessment_id'],
            'optometrist' => $r['optometrist_name'] ?: '—',
            'prescription_date' => Format::utc($r['prescription_date']),
            'is_paid' => $paid,
            'bill' => $r['bill_id'] === null ? null : [
                'id' => (int) $r['bill_id'], 'bill_no' => $r['bill_no'], 'status' => $r['bill_status'],
                'amount' => (float) $r['bill_amount'], 'paid_amount' => (float) $r['paid_amount'],
            ],
            'created_at' => Format::utc($r['created_at']),
            'created_by' => $r['created_by_name'],
            'row_version' => (int) $r['row_version'],
            'patient' => $customer,
        ];
    }

    public static function items(int $orderId): array
    {
        return array_map(static fn (array $r) => [
            'kind' => $r['kind'],
            'set_no' => $r['set_no'] === null ? null : (int) $r['set_no'],
            'rx_label' => $r['rx_label'],
            'price_item_id' => $r['price_item_id'] === null ? null : (int) $r['price_item_id'],
            'frame_id' => $r['frame_id'] === null ? null : (int) $r['frame_id'],
            'description' => $r['description'], 'unit_price' => (float) $r['unit_price'],
        ], Database::all('SELECT kind, set_no, rx_label, price_item_id, frame_id, description, unit_price FROM optical_order_items WHERE order_id = :o ORDER BY id', ['o' => $orderId]));
    }

    /** The lens items on the price list, for an external sale. */
    public static function lensItems(): array
    {
        return array_map(static fn (array $r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'unit_price' => (float) $r['unit_price']],
            Database::all("SELECT id, name, unit_price FROM price_items WHERE category = 'lens' AND is_active = 1 ORDER BY name"));
    }

    /**
     * Writes an order, its lines and its bill. Lines are lenses ({price_item_id, name, unit_price,
     * set_no?, label?}) and frames ({frame, set_no?, label?}, one per pair), the frame priced for
     * this customer. A line that belongs to a labelled prescription says so ("Reading — …").
     *
     * @param array{patient_id:?int, walkin_id:?int, visit_id:?int, assessment_id:?int} $owner
     */
    private static function raiseOrder(string $type, array $owner, array $lenses, array $frames, array $extra, string $billLabel, AuthContext $ctx): int
    {
        $lines = array_map(static fn (array $l) => [
            'kind' => 'lens', 'price_item_id' => $l['price_item_id'], 'frame_id' => null,
            'set_no' => $l['set_no'] ?? null, 'rx_label' => $l['label'] ?? null,
            'description' => mb_substr((($l['label'] ?? null) ? "{$l['label']} — " : '') . $l['name'], 0, 190), 'unit_price' => (float) $l['unit_price'],
        ], $lenses);
        if ($frames !== []) {
            $price = FrameService::price($owner['walkin_id'] !== null);
            foreach ($frames as $f) {
                $lines[] = [
                    'kind' => 'frame', 'price_item_id' => (int) $price['id'], 'frame_id' => $f['frame']['id'],
                    'set_no' => $f['set_no'] ?? null, 'rx_label' => $f['label'] ?? null,
                    'description' => mb_substr((($f['label'] ?? null) ? "Frame ({$f['label']}): " : 'Frame: ') . $f['frame']['label'], 0, 190),
                    'unit_price' => (float) $price['unit_price'],
                ];
            }
        }
        if ($lines === []) {
            throw HttpException::validation(['items' => 'Choose a frame or at least one lens.']);
        }
        $key = Format::localDateKey();
        $orderId = Database::insert(
            'INSERT INTO optical_orders (order_no, order_type, patient_id, walkin_id, visit_id, assessment_id, original_order_id, old_frame,
                                         change_reason_enc, status, total_amount, created_at, created_by)
             VALUES (:no, :type, :patient, :walkin, :visit, :assessment, :original, :old, :reason, :status, :total, UTC_TIMESTAMP(3), :by)',
            [
                'no' => sprintf('OPT-%s-%03d', $key, Sequence::next("optical:$key")), 'type' => $type,
                'patient' => $owner['patient_id'], 'walkin' => $owner['walkin_id'], 'visit' => $owner['visit_id'],
                'assessment' => $owner['assessment_id'], 'original' => $extra['original_order_id'] ?? null,
                'old' => $extra['old_frame'] ?? null,
                'reason' => Crypto::encrypt($extra['change_reason'] ?? null, 'optical_orders.change_reason'),
                'status' => 'ordered', 'total' => round(array_sum(array_column($lines, 'unit_price')), 2), 'by' => $ctx->userId,
            ]
        );
        foreach ($lines as $line) {
            Database::execute(
                'INSERT INTO optical_order_items (order_id, kind, set_no, rx_label, price_item_id, frame_id, description, unit_price)
                 VALUES (:o, :k, :s, :l, :p, :f, :d, :u)',
                ['o' => $orderId, 'k' => $line['kind'], 's' => $line['set_no'], 'l' => $line['rx_label'], 'p' => $line['price_item_id'],
                 'f' => $line['frame_id'], 'd' => $line['description'], 'u' => $line['unit_price']]
            );
        }
        $billId = BillingService::createBill(
            $owner['patient_id'], $owner['visit_id'], 'optical', mb_substr("$billLabel — " . implode(', ', array_column($lines, 'description')), 0, 255),
            array_map(static fn (array $l) => [
                'price_item_id' => $l['price_item_id'], 'description' => $l['description'], 'quantity' => 1, 'unit_price' => $l['unit_price'],
            ], $lines),
            false, null, false, $ctx->userId, 'optical_order', $orderId, $owner['walkin_id']
        );
        Database::execute('UPDATE optical_orders SET bill_id = :b WHERE id = :id', ['b' => $billId, 'id' => $orderId]);
        return $orderId;
    }

    /** Someone who is not a patient buys a frame, lenses, or both. */
    public static function createExternal(array $customer, ?int $frameId, array $lensIds, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($customer, $frameId, $lensIds, $ctx): array {
            $frames = $frameId === null ? [] : [['frame' => FrameService::assertAvailable($frameId)]];
            $lenses = [];
            foreach (array_unique($lensIds) as $lensId) {
                $item = Database::one("SELECT id, name, unit_price FROM price_items WHERE id = :id AND category = 'lens' AND is_active = 1", ['id' => $lensId])
                    ?? throw HttpException::validation(['lens_ids' => 'A lens is no longer on the price list.']);
                $lenses[] = ['price_item_id' => (int) $item['id'], 'name' => $item['name'], 'unit_price' => (float) $item['unit_price']];
            }
            $walkinId = PharmacyService::createWalkin($customer, $ctx->userId);
            $orderId = self::raiseOrder('external', ['patient_id' => null, 'walkin_id' => $walkinId, 'visit_id' => null, 'assessment_id' => null],
                $lenses, $frames, [], 'Spectacles (external)', $ctx);
            self::refreshStatus($orderId, $ctx->userId);
            return self::present(self::find($orderId));
        });
    }

    /**
     * Another pair of spectacles on the same prescription: the optometrist's lenses again, a
     * frame of the patient's choice, at full price. Its own order, bill and hand-over.
     */
    public static function createExtraPair(int $originalId, ?int $frameId, int $setNo, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($originalId, $frameId, $setNo, $ctx): array {
            $original = self::lock($originalId);
            if ($original['assessment_id'] === null || $original['patient_id'] === null) {
                throw HttpException::conflict('no_prescription', 'Another pair can only be made from a patient\'s optometry prescription.');
            }
            if (in_array($original['status'], ['cancelled', 'cancellation_requested'], true)) {
                throw HttpException::conflict('order_state', 'This order is cancelled; raise the extra pair from an order that stands.');
            }
            // The extra pair repeats one of the visit's prescriptions, chosen by the optician.
            $lenses = self::lensChoice((int) $original['assessment_id'], $setNo);
            if ($lenses === []) {
                throw HttpException::validation(['set_no' => 'Optometry did not record a lens on this prescription.']);
            }
            $frames = $frameId === null ? [] : [['frame' => FrameService::assertAvailable($frameId), 'set_no' => $setNo, 'label' => $lenses[0]['label']]];
            $orderId = self::raiseOrder('extra_pair', [
                'patient_id' => (int) $original['patient_id'], 'walkin_id' => null,
                'visit_id' => $original['visit_id'] === null ? null : (int) $original['visit_id'],
                'assessment_id' => (int) $original['assessment_id'],
            ], $lenses, $frames, ['original_order_id' => $originalId], "Extra pair (with {$original['order_no']})", $ctx);
            return self::present(self::find($orderId));
        });
    }

    /**
     * A broken or unwanted frame replaced on spectacles bought earlier. The lenses stay; the new
     * frame is charged at the normal frame price for this customer.
     */
    public static function createFrameChange(int $originalId, int $frameId, ?string $oldFrame, string $reason, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($originalId, $frameId, $oldFrame, $reason, $ctx): array {
            $original = self::lock($originalId);
            if ($original['status'] !== 'dispensed') {
                throw HttpException::conflict('order_state', 'A frame can only be changed on spectacles that have been handed over.');
            }
            $frames = [['frame' => FrameService::assertAvailable($frameId)]];
            $previous = Database::value(
                "SELECT description FROM optical_order_items WHERE order_id = :o AND kind = 'frame' ORDER BY id DESC LIMIT 1",
                ['o' => $originalId]
            );
            $old = $oldFrame ?? ($previous === null ? null : preg_replace('/^Frame( \([^)]*\))?: /', '', (string) $previous));
            $orderId = self::raiseOrder('frame_change', [
                'patient_id' => $original['patient_id'] === null ? null : (int) $original['patient_id'],
                'walkin_id' => $original['walkin_id'] === null ? null : (int) $original['walkin_id'],
                'visit_id' => null, 'assessment_id' => null,
            ], [], $frames, [
                'original_order_id' => $originalId, 'old_frame' => $old === null ? null : mb_substr($old, 0, 190), 'change_reason' => $reason,
            ], "Frame change on {$original['order_no']}", $ctx);
            self::refreshStatus($orderId, $ctx->userId);
            return self::present(self::find($orderId));
        });
    }

    /**
     * Confirms the referral as one order for the pairs the patient takes now ($setNos; null = all),
     * with a frame for each pair that takes one ([set_no => frame_id]), and raises one cashier bill.
     * Pairs left out wait in Incoming at the lens price of today; ordering them later uses that
     * locked price (a lens changed then is priced on that day).
     */
    public static function createOrder(int $assessmentId, array $frameIdsBySet, AuthContext $ctx, array $lensChanges = [], ?array $setNos = null): array
    {
        return Database::transaction(function () use ($assessmentId, $frameIdsBySet, $ctx, $lensChanges, $setNos): array {
            $assessment = Database::one(
                "SELECT a.id, a.visit_id, a.status, a.refer_optical, v.patient_id
                   FROM optometry_assessments a JOIN visits v ON v.id = a.visit_id
                  WHERE a.id = :a FOR UPDATE",
                ['a' => $assessmentId]
            ) ?? throw HttpException::notFound('The optometry record was not found.');
            if ($assessment['status'] !== 'completed' || (int) $assessment['refer_optical'] !== 1) {
                throw HttpException::conflict('not_referred', 'This patient has not been referred to the optical room.');
            }
            // First order on this prescription, or a later one for pairs left waiting.
            $later = (bool) Database::value("SELECT 1 FROM optical_orders WHERE assessment_id = :a AND order_type = 'referral'", ['a' => $assessmentId]);
            if ($later) {
                $waiting = self::waitingPairs($assessmentId, true);
                if ($waiting === []) {
                    throw HttpException::conflict('already_ordered', 'Every pair on this prescription has already been ordered.');
                }
                $lenses = array_merge(...array_map(static fn (array $pair) => $pair['lenses'], $waiting));
            } else {
                $lenses = self::lensChoice($assessmentId);
            }
            if ($lenses === []) {
                throw HttpException::conflict('no_lens', 'Optometry did not record a lens. Send the prescription back to optometry.');
            }
            $labels = array_column($lenses, 'label', 'set_no');
            // Which pairs the patient takes now; the others wait (first order) or keep waiting.
            $available = array_map('intval', array_keys($labels));
            $chosen = $setNos === null ? $available : array_values(array_unique(array_map('intval', $setNos)));
            if (array_diff($chosen, $available)) {
                throw HttpException::validation(['pairs' => 'A chosen pair is not waiting on this prescription.']);
            }
            if ($chosen === []) {
                throw HttpException::validation(['pairs' => 'Choose at least one pair to order now.']);
            }
            $allLenses = $lenses;
            $lenses = array_values(array_filter($lenses, static fn (array $l) => in_array($l['set_no'], $chosen, true)));
            $lensChanges = array_filter($lensChanges, static fn ($setNo) => in_array((int) $setNo, $chosen, true), ARRAY_FILTER_USE_KEY);
            $frameIdsBySet = array_filter($frameIdsBySet, static fn ($setNo) => in_array((int) $setNo, $chosen, true), ARRAY_FILTER_USE_KEY);
            // The patient wants another lens for a pair: [set_no => {lens_ids, reason}].
            $changes = [];
            foreach ($lensChanges as $setNo => $change) {
                if (!array_key_exists((int) $setNo, $labels)) {
                    throw HttpException::validation(['lenses' => 'A lens was changed on a prescription that is not on this visit.']);
                }
                $before = array_values(array_filter($lenses, static fn (array $l) => $l['set_no'] === (int) $setNo));
                $after = array_map(static fn (array $item) => $item + ['set_no' => (int) $setNo, 'label' => $labels[(int) $setNo]],
                    self::lensItemsById($change['lens_ids']));
                if (self::sameLenses($before, $after)) {
                    continue;
                }
                $lenses = array_merge(array_values(array_filter($lenses, static fn (array $l) => $l['set_no'] !== (int) $setNo)), $after);
                $changes[] = ['set_no' => (int) $setNo, 'label' => $labels[(int) $setNo], 'before' => $before, 'after' => $after, 'reason' => $change['reason']];
            }
            usort($lenses, static fn (array $a, array $b) => [$a['set_no'], $a['name']] <=> [$b['set_no'], $b['name']]);
            $frames = [];
            $wanted = array_count_values(array_map('intval', array_filter($frameIdsBySet)));
            foreach ($wanted as $frameId => $count) {
                FrameService::assertAvailable($frameId, $count);
            }
            foreach ($frameIdsBySet as $setNo => $frameId) {
                if (!$frameId) {
                    continue;
                }
                if (!array_key_exists((int) $setNo, $labels)) {
                    throw HttpException::validation(['frames' => 'A frame was chosen for a prescription that is not on this visit.']);
                }
                $frames[] = ['frame' => FrameService::find((int) $frameId), 'set_no' => (int) $setNo, 'label' => $labels[(int) $setNo]];
            }
            $orderId = self::raiseOrder('referral', [
                'patient_id' => (int) $assessment['patient_id'], 'walkin_id' => null,
                'visit_id' => (int) $assessment['visit_id'], 'assessment_id' => $assessmentId,
            ], $lenses, $frames, [], 'Spectacles', $ctx);
            foreach ($changes as $change) {
                self::recordLensChange($orderId, $change, 'confirm', null, $ctx->userId);
            }
            if ($later) {
                [$in, $params] = Database::in('s', $chosen);
                Database::execute(
                    "UPDATE optical_waiting_pairs SET status = 'ordered', order_id = :o WHERE assessment_id = :a AND status = 'waiting' AND set_no IN ($in)",
                    $params + ['o' => $orderId, 'a' => $assessmentId]
                );
            } else {
                // The pairs not taken now wait, their lens price locked as of today.
                foreach (array_diff($available, $chosen) as $setNo) {
                    $pairId = Database::insert(
                        'INSERT INTO optical_waiting_pairs (assessment_id, set_no, rx_label, first_order_id, created_at, created_by)
                         VALUES (:a, :s, :l, :o, UTC_TIMESTAMP(3), :by)',
                        ['a' => $assessmentId, 's' => $setNo, 'l' => $labels[$setNo], 'o' => $orderId, 'by' => $ctx->userId]
                    );
                    foreach (array_filter($allLenses, static fn (array $l) => $l['set_no'] === $setNo) as $lens) {
                        Database::execute(
                            'INSERT INTO optical_waiting_pair_lenses (pair_id, price_item_id, name, unit_price) VALUES (:p, :i, :n, :u)',
                            ['p' => $pairId, 'i' => $lens['price_item_id'], 'n' => mb_substr($lens['name'], 0, 190), 'u' => $lens['unit_price']]
                        );
                    }
                }
            }
            return self::present(self::find($orderId));
        });
    }

    // ─── Pairs left to order later ───────────────────────────────────────

    /**
     * Pairs of a prescription waiting to be ordered, each with its lenses at the locked price,
     * in the same shape as lensChoice().
     *
     * @return list<array{id:int, set_no:int, label:?string, since:?string, lenses:list<array>}>
     */
    public static function waitingPairs(int $assessmentId, bool $lock = false): array
    {
        $pairs = Database::all(
            "SELECT id, set_no, rx_label, created_at FROM optical_waiting_pairs WHERE assessment_id = :a AND status = 'waiting' ORDER BY set_no"
            . ($lock ? ' FOR UPDATE' : ''),
            ['a' => $assessmentId]
        );
        return array_map(static fn (array $p) => [
            'id' => (int) $p['id'], 'set_no' => (int) $p['set_no'], 'label' => $p['rx_label'], 'since' => Format::utc($p['created_at']),
            'lenses' => array_map(static fn (array $l) => [
                'price_item_id' => (int) $l['price_item_id'], 'name' => $l['name'], 'unit_price' => (float) $l['unit_price'],
                'set_no' => (int) $p['set_no'], 'label' => $p['rx_label'],
            ], Database::all('SELECT price_item_id, name, unit_price FROM optical_waiting_pair_lenses WHERE pair_id = :p ORDER BY name', ['p' => (int) $p['id']])),
        ], $pairs);
    }

    /** The patient will not order the waiting pairs (no longer wanted, bought elsewhere); the reason is kept. */
    public static function closeWaiting(int $assessmentId, string $reason, AuthContext $ctx): int
    {
        return Database::transaction(function () use ($assessmentId, $reason, $ctx): int {
            $count = Database::execute(
                "UPDATE optical_waiting_pairs SET status = 'closed', closed_at = UTC_TIMESTAMP(3), closed_by = :by, close_reason_enc = :r
                  WHERE assessment_id = :a AND status = 'waiting'",
                ['by' => $ctx->userId, 'r' => Crypto::encrypt($reason, 'optical_waiting_pairs.close_reason'), 'a' => $assessmentId]
            );
            if ($count === 0) {
                throw HttpException::conflict('nothing_waiting', 'No pairs are waiting on this prescription.');
            }
            return $count;
        });
    }

    // ─── Changing the lens ───────────────────────────────────────────────

    /** Why the lens of an order can no longer be changed, or null when it can. */
    private static function lensChangeBlocked(array $order, ?array $bill): ?string
    {
        return match (true) {
            $order['status'] !== 'ordered' => 'The lens has already been ordered from the supplier. Cancel the order and raise a new one.',
            $bill === null => 'This order has no bill to re-price.',
            $bill['status'] === 'discount_requested' || $bill['status'] === 'cancellation_requested'
                => 'The bill is waiting for an approval decision. Try again once it is decided.',
            $bill['status'] !== 'pending' || $bill['paid_amount'] > 0 => 'The bill has been paid, so the order cannot be changed. Cancel the order and raise a new one.',
            default => null,
        };
    }

    /**
     * The patient wants another lens on an order that is not yet with the supplier and not paid:
     * the pair's lens lines are replaced at today's price, the unpaid bill is cancelled and raised
     * again for the new total (an approved discount does not carry over; ask again), and the
     * change is kept with its reason. The prescription values never change.
     */
    public static function changeLens(int $id, ?int $setNo, array $lensIds, string $reason, int $rowVersion, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $setNo, $lensIds, $reason, $rowVersion, $ctx): array {
            $order = self::lock($id);
            if ((int) $order['row_version'] !== $rowVersion) {
                throw HttpException::conflict('stale', 'Someone else changed this order. Reload and try again.');
            }
            $bill = $order['bill_id'] === null ? null : BillingService::lockBill((int) $order['bill_id']);
            $blocked = self::lensChangeBlocked($order, $bill === null ? null : ['status' => $bill['status'], 'paid_amount' => (float) $bill['paid_amount']]);
            if ($blocked !== null) {
                throw HttpException::conflict('lens_locked', $blocked);
            }
            $lines = Database::all("SELECT * FROM optical_order_items WHERE order_id = :o AND kind = 'lens' ORDER BY id", ['o' => $id]);
            $before = array_values(array_filter($lines, static fn (array $l) => ($l['set_no'] === null ? null : (int) $l['set_no']) === $setNo));
            if ($before === []) {
                throw HttpException::validation(['set_no' => 'This order has no lens on that prescription.']);
            }
            $label = $before[0]['rx_label'];
            $after = array_map(static fn (array $item) => $item + ['set_no' => $setNo, 'label' => $label], self::lensItemsById($lensIds));
            $beforeLenses = array_map(static fn (array $l) => ['price_item_id' => (int) $l['price_item_id'], 'name' => $l['description'], 'unit_price' => (float) $l['unit_price']], $before);
            if (self::sameLenses($beforeLenses, $after)) {
                throw HttpException::validation(['lens_ids' => 'Choose a different lens; this is the lens already on the order.']);
            }

            Database::execute('DELETE FROM optical_order_items WHERE order_id = :o AND kind = \'lens\' AND ' . ($setNo === null ? 'set_no IS NULL' : 'set_no = :s'),
                $setNo === null ? ['o' => $id] : ['o' => $id, 's' => $setNo]);
            foreach ($after as $lens) {
                Database::execute(
                    "INSERT INTO optical_order_items (order_id, kind, set_no, rx_label, price_item_id, frame_id, description, unit_price)
                     VALUES (:o, 'lens', :s, :l, :p, NULL, :d, :u)",
                    ['o' => $id, 's' => $setNo, 'l' => $label, 'p' => $lens['price_item_id'],
                     'd' => mb_substr(($label ? "$label — " : '') . $lens['name'], 0, 190), 'u' => $lens['unit_price']]
                );
            }
            $items = self::items($id);
            $total = round(array_sum(array_column($items, 'unit_price')), 2);
            Database::execute(
                "UPDATE bills SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(3), cancelled_by = :by,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1 WHERE id = :id",
                ['by' => $ctx->userId, 'by2' => $ctx->userId, 'id' => (int) $bill['id']]
            );
            $billId = BillingService::createBill(
                $order['patient_id'] === null ? null : (int) $order['patient_id'],
                $order['visit_id'] === null ? null : (int) $order['visit_id'],
                'optical', mb_substr("Spectacles {$order['order_no']} (lens changed) — " . implode(', ', array_column($items, 'description')), 0, 255),
                array_map(static fn (array $l) => ['price_item_id' => $l['price_item_id'], 'description' => $l['description'], 'quantity' => 1, 'unit_price' => $l['unit_price']], $items),
                false, null, false, $ctx->userId, 'optical_order', $id, $order['walkin_id'] === null ? null : (int) $order['walkin_id']
            );
            Database::execute(
                'UPDATE optical_orders SET bill_id = :b, total_amount = :t, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id',
                ['b' => $billId, 't' => $total, 'by' => $ctx->userId, 'id' => $id]
            );
            self::recordLensChange($id, ['set_no' => $setNo, 'label' => $label, 'before' => $beforeLenses, 'after' => $after, 'reason' => $reason],
                'order', (int) $bill['id'], $ctx->userId);
            self::refreshStatus($id, $ctx->userId);
            return self::show($id, $ctx) + ['old_bill_no' => $bill['bill_no']];
        });
    }

    /** @return list<array{price_item_id:int, name:string, unit_price:float}> active lenses from the price list, at today's price */
    private static function lensItemsById(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw HttpException::validation(['lens_ids' => 'Choose at least one lens.']);
        }
        $out = [];
        foreach ($ids as $lensId) {
            $item = Database::one("SELECT id, name, unit_price FROM price_items WHERE id = :id AND category = 'lens' AND is_active = 1", ['id' => $lensId])
                ?? throw HttpException::validation(['lens_ids' => 'A lens is no longer on the price list.']);
            $out[] = ['price_item_id' => (int) $item['id'], 'name' => $item['name'], 'unit_price' => (float) $item['unit_price']];
        }
        return $out;
    }

    private static function sameLenses(array $a, array $b): bool
    {
        $ids = static function (array $list): array {
            $out = array_map(static fn (array $l) => (int) $l['price_item_id'], $list);
            sort($out);
            return $out;
        };
        return $ids($a) === $ids($b);
    }

    private static function recordLensChange(int $orderId, array $change, string $stage, ?int $oldBillId, int $userId): void
    {
        $names = static fn (array $list) => mb_substr(implode(', ', array_map(static fn (array $l) => preg_replace('/^.* — /u', '', $l['name']), $list)), 0, 500);
        $sum = static fn (array $list) => round(array_sum(array_map(static fn (array $l) => (float) $l['unit_price'], $list)), 2);
        Database::execute(
            'INSERT INTO optical_lens_changes (order_id, set_no, rx_label, stage, from_lenses, to_lenses, from_amount, to_amount, reason_enc, old_bill_id, changed_at, changed_by)
             VALUES (:o, :s, :l, :stage, :from, :to, :fa, :ta, :r, :bill, UTC_TIMESTAMP(3), :by)',
            [
                'o' => $orderId, 's' => $change['set_no'], 'l' => $change['label'], 'stage' => $stage,
                'from' => $names($change['before']), 'to' => $names($change['after']),
                'fa' => $sum($change['before']), 'ta' => $sum($change['after']),
                'r' => Crypto::encrypt($change['reason'], 'optical_lens_changes.reason'), 'bill' => $oldBillId, 'by' => $userId,
            ]
        );
    }

    public static function lensChanges(int $orderId): array
    {
        return array_map(static fn (array $r) => [
            'set_no' => $r['set_no'] === null ? null : (int) $r['set_no'], 'rx_label' => $r['rx_label'], 'stage' => $r['stage'],
            'from' => $r['from_lenses'], 'to' => $r['to_lenses'], 'from_amount' => (float) $r['from_amount'], 'to_amount' => (float) $r['to_amount'],
            'reason' => Crypto::decrypt($r['reason_enc'], 'optical_lens_changes.reason'), 'old_bill_no' => $r['old_bill_no'],
            'changed_at' => Format::utc($r['changed_at']), 'changed_by' => $r['changed_by_name'],
        ], Database::all(
            "SELECT c.*, ob.bill_no AS old_bill_no, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS changed_by_name
               FROM optical_lens_changes c JOIN users u ON u.id = c.changed_by LEFT JOIN bills ob ON ob.id = c.old_bill_id
              WHERE c.order_id = :o ORDER BY c.changed_at, c.id",
            ['o' => $orderId]
        ));
    }

    /** Supplier and production tracking; the status follows from the dates and the bill. */
    public static function track(int $id, array $data, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $data, $ctx): array {
            $order = self::lock($id);
            if (!in_array($order['status'], ['ordered', 'sent_to_supplier', 'lens_received', 'ready_for_dispensing'], true)) {
                throw HttpException::conflict('order_state', 'This order can no longer be changed.');
            }
            if ((int) $order['row_version'] !== (int) $data['row_version']) {
                throw HttpException::conflict('stale', 'Someone else changed this order. Reload and try again.');
            }
            Database::execute(
                'UPDATE optical_orders SET supplier_id = :supplier, supplier_order_date = :ordered, supplier_ref = :ref,
                        lens_received_date = :received, ready_date = :ready, updated_at = UTC_TIMESTAMP(3), updated_by = :by,
                        row_version = row_version + 1
                  WHERE id = :id',
                [
                    'supplier' => $data['supplier_id'], 'ordered' => $data['supplier_order_date'], 'ref' => $data['supplier_ref'],
                    'received' => $data['lens_received_date'], 'ready' => $data['ready_date'], 'by' => $ctx->userId, 'id' => $id,
                ]
            );
            self::refreshStatus($id, $ctx->userId);
            return self::present(self::find($id));
        });
    }

    /**
     * Keeps the status in step with the tracking dates and the bill:
     * lens in and work finished and bill settled → ready to hand over.
     */
    public static function refreshStatus(int $id, int $userId): void
    {
        $order = Database::one(
            "SELECT o.*, b.status AS bill_status FROM optical_orders o LEFT JOIN bills b ON b.id = o.bill_id WHERE o.id = :id FOR UPDATE",
            ['id' => $id]
        );
        if ($order === null || !in_array($order['status'], ['ordered', 'sent_to_supplier', 'lens_received', 'ready_for_dispensing'], true)) {
            return;
        }
        $paid = in_array($order['bill_status'], ['paid', 'exempted'], true);
        // A frame on its own (a frame change, or an external sale without lenses) needs no supplier.
        $hasLens = (bool) Database::value("SELECT 1 FROM optical_order_items WHERE order_id = :o AND kind = 'lens' LIMIT 1", ['o' => $id]);
        $status = 'ordered';
        if (!$hasLens) {
            $status = $paid ? 'ready_for_dispensing' : 'ordered';
        } elseif ($order['supplier_order_date'] !== null) {
            $status = 'sent_to_supplier';
        }
        if ($hasLens && $order['lens_received_date'] !== null) {
            $status = 'lens_received';
        }
        if ($hasLens && $order['lens_received_date'] !== null && $order['ready_date'] !== null && $paid) {
            $status = 'ready_for_dispensing';
        }
        if ($status !== $order['status']) {
            Database::execute(
                'UPDATE optical_orders SET status = :s, updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1 WHERE id = :id',
                ['s' => $status, 'by' => $userId, 'id' => $id]
            );
        }
    }

    /** Settling the bill can make an order ready to hand over. */
    public static function onBillSettled(array $bill, string $outcome): void
    {
        if (($bill['source_type'] ?? '') !== 'optical_order' || $bill['source_id'] === null) {
            return;
        }
        if ($outcome === 'cancelled') {
            Database::execute(
                'UPDATE optical_orders SET bill_id = NULL, updated_at = UTC_TIMESTAMP(3), row_version = row_version + 1
                  WHERE id = :id AND bill_id = :bill',
                ['id' => (int) $bill['source_id'], 'bill' => (int) $bill['id']]
            );
            return;
        }
        self::refreshStatus((int) $bill['source_id'], (int) ($bill['updated_by'] ?? $bill['created_by']));
    }

    public static function lock(int $id): array
    {
        return Database::one('SELECT * FROM optical_orders WHERE id = :id FOR UPDATE', ['id' => $id])
            ?? throw HttpException::notFound('Order not found.');
    }

    // ─── Dispensing ──────────────────────────────────────────────────────

    /** Spectacles are handed over only once the bill is settled. */
    public static function dispense(int $id, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $ctx): array {
            $order = self::lock($id);
            if ($order['status'] !== 'ready_for_dispensing') {
                throw HttpException::conflict('order_state', 'This order is not ready to be handed over.');
            }
            $billStatus = $order['bill_id'] === null ? null
                : Database::value('SELECT status FROM bills WHERE id = :id', ['id' => (int) $order['bill_id']]);
            if (!in_array($billStatus, ['paid', 'exempted'], true)) {
                throw HttpException::conflict('payment_required', 'The spectacles can only be handed over once the bill is settled.');
            }
            // The frame leaves the Optical Room now; until this moment it was only set aside.
            foreach (Database::all("SELECT frame_id FROM optical_order_items WHERE order_id = :o AND kind = 'frame'", ['o' => $id]) as $line) {
                FrameService::adjust((int) $line['frame_id'], -1, 'sold', $order['order_no'], null, $ctx->userId);
            }
            Database::execute(
                "UPDATE optical_orders SET status = 'dispensed', dispensed_at = UTC_TIMESTAMP(3), dispensed_by = :by,
                        updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1
                  WHERE id = :id",
                ['by' => $ctx->userId, 'by2' => $ctx->userId, 'id' => $id]
            );
            return self::present(self::find($id));
        });
    }

    // ─── Discount and cancellation ───────────────────────────────────────

    /** A discount on the spectacle bill, decided by an administrator. */
    public static function requestDiscount(int $id, float $amount, string $discountType, ?string $sponsor, string $remarks, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $amount, $discountType, $sponsor, $remarks, $ctx): array {
            $order = self::lock($id);
            if ($order['bill_id'] === null) {
                throw HttpException::conflict('no_bill', 'This order has no bill to discount.');
            }
            $bill = BillingService::lockBill((int) $order['bill_id']);
            if ($bill['status'] !== 'pending') {
                throw HttpException::conflict('bill_state', 'Only a bill still waiting for payment can be discounted.');
            }
            $due = (float) $bill['net_amount'] - (float) $bill['paid_amount'];
            if ($amount <= 0 || $amount > $due + 0.001) {
                throw HttpException::validation(['amount' => 'Ask for more than zero and no more than the amount due.']);
            }
            $approval = ApprovalService::create(
                'discount', $order['patient_id'] === null ? null : (int) $order['patient_id'], (int) $bill['id'], null, $amount, $discountType, $sponsor, $remarks, $ctx->userId
            );
            Database::execute(
                "UPDATE bills SET status = 'discount_requested', updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
                  WHERE id = :id",
                ['by' => $ctx->userId, 'id' => (int) $bill['id']]
            );
            return self::present(self::find($id)) + ['approval_no' => $approval['approval_no']];
        });
    }

    public static function requestCancellation(int $id, string $reason, AuthContext $ctx): array
    {
        return Database::transaction(function () use ($id, $reason, $ctx): array {
            $order = self::lock($id);
            if (!in_array($order['status'], ['ordered', 'sent_to_supplier', 'lens_received', 'ready_for_dispensing'], true)) {
                throw HttpException::conflict('order_state', 'This order cannot be cancelled.');
            }
            $approval = ApprovalService::create(
                'optical_cancellation', $order['patient_id'] === null ? null : (int) $order['patient_id'],
                $order['bill_id'] === null ? null : (int) $order['bill_id'],
                null, null, null, null, $reason, $ctx->userId, null, $id
            );
            Database::execute(
                "UPDATE optical_orders SET status = 'cancellation_requested', cancel_approval_id = :a, updated_at = UTC_TIMESTAMP(3),
                        updated_by = :by, row_version = row_version + 1 WHERE id = :id",
                ['a' => $approval['id'], 'by' => $ctx->userId, 'id' => $id]
            );
            return self::present(self::find($id)) + ['approval_no' => $approval['approval_no']];
        });
    }

    /** Called by the approval queue once an administrator has decided. */
    public static function onCancellationDecision(array $request, bool $approved, AuthContext $ctx): void
    {
        $orderId = (int) ($request['optical_order_id'] ?? 0);
        if ($orderId === 0) {
            return;
        }
        $order = self::lock($orderId);
        if ($order['status'] !== 'cancellation_requested') {
            return;
        }
        if (!$approved) {
            Database::execute(
                "UPDATE optical_orders SET status = 'ordered', cancel_approval_id = NULL, updated_at = UTC_TIMESTAMP(3),
                        updated_by = :by, row_version = row_version + 1 WHERE id = :id",
                ['by' => $ctx->userId, 'id' => $orderId]
            );
            self::refreshStatus($orderId, $ctx->userId);
            return;
        }
        Database::execute(
            "UPDATE optical_orders SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3),
                    updated_by = :by, row_version = row_version + 1 WHERE id = :id",
            ['by' => $ctx->userId, 'id' => $orderId]
        );
        // Pairs this order took from the waiting list go back to waiting.
        Database::execute("UPDATE optical_waiting_pairs SET status = 'waiting', order_id = NULL WHERE order_id = :o AND status = 'ordered'", ['o' => $orderId]);
        if ($order['bill_id'] !== null) {
            $bill = BillingService::lockBill((int) $order['bill_id']);
            if (in_array($bill['status'], ['pending', 'discount_requested'], true)) {
                Database::execute(
                    "UPDATE bills SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(3), cancelled_by = :by,
                            updated_at = UTC_TIMESTAMP(3), updated_by = :by2, row_version = row_version + 1 WHERE id = :id",
                    ['by' => $ctx->userId, 'by2' => $ctx->userId, 'id' => (int) $bill['id']]
                );
            }
        }
    }
}
