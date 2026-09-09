<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

abstract class Controller
{
    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        $data += [
            'appName' => (string) Config::get('settings.business_name', Config::get('app.name', 'Krishna Printer')),
            'appVersion' => (string) Config::get('app.version', ''),
            'cspNonce' => (string) Config::get('runtime.csp_nonce', ''),
            'currencySymbol' => (string) Config::get('app.currency_symbol', '₹'),
            'flashes' => Session::takeFlashes(),
        ];

        return Response::html(View::render($template, $data), $status);
    }

    /** @param array<string,mixed> $data */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /** @param array<string,mixed> $extra */
    protected function ok(string $message = '', array $extra = []): Response
    {
        return Response::json(['success' => true, 'message' => $message] + $extra);
    }

    /** @param array<string,mixed> $extra */
    protected function fail(string $message, int $status = 400, array $extra = []): Response
    {
        return Response::json(['success' => false, 'error' => $message] + $extra, $status);
    }

    protected function redirect(string $to, string $flashType = '', string $flashMessage = ''): Response
    {
        if ($flashMessage !== '') {
            Session::flash($flashType !== '' ? $flashType : 'info', $flashMessage);
        }
        return Response::redirect($to);
    }

    protected function back(Request $request, string $flashType = '', string $flashMessage = ''): Response
    {
        $referer = (string) $request->header('Referer', '');
        $target = '/';

        // Only follow a same-origin referer — never redirect a user to a URL an
        // attacker put in a header.
        if ($referer !== '') {
            $parts = parse_url($referer);
            $host = $parts['host'] ?? '';
            if ($host === '' || $host === explode(':', $request->host())[0]) {
                $target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            }
        }

        return $this->redirect($target, $flashType, $flashMessage);
    }

    protected function isAdmin(Request $request): bool
    {
        return $request->attribute('admin') !== null;
    }
}
