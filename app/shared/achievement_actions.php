<?php
// ============================================================
//  ACHIEVEMENT_ACTIONS.PHP — Achievements AJAX handler
//  Actions: list, submit, verify, reject
// ============================================================
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';

if (empty($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Not authenticated.']); exit; }

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

function achRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success'=>$ok,'message'=>$msg], $extra)); exit;
}

switch ($action) {

    // ── LIST achievements ────────────────────────────────────
    case 'list': {
        $where  = '';
        $params = [];
        $types  = '';

        if ($user_role === 'student') {
            $where  = 'WHERE a.submitted_by = ? AND a.status = "Verified"';
            $params = [$user_id];
            $types  = 'i';
        } elseif ($user_role === 'club_adviser') {
            // Adviser sees their club
            $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
            $cm->bind_param('i', $user_id);
            $cm->execute();
            $cm->bind_result($club_id);
            $cm->fetch();
            $cm->close();
            if (!empty($club_id)) { $where = "WHERE a.club_id = ?"; $params = [(int)$club_id]; $types = 'i'; }
        }

        $sql = "SELECT a.id, a.title, a.competition, a.award_date, a.status, a.notes, a.proof_file, a.created_at,
                       c.name AS club_name, c.code AS club_code,
                       u.first_name, u.last_name
                FROM achievements a
                JOIN clubs c ON c.id = a.club_id
                JOIN users u ON u.id = a.submitted_by
                $where
                ORDER BY a.award_date DESC";
        $stmt = $conn->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        achRespond(true, 'OK', ['achievements' => $rows]);
    }

    // ── LIST pending for OSA/Admin ────────────────────────────
    case 'list_pending': {
        if (!in_array($user_role, ['ssc','admin'])) achRespond(false, 'Not authorized.');
        $rows = $conn->query(
            "SELECT a.id, a.title, a.competition, a.award_date, a.proof_file, a.created_at,
                    c.name AS club_name, u.first_name, u.last_name
             FROM achievements a
             JOIN clubs c ON c.id = a.club_id
             JOIN users u ON u.id = a.submitted_by
             WHERE a.status = 'Pending'
             ORDER BY a.created_at ASC"
        )->fetch_all(MYSQLI_ASSOC);
        achRespond(true, 'OK', ['pending' => $rows]);
    }

    // ── SUBMIT achievement (Student / Adviser) ────────────────
    case 'submit': {
        if (!in_array($user_role, ['student','club_adviser'])) achRespond(false, 'Only students and advisers can submit.');

        $title       = trim($_POST['title']       ?? '');
        $competition = trim($_POST['competition'] ?? '');
        $award_date  = trim($_POST['award_date']  ?? '');

        if (!$title || !$competition || !$award_date) achRespond(false, 'Title, competition, and award date are required.');

        // Get club_id — prefer posted value, fall back to membership
        $club_id = (int)($_POST['club_id'] ?? 0);
        if (!$club_id) {
            $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
            $cm->bind_param('i', $user_id);
            $cm->execute();
            $cm->bind_result($club_id);
            $cm->fetch();
            $cm->close();
        }
        if (!$club_id) achRespond(false, 'You must belong to a club to submit an achievement. Please select one.');

        // Handle file upload
        $proof_file = null;
        if (!empty($_FILES['proof_file']['name'])) {
            $uploads_dir = __DIR__ . '/../uploads/achievements/';
            if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);
            $ext  = strtolower(pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','pdf','webp'];
            if (!in_array($ext, $allowed)) achRespond(false, 'Invalid file type. Allowed: jpg, png, pdf.');
            if ($_FILES['proof_file']['size'] > 5 * 1024 * 1024) achRespond(false, 'File too large (max 5MB).');
            $fname = 'ach_' . time() . '_' . $user_id . '.' . $ext;
            if (!move_uploaded_file($_FILES['proof_file']['tmp_name'], $uploads_dir . $fname))
                achRespond(false, 'File upload failed.');
            $proof_file = $fname;
        }

        $stmt = $conn->prepare(
            "INSERT INTO achievements (club_id, submitted_by, title, competition, award_date, proof_file, status)
             VALUES (?, ?, ?, ?, ?, ?, 'Pending')"
        );
        $stmt->bind_param('iissss', $club_id, $user_id, $title, $competition, $award_date, $proof_file);
        if (!$stmt->execute()) achRespond(false, 'Failed to submit: ' . $stmt->error);
        $new_id = $conn->insert_id;
        $stmt->close();

        // Notify OSA directors
        $osas = $conn->query("SELECT id FROM users WHERE role = 'ssc'");
        while ($o = $osas->fetch_assoc()) {
            push_notification($conn, (int)$o['id'], 'New Achievement Submission',
                "A new achievement \"$title\" from " . ($_SESSION['first_name']??'') . " was submitted for verification.", 'achievement');
        }
        log_audit($conn, $user_id, 'achievement_submit', 'achievements', $new_id, "Submitted: $title");
        achRespond(true, 'Achievement submitted for OSA verification.', ['id' => $new_id]);
    }

    // ── VERIFY achievement (OSA / Admin) ─────────────────────
    case 'verify': {
        if (!in_array($user_role, ['ssc','admin'])) achRespond(false, 'Only OSA Directors can verify achievements.');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) achRespond(false, 'Invalid achievement ID.');

        $stmt = $conn->prepare("UPDATE achievements SET status='Verified', verified_by=?, notes=? WHERE id=?");
        $note = trim($_POST['notes'] ?? 'Verified and endorsed by OSA Director.');
        $stmt->bind_param('isi', $user_id, $note, $id);
        if (!$stmt->execute()) achRespond(false, 'Failed to verify.');
        $stmt->close();

        $ach = $conn->query("SELECT submitted_by, title FROM achievements WHERE id=$id")->fetch_assoc();
        if ($ach) {
            push_notification($conn, (int)$ach['submitted_by'], 'Achievement Verified! 🏆',
                "Your achievement \"{$ach['title']}\" has been verified and endorsed by the OSA Director!", 'success');
        }
        log_audit($conn, $user_id, 'achievement_verify', 'achievements', $id, "Verified #$id");
        achRespond(true, 'Achievement verified and endorsed.');
    }

    // ── REJECT achievement (OSA / Admin) ─────────────────────
    case 'reject': {
        if (!in_array($user_role, ['ssc','admin'])) achRespond(false, 'Not authorized.');
        $id   = (int)($_POST['id']    ?? 0);
        $note = trim($_POST['notes']  ?? 'Please provide additional documentation.');
        if ($id <= 0) achRespond(false, 'Invalid achievement ID.');

        $stmt = $conn->prepare("UPDATE achievements SET status='Rejected', verified_by=?, notes=? WHERE id=?");
        $stmt->bind_param('isi', $user_id, $note, $id);
        $stmt->execute();
        $stmt->close();

        $ach = $conn->query("SELECT submitted_by, title FROM achievements WHERE id=$id")->fetch_assoc();
        if ($ach) {
            push_notification($conn, (int)$ach['submitted_by'], 'Achievement Info Requested',
                "Your achievement \"{$ach['title']}\" needs more info. Notes: $note", 'warning');
        }
        log_audit($conn, $user_id, 'achievement_reject', 'achievements', $id, "Rejected #$id: $note");
        achRespond(true, 'Achievement returned for additional info.');
    }

    default:
        achRespond(false, 'Unknown action.');
}
$conn->close();
