-- ============================================================
-- Component: Events & Event Registrations
-- File: 04_events_and_registrations.sql
-- Description: Institutional & Club events, approval lifecycle, and student registrations
-- ============================================================

CREATE TABLE IF NOT EXISTS `events` (
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

CREATE TABLE IF NOT EXISTS `event_registrations` (
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

-- Sample Seed Events
INSERT INTO `events` (`id`, `club_id`, `event_type`, `title`, `description`, `event_date`, `venue`, `status`, `created_by`) VALUES
(1, 1, 'Club', 'Annual Tech Symposium 2026', 'A nationwide technology symposium featuring AI, Cloud Computing, and Cybersecurity workshops.', '2026-08-15 09:00:00', 'Main Auditorium', 'Approved', 59),
(2, 1, 'Club', 'BCP Hackathon & Code Fest', '24-hour inter-college coding competition with cash prizes and industry mentors.', '2026-08-22 08:00:00', 'IT Laboratory 3', 'Approved', 59),
(3, 12, 'Club', 'Community Outreach Drive', 'Barangay computer literacy workshop and donation drive.', '2026-09-05 08:30:00', 'Barangay Hall', 'Pending SSC', 58)
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);
