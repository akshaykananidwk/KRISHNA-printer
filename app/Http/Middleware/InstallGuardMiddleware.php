<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Two-way installer guard.
 *
 *  - Before installation, everything redirects TO /install.
 *  - After installation, /install is refused.
 *
 * The second direction matters most: an installer left reachable on a live
 * site is a complete takeover, because it can rewrite the database credentials
 * and create an administrator.
 */
final class InstallGuardMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $lockFile = (string) Config::get('app.install_lock_file', '');
        $isInstalled = $lockFile !== '' && is_file($lockFile);
        $path = $request->path();
        $isInstallRoute = $path === '/install' || str_starts_with($path, '/install/');

        if ($isInstalled && $isInstallRoute) {
            return $request->isAjax()
                ? Response::json([
                    'success' => false,
                    'error' => 'This application is already installed. The installer is disabled.',
                ], 403)
                : Response::html($this->alreadyInstalledPage(), 403);
        }

        if (!$isInstalled && !$isInstallRoute) {
            // Static assets still need to load so the installer looks right.
            if (str_starts_with($path, '/assets/') || $path === '/favicon.ico') {
                return $next($request);
            }
            return $request->isAjax()
                ? Response::json([
                    'success' => false,
                    'error' => 'This application has not been installed yet.',
                    'redirect' => '/install',
                ], 503)
                : Response::redirect('/install');
        }

        return $next($request);
    }

    private function alreadyInstalledPage(): string
    {
        return '<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installer disabled</title>
<style>
 body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f5f7f6;color:#14261f;
      display:grid;place-items:center;min-height:100vh;margin:0;padding:1.5rem}
 .card{background:#fff;padding:2rem;border-radius:12px;max-width:32rem;
       box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center}
 h1{margin:0 0 .75rem;font-size:1.35rem}
 p{color:#5a6b64;line-height:1.6;margin:0 0 1rem}
 a{color:#1e6f5c;font-weight:600}
 code{background:#eef2f0;padding:.15rem .4rem;border-radius:4px;font-size:.9em}
</style></head>
<body><div class="card">
<h1>The installer is disabled</h1>
<p>This application is already installed, so the setup wizard has been locked to
prevent it being used to take over the site.</p>
<p>To reinstall deliberately, delete <code>storage/installed.lock</code> on the server first.</p>
<p><a href="/admin/login">Go to the admin sign-in page</a></p>
</div></body></html>';
    }
}
