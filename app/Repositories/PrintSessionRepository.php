<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Config;

final class PrintSessionRepository extends Repository
{
    protected function table(): string
    {
        return 'print_sessions';
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM print_sessions WHERE session_token = ? AND expires_at > UTC_TIMESTAMP()',
            [$token]
        );
    }

    /**
     * Get or create the basket for this visitor at this location.
     *
     * Keyed on (token, location) so scanning a different location's QR starts
     * a fresh basket rather than carrying files across shops.
     *
     * @return array<string,mixed>
     */
    public function ensure(string $token, int $locationId, ?int $printerId, string $ipHash, string $userAgent): array
    {
        $existing = $this->db->selectOne(
            'SELECT * FROM print_sessions
             WHERE session_token = ? AND location_id = ? AND expires_at > UTC_TIMESTAMP()',
            [$token, $locationId]
        );

        $ttlHours = (int) Config::get('uploads.retention_hours', 24);

        if ($existing !== null) {
            $this->db->execute(
                'UPDATE print_sessions
                 SET printer_id = COALESCE(?, printer_id),
                     expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR)
                 WHERE id = ?',
                [$printerId, $ttlHours, (int) $existing['id']]
            );
            $existing['printer_id'] = $printerId ?? $existing['printer_id'];
            return $existing;
        }

        $id = $this->db->insert('print_sessions', [
            'session_token' => $token,
            'location_id' => $locationId,
            'printer_id' => $printerId,
            'ip_hash' => $ipHash,
            'user_agent' => substr($userAgent, 0, 255),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlHours * 3600),
        ]);

        /** @var array<string,mixed> */
        return $this->findRow($id) ?? [];
    }

    public function setPrinter(int $sessionId, int $printerId): int
    {
        return $this->db->execute(
            'UPDATE print_sessions SET printer_id = ? WHERE id = ?',
            [$printerId, $sessionId]
        );
    }

    public function pruneExpired(): int
    {
        // Files keep their own retention deadline, so dropping the session row
        // does not orphan bytes on disk; FileService's purge handles those.
        return $this->db->execute(
            'DELETE FROM print_sessions
             WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
               AND NOT EXISTS (SELECT 1 FROM print_jobs j WHERE j.session_id = print_sessions.id)'
        );
    }
}
