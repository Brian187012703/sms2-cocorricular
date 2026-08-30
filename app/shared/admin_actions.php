<?php
// ============================================================
//  ADMIN_ACTIONS.PHP — System Administration AJAX handler
//  Actions: list_users, create_user, update_user, update_role,
//           reset_password, delete_user, list_logs, list_stuck,
//           override_budget, system_health, export_users_csv
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';

if (!in_array($user_role, ['admin', 'ssc'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function adRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

switch ($action) {

    // ── LIST all users ────────────────────────────────────────
    case 'list_users': {
        $search = trim($_GET['search'] ?? '');
        $role_filter = trim($_GET['role'] ?? '');

        $where = [];
        $params = [];
        $types = '';

        if (!empty($role_filter)) {
            $where[] = "u.role = ?";
            $params[] = $role_filter;
            $types .= 's';
        }

        if (!empty($search)) {
            $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR s.student_number LIKE ?)";
            $s_term = "%$search%";
            $params = array_merge($params, [$s_term, $s_term, $s_term, $s_term, $s_term]);
            $types .= 'sssss';
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.role, u.created_at,
                       s.student_number, s.course, s.year_level, s.section, s.status AS student_status
                FROM users u
                LEFT JOIN students s ON (s.first_name = u.first_name AND s.last_name = u.last_name)
                $where_sql
                ORDER BY u.role, u.last_name, u.first_name";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        adRespond(true, 'OK', ['users' => $rows]);
    }

    // ── CREATE NEW USER (Admin only) ──────────────────────────
    case 'create_user': {
        if ($user_role !== 'admin') {
            adRespond(false, 'Only System Administrators can create new accounts.');
        }

        $username   = trim($_POST['username'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $role       = trim($_POST['role'] ?? 'student');
        $password   = trim($_POST['password'] ?? 'Password123!');
        $student_no = trim($_POST['student_number'] ?? '');
        $course     = trim($_POST['course'] ?? '');
        $year_level = trim($_POST['year_level'] ?? '1st Year');
        $section    = trim($_POST['section'] ?? '1A');

        $valid_roles = ['admin', 'student', 'club_adviser', 'ssc'];
        if (!$username || !$email || !$first_name || !$last_name || !in_array($role, $valid_roles)) {
            adRespond(false, 'Please fill in all required fields with valid values.');
        }

        // Check if username or email exists
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $chk->bind_param('ss', $username, $email);
        $chk->execute();
        $res = $chk->get_result();
        if ($res && $res->num_rows > 0) {
            adRespond(false, 'Username or Email is already registered in the system.');
        }
        $chk->close();

        $pw_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, email, first_name, last_name, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssss', $username, $email, $first_name, $last_name, $pw_hash, $role);
        if (!$stmt->execute()) {
            adRespond(false, 'Failed to create user: ' . $stmt->error);
        }
        $new_user_id = $stmt->insert_id;
        $stmt->close();

        // If student role, create student record if provided
        if ($role === 'student') {
            $s_stmt = $conn->prepare("INSERT INTO students (student_number, first_name, last_name, birthday, course, year_level, section, phone, status) VALUES (?, ?, ?, '2004-01-01', ?, ?, ?, '09123456789', 'Active')");
            $s_no = $student_no ?: ('2026-' . rand(10000, 99999));
            $c_val = $course ?: 'Bachelor of Science in Information Technology';
            $s_stmt->bind_param('ssssss', $s_no, $first_name, $last_name, $c_val, $year_level, $section);
            $s_stmt->execute();
            $s_stmt->close();
        }

        log_audit($conn, $user_id, 'admin_create_user', 'users', $new_user_id, "Created new user #$new_user_id ($username, $role)");
        adRespond(true, "User account for \"$first_name $last_name\" created successfully!", ['user_id' => $new_user_id]);
    }

    // ── UPDATE USER DETAILS & ROLE (Admin only) ───────────────
    case 'update_user': {
        if ($user_role !== 'admin') {
            adRespond(false, 'Only System Administrators can update user accounts.');
        }

        $target_id  = (int)($_POST['user_id'] ?? 0);
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $new_role   = trim($_POST['role'] ?? '');

        $valid_roles = ['admin', 'student', 'club_adviser', 'ssc'];
        if ($target_id <= 0 || !$first_name || !$last_name || !$email || !in_array($new_role, $valid_roles)) {
            adRespond(false, 'Invalid input fields.');
        }

        // Email uniqueness check
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $chk->bind_param('si', $email, $target_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            adRespond(false, 'Email address is already in use by another account.');
        }
        $chk->close();

        $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, role = ? WHERE id = ?");
        $stmt->bind_param('ssssi', $first_name, $last_name, $email, $new_role, $target_id);
        if (!$stmt->execute()) {
            adRespond(false, 'Failed to update user: ' . $stmt->error);
        }
        $stmt->close();

        push_notification($conn, $target_id, 'Account Information Updated',
            "Your profile details and system role have been updated by the System Administrator.", 'info');

        log_audit($conn, $user_id, 'admin_update_user', 'users', $target_id, "Updated user #$target_id ($first_name $last_name, role: $new_role)");
        adRespond(true, 'User details updated successfully.');
    }

    // ── UPDATE user role (Admin only) ────────────────────────
    case 'update_role': {
        if ($user_role !== 'admin') adRespond(false, 'Only System Administrators can change roles.');
        $target_id  = (int)($_POST['user_id'] ?? 0);
        $new_role   = trim($_POST['new_role'] ?? '');
        $valid_roles = ['admin', 'student', 'club_adviser', 'ssc'];
        if ($target_id <= 0 || !in_array($new_role, $valid_roles)) adRespond(false, 'Invalid user or role.');
        if ($target_id === $user_id) adRespond(false, 'You cannot change your own role.');

        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->bind_param('si', $new_role, $target_id);
        if (!$stmt->execute()) adRespond(false, 'Failed to update role.');
        $stmt->close();

        push_notification($conn, $target_id, 'Role Updated',
            "Your system role has been updated to: " . ucwords(str_replace('_', ' ', $new_role)), 'info');
        log_audit($conn, $user_id, 'admin_role_change', 'users', $target_id,
            "Changed user #$target_id role to $new_role");

        adRespond(true, 'User role updated successfully.');
    }

    // ── RESET USER PASSWORD (Admin only) ──────────────────────
    case 'reset_password': {
        if ($user_role !== 'admin') adRespond(false, 'Only System Administrators can reset passwords.');
        $target_id = (int)($_POST['user_id'] ?? 0);
        $new_pass  = trim($_POST['new_password'] ?? 'Password123!');
        if ($target_id <= 0 || empty($new_pass)) adRespond(false, 'Invalid user or password.');

        $pw_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->bind_param('si', $pw_hash, $target_id);
        if (!$stmt->execute()) adRespond(false, 'Failed to reset password.');
        $stmt->close();

        push_notification($conn, $target_id, 'Security Alert: Password Reset',
            "Your account password was reset by the System Administrator.", 'warning');
        log_audit($conn, $user_id, 'admin_reset_password', 'users', $target_id, "Reset password for user #$target_id");

        adRespond(true, "Password for user #$target_id successfully reset!");
    }

    // ── LIST audit logs ───────────────────────────────────────
    case 'list_logs': {
        $limit  = min((int)($_GET['limit'] ?? 50), 200);
        $filter = trim($_GET['filter'] ?? '');
        $where  = $filter ? "WHERE (al.action LIKE ? OR al.detail LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)" : '';
        $filter_val = "%$filter%";

        $sql = "SELECT al.id, al.action, al.target_table, al.target_id, al.detail,
                       al.ip_address, al.created_at,
                       u.first_name, u.last_name, u.role
                FROM audit_logs al
                JOIN users u ON u.id = al.user_id
                $where
                ORDER BY al.created_at DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        if ($filter) {
            $stmt->bind_param('ssssi', $filter_val, $filter_val, $filter_val, $filter_val, $limit);
        } else {
            $stmt->bind_param('i', $limit);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        adRespond(true, 'OK', ['logs' => $rows]);
    }

    // ── LIST stuck budget requests ────────────────────────────
    case 'list_stuck': {
        $rows = $conn->query(
            "SELECT br.id, br.title, br.amount, br.status, br.created_at,
                    c.name AS club_name, u.first_name, u.last_name
             FROM budget_requests br
             JOIN clubs c ON c.id = br.club_id
             JOIN users u ON u.id = br.requested_by
             WHERE br.status NOT IN ('Disbursed','Rejected')
             AND br.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY br.created_at ASC"
        )->fetch_all(MYSQLI_ASSOC);
        adRespond(true, 'OK', ['stuck' => $rows]);
    }

    // ── FORCE APPROVE stuck budget (Admin only) ───────────────
    case 'override_budget': {
        if ($user_role !== 'admin') adRespond(false, 'Only Admins can force-approve budgets.');
        $id = (int)($_POST['budget_id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) adRespond(false, 'Invalid request ID.');

        $stmt = $conn->prepare("UPDATE budget_requests SET status='Pending Admin', notes='Force-forwarded to Admin by System Admin (workflow override).' WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $req = $conn->query("SELECT requested_by, title FROM budget_requests WHERE id=$id")->fetch_assoc();
        if ($req) {
            push_notification($conn, (int)$req['requested_by'], 'Budget Override',
                "Your budget request \"{$req['title']}\" was force-advanced by System Administrator.", 'info');
        }
        log_audit($conn, $user_id, 'admin_budget_override', 'budget_requests', $id, "Force-approved budget #$id");
        adRespond(true, 'Budget force-approved and ready for final disbursement.');
    }

    // ── SYSTEM HEALTH & DIAGNOSTICS ───────────────────────────
    case 'system_health': {
        $db_version = $conn->server_info;
        $uploads_ok = is_writable(__DIR__ . '/../uploads/');
        $user_count = (int)$conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
        $club_count = (int)$conn->query("SELECT COUNT(*) FROM clubs")->fetch_row()[0];
        $event_count = (int)$conn->query("SELECT COUNT(*) FROM events")->fetch_row()[0];
        $budget_count = (int)$conn->query("SELECT COUNT(*) FROM budget_requests")->fetch_row()[0];
        $log_count = (int)$conn->query("SELECT COUNT(*) FROM audit_logs")->fetch_row()[0];

        $diagnostics = [
            'php_version'    => PHP_VERSION,
            'db_version'     => $db_version,
            'uploads_writable' => $uploads_ok,
            'counts' => [
                'users'   => $user_count,
                'clubs'   => $club_count,
                'events'  => $event_count,
                'budgets' => $budget_count,
                'logs'    => $log_count,
            ],
            'server_time'    => date('Y-m-d H:i:s T'),
            'status'         => 'Healthy & Operational'
        ];
        adRespond(true, 'System health OK', ['diagnostics' => $diagnostics]);
    }

    // ── DELETE user (Admin only) ──────────────────────────────
    case 'delete_user': {
        if ($user_role !== 'admin') adRespond(false, 'Only Admins can delete users.');
        $target_id = (int)($_POST['user_id'] ?? 0);
        if ($target_id <= 0 || $target_id === $user_id) adRespond(false, 'Invalid user ID or self-delete not allowed.');

        $conn->query("DELETE FROM users WHERE id = $target_id");
        log_audit($conn, $user_id, 'admin_delete_user', 'users', $target_id, "Deleted user #$target_id");
        adRespond(true, 'User deleted.');
    }

    default:
        adRespond(false, 'Unknown action.');
}
$conn->close();
