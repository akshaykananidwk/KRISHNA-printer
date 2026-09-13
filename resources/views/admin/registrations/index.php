<?php
/**
 * @var App\Core\View $view
 * @var array<int,array<string,mixed>> $registrations
 * @var string $status
 * @var int $pending
 */

use App\Core\Csrf;
use App\Core\View;

$view->extend('layouts/admin');
$view->start('content');

$labels = [
    'pending' => 'Waiting',
    'approved' => 'Approved',
    'rejected' => 'Declined',
    'suspended' => 'Suspended',
];
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>Shop registrations</h1>
    <p>Shops that signed themselves up at <code>/register</code>. Approving one creates its
       location and its print agent; nothing on the public form does that on its own.</p>
  </div>
</div>

<div class="a-alert a-alert--info a-small">
  <strong>What approval does</strong>
  It creates the location (with a fresh QR token you must print), and a print agent for the
  computer at that shop. It does <em>not</em> issue the agent token — the shop does that itself
  from its own page, so nobody has to read a credential out over the phone. You still add the
  printer's prices, and the shop adds its own printer from the desktop software.
</div>

<div class="a-toolbar">
  <a class="a-btn<?= $status === '' ? ' a-btn--primary' : '' ?>" href="/admin/registrations">All</a>
  <?php foreach ($labels as $key => $label): ?>
    <a class="a-btn<?= $status === $key ? ' a-btn--primary' : '' ?>"
       href="/admin/registrations?status=<?= View::e($key) ?>">
      <?= View::e($label) ?><?= $key === 'pending' && $pending > 0 ? ' (' . $pending . ')' : '' ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($registrations === []): ?>
  <div class="a-card">
    <p class="a-small" style="color:var(--ink-faint)">Nothing here.</p>
  </div>
<?php else: ?>
  <?php foreach ($registrations as $row): ?>
    <div class="a-card">
      <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap">
        <div>
          <h2 class="a-card__title" style="margin-bottom:.2rem">
            <?= View::e((string) $row['shop_name']) ?>
          </h2>
          <p class="a-small" style="color:var(--ink-faint);margin:0">
            <?= View::e((string) $row['contact_name']) ?> ·
            <?= View::e((string) $row['phone']) ?> ·
            <?= View::e((string) $row['email']) ?>
          </p>
          <p class="a-small" style="color:var(--ink-faint);margin:.2rem 0 0">
            <?= View::e(trim(implode(', ', array_filter([
                (string) ($row['address_line1'] ?? ''),
                (string) ($row['city'] ?? ''),
                (string) ($row['state'] ?? ''),
                (string) ($row['postal_code'] ?? ''),
            ])))) ?>
          </p>
          <p class="a-small" style="color:var(--ink-faint);margin:.2rem 0 0">
            Registered <?= View::e((string) $row['created_at']) ?> UTC
            from <?= View::e((string) ($row['registered_ip'] ?? 'unknown')) ?>
          </p>
        </div>
        <div style="text-align:right">
          <span class="a-badge"><?= View::e($labels[(string) $row['status']] ?? (string) $row['status']) ?></span>
          <?php if (!empty($row['location_name'])): ?>
            <p class="a-small" style="margin:.4rem 0 0">
              <a href="/admin/locations/<?= (int) $row['location_id'] ?>">
                <?= View::e((string) $row['location_name']) ?>
              </a>
              <br><code><?= View::e((string) $row['location_code']) ?></code>
            </p>
          <?php endif; ?>
          <?php if (!empty($row['device_name'])): ?>
            <p class="a-small" style="color:var(--ink-faint);margin:.3rem 0 0">
              Agent: <?= View::e((string) $row['device_name']) ?>
              (<?= View::e((string) $row['device_status']) ?>)
            </p>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($row['review_note'])): ?>
        <p class="a-small" style="margin:.7rem 0 0"><strong>Note:</strong>
          <?= View::e((string) $row['review_note']) ?>
          <?php if (!empty($row['reviewer_name'])): ?>
            — <?= View::e((string) $row['reviewer_name']) ?>, <?= View::e((string) $row['reviewed_at']) ?> UTC
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <?php if ((string) $row['status'] === 'pending'): ?>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1rem">
          <form method="post" action="/admin/registrations/<?= (int) $row['id'] ?>/approve">
            <?= Csrf::field() ?>
            <button type="submit" class="a-btn a-btn--primary">Approve and create the shop</button>
          </form>
          <form method="post" action="/admin/registrations/<?= (int) $row['id'] ?>/reject"
                style="display:flex;gap:.4rem;flex:1;min-width:16rem">
            <?= Csrf::field() ?>
            <input class="a-input" type="text" name="reason" maxlength="500"
                   placeholder="Why not? The shop is shown this." required>
            <button type="submit" class="a-btn a-btn--danger">Decline</button>
          </form>
        </div>
      <?php elseif ((string) $row['status'] === 'approved'): ?>
        <form method="post" action="/admin/registrations/<?= (int) $row['id'] ?>/suspend"
              style="display:flex;gap:.4rem;margin-top:1rem;max-width:32rem">
          <?= Csrf::field() ?>
          <input class="a-input" type="text" name="reason" maxlength="500" placeholder="Reason">
          <button type="submit" class="a-btn">Suspend</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php $view->end(); ?>
