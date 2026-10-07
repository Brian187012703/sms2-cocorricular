<?php
// ============================================================
//  INDEX.PHP — Root Entry Point & Smart Setup Router
// ============================================================

// If explicitly configured for Laravel frontend
if (getenv('FRONTEND_DRIVER') === 'laravel' && file_exists(__DIR__ . '/public/index.php')) {
    require __DIR__ . '/public/index.php';
    exit;
}

require_once __DIR__ . '/app/shared/db.php';

// If DB connection error, route to setup wizard
if (!isset($conn) || $conn->connect_error) {
    header('Location: app/shared/setup.php?error=db_connect');
    exit;
}

// Check if tables are installed
$tableCheck = $conn->query("SHOW TABLES LIKE 'users'");

if (!$tableCheck || $tableCheck->num_rows === 0) {
    // Database empty -> auto-launch setup wizard
    header('Location: app/shared/setup.php');
} else {
    // Database ready -> go to sign in
    header('Location: app/auth/signin.php');
}
exit;
