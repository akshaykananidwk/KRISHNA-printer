<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AuditService;

/**
 * Starts the session and seeds per-request context that views and the audit
 * trail both need.
 */
final class StartSessionMiddleware implements MiddlewareInterface
{
    public function __construct(private AuditService $audit)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        // The secure cookie flag must reflect reality: setting it on a plain
        // HTTP request would make the cookie invisible and log everyone out.
        Session::start($request->isSecure());

        $this->audit->setRequestContext($request->ip(), $request->userAgent());
        $this->audit->adoptSessionActor();

        // Shared view data, set once rather than passed through every render.
        View::share('basePath', $request->basePath());
        View::share('currentPath', $request->path());
        View::share('cspNonce', (string) Config::get('runtime.csp_nonce', ''));

        return $next($request);
    }
}
