<?php
/** @var App\Core\View $view @var array $result @var array $locations @var array $matrix @var array $paperSizes */

use App\Core\Csrf;
use App\Core\View;
use App\Support\Money;

$view->extend('layouts/admin');
$view->start('content');
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>Pricing</h1>
    <p>Rules resolve most-specific-first: printer, then location, then the global default.</p>
  </div>
  <div class="a-page-head__actions">
    <a class="a-btn a-btn--primary" href="/admin/pricing/create">Add rule</a>
  </div>
</div>

<form class="a-filters" method="get" action="/admin/pricing" data-auto-submit>
  <div class="a-field a-grow">
    <label class="a-field__label" for="location_id">Show prices for</label>
    <select class="a-select" id="location_id" name="location_id">
      <option value="">All locations (global rules)</option>
      <?php foreach ($locations as $loc): ?>
        <option value="<?= $loc->id() ?>" <?= $locationId === $loc->id() ? 'selected' : '' ?>>
          <?= View::e($loc->string('name')) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="a-btn a-btn--ghost">Apply</button>
</form>

<section class="a-card">
  <h2 class="a-card__title">Price grid<?= $locationId > 0 ? ' for this location' : ' (global)' ?></h2>
  <p class="a-small a-muted" style="margin-top:-.5rem">
    Set the per-page price for each combination. Blank leaves the existing rule untouched; these
    are the prices a customer actually sees.
  </p>

  <form method="post" action="/admin/pricing/bulk">
    <?= Csrf::field() ?>
    <input type="hidden" name="location_id" value="<?= $locationId ?>">

    <div class="a-table-wrap">
      <table class="a-table">
        <thead>
          <tr>
            <th>Paper size</th>
            <th class="a-table__num">Black &amp; white (per page)</th>
            <th class="a-table__num">Colour (per page)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($paperSizes as $size => $meta): ?>
            <tr>
              <td>
                <strong><?= View::e((string) $meta['label']) ?></strong>
                <div class="a-small a-muted">
                  <?= (float) $meta['width_mm'] ?> × <?= (float) $meta['height_mm'] ?> mm
                </div>
              </td>
              <?php foreach (['bw', 'color'] as $mode):
                  $paise = $matrix[$size][$mode] ?? null; ?>
                <td class="a-table__num">
                  <input class="a-input" type="number" step="0.01" min="0" max="10000"
                         style="max-width:9rem;margin-left:auto"
                         name="prices[<?= View::e($size . '_' . $mode) ?>]"
                         value="<?= $paise === null ? '' : number_format($paise / 100, 2, '.', '') ?>"
                         placeholder="not set"
                         aria-label="<?= View::e($size . ' ' . ($mode === 'color' ? 'colour' : 'black and white')) ?> price per page">
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <button type="submit" class="a-btn a-btn--primary" style="margin-top:.9rem">Save the grid</button>
  </form>
</section>

<section class="a-card">
  <h2 class="a-card__title">Test the calculator</h2>
  <p class="a-small a-muted" style="margin-top:-.5rem">
    Runs the real pricing engine, so what you see here is exactly what a customer would be charged.
  </p>

  <form id="pricePreviewForm" class="a-form-grid">
    <div class="a-field">
      <label class="a-field__label" for="pv_location">Location</label>
      <select class="a-select" id="pv_location" name="location_id" required>
        <?php foreach ($locations as $loc): ?>
          <option value="<?= $loc->id() ?>" <?= $locationId === $loc->id() ? 'selected' : '' ?>>
            <?= View::e($loc->string('name')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="a-field">
      <label class="a-field__label" for="pv_paper">Paper</label>
      <select class="a-select" id="pv_paper" name="paper_size">
        <?php foreach ($paperSizes as $size => $meta): ?>
          <option value="<?= View::e($size) ?>"><?= View::e((string) $meta['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="a-field">
      <label class="a-field__label" for="pv_color">Colour</label>
      <select class="a-select" id="pv_color" name="color_mode">
        <option value="bw">Black &amp; white</option>
        <option value="color">Colour</option>
      </select>
    </div>
    <div class="a-field">
      <label class="a-field__label" for="pv_duplex">Sides</label>
      <select class="a-select" id="pv_duplex" name="duplex">
        <option value="single">Single</option>
        <option value="double">Double</option>
      </select>
    </div>
    <div class="a-field">
      <label class="a-field__label" for="pv_pages">Document pages</label>
      <input class="a-input" type="number" id="pv_pages" name="document_pages" min="1" max="5000" value="20">
    </div>
    <div class="a-field">
      <label class="a-field__label" for="pv_copies">Copies</label>
      <input class="a-input" type="number" id="pv_copies" name="copies" min="1" max="999" value="3">
    </div>
    <div class="a-field">
      <label class="a-field__label" for="pv_range">Page range</label>
      <input class="a-input" id="pv_range" name="page_range" value="all" placeholder="all or 1-5,8-10">
    </div>
    <div class="a-field" style="display:flex;align-items:flex-end">
      <button type="submit" class="a-btn a-btn--ghost">Calculate</button>
    </div>
  </form>

  <div id="pricePreviewResult" style="margin-top:.9rem"></div>
</section>

<section class="a-card">
  <h2 class="a-card__title">All rules</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr>
          <th>Rule</th><th>Scope</th><th>Match</th><th class="a-table__num">Per page</th>
          <th class="a-table__num">Fees</th><th>Active</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($result['rows'] === []): ?>
          <tr><td colspan="7" class="a-table__empty">
            No pricing rules yet. Without at least one, no customer can be charged and no job can be
            created.
          </td></tr>
        <?php else: ?>
          <?php foreach ($result['rows'] as $rule): ?>
            <tr>
              <td data-label="Rule"><?= View::e((string) $rule['name']) ?></td>
              <td data-label="Scope">
                <?php if ($rule['printer_id'] !== null): ?>
                  <span class="a-badge a-badge--info"><?= View::e((string) ($rule['printer_name'] ?? 'Printer')) ?></span>
                <?php elseif ($rule['location_id'] !== null): ?>
                  <span class="a-badge a-badge--success"><?= View::e((string) ($rule['location_name'] ?? 'Location')) ?></span>
                <?php else: ?>
                  <span class="a-badge a-badge--muted">Global</span>
                <?php endif; ?>
              </td>
              <td data-label="Match" class="a-small">
                <?= View::e((string) ($rule['paper_size'] ?? 'Any paper')) ?> ·
                <?= $rule['color_mode'] === null ? 'Any mode'
                    : ($rule['color_mode'] === 'color' ? 'Colour' : 'B&amp;W') ?> ·
                <?= $rule['duplex'] === null ? 'Any sides' : ucfirst((string) $rule['duplex']) ?>
                <?php if ((int) $rule['min_pages'] > 1 || $rule['max_pages'] !== null): ?>
                  <div class="a-muted">
                    <?= (int) $rule['min_pages'] ?>–<?= $rule['max_pages'] === null ? '∞' : (int) $rule['max_pages'] ?> pages
                  </div>
                <?php endif; ?>
              </td>
              <td data-label="Per page" class="a-table__num">
                <?= View::e(Money::formatWithSymbol((int) $rule['price_per_page_paise'])) ?>
              </td>
              <td data-label="Fees" class="a-table__num a-small">
                <?php if ((int) $rule['setup_fee_paise'] > 0): ?>
                  Setup <?= View::e(Money::formatWithSymbol((int) $rule['setup_fee_paise'])) ?><br>
                <?php endif; ?>
                <?php if ((int) $rule['minimum_charge_paise'] > 0): ?>
                  Min <?= View::e(Money::formatWithSymbol((int) $rule['minimum_charge_paise'])) ?>
                <?php endif; ?>
                <?= (int) $rule['setup_fee_paise'] === 0 && (int) $rule['minimum_charge_paise'] === 0 ? '—' : '' ?>
              </td>
              <td data-label="Active">
                <span class="a-badge a-badge--<?= (int) $rule['is_active'] === 1 ? 'success' : 'muted' ?>">
                  <?= (int) $rule['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                </span>
              </td>
              <td data-label="">
                <a class="a-btn a-btn--ghost a-btn--sm" href="/admin/pricing/<?= (int) $rule['id'] ?>/edit">Edit</a>
                <form class="a-inline-form" method="post" action="/admin/pricing/<?= (int) $rule['id'] ?>">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="_method" value="DELETE">
                  <button type="submit" class="a-btn a-btn--ghost a-btn--sm"
                          data-confirm="Delete this pricing rule? Combinations it covered may become unsellable.">
                    Delete
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?= $view->include('partials/pagination', [
      'result' => $result,
      'baseUrl' => '/admin/pricing',
      'query' => $locationId > 0 ? 'location_id=' . $locationId : '',
  ]) ?>
</section>

<?php $view->end(); ?>
