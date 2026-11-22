<?php
/**
 * One panel of the EDLP register, fetched on demand.
 *
 * app.js requests this fragment when a row's Edit (or a category's Adjust)
 * button is opened, so the register page itself ships one small placeholder
 * per row instead of a complete edit form. Same permissions and the same CSRF
 * field as the page that hosts it.
 *
 *   ?page=exception_panel&what=exception&id=12
 *   ?page=exception_panel&what=category&id=3
 */

$what = (string) ($_GET['what'] ?? 'exception');
$id   = (int) ($_GET['id'] ?? 0);

if ($what === 'category') {
    if (!can('exception.category')) {
        http_response_code(403);
        exit('<p class="muted">You are not allowed to change the category list.</p>');
    }
    $st = $pdo->prepare('SELECT * FROM exception_categories WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) {
        http_response_code(404);
        exit('<p class="muted">That category no longer exists.</p>');
    }
    ?>

    <form class="form form--sub" method="post" action="index.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_exception_category">
      <input type="hidden" name="back" value="exceptions">
      <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
      <div class="form__grid">
        <label class="field field--wide"><span>Name</span>
          <input type="text" name="name" maxlength="60" value="<?= e($c['name']) ?>">
        </label>
        <label class="field"><span>Accent</span>
          <select name="tone">
            <?php foreach (['red', 'blue', 'orange', 'green', 'neutral'] as $tone): ?>
              <option value="<?= $tone ?>" <?= $c['tone'] === $tone ? 'selected' : '' ?>><?= ucfirst($tone) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span>Order</span>
          <input type="number" name="sort" min="0" max="999" value="<?= (int) $c['sort'] ?>">
        </label>
      </div>
      <div class="form__actions">
        <button type="submit" class="btn btn--primary btn--sm">Save category</button>
        <span class="muted">Renaming moves its exceptions too.</span>
      </div>
    </form>
    <form class="inline-form inline-form--danger" method="post" action="index.php"
          data-confirm="Delete the category <?= e($c['name']) ?>?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_exception_category">
      <input type="hidden" name="back" value="exceptions">
      <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
      <button type="submit" class="btn btn--ghost btn--sm">Delete category</button>
    </form>
    <?php
    exit;
}

if (!can('exception.manage')) {
    http_response_code(403);
    exit('<p class="muted">You are not allowed to change exceptions.</p>');
}

$st = $pdo->prepare('SELECT * FROM exceptions WHERE id = ?');
$st->execute([$id]);
$e = $st->fetch();
if (!$e) {
    http_response_code(404);
    exit('<p class="muted">That exception no longer exists.</p>');
}

$cats = exception_categories($pdo);
?>

<form class="form form--sub" method="post" action="index.php">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update_exception">
  <input type="hidden" name="back" value="exceptions">
  <input type="hidden" name="exception_id" value="<?= (int) $e['id'] ?>">
  <div class="form__grid">
    <label class="field field--wide"><span>Title</span>
      <input type="text" name="title" maxlength="200" value="<?= e($e['title']) ?>">
    </label>
    <label class="field"><span>Category</span>
      <select name="category">
        <?php foreach ($cats as $c): ?>
          <option value="<?= e($c['name']) ?>" <?= $e['category'] === $c['name'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Status</span>
      <select name="status">
        <?php foreach (['Active', 'Pending', 'Revoked'] as $s): ?>
          <option <?= $e['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field field--wide"><span>Target</span>
      <input type="text" name="target" maxlength="180" value="<?= e($e['target']) ?>">
    </label>
    <label class="field"><span>Period start</span>
      <input type="date" name="period_start" value="<?= e($e['period_start']) ?>">
    </label>
    <label class="field"><span>Period end</span>
      <input type="date" name="period_end" value="<?= e($e['period_end']) ?>">
    </label>
    <label class="field"><span>Risk level</span>
      <select name="risk_level">
        <?php foreach (['High', 'Medium', 'Low'] as $s): ?>
          <option <?= $e['risk_level'] === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Reference no.</span>
      <input type="text" name="ref_no" maxlength="40" value="<?= e($e['ref_no']) ?>">
    </label>
    <label class="field"><span>Owner</span>
      <input type="text" name="owner" maxlength="100" value="<?= e($e['owner']) ?>">
    </label>
    <label class="field"><span>Approved by</span>
      <input type="text" name="approved_by" maxlength="100" value="<?= e($e['approved_by']) ?>">
    </label>
    <label class="field field--wide"><span>Detail</span>
      <textarea name="detail" rows="3"><?= e($e['detail']) ?></textarea>
    </label>
    <label class="field field--wide"><span>Business justification</span>
      <textarea name="justification" rows="2"><?= e($e['justification']) ?></textarea>
    </label>
    <label class="field field--wide"><span>Compensating control</span>
      <input type="text" name="control" maxlength="255" value="<?= e($e['control']) ?>">
    </label>
  </div>
  <div class="form__actions">
    <button type="submit" class="btn btn--primary btn--sm">Save</button>
    <span class="muted"><?= e($e['code']) ?> · updated <?= fmt_date($e['updated_at']) ?></span>
  </div>
</form>

<form class="inline-form inline-form--danger" method="post" action="index.php"
      data-confirm="Delete <?= e($e['code']) ?> permanently?">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete_exception">
  <input type="hidden" name="back" value="exceptions">
  <input type="hidden" name="exception_id" value="<?= (int) $e['id'] ?>">
  <button type="submit" class="btn btn--ghost btn--sm">Delete exception</button>
</form>
