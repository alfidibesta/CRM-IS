<?php
/**
 * SecOps CRM — PHP native entry point / router.
 *
 *   ?page=login | dashboard | sop | workflow | issues | issue
 *              | templates | template | users | download | doc | issue_doc
 *
 * Every POST is an action guarded by CSRF and by a role permission.
 */

declare(strict_types=1);

/* Fail closed: log the error, show a neutral page, never a stack trace. */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    $line = '[' . date('c') . '] ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    @file_put_contents($logDir . '/app.log', $line, FILE_APPEND);
    error_log(rtrim($line));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>500 · SecOps CRM</title>'
        . '<body style="font:14px/1.5 system-ui,sans-serif;background:#f7f8fa;color:#14161a;padding:40px">'
        . '<h1 style="font-size:18px;margin:0 0 6px">That action could not be completed</h1>'
        . '<p style="color:#4b5158;margin:0 0 16px">The error was logged and nothing was half-saved. Try again.</p>'
        . '<a href="?page=dashboard" style="background:#14161a;color:#fff;border-radius:999px;padding:8px 16px;text-decoration:none">Back to the dashboard</a>'
        . '</body>';
});

require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/notify.php';
require __DIR__ . '/lib/uploads.php';
require __DIR__ . '/lib/templates.php';
require __DIR__ . '/lib/word.php';
require __DIR__ . '/lib/report.php';

$cfg = require __DIR__ . '/config.php';

/* ----------------------------------------------- session + security headers */

$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// Start the session before any output so CSRF tokens and flash messages work.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

// Per-response nonce for the one inline script (the CSRF token hand-off).
$cspNonce = base64_encode(random_bytes(16));
$GLOBALS['csp_nonce'] = $cspNonce;

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

// Framing is denied by default; a deployment that must be embedded (preview
// pane, intranet portal) declares its allowed parents in SECOPS_FRAME_ANCESTORS.
$frameAncestors = trim((string) (getenv('SECOPS_FRAME_ANCESTORS') ?: ''));
if ($frameAncestors === '') {
    $frameAncestors = "'none'";
    header('X-Frame-Options: DENY');
}
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
    . "script-src 'self' 'nonce-{$cspNonce}'; connect-src 'self'; form-action 'self'; base-uri 'none'; "
    . "frame-ancestors {$frameAncestors}");

if ($https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

try {
    $pdo = db($cfg);
} catch (Throwable $e) {
    http_response_code(500);
    exit('<h2 style="font-family:system-ui">Database connection failed</h2><p style="font-family:system-ui">'
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</p><p style="font-family:system-ui">Start MySQL in Laragon and check <code>config.php</code>.</p>');
}

auth_boot($pdo);
audit_boot($pdo);
notify_boot($pdo);

$pages = ['login', 'dashboard', 'sop', 'workflow', 'issues', 'issue', 'templates', 'template', 'exceptions', 'users', 'audit', 'notifications', 'download', 'doc', 'issue_doc'];

/** POST target: ?page=X ( + id ) — validated against the page whitelist. */
function back_to(string $back, int $id = 0): void
{
    global $pages;
    if (!in_array($back, $pages, true)) {
        $back = 'dashboard';
    }
    $url = '?page=' . $back;
    if ($id > 0) {
        $url .= '&id=' . $id;
    }
    header('Location: ' . $url);
    exit;
}

/** Which permission each action requires. */
function action_permission(string $action): ?string
{
    static $map = [
        'create_issue'   => 'issue.create',
        'update_issue'   => 'issue.update',
        'set_issue_status' => 'issue.update',
        'update_issue_report' => 'issue.update',
        'issue_note'     => 'issue.note',
        'add_suspect'    => 'issue.suspect',
        'delete_suspect' => 'issue.suspect',
        'upload_evidence'=> 'issue.upload',
        'delete_file'    => 'issue.upload',
        'create_task'    => 'task.create',
        'move_task'      => 'task.move',
        'delete_task'    => 'task.delete',
        'create_template'=> 'template.edit',
        'update_template'=> 'template.edit',
        'delete_template'=> 'template.edit',
        'create_exception'          => 'exception.manage',
        'update_exception'          => 'exception.manage',
        'delete_exception'          => 'exception.manage',
        'create_exception_category' => 'exception.category',
        'update_exception_category' => 'exception.category',
        'delete_exception_category' => 'exception.category',
        'notifications_read' => 'page.dashboard',
        'notification_read'  => 'page.dashboard',
        'create_user'    => 'user.manage',
        'update_user'    => 'user.manage',
        'delete_user'    => 'user.manage',
    ];
    return $map[$action] ?? null;
}

/* ---------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A body above post_max_size arrives with an empty $_POST: answer 413
    // instead of processing a half-read request.
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        http_response_code(413);
        exit('<!doctype html><meta charset="utf-8"><p style="font:14px system-ui;padding:32px">'
            . 'That upload is larger than the server accepts (limit '
            . htmlspecialchars(human_size(upload_limit_bytes()), ENT_QUOTES, 'UTF-8') . ').</p>');
    }

    $action = (string) ($_POST['action'] ?? '');

    // Sign-in is the only action allowed while logged out.
    if ($action === 'login') {
        csrf_check();
        $res = attempt_login($pdo, (string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        if (!$res['ok']) {
            $_SESSION['login_error'] = (string) $res['error'];
            $_SESSION['login_user']  = (string) ($_POST['username'] ?? '');
            back_to('login');
        }
        unset($_SESSION['login_error'], $_SESSION['login_user']);
        back_to('dashboard');
    }

    require_login();
    csrf_check();

    if ($action === 'logout') {
        logout_user();
        session_start();
        header('Location: ?page=login');
        exit;
    }

    $perm = action_permission($action);
    if ($perm === null) {
        http_response_code(400);
        exit('Unknown action.');
    }
    require_permission($perm);

    $back    = (string) ($_POST['back'] ?? 'dashboard');
    $backId  = (int) ($_POST['back_id'] ?? 0);
    $u       = current_user_name();

    // Creation forms carry a one-shot token, so a resubmitted page cannot
    // create the same record twice.
    if (in_array($action, ['create_issue', 'create_exception', 'create_task', 'create_template', 'create_user'], true)
        && !once_check()) {
        flash('That form was already submitted — nothing was created twice.');
        back_to($back, $backId);
    }

    switch ($action) {
        case 'create_issue':
            $category = in_array($_POST['category'] ?? '', ['Physical Security', 'IT Security', 'Strain Security'], true) ? $_POST['category'] : 'IT Security';
            $severity = in_array($_POST['severity'] ?? '', ['Critical', 'High', 'Medium', 'Low'], true) ? $_POST['severity'] : 'Medium';
            $like = max(1, min(5, (int) ($_POST['likelihood'] ?? 3)));
            $imp  = max(1, min(5, (int) ($_POST['impact'] ?? 3)));

            $title = clamp($_POST['title'] ?? '', 200);
            if ($title === '') {
                $_SESSION['form_error'] = 'A title is required for the report.';
                back_to('issues');
            }

            $next = (int) $pdo->query('SELECT COALESCE(MAX(CAST(SUBSTRING(code,5) AS UNSIGNED)), 1000) + 1 FROM issues')->fetchColumn();

            $reportTpl = (int) ($_POST['report_template_id'] ?? 0);
            if ($reportTpl > 0) {
                $chk = $pdo->prepare('SELECT id FROM templates WHERE id = ?');
                $chk->execute([$reportTpl]);
                $reportTpl = (int) ($chk->fetchColumn() ?: 0);
            }

            $st = $pdo->prepare('INSERT INTO issues (code,title,category,severity,status,owner,location,department,asset,
                                 reported_by,reported_at,due_at,likelihood,impact,risk_score,root_cause,immediate_action,
                                 corrective_action,description,tags,report_template_id)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([
                'SEC-' . $next,
                $title,
                $category,
                $severity,
                'Open',
                clamp($_POST['owner'] ?? '', 100),
                clamp($_POST['location'] ?? '', 120),
                clamp($_POST['department'] ?? '', 100),
                clamp($_POST['asset'] ?? '', 140),
                clamp($_POST['reported_by'] ?? '', 100) ?: $u,
                date_or($_POST['reported_at'] ?? '', date('Y-m-d')),
                date_or($_POST['due_at'] ?? '', null),
                $like, $imp, $like * $imp,
                clamp($_POST['root_cause'] ?? '', 5000) ?: null,
                clamp($_POST['immediate_action'] ?? '', 5000) ?: null,
                clamp($_POST['corrective_action'] ?? '', 5000) ?: null,
                clamp($_POST['description'] ?? '', 20000),
                tag_normalise((string) ($_POST['tags'] ?? '')),
                $reportTpl > 0 ? $reportTpl : null,
            ]);
            $id    = (int) $pdo->lastInsertId();
            $code  = 'SEC-' . $next;
            $pdo->prepare('INSERT INTO issue_events (issue_id, actor, kind, message) VALUES (?,?,?,?)')
                ->execute([$id, $u, 'created', "Reported {$code} as {$severity} severity."]);
            audit('issue.create', 'issue', $id, $code, $title . ' · ' . $category . ' · ' . $severity . ' · risk ' . ($like * $imp));
            notify_issue_assignment($pdo, (string) ($_POST['owner'] ?? ''), $code, $title, $id);
            if ($severity === 'Critical') {
                notify_issue_critical($code, $title, $id);
            }
            flash("Issue {$code} created.");
            back_to('issue', $id);

        case 'update_issue':
            $id = (int) ($_POST['issue_id'] ?? 0);
            $st = $pdo->prepare('SELECT * FROM issues WHERE id = ?');
            $st->execute([$id]);
            $old = $st->fetch();
            if (!$old) {
                back_to('issues');
            }

            $status = in_array($_POST['status'] ?? '', ['Open', 'In Progress', 'Contained', 'Resolved'], true) ? $_POST['status'] : $old['status'];
            $severity = in_array($_POST['severity'] ?? '', ['Critical', 'High', 'Medium', 'Low'], true) ? $_POST['severity'] : $old['severity'];
            $category = in_array($_POST['category'] ?? '', ['Physical Security', 'IT Security', 'Strain Security'], true) ? $_POST['category'] : $old['category'];
            $like = max(1, min(5, (int) ($_POST['likelihood'] ?? $old['likelihood'])));
            $imp  = max(1, min(5, (int) ($_POST['impact'] ?? $old['impact'])));
            $closedAt = $status === 'Resolved' ? ($old['closed_at'] ?: date('Y-m-d')) : null;

            if (trim((string) ($_POST['title'] ?? '')) === '') {
                $_SESSION['form_error'] = 'A title is required; nothing was saved.';
                back_to('issue', $id);
            }

            $pdo->prepare('UPDATE issues SET title=?, category=?, severity=?, status=?, owner=?, location=?, department=?,
                           asset=?, reported_by=?, reported_at=?, due_at=?, closed_at=?, likelihood=?, impact=?, risk_score=?,
                           root_cause=?, immediate_action=?, corrective_action=?, reviewed_by=?, approved_by=?, description=?,
                           tags=?
                           WHERE id=?')
                ->execute([
                    clamp($_POST['title'] ?? $old['title'], 200),
                    $category, $severity, $status,
                    clamp($_POST['owner'] ?? '', 100), clamp($_POST['location'] ?? '', 120),
                    clamp($_POST['department'] ?? '', 100), clamp($_POST['asset'] ?? '', 140),
                    clamp($_POST['reported_by'] ?? '', 100),
                    date_or($_POST['reported_at'] ?? '', $old['reported_at']),
                    date_or($_POST['due_at'] ?? '', $old['due_at']),
                    $closedAt, $like, $imp, $like * $imp,
                    clamp($_POST['root_cause'] ?? '', 5000) ?: null,
                    clamp($_POST['immediate_action'] ?? '', 5000) ?: null,
                    clamp($_POST['corrective_action'] ?? '', 5000) ?: null,
                    clamp($_POST['reviewed_by'] ?? '', 100), clamp($_POST['approved_by'] ?? '', 100),
                    clamp($_POST['description'] ?? '', 20000),
                    tag_normalise((string) ($_POST['tags'] ?? $old['tags'] ?? '')),
                    $id,
                ]);

            $log = $pdo->prepare('INSERT INTO issue_events (issue_id, actor, kind, message) VALUES (?,?,?,?)');
            if ($status !== $old['status']) {
                $log->execute([$id, $u, 'status', "Status changed from {$old['status']} to {$status}."]);
            }
            if ($severity !== $old['severity']) {
                $log->execute([$id, $u, 'field', "Severity changed from {$old['severity']} to {$severity}."]);
            }
            $newScore = $like * $imp;
            if ($newScore !== (int) $old['risk_score']) {
                $log->execute([$id, $u, 'field', "Risk score updated from {$old['risk_score']} to {$newScore}."]);
            }
            if ($status === $old['status'] && $severity === $old['severity'] && $newScore === (int) $old['risk_score']) {
                $log->execute([$id, $u, 'field', 'Issue details updated by ' . $u . '.']);
            }

            $changed = [];
            if ($severity !== $old['severity']) {
                $changed[] = "severity {$old['severity']} to {$severity}";
            }
            if ($newScore !== (int) $old['risk_score']) {
                $changed[] = "risk {$old['risk_score']} to {$newScore}";
            }
            $newOwner = clamp($_POST['owner'] ?? '', 100);
            if ($newOwner !== '' && $newOwner !== (string) $old['owner']) {
                notify_issue_assignment($pdo, $newOwner, (string) $old['code'], (string) $old['title'], $id);
            }
            if ($severity === 'Critical' && (string) $old['severity'] !== 'Critical') {
                notify_issue_critical((string) $old['code'], (string) $old['title'], $id);
            }
            audit('issue.update', 'issue', $id, (string) $old['code'], $changed ? ucfirst(implode('; ', $changed)) : 'Fields saved');
            if ($status !== $old['status']) {
                audit('issue.status', 'issue', $id, (string) $old['code'], "Status {$old['status']} to {$status}");
            }
            flash('Issue updated.');
            back_to('issue', $id);

        case 'set_issue_status':
            // Quick status change from the register (keeps the page small).
            $id     = (int) ($_POST['issue_id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', ['Open', 'In Progress', 'Contained', 'Resolved'], true)
                ? (string) $_POST['status'] : '';
            $st = $pdo->prepare('SELECT code, status, closed_at FROM issues WHERE id = ?');
            $st->execute([$id]);
            $old = $st->fetch();
            if (!$old || $status === '') {
                back_to('issues');
            }
            if ($old['status'] !== $status) {
                $closedAt = $status === 'Resolved' ? ($old['closed_at'] ?: date('Y-m-d')) : null;
                $pdo->prepare('UPDATE issues SET status = ?, closed_at = ? WHERE id = ?')
                    ->execute([$status, $closedAt, $id]);
                $pdo->prepare('INSERT INTO issue_events (issue_id, actor, kind, message) VALUES (?,?,?,?)')
                    ->execute([$id, $u, 'status', "Status changed from {$old['status']} to {$status}."]);
                audit('issue.status', 'issue', $id, (string) $old['code'], "Status {$old['status']} to {$status} from the register");
                flash("Status set to {$status}.");
            }
            back_to('issues');

        case 'update_issue_report':
            $id = (int) ($_POST['issue_id'] ?? 0);
            $st = $pdo->prepare('SELECT * FROM issues WHERE id = ?');
            $st->execute([$id]);
            $old = $st->fetch();
            if (!$old) {
                back_to('issues');
            }

            $tplId = (int) ($_POST['report_template_id'] ?? 0);
            $tplName = '';
            if ($tplId > 0) {
                $chk = $pdo->prepare('SELECT code, name FROM templates WHERE id = ?');
                $chk->execute([$tplId]);
                $tpl = $chk->fetch();
                if ($tpl) {
                    $tplName = $tpl['code'] . ' ' . $tpl['name'];
                } else {
                    $tplId = 0;
                }
            }

            $body = trim((string) ($_POST['report_body'] ?? ''));
            if (isset($_POST['reset_body'])) {
                $body = '';
            }
            // A different form re-seeds the layout from that form.
            if ($tplId !== (int) $old['report_template_id']) {
                $body = '';
            }
            $body = $body === '' ? null : mb_substr($body, 0, 20000);

            $tplChanged  = $tplId !== (int) $old['report_template_id'];
            $bodyChanged = (string) ($body ?? '') !== (string) ($old['report_body'] ?? '');

            $pdo->prepare('UPDATE issues SET report_template_id = ?, report_body = ? WHERE id = ?')
                ->execute([$tplId > 0 ? $tplId : null, $body, $id]);

            $log = $pdo->prepare('INSERT INTO issue_events (issue_id, actor, kind, message) VALUES (?,?,?,?)');
            if ($tplChanged) {
                $log->execute([$id, $u, 'field', $tplId > 0
                    ? "Report form set to {$tplName}."
                    : 'Report form template cleared — the built-in report layout is used.']);
            }
            if ($bodyChanged) {
                $log->execute([$id, $u, 'field', $body === null
                    ? 'Report layout reset to the selected form template.'
                    : 'Report layout adjusted for this issue.']);
            }

            $reportDetail = [];
            if ($tplChanged) {
                $reportDetail[] = $tplId > 0 ? 'form set to ' . $tplName : 'form template cleared';
            }
            if ($bodyChanged) {
                $reportDetail[] = $body === null ? 'layout reset to the form' : 'layout adjusted';
            }
            audit('issue.report', 'issue', $id, (string) $old['code'], $reportDetail ? ucfirst(implode('; ', $reportDetail)) : 'Report form saved');
            flash('Report form saved.');
            back_to('issue', $id);

        case 'issue_note':
            $id = (int) ($_POST['issue_id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($note !== '') {
                $st = $pdo->prepare('SELECT code FROM issues WHERE id = ?');
                $st->execute([$id]);
                $noteCode = (string) ($st->fetchColumn() ?: '');
                if ($noteCode !== '') {
                    $pdo->prepare('INSERT INTO issue_events (issue_id, actor, kind, message) VALUES (?,?,?,?)')
                        ->execute([$id, $u, 'note', mb_substr($note, 0, 400)]);
                    audit('issue.note', 'issue', $id, $noteCode, mb_substr($note, 0, 160));
                    flash('Note added to the activity log.');
                }
            }
            back_to('issue', $id);

        case 'add_suspect':
            $id = (int) ($_POST['issue_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($name !== '') {
                $involvement = in_array($_POST['involvement'] ?? '', ['Primary', 'Accomplice', 'Witness', 'Victim', 'Unknown'], true)
                    ? $_POST['involvement'] : 'Witness';
                $pdo->prepare('INSERT INTO suspects (issue_id,name,employee_no,department,position,shift,involvement,contact,statement,interviewed)
                               VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([
                        $id, clamp($name, 120),
                        clamp($_POST['employee_no'] ?? '', 40), clamp($_POST['department'] ?? '', 100),
                        clamp($_POST['position'] ?? '', 100), clamp($_POST['shift'] ?? '', 40),
                        $involvement, clamp($_POST['contact'] ?? '', 60),
                        clamp($_POST['statement'] ?? '', 5000) ?: null,
                        isset($_POST['interviewed']) ? 1 : 0,
                    ]);
                $pdo->prepare('INSERT INTO issue_events (issue_id, actor, kind, message) VALUES (?,?,?,?)')
                    ->execute([$id, $u, 'suspect', "Person added to the report: {$name} ({$involvement})."]);
                audit('issue.suspect.add', 'issue', $id, $name, 'Added as ' . $involvement);
                flash('Person added to the report.');
            }
            back_to('issue', $id);

        case 'delete_suspect':
            $id = (int) ($_POST['issue_id'] ?? 0);
            $sid = (int) ($_POST['suspect_id'] ?? 0);
            $st = $pdo->prepare('SELECT name FROM suspects WHERE id = ? AND issue_id = ?');
            $st->execute([$sid, $id]);
            $name = (string) $st->fetchColumn();
            if ($name !== '') {
                $pdo->prepare('DELETE FROM suspects WHERE id = ? AND issue_id = ?')->execute([$sid, $id]);
                $pdo->prepare('INSERT INTO issue_events (issue_id, actor, kind, message) VALUES (?,?,?,?)')
                    ->execute([$id, $u, 'suspect', "Person removed from the report: {$name}."]);
                audit('issue.suspect.remove', 'issue', $id, $name, 'Removed from the report');
                flash('Person removed.');
            }
            back_to('issue', $id);

        case 'upload_evidence':
            $id = (int) ($_POST['issue_id'] ?? 0);
            $res = store_upload($_FILES['file'] ?? []);
            if (!$res['ok']) {
                flash((string) $res['error']);
                back_to('issue', $id);
            }
            $kind = in_array($_POST['kind'] ?? '', upload_kinds(), true) ? $_POST['kind'] : 'Document';
            $pdo->prepare('INSERT INTO issue_files (issue_id,original_name,stored_name,ext,mime,size_bytes,kind,note,uploaded_by)
                           VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $id, $res['name'], $res['stored'], $res['ext'], $res['mime'],
                    $res['size'], $kind, trim($_POST['note'] ?? ''), $u,
                ]);
            audit('file.upload', 'file', (int) $pdo->lastInsertId(), (string) $res['name'],
                $kind . ' · ' . human_size((int) $res['size']) . ' · issue #' . $id);
            $pdo->prepare('INSERT INTO issue_events (issue_id, actor, kind, message) VALUES (?,?,?,?)')
                ->execute([$id, $u, 'file', "Evidence uploaded: {$res['name']} ({$kind})."]);
            flash("Evidence uploaded: {$res['name']}.");
            back_to('issue', $id);

        case 'delete_file':
            $id = (int) ($_POST['issue_id'] ?? 0);
            $fid = (int) ($_POST['file_id'] ?? 0);
            $st = $pdo->prepare('SELECT * FROM issue_files WHERE id = ? AND issue_id = ?');
            $st->execute([$fid, $id]);
            $row = $st->fetch();
            if ($row) {
                $path = stored_path((string) $row['stored_name']);
                if ($path !== '' && is_file($path)) {
                    @unlink($path);
                }
                $pdo->prepare('DELETE FROM issue_files WHERE id = ?')->execute([$fid]);
                $pdo->prepare('INSERT INTO issue_events (issue_id, actor, kind, message) VALUES (?,?,?,?)')
                    ->execute([$id, $u, 'file', "Evidence removed: {$row['original_name']}."]);
                audit('file.delete', 'file', $fid, (string) $row['original_name'], 'Removed from issue #' . $id);
                flash('File removed.');
            }
            back_to('issue', $id);

        case 'create_exception':
            $cats     = exception_category_names($pdo);
            $category = in_array($_POST['category'] ?? '', $cats, true) ? (string) $_POST['category'] : ($cats[0] ?? 'Other');
            $start    = date_or($_POST['period_start'] ?? '', date('Y-m-d'));
            $end      = date_or($_POST['period_end'] ?? '', null);
            if ($end !== null && $start !== null && $end < $start) {
                [$start, $end] = [$end, $start];
            }
            $excTitle = clamp($_POST['title'] ?? '', 200);
            if ($excTitle === '') {
                $_SESSION['form_error'] = 'An exception title is required.';
                back_to('exceptions');
            }
            $next = (int) $pdo->query('SELECT COALESCE(MAX(CAST(SUBSTRING(code,5) AS UNSIGNED)), 0) + 1 FROM exceptions')->fetchColumn();
            $code = 'EXC-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);

            $pdo->prepare('INSERT INTO exceptions
                    (code,title,system,category,target,detail,justification,control,period_start,period_end,
                     status,risk_level,owner,approved_by,ref_no)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $code,
                    $excTitle,
                    'EDLP',
                    $category,
                    clamp($_POST['target'] ?? '', 180),
                    clamp($_POST['detail'] ?? '', 5000) ?: null,
                    clamp($_POST['justification'] ?? '', 5000) ?: null,
                    clamp($_POST['control'] ?? '', 255),
                    $start, $end,
                    in_array($_POST['status'] ?? '', ['Active', 'Pending', 'Revoked'], true) ? $_POST['status'] : 'Active',
                    in_array($_POST['risk_level'] ?? '', ['High', 'Medium', 'Low'], true) ? $_POST['risk_level'] : 'Medium',
                    clamp($_POST['owner'] ?? '', 100),
                    clamp($_POST['approved_by'] ?? '', 100),
                    clamp($_POST['ref_no'] ?? '', 40),
                ]);
            audit('exception.create', 'exception', (int) $pdo->lastInsertId(), $code, $category . ' · ' . ($end ?? 'no end date'));
            flash("Exception {$code} added for {$category}.");
            back_to('exceptions');

        case 'update_exception':
            $eid = (int) ($_POST['exception_id'] ?? 0);
            $st = $pdo->prepare('SELECT * FROM exceptions WHERE id = ?');
            $st->execute([$eid]);
            $old = $st->fetch();
            if (!$old) {
                back_to('exceptions');
            }

            $cats     = exception_category_names($pdo);
            $category = in_array($_POST['category'] ?? '', $cats, true) ? (string) $_POST['category'] : (string) $old['category'];
            $start    = date_or($_POST['period_start'] ?? '', (string) $old['period_start']);
            $end      = date_or($_POST['period_end'] ?? '', null);
            if ($end !== null && $start !== null && $end < $start) {
                [$start, $end] = [$end, $start];
            }
            $excTitle = clamp($_POST['title'] ?? '', 200);
            if ($excTitle === '') {
                $_SESSION['form_error'] = 'An exception title is required; nothing was saved.';
                back_to('exceptions');
            }

            $pdo->prepare('UPDATE exceptions SET title=?, category=?, target=?, detail=?, justification=?, control=?,
                           period_start=?, period_end=?, status=?, risk_level=?, owner=?, approved_by=?, ref_no=?
                           WHERE id=?')
                ->execute([
                    $excTitle,
                    $category,
                    clamp($_POST['target'] ?? '', 180),
                    clamp($_POST['detail'] ?? '', 5000) ?: null,
                    clamp($_POST['justification'] ?? '', 5000) ?: null,
                    clamp($_POST['control'] ?? '', 255),
                    $start, $end,
                    in_array($_POST['status'] ?? '', ['Active', 'Pending', 'Revoked'], true) ? $_POST['status'] : $old['status'],
                    in_array($_POST['risk_level'] ?? '', ['High', 'Medium', 'Low'], true) ? $_POST['risk_level'] : $old['risk_level'],
                    clamp($_POST['owner'] ?? '', 100),
                    clamp($_POST['approved_by'] ?? '', 100),
                    clamp($_POST['ref_no'] ?? '', 40),
                    $eid,
                ]);

            audit('exception.update', 'exception', $eid, (string) $old['code'], 'Period ' . $start . ' to ' . ($end ?? '—') . ' · ' . $category);
            flash("Exception {$old['code']} updated.");
            back_to('exceptions');

        case 'delete_exception':
            $eid = (int) ($_POST['exception_id'] ?? 0);
            $st = $pdo->prepare('SELECT code FROM exceptions WHERE id = ?');
            $st->execute([$eid]);
            $code = (string) $st->fetchColumn();
            if ($code !== '') {
                $pdo->prepare('DELETE FROM exceptions WHERE id = ?')->execute([$eid]);
                audit('exception.delete', 'exception', $eid, $code, 'Removed from the register');
                flash("Exception {$code} deleted.");
            }
            back_to('exceptions');

        case 'create_exception_category':
            $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 60));
            if ($name === '') {
                $_SESSION['form_error'] = 'Give the category a name.';
                back_to('exceptions');
            }
            $dupe = $pdo->prepare('SELECT id FROM exception_categories WHERE name = ?');
            $dupe->execute([$name]);
            if ($dupe->fetch()) {
                $_SESSION['form_error'] = 'That category already exists.';
                back_to('exceptions');
            }
            $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM exception_categories')->fetchColumn();
            $pdo->prepare('INSERT INTO exception_categories (name, tone, sort) VALUES (?,?,?)')
                ->execute([
                    $name,
                    in_array($_POST['tone'] ?? '', ['red', 'blue', 'orange', 'green', 'neutral'], true) ? $_POST['tone'] : 'neutral',
                    $sort,
                ]);
            audit('exception.category.create', 'exception_category', (int) $pdo->lastInsertId(), $name, 'Added to the register');
            flash("Category {$name} added.");
            back_to('exceptions');

        case 'update_exception_category':
            $cid = (int) ($_POST['category_id'] ?? 0);
            $st = $pdo->prepare('SELECT * FROM exception_categories WHERE id = ?');
            $st->execute([$cid]);
            $cat = $st->fetch();
            if (!$cat) {
                back_to('exceptions');
            }
            $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 60)) ?: (string) $cat['name'];
            $tone = in_array($_POST['tone'] ?? '', ['red', 'blue', 'orange', 'green', 'neutral'], true) ? $_POST['tone'] : (string) $cat['tone'];
            $sort = (int) ($_POST['sort'] ?? $cat['sort']);

            $dupe = $pdo->prepare('SELECT id FROM exception_categories WHERE name = ? AND id <> ?');
            $dupe->execute([$name, $cid]);
            if ($dupe->fetch()) {
                $_SESSION['form_error'] = 'Another category already uses that name.';
                back_to('exceptions');
            }

            $pdo->beginTransaction();
            $pdo->prepare('UPDATE exception_categories SET name = ?, tone = ?, sort = ? WHERE id = ?')
                ->execute([$name, $tone, $sort, $cid]);
            if ($name !== (string) $cat['name']) {
                // Renaming a category carries its exceptions with it.
                $pdo->prepare('UPDATE exceptions SET category = ? WHERE category = ?')
                    ->execute([$name, $cat['name']]);
            }
            $pdo->commit();
            audit('exception.category.update', 'exception_category', $cid, $name,
                $name !== (string) $cat['name'] ? 'Renamed from ' . $cat['name'] : 'Tone or order saved');
            flash("Category {$name} saved.");
            back_to('exceptions');

        case 'delete_exception_category':
            $cid = (int) ($_POST['category_id'] ?? 0);
            $st = $pdo->prepare('SELECT name FROM exception_categories WHERE id = ?');
            $st->execute([$cid]);
            $name = (string) $st->fetchColumn();
            if ($name === '') {
                back_to('exceptions');
            }
            $used = $pdo->prepare('SELECT COUNT(*) FROM exceptions WHERE category = ?');
            $used->execute([$name]);
            $count = (int) $used->fetchColumn();
            if ($count > 0) {
                $_SESSION['form_error'] = "Category \"{$name}\" is used by {$count} exception(s). Re-assign them before deleting it.";
            } elseif ((int) $pdo->query('SELECT COUNT(*) FROM exception_categories')->fetchColumn() <= 1) {
                $_SESSION['form_error'] = 'Keep at least one category.';
            } else {
                $pdo->prepare('DELETE FROM exception_categories WHERE id = ?')->execute([$cid]);
                audit('exception.category.delete', 'exception_category', $cid, $name, 'No exceptions used it');
                flash("Category {$name} deleted.");
            }
            back_to('exceptions');

        case 'create_task':
            $col = in_array($_POST['column'] ?? '', ['backlog', 'planned', 'in_progress', 'review', 'done'], true) ? $_POST['column'] : 'backlog';
            $cat = in_array($_POST['category'] ?? '', ['Physical', 'IT', 'Strain', 'General'], true) ? $_POST['category'] : 'General';
            $max = $pdo->prepare('SELECT COALESCE(MAX(position), -1) FROM tasks WHERE column_key = ?');
            $max->execute([$col]);
            $pdo->prepare('INSERT INTO tasks (title,column_key,priority,owner,category,due_at,issue_id,position) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([
                    trim($_POST['title'] ?? ''),
                    $col,
                    in_array($_POST['priority'] ?? '', ['Critical', 'High', 'Medium', 'Low'], true) ? $_POST['priority'] : 'Medium',
                    trim($_POST['owner'] ?? ''),
                    $cat,
                    ($_POST['due_at'] ?? '') !== '' ? $_POST['due_at'] : null,
                    (int) ($_POST['issue_id'] ?? 0) ?: null,
                    (int) $max->fetchColumn() + 1,
                ]);
            audit('task.create', 'task', (int) $pdo->lastInsertId(), clamp($_POST['title'] ?? '', 200),
                'Added to ' . str_replace('_', ' ', $col));
            flash('Task added to the board.');
            back_to($back, $backId);

        case 'move_task':
            header('Content-Type: application/json');
            $taskId   = (int) ($_POST['task_id'] ?? 0);
            $toColumn = (string) ($_POST['column'] ?? '');
            $toIndex  = (int) ($_POST['index'] ?? 0);

            $tst = $pdo->prepare('SELECT title, column_key FROM tasks WHERE id = ?');
            $tst->execute([$taskId]);
            $taskRow = $tst->fetch() ?: ['title' => '', 'column_key' => ''];

            if (!in_array($toColumn, ['backlog', 'planned', 'in_progress', 'review', 'done'], true)) {
                echo json_encode(['ok' => false, 'error' => 'bad column']);
                exit;
            }

            $pdo->beginTransaction();
            $ids = $pdo->prepare('SELECT id FROM tasks WHERE column_key = ? ORDER BY position, id');
            $ids->execute([$toColumn]);
            $order = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
            $order = array_values(array_diff($order, [$taskId]));
            $toIndex = max(0, min($toIndex, count($order)));
            array_splice($order, $toIndex, 0, [$taskId]);

            $pdo->prepare('UPDATE tasks SET column_key = ? WHERE id = ?')->execute([$toColumn, $taskId]);
            $pos = $pdo->prepare('UPDATE tasks SET position = ? WHERE id = ?');
            foreach ($order as $i => $id) {
                $pos->execute([$i, $id]);
            }
            $pdo->commit();

            audit('task.move', 'task', $taskId, (string) $taskRow['title'],
                ($taskRow['column_key'] !== '' ? $taskRow['column_key'] . ' to ' : '') . $toColumn);

            echo json_encode(['ok' => true]);
            exit;

        case 'delete_task':
            $tid = (int) ($_POST['task_id'] ?? 0);
            $tst = $pdo->prepare('SELECT title FROM tasks WHERE id = ?');
            $tst->execute([$tid]);
            $taskTitle = (string) ($tst->fetchColumn() ?: '');
            $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$tid]);
            audit('task.delete', 'task', $tid, $taskTitle, 'Removed from the board');
            flash('Task deleted.');
            back_to($back, $backId);

        case 'create_template':
            $code = clamp(strtoupper($_POST['code'] ?? ''), 20);
            if ($code === '') {
                $code = 'TPL-' . str_pad((string) ((int) $pdo->query('SELECT COUNT(*) FROM templates')->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
            }
            if (clamp($_POST['name'] ?? '', 160) === '') {
                $_SESSION['form_error'] = 'A template name is required.';
                back_to('templates');
            }
            $pdo->prepare('INSERT INTO templates (code,name,category,version,owner,description,body) VALUES (?,?,?,?,?,?,?)')
                ->execute([
                    $code,
                    clamp($_POST['name'] ?? 'Untitled form', 160),
                    in_array($_POST['category'] ?? '', ['Legal', 'HR', 'Security', 'Audit', 'Operations'], true) ? $_POST['category'] : 'Legal',
                    clamp($_POST['version'] ?? '1.0', 10),
                    clamp($_POST['owner'] ?? '', 100),
                    clamp($_POST['description'] ?? '', 400),
                    mb_substr((string) ($_POST['body'] ?? ''), 0, 60000),
                ]);
            $tid = (int) $pdo->lastInsertId();
            audit('template.create', 'template', $tid, $code, clamp($_POST['name'] ?? 'Untitled form', 160));
            flash('Template created.');
            back_to('template', $tid);

        case 'update_template':
            $tid = (int) ($_POST['template_id'] ?? 0);
            $tst = $pdo->prepare('SELECT code, name FROM templates WHERE id = ?');
            $tst->execute([$tid]);
            $tplOld = $tst->fetch() ?: ['code' => '', 'name' => ''];
            $pdo->prepare('UPDATE templates SET name=?, category=?, version=?, owner=?, description=?, body=? WHERE id=?')
                ->execute([
                    clamp($_POST['name'] ?? '', 160) ?: 'Untitled form',
                    in_array($_POST['category'] ?? '', ['Legal', 'HR', 'Security', 'Audit', 'Operations'], true) ? $_POST['category'] : 'Legal',
                    clamp($_POST['version'] ?? '1.0', 10),
                    clamp($_POST['owner'] ?? '', 100),
                    clamp($_POST['description'] ?? '', 400),
                    mb_substr((string) ($_POST['body'] ?? ''), 0, 60000),
                    $tid,
                ]);
            audit('template.update', 'template', $tid, (string) $tplOld['code'], (string) $tplOld['name']);
            flash('Template saved.');
            back_to('template', $tid);

        case 'delete_template':
            $tid = (int) ($_POST['template_id'] ?? 0);
            $tst = $pdo->prepare('SELECT code, name FROM templates WHERE id = ?');
            $tst->execute([$tid]);
            $tplGone = $tst->fetch() ?: ['code' => '', 'name' => ''];            
            $pdo->prepare('DELETE FROM templates WHERE id = ?')->execute([$tid]);
            audit('template.delete', 'template', $tid, (string) $tplGone['code'], (string) $tplGone['name']);
            flash('Template deleted.');
            back_to('templates');

        case 'create_user':
            $username = strtolower(preg_replace('/[^a-z0-9._-]/i', '', (string) ($_POST['username'] ?? '')) ?? '');
            $role = in_array($_POST['role'] ?? '', array_keys(role_list()), true) ? $_POST['role'] : 'viewer';
            $password = (string) ($_POST['password'] ?? '');

            $err = null;
            if (strlen($username) < 3) $err = 'Username must be at least 3 characters.';
            elseif (strlen($password) < 8) $err = 'Password must be at least 8 characters.';

            if ($err === null) {
                $st = $pdo->prepare('SELECT id FROM users WHERE username = ?');
                $st->execute([$username]);
                if ($st->fetch()) {
                    $err = 'That username is already taken.';
                }
            }
            if ($err !== null) {
                $_SESSION['form_error'] = $err;
                back_to('users');
            }

            $pdo->prepare('INSERT INTO users (username,name,email,role,password_hash,active) VALUES (?,?,?,?,?,1)')
                ->execute([
                    $username,
                    clamp($_POST['name'] ?? '', 100) ?: $username,
                    clamp($_POST['email'] ?? '', 120),
                    $role,
                    password_hash($password, PASSWORD_DEFAULT),
                ]);
            audit('user.create', 'user', (int) $pdo->lastInsertId(), $username, 'Role ' . $role);
            flash("User {$username} created with the {$role} role.");
            back_to('users');

        case 'update_user':
            $uid = (int) ($_POST['user_id'] ?? 0);
            $me  = (int) (current_user()['id'] ?? 0);
            $ust = $pdo->prepare('SELECT username, role, active FROM users WHERE id = ?');
            $ust->execute([$uid]);
            $before = $ust->fetch() ?: ['username' => '', 'role' => '', 'active' => 0];
            $role = in_array($_POST['role'] ?? '', array_keys(role_list()), true) ? $_POST['role'] : 'viewer';
            if ($uid === $me) {
                $role = current_user()['role']; // nobody demotes or locks themselves out
            }
            $active = isset($_POST['active']) ? 1 : 0;
            if ($uid === $me) {
                $active = 1;
            }

            $pdo->prepare('UPDATE users SET name=?, email=?, role=?, active=? WHERE id=?')
                ->execute([clamp($_POST['name'] ?? '', 100), clamp($_POST['email'] ?? '', 120), $role, $active, $uid]);

            $acct = [];
            if ((string) $before['role'] !== '' && (string) $before['role'] !== $role) {
                $acct[] = "role {$before['role']} to {$role}";
            }
            if ((int) $before['active'] !== $active) {
                $acct[] = $active ? 'account enabled' : 'account disabled';
            }
            audit('user.update', 'user', $uid, (string) ($before['username'] ?: ('#' . $uid)),
                $acct ? ucfirst(implode('; ', $acct)) : 'Profile saved');

            $password = (string) ($_POST['password'] ?? '');
            if ($password !== '') {
                if (strlen($password) < 8) {
                    $_SESSION['form_error'] = 'Password must be at least 8 characters; it was left unchanged.';
                } else {
                    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
                    $pdo->prepare('UPDATE users SET failed_attempts=0, locked_until=NULL WHERE id=?')->execute([$uid]);
                    audit('user.update', 'user', $uid, (string) ($before['username'] ?: ('#' . $uid)), 'Password reset by an administrator');
                    flash('User updated and password reset.');
                }
            } else {
                flash('User updated.');
            }
            back_to('users');

        case 'delete_user':
            $uid = (int) ($_POST['user_id'] ?? 0);
            $me  = (int) (current_user()['id'] ?? 0);
            $st = $pdo->prepare('SELECT username, role FROM users WHERE id = ?');
            $st->execute([$uid]);
            $victim = $st->fetch();

            if ($uid === $me) {
                $_SESSION['form_error'] = 'You cannot delete your own account.';
            } elseif (!$victim) {
                $_SESSION['form_error'] = 'User not found.';
            } elseif ($victim['role'] === 'admin'
                && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn() <= 1) {
                $_SESSION['form_error'] = 'The last active administrator cannot be deleted.';
            } else {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                audit('user.delete', 'user', $uid, (string) $victim['username'], 'Role ' . $victim['role']);
                flash("User {$victim['username']} deleted.");
            }
            back_to('users');

        case 'notifications_read':
            $marked = notify_mark_all($pdo, (int) (current_user()['id'] ?? 0));
            flash($marked > 0
                ? $marked . ' notification(s) marked as read.'
                : 'Nothing was unread — you are all caught up.');
            back_to(in_array($back, ['notifications', 'dashboard'], true) ? $back : 'notifications');

        case 'notification_read':
            notify_mark_one($pdo, (int) (current_user()['id'] ?? 0), (int) ($_POST['id'] ?? 0));
            back_to(in_array($back, ['notifications', 'dashboard'], true) ? $back : 'notifications');
    }

    back_to($back, $backId);
}

/* ------------------------------------------------------------------ pages */

$page = (string) ($_GET['page'] ?? 'dashboard');
if (!in_array($page, $pages, true)) {
    $page = 'dashboard';
}

// Sign-in screen: its own chrome, no sidebar, no permission checks.
if ($page === 'login') {
    if (is_logged_in()) {
        header('Location: ?page=dashboard');
        exit;
    }
    require __DIR__ . '/views/login.php';
    exit;
}

require_login();
require_permission(page_permission($page));

// Time-based alerts (due issues, expiring exceptions, document reviews) are
// worked out on page load. The sweep is deduped per day, so no cron is needed
// and a POST never pays for it.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    notify_sweep($pdo);
}

/* ------------------------------------------------------------- exports */

if ($page === 'audit' && ($_GET['export'] ?? '') === 'csv') {
    $f    = audit_filters($_GET);
    $rows = audit_search($pdo, $f, 1, 5000)['rows'];

    audit('audit.export', 'session', null, 'Audit trail', count($rows) . ' entries exported as CSV');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-trail-' . date('Ymd-Hi') . '.csv"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['When', 'Actor', 'Role', 'Action', 'Record type', 'Record ID', 'Reference', 'Detail', 'IP', 'Client']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['created_at'],
            $r['actor'],
            $r['role'],
            audit_action_label((string) $r['action']),
            audit_entity_label((string) $r['entity']),
            $r['entity_id'],
            $r['label'],
            $r['detail'],
            $r['ip'],
            $r['agent'],
        ]);
    }
    fclose($out);
    exit;
}

/* ------------------------------------------------------------ downloads */

if ($page === 'download') {
    require_permission('file.download');
    $st = $pdo->prepare('SELECT * FROM issue_files WHERE id = ?');
    $st->execute([(int) ($_GET['file'] ?? 0)]);
    $row = $st->fetch();
    $path = $row ? stored_path((string) $row['stored_name']) : '';

    if (!$row || $path === '' || !is_file($path)) {
        http_response_code(404);
        exit('File not found.');
    }

    $types = [
        'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'txt' => 'text/plain; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8', 'log' => 'text/plain; charset=utf-8',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip',
    ];
    $dl = $row['ext'] !== '' && ($row['ext'] === 'png' || $row['ext'] === 'jpg' || $row['ext'] === 'jpeg' || $row['ext'] === 'gif' || $row['ext'] === 'webp') && isset($_GET['inline']);

    audit('file.download', 'file', (int) $row['id'], (string) $row['original_name'],
        'Evidence · ' . human_size((int) $row['size_bytes']));

    header('Content-Type: ' . ($types[$row['ext']] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($dl ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', (string) $row['original_name']) . '"');
    readfile($path);
    exit;
}

if ($page === 'doc') {
    $st = $pdo->prepare('SELECT * FROM templates WHERE id = ?');
    $st->execute([(int) ($_GET['id'] ?? 0)]);
    $tpl = $st->fetch();
    if (!$tpl) {
        http_response_code(404);
        exit('Template not found.');
    }

    $fill = [];
    foreach (($_GET['fill'] ?? []) as $k => $v) {
        $key = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string) $k) ?? '');
        if ($key !== '') {
            $fill[$key] = mb_substr((string) $v, 0, 200);
        }
    }

    $ref = trim((string) ($_GET['ref'] ?? ''));
    $blocks = template_document_blocks($tpl, $ref);
    $bytes = docx_package($blocks, $fill, ['title' => $tpl['name'], 'creator' => 'SecOps CRM · ' . current_user_name()]);

    $pdo->prepare('UPDATE templates SET downloads = downloads + 1 WHERE id = ?')->execute([(int) $tpl['id']]);
    audit('template.download', 'template', (int) $tpl['id'], (string) $tpl['code'], 'Downloaded ' . $tpl['name']);
    docx_send($tpl['code'] . '-' . $tpl['name'] . '.docx', $bytes);
}

if ($page === 'issue_doc') {
    require_permission('issue.export');
    $st = $pdo->prepare('SELECT * FROM issues WHERE id = ?');
    $st->execute([(int) ($_GET['id'] ?? 0)]);
    $issue = $st->fetch();
    if (!$issue) {
        http_response_code(404);
        exit('Issue not found.');
    }

    // Same loader as the detail page (lib/report.php), so the .docx and the
    // screen can never disagree about which rows belong to the issue.
    $ctx = issue_report_context($pdo, (int) $issue['id']);

    $form = issue_report_template($pdo, $issue);
    $fill = issue_report_fill($issue, $ctx);

    audit('issue.export', 'issue', (int) $issue['id'], (string) $issue['code'], 'Report exported as .docx');

    docx_send($issue['code'] . '-issue-report.docx',
        docx_package(issue_report_blocks($issue, $ctx, $form), $fill, [
            'title'   => $issue['code'] . ' — Information Security Issue Report',
            'creator' => 'SecOps CRM · ' . current_user_name(),
        ]));
}

/* ----------------------------------------------------------------- views */

$titles = [
    'dashboard' => ['Security Overview', 'Information security posture across the plant'],
    'sop'       => ['SOP & Policy Library', 'Controlled documents, versions and review dates'],
    'workflow'  => ['Workflow Board', 'Security work in flight, from backlog to done'],
    'issues'    => ['Security Issues', 'Physical, IT and Strain security findings'],
    'issue'     => ['Issue Detail Report', 'Full investigation record, evidence and sign-off'],
    'templates' => ['Form Templates', 'NDA, agreements and controlled forms — download as Word'],
    'template'  => ['Template Detail', 'Preview, fill in and download as .docx'],
    'exceptions' => ['EDLP Exceptions', 'Approved deviations for USB, websites, cameras and more'],
    'users'     => ['Users & Authority', 'Accounts, roles and the permission matrix'],
    'audit'     => ['Audit Trail', 'Every recorded change, who made it and from where'],
    'notifications' => ['Notifications', 'Alerts for your issues, exceptions and document reviews'],
];

// Resolve the record before any output: a missing id must answer 404, not 200.
if ($page === 'issue' || $page === 'template') {
    $table = $page === 'issue' ? 'issues' : 'templates';
    $exists = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = ?");
    $exists->execute([(int) ($_GET['id'] ?? 0)]);
    if (!$exists->fetchColumn()) {
        http_response_code(404);
        $titles[$page] = ['Not found', 'That record does not exist any more'];
    }
}

require __DIR__ . '/views/header.php';
require __DIR__ . '/views/' . $page . '.php';
require __DIR__ . '/views/footer.php';
