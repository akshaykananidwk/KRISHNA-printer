<?php
/** @var App\Core\View $view @var array|null $rule @var array $locations @var array $printers @var array $paperSizes */

use App\Core\Csrf;
use App\Core\View;

$view->extend('layouts/admin');
$view->start('content');

$isEdit = $rule !== null;
$v = static fn (string $key, string $default = ''): string =>
    $rule === null ? $default : (string) ($rule[$key] ?? $default);
$money = static fn (string $key): string =>
    $rule === null ? '' : number_format(((int) ($rule[$key] ?? 0)) / 100, 2, '.', '');
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1><?= $isEdit ? 'Edit pricing rule' : 'Add pricing rule' ?></h1>
    <p>Leave a field blank to mean "any" — a narrower rule always wins over a broader one.</p>
  </div>
</div>

<form method="post" action="<?= View::e($formAction) ?>">
  <?= Csrf::field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="_method" value="PUT"><?php endif; ?>

  <div class="a-card">
    <h2 class="a-card__title">What this rule matches</h2>
    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="name">Rule name</label>
        <input class="a-input" id="name" name="name" required maxlength="120"
               value="<?= View::e($v('name')) ?>" placeholder="A4 B&amp;W standard">
      </div>

      <div class="a-field">
        <label class="a-field__label" for="location_id">Location</label>
        <select class="a-select" id="location_id" name="location_id">
          <option value="">All locations</option>
          <?php foreach ($locations as $loc): ?>
            <option value="<?= $loc->id() ?>" <?= $v('location_id') === (string) $loc->id() ? 'selected' : '' ?>>
              <?= View::e($loc->string('name')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="printer_id">Printer</label>
        <select class="a-select" id="printer_id" name="printer_id">
          <option value="">All printers</option>
          <?php foreach ($printers as $p): ?>
            <option value="<?= $p->id() ?>" <?= $v('printer_id') === (string) $p->id() ? 'selected' : '' ?>>
              <?= View::e($p->string('name')) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="a-field__hint">A printer-scoped rule must be at the selected location.</p>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="paper_size">Paper size</label>
        <select class="a-select" id="paper_size" name="paper_size">
          <option value="">Any</option>
          <?php foreach ($paperSizes as $size => $meta): ?>
            <option value="<?= View::e($size) ?>" <?= $v('paper_size') === $size ? 'selected' : '' ?>>
              <?= View::e((string) $meta['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="color_mode">Colour mode</label>
        <select class="a-select" id="color_mode" name="color_mode">
          <option value="">Any</option>
          <option value="bw" <?= $v('color_mode') === 'bw' ? 'selected' : '' ?>>Black &amp; white</option>
          <option value="color" <?= $v('color_mode') === 'color' ? 'selected' : '' ?>>Colour</option>
        </select>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="duplex">Sides</label>
        <select class="a-select" id="duplex" name="duplex">
          <option value="">Any</option>
          <option value="single" <?= $v('duplex') === 'single' ? 'selected' : '' ?>>Single side</option>
          <option value="double" <?= $v('duplex') === 'double' ? 'selected' : '' ?>>Double side</option>
        </select>
      </div>
    </div>
  </div>

  <div class="a-card">
    <h2 class="a-card__title">Price</h2>
    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="price_per_page">Price per page (<?= View::e($currencySymbol) ?>)</label>
        <input class="a-input" id="price_per_page" name="price_per_page" type="number"
               step="0.01" min="0" max="10000" required
               value="<?= $isEdit ? $money('price_per_page_paise') : '' ?>">
      </div>

      <div class="a-field">
        <label class="a-field__label" for="setup_fee">Per-order handling fee (<?= View::e($currencySymbol) ?>)</label>
        <input class="a-input" id="setup_fee" name="setup_fee" type="number" step="0.01" min="0"
               value="<?= $isEdit ? $money('setup_fee_paise') : '0.00' ?>">
        <p class="a-field__hint">Charged once per order, not once per file.</p>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="minimum_charge">Minimum charge (<?= View::e($currencySymbol) ?>)</label>
        <input class="a-input" id="minimum_charge" name="minimum_charge" type="number" step="0.01" min="0"
               value="<?= $isEdit ? $money('minimum_charge_paise') : '0.00' ?>">
      </div>

      <div class="a-field">
        <label class="a-field__label" for="min_pages">Applies from (pages)</label>
        <input class="a-input" id="min_pages" name="min_pages" type="number" min="1"
               value="<?= View::e($v('min_pages', '1')) ?>">
        <p class="a-field__hint">Use with the field beside it for volume tiers.</p>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="max_pages">Applies up to (pages)</label>
        <input class="a-input" id="max_pages" name="max_pages" type="number" min="1"
               value="<?= View::e($v('max_pages')) ?>" placeholder="No limit">
      </div>

      <div class="a-field">
        <label class="a-field__label" for="priority">Priority</label>
        <input class="a-input" id="priority" name="priority" type="number" min="-1000" max="1000"
               value="<?= View::e($v('priority', '0')) ?>">
        <p class="a-field__hint">Breaks ties between rules of equal specificity. Higher wins.</p>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="valid_from">Valid from</label>
        <input class="a-input" id="valid_from" name="valid_from" type="date"
               value="<?= View::e(substr($v('valid_from'), 0, 10)) ?>">
      </div>

      <div class="a-field">
        <label class="a-field__label" for="valid_until">Valid until</label>
        <input class="a-input" id="valid_until" name="valid_until" type="date"
               value="<?= View::e(substr($v('valid_until'), 0, 10)) ?>">
      </div>
    </div>

    <label class="a-check">
      <input type="checkbox" name="is_active" value="1" <?= !$isEdit || $v('is_active') === '1' ? 'checked' : '' ?>>
      <span>Active</span>
    </label>
  </div>

  <div class="a-row">
    <button type="submit" class="a-btn a-btn--primary"><?= $isEdit ? 'Save rule' : 'Add rule' ?></button>
    <a class="a-btn a-btn--ghost" href="/admin/pricing">Cancel</a>
  </div>
</form>

<?php $view->end(); ?>
