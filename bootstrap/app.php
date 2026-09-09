<?php
declare(strict_types=1);

/**
 * Application bootstrap.
 *
 * Shared by the front controller, the queue worker, the scheduler and the test
 * suite, so every entry point builds an identically-configured application.
 */

use App\Core\Application;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Encrypter;
use App\Core\Session;
use App\Http\Middleware;

$basePath = dirname(__DIR__);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}

require_once $basePath . '/app/Core/Autoloader.php';

$autoloader = new Autoloader();
$autoloader->addNamespace('App', $basePath . '/app');
$autoloader->register();

$app = new Application($basePath);
$app->boot();

// The encryption key is required for anything that touches a stored secret.
// During installation it does not exist yet, which is expected.
if (Encrypter::hasKey()) {
    Encrypter::setKey((string) Config::get('app.key'));
}

$container = $app->container();
$container->bind(Database::class, static fn (): Database => Database::instance());

// Runtime settings layer on top of the file config. Skipped before install,
// when there is no database to read them from.
if ((bool) Config::get('app.installed', false)) {
    try {
        Config::hydrateFromDatabase(Database::instance());

        // A few settings are authoritative at runtime and override the file.
        foreach ([
            'app.timezone' => 'settings.timezone',
            'app.force_https' => 'settings.force_https',
        ] as $target => $source) {
            $value = Config::get($source);
            if ($value !== null && $value !== '') {
                Config::set($target, $value);
            }
        }

        // Upload limits are operator-tunable from the settings screen.
        $maxFileMb = (int) Config::get('settings.max_file_mb', 0);
        if ($maxFileMb > 0) {
            Config::set('uploads.max_file_bytes', $maxFileMb * 1024 * 1024);
        }
        foreach ([
            'settings.max_files_per_batch' => 'uploads.max_files_per_batch',
            'settings.max_pages_per_job' => 'uploads.max_pages_per_job',
            'settings.file_retention_hours' => 'uploads.retention_hours',
            'settings.health_check_interval' => 'printing.queue.health_check_interval',
        ] as $source => $target) {
            $value = Config::get($source);
            if ($value !== null && $value !== '' && (int) $value > 0) {
                Config::set($target, (int) $value);
            }
        }

        date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Kolkata'));
    } catch (\Throwable $e) {
        \App\Core\Logger::error('Could not load runtime settings', ['error' => $e->getMessage()]);
    }
}

// Middleware aliases used in the route definitions.
$app->registerMiddleware([
    'install_guard' => Middleware\InstallGuardMiddleware::class,
    'maintenance' => Middleware\MaintenanceMiddleware::class,
    'https' => Middleware\HttpsMiddleware::class,
    'session' => Middleware\StartSessionMiddleware::class,
    'security_headers' => Middleware\SecurityHeadersMiddleware::class,
    'csrf' => Middleware\CsrfMiddleware::class,
    'rate_limit' => Middleware\RateLimitMiddleware::class,
    'auth' => Middleware\AuthMiddleware::class,
    'role' => Middleware\RoleMiddleware::class,
    'api_token' => Middleware\ApiTokenMiddleware::class,
]);

// routes/web.php is written against a $router variable.
$router = $app->router();
require $basePath . '/routes/web.php';

return $app;
