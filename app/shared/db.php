<?php
// ============================================================
//  DB.PHP — Database Connection & Environment Loader
// ============================================================

// 1. Load environment variables from .env if available
$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val, " \t\n\r\0\x0B\"'");
                if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                    putenv("$key=$val");
                    $_ENV[$key] = $val;
                    $_SERVER[$key] = $val;
                }
            }
        }
    }
}

// 2. Define DB constants from environment or fallback defaults
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USERNAME') ?: (getenv('DB_USER') ?: 'root'));
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''));
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_DATABASE') ?: (getenv('DB_NAME') ?: 'sms_db'));

$is_setup_script = (basename($_SERVER['PHP_SELF'] ?? '') === 'setup.php');

// 3. Connect to database directly (connects directly to DB_NAME without redundant schema creation)
if ($is_setup_script) {
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', (int)DB_PORT);
} else {
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
}

$db_connected = true;
$db_error = null;

if ($conn->connect_error) {
    $db_connected = false;
    $db_error = $conn->connect_error;

    if (!$is_setup_script) {
        if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            header('Content-Type: application/json');
            http_response_code(500);
            die(json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . $conn->connect_error . '. Please verify database credentials in .env.'
            ]));
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        
        // Calculate dynamic relative path to setup.php without hardcoded /sms/
        $setupPath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/') . '/../shared/setup.php';
        header("Location: {$proto}://{$host}{$setupPath}?error=db_connect");
        exit;
    }
} else {
    if ($is_setup_script) {
        $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->select_db(DB_NAME);
    }
    $conn->set_charset('utf8mb4');
}
?>
