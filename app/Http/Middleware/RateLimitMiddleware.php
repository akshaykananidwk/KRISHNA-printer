<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;

/**
 * Generic per-IP throttle applied to the whole public surface. Sensitive
 * endpoints (login, upload, payment) add their own tighter limits on top.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private RateLimiter $limiter)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $max = (int) Config::get('security.rate_limit.global_max', 300);
        $decay = (int) Config::get('security.rate_limit.global_decay', 60);

        $result = $this->limiter->hit('global:' . $request->ip(), $max, $decay);

        if (!$result['allowed']) {
            Logger::warning('Rate limit exceeded', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);
            throw new HttpException(429, 'Too many requests. Please slow down and try again shortly.', [
                'Retry-After' => (string) $result['retry_after'],
            ]);
        }

        $response = $next($request);
        $response->header('X-RateLimit-Limit', (string) $result['limit']);
        $response->header('X-RateLimit-Remaining', (string) $result['remaining']);
        return $response;
    }
}
