<?php
// ── Database connection ──────────────────────────────────────
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', 3306);
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', 'sms_db');

$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', (int)DB_PORT);

$db_connected = true;
$db_error = null;

if ($conn->connect_error) {
    $db_connected = false;
    $db_error = $conn->connect_error;

    $is_setup_script = (basename($_SERVER['PHP_SELF'] ?? '') === 'setup.php');
    if (!$is_setup_script) {
        if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            header('Content-Type: application/json');
            die(json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . $conn->connect_error . '. Please configure database at /sms/app/shared/setup.php'
            ]));
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        header("Location: {$proto}://{$host}/sms/app/shared/setup.php?error=db_connect");
        exit;
    }
} else {
    $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->select_db(DB_NAME);
    $conn->set_charset('utf8mb4');
}
?>
