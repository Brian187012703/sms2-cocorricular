-- ============================================================
-- Student Management System (SMS) — Master Database Schema
-- Database: `sms_db`
-- Charset: utf8mb4 / Collation: utf8mb4_unicode_ci
-- Generated & Modularized for High Reliability & Maintainability
-- ============================================================

CREATE DATABASE IF NOT EXISTS `sms_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sms_db`;

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================================
-- 1. Table: `users`
-- Subsystem: Authentication & User Accounts
-- Supported Roles: 'admin', 'student', 'club_adviser', 'ssc'
-- ============================================================
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(60) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'student', 'club_adviser', 'ssc') NOT NULL DEFAULT 'student',
  `profile_pic` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. Table: `students`
-- Subsystem: Student Records & Academic Mapping
-- ============================================================
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED DEFAULT NULL,
  `student_number` VARCHAR(50) DEFAULT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `birthday` DATE NOT NULL,
  `course` VARCHAR(150) NOT NULL,
  `year_level` VARCHAR(50) NOT NULL,
  `section` VARCHAR(50) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_students_user` (`user_id`),
  KEY `idx_students_number` (`student_number`),
  CONSTRAINT `fk_students_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. Table: `clubs`
-- Subsystem: Campus Organizations & Programs
-- ============================================================
DROP TABLE IF EXISTS `clubs`;
CREATE TABLE `clubs` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `category` ENUM('Academic', 'Cultural', 'Sports', 'Advocacy', 'Religious') NOT NULL DEFAULT 'Academic',
  `description` TEXT DEFAULT NULL,
  `adviser_name` VARCHAR(150) DEFAULT 'Unassigned',
  `status` ENUM('Active', 'Pending Charter', 'Suspended') NOT NULL DEFAULT 'Active',
  `program` VARCHAR(150) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clubs_code` (`code`),
  KEY `idx_clubs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. Table: `club_memberships`
-- Subsystem: Club Roster & Membership Role Management
-- ============================================================
DROP TABLE IF EXISTS `club_memberships`;
CREATE TABLE `club_memberships` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` INT(10) UNSIGNED NOT NULL,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `role` VARCHAR(50) DEFAULT 'Member',
  `status` ENUM('Active', 'Pending', 'Rejected') NOT NULL DEFAULT 'Active',
  `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_by` INT(10) UNSIGNED DEFAULT NULL,
  `letter_intent` VARCHAR(255) DEFAULT NULL,
  `letter_endorsement` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_club_user` (`club_id`, `user_id`),
  KEY `idx_cm_user` (`user_id`),
  KEY `idx_cm_club` (`club_id`),
  CONSTRAINT `fk_cm_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. Table: `club_applications`
-- Subsystem: Student Membership Application Submissions
-- ============================================================
DROP TABLE IF EXISTS `club_applications`;
CREATE TABLE `club_applications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `club_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `student_id_no` VARCHAR(50) DEFAULT NULL,
  `course` VARCHAR(150) DEFAULT NULL,
  `year_level` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `sex` VARCHAR(20) DEFAULT NULL,
  `dob` DATE DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `motivation` TEXT DEFAULT NULL,
  `letter_intent` VARCHAR(255) DEFAULT NULL,
  `letter_endorsement` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
  `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_by` INT(11) DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ca_club_status` (`club_id`, `status`),
  KEY `idx_ca_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 6. Table: `events`
-- Subsystem: Events Management & Approvals
-- ============================================================
DROP TABLE IF EXISTS `events`;
CREATE TABLE `events` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` INT(10) UNSIGNED DEFAULT NULL,
  `event_type` ENUM('Club', 'Institutional') NOT NULL DEFAULT 'Club',
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `event_date` DATETIME NOT NULL,
  `venue` VARCHAR(150) NOT NULL,
  `status` ENUM('Upcoming', 'Approved', 'Completed', 'Pending SSC', 'Pending Admin', 'Rejected') NOT NULL DEFAULT 'Pending SSC',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(10) UNSIGNED DEFAULT NULL,
  `endorsement_notes` TEXT DEFAULT NULL,
  `rejection_note` TEXT DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_events_club` (`club_id`),
  KEY `idx_events_status` (`status`),
  KEY `idx_events_date` (`event_date`),
  CONSTRAINT `fk_events_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_events_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. Table: `event_registrations`
-- Subsystem: Event Pre-Registrations & Participation
-- ============================================================
DROP TABLE IF EXISTS `event_registrations`;
CREATE TABLE `event_registrations` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT(10) UNSIGNED NOT NULL,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `registered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('Registered', 'Attended', 'Cancelled') NOT NULL DEFAULT 'Registered',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_user_reg` (`event_id`, `user_id`),
  KEY `idx_reg_user` (`user_id`),
  KEY `idx_reg_event` (`event_id`),
  CONSTRAINT `fk_er_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_er_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. Table: `budget_requests`
-- Subsystem: Multi-Tier Budget Requests & Disbursement Pipeline
-- ============================================================
DROP TABLE IF EXISTS `budget_requests`;
CREATE TABLE `budget_requests` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` INT(10) UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `status` ENUM('Pending Adviser', 'Pending SSC', 'Pending Admin', 'Disbursed', 'Rejected') NOT NULL DEFAULT 'Pending Adviser',
  `requested_by` INT(10) UNSIGNED NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_br_club` (`club_id`),
  KEY `idx_br_status` (`status`),
  KEY `idx_br_requester` (`requested_by`),
  CONSTRAINT `fk_br_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_br_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. Table: `attendance_logs`
-- Subsystem: Event Attendance Tracking (QR/RFID/Self-scan/Manual)
-- ============================================================
DROP TABLE IF EXISTS `attendance_logs`;
CREATE TABLE `attendance_logs` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT(10) UNSIGNED NOT NULL,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `check_in` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `method` ENUM('QR', 'RFID', 'Manual', 'QR_SELF') NOT NULL DEFAULT 'QR',
  `logged_by` INT(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_user` (`event_id`, `user_id`),
  KEY `idx_att_user` (`user_id`),
  KEY `idx_att_event` (`event_id`),
  KEY `idx_att_method` (`method`),
  CONSTRAINT `fk_al_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_al_logger` FOREIGN KEY (`logged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. Table: `elections`
-- Subsystem: Digital Elections Management
-- ============================================================
DROP TABLE IF EXISTS `elections`;
CREATE TABLE `elections` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `election_code` VARCHAR(50) NOT NULL,
  `club_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `closes_at` DATETIME DEFAULT NULL,
  `status` ENUM('open', 'closed', 'counting') DEFAULT 'open',
  `positions` TEXT DEFAULT NULL,
  `created_by` INT(11) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_election_code` (`election_code`),
  KEY `idx_elec_club` (`club_id`),
  KEY `idx_elec_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 11. Table: `election_candidates`
-- Subsystem: Election Candidates & Platform Tags
-- ============================================================
DROP TABLE IF EXISTS `election_candidates`;
CREATE TABLE `election_candidates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `election_id` INT(11) NOT NULL,
  `candidate_code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `position` VARCHAR(100) NOT NULL,
  `party` VARCHAR(150) DEFAULT NULL,
  `year_level` VARCHAR(50) DEFAULT NULL,
  `program` VARCHAR(50) DEFAULT NULL,
  `gwa` VARCHAR(20) DEFAULT NULL,
  `platform_tag` TEXT DEFAULT NULL,
  `achievements` TEXT DEFAULT NULL,
  `votes_count` INT(11) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cand_election` (`election_id`),
  KEY `idx_cand_position` (`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 12. Table: `election_votes`
-- Subsystem: Encrypted / Audited Ballots
-- ============================================================
DROP TABLE IF EXISTS `election_votes`;
CREATE TABLE `election_votes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `election_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `votes_json` TEXT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_election` (`election_id`, `user_id`),
  KEY `idx_vote_election` (`election_id`),
  KEY `idx_vote_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 13. Table: `achievements`
-- Subsystem: Student Recognition & Award Verifications
-- ============================================================
DROP TABLE IF EXISTS `achievements`;
CREATE TABLE `achievements` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` INT(10) UNSIGNED NOT NULL,
  `submitted_by` INT(10) UNSIGNED NOT NULL,
  `title` VARCHAR(250) NOT NULL,
  `competition` VARCHAR(250) NOT NULL,
  `award_date` DATE NOT NULL,
  `proof_file` VARCHAR(300) DEFAULT NULL,
  `status` ENUM('Pending', 'Verified', 'Rejected') NOT NULL DEFAULT 'Pending',
  `verified_by` INT(10) UNSIGNED DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ach_club` (`club_id`),
  KEY `idx_ach_submitter` (`submitted_by`),
  KEY `idx_ach_status` (`status`),
  CONSTRAINT `fk_ach_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ach_user` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ach_verifier` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. Table: `org_announcements`
-- Subsystem: Club Communications & Priority Announcements
-- ============================================================
DROP TABLE IF EXISTS `org_announcements`;
CREATE TABLE `org_announcements` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `club_id` INT(10) UNSIGNED NOT NULL,
  `author_id` INT(10) UNSIGNED NOT NULL,
  `title` VARCHAR(250) NOT NULL,
  `category` ENUM('Event', 'Activity', 'Requirement / Submission', 'Meeting', 'General') NOT NULL DEFAULT 'General',
  `priority` ENUM('Normal', 'Important', 'Urgent') NOT NULL DEFAULT 'Normal',
  `content` TEXT NOT NULL,
  `target_group` VARCHAR(100) DEFAULT 'All Members',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_oa_club` (`club_id`),
  KEY `idx_oa_author` (`author_id`),
  CONSTRAINT `fk_oa_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oa_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15. Table: `notifications`
-- Subsystem: User Notification Center
-- ============================================================
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(50) DEFAULT 'info',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_read` (`user_id`, `is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 16. Table: `audit_logs`
-- Subsystem: Security & Action Audit Trail
-- ============================================================
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `target_table` VARCHAR(100) DEFAULT NULL,
  `target_id` INT(10) UNSIGNED DEFAULT NULL,
  `detail` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(50) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 17. Table: `ai_recommendation_logs`
-- Subsystem: AI Engine Telemetry & Co-Curricular Recommendations
-- ============================================================
DROP TABLE IF EXISTS `ai_recommendation_logs`;
CREATE TABLE `ai_recommendation_logs` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `request_type` ENUM('recommendation', 'report') NOT NULL,
  `prompt_summary` TEXT DEFAULT NULL,
  `ai_response` MEDIUMTEXT DEFAULT NULL,
  `model_used` VARCHAR(100) DEFAULT 'gemini-2.0-flash',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_user` (`user_id`),
  KEY `idx_ai_type` (`request_type`),
  CONSTRAINT `fk_ai_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 18. Table: `system_settings`
-- Subsystem: System Key-Value Configuration Store
-- ============================================================
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA & INITIAL CONFIGURATION (SYSTEM_ACCOUNTS.md)
-- ============================================================

INSERT INTO `users` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`) VALUES
(1, 'bsit.student', 'brianjoshua@student.bcp.edu.ph', 'Juan', 'Santos', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(2, 'bshm.student', 'bshm@student.bcp.edu.ph', 'Maria', 'Cruz', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(3, 'bsais.student', 'bsais@student.bcp.edu.ph', 'Jose', 'Reyes', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(4, 'bstm.student', 'bstm@student.bcp.edu.ph', 'Ana', 'Dela Cruz', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(5, 'bsoa.student', 'bsoa@student.bcp.edu.ph', 'Carlos', 'Garcia', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(6, 'bse.student', 'bse@student.bcp.edu.ph', 'Liza', 'Ramos', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(7, 'bsba.student', 'bsba@student.bcp.edu.ph', 'Ramon', 'Villanueva', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(8, 'bsis.student', 'bsis@student.bcp.edu.ph', 'Patricia', 'Aquino', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(9, 'bscpe.student', 'bscpe@student.bcp.edu.ph', 'Mark', 'Bautista', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(10, 'bspsych.student', 'bspsych@student.bcp.edu.ph', 'Jenny', 'Navarro', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(11, 'bscrim.student', 'bscrim@student.bcp.edu.ph', 'Rico', 'Fernandez', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(12, 'bspe.student', 'bspe@student.bcp.edu.ph', 'Sheila', 'Santos', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(13, 'tle.student', 'tle@student.bcp.edu.ph', 'Angelo', 'Torres', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(14, 'bseled.student', 'bseled@student.bcp.edu.ph', 'Claire', 'Mendoza', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(15, 'bsseed.student', 'bsseed@student.bcp.edu.ph', 'Danilo', 'Pascual', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(16, 'bslis.student', 'bslis@student.bcp.edu.ph', 'Rowena', 'Espinosa', '$2y$10$hOurKEX9y7pG8Kc8UQgBnugBGPJGqda3QdCJkWiNMGGM.vOjjoTSe', 'student'),
(17, 'cssec.adviser', 'cssec@adviser.bcp.edu.ph', 'Alex', 'Reyes', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(18, 'acads.adviser', 'acads@adviser.bcp.edu.ph', 'Mark', 'Velo', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(19, 'aces.adviser', 'aces@adviser.bcp.edu.ph', 'Elena', 'Ramos', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(20, 'aiss.adviser', 'aiss@adviser.bcp.edu.ph', 'Clara', 'Tan', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(21, 'bliss.adviser', 'bliss@adviser.bcp.edu.ph', 'Robert', 'Cruz', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(22, 'brave.adviser', 'brave@adviser.bcp.edu.ph', 'Diana', 'Gomez', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(23, 'cjsu.adviser', 'cjsu@adviser.bcp.edu.ph', 'Jose', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(24, 'eyo.adviser', 'eyo@adviser.bcp.edu.ph', 'Lisa', 'Santos', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(25, 'galaw.adviser', 'galaw@adviser.bcp.edu.ph', 'Manuel', 'Cruz', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(26, 'gems.adviser', 'gems@adviser.bcp.edu.ph', 'Anna', 'Reyes', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(27, 'gold.adviser', 'gold@adviser.bcp.edu.ph', 'Karen', 'Lim', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(28, 'jfinex.adviser', 'jfinex@adviser.bcp.edu.ph', 'Manuel', 'Cruz', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(29, 'hrs.adviser', 'hrs@adviser.bcp.edu.ph', 'Alex', 'Reyes', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(30, 'jma.adviser', 'jma@adviser.bcp.edu.ph', 'Sarah', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(31, 'lakas.adviser', 'lakas@adviser.bcp.edu.ph', 'Clara', 'Tan', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(32, 'lapis.adviser', 'lapis@adviser.bcp.edu.ph', 'Diana', 'Gomez', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(33, 'libro.adviser', 'libro@adviser.bcp.edu.ph', 'Robert', 'Cruz', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(34, 'omega.adviser', 'omega@adviser.bcp.edu.ph', 'Jose', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(35, 'psychsoc.adviser', 'psychsoc@adviser.bcp.edu.ph', 'Lisa', 'Santos', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(36, 'rsd.adviser', 'rsd@adviser.bcp.edu.ph', 'Anna', 'Reyes', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(37, 'sigma.adviser', 'sigma@adviser.bcp.edu.ph', 'Karen', 'Lim', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(38, 'techs.adviser', 'techs@adviser.bcp.edu.ph', 'Alex', 'Reyes', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(39, 'tts.adviser', 'tts@adviser.bcp.edu.ph', 'Sarah', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(40, 'wika.adviser', 'wika@adviser.bcp.edu.ph', 'Clara', 'Tan', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(41, 'acac.adviser', 'acac@adviser.bcp.edu.ph', 'Sarah', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(42, 'cesc.adviser', 'cesc@adviser.bcp.edu.ph', 'Mark', 'Velo', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(43, 'ebcpct.adviser', 'ebcpct@adviser.bcp.edu.ph', 'Mark', 'Velo', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(44, 'rcyc.adviser', 'rcyc@adviser.bcp.edu.ph', 'Elena', 'Cruz', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(45, 'smc.adviser', 'smc@adviser.bcp.edu.ph', 'Mark', 'Velo', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(46, 'allstar.adviser', 'allstar@adviser.bcp.edu.ph', 'Sarah', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(47, 'bforce.adviser', 'bforce@adviser.bcp.edu.ph', 'Sarah', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(48, 'creative.adviser', 'creative@adviser.bcp.edu.ph', 'Sarah', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(49, 'cdc.adviser', 'cdc@adviser.bcp.edu.ph', 'Sarah', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(50, 'dlc.adviser', 'dlc@adviser.bcp.edu.ph', 'Sarah', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(51, 'ikatlong.adviser', 'ikatlong@adviser.bcp.edu.ph', 'Sarah', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(52, 'image.adviser', 'image@adviser.bcp.edu.ph', 'Sarah', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(53, 'sikat.adviser', 'sikat@adviser.bcp.edu.ph', 'Sarah', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(54, 'uv.adviser', 'uv@adviser.bcp.edu.ph', 'Sarah', 'Mercado', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(55, 'peer.adviser', 'peer@adviser.bcp.edu.ph', 'Elena', 'Cruz', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(56, 'newslink.adviser', 'newslink@adviser.bcp.edu.ph', 'Elena', 'Cruz', '$2y$10$oyeEZ5UhDsC3TIM8NoF0eeovu8cU0q4bpCHtRNCTE/NUo1czdBdxq', 'club_adviser'),
(57, 'gadcg.adviser', 'gadcg@adviser.bcp.edu.ph', 'Elena', 'Cruz', '$2y$10$j7Rk01Bv3gzqpnF6fBxSQOfX8.3RJuwU85MADI6YvO4AEytawnSz2', 'club_adviser'),
(58, 'ssc.officer', 'ssc@bcp.edu.ph', 'SSC', 'Officer', '$2y$10$IglOXnR9y1VmIwFNgiKNROrb4LlUsTexydSjISeGKHx1Fp.nJC/hq', 'ssc'),
(59, 'scc.admin', 'admin@bcp.edu.ph', 'System', 'Admin', '$2y$10$v5YfjzzK2ZuRD88Sp9.Xp.I/vAFHzXIIR4WPZ2bU5HWchSYY2vCT6', 'admin')
ON DUPLICATE KEY UPDATE `first_name`=VALUES(`first_name`), `last_name`=VALUES(`last_name`), `password_hash`=VALUES(`password_hash`), `role`=VALUES(`role`);

INSERT INTO `students` (`id`, `user_id`, `student_number`, `first_name`, `last_name`, `birthday`, `course`, `year_level`, `section`, `phone`, `status`) VALUES
(22, 1, '2024-10001', 'Juan', 'Santos', '2004-01-01', 'Bachelor of Science in Information Technology', '2nd Year', 'IT-2A', '', 'Active'),
(23, 2, '2024-10002', 'Maria', 'Cruz', '2004-01-01', 'Bachelor of Science in Hospitality Management', '1st Year', 'HM-1B', '', 'Active'),
(24, 3, '2024-10003', 'Jose', 'Reyes', '2004-01-01', 'Bachelor of Science in Accounting Information System', '3rd Year', 'AIS-3A', '', 'Active'),
(25, 4, '2024-10004', 'Ana', 'Dela Cruz', '2004-01-01', 'Bachelor of Science in Tourism Management', '2nd Year', 'TM-2C', '', 'Active'),
(26, 5, '2024-10005', 'Carlos', 'Garcia', '2004-01-01', 'Bachelor of Science in Office Administration', '1st Year', 'OA-1A', '', 'Active'),
(27, 6, '2024-10006', 'Liza', 'Ramos', '2004-01-01', 'Bachelor of Science in Entrepreneurship', '3rd Year', 'ENT-3B', '', 'Active'),
(28, 7, '2024-10007', 'Ramon', 'Villanueva', '2004-01-01', 'Bachelor of Science in Business Administration', '2nd Year', 'BA-2A', '', 'Active'),
(29, 8, '2024-10008', 'Patricia', 'Aquino', '2004-01-01', 'Bachelor of Science in Information Science', '1st Year', 'IS-1A', '', 'Active'),
(30, 9, '2024-10009', 'Mark', 'Bautista', '2004-01-01', 'Bachelor of Science in Computer Engineering', '3rd Year', 'CPE-3A', '', 'Active'),
(31, 10, '2024-10010', 'Jenny', 'Navarro', '2004-01-01', 'Bachelor of Science in Psychology', '2nd Year', 'PSY-2B', '', 'Active'),
(32, 11, '2024-10011', 'Rico', 'Fernandez', '2004-01-01', 'Bachelor of Science in Criminology', '4th Year', 'CRIM-4A', '', 'Active'),
(33, 12, '2024-10012', 'Sheila', 'Santos', '2004-01-01', 'Bachelor of Science in Physical Education', '2nd Year', 'PE-2A', '', 'Active'),
(34, 13, '2024-10013', 'Angelo', 'Torres', '2004-01-01', 'Technological and Livelihood Education', '1st Year', 'TLE-1B', '', 'Active'),
(35, 14, '2024-10014', 'Claire', 'Mendoza', '2004-01-01', 'Bachelor of Science in Elementary Education', '3rd Year', 'ELED-3A', '', 'Active'),
(36, 15, '2024-10015', 'Danilo', 'Pascual', '2004-01-01', 'Bachelor of Science in Secondary Education', '2nd Year', 'SEED-2C', '', 'Active'),
(37, 16, '2024-10016', 'Rowena', 'Espinosa', '2004-01-01', 'Bachelor of Science in Library Information Science', '3rd Year', 'LIS-3A', '', 'Active'),
(38, NULL, '2024-10017', 'Brian Joshua', 'Tanael', '2004-01-01', 'Bachelor of Science in Information Technology', '3rd Year', 'IT-3A', '', 'Active')
ON DUPLICATE KEY UPDATE `user_id`=VALUES(`user_id`), `student_number`=VALUES(`student_number`), `course`=VALUES(`course`), `year_level`=VALUES(`year_level`), `section`=VALUES(`section`);

INSERT INTO `clubs` (`id`, `code`, `name`, `category`, `description`, `adviser_name`, `status`, `program`) VALUES
(1, 'CSSEC', 'Computer Science Student Exec Council', 'Academic', 'Official organization for IT students focusing on technical skill-building and innovation.', 'Prof. Alex Reyes', 'Active', 'BSCS'),
(2, 'ACADS', 'Association of Computer Eng Driven Students', 'Academic', 'ACADS is dedicated to academic excellence among Computer Engineering students through seminars, competitions, and peer mentoring programs.', 'Prof. Mark Velo', 'Active', 'BSCpE'),
(3, 'ACES', 'Association of Computer Engineering Students', 'Academic', 'ACES unites Computer Engineering students and promotes technical growth through workshops, laboratory enhancement campaigns, and industry visits.', 'Prof. Elena Ramos', 'Active', 'BSCpE'),
(4, 'AISS', 'Accounting Info System Society', 'Academic', 'AISS advances accounting information literacy and bridges academic knowledge with industry technology practices in financial systems.', 'Prof. Clara Tan', 'Active', 'BSAIS'),
(5, 'BLISS', 'Bestlink Library & Info Science Society', 'Academic', 'BLISS promotes library science excellence and information literacy across all BCP academic departments through advocacy and community service.', 'Prof. Robert Cruz', 'Active', 'BLIS'),
(6, 'BRAVE', 'Values Education & Accountability Org', 'Academic', 'BRAVE fosters values education and responsible leadership among BCP students through community outreach and character formation programs.', 'Prof. Diana Gomez', 'Active', 'BSEd'),
(7, 'CJSU', 'Criminal Justice Student Unit', 'Academic', 'CJSU strengthens criminal justice students through moot courts, criminology seminars, and law enforcement immersion programs.', 'Prof. Jose Mercado', 'Active', 'BSCrim'),
(8, 'EYO', 'Entrepreyouth Organization', 'Academic', 'EYO cultivates entrepreneurial mindsets among BCP youth through business incubation workshops, trade fairs, and start-up mentorship.', 'Prof. Lisa Santos', 'Active', 'BSBA'),
(9, 'G.A.L.A.W', 'Athletes & Leaders Wellness Assoc', 'Academic', 'Group of Athletes and Leaders Association for Wellness.', 'Prof. Manuel Cruz', 'Active', 'Institutional'),
(10, 'GEMs', 'Guild of English Majors', 'Academic', 'English language literacy and debate.', 'Prof. Anna Reyes', 'Active', 'BSEd'),
(11, 'GOLD', 'Guild of Officers to Lead Development', 'Academic', 'Leadership and management seminars.', 'Prof. Karen Lim', 'Active', 'Institutional'),
(12, 'JFINEX', 'Junior Financial Executives', 'Academic', 'Financial literacy and investment seminars.', 'Prof. Manuel Cruz', 'Active', 'BSBA'),
(13, 'HRS', 'Human Resources Society', 'Academic', 'Human resources development.', 'Prof. Alex Reyes', 'Active', 'BSBA'),
(14, 'J.M.A', 'Junior Marketing Association', 'Academic', 'Marketing strategy and branding.', 'Prof. Sarah Mercado', 'Active', 'BSBA'),
(15, 'LAKAS', 'Liga ng Aktibong Kabataan sa Araling Panlipunan', 'Academic', 'Social studies and leadership development.', 'Prof. Clara Tan', 'Active', 'BSEd'),
(16, 'L.A.P.I.S', 'Leadership Assoc Program & Services', 'Academic', 'Leadership and community service.', 'Prof. Diana Gomez', 'Active', 'Institutional'),
(17, 'LIBRO', 'Lucid of Bright & Righteous Officers', 'Academic', 'Library science development.', 'Prof. Robert Cruz', 'Active', 'BLIS'),
(18, 'OMEGA', 'Org for Mathematics in Engineering', 'Academic', 'Engineering mathematics application.', 'Prof. Jose Mercado', 'Active', 'BSCpE'),
(19, 'PsychSoc', 'Psychology Society', 'Academic', 'Psychology student growth and services.', 'Prof. Lisa Santos', 'Active', 'BSPsych'),
(20, 'RSD', 'Regnum Scientiae Discipulus', 'Academic', 'Science major student development.', 'Prof. Anna Reyes', 'Active', 'Institutional'),
(21, 'SIGMA', 'Guild for Mathematics Majors', 'Academic', 'Mathematics exploration and peer tutoring.', 'Prof. Karen Lim', 'Active', 'BSEd'),
(22, 'TECHs', 'Tech, Exploratory & Hospitality Skills', 'Academic', 'Technology and hospitality skills development.', 'Prof. Alex Reyes', 'Active', 'BSHM'),
(23, 'TTS', 'Tourism Student Society', 'Academic', 'Tourism industry training.', 'Prof. Sarah Mercado', 'Active', 'BSTM'),
(24, 'WIKA', 'Wikang Filipino sa Akademya', 'Academic', 'Filipino language promotion.', 'Prof. Clara Tan', 'Active', 'BSEd'),
(25, 'ACAC', 'Association of Cultural Art Club', 'Cultural', 'Cultural arts representation.', 'Prof. Sarah Mercado', 'Active', 'Institutional'),
(26, 'CESC', 'Computer Engineering Sports Club', 'Sports', 'Computer Engineering sports events.', 'Prof. Mark Velo', 'Active', 'BSCpE'),
(27, 'EBCPCT', 'Elite BCP Chess Team', 'Sports', 'Elite chess training.', 'Prof. Mark Velo', 'Active', 'Institutional'),
(28, 'RCYC-BCP', 'Red Cross Youth Council - BCP', 'Sports', 'Red cross youth operations.', 'Prof. Elena Cruz', 'Active', 'Institutional'),
(29, 'SMC', 'Shuttle Master Club', 'Sports', 'Badminton training.', 'Prof. Mark Velo', 'Active', 'Institutional'),
(30, 'ALL STAR', 'All Star Talent Group', 'Cultural', 'Talent group.', 'Prof. Sarah Mercado', 'Active', 'Institutional'),
(31, 'B-FORCE', 'B-Force Hip-Hop Crew', 'Cultural', 'Hip-hop and dance crew.', 'Prof. Sarah Mercado', 'Active', 'Institutional'),
(32, 'CREATIVE', 'Creative Arts Group', 'Cultural', 'Creative design and arts group.', 'Prof. Sarah Mercado', 'Active', 'Institutional'),
(33, 'CDC', 'Criminology Dance Company', 'Cultural', 'Criminology dance group.', 'Prof. Sarah Mercado', 'Active', 'BSCrim'),
(34, 'DLC', 'Drum and Lyre Corporation', 'Cultural', 'Drum and lyre marching group.', 'Prof. Sarah Mercado', 'Active', 'Institutional'),
(35, 'IKATLONG', 'Ikatlong Lahi Royalties', 'Cultural', 'Cultural representation.', 'Prof. Sarah Mercado', 'Active', 'Institutional'),
(36, 'IMAGE', 'Image Alchemy Media Crew', 'Cultural', 'Photography and media crew.', 'Prof. Sarah Mercado', 'Active', 'Institutional'),
(37, 'S.I.K.A.T', 'Theater & Acting Group', 'Cultural', 'Theater and acting group.', 'Prof. Sarah Mercado', 'Active', 'Institutional'),
(38, 'UV', 'Unlimited Voice Choir', 'Cultural', 'Choir and vocal group.', 'Prof. Sarah Mercado', 'Active', 'Institutional'),
(39, 'PEER', 'Peer Counselor Support Group', 'Advocacy', 'Student counseling support.', 'Prof. Elena Cruz', 'Active', 'Institutional'),
(40, 'NEWSLINK', 'School Publications', 'Advocacy', 'Student journalism and school publications.', 'Prof. Elena Cruz', 'Active', 'Institutional'),
(41, 'GAD-CG', 'Gender and Development – Core Group', 'Advocacy', 'Gender awareness and advocacy.', 'Dr. Elena Cruz', 'Active', '')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `category`=VALUES(`category`), `description`=VALUES(`description`), `adviser_name`=VALUES(`adviser_name`), `status`=VALUES(`status`), `program`=VALUES(`program`);


-- 4. Club Memberships
INSERT INTO `club_memberships` (`id`, `club_id`, `user_id`, `role`, `status`, `approved_by`) VALUES
(1, 1, 1, 'Officer', 'Active', 58),
(2, 1, 2, 'Member',  'Active', 3)
ON DUPLICATE KEY UPDATE `role`=VALUES(`role`);

-- 5. Seed Events
INSERT INTO `events` (`id`, `club_id`, `event_type`, `title`, `description`, `event_date`, `venue`, `status`, `created_by`) VALUES
(1, 1, 'Club', 'Annual Tech Symposium 2026', 'A nationwide technology symposium featuring AI, Cloud Computing, and Cybersecurity workshops.', '2026-08-15 09:00:00', 'Main Auditorium', 'Approved', 59),
(2, 1, 'Club', 'BCP Hackathon & Code Fest', '24-hour inter-college coding competition with cash prizes and industry mentors.', '2026-08-22 08:00:00', 'IT Laboratory 3', 'Approved', 59),
(3, 12, 'Club', 'Community Outreach Drive', 'Barangay computer literacy workshop and donation drive.', '2026-09-05 08:30:00', 'Barangay Hall', 'Pending SSC', 58)
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);

-- 6. Event Registrations
INSERT INTO `event_registrations` (`id`, `event_id`, `user_id`, `status`) VALUES
(1, 1, 1, 'Registered'),
(2, 1, 2, 'Registered')
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`);

-- 7. Budget Requests
INSERT INTO `budget_requests` (`id`, `club_id`, `title`, `description`, `amount`, `status`, `requested_by`, `notes`) VALUES
(1, 1, 'Tech Symposium Equipment & Honorarium', 'Funding for keynote speaker honorarium, certificates, and event badges.', 15000.00, 'Pending SSC', 3, 'Endorsed by Club Adviser.'),
(2, 2, 'Hackathon Refreshments & Prizes', 'Food catering for 100 participants and trophy prizes for winners.', 25000.00, 'Pending SSC', 3, 'Pending initial SSC review.')
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);

-- 8. Attendance Logs
INSERT INTO `attendance_logs` (`id`, `event_id`, `user_id`, `check_in`, `method`, `logged_by`) VALUES
(1, 1, 1, '2026-08-15 08:55:00', 'QR', 59)
ON DUPLICATE KEY UPDATE `check_in`=VALUES(`check_in`);

-- 9. Achievements
INSERT INTO `achievements` (`id`, `club_id`, `submitted_by`, `title`, `competition`, `award_date`, `proof_file`, `status`, `verified_by`, `notes`) VALUES
(1, 1, 1, 'Champion - National Web Development Challenge', 'PH Inter-College WebDev Expo 2025', '2025-11-20', NULL, 'Verified', 58, 'Verified and approved by SSC.')
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);

-- 10. Notifications
INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `is_read`) VALUES
(1, 1, 'Welcome to SMS Portal', 'Your student account is active. Explore clubs and register for events!', 'info', 1)
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);

-- 11. System Audit Logs
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `target_table`, `target_id`, `detail`, `ip_address`) VALUES
(1, 59, 'system_init', 'system_settings', 1, 'Consolidated database schema initialized with 18 core tables.', '127.0.0.1')
ON DUPLICATE KEY UPDATE `action`=VALUES(`action`);

-- 12. System Settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('gemini_api_key', 'AIzaSyBoA51zkRgMdUTU1ly2g9aUZUljEy4Ss9E')
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`);
