<?php

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Small pill badge. $tone drives the colour (red / blue / orange / neutral). */
function badge(string $label, string $tone = 'neutral'): string
{
    return '<span class="badge badge--' . e($tone) . '">' . e($label) . '</span>';
}

function severity_tone(string $s): string
{
    return ['Critical' => 'red', 'High' => 'orange', 'Medium' => 'blue', 'Low' => 'neutral'][$s] ?? 'neutral';
}

function status_tone(string $s): string
{
    return [
        'Active' => 'green', 'Draft' => 'neutral', 'In Review' => 'blue', 'Obsolete' => 'neutral',
        'Open' => 'red', 'In Progress' => 'orange', 'Contained' => 'blue', 'Resolved' => 'green',
    ][$s] ?? 'neutral';
}

/** Category -> accent colour: Physical = red, IT = blue, Strain = orange. */
function scope_tone(string $c): string
{
    if (strpos($c, 'Physical') === 0) return 'red';
    if (strpos($c, 'IT') === 0) return 'blue';
    if (strpos($c, 'Strain') === 0) return 'orange';
    return 'neutral';
}

function fmt_date(?string $d): string
{
    if (!$d) return '—';
    $ts = strtotime($d);
    return $ts ? date('d M Y', $ts) : '—';
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $stored = (string) ($_SESSION['csrf'] ?? '');
    $sent   = (string) ($_POST['csrf'] ?? '');

    // An empty token is never a match, even when the session has none yet.
    if ($stored === '' || $sent === '' || !hash_equals($stored, $sent)) {
        http_response_code(419);
        exit('Invalid or expired form token. Please go back and retry.');
    }
}

/** Trim and hard-limit a posted value to its column width. */
function clamp($value, int $max): string
{
    return mb_substr(trim((string) $value), 0, $max);
}

/** Accept only a real YYYY-MM-DD date, else return the fallback. */
function date_or($value, ?string $fallback = null): ?string
{
    $v = trim((string) $value);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return $v;
    }
    return $fallback;
}

/* ------------------------------------------------------------- one-shot */

/** Hidden one-time token: a resubmitted form cannot create a second record. */
function once_field(): string
{
    $id = bin2hex(random_bytes(9));
    $_SESSION['once'][$id] = time();
    if (count($_SESSION['once']) > 20) {
        $_SESSION['once'] = array_slice($_SESSION['once'], -20, null, true);
    }
    return '<input type="hidden" name="once" value="' . e($id) . '">';
}

function once_check(): bool
{
    $id = (string) ($_POST['once'] ?? '');
    if ($id === '' || !isset($_SESSION['once'][$id])) {
        return false;
    }
    unset($_SESSION['once'][$id]);
    return true;
}

/** CSP nonce for the inline scripts in header.php / footer.php. */
function csp_nonce(): string
{
    return (string) ($GLOBALS['csp_nonce'] ?? '');
}

function flash(string $msg): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash'] = $msg;
}

function take_flash(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

/* ---------------------------------------------------------------- tags */

/** Split a stored tag string into single tags. */
function tag_split(?string $tags): array
{
    if ($tags === null || trim($tags) === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/[,;]+/', $tags) ?: [] as $t) {
        $t = trim($t);
        if ($t !== '') {
            $out[] = $t;
        }
    }
    return $out;
}

/** Clean a comma-separated tag input: trimmed, de-duplicated, at most 6. */
function tag_normalise(string $raw): string
{
    $seen = [];
    foreach (preg_split('/[,;]+/', $raw) ?: [] as $t) {
        $t = mb_substr(trim((string) preg_replace('/\s+/', ' ', $t)), 0, 24);
        if ($t === '') {
            continue;
        }
        $key = mb_strtolower($t);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = $t;
        if (count($seen) >= 6) {
            break;
        }
    }
    return implode(', ', array_values($seen));
}

/* ------------------------------------------------------ short-lived caches */

/**
 * Per-session cache for page furniture that every request reads but that no
 * one needs to the second: the bell counts and the sidebar tag list.
 *
 * $key is a plain name; forget() also clears every "name/..." sibling, so one
 * call can drop all variants of an entry. Without an active session (CLI,
 * tests) the producer simply runs every time.
 *
 * @return mixed The producer's value
 */
function nav_cache(string $key, callable $producer, int $ttl = 15)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return $producer();
    }

    $slot = $_SESSION['nav_cache'][$key] ?? null;
    if (is_array($slot) && (int) ($slot['at'] ?? 0) > time() - max(1, $ttl)) {
        return $slot['v'];
    }

    $value = $producer();
    $_SESSION['nav_cache'][$key] = ['at' => time(), 'v' => $value];

    return $value;
}

/** Drop a cached entry (and any "$key/..." variants) after a write. */
function nav_cache_forget(string $key): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    unset($_SESSION['nav_cache'][$key]);
    foreach (array_keys($_SESSION['nav_cache']) as $slot) {
        if (strpos((string) $slot, $key . '/') === 0) {
            unset($_SESSION['nav_cache'][$slot]);
        }
    }
}

/** Every tag in use, for the picker and the register filter. */
function known_tags(PDO $pdo): array
{
    // Read once per request: the page header, the register filter and the issue
    // form all ask for it; a short session cache then spares the table scan on
    // the next few page loads. Writes call known_tags_forget().
    static $cache = [];
    $key = spl_object_id($pdo);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $tags = nav_cache('tags', static function () use ($pdo): array {
        $out = [];
        foreach ($pdo->query("SELECT tags FROM issues WHERE tags <> ''")->fetchAll(PDO::FETCH_COLUMN) as $raw) {
            foreach (tag_split((string) $raw) as $t) {
                $out[mb_strtolower($t)] = $t;
            }
        }
        ksort($out);

        return array_values($out);
    }, 30);

    return $cache[$key] = $tags;
}

/** Drop the cached tag list after an issue write that can change it. */
function known_tags_forget(): void
{
    nav_cache_forget('tags');
}

/** Tag chips linking to the filtered register. */
function tag_chips(?string $tags, string $active = ''): string
{
    $out = '';
    foreach (tag_split($tags) as $t) {
        $on = mb_strtolower($t) === mb_strtolower($active) ? ' is-on' : '';
        $out .= '<a class="tagchip' . $on . '" href="?page=issues&amp;tag=' . urlencode($t) . '">#' . e($t) . '</a>';
    }
    return $out;
}

/* ---------------------------------------------------------- exceptions */

/** All EDLP exception categories, ordered for display. */
function exception_categories(PDO $pdo): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = $pdo->query('SELECT * FROM exception_categories ORDER BY sort, name')->fetchAll();
    }
    return $cache;
}

function exception_category_names(PDO $pdo): array
{
    return array_map(fn($c) => (string) $c['name'], exception_categories($pdo));
}

function category_tone(PDO $pdo, string $name): string
{
    foreach (exception_categories($pdo) as $c) {
        if ((string) $c['name'] === $name) {
            return (string) $c['tone'];
        }
    }
    return 'neutral';
}

/** Days left in the period (negative when already past). */
function exception_days_left(?string $end): ?int
{
    if (!$end) {
        return null;
    }
    $ts = strtotime($end . ' 00:00:00');
    if (!$ts) {
        return null;
    }
    return (int) round(($ts - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400);
}

/** Display state of an exception: [label, tone]. */
function exception_state(array $e): array
{
    if (($e['status'] ?? '') === 'Revoked') {
        return ['Revoked', 'neutral'];
    }
    if (($e['status'] ?? '') === 'Pending') {
        return ['Pending approval', 'orange'];
    }
    $days = exception_days_left($e['period_end'] ?? null);
    if ($days !== null && $days < 0) {
        return ['Expired', 'red'];
    }
    if ($days !== null && $days <= 30) {
        return ['Expiring', 'orange'];
    }
    return ['Active', 'green'];
}

/** Period text: "01 Jul 2025 → 30 Jun 2026". */
function exception_period(array $e): string
{
    return fmt_date($e['period_start'] ?? null) . ' → ' . fmt_date($e['period_end'] ?? null);
}

/* ------------------------------------------------------------- client IP */

/**
 * Addressing for an intranet deployment.
 *
 * On a LAN the app may sit behind a reverse proxy (nginx, IIS ARR, an intranet
 * portal). PHP then sees the proxy's address in REMOTE_ADDR and the real client
 * — for example 10.120.10.10 — only in X-Forwarded-For. Those headers are
 * client-controlled, though, so they are read only when the direct peer is a
 * configured trusted proxy; otherwise a user could write any address they like
 * into the audit trail.
 *
 * Trusted proxies come from SECOPS_TRUSTED_PROXIES (comma-separated addresses
 * or CIDRs) or config.php. The default trusts loopback only: a proxy on another
 * host must be listed explicitly.
 */

function secops_trusted_proxies(): array
{
    $fromConfig = $GLOBALS['secops_config']['trusted_proxies'] ?? null;
    if (is_array($fromConfig)) {
        $raw = $fromConfig;
    } else {
        $env = getenv('SECOPS_TRUSTED_PROXIES');
        $raw = explode(',', $env === false || $env === '' ? '127.0.0.1/32,::1/128' : $env);
    }

    $out = [];
    foreach ($raw as $entry) {
        $entry = trim((string) $entry);
        if ($entry !== '') {
            $out[] = $entry;
        }
    }
    return $out;
}

/** Strip brackets, a zone id and an IPv4 port so an address can be compared. */
function normalize_ip(string $ip): string
{
    $ip = trim($ip);
    if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
        $ip = substr($ip, 1, -1);
    }
    $ip = preg_replace('/%[0-9a-z._-]+$/i', '', $ip) ?? $ip;
    // Some proxies report IPv4 clients in mapped form (::ffff:10.120.10.10).
    if (stripos($ip, '::ffff:') === 0
        && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $ip = substr($ip, 7);
    }
    if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m) === 1) {
        $ip = $m[1];
    }
    return $ip;
}

/** Is $ip inside $cidr (a single address is treated as /32 or /128)? */
function ip_matches_cidr(string $ip, string $cidr): bool
{
    $cidr = trim($cidr);
    if ($cidr === '') {
        return false;
    }
    if (strpos($cidr, '/') === false) {
        $cidr .= strpos($cidr, ':') === false ? '/32' : '/128';
    }
    [$network, $bitsRaw] = explode('/', $cidr, 2);
    $bits = (int) $bitsRaw;

    $ipBin  = @inet_pton(normalize_ip($ip));
    $netBin = @inet_pton(normalize_ip($network));
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }

    $max = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $max) {
        return false;
    }

    $whole = intdiv($bits, 8);
    if ($whole > 0 && strncmp($ipBin, $netBin, $whole) !== 0) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xff << (8 - $rest)) & 0xff;
    return (ord($ipBin[$whole]) & $mask) === (ord($netBin[$whole]) & $mask);
}

/** Is this address one of the proxies whose forwarding headers we accept? */
function is_trusted_proxy(string $ip): bool
{
    $ip = normalize_ip($ip);
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    foreach (secops_trusted_proxies() as $cidr) {
        if (ip_matches_cidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

/**
 * The client address to record for this request.
 *
 * X-Forwarded-For is read right to left: each trusted proxy is skipped and the
 * first address it did not write is the client. Falls back to REMOTE_ADDR.
 */
function client_ip(): string
{
    $peer = normalize_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($peer === '' || filter_var($peer, FILTER_VALIDATE_IP) === false) {
        return '';
    }
    if (!is_trusted_proxy($peer)) {
        return $peer;
    }

    $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($forwarded !== '') {
        $hops = array_reverse(explode(',', $forwarded));
        $candidate = '';
        foreach ($hops as $hop) {
            $hop = normalize_ip($hop);
            if ($hop === '' || filter_var($hop, FILTER_VALIDATE_IP) === false) {
                continue;               // junk in the list: ignore that hop
            }
            if (is_trusted_proxy($hop)) {
                $candidate = $hop;      // keep the last trusted hop as a fallback
                continue;
            }
            return $hop;                // first address the chain did not vouch for
        }
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $real = normalize_ip((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP) !== false) {
        return $real;
    }

    return $peer;
}

/** The direct peer address, before any forwarding header is considered. */
function peer_ip(): string
{
    $peer = normalize_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($peer, FILTER_VALIDATE_IP) !== false ? $peer : '';
}
