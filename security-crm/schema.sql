-- SecOps CRM — optional manual import (phpMyAdmin).
-- The app creates this schema AND seeds the sample data automatically on the
-- first request, so you only need this file if you prefer importing by hand.
-- Importing it without the seed leaves every table empty; the app then seeds
-- them on the next request anyway (each table is seeded only when empty).
--
-- Tables: sops, issues, tasks, users, suspects, issue_files, issue_events, templates

CREATE DATABASE IF NOT EXISTS `security_crm`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `security_crm`;

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
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_issues_status` (`status`),
  KEY `idx_issues_category` (`category`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
