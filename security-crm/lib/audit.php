<?php
declare(strict_types=1);

/**
 * Append-only audit trail.
 *
 * Every event that matters — sign-in, sign-out, a refused action and each
 * create/update/delete of a record — is written to `audit_log` with the actor,
 * the role they held at the time, the record touched, the address and the
 * client. The table is only ever inserted into and read: nothing updates or
 * deletes a row, so the trail stays a record of what happened.
 *
 * audit_boot() receives the PDO handle once in index.php; audit() then works
 * from anywhere, including lib/auth.php, which has no $pdo of its own.
 */

function audit_boot(PDO $pdo): void
{
    $GLOBALS['audit_pdo'] = $pdo;
}

/** Action catalogue: key => [label, tone]. Also drives the filter menu. */
function audit_actions(): array
{
    return [
        'auth.login'   => ['Sign-in', 'green'],
        'auth.logout'  => ['Sign-out', 'neutral'],
        'auth.failed'  => ['Failed sign-in', 'red'],
        'auth.locked'  => ['Account locked', 'red'],
        'auth.blocked' => ['Address throttled', 'red'],
        'access.denied' => ['Permission denied', 'red'],
        'issue.create' => ['Issue created', 'red'],
        'issue.update' => ['Issue updated', 'blue'],
        'issue.status' => ['Status changed', 'blue'],
        'issue.report' => ['Report form', 'blue'],
        'issue.note'   => ['Note added', 'neutral'],
        'issue.suspect.add'    => ['Person added', 'orange'],
        'issue.suspect.remove' => ['Person removed', 'orange'],
        'issue.export' => ['Report exported', 'blue'],
        'file.upload'  => ['Evidence uploaded', 'orange'],
        'file.delete'  => ['Evidence removed', 'red'],
        'file.download' => ['Evidence downloaded', 'neutral'],
        'task.create'  => ['Task created', 'blue'],
        'task.move'    => ['Card moved', 'neutral'],
        'task.delete'  => ['Task deleted', 'red'],
        'template.create' => ['Template created', 'blue'],
        'template.update' => ['Template saved', 'blue'],
        'template.delete' => ['Template deleted', 'red'],
        'template.download' => ['Template downloaded', 'neutral'],
        'exception.create' => ['Exception added', 'orange'],
        'exception.update' => ['Exception updated', 'orange'],
        'exception.delete' => ['Exception deleted', 'red'],
        'exception.category.create' => ['Category added', 'neutral'],
        'exception.category.update' => ['Category saved', 'neutral'],
        'exception.category.delete' => ['Category deleted', 'neutral'],
        'user.create'  => ['User created', 'red'],
        'user.update'  => ['User updated', 'red'],
        'user.delete'  => ['User deleted', 'red'],
        'audit.export' => ['Trail exported', 'orange'],
    ];
}

function audit_action_label(string $action): string
{
    return audit_actions()[$action][0] ?? $action;
}

function audit_action_tone(string $action): string
{
    return audit_actions()[$action][1] ?? 'neutral';
}

/** Record types the trail can point at. */
function audit_entities(): array
{
    return [
        'session'            => 'Session',
        'issue'              => 'Issue',
        'file'               => 'Evidence',
        'task'               => 'Task',
        'template'           => 'Template',
        'exception'          => 'Exception',
        'exception_category' => 'Exception category',
        'user'               => 'User',
    ];
}

function audit_entity_label(string $entity): string
{
    return audit_entities()[$entity] ?? $entity;
}

/** Link back to the record an entry refers to, when there is a page for it. */
function audit_record_url(array $row): string
{
    $id = (int) ($row['entity_id'] ?? 0);
    if ($id <= 0) {
        return '';
    }
    switch ((string) ($row['entity'] ?? '')) {
        case 'issue':
            return '?page=issue&id=' . $id;
        case 'template':
            return '?page=template&id=' . $id;
        case 'user':
            return '?page=users';
        case 'exception':
        case 'exception_category':
            return '?page=exceptions';
        case 'task':
            return '?page=workflow';
        default:
            return '';
    }
}

/**
 * Write one trail entry.
 *
 * Never throws: an audit failure must not roll back or block the action it is
 * recording. Pass $actor/$role explicitly for events that happen before the
 * session user exists (a sign-in attempt, for example).
 */
function audit(
    string $action,
    string $entity = '',
    ?int $entityId = null,
    string $label = '',
    string $detail = '',
    ?string $actor = null,
    string $role = ''
): void {
    $pdo = $GLOBALS['audit_pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return;
    }

    try {
        $user = function_exists('current_user') ? current_user() : null;

        $row = [
            'actor_id'  => (int) ($user['id'] ?? 0) ?: null,
            'actor'     => mb_substr($actor ?? (string) ($user['name'] ?? 'System'), 0, 100),
            'role'      => mb_substr($role !== '' ? $role : (string) ($user['role'] ?? ''), 0, 20),
            'action'    => mb_substr($action, 0, 40),
            'entity'    => mb_substr($entity, 0, 30),
            'entity_id' => $entityId !== null && $entityId > 0 ? $entityId : null,
            'label'     => mb_substr($label, 0, 200),
            'detail'    => mb_substr($detail, 0, 500),
            'ip'        => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'agent'     => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
        ];

        $pdo->prepare('INSERT INTO audit_log (actor_id, actor, role, action, entity, entity_id, label, detail, ip, agent)
                       VALUES (:actor_id, :actor, :role, :action, :entity, :entity_id, :label, :detail, :ip, :agent)')
            ->execute($row);
    } catch (Throwable $e) {
        error_log('audit write failed: ' . $e->getMessage());
    }
}

/** Normalise the filter form / query string. Unknown values are dropped. */
function audit_filters(array $get): array
{
    $action = (string) ($get['action'] ?? '');
    $entity = (string) ($get['entity'] ?? '');

    return [
        'q'      => mb_substr(trim((string) ($get['q'] ?? '')), 0, 100),
        'action' => isset(audit_actions()[$action]) ? $action : '',
        'entity' => isset(audit_entities()[$entity]) ? $entity : '',
        'actor'  => mb_substr(trim((string) ($get['actor'] ?? '')), 0, 60),
        'from'   => (string) (date_or($get['from'] ?? '', '') ?? ''),
        'to'     => (string) (date_or($get['to'] ?? '', '') ?? ''),
    ];
}

/** [WHERE clause, bound arguments] for a filter set. */
function audit_where(array $f): array
{
    $where = [];
    $args  = [];

    if (($f['q'] ?? '') !== '') {
        $where[] = '(label LIKE ? OR detail LIKE ? OR actor LIKE ?)';
        array_push($args, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%');
    }
    if (($f['action'] ?? '') !== '') {
        $where[] = 'action = ?';
        $args[]  = $f['action'];
    }
    if (($f['entity'] ?? '') !== '') {
        $where[] = 'entity = ?';
        $args[]  = $f['entity'];
    }
    if (($f['actor'] ?? '') !== '') {
        $where[] = 'actor LIKE ?';
        $args[]  = '%' . $f['actor'] . '%';
    }
    if (($f['from'] ?? '') !== '') {
        $where[] = 'created_at >= ?';
        $args[]  = $f['from'] . ' 00:00:00';
    }
    if (($f['to'] ?? '') !== '') {
        $where[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $args[]  = $f['to'];
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $args];
}

/**
 * One page of trail entries.
 *
 * @return array{rows: array, total: int, page: int, pages: int, per: int}
 */
function audit_search(PDO $pdo, array $f, int $page = 1, int $per = 40): array
{
    $per  = max(1, min(5000, $per));
    $page = max(1, $page);

    [$where, $args] = audit_where($f);

    $count = $pdo->prepare('SELECT COUNT(*) FROM audit_log' . $where);
    $count->execute($args);
    $total = (int) $count->fetchColumn();

    $pages = max(1, (int) ceil($total / $per));
    $page  = min($page, $pages);
    $offset = ($page - 1) * $per;

    $sql = 'SELECT * FROM audit_log' . $where
         . ' ORDER BY created_at DESC, id DESC LIMIT ' . $per . ' OFFSET ' . $offset;
    $st = $pdo->prepare($sql);
    $st->execute($args);

    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per];
}

/** Counters for the trail header. */
function audit_stats(PDO $pdo): array
{
    $one = static fn(string $sql): int => (int) $pdo->query($sql)->fetchColumn();

    return [
        'total'  => $one('SELECT COUNT(*) FROM audit_log'),
        'today'  => $one('SELECT COUNT(*) FROM audit_log WHERE created_at >= CURDATE()'),
        'week'   => $one('SELECT COUNT(*) FROM audit_log WHERE created_at >= (NOW() - INTERVAL 7 DAY)'),
        'failed' => $one("SELECT COUNT(*) FROM audit_log
                          WHERE action IN ('auth.failed','auth.locked','auth.blocked')
                            AND created_at >= (NOW() - INTERVAL 1 DAY)"),
        'denied' => $one("SELECT COUNT(*) FROM audit_log
                          WHERE action = 'access.denied' AND created_at >= (NOW() - INTERVAL 7 DAY)"),
        'actors' => $one("SELECT COUNT(DISTINCT actor) FROM audit_log WHERE actor <> ''"),
    ];
}
