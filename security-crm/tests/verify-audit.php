<?php
declare(strict_types=1);
/**
 * Verify the audit trail's hash chain against the live database.
 *
 *   php tests/verify-audit.php
 *
 * Re-reads audit_log in id order, recomputes each entry's SHA-256 from the
 * fields the database holds and follows the links between entries. It stops at
 * the first entry that does not match and names it, so a direct MySQL edit, a
 * deleted row or an insert that bypassed the application cannot pass unnoticed.
 *
 * Exit code 0 = chain intact (or the trail is empty), 1 = broken or unreadable.
 * Run it from the security-crm folder, or from anywhere: paths are relative to
 * this file. README.md covers the interpretation and its limits.
 */

require __DIR__ . '/../lib/helpers.php';
require __DIR__ . '/../lib/audit.php';
require __DIR__ . '/../lib/db.php';

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
