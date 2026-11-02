<?php
[$h1, $sub] = $titles[$page];

$u    = current_user();
$role = $u['role'] ?? 'viewer';

$nav = [
    ['dashboard', 'Overview',  'blue',   'page.dashboard'],
    ['issues',    'Issues',    'red',    'page.issues'],
    ['workflow',  'Workflow',  'orange', 'page.workflow'],
    ['sop',       'SOP & Policy', 'blue', 'page.sop'],
    ['templates', 'Templates', 'neutral', 'page.templates'],
    ['exceptions', 'EDLP Exceptions', 'orange', 'page.exceptions'],
    ['audit',     'Audit trail',  'neutral', 'page.audit'],
];

$categories = ['Physical Security' => 'red', 'IT Security' => 'blue', 'Strain Security' => 'orange'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($h1) ?> · SecOps CRM</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
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
      <?php foreach ($nav as [$key, $label, $tone, $perm]): ?>
        <?php if (!can($perm)) continue; ?>
        <?php $isActive = $page === $key || ($key === 'issues' && $page === 'issue') || ($key === 'templates' && $page === 'template'); ?>
        <a class="nav__item <?= $isActive ? 'is-active' : '' ?>"
           href="?page=<?= $key ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
          <span class="nav__dot nav__dot--<?= $tone ?>"></span> <?= e($label) ?>
        </a>
      <?php endforeach; ?>

      <?php if (can('page.issues')): ?>
        <p class="nav__label">Categories</p>
        <?php foreach ($categories as $cat => $tone): ?>
          <a class="nav__item nav__item--sm" href="?page=issues&amp;cat=<?= urlencode($cat) ?>">
            <span class="chip-dot chip-dot--<?= $tone ?>"></span> <?= e($cat) ?>
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
          <span class="nav__dot nav__dot--orange"></span> Users &amp; Authority
        </a>
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
        <span class="pill">ISO 27001 scope</span>
        <span class="pill pill--date"><?= date('D, d M Y') ?></span>

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
