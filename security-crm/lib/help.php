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
                'Click a category in "Issues by category" to open the register already filtered to it.',
                'Use the bell for new alerts and "Recently reported" for the latest findings.',
            ],
            'tip' => 'This page is read-only; every record is one click away in the sidebar.',
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
                'Click a row to expand the document preview and its metadata.',
            ],
            'tip' => 'A document whose review falls due within 30 days raises an alert in the bell.',
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
                'Managers edit or delete entries and maintain the category list at the bottom of the page.',
            ],
            'tip' => 'An exception expiring within 30 days alerts both the owner and the security managers.',
        ],

        'audit' => [
            'title'   => 'Audit trail',
            'summary' => 'Every recorded action, with who did it, from where and what changed — the evidence trail for ISO 27001.',
            'steps'   => [
                'Combine the free-text search with the action, record type, actor and date filters, then "Apply filters".',
                '"Export CSV" downloads the filtered trail (up to 5,000 rows) for an auditor.',
                '"Newer" and "Older" page through the trail; the newest entry is always first.',
            ],
            'tip' => 'Each entry carries the SHA-256 hash of the one before it; php tests/verify-audit.php checks the chain.',
        ],

        'users' => [
            'title'   => 'Users & authority',
            'summary' => 'Accounts, roles and the authority matrix that decides what each role may do.',
            'steps'   => [
                'Create an account with a unique username and a password of at least 8 characters.',
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
