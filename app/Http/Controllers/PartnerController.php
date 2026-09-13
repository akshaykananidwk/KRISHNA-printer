<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\DeviceRepository;
use App\Repositories\PartnerRepository;
use App\Repositories\PrinterRepository;
use App\Repositories\PrintJobRepository;
use App\Services\AuthService;
use App\Services\PartnerService;

/**
 * Shop self-registration and the shop owner's own small area.
 *
 * Three pages and nothing more: register, sign in, and one screen showing
 * where the registration has got to, the agent token to paste into the
 * desktop software, and today's jobs. A shop owner sees their own shop; the
 * admin panel is somewhere else entirely and they have no account for it.
 */
final class PartnerController extends Controller
{
    public function __construct(
        private PartnerService $partners,
        private PartnerRepository $registrations,
        private AuthService $auth,
        private DeviceRepository $devices,
        private PrinterRepository $printers,
        private PrintJobRepository $jobs
    ) {
    }

    /** GET /register */
    public function showRegister(Request $request): Response
    {
        if ($this->partners->current() !== null) {
            return Response::redirect('/partner');
        }

        return $this->view('partner/register', [
            'title' => 'Register your shop',
            'old' => (array) Session::pull('register_old', []),
            'errors' => (array) Session::pull('register_errors', []),
        ]);
    }

    /** POST /register */
    public function register(Request $request): Response
    {
        $input = $request->all();

        $data = Validator::make($input, [
            'shop_name' => 'required|string|max:150',
            'contact_name' => 'required|string|max:120',
            'email' => 'required|email',
            // Phone and PIN code are patterns, not lengths. "max:32" on a
            // string of digits is read as "no greater than 32" — the rule
            // compares numerically as soon as the value looks numeric — so
            // every real mobile number was rejected as too large.
            'phone' => 'required|string|regex:/^[0-9][0-9 ()+.\-]{6,31}$/',
            'address_line1' => 'nullable|string|max:190',
            'city' => 'nullable|string|max:90',
            'state' => 'nullable|string|max:90',
            'postal_code' => 'nullable|string|regex:/^[A-Za-z0-9 \-]{3,20}$/',
            'password' => 'required|string|max:200',
        ])->validated();

        $remember = [
            'shop_name' => $data['shop_name'],
            'contact_name' => $data['contact_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'address_line1' => $data['address_line1'] ?? '',
            'city' => $data['city'] ?? '',
            'state' => $data['state'] ?? '',
            'postal_code' => $data['postal_code'] ?? '',
        ];

        $confirmation = (string) $request->input('password_confirmation', '');
        if ($confirmation !== (string) $data['password']) {
            return $this->rejectRegistration($remember, ['password' => 'The two passwords do not match.']);
        }

        // The same policy the admin panel applies, told the same way, and with
        // this shop's own details as context so "RameshXerox123" is refused.
        $policy = $this->auth->validatePassword((string) $data['password'], [
            $data['email'], $data['shop_name'], $data['contact_name'],
        ]);
        if (!$policy['valid']) {
            return $this->rejectRegistration($remember, ['password' => implode(' ', $policy['errors'])]);
        }

        if ($this->registrations->emailExists((string) $data['email'])) {
            // Registered already: say so plainly. Hiding it here would only
            // send someone round the form again with the same address, and the
            // address is one they already own.
            return $this->rejectRegistration($remember, [
                'email' => 'That email address is already registered. Sign in instead.',
            ]);
        }

        $this->partners->register($data, $request->ip());

        return $this->redirect(
            '/partner/login',
            'success',
            'Thank you. Your shop is registered and waiting for approval — '
            . 'sign in here to check, and your agent token appears as soon as it is approved.'
        );
    }

    /** GET /partner/login */
    public function showLogin(Request $request): Response
    {
        if ($this->partners->current() !== null) {
            return Response::redirect('/partner');
        }

        return $this->view('partner/login', [
            'title' => 'Shop sign in',
            'email' => (string) Session::pull('partner_login_email', ''),
        ]);
    }

    /** POST /partner/login */
    public function login(Request $request): Response
    {
        $email = $request->string('email');
        $password = (string) $request->input('password', '');

        if ($email === '' || $password === '') {
            Session::put('partner_login_email', $email);
            return $this->redirect('/partner/login', 'error', 'Enter your email address and password.');
        }

        $result = $this->partners->attempt($email, $password, $request->ip());
        if (!$result['success']) {
            Session::put('partner_login_email', $email);
            return $this->redirect('/partner/login', 'error', (string) $result['error']);
        }

        return $this->redirect('/partner', 'success', 'Signed in.');
    }

    /** POST /partner/logout */
    public function logout(Request $request): Response
    {
        $this->partners->logout();
        return $this->redirect('/partner/login', 'success', 'You have been signed out.');
    }

    /** GET /partner */
    public function dashboard(Request $request): Response
    {
        $partner = $this->partners->current();
        if ($partner === null) {
            return Response::redirect('/partner/login');
        }

        $device = null;
        $printers = [];
        $today = ['jobs' => 0, 'pages' => 0];

        if ($partner->isApproved() && $partner->locationId() !== null) {
            $device = $partner->deviceId() === null ? null : $this->devices->find($partner->deviceId());
            $printers = $this->printers->forLocation($partner->locationId(), false);
            $today = $this->jobs->todayTotalsForLocation($partner->locationId());
        }

        return $this->view('partner/dashboard', [
            'title' => $partner->string('shop_name'),
            'partner' => $partner,
            'device' => $device,
            'printers' => $printers,
            'today' => $today,
            // Shown exactly once, on the page load straight after it is issued.
            'newToken' => (string) Session::pull('partner_new_token', ''),
            'serverUrl' => rtrim((string) Config::get('settings.app_url', Config::get('app.url', '')), '/'),
            'staleAfter' => (int) Config::get('printing.queue.health_stale_after', 180),
        ]);
    }

    /** POST /partner/token */
    public function issueToken(Request $request): Response
    {
        $partner = $this->partners->current();
        if ($partner === null) {
            return Response::redirect('/partner/login');
        }

        if (!$partner->isApproved()) {
            throw new HttpException(403, 'Your shop has not been approved yet.');
        }

        $issued = $this->partners->issueToken($partner);
        Session::put('partner_new_token', $issued['token']);

        return $this->redirect(
            '/partner',
            'success',
            'New token issued. Copy it now — it is shown only on this page load, '
            . 'and any token you had before has stopped working.'
        );
    }

    /**
     * @param array<string,mixed> $old
     * @param array<string,string> $errors
     */
    private function rejectRegistration(array $old, array $errors): Response
    {
        Session::put('register_old', $old);
        Session::put('register_errors', $errors);
        return $this->redirect('/register', 'error', reset($errors) ?: 'Please check the form.');
    }
}
