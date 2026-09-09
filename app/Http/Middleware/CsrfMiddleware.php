<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Csrf;
use App\Core\Exceptions\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;

/**
 * Verifies the CSRF token on every state-changing request.
 *
 * Routes that authenticate with a bearer API token (the print agents and the
 * payment webhook) are exempt: they carry no session cookie, so there is no
 * ambient authority for an attacker to ride, and both verify their own
 * signature/token instead.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $token = $request->string(Csrf::FIELD) ?: (string) $request->header(Csrf::HEADER, '');

        if (!Csrf::verify($token)) {
            Logger::warning('CSRF token rejected', [
                'path' => $request->path(),
                'ip' => $request->ip(),
                'method' => $request->method(),
                'token_present' => $token !== '',
            ]);
            throw new HttpException(419, 'Your session has expired. Please refresh the page and try again.');
        }

        return $next($request);
    }
}
