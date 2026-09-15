-- ============================================================
-- Component: System Settings, Audit Logs & AI Analytics
-- File: 10_system_settings_and_audit.sql
-- Description: System key-value configurations, security audit logs, and AI recommendation telemetry
-- ============================================================

CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
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

CREATE TABLE IF NOT EXISTS `ai_recommendation_logs` (
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

-- Core Initial Settings Seed
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('gemini_api_key', 'AIzaSyBoA51zkRgMdUTU1ly2g9aUZUljEy4Ss9E')
ON DUPLICATE KEY UPDATE `updated_at`=CURRENT_TIMESTAMP;
