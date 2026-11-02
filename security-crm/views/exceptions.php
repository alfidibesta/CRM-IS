<?php
/**
 * EDLP exception register.
 *
 * Time-bound, approved deviations (USB, websites, cameras, cloud apps …).
 * Each exception carries an adjustable category, a validity period, the
 * detail behind the decision and the compensating control in force.
 */

$cats      = exception_categories($pdo);
$catNames  = exception_category_names($pdo);
$catIn     = (string) ($_GET['cat'] ?? '');
$stateIn   = (string) ($_GET['state'] ?? '');
$q         = trim((string) ($_GET['q'] ?? ''));

$where = [];
$args  = [];
if ($catIn !== '' && in_array($catIn, $catNames, true)) {
    $where[] = 'category = ?';
    $args[]  = $catIn;
}
switch ($stateIn) {
    case 'active':
        $where[] = "status = 'Active' AND (period_end IS NULL OR period_end > DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
        break;
    case 'expiring':
        $where[] = "status = 'Active' AND period_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
        break;
    case 'expired':
        $where[] = "status <> 'Revoked' AND period_end < CURDATE()";
        break;
    case 'pending':
        $where[] = "status = 'Pending'";
        break;
    case 'revoked':
        $where[] = "status = 'Revoked'";
        break;
}
if ($q !== '') {
    $where[] = '(title LIKE ? OR code LIKE ? OR target LIKE ? OR detail LIKE ? OR owner LIKE ? OR ref_no LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
}

$st = $pdo->prepare('SELECT * FROM exceptions'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY FIELD(status,\'Pending\',\'Active\',\'Revoked\'), period_end, code');
$st->execute($args);
$rows = $st->fetchAll();

// Only the four columns the counters below need; the register rows come from the filtered query above.
$all      = $pdo->query('SELECT status, period_end, category, risk_level FROM exceptions')->fetchAll();
$stateCnt = ['Active' => 0, 'Expiring' => 0, 'Expired' => 0, 'Pending approval' => 0, 'Revoked' => 0];
$catCnt   = [];
$riskCnt  = ['High' => 0, 'Medium' => 0, 'Low' => 0];
foreach ($all as $e) {
    $stateCnt[exception_state($e)[0]]++;
    $catCnt[$e['category']] = ($catCnt[$e['category']] ?? 0) + 1;
    $riskCnt[$e['risk_level']] = ($riskCnt[$e['risk_level']] ?? 0) + 1;
}

$canManage = can('exception.manage');
$canCats   = can('exception.category');

function exc_url(array $over): string
{
    $base = [
        'page'  => 'exceptions',
        'cat'   => $_GET['cat'] ?? '',
        'state' => $_GET['state'] ?? '',
        'q'     => trim($_GET['q'] ?? ''),
    ];
    return '?' . http_build_query(array_merge($base, $over));
}
?>

<section class="grid grid--stats">
  <article class="card stat stat--blue">
    <p class="stat__label">Exceptions on record</p>
    <p class="stat__value"><?= count($all) ?></p>
    <p class="stat__hint"><?= (int) $stateCnt['Active'] ?> active · <?= (int) $stateCnt['Pending approval'] ?> awaiting approval</p>
  </article>
  <article class="card stat stat--orange">
    <p class="stat__label">Expiring ≤ 30 days</p>
    <p class="stat__value"><?= (int) $stateCnt['Expiring'] ?></p>
    <p class="stat__hint">renew or close before the period ends</p>
  </article>
  <article class="card stat stat--red">
    <p class="stat__label">Expired</p>
    <p class="stat__value"><?= (int) $stateCnt['Expired'] ?></p>
    <p class="stat__hint">no longer valid — revoke or extend</p>
  </article>
  <article class="card stat stat--neutral">
    <p class="stat__label">High risk</p>
    <p class="stat__value"><?= (int) $riskCnt['High'] ?></p>
    <p class="stat__hint"><?= count($cats) ?> categories in use</p>
  </article>
</section>

<?php if ($canManage): ?>
<details class="panel" <?= isset($_GET['new']) ? 'open' : '' ?>>
  <summary class="btn btn--primary btn--summary btn--sm">+ Add EDLP exception</summary>
  <form class="card form" method="post" action="index.php">
    <?= csrf_field() ?>
    <?= once_field() ?>
    <input type="hidden" name="action" value="create_exception">
    <input type="hidden" name="back" value="exceptions">
    <div class="form__grid">
      <label class="field field--wide"><span>Exception title *</span>
        <input type="text" name="title" required maxlength="200" placeholder="e.g. USB for label printer firmware update">
      </label>
      <label class="field"><span>Category</span>
        <select name="category">
          <?php foreach ($cats as $c): ?>
            <option value="<?= e($c['name']) ?>" <?= $catIn === $c['name'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span>Risk level</span>
        <select name="risk_level">
          <option>High</option><option selected>Medium</option><option>Low</option>
        </select>
      </label>
      <label class="field field--wide"><span>Target <span class="muted">(USB serial, website, camera ID, device…)</span></span>
        <input type="text" name="target" maxlength="180" placeholder="USB-KNG-4GB-0117 · portal.example.com · CAM-27">
      </label>
      <label class="field"><span>Period start</span>
        <input type="date" name="period_start" value="<?= date('Y-m-d') ?>">
      </label>
      <label class="field"><span>Period end</span>
        <input type="date" name="period_end" value="<?= date('Y-m-d', strtotime('+90 days')) ?>">
      </label>
      <label class="field"><span>Status</span>
        <select name="status">
          <option>Active</option><option>Pending</option><option>Revoked</option>
        </select>
      </label>
      <label class="field"><span>Reference no.</span>
        <input type="text" name="ref_no" maxlength="40" placeholder="EDLP-2026-001">
      </label>
      <label class="field"><span>Owner</span>
        <input type="text" name="owner" maxlength="100" value="<?= e(current_user_name()) ?>">
      </label>
      <label class="field"><span>Approved by</span>
        <input type="text" name="approved_by" maxlength="100" placeholder="Security Manager">
      </label>
      <label class="field field--wide"><span>Detail</span>
        <textarea name="detail" rows="3" placeholder="What is excepted, where, and who holds it"></textarea>
      </label>
      <label class="field field--wide"><span>Business justification</span>
        <textarea name="justification" rows="2" placeholder="Why the standard control cannot be applied"></textarea>
      </label>
      <label class="field field--wide"><span>Compensating control</span>
        <input type="text" name="control" maxlength="255" placeholder="e.g. scanned on the AV station, read-only mount, monthly log review">
      </label>
    </div>
    <div class="form__actions">
      <button type="submit" class="btn btn--primary btn--sm">Add exception</button>
      <span class="muted">A period end inside 30 days shows as <b>Expiring</b>; past it the record reads <b>Expired</b>.</span>
    </div>
  </form>
</details>
<?php endif; ?>

<div class="toolbar">
  <div class="chips chips--filter">
    <span class="chips__label">Category</span>
    <a class="chip <?= $catIn === '' ? 'is-on' : '' ?>" href="<?= e(exc_url(['cat' => ''])) ?>">All <b><?= count($all) ?></b></a>
    <?php foreach ($cats as $c): ?>
      <a class="chip chip--<?= e($c['tone']) ?> <?= $catIn === $c['name'] ? 'is-on' : '' ?>"
         href="<?= e(exc_url(['cat' => $catIn === $c['name'] ? '' : $c['name']])) ?>">
        <?= e($c['name']) ?> <b><?= (int) ($catCnt[$c['name']] ?? 0) ?></b>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="chips chips--filter">
    <span class="chips__label">State</span>
    <?php foreach (['active' => 'Active', 'expiring' => 'Expiring', 'expired' => 'Expired', 'pending' => 'Pending', 'revoked' => 'Revoked'] as $key => $label): ?>
      <a class="chip <?= $stateIn === $key ? 'is-on' : '' ?>" href="<?= e(exc_url(['state' => $stateIn === $key ? '' : $key])) ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <form class="search" method="get" action="index.php">
    <input type="hidden" name="page" value="exceptions">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search title, target, reference…">
    <button type="submit" class="btn btn--ghost">Search</button>
    <?php if ($catIn !== '' || $stateIn !== '' || $q !== ''): ?>
      <a class="btn btn--ghost" href="?page=exceptions">Clear</a>
    <?php endif; ?>
  </form>
</div>

<section class="card card--flush">
  <table class="table table--list">
    <caption class="sr-only">EDLP exception register</caption>
    <thead>
      <tr>
        <th class="num" scope="col">#</th>
        <th scope="col">Exception</th>
        <th>Category</th>
        <th>Period</th>
        <th>State</th>
        <th>Risk</th>
        <th>Owner</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $e): ?>
      <?php
        [$stateLabel, $stateTone] = exception_state($e);
        $days = exception_days_left($e['period_end'] ?? null);
      ?>
      <tr class="row <?= $stateLabel === 'Expired' ? 'row--overdue' : '' ?>">
        <td class="num"><?= $i + 1 ?></td>
        <td class="cell-issue">
          <div class="cell-issue__head">
            <span class="mono"><?= e($e['code']) ?></span>
            <span class="tag tag--blue"><?= e($e['system']) ?></span>
            <span class="strong"><?= e($e['title']) ?></span>
          </div>

          <?php if (trim((string) $e['target']) !== ''): ?>
            <span class="cell-issue__sub mono"><?= e($e['target']) ?></span>
          <?php endif; ?>

          <?php if (trim((string) $e['detail']) !== '' || trim((string) $e['justification']) !== '' || trim((string) $e['control']) !== ''): ?>
            <details class="detail detail--inline">
              <summary class="detail__more">Detail · justification · control</summary>
              <div class="detail__body">
                <dl class="kv">
                  <?php if (trim((string) $e['detail']) !== ''): ?>
                    <dt>Detail</dt><dd class="preline"><?= e($e['detail']) ?></dd>
                  <?php endif; ?>
                  <?php if (trim((string) $e['justification']) !== ''): ?>
                    <dt>Justification</dt><dd class="preline"><?= e($e['justification']) ?></dd>
                  <?php endif; ?>
                  <?php if (trim((string) $e['control']) !== ''): ?>
                    <dt>Compensating control</dt><dd class="preline"><?= e($e['control']) ?></dd>
                  <?php endif; ?>
                  <?php if (trim((string) $e['ref_no']) !== ''): ?>
                    <dt>Reference</dt><dd class="mono"><?= e($e['ref_no']) ?></dd>
                  <?php endif; ?>
                  <dt>Approved by</dt><dd><?= e($e['approved_by']) ?: '—' ?></dd>
                  <dt>Recorded</dt><dd><?= fmt_date($e['created_at']) ?></dd>
                </dl>
              </div>
            </details>
          <?php endif; ?>
        </td>
        <td><span class="tag tag--<?= e(category_tone($pdo, (string) $e['category'])) ?>"><?= e($e['category']) ?></span></td>
        <td class="nowrap">
          <span class="period"><?= e(exception_period($e)) ?></span>
          <?php if ($days !== null): ?>
            <span class="muted period__days period__days--<?= $days < 0 ? 'red' : ($days <= 30 ? 'orange' : 'neutral') ?>">
              <?= $days < 0 ? abs($days) . ' days ago' : $days . ' days left' ?>
            </span>
          <?php endif; ?>
        </td>
        <td><?= badge($stateLabel, $stateTone) ?></td>
        <td><?= badge($e['risk_level'], severity_tone($e['risk_level'])) ?></td>
        <td class="nowrap"><?= e($e['owner']) ?: '—' ?></td>
        <td class="right">
          <?php if ($canManage): ?>
            <details class="detail detail--inline">
              <summary class="btn btn--ghost btn--xs">Edit</summary>
              <div class="detail__body detail__body--card detail__body--wide">
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
              </div>
            </details>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="8" class="empty">No exceptions match this filter.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>

<p class="footnote"><?= count($rows) ?> of <?= count($all) ?> exception<?= count($all) === 1 ? '' : 's' ?> shown · periods are inclusive · state is derived from the period and the status</p>

<div class="grid grid--two">
  <!-- -------------------------------------------------------- categories -->
  <section class="card">
    <header class="card__head">
      <h2>Exception categories</h2>
      <span class="muted">adjustable · drives the filter above</span>
    </header>

    <div class="table-wrap">
      <table class="table table--tight">
        <thead>
          <tr><th>Category</th><th class="center">In use</th><th>Order</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td>
              <span class="chip-dot chip-dot--<?= e($c['tone']) ?>"></span>
              <span class="strong"><?= e($c['name']) ?></span>
            </td>
            <td class="center"><?= (int) ($catCnt[$c['name']] ?? 0) ?></td>
            <td class="mono muted"><?= (int) $c['sort'] ?></td>
            <td class="right">
              <?php if ($canCats): ?>
                <details class="detail detail--inline">
                  <summary class="btn btn--ghost btn--xs">Adjust</summary>
                  <div class="detail__body detail__body--card">
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
                  </div>
                </details>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($canCats): ?>
      <details class="panel panel--sub">
        <summary class="btn btn--ghost btn--summary btn--sm">+ Add category</summary>
        <form class="form form--sub" method="post" action="index.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_exception_category">
          <input type="hidden" name="back" value="exceptions">
          <div class="form__grid">
            <label class="field field--wide"><span>Name</span>
              <input type="text" name="name" required maxlength="60" placeholder="e.g. Printer / MFP, VPN, Mobile device">
            </label>
            <label class="field"><span>Accent</span>
              <select name="tone">
                <option value="neutral">Neutral</option>
                <option value="red">Red</option>
                <option value="blue">Blue</option>
                <option value="orange">Orange</option>
                <option value="green">Green</option>
              </select>
            </label>
          </div>
          <div class="form__actions">
            <button type="submit" class="btn btn--primary btn--sm">Add category</button>
          </div>
        </form>
      </details>
    <?php endif; ?>
  </section>

  <!-- ------------------------------------------------------------- rules -->
  <section class="card">
    <header class="card__head">
      <h2>How the EDLP exception register works</h2>
      <span class="muted">EDLP · approved deviations</span>
    </header>

    <ul class="bullets">
      <li><b>Every deviation has a period.</b> Start and end dates are mandatory in practice: inside 30 days of the end the row reads <b>Expiring</b>, past the end it reads <b>Expired</b> until it is renewed or revoked.</li>
      <li><b>Categories are adjustable.</b> USB storage, website access, cameras and cloud apps ship as defaults; add your own from the panel on the left. Renaming a category carries its exceptions with it.</li>
      <li><b>Detail is the audit trail.</b> The register keeps the target (serial number, domain or camera ID), who holds it, the business justification and the compensating control.</li>
      <li><b>Nothing is silent.</b> Records with an expired period or a missing approval stay visible in the list instead of disappearing — the point of the register is to see them.</li>
      <li><b>Authority:</b> Managers and analysts maintain exceptions; only administrators and managers adjust the category list.</li>
    </ul>

    <header class="card__head card__head--sub">
      <h3>Coverage by category</h3>
    </header>
    <ul class="bars">
      <?php foreach ($cats as $c): ?>
        <?php $n = (int) ($catCnt[$c['name']] ?? 0); ?>
        <li>
          <div class="bars__row">
            <span class="chip-dot chip-dot--<?= e($c['tone']) ?>"></span>
            <span class="bars__name"><?= e($c['name']) ?></span>
            <span class="bars__val"><?= $n ?> exception<?= $n === 1 ? '' : 's' ?></span>
          </div>
          <div class="bars__track">
            <span class="bars__fill bars__fill--<?= e($c['tone']) ?>" style="width: <?= count($all) ? max(3, round($n / count($all) * 100)) : 0 ?>%"></span>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>
