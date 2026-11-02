<?php
/**
 * Notification centre: everything the bell collects for the signed-in user,
 * unread first, with the record behind each alert one click away.
 */

$f     = notify_filters($_GET);
$uid   = (int) (current_user()['id'] ?? 0);
$res   = notify_fetch($pdo, $uid, $f, max(1, (int) ($_GET['p'] ?? 1)));
$rows  = $res['rows'];
$kinds = notification_kinds();

$cnt = $pdo->prepare('SELECT COUNT(*) AS total, SUM(read_at IS NULL) AS unread FROM notifications WHERE user_id = ?');
$cnt->execute([$uid]);
$counts = $cnt->fetch() ?: ['total' => 0, 'unread' => 0];
$unread = (int) ($counts['unread'] ?? 0);
$all    = (int) ($counts['total'] ?? 0);

function notif_url(array $over): string
{
    $base = [
        'page'  => 'notifications',
        'state' => (string) ($_GET['state'] ?? ''),
        'kind'  => (string) ($_GET['kind'] ?? ''),
    ];
    foreach ($over as $k => $v) {
        $base[$k] = $v;
    }
    $base = array_filter($base, static fn($v) => $v !== '' && $v !== null);

    return '?' . http_build_query($base);
}
?>

<div class="toolbar">
  <div class="chips chips--filter">
    <span class="chips__label">Show</span>
    <a class="chip <?= $f['state'] === 'unread' ? 'is-on' : '' ?>" href="<?= e(notif_url(['state' => '', 'kind' => $f['kind']])) ?>">Unread <b><?= $unread ?></b></a>
    <a class="chip <?= $f['state'] === 'all' ? 'is-on' : '' ?>" href="<?= e(notif_url(['state' => 'all', 'kind' => $f['kind']])) ?>">All <b><?= $all ?></b></a>
  </div>

  <div class="chips chips--filter">
    <span class="chips__label">Type</span>
    <a class="chip <?= $f['kind'] === '' ? 'is-on' : '' ?>" href="<?= e(notif_url(['kind' => ''])) ?>">Any</a>
    <?php foreach ($kinds as $key => [$label, $tone]): ?>
      <a class="chip chip--<?= e($tone) ?> <?= $f['kind'] === $key ? 'is-on' : '' ?>" href="<?= e(notif_url(['kind' => $f['kind'] === $key ? '' : $key])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($unread > 0): ?>
    <form method="post" action="index.php" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="notifications_read">
      <input type="hidden" name="back" value="notifications">
      <button type="submit" class="btn btn--ghost">Mark all <?= $unread ?> read</button>
    </form>
  <?php endif; ?>
</div>

<section class="card card--flush">
  <table class="table table--list">
    <caption class="sr-only">Your notifications</caption>
    <thead>
      <tr>
        <th scope="col">When</th>
        <th scope="col">Type</th>
        <th scope="col">Notification</th>
        <th scope="col"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $n): ?>
      <tr class="<?= $n['read_at'] ? '' : 'row--unread' ?>">
        <td class="nowrap">
          <span class="mono"><?= e(notification_when($n['created_at'])) ?></span>
          <?php if (!$n['read_at']): ?><span class="chip-dot chip-dot--blue" title="Unread"></span><?php endif; ?>
        </td>
        <td><?= badge(notification_label((string) $n['kind']), notification_tone((string) $n['kind'])) ?></td>
        <td>
          <span class="strong"><?= e($n['title']) ?></span>
          <?php if (trim((string) $n['body']) !== ''): ?>
            <span class="muted"> — <?= e($n['body']) ?></span>
          <?php endif; ?>
        </td>
        <td class="right nowrap">
          <?php if (trim((string) $n['link']) !== ''): ?>
            <a class="btn btn--ghost btn--xs" href="<?= e($n['link']) ?>">Open</a>
          <?php endif; ?>
          <?php if (!$n['read_at']): ?>
            <form method="post" action="index.php" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="notification_read">
              <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
              <input type="hidden" name="back" value="notifications">
              <button type="submit" class="btn btn--ghost btn--xs">Mark read</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="4" class="empty">
        <?= $f['state'] === 'all' ? 'No notifications yet.' : 'Nothing unread — you are all caught up.' ?>
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>

<?php if ($res['pages'] > 1): ?>
<nav class="pager" aria-label="Notification pages">
  <span class="muted">Page <?= (int) $res['page'] ?> of <?= (int) $res['pages'] ?> · <?= (int) $res['total'] ?> shown in this filter</span>
  <?php if ($res['page'] > 1): ?>
    <a class="btn btn--ghost btn--xs" href="<?= e(notif_url(['p' => $res['page'] - 1])) ?>">← Newer</a>
  <?php endif; ?>
  <?php if ($res['page'] < $res['pages']): ?>
    <a class="btn btn--ghost btn--xs" href="<?= e(notif_url(['p' => $res['page'] + 1])) ?>">Older →</a>
  <?php endif; ?>
</nav>
<?php endif; ?>

<p class="footnote">
  Alerts are written for you only, and only when a rule matches: an issue is assigned to you, a
  Critical issue is raised, one of your issues is due within three days or overdue, an EDLP exception
  expires within 30 days, or a SOP or policy review falls due. Opening an alert does not clear it —
  use <b>Mark read</b> or <b>Mark all read</b>, so "unread" keeps meaning unprocessed.
</p>
