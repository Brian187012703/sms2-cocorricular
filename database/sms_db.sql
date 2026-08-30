-- ============================================================
--  Co-Curricular Student Management System (SMS) Database Export
--  Database Name : `sms_db`
--  Compatibility : MySQL 5.7+ / MariaDB 10.2+ / phpMyAdmin
--  Generated for : Moving/Deploying codebase to another device
-- ============================================================

CREATE DATABASE IF NOT EXISTS `sms_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `sms_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- Table structure for `users`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username`      VARCHAR(60)   NOT NULL UNIQUE,
    `email`         VARCHAR(150)  NOT NULL UNIQUE,
    `first_name`    VARCHAR(100)  NOT NULL,
    `last_name`     VARCHAR(100)  NOT NULL,
    `password_hash` VARCHAR(255)  NOT NULL,
    `role`          ENUM('admin','student','club_adviser','osa_director','finance_officer') NOT NULL DEFAULT 'student',
    `profile_pic`   VARCHAR(255)  DEFAULT NULL,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `students`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `first_name`   VARCHAR(100)  NOT NULL,
    `last_name`    VARCHAR(100)  NOT NULL,
    `birthday`     DATE          NOT NULL,
    `course`       VARCHAR(150)  NOT NULL,
    `year_level`   VARCHAR(50)   NOT NULL,
    `section`      VARCHAR(50)   NOT NULL,
    `phone`        VARCHAR(20)   NOT NULL,
    `status`       ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `clubs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `clubs`;
CREATE TABLE `clubs` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code`         VARCHAR(20)  NOT NULL UNIQUE,
    `name`         VARCHAR(150) NOT NULL,
    `category`     ENUM('Academic','Cultural','Sports','Advocacy','Religious') NOT NULL DEFAULT 'Academic',
    `description`  TEXT,
    `adviser_name` VARCHAR(150) DEFAULT 'Unassigned',
    `status`       ENUM('Active','Pending Charter','Suspended') NOT NULL DEFAULT 'Active',
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `club_memberships`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `club_memberships`;
CREATE TABLE `club_memberships` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `club_id`            INT UNSIGNED NOT NULL,
    `user_id`            INT UNSIGNED NOT NULL,
    `role`               VARCHAR(50) DEFAULT 'Member',
    `status`             ENUM('Active','Pending','Rejected') NOT NULL DEFAULT 'Pending',
    `approved_by`        INT UNSIGNED DEFAULT NULL,
    `letter_intent`      VARCHAR(255) DEFAULT NULL,
    `letter_endorsement` VARCHAR(255) DEFAULT NULL,
    `joined_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_club_user` (`club_id`, `user_id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_club` (`club_id`),
    CONSTRAINT `fk_cm_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `events`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `events`;
CREATE TABLE `events` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `club_id`        INT UNSIGNED NOT NULL,
    `title`          VARCHAR(200) NOT NULL,
    `description`    TEXT,
    `event_date`     DATETIME NOT NULL,
    `venue`          VARCHAR(150) NOT NULL,
    `status`         ENUM('Upcoming','Approved','Completed','Pending OSA','Rejected') NOT NULL DEFAULT 'Pending OSA',
    `created_by`     INT UNSIGNED DEFAULT NULL,
    `rejection_note` TEXT DEFAULT NULL,
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_events_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `event_registrations`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `event_registrations`;
CREATE TABLE `event_registrations` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_id`      INT UNSIGNED NOT NULL,
    `user_id`       INT UNSIGNED NOT NULL,
    `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `status`        ENUM('Registered','Attended','Cancelled') NOT NULL DEFAULT 'Registered',
    UNIQUE KEY `uq_event_user_reg` (`event_id`, `user_id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_event` (`event_id`),
    CONSTRAINT `fk_er_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_er_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `budget_requests`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `budget_requests`;
CREATE TABLE `budget_requests` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `club_id`      INT UNSIGNED NOT NULL,
    `title`        VARCHAR(200) NOT NULL,
    `description`  TEXT DEFAULT NULL,
    `amount`       DECIMAL(10,2) NOT NULL,
    `status`       ENUM('Pending Adviser','Pending OSA','Pending Finance','Disbursed','Rejected') NOT NULL DEFAULT 'Pending Adviser',
    `requested_by` INT UNSIGNED NOT NULL,
    `notes`        TEXT DEFAULT NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_br_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_br_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `attendance_logs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `attendance_logs`;
CREATE TABLE `attendance_logs` (
    `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_id`  INT UNSIGNED NOT NULL,
    `user_id`   INT UNSIGNED NOT NULL,
    `check_in`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `method`    ENUM('QR','RFID','Manual') NOT NULL DEFAULT 'QR',
    `logged_by` INT UNSIGNED DEFAULT NULL,
    UNIQUE KEY `uq_event_user` (`event_id`, `user_id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_event` (`event_id`),
    CONSTRAINT `fk_al_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `achievements`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `achievements`;
CREATE TABLE `achievements` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `club_id`      INT UNSIGNED NOT NULL,
    `submitted_by` INT UNSIGNED NOT NULL,
    `title`        VARCHAR(250) NOT NULL,
    `competition`  VARCHAR(250) NOT NULL,
    `award_date`   DATE NOT NULL,
    `proof_file`   VARCHAR(300) DEFAULT NULL,
    `status`       ENUM('Pending','Verified','Rejected') NOT NULL DEFAULT 'Pending',
    `verified_by`  INT UNSIGNED DEFAULT NULL,
    `notes`        TEXT DEFAULT NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ach_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ach_user` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `notifications`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `title`      VARCHAR(200) NOT NULL,
    `message`    TEXT NOT NULL,
    `type`       VARCHAR(50) DEFAULT 'info',
    `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_user_read` (`user_id`, `is_read`),
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `audit_logs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `action`       VARCHAR(100) NOT NULL,
    `target_table` VARCHAR(100) DEFAULT NULL,
    `target_id`    INT UNSIGNED DEFAULT NULL,
    `detail`       TEXT DEFAULT NULL,
    `ip_address`   VARCHAR(50) DEFAULT NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_user` (`user_id`),
    KEY `idx_created` (`created_at`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  SEED DATA
-- ============================================================

-- 1. Default Role Accounts
-- Default Credentials:
--   admin    / Admin@1234
--   student  / Password123!
--   adviser  / Password123!
--   osa      / Password123!
--   finance  / Password123!
INSERT INTO `users` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`) VALUES
(1, 'admin',   'admin@bcp.edu.ph',   'System',  'Admin',    '$2y$10$z4atcbkl9gg1NGl1ESj4I.mXpSn0YX1u5k4.2fqOv0Cfb6O0QXZPe', 'admin'),
(2, 'student', 'student@bcp.edu.ph', 'Student', 'User',     '$2y$10$iTiJ5U1M9xJYWTxei.hhW.m/0nUWqDrFThUmcjCFznLEUraOrtCIq', 'student'),
(3, 'adviser', 'adviser@bcp.edu.ph', 'Club',    'Adviser',  '$2y$10$iTiJ5U1M9xJYWTxei.hhW.m/0nUWqDrFThUmcjCFznLEUraOrtCIq', 'club_adviser'),
(4, 'osa',     'osa@bcp.edu.ph',     'OSA',     'Director', '$2y$10$iTiJ5U1M9xJYWTxei.hhW.m/0nUWqDrFThUmcjCFznLEUraOrtCIq', 'osa_director'),
(5, 'finance', 'finance@bcp.edu.ph', 'Finance', 'Officer',  '$2y$10$iTiJ5U1M9xJYWTxei.hhW.m/0nUWqDrFThUmcjCFznLEUraOrtCIq', 'finance_officer');

-- 2. Student Directory Data
INSERT INTO `students` (`id`, `first_name`, `last_name`, `birthday`, `course`, `year_level`, `section`, `phone`, `status`) VALUES
(1,  'Juswa',    'Pudaders',   '2004-06-20', 'Bachelor of Science in Information Technology', '4th Year', '41018', '09999999999', 'Active'),
(2,  'Maria',    'Santos',     '2003-03-15', 'Bachelor of Science in Computer Science',       '3rd Year', '31011', '09111111111', 'Inactive'),
(3,  'Jose',     'Reyes',      '2002-09-10', 'Bachelor of Science in Information Technology', '4th Year', '41019', '09222222222', 'Active'),
(4,  'Ana',      'Cruz',       '2005-01-25', 'Bachelor of Science in Information Systems',    '2nd Year', '21005', '09333333333', 'Active'),
(5,  'Carlos',   'Garcia',     '2001-11-30', 'Bachelor of Science in Computer Science',       '4th Year', '41020', '09444444444', 'Inactive'),
(6,  'Liza',     'Dela Cruz',  '2003-07-14', 'Bachelor of Science in Information Technology', '3rd Year', '31012', '09555555501', 'Active'),
(7,  'Ramon',    'Villanueva', '2002-04-22', 'Bachelor of Science in Computer Science',       '4th Year', '41021', '09555555502', 'Active'),
(8,  'Patricia', 'Aquino',     '2004-11-05', 'Bachelor of Science in Information Systems',    '2nd Year', '21006', '09555555503', 'Inactive'),
(9,  'Mark',     'Bautista',   '2003-08-30', 'Bachelor of Science in Information Technology', '3rd Year', '31013', '09555555504', 'Active'),
(10, 'Jenny',    'Navarro',    '2005-02-18', 'Bachelor of Science in Computer Science',       '1st Year', '11001', '09555555505', 'Active'),
(11, 'Rico',     'Fernandez',  '2001-12-09', 'Bachelor of Science in Information Technology', '4th Year', '41022', '09555555506', 'Inactive'),
(12, 'Sheila',   'Ramos',      '2004-05-03', 'Bachelor of Science in Information Systems',    '2nd Year', '21007', '09555555507', 'Active'),
(13, 'Angelo',   'Torres',     '2002-10-27', 'Bachelor of Science in Computer Science',       '4th Year', '41023', '09555555508', 'Active'),
(14, 'Claire',   'Mendoza',    '2003-01-16', 'Bachelor of Science in Information Technology', '3rd Year', '31014', '09555555509', 'Inactive'),
(15, 'Danilo',   'Pascual',    '2000-09-08', 'Bachelor of Science in Computer Science',       '4th Year', '41024', '09555555510', 'Active'),
(16, 'Rowena',   'Espinosa',   '2005-06-21', 'Bachelor of Science in Information Systems',    '1st Year', '11002', '09555555511', 'Active'),
(17, 'Freddie',  'Castillo',   '2002-03-13', 'Bachelor of Science in Information Technology', '4th Year', '41025', '09555555512', 'Inactive'),
(18, 'Aileen',   'Morales',    '2004-09-29', 'Bachelor of Science in Computer Science',       '2nd Year', '21008', '09555555513', 'Active'),
(19, 'Ronnie',   'Aguilar',    '2001-07-04', 'Bachelor of Science in Information Technology', '4th Year', '41026', '09555555514', 'Active'),
(20, 'Mylene',   'Domingo',    '2003-12-11', 'Bachelor of Science in Information Systems',    '3rd Year', '31015', '09555555515', 'Inactive'),
(21, 'Bryan',    'Lacson',     '2004-04-07', 'Bachelor of Science in Computer Science',       '2nd Year', '21009', '09555555516', 'Active'),
(22, 'Rosalie',  'Ilagan',     '2002-08-19', 'Bachelor of Science in Information Technology', '4th Year', '41027', '09555555517', 'Active'),
(23, 'Eduardo',  'Pineda',     '2005-03-25', 'Bachelor of Science in Computer Science',       '1st Year', '11003', '09555555518', 'Inactive'),
(24, 'Vanessa',  'Ocampo',     '2003-10-02', 'Bachelor of Science in Information Systems',    '3rd Year', '31016', '09555555519', 'Active'),
(25, 'Kenneth',  'Bondoc',     '2001-05-17', 'Bachelor of Science in Information Technology', '4th Year', '41028', '09555555520', 'Active');

-- 3. Student Organizations & Clubs
INSERT INTO `clubs` (`id`, `code`, `name`, `category`, `description`, `adviser_name`, `status`) VALUES
(1, 'ITS',     'Information Technology Society',             'Academic', 'Official organization for IT students focusing on technical skill-building and innovation.', 'Prof. Alex Reyes', 'Active'),
(2, 'CSSEC',   'Computer Science Student Executive Council', 'Academic', 'Empowering CS students through leadership, programming competitions, and research.', 'Prof. Alex Reyes', 'Active'),
(3, 'BCPVOL',  'BCP Campus Volunteers & Extension',          'Advocacy', 'Community outreach, outreach missions, and student volunteer projects.', 'Dr. Elena Cruz', 'Active'),
(4, 'BCPARTS', 'BCP Cultural Arts & Performing Troupe',      'Cultural', 'Dance, music, theater, and creative performance representation across campus.', 'Prof. Sarah Mercado', 'Active');

-- 4. Club Memberships
INSERT INTO `club_memberships` (`id`, `club_id`, `user_id`, `role`, `status`, `approved_by`) VALUES
(1, 1, 2, 'Member',  'Active', 3),
(2, 2, 2, 'Officer', 'Active', 3),
(3, 3, 2, 'Member',  'Active', 4);

-- 5. Events
INSERT INTO `events` (`id`, `club_id`, `title`, `description`, `event_date`, `venue`, `status`, `created_by`) VALUES
(1, 1, 'Annual Tech Symposium 2026',  'A nationwide technology symposium featuring AI, Cloud Computing, and Cybersecurity workshops.', '2026-08-15 09:00:00', 'Main Auditorium',  'Approved', 3),
(2, 2, 'BCP Hackathon & Code Fest',   '24-hour inter-college coding competition with cash prizes and industry mentors.',              '2026-08-22 08:00:00', 'IT Laboratory 3',  'Approved', 3),
(3, 3, 'Community Outreach Drive',    'Barangay computer literacy workshop and donation drive.',                                       '2026-09-05 08:30:00', 'Barangay Hall',    'Pending OSA', 3);

-- 6. Event Registrations
INSERT INTO `event_registrations` (`id`, `event_id`, `user_id`, `status`) VALUES
(1, 1, 2, 'Registered'),
(2, 2, 2, 'Registered');

-- 7. Budget Requests
INSERT INTO `budget_requests` (`id`, `club_id`, `title`, `description`, `amount`, `status`, `requested_by`, `notes`) VALUES
(1, 1, 'Tech Symposium Equipment & Badges', 'Funding for keynote speaker honorarium, certificates, and event badges.', 15000.00, 'Pending Finance', 3, 'Endorsed by OSA.'),
(2, 2, 'Hackathon Refreshments & Prizes',   'Food catering for 100 participants and trophy prizes for winners.',           25000.00, 'Pending OSA',     3, 'Pending initial OSA review.'),
(3, 3, 'Outreach Kits & Logistics',          'Educational supplies and transport for volunteer outreach team.',              8000.00,  'Pending Adviser', 3, 'Submitted by student committee.');

-- 8. Attendance Logs
INSERT INTO `attendance_logs` (`id`, `event_id`, `user_id`, `check_in`, `method`, `logged_by`) VALUES
(1, 1, 2, '2026-08-15 08:55:00', 'QR', 3);

-- 9. Achievements & Awards
INSERT INTO `achievements` (`id`, `club_id`, `submitted_by`, `title`, `competition`, `award_date`, `proof_file`, `status`, `verified_by`) VALUES
(1, 1, 2, 'Champion - National Web Development Challenge', 'PH Inter-College WebDev Expo 2025', '2025-11-20', NULL, 'Verified', 4),
(2, 2, 2, '1st Runner-Up - Algorithmic Coding Cup',        'Luzon CS Summit 2025',             '2025-10-14', NULL, 'Verified', 4),
(3, 1, 2, 'Best Innovative App Presentation',              'Youth In Tech Awards 2025',         '2025-08-05', NULL, 'Pending',  NULL);

-- 10. System Notifications
INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `is_read`) VALUES
(1, 2, 'Welcome to SMS Portal', 'Your student account is active. Explore clubs and register for events!', 'info', 1),
(2, 2, 'Event Registration Confirmed', 'You have successfully registered for Annual Tech Symposium 2026.', 'event', 0),
(3, 3, 'New Budget Request Submitted', 'Budget request #1 for Tech Symposium is now awaiting Finance approval.', 'budget', 0);

-- 11. System Audit Logs
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `target_table`, `target_id`, `detail`, `ip_address`) VALUES
(1, 1, 'system_setup', 'users', 1, 'Database schema initialized with core tables and seed data.', '127.0.0.1');

