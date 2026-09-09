<?php
/** @var App\Core\View $view */
use App\Core\View;

$view->extend('layouts/customer');
$view->start('content');
?>

<div class="c-state">
  <div class="c-state__icon" aria-hidden="true">
    <svg viewBox="0 0 48 48" width="72" height="72" fill="none" stroke="currentColor" stroke-width="2.2"
         stroke-linecap="round" stroke-linejoin="round">
      <circle cx="24" cy="24" r="19"/><line x1="24" y1="15" x2="24" y2="26"/><circle cx="24" cy="33" r="1.6" fill="currentColor"/>
    </svg>
  </div>

  <h1 class="c-state__title">This QR code is not recognised</h1>
  <p class="c-state__message">
    The link may have expired, or the code may have been replaced. Please scan the QR code
    displayed at the counter, or ask staff for help.
  </p>
</div>

<?php $view->end(); ?>
