<?php
/**
 * Form template library: NDA, agreements, controlled forms — download as Word.
 */

$cat = in_array($_GET['cat'] ?? '', ['Legal', 'HR', 'Security', 'Audit', 'Operations'], true) ? $_GET['cat'] : '';
$q   = trim($_GET['q'] ?? '');

$where = [];
$args  = [];
if ($cat !== '') { $where[] = 'category = ?'; $args[] = $cat; }
if ($q !== '')   { $where[] = '(name LIKE ? OR code LIKE ? OR description LIKE ? OR owner LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }

$sql = 'SELECT * FROM templates' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY FIELD(category,\'Legal\',\'HR\',\'Security\',\'Audit\',\'Operations\'), code';
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$counts = ['Legal' => 0, 'HR' => 0, 'Security' => 0, 'Audit' => 0, 'Operations' => 0];
foreach ($pdo->query('SELECT category, COUNT(*) c FROM templates GROUP BY category')->fetchAll() as $r) {
    $counts[$r['category']] = (int) $r['c'];
}
$total = array_sum($counts);

$canEdit = can('template.edit');
$canExport = can('issue.export');

function tpl_url(array $over): string
{
    $base = ['page' => 'templates', 'cat' => $_GET['cat'] ?? '', 'q' => trim($_GET['q'] ?? '')];
    return '?' . http_build_query(array_merge($base, $over));
}

$catTone = ['Legal' => 'blue', 'HR' => 'orange', 'Security' => 'red', 'Audit' => 'blue', 'Operations' => 'neutral'];
?>

<div class="toolbar">
  <div class="chips chips--filter">
    <a class="chip <?= !$cat ? 'is-on' : '' ?>" href="<?= e(tpl_url(['cat' => ''])) ?>">All <b><?= $total ?></b></a>
    <?php foreach ($counts as $name => $n): ?>
      <a class="chip chip--<?= $catTone[$name] ?> <?= $cat === $name ? 'is-on' : '' ?>" href="<?= e(tpl_url(['cat' => $cat === $name ? '' : $name])) ?>">
        <?= e($name) ?> <b><?= $n ?></b>
      </a>
    <?php endforeach; ?>
  </div>

  <form class="search" method="get" action="index.php">
    <input type="hidden" name="page" value="templates">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search NDA, agreement, form…">
    <button type="submit" class="btn btn--ghost">Search</button>
    <?php if ($cat || $q !== ''): ?><a class="btn btn--ghost" href="?page=templates">Clear</a><?php endif; ?>
  </form>
</div>

<?php if ($canEdit): ?>
<details class="panel" <?= isset($_GET['new']) ? 'open' : '' ?>>
  <summary class="btn btn--primary btn--summary btn--sm">+ New template</summary>
  <form class="card form" method="post" action="index.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_template">
    <input type="hidden" name="back" value="templates">
    <div class="form__grid">
      <label class="field"><span>Code</span><input type="text" name="code" maxlength="20" placeholder="TPL-0xx"></label>
      <label class="field field--wide"><span>Name *</span><input type="text" name="name" required maxlength="160" placeholder="e.g. Mutual Non-Disclosure Agreement"></label>
      <label class="field"><span>Category</span>
        <select name="category"><?php foreach (array_keys($counts) as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select>
      </label>
      <label class="field"><span>Version</span><input type="text" name="version" maxlength="10" value="1.0"></label>
      <label class="field"><span>Owner</span><input type="text" name="owner" maxlength="100"></label>
      <label class="field field--wide"><span>Description</span><input type="text" name="description" maxlength="400"></label>
      <label class="field field--wide"><span>Body</span>
        <textarea name="body" rows="6" placeholder="# Heading&#10;## Section&#10;- bullet&#10;1. numbered&#10;[ ] checkbox&#10;| Header | Header |&#10;| cell   | cell   |&#10;{{PLACEHOLDER}}"></textarea>
      </label>
    </div>
    <div class="form__actions">
      <button type="submit" class="btn btn--primary btn--sm">Create template</button>
      <span class="muted">Open the template afterwards to edit the full body.</span>
    </div>
  </form>
</details>
<?php endif; ?>

<?php if (!$rows): ?>
  <section class="card"><p class="empty">No templates match this filter.</p></section>
<?php endif; ?>

<section class="tplgrid">
  <?php foreach ($rows as $t): ?>
    <article class="card tpl">
      <header class="tpl__head">
        <span class="mono strong"><?= e($t['code']) ?></span>
        <span class="tag tag--<?= $catTone[$t['category']] ?>"><?= e($t['category']) ?></span>
        <span class="muted">v<?= e($t['version']) ?></span>
      </header>

      <h3 class="tpl__name"><a href="?page=template&amp;id=<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a></h3>
      <p class="tpl__desc"><?= e($t['description']) ?></p>

      <dl class="tpl__meta">
        <div><dt>Owner</dt><dd><?= e($t['owner']) ?: '—' ?></dd></div>
        <div><dt>Placeholders</dt><dd><?= count(placeholders_of((string) $t['body'])) ?></dd></div>
        <div><dt>Downloads</dt><dd><?= (int) $t['downloads'] ?></dd></div>
        <div><dt>Updated</dt><dd><?= fmt_date($t['updated_at']) ?></dd></div>
      </dl>

      <footer class="tpl__actions">
        <a class="btn btn--primary btn--sm" href="?page=doc&amp;id=<?= (int) $t['id'] ?>">Download .docx</a>
        <a class="btn btn--ghost btn--sm" href="?page=template&amp;id=<?= (int) $t['id'] ?>">Preview &amp; fill</a>
      </footer>
    </article>
  <?php endforeach; ?>
</section>

<p class="footnote"><?= count($rows) ?> template<?= count($rows) === 1 ? '' : 's' ?> shown · downloads are generated as real Word (.docx) files in the browser</p>
