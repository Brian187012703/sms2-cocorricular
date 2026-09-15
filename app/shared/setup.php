<?php
// ============================================================
//  SETUP.PHP — Co-Curricular Management System Setup & Installer
//  Supports Localhost & Multi-Device Network Deployments
//  URL: http://localhost/sms/app/shared/setup.php
// ============================================================
session_start();

$configFile = __DIR__ . '/db.php';
require_once $configFile;

$messages = [];
$errors   = [];

// Helper to run raw SQL safely
function executeSQLScript(mysqli $conn, string $sqlFilePath, array &$errors): bool {
    if (!file_exists($sqlFilePath)) {
        $errors[] = "SQL script not found: $sqlFilePath";
        return false;
    }

    $content = file_get_contents($sqlFilePath);
    if (empty(trim($content))) {
        $errors[] = "SQL script is empty: $sqlFilePath";
        return false;
    }

    // Enable multi-query execution
    if ($conn->multi_query($content)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());

        if ($conn->errno) {
            $errors[] = "Error executing $sqlFilePath: " . $conn->error;
            return false;
        }
        return true;
    } else {
        $errors[] = "Multi-query failed for $sqlFilePath: " . $conn->error;
        return false;
    }
}

// ------------------------------------------------------------
// POST ACTION DISPATCHER
// ------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action 1: Save & Test Database Connection Credentials
    if ($action === 'save_config') {
        $new_host = trim($_POST['db_host'] ?? '127.0.0.1');
        $new_port = (int)($_POST['db_port'] ?? 3306);
        $new_user = trim($_POST['db_user'] ?? 'root');
        $new_pass = $_POST['db_pass'] ?? '';
        $new_name = trim($_POST['db_name'] ?? 'sms_db');

        // Test connection
        $testConn = @new mysqli($new_host, $new_user, $new_pass, '', $new_port);
        if ($testConn->connect_error) {
            $errors[] = "Connection failed to {$new_host}:{$new_port} with user '{$new_user}': " . $testConn->connect_error;
        } else {
            // Auto-create database if not exists
            $testConn->query("CREATE DATABASE IF NOT EXISTS `{$new_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $testConn->close();

            // Write back to db.php cleanly
            $escaped_pass = addcslashes($new_pass, "'\\");
            $newConfigCode = "<?php\n"
                . "// ── Database connection ──────────────────────────────────────\n"
                . "if (!defined('DB_HOST')) define('DB_HOST', '{$new_host}');\n"
                . "if (!defined('DB_PORT')) define('DB_PORT', {$new_port});\n"
                . "if (!defined('DB_USER')) define('DB_USER', '{$new_user}');\n"
                . "if (!defined('DB_PASS')) define('DB_PASS', '{$escaped_pass}');\n"
                . "if (!defined('DB_NAME')) define('DB_NAME', '{$new_name}');\n\n"
                . "\$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', (int)DB_PORT);\n\n"
                . "\$db_connected = true;\n"
                . "\$db_error = null;\n\n"
                . "if (\$conn->connect_error) {\n"
                . "    \$db_connected = false;\n"
                . "    \$db_error = \$conn->connect_error;\n\n"
                . "    \$is_setup_script = (basename(\$_SERVER['PHP_SELF'] ?? '') === 'setup.php');\n"
                . "    if (!\$is_setup_script) {\n"
                . "        if (!empty(\$_SERVER['HTTP_ACCEPT']) && strpos(\$_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {\n"
                . "            header('Content-Type: application/json');\n"
                . "            die(json_encode([\n"
                . "                'success' => false,\n"
                . "                'message' => 'Database connection failed: ' . \$conn->connect_error . '. Please configure database at /sms/app/shared/setup.php'\n"
                . "            ]));\n"
                . "        }\n"
                . "        \$host = \$_SERVER['HTTP_HOST'] ?? 'localhost';\n"
                . "        \$proto = (isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] === 'on') ? 'https' : 'http';\n"
                . "        header(\"Location: {\$proto}://{\$host}/sms/app/shared/setup.php?error=db_connect\");\n"
                . "        exit;\n"
                . "    }\n"
                . "} else {\n"
                . "    \$conn->query(\"CREATE DATABASE IF NOT EXISTS `\" . DB_NAME . \"` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\");\n"
                . "    \$conn->select_db(DB_NAME);\n"
                . "    \$conn->set_charset('utf8mb4');\n"
                . "}\n"
                . "?>\n";

            if (file_put_contents($configFile, $newConfigCode)) {
                $messages[] = "✅ Database connection successfully verified and updated!";
                // Refresh connection in current execution
                $conn = @new mysqli($new_host, $new_user, $new_pass, $new_name, $new_port);
                if (!$conn->connect_error) {
                    $db_connected = true;
                    $db_error = null;
                    $conn->set_charset('utf8mb4');
                }
            } else {
                $errors[] = "Failed to write updated settings to db.php. Check folder permissions.";
            }
        }
    }

    // Action 2: Initialize / Rebuild All 18 Database Tables from master schema
    if ($action === 'init_database') {
        if (!$db_connected) {
            $errors[] = "Cannot initialize database: MySQL is not connected. Check connection parameters first.";
        } else {
            $masterSql = dirname(__DIR__, 2) . '/database/sms_db.sql';
            if (executeSQLScript($conn, $masterSql, $errors)) {
                $messages[] = "🎉 All 18 database tables and master schemas successfully initialized!";
            }
        }
    }

    // Action 3: Seed Official System Accounts & Accredited Clubs from SYSTEM_ACCOUNTS.md
    if ($action === 'seed_demo') {
        if (!$db_connected) {
            $errors[] = "Cannot seed data: MySQL is not connected.";
        } else {
            // Remove legacy generic testing accounts
            $conn->query("DELETE FROM users WHERE username IN ('student', 'adviser', 'ssc', 'admin')");

            // Password hashes based on SYSTEM_ACCOUNTS.md
            $admin_hash = password_hash('Bcp@Admin2026!',   PASSWORD_DEFAULT);
            $ssc_hash   = password_hash('Bcp@SSC2026!',     PASSWORD_DEFAULT);
            $std_hash   = password_hash('Bcp@Test2026!',    PASSWORD_DEFAULT);
            $adv_hash   = password_hash('Bcp@Adviser2026!', PASSWORD_DEFAULT);

            // 1. Central Admin & SSC
            $admins = [
                ['scc.admin',   'admin@bcp.edu.ph', 'System', 'Admin',   $admin_hash, 'admin'],
                ['ssc.officer', 'ssc@bcp.edu.ph',   'SSC',    'Officer', $ssc_hash,   'ssc']
            ];
            $u_stmt = $conn->prepare("INSERT INTO users (username, email, first_name, last_name, password_hash, role) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE first_name=VALUES(first_name), last_name=VALUES(last_name), password_hash=VALUES(password_hash), role=VALUES(role)");
            foreach ($admins as $adm) {
                $u_stmt->bind_param('ssssss', $adm[0], $adm[1], $adm[2], $adm[3], $adm[4], $adm[5]);
                $u_stmt->execute();
            }

            // 2. 16 Official Program Students
            $students_list = [
                ['2024-10001', 'Bachelor of Science in Information Technology', 'Juan', 'Santos', 'bsit.student', 'bsit@student.bcp.edu.ph', '2nd Year', 'IT-2A'],
                ['2024-10002', 'Bachelor of Science in Hospitality Management', 'Maria', 'Cruz', 'bshm.student', 'bshm@student.bcp.edu.ph', '1st Year', 'HM-1B'],
                ['2024-10003', 'Bachelor of Science in Accounting Information System', 'Jose', 'Reyes', 'bsais.student', 'bsais@student.bcp.edu.ph', '3rd Year', 'AIS-3A'],
                ['2024-10004', 'Bachelor of Science in Tourism Management', 'Ana', 'Dela Cruz', 'bstm.student', 'bstm@student.bcp.edu.ph', '2nd Year', 'TM-2C'],
                ['2024-10005', 'Bachelor of Science in Office Administration', 'Carlos', 'Garcia', 'bsoa.student', 'bsoa@student.bcp.edu.ph', '1st Year', 'OA-1A'],
                ['2024-10006', 'Bachelor of Science in Entrepreneurship', 'Liza', 'Ramos', 'bse.student', 'bse@student.bcp.edu.ph', '3rd Year', 'ENT-3B'],
                ['2024-10007', 'Bachelor of Science in Business Administration', 'Ramon', 'Villanueva', 'bsba.student', 'bsba@student.bcp.edu.ph', '2nd Year', 'BA-2A'],
                ['2024-10008', 'Bachelor of Science in Information Science', 'Patricia', 'Aquino', 'bsis.student', 'bsis@student.bcp.edu.ph', '1st Year', 'IS-1A'],
                ['2024-10009', 'Bachelor of Science in Computer Engineering', 'Mark', 'Bautista', 'bscpe.student', 'bscpe@student.bcp.edu.ph', '3rd Year', 'CPE-3A'],
                ['2024-10010', 'Bachelor of Science in Psychology', 'Jenny', 'Navarro', 'bspsych.student', 'bspsych@student.bcp.edu.ph', '2nd Year', 'PSY-2B'],
                ['2024-10011', 'Bachelor of Science in Criminology', 'Rico', 'Fernandez', 'bscrim.student', 'bscrim@student.bcp.edu.ph', '4th Year', 'CRIM-4A'],
                ['2024-10012', 'Bachelor of Science in Physical Education', 'Sheila', 'Santos', 'bspe.student', 'bspe@student.bcp.edu.ph', '2nd Year', 'PE-2A'],
                ['2024-10013', 'Technological and Livelihood Education', 'Angelo', 'Torres', 'tle.student', 'tle@student.bcp.edu.ph', '1st Year', 'TLE-1B'],
                ['2024-10014', 'Bachelor of Science in Elementary Education', 'Claire', 'Mendoza', 'bseled.student', 'bseled@student.bcp.edu.ph', '3rd Year', 'ELED-3A'],
                ['2024-10015', 'Bachelor of Science in Secondary Education', 'Danilo', 'Pascual', 'bsseed.student', 'bsseed@student.bcp.edu.ph', '2nd Year', 'SEED-2C'],
                ['2024-10016', 'Bachelor of Science in Library Information Science', 'Rowena', 'Espinosa', 'bslis.student', 'bslis@student.bcp.edu.ph', '3rd Year', 'LIS-3A']
            ];

            $role_student = 'student';
            foreach ($students_list as [$sno, $course, $fn, $ln, $un, $em, $yr, $sec]) {
                $u_stmt->bind_param('ssssss', $un, $em, $fn, $ln, $std_hash, $role_student);
                $u_stmt->execute();

                $uidRes = $conn->query("SELECT id FROM users WHERE username = '{$un}' LIMIT 1");
                $userId = ($uidRes && $row = $uidRes->fetch_assoc()) ? (int)$row['id'] : null;

                // Sync student profile
                $chk = $conn->query("SELECT id FROM students WHERE student_number = '{$sno}' LIMIT 1");
                if ($chk && $chk->num_rows > 0) {
                    $sRow = $chk->fetch_assoc();
                    $conn->query("UPDATE students SET user_id = {$userId}, first_name = '" . $conn->real_escape_string($fn) . "', last_name = '" . $conn->real_escape_string($ln) . "', course = '" . $conn->real_escape_string($course) . "', year_level = '" . $conn->real_escape_string($yr) . "', section = '" . $conn->real_escape_string($sec) . "' WHERE id = {$sRow['id']}");
                } else {
                    $conn->query("INSERT INTO students (user_id, student_number, first_name, last_name, birthday, course, year_level, section, phone, status) VALUES ({$userId}, '{$sno}', '" . $conn->real_escape_string($fn) . "', '" . $conn->real_escape_string($ln) . "', '2004-01-01', '" . $conn->real_escape_string($course) . "', '" . $conn->real_escape_string($yr) . "', '" . $conn->real_escape_string($sec) . "', '09123456789', 'Active')");
                }
            }

            // 3. 40 Official Faculty Advisers & Clubs
            $advisers_list = [
                ['cssec.adviser',    'cssec@adviser.bcp.edu.ph',    'Alex',   'Reyes',   'CSSEC',   'Computer Science Student Exec Council', 'Academic', 'BSCS'],
                ['acads.adviser',    'acads@adviser.bcp.edu.ph',    'Mark',   'Velo',    'ACADS',   'Association of Computer Eng Driven Students', 'Academic', 'BSCpE'],
                ['aces.adviser',     'aces@adviser.bcp.edu.ph',     'Elena',  'Ramos',   'ACES',    'Association of Computer Engineering Students', 'Academic', 'BSCpE'],
                ['aiss.adviser',     'aiss@adviser.bcp.edu.ph',     'Clara',  'Tan',     'AISS',    'Accounting Info System Society', 'Academic', 'BSAIS'],
                ['bliss.adviser',    'bliss@adviser.bcp.edu.ph',    'Robert', 'Cruz',    'BLISS',   'Bestlink Library & Info Science Society', 'Academic', 'BLIS'],
                ['brave.adviser',    'brave@adviser.bcp.edu.ph',    'Diana',  'Gomez',   'BRAVE',   'Values Education & Accountability Org', 'Academic', 'BSEd'],
                ['cjsu.adviser',     'cjsu@adviser.bcp.edu.ph',     'Jose',   'Mercado', 'CJSU',    'Criminal Justice Student Unit', 'Academic', 'BSCrim'],
                ['eyo.adviser',      'eyo@adviser.bcp.edu.ph',      'Lisa',   'Santos',  'EYO',     'Entrepreyouth Organization', 'Academic', 'BSBA'],
                ['galaw.adviser',    'galaw@adviser.bcp.edu.ph',    'Manuel', 'Cruz',    'G.A.L.A.W', 'Athletes & Leaders Wellness Assoc', 'Sports', 'Institutional'],
                ['gems.adviser',     'gems@adviser.bcp.edu.ph',     'Anna',   'Reyes',   'GEMs',    'Guild of English Majors', 'Academic', 'BSEd'],
                ['gold.adviser',     'gold@adviser.bcp.edu.ph',     'Karen',  'Lim',     'GOLD',    'Guild of Officers to Lead Development', 'Academic', 'Institutional'],
                ['jfinex.adviser',   'jfinex@adviser.bcp.edu.ph',   'Manuel', 'Cruz',    'JFINEX',  'Junior Financial Executives', 'Academic', 'BSBA'],
                ['hrs.adviser',      'hrs@adviser.bcp.edu.ph',      'Alex',   'Reyes',   'HRS',     'Human Resources Society', 'Academic', 'BSBA'],
                ['jma.adviser',      'jma@adviser.bcp.edu.ph',      'Sarah',  'Mercado', 'J.M.A',   'Junior Marketing Association', 'Academic', 'BSBA'],
                ['lakas.adviser',    'lakas@adviser.bcp.edu.ph',    'Clara',  'Tan',     'LAKAS',   'Liga ng Aktibong Kabataan sa Araling Panlipunan', 'Academic', 'BSEd'],
                ['lapis.adviser',    'lapis@adviser.bcp.edu.ph',    'Diana',  'Gomez',   'L.A.P.I.S', 'Leadership Assoc Program & Services', 'Advocacy', 'Institutional'],
                ['libro.adviser',    'libro@adviser.bcp.edu.ph',    'Robert', 'Cruz',    'LIBRO',   'Lucid of Bright & Righteous Officers', 'Advocacy', 'BLIS'],
                ['omega.adviser',    'omega@adviser.bcp.edu.ph',    'Jose',   'Mercado', 'OMEGA',   'Org for Mathematics in Engineering', 'Academic', 'BSCpE'],
                ['psychsoc.adviser', 'psychsoc@adviser.bcp.edu.ph', 'Lisa',   'Santos',  'PsychSoc','Psychology Society', 'Academic', 'BSPsych'],
                ['rsd.adviser',      'rsd@adviser.bcp.edu.ph',      'Anna',   'Reyes',   'RSD',     'Regnum Scientiae Discipulus', 'Academic', 'Institutional'],
                ['sigma.adviser',    'sigma@adviser.bcp.edu.ph',    'Karen',  'Lim',     'SIGMA',   'Guild for Mathematics Majors', 'Academic', 'BSEd'],
                ['techs.adviser',    'techs@adviser.bcp.edu.ph',    'Alex',   'Reyes',   'TECHs',   'Tech, Exploratory & Hospitality Skills', 'Academic', 'BSHM'],
                ['tts.adviser',      'tts@adviser.bcp.edu.ph',      'Sarah',  'Mercado', 'TTS',     'Tourism Student Society', 'Academic', 'BSTM'],
                ['wika.adviser',     'wika@adviser.bcp.edu.ph',     'Clara',  'Tan',     'WIKA',    'Wikang Filipino sa Akademya', 'Cultural', 'BSEd'],
                ['acac.adviser',     'acac@adviser.bcp.edu.ph',     'Sarah',  'Mercado', 'ACAC',    'Association of Cultural Art Club', 'Cultural', 'Institutional'],
                ['cesc.adviser',     'cesc@adviser.bcp.edu.ph',     'Mark',   'Velo',    'CESC',    'Computer Engineering Sports Club', 'Sports', 'BSCpE'],
                ['ebcpct.adviser',   'ebcpct@adviser.bcp.edu.ph',   'Mark',   'Velo',    'EBCPCT',  'Elite BCP Chess Team', 'Sports', 'Institutional'],
                ['rcyc.adviser',     'rcyc@adviser.bcp.edu.ph',     'Elena',  'Cruz',    'RCYC-BCP','Red Cross Youth Council - BCP', 'Advocacy', 'Institutional'],
                ['smc.adviser',      'smc@adviser.bcp.edu.ph',      'Mark',   'Velo',    'SMC',     'Shuttle Master Club', 'Sports', 'Institutional'],
                ['allstar.adviser',  'allstar@adviser.bcp.edu.ph',  'Sarah',  'Mercado', 'ALL STAR','All Star Talent Group', 'Cultural', 'Institutional'],
                ['bforce.adviser',   'bforce@adviser.bcp.edu.ph',   'Sarah',  'Mercado', 'B-FORCE', 'B-Force Hip-Hop Crew', 'Cultural', 'Institutional'],
                ['creative.adviser', 'creative@adviser.bcp.edu.ph', 'Sarah',  'Mercado', 'CREATIVE','Creative Arts Group', 'Cultural', 'Institutional'],
                ['cdc.adviser',      'cdc@adviser.bcp.edu.ph',      'Sarah',  'Mercado', 'CDC',     'Criminology Dance Company', 'Cultural', 'BSCrim'],
                ['dlc.adviser',      'dlc@adviser.bcp.edu.ph',      'Sarah',  'Mercado', 'DLC',     'Drum and Lyre Corporation', 'Cultural', 'Institutional'],
                ['ikatlong.adviser', 'ikatlong@adviser.bcp.edu.ph', 'Sarah',  'Mercado', 'IKATLONG','Ikatlong Lahi Royalties', 'Cultural', 'Institutional'],
                ['image.adviser',    'image@adviser.bcp.edu.ph',    'Sarah',  'Mercado', 'IMAGE',   'Image Alchemy Media Crew', 'Cultural', 'Institutional'],
                ['sikat.adviser',    'sikat@adviser.bcp.edu.ph',    'Sarah',  'Mercado', 'S.I.K.A.T','Theater & Acting Group', 'Cultural', 'Institutional'],
                ['uv.adviser',       'uv@adviser.bcp.edu.ph',       'Sarah',  'Mercado', 'UV',      'Unlimited Voice Choir', 'Cultural', 'Institutional'],
                ['peer.adviser',     'peer@adviser.bcp.edu.ph',     'Elena',  'Cruz',    'PEER',    'Peer Counselor Support Group', 'Advocacy', 'Institutional'],
                ['newslink.adviser', 'newslink@adviser.bcp.edu.ph', 'Elena',  'Cruz',    'NEWSLINK','School Publications', 'Advocacy', 'Institutional']
            ];

            $role_adviser = 'club_adviser';
            $c_stmt = $conn->prepare("INSERT INTO clubs (code, name, category, description, adviser_name, status, program) VALUES (?, ?, ?, ?, ?, 'Active', ?) ON DUPLICATE KEY UPDATE name=VALUES(name), adviser_name=VALUES(adviser_name), status='Active', program=VALUES(program)");

            foreach ($advisers_list as [$un, $em, $fn, $ln, $code, $cname, $cat, $prog]) {
                $u_stmt->bind_param('ssssss', $un, $em, $fn, $ln, $adv_hash, $role_adviser);
                $u_stmt->execute();

                $advFullName = "Prof. {$fn} {$ln}";
                $desc = "Official accredited campus student organization for {$cname}.";
                $c_stmt->bind_param('ssssss', $code, $cname, $cat, $desc, $advFullName, $prog);
                $c_stmt->execute();
            }

            $u_stmt->close();
            $c_stmt->close();

            $messages[] = "✨ Official system accounts (Admin, SSC, 16 Program Students, and 40 Faculty Advisers) successfully seeded from SYSTEM_ACCOUNTS.md!";
        }
    }

}

// ------------------------------------------------------------
// GATHER SYSTEM & TABLES TELEMETRY
// ------------------------------------------------------------
$expected_tables = [
    'users'                 => 'Identity, Authentication & 4 Core Roles (student, adviser, ssc, admin)',
    'students'              => 'Student Directory, Academic Profiles & Program Mapping',
    'clubs'                 => 'Accredited Campus Student Organizations & Academic Programs',
    'club_memberships'      => 'Active Roster, Leadership Roles & Advisers',
    'club_applications'     => 'Student Application Submissions & Endorsement Letters',
    'events'                => 'Campus Events, Activity Lifecycle & SSC/Admin Endorsements',
    'event_registrations'   => 'Pre-Registrations & Attendee Participation Roster',
    'budget_requests'       => 'Multi-Tier Budget Proposals (Adviser → SSC → Admin Approval)',
    'attendance_logs'       => 'QR Code, RFID, Self-Check-in & Manual Verification Logs',
    'elections'             => 'Digital Elections Management & Timeline Control',
    'election_candidates'   => 'Candidate Profiles, Platforms & Taglines',
    'election_votes'        => 'Audited, Tamper-Resistant Digital Ballots',
    'achievements'          => 'External Competitions, Awards & Verification System',
    'org_announcements'     => 'Targeted Club Announcements & Urgent Broadcasts',
    'notifications'         => 'System-Wide Alert & Communication Center',
    'audit_logs'            => 'Security, Access & Action Audit Trail',
    'ai_recommendation_logs'=> 'AI Telemetry & Co-Curricular Analytics',
    'system_settings'       => 'System Configurations & Integration API Keys'
];

$existing_tables = [];
$table_counts = [];
if ($db_connected && $conn) {
    $res = $conn->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_TYPE = 'BASE TABLE'");
    if ($res) {
        while ($row = $res->fetch_row()) {
            $existing_tables[] = $row[0];
            $cnt = $conn->query("SELECT COUNT(*) FROM `{$row[0]}`");
            $table_counts[$row[0]] = $cnt ? (int)$cnt->fetch_row()[0] : 0;
        }
    }
}

// Uploads directories check
$uploadDirs = [
    'uploads/achievements/'   => dirname(__DIR__, 2) . '/uploads/achievements/',
    'uploads/applications/'   => dirname(__DIR__, 2) . '/uploads/applications/',
    'uploads/avatars/'        => dirname(__DIR__, 2) . '/uploads/avatars/',
];
foreach ($uploadDirs as $rel => $abs) {
    if (!is_dir($abs)) {
        @mkdir($abs, 0755, true);
    }
}

// Network and LAN IP Detection
$hostName = gethostname();
$lanIp = gethostbyname($hostName);
$serverPort = $_SERVER['SERVER_PORT'] ?? 80;
$portSuffix = ($serverPort != 80 && $serverPort != 443) ? ":{$serverPort}" : "";
$proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';

$localUrl = "{$proto}://localhost{$portSuffix}/sms/";
$lanUrl   = "{$proto}://{$lanIp}{$portSuffix}/sms/";
$setupLanUrl = "{$proto}://{$lanIp}{$portSuffix}/sms/app/shared/setup.php";

$totalExpected = count($expected_tables);
$installedCount = count(array_intersect(array_keys($expected_tables), $existing_tables));
$allInstalled = ($installedCount === $totalExpected);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SMS Database Setup &amp; Deployment Wizard</title>
  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet"/>
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <!-- QR Code Generator -->
  <script src="../../app/js/qrcode.min.js"></script>

  <style>
    :root {
      --primary: #2563eb;
      --primary-hover: #1d4ed8;
      --primary-subtle: #eff6ff;
      --primary-border: #bfdbfe;
      --secondary: #475569;
      --success: #10b981;
      --success-subtle: #ecfdf5;
      --warning: #f59e0b;
      --warning-subtle: #fffbeb;
      --danger: #ef4444;
      --danger-subtle: #fef2f2;
      --bg: #f8fafc;
      --card-bg: #ffffff;
      --border: #e2e8f0;
      --text: #0f172a;
      --text-muted: #64748b;
      --radius: 14px;
      --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
      --shadow-md: 0 4px 14px rgba(0,0,0,0.08);
      --shadow-lg: 0 10px 30px rgba(0,0,0,0.1);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.5;
      padding: 40px 20px;
    }

    .container {
      max-width: 1040px;
      margin: 0 auto;
    }

    /* Header */
    .header-banner {
      background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
      border-radius: var(--radius);
      padding: 32px 36px;
      color: white;
      box-shadow: var(--shadow-md);
      margin-bottom: 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 20px;
    }
    .header-title h1 {
      font-size: 1.75rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .header-title p {
      color: #bfdbfe;
      font-size: 0.95rem;
      margin-top: 6px;
    }
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(255, 255, 255, 0.15);
      backdrop-filter: blur(10px);
      padding: 8px 16px;
      border-radius: 9999px;
      font-size: 0.88rem;
      font-weight: 600;
      border: 1px solid rgba(255, 255, 255, 0.25);
    }
    .status-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
    }
    .status-dot.online { background: #34d399; box-shadow: 0 0 10px #34d399; }
    .status-dot.offline { background: #f87171; box-shadow: 0 0 10px #f87171; }

    /* Alerts */
    .alert {
      padding: 16px 20px;
      border-radius: 10px;
      margin-bottom: 20px;
      font-size: 0.92rem;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .alert-success { background: var(--success-subtle); color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: var(--danger-subtle); color: #991b1b; border: 1px solid #fecaca; }

    /* Grid Layout */
    .grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 24px;
      margin-bottom: 24px;
    }
    @media (max-width: 860px) {
      .grid-2 { grid-template-columns: 1fr; }
    }

    /* Cards */
    .card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 24px;
      box-shadow: var(--shadow-sm);
    }
    .card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 20px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--border);
    }
    .card-header h3 {
      font-size: 1.15rem;
      font-weight: 700;
      color: #1e293b;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    /* Forms */
    .form-group {
      margin-bottom: 16px;
    }
    .form-group label {
      display: block;
      font-size: 0.82rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-muted);
      margin-bottom: 6px;
    }
    .form-control {
      width: 100%;
      padding: 10px 14px;
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.9rem;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: #f8fafc;
      color: var(--text);
      transition: all 0.2s ease;
    }
    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      background: white;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px 18px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 0.9rem;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.2s;
      border: 1px solid transparent;
    }
    .btn-primary { background: var(--primary); color: white; }
    .btn-primary:hover { background: var(--primary-hover); }
    .btn-success { background: var(--success); color: white; }
    .btn-success:hover { background: #059669; }
    .btn-secondary { background: white; border-color: var(--border); color: var(--text); }
    .btn-secondary:hover { background: #f1f5f9; }
    .btn-block { width: 100%; }

    /* Tables Grid */
    .table-list {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
    }
    .table-list th, .table-list td {
      padding: 12px 14px;
      font-size: 0.88rem;
      text-align: left;
      border-bottom: 1px solid #f1f5f9;
    }
    .table-list th {
      background: #f8fafc;
      color: var(--text-muted);
      font-weight: 700;
      text-transform: uppercase;
      font-size: 0.76rem;
      letter-spacing: 0.5px;
    }
    .table-list tr:hover td {
      background: #f8fafc;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 0.78rem;
      font-weight: 700;
    }
    .badge-success { background: #dcfce7; color: #166534; }
    .badge-danger  { background: #fee2e2; color: #991b1b; }
    .badge-info    { background: #e0f2fe; color: #0369a1; }

    /* Network URL box */
    .net-box {
      background: #f1f5f9;
      border: 1px dashed #cbd5e1;
      border-radius: 10px;
      padding: 14px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .net-url {
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.95rem;
      font-weight: 600;
      color: #1e3a8a;
      word-break: break-all;
    }
    .copy-btn {
      padding: 6px 12px;
      font-size: 0.8rem;
      background: white;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 600;
    }
    .copy-btn:hover { background: #e2e8f0; }

    /* QR Code Display */
    .qr-container {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 16px;
      background: white;
      border: 1px solid var(--border);
      border-radius: 12px;
      margin-top: 14px;
    }
    #qrcode img {
      margin: 0 auto;
    }

    /* Accounts Grid */
    .accounts-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 14px;
      margin-top: 14px;
    }
    .account-card {
      background: #f8fafc;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 14px;
    }
    .account-role {
      font-size: 0.76rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 6px;
    }
    .account-cred {
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.86rem;
      color: #334155;
    }
  </style>
</head>
<body>

<div class="container">

  <!-- Header Banner -->
  <div class="header-banner">
    <div class="header-title">
      <h1><i class="fa-solid fa-server"></i> SMS Database Setup &amp; Deployment</h1>
      <p>Configure local database, run schema migrations, or deploy to other devices on the network.</p>
    </div>
    <div>
      <?php if ($db_connected): ?>
        <div class="status-pill"><span class="status-dot online"></span> MySQL Connected (<?= htmlspecialchars(DB_NAME) ?>)</div>
      <?php else: ?>
        <div class="status-pill"><span class="status-dot offline"></span> MySQL Disconnected</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Messages & Errors -->
  <?php foreach ($messages as $msg): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div>
  <?php endforeach; ?>
  <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>

  <!-- Two Column Layout: DB Configuration & Network Access -->
  <div class="grid-2">

    <!-- Column 1: Database Settings Form -->
    <div class="card">
      <div class="card-header">
        <h3><i class="fa-solid fa-sliders text-primary"></i> Database Parameters</h3>
        <span class="badge badge-info"><?= $db_connected ? 'Active' : 'Action Required' ?></span>
      </div>
      <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 16px;">
        Adjust host, user, password, or port if running on a separate machine, remote server, or custom XAMPP setup.
      </p>

      <form method="POST">
        <input type="hidden" name="action" value="save_config"/>
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px;">
          <div class="form-group">
            <label>Database Host</label>
            <input type="text" name="db_host" class="form-control" value="<?= htmlspecialchars(defined('DB_HOST') ? DB_HOST : '127.0.0.1') ?>" required/>
          </div>
          <div class="form-group">
            <label>Port</label>
            <input type="number" name="db_port" class="form-control" value="<?= htmlspecialchars(defined('DB_PORT') ? DB_PORT : 3306) ?>" required/>
          </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
          <div class="form-group">
            <label>User</label>
            <input type="text" name="db_user" class="form-control" value="<?= htmlspecialchars(defined('DB_USER') ? DB_USER : 'root') ?>" required/>
          </div>
          <div class="form-group">
            <label>Password</label>
            <input type="password" name="db_pass" class="form-control" value="<?= htmlspecialchars(defined('DB_PASS') ? DB_PASS : '') ?>" placeholder="Empty if default XAMPP"/>
          </div>
        </div>

        <div class="form-group">
          <label>Database Name</label>
          <input type="text" name="db_name" class="form-control" value="<?= htmlspecialchars(defined('DB_NAME') ? DB_NAME : 'sms_db') ?>" required/>
        </div>

        <button type="submit" class="btn btn-primary btn-block">
          <i class="fa-solid fa-floppy-disk"></i> Test &amp; Save Configuration
        </button>
      </form>
    </div>

    <!-- Column 2: Multi-Device & Mobile Access -->
    <div class="card">
      <div class="card-header">
        <h3><i class="fa-solid fa-network-wired text-primary"></i> Multi-Device &amp; LAN Access</h3>
        <span class="badge badge-success"><i class="fa-solid fa-wifi"></i> LAN Ready</span>
      </div>
      <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 12px;">
        To open this SMS portal from other phones, tablets, or laptops on the same Wi-Fi:
      </p>

      <div class="net-box">
        <div>
          <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700;">NETWORK ACCESS URL:</div>
          <div class="net-url" id="lanUrlText"><?= htmlspecialchars($lanUrl) ?></div>
        </div>
        <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($lanUrl) ?>')">
          <i class="fa-regular fa-copy"></i> Copy
        </button>
      </div>

      <!-- QR Code Container -->
      <div class="qr-container">
        <div id="qrcode"></div>
        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 10px; font-weight: 600;">
          <i class="fa-solid fa-qrcode"></i> Scan with phone camera to connect instantly
        </div>
      </div>
    </div>

  </div>

  <!-- Schema Installation & Seeding Controls -->
  <div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
      <h3><i class="fa-solid fa-database text-primary"></i> Schema Installation &amp; Seed Actions</h3>
      <div>
        <span class="badge <?= $allInstalled ? 'badge-success' : 'badge-danger' ?>">
          <?= $installedCount ?> / <?= $totalExpected ?> Tables Initialized
        </span>
      </div>
    </div>

    <div style="display: flex; flex-wrap: wrap; gap: 14px; align-items: center; justify-content: space-between; background: #f8fafc; padding: 18px; border-radius: 10px; border: 1px solid var(--border);">
      <div>
        <h4 style="font-size: 1rem; font-weight: 700; margin-bottom: 4px;">Initialize 18 Canonical Tables</h4>
        <p style="font-size: 0.86rem; color: var(--text-muted);">
          Executes master <code>database/sms_db.sql</code> with all foreign keys, indexes, modern ENUMs, and seed data.
        </p>
      </div>
      <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <form method="POST" style="display: inline-block;">
          <input type="hidden" name="action" value="init_database"/>
          <button type="submit" class="btn btn-primary" onclick="return confirm('Initialize / synchronize all 18 tables?');">
            <i class="fa-solid fa-rocket"></i> <?= $allInstalled ? 'Re-Sync Database Schema' : 'Install All 18 Tables' ?>
          </button>
        </form>

        <form method="POST" style="display: inline-block;">
          <input type="hidden" name="action" value="seed_demo"/>
          <button type="submit" class="btn btn-secondary">
            <i class="fa-solid fa-seedling text-success"></i> Seed Demo Accounts &amp; Clubs
          </button>
        </form>

        <a href="../auth/signin.php" class="btn btn-success">
          <i class="fa-solid fa-right-to-bracket"></i> Go to Sign In
        </a>
      </div>
    </div>

    <!-- Table Health Status -->
    <h4 style="font-size: 0.95rem; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">Component Tables Status</h4>
    <div style="overflow-x: auto;">
      <table class="table-list">
        <thead>
          <tr>
            <th>Table Name</th>
            <th>Component Description</th>
            <th>Row Count</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expected_tables as $tbl => $desc): ?>
            <?php
              $is_installed = in_array($tbl, $existing_tables);
              $cnt = $table_counts[$tbl] ?? 0;
            ?>
            <tr>
              <td><strong style="font-family: 'JetBrains Mono', monospace;"><?= htmlspecialchars($tbl) ?></strong></td>
              <td style="color: #475569;"><?= htmlspecialchars($desc) ?></td>
              <td><span style="font-family: 'JetBrains Mono', monospace; font-weight: 600;"><?= $is_installed ? number_format($cnt) : '-' ?></span></td>
              <td>
                <?php if ($is_installed): ?>
                  <span class="badge badge-success"><i class="fa-solid fa-check"></i> Installed</span>
                <?php else: ?>
                  <span class="badge badge-danger"><i class="fa-solid fa-xmark"></i> Missing</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Official System Accounts Directory (Recognized by Database) -->
  <div class="card">
    <div class="card-header">
      <h3><i class="fa-solid fa-id-card-clip text-primary"></i> Official System Accounts Directory</h3>
      <span class="badge badge-success"><i class="fa-solid fa-check-double"></i> SYSTEM_ACCOUNTS.md Verified</span>
    </div>
    <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 16px;">
      These are the official system accounts recognized by the database across Central Admin, SSC Governance, 16 Academic Programs, and 40 Campus Student Organizations. Use the copy buttons for quick sign-in.
    </p>

    <!-- Quick Password Reference Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-bottom: 20px;">
      <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 12px 14px;">
        <span style="font-size: 0.74rem; font-weight: 800; color: #1d4ed8; text-transform: uppercase;">Central Admin Password</span>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
          <code style="font-size: 0.92rem; font-weight: 700; color: #1e3a8a;">Bcp@Admin2026!</code>
          <button type="button" class="copy-btn" onclick="copyText('Bcp@Admin2026!')"><i class="fa-regular fa-copy"></i></button>
        </div>
      </div>
      <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 12px 14px;">
        <span style="font-size: 0.74rem; font-weight: 800; color: #b45309; text-transform: uppercase;">SSC Officer Password</span>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
          <code style="font-size: 0.92rem; font-weight: 700; color: #78350f;">Bcp@SSC2026!</code>
          <button type="button" class="copy-btn" onclick="copyText('Bcp@SSC2026!')"><i class="fa-regular fa-copy"></i></button>
        </div>
      </div>
      <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 14px;">
        <span style="font-size: 0.74rem; font-weight: 800; color: #15803d; text-transform: uppercase;">Student Password (16 Programs)</span>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
          <code style="font-size: 0.92rem; font-weight: 700; color: #14532d;">Bcp@Test2026!</code>
          <button type="button" class="copy-btn" onclick="copyText('Bcp@Test2026!')"><i class="fa-regular fa-copy"></i></button>
        </div>
      </div>
      <div style="background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 10px; padding: 12px 14px;">
        <span style="font-size: 0.74rem; font-weight: 800; color: #7e22ce; text-transform: uppercase;">Faculty Adviser Password (40 Orgs)</span>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
          <code style="font-size: 0.92rem; font-weight: 700; color: #581c87;">Bcp@Adviser2026!</code>
          <button type="button" class="copy-btn" onclick="copyText('Bcp@Adviser2026!')"><i class="fa-regular fa-copy"></i></button>
        </div>
      </div>
    </div>

    <!-- Navigation Tabs -->
    <div style="display: flex; gap: 8px; border-bottom: 2px solid var(--border); margin-bottom: 18px; flex-wrap: wrap;">
      <button type="button" id="tabBtnAdmin" class="btn" style="border-radius: 8px 8px 0 0; background: #2563eb; color: white;" onclick="switchAccountTab('admin')">
        <i class="fa-solid fa-crown"></i> Central Admin &amp; SSC (2)
      </button>
      <button type="button" id="tabBtnStudents" class="btn btn-secondary" style="border-radius: 8px 8px 0 0;" onclick="switchAccountTab('students')">
        <i class="fa-solid fa-graduation-cap"></i> Program Students (16)
      </button>
      <button type="button" id="tabBtnAdvisers" class="btn btn-secondary" style="border-radius: 8px 8px 0 0;" onclick="switchAccountTab('advisers')">
        <i class="fa-solid fa-chalkboard-user"></i> Faculty Club Advisers (40)
      </button>
    </div>

    <!-- TAB 1: Central Admin & SSC -->
    <div id="tabContentAdmin">
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
        <div class="account-card" style="border-top: 4px solid #ef4444; background: white;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <span class="account-role" style="color: #dc2626;"><i class="fa-solid fa-shield-halved"></i> Institutional Administrator</span>
            <span class="badge badge-danger">Role: admin</span>
          </div>
          <div style="font-size: 1.05rem; font-weight: 700; color: #0f172a; margin-bottom: 6px;">System Admin</div>
          <div class="account-cred" style="margin-bottom: 4px;">Username: <strong>scc.admin</strong> <button type="button" class="copy-btn" style="padding: 2px 6px; font-size: 0.72rem;" onclick="copyText('scc.admin')"><i class="fa-regular fa-copy"></i></button></div>
          <div class="account-cred" style="margin-bottom: 4px;">Email: <strong>admin@bcp.edu.ph</strong></div>
          <div class="account-cred" style="margin-bottom: 6px;">Password: <strong>Bcp@Admin2026!</strong> <button type="button" class="copy-btn" style="padding: 2px 6px; font-size: 0.72rem;" onclick="copyText('Bcp@Admin2026!')"><i class="fa-regular fa-copy"></i></button></div>
          <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 8px; border-top: 1px dashed var(--border); padding-top: 6px;">
            Full governance, institutional oversight, system settings, and user management.
          </div>
        </div>

        <div class="account-card" style="border-top: 4px solid #f59e0b; background: white;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <span class="account-role" style="color: #d97706;"><i class="fa-solid fa-award"></i> Student Council Governance</span>
            <span class="badge badge-info" style="background: #fef3c7; color: #92400e;">Role: ssc</span>
          </div>
          <div style="font-size: 1.05rem; font-weight: 700; color: #0f172a; margin-bottom: 6px;">SSC Officer</div>
          <div class="account-cred" style="margin-bottom: 4px;">Username: <strong>ssc.officer</strong> <button type="button" class="copy-btn" style="padding: 2px 6px; font-size: 0.72rem;" onclick="copyText('ssc.officer')"><i class="fa-regular fa-copy"></i></button></div>
          <div class="account-cred" style="margin-bottom: 4px;">Email: <strong>ssc@bcp.edu.ph</strong></div>
          <div class="account-cred" style="margin-bottom: 6px;">Password: <strong>Bcp@SSC2026!</strong> <button type="button" class="copy-btn" style="padding: 2px 6px; font-size: 0.72rem;" onclick="copyText('Bcp@SSC2026!')"><i class="fa-regular fa-copy"></i></button></div>
          <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 8px; border-top: 1px dashed var(--border); padding-top: 6px;">
            Inter-club activity approvals, budget allocations, achievement endorsements, and campus elections.
          </div>
        </div>
      </div>
    </div>

    <!-- TAB 2: Students by Academic Program (16 Programs) -->
    <div id="tabContentStudents" style="display: none;">
      <div style="overflow-x: auto;">
        <table class="table-list">
          <thead>
            <tr>
              <th>Program</th>
              <th>Student Name</th>
              <th>Student No.</th>
              <th>Username</th>
              <th>Email</th>
              <th>Year &amp; Section</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $official_students_view = [
              ['BSIT', 'Juan Santos', '2024-10001', 'bsit.student', 'bsit@student.bcp.edu.ph', '2nd Year (IT-2A)'],
              ['BSHM', 'Maria Cruz', '2024-10002', 'bshm.student', 'bshm@student.bcp.edu.ph', '1st Year (HM-1B)'],
              ['BSAIS', 'Jose Reyes', '2024-10003', 'bsais.student', 'bsais@student.bcp.edu.ph', '3rd Year (AIS-3A)'],
              ['BSTM', 'Ana Dela Cruz', '2024-10004', 'bstm.student', 'bstm@student.bcp.edu.ph', '2nd Year (TM-2C)'],
              ['BSOA', 'Carlos Garcia', '2024-10005', 'bsoa.student', 'bsoa@student.bcp.edu.ph', '1st Year (OA-1A)'],
              ['BSE', 'Liza Ramos', '2024-10006', 'bse.student', 'bse@student.bcp.edu.ph', '3rd Year (ENT-3B)'],
              ['BSBA', 'Ramon Villanueva', '2024-10007', 'bsba.student', 'bsba@student.bcp.edu.ph', '2nd Year (BA-2A)'],
              ['BSIS', 'Patricia Aquino', '2024-10008', 'bsis.student', 'bsis@student.bcp.edu.ph', '1st Year (IS-1A)'],
              ['BSCpE', 'Mark Bautista', '2024-10009', 'bscpe.student', 'bscpe@student.bcp.edu.ph', '3rd Year (CPE-3A)'],
              ['BSPsych', 'Jenny Navarro', '2024-10010', 'bspsych.student', 'bspsych@student.bcp.edu.ph', '2nd Year (PSY-2B)'],
              ['BSCrim', 'Rico Fernandez', '2024-10011', 'bscrim.student', 'bscrim@student.bcp.edu.ph', '4th Year (CRIM-4A)'],
              ['BSPE', 'Sheila Santos', '2024-10012', 'bspe.student', 'bspe@student.bcp.edu.ph', '2nd Year (PE-2A)'],
              ['TLE', 'Angelo Torres', '2024-10013', 'tle.student', 'tle@student.bcp.edu.ph', '1st Year (TLE-1B)'],
              ['BSElEd', 'Claire Mendoza', '2024-10014', 'bseled.student', 'bseled@student.bcp.edu.ph', '3rd Year (ELED-3A)'],
              ['BSSecEd', 'Danilo Pascual', '2024-10015', 'bsseed.student', 'bsseed@student.bcp.edu.ph', '2nd Year (SEED-2C)'],
              ['BSLIS', 'Rowena Espinosa', '2024-10016', 'bslis.student', 'bslis@student.bcp.edu.ph', '3rd Year (LIS-3A)']
            ];
            foreach ($official_students_view as [$prog, $sname, $sno, $sun, $sem, $ssec]):
            ?>
              <tr>
                <td><span class="badge badge-info" style="font-size: 0.8rem;"><?= htmlspecialchars($prog) ?></span></td>
                <td><strong><?= htmlspecialchars($sname) ?></strong></td>
                <td><code style="font-size: 0.8rem;"><?= htmlspecialchars($sno) ?></code></td>
                <td><code style="font-weight: 700; color: #1e3a8a;"><?= htmlspecialchars($sun) ?></code></td>
                <td style="color: #64748b; font-size: 0.84rem;"><?= htmlspecialchars($sem) ?></td>
                <td><?= htmlspecialchars($ssec) ?></td>
                <td>
                  <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($sun) ?>')" title="Copy Username">
                    <i class="fa-regular fa-copy"></i>
                  </button>
                  <button type="button" class="copy-btn" onclick="copyText('Bcp@Test2026!')" title="Copy Password">
                    <i class="fa-solid fa-key"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- TAB 3: Faculty Club Advisers (40 Campus Organizations) -->
    <div id="tabContentAdvisers" style="display: none;">
      <div style="overflow-x: auto;">
        <table class="table-list">
          <thead>
            <tr>
              <th>Org Code</th>
              <th>Campus Organization Name</th>
              <th>Faculty Adviser</th>
              <th>Adviser Username</th>
              <th>Email</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $official_advisers_view = [
              ['CSSEC', 'Computer Science Student Exec Council', 'Prof. Alex Reyes', 'cssec.adviser', 'cssec@adviser.bcp.edu.ph'],
              ['ACADS', 'Association of Computer Eng Driven Students', 'Prof. Mark Velo', 'acads.adviser', 'acads@adviser.bcp.edu.ph'],
              ['ACES', 'Association of Computer Engineering Students', 'Prof. Elena Ramos', 'aces.adviser', 'aces@adviser.bcp.edu.ph'],
              ['AISS', 'Accounting Info System Society', 'Prof. Clara Tan', 'aiss.adviser', 'aiss@adviser.bcp.edu.ph'],
              ['BLISS', 'Bestlink Library & Info Science Society', 'Prof. Robert Cruz', 'bliss.adviser', 'bliss@adviser.bcp.edu.ph'],
              ['BRAVE', 'Values Education & Accountability Org', 'Prof. Diana Gomez', 'brave.adviser', 'brave@adviser.bcp.edu.ph'],
              ['CJSU', 'Criminal Justice Student Unit', 'Prof. Jose Mercado', 'cjsu.adviser', 'cjsu@adviser.bcp.edu.ph'],
              ['EYO', 'Entrepreyouth Organization', 'Prof. Lisa Santos', 'eyo.adviser', 'eyo@adviser.bcp.edu.ph'],
              ['G.A.L.A.W', 'Athletes & Leaders Wellness Assoc', 'Prof. Manuel Cruz', 'galaw.adviser', 'galaw@adviser.bcp.edu.ph'],
              ['GEMs', 'Guild of English Majors', 'Prof. Anna Reyes', 'gems.adviser', 'gems@adviser.bcp.edu.ph'],
              ['GOLD', 'Guild of Officers to Lead Development', 'Prof. Karen Lim', 'gold.adviser', 'gold@adviser.bcp.edu.ph'],
              ['JFINEX', 'Junior Financial Executives', 'Prof. Manuel Cruz', 'jfinex.adviser', 'jfinex@adviser.bcp.edu.ph'],
              ['HRS', 'Human Resources Society', 'Prof. Alex Reyes', 'hrs.adviser', 'hrs@adviser.bcp.edu.ph'],
              ['J.M.A', 'Junior Marketing Association', 'Prof. Sarah Mercado', 'jma.adviser', 'jma@adviser.bcp.edu.ph'],
              ['LAKAS', 'Liga ng Aktibong Kabataan sa Araling Panlipunan', 'Prof. Clara Tan', 'lakas.adviser', 'lakas@adviser.bcp.edu.ph'],
              ['L.A.P.I.S', 'Leadership Assoc Program & Services', 'Prof. Diana Gomez', 'lapis.adviser', 'lapis@adviser.bcp.edu.ph'],
              ['LIBRO', 'Lucid of Bright & Righteous Officers', 'Prof. Robert Cruz', 'libro.adviser', 'libro@adviser.bcp.edu.ph'],
              ['OMEGA', 'Org for Mathematics in Engineering', 'Prof. Jose Mercado', 'omega.adviser', 'omega@adviser.bcp.edu.ph'],
              ['PsychSoc', 'Psychology Society', 'Prof. Lisa Santos', 'psychsoc.adviser', 'psychsoc@adviser.bcp.edu.ph'],
              ['RSD', 'Regnum Scientiae Discipulus', 'Prof. Anna Reyes', 'rsd.adviser', 'rsd@adviser.bcp.edu.ph'],
              ['SIGMA', 'Guild for Mathematics Majors', 'Prof. Karen Lim', 'sigma.adviser', 'sigma@adviser.bcp.edu.ph'],
              ['TECHs', 'Tech, Exploratory & Hospitality Skills', 'Prof. Alex Reyes', 'techs.adviser', 'techs@adviser.bcp.edu.ph'],
              ['TTS', 'Tourism Student Society', 'Prof. Sarah Mercado', 'tts.adviser', 'tts@adviser.bcp.edu.ph'],
              ['WIKA', 'Wikang Filipino sa Akademya', 'Prof. Clara Tan', 'wika.adviser', 'wika@adviser.bcp.edu.ph'],
              ['ACAC', 'Association of Cultural Art Club', 'Prof. Sarah Mercado', 'acac.adviser', 'acac@adviser.bcp.edu.ph'],
              ['CESC', 'Computer Engineering Sports Club', 'Prof. Mark Velo', 'cesc.adviser', 'cesc@adviser.bcp.edu.ph'],
              ['EBCPCT', 'Elite BCP Chess Team', 'Prof. Mark Velo', 'ebcpct.adviser', 'ebcpct@adviser.bcp.edu.ph'],
              ['RCYC-BCP', 'Red Cross Youth Council - BCP', 'Dr. Elena Cruz', 'rcyc.adviser', 'rcyc@adviser.bcp.edu.ph'],
              ['SMC', 'Shuttle Master Club', 'Prof. Mark Velo', 'smc.adviser', 'smc@adviser.bcp.edu.ph'],
              ['ALL STAR', 'All Star Talent Group', 'Prof. Sarah Mercado', 'allstar.adviser', 'allstar@adviser.bcp.edu.ph'],
              ['B-FORCE', 'B-Force Hip-Hop Crew', 'Prof. Sarah Mercado', 'bforce.adviser', 'bforce@adviser.bcp.edu.ph'],
              ['CREATIVE', 'Creative Arts Group', 'Prof. Sarah Mercado', 'creative.adviser', 'creative@adviser.bcp.edu.ph'],
              ['CDC', 'Criminology Dance Company', 'Prof. Sarah Mercado', 'cdc.adviser', 'cdc@adviser.bcp.edu.ph'],
              ['DLC', 'Drum and Lyre Corporation', 'Prof. Sarah Mercado', 'dlc.adviser', 'dlc@adviser.bcp.edu.ph'],
              ['IKATLONG', 'Ikatlong Lahi Royalties', 'Prof. Sarah Mercado', 'ikatlong.adviser', 'ikatlong@adviser.bcp.edu.ph'],
              ['IMAGE', 'Image Alchemy Media Crew', 'Prof. Sarah Mercado', 'image.adviser', 'image@adviser.bcp.edu.ph'],
              ['S.I.K.A.T', 'Theater & Acting Group', 'Prof. Sarah Mercado', 'sikat.adviser', 'sikat@adviser.bcp.edu.ph'],
              ['UV', 'Unlimited Voice Choir', 'Prof. Sarah Mercado', 'uv.adviser', 'uv@adviser.bcp.edu.ph'],
              ['PEER', 'Peer Counselor Support Group', 'Dr. Elena Cruz', 'peer.adviser', 'peer@adviser.bcp.edu.ph'],
              ['NEWSLINK', 'School Publications', 'Dr. Elena Cruz', 'newslink.adviser', 'newslink@adviser.bcp.edu.ph']
            ];
            foreach ($official_advisers_view as [$code, $cname, $adv, $un, $em]):
            ?>
              <tr>
                <td><strong style="color: #1e3a8a;"><?= htmlspecialchars($code) ?></strong></td>
                <td><?= htmlspecialchars($cname) ?></td>
                <td><strong><?= htmlspecialchars($adv) ?></strong></td>
                <td><code style="font-weight: 700; color: #166534;"><?= htmlspecialchars($un) ?></code></td>
                <td style="color: #64748b; font-size: 0.84rem;"><?= htmlspecialchars($em) ?></td>
                <td>
                  <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($un) ?>')" title="Copy Username">
                    <i class="fa-regular fa-copy"></i>
                  </button>
                  <button type="button" class="copy-btn" onclick="copyText('Bcp@Adviser2026!')" title="Copy Password">
                    <i class="fa-solid fa-key"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

</div>

<!-- Generate QR Code & Switch Tabs via JS -->
<script>
  function switchAccountTab(tab) {
    var tabAdmin = document.getElementById('tabContentAdmin');
    var tabStudents = document.getElementById('tabContentStudents');
    var tabAdvisers = document.getElementById('tabContentAdvisers');

    var btnAdmin = document.getElementById('tabBtnAdmin');
    var btnStudents = document.getElementById('tabBtnStudents');
    var btnAdvisers = document.getElementById('tabBtnAdvisers');

    tabAdmin.style.display = (tab === 'admin') ? 'block' : 'none';
    tabStudents.style.display = (tab === 'students') ? 'block' : 'none';
    tabAdvisers.style.display = (tab === 'advisers') ? 'block' : 'none';

    btnAdmin.className = (tab === 'admin') ? 'btn btn-primary' : 'btn btn-secondary';
    btnStudents.className = (tab === 'students') ? 'btn btn-primary' : 'btn btn-secondary';
    btnAdvisers.className = (tab === 'advisers') ? 'btn btn-primary' : 'btn btn-secondary';
  }

  function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
      alert('Copied to clipboard: ' + text);
    }).catch(() => {
      prompt('Copy text:', text);
    });
  }

  // Generate QR code for mobile connection
  document.addEventListener('DOMContentLoaded', function() {
    var qrContainer = document.getElementById('qrcode');
    if (qrContainer && typeof QRCode !== 'undefined') {
      new QRCode(qrContainer, {
        text: '<?= $lanUrl ?>',
        width: 140,
        height: 140,
        colorDark: '#1e3a8a',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
    }
  });
</script>


</div>

<!-- Generate QR Code via JS -->
<script>
  function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
      alert('Network URL copied to clipboard: ' + text);
    }).catch(() => {
      prompt('Copy this URL:', text);
    });
  }

  // Generate QR code for mobile connection
  document.addEventListener('DOMContentLoaded', function() {
    var qrContainer = document.getElementById('qrcode');
    if (qrContainer && typeof QRCode !== 'undefined') {
      new QRCode(qrContainer, {
        text: '<?= $lanUrl ?>',
        width: 140,
        height: 140,
        colorDark: '#1e3a8a',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
    }
  });
</script>

</body>
</html>
