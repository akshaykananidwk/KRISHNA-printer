<?php
/**
 * @var App\Core\View $view
 * @var App\Models\Printer|null $printer
 * @var array<string,mixed>|null $connection
 * @var array<int,App\Models\Location> $locations
 * @var array<int,array<string,mixed>> $devices
 * @var array<string,array<string,mixed>> $profiles
 * @var array<string,string> $drivers
 * @var string $formAction
 */

use App\Core\Csrf;
use App\Core\View;

$view->extend('layouts/admin');
$view->start('content');

$isEdit = $printer !== null;
$value = static fn (string $key, string $default = ''): string =>
    $printer === null ? $default : $printer->string($key, $default);
$conn = static fn (string $key, string $default = ''): string =>
    $connection === null ? $default : (string) ($connection[$key] ?? $default);
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1><?= $isEdit ? 'Edit printer' : 'Add printer' ?></h1>
    <p>The transport decides what this printer can be asked to do — choose it carefully.</p>
  </div>
</div>

<form method="post" action="<?= View::e($formAction) ?>">
  <?= Csrf::field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="_method" value="PUT"><?php endif; ?>

  <div class="a-card">
    <h2 class="a-card__title">Printer</h2>
    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="name">Printer name</label>
        <input class="a-input" id="name" name="name" required maxlength="150"
               value="<?= View::e($value('name')) ?>" placeholder="Front counter printer">
      </div>

      <div class="a-field">
        <label class="a-field__label" for="code">Printer code</label>
        <input class="a-input a-mono" id="code" name="code" required maxlength="40"
               pattern="[a-z0-9]+(-[a-z0-9]+)*"
               value="<?= View::e($value('code')) ?>" placeholder="andheri-counter-1">
        <p class="a-field__hint">The agent refers to the printer by this code.</p>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="location_id">Location</label>
        <select class="a-select" id="location_id" name="location_id" required>
          <option value="">Choose a location…</option>
          <?php foreach ($locations as $loc): ?>
            <option value="<?= $loc->id() ?>" <?= $value('location_id') === (string) $loc->id() ? 'selected' : '' ?>>
              <?= View::e($loc->string('name')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="capability_profile">Model profile</label>
        <select class="a-select" id="capability_profile" name="capability_profile">
          <?php foreach ($profiles as $key => $profile): ?>
            <option value="<?= View::e($key) ?>"
                    <?= $value('capability_profile', 'generic') === $key ? 'selected' : '' ?>>
              <?= View::e((string) $profile['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="a-field__hint">
          Seeds the declared capability list and tells the system whether this model needs a
          rendering host. Declared values are never offered to customers until verified.
        </p>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="model">Model (optional override)</label>
        <input class="a-input" id="model" name="model" maxlength="120" value="<?= View::e($value('model')) ?>">
      </div>

      <div class="a-field">
        <label class="a-field__label" for="max_queue_depth">Maximum queue depth</label>
        <input class="a-input" id="max_queue_depth" name="max_queue_depth" type="number" min="1" max="500"
               value="<?= View::e($value('max_queue_depth', '25')) ?>">
        <p class="a-field__hint">Customers are turned away once this many jobs are waiting.</p>
      </div>
    </div>

    <label class="a-check">
      <input type="checkbox" name="is_enabled" value="1"
             <?= !$isEdit || $printer->bool('is_enabled') ? 'checked' : '' ?>>
      <span>Enabled — a disabled printer is never offered to customers</span>
    </label>
  </div>

  <div class="a-card">
    <h2 class="a-card__title">Connection</h2>

    <div class="a-alert a-alert--info a-small">
      <strong>Which transport?</strong>
      <ul style="margin:.4rem 0 0;padding-left:1.1rem">
        <?php foreach ($drivers as $key => $description): ?>
          <li><strong><?= View::e(strtoupper($key)) ?></strong> — <?= View::e($description) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="driver">Transport</label>
        <select class="a-select" id="driver" name="driver" required>
          <?php foreach (array_keys($drivers) as $key): ?>
            <option value="<?= View::e($key) ?>" <?= $conn('driver', 'agent') === $key ? 'selected' : '' ?>>
              <?= View::e(strtoupper($key)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="device_id">Print agent (for the agent transport)</label>
        <select class="a-select" id="device_id" name="device_id">
          <option value="">None</option>
          <?php foreach ($devices as $device): ?>
            <option value="<?= (int) $device['id'] ?>"
                    <?= $value('device_id') === (string) $device['id'] ? 'selected' : '' ?>>
              <?= View::e((string) $device['name']) ?> — <?= View::e((string) $device['location_name']) ?>
              (<?= View::e((string) $device['status']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <p class="a-field__hint">The agent must be at the same location as this printer.</p>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="queue_name">CUPS queue name</label>
        <input class="a-input a-mono" id="queue_name" name="queue_name" maxlength="120"
               value="<?= View::e($value('queue_name')) ?>" placeholder="Canon_GM4000_series">
        <p class="a-field__hint">The queue name on the agent, as shown by <code>lpstat -p</code>.</p>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="host">Host or IP (direct transports)</label>
        <input class="a-input a-mono" id="host" name="host" maxlength="190"
               value="<?= View::e($conn('host')) ?>" placeholder="192.168.1.50">
        <p class="a-field__hint">A private-network address. Never expose a printer to the internet.</p>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="port">Port</label>
        <input class="a-input" id="port" name="port" type="number" min="1" max="65535"
               value="<?= View::e($conn('port')) ?>" placeholder="631">
        <p class="a-field__hint">Left blank: 631 for IPP, 9100 for RAW, 515 for LPD.</p>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="ipp_path">IPP path</label>
        <input class="a-input a-mono" id="ipp_path" name="ipp_path" maxlength="190"
               value="<?= View::e($conn('ipp_path', '/ipp/print')) ?>">
      </div>

      <div class="a-field">
        <label class="a-field__label" for="lpd_queue">LPD queue</label>
        <input class="a-input a-mono" id="lpd_queue" name="lpd_queue" maxlength="120"
               value="<?= View::e($conn('lpd_queue')) ?>" placeholder="lp">
      </div>

      <div class="a-field">
        <label class="a-field__label" for="timeout_seconds">Timeout (seconds)</label>
        <input class="a-input" id="timeout_seconds" name="timeout_seconds" type="number" min="2" max="120"
               value="<?= View::e($conn('timeout_seconds', '10')) ?>">
      </div>

      <div class="a-field">
        <label class="a-field__label" for="username">Username (if the printer requires one)</label>
        <input class="a-input" id="username" name="username" maxlength="120"
               autocomplete="off" value="<?= View::e($conn('username')) ?>">
      </div>

      <div class="a-field">
        <label class="a-field__label" for="password">Password</label>
        <input class="a-input" id="password" name="password" type="password" maxlength="190" autocomplete="new-password"
               placeholder="<?= $conn('password_encrypted') !== '' ? 'Stored — leave blank to keep' : '' ?>">
        <p class="a-field__hint">Encrypted at rest. Leave blank to keep the stored one.</p>
      </div>
    </div>

    <label class="a-check">
      <input type="checkbox" name="use_tls" value="1" <?= $conn('use_tls') === '1' ? 'checked' : '' ?>>
      <span>Use TLS (IPPS)</span>
    </label>
    <label class="a-check">
      <input type="checkbox" name="verify_tls" value="1" <?= $conn('verify_tls', '1') === '1' ? 'checked' : '' ?>>
      <span>
        Verify the printer's TLS certificate
        <span class="a-field__hint" style="display:block">
          Most printers ship a self-signed certificate. Turning this off is logged; only do it on a
          network you control.
        </span>
      </span>
    </label>
  </div>

  <div class="a-card">
    <h2 class="a-card__title">Notes</h2>
    <div class="a-field">
      <textarea class="a-textarea" name="notes" maxlength="2000"
                placeholder="Tray configuration, cartridge fitted, service history…"><?= View::e($value('notes')) ?></textarea>
    </div>
  </div>

  <div class="a-row">
    <button type="submit" class="a-btn a-btn--primary"><?= $isEdit ? 'Save changes' : 'Add printer' ?></button>
    <a class="a-btn a-btn--ghost"
       href="<?= $isEdit ? '/admin/printers/' . $printer->id() : '/admin/printers' ?>">Cancel</a>
  </div>
</form>

<?php $view->end(); ?>
