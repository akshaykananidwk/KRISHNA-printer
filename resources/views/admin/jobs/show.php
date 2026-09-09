<?php
/**
 * @var App\Core\View $view
 * @var App\Models\PrintJob $job
 * @var array<string,mixed> $detail
 * @var array<int,array<string,mixed>> $events
 * @var array<string,mixed>|null $breakdown
 * @var array<string,mixed>|null $payment
 * @var array<int,array<string,mixed>> $batchJobs
 */

use App\Core\Csrf;
use App\Core\View;
use App\Support\Money;

$view->extend('layouts/admin');
$view->start('content');
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1 class="a-mono">Job <?= View::e($job->jobNumber()) ?></h1>
    <p>
      <?= View::e((string) $detail['location_name']) ?> · <?= View::e((string) $detail['printer_name']) ?>
      · created <?= View::e($job->string('created_at')) ?> UTC
    </p>
  </div>
  <div class="a-page-head__actions">
    <?php if ($job->isCancellable()): ?>
      <form class="a-inline-form" method="post" action="/admin/jobs/<?= View::e($job->jobNumber()) ?>/cancel">
        <?= Csrf::field() ?>
        <input type="hidden" name="reason" value="Cancelled by an operator from the admin panel.">
        <button type="submit" class="a-btn a-btn--danger"
                data-confirm="Cancel this job? If it was paid for, a refund may be owed.">Cancel job</button>
      </form>
    <?php endif; ?>
    <?php if ($job->status() === 'failed'): ?>
      <form class="a-inline-form" method="post" action="/admin/jobs/<?= View::e($job->jobNumber()) ?>/retry">
        <?= Csrf::field() ?>
        <button type="submit" class="a-btn a-btn--primary">Retry</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($job->string('error_message') !== ''): ?>
  <div class="a-alert a-alert--danger">
    <strong>Last error<?= $job->string('error_code') !== '' ? ' (' . View::e($job->string('error_code')) . ')' : '' ?></strong>
    <?= View::e($job->string('error_message')) ?>
  </div>
<?php endif; ?>

<div class="a-charts a-charts--2">
  <section class="a-card">
    <h2 class="a-card__title">Job</h2>
    <dl class="a-kv">
      <dt>Status</dt>
      <dd><span class="a-badge a-badge--<?= View::e($job->statusTone()) ?>"><?= View::e($job->statusLabel()) ?></span></dd>
      <dt>Payment</dt>
      <dd><?= View::e(ucwords(str_replace('_', ' ', $job->string('payment_status')))) ?></dd>
      <dt>Document</dt><dd><?= View::e((string) ($detail['file_name'] ?? 'Deleted')) ?></dd>
      <dt>Document pages</dt><dd><?= number_format($job->int('document_pages')) ?></dd>
      <dt>Selected pages</dt><dd><?= number_format($job->int('selected_pages')) ?></dd>
      <dt>Page range</dt><dd><?= View::e($job->string('page_range')) ?></dd>
      <dt>Copies</dt><dd><?= $job->int('copies') ?></dd>
      <dt>Billable pages</dt><dd><?= number_format($job->int('billable_pages')) ?></dd>
      <dt>Sheets</dt><dd><?= number_format($job->int('sheets')) ?></dd>
      <dt>Colour</dt><dd><?= View::e($job->colorLabel()) ?></dd>
      <dt>Paper</dt><dd><?= View::e($job->string('paper_size')) ?></dd>
      <dt>Orientation</dt><dd><?= View::e(ucfirst($job->string('orientation'))) ?></dd>
      <dt>Sides</dt><dd><?= View::e($job->duplexLabel()) ?></dd>
      <dt>Attempts</dt><dd><?= $job->int('attempts') ?> of <?= $job->int('max_attempts') ?></dd>
      <?php if ($job->string('remote_job_id') !== ''): ?>
        <dt>Printer job id</dt><dd class="a-mono"><?= View::e($job->string('remote_job_id')) ?></dd>
      <?php endif; ?>
      <dt>Queued</dt><dd><?= View::e($job->string('queued_at') ?: '—') ?></dd>
      <dt>Started</dt><dd><?= View::e($job->string('started_at') ?: '—') ?></dd>
      <dt>Completed</dt><dd><?= View::e($job->string('completed_at') ?: '—') ?></dd>
    </dl>
  </section>

  <section class="a-card">
    <h2 class="a-card__title">Charges</h2>
    <dl class="a-kv">
      <dt>Subtotal</dt><dd><?= View::e(Money::formatWithSymbol($job->int('subtotal_paise'))) ?></dd>
      <?php if ($job->int('tax_paise') > 0): ?>
        <dt><?= View::e((string) ($breakdown['tax_label'] ?? 'Tax')) ?></dt>
        <dd><?= View::e(Money::formatWithSymbol($job->int('tax_paise'))) ?></dd>
      <?php endif; ?>
      <?php if ($job->int('gateway_fee_paise') > 0): ?>
        <dt>Gateway fee</dt><dd><?= View::e(Money::formatWithSymbol($job->int('gateway_fee_paise'))) ?></dd>
      <?php endif; ?>
      <dt><strong>Total</strong></dt>
      <dd><strong><?= View::e(Money::formatWithSymbol($job->int('total_paise'))) ?></strong></dd>
    </dl>

    <?php if ($breakdown !== null && !empty($breakdown['explanation'])): ?>
      <p class="a-small a-muted" style="margin-top:.7rem">
        <?= View::e((string) $breakdown['explanation']) ?>
        <?php if (!empty($breakdown['rule_name'])): ?>
          <br>Rule: <?= View::e((string) $breakdown['rule_name']) ?>
          (<?= View::e((string) ($breakdown['rule_scope'] ?? '')) ?>)
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if ($payment !== null): ?>
      <h3 class="a-card__title a-card__title--plain" style="margin-top:1rem">Payment</h3>
      <dl class="a-kv">
        <dt>Gateway</dt><dd><?= View::e((string) $payment['gateway']) ?></dd>
        <dt>Order</dt><dd class="a-mono a-small"><?= View::e((string) ($payment['gateway_order_id'] ?? '—')) ?></dd>
        <dt>Payment</dt><dd class="a-mono a-small"><?= View::e((string) ($payment['gateway_payment_id'] ?? '—')) ?></dd>
        <dt>Status</dt><dd><?= View::e((string) $payment['status']) ?></dd>
        <dt>Verified</dt>
        <dd>
          <?php if ((int) $payment['verified_server_side'] === 1): ?>
            <span class="a-badge a-badge--success">
              Server-side (<?= View::e((string) $payment['verification_method']) ?>)
            </span>
          <?php else: ?>
            <span class="a-badge a-badge--danger">Not verified</span>
          <?php endif; ?>
        </dd>
        <dt>Method</dt><dd><?= View::e((string) ($payment['method'] ?? '—')) ?></dd>
        <?php if ((int) $payment['refunded_paise'] > 0): ?>
          <dt>Refunded</dt><dd><?= View::e(Money::formatWithSymbol((int) $payment['refunded_paise'])) ?></dd>
        <?php endif; ?>
      </dl>

      <?php if ((int) $payment['verified_server_side'] === 1 && (string) $payment['status'] !== 'refunded'): ?>
        <form method="post" action="/admin/jobs/<?= View::e($job->jobNumber()) ?>/refund" style="margin-top:.8rem">
          <?= Csrf::field() ?>
          <div class="a-field">
            <label class="a-field__label" for="refund_reason">Refund reason</label>
            <input class="a-input" id="refund_reason" name="reason" required minlength="3" maxlength="200"
                   placeholder="Printer jammed; customer not served">
          </div>
          <button type="submit" class="a-btn a-btn--ghost a-btn--sm"
                  data-confirm="Issue a refund through the payment gateway?">Refund this payment</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>

<?php if (count($batchJobs) > 1): ?>
<section class="a-card">
  <h2 class="a-card__title">Other jobs in the same order</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead><tr><th>Job</th><th>Document</th><th class="a-table__num">Pages</th>
        <th class="a-table__num">Amount</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($batchJobs as $row):
            if ((string) $row['job_number'] === $job->jobNumber()) { continue; }
            $sibling = App\Models\PrintJob::fromRow($row); ?>
          <tr>
            <td data-label="Job"><a class="a-mono" href="/admin/jobs/<?= View::e($sibling->jobNumber()) ?>">
              <?= View::e($sibling->jobNumber()) ?></a></td>
            <td data-label="Document" class="a-small"><?= View::e((string) ($row['file_name'] ?? '—')) ?></td>
            <td data-label="Pages" class="a-table__num"><?= number_format($sibling->int('billable_pages')) ?></td>
            <td data-label="Amount" class="a-table__num">
              <?= View::e(Money::formatWithSymbol($sibling->int('total_paise'))) ?></td>
            <td data-label="Status">
              <span class="a-badge a-badge--<?= View::e($sibling->statusTone()) ?>">
                <?= View::e($sibling->statusLabel()) ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="a-card">
  <h2 class="a-card__title">History</h2>
  <ul class="a-steps">
    <?php foreach ($events as $event): ?>
      <li class="a-step-row">
        <span class="a-step-row__icon a-step-row__icon--<?=
            in_array((string) $event['to_status'], ['completed'], true) ? 'ok'
            : (in_array((string) $event['to_status'], ['failed', 'cancelled'], true) ? 'failed' : 'running') ?>">
          <?= in_array((string) $event['to_status'], ['completed'], true) ? '✓'
              : (in_array((string) $event['to_status'], ['failed', 'cancelled'], true) ? '!' : '•') ?>
        </span>
        <span class="a-step-row__body">
          <span class="a-step-row__label">
            <?= $event['from_status'] !== null ? View::e((string) $event['from_status']) . ' → ' : '' ?>
            <?= View::e((string) $event['to_status']) ?>
            <span class="a-badge a-badge--muted"><?= View::e((string) $event['actor_type']) ?></span>
          </span>
          <span class="a-step-row__msg"><?= View::e((string) ($event['message'] ?? '')) ?></span>
        </span>
        <span class="a-step-row__time"><?= View::e((string) $event['created_at']) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
</section>

<?php $view->end(); ?>
