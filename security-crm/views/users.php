<?php
/**
 * Users & authority: accounts plus the role/permission matrix.
 * Requires the `page.users` permission (administrators only).
 */

$users = $pdo->query('SELECT * FROM users ORDER BY FIELD(role,\'admin\',\'manager\',\'analyst\',\'viewer\'), username')->fetchAll();

$roles = role_list();
$perms = permission_list();
$canManage = can('user.manage');

$stats = [
    'total'  => count($users),
    'active' => count(array_filter($users, fn($u) => (int) $u['active'])),
    'locked' => count(array_filter($users, fn($u) => $u['locked_until'] !== null && strtotime((string) $u['locked_until']) > time())),
];
?>

<section class="grid grid--stats">
  <article class="card stat stat--blue">
    <p class="stat__label">Accounts</p>
    <p class="stat__value"><?= $stats['total'] ?></p>
    <p class="stat__hint"><?= $stats['active'] ?> active</p>
  </article>
  <article class="card stat stat--red">
    <p class="stat__label">Locked out</p>
    <p class="stat__value"><?= $stats['locked'] ?></p>
    <p class="stat__hint">5 failures = 15 min</p>
  </article>
  <article class="card stat stat--orange">
    <p class="stat__label">Roles in use</p>
    <p class="stat__value"><?= count(array_unique(array_column($users, 'role'))) ?></p>
    <p class="stat__hint">admin · manager · analyst · viewer</p>
  </article>
  <article class="card stat stat--neutral">
    <p class="stat__label">Permissions</p>
    <p class="stat__value"><?= count($perms) ?></p>
    <p class="stat__hint">enforced on every action</p>
  </article>
</section>

<div class="grid grid--two">
  <!-- ------------------------------------------------------------ accounts -->
  <section class="card">
    <header class="card__head">
      <h2>Accounts</h2>
      <span class="muted"><?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?></span>
    </header>

    <div class="table-wrap">
      <table class="table table--tight">
        <thead>
          <tr><th>User</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <?php
            $locked = $u['locked_until'] !== null && strtotime((string) $u['locked_until']) > time();
            $isMe   = (int) $u['id'] === (int) (current_user()['id'] ?? 0);
          ?>
          <tr>
            <td>
              <div class="userline">
                <span class="avatar avatar--<?= role_tone($u['role']) ?>"><?= e(user_initials($u)) ?></span>
                <span>
                  <span class="strong"><?= e($u['name']) ?></span><?= $isMe ? ' <span class="muted">(you)</span>' : '' ?>
                  <span class="muted mono"> · <?= e($u['username']) ?></span><br>
                  <span class="muted"><?= e($u['email']) ?: 'no email' ?></span>
                </span>
              </div>
            </td>
            <td><?= badge(role_label($u['role']), role_tone($u['role'])) ?></td>
            <td>
              <?php if (!(int) $u['active']): ?>
                <?= badge('Disabled', 'neutral') ?>
              <?php elseif ($locked): ?>
                <?= badge('Locked', 'red') ?>
              <?php else: ?>
                <?= badge('Active', 'green') ?>
              <?php endif; ?>
              <?php if ((int) $u['failed_attempts'] > 0): ?>
                <span class="muted"><?= (int) $u['failed_attempts'] ?> failed</span>
              <?php endif; ?>
            </td>
            <td class="nowrap"><?= $u['last_login'] ? date('d M Y · H:i', strtotime((string) $u['last_login'])) : 'never' ?></td>
            <td class="right">
              <?php if ($canManage): ?>
                <details class="detail detail--inline">
                  <summary class="btn btn--ghost btn--xs">Edit</summary>
                  <div class="detail__body detail__body--card">
                    <form class="form form--sub" method="post" action="index.php">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="update_user">
                      <input type="hidden" name="back" value="users">
                      <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                      <div class="form__grid">
                        <label class="field field--wide"><span>Name</span>
                          <input type="text" name="name" maxlength="100" value="<?= e($u['name']) ?>">
                        </label>
                        <label class="field field--wide"><span>Email</span>
                          <input type="email" name="email" maxlength="120" value="<?= e($u['email']) ?>">
                        </label>
                        <label class="field"><span>Role</span>
                          <select name="role" <?= $isMe ? 'disabled' : '' ?>>
                            <?php foreach ($roles as $key => $r): ?>
                              <option value="<?= $key ?>" <?= $u['role'] === $key ? 'selected' : '' ?>><?= e($r['label']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <label class="field"><span>New password</span>
                          <input type="password" name="password" autocomplete="new-password" placeholder="leave blank to keep">
                        </label>
                        <label class="field field--check">
                          <input type="checkbox" name="active" value="1" <?= (int) $u['active'] ? 'checked' : '' ?> <?= $isMe ? 'disabled' : '' ?>>
                          <span>Account active</span>
                        </label>
                      </div>
                      <div class="form__actions">
                        <button type="submit" class="btn btn--primary btn--sm">Save</button>
                        <span class="muted">Blank password = unchanged</span>
                      </div>
                    </form>

                    <?php if (!$isMe): ?>
                      <form class="inline-form inline-form--danger" method="post" action="index.php"
                            data-confirm="Delete <?= e($u['username']) ?>? This cannot be undone.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="back" value="users">
                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" class="btn btn--ghost btn--sm">Delete user</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </details>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($canManage): ?>
      <details class="panel panel--sub" <?= count($users) < 2 ? 'open' : '' ?>>
        <summary class="btn btn--ghost btn--summary btn--sm">+ Add user</summary>
        <form class="form form--sub" method="post" action="index.php">
          <?= csrf_field() ?>
          <?= once_field() ?>
          <input type="hidden" name="action" value="create_user">
          <input type="hidden" name="back" value="users">
          <div class="form__grid">
            <label class="field"><span>Username *</span>
              <input type="text" name="username" required minlength="3" maxlength="40"
                     autocapitalize="none" spellcheck="false" placeholder="j.santoso">
            </label>
            <label class="field"><span>Display name *</span><input type="text" name="name" required maxlength="100"></label>
            <label class="field"><span>Email</span><input type="email" name="email" maxlength="120"></label>
            <label class="field"><span>Role</span>
              <select name="role">
                <?php foreach ($roles as $key => $r): ?>
                  <option value="<?= $key ?>" <?= $key === 'viewer' ? 'selected' : '' ?>><?= e($r['label']) ?> — <?= e($r['hint']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field"><span>Password * (min 8)</span>
              <input type="text" name="password" required minlength="8" autocomplete="new-password"
                     placeholder="min. 8 characters">
            </label>
          </div>
          <div class="form__actions">
            <button type="submit" class="btn btn--primary btn--sm">Create account</button>
          </div>
        </form>
      </details>
    <?php endif; ?>
  </section>

  <!-- ---------------------------------------------------------- role cards -->
  <section class="card">
    <header class="card__head">
      <h2>Roles</h2>
      <span class="muted">what each role can do</span>
    </header>

    <div class="rolelist">
      <?php foreach ($roles as $key => $r): ?>
        <article class="rolecard rolecard--<?= role_tone($key) ?>">
          <header>
            <span class="strong"><?= e($r['label']) ?></span>
            <span class="muted mono"><?= $key ?></span>
          </header>
          <p class="muted"><?= e($r['hint']) ?></p>
          <p class="rolecard__count">
            <b><?= in_array('*', $r['perms'], true) ? 'All ' . count($perms) : count($r['perms']) ?></b> of <?= count($perms) ?> permissions
          </p>
          <p class="rolecard__people muted">
            <?= count(array_filter($users, fn($u) => $u['role'] === $key)) ?> account(s)
          </p>
        </article>
      <?php endforeach; ?>
    </div>

    <header class="card__head card__head--sub">
      <h3>Authority matrix</h3>
      <span class="muted">✓ = allowed</span>
    </header>

    <div class="table-wrap table-wrap--scroll">
      <table class="table table--tight table--matrix">
        <thead>
          <tr>
            <th>Permission</th>
            <?php foreach ($roles as $key => $r): ?>
              <th class="center"><?= e($r['label']) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($perms as $pkey => $plabel): ?>
            <tr>
              <td><span class="mono muted"><?= e($pkey) ?></span><br><?= e($plabel) ?></td>
              <?php foreach ($roles as $key => $r): ?>
                <?php $on = in_array('*', $r['perms'], true) || in_array($pkey, $r['perms'], true); ?>
                <td class="center"><?= $on ? '<span class="mark mark--on">✓</span>' : '<span class="mark mark--off">·</span>' ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<section class="card">
  <header class="card__head">
    <h2>How authority is enforced</h2>
  </header>
  <ul class="bullets">
    <li>Every page and every POST action is checked against the signed-in role before it runs — a missing permission returns <b>403</b>.</li>
    <li>Sign-in issues a new session id, all forms carry a CSRF token, and 5 failed passwords lock the account for 15 minutes.</li>
    <li>Passwords are stored as bcrypt hashes; evidence files are served only through a permission-checked download route.</li>
    <li>Changing a role takes effect on that user's next request — no restart needed.</li>
  </ul>
</section>
