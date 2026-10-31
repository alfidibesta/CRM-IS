<?php
/**
 * Evidence / attachment handling: validation, storage, download metadata.
 *
 * Files live in uploads/ with a random name (no user input in the path).
 * uploads/.htaccess denies direct web access, so downloads always go through
 * index.php?page=download and are permission-checked.
 */

declare(strict_types=1);

const UPLOAD_MAX_BYTES = 8388608; // 8 MB

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
    if ($size > UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Files must be 8 MB or smaller.'];
    }

    $ext = file_ext($name);
    if (!in_array($ext, allowed_upload_ext(), true)) {
        return ['ok' => false, 'error' => 'File type not allowed. Allowed: ' . implode(', ', allowed_upload_ext()) . '.'];
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

/** Absolute path for a stored file name (defends against traversal). */
function stored_path(string $storedName): string
{
    $safe = basename($storedName);
    if ($safe !== $storedName || $safe === '' || $safe === '.' || $safe === '..') {
        return '';
    }
    return uploads_dir() . DIRECTORY_SEPARATOR . $safe;
}
