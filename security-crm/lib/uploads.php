<?php
/**
 * Evidence / attachment handling: validation, storage, download metadata.
 *
 * Files live in uploads/ with a random name (no user input in the path).
 * uploads/.htaccess denies direct web access, so downloads always go through
 * index.php?page=download and are permission-checked.
 */

declare(strict_types=1);

const UPLOAD_MAX_BYTES = 8388608; // 8 MB ceiling for the application itself

/** php.ini sizes look like "2M", "512K", "1G" or plain bytes. */
function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return 0;
    }
    $num  = (float) $value;
    $unit = strtolower(substr($value, -1));
    return (int) match ($unit) {
        'g' => $num * 1073741824,
        'm' => $num * 1048576,
        'k' => $num * 1024,
        default => $num,
    };
}

/**
 * Effective per-file cap: the smallest of php.ini, post_max_size and our own.
 * The UI and the server both use this, so the promised limit is the real one.
 */
function upload_limit_bytes(): int
{
    $limits = [];
    foreach ([ini_get('upload_max_filesize'), ini_get('post_max_size'), UPLOAD_MAX_BYTES] as $raw) {
        $bytes = is_numeric($raw) ? (int) $raw : ini_bytes((string) $raw);
        if ($bytes > 0) {
            $limits[] = $bytes;
        }
    }
    return $limits ? (int) min($limits) : UPLOAD_MAX_BYTES;
}

/**
 * How many files PHP takes from one request (max_file_uploads, 0 = no cap).
 *
 * Past this number PHP drops the extra files before the application runs and
 * leaves no entry for them in $_FILES, so the cap can only be shown up front
 * and mentioned when a batch arrives at it.
 */
function upload_max_files(): int
{
    $n = (int) ini_get('max_file_uploads');
    return $n > 0 ? $n : 0;
}

/** Whole request body PHP accepts (post_max_size, 0 = no cap). */
function upload_total_bytes(): int
{
    $raw   = (string) ini_get('post_max_size');
    $bytes = is_numeric($raw) ? (int) $raw : ini_bytes($raw);
    return $bytes > 0 ? $bytes : 0;
}

function uploads_dir(): string
{
    return dirname(__DIR__) . '/uploads';
}

function ensure_uploads_dir(): void
{
    $dir = uploads_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
}

function allowed_upload_ext(): array
{
    return ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'csv', 'log', 'doc', 'docx', 'xls', 'xlsx', 'zip'];
}

function upload_kinds(): array
{
    return ['Photo', 'Screenshot', 'Log', 'Document', 'Statement', 'Other'];
}

function human_size(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function file_ext(string $name): string
{
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
}

/**
 * The files in one $_FILES field, one entry per selected file.
 *
 * A browser sends `name[]`, `tmp_name[]`, `size[]`, `error[]` and `type[]` for
 * `<input type="file" name="files[]" multiple>` — the same shape whether one
 * file was chosen or ten. Slots the browser left empty (no file picked) are
 * dropped, so the caller only sees real selections; a single-file field is
 * returned as one entry, so the same loop stores both.
 *
 * @return list<array{name: string, tmp_name: string, error: int, size: int}>
 */
function upload_batch(array $field): array
{
    $names = $field['name'] ?? null;
    if (!is_array($names)) {
        return (int) ($field['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$field];
    }

    $files = [];
    foreach (array_keys($names) as $i) {
        $one = [
            'name'     => (string) ($names[$i] ?? ''),
            'tmp_name' => (string) ($field['tmp_name'][$i] ?? ''),
            'error'    => (int) ($field['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int) ($field['size'][$i] ?? 0),
        ];
        if ($one['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = $one;
    }

    return $files;
}

/**
 * Validate + store one uploaded file.
 *
 * @param array $file one entry of $_FILES
 * @return array{ok:bool, error?:string, stored?:string, name?:string, size?:int, mime?:string, ext?:string}
 */
function store_upload(array $file): array
{
    ensure_uploads_dir();

    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file selected.'];
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'That file is larger than the server limit.'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed (code ' . $err . ').'];
    }

    $tmp  = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $name = trim((string) ($file['name'] ?? ''));

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if ($name === '') {
        return ['ok' => false, 'error' => 'The file has no name.'];
    }
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'The file is empty.'];
    }
    if ($size > upload_limit_bytes()) {
        return ['ok' => false, 'error' => 'Files must be ' . human_size(upload_limit_bytes()) . ' or smaller.'];
    }

    // The form lists the accepted formats; the error names only what was refused.
    $ext = file_ext($name);
    if (!in_array($ext, allowed_upload_ext(), true)) {
        return ['ok' => false, 'error' => 'File type not allowed' . ($ext !== '' ? ' (.' . $ext . ')' : '') . '.'];
    }

    $mime = function_exists('finfo_open')
        ? (string) (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp) ?: '')
        : '';

    // Refuse anything that smells like a script, whatever the extension says.
    $blocked = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'cgi', 'pl', 'py', 'sh', 'htaccess'];
    if (in_array($ext, $blocked, true) || stripos($mime, 'php') !== false || stripos($mime, 'x-httpd') !== false) {
        return ['ok' => false, 'error' => 'Script files cannot be uploaded.'];
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest   = uploads_dir() . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'Could not store the file on disk.'];
    }

    return [
        'ok'     => true,
        'stored' => $stored,
        'name'   => mb_substr($name, 0, 190),
        'size'   => $size,
        'mime'   => $mime !== '' ? $mime : 'application/octet-stream',
        'ext'    => $ext,
    ];
}

/**
 * The flash line for one batch: what was stored, then why the rest was not.
 *
 * @param list<string> $saved  original names that were stored
 * @param list<string> $failed "name — reason" for each file that was not
 * @param int $maxFiles per-upload file cap, 0 when the server has none
 */
function upload_batch_summary(array $saved, array $failed, int $maxFiles = 0): string
{
    $said = [];

    if ($saved) {
        $said[] = count($saved) === 1
            ? 'Evidence uploaded: ' . $saved[0] . '.'
            : count($saved) . ' evidence files uploaded.';
    }

    if ($failed) {
        $shown  = array_slice($failed, 0, 3);
        $more   = count($failed) - count($shown);
        $said[] = ($saved ? count($failed) . ' not uploaded: ' : 'Nothing uploaded: ')
            . implode('; ', array_map(static fn($one) => rtrim($one, '.'), $shown))
            . ($more > 0 ? ' … and ' . $more . ' more' : '') . '.';
    }

    // Files past max_file_uploads never reach the app, so a batch that arrives
    // at the cap is the one place the desk can be told the rest may be missing.
    if ($maxFiles > 0 && count($saved) + count($failed) >= $maxFiles) {
        $said[] = 'The server takes ' . $maxFiles . ' file' . ($maxFiles === 1 ? '' : 's')
            . ' per upload — anything beyond that was not received.';
    }

    return $said ? implode(' ', $said) : 'No file selected.';
}

/**
 * Every evidence file under $dir, keyed by its path relative to the folder.
 *
 * Used by tools/backup.php: the manifest records each file's size, sha256 and
 * modification time, and --check compares a restored folder file by file. The
 * directory's own .htaccess is hardening, not evidence, so it is left out, as
 * are symlinks (which could point outside uploads/).
 *
 * @return array<string, array{bytes: int, sha256: string, modified: string}>
 */
function uploads_inventory(string $dir): array
{
    $dir = rtrim($dir, '/\\');
    if ($dir === '' || !is_dir($dir)) {
        return [];
    }

    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
        if ($rel === '' || $rel === '.htaccess') {
            continue;
        }
        $files[$rel] = [
            'bytes'    => (int) $file->getSize(),
            'sha256'   => (string) hash_file('sha256', $file->getPathname()),
            'modified' => date('c', $file->getMTime()),
        ];
    }
    ksort($files);

    return $files;
}

/** Absolute path for a stored file name (defends against traversal). */
function stored_path(string $storedName): string
{
    $safe = basename($storedName);
    if ($safe !== $storedName || $safe === '' || $safe === '.' || $safe === '..') {
        return '';
    }
    return uploads_dir() . DIRECTORY_SEPARATOR . $safe;
}
