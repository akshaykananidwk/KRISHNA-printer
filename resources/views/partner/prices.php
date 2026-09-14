<?php
/**
 * The shop's own price list.
 *
 * One price per paper size and colour mode, which is how a shop actually
 * thinks about it. Volume tiers, per-printer rates and duplex conditions are
 * deliberately not here: those are made by an operator who meant them, and
 * this page neither shows nor overwrites them.
 *
 * @var App\Core\View $view
 * @var App\Models\Partner $partner
 * @var array<string,array<string,mixed>> $paperSizes
 * @var array<string,array<string,mixed>> $colorModes
 * @var array<string,array<string,mixed>> $rules
 * @var array<string,int> $fallback
 * @var string $currencySymbol
 */

use App\Core\Csrf;
use App\Core\View;

$price = static function (string $size, string $mode) use ($rules): string {
    $rule = $rules[$size . ':' . $mode] ?? null;
    return $rule === null ? '' : number_format(((int) $rule['price_per_page_paise']) / 100, 2, '.', '');
};

$standard = static function (string $size, string $mode) use ($fallback, $currencySymbol): string {
    foreach ([$size . ':' . $mode, $size . ':*', '*:' . $mode, '*:*'] as $key) {
        if (isset($fallback[$key])) {
            return $currencySymbol . number_format($fallback[$key] / 100, 2);
        }
    }
    return 'not set';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Your prices · <?= View::e($appName) ?></title>
<link rel="stylesheet" href="/assets/css/admin.css">
<style nonce="<?= View::e($cspNonce) ?>">
  .p-wrap { max-width: 46rem; margin: 0 auto; padding: 1.5rem 1rem 4rem; }
  .p-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;
            flex-wrap: wrap; margin-bottom: 1.2rem; }
  .p-grid { width: 100%; border-collapse: collapse; }
  .p-grid th { text-align: left; font-size: .82rem; color: var(--ink-faint);
               padding: .5rem .6rem; border-bottom: 1px solid var(--line); font-weight: 600; }
  .p-grid td { padding: .55rem .6rem; border-bottom: 1px solid var(--line); vertical-align: middle; }
  .p-grid td.size { font-weight: 600; white-space: nowrap; }
  .p-grid input { width: 6.5rem; }
  .p-std { display: block; font-size: .75rem; color: var(--ink-faint); margin-top: .15rem; }
  .p-scroll { overflow-x: auto; }
</style>
</head>
<body class="a-body">
<div class="p-wrap">

  <div class="p-head">
    <div>
      <h1 style="margin:0">Your prices</h1>
      <p class="a-small" style="color:var(--ink-faint);margin:.2rem 0 0">
        <?= View::e($partner->string('shop_name')) ?>
      </p>
    </div>
    <a class="a-btn" href="/partner">Back</a>
  </div>

  <?php foreach ($flashes as $flash): ?>
    <div class="a-flash a-flash--<?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div>
  <?php endforeach; ?>

  <div class="a-alert a-alert--info a-small">
    <strong>What a customer pays, per page.</strong>
    Leave a box empty to use the standard rate shown under it. Tax, if any, is added on top —
    the customer sees the whole breakdown before paying. Changes apply to the next order, never
    to one already placed.
  </div>

  <form method="post" action="/partner/prices">
    <?= Csrf::field() ?>

    <div class="a-card">
      <div class="p-scroll">
        <table class="p-grid">
          <thead>
            <tr>
              <th>Paper</th>
              <?php foreach ($colorModes as $modeKey => $mode): ?>
                <th><?= View::e((string) $mode['label']) ?> (<?= View::e($currencySymbol) ?> per page)</th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($paperSizes as $sizeKey => $size): ?>
            <tr>
              <td class="size">
                <?= View::e((string) $size['label']) ?>
                <span class="p-std"><?= (int) $size['width_mm'] ?>&times;<?= (int) $size['height_mm'] ?> mm</span>
              </td>
              <?php foreach ($colorModes as $modeKey => $mode): ?>
                <td>
                  <input class="a-input" type="text" inputmode="decimal"
                         id="price_<?= View::e($sizeKey) ?>_<?= View::e($modeKey) ?>"
                         name="price_<?= View::e($sizeKey) ?>_<?= View::e($modeKey) ?>"
                         value="<?= View::e($price($sizeKey, $modeKey)) ?>"
                         placeholder="—"
                         aria-label="<?= View::e((string) $size['label']) ?> <?= View::e((string) $mode['label']) ?> price per page">
                  <span class="p-std">standard: <?= View::e($standard($sizeKey, $modeKey)) ?></span>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <button type="submit" class="a-btn a-btn--primary" style="margin-top:1.2rem">Save prices</button>
    </div>
  </form>

  <div class="a-card">
    <h2 class="a-card__title">A customer is only offered what your printer can do</h2>
    <p class="a-small" style="color:var(--ink-faint)">
      Setting a price for A3 does not make an A4 printer take A3 orders. Sizes and colour appear to
      customers only once your printer has been checked and found to support them — so a price you
      set here for something your machine cannot do simply never gets used.
    </p>
  </div>

</div>
</body>
</html>
