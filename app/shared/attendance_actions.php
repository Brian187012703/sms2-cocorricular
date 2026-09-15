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
        $event_id = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
        if ($event_id <= 0) aRespond(false, 'Invalid event ID.');

        $stmt = $conn->prepare(
            "SELECT al.id, al.user_id, al.check_in, al.method,
                    u.first_name, u.last_name, u.email,
                    s.student_number
             FROM attendance_logs al
             JOIN users u ON u.id = al.user_id
             LEFT JOIN students s ON s.id = (
                 SELECT s2.id FROM students s2 
                 WHERE s2.user_id = u.id OR (s2.first_name = u.first_name AND s2.last_name = u.last_name) 
                 LIMIT 1
             )
             WHERE al.event_id = ?
             ORDER BY al.check_in DESC"
        );
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        aRespond(true, 'OK', ['attendees' => $rows, 'count' => count($rows)]);
    }
    // -- LOG via QR scan -------------------------------------------------------
    // Mode A: User (Student/Staff) scans Event Poster QR (BCP-EVENT-{id} or BCP-EVENT-LOG-{id}) -> Self Check-in
    // Mode B: Staff/Scanner terminal scans User QR Badge (BCP-STUDENT-{id} or BCP-STAFF-{id}) for an Event -> Staff Check-in
    case 'log_qr': {
        $qr_data  = trim($_POST['qr_data']  ?? '');
        $event_id = (int)($_POST['event_id'] ?? 0);
        if (!$qr_data) aRespond(false, 'QR data is required.');

        // Extract code if wrapped in URL
        if (preg_match('/(BCP-EVENT(?:-LOG)?-\d+)/i', $qr_data, $url_m)) {
            $qr_data = $url_m[1];
        } elseif (preg_match('/(BCP-(?:STUDENT|STAFF|USER)-\d+)/i', $qr_data, $url_m)) {
            $qr_data = $url_m[1];
        }

        // ── MODE A: Scanned an EVENT QR Code (Self Check-in) ────────
        if (preg_match('/BCP-EVENT(?:-LOG)?-(\d+)/i', $qr_data, $m)) {
            $target_event_id = (int)$m[1];
            $ev = $conn->query("SELECT id, title FROM events WHERE id = $target_event_id")->fetch_assoc();
            if (!$ev) aRespond(false, 'Event QR is invalid or the event does not exist.');

            $dup = $conn->query("SELECT id FROM attendance_logs WHERE event_id = $target_event_id AND user_id = $user_id");
            if ($dup && $dup->num_rows > 0) {
                aRespond(false, "You are already checked in to \"{$ev['title']}\".", ['already_logged' => true]);
            }

            $method = 'QR';
            $stmt = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, method, logged_by) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('iisi', $target_event_id, $user_id, $method, $user_id);
            if (!$stmt->execute()) aRespond(false, 'Failed to record attendance: ' . $stmt->error);
            $stmt->close();

            log_audit($conn, $user_id, 'attendance_self_qr', 'attendance_logs', $target_event_id, "Self checked-in to \"{$ev['title']}\" via Event QR");
            aRespond(true, "Checked in to \"{$ev['title']}\" successfully!", ['event_title' => $ev['title']]);
        }

        // ── MODE B: Scanned a STUDENT / USER QR Badge ────────────────
        if (preg_match('/BCP-(?:STUDENT|STAFF|USER)-(\d+)/i', $qr_data, $m)) {
            $target_user_id = (int)$m[1];

            // If a student scans another student's badge
            if ($user_role === 'student') {
                aRespond(false, 'You scanned a student badge. To self-check in, please scan the Event QR code poster.');
            }

            // Staff scanner requires an event to be selected
            if ($event_id <= 0) {
                aRespond(false, 'Please select an event from the dropdown before scanning student badges.');
            }

            $u = $conn->query("SELECT id, first_name, last_name FROM users WHERE id = $target_user_id")->fetch_assoc();
            if (!$u) aRespond(false, 'Student not found in system database.');

            $dup = $conn->query("SELECT id FROM attendance_logs WHERE event_id = $event_id AND user_id = $target_user_id");
            if ($dup && $dup->num_rows > 0) {
                aRespond(false, "{$u['first_name']} {$u['last_name']} is already checked in.", ['already_logged' => true]);
            }

            $method = 'QR';
            $stmt = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, method, logged_by) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('iisi', $event_id, $target_user_id, $method, $user_id);
            if (!$stmt->execute()) aRespond(false, 'Failed to log attendance: ' . $stmt->error);
            $stmt->close();

            $ev = $conn->query("SELECT title FROM events WHERE id = $event_id")->fetch_assoc();
            if ($ev) {
                push_notification($conn, $target_user_id, 'Attendance Logged', "Your attendance for \"{$ev['title']}\" was recorded via QR scan.", 'info');
            }
            log_audit($conn, $user_id, 'attendance_qr', 'attendance_logs', $target_user_id, "Checked in user #$target_user_id for event #$event_id via QR");
            aRespond(true, "{$u['first_name']} {$u['last_name']} checked in successfully!", ['student_name' => $u['first_name'] . ' ' . $u['last_name']]);
        }

        // ── MODE C: Support Student Number (e.g. 2024-10001) or Username ────────
        if (in_array($user_role, ['club_adviser','ssc','admin']) && $event_id > 0) {
            // Check students table by student_number
            $stu_stmt = $conn->prepare("SELECT u.id, u.first_name, u.last_name FROM students s JOIN users u ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name)) WHERE s.student_number = ? LIMIT 1");
            if ($stu_stmt) {
                $stu_stmt->bind_param('s', $qr_data);
                $stu_stmt->execute();
                $sres = $stu_stmt->get_result()->fetch_assoc();
                $stu_stmt->close();
                if ($sres) {
                    $target_user_id = (int)$sres['id'];
                    $dup = $conn->query("SELECT id FROM attendance_logs WHERE event_id = $event_id AND user_id = $target_user_id");
                    if ($dup && $dup->num_rows > 0) {
                        aRespond(false, "{$sres['first_name']} {$sres['last_name']} is already checked in.", ['already_logged' => true]);
                    }
                    $method = 'QR';
                    $stmt = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, method, logged_by) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param('iisi', $event_id, $target_user_id, $method, $user_id);
                    $stmt->execute();
                    $stmt->close();
                    aRespond(true, "{$sres['first_name']} {$sres['last_name']} checked in successfully!", ['student_name' => $sres['first_name'] . ' ' . $sres['last_name']]);
                }
            }

            // Check users by username
            $u_stmt = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE username = ? LIMIT 1");
            if ($u_stmt) {
                $u_stmt->bind_param('s', $qr_data);
                $u_stmt->execute();
                $ures = $u_stmt->get_result()->fetch_assoc();
                $u_stmt->close();
                if ($ures) {
                    $target_user_id = (int)$ures['id'];
                    $dup = $conn->query("SELECT id FROM attendance_logs WHERE event_id = $event_id AND user_id = $target_user_id");
                    if ($dup && $dup->num_rows > 0) {
                        aRespond(false, "{$ures['first_name']} {$ures['last_name']} is already checked in.", ['already_logged' => true]);
                    }
                    $method = 'QR';
                    $stmt = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, method, logged_by) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param('iisi', $event_id, $target_user_id, $method, $user_id);
                    $stmt->execute();
                    $stmt->close();
                    aRespond(true, "{$ures['first_name']} {$ures['last_name']} checked in successfully!", ['student_name' => $ures['first_name'] . ' ' . $ures['last_name']]);
                }
            }
        }

        // Fallback for numeric IDs if staff scanner has an event selected
        if (is_numeric($qr_data) && in_array($user_role, ['club_adviser','ssc','admin']) && $event_id > 0) {
            $target_user_id = (int)$qr_data;
            $u = $conn->query("SELECT id, first_name, last_name FROM users WHERE id = $target_user_id")->fetch_assoc();
            if ($u) {
                $dup = $conn->query("SELECT id FROM attendance_logs WHERE event_id = $event_id AND user_id = $target_user_id");
                if ($dup && $dup->num_rows > 0) {
                    aRespond(false, "{$u['first_name']} {$u['last_name']} is already checked in.", ['already_logged' => true]);
                }
                $method = 'QR';
                $stmt = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, method, logged_by) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('iisi', $event_id, $target_user_id, $method, $user_id);
                $stmt->execute();
                $stmt->close();
                aRespond(true, "{$u['first_name']} {$u['last_name']} checked in successfully!");
            }
        }

        aRespond(false, "Unrecognized QR code format (\"$qr_data\"). Expected an Event Poster QR (BCP-EVENT-*) or Student Badge (BCP-STUDENT-*).");
    }

    // ── LOG manual override (SSC / Admin) ────────────────────
    case 'log_manual': {
        if (!in_array($user_role, ['ssc','admin']))
            aRespond(false, 'Only SSC Officers and Admins can do manual overrides.');

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
