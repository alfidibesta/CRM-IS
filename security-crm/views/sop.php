<?php
/** SOP / Policy library — filterable list modelled on systemdesign.io. */

$type   = $_GET['type']   ?? '';
$scope  = $_GET['scope']  ?? '';
$status = $_GET['status'] ?? '';
$q      = trim($_GET['q'] ?? '');

$where = [];
$args  = [];
if (in_array($type, ['Policy', 'SOP', 'Guideline'], true))   { $where[] = 'type = ?';   $args[] = $type; }
if (in_array($scope, ['Physical', 'IT', 'Strain', 'General'], true)) { $where[] = 'scope = ?'; $args[] = $scope; }
if (in_array($status, ['Active', 'Draft', 'In Review', 'Obsolete'], true)) { $where[] = 'status = ?'; $args[] = $status; }
if ($q !== '') { $where[] = '(title LIKE ? OR code LIKE ? OR owner LIKE ? OR summary LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }

$sql = 'SELECT * FROM sops' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY FIELD(scope,'Physical','IT','Strain','General'), code";
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$counts = [
    'all'      => (int) $pdo->query('SELECT COUNT(*) FROM sops')->fetchColumn(),
    'Policy'   => (int) $pdo->query("SELECT COUNT(*) FROM sops WHERE type='Policy'")->fetchColumn(),
    'SOP'      => (int) $pdo->query("SELECT COUNT(*) FROM sops WHERE type='SOP'")->fetchColumn(),
    'Guideline'=> (int) $pdo->query("SELECT COUNT(*) FROM sops WHERE type='Guideline'")->fetchColumn(),
];

function chip_url(array $over): string
{
    $base = ['page' => 'sop', 'type' => $_GET['type'] ?? '', 'scope' => $_GET['scope'] ?? '', 'status' => $_GET['status'] ?? '', 'q' => trim($_GET['q'] ?? '')];
    return '?' . http_build_query(array_merge($base, $over));
}

$activeFilters = ($type || $scope || $status || $q !== '');

$sopStats = [
    'all'      => $counts['all'],
    'active'   => $counts['all'] - (int) $pdo->query("SELECT COUNT(*) FROM sops WHERE status IN ('Draft','Obsolete')")->fetchColumn(),
    'review'   => (int) $pdo->query("SELECT COUNT(*) FROM sops WHERE status='In Review'")->fetchColumn(),
    'obsolete' => (int) $pdo->query("SELECT COUNT(*) FROM sops WHERE status='Obsolete'")->fetchColumn(),
];
?>

<section class="grid grid--stats">
  <article class="card stat stat--blue">
    <p class="stat__label">Controlled documents</p>
    <p class="stat__value"><?= $sopStats['all'] ?></p>
    <p class="stat__hint">policy · SOP · guideline</p>
  </article>
  <article class="card stat stat--neutral">
    <p class="stat__label">In force</p>
    <p class="stat__value"><?= $sopStats['active'] ?></p>
    <p class="stat__hint">effective and not obsolete</p>
  </article>
  <article class="card stat stat--orange">
    <p class="stat__label">In review</p>
    <p class="stat__value"><?= $sopStats['review'] ?></p>
    <p class="stat__hint">awaiting approval</p>
  </article>
  <article class="card stat stat--red">
    <p class="stat__label">Obsolete</p>
    <p class="stat__value"><?= $sopStats['obsolete'] ?></p>
    <p class="stat__hint">retained for reference</p>
  </article>
</section>

<div class="toolbar">
  <div class="chips chips--filter">
    <a class="chip <?= !$type ? 'is-on' : '' ?>" href="<?= e(chip_url(['type' => ''])) ?>">All <b><?= $counts['all'] ?></b></a>
    <?php foreach (['Policy', 'SOP', 'Guideline'] as $t): ?>
      <a class="chip <?= $type === $t ? 'is-on' : '' ?>" href="<?= e(chip_url(['type' => $t])) ?>"><?= $t ?> <b><?= $counts[$t] ?></b></a>
    <?php endforeach; ?>
  </div>

  <div class="chips chips--filter">
    <span class="chips__label">Scope</span>
    <?php foreach (['Physical', 'IT', 'Strain', 'General'] as $s): ?>
      <a class="chip chip--<?= scope_tone($s) ?> <?= $scope === $s ? 'is-on' : '' ?>" href="<?= e(chip_url(['scope' => $scope === $s ? '' : $s])) ?>"><?= $s ?></a>
    <?php endforeach; ?>
  </div>

  <form class="search" method="get" action="index.php">
    <input type="hidden" name="page" value="sop">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search code, title, owner…">
    <button type="submit" class="btn btn--ghost">Search</button>
    <?php if ($activeFilters): ?>
      <a class="btn btn--ghost" href="?page=sop">Clear</a>
    <?php endif; ?>
  </form>
</div>

<section class="card card--flush">
  <table class="table table--list">
    <thead>
      <tr>
        <th class="num">#</th>
        <th>Code</th>
        <th>Title</th>
        <th>Type</th>
        <th>Scope</th>
        <th>Ver</th>
        <th>Owner</th>
        <th>Effective</th>
        <th>Next review</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr class="row">
        <td class="num"><?= $i + 1 ?></td>
        <td class="mono"><?= e($r['code']) ?></td>
        <td>
          <details class="detail">
            <summary>
              <span class="strong"><?= e($r['title']) ?></span>
              <span class="muted"><?= e($r['summary']) ?></span>
            </summary>
            <div class="detail__body">
              <p><?= e($r['summary']) ?></p>
              <dl class="kv">
                <dt>Type</dt><dd><?= e($r['type']) ?></dd>
                <dt>Scope</dt><dd><?= e($r['scope']) ?></dd>
                <dt>Version</dt><dd><?= e($r['version']) ?></dd>
                <dt>Owner</dt><dd><?= e($r['owner']) ?></dd>
                <dt>Effective</dt><dd><?= fmt_date($r['effective_at']) ?></dd>
                <dt>Next review</dt><dd><?= fmt_date($r['review_at']) ?></dd>
              </dl>
              <?php if (!empty($r['content'])): ?>
                <div class="doc doc--sop"><?= blocks_to_html(parse_template_body((string) $r['content'])) ?></div>
              <?php else: ?>
                <p class="muted">No controlled text stored for this document — the summary above is the record.</p>
              <?php endif; ?>
            </div>
          </details>
        </td>
        <td><?= badge($r['type'], $r['type'] === 'Policy' ? 'blue' : ($r['type'] === 'SOP' ? 'orange' : 'neutral')) ?></td>
        <td><span class="tag tag--<?= scope_tone($r['scope']) ?>"><?= e($r['scope']) ?></span></td>
        <td class="mono">v<?= e($r['version']) ?></td>
        <td class="nowrap"><?= e($r['owner']) ?></td>
        <td class="nowrap"><?= fmt_date($r['effective_at']) ?></td>
        <td class="nowrap"><?= fmt_date($r['review_at']) ?></td>
        <td><?= badge($r['status'], status_tone($r['status'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="10" class="empty">No documents match this filter.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>

<p class="footnote"><?= count($rows) ?> document<?= count($rows) === 1 ? '' : 's' ?> shown · click a title to expand details</p>
