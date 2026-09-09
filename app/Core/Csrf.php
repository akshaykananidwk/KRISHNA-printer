<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Synchroniser-token CSRF protection.
 *
 * One secret per session; the value placed in forms is a per-request masked
 * token (random pad XOR secret) so the same secret is never emitted twice.
 * That defeats BREACH-style compression oracles against the token itself.
 */
final class Csrf
{
    private const SESSION_KEY = '__csrf_secret';
    public const FIELD = '_csrf_token';
    public const HEADER = 'X-CSRF-Token';

    public static function secret(): string
    {
        $secret = Session::get(self::SESSION_KEY);
        if (!is_string($secret) || strlen($secret) !== 32) {
            $secret = random_bytes(32);
            Session::put(self::SESSION_KEY, $secret);
        }
        return $secret;
    }

    /** Fresh masked token, safe to embed in HTML. */
    public static function token(): string
    {
        $secret = self::secret();
        $pad = random_bytes(32);
        return base64_encode($pad . ($pad ^ $secret));
    }

    public static function verify(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $raw = base64_decode($token, true);
        if ($raw === false || strlen($raw) !== 64) {
            return false;
        }
        $pad = substr($raw, 0, 32);
        $masked = substr($raw, 32, 32);
        return hash_equals(self::secret(), $pad ^ $masked);
    }

    /** Hidden input for forms. */
    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    public static function rotate(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
