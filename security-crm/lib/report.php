<?php
/**
 * Issue detail report: risk helpers plus the Word export block builder.
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
 * Build the .docx block list for one issue.
 *
 * @param array $issue  the issues row (joined with computed fields)
 * @param array $ctx    suspects, files, tasks, events, stats
 */
function issue_report_blocks(array $issue, array $ctx = []): array
{
    $suspects = $ctx['suspects'] ?? [];
    $files    = $ctx['files'] ?? [];
    $tasks    = $ctx['tasks'] ?? [];
    $events   = $ctx['events'] ?? [];
    $score    = (int) ($issue['risk_score'] ?? 0);
    [$rating] = risk_rating($score);

    $b = [];
    $b[] = ['t' => 'title', 'text' => 'INFORMATION SECURITY ISSUE REPORT'];
    $b[] = ['t' => 'subtitle', 'text' => $issue['code'] . ' · ' . $issue['category'] . ' · Manufacturing ISMS'];
    $b[] = ['t' => 'hr'];

    $b[] = ['t' => 'h2', 'text' => '1. Report details'];
    $b[] = ['t' => 'table', 'head' => true, 'rows' => [
        ['Field', 'Detail'],
        ['Report number', (string) $issue['code']],
        ['Title', (string) $issue['title']],
        ['Category', (string) $issue['category']],
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

    $b[] = ['t' => 'h2', 'text' => '2. Description & observation'];
    $b[] = ['t' => 'p', 'text' => (string) ($issue['description'] ?: 'No description recorded.')];

    $b[] = ['t' => 'h2', 'text' => '3. Risk assessment'];
    $b[] = ['t' => 'table', 'head' => true, 'rows' => [
        ['Likelihood', 'Impact', 'Score', 'Rating'],
        [likelihood_label((int) $issue['likelihood']), impact_label((int) $issue['impact']), (string) $score, $rating],
    ]];
    $b[] = ['t' => 'p', 'text' => 'Scoring: likelihood (1-5) x impact (1-5). 1-4 Low, 5-9 Moderate, 10-15 High, 16-25 Critical.'];
    $b[] = ['t' => 'p', 'text' => '**Root cause:** ' . (string) ($issue['root_cause'] ?: '—')];
    $b[] = ['t' => 'p', 'text' => '**Immediate action taken:** ' . (string) ($issue['immediate_action'] ?: '—')];
    $b[] = ['t' => 'p', 'text' => '**Corrective / preventive action:** ' . (string) ($issue['corrective_action'] ?: '—')];

    $b[] = ['t' => 'h2', 'text' => '4. Persons involved (suspects / witnesses)'];
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

    $b[] = ['t' => 'h2', 'text' => '5. Evidence & attachments'];
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

    $b[] = ['t' => 'h2', 'text' => '6. Remediation tasks'];
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

    $b[] = ['t' => 'h2', 'text' => '7. Activity log'];
    if ($events) {
        foreach ($events as $ev) {
            $b[] = ['t' => 'li', 'n' => null, 'text' => date('d M Y H:i', strtotime((string) $ev['created_at'])) . ' — ' . $ev['actor'] . ': ' . $ev['message']];
        }
    } else {
        $b[] = ['t' => 'p', 'text' => 'No activity recorded.'];
    }

    $b[] = ['t' => 'pagebreak'];
    $b[] = ['t' => 'h2', 'text' => '8. Sign-off'];
    $b[] = ['t' => 'table', 'head' => true, 'rows' => [
        ['Role', 'Name', 'Date', 'Signature'],
        ['Prepared by (reporter)', (string) ($issue['reported_by'] ?: '—'), fmt_date($issue['reported_at'] ?? null), ''],
        ['Reviewed by (security)', (string) ($issue['reviewed_by'] ?: '—'), fmt_date($issue['reviewed_at'] ?? null), ''],
        ['Approved by (plant manager)', (string) ($issue['approved_by'] ?: '—'), '', ''],
    ]];
    $b[] = ['t' => 'space'];
    $b[] = ['t' => 'p', 'text' => 'Distribution: Security Manager, Plant Manager, relevant department head.' ];
    $b[] = ['t' => 'p', 'text' => 'Classification: Internal — retain per ISMS-001 section 7 (24 months or until closure).' ];
    $b[] = ['t' => 'hr'];
    $b[] = ['t' => 'subtitle', 'text' => 'Report generated ' . date('d M Y, H:i') . ' · SecOps CRM'];

    return $b;
}
