<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Models\PrintJob;
use App\Repositories\LocationRepository;
use App\Repositories\PrinterRepository;
use App\Repositories\PrintJobRepository;
use App\Repositories\ReportRepository;
use App\Services\AuditService;
use App\Support\Exporter;
use App\Support\Money;

final class ReportController extends Controller
{
    public function __construct(
        private ReportRepository $reports,
        private PrintJobRepository $jobs,
        private LocationRepository $locations,
        private PrinterRepository $printers,
        private AuditService $audit
    ) {
    }

    /** GET /admin/reports */
    public function index(Request $request): Response
    {
        [$from, $to, $preset] = $this->resolveRange($request);
        $filters = $this->filtersFrom($request, $from, $to);
        $groupBy = $this->safeGroupBy($request->string('group_by', $this->defaultGroupFor($preset)));

        $summary = $this->reports->summaryForFilters($filters, $this->jobs);
        $grouped = $this->reports->groupedForFilters($filters, $this->jobs, $groupBy);

        return $this->view('admin/reports/index', [
            'title' => 'Reports',
            'active' => 'reports',
            'from' => $from,
            'to' => $to,
            'preset' => $preset,
            'groupBy' => $groupBy,
            'filters' => $filters,
            'summary' => $summary,
            'grouped' => $grouped,
            'locations' => $this->locations->all(),
            'printers' => $this->printers->all(),
            'statuses' => PrintJob::ALL_STATUSES,
            'paperSizes' => array_keys((array) Config::get('printing.paper_sizes', [])),
            'revenueByLocation' => $this->reports->revenueByLocation($from, $to, 20),
            'printerUsage' => $this->reports->printerUsage($from, $to, 20),
            'colorSplit' => $this->reports->colorSplit(
                $from,
                $to,
                isset($filters['location_id']) ? (int) $filters['location_id'] : null
            ),
            'paperBreakdown' => $this->reports->paperSizeBreakdown(
                $from,
                $to,
                isset($filters['location_id']) ? (int) $filters['location_id'] : null
            ),
            'queryString' => $this->queryString($request),
        ]);
    }

    /**
     * GET /admin/reports/export/{format}
     *
     * Streams the full filtered job list, chunked, so a year of data does not
     * have to fit in memory at once.
     */
    public function export(Request $request): Response
    {
        $format = strtolower((string) $request->routeParam('format'));
        if (!in_array($format, ['csv', 'excel', 'pdf'], true)) {
            return $this->fail('Unsupported export format.', 404);
        }

        [$from, $to, $preset] = $this->resolveRange($request);
        $filters = $this->filtersFrom($request, $from, $to);

        $headers = [
            'Job Number', 'Created (UTC)', 'Completed (UTC)', 'Location', 'Printer',
            'Document', 'Pages', 'Copies', 'Billable Pages', 'Sheets',
            'Colour Mode', 'Paper', 'Duplex', 'Range',
            'Subtotal', 'Tax', 'Gateway Fee', 'Total',
            'Payment', 'Status', 'Error',
        ];

        $rows = [];
        $symbol = (string) Config::get('app.currency_symbol', '₹');

        $count = $this->jobs->eachForExport($filters, static function (array $job) use (&$rows): void {
            $rows[] = [
                (string) $job['job_number'],
                (string) $job['created_at'],
                (string) ($job['completed_at'] ?? ''),
                (string) $job['location_name'],
                (string) $job['printer_name'],
                (string) ($job['file_name'] ?? ''),
                (int) $job['document_pages'],
                (int) $job['copies'],
                (int) $job['billable_pages'],
                (int) $job['sheets'],
                (string) $job['color_mode'] === 'color' ? 'Colour' : 'B&W',
                (string) $job['paper_size'],
                (string) $job['duplex'] === 'double' ? 'Double' : 'Single',
                (string) $job['page_range'],
                round(((int) $job['subtotal_paise']) / 100, 2),
                round(((int) $job['tax_paise']) / 100, 2),
                round(((int) $job['gateway_fee_paise']) / 100, 2),
                round(((int) $job['total_paise']) / 100, 2),
                (string) $job['payment_status'],
                (string) $job['status'],
                (string) ($job['error_message'] ?? ''),
            ];
        });

        $summary = $this->reports->summaryForFilters($filters, $this->jobs);

        $meta = [
            'Report period' => $from . ' to ' . $to . ' (UTC)',
            'Generated' => gmdate('Y-m-d H:i:s') . ' UTC',
            'Jobs' => number_format($count),
            'Pages printed' => number_format($summary['pages_printed'] ?? 0),
            'Revenue' => $symbol . Money::format($summary['revenue_paise'] ?? 0),
            'Filters' => $this->describeFilters($filters),
        ];

        $businessName = (string) Config::get('settings.business_name', Config::get('app.name', 'Krishna Printer'));
        $title = $businessName . ' — Print Report';
        $filename = sprintf('print-report-%s-to-%s', $from, $to);

        $this->audit->log(
            'report.exported',
            sprintf('Exported %d job(s) as %s for %s to %s.', $count, strtoupper($format), $from, $to),
            'report',
            null,
            'info'
        );

        return match ($format) {
            'csv' => Response::download(
                Exporter::csv($headers, $rows),
                $filename . '.csv',
                'text/csv; charset=UTF-8'
            ),
            'excel' => Response::download(
                Exporter::excel($title, $headers, $rows, $meta),
                $filename . '.xls',
                'application/vnd.ms-excel'
            ),
            'pdf' => Response::download(
                // The PDF is a landscape summary; the wide numeric columns are
                // dropped so the remaining ones stay readable on the page.
                Exporter::pdf(
                    $title,
                    ['Job', 'Created', 'Location', 'Printer', 'Pages', 'Mode', 'Paper', 'Total', 'Status'],
                    array_map(static fn (array $r): array => [
                        $r[0], substr((string) $r[1], 0, 16), $r[3], $r[4], $r[8], $r[10], $r[11],
                        number_format((float) $r[17], 2), $r[19],
                    ], $rows),
                    $meta
                ),
                $filename . '.pdf',
                'application/pdf'
            ),
            default => $this->fail('Unsupported format.', 404),
        };
    }

    /** GET /admin/reports/summary-export/{format} — the aggregated view, not the job list. */
    public function exportSummary(Request $request): Response
    {
        $format = strtolower((string) $request->routeParam('format'));
        [$from, $to] = $this->resolveRange($request);
        $filters = $this->filtersFrom($request, $from, $to);
        $groupBy = $this->safeGroupBy($request->string('group_by', 'day'));

        $grouped = $this->reports->groupedForFilters($filters, $this->jobs, $groupBy);

        $headers = [ucfirst(str_replace('_', ' ', $groupBy)), 'Jobs', 'Pages', 'Colour Pages', 'B&W Pages', 'Failed', 'Revenue'];
        $rows = [];

        foreach ($grouped as $row) {
            $rows[] = [
                (string) $row['group_key'],
                (int) $row['jobs'],
                (int) $row['pages'],
                (int) $row['pages_color'],
                (int) $row['pages_bw'],
                (int) $row['failed'],
                round(((int) $row['revenue_paise']) / 100, 2),
            ];
        }

        $meta = [
            'Report period' => $from . ' to ' . $to . ' (UTC)',
            'Grouped by' => $groupBy,
            'Generated' => gmdate('Y-m-d H:i:s') . ' UTC',
        ];

        $title = (string) Config::get('settings.business_name', 'Krishna Printer') . ' — Summary';
        $filename = sprintf('summary-%s-%s-to-%s', $groupBy, $from, $to);

        return match ($format) {
            'csv' => Response::download(Exporter::csv($headers, $rows), $filename . '.csv', 'text/csv; charset=UTF-8'),
            'excel' => Response::download(
                Exporter::excel($title, $headers, $rows, $meta),
                $filename . '.xls',
                'application/vnd.ms-excel'
            ),
            'pdf' => Response::download(
                Exporter::pdf($title, $headers, $rows, $meta),
                $filename . '.pdf',
                'application/pdf'
            ),
            default => $this->fail('Unsupported format.', 404),
        };
    }

    /** @return array{0:string,1:string,2:string} from, to, preset */
    private function resolveRange(Request $request): array
    {
        $preset = $request->string('preset');
        $today = gmdate('Y-m-d');

        $from = $request->string('from');
        $to = $request->string('to');

        $valid = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
            && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));

        if ($preset === '' && $valid($from) && $valid($to)) {
            $preset = 'custom';
        }

        [$from, $to] = match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [gmdate('Y-m-d', strtotime('-1 day')), gmdate('Y-m-d', strtotime('-1 day'))],
            'week' => [gmdate('Y-m-d', strtotime('monday this week')), $today],
            'last_week' => [
                gmdate('Y-m-d', strtotime('monday last week')),
                gmdate('Y-m-d', strtotime('sunday last week')),
            ],
            'month' => [gmdate('Y-m-01'), $today],
            'last_month' => [
                gmdate('Y-m-01', strtotime('first day of last month')),
                gmdate('Y-m-t', strtotime('last day of last month')),
            ],
            'custom' => [$valid($from) ? $from : $today, $valid($to) ? $to : $today],
            default => [gmdate('Y-m-d', strtotime('-29 days')), $today],
        };

        if ($preset === '') {
            $preset = 'last_30';
        }

        // A reversed range would silently return nothing; swap instead.
        if (strtotime($from) > strtotime($to)) {
            [$from, $to] = [$to, $from];
        }

        // Cap the window so one click cannot scan years of rows.
        $maxDays = 400;
        if ((strtotime($to) - strtotime($from)) / 86400 > $maxDays) {
            $from = gmdate('Y-m-d', strtotime($to) - $maxDays * 86400);
        }

        return [$from, $to, $preset];
    }

    /** @return array<string,mixed> */
    private function filtersFrom(Request $request, string $from, string $to): array
    {
        $filters = ['date_from' => $from, 'date_to' => $to];

        foreach (['location_id', 'printer_id'] as $key) {
            $value = $request->int($key, 0);
            if ($value > 0) {
                $filters[$key] = $value;
            }
        }

        $status = $request->string('status');
        if ($status !== '' && in_array($status, PrintJob::ALL_STATUSES, true)) {
            $filters['status'] = [$status];
        }

        foreach (['payment_status', 'color_mode', 'paper_size', 'duplex'] as $key) {
            $value = $request->string($key);
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    private function safeGroupBy(string $groupBy): string
    {
        $allowed = ['day', 'week', 'month', 'location', 'printer', 'color_mode', 'paper_size', 'status', 'payment_status'];
        return in_array($groupBy, $allowed, true) ? $groupBy : 'day';
    }

    private function defaultGroupFor(string $preset): string
    {
        return match ($preset) {
            'today', 'yesterday' => 'printer',
            'week', 'last_week' => 'day',
            'month', 'last_month' => 'day',
            default => 'day',
        };
    }

    /** @param array<string,mixed> $filters */
    private function describeFilters(array $filters): string
    {
        $parts = [];

        if (isset($filters['location_id'])) {
            $location = $this->locations->find((int) $filters['location_id']);
            $parts[] = 'Location: ' . ($location?->string('name') ?? '#' . $filters['location_id']);
        }
        if (isset($filters['printer_id'])) {
            $printer = $this->printers->find((int) $filters['printer_id']);
            $parts[] = 'Printer: ' . ($printer?->string('name') ?? '#' . $filters['printer_id']);
        }
        foreach (['status', 'payment_status', 'color_mode', 'paper_size', 'duplex'] as $key) {
            if (isset($filters[$key])) {
                $value = is_array($filters[$key]) ? implode(', ', $filters[$key]) : (string) $filters[$key];
                $parts[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
            }
        }

        return $parts === [] ? 'None' : implode('; ', $parts);
    }

    private function queryString(Request $request): string
    {
        $keep = ['from', 'to', 'preset', 'location_id', 'printer_id', 'status', 'payment_status', 'color_mode', 'paper_size', 'duplex', 'group_by'];
        $params = [];

        foreach ($keep as $key) {
            $value = $request->string($key);
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        return http_build_query($params);
    }
}
