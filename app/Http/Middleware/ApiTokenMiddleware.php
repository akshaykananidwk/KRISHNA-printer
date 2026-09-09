<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ApiTokenRepository;
use App\Services\AuditService;
use App\Services\RateLimiter;

/**
 * Bearer-token authentication for the print agent API.
 *
 * Agents authenticate with a token that maps to a specific device, which in
 * turn maps to a location. Every downstream query is scoped to that device, so
 * a compromised agent at one shop cannot read or print anything belonging to
 * another.
 */
final class ApiTokenMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ApiTokenRepository $tokens,
        private RateLimiter $limiter,
        private AuditService $audit
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $presented = $request->bearerToken();

        if ($presented === null || $presented === '') {
            return Response::json([
                'success' => false,
                'error' => 'An API token is required.',
            ], 401)->header('WWW-Authenticate', 'Bearer');
        }

        // Throttle by token prefix rather than by IP: agents behind one shop's
        // NAT share an address, and a stolen token should be limited on its own.
        $limit = $this->limiter->hit(
            'api:' . substr($presented, 0, 12),
            (int) Config::get('security.rate_limit.api_max', 600),
            (int) Config::get('security.rate_limit.api_decay', 60)
        );

        if (!$limit['allowed']) {
            return Response::json([
                'success' => false,
                'error' => 'Rate limit exceeded.',
            ], 429)->header('Retry-After', (string) $limit['retry_after']);
        }

        $token = $this->tokens->resolve($presented);

        if ($token === null) {
            $this->audit->security('api.invalid_token', 'An API request presented an invalid or expired token.', [
                'ip' => $request->ip(),
                'prefix' => substr($presented, 0, 12),
                'path' => $request->path(),
            ]);
            return Response::json([
                'success' => false,
                'error' => 'That token is not valid.',
            ], 401)->header('WWW-Authenticate', 'Bearer');
        }

        if (!ApiTokenRepository::hasAbility($token, 'agent') && !ApiTokenRepository::hasAbility($token, '*')) {
            return Response::json([
                'success' => false,
                'error' => 'This token does not have permission for the agent API.',
            ], 403);
        }

        $this->tokens->touch((int) $token['id'], $request->ip());

        $request->setAttribute('api_token', $token);
        $request->setAttribute('device_id', $token['device_id'] === null ? null : (int) $token['device_id']);
        $request->setAttribute(
            'token_location_id',
            $token['device_location_id'] ?? $token['location_id']
        );

        $this->audit->setActor(
            'agent',
            $token['device_id'] === null ? null : (string) $token['device_id'],
            (string) $token['name']
        );

        $response = $next($request);

        // Tell the agent when its credential is about to expire, so it can
        // prompt for rotation before it stops working mid-shift.
        if ($token['expires_at'] !== null) {
            $secondsLeft = strtotime((string) $token['expires_at'] . ' UTC') - time();
            if ($secondsLeft < 14 * 86400) {
                $response->header('X-Token-Expires-In', (string) max(0, $secondsLeft));
            }
        }
        if ($token['revoked_at'] !== null) {
            // Inside the rotation grace window.
            $response->header('X-Token-Rotated', 'true');
        }

        return $response;
    }
}
