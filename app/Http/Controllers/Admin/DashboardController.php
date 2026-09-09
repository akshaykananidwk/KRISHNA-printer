<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\PrinterRepository;
use App\Repositories\PrintJobRepository;
use App\Repositories\ReportRepository;
use App\Services\PrinterService;
use App\Support\Money;

final class DashboardController extends Controller
{
    public function __construct(
        private ReportRepository $reports,
        private PrintJobRepository $jobs,
        private PrinterRepository $printers,
        private PrinterService $printerService
    ) {
    }

    /** GET /admin/dashboard */
    public function index(Request $request): Response
    {
        $today = gmdate('Y-m-d');
        $summary = $this->reports->dailySummary($today);
        $counters = $this->reports->operationalCounters();

        // A 30-day window gives the charts enough shape to be useful without
        // making the page slow on a busy install.
        $from = gmdate('Y-m-d', strtotime('-29 days'));
        $series = $this->fillSeries($this->reports->dailySeries($from, $today), $from, $today);

        return $this->view('admin/dashboard', [
            'title' => 'Dashboard',
            'active' => 'dashboard',
            'summary' => $summary,
            'counters' => $counters,
            'cards' => $this->buildCards($summary, $counters),
            'series' => $series,
            'revenueByLocation' => $this->reports->revenueByLocation($from, $today, 8),
            'printerUsage' => $this->reports->printerUsage($from, $today, 8),
            'colorSplit' => $this->reports->colorSplit($from, $today),
            'recentJobs' => $this->jobs->recent(10),
            'offlinePrinters' => $this->offlinePrinters(),
            'rangeFrom' => $from,
            'rangeTo' => $today,
        ]);
    }

    /** GET /admin/dashboard/data — polled for live counters. */
    public function data(Request $request): Response
    {
        $today = gmdate('Y-m-d');
        $summary = $this->reports->dailySummary($today);
        $counters = $this->reports->operationalCounters();

        return $this->json([
            'success' => true,
            'summary' => $summary,
            'counters' => $counters,
            'cards' => $this->buildCards($summary, $counters),
            'updated_at' => gmdate('c'),
        ]);
    }

    /**
     * @param array<string,int> $summary
     * @param array<string,int> $counters
     * @return array<int,array<string,mixed>>
     */
    private function buildCards(array $summary, array $counters): array
    {
        return [
            [
                'key' => 'jobs_today',
                'label' => "Today's Jobs",
                'value' => number_format($summary['jobs_total'] ?? 0),
                'tone' => 'primary',
                'sub' => sprintf('%d active now', $summary['jobs_active'] ?? 0),
            ],
            [
                'key' => 'pages_today',
                'label' => "Today's Pages",
                'value' => number_format($summary['pages_total'] ?? 0),
                'tone' => 'primary',
                'sub' => sprintf('%s sheets', number_format($summary['sheets_total'] ?? 0)),
            ],
            [
                'key' => 'revenue_today',
                'label' => "Today's Revenue",
                'value' => Money::formatWithSymbol($summary['revenue_paise'] ?? 0),
                'tone' => 'success',
                'sub' => 'Completed & paid jobs',
            ],
            [
                'key' => 'color_pages',
                'label' => 'Colour Pages',
                'value' => number_format($summary['pages_color'] ?? 0),
                'tone' => 'info',
                'sub' => 'Today',
            ],
            [
                'key' => 'bw_pages',
                'label' => 'B&W Pages',
                'value' => number_format($summary['pages_bw'] ?? 0),
                'tone' => 'neutral',
                'sub' => 'Today',
            ],
            [
                'key' => 'successful',
                'label' => 'Successful Jobs',
                'value' => number_format($summary['jobs_successful'] ?? 0),
                'tone' => 'success',
                'sub' => 'Today',
            ],
            [
                'key' => 'failed',
                'label' => 'Failed Jobs',
                'value' => number_format($summary['jobs_failed'] ?? 0),
                'tone' => ($summary['jobs_failed'] ?? 0) > 0 ? 'danger' : 'neutral',
                'sub' => 'Today',
            ],
            [
                'key' => 'printers_offline',
                'label' => 'Offline Printers',
                'value' => number_format($counters['printers_offline'] ?? 0),
                'tone' => ($counters['printers_offline'] ?? 0) > 0 ? 'danger' : 'success',
                'sub' => sprintf('%d of %d online', $counters['printers_online'] ?? 0, $counters['printers_total'] ?? 0),
            ],
            [
                'key' => 'active_locations',
                'label' => 'Active Locations',
                'value' => number_format($counters['active_locations'] ?? 0),
                'tone' => 'primary',
                'sub' => sprintf('%d agents online', $counters['agents_online'] ?? 0),
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function offlinePrinters(): array
    {
        $rows = [];
        foreach ($this->printers->all(false) as $printer) {
            if ($printer->status() === 'online') {
                continue;
            }
            $rows[] = [
                'id' => $printer->id(),
                'name' => $printer->string('name'),
                'status' => $printer->status(),
                'status_label' => $printer->statusLabel(),
                'status_tone' => $printer->statusTone(),
                'last_error' => $printer->string('last_error'),
                'last_seen_at' => $printer->string('last_seen_at'),
            ];
        }
        return array_slice($rows, 0, 10);
    }

    /**
     * SQL returns only days that had jobs. The chart needs every day, or a
     * quiet Sunday silently disappears and the axis lies about the trend.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function fillSeries(array $rows, string $from, string $to): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['day']] = $row;
        }

        $series = [];
        $cursor = strtotime($from);
        $end = strtotime($to);

        while ($cursor <= $end) {
            $day = gmdate('Y-m-d', $cursor);
            $row = $indexed[$day] ?? null;

            $series[] = [
                'day' => $day,
                'label' => gmdate('j M', $cursor),
                'jobs' => (int) ($row['jobs'] ?? 0),
                'pages' => (int) ($row['pages'] ?? 0),
                'pages_color' => (int) ($row['pages_color'] ?? 0),
                'pages_bw' => (int) ($row['pages_bw'] ?? 0),
                'failed' => (int) ($row['failed'] ?? 0),
                'revenue_paise' => (int) ($row['revenue_paise'] ?? 0),
                'revenue' => round(((int) ($row['revenue_paise'] ?? 0)) / 100, 2),
            ];

            $cursor = strtotime('+1 day', $cursor);
        }

        return $series;
    }
}
