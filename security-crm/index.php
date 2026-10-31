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

require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/uploads.php';
require __DIR__ . '/lib/templates.php';
require __DIR__ . '/lib/word.php';
require __DIR__ . '/lib/report.php';

$cfg = require __DIR__ . '/config.php';

// Start the session before any output so CSRF tokens and flash messages work.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
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

$pages = ['login', 'dashboard', 'sop', 'workflow', 'issues', 'issue', 'templates', 'template', 'users', 'download', 'doc', 'issue_doc'];

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
        'create_user'    => 'user.manage',
        'update_user'    => 'user.manage',
        'delete_user'    => 'user.manage',
    ];
    return $map[$action] ?? null;
}

/* ---------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    switch ($action) {
        case 'create_issue':
            $category = in_array($_POST['category'] ?? '', ['Physical Security', 'IT Security', 'Strain Security'], true) ? $_POST['category'] : 'IT Security';
            $severity = in_array($_POST['severity'] ?? '', ['Critical', 'High', 'Medium', 'Low'], true) ? $_POST['severity'] : 'Medium';
            $like = max(1, min(5, (int) ($_POST['likelihood'] ?? 3)));
            $imp  = max(1, min(5, (int) ($_POST['impact'] ?? 3)));
            $next = (int) $pdo->query('SELECT COALESCE(MAX(CAST(SUBSTRING(code,5) AS UNSIGNED)), 1000) + 1 FROM issues')->fetchColumn();

            $st = $pdo->prepare('INSERT INTO issues (code,title,category,severity,status,owner,location,department,asset,
                                 reported_by,reported_at,due_at,likelihood,impact,risk_score,root_cause,immediate_action,
                                 corrective_action,description)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([
                'SEC-' . $next,
                trim($_POST['title'] ?? ''),
                $category,
                $severity,
                'Open',
                trim($_POST['owner'] ?? ''),
                trim($_POST['location'] ?? ''),
                trim($_POST['department'] ?? ''),
                trim($_POST['asset'] ?? ''),
                trim($_POST['reported_by'] ?? '') !== '' ? trim($_POST['reported_by']) : $u,
                ($_POST['reported_at'] ?? '') !== '' ? $_POST['reported_at'] : date('Y-m-d'),
                ($_POST['due_at'] ?? '') !== '' ? $_POST['due_at'] : null,
                $like, $imp, $like * $imp,
                trim($_POST['root_cause'] ?? '') ?: null,
                trim($_POST['immediate_action'] ?? '') ?: null,
                trim($_POST['corrective_action'] ?? '') ?: null,
                trim($_POST['description'] ?? ''),
            ]);
            $id    = (int) $pdo->lastInsertId();
            $code  = 'SEC-' . $next;
            $pdo->prepare('INSERT INTO issue_events (issue_id, actor, kind, message) VALUES (?,?,?,?)')
                ->execute([$id, $u, 'created', "Reported {$code} as {$severity} severity."]);
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

            $pdo->prepare('UPDATE issues SET title=?, category=?, severity=?, status=?, owner=?, location=?, department=?,
                           asset=?, reported_by=?, reported_at=?, due_at=?, closed_at=?, likelihood=?, impact=?, risk_score=?,
                           root_cause=?, immediate_action=?, corrective_action=?, reviewed_by=?, approved_by=?, description=?
                           WHERE id=?')
                ->execute([
                    trim($_POST['title'] ?? $old['title']),
                    $category, $severity, $status,
                    trim($_POST['owner'] ?? ''), trim($_POST['location'] ?? ''),
                    trim($_POST['department'] ?? ''), trim($_POST['asset'] ?? ''),
                    trim($_POST['reported_by'] ?? ''),
                    ($_POST['reported_at'] ?? '') !== '' ? $_POST['reported_at'] : $old['reported_at'],
                    ($_POST['due_at'] ?? '') !== '' ? $_POST['due_at'] : $old['due_at'],
                    $closedAt, $like, $imp, $like * $imp,
                    trim($_POST['root_cause'] ?? '') ?: null,
                    trim($_POST['immediate_action'] ?? '') ?: null,
                    trim($_POST['corrective_action'] ?? '') ?: null,
                    trim($_POST['reviewed_by'] ?? ''), trim($_POST['approved_by'] ?? ''),
                    trim($_POST['description'] ?? ''),
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
            flash('Issue updated.');
            back_to('issue', $id);

        case 'issue_note':
            $id = (int) ($_POST['issue_id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($note !== '') {
                $st = $pdo->prepare('SELECT id FROM issues WHERE id = ?');
                $st->execute([$id]);
                if ($st->fetch()) {
                    $pdo->prepare('INSERT INTO issue_events (issue_id, actor, kind, message) VALUES (?,?,?,?)')
                        ->execute([$id, $u, 'note', mb_substr($note, 0, 400)]);
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
                        $id, $name,
                        trim($_POST['employee_no'] ?? ''), trim($_POST['department'] ?? ''),
                        trim($_POST['position'] ?? ''), trim($_POST['shift'] ?? ''),
                        $involvement, trim($_POST['contact'] ?? ''),
                        trim($_POST['statement'] ?? '') ?: null,
                        isset($_POST['interviewed']) ? 1 : 0,
                    ]);
                $pdo->prepare('INSERT INTO issue_events (issue_id, actor, kind, message) VALUES (?,?,?,?)')
                    ->execute([$id, $u, 'suspect', "Person added to the report: {$name} ({$involvement})."]);
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
                flash('File removed.');
            }
            back_to('issue', $id);

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
            flash('Task added to the board.');
            back_to($back, $backId);

        case 'move_task':
            header('Content-Type: application/json');
            $taskId   = (int) ($_POST['task_id'] ?? 0);
            $toColumn = (string) ($_POST['column'] ?? '');
            $toIndex  = (int) ($_POST['index'] ?? 0);

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

            echo json_encode(['ok' => true]);
            exit;

        case 'delete_task':
            $tid = (int) ($_POST['task_id'] ?? 0);
            $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$tid]);
            flash('Task deleted.');
            back_to($back, $backId);

        case 'create_template':
            $code = strtoupper(trim($_POST['code'] ?? ''));
            if ($code === '') {
                $code = 'TPL-' . str_pad((string) ((int) $pdo->query('SELECT COUNT(*) FROM templates')->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
            }
            $pdo->prepare('INSERT INTO templates (code,name,category,version,owner,description,body) VALUES (?,?,?,?,?,?,?)')
                ->execute([
                    $code,
                    trim($_POST['name'] ?? 'Untitled form'),
                    in_array($_POST['category'] ?? '', ['Legal', 'HR', 'Security', 'Audit', 'Operations'], true) ? $_POST['category'] : 'Legal',
                    trim($_POST['version'] ?? '1.0'),
                    trim($_POST['owner'] ?? ''),
                    trim($_POST['description'] ?? ''),
                    (string) ($_POST['body'] ?? ''),
                ]);
            $tid = (int) $pdo->lastInsertId();
            flash('Template created.');
            back_to('template', $tid);

        case 'update_template':
            $tid = (int) ($_POST['template_id'] ?? 0);
            $pdo->prepare('UPDATE templates SET name=?, category=?, version=?, owner=?, description=?, body=? WHERE id=?')
                ->execute([
                    trim($_POST['name'] ?? ''),
                    in_array($_POST['category'] ?? '', ['Legal', 'HR', 'Security', 'Audit', 'Operations'], true) ? $_POST['category'] : 'Legal',
                    trim($_POST['version'] ?? '1.0'),
                    trim($_POST['owner'] ?? ''),
                    trim($_POST['description'] ?? ''),
                    (string) ($_POST['body'] ?? ''),
                    $tid,
                ]);
            flash('Template saved.');
            back_to('template', $tid);

        case 'delete_template':
            $tid = (int) ($_POST['template_id'] ?? 0);
            $pdo->prepare('DELETE FROM templates WHERE id = ?')->execute([$tid]);
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
                    trim($_POST['name'] ?? '') ?: $username,
                    trim($_POST['email'] ?? ''),
                    $role,
                    password_hash($password, PASSWORD_DEFAULT),
                ]);
            flash("User {$username} created with the {$role} role.");
            back_to('users');

        case 'update_user':
            $uid = (int) ($_POST['user_id'] ?? 0);
            $me  = (int) (current_user()['id'] ?? 0);
            $role = in_array($_POST['role'] ?? '', array_keys(role_list()), true) ? $_POST['role'] : 'viewer';
            if ($uid === $me) {
                $role = current_user()['role']; // nobody demotes or locks themselves out
            }
            $active = isset($_POST['active']) ? 1 : 0;
            if ($uid === $me) {
                $active = 1;
            }

            $pdo->prepare('UPDATE users SET name=?, email=?, role=?, active=? WHERE id=?')
                ->execute([trim($_POST['name'] ?? ''), trim($_POST['email'] ?? ''), $role, $active, $uid]);

            $password = (string) ($_POST['password'] ?? '');
            if ($password !== '') {
                if (strlen($password) < 8) {
                    $_SESSION['form_error'] = 'Password must be at least 8 characters; it was left unchanged.';
                } else {
                    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
                    $pdo->prepare('UPDATE users SET failed_attempts=0, locked_until=NULL WHERE id=?')->execute([$uid]);
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
                flash("User {$victim['username']} deleted.");
            }
            back_to('users');
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

    $q = function (string $sql, array $args = []) use ($pdo): array {
        $s = $pdo->prepare($sql);
        $s->execute($args);
        return $s->fetchAll();
    };

    $ctx = [
        'suspects' => $q('SELECT * FROM suspects WHERE issue_id = ? ORDER BY FIELD(involvement,\'Primary\',\'Accomplice\',\'Witness\',\'Victim\',\'Unknown\'), id', [$issue['id']]),
        'files'    => $q('SELECT * FROM issue_files WHERE issue_id = ? ORDER BY id', [$issue['id']]),
        'tasks'    => $q('SELECT t.* FROM tasks t WHERE t.issue_id = ? ORDER BY FIELD(t.column_key,\'in_progress\',\'review\',\'planned\',\'backlog\',\'done\'), t.position', [$issue['id']]),
        'events'   => $q('SELECT * FROM issue_events WHERE issue_id = ? ORDER BY created_at, id', [$issue['id']]),
    ];

    docx_send($issue['code'] . '-issue-report.docx',
        docx_package(issue_report_blocks($issue, $ctx), [], [
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
    'users'     => ['Users & Authority', 'Accounts, roles and the permission matrix'],
];

require __DIR__ . '/views/header.php';
require __DIR__ . '/views/' . $page . '.php';
require __DIR__ . '/views/footer.php';
