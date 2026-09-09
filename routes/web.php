<?php
declare(strict_types=1);

/**
 * Route definitions.
 *
 * Middleware groups:
 *   web    — session, CSRF, security headers, rate limit, maintenance gate
 *   admin  — web + authentication + role authorisation
 *   api    — bearer token, no session, no CSRF (signature-authenticated)
 *   guest  — public customer flow
 *
 * @var \App\Core\Router $router
 */

use App\Http\Controllers\Admin;
use App\Http\Controllers\Api;
use App\Http\Controllers\Customer;
use App\Http\Controllers\InstallController;

// ---------------------------------------------------------------------
// Installer — only reachable before installation (InstallGuardMiddleware)
// ---------------------------------------------------------------------
$router->group([
    'prefix' => '/install',
    'middleware' => ['install_guard', 'session', 'security_headers', 'csrf'],
], function ($router): void {
    $router->get('', InstallController::class . '@index')->name('install');
    $router->post('/requirements', InstallController::class . '@checkRequirements');
    $router->post('/database', InstallController::class . '@configureDatabase');
    $router->post('/application', InstallController::class . '@configureApplication');
    $router->post('/admin', InstallController::class . '@configureAdmin');
    $router->post('/system', InstallController::class . '@configureSystem');
    $router->post('/migrate', InstallController::class . '@migrate');
    $router->post('/seed', InstallController::class . '@seed');
    $router->post('/finalise', InstallController::class . '@finalise');
});

// ---------------------------------------------------------------------
// Public health probe — no session, no install guard, so a monitor can
// reach it during installation and during an update.
// ---------------------------------------------------------------------
$router->get('/health', Api\HealthController::class . '@check')->name('health');
$router->get('/up', Api\HealthController::class . '@check');

// ---------------------------------------------------------------------
// Customer flow
// ---------------------------------------------------------------------
$router->group(['middleware' => ['install_guard', 'maintenance', 'https', 'session', 'security_headers', 'rate_limit']], function ($router): void {

    $router->get('/', function (\App\Core\Request $request): \App\Core\Response {
        // The site root is not a customer entry point — customers arrive via
        // a QR link. Send anyone else to the admin sign-in.
        return \App\Core\Response::redirect('/admin/login');
    });

    $router->get('/print/status/{printer:\d+}', Customer\PrintController::class . '@printerStatus');
    $router->get('/print/files', Customer\UploadController::class . '@listFiles');

    $router->group(['middleware' => ['csrf']], function ($router): void {
        $router->post('/print/upload', Customer\UploadController::class . '@upload');
        $router->delete('/print/upload/{file:\d+}', Customer\UploadController::class . '@remove');
        $router->post('/print/quote', Customer\PrintController::class . '@quote');
        $router->post('/print/submit', Customer\PrintController::class . '@submit');
        $router->post('/print/payment/verify', Customer\PrintController::class . '@verifyPayment');
        $router->post('/print/payment/cancelled', Customer\PrintController::class . '@paymentCancelled');
        $router->post('/print/job/{number:[A-Z0-9]{4,24}}/cancel', Customer\PrintController::class . '@cancelJob');
    });

    $router->get('/print/pay/{batch:[a-f0-9]{32}}', Customer\PrintController::class . '@payment')->name('print.pay');
    $router->get('/print/success/{batch:[a-f0-9]{32}}', Customer\PrintController::class . '@success')->name('print.success');
    $router->get('/print/job/{number:[A-Z0-9]{4,24}}/status', Customer\PrintController::class . '@jobStatus');

    // The QR landing page, registered last because {location} would otherwise
    // swallow the fixed paths above: "/print/success/<32 hex>" reads equally
    // well as location "success" with a 32-hex token, and the router takes the
    // first match. The lookahead is the belt to that braces — a location code
    // can never shadow a reserved path even if the routes are reordered again.
    // {token} is constrained to hex so a malformed link 404s at the router
    // rather than reaching a database lookup.
    $reserved = 'status|files|upload|quote|submit|payment|pay|success|job';
    $router->get(
        '/print/{location:(?!(?:' . $reserved . ')/)[A-Za-z0-9._-]{1,40}}/{token:[a-f0-9]{32}}',
        Customer\PrintController::class . '@landing'
    )->name('print.landing');
});

// ---------------------------------------------------------------------
// Admin — authentication
// ---------------------------------------------------------------------
$router->group(['prefix' => '/admin', 'middleware' => ['install_guard', 'https', 'session', 'security_headers', 'rate_limit']], function ($router): void {
    $router->get('/login', Admin\AuthController::class . '@showLogin')->name('admin.login');
    $router->post('/login', Admin\AuthController::class . '@login')->middleware('csrf');
    $router->post('/logout', Admin\AuthController::class . '@logout')->middleware(['csrf', 'auth']);
});

// ---------------------------------------------------------------------
// Admin — everything behind authentication
// ---------------------------------------------------------------------
$router->group([
    'prefix' => '/admin',
    'middleware' => ['install_guard', 'https', 'session', 'security_headers', 'rate_limit', 'auth'],
], function ($router): void {

    // The password screen sits outside the role gate so a user forced to
    // change their password can always reach it.
    $router->get('/password', Admin\AuthController::class . '@showPasswordForm');
    $router->post('/password', Admin\AuthController::class . '@changePassword')->middleware('csrf');

    $router->group(['middleware' => ['role']], function ($router): void {

        $router->get('/dashboard', Admin\DashboardController::class . '@index')->name('admin.dashboard');
        $router->get('/dashboard/data', Admin\DashboardController::class . '@data');

        // --- Locations ---------------------------------------------------
        $router->get('/locations', Admin\LocationController::class . '@index')->name('admin.locations');
        $router->get('/locations/create', Admin\LocationController::class . '@create');
        $router->get('/locations/qr-sheet', Admin\LocationController::class . '@batchQrSheet');
        $router->get('/locations/{id:\d+}', Admin\LocationController::class . '@show');
        $router->get('/locations/{id:\d+}/edit', Admin\LocationController::class . '@edit');
        $router->get('/locations/{id:\d+}/qr/{format:svg|png|sheet}', Admin\LocationController::class . '@downloadQr');

        $router->group(['middleware' => ['csrf']], function ($router): void {
            $router->post('/locations', Admin\LocationController::class . '@store');
            $router->put('/locations/{id:\d+}', Admin\LocationController::class . '@update');
            $router->delete('/locations/{id:\d+}', Admin\LocationController::class . '@destroy');
            $router->post('/locations/{id:\d+}/rotate-qr', Admin\LocationController::class . '@rotateQr');
            $router->post('/locations/{id:\d+}/status', Admin\LocationController::class . '@setStatus');
        });

        // --- Printers ----------------------------------------------------
        $router->get('/printers', Admin\PrinterController::class . '@index')->name('admin.printers');
        $router->get('/printers/create', Admin\PrinterController::class . '@create');
        $router->get('/printers/{id:\d+}', Admin\PrinterController::class . '@show');
        $router->get('/printers/{id:\d+}/edit', Admin\PrinterController::class . '@edit');

        $router->group(['middleware' => ['csrf']], function ($router): void {
            $router->post('/printers', Admin\PrinterController::class . '@store');
            $router->put('/printers/{id:\d+}', Admin\PrinterController::class . '@update');
            $router->delete('/printers/{id:\d+}', Admin\PrinterController::class . '@destroy');
            $router->post('/printers/{id:\d+}/test', Admin\PrinterController::class . '@test');
            $router->post('/printers/{id:\d+}/probe', Admin\PrinterController::class . '@probe');
            $router->post('/printers/{id:\d+}/verify-capability', Admin\PrinterController::class . '@verifyCapability');
            $router->post('/printers/{id:\d+}/toggle', Admin\PrinterController::class . '@toggle');
            $router->post('/printers/{id:\d+}/maintenance', Admin\PrinterController::class . '@setMaintenance');
            $router->post('/printers/{id:\d+}/default', Admin\PrinterController::class . '@makeDefault');
        });

        // --- Print agents ------------------------------------------------
        $router->get('/devices', Admin\DeviceController::class . '@index')->name('admin.devices');

        $router->group(['middleware' => ['csrf']], function ($router): void {
            $router->post('/devices', Admin\DeviceController::class . '@store');
            $router->delete('/devices/{id:\d+}', Admin\DeviceController::class . '@destroy');
            $router->post('/devices/{id:\d+}/rotate-token', Admin\DeviceController::class . '@rotateToken');
            $router->post('/devices/{id:\d+}/toggle', Admin\DeviceController::class . '@toggle');
            $router->post('/tokens/{id:\d+}/revoke', Admin\DeviceController::class . '@revokeToken');
        });

        // --- Pricing -----------------------------------------------------
        $router->get('/pricing', Admin\PricingController::class . '@index')->name('admin.pricing');
        $router->get('/pricing/create', Admin\PricingController::class . '@create');
        $router->get('/pricing/{id:\d+}/edit', Admin\PricingController::class . '@edit');

        $router->group(['middleware' => ['csrf']], function ($router): void {
            $router->post('/pricing', Admin\PricingController::class . '@store');
            $router->put('/pricing/{id:\d+}', Admin\PricingController::class . '@update');
            $router->delete('/pricing/{id:\d+}', Admin\PricingController::class . '@destroy');
            $router->post('/pricing/preview', Admin\PricingController::class . '@preview');
            $router->post('/pricing/bulk', Admin\PricingController::class . '@bulk');
        });

        // --- Jobs --------------------------------------------------------
        $router->get('/jobs', Admin\JobController::class . '@index')->name('admin.jobs');
        $router->get('/jobs/live', Admin\JobController::class . '@live');
        $router->get('/jobs/{number:[A-Z0-9]{4,24}}', Admin\JobController::class . '@show');

        $router->group(['middleware' => ['csrf']], function ($router): void {
            $router->post('/jobs/{number:[A-Z0-9]{4,24}}/cancel', Admin\JobController::class . '@cancel');
            $router->post('/jobs/{number:[A-Z0-9]{4,24}}/retry', Admin\JobController::class . '@retry');
            $router->post('/jobs/{number:[A-Z0-9]{4,24}}/refund', Admin\JobController::class . '@refund');
        });

        // --- Reports -----------------------------------------------------
        $router->get('/reports', Admin\ReportController::class . '@index')->name('admin.reports');
        $router->get('/reports/export/{format:csv|excel|pdf}', Admin\ReportController::class . '@export');
        $router->get('/reports/summary-export/{format:csv|excel|pdf}', Admin\ReportController::class . '@exportSummary');

        // --- Settings ----------------------------------------------------
        $router->get('/settings', Admin\SettingsController::class . '@index')->name('admin.settings');

        $router->group(['middleware' => ['csrf']], function ($router): void {
            $router->post('/settings/general', Admin\SettingsController::class . '@saveGeneral');
            $router->post('/settings/pricing', Admin\SettingsController::class . '@saveTax');
            $router->post('/settings/payment', Admin\SettingsController::class . '@savePayment');
            $router->post('/settings/payment/test', Admin\SettingsController::class . '@testPayment');
            $router->post('/settings/printing', Admin\SettingsController::class . '@savePrinting');
        });

        // --- System, updates and backups ---------------------------------
        $router->get('/system', Admin\SystemController::class . '@index')->name('admin.system');
        $router->get('/system/health', Admin\SystemController::class . '@health');
        $router->get('/system/update/{id:\d+}', Admin\SystemController::class . '@updateDetail');
        $router->get('/backups/{id:\d+}/download', Admin\SystemController::class . '@downloadBackup');

        $router->group(['middleware' => ['csrf']], function ($router): void {
            $router->post('/system/update-config', Admin\SystemController::class . '@saveUpdateConfig');
            $router->post('/system/check-update', Admin\SystemController::class . '@checkUpdate');
            $router->post('/system/update', Admin\SystemController::class . '@runUpdate');
            $router->post('/system/maintenance', Admin\SystemController::class . '@toggleMaintenance');
            $router->post('/backups', Admin\SystemController::class . '@createBackup');
            $router->post('/backups/{id:\d+}/restore', Admin\SystemController::class . '@restoreBackup');
            $router->delete('/backups/{id:\d+}', Admin\SystemController::class . '@deleteBackup');
        });

        // --- Team and audit ----------------------------------------------
        $router->get('/users', Admin\UserController::class . '@index')->name('admin.users');
        $router->get('/audit', Admin\UserController::class . '@audit')->name('admin.audit');

        $router->group(['middleware' => ['csrf']], function ($router): void {
            $router->post('/users', Admin\UserController::class . '@store');
            $router->put('/users/{id:\d+}', Admin\UserController::class . '@update');
            $router->delete('/users/{id:\d+}', Admin\UserController::class . '@destroy');
            $router->post('/users/{id:\d+}/reset-password', Admin\UserController::class . '@resetPassword');
            $router->post('/users/{id:\d+}/unlock', Admin\UserController::class . '@unlock');
        });
    });
});

// ---------------------------------------------------------------------
// Agent API — bearer token, no session, no CSRF
// ---------------------------------------------------------------------
$router->group(['prefix' => '/api/agent', 'middleware' => ['install_guard', 'https', 'api_token']], function ($router): void {
    $router->get('/config', Api\AgentController::class . '@config');
    $router->post('/heartbeat', Api\AgentController::class . '@heartbeat');
    $router->post('/claim', Api\AgentController::class . '@claim');
    $router->get('/jobs/{number:[A-Z0-9]{4,24}}/document', Api\AgentController::class . '@document');
    $router->post('/jobs/{number:[A-Z0-9]{4,24}}/status', Api\AgentController::class . '@reportStatus');
    $router->post('/capabilities', Api\AgentController::class . '@reportCapabilities');
});

// ---------------------------------------------------------------------
// Gateway webhooks — HMAC-authenticated over the raw body
// ---------------------------------------------------------------------
$router->group(['prefix' => '/api/webhook', 'middleware' => ['install_guard']], function ($router): void {
    $router->post('/razorpay', Api\WebhookController::class . '@razorpay');
});
