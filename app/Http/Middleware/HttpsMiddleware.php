<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Redirects plain HTTP to HTTPS when the deployment has HTTPS enforcement on.
 *
 * Enforcement is a setting rather than a hard-coded rule because local
 * development and the installer must be reachable over http://localhost.
 */
final class HttpsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!$this->shouldEnforce($request)) {
            return $next($request);
        }

        if ($request->isSecure()) {
            return $next($request);
        }

        if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
            // Never 301 a POST: the body would be dropped. Refuse instead.
            return Response::json([
                'success' => false,
                'error' => 'This endpoint requires a secure (HTTPS) connection.',
            ], 403);
        }

        $target = 'https://' . $request->host() . ($_SERVER['REQUEST_URI'] ?? '/');
        return Response::redirect($target, 301);
    }

    private function shouldEnforce(Request $request): bool
    {
        if ((string) Config::get('settings.force_https', Config::get('app.force_https', '0')) !== '1') {
            return false;
        }
        // Loopback and private ranges are exempt so on-premise/LAN installs and
        // the agent's local health probe keep working.
        $host = strtolower(explode(':', $request->host())[0]);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }
        return true;
    }
}
