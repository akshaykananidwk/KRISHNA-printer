<?php
/**
 * The printer detail screen. Its most important job is making the capability
 * evidence visible: which options are verified, how, and which are only claimed
 * by a datasheet.
 *
 * @var App\Core\View $view
 * @var App\Models\Printer $printer
 * @var App\Models\Location|null $location
 * @var array<int,array<string,mixed>> $connections
 * @var array<string,array<int,array<string,mixed>>> $capabilityMatrix
 * @var array<string,mixed> $customerOptions
 * @var array<string,mixed> $profile
 * @var array<int,array<string,mixed>> $statusHistory
 * @var array<int,array<string,mixed>> $queue
 * @var array<int,array<string,mixed>> $errorLog
 * @var array<string,mixed> $paperSizes
 */

use App\Core\Csrf;
use App\Core\View;
use App\Models\PrintJob;

$view->extend('layouts/admin');
$view->start('content');

$capabilityLabels = [
    'paper_size' => 'Paper sizes',
    'color_mode' => 'Colour modes',
    'duplex' => 'Sides',
    'orientation' => 'Orientation',
];
$valueLabels = [
    'bw' => 'Black & white', 'color' => 'Colour',
    'single' => 'Single side', 'double' => 'Double side',
    'portrait' => 'Portrait', 'landscape' => 'Landscape',
];
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1><?= View::e($printer->string('name')) ?></h1>
    <p>
      <?= View::e($printer->string('model') ?: 'Unknown model') ?>
      <?php if ($location !== null): ?> · <a href="/admin/locations/<?= $location->id() ?>"><?= View::e($location->string('name')) ?></a><?php endif; ?>
      · <span class="a-mono"><?= View::e($printer->string('code')) ?></span>
    </p>
  </div>
  <div class="a-page-head__actions">
    <button type="button" class="a-btn a-btn--primary" id="testPrinterBtn" data-printer="<?= $printer->id() ?>">
      Test connection
    </button>
    <button type="button" class="a-btn a-btn--ghost" id="probeCapabilitiesBtn" data-printer="<?= $printer->id() ?>">
      Probe capabilities
    </button>
    <a class="a-btn a-btn--ghost" href="/admin/printers/<?= $printer->id() ?>/edit">Edit</a>
  </div>
</div>

<div class="a-alert" id="printerActionResult" hidden></div>

<?php if (!$customerOptions['verified']): ?>
  <div class="a-alert a-alert--warning">
    <strong>This printer is not yet offered to customers</strong>
    No print option has been verified on it. Run a capability probe, or record a test print below.
    Until then the QR page shows "Printing Service Currently Unavailable" — deliberately, so nobody
    is charged for an option this printer may not support.
  </div>
<?php endif; ?>

<?php if (!empty($profile['requires_rasterisation'])): ?>
  <div class="a-alert a-alert--info">
    <strong>This model needs a rendering host</strong>
    <?= View::e((string) ($profile['label'] ?? 'This printer')) ?> has no PCL or PostScript interpreter,
    so a PDF sent straight to port 9100 would print as garbage. Jobs must go through a location print
    agent, which rasterises with the printer driver. The RAW and LPD transports refuse such jobs
    rather than waste paper.
  </div>
<?php endif; ?>

<div class="a-charts a-charts--2">
  <section class="a-card">
    <h2 class="a-card__title">Status</h2>
    <dl class="a-kv">
      <dt>State</dt>
      <dd>
        <span class="a-badge a-badge--<?= View::e($printer->statusTone()) ?>"><?= View::e($printer->statusLabel()) ?></span>
        <?= $printer->bool('is_enabled') ? '' : '<span class="a-badge a-badge--muted">Disabled</span>' ?>
      </dd>
      <dt>Last seen</dt>
      <dd><?= $printer->string('last_seen_at') !== '' ? View::e($printer->string('last_seen_at')) . ' UTC' : 'Never' ?></dd>
      <dt>Last successful print</dt>
      <dd><?= $printer->string('last_success_at') !== '' ? View::e($printer->string('last_success_at')) . ' UTC' : 'None yet' ?></dd>
      <dt>Last error</dt>
      <dd><?= $printer->string('last_error') !== '' ? View::e($printer->string('last_error')) : '—' ?></dd>
      <dt>Consecutive failures</dt>
      <dd><?= $printer->int('consecutive_failures') ?></dd>
      <dt>Queue depth</dt>
      <dd><?= count($queue) ?> of <?= $printer->int('max_queue_depth') ?> max</dd>
      <dt>Capabilities</dt>
      <dd>
        <?php if ($printer->capabilitiesVerified()): ?>
          <span class="a-badge a-badge--success">Verified <?= View::e($printer->string('capabilities_verified_at')) ?> UTC</span>
        <?php else: ?>
          <span class="a-badge a-badge--warning">Never verified</span>
        <?php endif; ?>
      </dd>
    </dl>

    <div class="a-row" style="margin-top:1rem">
      <form class="a-inline-form" method="post" action="/admin/printers/<?= $printer->id() ?>/toggle">
        <?= Csrf::field() ?>
        <button type="submit" class="a-btn a-btn--ghost a-btn--sm">
          <?= $printer->bool('is_enabled') ? 'Disable' : 'Enable' ?>
        </button>
      </form>
      <form class="a-inline-form" method="post" action="/admin/printers/<?= $printer->id() ?>/maintenance">
        <?= Csrf::field() ?>
        <button type="submit" class="a-btn a-btn--ghost a-btn--sm">
          <?= $printer->status() === 'maintenance' ? 'Leave maintenance' : 'Put into maintenance' ?>
        </button>
      </form>
      <?php if (!$printer->bool('is_default')): ?>
        <form class="a-inline-form" method="post" action="/admin/printers/<?= $printer->id() ?>/default">
          <?= Csrf::field() ?>
          <button type="submit" class="a-btn a-btn--ghost a-btn--sm">Make default here</button>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <section class="a-card">
    <h2 class="a-card__title">Connections</h2>
    <?php foreach ($connections as $connection): ?>
      <div style="padding:.6rem 0;border-bottom:1px solid var(--line)">
        <div class="a-row">
          <strong><?= View::e(strtoupper((string) $connection['driver'])) ?></strong>
          <span class="a-badge a-badge--<?= (string) $connection['last_test_result'] === 'ok' ? 'success'
              : ((string) $connection['last_test_result'] === 'failed' ? 'danger' : 'muted') ?>">
            <?= View::e(ucfirst((string) $connection['last_test_result'])) ?>
          </span>
          <?= (int) $connection['is_enabled'] === 1 ? '' : '<span class="a-badge a-badge--muted">Disabled</span>' ?>
        </div>
        <p class="a-small a-muted" style="margin:.25rem 0 0">
          <?php if ($connection['host'] !== null): ?>
            <?= View::e((string) $connection['host']) ?>:<?= (int) $connection['port'] ?>
            <?= (int) $connection['use_tls'] === 1 ? ' · TLS' : '' ?>
            <?= (int) $connection['use_tls'] === 1 && (int) $connection['verify_tls'] === 0
                ? ' <span class="a-badge a-badge--warning">certificate not verified</span>' : '' ?>
          <?php elseif ((string) $connection['driver'] === 'agent'): ?>
            via the location print agent<?= $printer->string('queue_name') !== ''
                ? ' → queue ' . View::e($printer->string('queue_name')) : '' ?>
          <?php endif; ?>
        </p>
        <?php if ($connection['last_test_message'] !== null): ?>
          <p class="a-small" style="margin:.25rem 0 0"><?= View::e((string) $connection['last_test_message']) ?></p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($connections === []): ?>
      <p class="a-muted a-small">No connection configured. <a href="/admin/printers/<?= $printer->id() ?>/edit">Add one</a>.</p>
    <?php endif; ?>
  </section>
</div>

<section class="a-card">
  <h2 class="a-card__title">Capability matrix</h2>

  <div class="a-alert a-alert--info a-small">
    <strong>Only verified capabilities are offered to customers</strong>
    <span class="a-source a-source--probed">probed</span> means the printer reported it itself.
    <span class="a-source a-source--manual">manual</span> means an operator ran a test print and
    confirmed it. <span class="a-source a-source--declared">declared</span> comes from the
    manufacturer's datasheet and is <em>never</em> shown to a customer on its own — a spec sheet is
    a claim, not evidence that this unit, with this cartridge and tray loaded, can do it.
  </div>

  <?php foreach ($capabilityMatrix as $capability => $rows): ?>
    <h3 class="a-card__title a-card__title--plain" style="margin-top:1rem">
      <?= View::e($capabilityLabels[$capability] ?? $capability) ?>
    </h3>

    <div class="a-table-wrap">
      <table class="a-table a-table--stack">
        <thead>
          <tr><th>Value</th><th>Supported</th><th>Source</th><th>Verified</th><th>Evidence</th><th>Confirm by hand</th></tr>
        </thead>
        <tbody>
          <?php if ($rows === []): ?>
            <tr><td colspan="6" class="a-table__empty">Nothing recorded for this capability.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $row):
                $value = (string) $row['value'];
                $isSupported = (int) $row['is_supported'] === 1;
                $source = (string) $row['source'];
                $isVerifiedSource = in_array($source, ['probed', 'manual'], true); ?>
              <tr>
                <td data-label="Value">
                  <strong><?= View::e($valueLabels[$value] ?? $value) ?></strong>
                  <?= (int) $row['is_default'] === 1 ? ' <span class="a-badge a-badge--info">Default</span>' : '' ?>
                </td>
                <td data-label="Supported">
                  <span class="a-badge a-badge--<?= $isSupported ? 'success' : 'danger' ?>">
                    <?= $isSupported ? 'Yes' : 'No' ?>
                  </span>
                </td>
                <td data-label="Source">
                  <span class="a-source a-source--<?= View::e($source) ?>"><?= View::e($source) ?></span>
                </td>
                <td data-label="Verified">
                  <?php if ($isVerifiedSource && $isSupported): ?>
                    <span class="a-badge a-badge--success">Offered to customers</span>
                  <?php else: ?>
                    <span class="a-badge a-badge--muted">Not offered</span>
                  <?php endif; ?>
                </td>
                <td data-label="Evidence" class="a-small a-muted"><?= View::e((string) ($row['evidence'] ?? '—')) ?></td>
                <td data-label="Confirm">
                  <form class="a-inline-form" method="post"
                        action="/admin/printers/<?= $printer->id() ?>/verify-capability">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="capability" value="<?= View::e($capability) ?>">
                    <input type="hidden" name="value" value="<?= View::e($value) ?>">
                    <input type="hidden" name="note" value="Confirmed by test print from the printer screen.">
                    <button type="submit" name="supported" value="1" class="a-btn a-btn--ghost a-btn--sm"
                            data-confirm="Record that you have printed a test page with this option and it worked?">
                      Works
                    </button>
                    <button type="submit" name="supported" value="0" class="a-btn a-btn--ghost a-btn--sm"
                            data-confirm="Record that this option does NOT work on this printer?">
                      Doesn't
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>

  <?php if (!empty($profile['notes'])): ?>
    <div class="a-alert a-alert--info a-small" style="margin-top:1rem">
      <strong>Model notes — <?= View::e((string) ($profile['label'] ?? '')) ?></strong>
      <?= View::e((string) $profile['notes']) ?>
      <?php if (!empty($profile['source'])): ?>
        <br><em>Source: <?= View::e((string) $profile['source']) ?></em>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<div class="a-charts a-charts--2">
  <section class="a-card">
    <h2 class="a-card__title">Current queue</h2>
    <?php if ($queue === []): ?>
      <p class="a-muted a-small">Nothing queued.</p>
    <?php else: ?>
      <div class="a-table-wrap">
        <table class="a-table a-table--stack">
          <thead><tr><th>Job</th><th>Status</th><th class="a-table__num">Attempts</th></tr></thead>
          <tbody>
            <?php foreach ($queue as $row):
                $job = PrintJob::fromRow($row); ?>
              <tr>
                <td data-label="Job"><a class="a-mono" href="/admin/jobs/<?= View::e($job->jobNumber()) ?>">
                  <?= View::e($job->jobNumber()) ?></a></td>
                <td data-label="Status">
                  <span class="a-badge a-badge--<?= View::e($job->statusTone()) ?>"><?= View::e($job->statusLabel()) ?></span>
                </td>
                <td data-label="Attempts" class="a-table__num"><?= $job->int('attempts') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="a-card">
    <h2 class="a-card__title">Recent health checks</h2>
    <?php if ($statusHistory === []): ?>
      <p class="a-muted a-small">No health checks recorded yet.</p>
    <?php else: ?>
      <div class="a-table-wrap">
        <table class="a-table a-table--stack">
          <thead><tr><th>When (UTC)</th><th>Result</th><th>Transport</th><th>Detail</th></tr></thead>
          <tbody>
            <?php foreach (array_slice($statusHistory, 0, 12) as $check): ?>
              <tr>
                <td data-label="When" class="a-small a-muted"><?= View::e((string) $check['checked_at']) ?></td>
                <td data-label="Result">
                  <span class="a-badge a-badge--<?= (string) $check['status'] === 'online' ? 'success' : 'danger' ?>">
                    <?= View::e((string) $check['status']) ?>
                  </span>
                </td>
                <td data-label="Transport" class="a-small"><?= View::e((string) $check['driver']) ?></td>
                <td data-label="Detail" class="a-small">
                  <?= View::e((string) ($check['state_reason'] ?? $check['message'] ?? '—')) ?>
                  <?= $check['latency_ms'] !== null ? ' · ' . (int) $check['latency_ms'] . ' ms' : '' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php if ($errorLog !== []): ?>
<section class="a-card">
  <h2 class="a-card__title">Failed jobs on this printer</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead><tr><th>Job</th><th>Error</th><th>Code</th><th class="a-table__num">Attempts</th><th>When (UTC)</th></tr></thead>
      <tbody>
        <?php foreach ($errorLog as $row): ?>
          <tr>
            <td data-label="Job"><a class="a-mono" href="/admin/jobs/<?= View::e((string) $row['job_number']) ?>">
              <?= View::e((string) $row['job_number']) ?></a></td>
            <td data-label="Error" class="a-small"><?= View::e((string) ($row['error_message'] ?? '—')) ?></td>
            <td data-label="Code" class="a-small a-mono"><?= View::e((string) ($row['error_code'] ?? '—')) ?></td>
            <td data-label="Attempts" class="a-table__num"><?= (int) $row['attempts'] ?></td>
            <td data-label="When" class="a-small a-muted"><?= View::e((string) ($row['completed_at'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php $view->end(); ?>
