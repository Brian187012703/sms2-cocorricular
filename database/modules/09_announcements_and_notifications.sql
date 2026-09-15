-- ============================================================
-- Component: Announcements & Notifications
-- File: 09_announcements_and_notifications.sql
-- Description: Club-wide announcements and targeted user notifications
-- ============================================================

CREATE TABLE IF NOT EXISTS `org_announcements` (
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

CREATE TABLE IF NOT EXISTS `notifications` (
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

-- Sample Seed Notification
INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `is_read`) VALUES
(1, 1, 'Welcome to SMS Portal', 'Your student account is active. Explore clubs and register for events!', 'info', 1)
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);
