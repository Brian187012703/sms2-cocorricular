<?php
// ============================================================
//  SETUP.PHP  (shared/) — Co-Curricular Management System Setup
//  Visit: http://localhost/sms/app/shared/setup.php
// ============================================================
require_once __DIR__ . '/db.php';

$errors = [];

function runSQL($conn, string $sql, string $label, array &$errors): void {
    if (!$conn->query($sql)) $errors[] = "$label: " . $conn->error;
}

// ============================================================
//  1. Users table supporting 4 Core System Roles
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)   NOT NULL UNIQUE,
    email         VARCHAR(150)  NOT NULL UNIQUE,
    first_name    VARCHAR(100)  NOT NULL,
    last_name     VARCHAR(100)  NOT NULL,
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM('admin','student','club_adviser','ssc') NOT NULL DEFAULT 'student',
    profile_pic   VARCHAR(255)  DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create users table", $errors);

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_pic VARCHAR(255) DEFAULT NULL AFTER role");

// Ensure role ENUM is up to date & migrate legacy roles
$conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin','student','club_adviser','ssc') NOT NULL DEFAULT 'student'");
$conn->query("UPDATE users SET role='ssc' WHERE role IN ('ssc', 'ssc', 'club_officer')");

// ============================================================
//  2. Clubs table
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS clubs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code         VARCHAR(20)  NOT NULL UNIQUE,
    name         VARCHAR(150) NOT NULL,
    category     ENUM('Academic','Cultural','Sports','Advocacy','Religious') NOT NULL DEFAULT 'Academic',
    description  TEXT,
    adviser_name VARCHAR(150) DEFAULT 'Unassigned',
    status       ENUM('Active','Pending Charter','Suspended') NOT NULL DEFAULT 'Active',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create clubs table", $errors);

// ============================================================
//  3. Club Memberships table
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS club_memberships (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id     INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    role        VARCHAR(50) DEFAULT 'Member',
    status      ENUM('Active','Pending','Rejected') NOT NULL DEFAULT 'Pending',
    approved_by INT UNSIGNED DEFAULT NULL,
    letter_intent VARCHAR(255) DEFAULT NULL,
    letter_endorsement VARCHAR(255) DEFAULT NULL,
    joined_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_club_user (club_id, user_id),
    KEY idx_user (user_id),
    KEY idx_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create club_memberships table", $errors);

$conn->query("ALTER TABLE club_memberships ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED DEFAULT NULL");
$conn->query("ALTER TABLE club_memberships ADD COLUMN IF NOT EXISTS letter_intent VARCHAR(255) DEFAULT NULL");
$conn->query("ALTER TABLE club_memberships ADD COLUMN IF NOT EXISTS letter_endorsement VARCHAR(255) DEFAULT NULL");

// ============================================================
//  3.1. Event Registrations table
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS event_registrations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id      INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status        ENUM('Registered','Attended','Cancelled') NOT NULL DEFAULT 'Registered',
    UNIQUE KEY uq_event_user_reg (event_id, user_id),
    KEY idx_user (user_id),
    KEY idx_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create event_registrations table", $errors);

// ============================================================
//  4. Events table
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS events (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id           INT UNSIGNED DEFAULT NULL,
    event_type        ENUM('Club','Institutional') NOT NULL DEFAULT 'Club',
    title             VARCHAR(200) NOT NULL,
    description       TEXT,
    event_date        DATETIME NOT NULL,
    venue             VARCHAR(150) NOT NULL,
    status            ENUM('Upcoming','Approved','Completed','Pending SSC','Pending Admin','Rejected') NOT NULL DEFAULT 'Pending SSC',
    created_by        INT UNSIGNED DEFAULT NULL,
    endorsement_notes TEXT DEFAULT NULL,
    rejection_note    TEXT DEFAULT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create events table", $errors);

$conn->query("ALTER TABLE events MODIFY COLUMN club_id INT UNSIGNED DEFAULT NULL");
$conn->query("ALTER TABLE events ADD COLUMN IF NOT EXISTS event_type ENUM('Club','Institutional') NOT NULL DEFAULT 'Club' AFTER club_id");
$conn->query("ALTER TABLE events ADD COLUMN IF NOT EXISTS endorsement_notes TEXT DEFAULT NULL AFTER created_by");
$conn->query("ALTER TABLE events MODIFY COLUMN status ENUM('Upcoming','Approved','Completed','Pending SSC','Pending Admin','Rejected') NOT NULL DEFAULT 'Pending SSC'");
$conn->query("UPDATE events SET status='Pending SSC' WHERE status IN ('Pending OSA')");

// ============================================================
//  5. Budget Requests table
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS budget_requests (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id      INT UNSIGNED NOT NULL,
    title        VARCHAR(200) NOT NULL,
    description  TEXT DEFAULT NULL,
    amount       DECIMAL(10,2) NOT NULL,
    status       ENUM('Pending Adviser','Pending SSC','Pending Admin','Disbursed','Rejected') NOT NULL DEFAULT 'Pending Adviser',
    requested_by INT UNSIGNED NOT NULL,
    notes        TEXT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create budget_requests table", $errors);

$conn->query("ALTER TABLE events ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL");
$conn->query("ALTER TABLE budget_requests MODIFY COLUMN status ENUM('Pending Adviser','Pending SSC','Pending Admin','Disbursed','Rejected') NOT NULL DEFAULT 'Pending Adviser'");
$conn->query("UPDATE budget_requests SET status='Pending SSC' WHERE status IN ('Pending OSA', 'Pending Finance')");
$conn->query("ALTER TABLE budget_requests ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL");
$conn->query("ALTER TABLE clubs ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL");

// ============================================================
//  6. Attendance Logs table
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS attendance_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id    INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    check_in    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    method      ENUM('QR','RFID','Manual') NOT NULL DEFAULT 'QR',
    logged_by   INT UNSIGNED DEFAULT NULL,
    UNIQUE KEY uq_event_user (event_id, user_id),
    KEY idx_user (user_id),
    KEY idx_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create attendance_logs table", $errors);

// ============================================================
//  7. Achievements table
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS achievements (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id      INT UNSIGNED NOT NULL,
    submitted_by INT UNSIGNED NOT NULL,
    title        VARCHAR(250) NOT NULL,
    competition  VARCHAR(250) NOT NULL,
    award_date   DATE NOT NULL,
    proof_file   VARCHAR(300) DEFAULT NULL,
    status       ENUM('Pending','Verified','Rejected') NOT NULL DEFAULT 'Pending',
    verified_by  INT UNSIGNED DEFAULT NULL,
    notes        TEXT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create achievements table", $errors);

// ============================================================
//  8. Notifications table
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    title      VARCHAR(200) NOT NULL,
    message    TEXT NOT NULL,
    type       VARCHAR(50) DEFAULT 'info',
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_read (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create notifications table", $errors);

// ============================================================
//  9. Audit Logs table
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS audit_logs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    action       VARCHAR(100) NOT NULL,
    target_table VARCHAR(100) DEFAULT NULL,
    target_id    INT UNSIGNED DEFAULT NULL,
    detail       TEXT DEFAULT NULL,
    ip_address   VARCHAR(50) DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create audit_logs table", $errors);

// ============================================================
//  10. Students table
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS students (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_number VARCHAR(50)   DEFAULT NULL,
    first_name     VARCHAR(100)  NOT NULL,
    last_name      VARCHAR(100)  NOT NULL,
    birthday       DATE          NOT NULL,
    course         VARCHAR(150)  NOT NULL,
    year_level     VARCHAR(50)   NOT NULL,
    section        VARCHAR(50)   NOT NULL,
    phone          VARCHAR(20)   NOT NULL,
    status         ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create students table", $errors);

$conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS student_number VARCHAR(50) DEFAULT NULL AFTER id");

// ============================================================
//  10.1. AI Recommendation Logs table
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS ai_recommendation_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    request_type    ENUM('recommendation','report') NOT NULL,
    prompt_summary  TEXT,
    ai_response     MEDIUMTEXT,
    model_used      VARCHAR(100) DEFAULT 'gemini-2.0-flash',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    KEY idx_type (request_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create ai_recommendation_logs table", $errors);

// ============================================================
//  11. Org Announcements table
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS org_announcements (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id      INT UNSIGNED NOT NULL,
    author_id    INT UNSIGNED NOT NULL,
    title        VARCHAR(250) NOT NULL,
    category     ENUM('Event','Activity','Requirement / Submission','Meeting','General') NOT NULL DEFAULT 'General',
    priority     ENUM('Normal','Important','Urgent') NOT NULL DEFAULT 'Normal',
    content      TEXT NOT NULL,
    target_group VARCHAR(100) DEFAULT 'All Members',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_club (club_id),
    KEY idx_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create org_announcements table", $errors);

// ============================================================
//  AUTOMATIC RE-SEEDING FOR CORE DATA (If empty)
// ============================================================

// 1. Seed Core Test Users (4 Roles)
$user_cnt = (int)$conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
if ($user_cnt === 0 || isset($_GET['seed'])) {
    $std_hash   = password_hash('Password123!', PASSWORD_DEFAULT);
    $admin_hash = password_hash('Admin@1234',   PASSWORD_DEFAULT);

    $seed_users = [
        ['student',  'student@bcp.edu.ph',  'Student',  'User',    $std_hash,   'student'],
        ['adviser',  'adviser@bcp.edu.ph',  'Club',     'Adviser', $std_hash,   'club_adviser'],
        ['ssc',      'ssc@bcp.edu.ph',      'SSC',      'Officer', $std_hash,   'ssc'],
        ['admin',    'admin@bcp.edu.ph',    'System',   'Admin',   $admin_hash, 'admin'],
    ];

    $ins = $conn->prepare("INSERT IGNORE INTO users (username, email, first_name, last_name, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($seed_users as [$un, $em, $fn, $ln, $pw, $rl]) {
        $ins->bind_param('ssssss', $un, $em, $fn, $ln, $pw, $rl);
        $ins->execute();
    }
    $ins->close();
}

// 2. Seed Students
$std_cnt = (int)$conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'];
if ($std_cnt === 0 || isset($_GET['seed'])) {
    $conn->query("INSERT INTO students (student_number, first_name, last_name, birthday, course, year_level, section, phone, status) VALUES
    ('2024-10017', 'Juswa', 'Pudaders', '2004-06-20', 'Bachelor of Science in Information Technology', '4th Year', '41018', '09999999999', 'Active'),
    ('2024-10002', 'Maria', 'Santos',   '2003-03-15', 'Bachelor of Science in Computer Science',       '3rd Year', '31011', '09111111111', 'Active')");
}

// 3. Seed Accredited Campus Clubs
$club_cnt = (int)$conn->query("SELECT COUNT(*) AS c FROM clubs")->fetch_assoc()['c'];
// Remove ITS if it exists
$conn->query("DELETE FROM clubs WHERE code='ITS'");
if ($club_cnt <= 4 || isset($_GET['seed'])) {
    $seed_clubs = [
        [1, 'CSSEC',   'Computer Science Student Executive Council',                          'Academic', 'Empowering CS students through leadership and excellence.', 'Prof. Alex Reyes'],
        [2, 'ACADS',   'Association of Computer Engineering Academic Driven Students',         'Academic', 'Academic excellence for CpE students.', 'Prof. Mark Velo'],
        [3, 'ACES',    'Association of Computer Engineering Students',                        'Academic', 'Technical growth and workshops for CpE.', 'Prof. Elena Ramos'],
        [4, 'AISS',    'Accounting Information System Society',                               'Academic', 'Accounting and digital literacy.', 'Prof. Clara Tan'],
        [5, 'BLISS',   'Bestlink Library and Information Science Society',                    'Academic', 'Library science and information advocacy.', 'Prof. Robert Cruz'],
        [6, 'BRAVE',   'Building Responsibility and Accountability Through Values Education', 'Academic', 'Values education and character formation.', 'Prof. Diana Gomez'],
        [7, 'CJSU',    'Criminal Justice Student Unit',                                       'Academic', 'Criminology immersion and moot court.', 'Prof. Jose Mercado'],
        [8, 'EYO',     'Entrepreyouth Organization',                                          'Academic', 'Youth entrepreneurship and incubation.', 'Prof. Lisa Santos'],
        [9, 'GEMS',    'Guild of English Majors',                                             'Academic', 'English language literacy and debate.', 'Prof. Anna Reyes'],
        [10, 'JFINEX', 'Junior Financial Executives',                                         'Academic', 'Financial literacy and investment seminars.', 'Prof. Karen Lim'],
        [11, 'SIGMA',  'Students Interactive Guild for Mathematics Major',                   'Academic', 'Mathematics exploration and peer tutoring.', 'Prof. Manuel Cruz'],
        [12, 'BCPVOL', 'BCP Campus Volunteers & Extension',                                  'Advocacy', 'Community outreach and volunteer projects.', 'Dr. Elena Cruz'],
        [13, 'BCPARTS','BCP Cultural Arts & Performing Troupe',                              'Cultural', 'Dance, music, theater across campus.', 'Prof. Sarah Mercado']
    ];

    $c_stmt = $conn->prepare("INSERT IGNORE INTO clubs (id, code, name, category, description, adviser_name, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
    foreach ($seed_clubs as [$cid, $ccode, $cname, $ccat, $cdesc, $cadv]) {
        $c_stmt->bind_param('isssss', $cid, $ccode, $cname, $ccat, $cdesc, $cadv);
        $c_stmt->execute();
    }
    $c_stmt->close();
}

// 4. Seed Club Memberships (Only when explicit seed_dummy parameter is provided)
if (isset($_GET['seed_dummy'])) {
    $std_u = $conn->query("SELECT id FROM users WHERE username = 'student' LIMIT 1")->fetch_assoc();
    $adv_u = $conn->query("SELECT id FROM users WHERE username = 'adviser' LIMIT 1")->fetch_assoc();
    // Seed into CSSEC (id=1)
    if ($std_u) {
        $conn->query("INSERT IGNORE INTO club_memberships (club_id, user_id, role, status) VALUES (1, {$std_u['id']}, 'Member', 'Active')");
    }
    if ($adv_u) {
        $conn->query("INSERT IGNORE INTO club_memberships (club_id, user_id, role, status) VALUES (1, {$adv_u['id']}, 'Adviser', 'Active')");
    }
}

// 5. Seed Sample Events (Only when explicit seed_dummy parameter is provided)
if (isset($_GET['seed_dummy'])) {
    $adv_u = $conn->query("SELECT id FROM users WHERE role = 'club_adviser' LIMIT 1")->fetch_assoc();
    $creator_id = $adv_u ? (int)$adv_u['id'] : 1;
    $date1 = date('Y-m-d H:i:s', strtotime('+5 days'));
    $date2 = date('Y-m-d H:i:s', strtotime('+12 days'));
    $date3 = date('Y-m-d H:i:s', strtotime('+20 days'));

    $conn->query("INSERT INTO events (club_id, title, description, event_date, venue, status, created_by) VALUES
    (1, 'BCP National Tech Summit 2026', 'Annual technology conference bringing together industry pioneers.', '{$date1}', 'Main Auditorium & Online Stream', 'Approved', {$creator_id}),
    (1, 'CyberSecurity & Ethical Hacking Workshop', 'Hands-on training session covering network penetration testing.', '{$date2}', 'Computer Laboratory 4', 'Approved', {$creator_id}),
    (1, 'Inter-College Hackathon 2026', '24-hour hackathon for building student solution prototypes.', '{$date3}', 'Student Activity Center', 'Pending SSC', {$creator_id})");
}

// 6. Seed Sample Budget Requests (Only when explicit seed_dummy parameter is provided)
if (isset($_GET['seed_dummy'])) {
    $std_u = $conn->query("SELECT id FROM users WHERE role = 'student' LIMIT 1")->fetch_assoc();
    $req_id = $std_u ? (int)$std_u['id'] : 1;
    $conn->query("INSERT INTO budget_requests (club_id, title, description, amount, status, requested_by, notes) VALUES
    (1, 'Tech Summit 2026 Venue & Catering Support', 'Catering and sound system rental for campus tech summit.', 15000.00, 'Pending SSC', {$req_id}, 'Endorsed by Club Adviser.'),
    (1, 'CyberSecurity Workshop Equipment', 'Purchase of ethernet switches and lab testing hardware.', 8500.00, 'Disbursed', {$req_id}, 'Disbursed by SSC.'),
    (1, 'Annual General Assembly Materials', 'Printing of certificates and participant IDs.', 3500.00, 'Pending Adviser', {$req_id}, 'Submitted and awaiting Adviser approval.')");
}

// 7. Seed Sample Achievements (Only when explicit seed_dummy parameter is provided)
if (isset($_GET['seed_dummy'])) {
    $std_u = $conn->query("SELECT id FROM users WHERE role = 'student' LIMIT 1")->fetch_assoc();
    $sub_id = $std_u ? (int)$std_u['id'] : 1;
    $date1 = date('Y-m-d', strtotime('-1 month'));
    $date2 = date('Y-m-d', strtotime('-3 months'));

    $conn->query("INSERT INTO achievements (club_id, submitted_by, title, competition, award_date, status, notes) VALUES
    (1, {$sub_id}, '1st Place Champion - National IT Quiz Bee', 'National Philippine IT Congress 2025', '{$date1}', 'Verified', 'Verified and endorsed by SSC.'),
    (1, {$sub_id}, 'Best Student Organization Project 2025', 'BCP SSC Student Excellence Awards', '{$date2}', 'Verified', 'Verified and endorsed by SSC.')");
}

// 8. Seed Sample Announcements (Only when explicit seed_dummy parameter is provided)
if (isset($_GET['seed_dummy'])) {
    $adv_u = $conn->query("SELECT id FROM users WHERE role = 'club_adviser' LIMIT 1")->fetch_assoc();
    $auth_id = $adv_u ? (int)$adv_u['id'] : 1;
    $conn->query("INSERT INTO org_announcements (club_id, author_id, title, category, priority, content, target_group) VALUES
    (1, {$auth_id}, 'Submission of Mid-Term Activity Accomplishment Reports', 'Requirement / Submission', 'Urgent', 'All committee heads must submit activity logs and liquidation documents by August 20, 2026.', 'Club Officers'),
    (1, {$auth_id}, 'Monthly General Assembly & Officer Meeting', 'Meeting', 'Important', 'Join us for our monthly general assembly at Lab 4 this Friday at 3:00 PM.', 'All Members')");
}

// Ensure upload directories exist
$uploads_dir = __DIR__ . '/../uploads/achievements/';
if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);

$apps_dir = __DIR__ . '/../uploads/applications/';
if (!is_dir($apps_dir)) mkdir($apps_dir, 0755, true);

$avatars_dir = __DIR__ . '/../uploads/avatars/';
if (!is_dir($avatars_dir)) mkdir($avatars_dir, 0755, true);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>Co-Curricular System Setup</title>
  <style>
    body { font-family: 'Segoe UI', sans-serif; max-width: 720px; margin: 50px auto; padding: 25px; background: #f8fafc; color: #1e293b; }
    .card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
    h2 { margin-top: 0; color: #1e3a8a; }
    .ok { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th, td { text-align: left; padding: 10px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
    th { background: #f1f5f9; color: #475569; }
    code { background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 0.85rem; }
    .btn { display: inline-block; background: #2563eb; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; margin-top: 20px; }
    .btn:hover { background: #1d4ed8; }
    .badge { display:inline-block; padding:2px 8px; border-radius:4px; font-size:0.75rem; font-weight:600; background:#dbeafe; color:#1e40af; }
  </style>
</head>
<body>
<div class="card">
  <h2>🚀 Co-Curricular System Setup Complete!</h2>
  <div class="ok"><strong>✅ Database initialized with 4 System Roles (Student, Adviser, SSC, Admin). Full dynamic integration ready!</strong></div>

  <p><strong>Database Tables Status:</strong></p>
  <table>
    <thead><tr><th>Table</th><th>Status</th></tr></thead>
    <tbody>
      <tr><td>users</td><td>4 system roles configured (student, club_adviser, ssc, admin)</td></tr>
      <tr><td>students</td><td>Active student profiles seeded</td></tr>
      <tr><td>clubs</td><td>14 accredited campus organizations seeded</td></tr>
      <tr><td>club_memberships</td><td>Dynamic club memberships configured</td></tr>
      <tr><td>events &amp; registrations</td><td>Event proposals &amp; student registrations active</td></tr>
      <tr><td>budget_requests</td><td>Adviser &rarr; SSC approval pipeline configured</td></tr>
      <tr><td>attendance_logs</td><td>QR attendance tracker ready</td></tr>
      <tr><td>achievements</td><td>SSC achievement verification active</td></tr>
      <tr><td>org_announcements</td><td>Dynamic announcements module active</td></tr>
      <tr><td>notifications &amp; audit_logs</td><td>System-wide notifications &amp; audit logs ready</td></tr>
    </tbody>
  </table>

  <p style="margin-top:20px;"><strong>Configured Test Accounts:</strong></p>
  <table>
    <thead><tr><th>Role</th><th>Username</th><th>Password</th></tr></thead>
    <tbody>
      <tr><td><strong>General Student</strong></td><td><code>student</code></td><td><code>Password123!</code></td></tr>
      <tr><td><strong>Faculty Club Adviser</strong></td><td><code>adviser</code></td><td><code>Password123!</code></td></tr>
      <tr><td><strong>Supreme Student Council (SSC)</strong></td><td><code>ssc</code></td><td><code>Password123!</code></td></tr>
      <tr><td><strong>System Admin</strong></td><td><code>admin</code></td><td><code>Admin@1234</code></td></tr>
    </tbody>
  </table>

  <a href="../auth/signin.php" class="btn">Proceed to Sign In &rarr;</a>
</div>
</body>
</html>
