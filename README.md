# Security CRM (IS)

An information-security workspace for a manufacturing plant, written in **native
PHP 8 + MySQL** — no framework, no Composer, no build step. Drop the
`security-crm` folder into Laragon and it sets itself up on the first request.

| Module | What it does |
| --- | --- |
| **Dashboard** | posture at a glance: stats, issues by category, risk ratings, live activity, upcoming reviews |
| **SOP & Policy library** | typed, scoped, versioned documents with review dates and the controlled text inline |
| **Workflow board** | drag-and-drop kanban (Backlog → Planned → In Progress → Review → Done) backed by the issue register |
| **Issue register** | categories, tags, severity, search, risk score, quick status changes; each issue opens to a full report |
| **Issue report** | 5×5 risk assessment, root cause, actions, persons involved, evidence upload, activity log, `.docx` export that follows a chosen form template (adjustable per report) |
| **Form templates** | NDA, supplier & manufacturing agreements, visitor undertakings, audit checklists… downloadable as real Word files |
| **EDLP exceptions** | time-bound deviations (USB, websites, cameras, cloud apps) with period, justification, compensating control and derived Active / Expiring / Expired state |
| **Users & authority** | four roles with a server-enforced permission matrix, login lockout, audit trail |

## Run it in Laragon

1. Copy the **`security-crm`** folder into Laragon's web root, e.g.
   `C:\laragon\www\security-crm\`.
2. Laragon → **Start All** (Apache + MySQL, both green).
3. Open <http://localhost/security-crm/> (or `http://security-crm.test/` with
   auto virtual hosts).

The first page load creates the `security_crm` database, its eleven tables and
the sample data, including the accounts below. Nothing to import.

> **Deeper setup:** [`security-crm/README.md`](security-crm/README.md) covers the
> `AllowOverride All` setting that `.htaccess` needs, the `php.ini` upload
> limits the app reports, the `SECOPS_DB_*` variables, the full page and
> permission reference, the deployment checklist and a troubleshooting table.
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

## Requirements

PHP **8.1+** with `pdo_mysql`, `mbstring` and (optionally) `fileinfo`, plus
MySQL 8 / MariaDB — all bundled with Laragon. `mod_rewrite` and `ext-zip` are
not used; Word files are built by a small OOXML writer in `lib/word.php`.

## Repository layout

```
security-crm/   the whole application — see security-crm/README.md
README.md       this page
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
