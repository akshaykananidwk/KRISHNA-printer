<?php
/**
 * Checkout. The gateway widget runs here, but nothing it reports is trusted:
 * its response is posted to /print/payment/verify, which re-checks the
 * signature and re-reads the payment from the gateway API before any job moves.
 *
 * @var App\Core\View $view
 * @var string $batchId
 * @var array<int,array<string,mixed>> $jobs
 * @var string $totalDisplay
 * @var string $orderId
 * @var array<string,mixed> $checkout
 * @var string $checkoutScript
 * @var string $locationName
 */

use App\Core\View;
use App\Support\Money;

$view->extend('layouts/customer');
$view->start('content');
?>

<section class="c-pay">
  <h1 class="c-pay__title">Confirm and pay</h1>
  <p class="c-pay__sub"><?= View::e($locationName) ?></p>

  <ul class="c-pay__lines">
    <?php foreach ($jobs as $job): ?>
      <li class="c-pay__line">
        <div class="c-pay__line-main">
          <span class="c-pay__line-name"><?= View::e((string) ($job['file_name'] ?? 'Document')) ?></span>
          <span class="c-pay__line-meta">
            <?= (int) $job['selected_pages'] ?> × <?= (int) $job['copies'] ?>
            · <?= (string) $job['color_mode'] === 'color' ? 'Colour' : 'B&amp;W' ?>
            · <?= View::e((string) $job['paper_size']) ?>
          </span>
        </div>
        <span class="c-pay__line-amount"><?= View::e(Money::formatWithSymbol((int) $job['total_paise'])) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>

  <div class="c-pay__total">
    <span>Total to pay</span>
    <strong><?= View::e($totalDisplay) ?></strong>
  </div>

  <button type="button" class="c-btn c-btn--primary c-btn--block c-btn--lg" id="payBtn">
    Pay <?= View::e($totalDisplay) ?>
  </button>

  <p class="c-help c-help--center">
    Your document is printed only after the payment is confirmed with the bank.
  </p>

  <div class="c-error-banner" id="payError" role="alert" hidden></div>

  <div class="c-pay__verifying" id="verifying" hidden>
    <span class="c-spinner" aria-hidden="true"></span>
    <p>Confirming your payment — please do not close this page or pay again.</p>
  </div>
</section>

<?php
$view->end();
$view->start('scripts');
?>
<script src="<?= View::e($checkoutScript) ?>" nonce="<?= View::e($cspNonce) ?>"></script>
<script nonce="<?= View::e($cspNonce) ?>">
(function () {
  var config = <?= View::js($checkout) ?>;
  var batchId = <?= View::js($batchId) ?>;
  var csrf = <?= View::js(App\Core\Csrf::token()) ?>;

  var payBtn = document.getElementById('payBtn');
  var errorBox = document.getElementById('payError');
  var verifying = document.getElementById('verifying');

  function showError(message) {
    errorBox.textContent = message;
    errorBox.hidden = false;
    payBtn.disabled = false;
    verifying.hidden = true;
  }

  function verify(response) {
    verifying.hidden = false;
    errorBox.hidden = true;

    var body = new FormData();
    body.append('razorpay_order_id', response.razorpay_order_id || '');
    body.append('razorpay_payment_id', response.razorpay_payment_id || '');
    body.append('razorpay_signature', response.razorpay_signature || '');
    body.append('_csrf_token', csrf);

    fetch('/print/payment/verify', {
      method: 'POST',
      body: body,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success && data.redirect) {
          window.location.href = data.redirect;
        } else {
          showError(data.error || 'We could not confirm the payment. Please ask staff for help.');
        }
      })
      .catch(function () {
        showError('We could not reach the server to confirm your payment. Please ask staff for help before paying again.');
      });
  }

  payBtn.addEventListener('click', function () {
    if (typeof Razorpay === 'undefined') {
      showError('The payment window could not load. Check your connection and try again.');
      return;
    }

    payBtn.disabled = true;
    errorBox.hidden = true;

    var checkout = new Razorpay(Object.assign({}, config, {
      handler: verify,
      modal: {
        ondismiss: function () {
          payBtn.disabled = false;
          var body = new FormData();
          body.append('batch_id', batchId);
          body.append('_csrf_token', csrf);
          fetch('/print/payment/cancelled', {
            method: 'POST',
            body: body,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf }
          }).catch(function () {});
          showError('Payment was not completed. Your order is still open — tap Pay to try again.');
        }
      }
    }));

    checkout.on('payment.failed', function (event) {
      var description = (event && event.error && event.error.description) || 'The payment did not go through.';
      showError(description + ' Nothing has been printed and you have not been charged.');
    });

    checkout.open();
  });
})();
</script>
<?php $view->end(); ?>
