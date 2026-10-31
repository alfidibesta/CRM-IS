<?php
/**
 * Issue detail report: full record, risk assessment, persons involved,
 * evidence upload, remediation tasks, activity log and Word export.
 */

$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM issues WHERE id = ?');
$st->execute([$id]);
$issue = $st->fetch();

if (!$issue) {
    http_response_code(404);
    echo '<section class="card"><p class="empty">Issue not found. <a class="link" href="?page=issues">Back to the register</a></p></section>';
    return;
}

$fetch = function (string $sql, array $args = []) use ($pdo): array {
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return $s->fetchAll();
};

$suspects = $fetch('SELECT * FROM suspects WHERE issue_id = ? ORDER BY FIELD(involvement,\'Primary\',\'Accomplice\',\'Witness\',\'Victim\',\'Unknown\'), id', [$id]);
$files    = $fetch('SELECT * FROM issue_files WHERE issue_id = ? ORDER BY id', [$id]);
$events   = $fetch('SELECT * FROM issue_events WHERE issue_id = ? ORDER BY created_at DESC, id DESC', [$id]);
$tasks    = $fetch('SELECT * FROM tasks WHERE issue_id = ? ORDER BY FIELD(column_key,\'in_progress\',\'review\',\'planned\',\'backlog\',\'done\'), position', [$id]);

$score    = (int) $issue['risk_score'];
[$rating] = risk_rating($score);
$like     = (int) $issue['likelihood'];
$imp      = (int) $issue['impact'];

$canEdit     = can('issue.update');
$canNote     = can('issue.note');
$canSuspect  = can('issue.suspect');
$canUpload   = can('issue.upload');
$canExport   = can('issue.export');
$canDownload = can('file.download');

$openTasks = count(array_filter($tasks, fn($t) => $t['column_key'] !== 'done'));
$noteCount = count(array_filter($events, fn($e) => $e['kind'] === 'note'));
?>

<section class="report-head card">
  <div class="report-head__main">
    <p class="report-head__code">
      <span class="mono strong"><?= e($issue['code']) ?></span>
      <span class="tag tag--<?= scope_tone($issue['category']) ?>"><?= e($issue['category']) ?></span>
      <?= badge($issue['severity'], severity_tone($issue['severity'])) ?>
      <?= badge($issue['status'], status_tone($issue['status'])) ?>
      <?php if ($issue['closed_at']): ?><?= badge('Closed ' . fmt_date($issue['closed_at']), 'green') ?><?php endif; ?>
    </p>
    <h2 class="report-head__title"><?= e($issue['title']) ?></h2>

    <dl class="factstrip">
      <div><dt>Location</dt><dd><?= e($issue['location']) ?: '—' ?></dd></div>
      <div><dt>Department</dt><dd><?= e($issue['department']) ?: '—' ?></dd></div>
      <div><dt>Asset / system</dt><dd><?= e($issue['asset']) ?: '—' ?></dd></div>
      <div><dt>Owner</dt><dd><?= e($issue['owner']) ?: '—' ?></dd></div>
      <div><dt>Reported</dt><dd><?= fmt_date($issue['reported_at']) ?> by <?= e($issue['reported_by']) ?: '—' ?></dd></div>
      <div><dt>Target close</dt><dd><?= fmt_date($issue['due_at']) ?></dd></div>
      <div><dt>Reviewed by</dt><dd><?= e($issue['reviewed_by']) ?: '—' ?></dd></div>
      <div><dt>Attachments</dt><dd><?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?> · <?= count($suspects) ?> person<?= count($suspects) === 1 ? '' : 's' ?></dd></div>
    </dl>
  </div>

  <aside class="report-head__side">
    <div class="riskbox riskbox--<?= risk_tone($score) ?>">
      <span class="riskbox__score"><?= $score ?></span>
      <span class="riskbox__meta">
        <span class="riskbox__label">Risk score</span>
        <strong><?= e($rating) ?></strong>
        <span class="muted"><?= $like ?> × <?= $imp ?></span>
      </span>
    </div>
    <div class="report-head__actions">
      <?php if ($canExport): ?>
        <a class="btn btn--primary btn--sm" href="?page=issue_doc&amp;id=<?= $id ?>">Download report (.docx)</a>
      <?php endif; ?>
      <a class="btn btn--ghost btn--sm" href="?page=issues">Back to register</a>
    </div>
    <p class="footnote">Report generated <?= date('d M Y') ?> · classification Internal</p>
  </aside>
</section>

<div class="grid grid--two grid--report">

  <!-- ---------------------------------------------------- report details -->
  <section class="card">
    <header class="card__head">
      <h2>1 · Report details</h2>
      <?php if ($canEdit): ?><span class="muted">All fields save together</span><?php endif; ?>
    </header>

    <?php if ($canEdit): ?>
      <form class="form" method="post" action="index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_issue">
        <input type="hidden" name="back" value="issue">
        <input type="hidden" name="back_id" value="<?= $id ?>">
        <input type="hidden" name="issue_id" value="<?= $id ?>">

        <div class="form__grid">
          <label class="field field--wide"><span>Title</span>
            <input type="text" name="title" required maxlength="200" value="<?= e($issue['title']) ?>">
          </label>
          <label class="field"><span>Category</span>
            <select name="category">
              <?php foreach (['Physical Security', 'IT Security', 'Strain Security'] as $c): ?>
                <option <?= $issue['category'] === $c ? 'selected' : '' ?>><?= $c ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field"><span>Severity</span>
            <select name="severity">
              <?php foreach (['Critical', 'High', 'Medium', 'Low'] as $s): ?>
                <option <?= $issue['severity'] === $s ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field"><span>Status</span>
            <select name="status">
              <?php foreach (['Open', 'In Progress', 'Contained', 'Resolved'] as $s): ?>
                <option <?= $issue['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field"><span>Owner</span>
            <input type="text" name="owner" maxlength="100" value="<?= e($issue['owner']) ?>">
          </label>
          <label class="field"><span>Location</span>
            <input type="text" name="location" maxlength="120" value="<?= e($issue['location']) ?>">
          </label>
          <label class="field"><span>Department</span>
            <input type="text" name="department" maxlength="100" value="<?= e($issue['department']) ?>">
          </label>
          <label class="field field--wide"><span>Asset or system</span>
            <input type="text" name="asset" maxlength="140" value="<?= e($issue['asset']) ?>"
                   placeholder="e.g. HMI-03 (10.20.4.31), east gate turnstile">
          </label>
          <label class="field"><span>Reported by</span>
            <input type="text" name="reported_by" maxlength="100" value="<?= e($issue['reported_by']) ?>">
          </label>
          <label class="field"><span>Reported on</span>
            <input type="date" name="reported_at" value="<?= e($issue['reported_at']) ?>">
          </label>
          <label class="field"><span>Target close</span>
            <input type="date" name="due_at" value="<?= e($issue['due_at']) ?>">
          </label>
          <label class="field"><span>Reviewed by</span>
            <input type="text" name="reviewed_by" maxlength="100" value="<?= e($issue['reviewed_by']) ?>">
          </label>
          <label class="field"><span>Approved by</span>
            <input type="text" name="approved_by" maxlength="100" value="<?= e($issue['approved_by']) ?>">
          </label>

          <label class="field field--wide"><span>Description &amp; observation</span>
            <textarea name="description" rows="6" placeholder="What was observed, when, where and by whom"><?= e($issue['description']) ?></textarea>
          </label>
        </div>

        <header class="card__head card__head--sub"><h3>2 · Risk assessment</h3></header>
        <div class="form__grid form__grid--risk">
          <label class="field"><span>Likelihood</span>
            <select name="likelihood" class="js-risk" data-axis="like">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <option value="<?= $i ?>" <?= $like === $i ? 'selected' : '' ?>><?= e(likelihood_label($i)) ?></option>
              <?php endfor; ?>
            </select>
          </label>
          <label class="field"><span>Impact</span>
            <select name="impact" class="js-risk" data-axis="imp">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <option value="<?= $i ?>" <?= $imp === $i ? 'selected' : '' ?>><?= e(impact_label($i)) ?></option>
              <?php endfor; ?>
            </select>
          </label>
          <div class="field"><span>Score</span>
            <output class="riskscore riskscore--<?= risk_tone($score) ?>" id="risk-out"><?= $score ?> · <?= e($rating) ?></output>
          </div>
        </div>

        <?php
        $cells = '';
        for ($y = 5; $y >= 1; $y--) {
            for ($x = 1; $x <= 5; $x++) {
                $s = $x * $y;
                $on = ($x === $like && $y === $imp) ? ' is-on' : '';
                $cells .= '<span class="heat__cell heat--' . risk_tone($s) . $on . '" data-x="' . $x . '" data-y="' . $y . '" title="L' . $x . ' × I' . $y . ' = ' . $s . '">' . $s . '</span>';
            }
        }
        ?>
        <div class="heat" id="risk-heatmap" aria-hidden="true">
          <div class="heat__axis">Impact →</div>
          <div class="heat__grid"><?= $cells ?></div>
          <div class="heat__legend">
            <span class="heat__leg heat--green">1–4 Low</span>
            <span class="heat__leg heat--blue">5–9 Moderate</span>
            <span class="heat__leg heat--orange">10–15 High</span>
            <span class="heat__leg heat--red">16–25 Critical</span>
          </div>
        </div>

        <div class="form__grid">
          <label class="field field--wide"><span>Root cause</span>
            <textarea name="root_cause" rows="2" placeholder="Why could this happen?"><?= e($issue['root_cause']) ?></textarea>
          </label>
          <label class="field field--wide"><span>Immediate action taken</span>
            <textarea name="immediate_action" rows="2" placeholder="Containment already done"><?= e($issue['immediate_action']) ?></textarea>
          </label>
          <label class="field field--wide"><span>Corrective / preventive action</span>
            <textarea name="corrective_action" rows="2" placeholder="What stops it happening again"><?= e($issue['corrective_action']) ?></textarea>
          </label>
        </div>

        <div class="form__actions">
          <button type="submit" class="btn btn--primary">Save report</button>
          <span class="muted">Saving records the change in the activity log.</span>
        </div>
      </form>
    <?php else: ?>
      <p class="readnote">Read-only role — your account can review and export this report but not change it.</p>
      <dl class="kv kv--two">
        <dt>Status</dt><dd><?= badge($issue['status'], status_tone($issue['status'])) ?></dd>
        <dt>Severity</dt><dd><?= badge($issue['severity'], severity_tone($issue['severity'])) ?></dd>
        <dt>Likelihood × impact</dt><dd><?= e(likelihood_label($like)) ?> · <?= e(impact_label($imp)) ?></dd>
        <dt>Owner</dt><dd><?= e($issue['owner']) ?: '—' ?></dd>
        <dt>Description</dt><dd class="preline"><?= e($issue['description']) ?: '—' ?></dd>
        <dt>Root cause</dt><dd class="preline"><?= e($issue['root_cause']) ?: '—' ?></dd>
        <dt>Immediate action</dt><dd class="preline"><?= e($issue['immediate_action']) ?: '—' ?></dd>
        <dt>Corrective action</dt><dd class="preline"><?= e($issue['corrective_action']) ?: '—' ?></dd>
        <dt>Approved by</dt><dd><?= e($issue['approved_by']) ?: '—' ?></dd>
      </dl>
    <?php endif; ?>
  </section>

  <!-- --------------------------------------------------- persons involved -->
  <section class="card">
    <header class="card__head">
      <h2>3 · Persons involved</h2>
      <span class="muted"><?= count($suspects) ?> record<?= count($suspects) === 1 ? '' : 's' ?></span>
    </header>

    <?php if (!$suspects): ?>
      <p class="empty empty--sm">No suspects, witnesses or victims recorded yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--tight">
          <thead>
            <tr><th>Name</th><th>Emp. no.</th><th>Dept / position</th><th>Shift</th><th>Involvement</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($suspects as $s): ?>
            <tr class="suspect suspect--<?= strtolower((string) $s['involvement']) ?>">
              <td>
                <details class="detail detail--inline">
                  <summary><span class="strong"><?= e($s['name']) ?></span></summary>
                  <div class="detail__body">
                    <dl class="kv">
                      <dt>Contact</dt><dd><?= e($s['contact']) ?: '—' ?></dd>
                      <dt>Interviewed</dt><dd><?= (int) $s['interviewed'] ? 'Yes' : 'No' ?></dd>
                      <dt>Statement</dt><dd class="preline"><?= e($s['statement']) ?: '—' ?></dd>
                    </dl>
                  </div>
                </details>
              </td>
              <td class="mono"><?= e($s['employee_no']) ?: '—' ?></td>
              <td><?= e($s['department']) ?: '—' ?><span class="muted"> · <?= e($s['position']) ?: '—' ?></span></td>
              <td class="nowrap"><?= e($s['shift']) ?: '—' ?></td>
              <td><?= badge($s['involvement'], $s['involvement'] === 'Primary' ? 'red' : ($s['involvement'] === 'Victim' ? 'orange' : 'neutral')) ?></td>
              <td class="right">
                <?php if ($canSuspect): ?>
                  <form class="inline-form" method="post" action="index.php" data-confirm="Remove this person from the report?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_suspect">
                    <input type="hidden" name="back" value="issue">
                    <input type="hidden" name="back_id" value="<?= $id ?>">
                    <input type="hidden" name="issue_id" value="<?= $id ?>">
                    <input type="hidden" name="suspect_id" value="<?= (int) $s['id'] ?>">
                    <button type="submit" class="btn btn--ghost btn--xs">Remove</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($canSuspect): ?>
      <details class="panel panel--sub" <?= $suspects ? '' : 'open' ?>>
        <summary class="btn btn--ghost btn--summary btn--sm">+ Add person</summary>
        <form class="form form--sub" method="post" action="index.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_suspect">
          <input type="hidden" name="back" value="issue">
          <input type="hidden" name="back_id" value="<?= $id ?>">
          <input type="hidden" name="issue_id" value="<?= $id ?>">
          <div class="form__grid">
            <label class="field"><span>Name *</span><input type="text" name="name" required maxlength="120"></label>
            <label class="field"><span>Employee no.</span><input type="text" name="employee_no" maxlength="40"></label>
            <label class="field"><span>Department</span><input type="text" name="department" maxlength="100"></label>
            <label class="field"><span>Position</span><input type="text" name="position" maxlength="100"></label>
            <label class="field"><span>Shift</span><input type="text" name="shift" maxlength="40" placeholder="Night / Morning…"></label>
            <label class="field"><span>Involvement</span>
              <select name="involvement">
                <?php foreach (['Primary', 'Accomplice', 'Witness', 'Victim', 'Unknown'] as $iv): ?>
                  <option <?= $iv === 'Witness' ? 'selected' : '' ?>><?= $iv ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field"><span>Contact</span><input type="text" name="contact" maxlength="60" placeholder="Phone / email"></label>
            <label class="field field--check">
              <input type="checkbox" name="interviewed" value="1"> <span>Interviewed</span>
            </label>
            <label class="field field--wide"><span>Statement</span>
              <textarea name="statement" rows="3" placeholder="What this person said or what they were seen doing"></textarea>
            </label>
          </div>
          <div class="form__actions">
            <button type="submit" class="btn btn--primary btn--sm">Add person</button>
          </div>
        </form>
      </details>
    <?php endif; ?>
  </section>

  <!-- ---------------------------------------------------- evidence files -->
  <section class="card">
    <header class="card__head">
      <h2>4 · Evidence &amp; attachments</h2>
      <span class="muted"><?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?></span>
    </header>

    <?php if ($files): ?>
      <ul class="filelist">
        <?php foreach ($files as $f): ?>
          <li class="filelist__item">
            <span class="filelist__icon filelist__icon--<?= e($f['ext']) ?>"><?= e(strtoupper($f['ext'])) ?></span>
            <span class="filelist__main">
              <span class="filelist__name"><?= e($f['original_name']) ?></span>
              <span class="muted"><?= e($f['kind']) ?> · <?= human_size((int) $f['size_bytes']) ?> · uploaded by <?= e($f['uploaded_by']) ?: '—' ?> · <?= fmt_date($f['created_at']) ?></span>
              <?php if ($f['note']): ?><span class="filelist__note"><?= e($f['note']) ?></span><?php endif; ?>
            </span>
            <span class="filelist__actions">
              <?php if ($canDownload): ?>
                <a class="btn btn--ghost btn--xs" href="?page=download&amp;file=<?= (int) $f['id'] ?>">Download</a>
              <?php endif; ?>
              <?php if ($canUpload): ?>
                <form class="inline-form" method="post" action="index.php" data-confirm="Delete this file permanently?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_file">
                  <input type="hidden" name="back" value="issue">
                  <input type="hidden" name="back_id" value="<?= $id ?>">
                  <input type="hidden" name="issue_id" value="<?= $id ?>">
                  <input type="hidden" name="file_id" value="<?= (int) $f['id'] ?>">
                  <button type="submit" class="btn btn--ghost btn--xs">Delete</button>
                </form>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="empty empty--sm">No evidence uploaded yet — photos, CCTV stills, logs, statements.</p>
    <?php endif; ?>

    <?php if ($canUpload): ?>
      <form class="form form--upload" method="post" action="index.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_evidence">
        <input type="hidden" name="back" value="issue">
        <input type="hidden" name="back_id" value="<?= $id ?>">
        <input type="hidden" name="issue_id" value="<?= $id ?>">

        <div class="dropzone">
          <input type="file" name="file" id="evidence-file" required
                 accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.txt,.csv,.log,.doc,.docx,.xls,.xlsx,.zip">
          <label for="evidence-file" class="dropzone__label">
            <strong>Choose a file</strong> or drop it here
            <span class="muted">pdf, images, txt, csv, log, doc/docx, xls/xlsx, zip · max 8 MB</span>
          </label>
          <p class="dropzone__name muted" id="dropzone-name">No file selected</p>
        </div>

        <div class="form__grid form__grid--upload">
          <label class="field"><span>Evidence kind</span>
            <select name="kind">
              <?php foreach (upload_kinds() as $k): ?>
                <option <?= $k === 'Photo' ? 'selected' : '' ?>><?= $k ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field field--wide"><span>Note</span>
            <input type="text" name="note" maxlength="255" placeholder="e.g. CCTV still 14:03, camera 14">
          </label>
        </div>

        <div class="form__actions">
          <button type="submit" class="btn btn--primary btn--sm">Upload evidence</button>
          <span class="muted">Files are stored outside the web root and served only through permission checks.</span>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <!-- -------------------------------------------------------- tasks + log -->
  <section class="card">
    <header class="card__head">
      <h2>5 · Remediation tasks</h2>
      <a class="link" href="?page=workflow"><?= $openTasks ?> open · open board</a>
    </header>

    <?php if ($tasks): ?>
      <ul class="tasklist">
        <?php foreach ($tasks as $t): ?>
          <li class="tasklist__item">
            <?= badge(str_replace('_', ' ', $t['column_key']), $t['column_key'] === 'done' ? 'green' : 'orange') ?>
            <span class="tasklist__title"><?= e($t['title']) ?></span>
            <?= badge($t['priority'], severity_tone($t['priority'])) ?>
            <span class="muted"><?= e($t['owner']) ?: '—' ?><?= $t['due_at'] ? ' · due ' . fmt_date($t['due_at']) : '' ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="empty empty--sm">No remediation task is linked to this report.</p>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <header class="card__head">
    <h2>6 · Activity log</h2>
    <span class="muted"><?= count($events) ?> entries · <?= $noteCount ?> note<?= $noteCount === 1 ? '' : 's' ?></span>
  </header>

  <?php if ($canNote): ?>
    <form class="form form--note" method="post" action="index.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="issue_note">
      <input type="hidden" name="back" value="issue">
      <input type="hidden" name="back_id" value="<?= $id ?>">
      <input type="hidden" name="issue_id" value="<?= $id ?>">
      <div class="noterow">
        <input type="text" name="note" maxlength="400" required
               placeholder="Add an investigation note, handover or decision…">
        <button type="submit" class="btn btn--primary btn--sm">Add note</button>
      </div>
    </form>
  <?php endif; ?>

  <ol class="timeline">
    <?php foreach ($events as $ev): ?>
      <li class="timeline__item timeline__item--<?= e($ev['kind']) ?>">
        <span class="timeline__dot"></span>
        <div class="timeline__body">
          <p class="timeline__text"><?= e($ev['message']) ?></p>
          <p class="timeline__meta"><?= e($ev['actor']) ?> · <?= date('d M Y · H:i', strtotime((string) $ev['created_at'])) ?></p>
        </div>
        <span class="timeline__kind"><?= e($ev['kind']) ?></span>
      </li>
    <?php endforeach; ?>
    <?php if (!$events): ?>
      <li class="timeline__item"><div class="timeline__body"><p class="muted">No activity recorded.</p></div></li>
    <?php endif; ?>
  </ol>
</section>
