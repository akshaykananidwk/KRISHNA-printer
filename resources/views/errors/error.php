<?php
/** @var int $status @var string $message @var Throwable|null $debug */

use App\Core\View;

$titles = [
    400 => 'Bad request',
    401 => 'Sign in required',
    403 => 'Not allowed',
    404 => 'Not found',
    405 => 'Method not allowed',
    413 => 'That file is too large',
    419 => 'Your session expired',
    422 => 'Something is not valid',
    429 => 'Too many requests',
    500 => 'Something went wrong',
    503 => 'Temporarily unavailable',
];
$title = $titles[$status] ?? 'Error';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= View::e($title) ?></title>
<style>
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: #f5f7f6; color: #14261f; margin: 0;
    display: grid; place-items: center; min-height: 100vh; padding: 1.5rem;
  }
  .card {
    background: #fff; border-radius: 14px; padding: 2.2rem 1.6rem;
    max-width: 34rem; width: 100%; text-align: center;
    box-shadow: 0 1px 3px rgba(20,38,31,.08);
  }
  .code { font-size: .8rem; font-weight: 800; letter-spacing: .1em; color: #8a9691; }
  h1 { margin: .35rem 0 .7rem; font-size: 1.4rem; }
  p { color: #5a6b64; line-height: 1.6; margin: 0 0 1.2rem; }
  a { display: inline-block; background: #1e6f5c; color: #fff; text-decoration: none;
      padding: .7rem 1.3rem; border-radius: 9px; font-weight: 600; }
  pre { text-align: left; background: #14261f; color: #9ff5d0; padding: .9rem;
        border-radius: 9px; overflow-x: auto; font-size: .78rem; margin-top: 1.2rem; }
</style>
</head>
<body>
<div class="card">
  <div class="code">Error <?= (int) $status ?></div>
  <h1><?= View::e($title) ?></h1>
  <p><?= View::e($message) ?></p>
  <a href="/">Go back</a>

  <?php if ($debug !== null): ?>
    <pre><?= View::e($debug::class . ': ' . $debug->getMessage()) ?>

<?= View::e($debug->getFile() . ':' . $debug->getLine()) ?>

<?= View::e($debug->getTraceAsString()) ?></pre>
  <?php endif; ?>
</div>
</body>
</html>
