<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\Partner;

final class PartnerRepository extends Repository
{
    protected function table(): string
    {
        return 'partners';
    }

    public function find(int $id): ?Partner
    {
        $row = $this->findRow($id);
        return $row === null ? null : Partner::fromRow($row);
    }

    public function findByEmail(string $email): ?Partner
    {
        $row = $this->db->selectOne('SELECT * FROM partners WHERE email = ?', [mb_strtolower($email)]);
        return $row === null ? null : Partner::fromRow($row);
    }

    public function emailExists(string $email): bool
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM partners WHERE email = ?',
            [mb_strtolower($email)]
        ) > 0;
    }

    /**
     * Registrations for the review queue, newest first, pending ones first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function queue(string $status = '', int $limit = 100): array
    {
        $sql = 'SELECT p.*, l.name AS location_name, l.code AS location_code,
                       d.name AS device_name, d.status AS device_status,
                       a.name AS reviewer_name
                FROM partners p
                LEFT JOIN locations l ON l.id = p.location_id
                LEFT JOIN devices   d ON d.id = p.device_id
                LEFT JOIN admins    a ON a.id = p.reviewed_by';
        $bindings = [];

        if ($status !== '') {
            $sql .= ' WHERE p.status = ?';
            $bindings[] = $status;
        }

        // Pending first: the queue exists to be emptied, not browsed.
        $sql .= " ORDER BY p.status = 'pending' DESC, p.created_at DESC LIMIT " . max(1, min($limit, 500));

        return $this->db->select($sql, $bindings);
    }

    public function countPending(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM partners WHERE status = 'pending'");
    }

    public function recordSuccessfulLogin(int $id, string $ip): void
    {
        $this->db->execute(
            'UPDATE partners
             SET last_login_at = UTC_TIMESTAMP(), last_login_ip = ?,
                 failed_attempts = 0, locked_until = NULL
             WHERE id = ?',
            [$ip, $id]
        );
    }

    /**
     * Count a failed attempt and lock the account once the threshold is hit.
     * Same shape as the admin lockout: the per-IP rate limit alone does not
     * stop an attacker who rotates addresses against one known email.
     */
    public function recordFailedLogin(int $id, int $maxAttempts, int $lockSeconds): void
    {
        $this->db->execute(
            'UPDATE partners
             SET failed_attempts = failed_attempts + 1,
                 locked_until = CASE
                     WHEN failed_attempts + 1 >= ?
                     THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)
                     ELSE locked_until
                 END
             WHERE id = ?',
            [$maxAttempts, $lockSeconds, $id]
        );
    }

    public function markReviewed(
        int $id,
        string $status,
        ?int $reviewerId,
        string $note = '',
        ?int $locationId = null,
        ?int $deviceId = null
    ): void {
        $this->db->execute(
            'UPDATE partners
             SET status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), review_note = ?,
                 location_id = COALESCE(?, location_id), device_id = COALESCE(?, device_id)
             WHERE id = ?',
            [$status, $reviewerId, $note === '' ? null : $note, $locationId, $deviceId, $id]
        );
    }
}
