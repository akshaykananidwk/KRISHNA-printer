<?php
/**
 * The installation wizard — a single page that steps through eight stages
 * without ever asking the operator to edit a file.
 *
 * @var App\Core\View $view
 * @var int $step
 * @var array<int,string> $steps
 * @var array<int,int> $completed
 * @var array<string,mixed>|null $requirements
 * @var array<string,mixed> $data
 * @var string $phpVersion
 * @var string $detectedUrl
 */

use App\Core\Csrf;
use App\Core\View;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= View::e(Csrf::token()) ?>">
<title>Install · <?= View::e($appName) ?></title>
<link rel="stylesheet" href="/assets/css/admin.css">
<style nonce="<?= View::e($cspNonce) ?>">
  .i-wrap { max-width: 52rem; margin: 0 auto; padding: 1.5rem 1rem 4rem; }
  .i-head { text-align: center; margin-bottom: 1.6rem; }
  .i-head__mark { font-size: 2.4rem; }
  .i-head h1 { margin: .3rem 0 .2rem; font-size: 1.5rem; }
  .i-head p { margin: 0; color: var(--ink-soft); }
  .i-steps { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1.4rem; justify-content: center; }
  .i-step {
    display: flex; align-items: center; gap: .35rem;
    font-size: .78rem; padding: .3rem .6rem; border-radius: 999px;
    background: var(--surface); border: 1px solid var(--line); color: var(--ink-faint);
  }
  .i-step.is-current { border-color: var(--brand); color: var(--brand-dark); font-weight: 650; }
  .i-step.is-done { background: var(--ok-bg); border-color: var(--ok-bg); color: var(--ok); }
  .i-step__n {
    width: 1.1rem; height: 1.1rem; border-radius: 50%;
    background: currentColor; color: #fff; font-size: .62rem; font-weight: 800;
    display: grid; place-items: center;
  }
  .i-step.is-done .i-step__n { background: var(--ok); }
  .i-panel { display: none; }
  .i-panel.is-active { display: block; }
  .i-actions { display: flex; gap: .5rem; margin-top: 1.2rem; flex-wrap: wrap; }
  .i-log {
    background: var(--ink); color: #9ff5d0; font-family: ui-monospace, monospace;
    font-size: .8rem; padding: .8rem; border-radius: var(--radius-sm);
    max-height: 15rem; overflow-y: auto; white-space: pre-wrap; margin-top: .8rem;
  }
</style>
</head>
<body class="a-body">
<div class="i-wrap">

  <div class="i-head">
    <div class="i-head__mark" aria-hidden="true">🖨️</div>
    <h1>Install <?= View::e($appName) ?></h1>
    <p>Eight steps. No files to edit by hand.</p>
  </div>

  <nav class="i-steps" aria-label="Installation progress">
    <?php foreach ($steps as $number => $label): ?>
      <span class="i-step <?= in_array($number, $completed, true) ? 'is-done' : ($number === $step ? 'is-current' : '') ?>">
        <span class="i-step__n"><?= in_array($number, $completed, true) ? '✓' : $number ?></span>
        <?= View::e($label) ?>
      </span>
    <?php endforeach; ?>
  </nav>

  <div class="a-alert" id="installMessage" hidden></div>

  <!-- Step 1 — requirements ------------------------------------------- -->
  <section class="a-card i-panel <?= $step === 1 ? 'is-active' : '' ?>" data-step="1">
    <h2 class="a-card__title a-card__title--plain">1. Server requirements</h2>
    <p class="a-small a-muted">Everything marked required must pass before installation can continue.</p>

    <?php if ($requirements !== null): ?>
      <div class="a-table-wrap">
        <table class="a-table a-table--stack">
          <thead><tr><th>Check</th><th>Result</th><th>Found</th><th>Needed</th></tr></thead>
          <tbody>
            <?php foreach ($requirements['checks'] as $check): ?>
              <tr>
                <td data-label="Check"><?= View::e((string) $check['name']) ?></td>
                <td data-label="Result">
                  <span class="a-badge a-badge--<?= $check['passed'] ? 'success' : ($check['required'] ? 'danger' : 'warning') ?>">
                    <?= $check['passed'] ? 'OK' : ($check['required'] ? 'Required' : 'Advisory') ?>
                  </span>
                </td>
                <td data-label="Found" class="a-small a-mono"><?= View::e((string) $check['actual']) ?></td>
                <td data-label="Needed" class="a-small a-muted"><?= View::e((string) $check['expected']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="i-actions">
      <button type="button" class="a-btn a-btn--primary" data-action="requirements">Re-check and continue</button>
    </div>
  </section>

  <!-- Step 2 — database ------------------------------------------------ -->
  <section class="a-card i-panel <?= $step === 2 ? 'is-active' : '' ?>" data-step="2">
    <h2 class="a-card__title a-card__title--plain">2. Database</h2>
    <p class="a-small a-muted">
      MySQL 5.7.8+ or MariaDB 10.3+. On most shared hosting the host is <code>localhost</code>.
      If the database does not exist and the user is allowed to create one, it will be created.
    </p>

    <form id="databaseForm" class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="db_host">Database host</label>
        <input class="a-input" id="db_host" name="db_host" required value="<?= View::e((string) ($data['db_host'] ?? 'localhost')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="db_port">Port</label>
        <input class="a-input" id="db_port" name="db_port" type="number" required
               value="<?= View::e((string) ($data['db_port'] ?? 3306)) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="db_name">Database name</label>
        <input class="a-input a-mono" id="db_name" name="db_name" required
               value="<?= View::e((string) ($data['db_name'] ?? '')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="db_user">Database user</label>
        <input class="a-input" id="db_user" name="db_user" required autocomplete="off"
               value="<?= View::e((string) ($data['db_user'] ?? '')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="db_pass">Database password</label>
        <input class="a-input" id="db_pass" name="db_pass" type="password" autocomplete="new-password">
      </div>
    </form>

    <label class="a-check">
      <input type="checkbox" id="overwrite_existing" name="overwrite_existing" value="1">
      <span>Reuse an existing installation in this database (keeps data, applies missing migrations)</span>
    </label>

    <div class="i-actions">
      <button type="button" class="a-btn a-btn--primary" data-action="database">Test connection and continue</button>
    </div>
  </section>

  <!-- Step 3 — application --------------------------------------------- -->
  <section class="a-card i-panel <?= $step === 3 ? 'is-active' : '' ?>" data-step="3">
    <h2 class="a-card__title a-card__title--plain">3. Application</h2>

    <form id="applicationForm" class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="app_name">Application name</label>
        <input class="a-input" id="app_name" name="app_name" required
               value="<?= View::e((string) ($data['app_name'] ?? $appName)) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="app_url">Site address</label>
        <input class="a-input" id="app_url" name="app_url" type="url" required
               value="<?= View::e((string) ($data['app_url'] ?? $detectedUrl)) ?>">
        <p class="a-field__hint">QR links are built from this. Use the address customers will reach.</p>
      </div>
      <div class="a-field">
        <label class="a-field__label" for="timezone">Timezone</label>
        <select class="a-select" id="timezone" name="timezone">
          <?php foreach (timezone_identifiers_list() as $tz): ?>
            <option value="<?= View::e($tz) ?>"
                    <?= ($data['app_timezone'] ?? 'Asia/Kolkata') === $tz ? 'selected' : '' ?>>
              <?= View::e($tz) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="a-field">
        <label class="a-field__label" for="currency_symbol">Currency symbol</label>
        <input class="a-input" id="currency_symbol" name="currency_symbol" maxlength="5"
               value="<?= View::e((string) ($data['currency_symbol'] ?? '₹')) ?>">
      </div>
    </form>

    <label class="a-check">
      <input type="checkbox" id="force_https" name="force_https" value="1"
             <?= str_starts_with($detectedUrl, 'https://') ? 'checked' : '' ?>>
      <span>
        Force HTTPS
        <span class="a-field__hint" style="display:block">
          Leave this off until a certificate is installed, or the site will redirect to an address
          that does not work.
        </span>
      </span>
    </label>

    <div class="i-actions">
      <button type="button" class="a-btn a-btn--primary" data-action="application">Continue</button>
    </div>
  </section>

  <!-- Step 4 — admin account -------------------------------------------- -->
  <section class="a-card i-panel <?= $step === 4 ? 'is-active' : '' ?>" data-step="4">
    <h2 class="a-card__title a-card__title--plain">4. Administrator account</h2>
    <p class="a-small a-muted">This account can do everything, including system updates.</p>

    <form id="adminForm" class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="admin_name">Your name</label>
        <input class="a-input" id="admin_name" name="admin_name" required maxlength="120">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="admin_email">Email address</label>
        <input class="a-input" id="admin_email" name="admin_email" type="email" required maxlength="190"
               autocomplete="username">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="admin_password">Password</label>
        <input class="a-input" id="admin_password" name="admin_password" type="password" required
               minlength="10" autocomplete="new-password">
        <p class="a-field__hint">
          At least 10 characters with letters and numbers. Not your name, your email, or a common
          password.
        </p>
      </div>
      <div class="a-field">
        <label class="a-field__label" for="admin_password_confirmation">Confirm password</label>
        <input class="a-input" id="admin_password_confirmation" name="admin_password_confirmation"
               type="password" required autocomplete="new-password">
      </div>
    </form>

    <div class="i-actions">
      <button type="button" class="a-btn a-btn--primary" data-action="admin">Continue</button>
    </div>
  </section>

  <!-- Step 5 — system setup --------------------------------------------- -->
  <section class="a-card i-panel <?= $step === 5 ? 'is-active' : '' ?>" data-step="5">
    <h2 class="a-card__title a-card__title--plain">5. Your first location and prices</h2>
    <p class="a-small a-muted">You can add more locations, printers and prices afterwards.</p>

    <form id="systemForm" class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="business_name">Business name</label>
        <input class="a-input" id="business_name" name="business_name" required maxlength="120">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="location_name">First location name</label>
        <input class="a-input" id="location_name" name="location_name" required maxlength="150"
               placeholder="Main Branch">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="location_code">Location code</label>
        <input class="a-input a-mono" id="location_code" name="location_code" required maxlength="40"
               pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="main-branch">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="location_city">City</label>
        <input class="a-input" id="location_city" name="location_city" maxlength="90">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="contact_phone">Contact phone</label>
        <input class="a-input" id="contact_phone" name="contact_phone" type="tel" maxlength="32">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="price_a4_bw">A4 black &amp; white, per page</label>
        <input class="a-input" id="price_a4_bw" name="price_a4_bw" type="number" step="0.01" min="0"
               required value="2.00">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="price_a4_color">A4 colour, per page (0 to skip)</label>
        <input class="a-input" id="price_a4_color" name="price_a4_color" type="number" step="0.01" min="0"
               value="10.00">
      </div>
    </form>

    <label class="a-check">
      <input type="checkbox" id="enable_payments" name="enable_payments" value="1">
      <span>
        Take payment before printing
        <span class="a-field__hint" style="display:block">
          Gateway keys are entered later in Settings. Leave this off for now if you are not ready —
          jobs will print without charge until you turn it on.
        </span>
      </span>
    </label>

    <div class="i-actions">
      <button type="button" class="a-btn a-btn--primary" data-action="system">Continue</button>
    </div>
  </section>

  <!-- Step 6 — migrations ------------------------------------------------ -->
  <section class="a-card i-panel <?= $step === 6 ? 'is-active' : '' ?>" data-step="6">
    <h2 class="a-card__title a-card__title--plain">6. Create the database tables</h2>
    <p class="a-small a-muted">
      This writes <code>config/config.php</code> (including a freshly generated encryption key) and
      then creates the tables. It is the first step that changes anything on disk.
    </p>
    <div class="i-actions">
      <button type="button" class="a-btn a-btn--primary" data-action="migrate">Run migrations</button>
    </div>
    <div class="i-log" id="migrateLog" hidden></div>
  </section>

  <!-- Step 7 — seed -------------------------------------------------------- -->
  <section class="a-card i-panel <?= $step === 7 ? 'is-active' : '' ?>" data-step="7">
    <h2 class="a-card__title a-card__title--plain">7. Create your account and defaults</h2>
    <p class="a-small a-muted">
      Creates the administrator, the first location with its QR code, and the starting prices.
    </p>
    <div class="i-actions">
      <button type="button" class="a-btn a-btn--primary" data-action="seed">Create defaults</button>
    </div>
  </section>

  <!-- Step 8 — lock -------------------------------------------------------- -->
  <section class="a-card i-panel <?= $step === 8 ? 'is-active' : '' ?>" data-step="8">
    <h2 class="a-card__title a-card__title--plain">8. Lock the installer</h2>
    <div class="a-alert a-alert--warning">
      <strong>This step matters</strong>
      An installer left reachable on a live site can rewrite the database credentials and create an
      administrator — a complete takeover. Locking it writes <code>storage/installed.lock</code>,
      after which <code>/install</code> refuses to run.
    </div>
    <div class="i-actions">
      <button type="button" class="a-btn a-btn--primary" data-action="finalise">Finish and lock</button>
    </div>
    <div id="finishPanel"></div>
  </section>

</div>

<script nonce="<?= View::e($cspNonce) ?>">
(function () {
  'use strict';

  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var message = document.getElementById('installMessage');

  function show(tone, html) {
    message.className = 'a-alert a-alert--' + tone;
    message.innerHTML = html;
    message.hidden = false;
    message.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function escapeHtml(v) {
    return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function goToStep(n) {
    document.querySelectorAll('.i-panel').forEach(function (panel) {
      panel.classList.toggle('is-active', parseInt(panel.dataset.step, 10) === n);
    });
    document.querySelectorAll('.i-step').forEach(function (chip, index) {
      var number = index + 1;
      chip.classList.toggle('is-current', number === n);
      if (number < n) { chip.classList.add('is-done'); chip.querySelector('.i-step__n').textContent = '✓'; }
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function send(url, body, button, nextStep, onSuccess) {
    var original = button.textContent;
    button.disabled = true;
    button.textContent = 'Working…';
    message.hidden = true;

    fetch(url, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf },
      body: body
    })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
      .then(function (result) {
        if (!result.data.success) {
          show('danger', '<strong>Cannot continue</strong>' + escapeHtml(result.data.error || 'Something went wrong.'));
          if (result.data.requires_confirmation) {
            var box = document.getElementById('overwrite_existing');
            if (box) { box.checked = false; box.parentElement.scrollIntoView({ block: 'center' }); }
          }
          return;
        }
        show('success', escapeHtml(result.data.message || 'Done.'));
        if (onSuccess) { onSuccess(result.data); }
        if (nextStep) { goToStep(nextStep); }
      })
      .catch(function () {
        show('danger', 'Could not reach the server. Check that PHP is still running and try again.');
      })
      .finally(function () {
        button.disabled = false;
        button.textContent = original;
      });
  }

  function formBody(formId) {
    var body = new FormData(formId ? document.getElementById(formId) : undefined);
    body.append('_csrf_token', csrf);
    return body;
  }

  document.querySelectorAll('[data-action]').forEach(function (button) {
    button.addEventListener('click', function () {
      var action = button.dataset.action;

      if (action === 'requirements') {
        send('/install/requirements', formBody(), button, 2);

      } else if (action === 'database') {
        var form = document.getElementById('databaseForm');
        if (!form.reportValidity()) { return; }
        var body = formBody('databaseForm');
        if (document.getElementById('overwrite_existing').checked) {
          body.append('overwrite_existing', '1');
        }
        send('/install/database', body, button, 3);

      } else if (action === 'application') {
        var appForm = document.getElementById('applicationForm');
        if (!appForm.reportValidity()) { return; }
        var appBody = formBody('applicationForm');
        if (document.getElementById('force_https').checked) { appBody.append('force_https', '1'); }
        send('/install/application', appBody, button, 4);

      } else if (action === 'admin') {
        var adminForm = document.getElementById('adminForm');
        if (!adminForm.reportValidity()) { return; }
        if (document.getElementById('admin_password').value !==
            document.getElementById('admin_password_confirmation').value) {
          show('danger', 'The passwords do not match.');
          return;
        }
        send('/install/admin', formBody('adminForm'), button, 5);

      } else if (action === 'system') {
        var sysForm = document.getElementById('systemForm');
        if (!sysForm.reportValidity()) { return; }
        var sysBody = formBody('systemForm');
        if (document.getElementById('enable_payments').checked) { sysBody.append('enable_payments', '1'); }
        send('/install/system', sysBody, button, 6);

      } else if (action === 'migrate') {
        send('/install/migrate', formBody(), button, 7, function (data) {
          var log = document.getElementById('migrateLog');
          log.hidden = false;
          log.textContent = (data.log || []).join('\n') || 'No output.';
        });

      } else if (action === 'seed') {
        send('/install/seed', formBody(), button, 8);

      } else if (action === 'finalise') {
        send('/install/finalise', formBody(), button, null, function (data) {
          var panel = document.getElementById('finishPanel');
          var html = '<div class="a-alert a-alert--success" style="margin-top:1rem">' +
            '<strong>Installation complete</strong><ul style="margin:.4rem 0 0;padding-left:1.1rem">';
          (data.notes || []).forEach(function (note) { html += '<li>' + escapeHtml(note) + '</li>'; });
          html += '</ul></div>';

          if (data.qr_url) {
            html += '<div class="a-alert a-alert--warning" style="margin-top:.8rem">' +
              '<strong>Your first location\'s QR link — save it now</strong>' +
              'Only a hash is stored, so this cannot be shown again. If you lose it, issue a new QR ' +
              'code from the location screen.' +
              '<code class="a-token">' + escapeHtml(data.qr_url) + '</code></div>';
          }

          html += '<a class="a-btn a-btn--primary" href="/admin/login" style="margin-top:.8rem">' +
            'Go to the admin sign-in</a>';

          panel.innerHTML = html;
          document.querySelector('[data-action="finalise"]').hidden = true;
        });
      }
    });
  });
})();
</script>
</body>
</html>
