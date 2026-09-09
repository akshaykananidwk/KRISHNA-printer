<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Repositories\AdminRepository;
use App\Repositories\AuditRepository;
use App\Services\AuditService;
use App\Services\AuthService;

final class UserController extends Controller
{
    public function __construct(
        private AdminRepository $admins,
        private AuditRepository $auditRepository,
        private AuthService $auth,
        private AuditService $audit
    ) {
    }

    /** GET /admin/users */
    public function index(Request $request): Response
    {
        return $this->view('admin/users/index', [
            'title' => 'Team',
            'active' => 'users',
            'admins' => $this->admins->all(),
            'roles' => (array) Config::get('security.roles', []),
            'permissions' => (array) Config::get('security.permissions', []),
            'temporaryPassword' => Session::pull('temporary_password'),
        ]);
    }

    /** POST /admin/users */
    public function store(Request $request): Response
    {
        $actor = $request->attribute('admin');
        if ($actor === null || !$actor->isSuperAdmin()) {
            return $this->back($request, 'error', 'Only a super administrator can add team members.');
        }

        $data = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'email' => 'required|email',
            'role' => 'required|in:super_admin,admin,operator,viewer',
            'phone' => 'nullable|string|max:32',
        ])->validated();

        $email = mb_strtolower((string) $data['email']);

        if ($this->admins->emailExists($email)) {
            return $this->back($request, 'error', 'That email address is already in use.');
        }

        // A generated temporary password, shown once, with a forced change on
        // first sign-in. Nobody types a password for someone else.
        $temporary = $this->generateTemporaryPassword();

        $id = $this->admins->create([
            'name' => (string) $data['name'],
            'email' => $email,
            'password_hash' => $this->auth->hash($temporary),
            'role' => (string) $data['role'],
            'phone' => $data['phone'] ?? null,
            'is_active' => 1,
            'must_change_password' => 1,
            'password_changed_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->audit->log(
            'user.created',
            sprintf('Added team member "%s" as %s.', $data['name'], $data['role']),
            'admin',
            (string) $id,
            'critical'
        );

        Session::put('temporary_password', [
            'name' => (string) $data['name'],
            'email' => $email,
            'password' => $temporary,
        ]);

        return $this->redirect(
            '/admin/users',
            'success',
            'Team member added. Give them the temporary password shown below — it is displayed only once, '
            . 'and they must change it when they sign in.'
        );
    }

    /** PUT /admin/users/{id} */
    public function update(Request $request): Response
    {
        $actor = $request->attribute('admin');
        if ($actor === null || !$actor->isSuperAdmin()) {
            return $this->back($request, 'error', 'Only a super administrator can change team members.');
        }

        $id = (int) $request->routeParam('id');
        $admin = $this->admins->find($id);
        if ($admin === null) {
            throw new HttpException(404, 'That team member was not found.');
        }

        $data = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'email' => 'required|email',
            'role' => 'required|in:super_admin,admin,operator,viewer',
            'phone' => 'nullable|string|max:32',
            'is_active' => 'nullable|bool',
        ])->validated();

        $email = mb_strtolower((string) $data['email']);
        if ($this->admins->emailExists($email, $id)) {
            return $this->back($request, 'error', 'That email address is already in use.');
        }

        $isActive = (bool) ($data['is_active'] ?? false);
        $newRole = (string) $data['role'];

        // Never allow the last active super admin to be demoted or disabled —
        // that would lock everyone out of the settings and update modules.
        $losingSuperAdmin = $admin->isSuperAdmin() && ($newRole !== 'super_admin' || !$isActive);
        if ($losingSuperAdmin && $this->admins->countSuperAdmins($id) === 0) {
            return $this->back(
                $request,
                'error',
                'This is the only active super administrator. Promote someone else first.'
            );
        }

        $this->admins->updateById($id, [
            'name' => (string) $data['name'],
            'email' => $email,
            'role' => $newRole,
            'phone' => $data['phone'] ?? null,
            'is_active' => $isActive ? 1 : 0,
        ]);

        $this->audit->log(
            'user.updated',
            sprintf(
                'Updated team member "%s" — role %s, %s.',
                $data['name'],
                $newRole,
                $isActive ? 'active' : 'disabled'
            ),
            'admin',
            (string) $id,
            'critical'
        );

        return $this->back($request, 'success', 'Team member updated.');
    }

    /** POST /admin/users/{id}/reset-password */
    public function resetPassword(Request $request): Response
    {
        $actor = $request->attribute('admin');
        if ($actor === null || !$actor->isSuperAdmin()) {
            return $this->back($request, 'error', 'Only a super administrator can reset passwords.');
        }

        $id = (int) $request->routeParam('id');
        $admin = $this->admins->find($id);
        if ($admin === null) {
            throw new HttpException(404, 'That team member was not found.');
        }

        $temporary = $this->generateTemporaryPassword();
        $this->admins->setPassword($id, $this->auth->hash($temporary), true);

        // Clear any lockout so they can actually use the new password.
        $this->admins->updateById($id, ['failed_attempts' => 0, 'locked_until' => null]);

        $this->audit->log(
            'user.password_reset',
            sprintf('Reset the password for "%s".', $admin->string('name')),
            'admin',
            (string) $id,
            'critical'
        );

        Session::put('temporary_password', [
            'name' => $admin->string('name'),
            'email' => $admin->string('email'),
            'password' => $temporary,
        ]);

        return $this->redirect(
            '/admin/users',
            'success',
            'Password reset. The temporary password is shown below — it is displayed only once.'
        );
    }

    /** POST /admin/users/{id}/unlock */
    public function unlock(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $admin = $this->admins->find($id);
        if ($admin === null) {
            throw new HttpException(404, 'That team member was not found.');
        }

        $this->admins->updateById($id, ['failed_attempts' => 0, 'locked_until' => null]);

        $this->audit->log(
            'user.unlocked',
            sprintf('Unlocked the account for "%s".', $admin->string('name')),
            'admin',
            (string) $id,
            'notice'
        );

        return $this->back($request, 'success', 'Account unlocked.');
    }

    /** DELETE /admin/users/{id} */
    public function destroy(Request $request): Response
    {
        $actor = $request->attribute('admin');
        if ($actor === null || !$actor->isSuperAdmin()) {
            return $this->back($request, 'error', 'Only a super administrator can remove team members.');
        }

        $id = (int) $request->routeParam('id');

        if ($id === $actor->id()) {
            return $this->back($request, 'error', 'You cannot remove your own account.');
        }

        $admin = $this->admins->find($id);
        if ($admin === null) {
            throw new HttpException(404, 'That team member was not found.');
        }

        if ($admin->isSuperAdmin() && $this->admins->countSuperAdmins($id) === 0) {
            return $this->back($request, 'error', 'This is the only super administrator and cannot be removed.');
        }

        // Audit rows reference admins with ON DELETE SET NULL, so history
        // survives the account being removed.
        $this->admins->deleteById($id);

        $this->audit->log(
            'user.deleted',
            sprintf('Removed team member "%s".', $admin->string('name')),
            'admin',
            (string) $id,
            'critical'
        );

        return $this->redirect('/admin/users', 'success', 'Team member removed.');
    }

    /** GET /admin/audit */
    public function audit(Request $request): Response
    {
        $filters = [];
        foreach (['action', 'actor_type', 'severity', 'subject_type', 'search'] as $key) {
            $value = $request->string($key);
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }
        foreach (['date_from', 'date_to'] as $key) {
            $value = $request->string($key);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $filters[$key] = $value;
            }
        }

        return $this->view('admin/audit/index', [
            'title' => 'Audit log',
            'active' => 'audit',
            'result' => $this->auditRepository->paginate(max(1, $request->int('page', 1)), 50, $filters),
            'filters' => $filters,
            'actions' => $this->auditRepository->distinctActions(),
        ]);
    }

    /**
     * A readable but strong temporary password. Word-plus-digits beats random
     * characters here because it has to survive being read aloud or written on
     * a note, and it is single-use with a forced change on first sign-in.
     */
    private function generateTemporaryPassword(): string
    {
        $words = [
            'Harbour', 'Lantern', 'Marigold', 'Compass', 'Thicket', 'Copper',
            'Kestrel', 'Juniper', 'Falcon', 'Willow', 'Cobalt', 'Meadow',
            'Cedar', 'Quartz', 'Ember', 'Basalt', 'Saffron', 'Indigo',
        ];

        return $words[random_int(0, count($words) - 1)]
            . '-'
            . $words[random_int(0, count($words) - 1)]
            . '-'
            . random_int(1000, 9999);
    }
}
