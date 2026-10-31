<?php
/**
 * Sign-in screen — rendered with its own chrome (no sidebar).
 */

$error   = $_SESSION['login_error'] ?? null;
$prefill = $_SESSION['login_user'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_user']);

$demo = [
    ['admin',   'Admin@123',   'Administrator', 'Everything, including users'],
    ['manager', 'Manager@123', 'Security Manager', 'All records + templates'],
    ['analyst', 'Analyst@123', 'Security Analyst', 'Investigate, upload, move cards'],
    ['viewer',  'Viewer@123',  'Auditor / Viewer', 'Read-only, downloads'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · SecOps CRM</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-page">
<div class="login">

  <section class="login__brand">
    <div class="brand brand--light">
      <div class="brand__mark">S</div>
      <div class="brand__text">
        <strong>SecOps CRM</strong>
        <span>Manufacturing · ISMS</span>
      </div>
    </div>

    <div class="login__pitch">
      <h1>Information security work management for the plant floor.</h1>
      <p>Issue reports with evidence and suspect records, a workflow board, a controlled SOP library and Word-exportable forms — with role-based authority on every action.</p>
    </div>

    <ul class="login__points">
      <li><span class="chip-dot chip-dot--red"></span> Physical Security — access, gates, escorts, CCTV</li>
      <li><span class="chip-dot chip-dot--blue"></span> IT Security — accounts, network, incidents, patches</li>
      <li><span class="chip-dot chip-dot--orange"></span> Strain Security — fatigue, workload, human factor</li>
    </ul>

    <p class="login__foot">ISO 27001 scope · Plant 1 · Internal use only</p>
  </section>

  <section class="login__panel">
    <div class="login__card">
      <h2>Sign in</h2>
      <p class="muted">Use the account issued by your Security Manager.</p>

      <?php if ($error): ?>
        <div class="flash flash--error"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" action="index.php" class="form form--login">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="login">
        <label class="field">
          <span>Username</span>
          <input type="text" name="username" value="<?= e($prefill) ?>" required autofocus
                 autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="admin">
        </label>
        <label class="field">
          <span>Password</span>
          <input type="password" name="password" required autocomplete="current-password"
                 placeholder="••••••••">
        </label>
        <button type="submit" class="btn btn--primary btn--block">Sign in</button>
      </form>

      <div class="login__demo">
        <p class="login__demo-title">Demo accounts</p>
        <table class="table table--tight">
          <tbody>
          <?php foreach ($demo as [$user, $pass, $role, $hint]): ?>
            <tr>
              <td class="mono strong"><?= e($user) ?></td>
              <td class="mono"><?= e($pass) ?></td>
              <td><?= e($role) ?><span class="muted"> · <?= e($hint) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="footnote">5 failed attempts lock an account for 15 minutes.</p>
      </div>
    </div>
  </section>

</div>
</body>
</html>
