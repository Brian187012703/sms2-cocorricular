<?php
// ============================================================
//  EVENT_ACTIONS.PHP — Events AJAX handler
//  Actions: list, create, edit, approve, reject, delete
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';

if (empty($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not authenticated.']); exit; }

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

function eRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success'=>$ok,'message'=>$msg], $extra)); exit;
}

switch ($action) {

    // ── LIST events ──────────────────────────────────────────
    case 'list': {
        $sql = "SELECT e.id, e.title, e.description, e.event_date, e.venue, e.status, e.rejection_note,
                       e.created_at, c.name AS club_name, c.code AS club_code,
                       u.first_name, u.last_name
                FROM events e
                JOIN clubs c ON c.id = e.club_id
                LEFT JOIN users u ON u.id = e.created_by
                ORDER BY e.event_date ASC";
        $result = $conn->query($sql);
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        eRespond(true, 'OK', ['events' => $rows]);
    }

    // ── CREATE event proposal (Adviser / OSA / Admin) ─────────
    case 'create': {
        if (!in_array($user_role, ['club_adviser','ssc','admin']))
            eRespond(false, 'Only Club Advisers and above can create events.');

        $title       = trim($_POST['title']       ?? '');
        $description = trim($_POST['description'] ?? '');
        $event_date  = trim($_POST['event_date']  ?? '');
        $venue       = trim($_POST['venue']       ?? '');

        if (!$title || !$event_date || !$venue) eRespond(false, 'Title, date, and venue are required.');

        // Determine club_id
        $club_id = (int)($_POST['club_id'] ?? 1);

        $stmt = $conn->prepare(
            "INSERT INTO events (club_id, title, description, event_date, venue, status, created_by)
             VALUES (?, ?, ?, ?, ?, 'Pending SSC', ?)"
        );
        $stmt->bind_param('issssi', $club_id, $title, $description, $event_date, $venue, $user_id);
        if (!$stmt->execute()) eRespond(false, 'Failed to create event: ' . $stmt->error);
        $new_id = $conn->insert_id;
        $stmt->close();

        // Notify SSC officers/directors
        $osas = $conn->query("SELECT id FROM users WHERE role = 'ssc'");
        while ($o = $osas->fetch_assoc()) {
            push_notification($conn, (int)$o['id'], 'New Event Proposal',
                "A new event \"$title\" on " . date('M d, Y', strtotime($event_date)) . " was submitted for SSC review & approval.", 'event');
        }
        log_audit($conn, $user_id, 'event_create', 'events', $new_id, "Created event proposal: $title");

        eRespond(true, 'Event proposal submitted to SSC for review & approval.', ['id' => $new_id]);
    }

    // ── EDIT event (Adviser / OSA / Admin) ────────────────────
    case 'edit': {
        if (!in_array($user_role, ['club_adviser','ssc','admin']))
            eRespond(false, 'Not authorized to edit events.');

        $id          = (int)($_POST['id']          ?? 0);
        $title       = trim($_POST['title']        ?? '');
        $description = trim($_POST['description']  ?? '');
        $event_date  = trim($_POST['event_date']   ?? '');
        $venue       = trim($_POST['venue']        ?? '');

        if ($id <= 0 || !$title || !$event_date || !$venue) eRespond(false, 'All fields are required.');

        $stmt = $conn->prepare(
            "UPDATE events SET title=?, description=?, event_date=?, venue=? WHERE id=?"
        );
        $stmt->bind_param('ssssi', $title, $description, $event_date, $venue, $id);
        if (!$stmt->execute()) eRespond(false, 'Failed to update event.');
        $stmt->close();

        log_audit($conn, $user_id, 'event_edit', 'events', $id, "Edited event #$id");
        eRespond(true, 'Event updated successfully.');
    }

    // ── APPROVE event (OSA Director / Admin) ─────────────────
    case 'approve': {
        if (!in_array($user_role, ['ssc','admin'])) eRespond(false, 'Only OSA Directors can approve events.');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) eRespond(false, 'Invalid event ID.');

        $stmt = $conn->prepare("UPDATE events SET status = 'Approved', rejection_note = NULL WHERE id = ?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) eRespond(false, 'Failed to approve event.');
        $stmt->close();

        $ev = $conn->query("SELECT e.title, e.created_by FROM events e WHERE e.id = $id")->fetch_assoc();
        if ($ev && $ev['created_by']) {
            push_notification($conn, (int)$ev['created_by'], 'Event Approved!',
                "Your event \"{$ev['title']}\" has been approved by the OSA Director!", 'success');
        }
        log_audit($conn, $user_id, 'event_approve', 'events', $id, "Approved event #$id");
        eRespond(true, 'Event approved successfully.');
    }

    // ── REJECT event (OSA Director / Admin) ──────────────────
    case 'reject': {
        if (!in_array($user_role, ['ssc','admin'])) eRespond(false, 'Only OSA Directors can reject events.');
        $id   = (int)($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? 'Event proposal was rejected.');
        if ($id <= 0) eRespond(false, 'Invalid event ID.');

        $stmt = $conn->prepare("UPDATE events SET status = 'Rejected', rejection_note = ? WHERE id = ?");
        $stmt->bind_param('si', $note, $id);
        $stmt->execute();
        $stmt->close();

        $ev = $conn->query("SELECT title, created_by FROM events WHERE id = $id")->fetch_assoc();
        if ($ev && $ev['created_by']) {
            push_notification($conn, (int)$ev['created_by'], 'Event Rejected',
                "Your event \"{$ev['title']}\" was rejected. Reason: $note", 'warning');
        }
        log_audit($conn, $user_id, 'event_reject', 'events', $id, "Rejected event #$id: $note");
        eRespond(true, 'Event rejected.');
    }

    // ── REGISTER for event (All Roles) ─────────────────────────
    case 'register': {
        $event_id = (int)($_POST['event_id'] ?? 0);
        if ($event_id <= 0) eRespond(false, 'Invalid event ID.');

        // Check if event exists
        $ev = $conn->query("SELECT id, title, status, event_date FROM events WHERE id = $event_id")->fetch_assoc();
        if (!$ev) eRespond(false, 'Event not found.');
        if ($ev['status'] === 'Rejected') eRespond(false, 'Cannot register for a rejected event.');

        // Check if already registered
        $check = $conn->prepare("SELECT id, status FROM event_registrations WHERE event_id = ? AND user_id = ?");
        $check->bind_param('ii', $event_id, $user_id);
        $check->execute();
        $reg = $check->get_result()->fetch_assoc();
        $check->close();

        if ($reg && $reg['status'] === 'Registered') {
            eRespond(false, 'You are already registered for this event.');
        }

        $stmt = $conn->prepare(
            "INSERT INTO event_registrations (event_id, user_id, status) VALUES (?, ?, 'Registered')
             ON DUPLICATE KEY UPDATE status='Registered', registered_at=CURRENT_TIMESTAMP"
        );
        $stmt->bind_param('ii', $event_id, $user_id);
        if (!$stmt->execute()) eRespond(false, 'Failed to register: ' . $stmt->error);
        $stmt->close();

        push_notification($conn, $user_id, 'Event Registration Confirmed 🎉',
            "You have successfully registered for \"{$ev['title']}\" scheduled on " . date('M d, Y', strtotime($ev['event_date'])) . ".", 'success');
        log_audit($conn, $user_id, 'event_register', 'event_registrations', $event_id, "Registered for event #$event_id ({$ev['title']})");

        eRespond(true, "Successfully registered for \"{$ev['title']}\"!");
    }

    // ── DELETE event (Adviser / Admin) ─────────────────────────
    case 'delete': {
        if (!in_array($user_role, ['club_adviser','admin'])) eRespond(false, 'Not authorized.');
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM events WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        log_audit($conn, $user_id, 'event_delete', 'events', $id, "Deleted event #$id");
        eRespond(true, 'Event deleted.');
    }

    // ── LIST REGISTRATIONS for event (Adviser / OSA / Admin) ─
    case 'list_registrations': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'admin'])) eRespond(false, 'Unauthorized.');
        $event_id = (int)($_POST['event_id'] ?? $_GET['event_id'] ?? 0);
        if (!$event_id) eRespond(false, 'Invalid event ID.');

        $sql = "SELECT er.id, er.registered_at, er.status,
                       u.first_name, u.last_name, u.email,
                       s.course, s.year_level, s.phone
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

        $ev = $conn->query("SELECT title, event_date, venue FROM events WHERE id=$event_id")->fetch_assoc();

        eRespond(true, 'OK', ['registrations' => $registrations, 'event' => $ev]);
    }

    default:
        eRespond(false, 'Unknown action.');
}
$conn->close();
