<?php
/**
 * @var App\Core\View $view
 * @var App\Models\Partner $partner
 * @var array<string,mixed>|null $device
 * @var array<int,App\Models\Printer> $printers
 * @var array{jobs:int,pages:int} $today
 * @var string $newToken
 * @var string $serverUrl
 * @var int $staleAfter
 */

use App\Core\Csrf;
use App\Core\View;

$lastSeen = is_array($device) ? (string) ($device['last_seen_at'] ?? '') : '';
$online = $lastSeen !== '' && (time() - strtotime($lastSeen . ' UTC')) < $staleAfter;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($partner->string('shop_name')) ?> · <?= View::e($appName) ?></title>
<link rel="stylesheet" href="/assets/css/admin.css">
<style nonce="<?= View::e($cspNonce) ?>">
  .p-wrap { max-width: 46rem; margin: 0 auto; padding: 1.5rem 1rem 4rem; }
  .p-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;
            flex-wrap: wrap; margin-bottom: 1.2rem; }
  .p-token { font-family: ui-monospace, Consolas, monospace; font-size: .95rem; word-break: break-all;
             background: var(--surface-2, #f2f5f4); padding: .7rem .8rem; border-radius: .4rem;
             border: 1px solid var(--line, #d9e2de); }
  .p-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .8rem; }
  .p-stat { background: var(--surface); border-radius: var(--radius); padding: .9rem 1rem;
            box-shadow: var(--shadow); }
  .p-stat__n { font-size: 1.6rem; font-weight: 700; }
  .p-stat__l { color: var(--ink-faint); font-size: .82rem; }
  .p-steps { padding-left: 1.1rem; line-height: 1.65; }
  .p-steps li { margin-bottom: .35rem; }
</style>
</head>
<body class="a-body">
<div class="p-wrap">

  <div class="p-head">
    <div>
      <h1 style="margin:0"><?= View::e($partner->string('shop_name')) ?></h1>
      <p class="a-small" style="color:var(--ink-faint);margin:.2rem 0 0">
        <?= View::e($partner->statusLabel()) ?> · <?= View::e($partner->string('email')) ?>
      </p>
    </div>
    <form method="post" action="/partner/logout">
      <?= Csrf::field() ?>
      <button type="submit" class="a-btn">Sign out</button>
    </form>
  </div>

  <?php foreach ($flashes as $flash): ?>
    <div class="a-flash a-flash--<?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div>
  <?php endforeach; ?>

  <div class="a-alert a-alert--<?= $partner->isApproved() ? 'success' : ($partner->status() === 'pending' ? 'info' : 'warning') ?>">
    <strong><?= View::e($partner->statusLabel()) ?></strong>
    <?= View::e($partner->statusExplanation()) ?>
    <?php if ($partner->string('review_note') !== ''): ?>
      <div style="margin-top:.4rem"><?= View::e($partner->string('review_note')) ?></div>
    <?php endif; ?>
  </div>

  <?php if ($newToken !== ''): ?>
    <div class="a-card" style="border:2px solid var(--brand)">
      <h2 class="a-card__title" style="color:var(--brand)">Your agent token — copy it now</h2>
      <div class="a-alert a-alert--warning">
        <strong>Shown only on this page load.</strong>
        We keep only a fingerprint of it, so we cannot show it again. If you lose it, issue
        another one — that is not a problem, but the old one stops working the moment you do.
      </div>
      <p class="p-token"><?= View::e($newToken) ?></p>
    </div>
  <?php endif; ?>

  <?php if ($partner->isApproved()): ?>

    <div class="p-stats" style="margin-bottom:1.2rem">
      <div class="p-stat">
        <div class="p-stat__n"><?= (int) $today['jobs'] ?></div>
        <div class="p-stat__l">Jobs today</div>
      </div>
      <div class="p-stat">
        <div class="p-stat__n"><?= (int) $today['pages'] ?></div>
        <div class="p-stat__l">Pages printed today</div>
      </div>
      <div class="p-stat">
        <div class="p-stat__n" style="color:<?= $online ? 'var(--ok, #1e6f5c)' : 'var(--ink-faint)' ?>">
          <?= $online ? 'Online' : 'Offline' ?>
        </div>
        <div class="p-stat__l">
          <?= $lastSeen === '' ? 'Never checked in' : 'Last seen ' . View::e($lastSeen) . ' UTC' ?>
        </div>
      </div>
    </div>

    <div class="a-card">
      <h2 class="a-card__title">Set up the computer beside your printer</h2>
      <ol class="p-steps">
        <li>Install <strong>Krishna Printer</strong> on that computer and open it.</li>
        <li>Server address: <code><?= View::e($serverUrl !== '' ? $serverUrl : 'https://your-server') ?></code></li>
        <li>Agent token: issue one below and paste it in, then press <strong>Connect</strong>.</li>
        <li>On the <strong>Printers</strong> tab, pick your printer and press <strong>Add this printer</strong>.</li>
        <li>Press <strong>Start printing</strong>. Close the window — it keeps running beside the clock.</li>
      </ol>

      <form method="post" action="/partner/token" style="margin-top:1rem">
        <?= Csrf::field() ?>
        <button type="submit" class="a-btn a-btn--primary">
          <?= $device !== null && $lastSeen !== '' ? 'Issue a replacement token' : 'Get my agent token' ?>
        </button>
        <span class="a-small" style="color:var(--ink-faint);margin-left:.6rem">
          Any token you already have stops working.
        </span>
      </form>
    </div>

    <div class="a-card">
      <h2 class="a-card__title">Your printers</h2>
      <?php if ($printers === []): ?>
        <p class="a-small" style="color:var(--ink-faint)">
          None yet. They appear here as soon as you add one in the software.
        </p>
      <?php else: ?>
        <table class="a-table">
          <thead><tr><th>Printer</th><th>Status</th><th>Colour</th><th>Both sides</th></tr></thead>
          <tbody>
          <?php foreach ($printers as $printer): ?>
            <tr>
              <td><?= View::e($printer->string('name')) ?></td>
              <td><?= View::e(ucfirst($printer->string('status', 'unknown'))) ?></td>
              <td><?= $printer->bool('supports_color') ? 'Yes' : 'Black &amp; white' ?></td>
              <td><?= $printer->bool('supports_duplex') ? 'Yes' : 'One side' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  <?php endif; ?>

</div>
</body>
</html>
