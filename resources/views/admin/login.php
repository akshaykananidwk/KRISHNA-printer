<?php
/** @var App\Core\View $view @var string $email */

use App\Core\Csrf;
use App\Core\View;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in · <?= View::e($appName) ?></title>
<link rel="stylesheet" href="/assets/css/admin.css">
<style nonce="<?= View::e($cspNonce) ?>">
  .l-wrap { min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; }
  .l-card { width: 100%; max-width: 24rem; background: var(--surface); padding: 1.8rem 1.5rem;
            border-radius: var(--radius); box-shadow: var(--shadow); }
  .l-brand { text-align: center; margin-bottom: 1.4rem; }
  .l-brand__mark { font-size: 2rem; display: block; }
  .l-brand__name { font-weight: 700; font-size: 1.15rem; }
  .l-brand__sub { color: var(--ink-faint); font-size: .85rem; }
</style>
</head>
<body class="a-body">
<div class="l-wrap">
  <main class="l-card">
    <div class="l-brand">
      <span class="l-brand__mark" aria-hidden="true">🖨️</span>
      <div class="l-brand__name"><?= View::e($appName) ?></div>
      <div class="l-brand__sub">Management panel</div>
    </div>

    <?php foreach ($flashes as $flash): ?>
      <div class="a-flash a-flash--<?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div>
    <?php endforeach; ?>

    <form method="post" action="/admin/login" autocomplete="on">
      <?= Csrf::field() ?>

      <div class="a-field">
        <label class="a-field__label" for="email">Email address</label>
        <input class="a-input" type="email" id="email" name="email" required autofocus
               autocomplete="username" inputmode="email"
               value="<?= View::e($email) ?>">
      </div>

      <div class="a-field">
        <label class="a-field__label" for="password">Password</label>
        <input class="a-input" type="password" id="password" name="password" required
               autocomplete="current-password">
      </div>

      <label class="a-check">
        <input type="checkbox" name="remember" value="1">
        <span>Keep me signed in on this device</span>
      </label>

      <button type="submit" class="a-btn a-btn--primary" style="width:100%">Sign in</button>
    </form>
  </main>
</div>
</body>
</html>
