<?php
// ============================================================
//  RESET_DATA.PHP — Clear Demo Data for Integration Testing
// ============================================================
require_once __DIR__ . '/db.php';

// Disable foreign key checks for clean truncation
$conn->query("SET FOREIGN_KEY_CHECKS = 0;");

$tables_to_truncate = [
    'students',
    'clubs',
    'club_memberships',
    'events',
    'budget_requests',
    'achievements',
    'attendance_logs',
    'notifications',
    'audit_logs'
];

$truncated = [];
foreach ($tables_to_truncate as $table) {
    if ($conn->query("TRUNCATE TABLE $table")) {
        $truncated[] = $table;
    } else {
        // Fallback to DELETE if TRUNCATE fails due to FK
        $conn->query("DELETE FROM $table");
        $conn->query("ALTER TABLE $table AUTO_INCREMENT = 1");
        $truncated[] = $table;
    }
}

// Keep only core test role users (admin, student, adviser, osa, finance)
$conn->query("DELETE FROM users WHERE username NOT IN ('admin', 'student', 'adviser', 'osa', 'finance')");

// Enable foreign key checks
$conn->query("SET FOREIGN_KEY_CHECKS = 1;");

if (php_sapi_name() === 'cli') {
    echo "SUCCESS: Demo data cleared from " . implode(', ', $truncated) . "\n";
    echo "Core role accounts preserved in 'users' table for testing.\n";
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'All demo data successfully cleared.',
        'cleared_tables' => $truncated,
        'preserved_accounts' => ['admin', 'student', 'adviser', 'osa', 'finance']
    ]);
}
$conn->close();
