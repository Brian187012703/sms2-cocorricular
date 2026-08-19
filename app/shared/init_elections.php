<?php
require_once __DIR__ . '/db.php';

$sql1 = "CREATE TABLE IF NOT EXISTS elections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    election_code VARCHAR(50) UNIQUE NOT NULL,
    club_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    closes_at DATETIME NULL,
    status ENUM('open', 'closed', 'counting') DEFAULT 'open',
    positions TEXT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$sql2 = "CREATE TABLE IF NOT EXISTS election_candidates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    election_id INT NOT NULL,
    candidate_code VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    position VARCHAR(100) NOT NULL,
    party VARCHAR(150) NULL,
    year_level VARCHAR(50) NULL,
    program VARCHAR(50) NULL,
    gwa VARCHAR(20) NULL,
    platform_tag TEXT NULL,
    achievements TEXT NULL,
    votes_count INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$sql3 = "CREATE TABLE IF NOT EXISTS election_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    election_id INT NOT NULL,
    user_id INT NOT NULL,
    votes_json TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY user_election (election_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$conn->query($sql1);
$conn->query($sql2);
$conn->query($sql3);

// DB schema ready – no default auto-seeding (clean state for new elections testing)

