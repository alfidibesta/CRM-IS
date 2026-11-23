# SecOps CRM — user help

Information security work management for the plant floor: issue reports with
evidence and persons involved, a workflow board, a controlled SOP library, a
form/template library with Word export, an EDLP exception register and an
audited trail of every change.

This file is the long-form manual. Every screen also carries a **Help** button
in the top bar that explains that screen in place, so you rarely need to leave
the page you are on.

---

## 1. Signing in

Open the site URL; you land on the sign-in screen.

- Use the account issued by your Security Manager. Usernames and passwords are
  case-sensitive; the password field accepts paste.
- Five failed attempts lock an account for 15 minutes. 25 failed attempts from
  one address in 15 minutes block that address for 15 minutes.
- Signing in, a failed attempt, a lockout and an address block are all written
  to the audit trail.

### Roles

| Role | What it is for |
| --- | --- |
| **Administrator** | Everything: records, templates, exceptions, users, the permission matrix. |
| **Security Manager** | All records and templates; approves and closes; no user administration. |
| **Analyst** | Investigates: reports issues, uploads evidence, moves board cards, edits issues. |
| **Viewer** | Read-only registers, audit trail and report exports. Persons involved and evidence are restricted to the investigation roles. |

Exact permissions per role are shown on **Users & Authority → Authority
matrix**, which is generated from the same catalogue the code enforces, so the
document and the behaviour cannot drift apart.

---

## 2. The frame around every page

- **Sidebar** — the seven main registers, then category shortcuts (Physical, IT,
  Strain) and your most-used tags, then Administration (users, system log) when
  your role allows it. The current page is highlighted.
- **Top bar** — page title and purpose, the **Compact** spacing toggle, the
  **Dim** light/dark toggle (both remembered in the browser), the ISO scope and
  date pills, **Help** for this screen, **Alerts** (the bell), your account chip
  and **Sign out**.
- **Alerts bell** — unread count plus the latest eight notifications, with
  "Mark all read" and a link to the full list. Notifications are created when an
  issue is assigned to you, a Critical issue is raised, an item falls due, an
  exception is about to expire or a document review approaches.
- **Flash messages** — a green band confirms a save; a red band reports a
  validation error. Both disappear after the next action.

Both toggles and your session survive page changes. The interface works down to
phone widths: the sidebar becomes a two-column nav and wide tables scroll
inside their card instead of pushing the page sideways.

---

## 3. Security Overview (dashboard)

The posture snapshot:

- **Counters** — active documents, open issues, tasks in flight, board
  completion, evidence on file, form templates, EDLP exceptions, accounts.
- **Issues by category** and **Status split** — proportions across Physical, IT
  and Strain security, and across Open / In progress / Contained / Resolved.
- **Risk ratings** — the likelihood × impact heat for unresolved issues.
- **Latest activity**, **Recently reported**, **Audit trail**,
  **EDLP exceptions expiring**, **Next document reviews**, **Form templates** —
  the newest items in each area, each linking to its record.

The page is read-only.

---

## 4. Security Issues (register)

Every finding, newest first.

- **Report a new issue** (with `issue.create`): title, category, severity, owner,
  location, department, asset, dates, tags, description and the first risk
  assessment. The risk score is **likelihood × impact** (1–5 each), so it runs
  from 1 to 25.
- **Find** by code, title, owner or asset with the search box, or narrow with the
  category/tag chips, severity, status and owner filters. Filters stay in the
  URL, so a view can be bookmarked.
- **Change status** straight from the row with the select (audited).
- **Word report** — the `.docx` button on a row downloads that issue's formatted
  report without opening it.

Issue codes (`SEC-1001`, `SEC-1002`, …) are assigned automatically and never
reused within a run of records.

---

## 5. Issue Detail Report

The investigation record, in numbered sections:

1. **Report details** — the fields above plus reviewed/approved by.
2. **Risk assessment** — likelihood, impact and the derived score, root cause,
   immediate and corrective action.
3. **Persons involved** — suspects and witnesses with employee number,
   department, position, shift, involvement (Primary, Accomplice, Witness,
   Victim, Unknown), contact, statement and whether they were interviewed.
   Names, contact details and statements need `issue.person.read` (manager,
   analyst, administrator); other roles see a restricted note instead.
4. **Evidence & attachments** — upload files (PDF, images, Office documents,
   text, CSV, ZIP) with a kind label and a note; download or delete them again.
   The list and its downloads need `file.download`, which the auditor/viewer
   role does not hold. Downloads are audited and permission-checked; uploaded
   files are never served as plain static files.
5. **Remediation tasks** — cards linked to this issue; they appear on the
   workflow board.
6. **Activity log** — every status change, field change, note, upload and task
   action on this record, newest first. Use "add note" for anything that happened
   outside the system.

Above the sections:

- **Download report (.docx)** — the same content as a Word document.
- **Report form** — choose a form template for this issue's report, adjust the
  report layout for this issue, or reset it back to the template.

---

## 6. Workflow Board

Five columns — **Backlog**, **Planned**, **In progress**, **Review**, **Done**.

- Drag a card to reorder it or move it to another column. The move is saved
  immediately (JSON, same request) and audited.
- **+ Add task** — title, column, priority, owner, category (Physical, IT,
  Strain, General), due date, and optionally the issue it belongs to.
- Delete a card when it is no longer needed.

Moving cards requires `task.move`; viewers see the board read-only.

---

## 7. SOP & Policy Library

Controlled documents with code, type (SOP / Policy / Work instruction), scope,
version, owner, status (Active, Draft, In Review, Obsolete) and the next review
date.

- Filter by type and scope chips, or search code, title and owner.
- Open a row for the preview and the metadata.
- **Add a document** (`sop.manage`, manager and administrator) — "+ New
  document" takes a code (leave it blank for the next free code in the scope,
  e.g. `PHYS-004`), title, type, scope, version, owner, status, effective and
  review dates, summary and the controlled text in the same light markup the
  issue reports use. An **Active** document needs an effective date, and the
  review date cannot fall before it. Every save is audited; a rejected save
  returns to the form with what you typed still there.
- **Edit or delete** — "Edit document" on a row opens the same form in place;
  the delete button removes the record permanently. Prefer marking a superseded
  document **Obsolete** so the history stays readable.
- **Version history** — every save keeps a full snapshot of the document
  (all metadata plus the controlled text), the **reason for change** you type,
  who saved it and when. Open a row and expand *Version history* to list them;
  *Read this version* opens that snapshot on its own page, exactly as it was
  saved. The first snapshot of an existing document is recorded as its baseline,
  and a save that changes nothing does not create a revision.
- **Acknowledgement** — on an **Active** document, "I have read vX" records that
  you have read that exact revision. The **Read** column shows your own state,
  and for document control the count of active accounts that have read the
  current revision plus who has not. A new revision resets the count, so an
  edited policy has to be read again — that is the point of the record.
- **Add a template** — "+ New template" opens the form-template form on the
  Templates page.

A document whose review date falls within 30 days (or has passed) raises a
review alert for managers and administrators. Reading is recorded in the audit
trail as *Policy acknowledged* with the revision number, so the register and
the trail agree.

---

## 8. Form Templates and Template Detail

Controlled forms — NDAs, agreements, security forms — stored as simple text and
delivered as `.docx`.

**Library** — search by code, name or category; "Preview & fill" opens the
detail page; the `.docx` button downloads the blank form.

**Detail** —

- **Fill in & download** — type the reference and any placeholder values, then
  download a Word file with them inserted.
- **Preview** — the document as it will print.
- **Edit source** (`template.edit`) — the body supports `# heading`,
  `## section`, `- bullet`, `1. numbered`, `[ ] checkbox`, `| table | rows |`
  and `{{PLACEHOLDER}}` fields.
- **Reports using this form** — the issues that selected this template.
- **Delete** — retires the template; existing reports keep their saved layout.

---

## 9. EDLP Exceptions

The register of approved deviations from the standard control (USB media,
websites, cameras, and so on).

- Each entry carries code (`EXC-001`), title, category, target/asset,
  justification, compensating control, period start and end, status (Active,
  Pending, Revoked), risk level, owner and approver.
- Filter by status or category, or search by title, target and reference;
  expand a row to read the details.
- **+ Add EDLP exception** (`exception.manage`) records a new deviation.
- **Exception categories** at the bottom of the page maintain the category list
  used by the register; renaming a category carries its exceptions with it, and
  a category in use cannot be deleted.
- **Coverage by category** and the expiry list show where the register is thin
  and what is about to lapse.

An exception expiring within 30 days — or already expired — notifies the owner
and the security managers.

---

## 10. Audit Trail

Every recorded action: sign-in and failed sign-in, issue and task changes,
template and exception changes, user administration, evidence upload, download
and deletion, CSV exports.

- **Filters** — free text (reference, detail, actor), action, record type, actor,
  and a from/to date range; "Apply filters" combines them.
- **Export CSV** — the filtered trail (up to 5,000 rows) with columns for when,
  actor, role, action, record type, record ID, reference, detail, IP and client.
- **Paging** — "Newer" / "Older", newest entry first.

The trail is append-only in normal use. Each entry stores the SHA-256 hash of the
entry before it, so a modified or deleted row breaks the chain:

```bash
php tests/verify-audit.php
```

That command reports the first entry whose stored hash no longer matches. After a clean walk it
also compares the trail with the last line of `logs/audit-anchors.log` — the head hash kept
outside the database — and appends a fresh one, so deleting the newest entries is detected too.
Use `--no-anchor` for a read-only check, or `SECOPS_AUDIT_ANCHOR` to keep the file on another
disk or share.

---

## 11. Users & Authority

- **Accounts** — create (unique username, password of at least 8 characters),
  edit name, e-mail, role and active state, reset a password, delete.
- **Roles** — what each role is for.
- **Authority matrix** — every permission against every role.
- **How authority is enforced** — the short version: every action maps to a
  permission, checked on the server before the database is touched.

Guard rails: you cannot demote, disable or delete yourself, and the last active
administrator cannot be deleted.

---

## 12. Notifications

- Tabs for **unread** and **all**, plus a kind filter (assigned, critical, due,
  exception expiring/expired, SOP review).
- **Open** jumps to the record; **Mark read** clears one; **Mark all** clears the
  list.
- Paged, newest first. The bell in the top bar mirrors the unread count.

---

## 13. System Log

The tail of the application's error log, with a keyword filter. It is for faults
(upload failures, database errors), not for change history — that is the audit
trail.

---

## 14. Word exports

- **Issue report** — `SEC-xxxx-issue-report.docx` from the issue page or the
  register row.
- **Form template** — `TPL-xxx-name.docx`, blank or with the placeholders you
  filled in.
- Both are generated server-side (no Office installation needed) and every
  export is audited.

---

## 15. Data conventions

| Term | Meaning |
| --- | --- |
| `SEC-1001` | Issue code, assigned in sequence. |
| `EXC-001` | EDLP exception code. |
| `TPL-001` | Form template code. |
| Risk score | likelihood (1–5) × impact (1–5), 1–25. |
| Severity | Critical, High, Medium, Low. |
| Issue status | Open, In progress, Contained, Resolved (Resolved stamps a closed date). |
| Document status | Active, Draft, In Review, Obsolete. |

---

## 16. Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| "Invalid or expired form token" (419) | The page sat open too long or the session expired. Go back, reload and submit again. |
| "That upload is larger than the server accepts" | The PHP upload limit is lower than the file. The issue page prints the effective limit; raise `upload_max_filesize` / `post_max_size` on the server. |
| "Account locked" | Five failed attempts; wait 15 minutes or ask an administrator to reset the password, which also clears the lock. |
| "Too many failed attempts from this address" | Address throttle (25 failures in 15 minutes). Wait 15 minutes. Behind a reverse proxy, set `SECOPS_TRUSTED_PROXIES` so the real client address is used. |
| "That form was already submitted" | Double submit protection on creation forms. Nothing was created twice. |
| A page looks stale after an update | The service worker serves the cached CSS/JS; a reload picks up the new version. |
| The app looks different on a phone | Expected: the sidebar becomes a two-column nav and wide tables scroll inside their card. |

---

## 17. Where to look next

| File | Contents |
| --- | --- |
| `help.md` | This manual — what each screen does and how to use it. |
| `README.md` | Installation, configuration, intranet deployment, security notes, tests. |
| `tests/run.php` | The test suite (`php tests/run.php`). |
| `tests/verify-audit.php` | Audit hash-chain verification. |
