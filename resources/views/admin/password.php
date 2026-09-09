<?php
/** @var App\Core\View $view @var bool $forced */

use App\Core\Csrf;
use App\Core\Config;
use App\Core\View;

$view->extend('layouts/admin');
$view->start('content');
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>Change password</h1>
    <p>Choose something you do not use anywhere else.</p>
  </div>
</div>

<?php if ($forced): ?>
  <div class="a-alert a-alert--warning">
    <strong>A new password is required</strong>
    Your account was created with a temporary password. Set your own before continuing.
  </div>
<?php endif; ?>

<div class="a-card" style="max-width:30rem">
  <form method="post" action="/admin/password" autocomplete="on">
    <?= Csrf::field() ?>

    <div class="a-field">
      <label class="a-field__label" for="current_password">Current password</label>
      <input class="a-input" type="password" id="current_password" name="current_password"
             required autocomplete="current-password">
    </div>

    <div class="a-field">
      <label class="a-field__label" for="new_password">New password</label>
      <input class="a-input" type="password" id="new_password" name="new_password"
             required autocomplete="new-password" minlength="<?= (int) Config::get('security.password.min_length', 10) ?>">
      <p class="a-field__hint">
        At least <?= (int) Config::get('security.password.min_length', 10) ?> characters, with letters and
        numbers. Do not reuse your email address, your name, or the business name.
      </p>
    </div>

    <div class="a-field">
      <label class="a-field__label" for="new_password_confirmation">Confirm new password</label>
      <input class="a-input" type="password" id="new_password_confirmation"
             name="new_password_confirmation" required autocomplete="new-password">
    </div>

    <button type="submit" class="a-btn a-btn--primary">Update password</button>
  </form>
</div>

<?php $view->end(); ?>
