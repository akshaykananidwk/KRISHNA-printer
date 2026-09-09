<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\Printer;

final class PrinterRepository extends Repository
{
    protected function table(): string
    {
        return 'printers';
    }

    public function find(int $id): ?Printer
    {
        $row = $this->findRow($id);
        return $row === null ? null : Printer::fromRow($row);
    }

    public function findByCode(string $code): ?Printer
    {
        $row = $this->db->selectOne('SELECT * FROM printers WHERE code = ? AND deleted_at IS NULL', [$code]);
        return $row === null ? null : Printer::fromRow($row);
    }

    /** The printer must belong to the given location — prevents cross-location targeting. */
    public function findForLocation(int $printerId, int $locationId): ?Printer
    {
        $row = $this->db->selectOne(
            'SELECT * FROM printers WHERE id = ? AND location_id = ? AND deleted_at IS NULL',
            [$printerId, $locationId]
        );
        return $row === null ? null : Printer::fromRow($row);
    }

    /** @return array<int,Printer> */
    public function forLocation(int $locationId, bool $onlyEnabled = true): array
    {
        $sql = 'SELECT * FROM printers WHERE location_id = ? AND deleted_at IS NULL';
        if ($onlyEnabled) {
            $sql .= ' AND is_enabled = 1';
        }
        $sql .= ' ORDER BY is_default DESC, name';
        return Printer::fromRows($this->db->select($sql, [$locationId]));
    }

    /** The printer a QR link should land on: the location default, else the first enabled one. */
    public function defaultForLocation(int $locationId): ?Printer
    {
        $row = $this->db->selectOne(
            'SELECT * FROM printers
             WHERE location_id = ? AND deleted_at IS NULL AND is_enabled = 1
             ORDER BY is_default DESC,
                      (status = \'online\') DESC,
                      id ASC
             LIMIT 1',
            [$locationId]
        );
        return $row === null ? null : Printer::fromRow($row);
    }

    /** @return array<int,Printer> */
    public function all(bool $includeDisabled = true): array
    {
        $sql = 'SELECT * FROM printers WHERE deleted_at IS NULL';
        if (!$includeDisabled) {
            $sql .= ' AND is_enabled = 1';
        }
        return Printer::fromRows($this->db->select($sql . ' ORDER BY name'));
    }

    /** Printers due for a health probe, oldest check first. @return array<int,Printer> */
    public function dueForHealthCheck(int $intervalSeconds, int $limit = 50): array
    {
        return Printer::fromRows($this->db->select(
            'SELECT * FROM printers
             WHERE deleted_at IS NULL AND is_enabled = 1
               AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND))
             ORDER BY COALESCE(last_seen_at, \'1970-01-01\') ASC
             LIMIT ' . max(1, min(500, $limit)),
            [$intervalSeconds]
        ));
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function paginate(int $page, int $perPage, string $search = '', ?int $locationId = null, string $status = ''): array
    {
        $perPage = $this->clampPerPage($perPage);
        $page = max(1, $page);

        $where = ['p.deleted_at IS NULL'];
        $bindings = [];

        if ($search !== '') {
            $where[] = '(p.name LIKE ? OR p.code LIKE ? OR p.model LIKE ?)';
            $like = '%' . $search . '%';
            array_push($bindings, $like, $like, $like);
        }
        if ($locationId !== null) {
            $where[] = 'p.location_id = ?';
            $bindings[] = $locationId;
        }
        if ($status !== '') {
            $where[] = 'p.status = ?';
            $bindings[] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM printers p WHERE $whereSql", $bindings);

        $rows = $this->db->select(
            "SELECT p.*, l.name AS location_name, l.code AS location_code,
                    d.name AS device_name, d.status AS device_status, d.last_seen_at AS device_last_seen,
                    (SELECT COUNT(*) FROM print_jobs j
                      WHERE j.printer_id = p.id
                        AND j.status IN ('queued','processing','printing')) AS queue_depth,
                    (SELECT pc.driver FROM printer_connections pc
                      WHERE pc.printer_id = p.id AND pc.is_enabled = 1
                      ORDER BY pc.priority LIMIT 1) AS primary_driver
             FROM printers p
             INNER JOIN locations l ON l.id = p.location_id
             LEFT JOIN devices d ON d.id = p.device_id
             WHERE $whereSql
             ORDER BY l.name, p.name
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

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM printers WHERE code = ?';
        $bindings = [$code];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $bindings[] = $exceptId;
        }
        return (int) $this->db->scalar($sql, $bindings) > 0;
    }

    /** Only one printer per location may be the default. */
    public function setDefault(int $printerId, int $locationId): void
    {
        $this->db->transaction(function () use ($printerId, $locationId): void {
            $this->db->execute('UPDATE printers SET is_default = 0 WHERE location_id = ?', [$locationId]);
            $this->db->execute('UPDATE printers SET is_default = 1 WHERE id = ?', [$printerId]);
        });
    }

    public function softDelete(int $id): int
    {
        return $this->db->execute(
            'UPDATE printers SET deleted_at = UTC_TIMESTAMP(), is_enabled = 0 WHERE id = ?',
            [$id]
        );
    }

    public function countOffline(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM printers
             WHERE deleted_at IS NULL AND is_enabled = 1
               AND status IN ('offline','error','unknown')"
        );
    }

    public function countOnline(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM printers WHERE deleted_at IS NULL AND is_enabled = 1 AND status = 'online'"
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function connections(int $printerId, bool $onlyEnabled = true): array
    {
        $sql = 'SELECT * FROM printer_connections WHERE printer_id = ?';
        if ($onlyEnabled) {
            $sql .= ' AND is_enabled = 1';
        }
        return $this->db->select($sql . ' ORDER BY priority ASC, id ASC', [$printerId]);
    }

    /** @return array<string,mixed>|null */
    public function primaryConnection(int $printerId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM printer_connections
             WHERE printer_id = ? AND is_enabled = 1
             ORDER BY priority ASC, id ASC LIMIT 1',
            [$printerId]
        );
    }

    /** @return array<int,array<string,mixed>> recent health checks */
    public function statusHistory(int $printerId, int $limit = 25): array
    {
        return $this->db->select(
            'SELECT * FROM printer_status_checks WHERE printer_id = ?
             ORDER BY checked_at DESC LIMIT ' . max(1, min(200, $limit)),
            [$printerId]
        );
    }

    public function recordStatusCheck(
        int $printerId,
        string $status,
        string $driver,
        ?int $latencyMs,
        ?string $stateReason,
        ?string $message
    ): void {
        $this->db->insert('printer_status_checks', [
            'printer_id' => $printerId,
            'status' => $status,
            'driver' => $driver,
            'latency_ms' => $latencyMs,
            'state_reason' => $stateReason === null ? null : substr($stateReason, 0, 190),
            'message' => $message,
        ]);
    }

    /** Trim probe history so the table does not grow without bound. */
    public function pruneStatusHistory(int $days = 30): int
    {
        return $this->db->execute(
            'DELETE FROM printer_status_checks WHERE checked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)',
            [$days]
        );
    }
}
