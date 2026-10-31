<?php
/**
 * Authentication, roles and authority (permission) checks.
 *
 * Roles: admin | manager | analyst | viewer
 * Every role gets a permission list; "admin" carries the wildcard "*".
 */

declare(strict_types=1);

/** Permission catalogue: key => human label (drives the authority matrix page). */
function permission_list(): array
{
    return [
        'page.dashboard' => 'Overview dashboard',
        'page.sop'       => 'SOP & policy library',
        'page.workflow'  => 'Workflow board',
        'page.issues'    => 'Issue register & detail reports',
        'page.templates' => 'Form template library',
        'page.users'     => 'Users & authority',
        'issue.create'   => 'Report a new issue',
        'issue.update'   => 'Edit issue fields, status and risk',
        'issue.note'     => 'Add notes to an issue',
        'issue.suspect'  => 'Add / remove suspect details',
        'issue.upload'   => 'Upload & remove evidence files',
        'issue.export'   => 'Download the issue report (.docx)',
        'task.create'    => 'Create tasks on the board',
        'task.move'      => 'Move cards between columns',
        'task.delete'    => 'Delete tasks',
        'template.edit'  => 'Create / edit / delete templates',
        'file.download'  => 'Download attached files',
        'user.manage'    => 'Manage users, roles and passwords',
    ];
}

/** Role catalogue: key => label, description, permission list. */
function role_list(): array
{
    return [
        'admin' => [
            'label' => 'Administrator',
            'hint'  => 'Full control, including users and authority',
            'perms' => ['*'],
        ],
        'manager' => [
            'label' => 'Security Manager',
            'hint'  => 'Runs the programme: every record plus template control',
            'perms' => [
                'page.dashboard', 'page.sop', 'page.workflow', 'page.issues', 'page.templates',
                'issue.create', 'issue.update', 'issue.note', 'issue.suspect', 'issue.upload', 'issue.export',
                'task.create', 'task.move', 'task.delete', 'template.edit', 'file.download',
            ],
        ],
        'analyst' => [
            'label' => 'Security Analyst',
            'hint'  => 'Investigations, evidence and day-to-day board work',
            'perms' => [
                'page.dashboard', 'page.sop', 'page.workflow', 'page.issues', 'page.templates',
                'issue.create', 'issue.update', 'issue.note', 'issue.suspect', 'issue.upload', 'issue.export',
                'task.create', 'task.move', 'file.download',
            ],
        ],
        'viewer' => [
            'label' => 'Auditor / Viewer',
            'hint'  => 'Read-only: review, download and export',
            'perms' => [
                'page.dashboard', 'page.sop', 'page.workflow', 'page.issues', 'page.templates',
                'file.download', 'issue.export',
            ],
        ],
    ];
}

function role_label(string $role): string
{
    return role_list()[$role]['label'] ?? $role;
}

function role_tone(string $role): string
{
    return ['admin' => 'red', 'manager' => 'blue', 'analyst' => 'orange', 'viewer' => 'neutral'][$role] ?? 'neutral';
}

/** Which permission a page requires. */
function page_permission(string $page): string
{
    static $map = [
        'dashboard'  => 'page.dashboard',
        'sop'        => 'page.sop',
        'workflow'   => 'page.workflow',
        'issues'     => 'page.issues',
        'issue'      => 'page.issues',
        'issue_doc'  => 'page.issues',
        'templates'  => 'page.templates',
        'template'   => 'page.templates',
        'doc'        => 'page.templates',
        'users'      => 'page.users',
    ];
    return $map[$page] ?? 'page.dashboard';
}

/** The signed-in user row, or null. Populated by auth_boot(). */
function current_user(): ?array
{
    global $CURRENT_USER;
    return $CURRENT_USER ?? null;
}

function current_user_name(): string
{
    $u = current_user();
    return $u['name'] ?? ($u['username'] ?? 'System');
}

function user_initials(?array $u): string
{
    if (!$u) return '–';
    $name = $u['name'] ?? $u['username'] ?? '';
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $init = '';
    foreach ($parts as $p) {
        if ($p !== '') $init .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return mb_substr($init, 0, 2) ?: '–';
}

/** Does the signed-in role hold $perm? Admin holds everything via "*". */
function can(string $perm): bool
{
    $u = current_user();
    if (!$u) return false;
    $role  = (string) ($u['role'] ?? '');
    $perms = role_list()[$role]['perms'] ?? [];
    if (in_array('*', $perms, true)) return true;
    return in_array($perm, $perms, true);
}

/** Load the session user from the database (also called before POST handling). */
function auth_boot(PDO $pdo): ?array
{
    global $CURRENT_USER;
    $CURRENT_USER = null;

    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id <= 0) return null;

    $st = $pdo->prepare('SELECT id, username, name, email, role, active, last_login FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();

    if (!$u || !(int) $u['active']) {
        unset($_SESSION['user_id']);
        return null;
    }
    $CURRENT_USER = $u;
    return $u;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/** Username/password check with lockout after repeated failures. */
function attempt_login(PDO $pdo, string $username, string $password): array
{
    $st = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([trim($username)]);
    $u = $st->fetch();

    if (!$u) {
        return ['ok' => false, 'error' => 'Username or password is incorrect.'];
    }
    if (!(int) $u['active']) {
        return ['ok' => false, 'error' => 'This account is deactivated. Ask an administrator to re-enable it.'];
    }
    if ($u['locked_until'] !== null && strtotime((string) $u['locked_until']) > time()) {
        $mins = (int) ceil((strtotime((string) $u['locked_until']) - time()) / 60);
        return ['ok' => false, 'error' => "Too many failed attempts. Locked for another {$mins} minute(s)."];
    }

    if (!password_verify($password, (string) $u['password_hash'])) {
        $fails = (int) $u['failed_attempts'] + 1;
        $lock  = $fails >= 5 ? date('Y-m-d H:i:s', time() + 900) : null; // 15 min after 5 tries
        $pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?')
            ->execute([$fails, $lock, (int) $u['id']]);
        return ['ok' => false, 'error' => 'Username or password is incorrect.'];
    }

    $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?')
        ->execute([(int) $u['id']]);

    session_regenerate_id(true); // prevent session fixation
    $_SESSION['user_id'] = (int) $u['id'];
    unset($_SESSION['csrf']); // fresh CSRF token per sign-in

    return ['ok' => true];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

/** Stop with 401 → the sign-in screen. */
function require_login(): void
{
    if (is_logged_in()) return;
    header('Location: ?page=login');
    exit;
}

/** Stop with 403 when the role lacks $perm. */
function require_permission(string $perm): void
{
    if (can($perm)) return;
    forbidden_page($perm);
}

function forbidden_page(string $perm): void
{
    http_response_code(403);
    $label = permission_list()[$perm] ?? $perm;
    $role  = role_label(current_user()['role'] ?? '');
    $back  = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '?page=dashboard';
    $back  = preg_replace('/[^a-zA-Z0-9_\-\?=&%.\/:]/', '', $back) ?: '?page=dashboard';
    $here  = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $r     = htmlspecialchars($role, ENT_QUOTES, 'UTF-8');
    $b     = htmlspecialchars($back, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>403 · SecOps CRM</title>'
        . '<body style="font:14px/1.5 system-ui,sans-serif;background:#f7f8fa;color:#14161a;display:grid;place-items:center;min-height:90vh;margin:0">'
        . '<div style="background:#fff;border:1px solid #e6e8ec;border-radius:16px;padding:32px 36px;max-width:520px;text-align:center">'
        . '<p style="font-size:12px;letter-spacing:.09em;text-transform:uppercase;color:#e5484d;font-weight:700;margin:0 0 8px">403 · Not authorised</p>'
        . '<h1 style="font-size:20px;margin:0 0 8px">Your role cannot do this</h1>'
        . '<p style="color:#4b5158;margin:0 0 6px">You are signed in as <b>' . $r . '</b>.</p>'
        . '<p style="color:#4b5158;margin:0 0 18px">The action <b>' . $here . '</b> is restricted.</p>'
        . '<a href="' . $b . '" style="background:#14161a;color:#fff;border-radius:999px;padding:8px 18px;font-weight:600;text-decoration:none">Go back</a>'
        . '</div></body>';
    exit;
}
