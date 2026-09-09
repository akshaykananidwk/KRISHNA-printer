<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\AuthService;

final class AuthController extends Controller
{
    public function __construct(private AuthService $auth)
    {
    }

    /** GET /admin/login */
    public function showLogin(Request $request): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/admin/dashboard');
        }

        return $this->view('admin/login', [
            'title' => 'Sign in',
            'email' => (string) Session::pull('login_email', ''),
        ]);
    }

    /** POST /admin/login */
    public function login(Request $request): Response
    {
        $email = $request->string('email');
        $password = (string) $request->input('password', '');
        $remember = $request->bool('remember');

        if ($email === '' || $password === '') {
            Session::put('login_email', $email);
            return $this->redirect('/admin/login', 'error', 'Enter your email address and password.');
        }

        $result = $this->auth->attempt($email, $password, $request->ip(), $remember);

        if (!$result['success']) {
            Session::put('login_email', $email);
            return $this->redirect('/admin/login', 'error', (string) $result['error']);
        }

        if ($result['must_change_password'] ?? false) {
            return $this->redirect(
                '/admin/password',
                'warning',
                'Please choose a new password before continuing.'
            );
        }

        $intended = (string) Session::pull('intended_url', '/admin/dashboard');
        // Only ever redirect to an internal admin path.
        if (!str_starts_with($intended, '/admin') || str_starts_with($intended, '//')) {
            $intended = '/admin/dashboard';
        }

        return $this->redirect($intended, 'success', 'Signed in.');
    }

    /** POST /admin/logout */
    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return $this->redirect('/admin/login', 'success', 'You have been signed out.');
    }

    /** GET /admin/password */
    public function showPasswordForm(Request $request): Response
    {
        return $this->view('admin/password', [
            'title' => 'Change password',
            'forced' => (bool) Session::get('must_change_password', false),
        ]);
    }

    /** POST /admin/password */
    public function changePassword(Request $request): Response
    {
        $admin = $request->attribute('admin');
        if ($admin === null) {
            return $this->redirect('/admin/login');
        }

        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('new_password', '');
        $confirm = (string) $request->input('new_password_confirmation', '');

        if ($new !== $confirm) {
            return $this->redirect('/admin/password', 'error', 'The new passwords do not match.');
        }

        $result = $this->auth->changePassword($admin->id(), $current, $new);

        if (!$result['success']) {
            return $this->redirect('/admin/password', 'error', $result['message']);
        }

        return $this->redirect('/admin/dashboard', 'success', $result['message']);
    }
}
