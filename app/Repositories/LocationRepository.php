<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\Location;

final class LocationRepository extends Repository
{
    protected function table(): string
    {
        return 'locations';
    }

    public function find(int $id): ?Location
    {
        $row = $this->findRow($id);
        return $row === null ? null : Location::fromRow($row);
    }

    public function findByCode(string $code): ?Location
    {
        $row = $this->db->selectOne(
            'SELECT * FROM locations WHERE code = ? AND deleted_at IS NULL',
            [$code]
        );
        return $row === null ? null : Location::fromRow($row);
    }

    /**
     * Resolve a QR link. The token is looked up by hash — the plaintext token
     * is never stored — and the comparison is done in the index, so this stays
     * a single indexed read even with 50+ locations.
     */
    public function findByQrToken(string $code, string $token): ?Location
    {
        $row = $this->db->selectOne(
            'SELECT * FROM locations WHERE code = ? AND qr_token_hash = ? AND deleted_at IS NULL',
            [$code, hash('sha256', $token)]
        );
        return $row === null ? null : Location::fromRow($row);
    }

    /** @return array<int,Location> */
    public function allActive(): array
    {
        return Location::fromRows($this->db->select(
            "SELECT * FROM locations WHERE status = 'active' AND deleted_at IS NULL ORDER BY name"
        ));
    }

    /** @return array<int,Location> */
    public function all(bool $includeDeleted = false): array
    {
        $sql = 'SELECT * FROM locations' . ($includeDeleted ? '' : ' WHERE deleted_at IS NULL') . ' ORDER BY name';
        return Location::fromRows($this->db->select($sql));
    }

    /**
     * Paginated admin listing with printer counts folded in, so the index page
     * renders 50+ locations in one query instead of N+1.
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function paginate(int $page, int $perPage, string $search = '', string $status = ''): array
    {
        $perPage = $this->clampPerPage($perPage);
        $page = max(1, $page);

        $where = ['l.deleted_at IS NULL'];
        $bindings = [];

        if ($search !== '') {
            $where[] = '(l.name LIKE ? OR l.code LIKE ? OR l.city LIKE ? OR l.contact_phone LIKE ?)';
            $like = '%' . $search . '%';
            array_push($bindings, $like, $like, $like, $like);
        }
        if ($status !== '') {
            $where[] = 'l.status = ?';
            $bindings[] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM locations l WHERE $whereSql", $bindings);

        $rows = $this->db->select(
            "SELECT l.*,
                    (SELECT COUNT(*) FROM printers p
                      WHERE p.location_id = l.id AND p.deleted_at IS NULL) AS printer_count,
                    (SELECT COUNT(*) FROM printers p
                      WHERE p.location_id = l.id AND p.deleted_at IS NULL
                        AND p.is_enabled = 1 AND p.status = 'online') AS printers_online,
                    (SELECT COUNT(*) FROM print_jobs j
                      WHERE j.location_id = l.id AND j.created_at >= UTC_DATE()) AS jobs_today
             FROM locations l
             WHERE $whereSql
             ORDER BY l.name
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
        $sql = 'SELECT COUNT(*) FROM locations WHERE code = ?';
        $bindings = [$code];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $bindings[] = $exceptId;
        }
        return (int) $this->db->scalar($sql, $bindings) > 0;
    }

    public function softDelete(int $id): int
    {
        return $this->db->execute(
            "UPDATE locations SET deleted_at = UTC_TIMESTAMP(), status = 'inactive' WHERE id = ?",
            [$id]
        );
    }

    public function restore(int $id): int
    {
        return $this->db->execute('UPDATE locations SET deleted_at = NULL WHERE id = ?', [$id]);
    }

    public function countActive(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM locations WHERE status = 'active' AND deleted_at IS NULL"
        );
    }
}
