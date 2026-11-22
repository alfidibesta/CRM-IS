<?php
/**
 * System log — the uncaught errors written to logs/app.log.
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
    foreach (array_reverse($raw) as $line) { // newest first
        if ($q === '' || mb_stripos($line, $q) !== false) {
            $lines[] = $line;
        }
    }
}

$errors = 0;
foreach ($lines as $line) {
    if (preg_match('/\[[^\]]+\]\s+(Error|Exception|ParseError|TypeError|ErrorException)/i', $line) === 1
        || mb_stripos($line, 'Fatal') !== false) {
        $errors++;
    }
}

audit('logs.view', 'session', null, 'System log', count($lines) . ' line(s) reviewed' . ($q !== '' ? ' · filter “' . mb_substr($q, 0, 60) . '”' : ''));
?>

<section class="grid grid--stats grid--stats--trio">
  <article class="card stat stat--red">
    <p class="stat__label">PHP errors</p>
    <p class="stat__value"><?= $errors ?></p>
    <p class="stat__hint">uncaught or logged faults in the tail</p>
  </article>
  <article class="card stat stat--neutral">
    <p class="stat__label">Other lines</p>
    <p class="stat__value"><?= count($lines) - $errors ?></p>
    <p class="stat__hint">context lines in the tail</p>
  </article>
  <article class="card stat stat--blue">
    <p class="stat__label">Log size</p>
    <p class="stat__value"><?= e(human_size($size)) ?></p>
    <p class="stat__hint">logs/app.log · newest 400 lines · <?= is_writable(dirname($path)) ? 'writable' : 'NOT writable' ?></p>
  </article>
</section>

<div class="toolbar">
  <form class="search" method="get" action="index.php">
    <input type="hidden" name="page" value="logs">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Filter the tail — error, SQL…">
    <button type="submit" class="btn btn--ghost">Filter</button>
    <?php if ($q !== ''): ?>
      <a class="btn btn--ghost" href="?page=logs">Clear</a>
    <?php endif; ?>
  </form>
</div>

<details class="panel" data-collapse="logs-tail" open>
  <summary class="card__head">
    <h2>Recent entries</h2>
    <span class="muted"><?= count($lines) ?> shown · newest first</span>
  </summary>

  <?php if (!$lines): ?>
    <p class="empty empty--sm">Nothing logged <?= $q !== '' ? 'for that filter' : 'yet' ?> — that is the good state.</p>
  <?php else: ?>
    <div class="card card--flush">
      <pre class="logtail"><?= e(implode("\n", $lines)) ?></pre>
    </div>
  <?php endif; ?>
</details>

<p class="muted">Only failures land here: the global handler writes every uncaught error before showing the neutral 500 page, and PHP's own <span class="mono">error_log</span> receives the same entries. Activities are in the <a class="link" href="?page=audit">audit trail</a>, not this file. Archive or truncate it with your backups — the app never prunes it.</p>
