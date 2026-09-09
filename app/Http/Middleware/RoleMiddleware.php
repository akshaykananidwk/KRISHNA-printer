<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Admin;
use App\Services\AuditService;

/**
 * Permission gate.
 *
 * The required permission is derived from the route path rather than passed as
 * a middleware parameter, so a new admin route cannot accidentally ship
 * without authorisation: an unmapped path falls through to the most
 * restrictive default.
 */
final class RoleMiddleware implements MiddlewareInterface
{
    /**
     * Path prefix => required permission. Longest matching prefix wins, so a
     * specific rule can tighten a general one.
     *
     * @var array<string,string>
     */
    private const PERMISSION_MAP = [
        '/admin/dashboard' => 'dashboard.view',
        '/admin/locations' => 'locations.view',
        '/admin/locations/create' => 'locations.manage',
        '/admin/locations/store' => 'locations.manage',
        '/admin/printers' => 'printers.view',
        '/admin/printers/create' => 'printers.manage',
        '/admin/printers/store' => 'printers.manage',
        '/admin/pricing' => 'pricing.view',
        '/admin/jobs' => 'jobs.view',
        '/admin/reports' => 'reports.view',
        '/admin/settings' => 'settings.manage',
        '/admin/system' => 'settings.manage',
        '/admin/backups' => 'backups.view',
        '/admin/audit' => 'audit.view',
        '/admin/users' => 'settings.manage',
        '/admin/devices' => 'printers.manage',
        '/admin/tokens' => 'settings.manage',
    ];

    /** Path suffixes that always require a write permission on their section. */
    private const WRITE_SUFFIXES = ['/create', '/store', '/edit', '/update', '/delete', '/toggle', '/rotate', '/probe'];

    public function __construct(private AuditService $audit)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        /** @var Admin|null $admin */
        $admin = $request->attribute('admin');

        if (!$admin instanceof Admin) {
            throw new HttpException(401, 'Authentication is required.');
        }

        $permission = $this->requiredPermission($request);

        if (!$admin->can($permission)) {
            $this->audit->security(
                'authz.denied',
                sprintf(
                    '%s (%s) was denied access to %s — needs "%s".',
                    $admin->string('name'),
                    $admin->roleLabel(),
                    $request->path(),
                    $permission
                ),
                ['path' => $request->path(), 'permission' => $permission]
            );

            if ($request->isAjax()) {
                return Response::json([
                    'success' => false,
                    'error' => 'You do not have permission to do that.',
                ], 403);
            }

            throw new HttpException(
                403,
                'You do not have permission to view that page. Ask an administrator if you need access.'
            );
        }

        return $next($request);
    }

    private function requiredPermission(Request $request): string
    {
        $path = rtrim($request->path(), '/');
        $method = $request->method();

        $best = '';
        $permission = null;

        foreach (self::PERMISSION_MAP as $prefix => $required) {
            if (($path === $prefix || str_starts_with($path . '/', $prefix . '/'))
                && strlen($prefix) > strlen($best)) {
                $best = $prefix;
                $permission = $required;
            }
        }

        if ($permission === null) {
            // An unmapped admin route requires the highest permission rather
            // than the lowest — failing closed, not open.
            return 'settings.manage';
        }

        // Any non-GET request, or a path that clearly mutates, needs the
        // section's manage permission rather than its view permission.
        $isWrite = $method !== 'GET' && $method !== 'HEAD';
        if (!$isWrite) {
            foreach (self::WRITE_SUFFIXES as $suffix) {
                if (str_contains($path, $suffix)) {
                    $isWrite = true;
                    break;
                }
            }
        }

        if ($isWrite && str_ends_with($permission, '.view')) {
            $section = explode('.', $permission)[0];
            return $section . '.manage';
        }

        return $permission;
    }
}
