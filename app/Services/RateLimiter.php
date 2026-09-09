<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Fixed-window rate limiter backed by the database so limits hold across all
 * PHP workers and across load-balanced app servers (a per-process APCu cache
 * would not — with 50+ locations this runs on more than one node).
 *
 * The counter row is upserted atomically; concurrent requests cannot both read
 * a stale count because the increment happens inside the UPDATE.
 */
final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Register a hit and report whether the caller is still within budget.
     *
     * @return array{allowed:bool,remaining:int,retry_after:int,limit:int}
     */
    public function hit(string $key, int $maxAttempts, int $decaySeconds): array
    {
        $bucket = $this->bucketKey($key);
        $now = time();
        $windowStart = $now - ($now % $decaySeconds);
        $expiresAt = $windowStart + $decaySeconds;

        // Housekeeping: drop expired windows occasionally rather than on every
        // hit, so the limiter costs one round-trip in the common case.
        if (random_int(1, 100) === 1) {
            $this->db->execute('DELETE FROM rate_limits WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', $now)]);
        }

        $this->db->execute(
            'INSERT INTO rate_limits (bucket_key, window_start, attempts, expires_at)
             VALUES (:bucket_key, :window_start, 1, :expires_at)
             ON DUPLICATE KEY UPDATE attempts = attempts + 1',
            [
                'bucket_key' => $bucket,
                'window_start' => gmdate('Y-m-d H:i:s', $windowStart),
                'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
            ]
        );

        $attempts = (int) $this->db->scalar(
            'SELECT attempts FROM rate_limits WHERE bucket_key = ? AND window_start = ?',
            [$bucket, gmdate('Y-m-d H:i:s', $windowStart)]
        );

        return [
            'allowed' => $attempts <= $maxAttempts,
            'remaining' => max(0, $maxAttempts - $attempts),
            'retry_after' => max(1, $expiresAt - $now),
            'limit' => $maxAttempts,
        ];
    }

    /** Read the current count without incrementing it. */
    public function attempts(string $key, int $decaySeconds): int
    {
        $now = time();
        $windowStart = $now - ($now % $decaySeconds);
        return (int) $this->db->scalar(
            'SELECT attempts FROM rate_limits WHERE bucket_key = ? AND window_start = ?',
            [$this->bucketKey($key), gmdate('Y-m-d H:i:s', $windowStart)]
        );
    }

    public function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        return $this->attempts($key, $decaySeconds) >= $maxAttempts;
    }

    /** Clear a bucket — called after a successful login so a user is not punished. */
    public function clear(string $key): void
    {
        $this->db->execute('DELETE FROM rate_limits WHERE bucket_key = ?', [$this->bucketKey($key)]);
    }

    /**
     * Hash the caller-supplied key. This keeps IP addresses and email
     * addresses out of the rate_limits table (they would otherwise be personal
     * data retained for no operational reason) and bounds the column width.
     */
    private function bucketKey(string $key): string
    {
        $pepper = (string) Config::get('app.key', 'kpms');
        return hash_hmac('sha256', $key, $pepper);
    }
}
