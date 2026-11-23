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
 * Append-only by convention is not the same as tamper-evident, so every entry
 * also carries `prev_hash` (the hash of the entry before it) and `row_hash`
 * (SHA-256 over its own stored fields plus that link). Changing a row, deleting
 * one from the middle or inserting one behind the application's back breaks the
 * chain, and audit_verify() names the first entry that no longer matches.
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
    static $actions = [
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
        'sop.create'   => ['SOP created', 'blue'],
        'sop.update'   => ['SOP saved', 'blue'],
        'sop.delete'   => ['SOP deleted', 'red'],
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
        'logs.view'    => ['System log read', 'neutral'],
    ];

    return $actions;
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
        'sop'                => 'SOP / policy',
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
        case 'sop':
            return '?page=sop';
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

    $ownTxn = false;

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
            'ip'        => mb_substr(client_ip(), 0, 45),
            'agent'     => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
        ];

        // The chain is a single line, so the writer reads the current tail and
        // appends under a row lock: two simultaneous events cannot both link
        // to the same predecessor.
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownTxn = true;
        }

        $tail = $pdo->query('SELECT row_hash FROM audit_log ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
        $prev = is_string($tail) ? $tail : '';

        $pdo->prepare('INSERT INTO audit_log (actor_id, actor, role, action, entity, entity_id, label, detail, ip, agent)
                       VALUES (:actor_id, :actor, :role, :action, :entity, :entity_id, :label, :detail, :ip, :agent)')
            ->execute($row);

        $id = (int) $pdo->lastInsertId();

        // Hash what the database actually stored — defaults, truncation and
        // NULLs included — so the verifier recomputes the same bytes.
        $st = $pdo->prepare('SELECT * FROM audit_log WHERE id = ?');
        $st->execute([$id]);
        $saved = $st->fetch() ?: [];
        $hash = audit_row_hash($saved, $prev);

        $pdo->prepare('UPDATE audit_log SET prev_hash = ?, row_hash = ? WHERE id = ?')
            ->execute([$prev, $hash, $id]);

        if ($ownTxn) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable) {
                // The trail is best-effort; the caller's action still stands.
            }
        }
        error_log('audit write failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------ hash chain */

/** Fields sealed into an entry's hash, in a fixed order. */
function audit_hash_fields(): array
{
    return ['id', 'created_at', 'actor_id', 'actor', 'role', 'action',
            'entity', 'entity_id', 'label', 'detail', 'ip', 'agent'];
}

/**
 * Canonical bytes for one link: the entry's own fields plus the hash of the
 * entry before it.
 *
 * JSON is used because it is unambiguous — a value cannot forge a field
 * boundary, and NULL stays distinct from the empty string.
 */
function audit_hash_payload(array $row, string $prevHash): string
{
    $values = [];
    foreach (audit_hash_fields() as $field) {
        $values[$field] = $row[$field] ?? null;
    }
    $values['prev_hash'] = $prevHash;

    return (string) json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** The stored hash for one entry: SHA-256 over its payload. */
function audit_row_hash(array $row, string $prevHash): string
{
    return hash('sha256', audit_hash_payload($row, $prevHash));
}

/**
 * Walk an ordered list of entries and report the first one that does not
 * match its own hash or the entry before it.
 *
 * Pure: no database, so tests can hand it rows. $prevHash is the hash the
 * first entry is expected to link to (empty for the very first entry).
 *
 * @return array{ok: bool, checked: int, broken: ?array, head: ?int, tail: string}
 */
function audit_chain_check(array $rows, string $prevHash = ''): array
{
    $prev    = $prevHash;
    $checked = 0;
    $broken  = null;
    $head    = null;

    foreach ($rows as $row) {
        $checked++;
        $id = (int) ($row['id'] ?? 0);
        if ($head === null) {
            $head = $id;
        }

        $stored = (string) ($row['row_hash'] ?? '');
        $link   = (string) ($row['prev_hash'] ?? '');

        if ($broken === null) {
            if ($stored === '') {
                $broken = ['id' => $id, 'reason' => 'missing'];
            } elseif (!hash_equals($prev, $link)) {
                $broken = ['id' => $id, 'reason' => 'link'];
            } elseif (!hash_equals(audit_row_hash($row, $link), $stored)) {
                $broken = ['id' => $id, 'reason' => 'content'];
            }
        }

        // Follow what is stored, so the next entry is judged against the entry
        // that actually sits before it.
        $prev = $stored;
    }

    return ['ok' => $broken === null, 'checked' => $checked, 'broken' => $broken,
            'head' => $head, 'tail' => $prev];
}

/**
 * Verify the whole trail, in id order, in chunks.
 *
 * @return array{ok: bool, checked: int, broken: ?array, head: ?int, newest: ?int, tail: string}
 */
function audit_verify(PDO $pdo, int $limit = 0): array
{
    $seen    = 0;
    $afterId = 0;
    $prev    = '';
    $broken  = null;
    $head    = null;
    $newest  = null;

    while (true) {
        $take = 500;
        if ($limit > 0) {
            $take = min($take, $limit - $seen);
            if ($take <= 0) {
                break;
            }
        }

        // LIMIT cannot be bound portably through emulated prepares.
        $chunk = $pdo->prepare('SELECT * FROM audit_log WHERE id > ' . $afterId . ' ORDER BY id LIMIT ' . $take);
        $chunk->execute();
        $rows = $chunk->fetchAll();
        if (!$rows) {
            break;
        }

        $step = audit_chain_check($rows, $prev);
        if ($broken === null && !$step['ok']) {
            $broken = $step['broken'];
        }
        if ($head === null) {
            $head = $step['head'];
        }
        $seen  += $step['checked'];
        $prev   = $step['tail'];
        $last   = end($rows);
        $afterId = (int) $last['id'];
        $newest = $afterId;
    }

    return ['ok' => $broken === null, 'checked' => $seen, 'broken' => $broken,
            'head' => $head, 'newest' => $newest, 'tail' => $prev];
}

/* ------------------------------------------------------- external anchors */

/**
 * The anchor file: one line per verification run, outside the database.
 *
 * On the same host as the app by default (logs/ is denied by .htaccess and
 * router.php); SECOPS_AUDIT_ANCHOR points it at another disk or a share, which
 * is what makes a database restore or a row deletion visible.
 */
function audit_anchor_file(?string $file = null): string
{
    if ($file !== null && $file !== '') {
        return $file;
    }
    $env = getenv('SECOPS_AUDIT_ANCHOR');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    return dirname(__DIR__) . '/logs/audit-anchors.log';
}

/**
 * One anchor line: when, the newest id, that entry's hash, entries checked.
 * Tab-separated so the file stays readable with `cat` and cheap to append.
 */
function audit_anchor_line(array $row, int $checked, ?string $at = null): string
{
    return ($at ?? date('c')) . "\t" . (int) ($row['id'] ?? 0) . "\t"
        . (string) ($row['row_hash'] ?? '') . "\t" . max(0, $checked);
}

/** Parse an anchor line back into its fields; null when it is not one. */
function audit_anchor_parse(string $line): ?array
{
    $parts = explode("\t", rtrim($line, "\r\n"));
    if (count($parts) !== 4) {
        return null;
    }
    [$at, $id, $hash, $checked] = $parts;
    if ($id === '' || !ctype_digit($id) || strlen($hash) !== 64 || !ctype_xdigit($hash) || !ctype_digit($checked)) {
        return null;
    }

    return ['at' => $at, 'id' => (int) $id, 'hash' => strtolower($hash), 'checked' => (int) $checked];
}

/** The most recent anchor line, or null when nothing has been anchored yet. */
function audit_anchor_last(?string $file = null): ?array
{
    $file = audit_anchor_file($file);
    if (!is_file($file) || !is_readable($file)) {
        return null;
    }

    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return null;
    }

    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $anchor = audit_anchor_parse((string) $lines[$i]);
        if ($anchor !== null) {
            return $anchor + ['file' => $file];
        }
    }

    return null;
}

/**
 * Judge the anchored entry against the live trail. Pure, so the tests can
 * cover every branch without a database.
 *
 * @param array|null $anchor the last anchor line (id + hash)
 * @param array|null $stored the row at that id, or null when it is gone
 * @param int        $newest the highest id in audit_log right now
 * @return array{ok: bool, reason: string, id?: int, newest?: int}
 */
function audit_anchor_verdict(?array $anchor, ?array $stored, int $newest): array
{
    if ($anchor === null) {
        return ['ok' => true, 'reason' => 'none'];
    }

    $id = (int) $anchor['id'];
    if ($stored === null) {
        return ['ok' => false, 'reason' => 'deleted', 'id' => $id];
    }
    if (!hash_equals((string) $anchor['hash'], (string) ($stored['row_hash'] ?? ''))) {
        return ['ok' => false, 'reason' => 'rewritten', 'id' => $id];
    }
    if ($newest < $id) {
        return ['ok' => false, 'reason' => 'truncated', 'id' => $id];
    }

    return ['ok' => true, 'reason' => 'ok', 'id' => $id, 'newest' => $newest];
}

/** Compare the newest anchor line with the database. */
function audit_anchor_check(PDO $pdo, ?array $anchor = null): array
{
    $anchor = $anchor ?? audit_anchor_last();
    $newest = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
    $stored = null;

    if ($anchor !== null) {
        $st = $pdo->prepare('SELECT id, row_hash FROM audit_log WHERE id = ?');
        $st->execute([(int) $anchor['id']]);
        $stored = $st->fetch() ?: null;
    }

    return audit_anchor_verdict($anchor, $stored, $newest) + ['anchor' => $anchor];
}

/**
 * Append the current head to the anchor file. Called by the verifier after a
 * clean walk, so each run records the newest entry the database had.
 *
 * @return array{ok: bool, file?: string, id?: int, error?: string}
 */
function audit_anchor_write(PDO $pdo, int $checked, ?string $file = null): array
{
    $file = audit_anchor_file($file);
    $row  = $pdo->query('SELECT id, row_hash FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'the trail is empty', 'file' => $file];
    }

    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'cannot create ' . $dir, 'file' => $file];
    }

    $line = audit_anchor_line($row, $checked) . PHP_EOL;
    if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'cannot append to ' . $file, 'file' => $file];
    }

    return ['ok' => true, 'file' => $file, 'id' => (int) $row['id']];
}

/**
 * Seal entries that carry no hash yet, in id order.
 *
 * Used by the migration and the seed for rows written before the chain
 * existed. Rows that already have a hash are never rewritten — re-sealing an
 * altered trail would erase the evidence — so a direct MySQL edit stays
 * visible where it belongs instead of quietly becoming valid.
 */
function audit_seal_pending(PDO $pdo): int
{
    $sealed  = 0;
    $afterId = 0;
    $prev    = '';
    $update  = $pdo->prepare('UPDATE audit_log SET prev_hash = ?, row_hash = ? WHERE id = ?');

    while (true) {
        $st = $pdo->prepare('SELECT * FROM audit_log WHERE id > ' . $afterId . ' ORDER BY id LIMIT 500');
        $st->execute();
        $rows = $st->fetchAll();
        if (!$rows) {
            break;
        }

        foreach ($rows as $row) {
            $id     = (int) $row['id'];
            $stored = (string) ($row['row_hash'] ?? '');
            if ($stored === '') {
                $stored = audit_row_hash($row, $prev);
                $update->execute([$prev, $stored, $id]);
                $sealed++;
            }
            $prev    = $stored;
            $afterId = $id;
        }
    }

    return $sealed;
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

/** Counters for the trail header — one aggregate pass over the table. */
function audit_stats(PDO $pdo): array
{
    $row = $pdo->query("SELECT
            COUNT(*)                                                              AS total,
            COALESCE(SUM(created_at >= CURDATE()), 0)                             AS today,
            COALESCE(SUM(created_at >= (NOW() - INTERVAL 7 DAY)), 0)              AS week,
            COALESCE(SUM(action IN ('auth.failed','auth.locked','auth.blocked')
                     AND created_at >= (NOW() - INTERVAL 1 DAY)), 0)              AS failed,
            COALESCE(SUM(action = 'access.denied'
                     AND created_at >= (NOW() - INTERVAL 7 DAY)), 0)              AS denied,
            COUNT(DISTINCT NULLIF(actor, ''))                                     AS actors
        FROM audit_log")->fetch();

    return [
        'total'  => (int) ($row['total'] ?? 0),
        'today'  => (int) ($row['today'] ?? 0),
        'week'   => (int) ($row['week'] ?? 0),
        'failed' => (int) ($row['failed'] ?? 0),
        'denied' => (int) ($row['denied'] ?? 0),
        'actors' => (int) ($row['actors'] ?? 0),
    ];
}
