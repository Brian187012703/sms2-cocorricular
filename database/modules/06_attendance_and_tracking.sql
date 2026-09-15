-- ============================================================
-- Component: Attendance & On-Site Verification
-- File: 06_attendance_and_tracking.sql
-- Description: Automated QR, self-scan, RFID, and manual event check-ins
-- ============================================================

CREATE TABLE IF NOT EXISTS `attendance_logs` (
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

-- Sample Seed Attendance Log
INSERT INTO `attendance_logs` (`id`, `event_id`, `user_id`, `check_in`, `method`, `logged_by`) VALUES
(1, 1, 1, '2026-08-15 08:55:00', 'QR', 59)
ON DUPLICATE KEY UPDATE `check_in`=VALUES(`check_in`);
