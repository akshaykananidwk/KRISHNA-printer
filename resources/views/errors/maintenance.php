<?php
/** @var string $since @var string $businessName */

use App\Core\View;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="refresh" content="60">
<title>Back shortly · <?= View::e($businessName) ?></title>
<style>
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: #f5f7f6; color: #14261f; margin: 0;
    display: grid; place-items: center; min-height: 100vh; padding: 1.5rem;
  }
  .card {
    background: #fff; border-radius: 14px; padding: 2.4rem 1.6rem;
    max-width: 30rem; width: 100%; text-align: center;
    box-shadow: 0 1px 3px rgba(20,38,31,.08);
  }
  .mark { font-size: 2.4rem; }
  h1 { margin: .5rem 0 .7rem; font-size: 1.35rem; }
  p { color: #5a6b64; line-height: 1.6; margin: 0 0 .8rem; }
  .meta { font-size: .8rem; color: #8a9691; }
</style>
</head>
<body>
<div class="card">
  <div class="mark" aria-hidden="true">🖨️</div>
  <h1>Back in a few minutes</h1>
  <p>
    We are applying a quick update. Printing will be available again shortly — this page refreshes
    itself, so you can leave it open.
  </p>
  <p class="meta">
    <?= View::e($businessName) ?>
    <?php if ($since !== ''): ?><br>Started <?= View::e($since) ?><?php endif; ?>
  </p>
</div>
</body>
</html>
