<?php
/**
 * The receipt. Shows the job number prominently — it is what the customer
 * quotes at the counter — and polls each job's status so they can watch it
 * print without refreshing.
 *
 * @var App\Core\View $view
 * @var array<int,array<string,mixed>> $jobs
 * @var string $primaryJobNumber
 * @var string $totalDisplay
 * @var int $totalPages
 * @var string $locationName
 * @var string $printerName
 */

use App\Core\View;
use App\Models\PrintJob;
use App\Support\Money;

$view->extend('layouts/customer');
$view->start('content');

$isPaid = true;
foreach ($jobs as $j) {
    if (!in_array((string) $j['payment_status'], ['paid', 'not_required'], true)) {
        $isPaid = false;
        break;
    }
}
?>

<div class="c-receipt">
  <div class="c-receipt__tick" aria-hidden="true">
    <svg viewBox="0 0 52 52" width="64" height="64"><circle cx="26" cy="26" r="24" fill="none" stroke="currentColor"
      stroke-width="2.5"/><path d="M15 27l8 8 15-16" fill="none" stroke="currentColor" stroke-width="3.5"
      stroke-linecap="round" stroke-linejoin="round"/></svg>
  </div>

  <h1 class="c-receipt__title">Your document has been sent to the printer.</h1>

  <div class="c-jobno">
    <span class="c-jobno__label">Print Job</span>
    <strong class="c-jobno__value">#<?= View::e($primaryJobNumber) ?></strong>
    <?php if (count($jobs) > 1): ?>
      <span class="c-jobno__extra">+<?= count($jobs) - 1 ?> more in this order</span>
    <?php endif; ?>
  </div>

  <p class="c-receipt__lead">Please collect your printout at the counter.</p>

  <dl class="c-detail">
    <div class="c-detail__row"><dt>Location</dt><dd><?= View::e($locationName) ?></dd></div>
    <div class="c-detail__row"><dt>Printer</dt><dd><?= View::e($printerName) ?></dd></div>
    <div class="c-detail__row"><dt>Documents</dt><dd><?= count($jobs) ?></dd></div>
    <div class="c-detail__row"><dt>Total pages</dt><dd><?= number_format($totalPages) ?></dd></div>
    <div class="c-detail__row"><dt>Amount</dt><dd><?= View::e($totalDisplay) ?></dd></div>
    <div class="c-detail__row">
      <dt>Payment</dt>
      <dd>
        <span class="c-badge c-badge--<?= $isPaid ? 'success' : 'warning' ?>">
          <?= $isPaid
              ? ((string) $jobs[0]['payment_status'] === 'not_required' ? 'No payment required' : 'Paid')
              : 'Awaiting payment' ?>
        </span>
      </dd>
    </div>
  </dl>

  <h2 class="c-receipt__subtitle">Documents</h2>
  <ul class="c-joblist" id="jobList">
    <?php foreach ($jobs as $job):
        $model = PrintJob::fromRow($job); ?>
      <li class="c-joblist__item" data-job="<?= View::e($model->jobNumber()) ?>">
        <div class="c-joblist__head">
          <span class="c-joblist__no">#<?= View::e($model->jobNumber()) ?></span>
          <span class="c-badge c-badge--<?= View::e($model->statusTone()) ?>" data-status>
            <?= View::e($model->statusLabel()) ?>
          </span>
        </div>
        <p class="c-joblist__file"><?= View::e((string) ($job['file_name'] ?? 'Document')) ?></p>
        <p class="c-joblist__meta">
          <?= (int) $job['selected_pages'] ?> page<?= (int) $job['selected_pages'] === 1 ? '' : 's' ?>
          · <?= (int) $job['copies'] ?> cop<?= (int) $job['copies'] === 1 ? 'y' : 'ies' ?>
          · <?= View::e($model->colorLabel()) ?>
          · <?= View::e((string) $job['paper_size']) ?>
          · <?= View::e($model->duplexLabel()) ?>
          <?php if ((string) $job['page_range'] !== 'all'): ?>
            · pages <?= View::e((string) $job['page_range']) ?>
          <?php endif; ?>
          · <?= View::e(Money::formatWithSymbol((int) $job['total_paise'])) ?>
        </p>
        <p class="c-joblist__error" data-error hidden></p>
      </li>
    <?php endforeach; ?>
  </ul>

  <p class="c-receipt__note">
    Keep this page open to watch your job print, or note the job number above.
  </p>
</div>

<?php
$view->end();
$view->start('scripts');
?>
<script nonce="<?= View::e($cspNonce) ?>">
// Poll each job until it reaches a terminal state, then stop. Polling is
// deliberately unhurried — the customer is standing at a counter, not
// watching a stopwatch, and this runs on a phone battery.
(function () {
  var items = Array.prototype.slice.call(document.querySelectorAll('[data-job]'));
  var pending = items.length;
  if (!pending) { return; }

  var toneClasses = ['c-badge--success','c-badge--danger','c-badge--muted','c-badge--info','c-badge--warning','c-badge--neutral'];

  function poll(item) {
    var jobNumber = item.getAttribute('data-job');
    fetch('/print/job/' + encodeURIComponent(jobNumber) + '/status', {
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.success) { return; }

        var badge = item.querySelector('[data-status]');
        if (badge) {
          badge.textContent = data.status_label;
          toneClasses.forEach(function (c) { badge.classList.remove(c); });
          badge.classList.add('c-badge--' + data.status_tone);
        }

        var error = item.querySelector('[data-error]');
        if (error && data.error_message) {
          error.textContent = data.error_message;
          error.hidden = false;
        }

        if (data.is_terminal) {
          item.setAttribute('data-done', '1');
          pending--;
          if (pending <= 0) { clearInterval(timer); }
        }
      })
      .catch(function () { /* transient; try again next tick */ });
  }

  var timer = setInterval(function () {
    items.forEach(function (item) {
      if (!item.getAttribute('data-done')) { poll(item); }
    });
  }, 6000);

  items.forEach(poll);

  // Stop after five minutes; by then the job has either printed or needs a
  // human, and neither is helped by more requests.
  setTimeout(function () { clearInterval(timer); }, 300000);
})();
</script>
<?php $view->end(); ?>
