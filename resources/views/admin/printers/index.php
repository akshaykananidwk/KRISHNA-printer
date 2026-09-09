<?php
/** @var App\Core\View $view @var array $result @var array $locations */

use App\Core\View;

$view->extend('layouts/admin');
$view->start('content');
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>Printers</h1>
    <p><?= number_format((int) $result['total']) ?> printer(s) across all locations.</p>
  </div>
  <div class="a-page-head__actions">
    <a class="a-btn a-btn--primary" href="/admin/printers/create">Add printer</a>
  </div>
</div>

<form class="a-filters" method="get" action="/admin/printers" data-auto-submit>
  <div class="a-field a-grow">
    <label class="a-field__label" for="q">Search</label>
    <input class="a-input" type="search" id="q" name="q" value="<?= View::e($search) ?>"
           placeholder="Name, code or model">
  </div>
  <div class="a-field">
    <label class="a-field__label" for="location_id">Location</label>
    <select class="a-select" id="location_id" name="location_id">
      <option value="">All locations</option>
      <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc->id() ?>" <?= $locationId === $loc->id() ? 'selected' : '' ?>>
          <?= View::e($loc->string('name')) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="a-field">
    <label class="a-field__label" for="status">Status</label>
    <select class="a-select" id="status" name="status">
      <option value="">Any</option>
      <?php foreach (['online' => 'Online', 'offline' => 'Offline', 'error' => 'Error',
                      'unknown' => 'Unknown', 'maintenance' => 'Maintenance'] as $key => $label): ?>
        <option value="<?= View::e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= View::e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="a-btn a-btn--ghost">Filter</button>
</form>

<div class="a-card">
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr>
          <th>Printer</th><th>Location</th><th>Transport</th><th>Status</th>
          <th>Capabilities</th><th class="a-table__num">Queue</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($result['rows'] === []): ?>
          <tr><td colspan="7" class="a-table__empty">
            No printers yet. <a href="/admin/printers/create">Add one</a>.
          </td></tr>
        <?php else: ?>
          <?php foreach ($result['rows'] as $row):
              $tone = match ((string) $row['status']) {
                  'online' => 'success',
                  'offline', 'error' => 'danger',
                  'maintenance' => 'warning',
                  default => 'muted',
              }; ?>
            <tr>
              <td data-label="Printer">
                <a href="/admin/printers/<?= (int) $row['id'] ?>"><?= View::e((string) $row['name']) ?></a>
                <?php if ((int) $row['is_default'] === 1): ?>
                  <span class="a-badge a-badge--info">Default</span>
                <?php endif; ?>
                <?php if ((int) $row['is_enabled'] === 0): ?>
                  <span class="a-badge a-badge--muted">Disabled</span>
                <?php endif; ?>
                <div class="a-small a-muted"><?= View::e((string) ($row['model'] ?? '')) ?></div>
              </td>
              <td data-label="Location"><?= View::e((string) $row['location_name']) ?></td>
              <td data-label="Transport" class="a-small">
                <?= View::e(strtoupper((string) ($row['primary_driver'] ?? '—'))) ?>
                <?php if ((string) ($row['primary_driver'] ?? '') === 'agent' && $row['device_name'] !== null): ?>
                  <div class="a-muted"><?= View::e((string) $row['device_name']) ?>
                    <?= (string) $row['device_status'] === 'online' ? '' : ' (offline)' ?></div>
                <?php endif; ?>
              </td>
              <td data-label="Status">
                <span class="a-badge a-badge--<?= $tone ?>"><?= View::e(ucfirst((string) $row['status'])) ?></span>
              </td>
              <td data-label="Capabilities">
                <?php if ($row['capabilities_verified_at'] !== null): ?>
                  <span class="a-badge a-badge--success">Verified</span>
                <?php else: ?>
                  <span class="a-badge a-badge--warning">Not verified</span>
                <?php endif; ?>
              </td>
              <td data-label="Queue" class="a-table__num"><?= (int) $row['queue_depth'] ?></td>
              <td data-label="">
                <a class="a-btn a-btn--ghost a-btn--sm" href="/admin/printers/<?= (int) $row['id'] ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?= $view->include('partials/pagination', [
      'result' => $result,
      'baseUrl' => '/admin/printers',
      'query' => http_build_query(array_filter([
          'q' => $search, 'status' => $status, 'location_id' => $locationId ?: '',
      ])),
  ]) ?>
</div>

<?php $view->end(); ?>
