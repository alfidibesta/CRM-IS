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

echo $fail === 0
    ? "OK — {$pass} checks passed\n"
    : "FAILED — {$fail} of " . ($pass + $fail) . " checks\n";

exit($fail === 0 ? 0 : 1);
