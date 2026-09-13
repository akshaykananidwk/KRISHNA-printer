<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * The front page.
 *
 * Customers never see it — they arrive by QR code straight at a printer. It is
 * for everyone else who types the domain in: a shop owner deciding whether to
 * join, an office wondering what the printer in the corner is doing, and the
 * operator on their way to the panel. The root used to redirect to the admin
 * sign-in, which told none of them anything and offered two of them an account
 * they do not have.
 */
final class HomeController extends Controller
{
    /** GET / */
    public function index(Request $request): Response
    {
        return $this->view('home', [
            'title' => 'Cloud printing',
            // The page says "pays" or "pays if you charge" depending on this,
            // rather than describing a checkout an operator has turned off.
            'paymentsEnabled' => (string) Config::get('settings.payment_enabled', '0') === '1',
        ]);
    }
}
