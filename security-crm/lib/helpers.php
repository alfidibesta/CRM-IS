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
    $sent = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Invalid or expired form token. Please go back and retry.');
    }
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
