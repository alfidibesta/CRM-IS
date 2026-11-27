<?php
declare(strict_types=1);
/**
 * Dependency-free unit checks.
 *
 *   php tests/run.php      exit code 0 = all green, 1 = failures
 *
 * These cover the pure helpers (no database): tag handling, risk ratings,
 * date/length clamping, exception state, template parsing and the report
 * placeholder fill.
 */

require __DIR__ . '/../lib/helpers.php';
require __DIR__ . '/../lib/word.php';
require __DIR__ . '/../lib/templates.php';
require __DIR__ . '/../lib/report.php';
require __DIR__ . '/../lib/uploads.php';
require __DIR__ . '/../lib/audit.php';
require __DIR__ . '/../lib/notify.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/sops.php';
require __DIR__ . '/../router.php';   // rules only; the CLI guard stops dispatch

$pass = 0;
$fail = 0;

function ck(string $label, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        return;
    }
    $fail++;
    echo "FAIL {$label}\n  expected: " . var_export($expected, true)
        . "\n  actual:   " . var_export($actual, true) . "\n";
}

/* ------------------------------------------------------------------ tags */
ck('tag_split empty', tag_split(''), []);
ck('tag_split list', tag_split('a, b'), ['a', 'b']);
ck('tag_normalise dedupes', tag_normalise(' A , b ,a '), 'A, b');
ck('tag_normalise caps at 6', substr_count(tag_normalise('1,2,3,4,5,6,7,8'), ','), 5);

/* --------------------------------------------------------- risk ratings */
ck('risk 4 low', risk_rating(4)[0], 'Low');
ck('risk 5 moderate', risk_rating(5)[0], 'Moderate');
ck('risk 10 high', risk_rating(10)[0], 'High');
ck('risk 25 critical', risk_rating(25)[0], 'Critical');

/* --------------------------------------------------- clamping + dates */
ck('clamp trims', clamp('  ab  ', 10), 'ab');
ck('clamp cuts', clamp('abcdef', 3), 'abc');
ck('date_or valid', date_or('2026-02-28'), '2026-02-28');
ck('date_or invalid day', date_or('2026-02-30', 'fb'), 'fb');
ck('date_or junk', date_or('not-a-date', null), null);

/* ------------------------------------------------------------ exceptions */
ck('days left', exception_days_left(date('Y-m-d', strtotime('+10 days'))), 10);
ck('state active', exception_state(['status' => 'Active', 'period_end' => date('Y-m-d', strtotime('+90 days'))])[0], 'Active');
ck('state expiring', exception_state(['status' => 'Active', 'period_end' => date('Y-m-d', strtotime('+12 days'))])[0], 'Expiring');
ck('state expired', exception_state(['status' => 'Active', 'period_end' => date('Y-m-d', strtotime('-1 day'))])[0], 'Expired');
ck('state pending', exception_state(['status' => 'Pending', 'period_end' => date('Y-m-d')])[0], 'Pending approval');
ck('state revoked', exception_state(['status' => 'Revoked', 'period_end' => date('Y-m-d')])[0], 'Revoked');

// A renewal is an approval: it needs future time, a reason and a live record.
$pastExc  = date('Y-m-d', strtotime('-5 days'));
$soonExc  = date('Y-m-d', strtotime('+5 days'));
$laterExc = date('Y-m-d', strtotime('+400 days'));
$live     = ['status' => 'Active', 'period_end' => $soonExc];
ck('renew refuses a revoked exception',
    exception_renew_error(['status' => 'Revoked', 'period_end' => $soonExc], $laterExc, 'why') !== null, true);
ck('renew refuses a missing end date', exception_renew_error($live, null, 'why') !== null, true);
ck('renew refuses a past end date', exception_renew_error($live, $pastExc, 'why') !== null, true);
ck('renew refuses an unchanged end date', exception_renew_error($live, $soonExc, 'why') !== null, true);
ck('renew needs a reason', exception_renew_error($live, $laterExc, '   ') !== null, true);
ck('renew accepts a future end with a reason', exception_renew_error($live, $laterExc, 'vendor slip'), null);
ck('an expired exception can still be renewed',
    exception_renew_error(['status' => 'Active', 'period_end' => $pastExc], $laterExc, 'renew it'), null);

/* ------------------------------------------------------------- templates */
ck('placeholders unique', placeholders_of('{{A}} {{B}} {{A}}'), ['A' => 'A', 'B' => 'B']);
ck('fill_text', fill_text('Hi {{NAME}}', ['NAME' => 'Sam']), 'Hi Sam');
ck('fill_text missing', fill_text('Hi {{NAME}}', []), 'Hi [NAME]');
ck('placeholder label', placeholder_label('PARTY_A'), 'Party A');

$blocks = parse_template_body("# Title\n- bullet\n1. one\n| **H** | **H2** |\n| v | v2 |");
ck('parse title', $blocks[0]['t'] ?? '', 'h1');
ck('parse table head', $blocks[count($blocks) - 1]['head'] ?? false, true);

/* ------------------------------------------------------- report filling */
$issue = [
    'code' => 'SEC-9', 'title' => 'T', 'category' => 'IT Security', 'tags' => 'a, b',
    'severity' => 'High', 'status' => 'Open', 'likelihood' => 4, 'impact' => 5,
    'risk_score' => 20, 'description' => 'D', 'root_cause' => 'R',
    'immediate_action' => 'I', 'corrective_action' => 'C', 'reported_by' => 'Me',
    'reported_at' => '2026-01-02', 'due_at' => null, 'reviewed_by' => 'Rev',
    'approved_by' => 'App', 'owner' => 'O', 'location' => 'L',
    'department' => 'Dep', 'asset' => 'A',
];
$fill = issue_report_fill($issue, ['suspects' => [1, 2], 'files' => [], 'tasks' => []]);
ck('fill report no', $fill['REPORT_NO'], 'SEC-9');
ck('fill rating', $fill['RISK_RATING'], 'Critical');
ck('fill suspect count', $fill['SUSPECT_COUNT'], '2');
ck('fill tags', $fill['TAGS'], 'a, b');

/* --------------------------------------------------------------- uploads */
ck('upload limit sane', upload_limit_bytes() > 0 && upload_limit_bytes() <= UPLOAD_MAX_BYTES, true);
ck('human size', human_size(1048576), '1 MB');

// A multi-file field arrives as parallel arrays; the batch helper turns that
// into one entry per selected file and drops the slots the browser left empty.
ck('upload batch: one file in the array shape', upload_batch([
    'name' => ['a.png'], 'type' => ['image/png'], 'tmp_name' => ['/tmp/pa'],
    'error' => [UPLOAD_ERR_OK], 'size' => [11],
]), [['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => '/tmp/pa', 'error' => UPLOAD_ERR_OK, 'size' => 11]]);
ck('upload batch: two files, one empty slot', array_column(upload_batch([
    'name' => ['a.png', '', 'b.txt'], 'type' => ['image/png', '', 'text/plain'],
    'tmp_name' => ['/tmp/pa', '', '/tmp/pb'], 'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK],
    'size' => [11, 0, 22],
]), 'name'), ['a.png', 'b.txt']);
ck('upload batch: nothing chosen', upload_batch([
    'name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0,
]), []);
ck('upload batch: oversized file kept for store_upload to refuse', upload_batch([
    'name' => ['big.zip'], 'type' => ['application/zip'], 'tmp_name' => ['/tmp/pz'],
    'error' => [UPLOAD_ERR_INI_SIZE], 'size' => [0],
])[0]['error'], UPLOAD_ERR_INI_SIZE);

// Evidence filenames go into a Content-Disposition header, so the helper the
// Word exports use must leave nothing that can break out of the quoted value.
ck('safe filename keeps an ordinary name', safe_filename('Gate log 2026.pdf'), 'Gate_log_2026.pdf');
ck('safe filename removes quotes and newlines', safe_filename("a\"b\r\nc.pdf"), 'a_b__c.pdf');
ck('safe filename ignores a path', safe_filename('../../etc/passwd'), 'passwd');
ck('safe filename refuses a leading dot', safe_filename('.htaccess'), 'htaccess');
ck('safe filename falls back when nothing survives', safe_filename('...', 'evidence'), 'evidence');

// The backup manifest lists uploads/ file by file; the inventory is the one
// function both the manifest and --check use.
$upTmp = sys_get_temp_dir() . '/secops-uploads-' . bin2hex(random_bytes(4));
mkdir($upTmp);
mkdir($upTmp . '/sub');
file_put_contents($upTmp . '/a.txt', 'abc');
file_put_contents($upTmp . '/sub/b.bin', 'def');
file_put_contents($upTmp . '/.htaccess', "Require all denied\n");
$upInv = uploads_inventory($upTmp);
ck('inventory lists every evidence file', array_keys($upInv), ['a.txt', 'sub/b.bin']);
ck('inventory hashes the bytes', $upInv['a.txt']['sha256'], hash('sha256', 'abc'));
ck('inventory skips the htaccess guard', isset($upInv['.htaccess']), false);
ck('inventory totals the sizes', array_sum(array_column($upInv, 'bytes')), 6);
unlink($upTmp . '/a.txt');
unlink($upTmp . '/sub/b.bin');
unlink($upTmp . '/.htaccess');
rmdir($upTmp . '/sub');
rmdir($upTmp);

/* ------------------------------------------------------------ audit trail */
ck('audit label', audit_action_label('issue.create'), 'Issue created');
ck('audit label falls back', audit_action_label('nope'), 'nope');
ck('audit tone', audit_action_tone('access.denied'), 'red');
ck('audit tone default', audit_action_tone('nope'), 'neutral');
ck('audit entity label', audit_entity_label('exception_category'), 'Exception category');
ck('audit record url', audit_record_url(['entity' => 'issue', 'entity_id' => 7]), '?page=issue&id=7');
ck('audit record url none', audit_record_url(['entity' => 'session', 'entity_id' => null]), '');

$af = audit_filters(['q' => '  SEC-1 ', 'action' => 'issue.create', 'entity' => 'issue', 'actor' => 'R. H', 'from' => '2026-01-02', 'to' => 'junk']);
ck('audit filters q', $af['q'], 'SEC-1');
ck('audit filters action', $af['action'], 'issue.create');
ck('audit filters entity', $af['entity'], 'issue');
ck('audit filters date', $af['from'], '2026-01-02');
ck('audit filters junk date', $af['to'], '');
ck('audit filters unknown action', audit_filters(['action' => 'rm -rf'])['action'], '');

ck('audit where none', audit_where(audit_filters([]))[0], '');
[, $wargs] = audit_where(audit_filters(['q' => 'x', 'actor' => 'sam', 'action' => 'auth.login']));
ck('audit where args', $wargs, ['%x%', '%x%', '%x%', 'auth.login', '%sam%']);
ck('audit without pdo is silent', (static function (): bool {
    unset($GLOBALS['audit_pdo']);
    audit('issue.note');
    return true;
})(), true);

/* -------------------------------------------------------- audit chain */

// Rows shaped like the ones pdo_mysql returns (every column a string), so the
// verifier is exercised against the same bytes the database hands it.
$mkAudit = static function (int $id, string $detail, ?string $entityId = '5'): array {
    return [
        'id' => (string) $id, 'created_at' => '2026-01-02 03:04:05',
        'actor_id' => '1', 'actor' => 'R. Hartono', 'role' => 'admin',
        'action' => 'issue.update', 'entity' => 'issue', 'entity_id' => $entityId,
        'label' => 'SEC-1004', 'detail' => $detail, 'ip' => '10.20.4.20',
        'agent' => 'Mozilla/5.0', 'prev_hash' => '', 'row_hash' => '',
    ];
};

$chainRows = static function (array $rows) use ($mkAudit): array {
    $prev = '';
    $out  = [];
    foreach ($rows as $id => $detail) {
        $row = $mkAudit((int) $id, $detail);
        $row['prev_hash'] = $prev;
        $row['row_hash']  = audit_row_hash($row, $prev);
        $prev = $row['row_hash'];
        $out[] = $row;
    }
    return $out;
};

$h = audit_row_hash($mkAudit(1, 'a'), '');
ck('chain hash is sha-256 hex', strlen($h) . ':' . (ctype_xdigit($h) ? 'hex' : 'no'), '64:hex');
ck('chain hash is deterministic', audit_row_hash($mkAudit(1, 'a'), ''), $h);
ck('chain hash binds the predecessor', audit_row_hash($mkAudit(1, 'a'), 'x') !== $h, true);
ck('chain hash binds the detail', audit_row_hash($mkAudit(1, 'b'), '') !== $h, true);
ck('chain hash binds the timestamp', audit_row_hash(['created_at' => '2026-01-02 03:04:06'] + $mkAudit(1, 'a'), '') !== $h, true);
ck('chain hash keeps null and empty apart', audit_row_hash($mkAudit(1, 'a', null), '') !== audit_row_hash($mkAudit(1, 'a', ''), ''), true);
ck('chain hash cannot be spliced', audit_hash_payload(['label' => "a\x1fb", 'detail' => ''], '') === audit_hash_payload(['label' => 'a', 'detail' => 'b'], ''), false);

$chain = $chainRows([1 => 'a', 2 => 'b', 3 => 'c']);
$whole = audit_chain_check($chain);
ck('chain intact', $whole['ok'], true);
ck('chain walked every row', $whole['checked'], 3);
ck('chain tail is the last hash', $whole['tail'], $chain[2]['row_hash']);

$tampered = $chain;
$tampered[1]['detail'] = 'edited in MySQL';
ck('chain detects a changed row', audit_chain_check($tampered)['broken'], ['id' => 2, 'reason' => 'content']);

$gapped = $chain;
unset($gapped[1]);
ck('chain detects a deleted row', audit_chain_check(array_values($gapped))['broken'], ['id' => 3, 'reason' => 'link']);

$backdoor = $chain;
$backdoor[1]['row_hash'] = '';
ck('chain detects an unsealed row', audit_chain_check($backdoor)['broken'], ['id' => 2, 'reason' => 'missing']);

ck('empty chain is intact', audit_chain_check([])['ok'], true);

$first  = audit_chain_check(array_slice($chain, 0, 2));
$second = audit_chain_check(array_slice($chain, 2), $first['tail']);
ck('chain check continues across chunks', [$first['ok'], $second['ok'], $second['head']], [true, true, 3]);

/* ------------------------------------------------- external audit anchor */
$anchorRow = $chain[count($chain) - 1];
$anchor    = audit_anchor_parse(audit_anchor_line($anchorRow, 3, '2026-11-22T09:00:00+00:00'));
ck('anchor line round-trips', [$anchor['id'], $anchor['hash'], $anchor['checked']], [3, $anchorRow['row_hash'], 3]);
ck('anchor parse rejects junk', audit_anchor_parse('2026-11-22 not an anchor'), null);
ck('anchor parse rejects a short hash', audit_anchor_parse("2026-11-22T09:00:00+00:00\t3\tshort\t3"), null);

ck('anchor verdict: nothing anchored', audit_anchor_verdict(null, null, 0)['reason'], 'none');
ck('anchor verdict: intact', audit_anchor_verdict($anchor, ['row_hash' => $anchorRow['row_hash']], 3)['ok'], true);
ck('anchor verdict: anchored row deleted', audit_anchor_verdict($anchor, null, 2)['reason'], 'deleted');
ck('anchor verdict: anchored row rewritten', audit_anchor_verdict($anchor, ['row_hash' => str_repeat('0', 64)], 3)['reason'], 'rewritten');
ck('anchor verdict: trail truncated', audit_anchor_verdict($anchor, ['row_hash' => $anchorRow['row_hash']], 1)['reason'], 'truncated');

$anchorFile = sys_get_temp_dir() . '/secops-anchor-' . bin2hex(random_bytes(4)) . '.log';
file_put_contents($anchorFile,
    audit_anchor_line($chain[0], 2, '2026-11-21T09:00:00+00:00') . "\n"
    . 'garbage line' . "\n"
    . audit_anchor_line($anchorRow, 3, '2026-11-22T09:00:00+00:00') . "\n");
$lastAnchor = audit_anchor_last($anchorFile);
ck('anchor reads the newest line', [$lastAnchor['id'], $lastAnchor['checked']], [3, 3]);
ck('anchor reports its file', $lastAnchor['file'], $anchorFile);
@unlink($anchorFile);
ck('anchor missing file is null', audit_anchor_last($anchorFile), null);

/* ------------------------------------------ backup records in the anchor */
$bkSha  = str_repeat('ab', 32);
$bkName = 'secops-crm-20261127-123535.json';
$bkLine = audit_backup_anchor_line($bkName, $bkSha,
    ['id' => 154, 'row_hash' => $anchorRow['row_hash']], '2026-11-27T12:35:41+00:00');
$bk = audit_backup_anchor_parse($bkLine);
ck('backup record round-trips', [$bk['id'], $bk['head'], $bk['sha256'], $bk['name']],
    [154, $anchorRow['row_hash'], $bkSha, $bkName]);
ck('backup record needs six fields', audit_backup_anchor_parse(str_replace("\t", ' ', $bkLine)), null);
ck('backup record rejects a short manifest hash', audit_backup_anchor_parse(
    "backup\t2026-11-27T12:35:41+00:00\t154\t{" . $anchorRow['row_hash'] . "}\tbeef\t" . $bkName), null);
ck('backup record is not a chain anchor', audit_anchor_parse($bkLine), null);

$bkFile = sys_get_temp_dir() . '/secops-bk-anchor-' . bin2hex(random_bytes(4)) . '.log';
file_put_contents($bkFile,
    audit_anchor_line($chain[0], 2, '2026-11-21T09:00:00+00:00') . "\n"
    . audit_backup_anchor_line('a.json', str_repeat('0', 64), [], '2026-11-26T02:00:00+00:00') . "\n"
    . audit_backup_anchor_line('b.json', $bkSha, [], '2026-11-27T02:00:00+00:00') . "\n"
    . audit_backup_anchor_line('a.json', str_repeat('1', 64), [], '2026-11-27T03:00:00+00:00') . "\n");
ck('backup record finds the newest line for a name',
    audit_backup_anchor_find('a.json', $bkFile)['sha256'], str_repeat('1', 64));
ck('backup record reads a second name', audit_backup_anchor_find('b.json', $bkFile)['sha256'], $bkSha);
ck('backup record: unknown name', audit_backup_anchor_find('c.json', $bkFile), null);
ck('backup record matches on the bare file name',
    audit_backup_anchor_find('/tmp/a.json', $bkFile)['name'], 'a.json');
ck('chain anchor ignores backup records', audit_anchor_last($bkFile)['id'], (int) $chain[0]['id']);
@unlink($bkFile);
ck('backup record: missing file', audit_backup_anchor_find('a.json', $bkFile), null);

/* ------------------------------------------ shared chain / anchor wording */
ck('chain state: intact', audit_chain_state($whole)['state'], 'ok');
ck('chain state: empty', audit_chain_state(['ok' => true, 'checked' => 0])['state'], 'empty');
ck('chain state: broken names the entry',
    audit_chain_state(audit_chain_check($tampered))['label'], 'first broken link at #2');
ck('break reason: link', audit_broken_reason('link'), 'no longer links to the entry before it');
ck('break reason: unknown', audit_broken_reason('nope'), 'is in an unexpected state');
ck('anchor state: intact', audit_anchor_state($whole + ['reason' => 'ok', 'id' => 3, 'anchor' => $anchor])['state'], 'ok');
ck('anchor state: none', audit_anchor_state(['reason' => 'none'])['state'], 'none');
ck('anchor state: broken names the entry',
    audit_anchor_state(['ok' => false, 'reason' => 'deleted', 'id' => 3])['label'], 'BROKEN at entry #3');
ck('anchor reason: truncated', audit_anchor_reason('truncated'), 'the trail is shorter than the last anchor');
ck('chain scope: whole trail', audit_chain_scope(['checked' => 3, 'head' => 1, 'newest' => 3], 3),
    'all 3 entries (ids 1–3)');
ck('chain scope: newest only', audit_chain_scope(['checked' => 2, 'head' => 2, 'newest' => 3], 9),
    'the newest 2 of 9 entries (ids 2–3)');
ck('chain scope: nothing yet', audit_chain_scope(['checked' => 0], 0), 'nothing recorded yet');
ck('chain scope: a broken walk says how much it read',
    audit_chain_scope(['ok' => false, 'checked' => 4, 'head' => 1, 'newest' => 5,
                       'broken' => ['id' => 2, 'reason' => 'content']], 4),
    '4 entries read, newest id 5');

// The sentences a break or an anchor produces live in lib/audit.php only; a
// view or the verifier that restates them drifts the first time one changes.
$readerSrc = (string) file_get_contents(__DIR__ . '/verify-audit.php')
    . (string) file_get_contents(__DIR__ . '/../views/audit.php')
    . (string) file_get_contents(__DIR__ . '/../views/health.php');
ck('one copy of the break reasons', substr_count($readerSrc, 'no longer links to the entry before it'), 0);
ck('one copy of the anchor reasons', substr_count($readerSrc, 'the trail is shorter than'), 0);
ck('the self-check bounds its walk', str_contains($readerSrc, 'audit_verify_tail'), true);

/* --------------------------------------------------------- notifications */
ck('kind label', notification_label('issue_assigned'), 'Assigned to you');
ck('kind tone', notification_tone('issue_critical'), 'red');
ck('kind fallback', notification_label('nope'), 'nope');
ck('kind catalogue size', count(notification_kinds()), 6);
ck('when now', notification_when(date('Y-m-d H:i:s')), 'just now');
ck('when hours', notification_when(date('Y-m-d H:i:s', time() - 7200)), '2h ago');
ck('when days', notification_when(date('Y-m-d H:i:s', time() - 3 * 86400)), '3d ago');
ck('when null', notification_when(null), '—');
ck('filters default unread', notify_filters([])['state'], 'unread');
ck('filters all', notify_filters(['state' => 'all'])['state'], 'all');
ck('filters junk kind', notify_filters(['kind' => '<script>'])['kind'], '');
ck('notify without pdo is silent', (static function (): bool {
    unset($GLOBALS['notify_pdo']);
    notify(1, 'issue_due', 'x');
    return true;
})(), true);

/* ------------------------------------------- router ↔ permission wiring */

// The router and its guard tables live in index.php/auth.php, so read them as
// source: every POST case must map to a permission and every routed page must
// have a page permission, or the action silently falls back/401s.
$routerSrc = (string) file_get_contents(__DIR__ . '/../index.php');
$authSrc   = (string) file_get_contents(__DIR__ . '/../lib/auth.php');
$catalogue = array_keys(permission_list());

preg_match_all("/case '([a-z_]+)':/", $routerSrc, $m);
$cases = array_values(array_unique($m[1]));

preg_match('/function action_permission.*?static \$map = \[(.*?)\];/s', $routerSrc, $m);
preg_match_all("/'([a-z_]+)'\s*=>\s*'([a-z_.]+)'/", $m[1], $mm, PREG_SET_ORDER);
$actMap = [];
foreach ($mm as $row) {
    $actMap[$row[1]] = $row[2];
}

ck('every POST action has a permission', array_values(array_diff($cases, array_keys($actMap))), []);
ck('no action permission without a POST case', array_values(array_diff(array_keys($actMap), $cases)), []);
ck('action permissions exist in the catalogue', array_values(array_diff(array_unique(array_values($actMap)), $catalogue)), []);

preg_match('/\$pages = \[(.*?)\];/s', $routerSrc, $m);
preg_match_all("/'([a-z_]+)'/", $m[1], $mm);
$pages = $mm[1];

preg_match('/function page_permission.*?static \$map = \[(.*?)\];/s', $authSrc, $m);
preg_match_all("/'([a-z_]+)'\s*=>\s*'([a-z_.]+)'/", $m[1], $mm, PREG_SET_ORDER);
$pageMap = [];
foreach ($mm as $row) {
    $pageMap[$row[1]] = $row[2];
}

// login is guarded before the page map; download checks file.download itself.
$guardless = array_values(array_diff($pages, array_keys($pageMap)));
sort($guardless);
ck('routed pages without a page permission', $guardless, ['download', 'login']);
ck('page permissions for unrouted pages', array_values(array_diff(array_keys($pageMap), $pages)), []);
ck('page permissions exist in the catalogue', array_values(array_diff(array_unique(array_values($pageMap)), $catalogue)), []);

// Guard the parsers themselves: an empty extraction would make the checks
// above pass without testing anything.
ck('wiring check sees the POST actions', count($cases) > 10, true);
ck('wiring check sees the routed pages', count($pages) > 8, true);
ck('wiring check sees the permission catalogue', count($catalogue) > 10, true);

$badGrants = [];
foreach (role_list() as $role => $def) {
    foreach ($def['perms'] as $perm) {
        if ($perm !== '*' && !in_array($perm, $catalogue, true)) {
            $badGrants[] = $role . ':' . $perm;
        }
    }
}
ck('role grants exist in the catalogue', $badGrants, []);

/* -------------------------------------- persons involved + evidence gate */
ck('persons read permission exists', isset(permission_list()['issue.person.read']), true);
foreach (['admin', 'manager', 'analyst'] as $role) {
    ck($role . ' may read persons', in_array('issue.person.read', role_list()[$role]['perms'], true) || in_array('*', role_list()[$role]['perms'], true), true);
    ck($role . ' may download evidence', in_array('file.download', role_list()[$role]['perms'], true) || in_array('*', role_list()[$role]['perms'], true), true);
}
ck('document control permission exists', isset(permission_list()['sop.manage']), true);
ck('manager may manage SOPs', in_array('sop.manage', role_list()['manager']['perms'], true), true);
ck('analyst may not manage SOPs', in_array('sop.manage', role_list()['analyst']['perms'], true), false);
ck('viewer may not manage SOPs', in_array('sop.manage', role_list()['viewer']['perms'], true), false);
ck('viewer may not read persons', in_array('issue.person.read', role_list()['viewer']['perms'], true), false);
ck('viewer may not download evidence', in_array('file.download', role_list()['viewer']['perms'], true), false);

/* ------------------------------------------------- document control fields */
[$row, $err] = sop_fields([
    'code' => 'phys-004', 'title' => '  Gate Log  ', 'type' => 'SOP', 'scope' => 'Physical',
    'status' => 'Active', 'effective_at' => '2026-01-01', 'review_at' => '2027-01-01',
]);
ck('sop fields accept a valid document', $err, '');
ck('sop fields upper-case the code', $row['code'], 'PHYS-004');
ck('sop fields trim the title', $row['title'], 'Gate Log');
ck('sop fields keep the dates', [$row['effective_at'], $row['review_at']], ['2026-01-01', '2027-01-01']);
ck('sop fields default the version', $row['version'], '1.0');

ck('sop fields reject a blank title', sop_fields(['title' => '   '])[1] !== '', true);
ck('sop fields reject a code with a space', sop_fields(['title' => 'x', 'code' => 'ISMS 011'])[1] !== '', true);
ck('sop fields reject a code over 20 chars', sop_fields(['title' => 'x', 'code' => str_repeat('A', 21)])[1] !== '', true);
ck('sop fields accept a blank code on create', sop_fields(['title' => 'x'])[1], '');
ck('sop fields require a code on edit', sop_fields(['title' => 'x'], true)[1] !== '', true);
ck('sop fields reject review before effective', sop_fields(['title' => 'x', 'effective_at' => '2026-05-01', 'review_at' => '2026-04-01'])[1] !== '', true);
ck('sop fields reject Active without a date', sop_fields(['title' => 'x', 'status' => 'Active'])[1] !== '', true);
ck('sop fields reject oversized text', sop_fields(['title' => 'x', 'content' => str_repeat('a', SOP_CONTENT_MAX + 1)])[1] !== '', true);
ck('sop fields fall back to the SOP type', sop_fields(['title' => 'x', 'type' => 'Bogus'])[0]['type'], 'SOP');
ck('sop code prefixes follow the register',
    [sop_code_prefix('Physical'), sop_code_prefix('IT'), sop_code_prefix('Strain'), sop_code_prefix('General')],
    ['PHYS', 'IT', 'STRAIN', 'GEN']);

/* --------------------------------------- document history + acknowledgement */

ck('revision snapshots every controlled field', array_keys(sop_revision_fields()),
    ['code', 'title', 'type', 'scope', 'version', 'owner', 'status', 'effective_at', 'review_at', 'summary', 'content']);

$revBefore = ['code' => 'IT-001', 'title' => 'A', 'status' => 'Draft', 'content' => 'x', 'effective_at' => null];
$revAfter  = ['code' => 'IT-001', 'title' => 'A', 'status' => 'Active', 'content' => 'x', 'effective_at' => '2026-01-01'];
ck('revision diff names the fields that changed', sop_revision_diff($revBefore, $revAfter), ['Status', 'Effective date']);
ck('revision diff of an identical row is empty', sop_revision_diff($revBefore, $revBefore), []);
ck('revision diff treats null and empty alike', sop_revision_diff(['summary' => null], ['summary' => '']), []);
ck('revision diff sees a changed body', sop_revision_diff(['content' => 'a'], ['content' => 'b']), ['Controlled text']);
ck('change reason is trimmed', sop_change_reason(['change_reason' => '  Annual review  ']), 'Annual review');
ck('change reason is clamped', strlen(sop_change_reason(['change_reason' => str_repeat('x', 400)])), SOP_CHANGE_REASON_MAX);
ck('change reason defaults to empty', sop_change_reason([]), '');

$ackRows = [
    ['user_id' => '1', 'revision_id' => '5', 'acknowledged_at' => '2026-11-01 09:00:00'],
    ['user_id' => '2', 'revision_id' => '4', 'acknowledged_at' => '2026-10-01 09:00:00'],
];
ck('ack state: reader at the head revision', sop_ack_state($ackRows, 1, 5)['acked'], true);
ck('ack state: reader of an older revision is outstanding again', sop_ack_state($ackRows, 2, 5)['acked'], false);
ck('ack state: never read', sop_ack_state($ackRows, 3, 5)['acked'], false);
ck('ack state keeps the reading date', sop_ack_state($ackRows, 1, 5)['at'], '2026-11-01 09:00:00');
ck('ack state without a revision', sop_ack_state([], 1, 0)['acked'], false);
ck('ack coverage counts active accounts at the head revision', sop_ack_coverage($ackRows, [1, 2, 3], 5),
    ['read' => 1, 'total' => 3, 'outstanding' => [2, 3]]);
ck('ack coverage ignores a disabled account', sop_ack_coverage($ackRows, [2], 5)['read'], 0);
ck('ack coverage with nobody active', sop_ack_coverage($ackRows, [], 5), ['read' => 0, 'total' => 0, 'outstanding' => []]);
ck('ack coverage with everyone read', sop_ack_coverage($ackRows, [1], 5)['outstanding'], []);

ck('acknowledge permission exists', isset(permission_list()['sop.acknowledge']), true);
foreach (['admin', 'manager', 'analyst', 'viewer'] as $role) {
    ck($role . ' may acknowledge a policy', in_array('sop.acknowledge', role_list()[$role]['perms'], true) || in_array('*', role_list()[$role]['perms'], true), true);
}
ck('acknowledging does not imply document control', in_array('sop.manage', role_list()['viewer']['perms'], true), false);
ck('policy acknowledgement is a labelled audit event', audit_action_label('sop.acknowledge'), 'Policy acknowledged');

// The EDLP register's column is source_system; the old name must not linger in
// the schema, the seed, the create action or the view.
ck('schema names the EDLP source-system column',
    str_contains((string) file_get_contents(__DIR__ . '/../schema.sql'), '`source_system`'), true);
$excSrc = $routerSrc . (string) file_get_contents(__DIR__ . '/../views/exceptions.php')
    . (string) file_get_contents(__DIR__ . '/../lib/db.php');
ck('no code reads or seeds the old exceptions system column',
    str_contains($excSrc, "['system']") || str_contains($excSrc, '(system,'),
    false);

// Renewal and control verification are oversight acts, held by managers and
// administrators only; analysts keep day-to-day editing.
ck('renewal permission exists', isset(permission_list()['exception.renew']), true);
ck('manager may approve a renewal', in_array('exception.renew', role_list()['manager']['perms'], true), true);
ck('analyst may not approve a renewal', in_array('exception.renew', role_list()['analyst']['perms'], true), false);
ck('viewer may not approve a renewal', in_array('exception.renew', role_list()['viewer']['perms'], true), false);
ck('analyst keeps day-to-day exception editing', in_array('exception.manage', role_list()['analyst']['perms'], true), true);
ck('renewal is a labelled audit event', audit_action_label('exception.renew'), 'Exception renewed');
ck('control verification is a labelled audit event', audit_action_label('exception.control.verify'), 'Control verified');
$schemaSql = (string) file_get_contents(__DIR__ . '/../schema.sql');
ck('schema keeps the renewal approver', str_contains($schemaSql, '`renewed_by`'), true);
ck('schema keeps the control verification', str_contains($schemaSql, '`control_verified_at`'), true);
ck('the edit action keeps the stored period end',
    str_contains($routerSrc, "period_end'] !== null"), true);

/* ------------------------------------------------ OT vulnerability register */

$otPast = date('Y-m-d', strtotime('-4 days'));
ck('OT state open', ot_state(['status' => 'Open', 'due_at' => date('Y-m-d', strtotime('+20 days'))])[0], 'Open');
ck('OT state mitigating', ot_state(['status' => 'Mitigating', 'due_at' => date('Y-m-d')])[0], 'Mitigating');
ck('OT state overdue', ot_state(['status' => 'Open', 'due_at' => $otPast])[0], 'Overdue');
ck('OT state accepted', ot_state(['status' => 'Accepted', 'due_at' => null])[0], 'Risk accepted');
ck('OT state resolved', ot_state(['status' => 'Resolved', 'due_at' => $otPast])[0], 'Resolved');
ck('OT days left counts to the due date', ot_days_left(date('Y-m-d', strtotime('+9 days'))), 9);
ck('OT days left without a due date', ot_days_left(null), null);

ck('OT catalogue offers a direct-input fallback', in_array('Other', ot_category_names(), true), true);
ck('OT catalogue names weakness types, not asset classes', in_array('PLC / RTU', ot_category_names(), true), false);
ck('OT catalogue keeps an unknown value neutral', ot_category_tone('Unsafe robot teach pendant'), 'neutral');

$otBase = [
    'site' => '  Plant 1 · Line 3 ', 'category' => 'Unauthenticated / unencrypted protocol',
    'asset' => 'PLC-1 · 10.0.0.5', 'severity' => 'Critical', 'cvss_score' => '9.84',
    'advisory_ref' => 'ICSA-24-102-07', 'risk_control' => 'OT firewall blocks 502',
    'pc_risk_management' => 'Not managed', 'status' => 'Mitigating', 'owner' => 'D. Kusuma',
    'recent_contact' => '  OEM service desk  ',
    'identified_at' => '2026-01-10', 'due_at' => '2026-03-01',
];
$otRow = ot_fields($otBase);
ck('OT fields trim the business location', $otRow['site'], 'Plant 1 · Line 3');
ck('OT fields keep one CVSS decimal', $otRow['cvss_score'], '9.8');
ck('OT fields keep the chosen status', $otRow['status'], 'Mitigating');
ck('OT fields keep the PC risk level', $otRow['pc_risk_management'], 'Not managed');
ck('OT fields trim the recent contact', $otRow['recent_contact'], 'OEM service desk');
ck('OT fields derive the title from the vulnerability and target',
    $otRow['title'], 'Unauthenticated / unencrypted protocol — PLC-1 · 10.0.0.5');
ck('OT fields accept a complete finding', ot_fields_error($otRow), null);

ck('OT fields accept a typed vulnerability',
    ot_fields(['category' => OT_OTHER, 'category_other' => '  Unsafe robot teach pendant  '] + $otBase)['category'],
    'Unsafe robot teach pendant');
ck('OT fields accept a typed business location',
    ot_fields(['site' => OT_OTHER, 'site_other' => '  Plant 9 · Line 9 '] + $otBase)['site'], 'Plant 9 · Line 9');
ck('OT fields ignore the typed box when a catalogue value is picked',
    ot_fields(['category' => 'End-of-life OS / firmware', 'category_other' => 'ignored'] + $otBase)['category'],
    'End-of-life OS / firmware');
ck('OT fields keep the stored category on a tampered edit',
    ot_fields(['category' => 'Bogus'], ['category' => 'Other'])['category'], 'Other');
ck('OT fields keep the stored location on an empty edit', ot_fields([], ['site' => 'Plant 2'])['site'], 'Plant 2');
ck('OT fields clamp CVSS to 10', ot_fields(['cvss_score' => '12'] + $otBase)['cvss_score'], null);
ck('OT fields reject a non-numeric CVSS', ot_fields(['cvss_score' => 'high'] + $otBase)['cvss_score'], null);
ck('OT fields fall back on an unknown status', ot_fields(['status' => 'Closed'] + $otBase)['status'], 'Open');
ck('OT fields fall back on an unknown severity', ot_fields(['severity' => 'Urgent'] + $otBase)['severity'], 'Medium');
ck('OT fields keep the stored status on an edit', ot_fields(['status' => 'Closed'], ['status' => 'Accepted'])['status'], 'Accepted');
ck('OT fields default the report confirmation date to today',
    ot_fields(['identified_at' => 'junk'] + $otBase)['identified_at'], date('Y-m-d'));
ck('OT fields reject a blank business location',
    ot_fields_error(ot_fields(['site' => '  '] + $otBase)), 'Choose or enter the business location.');
ck('OT fields reject a blank vulnerability',
    ot_fields_error(ot_fields(['category' => '', 'site' => 'Plant 1'])), 'Choose or enter the vulnerability type.');
ck('OT fields reject a due date before the report date',
    ot_fields_error(ot_fields(['identified_at' => '2026-05-01', 'due_at' => '2026-04-01'] + $otBase)) !== null, true);
ck('OT fields accept no due date', ot_fields_error(ot_fields(['due_at' => ''] + $otBase)), null);

ck('OT register permission exists', isset(permission_list()['page.ot']), true);
ck('OT manage permission exists', isset(permission_list()['ot.manage']), true);
foreach (['manager', 'analyst'] as $role) {
    ck($role . ' may read the OT register', in_array('page.ot', role_list()[$role]['perms'], true), true);
    ck($role . ' may record OT findings', in_array('ot.manage', role_list()[$role]['perms'], true), true);
}
ck('viewer may read the OT register', in_array('page.ot', role_list()['viewer']['perms'], true), true);
ck('viewer may not record OT findings', in_array('ot.manage', role_list()['viewer']['perms'], true), false);
ck('OT finding creation is a labelled audit event', audit_action_label('otv.create'), 'OT finding added');
ck('OT finding update is a labelled audit event', audit_action_label('otv.update'), 'OT finding updated');
ck('the schema ships the OT register', str_contains($schemaSql, '`ot_vulnerabilities`'), true);
ck('the schema keeps the PC risk assessment', str_contains($schemaSql, '`pc_risk_management`'), true);
ck('the schema keeps the recent contact', str_contains($schemaSql, '`recent_contact`'), true);
ck('the OT audit entity links to the register', audit_record_url(['entity' => 'ot_vulnerability', 'entity_id' => 3]), '?page=ot');

/* -------------------------------------------------------------- client IP */

ck('cidr /8 matches', ip_matches_cidr('10.120.10.10', '10.0.0.0/8'), true);
ck('cidr /8 rejects', ip_matches_cidr('192.168.1.5', '10.0.0.0/8'), false);
ck('cidr bare address', ip_matches_cidr('10.120.10.10', '10.120.10.10'), true);
ck('cidr /25 upper bound', ip_matches_cidr('10.0.0.127', '10.0.0.0/25'), true);
ck('cidr /25 just outside', ip_matches_cidr('10.0.0.128', '10.0.0.0/25'), false);
ck('cidr v6 loopback', ip_matches_cidr('::1', '::1/128'), true);
ck('cidr mixed family', ip_matches_cidr('10.0.0.1', '::1/128'), false);
ck('normalize strips port', normalize_ip('10.120.10.10:8080'), '10.120.10.10');
ck('normalize unmaps v4-in-v6', normalize_ip('::ffff:10.120.10.10'), '10.120.10.10');
ck('mapped address matches v4 cidr', ip_matches_cidr('::ffff:10.120.10.10', '10.0.0.0/8'), true);

$savedServer = $_SERVER;
$GLOBALS['secops_config'] = ['trusted_proxies' => ['127.0.0.1/32', '10.120.10.5']];

$_SERVER = ['REMOTE_ADDR' => '10.120.10.10'];
ck('client ip direct on the LAN', client_ip(), '10.120.10.10');

$_SERVER = ['REMOTE_ADDR' => '10.120.10.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'];
ck('client ip ignores forwarding from an untrusted peer', client_ip(), '10.120.10.10');

$_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.120.10.5'];
ck('client ip behind a trusted proxy', client_ip(), '203.0.113.9');

$_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'junk, 10.120.10.9'];
ck('client ip skips junk hops', client_ip(), '10.120.10.9');

$_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '10.120.10.11'];
ck('client ip from x-real-ip', client_ip(), '10.120.10.11');

$_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
ck('client ip falls back to the peer', client_ip(), '127.0.0.1');

$_SERVER = $savedServer;
unset($GLOBALS['secops_config']);

/* ---------------------------------------------------------------- URL paths */

// The dev router must deny the files that would expose the installation: the
// database dumps in backups/, CLI tools, dotfiles and archives. These cases are
// the regression test for the exposure fixed on 2026-11-27.
$deniedPaths = [
    '/.htaccess', '/.git/config', '/.env',
    '/assets/../.htaccess', '/assets/%2e%2e/.htaccess',
    '/backups/', '/backups/secops-crm-20260101-000000.sql.gz',
    '/tools/backup.php', '/logs/app.log', '/uploads/evidence.pdf',
    '/lib/db.php', '/tests/run.php', '/router.php',
    '/schema.sql', '/README.md', '/help.md',
    '/dump.zip', '/dump.tar', '/dump.sql.gz', '/crm.sqlite', '/crm.db',
    '/config.php.bak', '/notes.md', '/x.swp', "/x\0.txt",
];
foreach ($deniedPaths as $denied) {
    ck('router denies ' . str_replace("\0", '\\0', $denied), router_denied_path($denied), true);
}

foreach (['/assets/app.css', '/assets/app.js', '/sw.js', '/offline.html'] as $served) {
    ck('router serves ' . $served, router_servable_path($served), true);
}

foreach (['/', '/index.php', '/?page=dashboard', '/favicon.ico'] as $routed) {
    ck('router does not serve ' . $routed, router_servable_path($routed), false);
}
// The rule admits the path; the router then answers 404 because no such file
// exists, rather than falling through to a page of HTML.
ck('router admits a missing asset path for a 404', router_servable_path('/assets/missing.css'), true);
ck('router never serves a backup', router_servable_path('/backups/x.sql.gz'), false);
ck('router never serves a dotfile', router_servable_path('/.htaccess'), false);

/* ------------------------------------------------------- 403 back-link */

ck('403 back keeps a same-host referrer',
    local_back_target('https://secops.local/index.php?page=issues&p=2', 'secops.local'),
    '/index.php?page=issues&p=2');
ck('403 back refuses another host',
    local_back_target('https://evil.example/phish', 'secops.local'), '?page=dashboard');
ck('403 back refuses protocol-relative',
    local_back_target('//evil.example/phish', 'secops.local'), '?page=dashboard');
ck('403 back refuses a backslash host',
    local_back_target('/\\evil.example', 'secops.local'), '?page=dashboard');
ck('403 back refuses userinfo',
    local_back_target('https://secops.local@evil.example/x', 'secops.local'), '?page=dashboard');
ck('403 back refuses a different port',
    local_back_target('https://secops.local:8443/x', 'secops.local'), '?page=dashboard');
ck('403 back keeps the matching port',
    local_back_target('http://secops.local:8080/x', 'secops.local:8080'), '/x');
ck('403 back keeps a query-only referrer',
    local_back_target('?page=sop', 'secops.local'), '?page=sop');
ck('403 back refuses an empty referrer',
    local_back_target(null, 'secops.local'), '?page=dashboard');
ck('403 back matches the host case-insensitively',
    local_back_target('https://SECOPS.local/page', 'secops.local'), '/page');

echo $fail === 0
    ? "OK — {$pass} checks passed\n"
    : "FAILED — {$fail} of " . ($pass + $fail) . " checks\n";

exit($fail === 0 ? 0 : 1);
