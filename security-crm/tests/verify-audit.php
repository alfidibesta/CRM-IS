<?php
declare(strict_types=1);
/**
 * Verify the audit trail's hash chain against the live database.
 *
 *   php tests/verify-audit.php [--no-anchor]
 *
 * Re-reads audit_log in id order, recomputes each entry's SHA-256 from the
 * fields the database holds and follows the links between entries. It stops at
 * the first entry that does not match and names it, so a direct MySQL edit, a
 * deleted row or an insert that bypassed the application cannot pass unnoticed.
 *
 * A chain alone cannot notice the newest entries disappearing: nothing follows
 * them, so no link breaks. After a clean walk the verifier therefore compares
 * the trail with the last anchor line (SECOPS_AUDIT_ANCHOR, or
 * logs/audit-anchors.log) — the head hash it recorded outside the database on
 * the previous run — and then appends a fresh anchor. Deleting or rewriting an
 * entry that an earlier run anchored fails the check. --no-anchor verifies
 * without advancing the file (a read-only audit).
 *
 * Exit code 0 = chain intact (or the trail is empty), 1 = broken or unreadable.
 * Run it from the security-crm folder, or from anywhere: paths are relative to
 * this file. README.md covers the interpretation and its limits.
 */

require __DIR__ . '/../lib/helpers.php';
require __DIR__ . '/../lib/audit.php';
require __DIR__ . '/../lib/db.php';

$noAnchor = in_array('--no-anchor', array_slice($argv ?? [], 1), true);

$cfg = require __DIR__ . '/../config.php';

try {
    $pdo = db($cfg);
} catch (Throwable $e) {
    fwrite(STDERR, 'database: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

try {
    $res = audit_verify($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'cannot read the chain: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'open the app once so migrate() adds prev_hash / row_hash, then run this again.' . PHP_EOL);
    exit(1);
}

if ($res['checked'] === 0) {
    echo 'audit trail: empty — nothing to verify' . PHP_EOL;
    exit(0);
}

if ($res['ok']) {
    echo 'audit trail: ' . $res['checked'] . ' entries, chain intact'
        . ' (ids ' . $res['head'] . '–' . $res['newest'] . ')' . PHP_EOL;

    // The anchor is what makes a deletion of the newest entries visible.
    $anchor = audit_anchor_check($pdo);
    if (!$anchor['ok']) {
        $why = [
            'deleted'   => 'the entry recorded at the last anchor is gone — the newest entries were deleted, or the database was restored from an older copy',
            'rewritten' => 'the entry recorded at the last anchor no longer has the hash written outside the database',
            'truncated' => 'the trail is shorter than the last anchor',
        ];
        fwrite(STDERR, 'audit anchor: BROKEN at entry #' . (int) ($anchor['id'] ?? 0) . ' — '
            . ($why[$anchor['reason']] ?? 'unexpected anchor state') . PHP_EOL);
        fwrite(STDERR, 'anchor file: ' . (string) ($anchor['anchor']['file'] ?? audit_anchor_file()) . PHP_EOL);
        exit(1);
    }

    if ($anchor['reason'] === 'none') {
        echo 'audit anchor: none recorded yet' . PHP_EOL;
    } else {
        echo 'audit anchor: matches the last anchor (id ' . (int) $anchor['id']
            . ', written ' . (string) ($anchor['anchor']['at'] ?? '?') . ')' . PHP_EOL;
    }

    if ($noAnchor) {
        echo 'audit anchor: not advanced (--no-anchor)' . PHP_EOL;
        exit(0);
    }

    $write = audit_anchor_write($pdo, (int) $res['checked']);
    if (!$write['ok']) {
        fwrite(STDERR, 'audit anchor: cannot write — ' . (string) ($write['error'] ?? 'unknown error') . PHP_EOL);
        exit(1);
    }
    echo 'audit anchor: appended id ' . (int) $write['id'] . ' to ' . $write['file'] . PHP_EOL;
    exit(0);
}

$reasons = [
    'missing' => 'the entry carries no hash — it was written outside the application',
    'link'    => 'the entry no longer links to the entry before it',
    'content' => 'the entry no longer matches the hash recorded for it',
];

$broken = $res['broken'] ?? ['id' => 0, 'reason' => 'link'];
echo 'audit trail: FIRST BROKEN LINK at entry #' . (int) $broken['id'] . ' — '
    . ($reasons[$broken['reason']] ?? 'unexpected chain state') . PHP_EOL;
echo 'checked ' . $res['checked'] . ' entries up to that point; newest id ' . (int) $res['newest'] . PHP_EOL;
exit(1);
