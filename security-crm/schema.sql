-- SecOps CRM — MySQL schema (PHP native + MySQL, no framework, no Composer).
-- Generated from a freshly seeded install. The app also creates and upgrades
-- every table by itself on the first request, so importing this file is optional.
--
-- Tables: sops, issues (tags + report form), tasks, users, suspects, issue_files,
--         issue_events, templates, exception_categories, exceptions, login_attempts,
--         audit_log
-- Demo sign-in: admin / Admin@123 (see README.md for the other roles).

CREATE DATABASE IF NOT EXISTS `security_crm` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `security_crm`;

/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exception_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `tone` enum('red','blue','orange','green','neutral') NOT NULL DEFAULT 'neutral',
  `sort` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exceptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `title` varchar(200) NOT NULL,
  `system` varchar(40) NOT NULL DEFAULT 'EDLP',
  `category` varchar(60) NOT NULL DEFAULT '',
  `target` varchar(180) NOT NULL DEFAULT '',
  `detail` text DEFAULT NULL,
  `justification` text DEFAULT NULL,
  `control` varchar(255) NOT NULL DEFAULT '',
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `status` enum('Active','Pending','Revoked') NOT NULL DEFAULT 'Active',
  `risk_level` enum('High','Medium','Low') NOT NULL DEFAULT 'Medium',
  `owner` varchar(100) NOT NULL DEFAULT '',
  `approved_by` varchar(100) NOT NULL DEFAULT '',
  `ref_no` varchar(40) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_exc_period` (`period_end`),
  KEY `idx_exc_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `issue_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_id` int(11) NOT NULL,
  `actor` varchar(100) NOT NULL DEFAULT '',
  `kind` enum('created','status','field','note','suspect','file','task') NOT NULL DEFAULT 'note',
  `message` varchar(400) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_events_issue` (`issue_id`),
  CONSTRAINT `fk_events_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `issue_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_id` int(11) NOT NULL,
  `original_name` varchar(200) NOT NULL,
  `stored_name` varchar(64) NOT NULL,
  `ext` varchar(10) NOT NULL DEFAULT '',
  `mime` varchar(100) NOT NULL DEFAULT '',
  `size_bytes` int(11) NOT NULL DEFAULT 0,
  `kind` enum('Photo','Screenshot','Log','Document','Statement','Other') NOT NULL DEFAULT 'Document',
  `note` varchar(255) NOT NULL DEFAULT '',
  `uploaded_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_files_issue` (`issue_id`),
  CONSTRAINT `fk_files_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `issues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `title` varchar(200) NOT NULL,
  `category` enum('Physical Security','IT Security','Strain Security') NOT NULL,
  `severity` enum('Critical','High','Medium','Low') NOT NULL DEFAULT 'Medium',
  `status` enum('Open','In Progress','Contained','Resolved') NOT NULL DEFAULT 'Open',
  `owner` varchar(100) NOT NULL DEFAULT '',
  `location` varchar(120) NOT NULL DEFAULT '',
  `department` varchar(100) NOT NULL DEFAULT '',
  `asset` varchar(140) NOT NULL DEFAULT '',
  `reported_by` varchar(100) NOT NULL DEFAULT '',
  `reported_at` date DEFAULT NULL,
  `due_at` date DEFAULT NULL,
  `closed_at` date DEFAULT NULL,
  `likelihood` tinyint(4) NOT NULL DEFAULT 3,
  `impact` tinyint(4) NOT NULL DEFAULT 3,
  `risk_score` int(11) NOT NULL DEFAULT 9,
  `root_cause` text DEFAULT NULL,
  `immediate_action` text DEFAULT NULL,
  `corrective_action` text DEFAULT NULL,
  `reviewed_by` varchar(100) NOT NULL DEFAULT '',
  `reviewed_at` date DEFAULT NULL,
  `approved_by` varchar(100) NOT NULL DEFAULT '',
  `description` text DEFAULT NULL,
  `tags` varchar(255) NOT NULL DEFAULT '',
  `report_template_id` int(11) DEFAULT NULL,
  `report_body` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_issues_status` (`status`),
  KEY `idx_issues_category` (`category`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(40) NOT NULL DEFAULT '',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `ok` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_attempts_ip` (`ip`,`created_at`),
  KEY `idx_attempts_user` (`username`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sops` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `title` varchar(200) NOT NULL,
  `type` enum('Policy','SOP','Guideline') NOT NULL DEFAULT 'SOP',
  `scope` enum('Physical','IT','Strain','General') NOT NULL DEFAULT 'General',
  `version` varchar(10) NOT NULL DEFAULT '1.0',
  `owner` varchar(100) NOT NULL DEFAULT '',
  `status` enum('Active','Draft','In Review','Obsolete') NOT NULL DEFAULT 'Draft',
  `effective_at` date DEFAULT NULL,
  `review_at` date DEFAULT NULL,
  `summary` varchar(500) NOT NULL DEFAULT '',
  `content` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `suspects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `employee_no` varchar(40) NOT NULL DEFAULT '',
  `department` varchar(100) NOT NULL DEFAULT '',
  `position` varchar(100) NOT NULL DEFAULT '',
  `shift` varchar(40) NOT NULL DEFAULT '',
  `involvement` enum('Primary','Accomplice','Witness','Victim','Unknown') NOT NULL DEFAULT 'Witness',
  `contact` varchar(60) NOT NULL DEFAULT '',
  `statement` text DEFAULT NULL,
  `interviewed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_suspects_issue` (`issue_id`),
  CONSTRAINT `fk_suspects_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `column_key` enum('backlog','planned','in_progress','review','done') NOT NULL DEFAULT 'backlog',
  `priority` enum('Critical','High','Medium','Low') NOT NULL DEFAULT 'Medium',
  `owner` varchar(100) NOT NULL DEFAULT '',
  `category` enum('Physical','IT','Strain','General') NOT NULL DEFAULT 'General',
  `due_at` date DEFAULT NULL,
  `issue_id` int(11) DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_tasks_issue` (`issue_id`),
  CONSTRAINT `fk_tasks_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(160) NOT NULL,
  `category` enum('Legal','HR','Security','Audit','Operations') NOT NULL DEFAULT 'Legal',
  `version` varchar(10) NOT NULL DEFAULT '1.0',
  `owner` varchar(100) NOT NULL DEFAULT '',
  `description` varchar(400) NOT NULL DEFAULT '',
  `body` mediumtext DEFAULT NULL,
  `downloads` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(40) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(120) NOT NULL DEFAULT '',
  `role` enum('admin','manager','analyst','viewer') NOT NULL DEFAULT 'viewer',
  `password_hash` varchar(255) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `failed_attempts` tinyint(4) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
CREATE TABLE `audit_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `actor_id` int(11) DEFAULT NULL,
  `actor` varchar(100) NOT NULL DEFAULT '',
  `role` varchar(20) NOT NULL DEFAULT '',
  `action` varchar(40) NOT NULL,
  `entity` varchar(30) NOT NULL DEFAULT '',
  `entity_id` int(11) DEFAULT NULL,
  `label` varchar(200) NOT NULL DEFAULT '',
  `detail` varchar(500) NOT NULL DEFAULT '',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `agent` varchar(120) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_actor` (`actor`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_entity` (`entity`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

