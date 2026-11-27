#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Database backup for the SecOps CRM.
 *
 *   php tools/backup.php [--out=DIR] [--keep=N] [--check=FILE]
 *
 * Dumps the configured database with mysqldump (or mariadb-dump) into
 * backups/secops-crm-YYYYmmdd-HHMMSS.sql.gz and writes a matching .json
 * manifest beside it: sha256, size, per-table row counts and the audit trail's
 * newest id + hash. Restoring that dump and comparing the manifest is the
 * restore drill in README.md ("Backups and restore").
 *
 * The connection is read-only on purpose — this tool never calls migrate() or
 * seeds anything, so taking a backup cannot change the database it copies.
 *
 * A dump that cannot show its own completion line is deleted and the run fails,
 * so a full disk or a killed server never leaves a plausible-looking half file.
 *
 * The evidence files are not inside the dump, so the manifest lists every one
 * of them under uploads/ with its own sha256. Copy the folder with the dump,
 * put it back into the restored app's uploads/, then check both halves with
 *
 *   php tools/backup.php --check=backups/secops-crm-YYYYmmdd-HHMMSS.json
 *
 * --check reads the dump beside the manifest and the app's own uploads/ folder
 * (never the database) and prints which folder it judged, so a copy left
 * somewhere else cannot pass the drill by accident.
 *
 * Exit 0 = dump written and manifest complete, 1 = nothing usable was written.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool runs from the command line only.' . PHP_EOL);
}

const BACKUP_PREFIX = 'secops-crm-';

$appRoot = dirname(__DIR__);
require $appRoot . '/lib/uploads.php';   // uploads_inventory() for the manifest

/** Human file size. */
function bk_bytes(int $bytes): string
{
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function bk_fail(string $message): void
{
    fwrite(STDERR, 'backup: ' . $message . PHP_EOL);
    exit(1);
}

function bk_usage(): void
{
    echo 'Usage: php tools/backup.php [--out=DIR] [--keep=N]' . PHP_EOL . PHP_EOL;
    echo '  --out=DIR   write the dump here (default: security-crm/backups)' . PHP_EOL;
    echo '  --keep=N    keep only the newest N dumps in that folder' . PHP_EOL;
    echo '  --check=FILE verify a dump and uploads/ against its manifest, then exit' . PHP_EOL;
    echo '  --help      this text' . PHP_EOL . PHP_EOL;
    echo 'Set SECOPS_MYSQLDUMP when mysqldump is not on PATH, for example' . PHP_EOL;
    echo 'C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqldump.exe' . PHP_EOL;
}

/**
 * Find a dump binary and prove it runs, so a wrong path fails here rather than
 * halfway through writing a file.
 *
 * @return array{bin: string, version: string}
 */
function bk_dump_tool(): array
{
    $candidates = [];
    $env = trim((string) getenv('SECOPS_MYSQLDUMP'));
    if ($env !== '') {
        $candidates[] = $env;
    }
    $candidates[] = 'mysqldump';
    $candidates[] = 'mariadb-dump';

    // Common Windows bundles, for users who have not added them to PATH.
    foreach ([
        'C:/laragon/bin/mysql/*/bin/mysqldump.exe',
        'C:/laragon/bin/mariadb/*/bin/mariadb-dump.exe',
        'C:/xampp/mysql/bin/mysqldump.exe',
    ] as $pattern) {
        foreach (glob($pattern) ?: [] as $hit) {
            $candidates[] = $hit;
        }
    }

    foreach (array_unique($candidates) as $bin) {
        $lines = [];
        $code  = 1;
        @exec(escapeshellarg($bin) . ' --version 2>&1', $lines, $code);
        $text = trim(implode(' ', $lines));
        // MySQL prints "... Distrib 8.0.30", MariaDB "... from 11.4.5-MariaDB":
        // a version number and a zero exit is what both have in common.
        if ($code === 0 && $text !== '' && preg_match('/\d+\.\d+/', $text) === 1) {
            return ['bin' => $bin, 'version' => $text];
        }
    }

    bk_fail('no mysqldump or mariadb-dump found. Add it to PATH, or point '
        . 'SECOPS_MYSQLDUMP at the binary (Laragon: C:\laragon\bin\mysql\<version>\bin\mysqldump.exe).');
    return ['bin' => '', 'version' => '']; // unreachable
}

/**
 * Client credentials in a temporary 0600 defaults file: never on the command
 * line (visible in ps) and not in the environment either.
 */
function bk_defaults_file(array $cfg): string
{
    $path = tempnam(sys_get_temp_dir(), 'secops-my-');
    if ($path === false) {
        bk_fail('cannot create a temporary file in ' . sys_get_temp_dir());
    }
    @chmod($path, 0600);

    $quote = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    $ini = "[client]\n"
        . 'host=' . $quote((string) $cfg['host']) . "\n"
        . 'port=' . (int) $cfg['port'] . "\n"
        . 'user=' . $quote((string) $cfg['user']) . "\n"
        . 'password=' . $quote((string) $cfg['password']) . "\n";

    if (file_put_contents($path, $ini) === false) {
        bk_fail('cannot write the temporary credentials file');
    }

    return $path;
}

/** Read-only connection: no migrate(), no seed. */
function bk_pdo(array $cfg): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string) $cfg['host'],
        (int) $cfg['port'],
        (string) $cfg['dbname'],
        (string) $cfg['charset']
    );

    return new PDO($dsn, (string) $cfg['user'], (string) $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
}

/**
 * Verify a dump and the uploads/ folder against a manifest.
 *
 * This is the restore check: it answers "is the copy I put back the one the
 * backup recorded?" for the dump as a whole and for every evidence file
 * individually, naming what is missing, changed or extra.
 */
function bk_check(string $manifestPath, string $appRoot): void
{
    if (!is_file($manifestPath)) {
        bk_fail('no manifest at ' . $manifestPath);
    }
    $raw = @file_get_contents($manifestPath);
    $m   = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($m) || ($m['app'] ?? '') !== 'SecOps CRM') {
        bk_fail('that file is not a SecOps CRM backup manifest');
    }

    $problems = 0;
    echo 'manifest  ' . $manifestPath . PHP_EOL;

    // A manifest may be edited by hand or carried in from elsewhere, so the
    // dump name is reduced to a bare file name: --check never reads a path out
    // of the manifest, only a file beside it.
    $dump     = is_array($m['dump'] ?? null) ? $m['dump'] : [];
    $dumpName = basename((string) ($dump['file'] ?? ''));
    $dumpFile = dirname($manifestPath) . '/' . $dumpName;
    // Manifests from this version record the hash and size of the written file
    // and keep the uncompressed count in sql_bytes; older ones hashed the SQL
    // stream, which cannot be compared with the file on disk.
    $fileHashed = isset($dump['sql_bytes']);

    if ($dumpName === '' || !is_file($dumpFile)) {
        echo 'dump      MISSING ' . ($dumpName !== '' ? $dumpName : '(no file recorded)') . PHP_EOL;
        $problems++;
    } elseif (!$fileHashed) {
        echo 'dump      this older manifest hashed the SQL stream, not the file — skipped' . PHP_EOL;
    } elseif (!hash_equals((string) ($dump['sha256'] ?? ''), (string) hash_file('sha256', $dumpFile))) {
        echo 'dump      sha256 DOES NOT MATCH ' . $dumpName . PHP_EOL;
        $problems++;
    } else {
        echo 'dump      sha256 matches · ' . bk_bytes((int) filesize($dumpFile)) . PHP_EOL;
    }

    $inventory = is_array($m['uploads']['inventory'] ?? null) ? $m['uploads']['inventory'] : null;
    if ($inventory === null) {
        // Older backups did not list the folder. A manifest from this version
        // that has lost its inventory is a different thing and must not pass.
        if ($fileHashed) {
            echo 'uploads   NO INVENTORY in a manifest that should carry one' . PHP_EOL;
            $problems++;
        } else {
            echo 'uploads   not recorded in this older manifest — skipped' . PHP_EOL;
        }
    } else {
        $expected = [];
        foreach ($inventory as $rel => $info) {
            if (is_string($rel) && is_array($info) && isset($info['sha256'])) {
                $expected[$rel] = (string) $info['sha256'];
            }
        }

        $dir     = $appRoot . '/uploads';
        $actual  = uploads_inventory($dir);
        $missing = array_diff_key($expected, $actual);
        $extra   = array_diff_key($actual, $expected);
        $changed = [];
        foreach (array_intersect_key($expected, $actual) as $rel => $want) {
            if (!hash_equals($want, (string) $actual[$rel]['sha256'])) {
                $changed[] = (string) $rel;
            }
        }

        echo 'uploads   ' . $dir . PHP_EOL;
        echo '          ' . count($expected) . ' expected · ' . count($actual) . ' present · '
            . count($missing) . ' missing · ' . count($changed) . ' changed · ' . count($extra) . ' extra' . PHP_EOL;

        foreach (array_slice(array_keys($missing), 0, 5) as $rel) {
            echo '          missing ' . $rel . PHP_EOL;
        }
        foreach (array_slice($changed, 0, 5) as $rel) {
            echo '          changed ' . $rel . PHP_EOL;
        }
        foreach (array_slice(array_keys($extra), 0, 5) as $rel) {
            echo '          extra   ' . $rel . PHP_EOL;
        }

        if ($missing || $changed || $extra) {
            $problems++;
        }
    }

    echo 'result    ' . ($problems === 0
        ? 'OK — the dump' . ($inventory !== null ? ' and every evidence file match' : ' matches') . ' the manifest'
        : $problems . ' problem group(s) — the restore is not the backup') . PHP_EOL;

    exit($problems === 0 ? 0 : 1);
}

// --------------------------------------------------------------------- args

$outDir    = $appRoot . '/backups';
$keep      = 0;
$checkFile = '';

foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        bk_usage();
        exit(0);
    }
    if (str_starts_with($arg, '--out=')) {
        $outDir = rtrim(substr($arg, 6), '/\\');
        if ($outDir === '') {
            bk_fail('--out needs a folder');
        }
        continue;
    }
    if (str_starts_with($arg, '--keep=')) {
        $keep = (int) substr($arg, 7);
        if ($keep < 1) {
            bk_fail('--keep needs a number of dumps to keep (1 or more)');
        }
        continue;
    }
    if (str_starts_with($arg, '--check=')) {
        $checkFile = substr($arg, 8);
        if ($checkFile === '') {
            bk_fail('--check needs the manifest to verify');
        }
        continue;
    }
    bk_fail('unknown option ' . $arg . ' (try --help)');
}

// A restore check reads only the manifest and the restored files; it never
// needs the database or a dump binary.
if ($checkFile !== '') {
    bk_check($checkFile, $appRoot);
}

// --------------------------------------------------------------------- setup

$cfg = require $appRoot . '/config.php';

if (!is_dir($outDir) && !@mkdir($outDir, 0700, true) && !is_dir($outDir)) {
    bk_fail('cannot create the backup folder ' . $outDir);
}
if (!is_writable($outDir)) {
    bk_fail('the backup folder is not writable: ' . $outDir);
}

try {
    $pdo = bk_pdo($cfg);
} catch (Throwable $e) {
    bk_fail('cannot read the database: ' . $e->getMessage()
        . PHP_EOL . '       check config.php (or the SECOPS_DB_* variables); open the app once first.');
}

$tool = bk_dump_tool();
$db   = (string) $cfg['dbname'];
$stamp = date('Ymd-His');
$base  = BACKUP_PREFIX . $stamp;
$gz    = function_exists('gzopen');
$final = $outDir . '/' . $base . ($gz ? '.sql.gz' : '.sql');
$part  = $outDir . '/.' . $base . '.part';
$creds = bk_defaults_file($cfg);

$cmd = escapeshellarg($tool['bin'])
    . ' --defaults-extra-file=' . escapeshellarg($creds)
    . ' --single-transaction --quick --skip-lock-tables --no-tablespaces'
    . ' --default-character-set=' . escapeshellarg((string) $cfg['charset'])
    . ' ' . escapeshellarg($db);

$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) {
    @unlink($creds);
    bk_fail('cannot start ' . $tool['bin']);
}

// ---------------------------------------------------------------- write dump

$bytes = 0;
$tail  = '';
$writeError = '';

$sink = $gz ? @gzopen($part, 'wb6') : @fopen($part, 'wb');
if ($sink === false) {
    proc_terminate($proc);
    @unlink($creds);
    bk_fail('cannot write ' . $part);
}

while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 1 << 20);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $bytes += strlen($chunk);
    $tail = substr($tail . $chunk, -4096);

    $wrote = $gz ? gzwrite($sink, $chunk) : fwrite($sink, $chunk);
    if ($wrote === false || $wrote === 0) {
        $writeError = 'the disk stopped accepting data after ' . bk_bytes($bytes);
        break;
    }
}

fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exit = proc_close($proc);

$gz ? gzclose($sink) : fclose($sink);
@unlink($creds);

if ($writeError !== '') {
    @unlink($part);
    bk_fail($writeError);
}
if ($exit !== 0) {
    @unlink($part);
    bk_fail('the dump failed (exit ' . $exit . '): ' . trim((string) $stderr));
}
if ($bytes === 0) {
    @unlink($part);
    bk_fail('the dump is empty');
}
// mysqldump writes this last; without it the file is a fragment.
if (stripos($tail, 'Dump completed') === false) {
    @unlink($part);
    bk_fail('the dump has no completion line, so it is incomplete — nothing was kept');
}
if (!@rename($part, $final)) {
    @unlink($part);
    bk_fail('cannot move the dump into place at ' . $final);
}
@chmod($final, 0600);

// ------------------------------------------------------------- record facts

$tables = [];
$rowCounts = [];
$totalRows = 0;
try {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $safe = str_replace('`', '', (string) $table);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM `' . $safe . '`')->fetchColumn();
        $rowCounts[$safe] = $count;
        $totalRows += $count;
    }
} catch (Throwable $e) {
    // The dump is already safe; the manifest just carries fewer numbers.
}

$schemaVersion = 0;
try {
    $schemaVersion = (int) $pdo->query('SELECT version FROM schema_meta WHERE id = 1')->fetchColumn();
} catch (Throwable $e) {
    $schemaVersion = 0;
}

$audit = ['entries' => 0, 'head_id' => 0, 'head_hash' => ''];
try {
    $audit['entries'] = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    $head = $pdo->query('SELECT id, row_hash FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
    if ($head) {
        $audit['head_id']   = (int) $head['id'];
        $audit['head_hash'] = (string) $head['row_hash'];
    }
} catch (Throwable $e) {
    // Older installs may not have the trail sealed yet.
}

$uploadsInventory = uploads_inventory($appRoot . '/uploads');
$uploadsBytes     = array_sum(array_column($uploadsInventory, 'bytes'));
$uploads = [
    'folder'    => 'uploads',
    'files'     => count($uploadsInventory),
    'bytes'     => (int) $uploadsBytes,
    'inventory' => $uploadsInventory,
];

$manifest = [
    'app'         => 'SecOps CRM',
    'created_at'  => date('c'),
    'database'    => [
        'name'           => $db,
        'host'           => (string) $cfg['host'],
        'port'           => (int) $cfg['port'],
        'schema_version' => $schemaVersion,
        'tables'         => count($rowCounts),
        'rows'           => $totalRows,
        'row_counts'     => $rowCounts,
    ],
    'audit'       => $audit,
    'dump'        => [
        'file'         => basename($final),
        // The hash and size of the file as written (gzip included), which is
        // what --check re-reads. sql_bytes is the uncompressed stream it holds.
        'bytes'        => (int) @filesize($final),
        'sha256'       => (string) hash_file('sha256', $final),
        'sql_bytes'    => $bytes,
        'gzip'         => $gz,
        'tool'         => basename($tool['bin']),
        'tool_version' => $tool['version'],
    ],
    'uploads'     => $uploads,
    'php'         => PHP_VERSION,
];

$manifestPath = $outDir . '/' . $base . '.json';
$written = @file_put_contents(
    $manifestPath,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);
if ($written === false) {
    bk_fail('the dump is at ' . $final . ' but the manifest could not be written');
}
@chmod($manifestPath, 0600);

// ------------------------------------------------------------------- report

$rel = static function (string $path) use ($appRoot): string {
    $prefix = $appRoot . DIRECTORY_SEPARATOR;
    return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
};

echo 'dump      ' . $rel($final) . PHP_EOL;
echo 'size      ' . bk_bytes((int) @filesize($final)) . ($gz ? ' (gzip)' : '') . PHP_EOL;
echo 'sha256    ' . $manifest['dump']['sha256'] . PHP_EOL;
echo 'tables    ' . count($rowCounts) . ' · ' . number_format($totalRows) . ' rows' . PHP_EOL;
echo 'audit     ' . ($audit['head_id'] > 0
        ? 'head #' . $audit['head_id'] . ' of ' . number_format($audit['entries']) . ' entries · '
            . substr($audit['head_hash'], 0, 12) . '…'
        : 'no sealed entries yet')
    . PHP_EOL;
echo 'manifest  ' . $rel($manifestPath) . PHP_EOL;

if ($uploads['files'] > 0) {
    echo 'uploads   ' . $uploads['files'] . ' file(s), ' . bk_bytes($uploads['bytes']) . PHP_EOL;
    echo '          every file is listed with its sha256 in the manifest; copy uploads/'
        . ' with the dump' . PHP_EOL;
}

echo 'restore   scratch database + SECOPS_DB_NAME=<scratch> php tests/verify-audit.php'
    . ' → expect "chain intact (ids 1–' . $audit['head_id'] . ')"' . PHP_EOL;
echo '          (README, "Backups and restore": use a throwaway SECOPS_AUDIT_ANCHOR for the drill)' . PHP_EOL;
echo 'check     php tools/backup.php --check=' . $rel($manifestPath) . PHP_EOL;

// -------------------------------------------------------------------- prune

if ($keep > 0) {
    $found = glob($outDir . '/' . BACKUP_PREFIX . '*.sql*') ?: [];
    $found = array_values(array_filter($found, static fn(string $f): bool => str_ends_with($f, '.sql') || str_ends_with($f, '.sql.gz')));
    rsort($found);
    $removed = 0;
    foreach (array_slice($found, $keep) as $old) {
        $stem = preg_replace('/\.sql(\.gz)?$/', '', $old) ?? $old;
        if (@unlink($old)) {
            $removed++;
        }
        @unlink($stem . '.json');
    }
    if ($removed > 0) {
        echo 'pruned    ' . $removed . ' older dump(s), keeping the newest ' . $keep . PHP_EOL;
    }
}

exit(0);
