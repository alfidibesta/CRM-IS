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

## Run it (Laragon)

1. Copy this folder to `C:\laragon\www\secops-crm` (any folder under `www` works).
2. Start **Apache** and **MySQL** in Laragon.
3. Open <http://secops-crm.local> (or `http://localhost/secops-crm`) and sign in.

That's it: on the first request the app creates the `security_crm` database,
all eleven tables and seeds them with sample data — plus the demo accounts below.

> No Apache? From this folder run `php -S localhost:8000 router.php`.
> `router.php` applies the same denies as `.htaccess`, so `schema.sql`,
> `README.md`, `lib/`, `tests/`, `logs/` and `uploads/` stay private.

Connection settings live in `config.php` — Laragon's default `root` with an
empty password is already set.

> Want a manual import instead? The schema lives in `schema.sql`. The app
> still seeds any empty table on the next request.

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

Downloads: `?page=issue_doc&id=N` (issue report), `?page=doc&id=N&fill[KEY]=…`
(template), `?page=download&file=N` (evidence file).

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
