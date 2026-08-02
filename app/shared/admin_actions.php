<?php
// ============================================================
//  ADMIN_ACTIONS.PHP — System Administration AJAX handler
//  Actions: list_users, update_role, list_logs, list_stuck, override_budget
// ============================================================
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';

if (empty($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not authenticated.']); exit; }

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';

if (!in_array($user_role, ['admin','osa_director'])) {
    echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function adRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success'=>$ok,'message'=>$msg], $extra)); exit;
}

switch ($action) {

    // ── LIST all users ────────────────────────────────────────
    case 'list_users': {
        $rows = $conn->query(
            "SELECT id, username, email, first_name, last_name, role, created_at
             FROM users ORDER BY role, last_name"
        )->fetch_all(MYSQLI_ASSOC);
        adRespond(true, 'OK', ['users' => $rows]);
    }

    // ── UPDATE user role (Admin only) ────────────────────────
    case 'update_role': {
        if ($user_role !== 'admin') adRespond(false, 'Only System Administrators can change roles.');
        $target_id  = (int)($_POST['user_id'] ?? 0);
        $new_role   = trim($_POST['new_role'] ?? '');
        $valid_roles = ['admin','student','club_adviser','osa_director','finance_officer'];
        if ($target_id <= 0 || !in_array($new_role, $valid_roles)) adRespond(false, 'Invalid user or role.');
        if ($target_id === $user_id) adRespond(false, 'You cannot change your own role.');

        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->bind_param('si', $new_role, $target_id);
        if (!$stmt->execute()) adRespond(false, 'Failed to update role.');
        $stmt->close();

        // Notify the affected user
        push_notification($conn, $target_id, 'Role Updated',
            "Your system role has been updated to: " . ucwords(str_replace('_',' ',$new_role)), 'info');
        log_audit($conn, $user_id, 'admin_role_change', 'users', $target_id,
            "Changed user #$target_id role to $new_role");

        adRespond(true, 'User role updated successfully.');
    }

    // ── LIST audit logs ───────────────────────────────────────
    case 'list_logs': {
        $limit  = min((int)($_GET['limit'] ?? 50), 200);
        $filter = trim($_GET['filter'] ?? '');
        $where  = $filter ? "WHERE al.action LIKE ?" : '';
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
        if ($filter) { $stmt->bind_param('si', $filter_val, $limit); }
        else          { $stmt->bind_param('i', $limit); }
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

        $stmt = $conn->prepare("UPDATE budget_requests SET status='Pending Finance', notes='Force-approved by System Admin (workflow override).' WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $req = $conn->query("SELECT requested_by, title FROM budget_requests WHERE id=$id")->fetch_assoc();
        if ($req) {
            push_notification($conn, (int)$req['requested_by'], 'Budget Override',
                "Your budget request \"{$req['title']}\" was force-approved by System Administrator.", 'info');
        }
        // Notify Finance
        $fins = $conn->query("SELECT id FROM users WHERE role='finance_officer'");
        while ($f = $fins->fetch_assoc()) {
            push_notification($conn, (int)$f['id'], 'Budget Override — Ready to Disburse',
                "Budget request #$id was force-approved via admin override and awaits disbursement.", 'budget');
        }
        log_audit($conn, $user_id, 'admin_budget_override', 'budget_requests', $id, "Force-approved budget #$id");
        adRespond(true, 'Budget force-approved and forwarded to Finance.');
    }

    // ── SYSTEM STATS ──────────────────────────────────────────
    case 'stats': {
        $stats = [
            'total_users'         => (int)$conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0],
            'total_clubs'         => (int)$conn->query("SELECT COUNT(*) FROM clubs WHERE status='Active'")->fetch_row()[0],
            'total_events'        => (int)$conn->query("SELECT COUNT(*) FROM events")->fetch_row()[0],
            'pending_budgets'     => (int)$conn->query("SELECT COUNT(*) FROM budget_requests WHERE status NOT IN ('Disbursed','Rejected')")->fetch_row()[0],
            'pending_achievements'=> (int)$conn->query("SELECT COUNT(*) FROM achievements WHERE status='Pending'")->fetch_row()[0],
            'total_members'       => (int)$conn->query("SELECT COUNT(*) FROM club_memberships WHERE status='Active'")->fetch_row()[0],
            'recent_logs'         => (int)$conn->query("SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch_row()[0],
        ];
        adRespond(true, 'OK', ['stats' => $stats]);
    }

    // ── DELETE user (Admin only) ──────────────────────────────
    case 'delete_user': {
        if ($user_role !== 'admin') adRespond(false, 'Only Admins can delete users.');
        $target_id = (int)($_POST['user_id'] ?? 0);
        if ($target_id <= 0 || $target_id === $user_id) adRespond(false, 'Invalid or self-delete not allowed.');
        $conn->query("DELETE FROM users WHERE id = $target_id");
        log_audit($conn, $user_id, 'admin_delete_user', 'users', $target_id, "Deleted user #$target_id");
        adRespond(true, 'User deleted.');
    }

    default:
        adRespond(false, 'Unknown action.');
}
$conn->close();
