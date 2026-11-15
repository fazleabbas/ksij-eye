<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\AuthContext;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\Request;
use App\Services\PatientService;

/**
 * The dashboard: today at a glance, and what is waiting.
 *
 * Everyone sees only the parts they are allowed to open, so a storekeeper is not shown
 * takings and a nurse is not shown purchase orders.
 */
final class DashboardController
{
    public function index(Request $request): array
    {
        $ctx = Auth::require();
        $today = Format::localTodayUtcStart();
        $tomorrow = Format::localDateToUtc(Format::localToday(), true);
        $range = ['from' => $today, 'to' => $tomorrow];

        return [
            'today' => Format::localToday(),
            'tiles' => $this->tiles($ctx, $range),
            'queue' => $ctx->can('visits.view') ? $this->queue($range) : [],
            'waiting' => $this->waiting($ctx),
            'alerts' => $this->alerts($ctx),
        ];
    }

    /** The numbers across the top, each one only if the person may open where it leads. */
    private function tiles(AuthContext $ctx, array $range): array
    {
        $tiles = [];
        if ($ctx->can('patients.view')) {
            // Every patient registered, including those who kept their paper file number.
            $tiles[] = [
                'label' => 'Registered patients',
                'value' => (int) Database::value('SELECT COUNT(*) FROM patients'),
                'module' => 'manage-patient',
            ];
        }
        if ($ctx->can('visits.view')) {
            $tiles[] = [
                'label' => 'Patients today',
                'value' => (int) Database::value(
                    'SELECT COUNT(DISTINCT patient_id) FROM visits WHERE created_at >= :from AND created_at < :to', $range
                ),
                'module' => 'manage-visit',
            ];
            $tiles[] = [
                'label' => 'Still being seen',
                'value' => (int) Database::value(
                    "SELECT COUNT(*) FROM visits WHERE created_at >= :from AND created_at < :to
                       AND status NOT IN ('completed', 'cancelled')", $range
                ),
                'module' => 'manage-visit',
            ];
            $tiles[] = [
                'label' => 'Finished today',
                'value' => (int) Database::value(
                    "SELECT COUNT(*) FROM visits WHERE created_at >= :from AND created_at < :to AND status = 'completed'", $range
                ),
                'module' => 'manage-visit',
            ];
        }
        if ($ctx->can('payments.view')) {
            $tiles[] = [
                'label' => 'Bills waiting for payment',
                'value' => (int) Database::value("SELECT COUNT(*) FROM bills WHERE status = 'pending'"),
                'module' => 'cashier-payments',
                'alert' => true,
            ];
            $tiles[] = [
                'label' => 'Collected today',
                'value' => round((float) Database::value(
                    'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE voided_at IS NULL AND received_at >= :from AND received_at < :to',
                    $range
                ), 2),
                'money' => true,
                'module' => 'cashier-history',
            ];
        }
        if ($ctx->can('approvals.view')) {
            $tiles[] = [
                'label' => 'Waiting for approval',
                'value' => (int) Database::value("SELECT COUNT(*) FROM approval_requests WHERE status = 'pending'"),
                'module' => 'approvals-pending',
                'alert' => true,
            ];
        }
        return $tiles;
    }

    /** Who is in the building and where they are up to. */
    private function queue(array $range): array
    {
        $rows = Database::all(
            "SELECT v.id, v.visit_no, v.status, v.destination, v.room, v.is_emergency, v.created_at,
                    p.id AS patient_id, p.reg_no, p.first_name_enc, p.middle_name_enc, p.last_name_enc,
                    p.gender, p.date_of_birth_enc
               FROM visits v
               JOIN patients p ON p.id = v.patient_id
              WHERE v.created_at >= :from AND v.created_at < :to AND v.status NOT IN ('completed', 'cancelled')
              ORDER BY v.is_emergency DESC, v.created_at
              LIMIT 15",
            $range
        );
        return array_map(static fn (array $r) => [
            'visit_id' => (int) $r['id'],
            'visit_no' => $r['visit_no'],
            'status' => $r['status'],
            'destination' => $r['destination'],
            'room' => $r['room'],
            'is_emergency' => (bool) $r['is_emergency'],
            'arrived' => Format::utc($r['created_at']),
            'patient' => PatientService::brief($r) + ['id' => (int) $r['patient_id']],
        ], $rows);
    }

    /** What each department has waiting, for the people who work there. */
    private function waiting(AuthContext $ctx): array
    {
        $sections = [];
        $add = static function (array &$sections, string $permission, AuthContext $ctx, string $title, string $module, array $lines): void {
            if (!$ctx->can($permission)) {
                return;
            }
            $sections[] = ['title' => $title, 'module' => $module, 'lines' => $lines];
        };
        $count = static fn (string $sql, array $params = []): int => (int) Database::value($sql, $params);

        $add($sections, 'triage.view', $ctx, 'Clinical', 'triage', [
            ['label' => 'Waiting for triage', 'value' => $count("SELECT COUNT(*) FROM visits WHERE status = 'waiting_triage'")],
            ['label' => 'Waiting for the doctor', 'value' => $count("SELECT COUNT(*) FROM visits WHERE status IN ('waiting_doctor', 'waiting_ophthalmology')")],
            ['label' => 'Waiting for optometry', 'value' => $count("SELECT COUNT(*) FROM visits WHERE status = 'waiting_optometry'")],
        ]);
        $add($sections, 'diagnostics.booking.view', $ctx, 'Diagnostics', 'diag-booking', [
            ['label' => 'Booked, not yet done', 'value' => $count("SELECT COUNT(*) FROM diagnostic_bookings WHERE status IN ('scheduled', 'arrived')")],
            ['label' => 'Waiting for a report', 'value' => $count("SELECT COUNT(*) FROM diagnostic_bookings WHERE status = 'arrived'")],
        ]);
        $add($sections, 'theatre.booking.view', $ctx, 'Theatre', 'theatre-booking', [
            ['label' => 'Operating today', 'value' => $count(
                "SELECT COUNT(*) FROM theatre_bookings WHERE status IN ('booked', 'cleared', 'in_theatre')
                   AND scheduled_for >= :from AND scheduled_for < :to",
                ['from' => Format::localTodayUtcStart(), 'to' => Format::localDateToUtc(Format::localToday(), true)]
            )],
            ['label' => 'In post-op care', 'value' => $count("SELECT COUNT(*) FROM theatre_bookings WHERE status = 'post_op'")],
        ]);
        $add($sections, 'optical.view', $ctx, 'Optical', 'opt-incoming', [
            ['label' => 'Prescriptions to order', 'value' => $count(
                "SELECT COUNT(*) FROM optometry_assessments a LEFT JOIN optical_orders o ON o.assessment_id = a.id
                  WHERE a.status = 'completed' AND a.refer_optical = 1 AND o.id IS NULL"
            )],
            ['label' => 'Ready to hand over', 'value' => $count("SELECT COUNT(*) FROM optical_orders WHERE status = 'ready_for_dispensing'")],
        ]);
        $add($sections, 'pharmacy.orders.view', $ctx, 'Pharmacy', 'pharm-orders', [
            ['label' => 'Waiting for review', 'value' => $count("SELECT COUNT(*) FROM pharmacy_orders WHERE status = 'pending_review'")],
            ['label' => 'Ready to hand over', 'value' => $count("SELECT COUNT(*) FROM pharmacy_orders WHERE status IN ('paid', 'partially_dispensed')")],
            ['label' => 'Medicines out of stock', 'value' => $count('SELECT COUNT(*) FROM pharmacy_stock WHERE quantity <= 0')],
        ]);
        $add($sections, 'requisitions.manage', $ctx, 'Stores', 'stores-requisition-requests', [
            ['label' => 'Requisitions to decide', 'value' => $count("SELECT COUNT(*) FROM store_requisitions WHERE status = 'submitted'")],
            ['label' => 'Approved, to issue', 'value' => $count("SELECT COUNT(*) FROM store_requisitions WHERE status IN ('approved', 'partly_issued')")],
            ['label' => 'Items at or below reorder level', 'value' => $count('SELECT COUNT(*) FROM store_items WHERE is_active = 1 AND quantity <= reorder_level')],
        ]);
        return $sections;
    }

    /** Things that need somebody to act, not just to look. */
    private function alerts(AuthContext $ctx): array
    {
        $alerts = [];
        if ($ctx->can('approvals.view')) {
            $pending = (int) Database::value("SELECT COUNT(*) FROM approval_requests WHERE status = 'pending'");
            if ($pending > 0) {
                $alerts[] = ['text' => "$pending request" . ($pending === 1 ? '' : 's') . ' waiting for a decision', 'module' => 'approvals-pending'];
            }
        }
        if ($ctx->can('pharmacy.inventory.view')) {
            $expiring = (int) Database::value(
                'SELECT COUNT(*) FROM pharmacy_stock WHERE expiry_date IS NOT NULL AND expiry_date <= :soon AND quantity > 0',
                ['soon' => Format::localToday(60)]
            );
            if ($expiring > 0) {
                $alerts[] = ['text' => "$expiring medicine" . ($expiring === 1 ? '' : 's') . ' expiring within 60 days', 'module' => 'pharm-inventory'];
            }
        }
        if ($ctx->can('stores.stock.view')) {
            $expiring = (int) Database::value(
                'SELECT COUNT(DISTINCT item_id) FROM store_item_batches WHERE expiry_date IS NOT NULL AND expiry_date <= :soon AND quantity > 0',
                ['soon' => Format::localToday(60)]
            );
            if ($expiring > 0) {
                $alerts[] = ['text' => "$expiring store item" . ($expiring === 1 ? '' : 's') . ' expiring within 60 days', 'module' => 'stores-stock'];
            }
        }
        if ($ctx->can('visits.followups.view')) {
            $due = (int) Database::value(
                "SELECT COUNT(*) FROM follow_ups WHERE status = 'due' AND due_date <= :today",
                ['today' => Format::localToday()]
            );
            if ($due > 0) {
                $alerts[] = ['text' => "$due follow-up" . ($due === 1 ? '' : 's') . ' now due', 'module' => 'manage-patient'];
            }
        }
        return $alerts;
    }
}
