<?php
/**
 * System self-check — is this installation healthy enough to trust?
 *
 * Administrator only (page.health): it prints server paths, versions and the
 * age of the newest dump, none of which belong in the normal review set.
 *
 * Every check is a read. Nothing on this page changes the installation, which
 * is what makes it safe to open in front of an auditor; the checks mirror what
 * an ISO 27001 assessor asks first — is the evidence trail intact, is there a
 * recent backup, and are the folders the app writes to actually writable.
 *
 * The chain check is bounded by default: it re-walks the newest HEALTH_TAIL_WALK
 * entries and always compares the anchor. A page that hashes every row on each
 * load gets slower every day the trail grows, for a proof nobody asked for yet;
 * ?verify=1 walks the whole trail for the reader who needs that answer.
 */

/** Newest entries re-walked by the quick check. */
const HEALTH_TAIL_WALK = 500;

$appRoot    = dirname(__DIR__);
$uploadsDir = $appRoot . '/uploads';
$logsDir    = $appRoot . '/logs';
$backupDir  = $appRoot . '/backups';

/** Files and bytes under a folder, ignoring the .htaccess guards. */
function health_folder(string $dir): array
{
    $files = 0;
    $bytes = 0;
    if (!is_dir($dir)) {
        return ['files' => 0, 'bytes' => 0];
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getFilename() !== '.htaccess') {
            $files++;
            $bytes += (int) $file->getSize();
        }
    }

    return ['files' => $files, 'bytes' => $bytes];
}

function health_tone(string $state): string
{
    return ['ok' => 'green', 'warn' => 'orange', 'fail' => 'red'][$state] ?? 'neutral';
}

function health_word(string $state): string
{
    return ['ok' => 'OK', 'warn' => 'Warn', 'fail' => 'Fail'][$state] ?? 'Info';
}

$rows = [];
$add = static function (string $section, string $label, string $value, string $state = 'ok', string $note = '') use (&$rows): void {
    $rows[$section][] = ['label' => $label, 'value' => $value, 'state' => $state, 'note' => $note];
};

/* --------------------------------------------------------- application */

$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
$add('Application', 'PHP version', PHP_VERSION, $phpOk ? 'ok' : 'fail',
    $phpOk ? 'this build needs 8.0 or newer' : 'upgrade PHP — this build needs 8.0 or newer');

$add('Application', 'Application build', (string) SCHEMA_VERSION, 'ok',
    'SCHEMA_VERSION in lib/db.php, bumped once per schema change');

$add('Application', 'Clock', date('d M Y H:i') . ' · ' . date_default_timezone_get(), 'ok',
    'due dates, alerts and the trail are judged against this time');

$gz = function_exists('gzopen');
$add('Application', 'Backup compression', $gz ? 'zlib available' : 'zlib missing', $gz ? 'ok' : 'warn',
    $gz ? 'tools/backup.php writes .sql.gz' : 'dumps would be written uncompressed');

/* ------------------------------------------------------------ database */

try {
    $stored = (int) $pdo->query('SELECT version FROM schema_meta WHERE id = 1')->fetchColumn();
} catch (Throwable $e) {
    $stored = 0;
}
$schemaOk = $stored === SCHEMA_VERSION;
$add('Database', 'Connection',
    (string) $cfg['host'] . ':' . (int) $cfg['port'] . ' · ' . (string) $cfg['dbname'], 'ok',
    'pdo_mysql · utf8mb4');
$add('Database', 'Schema version', $stored . ' of ' . SCHEMA_VERSION, $schemaOk ? 'ok' : 'fail',
    $schemaOk ? 'up to date' : 'migrate() runs on the next request — expect one slower page load');

$tables = 0;
$totalRows = 0;
try {
    $names = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $tables = count($names);
    foreach ($names as $name) {
        $totalRows += (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', (string) $name) . '`')->fetchColumn();
    }
} catch (Throwable $e) {
    // The page still renders: the row below simply reports what it could read.
}
$add('Database', 'Tables and rows', $tables . ' table(s) · ' . number_format($totalRows) . ' row(s)', 'ok',
    'seeded demo data included when the database was empty');

/* --------------------------------------------------------- audit trail */

// Quick: the newest entries plus the anchor. Full: every entry, on request.
$walkAll    = isset($_GET['verify']);
$chainTotal = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$chain      = $walkAll ? audit_verify($pdo) : audit_verify_tail($pdo, HEALTH_TAIL_WALK);
$chainState = audit_chain_state($chain);
$chainTone  = ['ok' => 'ok', 'broken' => 'fail'][$chainState['state']] ?? 'warn';

$add('Audit trail', 'Hash chain',
    $chainState['label'] . ' · ' . audit_chain_scope($chain, $chainTotal), $chainTone,
    $chainState['detail']
    . ($chainState['state'] !== 'empty' && $chain['checked'] < $chainTotal
        ? ' · older entries need "Walk the whole chain" below'
        : ''));

$end         = audit_anchor_check($pdo);
$anchorState = audit_anchor_state($end);
$add('Audit trail', 'External anchor', $anchorState['label'],
    ['ok' => 'ok', 'broken' => 'fail'][$anchorState['state']] ?? 'warn', $anchorState['detail']);

$anchorPath = audit_anchor_file();
$anchorWritable = is_writable(dirname($anchorPath));
$add('Audit trail', 'Anchor file', $anchorPath, $anchorWritable ? 'ok' : 'warn',
    $anchorWritable
        ? 'keep it on a different backup from the database'
        : 'not writable — the console verifier cannot record a new anchor');

/* --------------------------------------------------------------- files */

$up = health_folder($uploadsDir);
$upWritable = is_writable($uploadsDir);
$add('Files', 'Evidence folder', $upWritable ? 'writable' : 'not writable', $upWritable ? 'ok' : 'fail',
    $up['files'] . ' file(s) · ' . human_size($up['bytes']) . ' — not inside the database dump, back it up with the dump');

$logPath = $logsDir . '/app.log';
$logSize = is_file($logPath) ? (int) filesize($logPath) : 0;
$logWritable = is_writable($logsDir);
$add('Files', 'Log folder', $logWritable ? 'writable' : 'not writable', $logWritable ? 'ok' : 'fail',
    'app.log ' . human_size($logSize) . ' — uncaught errors only, never pruned by the app');

/* ------------------------------------------------------------- backups */

$dumps = glob($backupDir . '/secops-crm-*.sql*') ?: [];
usort($dumps, static fn(string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));

if (!$dumps) {
    $add('Backups', 'Newest dump', 'none found in ' . $backupDir, 'fail',
        'run php tools/backup.php on the server — a backup you have never restored is not a control');
} else {
    $age   = max(0, (int) floor((time() - (int) filemtime($dumps[0])) / 86400));
    $state = $age <= 7 ? 'ok' : ($age <= 30 ? 'warn' : 'fail');
    $add('Backups', 'Newest dump',
        basename($dumps[0]) . ' · ' . ($age === 0 ? 'today' : $age . ' day(s) old'), $state,
        $state === 'ok'
            ? number_format(count($dumps)) . ' dump(s) kept in ' . $backupDir
            : 'take one now: php tools/backup.php');

    $manifests = glob($backupDir . '/secops-crm-*.json') ?: [];
    $add('Backups', 'Manifest', count($manifests) . ' of ' . count($dumps) . ' dump(s)',
        count($manifests) >= count($dumps) ? 'ok' : 'warn',
        'the manifest carries the sha256, row counts and audit head to compare after a restore');
}

/* ------------------------------------------------------- configuration */

$demo = (bool) ($cfg['demo_mode'] ?? false);
$add('Configuration', 'Demo accounts', $demo ? 'listed on the sign-in screen' : 'hidden', $demo ? 'warn' : 'ok',
    $demo ? 'set SECOPS_DEMO=0 for a real deployment'
          : 'the accounts are still seeded when the database is empty');

$proxies = (array) ($cfg['trusted_proxies'] ?? []);
$add('Configuration', 'Trusted proxies', $proxies ? implode(', ', $proxies) : 'none', 'ok',
    'forwarded client addresses are believed only from these');

// Plain HTTP is only a finding when nothing trusted sits in front of the app:
// behind a listed proxy the TLS hop is the proxy's, so the app sees HTTP.
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$peer  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$proxied = $peer !== '' && is_trusted_proxy($peer);
if ($https) {
    $add('Configuration', 'Transport', 'HTTPS', 'ok', 'HSTS is sent on every response');
} elseif ($proxied) {
    $add('Configuration', 'Transport', 'HTTP behind the trusted proxy', 'ok',
        'the proxy terminates TLS; HSTS is only sent for HTTPS requests');
} else {
    $add('Configuration', 'Transport', 'plain HTTP, directly exposed', 'warn',
        'put the app behind a TLS proxy before real data lands in it');
}

/* -------------------------------------------------------------- render */

$fails = 0;
$warns = 0;
foreach ($rows as $list) {
    foreach ($list as $row) {
        if ($row['state'] === 'fail') {
            $fails++;
        } elseif ($row['state'] === 'warn') {
            $warns++;
        }
    }
}

$verdict = $fails > 0 ? 'Attention needed' : ($warns > 0 ? 'Worth a look' : 'All checks passed');
$tone    = $fails > 0 ? 'red' : ($warns > 0 ? 'orange' : 'green');

audit('health.view', 'session', null, 'System self-check', $fails . ' failing, ' . $warns . ' warning(s)');
?>

<section class="card">
  <div class="card__head">
    <h2>Installation verdict</h2>
    <?= badge($verdict, $tone) ?>
  </div>
  <p class="muted">
    <?= $fails + $warns === 0
        ? 'Every check passed: the trail is intact, the folders the app writes to are writable and a recent dump exists.'
        : $fails . ' check(s) failed and ' . $warns . ' need attention — the rows below name them.' ?>
    Nothing here changes the installation: every check is a read.
  </p>
</section>

<?php foreach ($rows as $section => $list): ?>
<section class="card card--flush">
  <table class="table table--list">
    <caption class="sr-only"><?= e($section) ?> checks</caption>
    <thead>
      <tr>
        <th scope="col"><?= e($section) ?></th>
        <th scope="col">State</th>
        <th scope="col">Value</th>
        <th scope="col" class="col-secondary">Detail</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($list as $row): ?>
        <tr>
          <td class="strong nowrap"><?= e($row['label']) ?></td>
          <td><?= badge(health_word($row['state']), health_tone($row['state'])) ?></td>
          <td class="mono"><?= e($row['value']) ?></td>
          <td class="muted col-secondary"><?= e($row['note']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endforeach; ?>

<div class="toolbar">
  <?php if ($walkAll): ?>
    <a class="btn btn--ghost btn--sm" href="?page=health">Back to the quick check</a>
    <span class="muted">Every entry was walked on this load — the anchor still needs the console verifier, which is what records it.</span>
  <?php else: ?>
    <a class="btn btn--ghost btn--sm" href="?page=health&amp;verify=1">Walk the whole chain</a>
    <span class="muted">The quick check re-walks the newest <?= number_format(HEALTH_TAIL_WALK) ?> entries and compares the anchor; a break in an older entry needs the full walk.</span>
  <?php endif; ?>
</div>

<p class="muted">
  The chain and the anchor are explained under <a class="link" href="?page=audit">Audit trail</a>,
  and the backup / restore drill is in README.md ("Backups and restore").
  This page reads the trail; <span class="mono">php tests/verify-audit.php</span> does the same from a
  console and records a new anchor.
</p>
