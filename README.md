# Security CRM (IS)

An information-security workspace for a manufacturing plant, written in **native
PHP 8 + MySQL** — no framework, no Composer, no build step. Drop the
`security-crm` folder into Laragon and it sets itself up on the first request.

## Features

| Module | What it does |
| --- | --- |
| **Dashboard** | posture at a glance: open/overdue counts, issues by category, risk ratings, SOPs due for review, live activity feed |
| **SOP & Policy library** | typed, scoped, versioned documents with owner, effective date, review date and the controlled text inline |
| **Workflow board** | drag-and-drop kanban (Backlog → Planned → In Progress → Review → Done), saves on drop, cards can link to an issue |
| **Issue register** | categories, tags, severity, search, 5×5 risk score, quick status change, links to the full report |
| **Issue report** | risk assessment + heatmap, root cause, immediate/corrective actions, persons involved, evidence upload, activity log, `.docx` export |
| **Form templates** | NDA, supplier & manufacturing agreements, visitor undertakings, audit checklists… fill in placeholders, preview, download real Word files |
| **EDLP exceptions** | time-bound deviations (USB, websites, cameras, cloud apps) with period, justification, compensating control and derived Active / Expiring / Expired state |
| **Notifications** | per-account alerts for assignments, critical issues, due dates, expiring exceptions and document reviews; bell in the top bar |
| **Audit trail** | append-only record of every sign-in, refusal and record change, with actor, role, address and CSV export |
| **Users & authority** | four roles with a server-enforced permission matrix, login lockout, per-account and per-address throttling |
| **System log** | administrator-only tail of `logs/app.log` for errors the browser never sees |

## Run it in Laragon

1. Copy the **`security-crm`** folder into Laragon's web root, e.g.
   `C:\laragon\www\security-crm\`.
2. Laragon → **Start All** (Apache + MySQL, both green).
3. Open <http://localhost/security-crm/> (or `http://security-crm.test/` with
   auto virtual hosts).

The first page load creates the `security_crm` database and its fourteen tables,
then seeds sample data and the accounts below. Nothing to import.

> **Deeper setup:** [`security-crm/README.md`](security-crm/README.md) covers the
> `AllowOverride All` setting that `.htaccess` needs, the `php.ini` upload
> limits, the `SECOPS_DB_*` variables, the full page and permission reference,
> the deployment checklist and a troubleshooting table.
>
> No Apache? From inside `security-crm`: `php -S localhost:8000 router.php`.

## Sign in

| Username | Password | Role |
| --- | --- | --- |
| `admin` | `Admin@123` | Administrator — everything, including users and authority |
| `manager` | `Manager@123` | Security Manager — every record, template and exception control |
| `analyst` | `Analyst@123` | Security Analyst — investigate, upload evidence, move cards, log exceptions |
| `viewer` | `Viewer@123` | Auditor / Viewer — read-only review, download and export |

Change these before the app leaves a development machine. Five wrong passwords
lock an account for 15 minutes; attempts are logged per account and per address.

## How to use it

**Report an issue.** *Issues* → **New issue**: title, category (Physical / IT /
Strain), severity, likelihood and impact; the risk score and rating are
calculated for you. Save and the report opens.

**Investigate.** On the issue page, edit fields, change status, add notes, add
persons involved (primary, witness, victim…), attach evidence (photos, logs,
statements) and create follow-up tasks on the board.

**Produce the Word report.** On the issue, pick a *Report form* template; the
layout is pre-filled from that form and can be adjusted for this report only.
**Download .docx** renders the form with the issue's own data, appending any
sections the form does not cover (risk, persons, evidence, tasks, activity,
sign-off).

**Create a reusable form.** *Templates* → **New template**, write the body in the
small markup (`#` headings, `-` bullets, `|` tables, `{{PLACEHOLDER}}`), then
download or let issues use it.

**Track deviations.** *EDLP Exceptions* → add the target (USB serial, website,
camera ID…), the period, the justification and the compensating control. The
state (Active / Expiring / Expired / Pending / Revoked) is derived from the dates.
Categories can be added, renamed and restyled.

**Keep documents current.** *SOP & Policy* lists every controlled document with
version, status and review date; the dashboard and alerts flag reviews that are
due. The full text is stored inline and expands in the list.

**Run the board.** *Workflow* is a kanban board; drag a card to change its column
and position (saved immediately) or delete it with the ✕ button.

**Review and prove it.** *Audit trail* filters by action, record type, actor,
free text and dates, and exports the current view to CSV. *Users & Authority*
shows the role matrix and lets an administrator manage accounts.

## Requirements

PHP **8.1+** with `pdo_mysql`, `mbstring` and (optionally) `fileinfo`, plus
MySQL 8 / MariaDB — all bundled with Laragon. `mod_rewrite` and `ext-zip` are
not used; Word files are built by a small OOXML writer in `lib/word.php`.

## Repository layout

```
security-crm/            the whole application
  index.php              router, permission guard, every POST action, downloads
  config.php             database credentials (SECOPS_DB_* overrides)
  router.php             denies private paths for the PHP built-in server
  schema.sql             optional manual import of the generated schema
  .htaccess              Apache hardening: denies uploads/lib/tests, gzip, caching
  lib/                   auth, database + seed, audit, notifications, uploads,
                         template markup, risk/report logic, OOXML writer
  views/                 header, footer, login and one file per page
  assets/                app.css (design system) and app.js (board, widgets)
  tests/run.php          dependency-free unit checks
  logs/app.log           errors written by the exception handler
  uploads/               evidence files under random names (direct access denied)
README.md                this page
```

## Security

Sign-in is required for every page and every POST action; roles are checked
server-side (403 on a missing permission, 419 on a missing or empty form token).
Responses carry a nonce-based CSP, `X-Frame-Options: DENY`, `nosniff` and a
referrer policy; cookies are `HttpOnly` + `SameSite=Lax` (+ `Secure` on HTTPS);
passwords are bcrypt; all SQL is prepared; uploads are extension-whitelisted,
mime-checked, script-blocked, stored under random names and served only through
a permission-checked route; `logs/app.log` holds errors that never reach the
browser. See the deployment checklist in `security-crm/README.md`.
