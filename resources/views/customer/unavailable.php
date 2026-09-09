<?php
/**
 * Shown whenever the printer cannot take a job. This is the screen the
 * requirement calls for: no upload control is rendered at all, so there is
 * nothing to submit even with the developer tools open.
 *
 * @var App\Core\View $view
 * @var string $heading
 * @var string $message
 * @var bool $canRetry
 * @var string $retryUrl
 * @var App\Models\Location|null $location
 * @var App\Models\Printer|null $printer
 */

use App\Core\View;

$view->extend('layouts/customer');
$view->start('content');
?>

<div class="c-state c-state--blocked">
  <div class="c-state__icon" aria-hidden="true">
    <svg viewBox="0 0 48 48" width="72" height="72" fill="none" stroke="currentColor" stroke-width="2.2"
         stroke-linecap="round" stroke-linejoin="round">
      <rect x="10" y="17" width="28" height="18" rx="3"/>
      <path d="M15 17V9h18v8M15 35v5h18v-5"/>
      <line x1="8" y1="8" x2="40" y2="40"/>
    </svg>
  </div>

  <h1 class="c-state__title"><?= View::e($heading ?? 'Printing Service Currently Unavailable') ?></h1>
  <p class="c-state__message"><?= View::e($message ?? 'Please try again later.') ?></p>

  <?php if (!empty($location)): ?>
    <div class="c-state__where">
      <span class="c-state__where-label">Location</span>
      <strong><?= View::e($location->string('name')) ?></strong>
      <?php if ($location->shortAddress() !== ''): ?>
        <span class="c-state__where-address"><?= View::e($location->shortAddress()) ?></span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($canRetry) && !empty($retryUrl)): ?>
    <a class="c-btn c-btn--primary c-state__action" href="<?= View::e($retryUrl) ?>">Check again</a>
    <p class="c-state__note">This page checks the printer live each time you open it.</p>
  <?php endif; ?>
</div>

<?php
$view->end();
$view->start('scripts');
?>
<?php if (!empty($canRetry) && !empty($retryUrl)): ?>
<script nonce="<?= View::e($cspNonce) ?>">
// Re-check automatically so a customer standing at the counter sees the
// service come back without having to think about refreshing.
(function () {
  var attempts = 0;
  var timer = setInterval(function () {
    if (++attempts > 20) { clearInterval(timer); return; }
    fetch(window.location.pathname, { method: 'HEAD', cache: 'no-store' })
      .then(function (response) {
        if (response.status === 200) { window.location.reload(); }
      })
      .catch(function () { /* offline; keep waiting */ });
  }, 15000);
})();
</script>
<?php endif; ?>
<?php $view->end(); ?>
