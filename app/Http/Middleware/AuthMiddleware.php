<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\AuthService;

/**
 * Requires an authenticated admin. Also enforces the forced-password-change
 * gate, so an account created with a temporary password cannot be used for
 * anything else until that password is replaced.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthService $auth,
        private AuditService $audit
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $admin = $this->auth->user();

        if ($admin === null) {
            if ($request->isAjax()) {
                return Response::json([
                    'success' => false,
                    'error' => 'Your session has ended. Please sign in again.',
                    'redirect' => '/admin/login',
                ], 401);
            }

            // Remember where they were headed so the login can return them.
            Session::put('intended_url', $request->path());
            return Response::redirect('/admin/login');
        }

        $this->audit->setActor('admin', (string) $admin->id(), $admin->string('name'));
        $request->setAttribute('admin', $admin);

        if ($admin->bool('must_change_password')) {
            $allowed = ['/admin/password', '/admin/logout'];
            if (!in_array($request->path(), $allowed, true)) {
                return $request->isAjax()
                    ? Response::json([
                        'success' => false,
                        'error' => 'You must change your password before continuing.',
                        'redirect' => '/admin/password',
                    ], 403)
                    : Response::redirect('/admin/password');
            }
        }

        return $next($request);
    }
}
