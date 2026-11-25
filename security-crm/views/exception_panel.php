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

$cats     = exception_categories($pdo);
$canRenew = can('exception.renew');
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
    <div class="field"><span>Period end</span>
      <p class="mono"><?= fmt_date($e['period_end']) ?></p>
      <span class="muted">Moves only through a renewal, so the approval is recorded.</span>
    </div>
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

<?php if ($canRenew): ?>
<details class="panel panel--sub">
  <summary class="btn btn--ghost btn--summary btn--sm">Renew the period</summary>
  <form class="form form--sub" method="post" action="index.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="renew_exception">
    <input type="hidden" name="back" value="exceptions">
    <input type="hidden" name="exception_id" value="<?= (int) $e['id'] ?>">
    <div class="form__grid">
      <label class="field"><span>New period end *</span>
        <input type="date" name="period_end" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
               value="<?= $e['period_end'] ? e(date('Y-m-d', strtotime((string) $e['period_end'] . ' +1 year'))) : e(date('Y-m-d', strtotime('+90 days'))) ?>">
      </label>
      <label class="field field--wide"><span>Reason for the extension *</span>
        <input type="text" name="renewal_note" required maxlength="255"
               placeholder="e.g. supplier migration slips a quarter; the control still runs">
      </label>
    </div>
    <div class="form__actions">
      <button type="submit" class="btn btn--primary btn--sm">Record renewal</button>
      <span class="muted">Records you as the approver with today's date and returns the status to Active; the previous end date stays in the audit trail.</span>
    </div>
  </form>
</details>

<details class="panel panel--sub">
  <summary class="btn btn--ghost btn--summary btn--sm">Verify the compensating control</summary>
  <form class="form form--sub" method="post" action="index.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="verify_exception_control">
    <input type="hidden" name="back" value="exceptions">
    <input type="hidden" name="exception_id" value="<?= (int) $e['id'] ?>">
    <div class="form__grid">
      <label class="field"><span>Verified on *</span>
        <input type="date" name="verified_on" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
      </label>
      <label class="field field--wide"><span>What was checked *</span>
        <input type="text" name="verification_note" required maxlength="255"
               placeholder="e.g. 12 scans logged on the AV station, no exceptions">
      </label>
    </div>
    <div class="form__actions">
      <button type="submit" class="btn btn--primary btn--sm">Record verification</button>
      <span class="muted">
        <?php if (trim((string) $e['control']) === ''): ?>
          Record the compensating control on the form above first.
        <?php else: ?>
          Control in force: <?= e($e['control']) ?>
        <?php endif; ?>
      </span>
    </div>
  </form>
</details>
<?php else: ?>
<p class="muted">Renewing the period and verifying the compensating control are for managers and administrators.</p>
<?php endif; ?>

<form class="inline-form inline-form--danger" method="post" action="index.php"
      data-confirm="Delete <?= e($e['code']) ?> permanently?">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete_exception">
  <input type="hidden" name="back" value="exceptions">
  <input type="hidden" name="exception_id" value="<?= (int) $e['id'] ?>">
  <button type="submit" class="btn btn--ghost btn--sm">Delete exception</button>
</form>
