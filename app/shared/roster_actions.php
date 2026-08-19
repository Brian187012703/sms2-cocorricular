<?php
// ============================================================
//  ROSTER_ACTIONS.PHP — Club Membership AJAX handler
//  Actions: list, list_pending, apply, approve, reject, remove, export_csv
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

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
        if (!in_array($user_role, ['club_adviser','ssc','admin']))
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
                       COALESCE(ca.first_name, u.first_name) AS first_name,
                       COALESCE(ca.last_name, u.last_name) AS last_name,
                       COALESCE(ca.email, u.email) AS email,
                       ca.student_id_no, ca.course, ca.year_level, ca.phone, ca.sex, ca.dob, ca.address, ca.motivation,
                       COALESCE(ca.letter_intent, cm.letter_intent) AS letter_intent,
                       COALESCE(ca.letter_endorsement, cm.letter_endorsement) AS letter_endorsement
                FROM club_memberships cm
                JOIN clubs c ON c.id = cm.club_id
                JOIN users u ON u.id = cm.user_id
                LEFT JOIN club_applications ca ON (ca.club_id = cm.club_id AND ca.user_id = cm.user_id AND ca.status = 'Pending')
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

        // Extract detailed form fields
        $first_name    = trim($_POST['first_name'] ?? $_SESSION['first_name'] ?? '');
        $last_name     = trim($_POST['last_name']  ?? $_SESSION['last_name']  ?? '');
        $student_id_no = trim($_POST['student_id_no'] ?? '');
        $course        = trim($_POST['course'] ?? '');
        $year_level    = trim($_POST['year_level'] ?? '');
        $email         = trim($_POST['email'] ?? $_SESSION['email'] ?? '');
        $phone         = trim($_POST['contact'] ?? '');
        $sex           = trim($_POST['sex'] ?? '');
        $dob           = !empty($_POST['dob']) ? date('Y-m-d', strtotime($_POST['dob'])) : null;
        $address       = trim($_POST['address'] ?? '');
        $motivation    = trim($_POST['motivation'] ?? '');

        // 1. Insert into dedicated club_applications table
        $app_stmt = $conn->prepare("
            INSERT INTO club_applications
            (club_id, user_id, first_name, last_name, student_id_no, course, year_level, email, phone, sex, dob, address, motivation, letter_intent, letter_endorsement, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
        ");
        $app_stmt->bind_param(
            'iisssssssssssss',
            $club_id, $user_id, $first_name, $last_name, $student_id_no, $course, $year_level,
            $email, $phone, $sex, $dob, $address, $motivation, $letter_intent_path, $letter_endorsement_path
        );
        $app_stmt->execute();
        $app_stmt->close();

        // 2. Insert/update club_memberships
        $stmt = $conn->prepare(
            "INSERT INTO club_memberships (club_id, user_id, role, status, letter_intent, letter_endorsement) VALUES (?, ?, 'Member', 'Pending', ?, ?)
             ON DUPLICATE KEY UPDATE status='Pending', letter_intent=VALUES(letter_intent), letter_endorsement=VALUES(letter_endorsement)"
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
        if (!in_array($user_role, ['club_adviser','ssc','admin']))
            rRespond(false, 'Not authorized.');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) rRespond(false, 'Invalid membership ID.');

        $stmt = $conn->prepare(
            "UPDATE club_memberships SET status='Active', approved_by=? WHERE id=? AND status='Pending'"
        );
        $stmt->bind_param('ii', $user_id, $id);
        if (!$stmt->execute() || $stmt->affected_rows === 0) rRespond(false, 'Could not approve — already processed?');
        $stmt->close();

        // Fetch membership details to sync club_applications
        $cm = $conn->query("SELECT cm.club_id, cm.user_id, c.name FROM club_memberships cm JOIN clubs c ON c.id=cm.club_id WHERE cm.id=$id")->fetch_assoc();
        if ($cm) {
            $app_up = $conn->prepare("UPDATE club_applications SET status='Approved', reviewed_by=?, reviewed_at=NOW() WHERE club_id=? AND user_id=? AND status='Pending'");
            $app_up->bind_param('iii', $user_id, $cm['club_id'], $cm['user_id']);
            $app_up->execute();
            $app_up->close();

            push_notification($conn, (int)$cm['user_id'], 'Membership Approved!',
                "Your application to join {$cm['name']} has been approved! Welcome aboard!", 'success');
        }
        log_audit($conn, $user_id, 'roster_approve', 'club_memberships', $id, "Approved membership #$id");
        rRespond(true, 'Applicant approved successfully.');
    }

    // ── REJECT applicant ─────────────────────────────────────
    case 'reject': {
        if (!in_array($user_role, ['club_adviser','ssc','admin']))
            rRespond(false, 'Not authorized.');
        $id   = (int)($_POST['id']     ?? 0);
        if ($id <= 0) rRespond(false, 'Invalid membership ID.');

        $stmt = $conn->prepare(
            "UPDATE club_memberships SET status='Rejected' WHERE id=? AND status='Pending'"
        );
        $stmt->bind_param('i', $id);
        if (!$stmt->execute() || $stmt->affected_rows === 0) rRespond(false, 'Could not reject — already processed?');
        $stmt->close();

        $cm = $conn->query("SELECT cm.club_id, cm.user_id, c.name FROM club_memberships cm JOIN clubs c ON c.id=cm.club_id WHERE cm.id=$id")->fetch_assoc();
        if ($cm) {
            $app_up = $conn->prepare("UPDATE club_applications SET status='Rejected', reviewed_by=?, reviewed_at=NOW() WHERE club_id=? AND user_id=? AND status='Pending'");
            $app_up->bind_param('iii', $user_id, $cm['club_id'], $cm['user_id']);
            $app_up->execute();
            $app_up->close();

            push_notification($conn, (int)$cm['user_id'], 'Membership Update',
                "Your application to join {$cm['name']} was not approved.", 'warning');
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
        if (!in_array($user_role, ['club_adviser','ssc','admin']))
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
