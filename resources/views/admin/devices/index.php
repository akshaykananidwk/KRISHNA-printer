<?php
/**
 * @var App\Core\View $view
 * @var array<int,array<string,mixed>> $devices
 * @var array<int,App\Models\Location> $locations
 * @var array<int,array<string,mixed>> $tokens
 * @var array<string,mixed>|null $newToken
 * @var string $appUrl
 * @var int $staleAfter
 */

use App\Core\Csrf;
use App\Core\View;

$view->extend('layouts/admin');
$view->start('content');
?>

<div class="a-page-head">
  <div class="a-page-head__text">
    <h1>Print agents</h1>
    <p>Small always-on computers on each shop's LAN that collect jobs and drive the printers.</p>
  </div>
</div>

<div class="a-alert a-alert--info a-small">
  <strong>Why an agent rather than a direct connection</strong>
  Port 9100 is unauthenticated by design — anyone who can reach it can print. Exposing it to the
  internet is not a configuration choice, it is a vulnerability. Consumer inkjets (the Canon PIXMA
  GM series included) also have no PCL or PostScript interpreter, so they need a host with the real
  driver to rasterise a document first. The agent solves both: it makes only outbound HTTPS
  requests to this server, so no inbound port and no VPN is needed, and it renders locally through
  CUPS. No Windows machine is required at any location.
</div>

<?php if ($newToken !== null): ?>
  <div class="a-card" style="border:2px solid var(--brand)">
    <h2 class="a-card__title" style="color:var(--brand)">Agent token — copy it now</h2>
    <div class="a-alert a-alert--warning">
      <strong>This token is shown only once</strong>
      Only a hash is stored, so it cannot be displayed again. If you lose it, rotate the token.
    </div>

    <dl class="a-kv" style="margin-bottom:.8rem">
      <dt>Agent</dt><dd><?= View::e((string) $newToken['name']) ?></dd>
      <dt>Device UID</dt><dd class="a-mono a-small"><?= View::e((string) $newToken['device_uid']) ?></dd>
      <dt>Server URL</dt><dd class="a-mono a-small"><?= View::e($appUrl) ?></dd>
    </dl>

    <code class="a-token" id="agentToken"><?= View::e((string) $newToken['token']) ?></code>
    <button type="button" class="a-btn a-btn--ghost a-btn--sm" data-copy="#agentToken">Copy token</button>

    <h3 class="a-card__title a-card__title--plain" style="margin-top:1.2rem">Install on the agent machine</h3>
    <pre class="a-token" style="white-space:pre-wrap" id="installCmd"># On the location's Linux box (Raspberry Pi OS, Debian or Ubuntu):
sudo apt install -y cups cups-client libreoffice-core libreoffice-writer poppler-utils python3
# Add the printer to CUPS first, then:
sudo ./agent/install.sh \
  --server "<?= View::e($appUrl) ?>" \
  --token "<?= View::e((string) $newToken['token']) ?>"</pre>
    <button type="button" class="a-btn a-btn--ghost a-btn--sm" data-copy="#installCmd">Copy commands</button>
  </div>
<?php endif; ?>

<section class="a-card">
  <h2 class="a-card__title">Register an agent</h2>
  <form method="post" action="/admin/devices">
    <?= Csrf::field() ?>
    <div class="a-form-grid">
      <div class="a-field">
        <label class="a-field__label" for="name">Agent name</label>
        <input class="a-input" id="name" name="name" required maxlength="120" placeholder="Andheri counter Pi">
      </div>
      <div class="a-field">
        <label class="a-field__label" for="location_id">Location</label>
        <select class="a-select" id="location_id" name="location_id" required>
          <option value="">Choose…</option>
          <?php foreach ($locations as $loc): ?>
            <option value="<?= $loc->id() ?>"><?= View::e($loc->string('name')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="a-field">
        <label class="a-field__label" for="poll_interval_secs">Poll interval (seconds)</label>
        <input class="a-input" id="poll_interval_secs" name="poll_interval_secs" type="number"
               min="5" max="300" value="10">
        <p class="a-field__hint">How often the agent asks for work. Lower means faster printing.</p>
      </div>
    </div>
    <button type="submit" class="a-btn a-btn--primary">Register agent</button>
  </form>
</section>

<section class="a-card">
  <h2 class="a-card__title">Registered agents</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr><th>Agent</th><th>Location</th><th>Status</th><th>Last check-in</th>
          <th class="a-table__num">Printers</th><th>Version</th><th></th></tr>
      </thead>
      <tbody>
        <?php if ($devices === []): ?>
          <tr><td colspan="7" class="a-table__empty">No agents registered yet.</td></tr>
        <?php else: ?>
          <?php foreach ($devices as $device):
              $lastSeen = $device['last_seen_at'];
              $secondsSince = $lastSeen === null ? null : time() - strtotime((string) $lastSeen . ' UTC');
              $isStale = $secondsSince === null || $secondsSince > $staleAfter; ?>
            <tr>
              <td data-label="Agent">
                <?= View::e((string) $device['name']) ?>
                <div class="a-small a-mono a-muted"><?= View::e(substr((string) $device['device_uid'], 0, 13)) ?>…</div>
              </td>
              <td data-label="Location"><?= View::e((string) $device['location_name']) ?></td>
              <td data-label="Status">
                <span class="a-badge a-badge--<?= match (true) {
                    (string) $device['status'] === 'disabled' => 'muted',
                    (string) $device['status'] === 'online' && !$isStale => 'success',
                    default => 'danger',
                } ?>">
                  <?= (string) $device['status'] === 'online' && $isStale
                      ? 'Stale' : View::e(ucfirst((string) $device['status'])) ?>
                </span>
              </td>
              <td data-label="Last check-in" class="a-small a-muted">
                <?= $lastSeen === null ? 'Never' : View::e((string) $lastSeen) . ' UTC' ?>
              </td>
              <td data-label="Printers" class="a-table__num"><?= (int) $device['printer_count'] ?></td>
              <td data-label="Version" class="a-small">
                <?= View::e((string) ($device['agent_version'] ?? '—')) ?>
                <?php if ($device['platform'] !== null): ?>
                  <div class="a-muted"><?= View::e((string) $device['platform']) ?></div>
                <?php endif; ?>
              </td>
              <td data-label="">
                <form class="a-inline-form" method="post" action="/admin/devices/<?= (int) $device['id'] ?>/rotate-token">
                  <?= Csrf::field() ?>
                  <button type="submit" class="a-btn a-btn--ghost a-btn--sm"
                          data-confirm="Issue a new token? The old one keeps working for a short grace period so the agent can pick this one up.">
                    New token
                  </button>
                </form>
                <form class="a-inline-form" method="post" action="/admin/devices/<?= (int) $device['id'] ?>/toggle">
                  <?= Csrf::field() ?>
                  <button type="submit" class="a-btn a-btn--ghost a-btn--sm">
                    <?= (string) $device['status'] === 'disabled' ? 'Enable' : 'Disable' ?>
                  </button>
                </form>
                <form class="a-inline-form" method="post" action="/admin/devices/<?= (int) $device['id'] ?>">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="_method" value="DELETE">
                  <button type="submit" class="a-btn a-btn--ghost a-btn--sm"
                          data-confirm="Remove this agent and revoke its token?">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="a-card">
  <h2 class="a-card__title">API tokens</h2>
  <div class="a-table-wrap">
    <table class="a-table a-table--stack">
      <thead>
        <tr><th>Name</th><th>Prefix</th><th>Scope</th><th>Last used</th><th>Expires</th><th>State</th><th></th></tr>
      </thead>
      <tbody>
        <?php if ($tokens === []): ?>
          <tr><td colspan="7" class="a-table__empty">No tokens issued.</td></tr>
        <?php else: ?>
          <?php foreach ($tokens as $token):
              $isRevoked = $token['revoked_at'] !== null;
              $inGrace = $isRevoked && $token['grace_until'] !== null
                  && strtotime((string) $token['grace_until'] . ' UTC') > time();
              $isExpired = $token['expires_at'] !== null
                  && strtotime((string) $token['expires_at'] . ' UTC') < time(); ?>
            <tr>
              <td data-label="Name"><?= View::e((string) $token['name']) ?></td>
              <td data-label="Prefix" class="a-mono a-small"><?= View::e((string) $token['token_prefix']) ?>…</td>
              <td data-label="Scope" class="a-small"><?= View::e((string) $token['abilities']) ?></td>
              <td data-label="Last used" class="a-small a-muted">
                <?= $token['last_used_at'] === null ? 'Never' : View::e((string) $token['last_used_at']) ?>
              </td>
              <td data-label="Expires" class="a-small a-muted">
                <?= $token['expires_at'] === null ? 'Never' : View::e(substr((string) $token['expires_at'], 0, 10)) ?>
              </td>
              <td data-label="State">
                <span class="a-badge a-badge--<?= match (true) {
                    $inGrace => 'warning',
                    $isRevoked || $isExpired => 'muted',
                    default => 'success',
                } ?>">
                  <?= $inGrace ? 'Rotating' : ($isRevoked ? 'Revoked' : ($isExpired ? 'Expired' : 'Active')) ?>
                </span>
              </td>
              <td data-label="">
                <?php if (!$isRevoked): ?>
                  <form class="a-inline-form" method="post" action="/admin/tokens/<?= (int) $token['id'] ?>/revoke">
                    <?= Csrf::field() ?>
                    <button type="submit" class="a-btn a-btn--ghost a-btn--sm"
                            data-confirm="Revoke this token immediately? The agent using it will stop working at once.">
                      Revoke
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php $view->end(); ?>
