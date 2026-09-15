-- ============================================================
-- Component: Digital Elections & Voting
-- File: 07_elections_and_voting.sql
-- Description: Club and institutional elections, candidacies, and tamper-resistant ballots
-- ============================================================

CREATE TABLE IF NOT EXISTS `elections` (
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

CREATE TABLE IF NOT EXISTS `election_candidates` (
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

CREATE TABLE IF NOT EXISTS `election_votes` (
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
