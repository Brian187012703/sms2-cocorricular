<?php
// ============================================================
//  SETUP.PHP  (shared/)  — Co-Curricular Management System Setup
//  Visit: http://localhost/sms/app/shared/setup.php
// ============================================================
require_once __DIR__ . '/db.php';

$errors = [];

// ── Helper ───────────────────────────────────────────────────
function runSQL($conn, string $sql, string $label, array &$errors): void {
    if (!$conn->query($sql)) $errors[] = "$label: " . $conn->error;
}

// ============================================================
//  1. Users table supporting 6 Roles
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)   NOT NULL UNIQUE,
    email         VARCHAR(150)  NOT NULL UNIQUE,
    first_name    VARCHAR(100)  NOT NULL,
    last_name     VARCHAR(100)  NOT NULL,
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM('admin','student','club_adviser','osa_director','finance_officer') NOT NULL DEFAULT 'student',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create users table", $errors);

// Ensure role ENUM is up to date
$conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin','student','club_adviser','osa_director','finance_officer') NOT NULL DEFAULT 'student'");

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
    joined_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_club_user (club_id, user_id),
    KEY idx_user (user_id),
    KEY idx_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create club_memberships table", $errors);

// Add approved_by, letter_intent, letter_endorsement if missing
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
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(200) NOT NULL,
    description TEXT,
    event_date  DATETIME NOT NULL,
    venue       VARCHAR(150) NOT NULL,
    status      ENUM('Upcoming','Approved','Completed','Pending OSA','Rejected') NOT NULL DEFAULT 'Pending OSA',
    created_by  INT UNSIGNED DEFAULT NULL,
    rejection_note TEXT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create events table", $errors);

$conn->query("ALTER TABLE events ADD COLUMN IF NOT EXISTS created_by INT UNSIGNED DEFAULT NULL");
$conn->query("ALTER TABLE events ADD COLUMN IF NOT EXISTS rejection_note TEXT DEFAULT NULL");
$conn->query("ALTER TABLE events MODIFY COLUMN status ENUM('Upcoming','Approved','Completed','Pending OSA','Rejected') NOT NULL DEFAULT 'Pending OSA'");

// ============================================================
//  5. Budget Requests table
// ============================================================
runSQL($conn, "CREATE TABLE IF NOT EXISTS budget_requests (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id      INT UNSIGNED NOT NULL,
    title        VARCHAR(200) NOT NULL,
    description  TEXT DEFAULT NULL,
    amount       DECIMAL(10,2) NOT NULL,
    status       ENUM('Pending Adviser','Pending OSA','Pending Finance','Disbursed','Rejected') NOT NULL DEFAULT 'Pending Adviser',
    requested_by INT UNSIGNED NOT NULL,
    notes        TEXT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create budget_requests table", $errors);

$conn->query("ALTER TABLE budget_requests ADD COLUMN IF NOT EXISTS description TEXT DEFAULT NULL");
$conn->query("ALTER TABLE budget_requests ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL");
$conn->query("ALTER TABLE budget_requests MODIFY COLUMN IF EXISTS requested_by INT UNSIGNED NOT NULL");

// ============================================================
//  6. Attendance Logs table (NEW)
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
//  7. Achievements table (NEW)
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
//  8. Notifications table (NEW)
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
//  9. Audit Logs table (NEW)
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
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name   VARCHAR(100)  NOT NULL,
    last_name    VARCHAR(100)  NOT NULL,
    birthday     DATE          NOT NULL,
    course       VARCHAR(150)  NOT NULL,
    year_level   VARCHAR(50)   NOT NULL,
    section      VARCHAR(50)   NOT NULL,
    phone        VARCHAR(20)   NOT NULL,
    status       ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", "Create students table", $errors);

// Only seed demo records if ?seed=1 is explicitly requested
if (isset($_GET['seed']) && $_GET['seed'] === '1') {

    // ── Seed 5 test user accounts ────────────────────────────
    $std_hash   = password_hash('Password123!', PASSWORD_DEFAULT);
    $admin_hash = password_hash('Admin@1234',   PASSWORD_DEFAULT);

    $seed_users = [
        ['student',  'student@bcp.edu.ph',  'Student',  'User',    $std_hash,   'student'],
        ['adviser',  'adviser@bcp.edu.ph',  'Club',     'Adviser', $std_hash,   'club_adviser'],
        ['osa',      'osa@bcp.edu.ph',      'OSA',      'Director',$std_hash,   'osa_director'],
        ['finance',  'finance@bcp.edu.ph',  'Finance',  'Officer', $std_hash,   'finance_officer'],
        ['admin',    'admin@bcp.edu.ph',    'System',   'Admin',   $admin_hash, 'admin'],
    ];

    $ins = $conn->prepare(
        "INSERT IGNORE INTO users (username, email, first_name, last_name, password_hash, role)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($seed_users as [$un, $em, $fn, $ln, $pw, $rl]) {
        $ins->bind_param('ssssss', $un, $em, $fn, $ln, $pw, $rl);
        $ins->execute();
    }
    $ins->close();

    // Clean up any old club_officer users
    $conn->query("UPDATE users SET role='student' WHERE role='club_officer'");

    // Seed Students
    $conn->query("INSERT INTO students (first_name, last_name, birthday, course, year_level, section, phone, status) VALUES
    ('Juswa',    'Pudaders',   '2004-06-20', 'Bachelor of Science in Information Technology', '4th Year', '41018', '09999999999', 'Active'),
    ('Maria',    'Santos',     '2003-03-15', 'Bachelor of Science in Computer Science',       '3rd Year', '31011', '09111111111', 'Inactive')");

    // Seed Clubs
    $conn->query("INSERT IGNORE INTO clubs (id, code, name, category, description, adviser_name, status) VALUES
    (1, 'ITS',    'Information Technology Society',             'Academic', 'Official organization for IT students.', 'Prof. Alex Reyes', 'Active'),
    (2, 'CSSEC',  'Computer Science Student Executive Council', 'Academic', 'Empowering CS students through leadership.', 'Prof. Alex Reyes', 'Active'),
    (3, 'BCPVOL', 'BCP Campus Volunteers & Extension',          'Advocacy', 'Community outreach and volunteer projects.', 'Dr. Elena Cruz', 'Active'),
    (4, 'BCPARTS','BCP Cultural Arts & Performing Troupe',      'Cultural', 'Dance, music, theater across campus.', 'Prof. Sarah Mercado', 'Active')");

    // Seed Club Memberships
    $student_id = null;
    $res = $conn->query("SELECT id, username FROM users WHERE username = 'student'");
    while ($row = $res->fetch_assoc()) {
        $student_id = $row['id'];
    }
    if ($student_id) $conn->query("INSERT IGNORE INTO club_memberships (club_id, user_id, role, status) VALUES (1, $student_id, 'Member', 'Active')");
}

// ============================================================
//  16. Create uploads directory
// ============================================================
$uploads_dir = __DIR__ . '/../uploads/achievements/';
if (!is_dir($uploads_dir)) {
    mkdir($uploads_dir, 0755, true);
}

// Also create applications upload directory (for letter_intent & letter_endorsement)
$apps_dir = __DIR__ . '/../uploads/applications/';
if (!is_dir($apps_dir)) {
    mkdir($apps_dir, 0755, true);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>Co-Curricular System Setup</title>
  <style>
    body { font-family: 'Segoe UI', sans-serif; max-width: 700px; margin: 50px auto; padding: 25px; background: #f8fafc; color: #1e293b; }
    .card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
    h2 { margin-top: 0; color: #1e3a8a; }
    .ok { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; }
    .err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th, td { text-align: left; padding: 10px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
    th { background: #f1f5f9; color: #475569; }
    code { background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 0.85rem; }
    .btn { display: inline-block; background: #2563eb; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; margin-top: 20px; }
    .btn:hover { background: #1d4ed8; }
    .badge { display:inline-block; padding:2px 8px; border-radius:4px; font-size:0.75rem; font-weight:600; }
    .badge-new { background:#dbeafe; color:#1e40af; }
  </style>
</head>
<body>
<div class="card">
  <h2>🚀 Co-Curricular System Setup Complete!</h2>

  <?php if (empty($errors)): ?>
    <div class="ok"><strong>✅ All 10 tables created/verified. Full integration schema ready!</strong></div>

    <p><strong>Tables Initialized:</strong></p>
    <table>
      <tr><td>users</td><td>5 roles seeded</td></tr>
      <tr><td>students</td><td><span class="badge badge-new">NEW</span> Sample student roster seeded</td></tr>
      <tr><td>clubs</td><td>4 clubs seeded</td></tr>
      <tr><td>club_memberships</td><td>Student linked to ITS</td></tr>
      <tr><td>events</td><td>3 sample events seeded</td></tr>
      <tr><td>budget_requests</td><td>3 sample requests seeded</td></tr>
      <tr><td>attendance_logs</td><td><span class="badge badge-new">NEW</span> Ready</td></tr>
      <tr><td>achievements</td><td><span class="badge badge-new">NEW</span> 4 entries seeded</td></tr>
      <tr><td>notifications</td><td><span class="badge badge-new">NEW</span> Ready</td></tr>
      <tr><td>audit_logs</td><td><span class="badge badge-new">NEW</span> Ready</td></tr>
    </table>

    <p style="margin-top:20px;"><strong>Test Accounts:</strong></p>
    <table>
      <thead><tr><th>Role</th><th>Username</th><th>Password</th></tr></thead>
      <tbody>
        <tr><td><strong>General Student</strong></td><td><code>student</code></td><td><code>Password123!</code></td></tr>
        <tr><td><strong>Club Adviser</strong></td><td><code>adviser</code></td><td><code>Password123!</code></td></tr>
        <tr><td><strong>OSA Director</strong></td><td><code>osa</code></td><td><code>Password123!</code></td></tr>
        <tr><td><strong>Finance Officer</strong></td><td><code>finance</code></td><td><code>Password123!</code></td></tr>
        <tr><td><strong>System Admin</strong></td><td><code>admin</code></td><td><code>Admin@1234</code></td></tr>
      </tbody>
    </table>

    <a href="../auth/signin.php" class="btn">Proceed to Sign In →</a>
  <?php else: ?>
    <div class="err"><strong>Errors occurred:</strong><br><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
  <?php endif; ?>
</div>
</body>
</html>
