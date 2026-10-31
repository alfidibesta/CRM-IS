<?php
/**
 * Security issue register: Physical / IT / Strain — with risk scores and
 * a link through to the full detail report.
 */

$cat = $_GET['cat'] ?? '';
$sev = $_GET['sev'] ?? '';
$stt = $_GET['st'] ?? '';
$q   = trim($_GET['q'] ?? '');

$where = [];
$args  = [];
if (in_array($cat, ['Physical Security', 'IT Security', 'Strain Security'], true)) { $where[] = 'category = ?'; $args[] = $cat; }
if (in_array($sev, ['Critical', 'High', 'Medium', 'Low'], true))                  { $where[] = 'severity = ?'; $args[] = $sev; }
if (in_array($stt, ['Open', 'In Progress', 'Contained', 'Resolved'], true))       { $where[] = 'status = ?';   $args[] = $stt; }
if ($q !== '') { $where[] = '(title LIKE ? OR code LIKE ? OR owner LIKE ? OR location LIKE ? OR department LIKE ? OR asset LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
$join = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$sql = 'SELECT * FROM issues' . $join
     . " ORDER BY FIELD(severity,'Critical','High','Medium','Low'), reported_at DESC, id DESC";
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

function issue_url(array $over): string
{
    $base = ['page' => 'issues', 'cat' => $_GET['cat'] ?? '', 'sev' => $_GET['sev'] ?? '', 'st' => $_GET['st'] ?? '', 'q' => trim($_GET['q'] ?? '')];
    return '?' . http_build_query(array_merge($base, $over));
}

$catCounts = $pdo->query('SELECT category, COUNT(*) c FROM issues GROUP BY category')->fetchAll(PDO::FETCH_KEY_PAIR);
$sevCounts = $pdo->query("SELECT severity, COUNT(*) c FROM issues WHERE status <> 'Resolved' GROUP BY severity")->fetchAll(PDO::FETCH_KEY_PAIR);

$stats = [
    'open'     => (int) $pdo->query("SELECT COUNT(*) FROM issues WHERE status IN ('Open','In Progress')")->fetchColumn(),
    'critical' => (int) $pdo->query("SELECT COUNT(*) FROM issues WHERE severity='Critical' AND status <> 'Resolved'")->fetchColumn(),
    'overdue'  => (int) $pdo->query("SELECT COUNT(*) FROM issues WHERE due_at < CURDATE() AND status <> 'Resolved'")->fetchColumn(),
    'avgRisk'  => (int) round((float) $pdo->query('SELECT AVG(risk_score) FROM issues WHERE status <> "Resolved"')->fetchColumn()),
    'people'   => (int) $pdo->query('SELECT COUNT(*) FROM suspects')->fetchColumn(),
    'files'    => (int) $pdo->query('SELECT COUNT(*) FROM issue_files')->fetchColumn(),
];
$canCreate = can('issue.create');
$canExport = can('issue.export');
?>

<section class="grid grid--stats">
  <article class="card stat stat--red">
    <p class="stat__label">Open issues</p>
    <p class="stat__value"><?= $stats['open'] ?></p>
    <p class="stat__hint"><?= $stats['critical'] ?> critical unresolved</p>
  </article>
  <article class="card stat stat--orange">
    <p class="stat__label">Overdue</p>
    <p class="stat__value"><?= $stats['overdue'] ?></p>
    <p class="stat__hint">past target close date</p>
  </article>
  <article class="card stat stat--blue">
    <p class="stat__label">Average risk</p>
    <p class="stat__value"><?= $stats['avgRisk'] ?></p>
    <p class="stat__hint"><?= e(risk_rating($stats['avgRisk'])[0]) ?> · 5×5 scoring</p>
  </article>
  <article class="card stat stat--neutral">
    <p class="stat__label">Evidence on file</p>
    <p class="stat__value"><?= $stats['files'] ?></p>
    <p class="stat__hint"><?= $stats['people'] ?> person record(s)</p>
  </article>
</section>

<?php if ($canCreate): ?>
<details class="panel" <?= isset($_GET['new']) ? 'open' : '' ?>>
  <summary class="btn btn--primary btn--summary">+ Report new issue</summary>
  <form class="card form" method="post" action="index.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_issue">
    <input type="hidden" name="back" value="issues">
    <div class="form__grid">
      <label class="field field--wide">
        <span>Title</span>
        <input type="text" name="title" required maxlength="200" placeholder="e.g. Unlocked control cabinet in Line 2">
      </label>
      <label class="field"><span>Category</span>
        <select name="category">
          <option>Physical Security</option>
          <option>IT Security</option>
          <option>Strain Security</option>
        </select>
      </label>
      <label class="field"><span>Severity</span>
        <select name="severity">
          <option>Critical</option><option>High</option><option selected>Medium</option><option>Low</option>
        </select>
      </label>
      <label class="field"><span>Likelihood</span>
        <select name="likelihood">
          <?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>" <?= $i === 3 ? 'selected' : '' ?>><?= e(likelihood_label($i)) ?></option><?php endfor; ?>
        </select>
      </label>
      <label class="field"><span>Impact</span>
        <select name="impact">
          <?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>" <?= $i === 3 ? 'selected' : '' ?>><?= e(impact_label($i)) ?></option><?php endfor; ?>
        </select>
      </label>
      <label class="field"><span>Owner</span><input type="text" name="owner" maxlength="100" placeholder="Name"></label>
      <label class="field"><span>Location</span><input type="text" name="location" maxlength="120" placeholder="Plant / line / room"></label>
      <label class="field"><span>Department</span><input type="text" name="department" maxlength="100"></label>
      <label class="field"><span>Asset / system</span><input type="text" name="asset" maxlength="140"></label>
      <label class="field"><span>Reported by</span><input type="text" name="reported_by" maxlength="100" value="<?= e(current_user_name()) ?>"></label>
      <label class="field"><span>Reported</span><input type="date" name="reported_at" value="<?= date('Y-m-d') ?>"></label>
      <label class="field"><span>Due</span><input type="date" name="due_at"></label>
      <label class="field field--wide"><span>Description</span>
        <textarea name="description" rows="3" placeholder="What happened, evidence, immediate action taken"></textarea>
      </label>
      <label class="field field--wide"><span>Root cause <span class="muted">(optional)</span></span>
        <textarea name="root_cause" rows="2"></textarea>
      </label>
    </div>
    <div class="form__actions">
      <button type="submit" class="btn btn--primary">Create issue</button>
      <span class="muted">Opens the full report page so you can add evidence and suspects.</span>
    </div>
  </form>
</details>
<?php endif; ?>

<div class="toolbar">
  <div class="chips chips--filter">
    <span class="chips__label">Category</span>
    <a class="chip <?= !$cat ? 'is-on' : '' ?>" href="<?= e(issue_url(['cat' => ''])) ?>">All <b><?= (int) array_sum($catCounts) ?></b></a>
    <?php foreach (['Physical Security', 'IT Security', 'Strain Security'] as $c): ?>
      <a class="chip chip--<?= scope_tone($c) ?> <?= $cat === $c ? 'is-on' : '' ?>" href="<?= e(issue_url(['cat' => $cat === $c ? '' : $c])) ?>">
        <?= e($c) ?> <b><?= (int) ($catCounts[$c] ?? 0) ?></b>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="chips chips--filter">
    <span class="chips__label">Severity</span>
    <?php foreach (['Critical', 'High', 'Medium', 'Low'] as $s): ?>
      <a class="chip <?= $sev === $s ? 'is-on' : '' ?>" href="<?= e(issue_url(['sev' => $sev === $s ? '' : $s])) ?>">
        <?= $s ?> <b><?= (int) ($sevCounts[$s] ?? 0) ?></b>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="chips chips--filter">
    <span class="chips__label">Status</span>
    <?php foreach (['Open', 'In Progress', 'Contained', 'Resolved'] as $s): ?>
      <a class="chip chip--<?= status_tone($s) ?> <?= $stt === $s ? 'is-on' : '' ?>" href="<?= e(issue_url(['st' => $stt === $s ? '' : $s])) ?>"><?= $s ?></a>
    <?php endforeach; ?>
  </div>

  <form class="search" method="get" action="index.php">
    <input type="hidden" name="page" value="issues">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search code, title, owner, asset…">
    <button type="submit" class="btn btn--ghost">Search</button>
    <?php if ($cat || $sev || $stt || $q !== ''): ?>
      <a class="btn btn--ghost" href="?page=issues">Clear</a>
    <?php endif; ?>
  </form>
</div>

<section class="card card--flush">
  <table class="table table--list">
    <thead>
      <tr>
        <th class="num">#</th>
        <th>Issue</th>
        <th>Category</th>
        <th>Severity</th>
        <th class="center">Risk</th>
        <th>Status</th>
        <th>Owner</th>
        <th>Reported</th>
        <th>Due</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <?php
        $score = (int) $r['risk_score'];
        $overdue = $r['due_at'] && $r['due_at'] < date('Y-m-d') && $r['status'] !== 'Resolved';
      ?>
      <tr class="row <?= $overdue ? 'row--overdue' : '' ?>">
        <td class="num"><?= $i + 1 ?></td>
        <td class="cell-issue">
          <a class="cell-issue__link" href="?page=issue&amp;id=<?= (int) $r['id'] ?>">
            <span class="mono"><?= e($r['code']) ?></span>
            <span class="strong"><?= e($r['title']) ?></span>
          </a>
          <span class="cell-issue__sub">
            <?= e($r['location']) ?: '—' ?> · <?= e($r['department']) ?: '—' ?> · owner <?= e($r['owner']) ?: '—' ?>
          </span>
        </td>
        <td><span class="tag tag--<?= scope_tone($r['category']) ?>"><?= e($r['category']) ?></span></td>
        <td><?= badge($r['severity'], severity_tone($r['severity'])) ?></td>
        <td class="center"><span class="riskscore riskscore--sm riskscore--<?= risk_tone($score) ?>" title="<?= $r['likelihood'] ?> × <?= $r['impact'] ?>"><?= $score ?></span></td>
        <td>
          <?php if (can('issue.update')): ?>
            <form class="inline-form" method="post" action="index.php">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_issue">
              <input type="hidden" name="back" value="issues">
              <input type="hidden" name="issue_id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="title" value="<?= e($r['title']) ?>">
              <input type="hidden" name="category" value="<?= e($r['category']) ?>">
              <input type="hidden" name="severity" value="<?= e($r['severity']) ?>">
              <input type="hidden" name="owner" value="<?= e($r['owner']) ?>">
              <input type="hidden" name="location" value="<?= e($r['location']) ?>">
              <input type="hidden" name="department" value="<?= e($r['department']) ?>">
              <input type="hidden" name="asset" value="<?= e($r['asset']) ?>">
              <input type="hidden" name="reported_by" value="<?= e($r['reported_by']) ?>">
              <input type="hidden" name="reported_at" value="<?= e($r['reported_at']) ?>">
              <input type="hidden" name="due_at" value="<?= e($r['due_at']) ?>">
              <input type="hidden" name="likelihood" value="<?= (int) $r['likelihood'] ?>">
              <input type="hidden" name="impact" value="<?= (int) $r['impact'] ?>">
              <input type="hidden" name="description" value="<?= e($r['description']) ?>">
              <input type="hidden" name="root_cause" value="<?= e($r['root_cause']) ?>">
              <input type="hidden" name="immediate_action" value="<?= e($r['immediate_action']) ?>">
              <input type="hidden" name="corrective_action" value="<?= e($r['corrective_action']) ?>">
              <input type="hidden" name="reviewed_by" value="<?= e($r['reviewed_by']) ?>">
              <input type="hidden" name="approved_by" value="<?= e($r['approved_by']) ?>">
              <select name="status" onchange="this.form.submit()">
                <?php foreach (['Open', 'In Progress', 'Contained', 'Resolved'] as $s): ?>
                  <option value="<?= $s ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php else: ?>
            <?= badge($r['status'], status_tone($r['status'])) ?>
          <?php endif; ?>
        </td>
        <td class="nowrap"><?= e($r['owner']) ?: '—' ?></td>
        <td class="nowrap"><?= fmt_date($r['reported_at']) ?></td>
        <td class="nowrap <?= $overdue ? 'text-red' : '' ?>"><?= fmt_date($r['due_at']) ?><?= $overdue ? ' ⚠' : '' ?></td>
        <td class="right">
          <a class="btn btn--ghost btn--xs" href="?page=issue&amp;id=<?= (int) $r['id'] ?>">Report</a>
          <?php if (can('issue.export')): ?>
            <a class="btn btn--ghost btn--xs" href="?page=issue_doc&amp;id=<?= (int) $r['id'] ?>" title="Download Word report">.docx</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="10" class="empty">No issues match this filter.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>

<p class="footnote"><?= count($rows) ?> issue<?= count($rows) === 1 ? '' : 's' ?> shown · click a title for the full report · changing the status select saves immediately</p>
