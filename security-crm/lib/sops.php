<?php
/**
 * SOP / policy document control: option lists, form validation and code
 * allocation, shared by the router and the library view.
 */
declare(strict_types=1);

const SOP_TYPES    = ['Policy', 'SOP', 'Guideline'];
const SOP_SCOPES   = ['Physical', 'IT', 'Strain', 'General'];
const SOP_STATUSES = ['Draft', 'In Review', 'Active', 'Obsolete'];

/** Longest controlled text the `content` TEXT column holds, in bytes. */
const SOP_CONTENT_MAX = 60000;

/**
 * Validate and normalise a posted document form.
 *
 * @return array{0: array<string, mixed>, 1: string} the row and the first error
 */
function sop_fields(array $post, bool $codeRequired = false): array
{
    $code    = strtoupper(trim((string) ($post['code'] ?? '')));
    $title   = clamp($post['title'] ?? '', 200);
    $content = (string) ($post['content'] ?? '');
    $from    = date_or($post['effective_at'] ?? '', null);
    $review  = date_or($post['review_at'] ?? '', null);
    $status  = in_array($post['status'] ?? '', SOP_STATUSES, true) ? (string) $post['status'] : 'Draft';

    $err = '';
    if ($title === '') {
        $err = 'A document title is required.';
    } elseif ($code === '' && $codeRequired) {
        $err = 'A document code is required.';
    } elseif ($code !== '' && !preg_match('/^[A-Z0-9._-]{1,20}$/', $code)) {
        $err = 'The code may hold letters, digits, dots, dashes and underscores only, up to 20 characters.';
    } elseif (strlen($content) > SOP_CONTENT_MAX) {
        $err = 'The controlled text is too long — keep it under 60,000 bytes.';
    } elseif ($from !== null && $review !== null && strcmp($review, $from) < 0) {
        $err = 'The next review date falls before the effective date; correct one of the two.';
    } elseif ($status === 'Active' && $from === null) {
        $err = 'An Active document needs an effective date.';
    }

    return [[
        'code'         => $code,
        'title'        => $title,
        'type'         => in_array($post['type'] ?? '', SOP_TYPES, true) ? (string) $post['type'] : 'SOP',
        'scope'        => in_array($post['scope'] ?? '', SOP_SCOPES, true) ? (string) $post['scope'] : 'General',
        'version'      => clamp($post['version'] ?? '1.0', 10) ?: '1.0',
        'owner'        => clamp($post['owner'] ?? '', 100),
        'status'       => $status,
        'effective_at' => $from,
        'review_at'    => $review,
        'summary'      => clamp($post['summary'] ?? '', 500),
        'content'      => $content,
    ], $err];
}

/** Scope prefix the register already uses: PHYS-001, IT-001, STRAIN-001, GEN-001. */
function sop_code_prefix(string $scope): string
{
    return ['Physical' => 'PHYS', 'IT' => 'IT', 'Strain' => 'STRAIN'][$scope] ?? 'GEN';
}

/** Next free code for a scope, so a blank code never collides with a row. */
function sop_next_code(PDO $pdo, string $scope): string
{
    $prefix = sop_code_prefix($scope) . '-';
    $st = $pdo->prepare('SELECT code FROM sops WHERE code LIKE ?');
    $st->execute([$prefix . '%']);
    $taken = array_flip($st->fetchAll(PDO::FETCH_COLUMN));

    for ($n = 1; $n < 1000; $n++) {
        $code = $prefix . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        if (!isset($taken[$code])) {
            return $code;
        }
    }
    return $prefix . '999';
}

/** True when a document other than $exceptId already owns the code. */
function sop_code_taken(PDO $pdo, string $code, int $exceptId = 0): bool
{
    $st = $pdo->prepare('SELECT id FROM sops WHERE code = ? AND id <> ?');
    $st->execute([$code, $exceptId]);
    return (bool) $st->fetchColumn();
}

/* ------------------------------------------- revision history (document control) */

/** Longest "reason for change" a revision stores. */
const SOP_CHANGE_REASON_MAX = 300;

/** Revisions one document shows on the library before the list is cut. */
const SOP_HISTORY_LIMIT = 20;

/** The sentence an editor gives for a save; required on update. */
function sop_change_reason(array $post): string
{
    return clamp($post['change_reason'] ?? '', SOP_CHANGE_REASON_MAX);
}

/** The controlled fields a revision snapshots, in register order: key => label. */
function sop_revision_fields(): array
{
    return [
        'code'         => 'Code',
        'title'        => 'Title',
        'type'         => 'Type',
        'scope'        => 'Scope',
        'version'      => 'Version',
        'owner'        => 'Owner',
        'status'       => 'Status',
        'effective_at' => 'Effective date',
        'review_at'    => 'Next review',
        'summary'      => 'Summary',
        'content'      => 'Controlled text',
    ];
}

/**
 * Which controlled fields differ between two snapshots, as labels.
 * NULL and '' compare equal, so a cleared date is not reported as a change
 * when it was never set.
 *
 * @return list<string>
 */
function sop_revision_diff(array $before, array $after): array
{
    $changed = [];
    foreach (sop_revision_fields() as $key => $label) {
        if ((string) ($before[$key] ?? '') !== (string) ($after[$key] ?? '')) {
            $changed[] = $label;
        }
    }

    return $changed;
}

/** Next revision number for a document. */
function sop_next_revision_no(PDO $pdo, int $sopId): int
{
    $st = $pdo->prepare('SELECT COALESCE(MAX(revision_no), 0) FROM sop_revisions WHERE sop_id = ?');
    $st->execute([$sopId]);

    return (int) $st->fetchColumn() + 1;
}

/**
 * Store one snapshot of a document as it now stands.
 *
 * Returns the new revision id. The unique key on (sop_id, revision_no) keeps
 * two simultaneous saves from sharing a number.
 */
function sop_save_revision(PDO $pdo, int $sopId, array $row, string $reason, string $by): int
{
    $pdo->prepare('INSERT INTO sop_revisions
            (sop_id, revision_no, code, title, type, scope, version, owner, status,
             effective_at, review_at, summary, content, change_reason, changed_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $sopId,
            sop_next_revision_no($pdo, $sopId),
            (string) $row['code'],
            (string) $row['title'],
            (string) $row['type'],
            (string) $row['scope'],
            (string) $row['version'],
            (string) $row['owner'],
            (string) $row['status'],
            $row['effective_at'] ?? null,
            $row['review_at'] ?? null,
            (string) ($row['summary'] ?? ''),
            (string) ($row['content'] ?? ''),
            clamp($reason, SOP_CHANGE_REASON_MAX),
            clamp($by, 100),
        ]);

    return (int) $pdo->lastInsertId();
}

/** Latest revision id of every document: sop_id => revision id. */
function sop_revision_head_ids(PDO $pdo): array
{
    $heads = [];
    foreach ($pdo->query('SELECT sop_id, MAX(id) AS head FROM sop_revisions GROUP BY sop_id') as $r) {
        $heads[(int) $r['sop_id']] = (int) $r['head'];
    }

    return $heads;
}

/** Revisions of several documents, newest first per document. */
function sop_revision_lists(PDO $pdo, array $sopIds): array
{
    $sopIds = array_values(array_filter(array_map('intval', $sopIds)));
    if (!$sopIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($sopIds), '?'));
    $st = $pdo->prepare("SELECT * FROM sop_revisions WHERE sop_id IN ($in)
                         ORDER BY sop_id, revision_no DESC");
    $st->execute($sopIds);

    $out = [];
    foreach ($st->fetchAll() as $row) {
        $id = (int) $row['sop_id'];
        if (count($out[$id] ?? []) < SOP_HISTORY_LIMIT) {
            $out[$id][] = $row;
        }
    }

    return $out;
}

/** Give every document without a history a baseline revision (idempotent). */
function sop_backfill_revisions(PDO $pdo): int
{
    return $pdo->exec("INSERT INTO sop_revisions
            (sop_id, revision_no, code, title, type, scope, version, owner, status,
             effective_at, review_at, summary, content, change_reason, changed_by, changed_at)
         SELECT s.id, 1, s.code, s.title, s.type, s.scope, s.version, s.owner, s.status,
                s.effective_at, s.review_at, s.summary, s.content,
                'Baseline recorded when version history was enabled', 'system', s.created_at
           FROM sops s
          WHERE NOT EXISTS (SELECT 1 FROM sop_revisions r WHERE r.sop_id = s.id)");
}

/* ----------------------------------------------- policy acknowledgement */

/** Every acknowledgement, keyed by document: sop_id => list of rows. */
function sop_ack_map(PDO $pdo): array
{
    $out = [];
    $sql = 'SELECT a.sop_id, a.user_id, a.revision_id, a.version, a.acknowledged_at
              FROM sop_acknowledgements a
              JOIN users u ON u.id = a.user_id
             ORDER BY a.acknowledged_at DESC';
    foreach ($pdo->query($sql) as $row) {
        $out[(int) $row['sop_id']][] = $row;
    }

    return $out;
}

/**
 * Whether this user has read the current revision of a document.
 *
 * @return array{acked: bool, at: string, revision_id: int}
 */
function sop_ack_state(array $rows, int $userId, int $headRev): array
{
    foreach ($rows as $row) {
        if ((int) $row['user_id'] === $userId) {
            return [
                'acked'       => $headRev > 0 && (int) $row['revision_id'] === $headRev,
                'at'          => (string) $row['acknowledged_at'],
                'revision_id' => (int) $row['revision_id'],
            ];
        }
    }

    return ['acked' => false, 'at' => '', 'revision_id' => 0];
}

/**
 * How much of the active workforce has read one revision.
 *
 * @param list<int> $activeUserIds
 * @return array{read: int, total: int, outstanding: list<int>}
 */
function sop_ack_coverage(array $rows, array $activeUserIds, int $headRev): array
{
    $atHead = [];
    foreach ($rows as $row) {
        if ($headRev > 0 && (int) $row['revision_id'] === $headRev) {
            $atHead[(int) $row['user_id']] = true;
        }
    }

    $read = 0;
    $outstanding = [];
    foreach ($activeUserIds as $id) {
        if (isset($atHead[(int) $id])) {
            $read++;
        } else {
            $outstanding[] = (int) $id;
        }
    }

    return ['read' => $read, 'total' => count($activeUserIds), 'outstanding' => $outstanding];
}

/** Record (or refresh) this user's reading of the current revision. */
function sop_ack_save(PDO $pdo, int $sopId, int $userId, int $revId, string $version): void
{
    $pdo->prepare('INSERT INTO sop_acknowledgements (sop_id, user_id, revision_id, version, acknowledged_at)
                   VALUES (?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE revision_id = VALUES(revision_id),
                                           version = VALUES(version),
                                           acknowledged_at = VALUES(acknowledged_at)')
        ->execute([$sopId, $userId, $revId, clamp($version, 10)]);
}
