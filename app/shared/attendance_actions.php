<?php
// ============================================================
//  ATTENDANCE_ACTIONS.PHP — Attendance AJAX handler
//  Actions: list_mine, list_event, log_qr, log_manual, analytics
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

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

    // ── LIST all attendees for an event (Adviser / above) ────
    case 'list_event': {
        if (!in_array($user_role, ['club_adviser','ssc','admin']))
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
    // -- LOG via QR scan -------------------------------------------------------
    // Student: scan event QR (BCP-EVENT-{id}) to self-register attendance
    // Staff:   scan student QR (BCP-STUDENT-{id}) to log attendance
    case 'log_qr': {
        $qr_data  = trim($_POST['qr_data']  ?? '');
        $event_id = (int)($_POST['event_id'] ?? 0);
        if (!$qr_data) aRespond(false, 'QR data is required.');

        // Student scanning an event QR code (BCP-EVENT-{id})
        if ($user_role === 'student' && preg_match('/BCP-EVENT-(\d+)/', $qr_data, $m)) {
            $target_event_id = (int)$m[1];
            $ev = $conn->query("SELECT id, title FROM events WHERE id=$target_event_id AND status IN ('Approved','Upcoming')")->fetch_assoc();
            if (!$ev) aRespond(false, 'Event QR is invalid or the event is not active.');
            $dup = $conn->query("SELECT id FROM attendance_logs WHERE event_id=$target_event_id AND user_id=$user_id");
            if ($dup && $dup->num_rows > 0)
                aRespond(false, "You are already checked in to \"{$ev['title']}\".", ['already_logged' => true]);
            $method = 'QR_SELF';
            $stmt = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, method, logged_by) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('iisi', $target_event_id, $user_id, $method, $user_id);
            if (!$stmt->execute()) aRespond(false, 'Failed to register: ' . $stmt->error);
            $stmt->close();
            aRespond(true, "Checked in to \"{$ev['title']}\" successfully!");
        }

        // Staff scanning a student QR code (BCP-STUDENT-{id})
        if (!in_array($user_role, ['club_adviser','ssc','admin']))
            aRespond(false, 'Scanner access not permitted for your role.');
        if ($event_id <= 0) aRespond(false, 'Please select an event first.');

        if (preg_match('/BCP-STUDENT-(\d+)/', $qr_data, $m)) {
            $target_user_id = (int)$m[1];
        } else { aRespond(false, 'Unrecognized QR format. Expected: BCP-STUDENT-{id}'); }

        $u = $conn->query("SELECT first_name, last_name FROM users WHERE id = $target_user_id")->fetch_assoc();
        if (!$u) aRespond(false, 'Student not found in system.');
        $dup = $conn->query("SELECT id FROM attendance_logs WHERE event_id=$event_id AND user_id=$target_user_id");
        if ($dup && $dup->num_rows > 0)
            aRespond(false, "{$u['first_name']} {$u['last_name']} is already checked in.", ['already_logged' => true]);

        $method = 'QR';
        $stmt = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, method, logged_by) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iisi', $event_id, $target_user_id, $method, $user_id);
        if (!$stmt->execute()) aRespond(false, 'Failed to log attendance: ' . $stmt->error);
        $stmt->close();
        $ev = $conn->query("SELECT title FROM events WHERE id = $event_id")->fetch_assoc();
        if ($ev) push_notification($conn, $target_user_id, 'Attendance Logged', "Your attendance for \"{$ev['title']}\" was recorded via QR scan.", 'info');
        log_audit($conn, $user_id, 'attendance_qr', 'attendance_logs', $target_user_id, "Checked in user #$target_user_id for event #$event_id via QR");
        aRespond(true, "{$u['first_name']} {$u['last_name']} checked in successfully!", ['student_name' => $u['first_name'] . ' ' . $u['last_name']]);
    }

    // ── LOG manual override (OSA / Admin) ────────────────────
    case 'log_manual': {
        if (!in_array($user_role, ['ssc','admin']))
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
        if (!in_array($user_role, ['ssc','admin','club_adviser']))
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
