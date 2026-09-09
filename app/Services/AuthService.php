<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;
use App\Models\Admin;
use App\Repositories\AdminRepository;

/**
 * Admin authentication.
 *
 * Brute force is resisted on two axes at once: a per-IP rate limit (so one
 * attacker cannot spray many accounts) and a per-account lockout (so an
 * attacker rotating IPs still cannot grind one account). Neither alone is
 * sufficient; together they are.
 */
final class AuthService
{
    public function __construct(
        private AdminRepository $admins,
        private RateLimiter $limiter,
        private AuditService $audit
    ) {
    }

    /**
     * @return array{success:bool,admin?:Admin,error?:string,retry_after?:int,must_change_password?:bool}
     */
    public function attempt(string $email, string $password, string $ip, bool $remember = false): array
    {
        $email = mb_strtolower(trim($email));

        $maxAttempts = (int) Config::get('security.login.max_attempts', 5);
        $decay = (int) Config::get('security.login.decay_seconds', 900);

        // Per-IP throttle, keyed on the IP alone so an attacker cannot dodge it
        // by varying the email.
        $ipLimit = $this->limiter->hit('login:ip:' . $ip, $maxAttempts * 3, $decay);
        if (!$ipLimit['allowed']) {
            $this->audit->security('auth.rate_limited', 'Login attempts from this address were rate limited.', [
                'ip' => $ip,
            ]);
            return [
                'success' => false,
                'error' => 'Too many login attempts. Please wait a few minutes and try again.',
                'retry_after' => $ipLimit['retry_after'],
            ];
        }

        // Per-account throttle.
        $accountLimit = $this->limiter->hit('login:account:' . $email, $maxAttempts, $decay);

        $admin = $this->admins->findByEmail($email);

        // Always run a hash comparison, even when the account does not exist,
        // so response timing does not reveal which emails are registered.
        $storedHash = $admin?->string('password_hash')
            ?? '$2y$12$0000000000000000000000000000000000000000000000000000u';
        $passwordMatches = password_verify($password, $storedHash);

        if ($admin === null) {
            $this->audit->security('auth.unknown_account', 'A login was attempted for an unknown account.', [
                'ip' => $ip,
            ]);
            return ['success' => false, 'error' => 'Those details are not correct.'];
        }

        if (!$admin->isActive()) {
            $this->audit->security('auth.inactive_account', 'A login was attempted on a disabled account.', [
                'admin_id' => $admin->id(),
                'ip' => $ip,
            ]);
            return ['success' => false, 'error' => 'This account has been disabled.'];
        }

        if ($admin->isLocked()) {
            $until = strtotime($admin->string('locked_until') . ' UTC');
            $minutes = max(1, (int) ceil(($until - time()) / 60));
            return [
                'success' => false,
                'error' => sprintf('This account is locked for another %d minute%s.', $minutes, $minutes === 1 ? '' : 's'),
                'retry_after' => $until - time(),
            ];
        }

        if (!$accountLimit['allowed']) {
            return [
                'success' => false,
                'error' => 'Too many attempts for this account. Please wait a few minutes.',
                'retry_after' => $accountLimit['retry_after'],
            ];
        }

        if (!$passwordMatches) {
            $this->admins->recordFailedLogin(
                $admin->id(),
                $maxAttempts,
                (int) Config::get('security.login.lockout_seconds', 900)
            );
            $this->audit->security('auth.failed', 'A login attempt failed.', [
                'admin_id' => $admin->id(),
                'ip' => $ip,
                'attempt' => $admin->int('failed_attempts') + 1,
            ]);
            return ['success' => false, 'error' => 'Those details are not correct.'];
        }

        // Success.
        $this->limiter->clear('login:account:' . $email);
        $this->admins->recordSuccessfulLogin($admin->id(), $ip);

        // Transparently upgrade a hash whose cost parameters have since changed.
        if (password_needs_rehash($storedHash, $this->algorithm(), $this->algorithmOptions())) {
            $this->admins->setPassword($admin->id(), $this->hash($password), false);
        }

        $this->startSession($admin, $remember);

        $this->audit->setActor('admin', (string) $admin->id(), $admin->string('name'));
        $this->audit->log(
            'auth.login',
            sprintf('%s signed in.', $admin->string('name')),
            'admin',
            (string) $admin->id()
        );

        return [
            'success' => true,
            'admin' => $admin,
            'must_change_password' => $admin->bool('must_change_password'),
        ];
    }

    private function startSession(Admin $admin, bool $remember): void
    {
        // A fresh session ID on privilege change closes the fixation window.
        Session::regenerate();
        Csrf::rotate();

        Session::put('admin_id', $admin->id());
        Session::put('admin_name', $admin->string('name'));
        Session::put('admin_role', $admin->role());
        Session::put('admin_email', $admin->string('email'));
        Session::put('authenticated_at', time());
        Session::put('must_change_password', $admin->bool('must_change_password'));

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $this->admins->setRememberToken($admin->id(), $token);
            Session::put('remember_token', $token);
        }
    }

    public function logout(): void
    {
        $adminId = Session::get('admin_id');
        $name = (string) Session::get('admin_name', '');

        if ($adminId !== null) {
            $this->admins->setRememberToken((int) $adminId, null);
            $this->audit->log('auth.logout', sprintf('%s signed out.', $name), 'admin', (string) $adminId);
        }

        Session::destroy();
    }

    public function check(): bool
    {
        return Session::get('admin_id') !== null;
    }

    public function user(): ?Admin
    {
        $adminId = Session::get('admin_id');
        if ($adminId === null) {
            return null;
        }

        $admin = $this->admins->find((int) $adminId);

        // A session must not outlive the account it belongs to: an admin
        // disabled mid-session is signed out on their next request.
        if ($admin === null || !$admin->isActive()) {
            Session::destroy();
            return null;
        }

        return $admin;
    }

    public function can(string $permission): bool
    {
        return $this->user()?->can($permission) ?? false;
    }

    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm(), $this->algorithmOptions());
    }

    private function algorithm(): string|int
    {
        return Config::get('security.password.algorithm', PASSWORD_BCRYPT);
    }

    /** @return array<string,int> */
    private function algorithmOptions(): array
    {
        $algorithm = $this->algorithm();
        if (defined('PASSWORD_ARGON2ID') && $algorithm === PASSWORD_ARGON2ID) {
            return (array) Config::get('security.password.argon_options', []);
        }
        return (array) Config::get('security.password.bcrypt_options', ['cost' => 12]);
    }

    /**
     * Password policy. Rejects the short, the obvious, and the guessable-from-
     * context (an admin's own email or the business name).
     *
     * @param array<int,string> $context extra strings the password must not contain
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function validatePassword(string $password, array $context = []): array
    {
        $errors = [];
        $minimum = (int) Config::get('security.password.min_length', 10);

        if (mb_strlen($password) < $minimum) {
            $errors[] = sprintf('Use at least %d characters.', $minimum);
        }
        if (mb_strlen($password) > 200) {
            $errors[] = 'Use no more than 200 characters.';
        }
        if (preg_match('/[A-Za-z]/', $password) !== 1) {
            $errors[] = 'Include at least one letter.';
        }
        if (preg_match('/\d/', $password) !== 1) {
            $errors[] = 'Include at least one number.';
        }

        $lower = mb_strtolower($password);

        foreach ((array) Config::get('security.password.blocklist', []) as $blocked) {
            if ($lower === mb_strtolower((string) $blocked)) {
                $errors[] = 'That password is too common. Please choose another.';
                break;
            }
        }

        foreach ($context as $value) {
            $value = mb_strtolower(trim((string) $value));
            if ($value !== '' && mb_strlen($value) >= 4 && str_contains($lower, $value)) {
                $errors[] = 'Do not include your name, email address or the business name in the password.';
                break;
            }
        }

        // Three or more identical consecutive characters, or a simple run.
        if (preg_match('/(.)\1{3,}/', $password) === 1) {
            $errors[] = 'Avoid repeating the same character four or more times.';
        }
        if (preg_match('/(012345|123456|abcdef|qwerty|password)/i', $password) === 1) {
            $errors[] = 'Avoid sequences like 123456 or qwerty.';
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /** @return array{success:bool,message:string} */
    public function changePassword(int $adminId, string $currentPassword, string $newPassword): array
    {
        $admin = $this->admins->find($adminId);
        if ($admin === null) {
            return ['success' => false, 'message' => 'That account does not exist.'];
        }

        if (!password_verify($currentPassword, $admin->string('password_hash'))) {
            $this->audit->security('auth.password_change_failed', 'A password change failed the current-password check.', [
                'admin_id' => $adminId,
            ]);
            return ['success' => false, 'message' => 'Your current password is not correct.'];
        }

        if (hash_equals($currentPassword, $newPassword)) {
            return ['success' => false, 'message' => 'The new password must be different from the current one.'];
        }

        $validation = $this->validatePassword($newPassword, [
            $admin->string('name'),
            explode('@', $admin->string('email'))[0],
            (string) Config::get('settings.business_name', ''),
        ]);

        if (!$validation['valid']) {
            return ['success' => false, 'message' => implode(' ', $validation['errors'])];
        }

        $this->admins->setPassword($adminId, $this->hash($newPassword), false);
        Session::put('must_change_password', false);

        $this->audit->log(
            'auth.password_changed',
            sprintf('%s changed their password.', $admin->string('name')),
            'admin',
            (string) $adminId,
            'notice'
        );

        return ['success' => true, 'message' => 'Your password has been changed.'];
    }
}
