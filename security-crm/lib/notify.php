<?php
declare(strict_types=1);

/**
 * In-app notifications.
 *
 * Two producers feed one table:
 *  - write-time rules (an issue is assigned, a Critical issue is raised) called
 *    from index.php in the same request as the change;
 *  - a page-load sweep (issues due, exceptions expiring, SOP reviews) that runs
 *    at most every five minutes per session and is idempotent thanks to the
 *    per-user dedupe key, so no cron job is needed.
 *
 * Rows belong to one user and are only ever read by that user.
 */

function notify_boot(PDO $pdo): void
{
    $GLOBALS['notify_pdo'] = $pdo;
}

/** Kind catalogue: key => [label, tone]. */
function notification_kinds(): array
{
    static $kinds = [
        'issue_assigned'      => ['Assigned to you', 'blue'],
        'issue_critical'      => ['Critical issue', 'red'],
        'issue_due'           => ['Issue due', 'orange'],
        'exception_expiring'  => ['Exception expiring', 'orange'],
        'exception_expired'   => ['Exception expired', 'red'],
        'sop_review'          => ['SOP review due', 'blue'],
    ];

    return $kinds;
}

function notification_label(string $kind): string
{
    return notification_kinds()[$kind][0] ?? $kind;
}

function notification_tone(string $kind): string
{
    return notification_kinds()[$kind][1] ?? 'neutral';
}

/** Compact age: "just now", "12m ago", "3h ago", "2d ago", then a date. */
function notification_when(?string $ts): string
{
    if (!$ts) {
        return '—';
    }
    $t = strtotime($ts);
    if (!$t) {
        return '—';
    }
    $d = time() - $t;
    if ($d < 60) {
        return 'just now';
    }
    if ($d < 3600) {
        return intdiv($d, 60) . 'm ago';
    }
    if ($d < 86400) {
        return intdiv($d, 3600) . 'h ago';
    }
    if ($d < 7 * 86400) {
        return intdiv($d, 86400) . 'd ago';
    }
    return date('d M', $t);
}

/** The account behind an owner name, when that name matches one. */
function notification_user_for_name(PDO $pdo, string $name): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }

    // One lookup per request instead of one per row; first match wins, which is
    // what the old LIMIT 1 query did when two accounts shared a name.
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach ($pdo->query('SELECT id, name, username FROM users WHERE active = 1')->fetchAll() as $u) {
            foreach ([mb_strtolower((string) $u['name']), mb_strtolower((string) $u['username'])] as $key) {
                if ($key !== '' && !isset($map[$key])) {
                    $map[$key] = (int) $u['id'];
                }
            }
        }
    }

    return $map[mb_strtolower($name)] ?? 0;
}

/**
 * Write one notification for one user.
 *
 * $dedupe makes the write idempotent: the same key for the same user is stored
 * once and later attempts are ignored, which is what lets the sweep re-run.
 */
function notify(int $userId, string $kind, string $title, string $body = '', string $link = '', string $dedupe = ''): void
{
    $pdo = $GLOBALS['notify_pdo'] ?? null;
    if (!$pdo instanceof PDO || $userId <= 0) {
        return;
    }

    try {
        $key = $dedupe !== '' ? mb_substr($dedupe . ':u' . $userId, 0, 140) : null;
        $pdo->prepare('INSERT IGNORE INTO notifications (user_id, kind, title, body, link, tone, dedupe)
                       VALUES (?,?,?,?,?,?,?)')
            ->execute([
                $userId,
                mb_substr($kind, 0, 30),
                mb_substr($title, 0, 200),
                mb_substr($body, 0, 400),
                mb_substr($link, 0, 200),
                notification_tone($kind),
                $key,
            ]);
    } catch (Throwable $e) {
        error_log('notification failed: ' . $e->getMessage());
    }
}

/** Notify every active account holding one of the given roles. */
function notify_roles(array $roles, string $kind, string $title, string $body = '', string $link = '', string $dedupe = ''): void
{
    $pdo = $GLOBALS['notify_pdo'] ?? null;
    if (!$pdo instanceof PDO || !$roles) {
        return;
    }
    foreach (notify_role_ids($pdo, $roles) as $id) {
        notify($id, $kind, $title, $body, $link, $dedupe);
    }
}

/** Active account ids holding any of the given roles, queried once per request. */
function notify_role_ids(PDO $pdo, array $roles): array
{
    static $cache = [];
    $key = implode('|', $roles);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $in = implode(',', array_fill(0, count($roles), '?'));
    $st = $pdo->prepare("SELECT id FROM users WHERE active = 1 AND role IN ($in)");
    $st->execute(array_values($roles));

    return $cache[$key] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** "Issue SEC-1004 is yours now" — skipped when you assigned it to yourself. */
function notify_issue_assignment(PDO $pdo, string $owner, string $code, string $title, int $issueId): void
{
    $uid = notification_user_for_name($pdo, $owner);
    $me  = (int) (current_user()['id'] ?? 0);
    if ($uid <= 0 || $uid === $me) {
        return;
    }
    notify($uid, 'issue_assigned', 'Issue ' . $code . ' is assigned to you', $title, '?page=issue&id=' . $issueId, 'issue_assigned:' . $issueId);
}

/** Critical issues go to everyone who runs the programme. */
function notify_issue_critical(string $code, string $title, int $issueId): void
{
    notify_roles(['manager', 'admin'], 'issue_critical', 'Critical issue ' . $code, $title, '?page=issue&id=' . $issueId, 'issue_critical:' . $issueId);
}

/** Unread count plus the latest few, for the bell. */
function notify_summary(PDO $pdo, int $limit = 8): array
{
    $uid = (int) (current_user()['id'] ?? 0);
    if ($uid <= 0) {
        return ['unread' => 0, 'items' => []];
    }

    $c = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $c->execute([$uid]);

    $st = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ?
                         ORDER BY read_at IS NULL DESC, created_at DESC, id DESC
                         LIMIT ' . max(1, min(20, $limit)));
    $st->execute([$uid]);

    return ['unread' => (int) $c->fetchColumn(), 'items' => $st->fetchAll()];
}

/** Normalise the list filters. */
function notify_filters(array $get): array
{
    $kind = (string) ($get['kind'] ?? '');
    $state = (string) ($get['state'] ?? '');

    return [
        'state' => $state === 'all' ? 'all' : 'unread',
        'kind'  => isset(notification_kinds()[$kind]) ? $kind : '',
    ];
}

/** One page of the signed-in user's notifications. */
function notify_fetch(PDO $pdo, int $uid, array $f, int $page = 1, int $per = 30): array
{
    $where = ['user_id = ?'];
    $args  = [$uid];
    if (($f['state'] ?? 'unread') !== 'all') {
        $where[] = 'read_at IS NULL';
    }
    if (($f['kind'] ?? '') !== '') {
        $where[] = 'kind = ?';
        $args[]  = $f['kind'];
    }
    $sql = ' WHERE ' . implode(' AND ', $where);

    $count = $pdo->prepare('SELECT COUNT(*) FROM notifications' . $sql);
    $count->execute($args);
    $total = (int) $count->fetchColumn();

    $per   = max(1, min(200, $per));
    $pages = max(1, (int) ceil($total / $per));
    $page  = max(1, min($page, $pages));

    $st = $pdo->prepare('SELECT * FROM notifications' . $sql
        . ' ORDER BY created_at DESC, id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
    $st->execute($args);

    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** Mark everything of this user read; returns how many changed. */
function notify_mark_all(PDO $pdo, int $uid): int
{
    if ($uid <= 0) {
        return 0;
    }
    $st = $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
    $st->execute([$uid]);

    return $st->rowCount();
}

function notify_mark_one(PDO $pdo, int $uid, int $id): void
{
    if ($uid <= 0 || $id <= 0) {
        return;
    }
    $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL')
        ->execute([$id, $uid]);
}

/**
 * Time-driven rules, run on page load at most once per five minutes.
 * Every write carries a date-stamped dedupe key, so a second sweep the same
 * day adds nothing.
 */
function notify_sweep(PDO $pdo): void
{
    if (!current_user()) {
        return;
    }
    if ((int) ($_SESSION['notify_sweep'] ?? 0) > time() - 300) {
        return;
    }
    $_SESSION['notify_sweep'] = time();

    $today = date('Y-m-d');

    // 1. Issues due within three days, or already past their date.
    $due = $pdo->query("SELECT id, code, title, owner, due_at FROM issues
                        WHERE status <> 'Resolved' AND due_at IS NOT NULL
                          AND due_at <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetchAll();
    foreach ($due as $i) {
        $uid = notification_user_for_name($pdo, (string) $i['owner']);
        if ($uid <= 0) {
            continue;
        }
        $days  = exception_days_left((string) $i['due_at']);
        $title = $days !== null && $days < 0
            ? 'Issue ' . $i['code'] . ' is ' . abs($days) . ' day(s) overdue'
            : 'Issue ' . $i['code'] . ' is due in ' . max(0, (int) $days) . ' day(s)';
        notify($uid, 'issue_due', $title, (string) $i['title'], '?page=issue&id=' . (int) $i['id'],
            'issue_due:' . (int) $i['id'] . ':' . $today);
    }

    // 2. EDLP exceptions expiring within 30 days, or already expired.
    $exc = $pdo->query("SELECT id, code, title, category, owner, period_end FROM exceptions
                        WHERE status = 'Active' AND period_end IS NOT NULL
                          AND period_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchAll();
    foreach ($exc as $x) {
        $days  = exception_days_left((string) $x['period_end']);
        $kind  = ($days !== null && $days < 0) ? 'exception_expired' : 'exception_expiring';
        $title = $kind === 'exception_expired'
            ? 'Exception ' . $x['code'] . ' has expired'
            : 'Exception ' . $x['code'] . ' expires in ' . (int) $days . ' day(s)';
        $body  = $x['title'] . ' · ' . $x['category'];
        $key   = $kind . ':' . (int) $x['id'] . ':' . $today;

        notify_roles(['manager', 'admin'], $kind, $title, $body, '?page=exceptions', $key);

        $ownerId = notification_user_for_name($pdo, (string) $x['owner']);
        if ($ownerId > 0) {
            notify($ownerId, $kind, $title, $body, '?page=exceptions', $key);
        }
    }

    // 3. SOP and policy reviews coming due.
    $sops = $pdo->query("SELECT id, code, title, review_at FROM sops
                         WHERE status <> 'Obsolete' AND review_at IS NOT NULL
                           AND review_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchAll();
    foreach ($sops as $s) {
        $days = exception_days_left((string) $s['review_at']);
        $when = $days !== null && $days < 0
            ? abs($days) . ' day(s) overdue'
            : 'in ' . max(0, (int) $days) . ' day(s)';
        notify_roles(['manager', 'admin'], 'sop_review', 'SOP review due: ' . $s['code'],
            $s['title'] . ' · review ' . $when, '?page=sop', 'sop:' . (int) $s['id'] . ':' . $today);
    }
}
