<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Serves a maintenance page while an update is running.
 *
 * Signed-in admins and the health endpoint pass through, so an operator can
 * watch the update and monitoring does not page anyone.
 */
final class MaintenanceMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $file = (string) Config::get('app.maintenance_file', '');

        if ($file === '' || !is_file($file)) {
            return $next($request);
        }

        // Always-allowed paths: the admin surface (so the update can be
        // monitored and, if needed, rolled back), and the health probe.
        $path = $request->path();
        if (str_starts_with($path, '/admin') || $path === '/health' || $path === '/up') {
            return $next($request);
        }

        $details = json_decode((string) @file_get_contents($file), true);
        $since = is_array($details) ? (string) ($details['since'] ?? '') : '';

        $payload = [
            'success' => false,
            'error' => 'The printing service is briefly unavailable while we apply an update. '
                . 'Please try again in a few minutes.',
            'maintenance' => true,
            'since' => $since,
        ];

        if ($request->isAjax() || str_starts_with($path, '/api/')) {
            return Response::json($payload, 503)->header('Retry-After', '120');
        }

        try {
            $html = View::render('errors/maintenance', [
                'since' => $since,
                'businessName' => (string) Config::get('settings.business_name', Config::get('app.name', 'Krishna Printer')),
                'flashes' => Session::takeFlashes(),
            ]);
        } catch (\Throwable) {
            $html = '<!doctype html><meta charset="utf-8"><title>Back shortly</title>'
                . '<body style="font-family:system-ui;padding:2rem;text-align:center">'
                . '<h1>Back shortly</h1><p>We are applying a quick update. Please try again in a few minutes.</p>';
        }

        return Response::html($html, 503)->header('Retry-After', '120');
    }
}
