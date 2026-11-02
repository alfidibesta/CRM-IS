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

/** CSP nonce for the single inline script in footer.php. */
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

/** Every tag in use, for the picker and the register filter. */
function known_tags(PDO $pdo): array
{
    // Read once per request: the page header, the register filter and the issue
    // form all ask for it, and every write path redirects before rendering.
    static $cache = [];
    $key = spl_object_id($pdo);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $out = [];
    foreach ($pdo->query("SELECT tags FROM issues WHERE tags <> ''")->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        foreach (tag_split((string) $raw) as $t) {
            $out[mb_strtolower($t)] = $t;
        }
    }
    ksort($out);

    return $cache[$key] = array_values($out);
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
