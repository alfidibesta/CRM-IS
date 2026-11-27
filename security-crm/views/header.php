<?php
[$h1, $sub] = $titles[$page];

$u    = current_user();
$role = $u['role'] ?? 'viewer';

/* Third field is the symbol id in the sprite below: every destination looks
   different, which a dot in one of three colours could never do. */
$nav = [
    ['dashboard', 'Overview',  'i-overview', 'page.dashboard'],
    ['issues',    'Issues',    'i-issues',   'page.issues'],
    ['workflow',  'Workflow',  'i-workflow', 'page.workflow'],
    ['sop',       'SOP & Policy', 'i-sop',   'page.sop'],
    ['templates', 'Templates', 'i-templates', 'page.templates'],
    ['exceptions', 'EDLP Exceptions', 'i-exceptions', 'page.exceptions'],
    ['ot',        'OT Vulnerabilities', 'i-ot', 'page.ot'],
    ['audit',     'Audit trail',  'i-audit', 'page.audit'],
    ['health',    'System check', 'i-health', 'page.health'],
];

$categories = ['Physical Security' => 'i-cat-physical', 'IT Security' => 'i-cat-it', 'Strain Security' => 'i-cat-strain'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($h1) ?> · SecOps CRM</title>
<script nonce="<?= e(csp_nonce()) ?>">
/* Applied before first paint so the chosen theme never flashes. */
(function () {
  document.documentElement.classList.add('js');
  try {
    var t = localStorage.getItem('secops-theme');
    if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
    var d = localStorage.getItem('secops-density');
    if (d === 'compact' || d === 'comfortable') document.documentElement.dataset.density = d;
    // Simple shows one layer per page; Detailed opens every collapsible block.
    document.documentElement.dataset.detail = localStorage.getItem('secops-detail') === 'detailed' ? 'detailed' : 'simple';
  } catch (e) {}
})();
</script>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<?php /* One inline sprite: no icon font, no extra request, and every symbol
         strokes currentColor, so it follows the text and the active item. */ ?>
<svg class="icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
  <symbol id="i-overview" viewBox="0 0 24 24"><path d="M4 4h6.5v6.5H4zM13.5 4H20v6.5h-6.5zM4 13.5h6.5V20H4zM13.5 13.5H20V20h-6.5z"/></symbol>
  <symbol id="i-issues" viewBox="0 0 24 24"><path d="M12 3.8 21.2 20H2.8L12 3.8Z"/><path d="M12 10v4.4M12 17.6h.01"/></symbol>
  <symbol id="i-workflow" viewBox="0 0 24 24"><path d="M4 4h4.2v11H4zM9.9 4h4.2v16H9.9zM15.8 4H20v7.5h-4.2z"/></symbol>
  <symbol id="i-sop" viewBox="0 0 24 24"><path d="M6 3.5h7.5L18 8v12.5H6z"/><path d="M13.5 3.5V8H18"/><path d="M9 12.5h6M9 16h4"/></symbol>
  <symbol id="i-templates" viewBox="0 0 24 24"><path d="M4 7.5v11.2a1.3 1.3 0 0 0 1.3 1.3h9.2"/><path d="M8.5 3.5h7L20 8v12.5H8.5z"/><path d="M15.5 3.5V8H20"/><path d="M11.5 12h5M11.5 15.5h3"/></symbol>
  <symbol id="i-exceptions" viewBox="0 0 24 24"><circle cx="7.6" cy="16.4" r="3.9"/><path d="M10.4 13.6 20 4M16.6 7.4l2.6 2.6M13.6 10.4l2.3 2.3"/></symbol>
  <symbol id="i-ot" viewBox="0 0 24 24"><rect x="6.5" y="6.5" width="11" height="11" rx="1.6"/><path d="M9.5 3v3.5M14.5 3v3.5M9.5 17.5V21M14.5 17.5V21M3 9.5h3.5M3 14.5h3.5M17.5 9.5H21M17.5 14.5H21"/></symbol>
  <symbol id="i-audit" viewBox="0 0 24 24"><path d="M4 6h9M4 11h9M4 16h5"/><path d="m15 16 2.2 2.2 4-4"/></symbol>
  <symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8.2" r="3.2"/><path d="M3.6 20c0-3 2.4-5.1 5.4-5.1s5.4 2.1 5.4 5.1"/><path d="M16.2 5.6a3.2 3.2 0 0 1 0 5.6"/><path d="M18 15c2 .7 3.4 2.4 3.4 5"/></symbol>
  <symbol id="i-health" viewBox="0 0 24 24"><path d="M12 3.2 19.4 6v6.1c0 4.3-3 7.4-7.4 8.7C7.6 19.5 4.6 16.4 4.6 12.1V6Z"/><path d="m8.6 12 2.4 2.4 4.4-4.6"/></symbol>
  <symbol id="i-log" viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="15" rx="2"/><path d="m7.5 10 2.5 2.5-2.5 2.5M13 15h4"/></symbol>
  <symbol id="i-cat-physical" viewBox="0 0 24 24"><path d="M5 20V8M12 20V8M19 20V8M3 12h18M3 16h18"/></symbol>
  <symbol id="i-cat-it" viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="12" rx="2"/><path d="M8 20h8M12 16.5V20"/></symbol>
  <symbol id="i-cat-strain" viewBox="0 0 24 24"><circle cx="9.5" cy="8" r="3.2"/><path d="M3.5 20c0-3.2 2.7-5.4 6-5.4s6 2.2 6 5.4"/><path d="M18.5 4.5v5.2M18.5 12.6h.01"/></symbol>
</svg>
<a class="skip-link" href="#main">Skip to main content</a>
<div class="shell">

  <aside class="sidebar">
    <div class="brand">
      <div class="brand__mark">S</div>
      <div class="brand__text">
        <strong>SecOps CRM</strong>
        <span>Manufacturing · ISMS</span>
      </div>
    </div>

    <nav class="nav">
      <?php foreach ($nav as [$key, $label, $icon, $perm]): ?>
        <?php if (!can($perm)) continue; ?>
        <?php $isActive = $page === $key || ($key === 'issues' && $page === 'issue') || ($key === 'templates' && $page === 'template'); ?>
        <a class="nav__item <?= $isActive ? 'is-active' : '' ?>"
           href="?page=<?= $key ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
          <svg class="nav__icon" aria-hidden="true" focusable="false"><use href="#<?= e($icon) ?>"></use></svg> <?= e($label) ?>
        </a>
      <?php endforeach; ?>

      <?php if (can('page.issues')): ?>
        <p class="nav__label">Categories</p>
        <?php foreach ($categories as $cat => $icon): ?>
          <a class="nav__item nav__item--sm" href="?page=issues&amp;cat=<?= urlencode($cat) ?>">
            <svg class="nav__icon nav__icon--sm" aria-hidden="true" focusable="false"><use href="#<?= e($icon) ?>"></use></svg> <?= e($cat) ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (can('page.issues') && ($sidebarTags = known_tags($pdo))): ?>
        <p class="nav__label">Tags</p>
        <div class="nav__tags">
          <?php foreach (array_slice($sidebarTags, 0, 6) as $t): ?>
            <a class="tagchip" href="?page=issues&amp;tag=<?= urlencode($t) ?>">#<?= e($t) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (can('page.users')): ?>
        <p class="nav__label">Administration</p>
        <a class="nav__item <?= $page === 'users' ? 'is-active' : '' ?>" href="?page=users">
          <svg class="nav__icon" aria-hidden="true" focusable="false"><use href="#i-users"></use></svg> Users &amp; Authority
        </a>
        <?php if (can('page.logs')): ?>
          <a class="nav__item <?= $page === 'logs' ? 'is-active' : '' ?>" href="?page=logs">
            <svg class="nav__icon" aria-hidden="true" focusable="false"><use href="#i-log"></use></svg> System log
          </a>
        <?php endif; ?>
      <?php endif; ?>
    </nav>

    <div class="sidebar__foot">
      <div class="plant">
        <span class="plant__led"></span>
        Plant 1 · Line 1–4
      </div>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div>
        <h1><?= e($h1) ?></h1>
        <p class="topbar__sub"><?= e($sub) ?></p>
      </div>

      <div class="topbar__meta">
        <button type="button" class="btn btn--ghost btn--sm" data-density-toggle aria-pressed="false"
                title="Switch between compact and comfortable spacing">Compact</button>
        <button type="button" class="btn btn--ghost btn--sm" data-theme-toggle aria-pressed="true"
                title="Bright or dim the interface">Dim</button>
        <button type="button" class="btn btn--ghost btn--sm" data-detail-toggle aria-pressed="false"
                title="Simple shows the essentials; Detailed opens every panel and column">Detailed view</button>

        <span class="pill">ISO 27001 scope</span>
        <span class="pill pill--date"><?= date('D, d M Y') ?></span>

        <?php if ($help = help_for($page)): ?>
          <details class="bell help">
            <summary class="bell__btn" title="How this page works">
              Help <span class="help__mark" aria-hidden="true">?</span>
            </summary>
            <div class="bell__panel help__panel">
              <div class="bell__head"><strong><?= e($help['title']) ?></strong></div>
              <p class="help__intro"><?= e($help['summary']) ?></p>
              <ol class="help__steps">
                <?php foreach ($help['steps'] as $step): ?>
                  <li><?= e($step) ?></li>
                <?php endforeach; ?>
              </ol>
              <p class="help__tip"><strong>Tip</strong> <?= e($help['tip']) ?></p>
            </div>
          </details>
        <?php endif; ?>

        <?php $bell = notify_summary($pdo); ?>
        <details class="bell">
          <summary class="bell__btn" aria-label="Notifications, <?= (int) $bell['unread'] ?> unread">
            Alerts
            <?php if ($bell['unread'] > 0): ?>
              <span class="bell__count"><?= $bell['unread'] > 99 ? '99+' : (int) $bell['unread'] ?></span>
            <?php endif; ?>
          </summary>
          <div class="bell__panel">
            <div class="bell__head">
              <strong><?= (int) $bell['unread'] ?> unread</strong>
              <a class="link" href="?page=notifications">View all</a>
            </div>
            <ul class="bell__list">
              <?php foreach ($bell['items'] as $n): ?>
                <li class="bell__item<?= $n['read_at'] ? '' : ' is-unread' ?>">
                  <a class="bell__link" href="<?= e($n['link'] ?: '?page=notifications') ?>">
                    <span class="bell__dot bell__dot--<?= e($n['tone']) ?>"></span>
                    <span class="bell__text">
                      <strong><?= e($n['title']) ?></strong>
                      <span><?= e($n['body']) ?></span>
                    </span>
                    <span class="bell__when"><?= e(notification_when($n['created_at'])) ?></span>
                  </a>
                </li>
              <?php endforeach; ?>
              <?php if (!$bell['items']): ?>
                <li class="bell__empty">Nothing yet — alerts land here.</li>
              <?php endif; ?>
            </ul>
            <?php if ($bell['unread'] > 0): ?>
              <form class="bell__foot" method="post" action="index.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="notifications_read">
                <input type="hidden" name="back" value="<?= e($page) ?>">
                <?= back_qs_field() ?>
                <button type="submit" class="btn btn--ghost btn--xs">Mark all read</button>
              </form>
            <?php endif; ?>
          </div>
        </details>

        <div class="userchip" title="<?= e($u['email'] ?? '') ?>">
          <span class="userchip__avatar"><?= e(user_initials($u)) ?></span>
          <span class="userchip__text">
            <strong><?= e($u['name'] ?? '') ?></strong>
            <span class="userchip__role userchip__role--<?= role_tone($role) ?>"><?= e(role_label($role)) ?></span>
          </span>
        </div>

        <form method="post" action="index.php" class="logout-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="logout">
          <button type="submit" class="btn btn--ghost btn--sm" title="Sign out">Sign out</button>
        </form>
      </div>
    </header>

    <?php if ($msg = take_flash()): ?>
      <div class="flash" role="status" aria-live="polite"><?= e($msg) ?></div>
    <?php endif; ?>
    <?php if ($err = $_SESSION['form_error'] ?? null): ?>
      <div class="flash flash--error" role="alert"><?= e($err) ?></div>
      <?php unset($_SESSION['form_error']); ?>
    <?php endif; ?>

    <main class="content" id="main">
