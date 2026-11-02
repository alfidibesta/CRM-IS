<?php
/**
 * PDO connection, schema migration and one-time seed data.
 *
 * The app bootstraps itself on the first request: it creates the database,
 * every table, adds any missing columns (so older installs upgrade in place)
 * and seeds sample data when a table is empty.
 */

declare(strict_types=1);

function db(array $cfg): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset']);
    $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $dbname = str_replace('`', '', $cfg['dbname']);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");

    migrate($pdo);
    seedIfEmpty($pdo);

    return $pdo;
}

function migrate(PDO $pdo): void
{
    $ddl = <<<'SQL'
CREATE TABLE IF NOT EXISTS sops (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    code          VARCHAR(20)  NOT NULL UNIQUE,
    title         VARCHAR(200) NOT NULL,
    type          ENUM('Policy','SOP','Guideline') NOT NULL DEFAULT 'SOP',
    scope         ENUM('Physical','IT','Strain','General') NOT NULL DEFAULT 'General',
    version       VARCHAR(10)  NOT NULL DEFAULT '1.0',
    owner         VARCHAR(100) NOT NULL DEFAULT '',
    status        ENUM('Active','Draft','In Review','Obsolete') NOT NULL DEFAULT 'Draft',
    effective_at  DATE NULL,
    review_at     DATE NULL,
    summary       VARCHAR(500) NOT NULL DEFAULT '',
    content       TEXT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS issues (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    code              VARCHAR(20)  NOT NULL UNIQUE,
    title             VARCHAR(200) NOT NULL,
    category          ENUM('Physical Security','IT Security','Strain Security') NOT NULL,
    severity          ENUM('Critical','High','Medium','Low') NOT NULL DEFAULT 'Medium',
    status            ENUM('Open','In Progress','Contained','Resolved') NOT NULL DEFAULT 'Open',
    owner             VARCHAR(100) NOT NULL DEFAULT '',
    location          VARCHAR(120) NOT NULL DEFAULT '',
    department        VARCHAR(100) NOT NULL DEFAULT '',
    asset             VARCHAR(140) NOT NULL DEFAULT '',
    reported_by       VARCHAR(100) NOT NULL DEFAULT '',
    reported_at       DATE NULL,
    due_at            DATE NULL,
    closed_at         DATE NULL,
    likelihood        TINYINT NOT NULL DEFAULT 3,
    impact            TINYINT NOT NULL DEFAULT 3,
    risk_score        INT NOT NULL DEFAULT 9,
    root_cause        TEXT NULL,
    immediate_action  TEXT NULL,
    corrective_action TEXT NULL,
    reviewed_by       VARCHAR(100) NOT NULL DEFAULT '',
    reviewed_at       DATE NULL,
    approved_by       VARCHAR(100) NOT NULL DEFAULT '',
    description       TEXT NULL,
    tags              VARCHAR(255) NOT NULL DEFAULT '',
    report_template_id INT NULL,
    report_body       MEDIUMTEXT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_issues_status (status),
    INDEX idx_issues_category (category)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tasks (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    title         VARCHAR(200) NOT NULL,
    column_key    ENUM('backlog','planned','in_progress','review','done') NOT NULL DEFAULT 'backlog',
    priority      ENUM('Critical','High','Medium','Low') NOT NULL DEFAULT 'Medium',
    owner         VARCHAR(100) NOT NULL DEFAULT '',
    category      ENUM('Physical','IT','Strain','General') NOT NULL DEFAULT 'General',
    due_at        DATE NULL,
    issue_id      INT NULL,
    position      INT NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tasks_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(40) NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(120) NOT NULL DEFAULT '',
    role            ENUM('admin','manager','analyst','viewer') NOT NULL DEFAULT 'viewer',
    password_hash   VARCHAR(255) NOT NULL,
    active          TINYINT(1) NOT NULL DEFAULT 1,
    failed_attempts TINYINT NOT NULL DEFAULT 0,
    locked_until    DATETIME NULL,
    last_login      DATETIME NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(40) NOT NULL DEFAULT '',
    ip         VARCHAR(45) NOT NULL DEFAULT '',
    ok         TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_ip (ip, created_at),
    INDEX idx_attempts_user (username, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS suspects (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    issue_id     INT NOT NULL,
    name         VARCHAR(120) NOT NULL,
    employee_no  VARCHAR(40)  NOT NULL DEFAULT '',
    department   VARCHAR(100) NOT NULL DEFAULT '',
    position     VARCHAR(100) NOT NULL DEFAULT '',
    shift        VARCHAR(40)  NOT NULL DEFAULT '',
    involvement  ENUM('Primary','Accomplice','Witness','Victim','Unknown') NOT NULL DEFAULT 'Witness',
    contact      VARCHAR(60)  NOT NULL DEFAULT '',
    statement    TEXT NULL,
    interviewed  TINYINT(1) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_suspects_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS issue_files (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    issue_id      INT NOT NULL,
    original_name VARCHAR(200) NOT NULL,
    stored_name   VARCHAR(64)  NOT NULL,
    ext           VARCHAR(10)  NOT NULL DEFAULT '',
    mime          VARCHAR(100) NOT NULL DEFAULT '',
    size_bytes    INT NOT NULL DEFAULT 0,
    kind          ENUM('Photo','Screenshot','Log','Document','Statement','Other') NOT NULL DEFAULT 'Document',
    note          VARCHAR(255) NOT NULL DEFAULT '',
    uploaded_by   VARCHAR(100) NOT NULL DEFAULT '',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_files_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS issue_events (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    issue_id   INT NOT NULL,
    actor      VARCHAR(100) NOT NULL DEFAULT '',
    kind       ENUM('created','status','field','note','suspect','file','task') NOT NULL DEFAULT 'note',
    message    VARCHAR(400) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_events_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
    INDEX idx_events_issue (issue_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS templates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(20) NOT NULL UNIQUE,
    name        VARCHAR(160) NOT NULL,
    category    ENUM('Legal','HR','Security','Audit','Operations') NOT NULL DEFAULT 'Legal',
    version     VARCHAR(10) NOT NULL DEFAULT '1.0',
    owner       VARCHAR(100) NOT NULL DEFAULT '',
    description VARCHAR(400) NOT NULL DEFAULT '',
    body        MEDIUMTEXT NULL,
    downloads   INT NOT NULL DEFAULT 0,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exception_categories (
    id    INT AUTO_INCREMENT PRIMARY KEY,
    name  VARCHAR(60) NOT NULL UNIQUE,
    tone  ENUM('red','blue','orange','green','neutral') NOT NULL DEFAULT 'neutral',
    sort  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exceptions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    code          VARCHAR(20)  NOT NULL UNIQUE,
    title         VARCHAR(200) NOT NULL,
    system        VARCHAR(40)  NOT NULL DEFAULT 'EDLP',
    category      VARCHAR(60)  NOT NULL DEFAULT '',
    target        VARCHAR(180) NOT NULL DEFAULT '',
    detail        TEXT NULL,
    justification TEXT NULL,
    control       VARCHAR(255) NOT NULL DEFAULT '',
    period_start  DATE NULL,
    period_end    DATE NULL,
    status        ENUM('Active','Pending','Revoked') NOT NULL DEFAULT 'Active',
    risk_level    ENUM('High','Medium','Low') NOT NULL DEFAULT 'Medium',
    owner         VARCHAR(100) NOT NULL DEFAULT '',
    approved_by   VARCHAR(100) NOT NULL DEFAULT '',
    ref_no        VARCHAR(40)  NOT NULL DEFAULT '',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_exc_period (period_end),
    INDEX idx_exc_status (status)
) ENGINE=InnoDB;
SQL;

    // One exec() per statement: multi-statement queries are not portable
    // across pdo_mysql configurations.
    foreach (array_filter(array_map('trim', explode(';', $ddl))) as $statement) {
        $pdo->exec($statement);
    }

    // Upgrade path for databases created before these columns existed.
    ensure_columns($pdo, 'issues', [
        'department'        => "VARCHAR(100) NOT NULL DEFAULT ''",
        'asset'             => "VARCHAR(140) NOT NULL DEFAULT ''",
        'reported_by'       => "VARCHAR(100) NOT NULL DEFAULT ''",
        'closed_at'         => 'DATE NULL',
        'likelihood'        => 'TINYINT NOT NULL DEFAULT 3',
        'impact'            => 'TINYINT NOT NULL DEFAULT 3',
        'risk_score'        => 'INT NOT NULL DEFAULT 9',
        'root_cause'        => 'TEXT NULL',
        'immediate_action'  => 'TEXT NULL',
        'corrective_action' => 'TEXT NULL',
        'reviewed_by'       => "VARCHAR(100) NOT NULL DEFAULT ''",
        'reviewed_at'       => 'DATE NULL',
        'approved_by'       => "VARCHAR(100) NOT NULL DEFAULT ''",
        'updated_at'        => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'tags'              => "VARCHAR(255) NOT NULL DEFAULT ''",
        'report_template_id' => 'INT NULL',
        'report_body'       => 'MEDIUMTEXT NULL',
    ]);
    ensure_columns($pdo, 'sops', ['content' => 'TEXT NULL']);
}

/** Add missing columns to an existing table (no-op when present). */
function ensure_columns(PDO $pdo, string $table, array $defs): void
{
    $have = [];
    $st = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table));
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $col) {
        $have[strtolower((string) $col)] = true;
    }
    foreach ($defs as $col => $ddl) {
        if (!isset($have[strtolower($col)])) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $col . '` ' . $ddl);
        }
    }
}

/* ------------------------------------------------------------------ seed */

function seedIfEmpty(PDO $pdo): void
{
    seed_users($pdo);
    seed_sops($pdo);
    seed_issues($pdo);
    seed_tasks($pdo);
    seed_templates($pdo);
    seed_issue_extras($pdo);
    seed_exceptions($pdo);
    seed_activity($pdo);
}

function seed_users(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
        return;
    }
    $rows = [
        ['admin',   'R. Hartono',   'admin@plant.local',   'admin',   'Admin@123'],
        ['manager', 'S. Wijaya',    'security@plant.local','manager', 'Manager@123'],
        ['analyst', 'D. Kusuma',    'analyst@plant.local', 'analyst', 'Analyst@123'],
        ['viewer',  'A. Pratama',   'audit@plant.local',   'viewer',  'Viewer@123'],
    ];
    $st = $pdo->prepare('INSERT INTO users (username,name,email,role,password_hash,active) VALUES (?,?,?,?,?,1)');
    foreach ($rows as $r) {
        $st->execute([$r[0], $r[1], $r[2], $r[3], password_hash($r[4], PASSWORD_DEFAULT)]);
    }
}

function seed_sops(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM sops')->fetchColumn() > 0) {
        return;
    }

    $sops = [
        ['ISMS-001','Information Security Policy','Policy','General','4.2','R. Hartono','Active','2025-01-15','2026-01-15','Top-level statement of management commitment to protecting plant and factory information assets.',
            "# Information Security Policy\n\n## 1. Management commitment\nManagement commits to protecting the confidentiality, integrity and availability of all information assets used by the plant, and to complying with applicable legal, regulatory and contractual requirements.\n\n## 2. Scope\nThis policy applies to all employees, contractors, vendors and systems at Plant 1, including IT, OT/SCADA, MES and support functions.\n\n## 3. Principles\n- Access to information is granted on a need-to-know basis\n- Every significant security event is reported within 24 hours\n- Changes to production systems follow the approved change workflow\n- Supplier access is controlled, logged and reviewed quarterly\n\n## 4. Responsibilities\n| Role | Responsibility |\n| **Plant Manager** | Provides resources and approves this policy annually |\n| **Security Manager** | Maintains the ISMS, runs audits and reports to management |\n| **Department Heads** | Implement controls inside their area |\n| **All personnel** | Follow the policy and report incidents |"],
        ['ISMS-002','Access Control Policy','Policy','IT','3.1','D. Kusuma','Active','2025-03-01','2026-03-01','Rules for user accounts, privileged access and least-privilege on OT/IT networks.',
            "# Access Control Policy\n\n## 1. Account lifecycle\n1. Accounts are created only with an approved access request\n2. Shared or generic accounts are forbidden on systems that keep an audit trail\n3. Accounts are disabled within 24 hours of a leaver's exit\n4. Privileged accounts are reviewed monthly\n\n## 2. Password and authentication\n- Minimum 12 characters for standard accounts, 16 for privileged\n- Multi-factor authentication for remote and admin access\n- Service accounts use managed secrets, never personal credentials\n\n## 3. Segmentation\nCorporate, plant and SCADA networks are separated by firewalls. Any rule change needs Security Manager approval."],
        ['PHYS-001','Plant Physical Access SOP','SOP','Physical','2.4','S. Wijaya','Active','2025-02-10','2026-02-10','Badge issuance, visitor registration and gate checks for the production floor.',
            "# Plant Physical Access SOP\n\n## 1. Gates\n- Every person entering through Gate 1 and Gate 2 wears a visible badge\n- Vehicles are inspected against the delivery manifest\n- Night access (22:00 - 05:00) requires supervisor approval\n\n## 2. Badges\nBadges are issued by HR on an approved form, returned on the last working day, and reported lost within one hour.\n\n## 3. Guard rounds\n| Round | Time | Check |\n| **R1** | 08:00 | Server room, fire doors, chemical store |\n| **R2** | 14:00 | East gate, warehouse, SCADA cabinet |\n| **R3** | 22:00 | Server room, control room, perimeter |"],
        ['PHYS-002','Visitor & Contractor Escort SOP','SOP','Physical','1.8','S. Wijaya','In Review','2025-06-01','2026-06-01','Mandatory escort ratios and prohibited areas for external contractors.', null],
        ['IT-001','Patch Management SOP','SOP','IT','3.0','D. Kusuma','Active','2025-04-20','2026-04-20','Monthly patch cycle for Windows, MES and SCADA engineering workstations.',
            "# Patch Management SOP\n\n## 1. Cycle\n| Window | Target | Owner |\n| **Week 1** | Critical patches | IT Ops |\n| **Week 2** | Server and endpoint | IT Ops |\n| **Week 3** | OT / SCADA (with production approval) | Automation |\n\n## 2. Rules\n- Test on a staging workstation before production\n- SCADA patches need a change request and a rollback plan\n- Anything deferred is recorded with a risk acceptance signed by the Security Manager"],
        ['IT-002','Incident Response Policy','Policy','IT','2.2','A. Pratama','Active','2025-05-05','2026-05-05','Detection, escalation, containment and post-incident review for security events.',
            "# Incident Response Policy\n\n## 1. Severity\n| Level | Example | Response |\n| **SEV1** | Production data loss, ransomware | 15 minutes, war room |\n| **SEV2** | Unauthorised access to OT | 1 hour |\n| **SEV3** | Single phishing attempt | 1 business day |\n\n## 2. Phases\n1. Detect and report within 24 hours\n2. Contain: isolate, disable accounts, preserve logs\n3. Eradicate and recover\n4. Post-incident review within 5 working days"],
        ['IT-003','Removable Media Guideline','Guideline','IT','1.4','A. Pratama','Draft','2025-09-01','2026-09-01','Approved USB handling on the plant network and quarantined sample scanning.', null],
        ['STRAIN-001','Human Factor & Workload Policy','Policy','Strain','2.0','M. Larasati','Active','2025-03-15','2026-03-15','Shift-hour limits, fatigue reporting and security-relevant human error controls.',
            "# Human Factor & Workload Policy\n\n## 1. Limits\n- Maximum 12 consecutive hours on the control desk\n- Minimum 11 hours rest between shifts\n- No single person operates critical tasks for more than 8 hours without a break handover\n\n## 2. Fatigue reporting\nAnyone noticing fatigue-related errors reports them without blame. Repeated reports trigger a workload review.\n\n## 3. Security link\nFatigue and time pressure are treated as causes of security events: they must appear in every incident review."],
        ['STRAIN-002','Security Awareness Training SOP','SOP','Strain','2.1','M. Larasati','Active','2025-07-01','2026-07-01','Quarterly phishing drills and onboarding training for production staff.',
            "# Security Awareness Training SOP\n\n## 1. Schedule\n- Onboarding session before first system access\n- Quarterly phishing simulation for all office users\n- Annual refresher for production floor and contractors\n\n## 2. Pass criteria\n80% correct answers; two consecutive failures trigger a coaching session with the supervisor."],
        ['STRAIN-003','Clean Desk & Document Handling','Guideline','Strain','1.1','R. Hartono','Obsolete','2024-01-10','2025-01-10','Superseded by ISMS-001 section 7 on physical media handling.', null],
    ];

    $st = $pdo->prepare('INSERT INTO sops (code,title,type,scope,version,owner,status,effective_at,review_at,summary,content) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($sops as $s) {
        $st->execute($s);
    }
}

function seed_issues(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM issues')->fetchColumn() > 0) {
        return;
    }

    $defaults = [
        'department' => '', 'asset' => '', 'reported_by' => '', 'likelihood' => 3, 'impact' => 3,
        'root_cause' => null, 'immediate_action' => null, 'corrective_action' => null,
        'reviewed_by' => '', 'reviewed_at' => null, 'approved_by' => '',
    ];

    $rows = [
        ['code'=>'SEC-1001','title'=>'Unlocked server room door after shift change','category'=>'Physical Security','severity'=>'Critical','status'=>'Open','owner'=>'S. Wijaya','location'=>'HQ - Server Room','reported_at'=>'2025-10-02','due_at'=>'2025-11-05',
         'description'=>"Door contact sensor reported open for 40 minutes; badge log shows no guard round.\nObserved during the 22:00 round: server room door ajar, no person inside, alarm panel in bypass since 21:10.",
         'department'=>'IT Infrastructure','asset'=>'SR-A1 door controller / alarm panel','reported_by'=>'Night Security Patrol','likelihood'=>4,'impact'=>5,
         'root_cause'=>'Alarm panel left in bypass during cabling work and the work order was closed without restoring it.',
         'immediate_action'=>'Door secured, bypass removed, panel returned to normal, guard round re-issued.',
         'corrective_action'=>'Add bypass-restore step to the work order checklist; audit panel state at every round.','reviewed_by'=>'R. Hartono'],

        ['code'=>'SEC-1002','title'=>'Tailgating at east gate captured on camera','category'=>'Physical Security','severity'=>'High','status'=>'In Progress','owner'=>'S. Wijaya','location'=>'Plant - East Gate','reported_at'=>'2025-10-06','due_at'=>'2025-11-12',
         'description'=>"Two visitors entered without badge registration during the 14:00 shift handover.\nCamera 14 shows both persons following a forklift through the gate within the 8-second closing window.",
         'department'=>'Logistics','asset'=>'East gate turnstile / camera 14','reported_by'=>'Gate Operator','likelihood'=>4,'impact'=>4,
         'root_cause'=>'Turnstile configured for one badge per swipe with no tailgating alarm; gate left open for forklift traffic.',
         'immediate_action'=>'Both persons identified and registered retrospectively; gate discipline re-briefed.',
         'corrective_action'=>'Enable tailgating detection on camera 14 and split the forklift lane.','reviewed_by'=>'R. Hartono'],

        ['code'=>'SEC-1003','title'=>'Faded access signage at chemical warehouse','category'=>'Physical Security','severity'=>'Low','status'=>'Contained','owner'=>'B. Saputra','location'=>'Warehouse B','reported_at'=>'2025-09-18','due_at'=>'2025-11-30',
         'description'=>'Restricted-area signage no longer legible; replacement ordered.',
         'department'=>'Warehouse','asset'=>'Warehouse B entrance signage','reported_by'=>'EHS Officer','likelihood'=>2,'impact'=>2,
         'immediate_action'=>'Temporary printed sign posted; area temporarily restricted to badge holders.'],

        ['code'=>'SEC-1004','title'=>'Legacy HMI reachable from office VLAN','category'=>'IT Security','severity'=>'Critical','status'=>'In Progress','owner'=>'D. Kusuma','location'=>'Plant - Line 3','reported_at'=>'2025-10-08','due_at'=>'2025-11-08',
         'description'=>"Engineering HMI accepts logins from the corporate network; segmentation change requested.\nPort scan from the office VLAN confirms RDP and VNC open on 10.20.4.31.",
         'department'=>'Automation','asset'=>'HMI-03 (10.20.4.31)','reported_by'=>'D. Kusuma','likelihood'=>4,'impact'=>5,
         'root_cause'=>'Temporary rule added during an equipment trial in 2023 was never removed.',
         'immediate_action'=>'Rule restricted to the engineering jump host; monitoring enabled on the HMI.',
         'corrective_action'=>'Firewall rule review for all OT assets with automatic expiry on temporary rules.','reviewed_by'=>'R. Hartono'],

        ['code'=>'SEC-1005','title'=>'Shared operator account on MES terminal 4','category'=>'IT Security','severity'=>'High','status'=>'Open','owner'=>'D. Kusuma','location'=>'MES - Line 4','reported_at'=>'2025-10-11','due_at'=>'2025-11-15',
         'description'=>"Single credential used by three operators; audit trail unusable.",
         'department'=>'Production Line 4','asset'=>'MES terminal 4','reported_by'=>'MES Administrator','likelihood'=>3,'impact'=>4,
         'root_cause'=>'Account created as a fallback during a badge reader outage and never replaced.',
         'corrective_action'=>'Issue individual operator accounts; enforce badge logon on all MES terminals.'],

        ['code'=>'SEC-1006','title'=>'Outdated antivirus definitions on 12 workstations','category'=>'IT Security','severity'=>'Medium','status'=>'Contained','owner'=>'A. Pratama','location'=>'Plant - IT','reported_at'=>'2025-09-28','due_at'=>'2025-11-20',
         'description'=>"Definition updates stalled after the WSUS server migration.",
         'department'=>'IT Operations','asset'=>'WSUS / 12 engineering workstations','reported_by'=>'A. Pratama','likelihood'=>3,'impact'=>3,
         'immediate_action'=>'Workstations pushed to manual update; definitions current again.',
         'corrective_action'=>'Add definition age to the weekly health report.'],

        ['code'=>'SEC-1007','title'=>'Phishing email opened by procurement mailbox','category'=>'IT Security','severity'=>'High','status'=>'Resolved','owner'=>'A. Pratama','location'=>'HQ - Finance','reported_at'=>'2025-09-22','due_at'=>'2025-10-06',
         'description'=>"Credential harvested then reset; session revoked and mailbox rules purged.",
         'department'=>'Procurement','asset'=>'procurement@ mailbox','reported_by'=>'A. Pratama','likelihood'=>4,'impact'=>4,
         'root_cause'=>'Look-alike supplier domain with an invoice attachment; no banner on external mail.',
         'immediate_action'=>'Password reset, sessions revoked, malicious rules deleted, domain blocked.',
         'corrective_action'=>'External mail banner, supplier domain allow-list, targeted phishing drill.','reviewed_by'=>'R. Hartono','reviewed_at'=>'2025-10-08'],

        ['code'=>'SEC-1008','title'=>'Night shift exceeds 12 consecutive hours on control desk','category'=>'Strain Security','severity'=>'High','status'=>'Open','owner'=>'M. Larasati','location'=>'Plant - Control Room','reported_at'=>'2025-10-09','due_at'=>'2025-11-10',
         'description'=>"Fatigue-related lapses correlate with two badge-tailgating incidents.\nRoster review shows three operators above 60 hours in the last seven days.",
         'department'=>'Production Control','asset'=>'Control desk roster','reported_by'=>'Shift Supervisor','likelihood'=>4,'impact'=>4,
         'root_cause'=>'Understaffed night roster during the ramp-up to the Q4 order.',
         'corrective_action'=>'Re-balance the roster, add one relief operator, block overtime above policy in the scheduling tool.'],

        ['code'=>'SEC-1009','title'=>'No refresher training for 9 new operators','category'=>'Strain Security','severity'=>'Medium','status'=>'In Progress','owner'=>'M. Larasati','location'=>'HR / Training','reported_at'=>'2025-10-01','due_at'=>'2025-11-25',
         'description'=>"Onboarding batch missed the quarterly security awareness session.",
         'department'=>'HR / Training','asset'=>'Training matrix','reported_by'=>'HR Officer','likelihood'=>3,'impact'=>3,
         'corrective_action'=>'Run a make-up session and gate system access on training completion.'],

        ['code'=>'SEC-1010','title'=>'Pressure to bypass change approval on SCADA','category'=>'Strain Security','severity'=>'Critical','status'=>'Open','owner'=>'R. Hartono','location'=>'Plant - SCADA','reported_at'=>'2025-10-13','due_at'=>'2025-11-03',
         'description'=>"Production deadline used to justify an undocumented configuration change.\nChange was applied directly on the SCADA server without a ticket or rollback plan.",
         'department'=>'Automation','asset'=>'SCADA server / project archive','reported_by'=>'Automation Engineer','likelihood'=>3,'impact'=>5,
         'root_cause'=>'Deadline pressure plus unclear emergency-change authority.',
         'immediate_action'=>'Change documented retroactively and validated against the archive; no further edits allowed without ticket.',
         'corrective_action'=>'Define emergency change authority, require dual approval and a next-day review.','reviewed_by'=>'R. Hartono'],

        ['code'=>'SEC-1011','title'=>'Fire door propped open for forklift traffic','category'=>'Physical Security','severity'=>'Medium','status'=>'Resolved','owner'=>'B. Saputra','location'=>'Warehouse A','reported_at'=>'2025-09-12','due_at'=>'2025-09-26',
         'description'=>"Magnetic hold-open now tied to the alarm panel.",
         'department'=>'Warehouse','asset'=>'Fire door FD-12','reported_by'=>'EHS Officer','likelihood'=>3,'impact'=>4,
         'immediate_action'=>'Door released and re-closed; temporary barrier removed.',
         'corrective_action'=>'Hold-open device wired to the panel; daily visual check added to the opening round.','reviewed_by'=>'R. Hartono','reviewed_at'=>'2025-09-28'],

        ['code'=>'SEC-1012','title'=>'Shadow IT SaaS used for supplier drawings','category'=>'IT Security','severity'=>'Medium','status'=>'Open','owner'=>'A. Pratama','location'=>'HQ - Engineering','reported_at'=>'2025-10-15','due_at'=>'2025-11-28',
         'description'=>"CAD files uploaded to an unapproved file-sharing service.",
         'department'=>'Engineering','asset'=>'External file-sharing tenancy','reported_by'=>'A. Pratama','likelihood'=>3,'impact'=>4,
         'root_cause'=>'No approved external collaboration tool, so teams self-served.',
         'corrective_action'=>'Roll out an approved sharing service, revoke the tenancy, add SaaS discovery to monthly review.'],
    ];

    $st = $pdo->prepare('INSERT INTO issues (code,title,category,severity,status,owner,location,department,asset,reported_by,reported_at,due_at,likelihood,impact,risk_score,root_cause,immediate_action,corrective_action,reviewed_by,reviewed_at,description)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $row) {
        $r = array_merge($defaults, $row);
        $st->execute([
            $r['code'], $r['title'], $r['category'], $r['severity'], $r['status'], $r['owner'],
            $r['location'], $r['department'], $r['asset'], $r['reported_by'],
            $r['reported_at'], $r['due_at'],
            (int) $r['likelihood'], (int) $r['impact'], (int) $r['likelihood'] * (int) $r['impact'],
            $r['root_cause'], $r['immediate_action'], $r['corrective_action'],
            $r['reviewed_by'], $r['reviewed_at'], $r['description'],
        ]);
    }
}

function seed_tasks(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn() > 0) {
        return;
    }

    $idByCode = [];
    foreach ($pdo->query('SELECT id, code FROM issues')->fetchAll() as $r) {
        $idByCode[$r['code']] = (int) $r['id'];
    }

    $tasks = [
        ['Close badge-log gap for guard rounds',      'in_progress', 'High',     'S. Wijaya',    'Physical', '2025-11-05', 'SEC-1001'],
        ['Re-verify firewall rules for HMI VLAN',    'review',      'Critical', 'D. Kusuma',    'IT',       '2025-11-08', 'SEC-1004'],
        ['Write visitor escort SOP v2.0',            'in_progress', 'Medium',   'S. Wijaya',    'Physical', '2025-11-18', 'SEC-1002'],
        ['Draft incident response tabletop exercise','backlog',     'Medium',   'A. Pratama',   'IT',       '2025-12-01', null],
        ['Replace faded warehouse signage',          'done',        'Low',      'B. Saputra',   'Physical', '2025-10-30', 'SEC-1003'],
        ['Rotate shared MES operator accounts',      'planned',     'High',     'D. Kusuma',    'IT',       '2025-11-15', 'SEC-1005'],
        ['Schedule security awareness make-up session','planned',    'Medium',   'M. Larasati',  'Strain',   '2025-11-25', 'SEC-1009'],
        ['Review shift-hour exception report',       'backlog',     'High',     'M. Larasati',  'Strain',   '2025-11-10', 'SEC-1008'],
        ['Quarterly access rights recertification',  'backlog',     'Medium',   'D. Kusuma',    'IT',       '2025-12-15', null],
        ['Update SCADA change approval workflow',    'review',      'Critical', 'R. Hartono',   'Strain',   '2025-11-03', 'SEC-1010'],
        ['Add SaaS discovery to monthly review',     'planned',     'Medium',   'A. Pratama',   'IT',       '2025-11-28', 'SEC-1012'],
        ['Wire fire-door hold-open to alarm panel',  'done',        'Medium',   'B. Saputra',   'Physical', '2025-09-26', 'SEC-1011'],
    ];

    $st = $pdo->prepare('INSERT INTO tasks (title,column_key,priority,owner,category,due_at,issue_id,position) VALUES (?,?,?,?,?,?,?,?)');
    $positions = [];
    foreach ($tasks as $t) {
        $col = $t[1];
        $positions[$col] = ($positions[$col] ?? -1) + 1;
        $issueId = $t[6] !== null ? ($idByCode[$t[6]] ?? null) : null;
        $st->execute([$t[0], $col, $t[2], $t[3], $t[4], $t[5], $issueId, $positions[$col]]);
    }
}

function seed_templates(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM templates')->fetchColumn() > 0) {
        return;
    }

    $tpl = [
        ['TPL-001','Mutual Non-Disclosure Agreement (NDA)','Legal','2.1','Legal / Compliance',
         'Two-way confidentiality agreement for sharing drawings, process data and pricing during a supplier or customer evaluation.',
            "# Mutual Non-Disclosure Agreement\n\nThis Agreement is made on {{EFFECTIVE_DATE}} between:\n\n**Disclosing Party A:** {{PARTY_A}}, represented by {{REPRESENTATIVE_A}}, of {{ADDRESS_A}}\n**Disclosing Party B:** {{PARTY_B}}, represented by {{REPRESENTATIVE_B}}, of {{ADDRESS_B}}\n\n## 1. Purpose\nThe Parties wish to evaluate a manufacturing, supply or services relationship (the \"Purpose\") and may disclose Confidential Information to each other for that Purpose only.\n\n## 2. Confidential Information\nConfidential Information means all non-public information disclosed in any form, including:\n- drawings, CAD files, bills of material and process parameters\n- production rates, yields, costs, pricing and customer lists\n- access credentials, network diagrams and security configurations\n- information identified as confidential at disclosure, or which ought reasonably to be treated as confidential\n\n## 3. Exclusions\nConfidential Information does not include information that:\n1. is or becomes public through no fault of the Receiving Party\n2. was lawfully known to the Receiving Party before disclosure\n3. is received from a third party without restriction, or\n4. is independently developed without use of the disclosing Party's information\n\n## 4. Obligations\nThe Receiving Party shall:\n- use the information only for the Purpose\n- limit access to employees and contractors with a need to know\n- protect it with at least the care used for its own confidential information\n- not reverse engineer samples, tooling or prototypes received\n\n## 5. Term\nThis Agreement starts on {{EFFECTIVE_DATE}} and runs for {{TERM_YEARS}} years. Confidentiality obligations survive for {{SURVIVAL_YEARS}} years after each disclosure.\n\n## 6. Return or destruction\nOn written request the Receiving Party shall return or destroy all Confidential Information, except for one archival copy kept where retention is legally required.\n\n## 7. No licence\nNo rights or licences are granted by disclosure under this Agreement.\n\n## 8. Governing law\nThis Agreement is governed by the laws of {{JURISDICTION}}.\n\n---\n\n## Signatures\n\n| Party | Name | Title | Signature | Date |\n| **Party A** | {{SIGNATORY_A}} | {{TITLE_A}} |  |  |\n| **Party B** | {{SIGNATORY_B}} | {{TITLE_B}} |  |  |"],

        ['TPL-002','One-Way (Unilateral) NDA','Legal','1.4','Legal / Compliance',
         'Single-direction disclosure, e.g. showing a plant tour participant confidential process detail.',
            "# One-Way Non-Disclosure Agreement\n\nBetween **Disclosing Party:** {{PARTY_A}} and **Receiving Party:** {{PARTY_B}}, effective {{EFFECTIVE_DATE}}.\n\n## 1. Disclosure\nThe Disclosing Party may share Confidential Information relating to {{PURPOSE}}. The Receiving Party receives no obligation from the Disclosing Party under this Agreement.\n\n## 2. Use\nThe Receiving Party shall use the information only for {{PURPOSE}} and for no other purpose, and shall not copy, photograph or reproduce it without written consent.\n\n## 3. Permitted recipients\nThe Receiving Party may share the information only with its employees and contractors who need it and who are bound by obligations no less protective than this Agreement. The Receiving Party remains liable for their breaches.\n\n## 4. Compelled disclosure\nIf disclosure is required by law, the Receiving Party shall give the Disclosing Party prompt notice where lawful and cooperate in seeking protective treatment.\n\n## 5. Term and remedies\nThe Agreement runs for {{TERM_YEARS}} years. The Parties agree that damages alone may be inadequate and that injunctive relief may be sought.\n\n## 6. Signatures\n\n| Party | Name | Title | Signature | Date |\n| **Disclosing** | {{SIGNATORY_A}} | {{TITLE_A}} |  |  |\n| **Receiving** | {{SIGNATORY_B}} | {{TITLE_B}} |  |  |"],

        ['TPL-003','Supplier & Manufacturing Agreement','Legal','3.0','Legal / Procurement',
         'Framework terms covering supply, quality, information security and audit rights for external manufacturers.',
            "# Manufacturing & Supply Agreement\n\n**Supplier:** {{PARTY_B}} · **Buyer:** {{PARTY_A}} · **Effective:** {{EFFECTIVE_DATE}}\n\n## 1. Scope\nThe Supplier shall manufacture and deliver the goods described in each purchase order (\"Goods\") in accordance with the specifications attached to that order.\n\n## 2. Quality\n- The Supplier operates a documented quality system and notifies the Buyer of any non-conformence within 24 hours\n- Corrective actions are answered within 5 working days\n- The Buyer may audit the Supplier with 10 business days' notice\n\n## 3. Information security\n- Confidential information is protected under the attached NDA ({{NDA_REF}})\n- Access to the Buyer's systems is limited to named individuals and reviewed quarterly\n- The Supplier notifies the Buyer of any security incident affecting the Buyer's data within 24 hours\n- Sub-processors require prior written approval\n\n## 4. Delivery and continuity\nDelivery terms are DAP {{DELIVERY_POINT}}. The Supplier shall hold {{BUFFER_DAYS}} days of critical buffer stock and maintain a documented business continuity plan.\n\n## 5. Change control\nNo change to process, tooling, material or sub-supplier without the Buyer's written approval.\n\n## 6. Term and termination\nInitial term {{TERM_YEARS}} years, renewing annually. Either party may terminate for material breach not cured within 30 days.\n\n## 7. Governing law\nLaws of {{JURISDICTION}}.\n\n| Party | Name | Title | Signature | Date |\n| **Buyer** | {{SIGNATORY_A}} | {{TITLE_A}} |  |  |\n| **Supplier** | {{SIGNATORY_B}} | {{TITLE_B}} |  |  |"],

        ['TPL-004','Contractor Confidentiality & Site Security Agreement','Legal','1.7','Legal / Security',
         'What contractors sign before starting work: NDA, badge rules, escort, photo and device restrictions.',
            "# Contractor Confidentiality & Site Security Agreement\n\nContractor: {{CONTRACTOR_NAME}} · Company: {{COMPANY}} · Site: {{SITE}} · Valid until: {{EXPIRY_DATE}}\n\n## 1. Confidentiality\nAll information seen or heard on site — process parameters, drawings, production data, staff information — is Confidential Information and shall not be disclosed or photographed without written permission.\n\n## 2. Site rules\n- Wear the contractor badge visibly at all times and return it on exit\n- Stay within the escorted area; never enter restricted zones alone\n- No personal USB drives or cameras on the production floor unless approved in writing\n- Report every incident, near miss and suspicious observation before leaving site\n\n## 3. Devices\nOnly registered devices may connect to plant network ports. Registration requires asset number, owner and endpoint protection status.\n\n## 4. Personnel\nOnly named personnel may attend site. Substitutions require 24 hours' notice and the same screening.\n\n## 5. Return of property\nAll documents, media, badges and keys are returned on the last day of work.\n\n## 6. Acknowledgement\n\n| Field | Detail |\n| **Contractor signature** |  |\n| **Date** |  |\n| **Plant representative** | {{REPRESENTATIVE}} |\n| **Permit / work order no.** | {{WORK_ORDER}} |"],

        ['TPL-005','Visitor Undertaking & Site Access Agreement','Security','2.0','Security / Reception',
         'Short single page signed at reception before a visitor badge is issued.',
            "# Visitor Undertaking & Site Access Agreement\n\nVisitor: {{VISITOR_NAME}} · Company: {{COMPANY}} · Host: {{HOST}} · Date: {{VISIT_DATE}}\n\n## 1. I undertake to:\n- wear this badge visibly and not lend it to anyone\n- stay with my host or an approved escort at all times\n- not photograph, record or copy anything without permission\n- not bring firearms, illegal substances or unauthorized media onto site\n- report any accident, incident or unsafe condition immediately\n- leave the site by {{EXIT_TIME}} and return the badge at reception\n\n## 2. Data\nMy name, company, host and visit times are recorded for security and are retained for 24 months under ISMS-001.\n\n## 3. Emergency\nIn an alarm I will follow my host's instructions and assemble at {{ASSEMBLY_POINT}}.\n\n| Field | Detail |\n| **Visitor signature** |  |\n| **Time in / out** |  |\n| **Badge number** |  |\n| **Issued by** | {{RECEPTION}} |"],

        ['TPL-006','Employee Information Security Agreement','HR','1.9','HR / Security',
         'Signed with the employment contract: account use, clean desk, media, reporting and disciplinary consequences.',
            "# Employee Information Security Agreement\n\nEmployee: {{EMPLOYEE_NAME}} · Employee no.: {{EMPLOYEE_NO}} · Department: {{DEPARTMENT}} · Start date: {{START_DATE}}\n\n## 1. Accounts and credentials\n- Use only your own accounts; never share or borrow credentials\n- Never approve a sign-in for someone else\n- Report a lost badge or suspected password leak within one hour\n\n## 2. Information handling\n- Classify and store documents in the approved locations only\n- No company information on personal cloud storage or personal email\n- Lock your screen when leaving the desk; clean desk at end of shift\n\n## 3. Media and devices\nApproved USB media only, scanned before use. No personal software on workstations.\n\n## 4. Reporting\nReport security events and near misses within 24 hours. Reporting in good faith carries no penalty.\n\n## 5. Consequences\nBreach of this agreement may lead to disciplinary action up to and including dismissal, and may be referred to authorities where required.\n\n| Field | Detail |\n| **Employee signature** |  |\n| **Date** |  |\n| **Manager** | {{MANAGER}} |\n| **HR reference** | {{HR_REF}} |"],

        ['TPL-007','Security Incident Report Form','Security','2.3','Security / Incident Response',
         'Structured capture of an event: what happened, who was involved, evidence, containment and closure.',
            "# Security Incident Report Form\n\nReport no.: {{REPORT_NO}} · Reported by: {{REPORTER}} · Date/time: {{DATETIME}} · Severity: {{SEVERITY}}\n\n## 1. Classification\n| Field | Detail |\n| **Category** | Physical / IT / Strain / Other: {{CATEGORY}} |\n| **Location** | {{LOCATION}} |\n| **Asset or system** | {{ASSET}} |\n| **Detected by** | {{DETECTED_BY}} |\n\n## 2. What happened\n{{DESCRIPTION}}\n\n## 3. Persons involved\n\n| Name | Employee no. | Department | Role in event |\n| **Person 1** |  |  |  |\n| **Person 2** |  |  |  |\n\n## 4. Immediate containment\n- [ ] Area or system secured\n- [ ] Access revoked / badge disabled\n- [ ] Evidence preserved (logs, CCTV, photos)\n- [ ] Supervisor and Security Manager notified\n\nActions taken: {{IMMEDIATE_ACTION}}\n\n## 5. Evidence attached\n- [ ] Photographs / CCTV stills\n- [ ] System or badge logs\n- [ ] Witness statements\n- [ ] Screenshots\n\n## 6. Root cause and corrective action\nRoot cause: {{ROOT_CAUSE}}\n\nCorrective action, owner and due date: {{CORRECTIVE_ACTION}}\n\n## 7. Review and closure\n\n| Role | Name | Signature | Date |\n| **Reported by** | {{REPORTER}} |  |  |\n| **Reviewed by** | {{REVIEWER}} |  |  |\n| **Approved by** | {{APPROVER}} |  |  |"],

        ['TPL-008','Access Request Form','Security','1.6','IT / Security',
         'New, changed or temporary access to plant systems, with approval chain and review date.',
            "# Access Request Form\n\nRequest no.: {{REQUEST_NO}} · Requested by: {{REQUESTER}} · Date: {{DATE}}\n\n## 1. Person\n| Field | Detail |\n| **Name** | {{EMPLOYEE_NAME}} |\n| **Employee no.** | {{EMPLOYEE_NO}} |\n| **Department / cost centre** | {{DEPARTMENT}} |\n| **Employment type** | Employee / Contractor / Vendor |\n\n## 2. Access requested\n\n| System | Access level | Justification |\n| **MES** | Read / Write | {{JUSTIFICATION}} |\n| **SCADA** | Operator / Engineer |  |\n| **File share** | Read / Write |  |\n| **Domain account** | Standard / Privileged |  |\n\n## 3. Business need\nReason and task reference: {{JUSTIFICATION}}\n\n## 4. Duration\n- [ ] Permanent\n- [ ] Temporary until {{END_DATE}} (auto-expiring)\n- [ ] Emergency (requires retroactive approval within 24 hours)\n\n## 5. Approvals\n\n| Role | Name | Decision | Date |\n| **Line manager** | {{MANAGER}} |  |  |\n| **System owner** | {{OWNER}} |  |  |\n| **Security** | {{SECURITY}} |  |  |\n\n## 6. Review\nAccess reviewed on: {{REVIEW_DATE}} — quarterly recertification applies to all privileged access."],

        ['TPL-009','Change Request Form','Operations','2.2','Operations / IT',
         'Planned change to production, IT or SCADA systems, including risk, rollback and approval.',
            "# Change Request Form\n\nCR no.: {{CR_NO}} · Requested by: {{REQUESTER}} · Planned window: {{WINDOW}}\n\n## 1. Summary\n| Field | Detail |\n| **System / area** | {{SYSTEM}} |\n| **Change type** | Standard / Normal / Emergency |\n| **Reason** | {{REASON}} |\n| **Related incident** | {{INCIDENT_REF}} |\n\n## 2. Detailed description\n{{DESCRIPTION}}\n\n## 3. Risk and impact\n| Field | Detail |\n| **Production impact** | None / Line stop / Partial |\n| **Rollback plan** | {{ROLLBACK}} |\n| **Backout time (min)** | {{BACKOUT_MINUTES}} |\n| **Security impact** | {{SECURITY_IMPACT}} |\n\n## 4. Testing\n- [ ] Tested in staging / offline copy\n- [ ] Backup or snapshot taken before the change\n- [ ] Validation steps documented\n\n## 5. Communication\nStakeholders notified: {{STAKEHOLDERS}} · Downstream notice period: {{NOTICE_PERIOD}}\n\n## 6. Approvals\n\n| Role | Name | Decision | Date |\n| **Change owner** |  |  |  |\n| **System owner** |  |  |  |\n| **Security (OT/network)** |  |  |  |\n| **Plant manager (production)** |  |  |  |\n\n## 7. Post-implementation\nImplemented by: {{IMPLEMENTER}} · Verified: {{VERIFY_RESULT}} · Problems encountered: {{PROBLEMS}}"],

        ['TPL-010','Security Risk Assessment Form','Audit','1.5','Security / Risk',
         'Five-by-five likelihood and impact assessment with treatment decision and acceptance sign-off.',
            "# Security Risk Assessment Form\n\nAssessment ref: {{ASSET_REVIEW}} · Assessor: {{ASSESSOR}} · Date: {{DATE}}\n\n## 1. Asset and context\n| Field | Detail |\n| **Asset / process** | {{ASSET}} |\n| **Owner** | {{ASSET_OWNER}} |\n| **Value** | Confidentiality {{CONF}} / Integrity {{INTEG}} / Availability {{AVAIL}} |\n\n## 2. Threats and vulnerabilities\n| Threat | Vulnerability | Existing control |\n| **{{THREAT}}** | {{VULNERABILITY}} | {{CONTROL}} |\n\n## 3. Risk evaluation\n| Likelihood (1-5) | Impact (1-5) | Score (L x I) | Rating |\n| {{LIKELIHOOD}} | {{IMPACT}} | {{SCORE}} | {{RATING}} |\n\nRating scale: 1-4 Low · 5-9 Moderate · 10-15 High · 16-25 Critical\n\n## 4. Treatment\n- [ ] Mitigate — action: {{TREATMENT}}, owner: {{OWNER}}, due: {{DUE_DATE}}\n- [ ] Transfer — insurer / supplier: {{TRANSFER}}\n- [ ] Accept — accepted by: {{ACCEPTOR}}\n- [ ] Avoid — activity stopped\n\n## 5. Review\nReassess on {{REVIEW_DATE}} or after any incident affecting this asset.\n\n| Role | Name | Signature | Date |\n| **Assessor** | {{ASSESSOR}} |  |  |\n| **Risk owner** | {{ACCEPTOR}} |  |  |"],

        ['TPL-011','Internal Audit Checklist','Audit','2.0','Security / Audit',
         'Clause-by-clause checklist for ISMS internal audits, scored and signed at the closing meeting.',
            "# Internal Audit Checklist\n\nAudit: {{AUDIT_REF}} · Auditor: {{AUDITOR}} · Auditee: {{AUDITEE}} · Date: {{DATE}}\n\n## 1. Access control\n- [ ] Joiner/mover/leaver process is followed and evidenced\n- [ ] No shared or generic user accounts in use\n- [ ] Privileged access reviewed within the last quarter\n- [ ] Remote access requires multi-factor authentication\n\n## 2. Physical security\n- [ ] Badge log matches the roster for the sampled shift\n- [ ] Restricted areas signed, sealed and inspected\n- [ ] Visitor register complete with escort records\n- [ ] Clean desk spot check passed\n\n## 3. Operations and change\n- [ ] Patch levels within the agreed window\n- [ ] Change tickets approved before implementation\n- [ ] Backups restored in the last test\n- [ ] Incident log reviewed with closure evidence\n\n## 4. People and training\n- [ ] Training matrix current for all sampled staff\n- [ ] Contractor agreements signed and in date\n- [ ] Awareness drill results recorded\n\n## 5. Findings\n\n| Ref | Clause | Finding | Severity | Owner | Due |\n| **F1** |  |  |  |  |  |\n| **F2** |  |  |  |  |  |\n\n## 6. Closing\nOverall conclusion: {{CONCLUSION}}\n\n| Role | Name | Signature | Date |\n| **Lead auditor** | {{AUDITOR}} |  |  |\n| **Auditee** | {{AUDITEE}} |  |  |"],

        ['TPL-012','Policy Acknowledgement & Training Record','HR','1.3','HR / Security',
         'Proof that a person read a specific policy version and passed the related training.',
            "# Policy Acknowledgement & Training Record\n\nEmployee: {{EMPLOYEE_NAME}} · Employee no.: {{EMPLOYEE_NO}} · Department: {{DEPARTMENT}}\n\n## 1. Document acknowledged\n| Field | Detail |\n| **Document code** | {{DOC_CODE}} |\n| **Title** | {{DOC_TITLE}} |\n| **Version** | {{VERSION}} |\n| **Effective date** | {{EFFECTIVE_DATE}} |\n\n## 2. What I confirm\n- I have received and read the document above in full\n- I understand my obligations and the consequences of not complying\n- I know where the current version is stored and how to check it\n- I will report incidents and near misses as described in the document\n\n## 3. Training\n| Item | Method | Result | Date |\n| **Awareness session** | Classroom / e-learning | {{RESULT}} | {{TRAINING_DATE}} |\n| **Assessment** | Quiz | Score {{SCORE}}% | {{TRAINING_DATE}} |\n\n## 4. Signatures\n\n| Field | Detail |\n| **Employee signature** |  |\n| **Date** | {{DATE}} |\n| **Trainer / verifier** | {{TRAINER}} |"],
    ];

    $st = $pdo->prepare('INSERT INTO templates (code,name,category,version,owner,description,body,downloads) VALUES (?,?,?,?,?,?,?,0)');
    foreach ($tpl as $t) {
        $st->execute($t);
    }
}

/**
 * Tags and the linked report form for the seeded issues. Runs only while no
 * issue carries either, so demo content never overwrites real edits.
 */
function seed_issue_extras(PDO $pdo): void
{
    $filled = (int) $pdo->query("SELECT COUNT(*) FROM issues WHERE tags <> '' OR report_template_id IS NOT NULL")->fetchColumn();
    if ($filled > 0) {
        return;
    }

    $idByCode = [];
    foreach ($pdo->query('SELECT id, code FROM issues')->fetchAll() as $r) {
        $idByCode[(string) $r['code']] = (int) $r['id'];
    }
    $tplByCode = [];
    foreach ($pdo->query('SELECT id, code FROM templates')->fetchAll() as $r) {
        $tplByCode[(string) $r['code']] = (int) $r['id'];
    }

    $rows = [
        ['SEC-1001', 'door-control, after-hours, cctv', 'TPL-007'],
        ['SEC-1002', 'tailgating, gate, logistics', 'TPL-007'],
        ['SEC-1003', 'signage, warehouse', null],
        ['SEC-1004', 'ot-network, segmentation, hmi', 'TPL-010'],
        ['SEC-1005', 'shared-account, mes, audit-trail', 'TPL-007'],
        ['SEC-1006', 'patching, endpoint, wsus', null],
        ['SEC-1007', 'phishing, email, credential', 'TPL-007'],
        ['SEC-1008', 'fatigue, roster, overtime', null],
        ['SEC-1009', 'training, onboarding', 'TPL-012'],
        ['SEC-1010', 'scada, change-control, deadline-pressure', 'TPL-009'],
        ['SEC-1011', 'fire-door, warehouse, hold-open', null],
        ['SEC-1012', 'shadow-it, saas, cad', 'TPL-010'],
    ];

    $st = $pdo->prepare('UPDATE issues SET tags = ?, report_template_id = ? WHERE id = ?');
    foreach ($rows as [$code, $tags, $tpl]) {
        if (!isset($idByCode[$code])) {
            continue;
        }
        $st->execute([$tags, $tpl !== null ? ($tplByCode[$tpl] ?? null) : null, $idByCode[$code]]);
    }
}

/* ------------------------------------------------------------- exceptions */

function seed_exceptions(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM exception_categories')->fetchColumn() === 0) {
        $cats = [
            ['USB Storage', 'orange', 1],
            ['Website Access', 'blue', 2],
            ['Camera / CCTV', 'red', 3],
            ['Removable Media', 'orange', 4],
            ['Network Share', 'neutral', 5],
            ['Cloud Application', 'blue', 6],
            ['Device Allow-list', 'neutral', 7],
        ];
        $st = $pdo->prepare('INSERT INTO exception_categories (name, tone, sort) VALUES (?,?,?)');
        foreach ($cats as $c) {
            $st->execute($c);
        }
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM exceptions')->fetchColumn() > 0) {
        return;
    }

    // Periods are relative to today so the demo always shows one expiring and
    // one already-expired exception.
    $day = fn(int $offset): string => date('Y-m-d', (int) strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));

    $rows = [
        ['EXC-001', 'Line 3 label printer firmware USB', 'EDLP', 'USB Storage',
            'USB-KNG-4GB-0117 (Kingston DataTraveler, serial 0117)',
            "Issued to the Line 3 packaging team for firmware updates on the label printer.\nKept in the shift supervisor's locked drawer and signed out on the paper log.",
            'The printer vendor publishes firmware only as a FAT32 USB image; the plant has no network path to the printer bootloader.',
            'Scan on the standalone AV station before use; read-only mount; single named holder.',
            $day(-120), $day(200), 'Active', 'Medium', 'B. Saputra', 'R. Hartono', 'EDLP-2025-001'],

        ['EXC-002', 'Coating MSDS download from supplier portal', 'EDLP', 'Website Access',
            'portal.kerta-materials.example (download only)',
            "Engineering downloads safety data sheets for the new coating line.\nNo uploads, no account sharing; each download is logged by the proxy.",
            'The supplier publishes revised MSDS only through its portal; paper copies were always out of date.',
            'Proxy allow-list for the domain only, upload blocked, logs reviewed monthly.',
            $day(-60), $day(25), 'Active', 'Medium', 'A. Pratama', 'R. Hartono', 'EDLP-2025-014'],

        ['EXC-003', 'Camera 27 temporary sightline on the new dock', 'EDLP', 'Camera / CCTV',
            'CAM-27 · dock D2 loading bay',
            "Camera 27 was re-aimed to cover dock D2 while the permanent pole is installed.\nBlind spot remains on the west stair until the new mount is commissioned.",
            'The old pole was removed with the warehouse extension; the new mount is on order.',
            'Guard round R2 checks the west stair visually; recording retention kept at 30 days.',
            $day(-30), $day(120), 'Active', 'Low', 'S. Wijaya', 'R. Hartono', 'EDLP-2025-021'],

        ['EXC-004', 'Engineering laptop on the OT VLAN for commissioning', 'EDLP', 'Device Allow-list',
            'ENG-LT-042 · 10.20.9.42',
            "Commissioning laptop needs a fixed address on the OT segment for the Line 4 vision system.\nEndpoint protection, disk encryption and USB block are enforced; no office mail or browsing.",
            'The vision vendor tool cannot run across the firewall; the work lasts until the line is handed over.',
            'Host firewall limited to two OT hosts, patched weekly, disconnected when not commissioning.',
            $day(-10), $day(14), 'Active', 'High', 'D. Kusuma', 'R. Hartono', 'EDLP-2025-026'],

        ['EXC-005', 'Machine-vision backup USB for cell 4', 'EDLP', 'USB Storage',
            'USB-SND-16GB-0090',
            'Weekly recipe and calibration backup from cell 4 written to a USB stick.',
            'The cell controller has no network backup path; vendor supports USB export only.',
            'Dedicated media, scanned after each use, stored in the control room safe.',
            $day(-400), $day(-10), 'Active', 'Medium', 'B. Saputra', 'R. Hartono', 'EDLP-2024-009'],

        ['EXC-006', 'CAD viewer cloud trial for supplier drawings', 'EDLP', 'Cloud Application',
            'viewer.cadcloud.example (30-day trial)',
            "Engineering wants a browser viewer for supplier STEP files instead of installing the CAD suite.\nTen named users, no customer drawings, watermarked export disabled.",
            'The CAD suite is licensed for four seats; twelve engineers need read-only viewing.',
            'Trial tenant under review; data processing agreement and region check pending.',
            $day(-2), $day(45), 'Pending', 'High', 'A. Pratama', '', 'EDLP-2025-031'],

        ['EXC-007', 'External SharePoint link for the customer audit pack', 'EDLP', 'Network Share',
            'sharepoint.example/sites/q4-audit (guest link)',
            "Audit evidence pack shared with the customer quality team through a guest link.\nLink expires automatically and is limited to named recipients.",
            'The customer has no access to the plant collaboration tenant and the audit is next week.',
            'Guest link with expiry, download-only, reviewed when the audit closes.',
            $day(-20), $day(90), 'Active', 'Medium', 'S. Wijaya', 'R. Hartono', 'EDLP-2025-029'],

        ['EXC-008', 'Contractor camera for ceiling installation photos', 'EDLP', 'Camera / CCTV',
            'Contractor phone, work order CO-2291',
            'Electrical contractor photographs completed ceiling trays for the handover report.',
            'The handover requires photo evidence; the contractor has no plant camera.',
            'Escorted at all times, photos reviewed on site, no production lines in frame.',
            $day(-5), $day(3), 'Active', 'High', 'S. Wijaya', 'R. Hartono', 'EDLP-2025-033'],

        ['EXC-009', 'USB for MES terminal 4 badge-reader firmware', 'EDLP', 'Removable Media',
            'USB-SND-8GB-0142',
            'Badge reader firmware for MES terminal 4 is flashed from a vendor-supplied USB stick.',
            'The reader has no network interface and the vendor only distributes signed images on USB.',
            'Vendor signed image verified, media retained by IT after flashing.',
            $day(-200), $day(130), 'Active', 'Low', 'D. Kusuma', 'R. Hartono', 'EDLP-2025-017'],

        ['EXC-010', 'Legacy vendor remote support tool', 'EDLP', 'Cloud Application',
            'support.legacy-oem.example',
            'Old press OEM used a cloud remote-support agent for breakdowns.',
            'Kept for the press warranty period; the press was replaced in Q3.',
            'Agent uninstalled and the tenant closed after the press was decommissioned.',
            $day(-300), $day(-45), 'Revoked', 'High', 'D. Kusuma', 'R. Hartono', 'EDLP-2024-022'],
    ];

    $st = $pdo->prepare('INSERT INTO exceptions
        (code,title,system,category,target,detail,justification,control,period_start,period_end,status,risk_level,owner,approved_by,ref_no)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $r) {
        $st->execute($r);
    }
}

/** Suspects, evidence-free history and activity for the seeded issues. */
function seed_activity(PDO $pdo): void
{
    $idByCode = [];
    foreach ($pdo->query('SELECT id, code FROM issues')->fetchAll() as $r) {
        $idByCode[$r['code']] = (int) $r['id'];
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM suspects')->fetchColumn() === 0 && $idByCode) {
        $rows = [
            ['SEC-1002', 'Yoga Prakoso', 'EMP-2291', 'Logistics', 'Forklift operator', 'Afternoon', 'Witness', '0812-0000-1122', 'I held the gate open for the inbound truck; I did not see two people walk in behind it.', 1],
            ['SEC-1002', 'Unknown male, blue jacket', '', 'Contractor', 'Unknown', 'Afternoon', 'Primary', '', 'Captured on camera 14 at 14:03; identity still being confirmed with the contractor.', 0],
            ['SEC-1005', 'Rudi Setiawan', 'EMP-1874', 'Production Line 4', 'Operator', 'Morning', 'Primary', '0813-0000-4455', 'Admits sharing the MES login with two colleagues because the badge reader failed last month.', 1],
            ['SEC-1010', 'Bagus Nugroho', 'EMP-1102', 'Automation', 'Automation engineer', 'Day', 'Primary', '0811-0000-9988', 'Says he was told to get the line running before the deadline and would sort the paperwork later.', 1],
            ['SEC-1008', 'Siti Rahayu', 'EMP-2050', 'Production Control', 'Control desk operator', 'Night', 'Victim', '0815-0000-3311', 'Worked 13 hours on 22 September after two colleagues called in sick; reported feeling unable to refuse.', 1],
            ['SEC-1001', 'Unknown person', '', 'Unknown', 'Unknown', 'Night', 'Unknown', '', 'Badge log shows no entry; camera angle does not cover the door frame. CCTV clip preserved.', 0],
        ];
        $st = $pdo->prepare('INSERT INTO suspects (issue_id,name,employee_no,department,position,shift,involvement,contact,statement,interviewed) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($rows as $r) {
            if (isset($idByCode[$r[0]])) {
                $st->execute(array_merge([$idByCode[$r[0]]], array_slice($r, 1)));
            }
        }
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM issue_events')->fetchColumn() === 0 && $idByCode) {
        $issues = $pdo->query('SELECT id, code, reported_at, status, severity, reported_by FROM issues')->fetchAll();
        $ins = $pdo->prepare('INSERT INTO issue_events (issue_id, actor, kind, message, created_at) VALUES (?,?,?,?,?)');
        foreach ($issues as $i) {
            $actor = $i['reported_by'] !== '' ? $i['reported_by'] : 'SecOps CRM';
            $when  = $i['reported_at'] ?: date('Y-m-d');
            $ins->execute([(int) $i['id'], $actor, 'created',
                'Reported ' . $i['code'] . ' as ' . $i['severity'] . ' severity.', $when . ' 08:15:00']);

            if ($i['status'] !== 'Open') {
                $ins->execute([(int) $i['id'], 'S. Wijaya', 'status',
                    'Status set to ' . $i['status'] . '.', date('Y-m-d H:i:s', strtotime($when . ' +3 days') ?: time())]);
            }
        }

        $extra = [
            ['SEC-1001', 'R. Hartono', 'note', 'CCTV clip for 21:00-22:00 exported and archived; guard roster pulled for the week.'],
            ['SEC-1004', 'D. Kusuma', 'field', 'Risk score updated to 20 after confirming remote execution is possible from the office VLAN.'],
            ['SEC-1007', 'A. Pratama', 'note', 'Post-incident review completed: external mail banner deployed to all mailboxes.'],
            ['SEC-1010', 'R. Hartono', 'note', 'Emergency change authority defined; dual approval required from 01 Nov.'],
        ];
        foreach ($extra as $e) {
            if (isset($idByCode[$e[0]])) {
                $ins->execute([$idByCode[$e[0]], $e[1], $e[2], $e[3], date('Y-m-d H:i:s', strtotime('+5 days'))]);
            }
        }
    }
}
