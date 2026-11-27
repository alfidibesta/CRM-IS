# SecOps CRM — PHP native + MySQL

Information-security work management for a manufacturing plant: an overview
dashboard, an SOP/Policy library, a drag-and-drop workflow board, a detailed
**issue report** page (categories and tags, evidence uploads, suspect records,
risk scoring, activity log, Word export), a **form template library** (NDA,
agreements and other controlled forms, downloadable as real `.docx` files),
an **EDLP exception register** for time-bound deviations and an **OT
vulnerability register** for process-control findings.

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
default, empty password), creates the `security_crm` database and its **sixteen
tables**, then seeds sample data — SOPs, 12 issues, 12 templates, 10 EDLP
exceptions, 8 OT findings — plus the four demo accounts below. The sign-in page appears once
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
| `SECOPS_TRUSTED_PROXIES` | `127.0.0.1/32,::1/128` | proxies whose `X-Forwarded-For` may be believed (see [Intranet deployment](#intranet-deployment)) |
| `SECOPS_AUDIT_ANCHOR` | `logs/audit-anchors.log` | file that keeps the audit trail's head hash outside the database (see [Tamper evidence](#tamper-evidence)) |

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

## Intranet deployment

The app is built for a company network: it loads no CDN, web font, analytics or
any other external resource, so an isolated LAN renders it exactly like a
connected one. Serve it from its LAN address (for example
`http://10.120.10.20/security-crm/`) — ideally over HTTPS, because the service
worker that powers offline mode only registers on HTTPS or `localhost`.

### The real client address

`REMOTE_ADDR` is the address that reached PHP. Behind a reverse proxy, load
balancer or intranet portal that is the proxy, and the user's own address (for
example `10.120.10.10`) arrives in `X-Forwarded-For`. The audit trail and the
`login_attempts` table record the **client** address:

- A forwarding header is believed only when the request's direct peer is listed
  in `SECOPS_TRUSTED_PROXIES` (comma-separated addresses or CIDRs). The default
  trusts this machine only: `127.0.0.1/32,::1/128`.
- `X-Forwarded-For` is read right to left, skipping each trusted proxy, so an
  entry a client appended itself is never the one recorded.
- `X-Real-IP` is used when a proxy sends that instead of the list.

```apache
# Proxy on the same host (Laragon + nginx/IIS in front, for example)
SetEnv SECOPS_TRUSTED_PROXIES "127.0.0.1/32,::1/128"
# Proxy on the LAN, or a whole proxy subnet
SetEnv SECOPS_TRUSTED_PROXIES "10.120.10.5,10.0.0.0/8"
```

```nginx
proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Real-IP         $remote_addr;
```

`X-Forwarded-Proto: https` is honoured only from a trusted proxy as well, so the
session cookie is `Secure` and HSTS is sent when TLS ends at the proxy. Record
the proxy addresses in `SECOPS_TRUSTED_PROXIES` — otherwise the audit trail
records the proxy instead of the user.

### Embedding in an intranet portal

Framing is denied by default. To show the CRM inside a portal page, name the
portal's origin and restart the web server:

```apache
SetEnv SECOPS_FRAME_ANCESTORS "https://intranet.example.local"
```

## Sign in

| Username | Password | Role | Can do |
| --- | --- | --- | --- |
| `admin` | `Admin@123` | Administrator | Everything, including users & authority |
| `manager` | `Manager@123` | Security Manager | Every record, templates and the SOP library |
| `analyst` | `Analyst@123` | Security Analyst | Investigate, upload evidence, move cards |
| `viewer` | `Viewer@123` | Auditor / Viewer | Read-only: review the registers, audit trail and report exports |

5 failed passwords lock an account for 15 minutes. Add or change accounts on
**Users & Authority** (admin only).

## Features

- **Dashboard** — open and overdue counts, issues by category, risk ratings,
  documents due for review, and the six latest audit entries; plus the trend a
  management review asks for — issues opened vs closed per month for the last
  six months, mean time to resolve, and the age of every unresolved issue.
- **SOP & Policy library** — type (Policy / SOP / Guideline), scope, version,
  owner, status and review date; the controlled text expands inline; document
  control adds, edits and deletes documents in place and links to the template
  form. Every save keeps a full **version snapshot** with its reason and author,
  and staff record that they have **read** the current revision, so the library
  doubles as an awareness record.
- **Workflow board** — five columns, drag and drop with the order saved on drop,
  priority/owner/due date, optional link to an issue.
- **Issue register and report** — category, severity, tags, search and quick
  status change; the report adds the 5x5 risk assessment, heatmap, root cause,
  actions, persons involved, evidence, activity log and `.docx` export.
- **Form templates** — controlled forms stored as light markup, previewed on
  screen, filled with placeholders and downloaded as real Word files.
- **EDLP exceptions** — approved deviations with target, period, justification,
  compensating control and derived Active / Expiring / Expired state; adjustable
  categories. Extending the period is a **renewal** (managers and administrators)
  that records the approver, the moment and the reason, and the compensating
  control keeps its own verification date, note and verifier.
- **OT vulnerabilities** — process-control findings with the site and asset
  they affect, category, severity and CVSS score, the risk control in force, a
  recommendation, how the PC risk is managed (managed / partially managed / not
  managed), status, owner and remediation due date; an open finding past its due
  date reads **Overdue**.
- **Notifications** — assignment, critical issue, due date, expiring exception
  and SOP review alerts, deduped per day, with unread/all filters.
- **Audit trail and system log** — every action recorded and exportable as CSV,
  each entry hash-chained to the one before it so a direct database edit is
  detectable; the administrator-only log page shows handled and uncaught failures.
- **Users and authority** — four roles, a rendered permission matrix, lockout
  after five failures, throttling per address, password reset by an admin.

## How to use it

Every register works the same way: a **+ button** opens a short form, **Save**
adds the record, and the detail (evidence, people, tasks) is added on the record
itself. Nothing typed into a form is lost — a validation error redraws it filled
in, and every save lands in the audit trail.

### 1. Sign in

1. Open the URL, enter the username and password, press **Sign in**.
2. The dashboard that opens is read-only: it counts what the registers hold.

### 2. Report a security issue

1. Sidebar -> **Issues** -> **+ Report new issue**.
2. Fill **Title**, **Category**, **Severity**, **Likelihood**, **Impact** and
   **Description**. The risk score (likelihood x impact) is calculated for you.
3. Open **Owner, location and report form** if you have the owner, location,
   department, asset, reported/due dates, tags or the Word form to choose.
4. Press **Create issue** — the full report page opens on the new record.
5. On that page: **Upload evidence**, **+ Add person**, **+ Add note**, and add
   remediation tasks. **Download report (.docx)** writes the Word report.

### 3. Add a workflow task

1. **Workflow** -> **+ Add task**: task, column, priority, category, owner, due
   date and an optional linked issue.
2. Press **Add task**; drag the card to another column to move it — the move is
   saved and audited on drop.

### 4. Add a controlled document (policy / SOP / guideline)

1. **SOP & Policy** -> **+ New document**.
2. Title (the code is optional — leaving it blank assigns one), type, scope,
   version, owner, status, effective and next review dates, summary.
3. Paste the controlled text in light Markdown: `# heading`, `- bullet`,
   `1. numbered`, `| table |`.
4. Press **Create document**. Readers later record **I have read vX** on the row.

### 5. Add a form template

1. **Templates** -> **+ New template**: code, name, category and the form source
   with `{{PLACEHOLDER}}` fields.
2. Press **Create template**, then **Preview & fill** to fill a copy or
   **Download .docx** for Word.

### 6. Add an EDLP exception

1. **EDLP Exceptions** -> **+ Add EDLP exception**.
2. Title, category, risk level, target, period start/end, owner, approved by,
   detail, business justification and compensating control.
3. Press **Add exception**. A period end inside 30 days reads **Expiring**, a
   past one **Expired**.
4. Extend it with **Edit -> Renew** (new end date, reason, approver) and record
   the control check with **Edit -> Verify**.

### 7. Add an OT vulnerability

1. **OT Vulnerabilities** -> open **Vulnerability registration**.
2. Fill business location, vulnerability, importance and target facilities.
3. Open **Monitoring and management** for status, remediation due date, PC risk
   management, recent contact, owner, CVSS score, control and recommendation.
4. Press **Vulnerability registration** at the bottom of the form.

### 8. Add a user (administrator only)

1. **Users & Authority** -> **+ Add user**: username, name, e-mail, role and a
   password of at least 8 characters.
2. Press **Create account**. **Save** on a row changes the role, disables the
   account or resets its password.

### 9. Check the trail

1. **Audit trail** lists every save with who, when and from where; **Export CSV**
   downloads the current filter.
2. The **Alerts** bell holds assignments, critical findings, items falling due
   and exceptions about to expire.

### Upgrading from an earlier build

`SCHEMA_VERSION` in `lib/db.php` bumps once per schema change; `migrate()` only
adds missing tables, columns and indexes, so an existing database keeps its data.

Build `20261124` adds document control: `sop_revisions` keeps a full snapshot of
every saved version with its reason and author, and `sop_acknowledgements`
records who read which revision. Both tables are created on the next request and
give each existing document a baseline revision; nothing else changes.

Build `20261125` renames `exceptions.system` to `exceptions.source_system`; the
column is renamed in place on the next request, so existing values are kept.

Build `20261127` adds the OT vulnerability register (`ot_vulnerabilities`,
permissions `page.ot` and `ot.manage`). The table is created and seeded on the
next request; no existing table or column changes.

Build `20261128` reshapes that register into the shop-floor registration sheet:
business location, vulnerability, importance, report confirmation date, target
facilities and systems, and remarks, with a recent-contact column and
"Other (direct input)" on the location and vulnerability catalogues. Only
`recent_contact` is added; earlier category values are mapped to the new
vulnerability types so no row is lost.

Build `20261126` adds the exception renewal path: `renewed_at`, `renewed_by`,
`renewal_note`, `control_verified_at`, `control_verified_by` and
`control_verified_note` on `exceptions`. The columns are added on the next
request; existing rows keep their dates and simply show no renewal yet.

This build (`20261122`) adds `prev_hash` and `row_hash` to `audit_log` and seals
the entries that already exist, once, in id order. It is additive and reversible:
back up first, because re-sealing recomputes the chain.

```bash
mysqldump -u root -p security_crm > backup-before-audit-chain.sql   # keep this
```

To go back to the previous build, restore that dump (or, if you only want the
columns gone, revert the code first and then):

```sql
ALTER TABLE audit_log DROP COLUMN row_hash, DROP COLUMN prev_hash;
```

This build **removes the CCTV module**. `lib/cctv.php`, `views/cctv.php` and the
`uploads/cctv/` folder are gone, and no new `cameras` table is created. An older
database still holds its `cameras` table — nothing drops it for you. Back up,
then remove it when you are ready:

```bash
mysqldump -u root -p security_crm > backup-before-drop.sql
mysql -u root -p security_crm -e "DROP TABLE cameras;"
```

The audit trail is append-only, so earlier camera entries stay in the history
and now show their raw action key (for example `cctv.prune`).

## Pages

| URL | What it is |
| --- | --- |
| `?page=login` | Sign-in screen (its own layout, no sidebar) |
| `?page=dashboard` | Posture at a glance: stats, issues by category, risk ratings, six-month opened-vs-closed trend with mean time to resolve, age of unresolved issues, live activity feed, upcoming reviews, template shortcuts |
| `?page=sop` | SOP & Policy library — filter chips by type / scope / status, search, expandable rows with the controlled text and version history, create / edit / delete documents (document control), "I have read" acknowledgement with coverage per revision, shortcut to add a form template |
| `?page=workflow` | Kanban board (Backlog → Planned → In Progress → Review → Done), drag & drop saves instantly, delete + link to issue |
| `?page=issues` | Issue register — category / severity / status / tag filters, tag chips per issue, search, risk score column, quick status change, links to the report |
| `?page=issue&id=N` | **Full issue report**: details with tags, report form (template + per-report layout), 5×5 risk assessment + heatmap, root cause / actions, persons involved (suspects), evidence upload & download, remediation tasks, activity log, `.docx` export |
| `?page=templates` | Form template library — NDA, supplier & manufacturing agreement, visitor undertaking, incident report, audit checklist… filter, search, one-click Word download |
| `?page=template&id=N` | Fill in the placeholders, live document preview, download `.docx`, list of issue reports that use this form, edit the source (editors only) |
| `?page=exceptions` | **EDLP exception register** — USB, websites, cameras, cloud apps…: adjustable categories, validity period with expiring/expired state, detail, justification, compensating control, category manager |
| `?page=ot` | **OT vulnerability register** — daily FA monitoring: business location, vulnerability type (with *Other*), importance, report confirmation date, target facilities/systems, remarks, current status, recent contact, PC risk management, risk control, owner and due date |
| `?page=users` | Accounts + the role/permission **authority matrix** (admin only) |
| `?page=audit` | **Audit trail** — every recorded event (sign-ins, refusals, record changes, downloads) with actor, role, record, detail and address; filters, a **Verify integrity** button that re-walks the hash chain and an in-page anchor check, and a CSV export |
| `?page=health` | **System self-check** (administrator) — verdict plus PHP version, schema state, hash chain and anchor, storage permissions, how old the newest database dump is, and the configuration that changes security behaviour |
| `?page=logs` | **System log** (administrator) — the tail of `logs/app.log`: PHP errors and uncaught exceptions the browser never sees |

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
| Create / edit / delete SOP & policy documents | ✓ | ✓ | — | — |
| Acknowledge reading a policy or SOP | ✓ | ✓ | ✓ | ✓ |
| See who has read a document revision | ✓ | ✓ | — | — |
| Add / edit / delete EDLP exceptions | ✓ | ✓ | ✓ | — |
| Read the OT vulnerability register | ✓ | ✓ | ✓ | ✓ |
| Add / edit / delete OT vulnerabilities | ✓ | ✓ | ✓ | — |
| Approve a renewal / verify a compensating control | ✓ | ✓ | — | — |
| Manage exception categories | ✓ | ✓ | — | — |
| Read the system log | ✓ | — | — | — |
| Run the system self-check | ✓ | — | — | — |
| Read persons involved (contact, statements) | ✓ | ✓ | ✓ | — |
| Download evidence files | ✓ | ✓ | ✓ | — |
| Export the issue report (`.docx`) | ✓ | ✓ | ✓ | ✓ (without persons or evidence) |
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

The **period end is approval-bearing**: the edit form shows it read-only, and it
only moves through *Renew* — a separate action limited to managers and
administrators that records the new end date, the reason, the approver
(`renewed_by`), the moment (`renewed_at`) and sets the status back to Active. A
revoked exception cannot be renewed, and the new end must be in the future. The
compensating control carries `control_verified_at`, `control_verified_by` and
`control_verified_note`, so "the control is still in force" is a dated record
rather than an assumption.

**OT vulnerabilities.** `?page=ot` is the daily register for factory-automation
weaknesses — the process-control findings that cannot be patched like an office
PC. The registration sheet follows the shop floor: **business location**
(required), **vulnerability** type (unpatched CVE/firmware, end-of-life
OS/firmware, default or weak credentials, unauthenticated/unencrypted protocol,
missing segmentation, remote/vendor access, missing hardening, weak backup,
physical/USB), **importance**, **report confirmation date**, **target facilities
and systems**, and **remarks**. Both catalogues end with **Other (direct
input)**. The same form keeps the current status, remediation due date, PC risk
management (`Managed`, `Partially managed`, `Not managed`), recent contact,
owner, optional CVSS score with advisory reference (`ICSA-24-102-07`), the
**risk control** that holds the risk down while the fix waits and a
**recommendation**. The list shows report date, business site, vulnerability,
target facilities, importance, current status, recent contact and the
management actions. State is derived: **Open**, **Mitigating**, **Overdue** (an
open finding past its due date), **Risk accepted** and **Resolved**. The stat
cards count open findings, open critical/high ones, overdue remediation and open
findings whose PC risk is not managed.

## Themes, density and panels

**Bright or dim.** The two buttons in the top bar switch the palette and the
spacing. Both choices are stored in the browser (not in the account), are
applied before the first paint so nothing flashes, and never change layout.
**Compact** trims padding, table rows and gaps; **Dim** is the dark palette.

**Simple or detailed.** **Detailed view** in the top bar opens every filter
cluster, explainer, coverage chart and secondary column at once. **Simple
view** — the default — shows only the layer a page needs to do its work, and
each second layer opens with one click on its own block. The choice is
remembered per browser and hides no feature: a closed block still submits its
fields, and the filters in force are listed as removable chips above the list.

**Collapsible panels.** Detail panels — an issue's report form, the exception
categories, the log tail — are native `<details>` elements that remember
whether you left them open.

## Help

Every screen carries a **Help** button next to the date pill. It opens a short
panel for the page you are on — what the screen is for, the steps for its
actions and one tip — so nobody has to leave the record they are looking at.
The entries live in `lib/help.php`, one per page.

The full manual is [help.md](help.md). It opens with a step-by-step quick start
for entering data — issues, tasks, documents, forms, deviations, OT findings and
accounts — then covers sign-in and roles, every register, the Word exports, data
conventions and troubleshooting.

## Offline

Nothing on any screen is fetched from the internet: every stylesheet, script, icon and font
is served by the app itself (the font stack is the system UI font), so a plant network with
no internet works exactly like the demo. No external API is called either — Word files are
built by the bundled OOXML writer.

`sw.js` is a service worker that precaches `assets/app.css`, `assets/app.js` and
`offline.html`:

| Request | Strategy |
| --- | --- |
| `assets/*` (CSS, JS, images) | cache-first, refreshed in the background |
| Page navigations (`index.php?page=…`) | network-first, falls back to `offline.html` |
| Anything else | network only — pages and downloads are rendered per session and are never cached |

While the browser reports no connection, the app shows a red banner and **blocks form
submissions** instead of queueing them: every POST carries a one-shot CSRF token, so a
replayed submission would be refused anyway. Board drag-and-drop is blocked the same way.

Service workers only run over HTTPS or on `localhost`. Opened as plain `http://` over the
LAN the app behaves as before — no caching, no offline page. Bump `VERSION` in `sw.js`
when `app.css` or `app.js` change so clients pick up the new files.

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
lib/notify.php       alert rules: assignment, critical, due, expiring, review
lib/templates.php     template markup -> HTML blocks
lib/word.php         OOXML (.docx) writer + ZIP encoder
lib/report.php        risk scoring, report-form binding, issue report .docx blocks
lib/sops.php          document control: validation, code allocation, revisions, acknowledgement
views/               header, footer, login and one file per page
assets/app.css       design tokens + all styling (red / blue / orange accents)
assets/app.js        drag & drop, delete confirms, dropzone, live risk score
schema.sql           optional manual import
.htaccess            Apache hardening: deny uploads/lib/tests, deflate, caching
router.php           same denies for the PHP built-in server (php -S … router.php)
tests/run.php        dependency-free unit checks (php tests/run.php)
tests/verify-audit.php  re-reads the trail and checks its hash chain
logs/app.log         error log written by the exception handler
logs/audit-anchors.log  head hashes written by tests/verify-audit.php
uploads/             evidence files (random names, direct access denied)
```

## Design accents

- **Red** — Physical Security, critical severity, open issues
- **Blue** — IT Security, policies, review states
- **Orange** — Strain Security (human factor), in-progress work
- **Green** — resolved / low risk / completed

The layout is intentionally compact (13px base, dense tables) so a whole
register or board fits on one screen.

## Notifications

Each account gets its own alerts, written by rules rather than by typing:

| Rule | Who is told | When |
| --- | --- | --- |
| Issue assigned | the account whose `name` or `username` matches the issue owner | issue created, or its owner changed |
| Critical issue | security managers and administrators | a Critical issue is created or raised to Critical |
| Issue due | the issue owner | 3 days before `due_at`, then daily while overdue |
| Exception expiring | managers, administrators and the owner | 30 days before `period_end`, then daily once expired |
| SOP review due | managers and administrators | 30 days before `review_at`, then daily while overdue |

The **Alerts** bell in the top bar shows the unread count and the latest eight; **Notifications**
(`?page=notifications`) lists them with unread/all and type filters, a **Mark read** button per row and
**Mark all read**. Opening an alert does not clear it, so "unread" keeps meaning unprocessed.

Time-based rules run when a page loads, at most once every five minutes per session, and carry a
date-stamped dedupe key — no cron job, and a second visit the same day adds nothing. Assignment names
are matched to accounts by name or username; an issue owned by a name with no account notifies nobody,
and you are never notified about a change you just made yourself.

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

### Tamper evidence

Append-only by convention is not the same as append-only in fact: anyone with a MySQL console can
`UPDATE` or `DELETE` a row. Every entry therefore carries two extra columns — `prev_hash`, the hash
of the entry written before it, and `row_hash`, a SHA-256 over its own stored fields (id, timestamp,
actor, role, action, record, label, detail, address, client) plus that link. The writer reads the
current tail under a row lock, so two simultaneous events cannot both link to the same predecessor.

```bash
php tests/verify-audit.php
# audit trail: 123 entries, chain intact (ids 1–123)          exit 0
# audit trail: FIRST BROKEN LINK at entry #60 — the entry no
#   longer matches the hash recorded for it                   exit 1
```

**Verify integrity** on the trail page runs the same walk in the browser, so the reader who needs the
proof does not need a shell: it answers `chain intact (ids 1–N)`, or names the first broken entry and
why, and compares the newest entry with the anchor file. It writes nothing — not a row, not an audit
event — so the trail an auditor checks is byte-for-byte the trail that was there before the click.
Anchoring stays with the console verifier, which is the one you put in cron.

It walks `audit_log` in id order, recomputes each hash and follows each link, stopping at the first
entry that does not match: a changed field reads as *no longer matches the hash*, a deleted row as
*no longer links to the entry before it*, and a row inserted behind the application's back as
*carries no hash*. Exit code 0 means intact, 1 means broken or unreadable, so it can run from cron or
a pre-audit checklist. Nothing on screen changed — the trail still reads the same columns.

What a chain alone cannot see: deleting the newest entries (nothing follows them), or the very
first entry (which links to an empty string). The verifier therefore **anchors the head outside
the database**: after a clean walk it appends the newest id and that entry's `row_hash` to
`logs/audit-anchors.log` — or to the file named by `SECOPS_AUDIT_ANCHOR` — and on the next run it
checks that the anchored entry is still there with the same hash. Deleting those entries, or
restoring an older database over the trail, then fails with `audit anchor: BROKEN at entry #N`.
`--no-anchor` verifies without advancing the file, for a read-only audit. Keep the anchor file (or
a copy) on a different backup from the database, and run the verifier daily so the anchor keeps
up: a deletion that happens after the last anchor is only named once the trail is anchored again.
Re-sealing is deliberately not offered: an existing hash is never rewritten, so a tampered chain
stays tampered.

## Backups and restore

The database holds the register, the controlled documents and the hash-chained trail; `uploads/`
holds the evidence files. Nothing in the app schedules a copy of either, and a backup you have
never restored is a hope rather than a control (ISO 27001 A.8.13). One command plus one quarterly
drill closes that gap.

### Take a backup

```bash
php tools/backup.php
```

```
dump      backups/secops-crm-20261127-123535.sql.gz
size      32.1 KB (gzip)
sha256    efffdb199c296925fd536054e1e2fdf1f7e160973f0b39adec2157d338f9d2ae
tables    17 · 386 rows
audit     head #133 of 133 entries · 9a7c01dc9ed0…
manifest  backups/secops-crm-20261127-123535.json
restore   scratch database + SECOPS_DB_NAME=<scratch> php tests/verify-audit.php
```

It reads the connection from `config.php` (or the `SECOPS_DB_*` variables), connects **read-only**
— no `migrate()`, no seeding, so a backup cannot change the database it copies — and writes two
files into `backups/`:

- **`secops-crm-<date>-<time>.sql.gz`** — a `--single-transaction` dump, consistent even while
  people are using the app, which is also what keeps the audit chain inside it valid.
- **`secops-crm-<date>-<time>.json`** — the manifest: sha256, size, per-table row counts, and the
  trail's newest id and hash. This is what you compare against after a restore.

The dump is kept only when mysqldump wrote its own `-- Dump completed` line, so a full disk or a
killed server fails loudly instead of leaving a plausible half file. `--keep=30` prunes to the
newest 30, `--out=D:\backups\secops` writes elsewhere, `--help` lists both.

It needs `mysqldump` or `mariadb-dump`; when it is not on `PATH`, name it once:

```bat
set SECOPS_MYSQLDUMP=C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqldump.exe
php tools\backup.php
```

Daily, from cron (or Task Scheduler):

```bash
0 2 * * * cd /var/www/security-crm && php tools/backup.php --keep=30
```

Evidence files are **not** inside the dump — copy them with it:

```bash
tar -czf backups/uploads-$(date +%F).tar.gz uploads
```

### Restore drill

Do this once a quarter and before any upgrade. A restore you have never run is the part that
fails.

**1. Restore into a scratch database** — never over the live one:

```bash
mysql -u root -p -e "DROP DATABASE IF EXISTS security_crm_restore_check; CREATE DATABASE security_crm_restore_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c backups/secops-crm-20261127-123535.sql.gz | mysql -u root -p security_crm_restore_check
```

**2. Walk the chain in the copy**, with a throwaway anchor file:

```bash
SECOPS_DB_NAME=security_crm_restore_check SECOPS_AUDIT_ANCHOR=/tmp/restore-drill-anchors.log \
  php tests/verify-audit.php
# audit trail: 133 entries, chain intact (ids 1–133)
# audit anchor: none recorded yet
# audit anchor: appended id 133 to /tmp/restore-drill-anchors.log
```

The separate `SECOPS_AUDIT_ANCHOR` matters: a drill must never judge, or advance, the anchor the
live installation relies on. `--no-anchor` alone is not enough, because the verifier would still
compare the restored copy with the live anchor and call an older (perfectly good) backup
"restored from an older copy".

**3. Compare with the manifest.** The printed `ids 1–N` must equal `audit.head_id`, and a count
or two should match `database.row_counts`:

```sql
SELECT COUNT(*) FROM security_crm_restore_check.issues;   -- 12 in the manifest above
SELECT COUNT(*) FROM security_crm_restore_check.audit_log; -- 133
```

**4. Drop the scratch database.**

```sql
DROP DATABASE security_crm_restore_check;
```

Reading the result:

- **`chain intact` with matching counts** — the dump is complete and restorable. That is the
  pass condition.
- **a broken link inside the copy** — the dump was cut short, or someone edited `audit_log` by
  hand before it was taken. Take a fresh dump and investigate the live trail.
- **a head id lower than the manifest** — you restored an older dump. Normal for last week's
  backup, not for the one you just took.

The real restore is the same thing pointed at the live database: stop the app, restore the dump,
put the matching `uploads/` files back, then run `php tests/verify-audit.php` once. If the copy is
older than the last anchor, it answers `audit anchor: BROKEN … the database was restored from an
older copy` — that is the anchor working as designed, and the entry it names is the first one the
restore dropped. Keep the anchor file on a different backup from the database, as *Tamper
evidence* says above.

## Known limitations

- **One plant, one database.** There is no tenant or site switch; a second plant
  needs a second copy and database.
- **Files live on local disk.** Evidence and `logs/app.log` are not replicated;
  include `security-crm/uploads/` and the database in the same backup
  (`tools/backup.php` covers the database half).
- **Search is SQL `LIKE`.** Fine for a few thousand rows, not a search engine.
- **Exports are `.docx` only.** PDF would need an external converter.
- **No email.** Alerts are in-app; nothing leaves the server.

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

5. Behind a reverse proxy, set `SECOPS_TRUSTED_PROXIES` to its address so the
   audit trail and sign-in throttling see the user's LAN address, not the
   proxy's (see [Intranet deployment](#intranet-deployment)). Only list
   addresses that really are proxies: headers from anyone else are ignored.

### Tests

```bash
php tests/run.php            # unit checks: tags, risk, clamping, dates, parsing,
                             # client IP, audit + hash chain, notifications, router ↔ permission wiring
php tests/verify-audit.php   # live check: walks audit_log and names the first broken link
```
