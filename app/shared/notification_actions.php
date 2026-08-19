<?php
// ============================================================
//  NOTIFICATION_ACTIONS.PHP — shared notification helper
//  Called internally by other action handlers + directly via AJAX
// ============================================================
if (!headers_sent()) {
    header('Content-Type: application/json');
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';


function respond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

// ── Internal helper: push a notification ────────────────────
function push_notification($conn, int $user_id, string $title, string $message, string $type = 'info'): void {
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('isss', $user_id, $title, $message, $type);
    $stmt->execute();
    $stmt->close();
}

// ── Internal helper: log audit entry ────────────────────────
function log_audit($conn, int $user_id, string $action, string $table = '', int $target_id = 0, string $detail = ''): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, target_table, target_id, detail, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ississ', $user_id, $action, $table, $target_id, $detail, $ip);
    $stmt->execute();
    $stmt->close();
}

// ── Only run as AJAX if called directly ─────────────────────
if (basename($_SERVER['SCRIPT_FILENAME']) !== 'notification_actions.php') return;

if (empty($_SESSION['user_id'])) respond(false, 'Not authenticated.');

$user_id = (int)$_SESSION['user_id'];
$action  = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $limit = min((int)($_GET['limit'] ?? 20), 50);
        $stmt = $conn->prepare(
            "SELECT id, title, message, type, is_read, created_at
             FROM notifications WHERE user_id = ?
             ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->bind_param('ii', $user_id, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $unread = 0;
        $stmt2 = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt2->bind_param('i', $user_id);
        $stmt2->execute();
        $stmt2->bind_result($unread);
        $stmt2->fetch();
        $stmt2->close();

        respond(true, 'OK', ['notifications' => $rows, 'unread' => $unread]);

    case 'mark_read':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->bind_param('ii', $id, $user_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");
        }
        respond(true, 'Marked as read.');

    case 'broadcast':
        $user_role = $_SESSION['role'] ?? 'student';
        if (!in_array($user_role, ['club_adviser', 'admin', 'ssc'])) respond(false, 'Unauthorized.');

        $club_id = (int)($_POST['club_id'] ?? 0);
        $title   = trim($_POST['title']   ?? '');
        $message = trim($_POST['message'] ?? '');

        if (!$club_id || empty($title) || empty($message)) respond(false, 'Missing required fields.');

        $members = $conn->query("SELECT user_id FROM club_memberships WHERE club_id=$club_id AND status='Active'");
        $count = 0;
        if ($members) {
            while ($m = $members->fetch_assoc()) {
                push_notification($conn, (int)$m['user_id'], $title, $message, 'info');
                $count++;
            }
        }
        respond(true, "Announcement broadcasted to $count active member(s).", ['sent_count' => $count]);

    default:
        respond(false, 'Unknown action.');
}
$conn->close();
