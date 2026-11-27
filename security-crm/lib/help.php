<?php
declare(strict_types=1);

/**
 * Contextual help for every screen.
 *
 * One entry per page key: a short summary of what the screen is for, three or
 * four steps covering its actions, and one tip. The top bar renders the entry
 * for the page being viewed as a "Help" popover, so every feature explains
 * itself in place. The longer manual lives in help.md next to this file.
 *
 * @return array<string, array{title: string, summary: string, steps: list<string>, tip: string}>
 */
function help_topics(): array
{
    static $topics = [
        'dashboard' => [
            'title'   => 'Security overview',
            'summary' => 'One screen for the whole programme: document control, open issues, board progress, evidence, exceptions and the newest activity.',
            'steps'   => [
                'Start with the counters — open issues, tasks in flight and board completion show whether the plant is keeping up.',
                'Read "Opened vs closed" with "Age of unresolved issues": the trend and the backlog age answer whether the programme is improving, which the counters alone cannot.',
                'Click a category in "Issues by category" to open the register already filtered to it.',
                'Use the bell for new alerts and "Recently reported" for the latest findings.',
            ],
            'tip' => 'This page is read-only. To enter data, go to Issues and press "+ Report new issue".',
        ],

        'issues' => [
            'title'   => 'Security issues',
            'summary' => 'The register of every Physical, IT and Strain security finding, newest first, with search, filters and a per-row Word report.',
            'steps'   => [
                'Search by code, title, owner or asset; the category and tag chips in the sidebar filter the list too.',
                'With issue.create you can use "+ Report new issue" — the risk score is likelihood × impact.',
                'Change a status inline with the select in the row, or open the issue to edit the full record.',
                'The .docx button on a row downloads that issue\'s report; the pager walks the list.',
            ],
            'tip' => 'Filters live in the URL, so a filtered view can be bookmarked or sent to a colleague.',
        ],

        'issue' => [
            'title'   => 'Issue detail report',
            'summary' => 'The full investigation record: report details, risk, persons involved, evidence, remediation tasks and the activity log.',
            'steps'   => [
                'Edit "1 · Report details" and "2 · Risk assessment"; severity and risk changes are written to the activity log.',
                'Upload evidence in "4 · Evidence & attachments" and download any file again from the same list.',
                'Add people in "3 · Persons involved" and tasks in "5 · Remediation tasks" — tasks also appear on the board.',
                'Persons involved and the evidence list are limited to the investigation roles; an auditor sees a restricted note and exports the report without them.',
                'Use "Download report (.docx)" for the Word report, and "Report form" to choose or adjust the form it uses.',
            ],
            'tip' => 'Every save is audited; when something changed outside the system, record it as a note in the activity log.',
        ],

        'workflow' => [
            'title'   => 'Workflow board',
            'summary' => 'Security work in flight as a five-column board: Backlog, Planned, In progress, Review and Done.',
            'steps'   => [
                'Drag a card to another column or position — the move is saved immediately and audited.',
                '"+ Add task" creates a card, optionally linked to an issue, with owner, priority and due date.',
                'Delete a card when the work is no longer needed.',
            ],
            'tip' => 'Cards move only with task.move permission; viewers see the board read-only.',
        ],

        'sop' => [
            'title'   => 'SOP & policy library',
            'summary' => 'Controlled SOPs, policies and work instructions with type, scope, version, owner, status and next review date.',
            'steps'   => [
                'Filter with the type and scope chips, or search by code, title and owner.',
                'Click a row to expand the document preview, its metadata and its version history — "Read this version" opens a saved snapshot on its own page.',
                'With document control use "+ New document" to add a policy, SOP or guideline, and "Edit document" on a row to change or delete it — every save asks why and keeps the previous text as a version.',
                'On an Active document press "I have read vX" to record that you have read it; the Read column shows who has.',
                '"+ New template" opens the form-template form on the Templates page.',
            ],
            'tip' => 'A new version resets acknowledgements, so a change to a policy asks its readers to read it again.',
        ],

        'templates' => [
            'title'   => 'Form templates',
            'summary' => 'Controlled forms — NDAs, agreements and security forms — kept as editable text and downloaded as Word files.',
            'steps'   => [
                'Search by code, name or category, then "Preview & fill" or download the .docx.',
                '"+ New template" takes simple Markdown: # heading, ## section, - bullet, 1. numbered, [ ] checkbox, | tables |, and {{PLACEHOLDER}} for fields.',
            ],
            'tip' => 'Keep placeholder names upper-case and unchanged so existing fills keep working.',
        ],

        'template' => [
            'title'   => 'Template detail',
            'summary' => 'One form: its source, a live preview, placeholder filling and the issues that already use it.',
            'steps'   => [
                'Fill the placeholders with the reference and press "Download Word file" for a .docx with your values.',
                'Edit the source and save (template.edit); headings, bullets, numbered lists, checkboxes and tables are understood.',
                'Delete the template when it is retired — reports that used it keep their own saved layout.',
            ],
            'tip' => 'The reference you type is printed on the document, so use the real file reference (e.g. NDA-2025-014).',
        ],

        'exceptions' => [
            'title'   => 'EDLP exceptions',
            'summary' => 'The register of approved deviations — USB, websites, cameras and more — with owner, period, risk and compensating control.',
            'steps'   => [
                'Filter by status or category, or search, then expand a row to read the justification and control.',
                '"+ Add EDLP exception" records a new deviation; its period end drives the expiry alerts.',
                'Edit -> Renew extends the period: managers and administrators record the new end, the reason, the approver and the moment. The edit form itself cannot move the period end.',
                'Edit -> Verify the compensating control records the date, what was checked and who checked it.',
                'Managers edit or delete entries and maintain the category list at the bottom of the page.',
            ],
            'tip' => 'Renew before the period ends: an extension recorded on the last day, with a fresh control verification, is the easiest audit answer.',
        ],

        'ot' => [
            'title'   => 'OT vulnerabilities',
            'summary' => 'Daily monitoring of factory-automation weaknesses — PLC, HMI, SCADA and the network between them — by business location, vulnerability, importance and target facilities.',
            'steps'   => [
                'Filter by business location, vulnerability type or current status, or search the vulnerability, facility and recent contact.',
                '"Vulnerability registration" records the business location, vulnerability, importance, report confirmation date, target facilities/systems and remarks. Both catalogues end with "Other (direct input)".',
                'Open "Monitoring and management" for the status, remediation due date, PC risk management, recent contact, owner, CVSS and risk control.',
                'Expand a row for the remarks, control and recommendation; Edit opens the full form and the delete button.',
            ],
            'tip' => 'Give a finding a due date even when the fix waits for a shutdown: an open finding past its due date reads Overdue, which is what the review meeting asks about.',
        ],

        'audit' => [
            'title'   => 'Audit trail',
            'summary' => 'Every recorded action, with who did it, from where and what changed — the evidence trail for ISO 27001.',
            'steps'   => [
                'Combine the free-text search with the action, record type, actor and date filters, then "Apply filters".',
                '"Verify integrity" re-walks the whole hash chain and names the first entry that no longer matches; it writes nothing, so the trail you audit stays exactly as it was.',
                '"Export CSV" downloads the filtered trail (up to 5,000 rows) for an auditor.',
                '"Newer" and "Older" page through the trail; the newest entry is always first.',
            ],
            'tip' => 'A clean run reads "chain intact (ids 1–N)". The console verifier — php tests/verify-audit.php — does the same walk and also records the anchor outside the database.',
        ],

        'health' => [
            'title'   => 'System self-check',
            'summary' => 'Whether this installation can be trusted right now: PHP and schema versions, the audit hash chain and its anchor, storage permissions and how old the newest database dump is.',
            'steps'   => [
                'Read the verdict first: red means a check failed, orange means something needs attention soon.',
                'A broken chain or anchor means somebody changed the trail outside the application — export the trail and keep the anchor file before anything else.',
                'Under "Backups", an old or missing dump means the restore drill cannot pass: run php tools/backup.php on the server.',
                'Paths, versions and the demo-account setting are here because this page is administrator-only.',
            ],
            'tip' => 'Every check is a read — opening this page never changes the installation, so it is safe to run in front of an auditor.',
        ],

        'users' => [
            'title'   => 'Users & authority',
            'summary' => 'Accounts, roles and the authority matrix that decides what each role may do.',
            'steps'   => [
                'Press "+ Add user" at the bottom of the page: a unique username, a password of at least 8 characters and a role.',
                'Edit an account to change its name, e-mail, role or active state, or to reset its password.',
                'Read the authority matrix to see exactly which permission each role holds.',
            ],
            'tip' => 'You cannot demote, disable or delete yourself, and the last active administrator cannot be deleted.',
        ],

        'logs' => [
            'title'   => 'System log',
            'summary' => 'The tail of the application error log, for troubleshooting a failed upload or a database error.',
            'steps'   => [
                'Filter the tail by keyword (error, SQL, a file name) and clear the filter to see everything again.',
            ],
            'tip' => 'The log is for faults; the audit trail is the place for who changed what.',
        ],

        'notifications' => [
            'title'   => 'Notifications',
            'summary' => 'Alerts for issues assigned to you, critical findings, items falling due, exceptions expiring and document reviews.',
            'steps'   => [
                'Switch between unread and all, filter by kind, then open an alert to jump to the record.',
                '"Mark read" clears one alert; "Mark all" clears the list.',
            ],
            'tip' => 'The bell in the top bar shows the unread count and the latest alerts on every page.',
        ],
    ];

    return $topics;
}

/** The help entry for a page key, or null when the screen has none. */
function help_for(string $page): ?array
{
    return help_topics()[$page] ?? null;
}
