<?php
declare(strict_types=1);

namespace App\Repositories;

/**
 * Aggregate queries for the dashboard, charts and reports.
 *
 * Revenue is counted only from jobs that were actually paid for (or that were
 * free because payment is disabled) AND that reached the printer — a failed
 * job the customer was refunded for is not revenue. Every aggregate here uses
 * the same definition so the dashboard, the charts and the CSV export cannot
 * disagree with each other.
 */
final class ReportRepository extends Repository
{
    protected function table(): string
    {
        return 'print_jobs';
    }

    /** Jobs that count toward revenue. */
    private const REVENUE_CONDITION = "j.status = 'completed' AND j.payment_status IN ('paid','not_required')";

    /** Jobs whose pages were physically produced. */
    private const PAGES_CONDITION = "j.status = 'completed'";

    /**
     * Dashboard summary for a single day (UTC date string).
     *
     * @return array<string,int>
     */
    public function dailySummary(string $date, ?int $locationId = null): array
    {
        $where = 'DATE(j.created_at) = ?';
        $bindings = [$date];
        if ($locationId !== null) {
            $where .= ' AND j.location_id = ?';
            $bindings[] = $locationId;
        }

        $row = $this->db->selectOne(
            "SELECT
                COUNT(*) AS jobs_total,
                COALESCE(SUM(CASE WHEN j.status = 'completed' THEN 1 ELSE 0 END), 0) AS jobs_successful,
                COALESCE(SUM(CASE WHEN j.status = 'failed' THEN 1 ELSE 0 END), 0) AS jobs_failed,
                COALESCE(SUM(CASE WHEN j.status = 'cancelled' THEN 1 ELSE 0 END), 0) AS jobs_cancelled,
                COALESCE(SUM(CASE WHEN j.status IN ('queued','processing','printing') THEN 1 ELSE 0 END), 0) AS jobs_active,
                COALESCE(SUM(CASE WHEN " . self::PAGES_CONDITION . " THEN j.billable_pages ELSE 0 END), 0) AS pages_total,
                COALESCE(SUM(CASE WHEN " . self::PAGES_CONDITION . " AND j.color_mode = 'color' THEN j.billable_pages ELSE 0 END), 0) AS pages_color,
                COALESCE(SUM(CASE WHEN " . self::PAGES_CONDITION . " AND j.color_mode = 'bw' THEN j.billable_pages ELSE 0 END), 0) AS pages_bw,
                COALESCE(SUM(CASE WHEN " . self::PAGES_CONDITION . " THEN j.sheets ELSE 0 END), 0) AS sheets_total,
                COALESCE(SUM(CASE WHEN " . self::REVENUE_CONDITION . " THEN j.total_paise ELSE 0 END), 0) AS revenue_paise
             FROM print_jobs j
             WHERE $where",
            $bindings
        ) ?? [];

        return array_map('intval', $row);
    }

    /**
     * Daily series for the revenue / jobs charts.
     *
     * Dates with no jobs are absent from the SQL result; the caller fills the
     * gaps so the chart shows a continuous axis rather than skipping days.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dailySeries(string $from, string $to, ?int $locationId = null): array
    {
        $where = 'j.created_at >= ? AND j.created_at <= ?';
        $bindings = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if ($locationId !== null) {
            $where .= ' AND j.location_id = ?';
            $bindings[] = $locationId;
        }

        return $this->db->select(
            "SELECT DATE(j.created_at) AS day,
                    COUNT(*) AS jobs,
                    COALESCE(SUM(CASE WHEN " . self::PAGES_CONDITION . " THEN j.billable_pages ELSE 0 END), 0) AS pages,
                    COALESCE(SUM(CASE WHEN " . self::PAGES_CONDITION . " AND j.color_mode = 'color' THEN j.billable_pages ELSE 0 END), 0) AS pages_color,
                    COALESCE(SUM(CASE WHEN " . self::PAGES_CONDITION . " AND j.color_mode = 'bw' THEN j.billable_pages ELSE 0 END), 0) AS pages_bw,
                    COALESCE(SUM(CASE WHEN " . self::REVENUE_CONDITION . " THEN j.total_paise ELSE 0 END), 0) AS revenue_paise,
                    COALESCE(SUM(CASE WHEN j.status = 'failed' THEN 1 ELSE 0 END), 0) AS failed
             FROM print_jobs j
             WHERE $where
             GROUP BY DATE(j.created_at)
             ORDER BY day",
            $bindings
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function revenueByLocation(string $from, string $to, int $limit = 20): array
    {
        return $this->db->select(
            "SELECT l.id, l.name, l.code, l.city,
                    COUNT(j.id) AS jobs,
                    COALESCE(SUM(CASE WHEN " . self::PAGES_CONDITION . " THEN j.billable_pages ELSE 0 END), 0) AS pages,
                    COALESCE(SUM(CASE WHEN " . self::REVENUE_CONDITION . " THEN j.total_paise ELSE 0 END), 0) AS revenue_paise
             FROM locations l
             LEFT JOIN print_jobs j
                    ON j.location_id = l.id
                   AND j.created_at >= ? AND j.created_at <= ?
             WHERE l.deleted_at IS NULL
             GROUP BY l.id, l.name, l.code, l.city
             ORDER BY revenue_paise DESC, l.name
             LIMIT " . max(1, min(200, $limit)),
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function printerUsage(string $from, string $to, int $limit = 20): array
    {
        return $this->db->select(
            "SELECT p.id, p.name, p.model, p.status, l.name AS location_name,
                    COUNT(j.id) AS jobs,
                    COALESCE(SUM(CASE WHEN " . self::PAGES_CONDITION . " THEN j.billable_pages ELSE 0 END), 0) AS pages,
                    COALESCE(SUM(CASE WHEN j.status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
                    COALESCE(SUM(CASE WHEN " . self::REVENUE_CONDITION . " THEN j.total_paise ELSE 0 END), 0) AS revenue_paise
             FROM printers p
             INNER JOIN locations l ON l.id = p.location_id
             LEFT JOIN print_jobs j
                    ON j.printer_id = p.id
                   AND j.created_at >= ? AND j.created_at <= ?
             WHERE p.deleted_at IS NULL
             GROUP BY p.id, p.name, p.model, p.status, l.name
             ORDER BY pages DESC, p.name
             LIMIT " . max(1, min(200, $limit)),
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
    }

    /** @return array<string,int> colour vs mono page split */
    public function colorSplit(string $from, string $to, ?int $locationId = null): array
    {
        $where = 'j.created_at >= ? AND j.created_at <= ?';
        $bindings = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if ($locationId !== null) {
            $where .= ' AND j.location_id = ?';
            $bindings[] = $locationId;
        }

        $row = $this->db->selectOne(
            "SELECT
                COALESCE(SUM(CASE WHEN j.color_mode = 'color' THEN j.billable_pages ELSE 0 END), 0) AS color_pages,
                COALESCE(SUM(CASE WHEN j.color_mode = 'bw' THEN j.billable_pages ELSE 0 END), 0) AS bw_pages,
                COALESCE(SUM(CASE WHEN j.color_mode = 'color' THEN j.total_paise ELSE 0 END), 0) AS color_revenue_paise,
                COALESCE(SUM(CASE WHEN j.color_mode = 'bw' THEN j.total_paise ELSE 0 END), 0) AS bw_revenue_paise
             FROM print_jobs j
             WHERE $where AND " . self::PAGES_CONDITION,
            $bindings
        ) ?? [];

        return array_map('intval', $row);
    }

    /** @return array<int,array<string,mixed>> paper-size distribution */
    public function paperSizeBreakdown(string $from, string $to, ?int $locationId = null): array
    {
        $where = 'j.created_at >= ? AND j.created_at <= ?';
        $bindings = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if ($locationId !== null) {
            $where .= ' AND j.location_id = ?';
            $bindings[] = $locationId;
        }

        return $this->db->select(
            "SELECT j.paper_size,
                    COUNT(*) AS jobs,
                    COALESCE(SUM(j.billable_pages), 0) AS pages,
                    COALESCE(SUM(j.total_paise), 0) AS revenue_paise
             FROM print_jobs j
             WHERE $where AND " . self::PAGES_CONDITION . "
             GROUP BY j.paper_size
             ORDER BY pages DESC",
            $bindings
        );
    }

    /**
     * Full report row set for the reports screen and its exports.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function summaryForFilters(array $filters, PrintJobRepository $jobs): array
    {
        [$whereSql, $bindings] = $jobs->buildFilters($filters);

        $row = $this->db->selectOne(
            "SELECT
                COUNT(*) AS jobs_total,
                COALESCE(SUM(CASE WHEN j.status = 'completed' THEN 1 ELSE 0 END), 0) AS jobs_completed,
                COALESCE(SUM(CASE WHEN j.status = 'failed' THEN 1 ELSE 0 END), 0) AS jobs_failed,
                COALESCE(SUM(CASE WHEN j.status = 'cancelled' THEN 1 ELSE 0 END), 0) AS jobs_cancelled,
                COALESCE(SUM(j.billable_pages), 0) AS pages_requested,
                COALESCE(SUM(CASE WHEN j.status = 'completed' THEN j.billable_pages ELSE 0 END), 0) AS pages_printed,
                COALESCE(SUM(CASE WHEN j.status = 'completed' AND j.color_mode = 'color' THEN j.billable_pages ELSE 0 END), 0) AS pages_color,
                COALESCE(SUM(CASE WHEN j.status = 'completed' AND j.color_mode = 'bw' THEN j.billable_pages ELSE 0 END), 0) AS pages_bw,
                COALESCE(SUM(CASE WHEN j.status = 'completed' THEN j.sheets ELSE 0 END), 0) AS sheets,
                COALESCE(SUM(CASE WHEN " . self::REVENUE_CONDITION . " THEN j.total_paise ELSE 0 END), 0) AS revenue_paise,
                COALESCE(SUM(CASE WHEN " . self::REVENUE_CONDITION . " THEN j.tax_paise ELSE 0 END), 0) AS tax_paise,
                COALESCE(SUM(CASE WHEN " . self::REVENUE_CONDITION . " THEN j.gateway_fee_paise ELSE 0 END), 0) AS gateway_fee_paise
             FROM print_jobs j
             INNER JOIN locations l ON l.id = j.location_id
             INNER JOIN printers p ON p.id = j.printer_id
             LEFT JOIN print_files f ON f.id = j.file_id
             WHERE $whereSql",
            $bindings
        ) ?? [];

        return array_map('intval', $row);
    }

    /**
     * Group a filtered report by an allowed dimension.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function groupedForFilters(array $filters, PrintJobRepository $jobs, string $groupBy): array
    {
        [$whereSql, $bindings] = $jobs->buildFilters($filters);

        // Group expressions are chosen from a fixed map — never interpolated
        // from request input.
        $groups = [
            'day' => ['DATE(j.created_at)', 'day'],
            'week' => ["DATE_FORMAT(j.created_at, '%x-W%v')", 'week'],
            'month' => ["DATE_FORMAT(j.created_at, '%Y-%m')", 'month'],
            'location' => ['l.name', 'location'],
            'printer' => ['p.name', 'printer'],
            'color_mode' => ['j.color_mode', 'color_mode'],
            'paper_size' => ['j.paper_size', 'paper_size'],
            'status' => ['j.status', 'status'],
            'payment_status' => ['j.payment_status', 'payment_status'],
        ];
        [$expression, $alias] = $groups[$groupBy] ?? $groups['day'];

        return $this->db->select(
            "SELECT $expression AS group_key,
                    COUNT(*) AS jobs,
                    COALESCE(SUM(CASE WHEN j.status = 'completed' THEN j.billable_pages ELSE 0 END), 0) AS pages,
                    COALESCE(SUM(CASE WHEN j.status = 'completed' AND j.color_mode = 'color' THEN j.billable_pages ELSE 0 END), 0) AS pages_color,
                    COALESCE(SUM(CASE WHEN j.status = 'completed' AND j.color_mode = 'bw' THEN j.billable_pages ELSE 0 END), 0) AS pages_bw,
                    COALESCE(SUM(CASE WHEN j.status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
                    COALESCE(SUM(CASE WHEN " . self::REVENUE_CONDITION . " THEN j.total_paise ELSE 0 END), 0) AS revenue_paise
             FROM print_jobs j
             INNER JOIN locations l ON l.id = j.location_id
             INNER JOIN printers p ON p.id = j.printer_id
             LEFT JOIN print_files f ON f.id = j.file_id
             WHERE $whereSql
             GROUP BY group_key
             ORDER BY group_key",
            $bindings
        );
    }

    /** Live operational counters for the dashboard header. @return array<string,int> */
    public function operationalCounters(): array
    {
        $row = $this->db->selectOne(
            "SELECT
                (SELECT COUNT(*) FROM locations WHERE status = 'active' AND deleted_at IS NULL) AS active_locations,
                (SELECT COUNT(*) FROM printers WHERE deleted_at IS NULL AND is_enabled = 1) AS printers_total,
                (SELECT COUNT(*) FROM printers WHERE deleted_at IS NULL AND is_enabled = 1 AND status = 'online') AS printers_online,
                (SELECT COUNT(*) FROM printers WHERE deleted_at IS NULL AND is_enabled = 1 AND status IN ('offline','error')) AS printers_offline,
                (SELECT COUNT(*) FROM printers WHERE deleted_at IS NULL AND is_enabled = 1 AND status = 'unknown') AS printers_unknown,
                (SELECT COUNT(*) FROM print_jobs WHERE status IN ('queued','processing','printing')) AS queue_depth,
                (SELECT COUNT(*) FROM devices WHERE status = 'online') AS agents_online,
                (SELECT COUNT(*) FROM devices) AS agents_total"
        ) ?? [];

        return array_map('intval', $row);
    }
}
