<?php
/**
 * @var App\Core\View $view
 * @var array<string,mixed> $old
 * @var array<string,string> $errors
 */

use App\Core\Csrf;
use App\Core\View;

$value = static fn (string $key): string => View::e((string) ($old[$key] ?? ''));
$error = static fn (string $key): string => (string) ($errors[$key] ?? '');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Register your shop · <?= View::e($appName) ?></title>
<link rel="stylesheet" href="/assets/css/admin.css">
<style nonce="<?= View::e($cspNonce) ?>">
  .p-wrap { min-height: 100vh; display: grid; place-items: start center; padding: 2rem 1rem 4rem; }
  .p-card { width: 100%; max-width: 38rem; background: var(--surface); padding: 1.8rem 1.5rem;
            border-radius: var(--radius); box-shadow: var(--shadow); }
  .p-brand { text-align: center; margin-bottom: 1.2rem; }
  .p-brand__mark { font-size: 2rem; display: block; }
  .p-brand__name { font-weight: 700; font-size: 1.15rem; }
  .p-grid { display: grid; gap: 0 1rem; grid-template-columns: 1fr 1fr; }
  @media (max-width: 33rem) { .p-grid { grid-template-columns: 1fr; } }
  .p-err { color: var(--danger, #b3261e); font-size: .82rem; margin-top: .25rem; display: block; }
  .p-foot { text-align: center; margin-top: 1.2rem; font-size: .9rem; color: var(--ink-faint); }
</style>
</head>
<body class="a-body">
<div class="p-wrap">
  <main class="p-card">
    <div class="p-brand">
      <span class="p-brand__mark" aria-hidden="true">🖨️</span>
      <div class="p-brand__name"><?= View::e($appName) ?></div>
    </div>

    <h1 class="a-card__title">Register your shop</h1>
    <p class="a-small" style="color:var(--ink-faint)">
      Fill this in once. We check the details and switch your shop on, and you then get an agent
      token to paste into the Krishna Printer software on the computer beside your printer.
      Nothing is opened on your network and no port is forwarded — the software only dials out.
    </p>

    <?php foreach ($flashes as $flash): ?>
      <div class="a-flash a-flash--<?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div>
    <?php endforeach; ?>

    <form method="post" action="/register" autocomplete="on">
      <?= Csrf::field() ?>

      <div class="a-field">
        <label class="a-field__label" for="shop_name">Shop name</label>
        <input class="a-input" type="text" id="shop_name" name="shop_name" required autofocus
               maxlength="150" value="<?= $value('shop_name') ?>">
        <?php if ($error('shop_name') !== ''): ?><span class="p-err"><?= View::e($error('shop_name')) ?></span><?php endif; ?>
      </div>

      <div class="p-grid">
        <div class="a-field">
          <label class="a-field__label" for="contact_name">Your name</label>
          <input class="a-input" type="text" id="contact_name" name="contact_name" required
                 maxlength="120" autocomplete="name" value="<?= $value('contact_name') ?>">
        </div>
        <div class="a-field">
          <label class="a-field__label" for="phone">Mobile number</label>
          <input class="a-input" type="tel" id="phone" name="phone" required maxlength="32"
                 autocomplete="tel" inputmode="tel" value="<?= $value('phone') ?>">
        </div>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="email">Email address</label>
        <input class="a-input" type="email" id="email" name="email" required maxlength="190"
               autocomplete="username" inputmode="email" value="<?= $value('email') ?>">
        <?php if ($error('email') !== ''): ?><span class="p-err"><?= View::e($error('email')) ?></span><?php endif; ?>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="address_line1">Shop address</label>
        <input class="a-input" type="text" id="address_line1" name="address_line1" maxlength="190"
               autocomplete="street-address" value="<?= $value('address_line1') ?>">
      </div>

      <div class="p-grid">
        <div class="a-field">
          <label class="a-field__label" for="city">City</label>
          <input class="a-input" type="text" id="city" name="city" maxlength="90"
                 value="<?= $value('city') ?>">
        </div>
        <div class="a-field">
          <label class="a-field__label" for="postal_code">PIN code</label>
          <input class="a-input" type="text" id="postal_code" name="postal_code" maxlength="20"
                 inputmode="numeric" value="<?= $value('postal_code') ?>">
        </div>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="state">State</label>
        <input class="a-input" type="text" id="state" name="state" maxlength="90"
               value="<?= $value('state') ?>">
      </div>

      <div class="p-grid">
        <div class="a-field">
          <label class="a-field__label" for="password">Choose a password</label>
          <input class="a-input" type="password" id="password" name="password" required
                 autocomplete="new-password">
        </div>
        <div class="a-field">
          <label class="a-field__label" for="password_confirmation">Type it again</label>
          <input class="a-input" type="password" id="password_confirmation"
                 name="password_confirmation" required autocomplete="new-password">
        </div>
      </div>
      <?php if ($error('password') !== ''): ?><span class="p-err"><?= View::e($error('password')) ?></span><?php endif; ?>

      <button type="submit" class="a-btn a-btn--primary" style="width:100%;margin-top:1rem">
        Register my shop
      </button>
    </form>

    <p class="p-foot">
      Already registered? <a href="/partner/login">Sign in</a>
    </p>
  </main>
</div>
</body>
</html>
