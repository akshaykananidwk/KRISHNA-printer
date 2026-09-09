<?php
/**
 * @var App\Core\View $view
 * @var string $from @var string $to @var string $preset @var string $groupBy
 * @var array<string,int> $summary
 * @var array<int,array<string,mixed>> $grouped
 * @var array<int,array<string,mixed>> $revenueByLocation
 * @var array<int,array<string,mixed>> $printerUsage
 * @var array<string,int> $colorSplit
 * @var array<int,array<string,mixed>> $paperBreakdown
 * @var string $queryString
 */

use App\Core\View;
use App\Support\Chart;
use App\Support\Money;

$view->extend('layouts/admin');
$view->start('content');

$presets = [
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    'week' => 'This week',
    'last_week' => 'Last week',
    'month' => 'This month',
    'last_month' => 'Last month',
    'last_30' => 'Last 30 days',
    'custom' => 'Custom range',
];
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>Reports</h1>
    <p><?= View::e($from) ?> to <?= View::e($to) ?> (UTC)</p>
  </div>
  <div class="a-page-head__actions">
    <a class="a-btn a-btn--ghost a-btn--sm" href="/admin/reports/export/csv?<?= View::e($queryString) ?>">CSV</a>
    <a class="a-btn a-btn--ghost a-btn--sm" href="/admin/reports/export/excel?<?= View::e($queryString) ?>">Excel</a>
    <a class="a-btn a-btn--ghost a-btn--sm" href="/admin/reports/export/pdf?<?= View::e($queryString) ?>">PDF</a>
  </div>
</div>

<form class="a-filters" method="get" action="/admin/reports" data-auto-submit>
  <div class="a-field">
    <label class="a-field__label" for="preset">Period</label>
    <select class="a-select" id="preset" name="preset">
      <?php foreach ($presets as $key => $label): ?>
        <option value="<?= View::e($key) ?>" <?= $preset === $key ? 'selected' : '' ?>><?= View::e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="a-field">
    <label class="a-field__label" for="from">From</label>
    <input class="a-input" type="date" id="from" name="from" value="<?= View::e($from) ?>">
  </div>
  <div class="a-field">
    <label class="a-field__label" for="to">To</label>
    <input class="a-input" type="date" id="to" name="to" value="<?= View::e($to) ?>">
  </div>
  <div class="a-field">
    <label class="a-field__label" for="location_id">Location</label>
    <select class="a-select" id="location_id" name="location_id">
      <option value="">All</option>
      <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc->id() ?>" <?= (int) ($filters['location_id'] ?? 0) === $loc->id() ? 'selected' : '' ?>>
          <?= View::e($loc->string('name')) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="a-field">
    <label class="a-field__label" for="printer_id">Printer</label>
    <select class="a-select" id="printer_id" name="printer_id">
      <option value="">All</option>
      <?php foreach ($printers as $p): ?>
        <option value="<?= $p->id() ?>" <?= (int) ($filters['printer_id'] ?? 0) === $p->id() ? 'selected' : '' ?>>
          <?= View::e($p->string('name')) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="a-field">
    <label class="a-field__label" for="color_mode">Colour</label>
    <select class="a-select" id="color_mode" name="color_mode">
      <option value="">Any</option>
      <option value="bw" <?= ($filters['color_mode'] ?? '') === 'bw' ? 'selected' : '' ?>>B&amp;W</option>
      <option value="color" <?= ($filters['color_mode'] ?? '') === 'color' ? 'selected' : '' ?>>Colour</option>
    </select>
  </div>
  <div class="a-field">
    <label class="a-field__label" for="paper_size">Paper</label>
    <select class="a-select" id="paper_size" name="paper_size">
      <option value="">Any</option>
      <?php foreach ($paperSizes as $size): ?>
        <option value="<?= View::e($size) ?>" <?= ($filters['paper_size'] ?? '') === $size ? 'selected' : '' ?>>
          <?= View::e($size) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="a-field">
    <label class="a-field__label" for="status">Print status</label>
    <select class="a-select" id="status" name="status">
      <option value="">Any</option>
      <?php
      $currentStatus = is_array($filters['status'] ?? null) ? ($filters['status'][0] ?? '') : '';
      foreach ($statuses as $s): ?>
        <option value="<?= View::e($s) ?>" <?= $currentStatus === $s ? 'selected' : '' ?>>
          <?= View::e(ucwords(str_replace('_', ' ', $s))) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="a-field">
    <label class="a-field__label" for="payment_status">Payment</label>
    <select class="a-select" id="payment_status" name="payment_status">
      <option value="">Any</option>
      <?php foreach (['not_required' => 'Not required', 'pending' => 'Pending', 'paid' => 'Paid',
                      'failed' => 'Failed', 'refunded' => 'Refunded'] as $key => $label): ?>
        <option value="<?= View::e($key) ?>" <?= ($filters['payment_status'] ?? '') === $key ? 'selected' : '' ?>>
          <?= View::e($label) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="a-field">
    <label class="a-field__label" for="group_by">Group by</label>
    <select class="a-select" id="group_by" name="group_by">
      <?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'location' => 'Location',
                      'printer' => 'Printer', 'color_mode' => 'Colour mode', 'paper_size' => 'Paper size',
                      'status' => 'Status', 'payment_status' => 'Payment status'] as $key => $label): ?>
        <option value="<?= View::e($key) ?>" <?= $groupBy === $key ? 'selected' : '' ?>><?= View::e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="a-btn a-btn--ghost">Apply</button>
  <a class="a-btn a-btn--ghost" href="/admin/reports">Reset</a>
</form>

<section class="a-stats">
  <div class="a-stat a-stat--primary">
    <div class="a-stat__label">Jobs</div>
    <div class="a-stat__value"><?= number_format($summary['jobs_total'] ?? 0) ?></div>
    <div class="a-stat__sub"><?= number_format($summary['jobs_completed'] ?? 0) ?> completed</div>
  </div>
  <div class="a-stat a-stat--danger">
    <div class="a-stat__label">Failed</div>
    <div class="a-stat__value"><?= number_format($summary['jobs_failed'] ?? 0) ?></div>
    <div class="a-stat__sub"><?= number_format($summary['jobs_cancelled'] ?? 0) ?> cancelled</div>
  </div>
  <div class="a-stat a-stat--primary">
    <div class="a-stat__label">Pages printed</div>
    <div class="a-stat__value"><?= number_format($summary['pages_printed'] ?? 0) ?></div>
    <div class="a-stat__sub"><?= number_format($summary['sheets'] ?? 0) ?> sheets</div>
  </div>
  <div class="a-stat a-stat--info">
    <div class="a-stat__label">Colour pages</div>
    <div class="a-stat__value"><?= number_format($summary['pages_color'] ?? 0) ?></div>
    <div class="a-stat__sub"><?= number_format($summary['pages_bw'] ?? 0) ?> B&amp;W</div>
  </div>
  <div class="a-stat a-stat--success">
    <div class="a-stat__label">Revenue</div>
    <div class="a-stat__value"><?= View::e(Money::formatWithSymbol($summary['revenue_paise'] ?? 0)) ?></div>
    <div class="a-stat__sub">Completed &amp; paid</div>
  </div>
  <div class="a-stat a-stat--warning">
    <div class="a-stat__label">Tax collected</div>
    <div class="a-stat__value"><?= View::e(Money::formatWithSymbol($summary['tax_paise'] ?? 0)) ?></div>
    <div class="a-stat__sub">
      Fees <?= View::e(Money::formatWithSymbol($summary['gateway_fee_paise'] ?? 0)) ?>
    </div>
  </div>
</section>

<div class="a-charts a-charts--2">
  <section class="a-card">
    <h2 class="a-card__title">Revenue by location</h2>
    <?= Chart::ranked(
        array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'value' => ((int) $row['revenue_paise']) / 100,
            'meta' => sprintf('%s jobs · %s pages', number_format((int) $row['jobs']), number_format((int) $row['pages'])),
        ], $revenueByLocation),
        $currencySymbol,
        12
    ) ?>
  </section>

  <section class="a-card">
    <h2 class="a-card__title">Colour vs black &amp; white</h2>
    <?= Chart::split(
        (int) ($colorSplit['bw_pages'] ?? 0),
        (int) ($colorSplit['color_pages'] ?? 0),
        'Black & white',
        'Colour'
    ) ?>

    <?php if ($paperBreakdown !== []): ?>
      <h3 class="a-card__title a-card__title--plain" style="margin-top:1.2rem">Paper sizes used</h3>
      <?= Chart::ranked(
          array_map(static fn (array $row): array => [
              'name' => (string) $row['paper_size'],
              'value' => (int) $row['pages'],
              'meta' => sprintf('%s jobs', number_format((int) $row['jobs'])),
          ], $paperBreakdown),
          '',
          8
      ) ?>
    <?php endif; ?>
  </section>
</div>

<section class="a-card">
  <div class="a-row" style="justify-content:space-between;margin-bottom:.8rem">
    <h2 class="a-card__title" style="margin:0">Grouped by <?= View::e(str_replace('_', ' ', $groupBy)) ?></h2>
    <div class="a-row">
      <a class="a-btn a-btn--ghost a-btn--sm"
         href="/admin/reports/summary-export/csv?<?= View::e($queryString) ?>">Export this table</a>
    </div>
  </div>

  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr>
          <th><?= View::e(ucfirst(str_replace('_', ' ', $groupBy))) ?></th>
          <th class="a-table__num">Jobs</th>
          <th class="a-table__num">Pages</th>
          <th class="a-table__num">Colour</th>
          <th class="a-table__num">B&amp;W</th>
          <th class="a-table__num">Failed</th>
          <th class="a-table__num">Revenue</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($grouped === []): ?>
          <tr><td colspan="7" class="a-table__empty">No activity in this period.</td></tr>
        <?php else: ?>
          <?php foreach ($grouped as $row): ?>
            <tr>
              <td data-label="Group"><?= View::e((string) $row['group_key']) ?></td>
              <td data-label="Jobs" class="a-table__num"><?= number_format((int) $row['jobs']) ?></td>
              <td data-label="Pages" class="a-table__num"><?= number_format((int) $row['pages']) ?></td>
              <td data-label="Colour" class="a-table__num"><?= number_format((int) $row['pages_color']) ?></td>
              <td data-label="B&amp;W" class="a-table__num"><?= number_format((int) $row['pages_bw']) ?></td>
              <td data-label="Failed" class="a-table__num"><?= number_format((int) $row['failed']) ?></td>
              <td data-label="Revenue" class="a-table__num">
                <?= View::e(Money::formatWithSymbol((int) $row['revenue_paise'])) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="a-card">
  <h2 class="a-card__title">Printer usage</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr><th>Printer</th><th>Location</th><th>State</th>
          <th class="a-table__num">Jobs</th><th class="a-table__num">Pages</th>
          <th class="a-table__num">Failed</th><th class="a-table__num">Revenue</th></tr>
      </thead>
      <tbody>
        <?php if ($printerUsage === []): ?>
          <tr><td colspan="7" class="a-table__empty">No printers to report on.</td></tr>
        <?php else: ?>
          <?php foreach ($printerUsage as $row): ?>
            <tr>
              <td data-label="Printer"><?= View::e((string) $row['name']) ?></td>
              <td data-label="Location"><?= View::e((string) $row['location_name']) ?></td>
              <td data-label="State">
                <span class="a-badge a-badge--<?= (string) $row['status'] === 'online' ? 'success' : 'muted' ?>">
                  <?= View::e(ucfirst((string) $row['status'])) ?>
                </span>
              </td>
              <td data-label="Jobs" class="a-table__num"><?= number_format((int) $row['jobs']) ?></td>
              <td data-label="Pages" class="a-table__num"><?= number_format((int) $row['pages']) ?></td>
              <td data-label="Failed" class="a-table__num"><?= number_format((int) $row['failed']) ?></td>
              <td data-label="Revenue" class="a-table__num">
                <?= View::e(Money::formatWithSymbol((int) $row['revenue_paise'])) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php $view->end(); ?>
