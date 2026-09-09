<?php
declare(strict_types=1);

namespace App\Repositories;

final class PrintFileRepository extends Repository
{
    protected function table(): string
    {
        return 'print_files';
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->findRow($id);
    }

    /** Scoped read: a file is only visible to the session that uploaded it. */
    public function findForSession(int $id, int $sessionId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM print_files WHERE id = ? AND session_id = ? AND is_deleted = 0',
            [$id, $sessionId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function forSession(int $sessionId): array
    {
        return $this->db->select(
            'SELECT * FROM print_files WHERE session_id = ? AND is_deleted = 0 ORDER BY id',
            [$sessionId]
        );
    }

    public function totalBytesForSession(int $sessionId): int
    {
        return (int) $this->db->scalar(
            'SELECT COALESCE(SUM(size_bytes), 0) FROM print_files WHERE session_id = ? AND is_deleted = 0',
            [$sessionId]
        );
    }

    public function countForSession(int $sessionId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM print_files WHERE session_id = ? AND is_deleted = 0',
            [$sessionId]
        );
    }

    /**
     * Files eligible for physical deletion.
     *
     * A file is only purged when its retention deadline has passed AND no job
     * still needs it (queued/printing jobs hold their file). That ordering is
     * what stops cleanup from deleting a document out from under a printer.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueForPurge(int $limit = 200): array
    {
        return $this->db->select(
            "SELECT f.* FROM print_files f
             WHERE f.is_deleted = 0
               AND f.purge_after <= UTC_TIMESTAMP()
               AND NOT EXISTS (
                   SELECT 1 FROM print_jobs j
                   WHERE j.file_id = f.id
                     AND j.status IN ('pending','payment_pending','paid','queued','processing','printing')
               )
             ORDER BY f.purge_after ASC
             LIMIT " . max(1, min(1000, $limit))
        );
    }

    public function markDeleted(int $id): int
    {
        return $this->db->execute(
            'UPDATE print_files SET is_deleted = 1, deleted_at = UTC_TIMESTAMP() WHERE id = ?',
            [$id]
        );
    }

    /** Pull a completed job's file purge deadline forward. */
    public function accelerateRetention(int $fileId, int $hours): int
    {
        return $this->db->execute(
            'UPDATE print_files
             SET purge_after = LEAST(purge_after, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR))
             WHERE id = ?',
            [$hours, $fileId]
        );
    }

    public function setPageCount(int $id, int $pages, string $source): int
    {
        return $this->db->execute(
            'UPDATE print_files SET page_count = ?, page_count_source = ? WHERE id = ?',
            [$pages, $source, $id]
        );
    }

    public function setScanResult(int $id, string $status, ?string $message): int
    {
        return $this->db->execute(
            'UPDATE print_files SET scan_status = ?, scan_message = ? WHERE id = ?',
            [$status, $message === null ? null : substr($message, 0, 255), $id]
        );
    }

    public function countStored(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM print_files WHERE is_deleted = 0');
    }

    public function bytesStored(): int
    {
        return (int) $this->db->scalar(
            'SELECT COALESCE(SUM(size_bytes), 0) FROM print_files WHERE is_deleted = 0'
        );
    }

    /** Remove tombstone rows long after the bytes are gone. */
    public function pruneDeletedRows(int $days = 90): int
    {
        return $this->db->execute(
            'DELETE FROM print_files
             WHERE is_deleted = 1
               AND deleted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
               AND NOT EXISTS (SELECT 1 FROM print_jobs j WHERE j.file_id = print_files.id)',
            [$days]
        );
    }
}
