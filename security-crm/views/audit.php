<?php
/**
 * Audit trail: every recorded system event, newest first, with filters,
 * counters and a CSV export for auditors.
 */

$f      = audit_filters($_GET);
$res    = audit_search($pdo, $f, max(1, (int) ($_GET['p'] ?? 1)));
$rows   = $res['rows'];
$stats  = audit_stats($pdo);
$acts   = audit_actions();
$ents   = audit_entities();
$narrow = $f['q'] !== '' || $f['action'] !== '' || $f['entity'] !== ''
    || $f['actor'] !== '' || $f['from'] !== '' || $f['to'] !== '';

function audit_url(array $over): string
{
    $base = [
        'page'   => 'audit',
        'q'      => trim((string) ($_GET['q'] ?? '')),
        'action' => (string) ($_GET['action'] ?? ''),
        'entity' => (string) ($_GET['entity'] ?? ''),
        'actor'  => (string) ($_GET['actor'] ?? ''),
        'from'   => (string) ($_GET['from'] ?? ''),
        'to'     => (string) ($_GET['to'] ?? ''),
    ];
    foreach ($over as $k => $v) {
        $base[$k] = $v;
    }
    $base = array_filter($base, static fn($v) => $v !== '' && $v !== null);

    return '?' . http_build_query($base);
}

$exportUrl = '?page=audit&amp;export=csv' . ($narrow ? '&amp;' . e(http_build_query(array_filter([
    'q'      => $f['q'],
    'action' => $f['action'],
    'entity' => $f['entity'],
    'actor'  => $f['actor'],
    'from'   => $f['from'],
    'to'     => $f['to'],
]))) : '');
?>

<section class="grid grid--stats">
  <article class="card stat stat--blue">
    <p class="stat__label">Events today</p>
    <p class="stat__value"><?= number_format($stats['today']) ?></p>
    <p class="stat__hint"><?= number_format($stats['total']) ?> recorded in total</p>
  </article>
  <article class="card stat stat--neutral">
    <p class="stat__label">Last 7 days</p>
    <p class="stat__value"><?= number_format($stats['week']) ?></p>
    <p class="stat__hint"><?= number_format($stats['actors']) ?> distinct actor(s)</p>
  </article>
  <article class="card stat stat--red">
    <p class="stat__label">Failed sign-ins</p>
    <p class="stat__value"><?= number_format($stats['failed']) ?></p>
    <p class="stat__hint">wrong password, lockout, throttle · 24h</p>
  </article>
  <article class="card stat stat--orange">
    <p class="stat__label">Denied actions</p>
    <p class="stat__value"><?= number_format($stats['denied']) ?></p>
    <p class="stat__hint">role refused by a permission · 7d</p>
  </article>
</section>

<form class="card form" method="get" action="index.php">
  <input type="hidden" name="page" value="audit">
  <div class="form__grid">
    <label class="field field--wide">
      <span>Search <span class="muted">(reference, detail, actor)</span></span>
      <input type="search" name="q" value="<?= e($f['q']) ?>" maxlength="100" placeholder="e.g. SEC-1004, password, R. Hartono">
    </label>
    <label class="field">
      <span>Action</span>
      <select name="action">
        <option value="">Any action</option>
        <?php foreach ($acts as $key => [$label, $tone]): ?>
          <option value="<?= e($key) ?>" <?= $f['action'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">
      <span>Record type</span>
      <select name="entity">
        <option value="">Any record</option>
        <?php foreach ($ents as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $f['entity'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">
      <span>Actor</span>
      <input type="text" name="actor" value="<?= e($f['actor']) ?>" maxlength="60" placeholder="Name or username">
    </label>
    <label class="field"><span>From</span><input type="date" name="from" value="<?= e($f['from']) ?>"></label>
    <label class="field"><span>To</span><input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  </div>
  <div class="form__actions">
    <button type="submit" class="btn btn--primary">Apply filters</button>
    <?php if ($narrow): ?><a class="btn btn--ghost" href="?page=audit">Clear</a><?php endif; ?>
    <a class="btn btn--ghost" href="<?= $exportUrl ?>">Export CSV</a>
    <span class="muted"><?= number_format($res['total']) ?> event(s) match · the trail is append-only</span>
  </div>
</form>

<section class="card card--flush">
  <table class="table table--list">
    <caption class="sr-only">System audit trail</caption>
    <thead>
      <tr>
        <th scope="col">When</th>
        <th scope="col">Actor</th>
        <th scope="col">Event</th>
        <th scope="col">Record</th>
        <th scope="col">Detail</th>
        <th scope="col">From</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <?php $ts = strtotime((string) $r['created_at']); ?>
      <tr>
        <td class="nowrap">
          <span class="mono"><?= $ts ? date('d M Y', $ts) : '—' ?></span>
          <span class="muted"><?= $ts ? date('H:i:s', $ts) : '' ?></span>
        </td>
        <td class="nowrap">
          <?= e($r['actor']) ?: '—' ?>
          <span class="muted">· <?= e(role_label((string) $r['role'])) ?></span>
        </td>
        <td><?= badge(audit_action_label((string) $r['action']), audit_action_tone((string) $r['action'])) ?></td>
        <td>
          <?php $url = audit_record_url($r); ?>
          <?php if ($url !== ''): ?>
            <a class="link strong" href="<?= e($url) ?>"><?= e($r['label']) ?: e(audit_entity_label((string) $r['entity'])) ?></a>
          <?php else: ?>
            <span class="strong"><?= e($r['label']) ?: '—' ?></span>
          <?php endif; ?>
          <?php if ((string) $r['entity'] !== ''): ?>
            <span class="muted">
              · <?= e(audit_entity_label((string) $r['entity'])) ?><?= $r['entity_id'] !== null ? ' #' . (int) $r['entity_id'] : '' ?>
            </span>
          <?php endif; ?>
        </td>
        <td><?= e($r['detail']) ?: '<span class="muted">—</span>' ?></td>
        <td class="nowrap mono muted"><?= e($r['ip']) ?: '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="6" class="empty">No audit events match this filter.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>

<?php if ($res['pages'] > 1): ?>
<nav class="pager" aria-label="Audit trail pages">
  <span class="muted">Page <?= (int) $res['page'] ?> of <?= (int) $res['pages'] ?> · <?= number_format($res['total']) ?> events</span>
  <?php if ($res['page'] > 1): ?>
    <a class="btn btn--ghost btn--xs" href="<?= e(audit_url(['p' => $res['page'] - 1])) ?>">← Newer</a>
  <?php endif; ?>
  <?php if ($res['page'] < $res['pages']): ?>
    <a class="btn btn--ghost btn--xs" href="<?= e(audit_url(['p' => $res['page'] + 1])) ?>">Older →</a>
  <?php endif; ?>
</nav>
<?php endif; ?>

<p class="footnote">
  Entries are written by the application and never edited: no screen updates or deletes them.
  Coverage — sign-in, sign-out, refused actions, issues, status changes, report forms, notes, people,
  evidence, tasks, templates, exceptions, categories, users and exports. Export the filtered view as CSV
  for an audit pack; retention follows your database backup policy.
</p>
