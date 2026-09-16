<?php
// ============================================================
//  PATCH_DATABASE_INTEGRITY.PHP
//  Migration script addressing F1-F7 database structure issues
// ============================================================
require_once __DIR__ . '/../../app/shared/db.php';

echo "Starting Database Integrity Patch...\n";

// Sync students.user_id where null by first_name and last_name
echo "[1/7] Syncing students.user_id with users.id...\n";
$sync_sql = "UPDATE students s 
             JOIN users u ON (s.first_name = u.first_name AND s.last_name = u.last_name) 
             SET s.user_id = u.id 
             WHERE s.user_id IS NULL";
$conn->query($sync_sql);
echo "  Students synced: " . $conn->affected_rows . " rows.\n";

// 2. Expand ai_recommendation_logs.request_type to VARCHAR(50)
echo "[2/7] Patching ai_recommendation_logs.request_type...\n";
$conn->query("ALTER TABLE ai_recommendation_logs MODIFY COLUMN request_type VARCHAR(50) NOT NULL DEFAULT 'recommendation'");
echo "  Done.\n";

// 3. Unify collations to utf8mb4_unicode_ci
echo "[3/7] Unifying table collations to utf8mb4_unicode_ci...\n";
$tables_res = $conn->query("SHOW TABLES");
while ($row = $tables_res->fetch_array()) {
    $table = $row[0];
    $conn->query("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}
echo "  Collations updated across all tables.\n";

// 4. Sync clubs.adviser_name from users/club_memberships
echo "[4/7] Syncing clubs.adviser_name...\n";
$clubs_res = $conn->query("SELECT c.id, u.first_name, u.last_name 
                           FROM clubs c
                           JOIN club_memberships cm ON cm.club_id = c.id AND cm.role = 'Adviser' AND cm.status = 'Active'
                           JOIN users u ON u.id = cm.user_id");
if ($clubs_res) {
    while ($c = $clubs_res->fetch_assoc()) {
        $adv_name = trim($c['first_name'] . ' ' . $c['last_name']);
        $stmt = $conn->prepare("UPDATE clubs SET adviser_name = ? WHERE id = ?");
        $stmt->bind_param('si', $adv_name, $c['id']);
        $stmt->execute();
        $stmt->close();
    }
}
echo "  Clubs adviser names verified.\n";

// 5. Align types & add Foreign Keys to elections, election_candidates, election_votes
echo "[5/7] Adding Foreign Keys to Elections module...\n";

// Helper function to safely drop existing FK
function drop_fk_if_exists($conn, $table, $fk_name) {
    $res = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND CONSTRAINT_NAME = '{$fk_name}'");
    if ($res && $res->num_rows > 0) {
        $conn->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk_name}`");
    }
}

// Clean up orphaned records first
$conn->query("DELETE FROM election_votes WHERE election_id NOT IN (SELECT id FROM elections)");
$conn->query("DELETE FROM election_votes WHERE user_id NOT IN (SELECT id FROM users)");
$conn->query("DELETE FROM election_candidates WHERE election_id NOT IN (SELECT id FROM elections)");
$conn->query("DELETE FROM elections WHERE club_id NOT IN (SELECT id FROM clubs)");
$conn->query("DELETE FROM elections WHERE created_by NOT IN (SELECT id FROM users)");

// Align columns to match parent table types (INT(10) UNSIGNED)
$conn->query("ALTER TABLE `elections` MODIFY COLUMN `club_id` INT(10) UNSIGNED NOT NULL");
$conn->query("ALTER TABLE `elections` MODIFY COLUMN `created_by` INT(10) UNSIGNED NOT NULL");
$conn->query("ALTER TABLE `election_votes` MODIFY COLUMN `user_id` INT(10) UNSIGNED NOT NULL");

drop_fk_if_exists($conn, 'elections', 'fk_elec_club');
drop_fk_if_exists($conn, 'elections', 'fk_elec_creator');
drop_fk_if_exists($conn, 'election_candidates', 'fk_cand_elec');
drop_fk_if_exists($conn, 'election_votes', 'fk_vote_elec');
drop_fk_if_exists($conn, 'election_votes', 'fk_vote_user');

$conn->query("ALTER TABLE `elections` ADD CONSTRAINT `fk_elec_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE");
$conn->query("ALTER TABLE `elections` ADD CONSTRAINT `fk_elec_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE");
$conn->query("ALTER TABLE `election_candidates` ADD CONSTRAINT `fk_cand_elec` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE");
$conn->query("ALTER TABLE `election_votes` ADD CONSTRAINT `fk_vote_elec` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE");
$conn->query("ALTER TABLE `election_votes` ADD CONSTRAINT `fk_vote_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE");
echo "  Elections foreign keys added.\n";

// 6. Align types & add Foreign Keys to club_applications
echo "[6/7] Adding Foreign Keys to club_applications...\n";
$conn->query("DELETE FROM club_applications WHERE club_id NOT IN (SELECT id FROM clubs)");
$conn->query("DELETE FROM club_applications WHERE user_id NOT IN (SELECT id FROM users)");
$conn->query("UPDATE club_applications SET reviewed_by = NULL WHERE reviewed_by IS NOT NULL AND reviewed_by NOT IN (SELECT id FROM users)");

$conn->query("ALTER TABLE `club_applications` MODIFY COLUMN `club_id` INT(10) UNSIGNED NOT NULL");
$conn->query("ALTER TABLE `club_applications` MODIFY COLUMN `user_id` INT(10) UNSIGNED NOT NULL");
$conn->query("ALTER TABLE `club_applications` MODIFY COLUMN `reviewed_by` INT(10) UNSIGNED DEFAULT NULL");

drop_fk_if_exists($conn, 'club_applications', 'fk_ca_club');
drop_fk_if_exists($conn, 'club_applications', 'fk_ca_user');
drop_fk_if_exists($conn, 'club_applications', 'fk_ca_reviewer');

$conn->query("ALTER TABLE `club_applications` ADD CONSTRAINT `fk_ca_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE");
$conn->query("ALTER TABLE `club_applications` ADD CONSTRAINT `fk_ca_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE");
$conn->query("ALTER TABLE `club_applications` ADD CONSTRAINT `fk_ca_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL");
echo "  Club applications foreign keys added.\n";

// 7. Academic Programs Lookup Table
echo "[7/7] Creating and seeding academic_programs lookup table...\n";
$conn->query("CREATE TABLE IF NOT EXISTS `academic_programs` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(20) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `department` VARCHAR(100) NOT NULL,
    `status` ENUM('Active', 'Inactive') DEFAULT 'Active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$programs = [
    ['BSIT', 'Bachelor of Science in Information Technology', 'College of Computer Studies'],
    ['BSCS', 'Bachelor of Science in Computer Science', 'College of Computer Studies'],
    ['BSCpE', 'Bachelor of Science in Computer Engineering', 'College of Engineering'],
    ['BSHM', 'Bachelor of Science in Hospitality Management', 'College of Hospitality Management'],
    ['BSTM', 'Bachelor of Science in Tourism Management', 'College of Hospitality Management'],
    ['BSBA', 'Bachelor of Science in Business Administration', 'College of Business and Accountancy'],
    ['BSA', 'Bachelor of Science in Accountancy', 'College of Business and Accountancy'],
    ['BEEd', 'Bachelor of Elementary Education', 'College of Education'],
    ['BSEd', 'Bachelor of Secondary Education', 'College of Education'],
    ['BAP', 'Bachelor of Arts in Psychology', 'College of Arts and Sciences'],
    ['BSN', 'Bachelor of Science in Nursing', 'College of Nursing'],
    ['BSCrim', 'Bachelor of Science in Criminology', 'College of Criminology'],
];

$ins_prog = $conn->prepare("INSERT IGNORE INTO academic_programs (code, name, department) VALUES (?, ?, ?)");
foreach ($programs as $prog) {
    $ins_prog->bind_param('sss', $prog[0], $prog[1], $prog[2]);
    $ins_prog->execute();
}
$ins_prog->close();
echo "  Academic programs initialized.\n";

echo "Database integrity patch completed successfully!\n";
