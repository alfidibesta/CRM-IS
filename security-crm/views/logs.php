<?php
/**
 * System log — the handled and unhandled errors written to logs/app.log.
 *
 * Administrator only (page.logs): entries can carry file paths and SQL, so the
 * page is not part of the normal review set. Nothing here is writable.
 */

$path = dirname(__DIR__) . '/logs/app.log';
$size = is_file($path) ? (int) filesize($path) : 0;
$q    = trim((string) ($_GET['q'] ?? ''));

$lines = [];
if (is_file($path) && $size > 0) {
    $raw = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $raw = array_slice($raw, -400);          // the tail is what matters
    $raw = array_reverse($raw);              // newest first
    foreach ($raw as $line) {
        if ($q !== '' && mb_stripos($line, $q) === false) {
            continue;
        }
        $lines[] = $line;
    }
}

$counts = ['error' => 0, 'cctv' => 0, 'other' => 0];
foreach ($lines as $line) {
    if (mb_stripos($line, 'cctv.') !== false) {
        $counts['cctv']++;
    } elseif (preg_match('/\[[^\]]+\]\s+(Error|Exception|ParseError|TypeError|ErrorException)/i', $line) === 1
        || mb_stripos($line, 'Fatal') !== false) {
        $counts['error']++;
    } else {
        $counts['other']++;
    }
}

audit('logs.view', 'session', null, 'System log', count($lines) . ' line(s) reviewed' . ($q !== '' ? ' · filter “' . mb_substr($q, 0, 60) . '”' : ''));
?>

<section class="grid grid--stats">
  <article class="card stat stat--red">
    <p class="stat__label">PHP errors</p>
    <p class="stat__value"><?= (int) $counts['error'] ?></p>
    <p class="stat__hint">uncaught or logged faults in the tail</p>
  </article>
  <article class="card stat stat--orange">
    <p class="stat__label">Handled failures</p>
    <p class="stat__value"><?= (int) $counts['cctv'] ?></p>
    <p class="stat__hint">module actions that could not complete</p>
  </article>
  <article class="card stat stat--neutral">
    <p class="stat__label">Log size</p>
    <p class="stat__value"><?= e(human_size($size)) ?></p>
    <p class="stat__hint">logs/app.log · newest 400 lines</p>
  </article>
  <article class="card stat stat--blue">
    <p class="stat__label">Path</p>
    <p class="stat__value" style="font-size:13px;word-break:break-all">logs/app.log</p>
    <p class="stat__hint"><?= is_writable(dirname($path)) ? 'folder writable' : 'folder NOT writable' ?></p>
  </article>
</section>

<form class="filters filters--tight" method="get" action="index.php">
  <input type="hidden" name="page" value="logs">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Filter the tail — camera, exception, SQL…">
  <button type="submit" class="btn btn--ghost btn--sm">Filter</button>
  <?php if ($q !== ''): ?>
    <a class="btn btn--ghost btn--sm" href="?page=logs">Clear</a>
  <?php endif; ?>
</form>

<details class="panel" data-collapse="logs-tail" open>
  <summary class="cctv-block__head">
    <h2>Recent entries</h2>
    <span class="muted"><?= count($lines) ?> shown · newest first</span>
  </summary>

  <?php if (!$lines): ?>
    <p class="empty empty--sm">Nothing logged <?= $q !== '' ? 'for that filter' : 'yet' ?> — that is the good state.</p>
  <?php else: ?>
    <div class="card card--flush">
      <pre class="logtail"><?php foreach ($lines as $line): ?><span class="logtail__line<?= mb_stripos($line, 'cctv.') !== false ? ' is-warn' : '' ?>"><?= e($line) ?><?= "\n" ?></span><?php endforeach; ?></pre>
    </div>
  <?php endif; ?>
</details>

<div class="card cctv-doc">
  <h2>What lands here</h2>
  <ul class="cctv-steps">
    <li><b>Uncaught errors</b> — the global handler writes class, message, file and line, then shows the neutral 500 page.</li>
    <li><b>Handled failures</b> — a camera that will not answer, a stream FFmpeg cannot read, an upload folder that became read-only.</li>
    <li><b>Activities</b> are not here: every create, change, delete, sign-in and refusal goes to the append-only <a class="link" href="?page=audit">audit trail</a> instead, with actor, role, address and record.</li>
  </ul>
  <p class="muted">PHP's own error log (<span class="mono">error_log</span>) receives the same uncaught entries, so Apache's log stays useful too. Archive or truncate this file as part of your backup routine; the app never prunes it.</p>
</div>
