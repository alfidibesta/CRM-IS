#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Database backup for the SecOps CRM.
 *
 *   php tools/backup.php [--out=DIR] [--keep=N]
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
 * Not included: the evidence files in uploads/. Back those up with the
 * database; the run prints what it left out.
 *
 * Exit 0 = dump written and manifest complete, 1 = nothing usable was written.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool runs from the command line only.' . PHP_EOL);
}

const BACKUP_PREFIX = 'secops-crm-';

$appRoot = dirname(__DIR__);

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

/** Files and bytes under uploads/, which the dump does not contain. */
function bk_dir_stats(string $dir): array
{
    $files = 0;
    $bytes = 0;
    if (!is_dir($dir)) {
        return ['files' => 0, 'bytes' => 0];
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && $file->getFilename() !== '.htaccess') {
            $files++;
            $bytes += (int) $file->getSize();
        }
    }

    return ['files' => $files, 'bytes' => $bytes];
}

// --------------------------------------------------------------------- args

$outDir = $appRoot . '/backups';
$keep   = 0;

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
    bk_fail('unknown option ' . $arg . ' (try --help)');
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

$hash  = hash_init('sha256');
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
    hash_update($hash, $chunk);
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

$uploads = bk_dir_stats($appRoot . '/uploads');

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
        'bytes'        => $bytes,
        'sha256'       => hash_final($hash),
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
    echo 'uploads   not in the dump: ' . $uploads['files'] . ' file(s), ' . bk_bytes($uploads['bytes'])
        . ' — copy uploads/ with it (README, "Backups and restore")' . PHP_EOL;
}

echo 'restore   scratch database + SECOPS_DB_NAME=<scratch> php tests/verify-audit.php'
    . ' → expect "chain intact (ids 1–' . $audit['head_id'] . ')"' . PHP_EOL;
echo '          (README, "Backups and restore": use a throwaway SECOPS_AUDIT_ANCHOR for the drill)' . PHP_EOL;

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
