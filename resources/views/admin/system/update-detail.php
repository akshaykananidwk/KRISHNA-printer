<?php
/** @var App\Core\View $view @var array $log @var array $steps @var array $changedFiles @var array $migrations */

use App\Core\View;

$view->extend('layouts/admin');
$view->start('content');
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>Update #<?= (int) $log['id'] ?></h1>
    <p><?= View::e((string) $log['started_at']) ?> UTC · <?= View::e((string) $log['repository']) ?>
      (<?= View::e((string) $log['branch']) ?>)</p>
  </div>
  <div class="a-page-head__actions">
    <a class="a-btn a-btn--ghost" href="/admin/system">Back to system</a>
  </div>
</div>

<div class="a-alert a-alert--<?= match ((string) $log['status']) {
    'completed' => 'success', 'failed' => 'danger', 'rolled_back' => 'warning', default => 'info',
} ?>">
  <strong><?= View::e(ucwords(str_replace('_', ' ', (string) $log['status']))) ?></strong>
  <?php if ($log['error_message'] !== null): ?>
    <?= View::e((string) $log['error_message']) ?>
  <?php elseif ((string) $log['status'] === 'completed'): ?>
    The update was applied and the health checks passed.
  <?php endif; ?>
  <?php if ((int) $log['rollback_performed'] === 1): ?>
    <br>The previous version was restored automatically.
    <?php if ($log['rollback_reason'] !== null): ?>
      Reason: <?= View::e((string) $log['rollback_reason']) ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="a-charts a-charts--2">
  <section class="a-card">
    <h2 class="a-card__title">Commit</h2>
    <dl class="a-kv">
      <dt>From</dt><dd class="a-mono"><?= View::e(substr((string) ($log['from_commit'] ?? ''), 0, 12) ?: '—') ?></dd>
      <dt>To</dt><dd class="a-mono"><?= View::e(substr((string) ($log['to_commit'] ?? ''), 0, 12) ?: '—') ?></dd>
      <dt>Version</dt>
      <dd><?= View::e((string) ($log['from_version'] ?? '?')) ?> → <?= View::e((string) ($log['to_version'] ?? '?')) ?></dd>
      <dt>Author</dt><dd><?= View::e((string) ($log['commit_author'] ?? '—')) ?></dd>
      <dt>Commit date</dt><dd><?= View::e((string) ($log['commit_date'] ?? '—')) ?></dd>
      <dt>Duration</dt>
      <dd><?= $log['duration_ms'] !== null ? round(((int) $log['duration_ms']) / 1000, 1) . ' seconds' : '—' ?></dd>
    </dl>

    <?php if ($log['commit_message'] !== null): ?>
      <h3 class="a-card__title a-card__title--plain" style="margin-top:1rem">Commit message</h3>
      <pre class="a-small" style="white-space:pre-wrap;margin:0"><?= View::e((string) $log['commit_message']) ?></pre>
    <?php endif; ?>
  </section>

  <section class="a-card">
    <h2 class="a-card__title">Migrations run</h2>
    <?php if ($migrations === []): ?>
      <p class="a-muted a-small">No migrations were applied by this update.</p>
    <?php else: ?>
      <ul class="a-small a-mono" style="margin:0;padding-left:1.1rem">
        <?php foreach ($migrations as $migration): ?>
          <li><?= View::e((string) $migration) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($log['database_backup_id'] !== null): ?>
      <p class="a-small a-muted" style="margin-top:.8rem">
        Database backup #<?= (int) $log['database_backup_id'] ?> was taken before this update.
      </p>
    <?php endif; ?>
  </section>
</div>

<section class="a-card">
  <h2 class="a-card__title">Step trace</h2>
  <?php if ($steps === []): ?>
    <p class="a-muted a-small">No steps were recorded.</p>
  <?php else: ?>
    <ul class="a-steps">
      <?php foreach ($steps as $step):
          $s = (string) ($step['status'] ?? 'running'); ?>
        <li class="a-step-row">
          <span class="a-step-row__icon a-step-row__icon--<?= $s === 'ok' ? 'ok' : ($s === 'failed' ? 'failed' : 'running') ?>">
            <?= $s === 'ok' ? '✓' : ($s === 'failed' ? '!' : '•') ?>
          </span>
          <span class="a-step-row__body">
            <span class="a-step-row__label"><?= View::e((string) ($step['label'] ?? $step['key'] ?? '')) ?></span>
            <span class="a-step-row__msg"><?= View::e((string) ($step['message'] ?? '')) ?></span>
          </span>
          <span class="a-step-row__time">
            <?= isset($step['duration_ms']) ? (int) $step['duration_ms'] . ' ms' : '' ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php if ($changedFiles !== []): ?>
<section class="a-card">
  <h2 class="a-card__title">Changed files (<?= count($changedFiles) ?>)</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead><tr><th>File</th><th>Change</th><th class="a-table__num">+</th><th class="a-table__num">−</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($changedFiles, 0, 300) as $file): ?>
          <tr>
            <td data-label="File" class="a-mono a-small"><?= View::e((string) ($file['filename'] ?? '')) ?></td>
            <td data-label="Change">
              <span class="a-badge a-badge--<?= match ((string) ($file['status'] ?? '')) {
                  'added' => 'success', 'removed' => 'danger', 'renamed' => 'info', default => 'muted',
              } ?>"><?= View::e((string) ($file['status'] ?? '')) ?></span>
            </td>
            <td data-label="Added" class="a-table__num"><?= (int) ($file['additions'] ?? 0) ?></td>
            <td data-label="Removed" class="a-table__num"><?= (int) ($file['deletions'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php $view->end(); ?>
