<?php
// ============================================================
//  ATTENDANCE_ACTIONS.PHP — Attendance AJAX handler
//  Actions: list_mine, list_event, log_qr, log_manual, analytics
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';
require_once __DIR__ . '/security.php';

if (empty($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not authenticated.']); exit; }

// CSRF check on mutating requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

function aRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success'=>$ok,'message'=>$msg], $extra)); exit;
}

// ── HELPER: Resolve User from any QR / Student Number / ID ──
// Supports: BCP-STUDENT-2024-10001, BCP-STUDENT-1, BCP-STAFF-1, BCP-USER-1, 2024-10001, username, etc.
function resolveUserFromQr(mysqli $conn, string $code): ?array {
    $code = trim($code);
    if (!$code) return null;

    // Check if wrapped in URL
    if (preg_match('/(BCP-(?:STUDENT|STAFF|USER)-[A-Za-z0-9\-_\.]+)/i', $code, $m)) {
        $code = $m[1];
    }

    // Clean identifier (strip prefix if present)
    $cleanId = preg_replace('/^BCP-(?:STUDENT|STAFF|USER)-/i', '', $code);

    // 1. Match students table by student_number (e.g. '2024-10001')
    $stmt = $conn->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.username,
                                   s.student_number, s.course, s.year_level, s.section
                            FROM students s
                            JOIN users u ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
                            WHERE s.student_number = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $cleanId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return $row;
    }

    // Also check with original code in case prefix wasn't stripped
    if ($cleanId !== $code) {
        $stmt = $conn->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.username,
                                       s.student_number, s.course, s.year_level, s.section
                                FROM students s
                                JOIN users u ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
                                WHERE s.student_number = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) return $row;
        }
    }

    // 2. If numeric, check users.id
    if (is_numeric($cleanId)) {
        $uid = (int)$cleanId;
        $stmt = $conn->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.username,
                                       s.student_number, s.course, s.year_level, s.section
                                FROM users u
                                LEFT JOIN students s ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
                                WHERE u.id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) return $row;
        }
    }

    // 3. Match users table by username (e.g. 'bsit.student', 'admin', 'cssec.adviser')
    $stmt = $conn->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.username,
                                   s.student_number, s.course, s.year_level, s.section
                            FROM users u
                            LEFT JOIN students s ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
                            WHERE u.username = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $cleanId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return $row;
    }

    return null;
}

// ── HELPER: Resolve Event from any Event QR payload ───────
function resolveEventFromQr(mysqli $conn, string $code): ?array {
    $code = trim($code);
    if (!$code) return null;

    $targetEventId = 0;
    if (preg_match('/BCP-EVENT(?:-LOG)?-(\d+)/i', $code, $m)) {
        $targetEventId = (int)$m[1];
    } elseif (preg_match('/EVENT-(\d+)/i', $code, $m)) {
        $targetEventId = (int)$m[1];
    }

    if ($targetEventId <= 0) return null;

    $stmt = $conn->prepare("SELECT e.id, e.title, e.event_date, e.venue, e.status, c.name as club_name, c.code as club_code
                            FROM events e
                            LEFT JOIN clubs c ON c.id = e.club_id
                            WHERE e.id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $targetEventId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
    return null;
}

if (!empty($action)) {
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
    // Mode A: Student/Staff scans Event Poster QR (BCP-EVENT-{id}) -> Self Check-in
    // Mode B: Staff/Scanner terminal scans User QR Badge (BCP-STUDENT-{num/id} or BCP-STAFF-{id}) for an Event -> Staff Check-in
    case 'log_qr': {
        $qr_data  = trim($_POST['qr_data']  ?? '');
        $event_id = (int)($_POST['event_id'] ?? 0);
        if (!$qr_data) aRespond(false, 'QR data is required.');

        // ── 1. CHECK IF SCANNED DATA IS AN EVENT QR CODE ──────────────
        $scannedEvent = resolveEventFromQr($conn, $qr_data);
        if ($scannedEvent) {
            $target_event_id = (int)$scannedEvent['id'];
            $evTitle = $scannedEvent['title'];

            // Mode A-1: If a student (or user self-checking in) scans the event poster
            if ($user_role === 'student' || empty($event_id) || $event_id === $target_event_id) {
                // Check if already checked in
                $dup = $conn->query("SELECT id, check_in FROM attendance_logs WHERE event_id = $target_event_id AND user_id = $user_id LIMIT 1");
                if ($dup && $dup->num_rows > 0) {
                    $dupRow = $dup->fetch_assoc();
                    $timeStr = date('h:i A', strtotime($dupRow['check_in']));
                    aRespond(false, "You are already checked in to \"{$evTitle}\" (at {$timeStr}).", [
                        'already_logged' => true,
                        'event_title'    => $evTitle
                    ]);
                }

                $method = 'QR';
                $stmt = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, method, logged_by) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('iisi', $target_event_id, $user_id, $method, $user_id);
                if (!$stmt->execute()) {
                    aRespond(false, 'Failed to record attendance: ' . $stmt->error);
                }
                $stmt->close();

                log_audit($conn, $user_id, 'attendance_self_qr', 'attendance_logs', $target_event_id, "Self checked-in to \"{$evTitle}\" via Event QR");
                aRespond(true, "Checked in to \"{$evTitle}\" successfully!", [
                    'event_title' => $evTitle,
                    'is_self_checkin' => true
                ]);
            } else {
                // Mode A-2: Staff terminal scanned an event poster -> Switch active event in terminal
                aRespond(true, "Event selected: \"{$evTitle}\". You can now scan student badges!", [
                    'is_event_switch' => true,
                    'event_id'        => $target_event_id,
                    'event_title'     => $evTitle
                ]);
            }
        }

        // ── 2. CHECK IF SCANNED DATA IS A STUDENT OR STAFF BADGE ─────
        $targetUser = resolveUserFromQr($conn, $qr_data);
        if ($targetUser) {
            $target_user_id = (int)$targetUser['id'];
            $fullName       = trim($targetUser['first_name'] . ' ' . $targetUser['last_name']);
            $stuNum         = $targetUser['student_number'] ?? $targetUser['username'];

            // If a student tries to scan another student's badge
            if ($user_role === 'student') {
                aRespond(false, 'You scanned a student badge. To self-check in, point your camera at the Event QR poster posted at the venue.');
            }

            // Staff scanner requires an event to be selected
            if ($event_id <= 0) {
                aRespond(false, "Please select an event from the dropdown before scanning student badges. (Scanned: $fullName - $stuNum)", [
                    'need_event'   => true,
                    'student_name' => $fullName
                ]);
            }

            // Check if already checked in to this event
            $dup = $conn->query("SELECT id, check_in FROM attendance_logs WHERE event_id = $event_id AND user_id = $target_user_id LIMIT 1");
            if ($dup && $dup->num_rows > 0) {
                $dupRow = $dup->fetch_assoc();
                $timeStr = date('h:i A', strtotime($dupRow['check_in']));
                aRespond(false, "{$fullName} ({$stuNum}) is already checked in (at {$timeStr}).", [
                    'already_logged' => true,
                    'student_name'   => $fullName,
                    'student_number' => $stuNum
                ]);
            }

            $method = 'QR';
            $stmt = $conn->prepare("INSERT INTO attendance_logs (event_id, user_id, method, logged_by) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('iisi', $event_id, $target_user_id, $method, $user_id);
            if (!$stmt->execute()) {
                aRespond(false, 'Failed to log attendance: ' . $stmt->error);
            }
            $stmt->close();

            // Push notification & audit
            $ev = $conn->query("SELECT title FROM events WHERE id = $event_id")->fetch_assoc();
            $evTitle = $ev['title'] ?? 'Campus Event';
            push_notification($conn, $target_user_id, 'Attendance Logged', "Your attendance for \"{$evTitle}\" was recorded via QR scan.", 'info');
            log_audit($conn, $user_id, 'attendance_qr', 'attendance_logs', $target_user_id, "Checked in {$fullName} (#$target_user_id) for event #$event_id via QR");

            aRespond(true, "{$fullName} ({$stuNum}) checked in successfully!", [
                'student_name'   => $fullName,
                'student_number' => $stuNum,
                'course'         => $targetUser['course'] ?? '',
                'section'        => $targetUser['section'] ?? '',
                'check_in_time'  => date('h:i:s A')
            ]);
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
} elseif (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'attendance_actions.php') {
    aRespond(false, 'No action specified.');
}

