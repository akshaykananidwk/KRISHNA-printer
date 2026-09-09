<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Session handling with hardened cookie flags, periodic ID rotation and
 * flash-message support. Sessions are file-backed inside /storage/sessions so
 * they are never readable through the web root.
 */
final class Session
{
    private static bool $started = false;

    public static function start(bool $secure = true): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        if (PHP_SAPI === 'cli') {
            // CLI (worker, tests): use an in-memory array shim instead of real sessions.
            self::$started = true;
            $GLOBALS['_SESSION_CLI'] = $GLOBALS['_SESSION_CLI'] ?? [];
            $_SESSION = &$GLOBALS['_SESSION_CLI'];
            return;
        }

        $path = Config::get('app.session_path', '');
        if (is_string($path) && $path !== '' && is_dir($path) && is_writable($path)) {
            session_save_path($path);
        }

        session_name((string) Config::get('app.session_name', 'KPMSSESSID'));
        session_set_cookie_params([
            'lifetime' => (int) Config::get('app.session_lifetime', 7200),
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) (int) Config::get('app.session_lifetime', 7200));

        session_start();
        self::$started = true;

        // Absolute-idle expiry, independent of the cookie lifetime.
        $lifetime = (int) Config::get('app.session_lifetime', 7200);
        $last = (int) ($_SESSION['__last_activity'] ?? time());
        if (time() - $last > $lifetime) {
            self::destroy();
            session_start();
        }
        $_SESSION['__last_activity'] = time();

        // Rotate the session ID periodically to shorten the fixation window.
        $rotated = (int) ($_SESSION['__rotated_at'] ?? 0);
        if ($rotated === 0) {
            $_SESSION['__rotated_at'] = time();
        } elseif (time() - $rotated > (int) Config::get('app.session_rotate_seconds', 900)) {
            session_regenerate_id(true);
            $_SESSION['__rotated_at'] = time();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);
        return $value;
    }

    public static function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['__rotated_at'] = time();
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name() ?: 'KPMSSESSID', '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]);
            }
            session_destroy();
        }
    }

    public static function flash(string $type, string $message): void
    {
        $flashes = $_SESSION['__flash'] ?? [];
        $flashes[] = ['type' => $type, 'message' => $message];
        $_SESSION['__flash'] = $flashes;
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function takeFlashes(): array
    {
        $flashes = $_SESSION['__flash'] ?? [];
        unset($_SESSION['__flash']);
        return is_array($flashes) ? $flashes : [];
    }

    /** Stable per-visitor identifier used to scope guest print sessions. */
    public static function customerSessionId(): string
    {
        if (!isset($_SESSION['customer_session_id']) || !is_string($_SESSION['customer_session_id'])) {
            $_SESSION['customer_session_id'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['customer_session_id'];
    }

    public static function id(): string
    {
        return PHP_SAPI === 'cli' ? 'cli' : (string) session_id();
    }
}
