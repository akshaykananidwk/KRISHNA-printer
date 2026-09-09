<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Applies the response security headers, including a nonce-based CSP.
 *
 * The nonce is generated per request and exposed via Config so templates can
 * stamp it onto their inline <script>/<style> blocks. That lets us keep a
 * strict policy with no 'unsafe-inline'.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        Config::set('runtime.csp_nonce', $nonce);

        $response = $next($request);

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "style-src 'self' 'nonce-$nonce'",
            "script-src 'self' 'nonce-$nonce'",
            "connect-src 'self'",
        ];

        // The payment gateway checkout widget needs its own origins; only add
        // them when payments are actually enabled.
        if ((string) Config::get('settings.payment_enabled', '0') === '1') {
            foreach ((array) Config::get('payment.csp_sources', []) as $source) {
                $directives[] = $source;
            }
        }

        $response->header('Content-Security-Policy', implode('; ', $directives));
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-Frame-Options', 'DENY');
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->header('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=(self)');
        $response->header('Cross-Origin-Opener-Policy', 'same-origin');
        $response->header('X-Permitted-Cross-Domain-Policies', 'none');

        if ($request->isSecure()) {
            $response->header(
                'Strict-Transport-Security',
                'max-age=' . (int) Config::get('app.hsts_max_age', 31536000) . '; includeSubDomains'
            );
        }

        return $response;
    }
}
