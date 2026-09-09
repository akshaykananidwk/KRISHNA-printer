<?php
/** @var App\Core\View $view @var array $admins @var array $roles @var array $permissions @var array|null $temporaryPassword */

use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;

$view->extend('layouts/admin');
$view->start('content');

$currentAdminId = (int) Session::get('admin_id', 0);
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>Team</h1>
    <p>Roles decide what each person can reach. Passwords are never set for someone else — a
      temporary one is generated and must be changed on first sign-in.</p>
  </div>
</div>

<?php if ($temporaryPassword !== null): ?>
  <div class="a-card" style="border:2px solid var(--brand)">
    <h2 class="a-card__title" style="color:var(--brand)">Temporary password — give it to them now</h2>
    <div class="a-alert a-alert--warning">
      <strong>Shown only once</strong>
      Passwords are stored one-way, so this cannot be displayed again. Pass it on in person or
      through a channel you trust — never email it alongside the username.
    </div>
    <dl class="a-kv" style="margin-bottom:.6rem">
      <dt>Name</dt><dd><?= View::e((string) $temporaryPassword['name']) ?></dd>
      <dt>Email</dt><dd class="a-mono"><?= View::e((string) $temporaryPassword['email']) ?></dd>
    </dl>
    <code class="a-token" id="tempPassword"><?= View::e((string) $temporaryPassword['password']) ?></code>
    <button type="button" class="a-btn a-btn--ghost a-btn--sm" data-copy="#tempPassword">Copy</button>
  </div>
<?php endif; ?>

<section class="a-card">
  <h2 class="a-card__title">Add a team member</h2>
  <form method="post" action="/admin/users">
    <?= Csrf::field() ?>
    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="name">Name</label>
        <input class="a-input" id="name" name="name" required maxlength="120">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="email">Email address</label>
        <input class="a-input" id="email" name="email" type="email" required maxlength="190">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="phone">Phone (optional)</label>
        <input class="a-input" id="phone" name="phone" type="tel" maxlength="32">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="role">Role</label>
        <select class="a-select" id="role" name="role" required>
          <?php foreach ($roles as $key => $label): ?>
            <option value="<?= View::e($key) ?>" <?= $key === 'operator' ? 'selected' : '' ?>>
              <?= View::e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <button type="submit" class="a-btn a-btn--primary">Add team member</button>
  </form>
</section>

<section class="a-card">
  <h2 class="a-card__title">Team</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Last sign-in</th><th>State</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($admins as $admin): ?>
          <tr>
            <td data-label="Name">
              <?= View::e($admin->string('name')) ?>
              <?php if ($admin->id() === $currentAdminId): ?>
                <span class="a-badge a-badge--info">You</span>
              <?php endif; ?>
            </td>
            <td data-label="Email" class="a-small a-mono"><?= View::e($admin->string('email')) ?></td>
            <td data-label="Role"><?= View::e($admin->roleLabel()) ?></td>
            <td data-label="Last sign-in" class="a-small a-muted">
              <?= $admin->string('last_login_at') !== ''
                  ? View::e($admin->string('last_login_at')) . ' UTC' : 'Never' ?>
            </td>
            <td data-label="State">
              <?php if (!$admin->isActive()): ?>
                <span class="a-badge a-badge--muted">Disabled</span>
              <?php elseif ($admin->isLocked()): ?>
                <span class="a-badge a-badge--danger">Locked</span>
              <?php elseif ($admin->bool('must_change_password')): ?>
                <span class="a-badge a-badge--warning">Must change password</span>
              <?php else: ?>
                <span class="a-badge a-badge--success">Active</span>
              <?php endif; ?>
            </td>
            <td data-label="">
              <details>
                <summary class="a-btn a-btn--ghost a-btn--sm" style="display:inline-flex">Manage</summary>
                <div style="padding:.7rem 0">
                  <form method="post" action="/admin/users/<?= $admin->id() ?>" style="margin-bottom:.6rem">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="_method" value="PUT">
                    <div class="a-form-grid">
                      <div class="a-field">
                        <label class="a-field__label">Name</label>
                        <input class="a-input" name="name" required maxlength="120"
                               value="<?= View::e($admin->string('name')) ?>">
                      </div>
                      <div class="a-field">
                        <label class="a-field__label">Email</label>
                        <input class="a-input" name="email" type="email" required maxlength="190"
                               value="<?= View::e($admin->string('email')) ?>">
                      </div>
                      <div class="a-field">
                        <label class="a-field__label">Phone</label>
                        <input class="a-input" name="phone" type="tel" maxlength="32"
                               value="<?= View::e($admin->string('phone')) ?>">
                      </div>
                      <div class="a-field">
                        <label class="a-field__label">Role</label>
                        <select class="a-select" name="role">
                          <?php foreach ($roles as $key => $label): ?>
                            <option value="<?= View::e($key) ?>" <?= $admin->role() === $key ? 'selected' : '' ?>>
                              <?= View::e($label) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </div>
                    <label class="a-check">
                      <input type="checkbox" name="is_active" value="1" <?= $admin->isActive() ? 'checked' : '' ?>>
                      <span>Active</span>
                    </label>
                    <button type="submit" class="a-btn a-btn--primary a-btn--sm">Save</button>
                  </form>

                  <div class="a-row">
                    <form class="a-inline-form" method="post" action="/admin/users/<?= $admin->id() ?>/reset-password">
                      <?= Csrf::field() ?>
                      <button type="submit" class="a-btn a-btn--ghost a-btn--sm"
                              data-confirm="Reset this password? A new temporary one is generated and shown once.">
                        Reset password
                      </button>
                    </form>
                    <?php if ($admin->isLocked()): ?>
                      <form class="a-inline-form" method="post" action="/admin/users/<?= $admin->id() ?>/unlock">
                        <?= Csrf::field() ?>
                        <button type="submit" class="a-btn a-btn--ghost a-btn--sm">Unlock</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($admin->id() !== $currentAdminId): ?>
                      <form class="a-inline-form" method="post" action="/admin/users/<?= $admin->id() ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="_method" value="DELETE">
                        <button type="submit" class="a-btn a-btn--danger a-btn--sm"
                                data-confirm="Remove this team member? Their audit history is kept.">
                          Remove
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="a-card">
  <h2 class="a-card__title">What each role can do</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead><tr><th>Role</th><th>Permissions</th></tr></thead>
      <tbody>
        <?php foreach ($roles as $key => $label): ?>
          <tr>
            <td data-label="Role"><strong><?= View::e($label) ?></strong></td>
            <td data-label="Permissions" class="a-small a-muted">
              <?php $granted = $permissions[$key] ?? []; ?>
              <?= in_array('*', $granted, true)
                  ? 'Everything, including system updates and database restores.'
                  : View::e(implode(', ', $granted)) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php $view->end(); ?>
