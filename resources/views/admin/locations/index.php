<?php
/** @var App\Core\View $view @var array $result @var string $search @var string $status */

use App\Core\View;

$view->extend('layouts/admin');
$view->start('content');
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>Locations</h1>
    <p><?= number_format((int) $result['total']) ?> location(s). Each has its own QR code.</p>
  </div>
  <div class="a-page-head__actions">
    <a class="a-btn a-btn--primary" href="/admin/locations/create">Add location</a>
  </div>
</div>

<form class="a-filters" method="get" action="/admin/locations" data-auto-submit>
  <div class="a-field a-grow">
    <label class="a-field__label" for="q">Search</label>
    <input class="a-input" type="search" id="q" name="q" value="<?= View::e($search) ?>"
           placeholder="Name, code, city or phone">
  </div>
  <div class="a-field">
    <label class="a-field__label" for="status">Status</label>
    <select class="a-select" id="status" name="status">
      <option value="">All</option>
      <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'maintenance' => 'Maintenance'] as $value => $label): ?>
        <option value="<?= View::e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
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
          <th>Location</th><th>Code</th><th>City</th>
          <th class="a-table__num">Printers</th><th class="a-table__num">Jobs today</th>
          <th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($result['rows'] === []): ?>
          <tr><td colspan="7" class="a-table__empty">
            No locations yet. <a href="/admin/locations/create">Add your first one</a>.
          </td></tr>
        <?php else: ?>
          <?php foreach ($result['rows'] as $row): ?>
            <tr>
              <td data-label="Location">
                <a href="/admin/locations/<?= (int) $row['id'] ?>"><?= View::e((string) $row['name']) ?></a>
              </td>
              <td data-label="Code" class="a-mono a-small"><?= View::e((string) $row['code']) ?></td>
              <td data-label="City"><?= View::e((string) ($row['city'] ?? '—')) ?></td>
              <td data-label="Printers" class="a-table__num">
                <?php
                $online = (int) $row['printers_online'];
                $total = (int) $row['printer_count'];
                $tone = $total === 0 ? 'muted' : ($online === $total ? 'success' : ($online === 0 ? 'danger' : 'warning'));
                ?>
                <span class="a-badge a-badge--<?= $tone ?>"><?= $online ?>/<?= $total ?> online</span>
              </td>
              <td data-label="Jobs today" class="a-table__num"><?= number_format((int) $row['jobs_today']) ?></td>
              <td data-label="Status">
                <span class="a-badge a-badge--<?= (string) $row['status'] === 'active' ? 'success' : 'muted' ?>">
                  <?= View::e(ucfirst((string) $row['status'])) ?>
                </span>
              </td>
              <td data-label="">
                <a class="a-btn a-btn--ghost a-btn--sm" href="/admin/locations/<?= (int) $row['id'] ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?= $view->include('partials/pagination', [
      'result' => $result,
      'baseUrl' => '/admin/locations',
      'query' => http_build_query(array_filter(['q' => $search, 'status' => $status])),
  ]) ?>
</div>

<?php $view->end(); ?>
