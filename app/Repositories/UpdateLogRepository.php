<?php
declare(strict_types=1);

namespace App\Repositories;

final class UpdateLogRepository extends Repository
{
    protected function table(): string
    {
        return 'update_logs';
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->findRow($id);
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 25): array
    {
        return $this->db->select(
            'SELECT u.*, a.name AS triggered_by_name
             FROM update_logs u
             LEFT JOIN admins a ON a.id = u.triggered_by
             ORDER BY u.id DESC LIMIT ' . max(1, min(100, $limit))
        );
    }

    public function latestSuccessful(): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM update_logs WHERE status = 'completed' ORDER BY id DESC LIMIT 1"
        );
    }

    /** An update already running blocks a second one from starting. */
    public function runningUpdate(): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM update_logs
             WHERE status NOT IN ('completed','failed','rolled_back')
               AND started_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)
             ORDER BY id DESC LIMIT 1"
        );
    }

    public function setStatus(int $id, string $status): int
    {
        return $this->db->execute('UPDATE update_logs SET status = ? WHERE id = ?', [$status, $id]);
    }

    /**
     * Append one step result. Steps are stored as an ordered JSON array so the
     * admin UI can replay exactly what happened and where it stopped.
     *
     * @param array<string,mixed> $step
     */
    public function appendStep(int $id, array $step): void
    {
        $row = $this->findRow($id);
        $steps = [];
        if ($row !== null && is_string($row['steps']) && $row['steps'] !== '') {
            $decoded = json_decode($row['steps'], true);
            $steps = is_array($decoded) ? $decoded : [];
        }
        $steps[] = $step + ['at' => gmdate('c')];
        $this->db->execute(
            'UPDATE update_logs SET steps = ? WHERE id = ?',
            [json_encode($steps, JSON_UNESCAPED_SLASHES), $id]
        );
    }

    public function complete(int $id, string $status, ?string $error, int $durationMs): int
    {
        return $this->db->execute(
            'UPDATE update_logs
             SET status = ?, error_message = ?, duration_ms = ?, completed_at = UTC_TIMESTAMP()
             WHERE id = ?',
            [$status, $error, $durationMs, $id]
        );
    }

    public function markRolledBack(int $id, string $reason): int
    {
        return $this->db->execute(
            "UPDATE update_logs
             SET status = 'rolled_back', rollback_performed = 1, rollback_reason = ?,
                 completed_at = UTC_TIMESTAMP()
             WHERE id = ?",
            [$reason, $id]
        );
    }
}
