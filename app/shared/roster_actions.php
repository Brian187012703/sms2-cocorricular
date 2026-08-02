<?php
// ============================================================
//  ROSTER_ACTIONS.PHP — Club Membership AJAX handler
//  Actions: list, list_pending, apply, approve, reject, remove, export_csv
// ============================================================
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';

if (empty($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not authenticated.']); exit; }

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

function rRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success'=>$ok,'message'=>$msg], $extra)); exit;
}

switch ($action) {

    // ── LIST active members ──────────────────────────────────
    case 'list': {
        $club_filter = '';
        $params = [];
        $types  = '';

        if ($user_role === 'club_adviser') {
            $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
            $cm->bind_param('i', $user_id);
            $cm->execute();
            $cm->bind_result($club_id);
            $cm->fetch();
            $cm->close();
            if (!empty($club_id)) { $club_filter = 'AND cm.club_id = ?'; $params[] = $club_id; $types .= 'i'; }
        }

        $sql = "SELECT cm.id, cm.club_id, cm.user_id, cm.role AS member_role,
                       cm.status, cm.joined_at,
                       c.name AS club_name, c.code AS club_code,
                       u.first_name, u.last_name, u.email
                FROM club_memberships cm
                JOIN clubs c ON c.id = cm.club_id
                JOIN users u ON u.id = cm.user_id
                WHERE cm.status = 'Active' $club_filter
                ORDER BY cm.joined_at DESC";
        $stmt = $conn->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        rRespond(true, 'OK', ['members' => $rows]);
    }

    // ── LIST pending applicants ──────────────────────────────
    case 'list_pending': {
        if (!in_array($user_role, ['club_adviser','osa_director','admin']))
            rRespond(false, 'Not authorized.');

        $club_filter = '';
        $params = [];
        $types  = '';
        if ($user_role === 'club_adviser') {
            $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
            $cm->bind_param('i', $user_id);
            $cm->execute();
            $cm->bind_result($club_id);
            $cm->fetch();
            $cm->close();
            if (!empty($club_id)) { $club_filter = 'AND cm.club_id = ?'; $params[] = $club_id; $types .= 'i'; }
        }

        $sql = "SELECT cm.id, cm.club_id, cm.user_id, cm.joined_at,
                       c.name AS club_name, c.code AS club_code,
                       u.first_name, u.last_name, u.email
                FROM club_memberships cm
                JOIN clubs c ON c.id = cm.club_id
                JOIN users u ON u.id = cm.user_id
                WHERE cm.status = 'Pending' $club_filter
                ORDER BY cm.joined_at ASC";
        $stmt = $conn->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        rRespond(true, 'OK', ['applicants' => $rows]);
    }

    // ── LIST my memberships (Student) ────────────────────────
    case 'my_memberships': {
        $stmt = $conn->prepare(
            "SELECT cm.id, cm.role AS member_role, cm.status, cm.joined_at,
                    c.name AS club_name, c.code AS club_code, c.category
             FROM club_memberships cm
             JOIN clubs c ON c.id = cm.club_id
             WHERE cm.user_id = ?
             ORDER BY cm.joined_at DESC"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        rRespond(true, 'OK', ['memberships' => $rows]);
    }

    // ── APPLY to join a club (Student) ───────────────────────
    case 'apply': {
        if ($user_role !== 'student') rRespond(false, 'Only students can apply.');
        $club_id = (int)($_POST['club_id'] ?? 0);
        if ($club_id <= 0) rRespond(false, 'Invalid club.');

        // Check already a member or pending
        $check = $conn->prepare("SELECT status FROM club_memberships WHERE user_id=? AND club_id=?");
        $check->bind_param('ii', $user_id, $club_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();
        if ($existing) {
            if ($existing['status'] === 'Active') rRespond(false, 'You are already a member of this club.');
            if ($existing['status'] === 'Pending') rRespond(false, 'Your application is already pending review.');
        }

        // File uploads for Letter of Intent & Letter of Endorsement
        $upload_dir = __DIR__ . '/../uploads/applications/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $letter_intent_path = null;
        if (!empty($_FILES['letter_intent']['name'])) {
            $ext = strtolower(pathinfo($_FILES['letter_intent']['name'], PATHINFO_EXTENSION));
            $fname = 'intent_' . time() . '_' . $user_id . '.' . $ext;
            if (move_uploaded_file($_FILES['letter_intent']['tmp_name'], $upload_dir . $fname)) {
                $letter_intent_path = $fname;
            }
        }

        $letter_endorsement_path = null;
        if (!empty($_FILES['letter_endorsement']['name'])) {
            $ext = strtolower(pathinfo($_FILES['letter_endorsement']['name'], PATHINFO_EXTENSION));
            $fname = 'endorsement_' . time() . '_' . $user_id . '.' . $ext;
            if (move_uploaded_file($_FILES['letter_endorsement']['tmp_name'], $upload_dir . $fname)) {
                $letter_endorsement_path = $fname;
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO club_memberships (club_id, user_id, role, status, letter_intent, letter_endorsement) VALUES (?, ?, 'Member', 'Pending', ?, ?)"
        );
        $stmt->bind_param('iiss', $club_id, $user_id, $letter_intent_path, $letter_endorsement_path);
        if (!$stmt->execute()) rRespond(false, 'Failed to apply: ' . $stmt->error);
        $new_id = $conn->insert_id;
        $stmt->close();

        // Get club name
        $club = $conn->query("SELECT name FROM clubs WHERE id = $club_id")->fetch_assoc();
        $club_name = $club ? $club['name'] : 'the club';

        // Notify advisers of that club
        $advisers = $conn->query("SELECT cm.user_id FROM club_memberships cm JOIN users u ON u.id=cm.user_id WHERE cm.club_id=$club_id AND cm.status='Active' AND u.role='club_adviser'");
        $fn = $_SESSION['first_name'] ?? 'A student';
        $ln = $_SESSION['last_name']  ?? '';
        while ($o = $advisers->fetch_assoc()) {
            push_notification($conn, (int)$o['user_id'], 'New Club Applicant',
                "$fn $ln applied to join $club_name. Please review.", 'roster');
        }
        log_audit($conn, $user_id, 'club_apply', 'club_memberships', $new_id, "Applied to club $club_id");
        rRespond(true, "Application submitted to $club_name. Awaiting adviser approval.");
    }

    // ── APPROVE applicant ────────────────────────────────────
    case 'approve': {
        if (!in_array($user_role, ['club_adviser','osa_director','admin']))
            rRespond(false, 'Not authorized.');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) rRespond(false, 'Invalid membership ID.');

        $stmt = $conn->prepare(
            "UPDATE club_memberships SET status='Active', approved_by=? WHERE id=? AND status='Pending'"
        );
        $stmt->bind_param('ii', $user_id, $id);
        if (!$stmt->execute() || $stmt->affected_rows === 0) rRespond(false, 'Could not approve — already processed?');
        $stmt->close();

        // Notify the applicant
        $cm = $conn->query("SELECT cm.user_id, c.name FROM club_memberships cm JOIN clubs c ON c.id=cm.club_id WHERE cm.id=$id")->fetch_assoc();
        if ($cm) {
            push_notification($conn, (int)$cm['user_id'], 'Membership Approved!',
                "Your application to join {$cm['name']} has been approved! Welcome aboard!", 'success');
        }
        log_audit($conn, $user_id, 'roster_approve', 'club_memberships', $id, "Approved membership #$id");
        rRespond(true, 'Applicant approved successfully.');
    }

    // ── REJECT applicant ─────────────────────────────────────
    case 'reject': {
        if (!in_array($user_role, ['club_adviser','osa_director','admin']))
            rRespond(false, 'Not authorized.');
        $id   = (int)($_POST['id']     ?? 0);
        if ($id <= 0) rRespond(false, 'Invalid membership ID.');

        $stmt = $conn->prepare("UPDATE club_memberships SET status='Rejected' WHERE id=? AND status='Pending'");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute() || $stmt->affected_rows === 0) rRespond(false, 'Could not reject.');
        $stmt->close();

        $cm = $conn->query("SELECT cm.user_id, c.name FROM club_memberships cm JOIN clubs c ON c.id=cm.club_id WHERE cm.id=$id")->fetch_assoc();
        if ($cm) {
            push_notification($conn, (int)$cm['user_id'], 'Membership Application',
                "Your application to join {$cm['name']} was not approved at this time.", 'warning');
        }
        log_audit($conn, $user_id, 'roster_reject', 'club_memberships', $id, "Rejected membership #$id");
        rRespond(true, 'Applicant rejected.');
    }

    // ── REMOVE active member ─────────────────────────────────
    case 'remove': {
        if (!in_array($user_role, ['club_adviser','admin'])) rRespond(false, 'Not authorized.');
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE club_memberships SET status='Rejected' WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        log_audit($conn, $user_id, 'roster_remove', 'club_memberships', $id, "Removed member #$id");
        rRespond(true, 'Member removed.');
    }

    // ── EXPORT CSV ───────────────────────────────────────────
    case 'export_csv': {
        if (!in_array($user_role, ['club_adviser','osa_director','admin']))
            rRespond(false, 'Not authorized.');

        // Return JSON of all active members for JS to build CSV
        $rows = $conn->query(
            "SELECT u.first_name, u.last_name, u.email, c.name AS club_name, c.code, cm.role AS member_role, cm.joined_at
             FROM club_memberships cm
             JOIN users u ON u.id = cm.user_id
             JOIN clubs c ON c.id = cm.club_id
             WHERE cm.status = 'Active'
             ORDER BY c.name, u.last_name"
        )->fetch_all(MYSQLI_ASSOC);

        log_audit($conn, $user_id, 'roster_export', 'club_memberships', 0, 'Exported CSV roster');
        rRespond(true, 'OK', ['csv_data' => $rows]);
    }

    default:
        rRespond(false, 'Unknown action.');
}
$conn->close();
