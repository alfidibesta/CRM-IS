<?php
/** Dashboard: posture at a glance, risk distribution, activity and templates. */

$statSops   = (int) $pdo->query("SELECT COUNT(*) FROM sops WHERE status = 'Active'")->fetchColumn();
$statReview = (int) $pdo->query("SELECT COUNT(*) FROM sops WHERE status = 'In Review'")->fetchColumn();
$statOpen   = (int) $pdo->query("SELECT COUNT(*) FROM issues WHERE status IN ('Open','In Progress')")->fetchColumn();
$statCrit   = (int) $pdo->query("SELECT COUNT(*) FROM issues WHERE severity = 'Critical' AND status <> 'Resolved'")->fetchColumn();
$statOver   = (int) $pdo->query("SELECT COUNT(*) FROM issues WHERE due_at < CURDATE() AND status <> 'Resolved'")->fetchColumn();
$statFiles  = (int) $pdo->query('SELECT COUNT(*) FROM issue_files')->fetchColumn();
$statPeople = (int) $pdo->query('SELECT COUNT(*) FROM suspects')->fetchColumn();
$statTpl    = (int) $pdo->query('SELECT COUNT(*) FROM templates')->fetchColumn();
$statUsers  = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn();

$byCategory = $pdo->query("SELECT category,
        SUM(status <> 'Resolved') AS open,
        SUM(severity = 'Critical' AND status <> 'Resolved') AS critical
    FROM issues GROUP BY category ORDER BY FIELD(category,'Physical Security','IT Security','Strain Security')")->fetchAll();

$byStatus = $pdo->query("SELECT status, COUNT(*) c FROM issues GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

$byRating = ['Low' => 0, 'Moderate' => 0, 'High' => 0, 'Critical' => 0];
$heat = $pdo->query('SELECT likelihood, impact, COUNT(*) c FROM issues WHERE status <> "Resolved" GROUP BY likelihood, impact')->fetchAll();
foreach ($heat as $h) {
    $byRating[risk_rating((int) $h['likelihood'] * (int) $h['impact'])[0]] += (int) $h['c'];
}

$upcoming = $pdo->query("SELECT code, title, review_at, owner FROM sops
    WHERE status <> 'Obsolete' AND review_at IS NOT NULL AND review_at >= CURDATE()
    ORDER BY review_at ASC LIMIT 5")->fetchAll();

$recent = $pdo->query("SELECT id, code, title, category, severity, status, owner, due_at, risk_score FROM issues
    ORDER BY reported_at DESC, id DESC LIMIT 6")->fetchAll();

$feed = $pdo->query('SELECT e.message, e.actor, e.kind, e.created_at, i.code, i.id AS issue_id
    FROM issue_events e JOIN issues i ON i.id = e.issue_id
    ORDER BY e.created_at DESC, e.id DESC LIMIT 6')->fetchAll();

$tplTop = $pdo->query('SELECT id, code, name, category FROM templates ORDER BY downloads DESC, code LIMIT 6')->fetchAll();

$load = $pdo->query('SELECT column_key, COUNT(*) c FROM tasks GROUP BY column_key')->fetchAll(PDO::FETCH_KEY_PAIR);
$totalTasks = array_sum($load) ?: 1;

$ratingTone = ['Low' => 'green', 'Moderate' => 'blue', 'High' => 'orange', 'Critical' => 'red'];
?>

<section class="grid grid--stats">
  <article class="card stat stat--blue">
    <p class="stat__label">Active documents</p>
    <p class="stat__value"><?= $statSops ?></p>
    <p class="stat__hint"><?= $statReview ?> in review</p>
  </article>
  <article class="card stat stat--red">
    <p class="stat__label">Open issues</p>
    <p class="stat__value"><?= $statOpen ?></p>
    <p class="stat__hint"><?= $statCrit ?> critical · <?= $statOver ?> overdue</p>
  </article>
  <article class="card stat stat--orange">
    <p class="stat__label">Tasks in flight</p>
    <p class="stat__value"><?= (int) ($load['in_progress'] ?? 0) ?></p>
    <p class="stat__hint"><?= (int) ($load['review'] ?? 0) ?> awaiting review</p>
  </article>
  <article class="card stat stat--neutral">
    <p class="stat__label">Board completion</p>
    <p class="stat__value"><?= (int) round(((int) ($load['done'] ?? 0)) / $totalTasks * 100) ?>%</p>
    <p class="stat__hint"><?= (int) ($load['done'] ?? 0) ?> of <?= $totalTasks ?> tasks done</p>
  </article>
  <article class="card stat stat--red">
    <p class="stat__label">Evidence on file</p>
    <p class="stat__value"><?= $statFiles ?></p>
    <p class="stat__hint"><?= $statPeople ?> person record(s)</p>
  </article>
  <article class="card stat stat--blue">
    <p class="stat__label">Form templates</p>
    <p class="stat__value"><?= $statTpl ?></p>
    <p class="stat__hint">NDA, agreements, forms</p>
  </article>
  <article class="card stat stat--orange">
    <p class="stat__label">Accounts</p>
    <p class="stat__value"><?= $statUsers ?></p>
    <p class="stat__hint">signed in as <?= e(role_label(current_user()['role'] ?? '')) ?></p>
  </article>
  <article class="card stat stat--neutral">
    <p class="stat__label">Board columns</p>
    <p class="stat__value"><?= count($load) ?></p>
    <p class="stat__hint">backlog → done</p>
  </article>
</section>

<section class="grid grid--two">
  <article class="card">
    <header class="card__head">
      <h2>Issues by category</h2>
      <a class="link" href="?page=issues">Open list</a>
    </header>
    <ul class="bars">
      <?php foreach ($byCategory as $row): ?>
        <li>
          <div class="bars__row">
            <span class="chip-dot chip-dot--<?= scope_tone($row['category']) ?>"></span>
            <span class="bars__name"><?= e($row['category']) ?></span>
            <span class="bars__val"><?= (int) $row['open'] ?> open<?= $row['critical'] ? ' · ' . (int) $row['critical'] . ' critical' : '' ?></span>
          </div>
          <?php $pct = $statOpen ? max(4, round($row['open'] / $statOpen * 100)) : 0; ?>
          <div class="bars__track"><span class="bars__fill bars__fill--<?= scope_tone($row['category']) ?>" style="width: <?= $pct ?>%"></span></div>
        </li>
      <?php endforeach; ?>
    </ul>

    <header class="card__head card__head--sub">
      <h3>Status split</h3>
    </header>
    <div class="chips">
      <?php foreach (['Open', 'In Progress', 'Contained', 'Resolved'] as $s): ?>
        <span class="chip chip--<?= status_tone($s) ?>"><?= e($s) ?> <b><?= (int) ($byStatus[$s] ?? 0) ?></b></span>
      <?php endforeach; ?>
    </div>

    <header class="card__head card__head--sub">
      <h3>Risk ratings</h3>
      <span class="muted">open issues</span>
    </header>
    <ul class="ratinglist">
      <?php foreach ($byRating as $name => $n): ?>
        <li>
          <span class="ratinglist__dot ratinglist__dot--<?= $ratingTone[$name] ?>"></span>
          <span class="ratinglist__name"><?= e($name) ?></span>
          <span class="ratinglist__bar"><span class="bars__fill bars__fill--<?= $ratingTone[$name] ?>" style="width: <?= $statOpen ? max(4, round($n / max(1, array_sum($byRating)) * 100)) : 0 ?>%"></span></span>
          <span class="ratinglist__n"><?= $n ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </article>

  <article class="card">
    <header class="card__head">
      <h2>Latest activity</h2>
      <span class="muted">across all reports</span>
    </header>
    <ul class="feed">
      <?php foreach ($feed as $f): ?>
        <li class="feed__item feed__item--<?= e($f['kind']) ?>">
          <span class="feed__dot"></span>
          <div>
            <a class="feed__text" href="?page=issue&amp;id=<?= (int) $f['issue_id'] ?>">
              <b><?= e($f['code']) ?></b> — <?= e($f['message']) ?>
            </a>
            <p class="feed__meta"><?= e($f['actor']) ?> · <?= date('d M Y · H:i', strtotime((string) $f['created_at'])) ?></p>
          </div>
        </li>
      <?php endforeach; ?>
      <?php if (!$feed): ?><li class="feed__item"><p class="muted">No activity yet.</p></li><?php endif; ?>
    </ul>

    <header class="card__head card__head--sub">
      <h3>Next document reviews</h3>
      <a class="link" href="?page=sop">Library</a>
    </header>
    <table class="table table--tight">
      <tbody>
      <?php foreach ($upcoming as $u): ?>
        <tr>
          <td class="mono"><?= e($u['code']) ?></td>
          <td><?= e($u['title']) ?><span class="muted"> · <?= e($u['owner']) ?></span></td>
          <td class="nowrap"><?= fmt_date($u['review_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$upcoming): ?>
        <tr><td class="muted">Nothing scheduled.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </article>
</section>

<section class="card">
  <header class="card__head">
    <h2>Recently reported</h2>
    <a class="link" href="?page=issues">View all issues</a>
  </header>
  <table class="table">
    <thead>
      <tr><th>#</th><th>Issue</th><th>Category</th><th>Severity</th><th class="center">Risk</th><th>Status</th><th>Owner</th><th>Due</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <?php $score = (int) $r['risk_score']; ?>
      <tr>
        <td class="mono"><?= e($r['code']) ?></td>
        <td>
          <a class="strong link" href="?page=issue&amp;id=<?= (int) $r['id'] ?>"><?= e($r['title']) ?></a>
        </td>
        <td><span class="tag tag--<?= scope_tone($r['category']) ?>"><?= e($r['category']) ?></span></td>
        <td><?= badge($r['severity'], severity_tone($r['severity'])) ?></td>
        <td class="center"><span class="riskscore riskscore--sm riskscore--<?= risk_tone($score) ?>"><?= $score ?></span></td>
        <td><?= badge($r['status'], status_tone($r['status'])) ?></td>
        <td><?= e($r['owner']) ?></td>
        <td class="nowrap"><?= fmt_date($r['due_at']) ?></td>
        <td class="right"><a class="btn btn--ghost btn--xs" href="?page=issue&amp;id=<?= (int) $r['id'] ?>">Report</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php if (can('page.templates') && $tplTop): ?>
<section class="card">
  <header class="card__head">
    <h2>Form templates</h2>
    <a class="link" href="?page=templates">Open library</a>
  </header>
  <div class="quickgrid">
    <?php foreach ($tplTop as $t): ?>
      <a class="quickcard" href="?page=doc&amp;id=<?= (int) $t['id'] ?>">
        <span class="quickcard__code mono"><?= e($t['code']) ?></span>
        <span class="quickcard__name"><?= e($t['name']) ?></span>
        <span class="muted"><?= e($t['category']) ?> · download .docx</span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
