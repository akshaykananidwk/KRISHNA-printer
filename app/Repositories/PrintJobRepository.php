<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\PrintJob;

final class PrintJobRepository extends Repository
{
    protected function table(): string
    {
        return 'print_jobs';
    }

    public function find(int $id): ?PrintJob
    {
        $row = $this->findRow($id);
        return $row === null ? null : PrintJob::fromRow($row);
    }

    public function findByNumber(string $jobNumber): ?PrintJob
    {
        $row = $this->db->selectOne('SELECT * FROM print_jobs WHERE job_number = ?', [$jobNumber]);
        return $row === null ? null : PrintJob::fromRow($row);
    }

    /** @return array<int,array<string,mixed>> */
    public function detailByNumber(string $jobNumber): ?array
    {
        return $this->db->selectOne(
            'SELECT j.*, l.name AS location_name, l.code AS location_code, l.city AS location_city,
                    p.name AS printer_name, p.model AS printer_model,
                    f.original_name AS file_name, f.size_bytes AS file_size, f.extension AS file_extension,
                    pay.status AS payment_gateway_status, pay.gateway_payment_id, pay.method AS payment_method
             FROM print_jobs j
             INNER JOIN locations l ON l.id = j.location_id
             INNER JOIN printers p ON p.id = j.printer_id
             LEFT JOIN print_files f ON f.id = j.file_id
             LEFT JOIN payments pay ON pay.id = j.payment_id
             WHERE j.job_number = ?',
            [$jobNumber]
        );
    }

    /** @return array<int,PrintJob> */
    public function forBatch(string $batchId): array
    {
        return PrintJob::fromRows($this->db->select(
            'SELECT * FROM print_jobs WHERE batch_id = ? ORDER BY id',
            [$batchId]
        ));
    }

    /** @return array<int,array<string,mixed>> */
    public function detailForBatch(string $batchId): array
    {
        return $this->db->select(
            'SELECT j.*, l.name AS location_name, p.name AS printer_name,
                    f.original_name AS file_name
             FROM print_jobs j
             INNER JOIN locations l ON l.id = j.location_id
             INNER JOIN printers p ON p.id = j.printer_id
             LEFT JOIN print_files f ON f.id = j.file_id
             WHERE j.batch_id = ?
             ORDER BY j.id',
            [$batchId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function events(int $jobId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT * FROM job_events WHERE job_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            [$jobId]
        );
    }

    /**
     * Atomically claim the next printable job for a printer.
     *
     * The claim is a conditional UPDATE that writes a lease token, so two
     * workers racing on the same row cannot both win: the second one's
     * WHERE clause no longer matches and it gets rowCount() === 0.
     *
     * @param array<int,int> $printerIds
     * @return array<string,mixed>|null the claimed job row
     */
    public function claimNext(array $printerIds, string $leaseToken, int $leaseSeconds): ?array
    {
        if ($printerIds === []) {
            return null;
        }
        [$in, $bindings] = $this->inClause($printerIds);

        // Find a candidate first, then claim it conditionally. Selecting inside
        // the UPDATE is not portable across MySQL versions, and this two-step
        // is safe because the UPDATE re-checks every condition.
        $candidate = $this->db->selectOne(
            "SELECT id FROM print_jobs
             WHERE printer_id IN $in
               AND status IN ('queued','paid')
               AND payment_status IN ('not_required','paid')
               AND available_at <= UTC_TIMESTAMP()
               AND (lease_token IS NULL OR lease_expires_at < UTC_TIMESTAMP())
             ORDER BY id ASC
             LIMIT 1",
            $bindings
        );
        if ($candidate === null) {
            return null;
        }

        $claimed = $this->db->execute(
            "UPDATE print_jobs
             SET lease_token = ?,
                 lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),
                 status = 'processing',
                 started_at = COALESCE(started_at, UTC_TIMESTAMP()),
                 attempts = attempts + 1
             WHERE id = ?
               AND status IN ('queued','paid')
               AND payment_status IN ('not_required','paid')
               AND available_at <= UTC_TIMESTAMP()
               AND (lease_token IS NULL OR lease_expires_at < UTC_TIMESTAMP())",
            [$leaseToken, $leaseSeconds, (int) $candidate['id']]
        );

        if ($claimed === 0) {
            return null; // another worker won the race
        }

        return $this->db->selectOne(
            'SELECT j.*, f.relative_path, f.stored_name, f.original_name, f.extension, f.mime_type,
                    p.name AS printer_name, p.queue_name, p.capability_profile, p.model AS printer_model,
                    l.name AS location_name, l.code AS location_code
             FROM print_jobs j
             LEFT JOIN print_files f ON f.id = j.file_id
             INNER JOIN printers p ON p.id = j.printer_id
             INNER JOIN locations l ON l.id = j.location_id
             WHERE j.id = ?',
            [(int) $candidate['id']]
        );
    }

    /** Release a lease without changing status — used when a worker shuts down cleanly. */
    public function releaseLease(int $jobId, string $leaseToken): int
    {
        return $this->db->execute(
            "UPDATE print_jobs
             SET lease_token = NULL, lease_expires_at = NULL, status = 'queued'
             WHERE id = ? AND lease_token = ? AND status = 'processing'",
            [$jobId, $leaseToken]
        );
    }

    /**
     * Return jobs whose lease expired (a worker or agent died mid-print) to
     * the queue. Without this, a crashed agent would strand jobs forever.
     */
    public function reclaimExpiredLeases(): int
    {
        return $this->db->execute(
            "UPDATE print_jobs
             SET status = 'queued',
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 error_message = CONCAT(COALESCE(error_message, ''), '[lease expired, requeued] ')
             WHERE status IN ('processing','printing')
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at < UTC_TIMESTAMP()
               AND attempts < max_attempts"
        );
    }

    /** Jobs that exhausted their retries while leased become failed, not zombies. */
    public function failExhaustedLeases(): int
    {
        return $this->db->execute(
            "UPDATE print_jobs
             SET status = 'failed',
                 completed_at = UTC_TIMESTAMP(),
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 error_code = COALESCE(error_code, 'lease_expired'),
                 error_message = COALESCE(error_message, 'The print worker stopped responding and the job exhausted its retries.')
             WHERE status IN ('processing','printing')
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at < UTC_TIMESTAMP()
               AND attempts >= max_attempts"
        );
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $perPage = $this->clampPerPage($perPage);
        $page = max(1, $page);

        [$whereSql, $bindings] = $this->buildFilters($filters);

        $total = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM print_jobs j
             INNER JOIN locations l ON l.id = j.location_id
             INNER JOIN printers p ON p.id = j.printer_id
             WHERE $whereSql",
            $bindings
        );

        $sort = $this->safeOrderBy(
            (string) ($filters['sort'] ?? 'created_at'),
            ['created_at', 'total_paise', 'billable_pages', 'status', 'job_number', 'completed_at'],
            'created_at'
        );
        $direction = $this->safeDirection((string) ($filters['direction'] ?? 'DESC'));

        $rows = $this->db->select(
            "SELECT j.*, l.name AS location_name, l.code AS location_code,
                    p.name AS printer_name, f.original_name AS file_name
             FROM print_jobs j
             INNER JOIN locations l ON l.id = j.location_id
             INNER JOIN printers p ON p.id = j.printer_id
             LEFT JOIN print_files f ON f.id = j.file_id
             WHERE $whereSql
             ORDER BY j.$sort $direction, j.id $direction
             LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
            $bindings
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => (int) max(1, ceil($total / $perPage)),
            'per_page' => $perPage,
        ];
    }

    /**
     * Shared filter builder for the job list, reports and exports, so all three
     * agree on exactly what a filter means.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    public function buildFilters(array $filters): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        if (!empty($filters['location_id'])) {
            $where[] = 'j.location_id = ?';
            $bindings[] = (int) $filters['location_id'];
        }
        if (!empty($filters['printer_id'])) {
            $where[] = 'j.printer_id = ?';
            $bindings[] = (int) $filters['printer_id'];
        }
        if (!empty($filters['status'])) {
            $statuses = (array) $filters['status'];
            [$in, $statusBindings] = $this->inClause($statuses);
            $where[] = "j.status IN $in";
            $bindings = array_merge($bindings, $statusBindings);
        }
        if (!empty($filters['payment_status'])) {
            $where[] = 'j.payment_status = ?';
            $bindings[] = (string) $filters['payment_status'];
        }
        if (!empty($filters['color_mode'])) {
            $where[] = 'j.color_mode = ?';
            $bindings[] = (string) $filters['color_mode'];
        }
        if (!empty($filters['paper_size'])) {
            $where[] = 'j.paper_size = ?';
            $bindings[] = (string) $filters['paper_size'];
        }
        if (!empty($filters['duplex'])) {
            $where[] = 'j.duplex = ?';
            $bindings[] = (string) $filters['duplex'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'j.created_at >= ?';
            $bindings[] = (string) $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'j.created_at <= ?';
            $bindings[] = (string) $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[] = '(j.job_number LIKE ? OR f_search.original_name LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($bindings, $like, $like);
        }

        $sql = implode(' AND ', $where);

        // The search branch needs the files join; add it only when used so the
        // common query stays cheap.
        if (!empty($filters['search'])) {
            $sql = str_replace('f_search.original_name', 'COALESCE(f.original_name, \'\')', $sql);
        }

        return [$sql, $bindings];
    }

    /** Stream large result sets for export without loading everything into memory. */
    public function eachForExport(array $filters, callable $callback, int $chunkSize = 500): int
    {
        [$whereSql, $bindings] = $this->buildFilters($filters);
        $offset = 0;
        $processed = 0;

        while (true) {
            $rows = $this->db->select(
                "SELECT j.*, l.name AS location_name, l.code AS location_code,
                        p.name AS printer_name, f.original_name AS file_name
                 FROM print_jobs j
                 INNER JOIN locations l ON l.id = j.location_id
                 INNER JOIN printers p ON p.id = j.printer_id
                 LEFT JOIN print_files f ON f.id = j.file_id
                 WHERE $whereSql
                 ORDER BY j.id ASC
                 LIMIT $chunkSize OFFSET $offset",
                $bindings
            );
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $callback($row);
                $processed++;
            }
            $offset += $chunkSize;
        }

        return $processed;
    }

    public function nextJobNumberSequence(): int
    {
        // AUTO_INCREMENT would leak total volume in the public job number, so
        // the sequence is derived from a counter row instead.
        $this->db->execute(
            "INSERT INTO system_settings (setting_key, setting_value, value_type, group_name, label)
             VALUES ('job_number_sequence', '1', 'int', 'internal', 'Job number sequence')
             ON DUPLICATE KEY UPDATE setting_value = CAST(CAST(setting_value AS UNSIGNED) + 1 AS CHAR)"
        );
        return (int) $this->db->scalar(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'job_number_sequence'"
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 10): array
    {
        return $this->db->select(
            'SELECT j.*, l.name AS location_name, p.name AS printer_name
             FROM print_jobs j
             INNER JOIN locations l ON l.id = j.location_id
             INNER JOIN printers p ON p.id = j.printer_id
             ORDER BY j.id DESC LIMIT ' . max(1, min(100, $limit))
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function queueForPrinter(int $printerId, int $limit = 20): array
    {
        return $this->db->select(
            "SELECT * FROM print_jobs
             WHERE printer_id = ? AND status IN ('queued','processing','printing')
             ORDER BY id ASC LIMIT " . max(1, min(100, $limit)),
            [$printerId]
        );
    }

    public function queueDepth(int $printerId): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM print_jobs
             WHERE printer_id = ? AND status IN ('queued','processing','printing')",
            [$printerId]
        );
    }
}
