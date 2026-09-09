<?php
/**
 * @var App\Core\View $view
 * @var array<int,array<string,mixed>> $cards
 * @var array<int,array<string,mixed>> $series
 * @var array<int,array<string,mixed>> $revenueByLocation
 * @var array<int,array<string,mixed>> $printerUsage
 * @var array<string,int> $colorSplit
 * @var array<int,array<string,mixed>> $recentJobs
 * @var array<int,array<string,mixed>> $offlinePrinters
 * @var array<string,int> $counters
 */

use App\Core\View;
use App\Models\PrintJob;
use App\Support\Chart;
use App\Support\Money;

$view->extend('layouts/admin');
$view->start('content');
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>Dashboard</h1>
    <p>Today at a glance, and the last 30 days of activity.</p>
  </div>
  <div class="a-page-head__actions">
    <a class="a-btn a-btn--ghost a-btn--sm" href="/admin/reports">Full reports</a>
  </div>
</div>

<?php if (($counters['printers_offline'] ?? 0) > 0): ?>
  <div class="a-alert a-alert--danger">
    <strong><?= (int) $counters['printers_offline'] ?> printer(s) are offline</strong>
    Customers cannot start jobs on these printers. They are listed below.
  </div>
<?php endif; ?>

<?php if (($counters['printers_unknown'] ?? 0) > 0): ?>
  <div class="a-alert a-alert--warning">
    <strong><?= (int) $counters['printers_unknown'] ?> printer(s) have not reported in</strong>
    A printer with an unknown state is treated as unavailable, so customers are not offered it.
  </div>
<?php endif; ?>

<section class="a-stats" aria-label="Today's figures">
  <?php foreach ($cards as $card): ?>
    <div class="a-stat a-stat--<?= View::e($card['tone']) ?>" data-card="<?= View::e($card['key']) ?>">
      <div class="a-stat__label"><?= View::e($card['label']) ?></div>
      <div class="a-stat__value" data-value><?= View::e($card['value']) ?></div>
      <div class="a-stat__sub" data-sub><?= View::e($card['sub']) ?></div>
    </div>
  <?php endforeach; ?>
</section>

<div class="a-charts a-charts--2">
  <section class="a-card">
    <h2 class="a-card__title">Daily revenue — last 30 days</h2>
    <div class="a-chart">
      <?= Chart::area($series, 'revenue', 'Daily revenue', Chart::SERIES_1, $currencySymbol, 200) ?>
    </div>
  </section>

  <section class="a-card">
    <h2 class="a-card__title">Colour vs black &amp; white</h2>
    <?= Chart::split(
        (int) ($colorSplit['bw_pages'] ?? 0),
        (int) ($colorSplit['color_pages'] ?? 0),
        'Black & white',
        'Colour'
    ) ?>
  </section>
</div>

<div class="a-charts a-charts--2">
  <section class="a-card">
    <h2 class="a-card__title">Print jobs per day</h2>
    <div class="a-chart">
      <?= Chart::bars($series, 'jobs', 'Print jobs per day', Chart::SERIES_1, 190) ?>
    </div>
  </section>

  <section class="a-card">
    <h2 class="a-card__title">Pages per day by type</h2>
    <div class="a-chart">
      <?= Chart::stacked($series, 'pages_bw', 'pages_color', 'B&W', 'Colour', 190) ?>
      <?= Chart::legend([
          ['label' => 'Black & white', 'color' => Chart::SERIES_1],
          ['label' => 'Colour', 'color' => Chart::SERIES_2],
      ]) ?>
    </div>
  </section>
</div>

<div class="a-charts a-charts--2">
  <section class="a-card">
    <h2 class="a-card__title">Revenue by location</h2>
    <?= Chart::ranked(
        array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'value' => ((int) $row['revenue_paise']) / 100,
            'meta' => sprintf('%s jobs · %s pages', number_format((int) $row['jobs']), number_format((int) $row['pages'])),
        ], $revenueByLocation),
        $currencySymbol
    ) ?>
  </section>

  <section class="a-card">
    <h2 class="a-card__title">Printer usage</h2>
    <?= Chart::ranked(
        array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'value' => (int) $row['pages'],
            'meta' => sprintf(
                '%s · %s jobs%s',
                (string) $row['location_name'],
                number_format((int) $row['jobs']),
                (int) $row['failed'] > 0 ? ' · ' . (int) $row['failed'] . ' failed' : ''
            ),
        ], $printerUsage),
        '',
        8
    ) ?>
    <p class="a-small a-muted" style="margin-top:.6rem">Pages printed in the last 30 days.</p>
  </section>
</div>

<?php if ($offlinePrinters !== []): ?>
<section class="a-card">
  <h2 class="a-card__title">Printers needing attention</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr><th>Printer</th><th>State</th><th>Last seen</th><th>Last error</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($offlinePrinters as $printer): ?>
          <tr>
            <td data-label="Printer"><?= View::e($printer['name']) ?></td>
            <td data-label="State">
              <span class="a-badge a-badge--<?= View::e($printer['status_tone']) ?>">
                <?= View::e($printer['status_label']) ?>
              </span>
            </td>
            <td data-label="Last seen" class="a-small a-muted">
              <?= $printer['last_seen_at'] !== '' ? View::e($printer['last_seen_at']) . ' UTC' : 'Never' ?>
            </td>
            <td data-label="Last error" class="a-small"><?= View::e($printer['last_error'] ?: '—') ?></td>
            <td data-label="">
              <a class="a-btn a-btn--ghost a-btn--sm" href="/admin/printers/<?= (int) $printer['id'] ?>">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="a-card">
  <h2 class="a-card__title">Recent jobs</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr>
          <th>Job</th><th>Location</th><th>Printer</th>
          <th class="a-table__num">Pages</th><th class="a-table__num">Amount</th>
          <th>Status</th><th>Created (UTC)</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($recentJobs === []): ?>
          <tr><td colspan="7" class="a-table__empty">No print jobs yet.</td></tr>
        <?php else: ?>
          <?php foreach ($recentJobs as $row):
              $job = PrintJob::fromRow($row); ?>
            <tr>
              <td data-label="Job">
                <a class="a-mono" href="/admin/jobs/<?= View::e($job->jobNumber()) ?>">
                  <?= View::e($job->jobNumber()) ?>
                </a>
              </td>
              <td data-label="Location"><?= View::e((string) $row['location_name']) ?></td>
              <td data-label="Printer"><?= View::e((string) $row['printer_name']) ?></td>
              <td data-label="Pages" class="a-table__num"><?= number_format($job->int('billable_pages')) ?></td>
              <td data-label="Amount" class="a-table__num">
                <?= View::e(Money::formatWithSymbol($job->int('total_paise'))) ?>
              </td>
              <td data-label="Status">
                <span class="a-badge a-badge--<?= View::e($job->statusTone()) ?>">
                  <?= View::e($job->statusLabel()) ?>
                </span>
              </td>
              <td data-label="Created" class="a-small a-muted"><?= View::e($job->string('created_at')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php
$view->end();
$view->start('scripts');
?>
<script nonce="<?= View::e($cspNonce) ?>">
// Refresh the counters without reloading the page, so a screen left open on a
// back-office monitor stays current.
(function () {
  setInterval(function () {
    fetch('/admin/dashboard/data', {
      headers: { 'Accept': 'application/json' }, cache: 'no-store'
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.success) { return; }
        data.cards.forEach(function (card) {
          var node = document.querySelector('[data-card="' + card.key + '"]');
          if (!node) { return; }
          node.querySelector('[data-value]').textContent = card.value;
          node.querySelector('[data-sub]').textContent = card.sub;
        });
      })
      .catch(function () {});
  }, 30000);
})();
</script>
<?php $view->end(); ?>
