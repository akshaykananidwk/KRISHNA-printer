<?php
/**
 * @var App\Core\View $view
 * @var array<string,mixed> $status
 * @var bool $updateConfigured @var string $repository @var string $branch
 * @var string $currentCommit @var string $currentVersion
 * @var array<int,array<string,mixed>> $updateHistory
 * @var array<int,array<string,mixed>> $backups
 * @var int $backupBytes
 * @var bool $maintenanceMode
 */

use App\Core\Csrf;
use App\Core\View;

$view->extend('layouts/admin');
$view->start('content');

$bytes = static function (int $value): string {
    if ($value < 1024) { return $value . ' B'; }
    $units = ['KB', 'MB', 'GB'];
    $n = $value / 1024;
    $i = 0;
    while ($n >= 1024 && $i < 2) { $n /= 1024; $i++; }
    return round($n, 1) . ' ' . $units[$i];
};
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>System</h1>
    <p>Version <?= View::e($currentVersion) ?><?= $currentCommit !== ''
        ? ' · ' . View::e(substr($currentCommit, 0, 7)) : '' ?> · PHP <?= View::e($status['php_version']) ?></p>
  </div>
  <div class="a-page-head__actions">
    <form class="a-inline-form" method="post" action="/admin/system/maintenance">
      <?= Csrf::field() ?>
      <button type="submit" class="a-btn a-btn--<?= $maintenanceMode ? 'primary' : 'ghost' ?>"
              data-confirm="<?= $maintenanceMode
                  ? 'Turn maintenance mode off and let customers print again?'
                  : 'Turn maintenance mode on? Customers will not be able to start new print jobs.' ?>">
        <?= $maintenanceMode ? 'Leave maintenance mode' : 'Enter maintenance mode' ?>
      </button>
    </form>
  </div>
</div>

<?php if ($maintenanceMode): ?>
  <div class="a-alert a-alert--warning">
    <strong>Maintenance mode is on</strong>
    Customers see a "back shortly" page and cannot start new print jobs. The admin panel stays available.
  </div>
<?php endif; ?>

<section class="a-card">
  <h2 class="a-card__title">Updates from GitHub</h2>

  <div class="a-alert a-alert--info a-small">
    <strong>What "Update now" does</strong>
    Backs up the database and the application, downloads and verifies the target commit, enters
    maintenance mode, swaps the files, runs migrations, clears caches, runs health checks, then
    leaves maintenance mode. If any step fails, the previous files are restored, the database backup
    is restored when migrations had already run, maintenance mode is lifted and the failure is
    logged. Your <code>config/config.php</code>, <code>uploads/</code>, <code>storage/</code>,
    <code>logs/</code> and <code>backups/</code> are never touched by an update.
  </div>

  <form method="post" action="/admin/system/update-config">
    <?= Csrf::field() ?>
    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="repository">GitHub repository</label>
        <input class="a-input a-mono" id="repository" name="repository" required maxlength="190"
               value="<?= View::e($repository) ?>" placeholder="owner/repository">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="branch">Branch</label>
        <input class="a-input a-mono" id="branch" name="branch" maxlength="120"
               value="<?= View::e($branch) ?>" placeholder="main">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="token">Access token</label>
        <input class="a-input" id="token" name="token" type="password" maxlength="255" autocomplete="new-password"
               placeholder="<?= $updateConfigured ? 'Stored — leave blank to keep' : 'ghp_… or a fine-grained token' ?>">
        <p class="a-field__hint">
          Needs read access to the repository's contents. Encrypted at rest and never displayed again.
          Verified against GitHub when you save.
        </p>
      </div>
    </div>
    <button type="submit" class="a-btn a-btn--ghost">Save update settings</button>
  </form>

  <?php if ($updateConfigured): ?>
    <hr style="border:0;border-top:1px solid var(--line);margin:1.2rem 0">

    <div class="a-row">
      <button type="button" class="a-btn a-btn--primary" id="checkUpdateBtn">Check for update</button>
      <button type="button" class="a-btn a-btn--danger" id="runUpdateBtn" hidden>Update now</button>
      <?php if ($lastCheck !== ''): ?>
        <span class="a-small a-muted">Last checked <?= View::e($lastCheck) ?></span>
      <?php endif; ?>
    </div>

    <div class="a-alert" id="updatePanel" style="margin-top:.9rem" hidden></div>
    <ul class="a-steps" id="updateSteps" style="margin-top:.9rem" hidden></ul>
  <?php else: ?>
    <p class="a-small a-muted" style="margin-top:.8rem">
      Save a repository and token above to enable one-click updates.
    </p>
  <?php endif; ?>
</section>

<section class="a-card">
  <h2 class="a-card__title">Backups</h2>
  <p class="a-small a-muted" style="margin-top:-.5rem">
    <?= count($backups) ?> backup(s), <?= View::e($bytes($backupBytes)) ?> on disk.
    Backups live outside the web root. Customer uploads are excluded deliberately — they are
    transient, and copying them would multiply the exposure of customer documents.
  </p>

  <form class="a-inline-form" method="post" action="/admin/backups">
    <?= Csrf::field() ?>
    <input type="hidden" name="type" value="database">
    <button type="submit" class="a-btn a-btn--ghost">Back up the database</button>
  </form>
  <form class="a-inline-form" method="post" action="/admin/backups">
    <?= Csrf::field() ?>
    <input type="hidden" name="type" value="application">
    <button type="submit" class="a-btn a-btn--ghost">Back up the application</button>
  </form>

  <div class="a-table-wrap" style="margin-top:1rem">
    <table class="a-table a-table--stack">
      <thead>
        <tr><th>Backup</th><th>Type</th><th>Trigger</th><th class="a-table__num">Size</th>
          <th>Status</th><th>Created (UTC)</th><th></th></tr>
      </thead>
      <tbody>
        <?php if ($backups === []): ?>
          <tr><td colspan="7" class="a-table__empty">No backups yet.</td></tr>
        <?php else: ?>
          <?php foreach ($backups as $backup): ?>
            <tr>
              <td data-label="Backup" class="a-mono a-small"><?= View::e((string) $backup['filename']) ?></td>
              <td data-label="Type"><?= View::e(ucfirst((string) $backup['backup_type'])) ?></td>
              <td data-label="Trigger" class="a-small">
                <?= View::e(str_replace('_', ' ', (string) $backup['trigger_source'])) ?>
              </td>
              <td data-label="Size" class="a-table__num"><?= View::e($bytes((int) $backup['size_bytes'])) ?></td>
              <td data-label="Status">
                <span class="a-badge a-badge--<?= match ((string) $backup['status']) {
                    'completed' => 'success', 'failed' => 'danger',
                    'restored' => 'info', 'deleted' => 'muted', default => 'warning',
                } ?>"><?= View::e(ucfirst((string) $backup['status'])) ?></span>
              </td>
              <td data-label="Created" class="a-small a-muted"><?= View::e((string) $backup['started_at']) ?></td>
              <td data-label="">
                <?php if ((string) $backup['status'] === 'completed'): ?>
                  <a class="a-btn a-btn--ghost a-btn--sm"
                     href="/admin/backups/<?= (int) $backup['id'] ?>/download">Download</a>

                  <?php if ((string) $backup['backup_type'] === 'database'): ?>
                    <form class="a-inline-form" method="post"
                          action="/admin/backups/<?= (int) $backup['id'] ?>/restore">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="confirm" value="">
                      <button type="submit" class="a-btn a-btn--danger a-btn--sm"
                              data-confirm="This REPLACES the current database with this backup. A safety backup of the current state is taken first."
                              data-confirm-phrase="RESTORE">Restore</button>
                    </form>
                  <?php endif; ?>

                  <form class="a-inline-form" method="post" action="/admin/backups/<?= (int) $backup['id'] ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="a-btn a-btn--ghost a-btn--sm"
                            data-confirm="Delete this backup file permanently?">Delete</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($updateHistory !== []): ?>
<section class="a-card">
  <h2 class="a-card__title">Update history</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr><th>When (UTC)</th><th>From → to</th><th>Status</th><th>Duration</th><th>By</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($updateHistory as $log): ?>
          <tr>
            <td data-label="When" class="a-small a-muted"><?= View::e((string) $log['started_at']) ?></td>
            <td data-label="From → to" class="a-mono a-small">
              <?= View::e(substr((string) ($log['from_commit'] ?? ''), 0, 7) ?: '—') ?>
              → <?= View::e(substr((string) ($log['to_commit'] ?? ''), 0, 7) ?: '—') ?>
            </td>
            <td data-label="Status">
              <span class="a-badge a-badge--<?= match ((string) $log['status']) {
                  'completed' => 'success', 'failed' => 'danger',
                  'rolled_back' => 'warning', default => 'info',
              } ?>"><?= View::e(str_replace('_', ' ', (string) $log['status'])) ?></span>
            </td>
            <td data-label="Duration" class="a-small">
              <?= $log['duration_ms'] !== null ? round(((int) $log['duration_ms']) / 1000, 1) . ' s' : '—' ?>
            </td>
            <td data-label="By" class="a-small"><?= View::e((string) ($log['triggered_by_name'] ?? 'system')) ?></td>
            <td data-label="">
              <a class="a-btn a-btn--ghost a-btn--sm" href="/admin/system/update/<?= (int) $log['id'] ?>">Detail</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="a-card">
  <h2 class="a-card__title">Server health</h2>

  <dl class="a-kv" style="margin-bottom:1rem">
    <dt>Database size</dt><dd><?= View::e($bytes((int) $status['database_size_bytes'])) ?></dd>
    <dt>Disk free</dt>
    <dd><?= $status['disk_free_bytes'] !== null ? View::e($bytes((int) $status['disk_free_bytes'])) : 'unknown' ?></dd>
    <dt>Printers online</dt>
    <dd><?= (int) $status['printers_online'] ?> online, <?= (int) $status['printers_offline'] ?> not</dd>
    <dt>HTTPS enforced</dt><dd><?= $status['https_enforced'] ? 'Yes' : 'No' ?></dd>
    <dt>Payments</dt><dd><?= $status['payment_enabled'] ? 'Enabled' : 'Disabled' ?></dd>
    <dt>Installer</dt>
    <dd>
      <?= $status['install_locked']
          ? '<span class="a-badge a-badge--success">Locked</span>'
          : '<span class="a-badge a-badge--danger">NOT LOCKED — delete or lock /install now</span>' ?>
    </dd>
    <dt>Server time</dt><dd><?= View::e((string) $status['server_time_utc']) ?> UTC</dd>
  </dl>

  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead><tr><th>Check</th><th>Result</th><th>Found</th><th>Expected</th></tr></thead>
      <tbody>
        <?php foreach ($status['requirements']['checks'] as $check): ?>
          <tr>
            <td data-label="Check"><?= View::e((string) $check['name']) ?></td>
            <td data-label="Result">
              <span class="a-badge a-badge--<?= $check['passed'] ? 'success' : ($check['required'] ? 'danger' : 'warning') ?>">
                <?= $check['passed'] ? 'OK' : ($check['required'] ? 'Failed' : 'Advisory') ?>
              </span>
            </td>
            <td data-label="Found" class="a-small a-mono"><?= View::e((string) $check['actual']) ?></td>
            <td data-label="Expected" class="a-small a-muted"><?= View::e((string) $check['expected']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php $view->end(); ?>
