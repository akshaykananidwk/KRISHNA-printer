<?php
/** @var App\Core\View $view @var array $settings @var array $secretsSet @var bool $paymentConfigured @var array $timezones */

use App\Core\Config;
use App\Core\Csrf;
use App\Core\View;

$view->extend('layouts/admin');
$view->start('content');

$s = static fn (string $key, string $default = ''): string => (string) ($settings[$key] ?? $default);
$on = static fn (string $key, bool $default = false): bool =>
    isset($settings[$key]) ? (bool) $settings[$key] : $default;
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>Settings</h1>
    <p>Stored secrets are never shown again — leave a secret field blank to keep the stored value.</p>
  </div>
</div>

<section class="a-card">
  <h2 class="a-card__title">General</h2>
  <form method="post" action="/admin/settings/general">
    <?= Csrf::field() ?>
    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="business_name">Business name</label>
        <input class="a-input" id="business_name" name="business_name" required maxlength="120"
               value="<?= View::e($s('business_name', (string) Config::get('app.name'))) ?>">
        <p class="a-field__hint">Shown to customers and on QR posters.</p>
      </div>
      <div class="a-field">
        <label class="a-field__label" for="app_url">Site address</label>
        <input class="a-input" id="app_url" name="app_url" type="url" maxlength="190"
               value="<?= View::e($s('app_url')) ?>" placeholder="https://print.example.com">
        <p class="a-field__hint">Used to build QR links. Change it and existing QR codes stop matching.</p>
      </div>
      <div class="a-field">
        <label class="a-field__label" for="support_phone">Support phone</label>
        <input class="a-input" id="support_phone" name="support_phone" type="tel" maxlength="32"
               value="<?= View::e($s('support_phone')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="support_email">Support email</label>
        <input class="a-input" id="support_email" name="support_email" type="email" maxlength="190"
               value="<?= View::e($s('support_email')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="job_number_prefix">Job number prefix</label>
        <input class="a-input a-mono" id="job_number_prefix" name="job_number_prefix" maxlength="4"
               pattern="[A-Za-z]{1,4}" value="<?= View::e($s('job_number_prefix', 'AK')) ?>">
        <p class="a-field__hint">Letters only, e.g. AK gives job numbers like AK102548.</p>
      </div>
      <div class="a-field">
        <label class="a-field__label" for="brand_color">Brand colour</label>
        <input class="a-input" id="brand_color" name="brand_color" type="color"
               value="<?= View::e($s('brand_color', '#1e6f5c')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="timezone">Timezone</label>
        <select class="a-select" id="timezone" name="timezone">
          <?php foreach ($timezones as $tz): ?>
            <option value="<?= View::e($tz) ?>" <?= $s('timezone', 'Asia/Kolkata') === $tz ? 'selected' : '' ?>>
              <?= View::e($tz) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="a-field">
        <label class="a-field__label" for="file_retention_hours">Keep uploaded files for (hours)</label>
        <input class="a-input" id="file_retention_hours" name="file_retention_hours" type="number" min="1" max="168"
               value="<?= View::e($s('file_retention_hours', '24')) ?>">
        <p class="a-field__hint">Printed files are removed sooner. Shorter is better for customer privacy.</p>
      </div>
    </div>

    <label class="a-check">
      <input type="checkbox" name="force_https" value="1" <?= $on('force_https') ? 'checked' : '' ?>>
      <span>
        Force HTTPS
        <span class="a-field__hint" style="display:block">
          Turn this on once a certificate is installed. Customers upload documents and pay on this
          site — it should not run over plain HTTP in production.
        </span>
      </span>
    </label>

    <button type="submit" class="a-btn a-btn--primary">Save general settings</button>
  </form>
</section>

<section class="a-card">
  <h2 class="a-card__title">Tax and fees</h2>
  <form method="post" action="/admin/settings/pricing">
    <?= Csrf::field() ?>
    <label class="a-check">
      <input type="checkbox" name="tax_enabled" value="1" <?= $on('tax_enabled') ? 'checked' : '' ?>>
      <span>Add tax to print prices</span>
    </label>

    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="tax_label">Tax label</label>
        <input class="a-input" id="tax_label" name="tax_label" maxlength="40"
               value="<?= View::e($s('tax_label', 'GST')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="tax_percent">Tax rate (%)</label>
        <input class="a-input" id="tax_percent" name="tax_percent" type="number" step="0.01" min="0" max="100"
               value="<?= View::e($s('tax_percent', '0')) ?>">
      </div>
    </div>

    <label class="a-check">
      <input type="checkbox" name="tax_inclusive" value="1" <?= $on('tax_inclusive') ? 'checked' : '' ?>>
      <span>Prices already include tax (shown separately on the receipt, not added on top)</span>
    </label>

    <hr style="border:0;border-top:1px solid var(--line);margin:1rem 0">

    <label class="a-check">
      <input type="checkbox" name="gateway_fee_passthrough" value="1"
             <?= $on('gateway_fee_passthrough') ? 'checked' : '' ?>>
      <span>Pass the payment gateway fee on to the customer</span>
    </label>

    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="gateway_fee_percent">Gateway fee (%)</label>
        <input class="a-input" id="gateway_fee_percent" name="gateway_fee_percent"
               type="number" step="0.01" min="0" max="20"
               value="<?= View::e($s('gateway_fee_percent', '0')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="gateway_fee_fixed">Fixed fee (<?= View::e($currencySymbol) ?>)</label>
        <input class="a-input" id="gateway_fee_fixed" name="gateway_fee_fixed"
               type="number" step="0.01" min="0"
               value="<?= View::e($s('gateway_fee_fixed', '0')) ?>">
      </div>
    </div>

    <button type="submit" class="a-btn a-btn--primary">Save tax and fees</button>
  </form>
</section>

<section class="a-card">
  <h2 class="a-card__title">Payments</h2>

  <?php if (!$paymentConfigured && $on('payment_enabled')): ?>
    <div class="a-alert a-alert--danger">
      <strong>Payments are enabled but not configured</strong>
      Customers will reach a broken checkout. Enter the gateway credentials, or turn payments off.
    </div>
  <?php endif; ?>

  <div class="a-alert a-alert--info a-small">
    <strong>How a payment becomes a print</strong>
    The browser's "payment succeeded" callback is never trusted. Every payment has its HMAC
    signature recomputed here and is then re-read from the gateway's API, which must report it as
    captured for this exact order and amount. Only then is the job released to the printer.
  </div>

  <form method="post" action="/admin/settings/payment">
    <?= Csrf::field() ?>
    <label class="a-check">
      <input type="checkbox" name="payment_enabled" value="1" <?= $on('payment_enabled') ? 'checked' : '' ?>>
      <span>
        Take payment before printing
        <span class="a-field__hint" style="display:block">
          With this off, jobs print without charge — useful for an office or campus deployment.
        </span>
      </span>
    </label>

    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="payment_gateway">Gateway</label>
        <select class="a-select" id="payment_gateway" name="payment_gateway">
          <option value="razorpay" <?= $s('payment_gateway', 'razorpay') === 'razorpay' ? 'selected' : '' ?>>
            Razorpay
          </option>
        </select>
      </div>
      <div class="a-field">
        <label class="a-field__label" for="razorpay_key_id">Key ID</label>
        <input class="a-input a-mono" id="razorpay_key_id" name="razorpay_key_id" maxlength="120"
               autocomplete="off" value="<?= View::e($s('razorpay_key_id')) ?>" placeholder="rzp_live_…">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="razorpay_key_secret">Key secret</label>
        <input class="a-input" id="razorpay_key_secret" name="razorpay_key_secret" type="password"
               maxlength="190" autocomplete="new-password"
               placeholder="<?= $secretsSet['razorpay_key_secret'] ? 'Stored — leave blank to keep' : 'Enter the secret' ?>">
        <p class="a-field__hint">
          <?= $secretsSet['razorpay_key_secret']
              ? 'A secret is stored (encrypted). It cannot be displayed again.'
              : 'No secret stored yet.' ?>
        </p>
      </div>
      <div class="a-field">
        <label class="a-field__label" for="razorpay_webhook_secret">Webhook secret</label>
        <input class="a-input" id="razorpay_webhook_secret" name="razorpay_webhook_secret" type="password"
               maxlength="190" autocomplete="new-password"
               placeholder="<?= $secretsSet['razorpay_webhook_secret'] ? 'Stored — leave blank to keep' : 'Optional but recommended' ?>">
        <p class="a-field__hint">
          Point the gateway's webhook at
          <code><?= View::e(rtrim($s('app_url'), '/')) ?>/api/webhook/razorpay</code>.
          It rescues payments where the customer closed the tab before returning.
        </p>
      </div>
    </div>

    <div class="a-row">
      <button type="submit" class="a-btn a-btn--primary">Save payment settings</button>
    </div>
  </form>
</section>

<section class="a-card">
  <h2 class="a-card__title">Uploads and printing</h2>
  <form method="post" action="/admin/settings/printing">
    <?= Csrf::field() ?>
    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="max_file_mb">Maximum file size (MB)</label>
        <input class="a-input" id="max_file_mb" name="max_file_mb" type="number" min="1" max="200"
               value="<?= View::e($s('max_file_mb', '25')) ?>">
        <p class="a-field__hint">
          Cannot exceed the server's upload_max_filesize
          (currently <?= View::e((string) ini_get('upload_max_filesize')) ?>).
        </p>
      </div>
      <div class="a-field">
        <label class="a-field__label" for="max_files_per_batch">Files per order</label>
        <input class="a-input" id="max_files_per_batch" name="max_files_per_batch" type="number" min="1" max="50"
               value="<?= View::e($s('max_files_per_batch', '10')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="max_pages_per_job">Maximum pages per job</label>
        <input class="a-input" id="max_pages_per_job" name="max_pages_per_job" type="number" min="1" max="10000"
               value="<?= View::e($s('max_pages_per_job', '2000')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="health_check_interval">Printer health check every (seconds)</label>
        <input class="a-input" id="health_check_interval" name="health_check_interval"
               type="number" min="15" max="3600" value="<?= View::e($s('health_check_interval', '60')) ?>">
        <p class="a-field__hint">Lower means faster detection but more traffic to each printer.</p>
      </div>
      <div class="a-field">
        <label class="a-field__label" for="default_max_queue_depth">Default queue limit per printer</label>
        <input class="a-input" id="default_max_queue_depth" name="default_max_queue_depth"
               type="number" min="1" max="500" value="<?= View::e($s('default_max_queue_depth', '25')) ?>">
      </div>
    </div>

    <button type="submit" class="a-btn a-btn--primary">Save printing settings</button>
  </form>
</section>

<?php $view->end(); ?>
