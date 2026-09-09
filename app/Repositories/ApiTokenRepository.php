<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Config;

final class ApiTokenRepository extends Repository
{
    protected function table(): string
    {
        return 'api_tokens';
    }

    /**
     * Issue a token. The plaintext is returned once and never stored; only the
     * SHA-256 hash goes to the database, so a database compromise does not
     * yield working agent credentials.
     *
     * @return array{id:int,token:string,prefix:string}
     */
    public function issue(
        string $name,
        string $abilities = 'agent',
        ?int $deviceId = null,
        ?int $locationId = null,
        ?int $createdBy = null,
        ?int $lifetimeDays = null
    ): array {
        $prefix = (string) Config::get('security.api_tokens.prefix', 'kpms_');
        $length = (int) Config::get('security.api_tokens.length', 40);
        $random = rtrim(strtr(base64_encode(random_bytes($length)), '+/', '-_'), '=');
        $plain = $prefix . substr($random, 0, $length);

        $lifetimeDays ??= (int) Config::get('security.api_tokens.default_lifetime_days', 365);

        $id = $this->db->insert('api_tokens', [
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 12),
            'abilities' => $abilities,
            'device_id' => $deviceId,
            'location_id' => $locationId,
            'created_by' => $createdBy,
            'expires_at' => $lifetimeDays > 0 ? gmdate('Y-m-d H:i:s', time() + $lifetimeDays * 86400) : null,
        ]);

        return ['id' => $id, 'token' => $plain, 'prefix' => substr($plain, 0, 12)];
    }

    /**
     * Resolve a presented bearer token.
     *
     * The lookup is by hash (an indexed equality read, so no timing signal from
     * the query itself), and a token is valid while it is un-revoked and
     * un-expired, OR while it is inside its post-rotation grace window.
     *
     * @return array<string,mixed>|null
     */
    public function resolve(string $plainToken): ?array
    {
        if ($plainToken === '') {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT t.*, d.location_id AS device_location_id
             FROM api_tokens t
             LEFT JOIN devices d ON d.id = t.device_id
             WHERE t.token_hash = ?',
            [hash('sha256', $plainToken)]
        );

        if ($row === null) {
            return null;
        }

        $now = time();

        if ($row['revoked_at'] !== null) {
            // A revoked token may still be inside its rotation grace window.
            $grace = $row['grace_until'];
            if ($grace === null || strtotime((string) $grace . ' UTC') < $now) {
                return null;
            }
        }

        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at'] . ' UTC') < $now) {
            return null;
        }

        return $row;
    }

    public function touch(int $tokenId, string $ip): void
    {
        $this->db->execute(
            'UPDATE api_tokens SET last_used_at = UTC_TIMESTAMP(), last_used_ip = ? WHERE id = ?',
            [$ip, $tokenId]
        );
    }

    /**
     * Rotate a token: issue a replacement and put the old one into a grace
     * window rather than killing it instantly, so an agent that is mid-poll
     * finishes its request and picks up the new credential on the next cycle.
     *
     * @return array{id:int,token:string,prefix:string}
     */
    public function rotate(int $tokenId, ?int $createdBy = null): array
    {
        $existing = $this->findRow($tokenId);
        if ($existing === null) {
            throw new \RuntimeException('Token not found.');
        }

        $grace = (int) Config::get('security.api_tokens.rotation_grace_seconds', 900);

        return $this->db->transaction(function () use ($existing, $tokenId, $createdBy, $grace): array {
            $new = $this->issue(
                (string) $existing['name'],
                (string) $existing['abilities'],
                $existing['device_id'] === null ? null : (int) $existing['device_id'],
                $existing['location_id'] === null ? null : (int) $existing['location_id'],
                $createdBy
            );

            $this->db->execute(
                'UPDATE api_tokens
                 SET revoked_at = UTC_TIMESTAMP(),
                     grace_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)
                 WHERE id = ?',
                [$grace, $tokenId]
            );

            $this->db->execute('UPDATE api_tokens SET rotated_from = ? WHERE id = ?', [$tokenId, $new['id']]);

            return $new;
        });
    }

    public function revoke(int $tokenId): int
    {
        return $this->db->execute(
            'UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP(), grace_until = NULL WHERE id = ?',
            [$tokenId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->db->select(
            'SELECT t.*, d.name AS device_name, l.name AS location_name, a.name AS created_by_name
             FROM api_tokens t
             LEFT JOIN devices d ON d.id = t.device_id
             LEFT JOIN locations l ON l.id = t.location_id
             LEFT JOIN admins a ON a.id = t.created_by
             ORDER BY t.id DESC'
        );
    }

    public function pruneExpired(int $days = 30): int
    {
        return $this->db->execute(
            'DELETE FROM api_tokens
             WHERE revoked_at IS NOT NULL
               AND revoked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)',
            [$days]
        );
    }

    /** @param array<string,mixed> $token */
    public static function hasAbility(array $token, string $ability): bool
    {
        $abilities = array_map('trim', explode(',', (string) ($token['abilities'] ?? '')));
        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }
}
