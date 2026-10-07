<?php
// ============================================================
//  DB.PHP — Database Connection & Environment Loader
// ============================================================

// Bestlink College of the Philippines timezone synchronization
if (date_default_timezone_get() !== 'Asia/Manila') {
    date_default_timezone_set('Asia/Manila');
}

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
                if (getenv($key) === false && !array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                    putenv("$key=$val");
                    $_ENV[$key] = $val;
                    $_SERVER[$key] = $val;
                }
            }
        }
    }
}

// 2. Parse DATABASE_URL if provided (HostForge, Railway, Heroku standard)
$dbUrl = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? ($_SERVER['DATABASE_URL'] ?? ''));
if (!empty($dbUrl)) {
    $parsedUrl = parse_url($dbUrl);
    if (!empty($parsedUrl['host'])) {
        putenv('DB_HOST=' . $parsedUrl['host']);
        $_ENV['DB_HOST'] = $parsedUrl['host'];
    }
    if (!empty($parsedUrl['user'])) {
        $dbUser = rawurldecode($parsedUrl['user']);
        putenv('DB_USER=' . $dbUser);
        $_ENV['DB_USER'] = $dbUser;
        putenv('DB_USERNAME=' . $dbUser);
        $_ENV['DB_USERNAME'] = $dbUser;
    }
    if (isset($parsedUrl['pass'])) {
        $dbPass = rawurldecode($parsedUrl['pass']);
        putenv('DB_PASS=' . $dbPass);
        $_ENV['DB_PASS'] = $dbPass;
        putenv('DB_PASSWORD=' . $dbPass);
        $_ENV['DB_PASSWORD'] = $dbPass;
    }
    if (!empty($parsedUrl['path'])) {
        $dbFromUrl = rawurldecode(ltrim($parsedUrl['path'], '/'));
        putenv('DB_NAME=' . $dbFromUrl);
        $_ENV['DB_NAME'] = $dbFromUrl;
        putenv('DB_DATABASE=' . $dbFromUrl);
        $_ENV['DB_DATABASE'] = $dbFromUrl;
    }
    if (!empty($parsedUrl['port'])) {
        putenv('DB_PORT=' . $parsedUrl['port']);
        $_ENV['DB_PORT'] = (int)$parsedUrl['port'];
    }
}

// 3. Normalize database environment variables / aliases & clean placeholders
if (preg_match('/^<.*>$/', (string)getenv('DB_PASSWORD'))) {
    putenv('DB_PASSWORD=' . (getenv('DB_PASS') ?: ''));
    $_ENV['DB_PASSWORD'] = getenv('DB_PASSWORD');
}

if (!getenv('DB_USER') && getenv('DB_USERNAME')) {
    putenv('DB_USER=' . getenv('DB_USERNAME'));
    $_ENV['DB_USER'] = getenv('DB_USERNAME');
}
if (getenv('DB_USER') && (!getenv('DB_USERNAME') || getenv('DB_USERNAME') !== getenv('DB_USER'))) {
    putenv('DB_USERNAME=' . getenv('DB_USER'));
    $_ENV['DB_USERNAME'] = getenv('DB_USER');
}

if ((getenv('DB_PASS') === false || getenv('DB_PASS') === '') && getenv('DB_PASSWORD') !== false && getenv('DB_PASSWORD') !== '') {
    putenv('DB_PASS=' . getenv('DB_PASSWORD'));
    $_ENV['DB_PASS'] = getenv('DB_PASSWORD');
}
if ((getenv('DB_PASSWORD') === false || getenv('DB_PASSWORD') === '' || preg_match('/^<.*>$/', (string)getenv('DB_PASSWORD'))) && getenv('DB_PASS') !== false && getenv('DB_PASS') !== '') {
    putenv('DB_PASSWORD=' . getenv('DB_PASS'));
    $_ENV['DB_PASSWORD'] = getenv('DB_PASS');
}

if (!getenv('DB_NAME') && getenv('DB_DATABASE')) {
    putenv('DB_NAME=' . getenv('DB_DATABASE'));
    $_ENV['DB_NAME'] = getenv('DB_DATABASE');
}
if (getenv('DB_NAME') && (!getenv('DB_DATABASE') || getenv('DB_DATABASE') !== getenv('DB_NAME'))) {
    putenv('DB_DATABASE=' . getenv('DB_NAME'));
    $_ENV['DB_DATABASE'] = getenv('DB_NAME');
}

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$db   = getenv('DB_NAME') ?: 'sms_db';
$port = (int)(getenv('DB_PORT') ?: 3306);

// If pass is an unreplaced template placeholder like <ang_password...>, clear it
if (preg_match('/^<.*>$/', $pass)) {
    $pass = '';
}

// Define DB constants for backward compatibility across modules
if (!defined('DB_HOST')) define('DB_HOST', $host);
if (!defined('DB_USER')) define('DB_USER', $user);
if (!defined('DB_PASS')) define('DB_PASS', $pass);
if (!defined('DB_NAME')) define('DB_NAME', $db);
if (!defined('DB_PORT')) define('DB_PORT', $port);

$is_setup_script = (basename($_SERVER['PHP_SELF'] ?? '') === 'setup.php');

// Disable throwing fatal exceptions on mysqli errors so we can handle them gracefully
mysqli_report(MYSQLI_REPORT_OFF);

$db_connected = false;
$db_error = null;

try {
    // 3. Connect to MySQL server with 3-second timeout to prevent cloud health hangs
    $conn = mysqli_init();
    if ($conn) {
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
        @$conn->real_connect($host, $user, $pass, $is_setup_script ? '' : $db, $port);
    }

    // If database does not exist (MySQL error 1049: Unknown database), auto-connect and create it
    if (!$is_setup_script && $conn && $conn->connect_errno === 1049) {
        $conn = mysqli_init();
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
        @$conn->real_connect($host, $user, $pass, '', $port);
        if (!$conn->connect_error) {
            $conn->query("CREATE DATABASE IF NOT EXISTS `" . $db . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $conn->select_db($db);
        }
    }

    if ($conn && !$conn->connect_error) {
        $db_connected = true;
        @$conn->query("SET time_zone = '+08:00'");
    } else {
        $db_connected = false;
        $db_error = $conn ? $conn->connect_error : 'Failed to initialize mysqli';
    }
} catch (Throwable $e) {
    $db_connected = false;
    $db_error = $e->getMessage();
}

if (!$db_connected) {

    if (!$is_setup_script) {
        if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            header('Content-Type: application/json');
            http_response_code(500);
            die(json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . ($db_error ?: ($conn->connect_error ?? 'Check database server and credentials.'))
            ]));
        }
        $http_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        
        // Calculate dynamic relative path to setup.php without hardcoded folder names
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (strpos($scriptName, '/app/') !== false) {
            $base = substr($scriptName, 0, strpos($scriptName, '/app/'));
        } else {
            $base = rtrim(dirname($scriptName), '/\\');
            if ($base === '/' || $base === '\\') $base = '';
        }
        $setupUrl = "{$proto}://{$http_host}{$base}/app/shared/setup.php?error=db_connect";
        header("Location: {$setupUrl}");
        exit;
    }
} else {
    if ($is_setup_script) {
        $conn->query("CREATE DATABASE IF NOT EXISTS `" . $db . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->select_db($db);
    }
    $conn->set_charset('utf8mb4');
}

/**
 * Helper to get a dedicated connection to an organization's isolated database.
 * Accepts club ID or club code (e.g. 1, 'CSSEC', 'RCYC-BCP').
 */
function getOrgDb(string|int $club_identifier): ?mysqli {
    require_once __DIR__ . '/org_db_manager.php';
    return get_org_db_connection($club_identifier);
}
