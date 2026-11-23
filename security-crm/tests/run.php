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

echo $fail === 0
    ? "OK — {$pass} checks passed\n"
    : "FAILED — {$fail} of " . ($pass + $fail) . " checks\n";

exit($fail === 0 ? 0 : 1);
