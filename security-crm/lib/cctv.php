<?php
/**
 * CCTV: camera register helpers, frame capture and environment health.
 *
 * Frames are pulled with FFmpeg when it is installed (RTSP/RTMP/HLS cameras),
 * with cURL when the camera exposes a JPEG snapshot URL, and — when neither is
 * available — as a generated demo frame so the wall still renders. Every
 * attempt is logged; failures land in logs/app.log and are shown on the page.
 *
 * Frames live in uploads/cctv/ and are only served through the permission
 * checked ?page=cctv_frame route, never straight from the folder.
 */

declare(strict_types=1);

const CCTV_MAX_FRAME_BYTES = 3145728;   // 3 MB — a still, not a recording
const CCTV_CAPTURE_SECONDS = 8;

/* ------------------------------------------------------------ vocabulary */

function cctv_modes(): array
{
    return ['ffmpeg' => 'FFmpeg stream (RTSP / RTMP / HLS)', 'http' => 'HTTP snapshot (JPEG URL)', 'demo' => 'Demo frame (no camera)'];
}

function cctv_status_list(): array
{
    return ['Online', 'Offline', 'Maintenance'];
}

function cctv_zones(): array
{
    return ['Perimeter', 'Production floor', 'Warehouse', 'Server room', 'Office', 'Car park', 'Other'];
}

function cctv_status_tone(string $status): string
{
    return ['Online' => 'green', 'Offline' => 'red', 'Maintenance' => 'orange'][$status] ?? 'neutral';
}

function cctv_resolution_list(): array
{
    return ['720p', '1080p', '2K', '4K'];
}

/* --------------------------------------------------------------- storage */

function cctv_dir(): string
{
    return dirname(__DIR__) . '/uploads/cctv';
}

function cctv_ensure_dir(): bool
{
    $dir = cctv_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
    return is_writable($dir);
}

/** A stored frame name reduced to a safe basename (no paths from the DB). */
function cctv_safe_file(string $name): string
{
    $safe = basename(trim($name));
    if ($safe === '' || $safe === '.' || $safe === '..' || $safe !== trim($name)) {
        return '';
    }
    return preg_match('/^[A-Za-z0-9._-]+\.(jpg|jpeg|png|svg)$/i', $safe) === 1 ? $safe : '';
}

function cctv_frame_path(string $name): string
{
    $safe = cctv_safe_file($name);
    return $safe === '' ? '' : cctv_dir() . DIRECTORY_SEPARATOR . $safe;
}

/** Only real camera transports; blocks file://, php:// and friends. */
function cctv_url_ok(string $url): bool
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 255) {
        return false;
    }
    return preg_match('#^(rtsps?|rtmps?|https?|hls)://[^\s]+$#i', $url) === 1;
}

function cctv_effective_mode(array $cam): string
{
    $mode = (string) ($cam['snapshot_mode'] ?? 'demo');
    if (!array_key_exists($mode, cctv_modes())) {
        return 'demo';
    }
    if ($mode === 'http' && !cctv_url_ok((string) ($cam['stream_url'] ?? ''))) {
        return 'demo';
    }
    if ($mode === 'ffmpeg' && !cctv_url_ok((string) ($cam['stream_url'] ?? ''))) {
        return 'demo';
    }
    return $mode;
}

/* ----------------------------------------------------------- the binaries */

/** Locate FFmpeg once per request; null when it is not installed. */
function cctv_ffmpeg_bin(): ?string
{
    static $resolved = false;
    if ($resolved !== false) {
        return $resolved;
    }

    $candidates = [];
    $env = trim((string) getenv('SECOPS_FFMPEG'));
    if ($env !== '') {
        $candidates[] = $env;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $candidates[] = 'ffmpeg.exe';
        foreach ([
            'C:\\laragon\\bin\\ffmpeg\\bin\\ffmpeg.exe',
            'C:\\laragon\\bin\\ffmpeg\\ffmpeg.exe',
            'C:\\ffmpeg\\bin\\ffmpeg.exe',
        ] as $c) {
            $candidates[] = $c;
        }
    } else {
        $candidates[] = 'ffmpeg';
        foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'] as $c) {
            $candidates[] = $c;
        }
    }

    foreach ($candidates as $c) {
        if (strpbrk($c, '/\\') !== false) {
            if (is_file($c) && (PHP_OS_FAMILY === 'Windows' || is_executable($c))) {
                return $resolved = $c;
            }
            continue;
        }
        $found = cctv_which($c);
        if ($found !== null) {
            return $resolved = $found;
        }
    }

    return $resolved = null;
}

/** PATH lookup without assuming exec() is enabled. */
function cctv_which(string $binary): ?string
{
    if (!function_exists('exec')) {
        return null;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        return null;
    }

    $out = [];
    $rc  = 1;
    if (PHP_OS_FAMILY === 'Windows') {
        @exec('where ' . escapeshellarg($binary) . ' 2>NUL', $out, $rc);
    } else {
        @exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null', $out, $rc);
    }
    $first = trim((string) ($out[0] ?? ''));
    return ($rc === 0 && $first !== '' && is_file($first)) ? $first : null;
}

/** Run a command with a hard timeout; returns [exitCode, stdout, stderr, seconds]. */
function cctv_run(array $command, int $seconds = CCTV_CAPTURE_SECONDS): array
{
    if (!function_exists('proc_open')) {
        return [-1, '', 'proc_open is disabled', 0.0];
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $start = microtime(true);
    $proc  = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return [-1, '', 'could not start the process', 0.0];
    }

    $out = '';
    $err = '';
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $code = -1;
    while (true) {
        $status = proc_get_status($proc);
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);

        if (!$status['running']) {
            $code = (int) $status['exitcode'];
            break;
        }
        if (microtime(true) - $start > $seconds) {
            @proc_terminate($proc, 9);
            $code = -1;
            $err .= ' (timed out after ' . $seconds . 's)';
            break;
        }
        usleep(50000);
    }

    foreach ($pipes as $p) {
        if (is_resource($p)) {
            @fclose($p);
        }
    }
    @proc_close($proc);

    return [$code, mb_substr($out, 0, 4000), mb_substr($err, 0, 4000), round(microtime(true) - $start, 2)];
}

/** `ffmpeg -version` first line, or null. */
function cctv_ffmpeg_version(?string $bin): ?string
{
    if ($bin === null) {
        return null;
    }
    [$code, $out] = cctv_run([$bin, '-hide_banner', '-version'], 4);
    if ($code !== 0 || $out === '') {
        return null;
    }
    $line = strtok($out, "\n") ?: '';
    return trim(mb_substr($line, 0, 120)) ?: null;
}

/* --------------------------------------------------------------- capture */

/**
 * Grab one still for a camera into uploads/cctv/.
 *
 * @return array{ok:bool, mode:string, file?:string, error?:string, detail?:string, ms?:float}
 */
function cctv_grab(array $cam): array
{
    $mode = cctv_effective_mode($cam);
    $id   = (int) ($cam['id'] ?? 0);
    $code = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($cam['code'] ?? 'cam')) ?: 'cam';
    $stamp = date('Ymd-His');

    if (!cctv_ensure_dir()) {
        return ['ok' => false, 'mode' => $mode, 'error' => 'The uploads/cctv folder is not writable.'];
    }

    $url = trim((string) ($cam['stream_url'] ?? ''));

    if ($mode === 'http') {
        if (!cctv_url_ok($url)) {
            return ['ok' => false, 'mode' => $mode, 'error' => 'That snapshot URL is not an http(s) address.'];
        }
        $file = "{$code}-{$stamp}.jpg";
        $path = cctv_dir() . DIRECTORY_SEPARATOR . $file;
        $res  = cctv_fetch_http($url, $path);
        return $res['ok']
            ? ['ok' => true, 'mode' => $mode, 'file' => $file, 'detail' => $res['detail'] ?? '', 'ms' => $res['ms'] ?? 0.0]
            : ['ok' => false, 'mode' => $mode, 'error' => $res['error'] ?? 'Snapshot download failed.'];
    }

    if ($mode === 'ffmpeg') {
        $bin = cctv_ffmpeg_bin();
        if ($bin === null) {
            return ['ok' => false, 'mode' => $mode, 'error' => 'FFmpeg is not installed — see “Add FFmpeg to Laragon” on this page.'];
        }
        if (!cctv_url_ok($url)) {
            return ['ok' => false, 'mode' => $mode, 'error' => 'That stream URL is not an rtsp/rtmp/http address.'];
        }

        $file = "{$code}-{$stamp}.jpg";
        $path = cctv_dir() . DIRECTORY_SEPARATOR . $file;

        $cmd = [$bin, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y'];
        if (stripos($url, 'rtsp') === 0) {
            array_push($cmd, '-rtsp_transport', 'tcp');
        }
        array_push($cmd, '-timeout', '5000000', '-i', $url, '-frames:v', '1', '-q:v', '4', $path);

        [$code_, , $err, $secs] = cctv_run($cmd, CCTV_CAPTURE_SECONDS);
        $ok = $code_ === 0 && is_file($path) && filesize($path) > 0;

        return $ok
            ? ['ok' => true, 'mode' => $mode, 'file' => $file, 'detail' => 'captured in ' . $secs . 's', 'ms' => $secs]
            : ['ok' => false, 'mode' => $mode, 'error' => 'FFmpeg could not read the stream: ' . (trim($err) ?: 'no output'), 'ms' => $secs];
    }

    /* demo: a generated still so the wall is never blank */
    $file = "{$code}-demo.jpg";
    $path = cctv_dir() . DIRECTORY_SEPARATOR . $file;
    $res  = cctv_demo_frame($cam, $path);
    return $res['ok']
        ? ['ok' => true, 'mode' => 'demo', 'file' => $file, 'detail' => 'generated demo frame']
        : ['ok' => false, 'mode' => 'demo', 'error' => $res['error'] ?? 'Could not draw the demo frame.'];
}

/** Download a JPEG/PNG snapshot with cURL, size- and type-checked. */
function cctv_fetch_http(string $url, string $dest): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'The cURL extension is not enabled in PHP.'];
    }
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => CCTV_CAPTURE_SECONDS,
        CURLOPT_MAXFILESIZE    => CCTV_MAX_FRAME_BYTES,
        CURLOPT_USERAGENT      => 'SecOps-CRM/1.0 (snapshot)',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $secs = round(microtime(true) - $start, 2);

    if ($body === false) {
        return ['ok' => false, 'error' => 'Snapshot request failed: ' . ($err ?: 'no answer')];
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => 'Snapshot URL answered HTTP ' . $code . '.'];
    }
    if (strlen($body) === 0 || strlen($body) > CCTV_MAX_FRAME_BYTES) {
        return ['ok' => false, 'error' => 'Snapshot size is outside 1 B – ' . human_size(CCTV_MAX_FRAME_BYTES) . '.'];
    }

    // A JPEG starts FF D8 FF, a PNG 89 50 4E 47 — anything else is not a still.
    $sig = substr($body, 0, 4);
    if ($sig === '' || (substr($sig, 0, 3) !== "\xFF\xD8\xFF" && $sig !== "\x89PNG")) {
        return ['ok' => false, 'error' => 'That URL did not return a JPEG or PNG image.'];
    }
    if (@file_put_contents($dest, $body) === false) {
        return ['ok' => false, 'error' => 'Could not write the frame to disk.'];
    }

    return ['ok' => true, 'detail' => human_size(strlen($body)) . ' in ' . $secs . 's', 'ms' => $secs];
}

/** Generated still (GD): grid, camera id, location, timestamp. */
function cctv_demo_frame(array $cam, string $dest): array
{
    if (!function_exists('imagecreatetruecolor')) {
        return ['ok' => false, 'error' => 'The GD extension is not enabled, so no demo frame can be drawn.'];
    }

    $w = 480;
    $h = 270;
    $im = imagecreatetruecolor($w, $h);
    if (!$im) {
        return ['ok' => false, 'error' => 'GD could not allocate the frame.'];
    }

    for ($y = 0; $y < $h; $y++) {
        $shade = (int) round($y * 14 / $h);
        $c = imagecolorallocate($im, 12 + $shade, 16 + $shade, 22 + $shade);
        imageline($im, 0, $y, $w, $y, $c);
    }

    $grid = imagecolorallocatealpha($im, 120, 145, 180, 116);
    for ($x = 0; $x < $w; $x += 40) {
        imageline($im, $x, 0, $x, $h, $grid);
    }
    for ($y = 0; $y < $h; $y += 40) {
        imageline($im, 0, $y, $w, $y, $grid);
    }

    $band = imagecolorallocatealpha($im, 47, 111, 237, 96);
    imagefilledrectangle($im, 0, 104, $w, 140, $band);

    $white = imagecolorallocate($im, 236, 241, 250);
    $muted = imagecolorallocate($im, 150, 163, 182);

    imagestring($im, 5, 14, 12, mb_substr((string) ($cam['code'] ?? 'CAM'), 0, 18), $white);
    imagestring($im, 3, 14, 34, mb_substr((string) ($cam['location'] ?? ''), 0, 46), $muted);
    imagestring($im, 3, 14, 58, 'NO LIVE SIGNAL · DEMO FRAME', $muted);
    imagestring($im, 3, 14, 150, date('Y-m-d H:i:s'), $white);
    imagestring($im, 2, 14, $h - 22, 'SecOps CRM · CCTV module', $muted);

    $ok = imagejpeg($im, $dest, 70);

    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'GD could not write the JPEG.'];
}

/** Save the capture result on the camera row. */
function cctv_store_frame(PDO $pdo, int $id, array $res): void
{
    // A generated demo frame proves the page renders, not that the camera is
    // back, so only a real source may flip the status.
    $live = ($res['mode'] ?? 'demo') !== 'demo';

    if ($res['ok']) {
        $pdo->prepare('UPDATE cameras SET frame_file = ?, last_frame_at = NOW(),
                              status = IF(? = 1 AND status = \'Offline\', \'Online\', status) WHERE id = ?')
            ->execute([(string) $res['file'], $live ? 1 : 0, $id]);
        return;
    }

    if ($live) {
        $pdo->prepare('UPDATE cameras SET status = \'Offline\' WHERE id = ? AND status <> \'Maintenance\'')->execute([$id]);
    }
}

/** Capture one camera, store the result, log it. $quiet skips the audit row. */
function cctv_capture(PDO $pdo, array $cam, bool $quiet = false): array
{
    $res = cctv_grab($cam);
    $id  = (int) ($cam['id'] ?? 0);
    cctv_store_frame($pdo, $id, $res);

    if (!$res['ok']) {
        app_log('cctv.capture', 'Camera ' . ($cam['code'] ?? ('#' . $id)) . ': ' . ($res['error'] ?? 'unknown error'));
    }
    if (!$quiet) {
        audit(
            $res['ok'] ? 'cctv.capture' : 'cctv.capture.fail',
            'camera',
            $id > 0 ? $id : null,
            (string) ($cam['code'] ?? ('#' . $id)),
            $res['ok']
                ? 'Frame captured (' . $res['mode'] . ')' . (!empty($res['detail']) ? ' · ' . $res['detail'] : '')
                : 'Capture failed (' . $res['mode'] . '): ' . mb_substr((string) ($res['error'] ?? ''), 0, 240)
        );
    }
    return $res;
}

/* ------------------------------------------------------------ presentation */

function cctv_frame_url(array $cam): string
{
    return '?page=cctv_frame&id=' . (int) ($cam['id'] ?? 0) . '&v=' . urlencode((string) ($cam['last_frame_at'] ?? '0'));
}

/** Ensure a demo camera has a still to show; returns the URL to render. */
function cctv_thumb(PDO $pdo, array $cam): string
{
    $file = (string) ($cam['frame_file'] ?? '');
    $path = cctv_frame_path($file);
    if ($path === '' || !is_file($path)) {
        if (cctv_effective_mode($cam) === 'demo') {
            cctv_capture($pdo, $cam, true);
            $cam = cctv_camera($pdo, (int) $cam['id']) ?? $cam;
        }
    }
    return cctv_frame_url($cam);
}

function cctv_camera(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM cameras WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function cctv_age_label(?string $when): string
{
    if (!$when) {
        return 'never';
    }
    $ts = strtotime($when);
    if (!$ts) {
        return 'never';
    }
    $mins = (int) floor((time() - $ts) / 60);
    if ($mins < 1) return 'just now';
    if ($mins < 60) return $mins . ' min ago';
    $hours = (int) floor($mins / 60);
    if ($hours < 24) return $hours . ' h ago';
    return (int) floor($hours / 24) . ' d ago';
}

/** Counts for the stat row, from the rows already loaded. */
function cctv_summary(array $cameras): array
{
    $out = ['total' => count($cameras), 'online' => 0, 'offline' => 0, 'maintenance' => 0, 'streams' => 0, 'demo' => 0];
    foreach ($cameras as $c) {
        $status = (string) ($c['status'] ?? 'Offline');
        if (isset($out[strtolower($status)])) {
            $out[strtolower($status)]++;
        }
        if (cctv_effective_mode($c) === 'demo') {
            $out['demo']++;
        } else {
            $out['streams']++;
        }
    }
    return $out;
}

/**
 * Environment checks shown on the CCTV page — one row per requirement.
 *
 * @return list<array{label:string, state:string, detail:string, fix:string}>
 */
function cctv_health(?PDO $pdo = null): array
{
    $rows = [];

    $bin = cctv_ffmpeg_bin();
    $ver = cctv_ffmpeg_version($bin);
    $rows[] = $bin === null
        ? [
            'label'  => 'FFmpeg',
            'state'  => 'warn',
            'detail' => 'Not found — RTSP/RTMP streams cannot be captured. Demo frames and HTTP snapshots still work.',
            'fix'    => 'Add FFmpeg to Laragon (steps in the panel below), or point SECOPS_FFMPEG at ffmpeg.exe.',
        ]
        : [
            'label'  => 'FFmpeg',
            'state'  => 'ok',
            'detail' => ($ver ?? 'ffmpeg found') . ' — ' . $bin,
            'fix'    => '',
        ];

    $rows[] = function_exists('curl_init')
        ? ['label' => 'cURL (HTTP snapshots)', 'state' => 'ok', 'detail' => 'enabled', 'fix' => '']
        : ['label' => 'cURL (HTTP snapshots)', 'state' => 'warn', 'detail' => 'not enabled', 'fix' => 'Enable extension=curl in php.ini, then restart Apache.'];

    $rows[] = function_exists('imagecreatetruecolor')
        ? ['label' => 'GD (demo frames)', 'state' => 'ok', 'detail' => 'enabled', 'fix' => '']
        : ['label' => 'GD (demo frames)', 'state' => 'warn', 'detail' => 'not enabled', 'fix' => 'Enable extension=gd in php.ini for placeholder stills.'];

    cctv_ensure_dir();
    $rows[] = is_writable(cctv_dir())
        ? ['label' => 'Frame folder', 'state' => 'ok', 'detail' => 'uploads/cctv is writable', 'fix' => '']
        : ['label' => 'Frame folder', 'state' => 'bad', 'detail' => 'uploads/cctv is not writable', 'fix' => 'Grant the web server user write access to security-crm/uploads/cctv.'];

    if ($pdo !== null) {
        $byMode = $pdo->query('SELECT snapshot_mode, COUNT(*) AS n FROM cameras WHERE active = 1 GROUP BY snapshot_mode')->fetchAll(PDO::FETCH_KEY_PAIR);
        $streams = (int) ($byMode['ffmpeg'] ?? 0) + (int) ($byMode['http'] ?? 0);
        $demo    = (int) ($byMode['demo'] ?? 0);
        $rows[] = $streams === 0
            ? [
                'label'  => 'Camera streams',
                'state'  => 'warn',
                'detail' => $demo . ' camera(s) configured as demo — no live URLs yet.',
                'fix'    => 'Edit a camera and choose “FFmpeg stream” or “HTTP snapshot”, then paste its URL.',
            ]
            : [
                'label'  => 'Camera streams',
                'state'  => 'ok',
                'detail' => $streams . ' live source(s), ' . $demo . ' demo.',
                'fix'    => '',
            ];
    }

    return $rows;
}

/** health state -> CSS tone */
function cctv_health_tone(string $state): string
{
    return ['ok' => 'green', 'warn' => 'orange', 'bad' => 'red'][$state] ?? 'neutral';
}

function cctv_health_summary(array $health): array
{
    $out = ['ok' => 0, 'warn' => 0, 'bad' => 0];
    foreach ($health as $h) {
        $state = (string) ($h['state'] ?? 'warn');
        $out[$state] = ($out[$state] ?? 0) + 1;
    }
    return $out;
}
