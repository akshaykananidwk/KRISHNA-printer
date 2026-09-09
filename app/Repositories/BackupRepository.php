<?php
declare(strict_types=1);

namespace App\Repositories;

final class BackupRepository extends Repository
{
    protected function table(): string
    {
        return 'backups';
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->findRow($id);
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 50): array
    {
        return $this->db->select(
            'SELECT b.*, a.name AS created_by_name
             FROM backups b
             LEFT JOIN admins a ON a.id = b.created_by
             ORDER BY b.id DESC LIMIT ' . max(1, min(200, $limit))
        );
    }

    public function markCompleted(int $id, int $sizeBytes, string $sha256): int
    {
        return $this->db->execute(
            "UPDATE backups
             SET status = 'completed', size_bytes = ?, sha256 = ?, completed_at = UTC_TIMESTAMP()
             WHERE id = ?",
            [$sizeBytes, $sha256, $id]
        );
    }

    public function markFailed(int $id, string $error): int
    {
        return $this->db->execute(
            "UPDATE backups SET status = 'failed', error_message = ?, completed_at = UTC_TIMESTAMP() WHERE id = ?",
            [$error, $id]
        );
    }

    public function markRestored(int $id): int
    {
        return $this->db->execute(
            "UPDATE backups SET status = 'restored', restored_at = UTC_TIMESTAMP() WHERE id = ?",
            [$id]
        );
    }

    /**
     * Backups eligible for deletion: past their expiry, or beyond the
     * retention count for their type. The newest N of each type are always
     * kept regardless of age, so a quiet month never leaves the system with
     * nothing to roll back to.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueForCleanup(int $keepPerType, int $retentionDays): array
    {
        $expired = $this->db->select(
            "SELECT * FROM backups
             WHERE status = 'completed'
               AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)",
            [$retentionDays]
        );

        $keepIds = [];
        foreach (['database', 'application', 'full'] as $type) {
            $rows = $this->db->select(
                "SELECT id FROM backups
                 WHERE backup_type = ? AND status = 'completed'
                 ORDER BY id DESC LIMIT " . max(1, $keepPerType),
                [$type]
            );
            foreach ($rows as $row) {
                $keepIds[(int) $row['id']] = true;
            }
        }

        return array_values(array_filter(
            $expired,
            static fn (array $row): bool => !isset($keepIds[(int) $row['id']])
        ));
    }

    public function markDeleted(int $id): int
    {
        return $this->db->execute("UPDATE backups SET status = 'deleted' WHERE id = ?", [$id]);
    }

    /** @return array<string,mixed>|null the newest usable backup of a type */
    public function latestCompleted(string $type): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM backups WHERE backup_type = ? AND status IN ('completed','restored')
             ORDER BY id DESC LIMIT 1",
            [$type]
        );
    }

    public function totalBytes(): int
    {
        return (int) $this->db->scalar(
            "SELECT COALESCE(SUM(size_bytes), 0) FROM backups WHERE status IN ('completed','restored')"
        );
    }
}
