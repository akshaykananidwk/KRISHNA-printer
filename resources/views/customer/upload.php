<?php
/**
 * The single-page customer flow: upload → configure → price → submit.
 *
 * Rendered server-side with all the printer's VERIFIED options; the JavaScript
 * only orchestrates. If scripting is unavailable the page still explains what
 * to do and the form degrades to a plain multi-file upload.
 *
 * @var App\Core\View $view
 * @var App\Models\Location $location
 * @var App\Models\Printer $printer
 * @var array<string,mixed> $options
 * @var array<int,array<string,mixed>> $priceList
 * @var array<int,array<string,mixed>> $existingFiles
 * @var int $maxFileBytes
 * @var int $maxFiles
 * @var array<int,string> $allowedExtensions
 * @var array<int,int> $copyPresets
 * @var int $maxCopies
 * @var bool $paymentEnabled
 */

use App\Core\View;

$view->extend('layouts/customer');
$view->start('content');

$accept = implode(',', array_map(static fn (string $e): string => '.' . $e, $allowedExtensions));
?>

<section class="c-hero">
  <p class="c-hero__eyebrow">Ready to print</p>
  <h1 class="c-hero__title"><?= View::e($location->string('name')) ?></h1>
  <?php if ($location->shortAddress() !== ''): ?>
    <p class="c-hero__sub"><?= View::e($location->shortAddress()) ?></p>
  <?php endif; ?>

  <div class="c-printer-chip" id="printerChip" data-printer-id="<?= (int) $printer->id() ?>">
    <span class="c-dot c-dot--ok" aria-hidden="true"></span>
    <span class="c-printer-chip__text">
      <strong><?= View::e($printer->string('name')) ?></strong> is online
    </span>
  </div>
</section>

<?php if ($priceList !== []): ?>
<details class="c-rates">
  <summary class="c-rates__summary">Printing rates</summary>
  <table class="c-rates__table">
    <thead><tr><th scope="col">Paper</th><th scope="col">Type</th><th scope="col">Per page</th></tr></thead>
    <tbody>
      <?php foreach ($priceList as $rate): ?>
        <tr>
          <td><?= View::e($rate['paper_label']) ?></td>
          <td><?= View::e($rate['color_label']) ?></td>
          <td class="c-rates__price"><?= View::e($rate['price']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</details>
<?php endif; ?>

<!-- Step 1 — upload ------------------------------------------------- -->
<section class="c-step" id="stepUpload" aria-labelledby="stepUploadTitle">
  <h2 class="c-step__title" id="stepUploadTitle"><span class="c-step__n">1</span> Choose your file</h2>

  <div class="c-drop" id="dropZone">
    <input type="file"
           id="fileInput"
           class="c-drop__input"
           name="files[]"
           accept="<?= View::e($accept) ?>"
           multiple
           aria-describedby="uploadHelp">
    <label for="fileInput" class="c-drop__label">
      <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.8"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
      </svg>
      <span class="c-drop__primary">Tap to choose a file</span>
      <span class="c-drop__secondary">or drag it here</span>
    </label>
  </div>

  <p class="c-help" id="uploadHelp">
    <?= View::e(strtoupper(implode(', ', $allowedExtensions))) ?> ·
    up to <?= (int) round($maxFileBytes / 1048576) ?> MB each ·
    up to <?= (int) $maxFiles ?> files
  </p>

  <div class="c-upload-progress" id="uploadProgress" hidden>
    <div class="c-upload-progress__bar"><span id="uploadBar"></span></div>
    <p class="c-upload-progress__text" id="uploadText">Uploading…</p>
  </div>

  <ul class="c-files" id="fileList" aria-live="polite"></ul>
</section>

<!-- Step 2 — options ------------------------------------------------- -->
<section class="c-step" id="stepOptions" aria-labelledby="stepOptionsTitle" hidden>
  <h2 class="c-step__title" id="stepOptionsTitle"><span class="c-step__n">2</span> Print settings</h2>
  <div id="optionCards"></div>
</section>

<!-- Step 3 — price --------------------------------------------------- -->
<section class="c-step" id="stepPrice" aria-labelledby="stepPriceTitle" hidden>
  <h2 class="c-step__title" id="stepPriceTitle"><span class="c-step__n">3</span> Price</h2>
  <div class="c-summary" id="priceSummary" aria-live="polite"></div>

  <button type="button" class="c-btn c-btn--primary c-btn--block" id="submitBtn" disabled>
    <?= $paymentEnabled ? 'Continue to payment' : 'Send to printer' ?>
  </button>
  <p class="c-help c-help--center" id="submitHelp">
    <?= $paymentEnabled
        ? 'You will see the exact amount before paying.'
        : 'Your document will be sent to the printer at this counter.' ?>
  </p>
</section>

<div class="c-error-banner" id="errorBanner" role="alert" hidden></div>

<?php
$view->end();
$view->start('scripts');
?>
<script nonce="<?= View::e($cspNonce) ?>">
window.KPMS = {
  printerId: <?= View::js($printer->id()) ?>,
  options: <?= View::js($options) ?>,
  copyPresets: <?= View::js($copyPresets) ?>,
  maxCopies: <?= View::js($maxCopies) ?>,
  maxFiles: <?= View::js($maxFiles) ?>,
  maxFileBytes: <?= View::js($maxFileBytes) ?>,
  allowedExtensions: <?= View::js($allowedExtensions) ?>,
  paymentEnabled: <?= View::js($paymentEnabled) ?>,
  currencySymbol: <?= View::js($currencySymbol) ?>,
  existingFiles: <?= View::js($existingFiles) ?>,
  csrfToken: <?= View::js(App\Core\Csrf::token()) ?>
};
</script>
<script src="/assets/js/customer.js" nonce="<?= View::e($cspNonce) ?>"></script>
<?php $view->end(); ?>
