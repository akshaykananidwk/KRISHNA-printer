<?php
/** @var App\Core\View $view @var array $result @var array $filters @var array $locations @var array $printers @var array $statuses */

use App\Core\View;
use App\Models\PrintJob;
use App\Support\Money;

$view->extend('layouts/admin');
$view->start('content');

$currentStatus = is_array($filters['status'] ?? null) ? ($filters['status'][0] ?? '') : '';
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>Print jobs</h1>
    <p><?= number_format((int) $result['total']) ?> job(s) match these filters.</p>
  </div>
</div>

<form class="a-filters" method="get" action="/admin/jobs" data-auto-submit>
  <div class="a-field">
    <label class="a-field__label" for="search">Search</label>
    <input class="a-input" type="search" id="search" name="search"
           value="<?= View::e((string) ($filters['search'] ?? '')) ?>" placeholder="Job number or file">
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
    <label class="a-field__label" for="status">Status</label>
    <select class="a-select" id="status" name="status">
      <option value="">Any</option>
      <?php foreach ($statuses as $s): ?>
        <option value="<?= View::e($s) ?>" <?= $currentStatus === $s ? 'selected' : '' ?>>
          <?= View::e(ucwords(str_replace('_', ' ', $s))) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="a-field">
    <label class="a-field__label" for="date_from">From</label>
    <input class="a-input" type="date" id="date_from" name="date_from"
           value="<?= View::e((string) ($filters['date_from'] ?? '')) ?>">
  </div>
  <div class="a-field">
    <label class="a-field__label" for="date_to">To</label>
    <input class="a-input" type="date" id="date_to" name="date_to"
           value="<?= View::e((string) ($filters['date_to'] ?? '')) ?>">
  </div>
  <button type="submit" class="a-btn a-btn--ghost">Filter</button>
  <a class="a-btn a-btn--ghost" href="/admin/jobs">Reset</a>
</form>

<div class="a-card">
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr>
          <th>Job</th><th>Location / printer</th><th>Document</th>
          <th class="a-table__num">Pages</th><th>Settings</th>
          <th class="a-table__num">Amount</th><th>Payment</th><th>Status</th><th>Created (UTC)</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($result['rows'] === []): ?>
          <tr><td colspan="9" class="a-table__empty">No jobs match these filters.</td></tr>
        <?php else: ?>
          <?php foreach ($result['rows'] as $row):
              $job = PrintJob::fromRow($row); ?>
            <tr data-job="<?= View::e($job->jobNumber()) ?>">
              <td data-label="Job">
                <a class="a-mono" href="/admin/jobs/<?= View::e($job->jobNumber()) ?>">
                  <?= View::e($job->jobNumber()) ?>
                </a>
              </td>
              <td data-label="Location / printer">
                <?= View::e((string) $row['location_name']) ?>
                <div class="a-small a-muted"><?= View::e((string) $row['printer_name']) ?></div>
              </td>
              <td data-label="Document" class="a-small"><?= View::e((string) ($row['file_name'] ?? '—')) ?></td>
              <td data-label="Pages" class="a-table__num">
                <?= number_format($job->int('billable_pages')) ?>
                <div class="a-small a-muted"><?= $job->int('copies') ?>×</div>
              </td>
              <td data-label="Settings" class="a-small">
                <?= View::e($job->colorLabel()) ?> · <?= View::e($job->string('paper_size')) ?>
                <div class="a-muted">
                  <?= View::e($job->duplexLabel()) ?>
                  <?php if ($job->string('page_range') !== 'all'): ?>
                    · pages <?= View::e($job->string('page_range')) ?>
                  <?php endif; ?>
                </div>
              </td>
              <td data-label="Amount" class="a-table__num">
                <?= View::e(Money::formatWithSymbol($job->int('total_paise'))) ?>
              </td>
              <td data-label="Payment">
                <?php
                $paymentStatus = $job->string('payment_status');
                $paymentTone = match ($paymentStatus) {
                    'paid' => 'success',
                    'failed' => 'danger',
                    'pending' => 'warning',
                    'refunded' => 'info',
                    default => 'muted',
                }; ?>
                <span class="a-badge a-badge--<?= $paymentTone ?>">
                  <?= View::e(ucwords(str_replace('_', ' ', $paymentStatus))) ?>
                </span>
              </td>
              <td data-label="Status">
                <span class="a-badge a-badge--<?= View::e($job->statusTone()) ?>" data-status>
                  <?= View::e($job->statusLabel()) ?>
                </span>
                <?php if ($job->int('attempts') > 1): ?>
                  <div class="a-small a-muted"><?= $job->int('attempts') ?> attempts</div>
                <?php endif; ?>
              </td>
              <td data-label="Created" class="a-small a-muted"><?= View::e($job->string('created_at')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?= $view->include('partials/pagination', [
      'result' => $result,
      'baseUrl' => '/admin/jobs',
      'query' => http_build_query(array_filter([
          'search' => $filters['search'] ?? '',
          'location_id' => $filters['location_id'] ?? '',
          'printer_id' => $filters['printer_id'] ?? '',
          'status' => $currentStatus,
          'date_from' => $filters['date_from'] ?? '',
          'date_to' => $filters['date_to'] ?? '',
      ])),
  ]) ?>
</div>

<?php
$view->end();
$view->start('scripts');
?>
<script nonce="<?= View::e($cspNonce) ?>">
// Live-update the status column so a queue being worked through is visible
// without reloading.
(function () {
  var query = window.location.search;
  var toneClasses = ['a-badge--success','a-badge--danger','a-badge--muted','a-badge--info','a-badge--warning','a-badge--neutral'];

  setInterval(function () {
    fetch('/admin/jobs/live' + query, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.success) { return; }
        data.jobs.forEach(function (job) {
          var row = document.querySelector('[data-job="' + job.job_number + '"]');
          if (!row) { return; }
          var badge = row.querySelector('[data-status]');
          if (!badge) { return; }
          badge.textContent = job.status_label;
          toneClasses.forEach(function (c) { badge.classList.remove(c); });
          badge.classList.add('a-badge--' + job.status_tone);
        });
      })
      .catch(function () {});
  }, 12000);
})();
</script>
<?php $view->end(); ?>
