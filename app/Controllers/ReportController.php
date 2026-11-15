<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Format;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\ReportService;

/** Reports over the live data, and the dashboard. */
final class ReportController
{
    public function options(): array
    {
        $ctx = Auth::require();
        $reports = [];
        foreach (ReportService::REPORTS as $type => $definition) {
            if ($ctx->can($definition['permission'])) {
                $reports[] = ['type' => $type, 'title' => $definition['title'], 'days' => $definition['days']];
            }
        }
        return [
            'reports' => $reports,
            'today' => Format::localToday(),
            'schemes' => array_column(Database::all(
                "SELECT label FROM lookup_values WHERE list_code = 'discount_type' AND is_active = 1 ORDER BY sort_order, label"
            ), 'label'),
            'statuses' => [
                'financial' => ['pending', 'paid', 'exempted', 'cancelled', 'discount_requested', 'partially_refunded', 'refunded'],
                'reception' => ['pending_payment', 'waiting_triage', 'waiting_doctor', 'waiting_optometry',
                                'waiting_ophthalmology', 'waiting_investigation', 'in_progress', 'completed', 'cancelled'],
                'diagnostics' => ['scheduled', 'arrived', 'completed', 'cancellation_requested', 'cancelled'],
                'theatre' => ['booked', 'cleared', 'in_theatre', 'post_op', 'discharged', 'cancelled'],
                'optical' => ['ordered', 'sent_to_supplier', 'lens_received', 'ready_for_dispensing', 'dispensed', 'cancelled'],
                'mtuha_main' => ReportService::MTUHA_OUTCOMES,
                'pharmacy' => ['pending_review', 'awaiting_payment', 'paid', 'partially_dispensed', 'completed', 'cancelled'],
            ],
        ];
    }

    public function run(Request $request): array
    {
        $ctx = Auth::require();
        $type = (string) $request->query('type');
        $report = ReportService::run($type, $this->filters($request), $ctx);
        Audit::log('reports', 'report.viewed',
            "Opened the {$report['title']} report for {$report['from']} to {$report['to']}", 'report', $type);
        return ['report' => $report];
    }

    public function export(Request $request): never
    {
        $ctx = Auth::require();
        $type = (string) $request->query('type');
        $report = ReportService::run($type, $this->filters($request), $ctx);
        Audit::log('reports', 'report.exported',
            "Exported the {$report['title']} report for {$report['from']} to {$report['to']}", 'report', $type);
        $name = sprintf('KSI-%s-%s-to-%s.csv', str_replace(' ', '-', $report['title']), $report['from'], $report['to']);
        Response::file("\xEF\xBB\xBF" . ReportService::csv($report), 'text/csv', $name);
    }

    private function filters(Request $request): array
    {
        return [
            'from' => (string) $request->query('from'),
            'to' => (string) $request->query('to'),
            'status' => (string) $request->query('status'),
            'search' => (string) $request->query('search'),
            'scheme' => (string) $request->query('scheme'),
            'max_age' => (string) $request->query('max_age'),
        ];
    }
}
