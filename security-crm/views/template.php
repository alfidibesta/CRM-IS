<?php
/**
 * One template: fill-in form, live-ish preview, Word download and (for
 * editors) the body source with markup hints.
 */

$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM templates WHERE id = ?');
$st->execute([$id]);
$tpl = $st->fetch();

if (!$tpl) {
    http_response_code(404);
    echo '<section class="card"><p class="empty">Template not found. <a class="link" href="?page=templates">Back to the library</a></p></section>';
    return;
}

$canEdit = can('template.edit');
$ph      = placeholders_of((string) $tpl['body']);
$blocks  = template_document_blocks($tpl);
$preview = blocks_to_html($blocks);
?>

<section class="report-head card">
  <div class="report-head__main">
    <p class="report-head__code">
      <span class="mono strong"><?= e($tpl['code']) ?></span>
      <span class="tag tag--blue"><?= e($tpl['category']) ?></span>
      <span class="badge badge--neutral">v<?= e($tpl['version']) ?></span>
      <span class="badge badge--green">Active</span>
    </p>
    <h2 class="report-head__title"><?= e($tpl['name']) ?></h2>
    <p class="muted"><?= e($tpl['description']) ?></p>

    <dl class="factstrip">
      <div><dt>Owner</dt><dd><?= e($tpl['owner']) ?: '—' ?></dd></div>
      <div><dt>Placeholders</dt><dd><?= count($ph) ?></dd></div>
      <div><dt>Downloads</dt><dd><?= (int) $tpl['downloads'] ?></dd></div>
      <div><dt>Updated</dt><dd><?= fmt_date($tpl['updated_at']) ?></dd></div>
    </dl>
  </div>

  <aside class="report-head__side">
    <div class="riskbox riskbox--blue">
      <span class="riskbox__score"><?= count($ph) ?></span>
      <span class="riskbox__meta">
        <span class="riskbox__label">Fields to fill</span>
        <strong>before download</strong>
      </span>
    </div>
    <div class="report-head__actions">
      <a class="btn btn--primary btn--sm" href="?page=doc&amp;id=<?= $id ?>">Download .docx</a>
      <a class="btn btn--ghost btn--sm" href="?page=templates">Back to library</a>
    </div>
    <p class="footnote">Word document generated instantly · uncontrolled when printed</p>
  </aside>
</section>

<div class="grid grid--two">
  <!-- ---------------------------------------------------------- fill + dl -->
  <section class="card">
    <header class="card__head">
      <h2>Fill in &amp; download</h2>
      <span class="muted">Optional — empty fields print as [FIELD]</span>
    </header>

    <form class="form" method="get" action="index.php">
      <input type="hidden" name="page" value="doc">
      <input type="hidden" name="id" value="<?= $id ?>">

      <?php if ($ph): ?>
        <div class="form__grid">
          <?php foreach ($ph as $key): ?>
            <label class="field">
              <span><?= e(placeholder_label($key)) ?></span>
              <input type="text" name="fill[<?= e($key) ?>]" maxlength="200"
                     placeholder="{{<?= e($key) ?>}}">
            </label>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="muted">This template has no placeholders — it downloads as-is.</p>
      <?php endif; ?>

      <label class="field field--wide"><span>Reference / header line</span>
        <input type="text" name="ref" maxlength="120" placeholder="e.g. Ref: NDA-2025-014 for Kerta Steel">
      </label>

      <div class="form__actions">
        <button type="submit" class="btn btn--primary">Download Word file</button>
        <span class="muted">Produces a real .docx you can open and edit in Word.</span>
      </div>
    </form>

    <?php if ($canEdit): ?>
      <details class="panel panel--sub">
        <summary class="btn btn--ghost btn--summary btn--sm">Edit template source</summary>
        <form class="form form--sub" method="post" action="index.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_template">
          <input type="hidden" name="back" value="template">
          <input type="hidden" name="back_id" value="<?= $id ?>">
          <input type="hidden" name="template_id" value="<?= $id ?>">
          <div class="form__grid">
            <label class="field field--wide"><span>Name</span><input type="text" name="name" required maxlength="160" value="<?= e($tpl['name']) ?>"></label>
            <label class="field"><span>Category</span>
              <select name="category">
                <?php foreach (['Legal', 'HR', 'Security', 'Audit', 'Operations'] as $c): ?>
                  <option <?= $tpl['category'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field"><span>Version</span><input type="text" name="version" maxlength="10" value="<?= e($tpl['version']) ?>"></label>
            <label class="field"><span>Owner</span><input type="text" name="owner" maxlength="100" value="<?= e($tpl['owner']) ?>"></label>
            <label class="field field--wide"><span>Description</span><input type="text" name="description" maxlength="400" value="<?= e($tpl['description']) ?>"></label>
            <label class="field field--wide"><span>Body</span>
              <textarea name="body" rows="16" class="codebox"><?= e($tpl['body']) ?></textarea>
            </label>
          </div>

          <div class="markuphint">
            <p class="markuphint__title">Markup</p>
            <ul>
              <li><code># Title</code>, <code>## Section</code>, <code>### Sub</code></li>
              <li><code>- bullet</code> · <code>1. numbered</code> · <code>[ ] checkbox</code></li>
              <li><code>| Header | Header |</code> then <code>| cell | cell |</code></li>
              <li><code>{{PLACEHOLDER}}</code> for fill-in fields · <code>---</code> rule</li>
            </ul>
          </div>

          <div class="form__actions">
            <button type="submit" class="btn btn--primary btn--sm">Save template</button>
          </div>
        </form>

        <form class="inline-form inline-form--danger" method="post" action="index.php"
               data-confirm="Delete this template permanently?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_template">
          <input type="hidden" name="back" value="templates">
          <input type="hidden" name="template_id" value="<?= $id ?>">
          <button type="submit" class="btn btn--ghost btn--sm">Delete template</button>
        </form>
      </details>
    <?php endif; ?>
  </section>

  <!-- ------------------------------------------------------------ preview -->
  <section class="card card--doc">
    <header class="card__head">
      <h2>Document preview</h2>
      <span class="muted">A4 · matches the .docx layout</span>
    </header>

    <article class="doc" id="doc-preview">
      <?= $preview ?>
    </article>
  </section>
</div>

<p class="footnote"><?= count($ph) ?> placeholder<?= count($ph) === 1 ? '' : 's' ?> · preview shows <span class="doc-fill">empty fields</span> the way they print if left blank</p>
