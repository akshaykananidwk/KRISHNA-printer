<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\Admin;

final class AdminRepository extends Repository
{
    protected function table(): string
    {
        return 'admins';
    }

    public function find(int $id): ?Admin
    {
        $row = $this->findRow($id);
        return $row === null ? null : Admin::fromRow($row);
    }

    public function findByEmail(string $email): ?Admin
    {
        $row = $this->db->selectOne('SELECT * FROM admins WHERE email = ?', [mb_strtolower($email)]);
        return $row === null ? null : Admin::fromRow($row);
    }

    public function findByRememberToken(string $token): ?Admin
    {
        $row = $this->db->selectOne(
            'SELECT * FROM admins WHERE remember_token_hash = ? AND is_active = 1',
            [hash('sha256', $token)]
        );
        return $row === null ? null : Admin::fromRow($row);
    }

    /** @return array<int,Admin> */
    public function all(): array
    {
        return Admin::fromRows($this->db->select('SELECT * FROM admins ORDER BY name'));
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM admins WHERE email = ?';
        $bindings = [mb_strtolower($email)];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $bindings[] = $exceptId;
        }
        return (int) $this->db->scalar($sql, $bindings) > 0;
    }

    public function recordSuccessfulLogin(int $id, string $ip): void
    {
        $this->db->execute(
            'UPDATE admins
             SET last_login_at = UTC_TIMESTAMP(), last_login_ip = ?,
                 failed_attempts = 0, locked_until = NULL
             WHERE id = ?',
            [$ip, $id]
        );
    }

    /**
     * Count a failed attempt and lock the account once the threshold is hit.
     * The lock is per-account and complements the per-IP rate limit, so an
     * attacker rotating IPs still cannot brute-force one account.
     */
    public function recordFailedLogin(int $id, int $maxAttempts, int $lockSeconds): void
    {
        $this->db->execute(
            'UPDATE admins
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

    public function setPassword(int $id, string $hash, bool $mustChange = false): void
    {
        $this->db->execute(
            'UPDATE admins
             SET password_hash = ?, password_changed_at = UTC_TIMESTAMP(),
                 must_change_password = ?, remember_token_hash = NULL
             WHERE id = ?',
            [$hash, $mustChange ? 1 : 0, $id]
        );
    }

    public function setRememberToken(int $id, ?string $token): void
    {
        $this->db->execute(
            'UPDATE admins SET remember_token_hash = ? WHERE id = ?',
            [$token === null ? null : hash('sha256', $token), $id]
        );
    }

    public function countActive(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM admins WHERE is_active = 1');
    }

    public function countSuperAdmins(?int $exceptId = null): int
    {
        $sql = "SELECT COUNT(*) FROM admins WHERE role = 'super_admin' AND is_active = 1";
        $bindings = [];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $bindings[] = $exceptId;
        }
        return (int) $this->db->scalar($sql, $bindings);
    }
}
