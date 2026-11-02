<?php
/**
 * Issue detail report: risk helpers, template binding and the Word export.
 *
 * A report can run on a form template (NDA-style markup) chosen per issue.
 * The issue's own adjusted layout wins over the template body, and the
 * placeholders are filled from the issue record itself.
 */

declare(strict_types=1);

/** 5x5 risk score -> rating label + colour tone. */
function risk_rating(int $score): array
{
    if ($score >= 16) return ['Critical', 'red'];
    if ($score >= 10) return ['High', 'orange'];
    if ($score >= 5)  return ['Moderate', 'blue'];
    return ['Low', 'green'];
}

function risk_tone(int $score): string
{
    return risk_rating($score)[1];
}

function likelihood_label(int $n): string
{
    return [1 => '1 · Rare', 2 => '2 · Unlikely', 3 => '3 · Possible', 4 => '4 · Likely', 5 => '5 · Almost certain'][$n] ?? (string) $n;
}

function impact_label(int $n): string
{
    return [1 => '1 · Negligible', 2 => '2 · Minor', 3 => '3 · Moderate', 4 => '4 · Major', 5 => '5 · Severe'][$n] ?? (string) $n;
}

/**
 * Placeholder values filled into the report form for one issue.
 * These are the {{KEYS}} an author can use while adjusting the layout.
 */
function issue_report_fill(array $issue, array $ctx = []): array
{
    $score    = (int) ($issue['risk_score'] ?? 0);
    [$rating] = risk_rating($score);

    return [
        'REPORT_NO'         => (string) ($issue['code'] ?? ''),
        'CODE'              => (string) ($issue['code'] ?? ''),
        'TITLE'             => (string) ($issue['title'] ?? ''),
        'CATEGORY'          => (string) ($issue['category'] ?? ''),
        'TAGS'              => (string) ($issue['tags'] ?? ''),
        'SEVERITY'          => (string) ($issue['severity'] ?? ''),
        'STATUS'            => (string) ($issue['status'] ?? ''),
        'OWNER'             => (string) ($issue['owner'] ?? ''),
        'LOCATION'          => (string) ($issue['location'] ?? ''),
        'DEPARTMENT'        => (string) ($issue['department'] ?? ''),
        'ASSET'             => (string) ($issue['asset'] ?? ''),
        'REPORTER'          => (string) ($issue['reported_by'] ?? ''),
        'REPORTED_BY'       => (string) ($issue['reported_by'] ?? ''),
        'REPORTED_AT'       => fmt_date($issue['reported_at'] ?? null),
        'DATETIME'          => fmt_date($issue['reported_at'] ?? null),
        'DUE_DATE'          => fmt_date($issue['due_at'] ?? null),
        'DUE_AT'            => fmt_date($issue['due_at'] ?? null),
        'CLOSED_AT'         => fmt_date($issue['closed_at'] ?? null),
        'LIKELIHOOD'        => likelihood_label((int) ($issue['likelihood'] ?? 0)),
        'IMPACT'            => impact_label((int) ($issue['impact'] ?? 0)),
        'SCORE'             => (string) $score,
        'RISK_SCORE'        => (string) $score,
        'RATING'            => $rating,
        'RISK_RATING'       => $rating,
        'DESCRIPTION'       => (string) ($issue['description'] ?? ''),
        'ROOT_CAUSE'        => (string) ($issue['root_cause'] ?? ''),
        'IMMEDIATE_ACTION'  => (string) ($issue['immediate_action'] ?? ''),
        'CORRECTIVE_ACTION' => (string) ($issue['corrective_action'] ?? ''),
        'REVIEWER'          => (string) ($issue['reviewed_by'] ?? ''),
        'REVIEWED_BY'       => (string) ($issue['reviewed_by'] ?? ''),
        'REVIEW_DATE'       => fmt_date($issue['reviewed_at'] ?? null),
        'APPROVER'          => (string) ($issue['approved_by'] ?? ''),
        'APPROVED_BY'       => (string) ($issue['approved_by'] ?? ''),
        'SUSPECT_COUNT'     => (string) count($ctx['suspects'] ?? []),
        'FILE_COUNT'        => (string) count($ctx['files'] ?? []),
        'TASK_COUNT'        => (string) count($ctx['tasks'] ?? []),
        'TODAY'             => date('d M Y'),
        'SITE'              => 'Plant 1',
    ];
}

/** The template bound to an issue, or null. */
function issue_report_template(PDO $pdo, array $issue): ?array
{
    $id = (int) ($issue['report_template_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM templates WHERE id = ?');
    $st->execute([$id]);
    $tpl = $st->fetch();
    return $tpl ?: null;
}

/** Layout used for this report: the adjusted body, else the template body. */
function issue_report_body(array $issue, ?array $tpl): string
{
    $own = trim((string) ($issue['report_body'] ?? ''));
    if ($own !== '') {
        return $own;
    }
    return trim((string) ($tpl['body'] ?? ''));
}

/**
 * Build the .docx block list for one issue.
 *
 * @param array      $issue the issues row
 * @param array      $ctx   suspects, files, tasks, events
 * @param array|null $tpl   report form template bound to the issue
 */
function issue_report_blocks(array $issue, array $ctx = [], ?array $tpl = null): array
{
    $suspects = $ctx['suspects'] ?? [];
    $files    = $ctx['files'] ?? [];
    $tasks    = $ctx['tasks'] ?? [];
    $events   = $ctx['events'] ?? [];
    $score    = (int) ($issue['risk_score'] ?? 0);
    [$rating] = risk_rating($score);

    $body    = $tpl !== null ? issue_report_body($issue, $tpl) : '';
    $hasForm = $body !== '';

    $b = [];
    $b[] = ['t' => 'title', 'text' => 'INFORMATION SECURITY ISSUE REPORT'];
    $b[] = ['t' => 'subtitle', 'text' => $issue['code'] . ' · ' . $issue['category'] . ' · Manufacturing ISMS'];
    if (trim((string) ($issue['tags'] ?? '')) !== '') {
        $b[] = ['t' => 'subtitle', 'text' => 'Tags: ' . $issue['tags']];
    }
    $b[] = ['t' => 'hr'];

    // Sections are numbered 1..n on the built-in layout; when a form template
    // carries the report itself the appended parts become annexes A..F.
    $n         = 0;
    $annex     = 0;
    $usesAnnex = $hasForm;
    $label     = function (string $name) use (&$n, &$annex, $usesAnnex): string {
        if ($usesAnnex) {
            $annex++;
            return 'Annex ' . chr(64 + $annex) . ' · ' . $name;
        }
        $n++;
        return $n . '. ' . $name;
    };

    if ($hasForm) {
        $b[] = ['t' => 'subtitle', 'text' => 'Report form ' . $tpl['code'] . ' · ' . $tpl['name'] . ' · v' . $tpl['version']];
        if (trim((string) ($issue['report_body'] ?? '')) !== '') {
            $b[] = ['t' => 'subtitle', 'text' => 'Layout adjusted for this report'];
        }
        $b = array_merge($b, parse_template_body($body));
        $b[] = ['t' => 'hr'];
    }

    $b[] = ['t' => 'h2', 'text' => $label('Report details')];
    $b[] = ['t' => 'table', 'head' => true, 'rows' => [
        ['Field', 'Detail'],
        ['Report number', (string) $issue['code']],
        ['Title', (string) $issue['title']],
        ['Category', (string) $issue['category']],
        ['Tags', (string) ($issue['tags'] ?: '—')],
        ['Severity', (string) $issue['severity']],
        ['Status', (string) $issue['status']],
        ['Location / area', (string) ($issue['location'] ?: '—')],
        ['Asset or system', (string) ($issue['asset'] ?: '—')],
        ['Department', (string) ($issue['department'] ?: '—')],
        ['Reported by', (string) ($issue['reported_by'] ?: '—')],
        ['Reported on', fmt_date($issue['reported_at'] ?? null)],
        ['Owner', (string) ($issue['owner'] ?: '—')],
        ['Target close', fmt_date($issue['due_at'] ?? null)],
        ['Reviewed by', (string) ($issue['reviewed_by'] ?: '—')],
    ]];

    $b[] = ['t' => 'h2', 'text' => $label('Description & observation')];
    $b[] = ['t' => 'p', 'text' => (string) ($issue['description'] ?: 'No description recorded.')];

    $b[] = ['t' => 'h2', 'text' => $label('Risk assessment')];
    $b[] = ['t' => 'table', 'head' => true, 'rows' => [
        ['Likelihood', 'Impact', 'Score', 'Rating'],
        [likelihood_label((int) $issue['likelihood']), impact_label((int) $issue['impact']), (string) $score, $rating],
    ]];
    $b[] = ['t' => 'p', 'text' => 'Scoring: likelihood (1-5) x impact (1-5). 1-4 Low, 5-9 Moderate, 10-15 High, 16-25 Critical.'];
    $b[] = ['t' => 'p', 'text' => '**Root cause:** ' . (string) ($issue['root_cause'] ?: '—')];
    $b[] = ['t' => 'p', 'text' => '**Immediate action taken:** ' . (string) ($issue['immediate_action'] ?: '—')];
    $b[] = ['t' => 'p', 'text' => '**Corrective / preventive action:** ' . (string) ($issue['corrective_action'] ?: '—')];

    $b[] = ['t' => 'h2', 'text' => $label('Persons involved (suspects / witnesses)')];
    if ($suspects) {
        $rows = [['Name', 'Employee no.', 'Department', 'Position', 'Shift', 'Involvement', 'Interviewed']];
        foreach ($suspects as $s) {
            $rows[] = [
                (string) $s['name'],
                (string) ($s['employee_no'] ?: '—'),
                (string) ($s['department'] ?: '—'),
                (string) ($s['position'] ?: '—'),
                (string) ($s['shift'] ?: '—'),
                (string) $s['involvement'],
                (int) $s['interviewed'] ? 'Yes' : 'No',
            ];
        }
        $b[] = ['t' => 'table', 'head' => true, 'rows' => $rows];
        foreach ($suspects as $s) {
            if (trim((string) ($s['statement'] ?? '')) !== '') {
                $b[] = ['t' => 'p', 'text' => '**Statement — ' . $s['name'] . ':** ' . $s['statement']];
            }
        }
    } else {
        $b[] = ['t' => 'p', 'text' => 'No persons recorded.'];
    }

    $b[] = ['t' => 'h2', 'text' => $label('Evidence & attachments')];
    if ($files) {
        $rows = [['File', 'Type', 'Kind', 'Size', 'Uploaded by', 'Date']];
        foreach ($files as $f) {
            $rows[] = [
                (string) $f['original_name'],
                strtoupper((string) $f['ext']),
                (string) $f['kind'],
                human_size((int) $f['size_bytes']),
                (string) ($f['uploaded_by'] ?: '—'),
                fmt_date($f['created_at'] ?? null),
            ];
        }
        $b[] = ['t' => 'table', 'head' => true, 'rows' => $rows];
    } else {
        $b[] = ['t' => 'p', 'text' => 'No evidence attached.'];
    }

    $b[] = ['t' => 'h2', 'text' => $label('Remediation tasks')];
    if ($tasks) {
        $rows = [['Task', 'Column', 'Priority', 'Owner', 'Due']];
        foreach ($tasks as $t) {
            $rows[] = [
                (string) $t['title'],
                str_replace('_', ' ', (string) $t['column_key']),
                (string) $t['priority'],
                (string) ($t['owner'] ?: '—'),
                fmt_date($t['due_at'] ?? null),
            ];
        }
        $b[] = ['t' => 'table', 'head' => true, 'rows' => $rows];
    } else {
        $b[] = ['t' => 'p', 'text' => 'No tasks linked to this report.'];
    }

    $b[] = ['t' => 'h2', 'text' => $label('Activity log')];
    if ($events) {
        foreach ($events as $ev) {
            $b[] = ['t' => 'li', 'n' => null, 'text' => date('d M Y H:i', strtotime((string) $ev['created_at'])) . ' — ' . $ev['actor'] . ': ' . $ev['message']];
        }
    } else {
        $b[] = ['t' => 'p', 'text' => 'No activity recorded.'];
    }

    $b[] = ['t' => 'pagebreak'];
    $b[] = ['t' => 'h2', 'text' => $label('Sign-off')];
    $b[] = ['t' => 'table', 'head' => true, 'rows' => [
        ['Role', 'Name', 'Date', 'Signature'],
        ['Prepared by (reporter)', (string) ($issue['reported_by'] ?: '—'), fmt_date($issue['reported_at'] ?? null), ''],
        ['Reviewed by (security)', (string) ($issue['reviewed_by'] ?: '—'), fmt_date($issue['reviewed_at'] ?? null), ''],
        ['Approved by (plant manager)', (string) ($issue['approved_by'] ?: '—'), '', ''],
    ]];
    $b[] = ['t' => 'space'];
    $b[] = ['t' => 'p', 'text' => 'Distribution: Security Manager, Plant Manager, relevant department head.'];
    $b[] = ['t' => 'p', 'text' => 'Classification: Internal — retain per ISMS-001 section 7 (24 months or until closure).'];
    $b[] = ['t' => 'hr'];
    $b[] = ['t' => 'subtitle', 'text' => 'Report generated ' . date('d M Y, H:i') . ' · SecOps CRM'];

    return $b;
}

/**
 * Related rows for one issue report: suspects, evidence, tasks and activity.
 *
 * Shared by the detail page and the Word export so the two cannot drift.
 * Events come oldest-first; the detail page reverses them for display.
 */
function issue_report_context(PDO $pdo, int $issueId): array
{
    $fetch = static function (string $sql) use ($pdo, $issueId): array {
        $st = $pdo->prepare($sql);
        $st->execute([$issueId]);
        return $st->fetchAll();
    };

    return [
        'suspects' => $fetch('SELECT * FROM suspects WHERE issue_id = ? ORDER BY FIELD(involvement,\'Primary\',\'Accomplice\',\'Witness\',\'Victim\',\'Unknown\'), id'),
        'files'    => $fetch('SELECT * FROM issue_files WHERE issue_id = ? ORDER BY id'),
        'tasks'    => $fetch('SELECT * FROM tasks WHERE issue_id = ? ORDER BY FIELD(column_key,\'in_progress\',\'review\',\'planned\',\'backlog\',\'done\'), position'),
        'events'   => $fetch('SELECT * FROM issue_events WHERE issue_id = ? ORDER BY created_at, id'),
    ];
}
