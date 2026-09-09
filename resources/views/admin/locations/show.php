<?php
/**
 * @var App\Core\View $view
 * @var App\Models\Location $location
 * @var array<int,App\Models\Printer> $printers
 * @var array<int,array<string,mixed>> $pricingRules
 * @var array $recentJobs
 * @var string|null $plainToken
 * @var string|null $qrSvg
 * @var string|null $qrUrl
 */

use App\Core\Csrf;
use App\Core\View;
use App\Models\PrintJob;
use App\Support\Money;

$view->extend('layouts/admin');
$view->start('content');
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1><?= View::e($location->string('name')) ?></h1>
    <p>
      <span class="a-mono"><?= View::e($location->string('code')) ?></span>
      <?php if ($location->fullAddress() !== ''): ?> · <?= View::e($location->fullAddress()) ?><?php endif; ?>
    </p>
  </div>
  <div class="a-page-head__actions">
    <a class="a-btn a-btn--ghost" href="/admin/locations/<?= $location->id() ?>/edit">Edit</a>
    <form class="a-inline-form" method="post" action="/admin/locations/<?= $location->id() ?>/rotate-qr">
      <?= Csrf::field() ?>
      <button type="submit" class="a-btn a-btn--ghost"
              data-confirm="Issuing a new QR code stops every printed code for this location from working. Continue?">
        New QR code
      </button>
    </form>
    <form class="a-inline-form" method="post" action="/admin/locations/<?= $location->id() ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="_method" value="DELETE">
      <button type="submit" class="a-btn a-btn--danger"
              data-confirm="Delete or disable this location? Locations with print history are disabled, not deleted.">
        Delete
      </button>
    </form>
  </div>
</div>

<?php if ($plainToken !== null && $qrSvg !== null): ?>
  <div class="a-card" style="border:2px solid var(--brand)">
    <h2 class="a-card__title" style="color:var(--brand)">Your QR code — download it now</h2>
    <div class="a-alert a-alert--warning">
      <strong>This is the only time the QR link can be shown</strong>
      Only a hash of the token is stored, so a stolen database dump cannot produce a working QR
      code. Download or print the code now; if you lose it, issue a new one (which invalidates
      any already printed).
    </div>

    <div class="a-row" style="align-items:flex-start;gap:1.2rem">
      <div style="width:190px;flex:none"><?= $qrSvg ?></div>
      <div class="a-grow a-stack">
        <div>
          <span class="a-field__label">QR link</span>
          <code class="a-token" id="qrUrl"><?= View::e($qrUrl) ?></code>
          <button type="button" class="a-btn a-btn--ghost a-btn--sm" data-copy="#qrUrl">Copy link</button>
        </div>
        <div class="a-row">
          <a class="a-btn a-btn--primary a-btn--sm"
             href="/admin/locations/<?= $location->id() ?>/qr/sheet" target="_blank" rel="noopener">
            Printable sheet
          </a>
          <a class="a-btn a-btn--ghost a-btn--sm" href="/admin/locations/<?= $location->id() ?>/qr/png">
            Download PNG
          </a>
          <a class="a-btn a-btn--ghost a-btn--sm" href="/admin/locations/<?= $location->id() ?>/qr/svg">
            Download SVG
          </a>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="a-charts a-charts--2">
  <section class="a-card">
    <h2 class="a-card__title">Printers at this location</h2>

    <?php if ($printers === []): ?>
      <p class="a-muted a-small">
        No printer here yet. <a href="/admin/printers/create">Add one</a> — customers cannot print
        until a printer is attached and its capabilities verified.
      </p>
    <?php else: ?>
      <div class="a-table-wrap">
        <table class="a-table a-table--stack">
          <thead><tr><th>Printer</th><th>Status</th><th>Capabilities</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($printers as $printer): ?>
              <tr>
                <td data-label="Printer">
                  <a href="/admin/printers/<?= $printer->id() ?>"><?= View::e($printer->string('name')) ?></a>
                  <?php if ($printer->bool('is_default')): ?>
                    <span class="a-badge a-badge--info">Default</span>
                  <?php endif; ?>
                  <?php if (!$printer->bool('is_enabled')): ?>
                    <span class="a-badge a-badge--muted">Disabled</span>
                  <?php endif; ?>
                </td>
                <td data-label="Status">
                  <span class="a-badge a-badge--<?= View::e($printer->statusTone()) ?>">
                    <?= View::e($printer->statusLabel()) ?>
                  </span>
                </td>
                <td data-label="Capabilities">
                  <?php if ($printer->capabilitiesVerified()): ?>
                    <span class="a-badge a-badge--success">Verified</span>
                  <?php else: ?>
                    <span class="a-badge a-badge--warning">Not verified</span>
                  <?php endif; ?>
                </td>
                <td data-label="">
                  <a class="a-btn a-btn--ghost a-btn--sm" href="/admin/printers/<?= $printer->id() ?>">Open</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="a-card">
    <h2 class="a-card__title">Details</h2>
    <dl class="a-kv">
      <dt>Status</dt>
      <dd>
        <span class="a-badge a-badge--<?= $location->isActive() ? 'success' : 'muted' ?>">
          <?= View::e($location->statusLabel()) ?>
        </span>
      </dd>
      <dt>Contact</dt><dd><?= View::e($location->string('contact_name') ?: '—') ?></dd>
      <dt>Phone</dt><dd><?= View::e($location->string('contact_phone') ?: '—') ?></dd>
      <dt>Email</dt><dd><?= View::e($location->string('contact_email') ?: '—') ?></dd>
      <dt>Timezone</dt><dd><?= View::e($location->string('timezone')) ?></dd>
      <dt>QR issued</dt><dd><?= View::e($location->string('qr_token_issued_at')) ?> UTC</dd>
      <dt>QR token</dt><dd class="a-mono"><?= View::e($location->string('qr_token_hint')) ?>… (hashed)</dd>
    </dl>

    <?php if ($location->string('notes') !== ''): ?>
      <p class="a-small a-muted" style="margin-top:.8rem"><?= nl2br(View::e($location->string('notes'))) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/locations/<?= $location->id() ?>/status" style="margin-top:1rem">
      <?= Csrf::field() ?>
      <div class="a-row">
        <select class="a-select a-grow" name="status">
          <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'maintenance' => 'Maintenance'] as $key => $label): ?>
            <option value="<?= View::e($key) ?>" <?= $location->string('status') === $key ? 'selected' : '' ?>>
              <?= View::e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="a-btn a-btn--ghost">Update</button>
      </div>
    </form>
  </section>
</div>

<section class="a-card">
  <h2 class="a-card__title">Pricing that applies here</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr><th>Rule</th><th>Scope</th><th>Paper</th><th>Mode</th><th class="a-table__num">Per page</th></tr>
      </thead>
      <tbody>
        <?php if ($pricingRules === []): ?>
          <tr><td colspan="5" class="a-table__empty">
            No pricing rules apply here. <a href="/admin/pricing">Set prices</a> — customers cannot
            be charged without them.
          </td></tr>
        <?php else: ?>
          <?php foreach ($pricingRules as $rule): ?>
            <tr>
              <td data-label="Rule"><?= View::e((string) $rule['name']) ?></td>
              <td data-label="Scope">
                <?php if ($rule['printer_id'] !== null): ?>
                  <span class="a-badge a-badge--info">This printer</span>
                <?php elseif ($rule['location_id'] !== null): ?>
                  <span class="a-badge a-badge--success">This location</span>
                <?php else: ?>
                  <span class="a-badge a-badge--muted">All locations</span>
                <?php endif; ?>
              </td>
              <td data-label="Paper"><?= View::e((string) ($rule['paper_size'] ?? 'Any')) ?></td>
              <td data-label="Mode">
                <?= $rule['color_mode'] === null ? 'Any' : ($rule['color_mode'] === 'color' ? 'Colour' : 'B&amp;W') ?>
              </td>
              <td data-label="Per page" class="a-table__num">
                <?= View::e(Money::formatWithSymbol((int) $rule['price_per_page_paise'])) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="a-card">
  <h2 class="a-card__title">Recent print jobs here</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr><th>Job</th><th>Printer</th><th class="a-table__num">Pages</th>
            <th class="a-table__num">Amount</th><th>Status</th><th>Created (UTC)</th></tr>
      </thead>
      <tbody>
        <?php if ($recentJobs['rows'] === []): ?>
          <tr><td colspan="6" class="a-table__empty">No print jobs at this location yet.</td></tr>
        <?php else: ?>
          <?php foreach ($recentJobs['rows'] as $row):
              $job = PrintJob::fromRow($row); ?>
            <tr>
              <td data-label="Job">
                <a class="a-mono" href="/admin/jobs/<?= View::e($job->jobNumber()) ?>"><?= View::e($job->jobNumber()) ?></a>
              </td>
              <td data-label="Printer"><?= View::e((string) $row['printer_name']) ?></td>
              <td data-label="Pages" class="a-table__num"><?= number_format($job->int('billable_pages')) ?></td>
              <td data-label="Amount" class="a-table__num">
                <?= View::e(Money::formatWithSymbol($job->int('total_paise'))) ?>
              </td>
              <td data-label="Status">
                <span class="a-badge a-badge--<?= View::e($job->statusTone()) ?>"><?= View::e($job->statusLabel()) ?></span>
              </td>
              <td data-label="Created" class="a-small a-muted"><?= View::e($job->string('created_at')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <p class="a-small" style="margin-top:.7rem">
    <a href="/admin/jobs?location_id=<?= $location->id() ?>">See all jobs at this location →</a>
  </p>
</section>

<?php $view->end(); ?>
