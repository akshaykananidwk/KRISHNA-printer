<?php
/** @var App\Core\View $view @var App\Models\Location|null $location @var string $formAction */

use App\Core\Config;
use App\Core\Csrf;
use App\Core\View;

$view->extend('layouts/admin');
$view->start('content');

$isEdit = $location !== null;
$value = static fn (string $key, string $default = ''): string =>
    $location === null ? $default : $location->string($key, $default);
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1><?= $isEdit ? 'Edit location' : 'Add location' ?></h1>
    <p><?= $isEdit
        ? 'Changing the code does not change the QR token; rotate the QR separately if needed.'
        : 'A QR code is generated automatically once the location is saved.' ?></p>
  </div>
</div>

<form method="post" action="<?= View::e($formAction) ?>">
  <?= Csrf::field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="_method" value="PUT"><?php endif; ?>

  <div class="a-card">
    <h2 class="a-card__title">Basics</h2>
    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="name">Location name</label>
        <input class="a-input" id="name" name="name" required maxlength="150"
               value="<?= View::e($value('name')) ?>" placeholder="Andheri West Branch">
      </div>

      <div class="a-field">
        <label class="a-field__label" for="code">Location code</label>
        <input class="a-input a-mono" id="code" name="code" required maxlength="40"
               pattern="[a-z0-9]+(-[a-z0-9]+)*"
               value="<?= View::e($value('code')) ?>" placeholder="andheri-west-01">
        <p class="a-field__hint">Appears in the QR link. Lowercase letters, numbers and hyphens.</p>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="status">Status</label>
        <select class="a-select" id="status" name="status">
          <?php foreach (['active' => 'Active — accepting jobs', 'inactive' => 'Inactive', 'maintenance' => 'Maintenance'] as $key => $label): ?>
            <option value="<?= View::e($key) ?>" <?= $value('status', 'active') === $key ? 'selected' : '' ?>>
              <?= View::e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="a-field">
        <label class="a-field__label" for="timezone">Timezone</label>
        <select class="a-select" id="timezone" name="timezone">
          <?php
          $currentTz = $value('timezone', (string) Config::get('app.timezone', 'Asia/Kolkata'));
          foreach (timezone_identifiers_list() as $tz): ?>
            <option value="<?= View::e($tz) ?>" <?= $currentTz === $tz ? 'selected' : '' ?>><?= View::e($tz) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="a-card">
    <h2 class="a-card__title">Address</h2>
    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="address_line1">Address line 1</label>
        <input class="a-input" id="address_line1" name="address_line1" maxlength="190"
               value="<?= View::e($value('address_line1')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="address_line2">Address line 2</label>
        <input class="a-input" id="address_line2" name="address_line2" maxlength="190"
               value="<?= View::e($value('address_line2')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="city">City</label>
        <input class="a-input" id="city" name="city" maxlength="90" value="<?= View::e($value('city')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="state">State</label>
        <input class="a-input" id="state" name="state" maxlength="90" value="<?= View::e($value('state')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="postal_code">PIN code</label>
        <input class="a-input" id="postal_code" name="postal_code" maxlength="20"
               inputmode="numeric" value="<?= View::e($value('postal_code')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="country">Country</label>
        <input class="a-input" id="country" name="country" maxlength="2"
               value="<?= View::e($value('country', 'IN')) ?>">
      </div>
    </div>
  </div>

  <div class="a-card">
    <h2 class="a-card__title">Contact</h2>
    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="contact_name">Contact name</label>
        <input class="a-input" id="contact_name" name="contact_name" maxlength="120"
               value="<?= View::e($value('contact_name')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="contact_phone">Phone</label>
        <input class="a-input" id="contact_phone" name="contact_phone" maxlength="32" type="tel"
               value="<?= View::e($value('contact_phone')) ?>">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="contact_email">Email</label>
        <input class="a-input" id="contact_email" name="contact_email" maxlength="190" type="email"
               value="<?= View::e($value('contact_email')) ?>">
      </div>
    </div>

    <div class="a-field">
      <label class="a-field__label" for="notes">Internal notes</label>
      <textarea class="a-textarea" id="notes" name="notes" maxlength="2000"><?= View::e($value('notes')) ?></textarea>
    </div>
  </div>

  <div class="a-row">
    <button type="submit" class="a-btn a-btn--primary"><?= $isEdit ? 'Save changes' : 'Create location' ?></button>
    <a class="a-btn a-btn--ghost"
       href="<?= $isEdit ? '/admin/locations/' . $location->id() : '/admin/locations' ?>">Cancel</a>
  </div>
</form>

<?php $view->end(); ?>
