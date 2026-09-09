<?php
declare(strict_types=1);

namespace App\Repositories;

final class AuditRepository extends Repository
{
    protected function table(): string
    {
        return 'activity_logs';
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function paginate(int $page, int $perPage, array $filters = []): array
    {
        $perPage = $this->clampPerPage($perPage);
        $page = max(1, $page);

        $where = ['1 = 1'];
        $bindings = [];

        if (!empty($filters['action'])) {
            $where[] = 'action = ?';
            $bindings[] = (string) $filters['action'];
        }
        if (!empty($filters['actor_type'])) {
            $where[] = 'actor_type = ?';
            $bindings[] = (string) $filters['actor_type'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'severity = ?';
            $bindings[] = (string) $filters['severity'];
        }
        if (!empty($filters['subject_type'])) {
            $where[] = 'subject_type = ?';
            $bindings[] = (string) $filters['subject_type'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= ?';
            $bindings[] = (string) $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= ?';
            $bindings[] = (string) $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[] = '(description LIKE ? OR actor_label LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($bindings, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM activity_logs WHERE $whereSql", $bindings);

        $rows = $this->db->select(
            "SELECT * FROM activity_logs WHERE $whereSql ORDER BY id DESC
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

    /** @return array<int,array<string,mixed>> */
    public function forSubject(string $type, string $id, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT * FROM activity_logs WHERE subject_type = ? AND subject_id = ?
             ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            [$type, $id]
        );
    }

    /** @return array<int,string> distinct actions, for the filter dropdown */
    public function distinctActions(): array
    {
        return array_column(
            $this->db->select('SELECT DISTINCT action FROM activity_logs ORDER BY action LIMIT 200'),
            'action'
        );
    }

    public function prune(int $days): int
    {
        return $this->db->execute(
            'DELETE FROM activity_logs
             WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
               AND severity <> \'critical\'',
            [$days]
        );
    }
}
