<?php
// ============================================================
//  ATTENDANCE_ACTIONS.PHP — Attendance AJAX handler
//  Actions: list_mine, list_event, log_qr, log_manual, analytics
// ============================================================
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';

if (empty($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not authenticated.']); exit; }

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

function aRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success'=>$ok,'message'=>$msg], $extra)); exit;
}

switch ($action) {

    // ── LIST my attendance history ────────────────────────────
    case 'list_mine': {
        $stmt = $conn->prepare(
            "SELECT al.id, al.check_in, al.method,
                    e.title AS event_name, e.event_date, e.venue
             FROM attendance_logs al
             JOIN events e ON e.id = al.event_id
             WHERE al.user_id = ?
             ORDER BY al.check_in DESC"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        aRespond(true, 'OK', ['logs' => $rows]);
    }

    // ── LIST all attendees for an event (Adviser / above) ────
    case 'list_event': {
        if (!in_array($user_role, ['club_adviser','osa_director','admin']))
            aRespond(false, 'Not authorized.');
        $event_id = (int)($_GET['event_id'] ?? 0);
        if ($event_id <= 0) aRespond(false, 'Invalid event ID.');

        $stmt = $conn->prepare(
            "SELECT al.id, al.check_in, al.method,
                    u.first_name, u.last_name, u.email
             FROM attendance_logs al
             JOIN users u ON u.id = al.user_id
             WHERE al.event_id = ?
             ORDER BY al.check_in ASC"
        );
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        aRespond(true, 'OK', ['attendees' => $rows]);
    }

    // ── LOG via QR scan (Adviser / OSA / Admin) ──────────────
    case 'log_qr': {
        if (!in_array($user_role, ['club_adviser','osa_director','admin']))
            aRespond(false, 'Scanner access not permitted for your role.');

        $qr_data  = trim($_POST['qr_data']  ?? '');
        $event_id = (int)($_POST['event_id'] ?? 0);

        if (!$qr_data || $event_id <= 0) aRespond(false, 'QR data and event ID are required.');

        // Parse BCP-STUDENT-{id} format
        if (preg_match('/BCP-STUDENT-(\d+)/', $qr_data, $m)) {
            $target_user_id = (int)$m[1];
        } else {
            aRespond(false, 'Unrecognized QR format. Expected: BCP-STUDENT-{id}');
        }

        // Verify user exists
        $u = $conn->query("SELECT first_name, last_name FROM users WHERE id = $target_user_id")->fetch_assoc();
        if (!$u) aRespond(false, 'Student not found in system.');

        // Check for duplicate
        $dup = $conn->query("SELECT id FROM attendance_logs WHERE event_id=$event_id AND user_id=$target_user_id");
        if ($dup && $dup->num_rows > 0)
            aRespond(false, "{$u['first_name']} {$u['last_name']} is already checked in.", ['already_logged' => true]);

        $method = 'QR';
        $stmt = $conn->prepare(
            "INSERT INTO attendance_logs (event_id, user_id, method, logged_by) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param('iisi', $event_id, $target_user_id, $method, $user_id);
        if (!$stmt->execute()) aRespond(false, 'Failed to log attendance: ' . $stmt->error);
        $stmt->close();

        // Notify the student
        $ev = $conn->query("SELECT title FROM events WHERE id = $event_id")->fetch_assoc();
        if ($ev) {
            push_notification($conn, $target_user_id, 'Attendance Logged',
                "Your attendance for \"{$ev['title']}\" was recorded via QR scan.", 'info');
        }
        log_audit($conn, $user_id, 'attendance_qr', 'attendance_logs', $target_user_id,
            "Checked in user #$target_user_id for event #$event_id via QR");

        aRespond(true, "{$u['first_name']} {$u['last_name']} checked in successfully!", [
            'student_name' => $u['first_name'] . ' ' . $u['last_name']
        ]);
    }

    // ── LOG manual override (OSA / Admin) ────────────────────
    case 'log_manual': {
        if (!in_array($user_role, ['osa_director','admin']))
            aRespond(false, 'Only OSA Directors and Admins can do manual overrides.');

        $target_user_id = (int)($_POST['user_id']   ?? 0);
        $event_id       = (int)($_POST['event_id']  ?? 0);
        $check_in       = trim($_POST['check_in']   ?? date('Y-m-d H:i:s'));

        if ($target_user_id <= 0 || $event_id <= 0) aRespond(false, 'User and event are required.');

        $method = 'Manual';
        // Remove existing log first if override
        $conn->query("DELETE FROM attendance_logs WHERE event_id=$event_id AND user_id=$target_user_id");

        $stmt = $conn->prepare(
            "INSERT INTO attendance_logs (event_id, user_id, check_in, method, logged_by) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iissi', $event_id, $target_user_id, $check_in, $method, $user_id);
        if (!$stmt->execute()) aRespond(false, 'Failed to log attendance.');
        $stmt->close();

        log_audit($conn, $user_id, 'attendance_manual_override', 'attendance_logs', $target_user_id,
            "Manual override for user #$target_user_id event #$event_id");
        aRespond(true, 'Manual attendance logged successfully.');
    }

    // ── ANALYTICS summary ────────────────────────────────────
    case 'analytics': {
        if (!in_array($user_role, ['osa_director','admin','club_adviser']))
            aRespond(false, 'Not authorized.');

        $total_events  = (int)$conn->query("SELECT COUNT(*) FROM events WHERE status IN ('Approved','Completed')")->fetch_row()[0];
        $total_logs    = (int)$conn->query("SELECT COUNT(*) FROM attendance_logs")->fetch_row()[0];
        $unique_users  = (int)$conn->query("SELECT COUNT(DISTINCT user_id) FROM attendance_logs")->fetch_row()[0];
        $avg_per_event = $total_events > 0 ? round($total_logs / $total_events, 1) : 0;

        // Per-event breakdown
        $breakdown = $conn->query(
            "SELECT e.title, e.event_date,
                    COUNT(al.id) AS attendee_count
             FROM events e
             LEFT JOIN attendance_logs al ON al.event_id = e.id
             WHERE e.status IN ('Approved','Completed')
             GROUP BY e.id
             ORDER BY e.event_date DESC
             LIMIT 10"
        )->fetch_all(MYSQLI_ASSOC);

        aRespond(true, 'OK', [
            'total_events'  => $total_events,
            'total_logs'    => $total_logs,
            'unique_users'  => $unique_users,
            'avg_per_event' => $avg_per_event,
            'breakdown'     => $breakdown,
        ]);
    }

    default:
        aRespond(false, 'Unknown action.');
}
$conn->close();
