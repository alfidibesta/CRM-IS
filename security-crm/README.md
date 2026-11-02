# SecOps CRM — PHP native + MySQL

Information-security work management for a manufacturing plant: an overview
dashboard, an SOP/Policy library, a drag-and-drop workflow board, a detailed
**issue report** page (categories and tags, evidence uploads, suspect records,
risk scoring, activity log, Word export), a **form template library** (NDA,
agreements and other controlled forms, downloadable as real `.docx` files) and
an **EDLP exception register** for time-bound deviations.

Every issue report can follow a form template: pick the form, and the export is
filled with that issue's own data. The layout can also be adjusted per report.

Access is protected by **login + role authority**: every page and every action
is checked against the signed-in role.

No framework, no build step, no Composer — plain PHP 8, PDO/MySQL, one CSS
file and one JS file. Drop it into Laragon and go.

## Run it in Laragon (step by step)

Anything from Laragon 4 up works: **Apache 2.4** + **MySQL 8 / MariaDB** +
**PHP 8.1 or newer**. No Composer, no Node, no `ext-zip`.

### 1. Copy the app into `www`

The PHP app is the **`security-crm`** folder. Copy that folder (or the whole
repository) into Laragon's web root; Menu → **www** opens it. You should end up
with:

```
C:\laragon\www\security-crm\index.php
C:\laragon\www\security-crm\config.php
C:\laragon\www\security-crm\views\
```

You can rename it (`secops`, `crm-is`, …) — nothing inside depends on the name.

### 2. Start Apache and MySQL

Laragon → **Start All**. Both dots must be green: Apache runs the PHP, MySQL
holds the data.

### 3. Open it in the browser

- <http://localhost/security-crm/>
- or, with *auto virtual hosts* on (Preferences → Services & Ports):
  <http://security-crm.test/>

Open a **URL**, not the folder — `file:///C:/laragon/www/...` never executes PHP.
If the `.test` host answers 404, auto virtual hosts is off; the `localhost` URL
above always works.

### 4. Let the first request build the database

Nothing to import. On the first page load the app connects as `root` (Laragon's
default, empty password), creates the `security_crm` database and its **eleven
tables**, then seeds sample data — SOPs, 12 issues, 12 templates, 10 EDLP
exceptions — plus the four demo accounts below. The sign-in page appears once
that second or two of setup finishes.

Prefer phpMyAdmin? Import `schema.sql` first; the app still seeds any table that
is empty.

### 5. Sign in

Use one of the accounts in the next section. Five wrong passwords lock that
account for 15 minutes.

### 6. The two Laragon settings worth checking

| Setting | Why it matters | Where |
| --- | --- | --- |
| `AllowOverride All` on the `www` directory | `security-crm/.htaccess` denies `/uploads`, `/lib`, `/tests`, `*.sql` and `*.md`, and turns on gzip + asset caching. Without it Apache ignores the file and anyone can download `schema.sql`. | `C:\laragon\bin\apache\httpd.conf` → `<Directory "C:/laragon/www">` → `AllowOverride All`, restart Apache. **Check:** <http://localhost/security-crm/schema.sql> must answer 403/404, not the file. |
| Upload size in `php.ini` | Evidence uploads are capped at the **effective** PHP limit — the smallest of `upload_max_filesize`, `post_max_size` and the app's 8 MB — and the issue page prints that real number. Laragon's default `2M` therefore means a 2 MB cap, not 8 MB. | Menu → PHP → **php.ini**: `upload_max_filesize = 8M`, `post_max_size = 16M`, then restart Apache. |

Required extensions are `pdo_mysql`, `mbstring` and — for reliable evidence
type checks — `fileinfo`; all three are enabled in Laragon by default.
`mod_rewrite` is *not* needed: every URL is `index.php?page=…`.

### 7. Point it at a real database account (recommended)

`config.php` ships with Laragon's `root` / empty password. Either edit that file
or set environment variables, which take precedence:

| Variable | Default | Meaning |
| --- | --- | --- |
| `SECOPS_DB_HOST` | `127.0.0.1` | MySQL host |
| `SECOPS_DB_PORT` | `3306` | MySQL port |
| `SECOPS_DB_USER` | `root` | database user |
| `SECOPS_DB_PASS` | *(empty)* | database password |
| `SECOPS_DB_NAME` | `security_crm` | database name (created if missing) |
| `SECOPS_DEMO` | `1` | `0` hides the demo accounts on the sign-in screen |

On Apache, `SetEnv SECOPS_DB_USER secops` inside the vhost (or the folder's
`.htaccess`) works; with the built-in server, prefix the command.

### 8. Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `could not find driver` | `pdo_mysql` is off for the PHP version Apache runs — Menu → PHP → Extensions |
| `SQLSTATE[HY000] [1045] Access denied` | MySQL is stopped, or `root` has a password → start MySQL / set `SECOPS_DB_PASS` |
| `SQLSTATE[HY000] [2002] Connection refused` | wrong host or port → check `SECOPS_DB_HOST` / `SECOPS_DB_PORT` |
| HTTP 500 or a blank page | read `security-crm/logs/app.log`; confirm PHP 8.1+ (Menu → PHP → Version) |
| Unstyled page | wrong URL depth — the app must be at `/security-crm/index.php` so `assets/` resolves beside it |
| `http://security-crm.test` → 404 | auto virtual hosts is off → use `http://localhost/security-crm/` |
| `schema.sql` downloads in the browser | `AllowOverride` is not `All` (step 6) |
| Upload refused below 8 MB | `php.ini` limits (step 6) — the page shows the cap it is enforcing |
| Port 80 already in use | Laragon → Preferences → Port, or stop IIS / Skype |
| Downloaded `.docx` is empty | whitespace or a BOM before `<?php` in a file you edited |

### 9. No Apache?

From inside the `security-crm` folder:

```bash
php -S localhost:8000 router.php
```

`router.php` applies the same denies as `.htaccess`, so `schema.sql`,
`README.md`, `lib/`, `tests/`, `logs/` and `uploads/` stay private.

## Sign in

| Username | Password | Role | Can do |
| --- | --- | --- | --- |
| `admin` | `Admin@123` | Administrator | Everything, including users & authority |
| `manager` | `Manager@123` | Security Manager | Every record + template control |
| `analyst` | `Analyst@123` | Security Analyst | Investigate, upload evidence, move cards |
| `viewer` | `Viewer@123` | Auditor / Viewer | Read-only: review, download, export |

5 failed passwords lock an account for 15 minutes. Add or change accounts on
**Users & Authority** (admin only).

## Pages

| URL | What it is |
| --- | --- |
| `?page=login` | Sign-in screen (its own layout, no sidebar) |
| `?page=dashboard` | Posture at a glance: stats, issues by category, risk ratings, live activity feed, upcoming reviews, template shortcuts |
| `?page=sop` | SOP & Policy library — filter chips by type / scope / status, search, expandable rows with the controlled text |
| `?page=workflow` | Kanban board (Backlog → Planned → In Progress → Review → Done), drag & drop saves instantly, delete + link to issue |
| `?page=issues` | Issue register — category / severity / status / tag filters, tag chips per issue, search, risk score column, quick status change, links to the report |
| `?page=issue&id=N` | **Full issue report**: details with tags, report form (template + per-report layout), 5×5 risk assessment + heatmap, root cause / actions, persons involved (suspects), evidence upload & download, remediation tasks, activity log, `.docx` export |
| `?page=templates` | Form template library — NDA, supplier & manufacturing agreement, visitor undertaking, incident report, audit checklist… filter, search, one-click Word download |
| `?page=template&id=N` | Fill in the placeholders, live document preview, download `.docx`, list of issue reports that use this form, edit the source (editors only) |
| `?page=exceptions` | **EDLP exception register** — USB, websites, cameras, cloud apps…: adjustable categories, validity period with expiring/expired state, detail, justification, compensating control, category manager |
| `?page=users` | Accounts + the role/permission **authority matrix** (admin only) |
| `?page=audit` | **Audit trail** — every recorded event (sign-ins, refusals, record changes, downloads) with actor, role, record, detail and address; filters and a CSV export |

Downloads: `?page=issue_doc&id=N` (issue report), `?page=doc&id=N&fill[KEY]=…`
(template), `?page=download&file=N` (evidence file), `?page=audit&export=csv`
(audit trail, following the active filter).

## Authority

| Permission group | admin | manager | analyst | viewer |
| --- | :--: | :--: | :--: | :--: |
| View dashboard, SOP, board, issues, templates, exceptions | ✓ | ✓ | ✓ | ✓ |
| Report / edit issues, add notes | ✓ | ✓ | ✓ | — |
| Add suspects, upload / remove evidence | ✓ | ✓ | ✓ | — |
| Create / move tasks | ✓ | ✓ | ✓ | — |
| Delete tasks | ✓ | ✓ | — | — |
| Create / edit / delete templates | ✓ | ✓ | — | — |
| Add / edit / delete EDLP exceptions | ✓ | ✓ | ✓ | — |
| Manage exception categories | ✓ | ✓ | — | — |
| Download files & export `.docx` | ✓ | ✓ | ✓ | ✓ |
| Read the audit trail | ✓ | ✓ | — | ✓ |
| Manage users, roles, passwords | ✓ | — | — | — |

The matrix is rendered from `role_list()` / `permission_list()` in
`lib/auth.php` — add a permission there and it appears on the Users page.

## Report forms, tags and exceptions

**Tags.** An issue carries up to six comma-separated tags (`ot-network`,
`tailgating`, `after-hours`…). They show as chips in the register and on the
report, filter the list with `?page=issues&tag=…`, and appear in the search.

**Report form.** The *Report form* card on an issue does three things:

1. choose which template the report follows (`issues.report_template_id`);
2. edit the layout for **this report only** (`issues.report_body`) — the editor
   is pre-filled with the resolved layout and *Reset to template* drops the
   adjustment;
3. preview the generated document and download it.

Placeholders are filled automatically from the issue: `{{CODE}}`, `{{TITLE}}`,
`{{CATEGORY}}`, `{{TAGS}}`, `{{SEVERITY}}`, `{{STATUS}}`, `{{OWNER}}`,
`{{LOCATION}}`, `{{DEPARTMENT}}`, `{{ASSET}}`, `{{REPORTED_BY}}`,
`{{REPORTED_AT}}`, `{{DUE_AT}}`, `{{RISK_SCORE}}`, `{{RISK_RATING}}`,
`{{LIKELIHOOD}}`, `{{IMPACT}}`, `{{ROOT_CAUSE}}`, `{{IMMEDIATE_ACTION}}`,
`{{CORRECTIVE_ACTION}}`, `{{DESCRIPTION}}`, `{{REVIEWER}}`, `{{APPROVER}}`,
`{{TODAY}}`. Whatever the form does not cover is appended as **Annex A–F**
(risk, persons, evidence, tasks, activity, sign-off), so nothing is lost.

**EDLP exceptions.** `?page=exceptions` is the register of approved, time-bound
deviations — USB storage, website access, cameras, removable media, network
shares, cloud apps. Each record keeps the target (serial, domain or camera ID),
the period (start and end), the detail, the business justification, the
compensating control and who approved it. State is derived: **Active**,
**Expiring** (≤ 30 days left), **Expired**, **Pending approval**, **Revoked**.
Categories live in `exception_categories` and are fully adjustable — add, rename
(records follow), restyle or delete when unused.

## Word export

Both the issue report and the templates are built as **real `.docx` files** by
a small OOXML writer (`lib/word.php`) with its own store-only ZIP encoder, so
no Composer packages or `ext-zip` are required. Template bodies use a tiny
markup:

```
# Title / ## Section / ### Sub-heading
> subtitle
- bullet · 1. numbered · [ ] checkbox
| Header | Header |      ← a first row in **bold** becomes the header row
| cell   | cell   |
{{PLACEHOLDER}}          ← fill-in field
---
```

The same blocks render to the on-screen preview and into the `.docx`.

## Structure

```
index.php            router: login/logout, permission guard, all POST actions,
                     file / .docx download streams
config.php           MySQL credentials
lib/auth.php         roles, permission catalogue, login, lockout, 401/403
lib/db.php           PDO connection, schema migration, per-table seed
lib/helpers.php      escaping, badges, CSRF, flash, tag + exception helpers
lib/uploads.php      upload validation (whitelist, size, mime) + effective limit
lib/templates.php     template markup -> HTML blocks
lib/word.php         OOXML (.docx) writer + ZIP encoder
lib/report.php        risk scoring, report-form binding, issue report .docx blocks
views/               header, footer, login and one file per page
assets/app.css       design tokens + all styling (red / blue / orange accents)
assets/app.js        drag & drop, delete confirms, dropzone, live risk score
schema.sql           optional manual import
.htaccess            Apache hardening: deny uploads/lib/tests, deflate, caching
router.php           same denies for the PHP built-in server (php -S … router.php)
tests/run.php        dependency-free unit checks (php tests/run.php)
logs/app.log         error log written by the exception handler
uploads/             evidence files (random names, direct access denied)
```

## Design accents

- **Red** — Physical Security, critical severity, open issues
- **Blue** — IT Security, policies, review states
- **Orange** — Strain Security (human factor), in-progress work
- **Green** — resolved / low risk / completed

The layout is intentionally compact (13px base, dense tables) so a whole
register or board fits on one screen.

## Audit trail

Every change is recorded in `audit_log`: who acted, the role they held at that moment, the record
touched (`entity` + `entity_id`), a human label, the detail, the address and the client. The table is
append-only — the application inserts and reads; nothing in it updates or deletes a row.

Covered events: sign-in, sign-out, failed sign-in, lockout and address throttle, refused permission,
issue create / update / status / report form / note / person / export, evidence upload / download /
delete, task create / move / delete, template create / update / delete / download, exception and
category create / update / delete, user create / update / password reset / delete, and trail exports.

Open **Audit trail** in the sidebar — permission `page.audit`, held by administrator, security manager
and auditor/viewer; an analyst generates history but does not read it. Filter by action, record type,
actor, free text and date range; **Export CSV** writes the current filter as an audit pack; the
dashboard shows the six most recent entries. Retention follows your database backup policy — the app
never prunes the table, so archive it yourself if it grows large.

## Security notes

- Sign-in required for every page; roles are enforced server-side on both pages
  **and** POST actions — a missing permission returns **403**, a missing or
  empty form token returns **419**.
- Session ids are regenerated on sign-in; cookies are `HttpOnly` + `SameSite=Lax`
  (plus `Secure` under HTTPS); `session.use_strict_mode` is on.
- Failed logins are counted per account (5 = 15-minute lock) **and** per address
  (25 in 15 minutes), and every attempt is written to `login_attempts`.
- Creation forms carry a one-shot token, so a resubmitted page cannot create the
  same record twice.
- Every response sends `Content-Security-Policy` (per-request nonce for the one
  inline script), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: same-origin` and `Permissions-Policy`; `X-Powered-By` is removed.
- Uncaught errors are logged to `logs/app.log` and answered with a neutral 500
  page — stack traces and SQL never reach the browser. Over-long input is clamped
  to the column width, dates are validated, and empty required fields are refused.
- Passwords are stored as bcrypt hashes; output is escaped with
  `htmlspecialchars`; all SQL uses prepared statements.
- Uploads are capped at the **effective** PHP limit (shown in the UI), extension-
  whitelisted, mime-checked, script-blocked, stored under random names, and served
  only through the permission-checked download route.
- Framing is denied by default; an installation that must be embedded sets
  `SECOPS_FRAME_ANCESTORS` to its allowed parents.

### Deployment checklist

1. Point `SECOPS_DB_USER` / `SECOPS_DB_PASS` at a dedicated MySQL account — do
   not ship `root` with an empty password.
2. Set `SECOPS_DEMO=0` to hide the demo account list on the sign-in page.
3. Serve over HTTPS — the app then sets `Secure` cookies and HSTS.
4. Keep `security-crm/.htaccess` active on Apache (it denies `/uploads`, `/lib`,
   `/tests`, `*.sql` and `*.md`, and enables compression + asset caching). On
   Nginx use:

```nginx
location ^~ /secops-crm/uploads/ { deny all; }
location ^~ /secops-crm/lib/     { deny all; }
location ^~ /secops-crm/tests/   { deny all; }
location ~* ^/secops-crm/.*\.(sql|md)$ { deny all; }
gzip on;
```

### Tests

```bash
php tests/run.php     # 31 unit checks: tags, risk, clamping, dates, parsing
```
