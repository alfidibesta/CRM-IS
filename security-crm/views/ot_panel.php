<?php
/**
 * One OT finding's edit panel, fetched on demand.
 *
 * app.js requests this fragment when a register row's Edit button opens, so the
 * register ships one small placeholder per row instead of a full form. The
 * fields are the registration form's fields, including the two catalogues that
 * offer "Other (direct input)". Same permission and the same CSRF field as the
 * page that hosts it.
 *
 *   ?page=ot_panel&id=7
 */

if (!can('ot.manage')) {
    http_response_code(403);
    exit('<p class="muted">You are not allowed to change OT findings.</p>');
}

$st = $pdo->prepare('SELECT * FROM ot_vulnerabilities WHERE id = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$v = $st->fetch();
if (!$v) {
    http_response_code(404);
    exit('<p class="muted">That OT finding no longer exists.</p>');
}

$sites = array_column(
    $pdo->query("SELECT DISTINCT site FROM ot_vulnerabilities WHERE site <> '' ORDER BY site")->fetchAll(),
    'site'
);
$cats      = ot_categories();
$siteOther = $v['site'] !== '' && !in_array($v['site'], $sites, true);
$catOther  = $v['category'] !== '' && !isset($cats[$v['category']]);
?>

<form class="form form--sub" method="post" action="index.php">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update_otv">
  <input type="hidden" name="back" value="ot">
  <input type="hidden" name="otv_id" value="<?= (int) $v['id'] ?>">
  <div class="form__grid">
    <label class="field"><span>Business Location</span>
      <select name="site" required data-other="otp-site-other">
        <option value="">Please select</option>
        <?php foreach ($sites as $s): ?>
          <option value="<?= e($s) ?>" <?= $v['site'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
        <option value="<?= OT_OTHER ?>" <?= $siteOther ? 'selected' : '' ?>>Other (direct input)</option>
      </select>
      <input class="field__other" type="text" id="otp-site-other" name="site_other" maxlength="120"
             placeholder="Type the business location" value="<?= $siteOther ? e($v['site']) : '' ?>"
             <?= $siteOther ? '' : 'hidden disabled' ?>>
    </label>

    <label class="field"><span>Vulnerabilities</span>
      <select name="category" required data-other="otp-cat-other">
        <option value="">Please select</option>
        <?php foreach ($cats as $name => $tone): ?>
          <option value="<?= e($name) ?>" <?= $v['category'] === $name ? 'selected' : '' ?>><?= e($name) ?></option>
        <?php endforeach; ?>
        <option value="<?= OT_OTHER ?>" <?= $catOther ? 'selected' : '' ?>>Other (direct input)</option>
      </select>
      <input class="field__other" type="text" id="otp-cat-other" name="category_other" maxlength="60"
             placeholder="Type the vulnerability type" value="<?= $catOther ? e($v['category']) : '' ?>"
             <?= $catOther ? '' : 'hidden disabled' ?>>
    </label>

    <label class="field"><span>Importance</span>
      <select name="severity">
        <?php foreach (['Critical', 'High', 'Medium', 'Low'] as $s): ?>
          <option <?= $v['severity'] === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="field"><span>Report Confirmation Date</span>
      <input type="date" name="identified_at" value="<?= e($v['identified_at']) ?>">
    </label>

    <label class="field"><span>Target Facilities and Systems</span>
      <input type="text" name="asset" maxlength="180" value="<?= e($v['asset']) ?>"
             placeholder="Example: Production line PLC (S7-315) · 10.30.3.11">
    </label>

    <label class="field"><span>Remarks</span>
      <textarea name="detail" rows="2" placeholder="Additional checks"><?= e($v['detail']) ?></textarea>
    </label>
  </div>

  <details class="panel panel--inner">
    <summary class="btn btn--ghost btn--sm btn--summary">Monitoring and management</summary>
    <div class="form__grid">
      <label class="field"><span>Current status</span>
        <select name="status">
          <?php foreach (ot_statuses() as $s): ?>
            <option <?= $v['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span>Remediation due</span>
        <input type="date" name="due_at" value="<?= e($v['due_at']) ?>">
      </label>
      <label class="field"><span>PC risk management</span>
        <select name="pc_risk_management">
          <?php foreach (ot_pc_risk_options() as $opt): ?>
            <option <?= $v['pc_risk_management'] === $opt ? 'selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span>Recent contact</span>
        <input type="text" name="recent_contact" maxlength="120" value="<?= e($v['recent_contact']) ?>">
      </label>
      <label class="field"><span>Owner</span>
        <input type="text" name="owner" maxlength="100" value="<?= e($v['owner']) ?>">
      </label>
      <label class="field"><span>CVSS score</span>
        <input type="number" name="cvss_score" min="0" max="10" step="0.1"
               value="<?= $v['cvss_score'] !== null ? e(number_format((float) $v['cvss_score'], 1)) : '' ?>">
      </label>
      <label class="field"><span>Advisory / reference</span>
        <input type="text" name="advisory_ref" maxlength="60" value="<?= e($v['advisory_ref']) ?>">
      </label>
      <label class="field field--wide"><span>Risk control in place</span>
        <input type="text" name="risk_control" maxlength="255" value="<?= e($v['risk_control']) ?>">
      </label>
      <label class="field field--wide"><span>Recommendation</span>
        <textarea name="recommendation" rows="2"><?= e($v['recommendation']) ?></textarea>
      </label>
    </div>
  </details>

  <div class="form__actions">
    <button type="submit" class="btn btn--primary btn--sm">Save</button>
    <span class="muted"><?= e($v['code']) ?> · updated <?= fmt_date($v['updated_at']) ?></span>
  </div>
</form>

<form class="inline-form inline-form--danger" method="post" action="index.php"
      data-confirm="Delete <?= e($v['code']) ?> permanently?">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete_otv">
  <input type="hidden" name="back" value="ot">
  <input type="hidden" name="otv_id" value="<?= (int) $v['id'] ?>">
  <button type="submit" class="btn btn--ghost btn--sm">Delete finding</button>
</form>
