<?php
declare(strict_types=1);

/**
 * Application configuration.
 *
 * Values here are defaults and structure. Deployment-specific credentials live
 * in config/config.php, which the installer generates and which is excluded
 * from version control and preserved across GitHub updates.
 */

$generated = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$base = dirname(__DIR__);

/** Read a value from the generated config, then the environment, then a default. */
$value = static function (string $key, mixed $default = null) use ($generated): mixed {
    if (array_key_exists($key, $generated)) {
        return $generated[$key];
    }
    $env = getenv(strtoupper($key));
    return $env === false || $env === '' ? $default : $env;
};

return [
    'name' => $value('app_name', 'Krishna Printer'),
    'version' => trim((string) @file_get_contents($base . '/VERSION')) ?: '1.0.0',
    'env' => $value('app_env', 'production'),
    'debug' => filter_var($value('app_debug', false), FILTER_VALIDATE_BOOL),
    'url' => rtrim((string) $value('app_url', ''), '/'),
    'key' => (string) $value('app_key', ''),
    'timezone' => $value('app_timezone', 'Asia/Kolkata'),
    'locale' => 'en',
    'currency' => 'INR',
    'currency_symbol' => '₹',

    // Set to true only when the app genuinely sits behind a reverse proxy or
    // load balancer you control. When false, X-Forwarded-* headers are ignored,
    // so a client cannot spoof its IP past the rate limiter.
    'trust_proxy' => filter_var($value('trust_proxy', false), FILTER_VALIDATE_BOOL),
    'force_https' => $value('force_https', '0'),
    'hsts_max_age' => 31536000,

    'log_level' => $value('log_level', 'info'),
    'log_retention_days' => 30,

    // Paths. Uploads, storage, logs and backups all sit OUTSIDE /public.
    'base_path' => $base,
    'public_path' => $base . '/public',
    'view_path' => $base . '/resources/views',
    'storage_path' => $base . '/storage',
    'upload_path' => $value('upload_path', $base . '/uploads'),
    'log_path' => $base . '/logs',
    'backup_path' => $base . '/backups',
    'session_path' => $base . '/storage/sessions',
    'cache_path' => $base . '/storage/cache',
    'migrations_path' => $base . '/database/migrations',

    'session_name' => 'KPMSSESSID',
    'session_lifetime' => 7200,
    'session_rotate_seconds' => 900,

    'installed' => is_file($base . '/storage/installed.lock'),
    'install_lock_file' => $base . '/storage/installed.lock',
    'maintenance_file' => $base . '/storage/maintenance.flag',
];
