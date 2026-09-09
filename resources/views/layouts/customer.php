<?php
/**
 * Mobile-first customer layout.
 *
 * @var App\Core\View $view
 * @var string $title
 * @var string $appName
 * @var string $cspNonce
 * @var array<int,array{type:string,message:string}> $flashes
 */

use App\Core\Config;
use App\Core\Csrf;
use App\Core\View;

$brandColor = (string) Config::get('settings.brand_color', '#1e6f5c');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= View::e($brandColor) ?>">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= View::e(Csrf::token()) ?>">
<meta name="description" content="Scan, upload and print from your phone at <?= View::e($appName) ?>.">
<title><?= View::e($title ?? 'Print') ?> · <?= View::e($appName) ?></title>
<link rel="stylesheet" href="/assets/css/customer.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🖨️</text></svg>">
<style nonce="<?= View::e($cspNonce) ?>">:root{--brand:<?= View::e($brandColor) ?>}</style>
</head>
<body class="c-body">

<header class="c-header">
  <div class="c-header__inner">
    <span class="c-header__mark" aria-hidden="true">🖨️</span>
    <span class="c-header__name"><?= View::e($appName) ?></span>
  </div>
</header>

<main class="c-main" id="main">
  <?php if (!empty($flashes)): ?>
    <div class="c-flashes" role="status">
      <?php foreach ($flashes as $flash): ?>
        <div class="c-flash c-flash--<?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?= $view->section('content') ?>
</main>

<footer class="c-footer">
  <?php
  $supportPhone = (string) Config::get('settings.support_phone', '');
  if ($supportPhone !== ''):
      ?>
    <p class="c-footer__help">Need help? Ask staff or call
      <a href="tel:<?= View::e(preg_replace('/[^0-9+]/', '', $supportPhone) ?? '') ?>"><?= View::e($supportPhone) ?></a>
    </p>
  <?php endif; ?>
  <p class="c-footer__meta"><?= View::e($appName) ?></p>
</footer>

<?= $view->section('scripts') ?>
</body>
</html>
