<?php
/** @var App\Core\View $view @var array $result @var array $filters @var array $actions */

use App\Core\View;

$view->extend('layouts/admin');
$view->start('content');
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>Audit log</h1>
    <p>Append-only record of every privileged and money-touching action. Secrets are redacted before
      anything is written here.</p>
  </div>
</div>

<form class="a-filters" method="get" action="/admin/audit" data-auto-submit>
  <div class="a-field a-grow">
    <label class="a-field__label" for="search">Search</label>
    <input class="a-input" type="search" id="search" name="search"
           value="<?= View::e((string) ($filters['search'] ?? '')) ?>" placeholder="Description or actor">
  </div>
  <div class="a-field">
    <label class="a-field__label" for="action">Action</label>
    <select class="a-select" id="action" name="action">
      <option value="">All</option>
      <?php foreach ($actions as $action): ?>
        <option value="<?= View::e($action) ?>" <?= ($filters['action'] ?? '') === $action ? 'selected' : '' ?>>
          <?= View::e($action) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="a-field">
    <label class="a-field__label" for="severity">Severity</label>
    <select class="a-select" id="severity" name="severity">
      <option value="">All</option>
      <?php foreach (['info' => 'Info', 'notice' => 'Notice', 'warning' => 'Warning', 'critical' => 'Critical'] as $k => $v): ?>
        <option value="<?= View::e($k) ?>" <?= ($filters['severity'] ?? '') === $k ? 'selected' : '' ?>>
          <?= View::e($v) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="a-field">
    <label class="a-field__label" for="actor_type">Actor</label>
    <select class="a-select" id="actor_type" name="actor_type">
      <option value="">All</option>
      <?php foreach (['admin' => 'Admin', 'customer' => 'Customer', 'agent' => 'Print agent',
                      'system' => 'System', 'gateway' => 'Payment gateway'] as $k => $v): ?>
        <option value="<?= View::e($k) ?>" <?= ($filters['actor_type'] ?? '') === $k ? 'selected' : '' ?>>
          <?= View::e($v) ?>
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
  <a class="a-btn a-btn--ghost" href="/admin/audit">Reset</a>
</form>

<div class="a-card">
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr><th>When (UTC)</th><th>Severity</th><th>Action</th><th>Actor</th>
          <th>Description</th><th>Subject</th><th>IP</th></tr>
      </thead>
      <tbody>
        <?php if ($result['rows'] === []): ?>
          <tr><td colspan="7" class="a-table__empty">No audit entries match these filters.</td></tr>
        <?php else: ?>
          <?php foreach ($result['rows'] as $row): ?>
            <tr>
              <td data-label="When" class="a-small a-muted a-nowrap"><?= View::e((string) $row['created_at']) ?></td>
              <td data-label="Severity">
                <span class="a-badge a-badge--<?= match ((string) $row['severity']) {
                    'critical' => 'danger', 'warning' => 'warning', 'notice' => 'info', default => 'muted',
                } ?>"><?= View::e((string) $row['severity']) ?></span>
              </td>
              <td data-label="Action" class="a-small a-mono"><?= View::e((string) $row['action']) ?></td>
              <td data-label="Actor" class="a-small">
                <?= View::e((string) ($row['actor_label'] ?? $row['actor_type'])) ?>
                <div class="a-muted"><?= View::e((string) $row['actor_type']) ?></div>
              </td>
              <td data-label="Description"><?= View::e((string) ($row['description'] ?? '')) ?></td>
              <td data-label="Subject" class="a-small a-muted">
                <?= $row['subject_type'] !== null
                    ? View::e((string) $row['subject_type']) . ' #' . View::e((string) ($row['subject_id'] ?? ''))
                    : '—' ?>
              </td>
              <td data-label="IP" class="a-small a-mono a-muted"><?= View::e((string) ($row['ip_address'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?= $view->include('partials/pagination', [
      'result' => $result,
      'baseUrl' => '/admin/audit',
      'query' => http_build_query(array_filter($filters)),
  ]) ?>
</div>

<?php $view->end(); ?>
