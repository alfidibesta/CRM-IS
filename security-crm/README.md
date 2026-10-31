# SecOps CRM — PHP native + MySQL

Information-security work management for a manufacturing plant: an overview
dashboard, an SOP/Policy library, a drag-and-drop workflow board, a detailed
**issue report** page (evidence uploads, suspect records, risk scoring,
activity log, Word export) and a **form template library** (NDA, agreements and
other controlled forms, downloadable as real `.docx` files).

Access is protected by **login + role authority**: every page and every action
is checked against the signed-in role.

No framework, no build step, no Composer — plain PHP 8, PDO/MySQL, one CSS
file and one JS file. Drop it into Laragon and go.

## Run it (Laragon)

1. Copy this folder to `C:\laragon\www\secops-crm` (any folder under `www` works).
2. Start **Apache** and **MySQL** in Laragon.
3. Open <http://secops-crm.local> (or `http://localhost/secops-crm`) and sign in.

That's it: on the first request the app creates the `security_crm` database,
all eight tables and seeds them with sample data — plus the demo accounts below.

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
| `?page=issues` | Issue register — category / severity / status filters, search, risk score column, quick status change, links to the report |
| `?page=issue&id=N` | **Full issue report**: details, 5×5 risk assessment + heatmap, root cause / actions, persons involved (suspects), evidence upload & download, remediation tasks, activity log, `.docx` export |
| `?page=templates` | Form template library — NDA, supplier & manufacturing agreement, visitor undertaking, incident report, audit checklist… filter, search, one-click Word download |
| `?page=template&id=N` | Fill in the placeholders, live document preview, download `.docx`, edit the source (editors only) |
| `?page=users` | Accounts + the role/permission **authority matrix** (admin only) |

Downloads: `?page=issue_doc&id=N` (issue report), `?page=doc&id=N&fill[KEY]=…`
(template), `?page=download&file=N` (evidence file).

## Authority

| Permission group | admin | manager | analyst | viewer |
| --- | :--: | :--: | :--: | :--: |
| View dashboard, SOP, board, issues, templates | ✓ | ✓ | ✓ | ✓ |
| Report / edit issues, add notes | ✓ | ✓ | ✓ | — |
| Add suspects, upload / remove evidence | ✓ | ✓ | ✓ | — |
| Create / move tasks | ✓ | ✓ | ✓ | — |
| Delete tasks | ✓ | ✓ | — | — |
| Create / edit / delete templates | ✓ | ✓ | — | — |
| Download files & export `.docx` | ✓ | ✓ | ✓ | ✓ |
| Manage users, roles, passwords | ✓ | — | — | — |

The matrix is rendered from `role_list()` / `permission_list()` in
`lib/auth.php` — add a permission there and it appears on the Users page.

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
lib/helpers.php      escaping, badges, CSRF, flash messages
lib/uploads.php      upload validation (whitelist, size, mime) + storage
lib/templates.php     template markup -> HTML blocks
lib/word.php         OOXML (.docx) writer + ZIP encoder
lib/report.php        risk scoring helpers + issue report document blocks
views/               header, footer, login and one file per page
assets/app.css       design tokens + all styling (red / blue / orange accents)
assets/app.js        drag & drop, delete confirms, dropzone, live risk score
schema.sql           optional manual import
uploads/             evidence files (random names, .htaccess denies direct access)
```

## Design accents

- **Red** — Physical Security, critical severity, open issues
- **Blue** — IT Security, policies, review states
- **Orange** — Strain Security (human factor), in-progress work
- **Green** — resolved / low risk / completed

The layout is intentionally compact (13px base, dense tables) so a whole
register or board fits on one screen.

## Security notes

- Sign-in required for every page; roles are enforced server-side on both
  pages **and** POST actions (a missing permission returns 403).
- Session id is regenerated on sign-in, all forms carry a CSRF token, and
  failed logins are counted with a 15-minute lock after 5 attempts.
- Passwords are stored as bcrypt hashes; output is escaped with
  `htmlspecialchars`; all SQL uses prepared statements.
- Uploads are size-limited (8 MB), extension-whitelisted, mime-checked, stored
  under random names, and served only through the permission-checked download
  route.

Intended for a local / intranet deployment — put it behind HTTPS and your
normal server hardening before exposing it beyond Laragon.
