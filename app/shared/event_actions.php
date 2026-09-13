<?php
// ============================================================
//  EVENT_ACTIONS.PHP — Events AJAX handler
//  Workflow:
//    Club Adviser Proposal -> Pending SSC
//    SSC Review & Endorsement -> Pending Admin
//    System Admin Clearance -> Approved (Posted to Calendar)
//    SSC Institutional Event -> Pending Admin -> Approved
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';
require_once __DIR__ . '/ph_holidays.php';

if (empty($_SESSION['user_id'])) { 
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']); 
    exit; 
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

function eRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra)); 
    exit;
}

switch ($action) {

    // ── CHECK CONFLICT (Holidays, Exam Blackout, Venue Collision) ──
    case 'check_conflict': {
        $event_date = trim($_POST['event_date'] ?? $_GET['event_date'] ?? '');
        $venue      = trim($_POST['venue']      ?? $_GET['venue']      ?? '');
        $exclude_id = (int)($_POST['event_id']  ?? $_GET['event_id']  ?? 0);
        $club_id    = (int)($_POST['club_id']   ?? $_GET['club_id']   ?? 0);

        if (!$event_date) eRespond(false, 'Date is required.');
        $result = detect_event_schedule_conflict($conn, $event_date, $venue, $exclude_id, $club_id);
        eRespond(true, 'Conflict audit completed.', ['analysis' => $result]);
    }

    // ── GET HOLIDAYS CATALOG ──────────────────────────────────────────
    case 'get_holidays': {
        $year = (int)($_POST['year'] ?? $_GET['year'] ?? date('Y'));
        if ($year < 2020 || $year > 2035) $year = (int)date('Y');
        $holidays = get_ph_holidays($year);
        eRespond(true, 'OK', ['holidays' => $holidays]);
    }

    // ── LIST events ──────────────────────────────────────────
    case 'list': {
        $sql = "SELECT e.id, e.club_id, e.event_type, e.title, e.description, e.event_date, e.venue,
                       e.status, e.endorsement_notes, e.rejection_note, e.created_by, e.created_at,
                       COALESCE(c.name, 'BCP Institutional / Campus-Wide') AS club_name,
                       COALESCE(c.code, 'INSTITUTIONAL') AS club_code,
                       u.first_name, u.last_name, u.role AS creator_role
                FROM events e
                LEFT JOIN clubs c ON c.id = e.club_id
                LEFT JOIN users u ON u.id = e.created_by
                ORDER BY e.event_date ASC";
        $result = $conn->query($sql);
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        eRespond(true, 'OK', ['events' => $rows]);
    }

    // ── CREATE event proposal (Adviser / SSC / Admin) ─────────
    case 'create': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'admin'])) {
            eRespond(false, 'Only Club Advisers, SSC Officers, and Administrators can create events.');
        }

        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $event_date  = trim($_POST['event_date'] ?? '');
        $venue       = trim($_POST['venue'] ?? '');
        $event_type  = trim($_POST['event_type'] ?? 'Club');
        $raw_club_id = (int)($_POST['club_id'] ?? 0);

        if (!$title || !$event_date || !$venue) {
            eRespond(false, 'Event title, date, and venue are required.');
        }

        // Institutional events created by SSC/Admin have NULL club_id
        $club_id = null;
        if ($event_type === 'Club') {
            if ($user_role === 'club_adviser') {
                $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
                $cm->bind_param('i', $user_id);
                $cm->execute();
                $cm->bind_result($my_cid);
                $cm->fetch();
                $cm->close();
                $club_id = $my_cid ? (int)$my_cid : ($raw_club_id ?: 1);
            } else {
                $club_id = $raw_club_id > 0 ? $raw_club_id : 1;
            }
        } else {
            $event_type = 'Institutional';
            $club_id = null;
        }

        // Determine initial status based on role
        // Club Adviser -> Pending SSC
        // SSC Officer  -> Pending Admin
        // Admin        -> Approved
        $initial_status = 'Pending SSC';
        if ($user_role === 'ssc') {
            $initial_status = 'Pending Admin';
        } elseif ($user_role === 'admin') {
            $initial_status = 'Approved';
        }

        // Run Pre-Flight Conflict Check
        $conflictAnalysis = detect_event_schedule_conflict($conn, $event_date, $venue, 0, $club_id ?: 0);

        $stmt = $conn->prepare(
            "INSERT INTO events (club_id, event_type, title, description, event_date, venue, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issssssi', $club_id, $event_type, $title, $description, $event_date, $venue, $initial_status, $user_id);
        if (!$stmt->execute()) {
            eRespond(false, 'Failed to create event: ' . $stmt->error);
        }
        $new_id = $conn->insert_id;
        $stmt->close();

        $holidayNotice = $conflictAnalysis['has_conflict'] ? " [Flagged: {$conflictAnalysis['summary']}]" : "";

        // Notify appropriate role
        if ($initial_status === 'Pending SSC') {
            $sscs = $conn->query("SELECT id FROM users WHERE role = 'ssc'");
            while ($o = $sscs->fetch_assoc()) {
                push_notification($conn, (int)$o['id'], 'New Club Event Proposal',
                    "New event \"$title\" on " . date('M d, Y', strtotime($event_date)) . " submitted for SSC endorsement.{$holidayNotice}", 'event');
            }
        } elseif ($initial_status === 'Pending Admin') {
            $admins = $conn->query("SELECT id FROM users WHERE role = 'admin'");
            while ($a = $admins->fetch_assoc()) {
                push_notification($conn, (int)$a['id'], 'Event Awaiting Final Approval',
                    ($event_type === 'Institutional' ? 'Institutional Event ' : 'Endorsed Event ') . "\"$title\" needs Admin calendar approval.{$holidayNotice}", 'event');
            }
        }

        log_audit($conn, $user_id, 'event_create', 'events', $new_id, "Created $event_type event \"$title\" (Status: $initial_status)$holidayNotice");

        $statusMsg = ($initial_status === 'Pending SSC') 
            ? 'Event proposal submitted for SSC review and endorsement.' 
            : (($initial_status === 'Pending Admin') 
                ? 'Institutional / Endorsed event forwarded to System Admin for calendar approval.' 
                : 'Event created and published to campus calendar.');

        eRespond(true, $statusMsg, [
            'id' => $new_id,
            'status' => $initial_status,
            'conflict_analysis' => $conflictAnalysis
        ]);
    }

    // ── SSC ENDORSE / FORWARD TO ADMIN (Stage 2) ───────────────
    case 'ssc_endorse':
    case 'forward_to_admin': {
        if (!in_array($user_role, ['ssc', 'admin'])) {
            eRespond(false, 'Only SSC Officers and Admins can endorse events.');
        }

        $id    = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        if ($id <= 0) eRespond(false, 'Invalid event ID.');

        $endorse_str = "Endorsed by SSC" . ($notes ? ": $notes" : " for final Admin calendar clearance.");

        $stmt = $conn->prepare("UPDATE events SET status = 'Pending Admin', endorsement_notes = ? WHERE id = ?");
        $stmt->bind_param('si', $endorse_str, $id);
        if (!$stmt->execute()) eRespond(false, 'Failed to endorse event: ' . $stmt->error);
        $stmt->close();

        // Notify Admins
        $ev = $conn->query("SELECT title, created_by FROM events WHERE id = $id")->fetch_assoc();
        $admins = $conn->query("SELECT id FROM users WHERE role = 'admin'");
        while ($a = $admins->fetch_assoc()) {
            push_notification($conn, (int)$a['id'], 'Event Endorsed by SSC',
                "Event \"{$ev['title']}\" was endorsed by SSC and awaits Admin final clearance.", 'event');
        }

        if ($ev && $ev['created_by']) {
            push_notification($conn, (int)$ev['created_by'], 'Event Endorsed by SSC',
                "Your event proposal \"{$ev['title']}\" was endorsed by SSC and forwarded to Admin for calendar posting.", 'info');
        }

        log_audit($conn, $user_id, 'event_ssc_endorse', 'events', $id, "SSC endorsed event #$id to Pending Admin");
        eRespond(true, 'Event endorsed and forwarded to System Admin for final calendar approval.');
    }

    // ── ADMIN FINAL APPROVE (Stage 3 -> Approved / Calendar) ──
    case 'admin_approve':
    case 'approve': {
        if (!in_array($user_role, ['admin'])) {
            eRespond(false, 'Only System Administrators can grant final calendar approval.');
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) eRespond(false, 'Invalid event ID.');

        $stmt = $conn->prepare("UPDATE events SET status = 'Approved', rejection_note = NULL WHERE id = ?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) eRespond(false, 'Failed to approve event.');
        $stmt->close();

        $ev = $conn->query("SELECT title, created_by, event_date FROM events WHERE id = $id")->fetch_assoc();
        if ($ev && $ev['created_by']) {
            push_notification($conn, (int)$ev['created_by'], 'Event Officially Approved! 🎉',
                "Your event \"{$ev['title']}\" is approved and now active on the official BCP calendar!", 'success');
        }

        // Notify SSC
        $sscs = $conn->query("SELECT id FROM users WHERE role = 'ssc'");
        while ($s = $sscs->fetch_assoc()) {
            push_notification($conn, (int)$s['id'], 'Event Approved & Published',
                "Event \"{$ev['title']}\" has received Admin clearance and is published on campus calendar.", 'event');
        }

        log_audit($conn, $user_id, 'event_admin_approve', 'events', $id, "Admin granted final clearance for event #$id");
        eRespond(true, 'Event approved and published to the active campus calendar!');
    }

    // ── REJECT event (SSC / Admin) ──────────────────
    case 'reject': {
        if (!in_array($user_role, ['ssc', 'admin'])) {
            eRespond(false, 'Only SSC Officers and Administrators can reject events.');
        }

        $id   = (int)($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? 'Event proposal did not meet compliance requirements.');
        if ($id <= 0) eRespond(false, 'Invalid event ID.');

        $rej_prefix = ($user_role === 'ssc') ? 'SSC Review Feedback: ' : 'Admin Review Feedback: ';
        $full_note  = $rej_prefix . $note;

        $stmt = $conn->prepare("UPDATE events SET status = 'Rejected', rejection_note = ? WHERE id = ?");
        $stmt->bind_param('si', $full_note, $id);
        $stmt->execute();
        $stmt->close();

        $ev = $conn->query("SELECT title, created_by FROM events WHERE id = $id")->fetch_assoc();
        if ($ev && $ev['created_by']) {
            push_notification($conn, (int)$ev['created_by'], 'Event Proposal Feedback',
                "Your event proposal \"{$ev['title']}\" was rejected. Reason: $note", 'warning');
        }

        log_audit($conn, $user_id, 'event_reject', 'events', $id, "Rejected event #$id by $user_role: $note");
        eRespond(true, 'Event proposal rejected and notification sent to proposer.');
    }

    // ── EDIT event (Adviser / SSC / Admin) ────────────────────
    case 'edit': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'admin'])) {
            eRespond(false, 'Not authorized to edit events.');
        }

        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $event_date  = trim($_POST['event_date'] ?? '');
        $venue       = trim($_POST['venue'] ?? '');

        if ($id <= 0 || !$title || !$event_date || !$venue) {
            eRespond(false, 'All fields are required.');
        }

        $stmt = $conn->prepare(
            "UPDATE events SET title=?, description=?, event_date=?, venue=? WHERE id=?"
        );
        $stmt->bind_param('ssssi', $title, $description, $event_date, $venue, $id);
        if (!$stmt->execute()) eRespond(false, 'Failed to update event.');
        $stmt->close();

        log_audit($conn, $user_id, 'event_edit', 'events', $id, "Edited event #$id: $title");
        eRespond(true, 'Event updated successfully.');
    }

    // ── REGISTER for event ──────────────────────────────────────
    case 'register': {
        if ($user_role === 'club_adviser') {
            eRespond(false, 'Club Advisers are not eligible to register as event participants.');
        }

        $event_id = (int)($_POST['event_id'] ?? 0);
        if ($event_id <= 0) eRespond(false, 'Invalid event ID.');

        $ev = $conn->query("SELECT id, title, status, event_date FROM events WHERE id = $event_id")->fetch_assoc();
        if (!$ev) eRespond(false, 'Event not found.');
        if ($ev['status'] !== 'Approved' && $ev['status'] !== 'Upcoming') {
            eRespond(false, 'Registration is only open for officially approved events.');
        }

        $stmt = $conn->prepare(
            "INSERT INTO event_registrations (event_id, user_id, status) VALUES (?, ?, 'Registered')
             ON DUPLICATE KEY UPDATE status='Registered', registered_at=CURRENT_TIMESTAMP"
        );
        $stmt->bind_param('ii', $event_id, $user_id);
        if (!$stmt->execute()) eRespond(false, 'Failed to register: ' . $stmt->error);
        $stmt->close();

        push_notification($conn, $user_id, 'Event Registration Confirmed 🎉',
            "You have successfully registered for \"{$ev['title']}\" on " . date('M d, Y', strtotime($ev['event_date'])) . ".", 'success');
        log_audit($conn, $user_id, 'event_register', 'event_registrations', $event_id, "Registered for event #$event_id ({$ev['title']})");

        eRespond(true, "Successfully registered for \"{$ev['title']}\"!");
    }

    // ── DELETE event (Adviser / SSC / Admin) ───────────────────
    case 'delete': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'admin'])) {
            eRespond(false, 'Not authorized.');
        }
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM events WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        log_audit($conn, $user_id, 'event_delete', 'events', $id, "Deleted event #$id");
        eRespond(true, 'Event deleted.');
    }

    // ── LIST REGISTRATIONS for event ──────────────────────────
    case 'list_registrations': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'admin'])) {
            eRespond(false, 'Unauthorized.');
        }
        $event_id = (int)($_POST['event_id'] ?? $_GET['event_id'] ?? 0);
        if (!$event_id) eRespond(false, 'Invalid event ID.');

        $sql = "SELECT er.id, er.registered_at, er.status,
                       u.first_name, u.last_name, u.email,
                       s.student_number, s.course, s.year_level, s.phone, s.section
                FROM event_registrations er
                JOIN users u ON u.id = er.user_id
                LEFT JOIN students s ON (s.first_name = u.first_name AND s.last_name = u.last_name)
                WHERE er.event_id = ?
                ORDER BY er.registered_at ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $registrations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $ev = $conn->query("
            SELECT e.title, e.event_date, e.venue, e.status, e.event_type,
                   COALESCE(c.name, 'BCP Institutional / Campus-Wide') AS club_name, 
                   COALESCE(c.code, 'INSTITUTIONAL') AS club_code 
            FROM events e 
            LEFT JOIN clubs c ON c.id = e.club_id 
            WHERE e.id = $event_id
        ")->fetch_assoc();

        eRespond(true, 'OK', ['registrations' => $registrations, 'event' => $ev]);
    }

    default:
        eRespond(false, 'Unknown action.');
}
$conn->close();
