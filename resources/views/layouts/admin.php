<?php
/**
 * Admin shell: responsive sidebar navigation that collapses to a drawer.
 *
 * @var App\Core\View $view
 * @var string $title
 * @var string $active
 * @var string $cspNonce
 */

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;

$admin = null;
$adminId = Session::get('admin_id');
if ($adminId !== null) {
    $admin = new App\Models\Admin([
        'id' => $adminId,
        'name' => Session::get('admin_name', ''),
        'role' => Session::get('admin_role', 'viewer'),
        'email' => Session::get('admin_email', ''),
    ]);
}

$nav = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => '/admin/dashboard', 'permission' => 'dashboard.view', 'icon' => 'grid'],
    ['key' => 'jobs', 'label' => 'Print jobs', 'url' => '/admin/jobs', 'permission' => 'jobs.view', 'icon' => 'list'],
    ['key' => 'locations', 'label' => 'Locations', 'url' => '/admin/locations', 'permission' => 'locations.view', 'icon' => 'pin'],
    ['key' => 'printers', 'label' => 'Printers', 'url' => '/admin/printers', 'permission' => 'printers.view', 'icon' => 'printer'],
    ['key' => 'devices', 'label' => 'Print agents', 'url' => '/admin/devices', 'permission' => 'printers.manage', 'icon' => 'cpu'],
    ['key' => 'pricing', 'label' => 'Pricing', 'url' => '/admin/pricing', 'permission' => 'pricing.view', 'icon' => 'tag'],
    ['key' => 'reports', 'label' => 'Reports', 'url' => '/admin/reports', 'permission' => 'reports.view', 'icon' => 'chart'],
    ['key' => 'users', 'label' => 'Team', 'url' => '/admin/users', 'permission' => 'settings.manage', 'icon' => 'users'],
    ['key' => 'audit', 'label' => 'Audit log', 'url' => '/admin/audit', 'permission' => 'audit.view', 'icon' => 'shield'],
    ['key' => 'settings', 'label' => 'Settings', 'url' => '/admin/settings', 'permission' => 'settings.manage', 'icon' => 'cog'],
    ['key' => 'system', 'label' => 'System', 'url' => '/admin/system', 'permission' => 'settings.manage', 'icon' => 'server'],
];

$icons = [
    'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'list' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
    'pin' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
    'printer' => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
    'cpu' => '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/>',
    'tag' => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
    'chart' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
    'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
    'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'cog' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    'server' => '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>',
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= View::e(Csrf::token()) ?>">
<title><?= View::e($title ?? 'Admin') ?> · <?= View::e($appName) ?></title>
<link rel="stylesheet" href="/assets/css/admin.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🖨️</text></svg>">
</head>
<body class="a-body">

<a class="a-skip" href="#main">Skip to content</a>

<input type="checkbox" id="navToggle" class="a-nav-toggle" hidden>

<header class="a-topbar">
  <label for="navToggle" class="a-topbar__burger" aria-label="Toggle navigation">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/>
         <line x1="3" y1="18" x2="21" y2="18"/></svg>
  </label>

  <span class="a-topbar__title"><?= View::e($title ?? 'Admin') ?></span>

  <div class="a-topbar__right">
    <?php if ($admin !== null): ?>
      <span class="a-avatar" title="<?= View::e($admin->string('name')) ?>"><?= View::e($admin->initials()) ?></span>
      <form method="post" action="/admin/logout" class="a-logout">
        <?= Csrf::field() ?>
        <button type="submit" class="a-topbar__logout">Sign out</button>
      </form>
    <?php endif; ?>
  </div>
</header>

<div class="a-shell">
  <nav class="a-sidebar" aria-label="Main">
    <div class="a-sidebar__brand">
      <span class="a-sidebar__mark" aria-hidden="true">🖨️</span>
      <span class="a-sidebar__name"><?= View::e($appName) ?></span>
    </div>

    <ul class="a-nav">
      <?php foreach ($nav as $item): ?>
        <?php if ($admin !== null && !$admin->can($item['permission'])) { continue; } ?>
        <li>
          <a href="<?= View::e($item['url']) ?>"
             class="a-nav__link<?= ($active ?? '') === $item['key'] ? ' is-active' : '' ?>"
             <?= ($active ?? '') === $item['key'] ? 'aria-current="page"' : '' ?>>
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icons[$item['icon']] ?></svg>
            <span><?= View::e($item['label']) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($admin !== null): ?>
      <div class="a-sidebar__foot">
        <span class="a-sidebar__user"><?= View::e($admin->string('name')) ?></span>
        <span class="a-sidebar__role"><?= View::e($admin->roleLabel()) ?></span>
        <a class="a-sidebar__link" href="/admin/password">Change password</a>
      </div>
    <?php endif; ?>
  </nav>

  <label for="navToggle" class="a-scrim" aria-hidden="true"></label>

  <main class="a-main" id="main">
    <?php if (!empty($flashes)): ?>
      <div class="a-flashes" role="status">
        <?php foreach ($flashes as $flash): ?>
          <div class="a-flash a-flash--<?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?= $view->section('content') ?>
  </main>
</div>

<script src="/assets/js/admin.js" nonce="<?= View::e($cspNonce) ?>"></script>
<?= $view->section('scripts') ?>
</body>
</html>
