<?php
/**
 * OT vulnerability register.
 *
 * Daily monitoring and management of factory-automation (FA) weaknesses — PLC,
 * HMI, SCADA, drives, vision and the network between them. The registration
 * form follows the shop-floor sheet: business location, vulnerability type,
 * importance, report confirmation date, target facilities and remarks. The
 * list below it carries the same columns plus status, recent contact and the
 * management actions.
 *
 * Business location and vulnerability type are catalogues, but both offer
 * "Other (direct input)" because a plant always has a line or a weakness the
 * list has not seen yet.
 */

$catIn   = (string) ($_GET['cat'] ?? '');
$stateIn = (string) ($_GET['state'] ?? '');
$siteIn  = trim((string) ($_GET['site'] ?? ''));
$q       = trim((string) ($_GET['q'] ?? ''));

$where = [];
$args  = [];
if ($catIn !== '') {
    $where[] = 'category = ?';
    $args[]  = $catIn;
}
if ($siteIn !== '') {
    $where[] = 'site = ?';
    $args[]  = $siteIn;
}
switch ($stateIn) {
    case 'open':
        $where[] = "status IN ('Open','Mitigating')";
        break;
    case 'overdue':
        $where[] = "status IN ('Open','Mitigating') AND due_at < CURDATE()";
        break;
    case 'mitigating':
        $where[] = "status = 'Mitigating'";
        break;
    case 'accepted':
        $where[] = "status = 'Accepted'";
        break;
    case 'resolved':
        $where[] = "status = 'Resolved'";
        break;
}
if ($q !== '') {
    $where[] = '(title LIKE ? OR code LIKE ? OR site LIKE ? OR asset LIKE ? OR advisory_ref LIKE ? OR detail LIKE ? OR owner LIKE ? OR recent_contact LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
}

$st = $pdo->prepare('SELECT * FROM ot_vulnerabilities'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY FIELD(status,'Open','Mitigating','Accepted','Resolved'),
                 FIELD(severity,'Critical','High','Medium','Low'), due_at IS NULL, due_at, code");
$st->execute($args);
$rows = $st->fetchAll();

// Only the columns the counters, filters and dropdowns need.
$all       = $pdo->query('SELECT status, severity, site, category, due_at, pc_risk_management FROM ot_vulnerabilities')->fetchAll();
$open      = 0;
$critical  = 0;
$overdue   = 0;
$unmanaged = 0;
$siteCnt   = [];
$catCnt    = [];
foreach ($all as $v) {
    [$state] = ot_state($v);
    if ($v['status'] !== 'Resolved') {
        $open++;
    }
    if ($state === 'Overdue') {
        $overdue++;
    }
    if ($v['status'] !== 'Resolved' && in_array($v['severity'], ['Critical', 'High'], true)) {
        $critical++;
    }
    if ($v['status'] !== 'Resolved' && $v['pc_risk_management'] === 'Not managed') {
        $unmanaged++;
    }
    $siteCnt[$v['site']] = ($siteCnt[$v['site']] ?? 0) + 1;
    $catCnt[$v['category']] = ($catCnt[$v['category']] ?? 0) + 1;
}
arsort($siteCnt);
$sites = array_keys($siteCnt);

// The catalogue plus any value typed through "Other", so a custom weakness
// still gets a filter chip and a coverage bar instead of disappearing.
$catOptions = ot_categories();
foreach (array_keys($catCnt) as $c) {
    if (!isset($catOptions[$c])) {
        $catOptions[$c] = 'neutral';
    }
}

$canManage = can('ot.manage');

function ot_url(array $over): string
{
    $base = [
        'page'  => 'ot',
        'cat'   => $_GET['cat'] ?? '',
        'state' => $_GET['state'] ?? '',
        'site'  => trim($_GET['site'] ?? ''),
        'q'     => trim($_GET['q'] ?? ''),
    ];
    return '?' . http_build_query(array_merge($base, $over));
}
?>

<section class="grid grid--stats statbar">
  <article class="card stat stat--blue">
    <p class="stat__label">OT findings on record</p>
    <p class="stat__value"><?= count($all) ?></p>
    <p class="stat__hint"><?= $open ?> still open · <?= count($sites) ?> location<?= count($sites) === 1 ? '' : 's' ?></p>
  </article>
  <article class="card stat stat--red">
    <p class="stat__label">Critical / high open</p>
    <p class="stat__value"><?= $critical ?></p>
    <p class="stat__hint">priority for the next maintenance window</p>
  </article>
  <article class="card stat stat--orange">
    <p class="stat__label">Overdue remediation</p>
    <p class="stat__value"><?= $overdue ?></p>
    <p class="stat__hint">due date passed, still open</p>
  </article>
  <article class="card stat stat--neutral">
    <p class="stat__label">Risk not managed</p>
    <p class="stat__value"><?= $unmanaged ?></p>
    <p class="stat__hint">open findings with no PC risk owner</p>
  </article>
</section>

<?php if ($canManage): ?>
<details class="card more panel--create" <?= isset($_GET['new']) ? 'open' : '' ?>>
  <summary>Register a vulnerability <span class="more__hint">daily monitoring · PLC, HMI, SCADA</span></summary>
  <div class="more__body">
  <div class="modal-source" id="new-ot-form">
  <form class="form" method="post" action="index.php">
    <?= csrf_field() ?>
    <?= once_field() ?>
    <input type="hidden" name="action" value="create_otv">
    <input type="hidden" name="back" value="ot">

    <div class="form__grid">
      <label class="field"><span>Business Location *</span>
        <select name="site" required data-other="ot-site-other">
          <option value="">Please select</option>
          <?php foreach ($sites as $s): ?>
            <option value="<?= e($s) ?>" <?= $siteIn === $s ? 'selected' : '' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
          <option value="<?= OT_OTHER ?>" <?= $siteIn !== '' && !in_array($siteIn, $sites, true) ? 'selected' : '' ?>>Other (direct input)</option>
        </select>
        <input class="field__other" type="text" id="ot-site-other" name="site_other" maxlength="120"
               placeholder="Type the business location"
               value="<?= $siteIn !== '' && !in_array($siteIn, $sites, true) ? e($siteIn) : '' ?>"
               <?= $siteIn !== '' && !in_array($siteIn, $sites, true) ? '' : 'hidden disabled' ?>>
      </label>

      <label class="field"><span>Vulnerabilities *</span>
        <select name="category" required data-other="ot-cat-other">
          <option value="">Please select</option>
          <?php foreach (ot_categories() as $name => $tone): ?>
            <option value="<?= e($name) ?>" <?= $catIn === $name ? 'selected' : '' ?>><?= e($name) ?></option>
          <?php endforeach; ?>
          <option value="<?= OT_OTHER ?>" <?= $catIn !== '' && !isset(ot_categories()[$catIn]) ? 'selected' : '' ?>>Other (direct input)</option>
        </select>
        <input class="field__other" type="text" id="ot-cat-other" name="category_other" maxlength="60"
               placeholder="Type the vulnerability type"
               value="<?= $catIn !== '' && !isset(ot_categories()[$catIn]) ? e($catIn) : '' ?>"
               <?= $catIn !== '' && !isset(ot_categories()[$catIn]) ? '' : 'hidden disabled' ?>>
      </label>

      <label class="field"><span>Importance *</span>
        <select name="severity" required>
          <option>Critical</option>
          <option>High</option>
          <option selected>Medium</option>
          <option>Low</option>
        </select>
      </label>

      <label class="field"><span>Report Confirmation Date *</span>
        <input type="date" name="identified_at" required value="<?= date('Y-m-d') ?>">
      </label>

      <label class="field field--half"><span>Target Facilities and Systems</span>
        <input type="text" name="asset" maxlength="180" placeholder="Example: Production line PLC (S7-315) · 10.30.3.11">
      </label>

      <label class="field field--half"><span>Remarks</span>
        <textarea name="detail" rows="2" placeholder="Additional checks"></textarea>
      </label>
    </div>

    <details class="panel panel--inner">
      <summary class="btn btn--ghost btn--sm btn--summary">Monitoring and management</summary>
      <div class="form__grid">
        <label class="field"><span>Current status</span>
          <select name="status">
            <?php foreach (ot_statuses() as $s): ?>
              <option><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span>Remediation due</span>
          <input type="date" name="due_at" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
        </label>
        <label class="field"><span>PC risk management</span>
          <select name="pc_risk_management">
            <?php foreach (ot_pc_risk_options() as $opt): ?>
              <option <?= $opt === 'Partially managed' ? 'selected' : '' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span>Recent contact</span>
          <input type="text" name="recent_contact" maxlength="120" placeholder="e.g. OEM service desk · ticket 4412">
        </label>
        <label class="field"><span>Owner</span>
          <input type="text" name="owner" maxlength="100" value="<?= e(current_user_name()) ?>">
        </label>
        <label class="field"><span>CVSS score</span>
          <input type="number" name="cvss_score" min="0" max="10" step="0.1" placeholder="7.5">
        </label>
        <label class="field"><span>Advisory / reference</span>
          <input type="text" name="advisory_ref" maxlength="60" placeholder="ICSA-24-102-07">
        </label>
        <label class="field field--wide"><span>Risk control in place</span>
          <input type="text" name="risk_control" maxlength="255" placeholder="e.g. OT firewall blocks 502 from the office VLAN">
        </label>
        <label class="field field--wide"><span>Recommendation</span>
          <textarea name="recommendation" rows="2" placeholder="The remediation the owner should carry out"></textarea>
        </label>
      </div>
    </details>

    <div class="form__actions">
      <button type="submit" class="btn btn--primary btn--sm">Vulnerability registration</button>
      <span class="muted">An open finding past its due date reads <b>Overdue</b>.</span>
    </div>
  </form>
  </div>
  </div>
</details>
<?php endif; ?>

<section class="card card--flush">
  <header class="card__head card__head--pad toolbar--filters">
    <h2>List of vulnerabilities</h2>

    <form class="search" method="get" action="index.php">
      <input type="hidden" name="page" value="ot">
      <input type="hidden" name="cat" value="<?= e($catIn) ?>">
      <input type="hidden" name="state" value="<?= e($stateIn) ?>">
      <input type="hidden" name="site" value="<?= e($siteIn) ?>">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search vulnerability, facility, contact…">
      <button type="submit" class="btn btn--ghost">Search</button>
    </form>

    <details class="filters filters--pop">
      <summary>Filters</summary>
      <div class="filters__body">
      <div class="chips chips--filter">
        <span class="chips__label">Business location</span>
        <form class="search search--inline" method="get" action="index.php">
          <input type="hidden" name="page" value="ot">
          <input type="hidden" name="cat" value="<?= e($catIn) ?>">
          <input type="hidden" name="state" value="<?= e($stateIn) ?>">
          <input type="hidden" name="q" value="<?= e($q) ?>">
          <select name="site" class="search__select" aria-label="Business location" data-autosubmit>
            <option value="">All workplaces</option>
            <?php foreach ($sites as $s): ?>
              <option value="<?= e($s) ?>" <?= $siteIn === $s ? 'selected' : '' ?>><?= e($s) ?> (<?= (int) $siteCnt[$s] ?>)</option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn--ghost btn--xs">Apply</button>
        </form>
      </div>

      <div class="chips chips--filter">
        <span class="chips__label">Current status</span>
        <?php foreach (['open' => 'Open', 'mitigating' => 'Mitigating', 'overdue' => 'Overdue', 'accepted' => 'Accepted', 'resolved' => 'Resolved'] as $key => $label): ?>
          <a class="chip <?= $stateIn === $key ? 'is-on' : '' ?>" href="<?= e(ot_url(['state' => $stateIn === $key ? '' : $key])) ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </div>

      <div class="chips chips--filter">
        <span class="chips__label">Vulnerabilities</span>
        <a class="chip <?= $catIn === '' ? 'is-on' : '' ?>" href="<?= e(ot_url(['cat' => ''])) ?>">All <b><?= count($all) ?></b></a>
        <?php foreach ($catOptions as $name => $tone): ?>
          <a class="chip chip--<?= e($tone) ?> <?= $catIn === $name ? 'is-on' : '' ?>"
             href="<?= e(ot_url(['cat' => $catIn === $name ? '' : $name])) ?>">
            <?= e($name) ?> <b><?= (int) ($catCnt[$name] ?? 0) ?></b>
          </a>
        <?php endforeach; ?>
      </div>

    </div>
  </details>

  <span class="countchip"><?= count($rows) ?> of <?= count($all) ?> shown</span>

  <?php if ($canManage): ?>
    <a class="btn btn--primary btn--sm" href="?page=ot&amp;new=1"
       data-modal-node="new-ot-form" data-modal-drawer data-modal-title="Register a vulnerability">+ Register finding</a>
  <?php endif; ?>
</header>

<?php if ($catIn !== '' || $stateIn !== '' || $siteIn !== '' || $q !== ''): ?>
<p class="activefilters">
  <span class="activefilters__label">Showing</span>
  <?php if ($siteIn !== ''): ?><a class="chip is-on" href="<?= e(ot_url(['site' => ''])) ?>" title="Remove this filter">Location: <?= e($siteIn) ?> <span aria-hidden="true">×</span></a><?php endif; ?>
  <?php if ($catIn !== ''): ?><a class="chip is-on" href="<?= e(ot_url(['cat' => ''])) ?>" title="Remove this filter">Vulnerability: <?= e($catIn) ?> <span aria-hidden="true">×</span></a><?php endif; ?>
  <?php if ($stateIn !== ''): ?><a class="chip is-on" href="<?= e(ot_url(['state' => ''])) ?>" title="Remove this filter">Status: <?= e(ucfirst($stateIn)) ?> <span aria-hidden="true">×</span></a><?php endif; ?>
  <?php if ($q !== ''): ?><a class="chip is-on" href="<?= e(ot_url(['q' => ''])) ?>" title="Clear the search">Search: <?= e($q) ?> <span aria-hidden="true">×</span></a><?php endif; ?>
  <a class="link" href="?page=ot">Clear all</a>
</p>
<?php endif; ?>

  <table class="table table--list table--ot">
    <caption class="sr-only">List of OT vulnerabilities</caption>
    <thead>
      <tr>
        <th scope="col"><span class="ot-th-full">Report Confirmation Date</span><span class="ot-th-phone">Ref</span></th>
        <th scope="col">Business Sites</th>
        <th scope="col">Vulnerabilities</th>
        <th scope="col" class="col-secondary">Target Facilities and Systems</th>
        <th scope="col">Importance</th>
        <th scope="col">Current Status</th>
        <th scope="col" class="col-secondary">Recent Contacts</th>
        <th class="right" scope="col">Management</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $v): ?>
      <?php
        [$stateLabel, $stateTone] = ot_state($v);
        $days = ot_days_left($v['due_at'] ?? null);
      ?>
      <tr class="row <?= $stateLabel === 'Overdue' ? 'row--overdue' : '' ?>">
        <td class="nowrap">
          <span class="period ot-date"><?= fmt_date($v['identified_at']) ?></span>
          <span class="muted mono cell-sub"><?= e($v['code']) ?></span>
        </td>
        <td class="nowrap ot-site"><?= e($v['site']) ?: '—' ?></td>
        <td class="cell-issue">
          <span class="tag tag--<?= e(ot_category_tone((string) $v['category'])) ?>"><?= e($v['category']) ?: '—' ?></span>

          <?php if (trim((string) $v['detail']) !== '' || trim((string) $v['risk_control']) !== '' || trim((string) $v['recommendation']) !== ''): ?>
            <details class="detail detail--inline">
              <summary class="detail__more">Remarks · control · recommendation</summary>
              <div class="detail__body">
                <dl class="kv">
                  <?php if (trim((string) $v['detail']) !== ''): ?>
                    <dt>Remarks</dt><dd class="preline"><?= e($v['detail']) ?></dd>
                  <?php endif; ?>
                  <?php if (trim((string) $v['risk_control']) !== ''): ?>
                    <dt>Risk control</dt><dd class="preline"><?= e($v['risk_control']) ?></dd>
                  <?php endif; ?>
                  <?php if (trim((string) $v['recommendation']) !== ''): ?>
                    <dt>Recommendation</dt><dd class="preline"><?= e($v['recommendation']) ?></dd>
                  <?php endif; ?>
                  <dt>PC risk management</dt><dd><?= e($v['pc_risk_management']) ?></dd>
                  <?php if ($v['cvss_score'] !== null): ?>
                    <dt>CVSS</dt><dd><?= e(number_format((float) $v['cvss_score'], 1)) ?><?= trim((string) $v['advisory_ref']) !== '' ? ' · ' . e($v['advisory_ref']) : '' ?></dd>
                  <?php endif; ?>
                  <dt>Owner</dt><dd><?= e($v['owner']) ?: '—' ?></dd>
                  <dt>Remediation due</dt><dd><?= fmt_date($v['due_at']) ?></dd>
                  <dt>Recorded</dt><dd><?= fmt_date($v['created_at']) ?></dd>
                </dl>
              </div>
            </details>
          <?php endif; ?>
        </td>
        <td class="cell-control col-secondary"><span class="muted"><?= e($v['asset']) ?: '—' ?></span></td>
        <td><span class="tag tag--<?= e(ot_severity_tone($v['severity'])) ?>"><?= e($v['severity']) ?></span></td>
        <td>
          <?= badge($stateLabel, $stateTone) ?>
          <?php if ($days !== null && $v['status'] !== 'Resolved'): ?>
            <span class="muted period__days period__days--<?= $days < 0 ? 'red' : ($days <= 14 ? 'orange' : 'neutral') ?>">
              <?= $days < 0 ? abs($days) . ' days ago' : $days . ' days left' ?>
            </span>
          <?php endif; ?>
        </td>
        <td class="cell-control col-secondary"><span class="muted"><?= e($v['recent_contact']) ?: '—' ?></span></td>
        <td class="right">
          <?php if ($canManage): ?>
            <?php $panel = '?page=ot_panel&id=' . (int) $v['id']; ?>
            <a class="btn btn--ghost btn--xs" href="<?= e($panel) ?>"
               data-modal-url="<?= e($panel) ?>" data-modal-title="Edit <?= e($v['code']) ?>">Edit</a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="8" class="empty">No vulnerabilities have been found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>

<div class="grid grid--two">
  <!-- ------------------------------------------------------------- rules -->
  <details class="card more" data-more>
    <summary>How this register works <span class="more__hint">locations, status, importance</span></summary>
    <div class="more__body">
    <ul class="bullets">
      <li><b>One row per weakness, on the asset it touches.</b> The business location is required; the target facilities column names the PLC, HMI, drive, vision system or server so the fix can be found at the cabinet.</li>
      <li><b>The weakness is chosen from a catalogue, or typed in.</b> Business location and vulnerability type both end with <b>Other (direct input)</b>, so a new line or a weakness the catalogue does not name is still recorded consistently.</li>
      <li><b>Importance is the plant's call.</b> Critical, high, medium or low with the production consequence in mind; CVSS and the advisory reference are kept beside it for the record.</li>
      <li><b>Remarks carry the daily checks.</b> What was seen, what was checked and what the owner should do; the control in force stays visible while the risk is open.</li>
      <li><b>Current status is derived.</b> Open or mitigating past the remediation due date reads <b>Overdue</b>; an accepted finding stays visible as <b>Risk accepted</b> instead of disappearing.</li>
      <li><b>Authority:</b> managers and analysts register, edit and delete findings; administrators and viewers read the register.</li>
    </ul>
    </div>
  </details>

  <!-- ------------------------------------------------------------- coverage -->
  <details class="card more" data-more>
    <summary>Findings by vulnerability <span class="more__hint"><?= count($all) ?> on record</span></summary>
    <div class="more__body">
    <ul class="bars">
      <?php foreach ($catOptions as $name => $tone): ?>
        <?php $n = (int) ($catCnt[$name] ?? 0); ?>
        <li>
          <div class="bars__row">
            <span class="chip-dot chip-dot--<?= e($tone) ?>"></span>
            <span class="bars__name"><?= e($name) ?></span>
            <span class="bars__val"><?= $n ?> finding<?= $n === 1 ? '' : 's' ?></span>
          </div>
          <div class="bars__track">
            <span class="bars__fill bars__fill--<?= e($tone) ?>" style="width: <?= count($all) ? max(3, round($n / count($all) * 100)) : 0 ?>%"></span>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    </div>
  </details>
</div>
