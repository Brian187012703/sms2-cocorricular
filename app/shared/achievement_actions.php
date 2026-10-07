<?php
// ============================================================
//  ACHIEVEMENT_ACTIONS.PHP — Achievements AJAX handler
//  Workflow:
//    Stage 1: Club Adviser submits -> Status: 'Pending SSC'
//    Stage 2: SSC Officers verify   -> Status: 'Pending Admin'
//    Stage 3: Admin clears/approves -> Status: 'Approved' (Published to Directory)
// ============================================================
header('Content-Type: application/json');
session_start();
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
            $where  = 'WHERE a.status IN ("Approved", "Verified")';
        } elseif ($user_role === 'club_adviser') {
            // Adviser sees their club's achievements
            $sess_user = $_SESSION['username'] ?? '';
            $cm = $conn->prepare("SELECT id FROM clubs WHERE (id IN (SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active') OR code=UPPER(SUBSTRING_INDEX(?, '.', 1)) OR adviser_user_id=?) AND status='Active' LIMIT 1");
            $cm->bind_param('isi', $user_id, $sess_user, $user_id);
            $cm->execute();
            $cm->bind_result($club_id);
            $cm->fetch();
            $cm->close();
            if (!empty($club_id)) { $where = "WHERE a.club_id = ?"; $params = [(int)$club_id]; $types = 'i'; }
            else { $where = "WHERE 1=0"; }
        }

        $sql = "SELECT a.id, a.title, a.competition, a.award_date, a.status, a.notes, a.proof_file, a.created_at,
                       c.name AS club_name, c.code AS club_code,
                       u.first_name, u.last_name, u.role AS sub_role,
                       v.first_name AS ver_first, v.last_name AS ver_last, v.role AS ver_role,
                       ap.first_name AS app_first, ap.last_name AS app_last, ap.role AS app_role
                FROM achievements a
                JOIN clubs c ON c.id = a.club_id
                JOIN users u ON u.id = a.submitted_by
                LEFT JOIN users v ON v.id = a.verified_by
                LEFT JOIN users ap ON ap.id = a.approved_by
                $where
                ORDER BY a.award_date DESC";
        $stmt = $conn->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        achRespond(true, 'OK', ['achievements' => $rows]);
    }

    // ── LIST pending for SSC/Admin ────────────────────────────
    case 'list_pending': {
        if (!in_array($user_role, ['ssc','admin'])) achRespond(false, 'Not authorized.');
        
        $status_filter = ($user_role === 'ssc') 
            ? "WHERE a.status IN ('Pending SSC', 'Pending')"
            : "WHERE a.status IN ('Pending Admin', 'Pending SSC', 'Pending')";

        $rows = $conn->query(
            "SELECT a.id, a.title, a.competition, a.award_date, a.proof_file, a.status, a.created_at,
                    c.name AS club_name, c.code AS club_code, u.first_name, u.last_name
             FROM achievements a
             JOIN clubs c ON c.id = a.club_id
             JOIN users u ON u.id = a.submitted_by
             $status_filter
             ORDER BY a.created_at ASC"
        )->fetch_all(MYSQLI_ASSOC);
        achRespond(true, 'OK', ['pending' => $rows]);
    }

    // ── SUBMIT achievement (Club Adviser role) ────────────────
    case 'submit': {
        if (!in_array($user_role, ['club_adviser', 'admin'])) {
            achRespond(false, 'Only Club Advisers can submit organization achievements for verification.');
        }

        $title       = trim($_POST['title']       ?? '');
        $competition = trim($_POST['competition'] ?? '');
        $award_date  = trim($_POST['award_date']  ?? '');

        if (!$title || !$competition || !$award_date) {
            achRespond(false, 'Title, competition, and award date are required.');
        }

        // Get club_id — prefer posted value, fall back to adviser club
        $club_id = (int)($_POST['club_id'] ?? 0);
        if (!$club_id) {
            $sess_user = $_SESSION['username'] ?? '';
            $cm = $conn->prepare("SELECT id FROM clubs WHERE (id IN (SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active') OR code=UPPER(SUBSTRING_INDEX(?, '.', 1)) OR adviser_user_id=?) AND status='Active' LIMIT 1");
            $cm->bind_param('isi', $user_id, $sess_user, $user_id);
            $cm->execute();
            $cm->bind_result($club_id);
            $cm->fetch();
            $cm->close();
        }

        if ($user_role === 'club_adviser') {
            // Verify that this adviser handles the requested club
            $sess_user = $_SESSION['username'] ?? '';
            $chk = $conn->prepare("SELECT id FROM clubs WHERE id=? AND (id IN (SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active') OR code=UPPER(SUBSTRING_INDEX(?, '.', 1)) OR adviser_user_id=?) AND status='Active' LIMIT 1");
            $chk->bind_param('iisi', $club_id, $user_id, $sess_user, $user_id);
            $chk->execute();
            $chk->bind_result($valid_cid);
            $chk->fetch();
            $chk->close();
            if (!$valid_cid) {
                achRespond(false, 'Forbidden: You can only submit achievements for your assigned student organization.');
            }
        }

        if (!$club_id) achRespond(false, 'You must select a valid active organization.');

        // Handle file upload
        $proof_file = null;
        if (!empty($_FILES['proof_file']['name']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
            $uploads_dir = __DIR__ . '/../uploads/achievements/';
            if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);
            $ext  = strtolower(pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg','jpeg','png','pdf','webp'];
            if (!in_array($ext, $allowed_exts, true)) achRespond(false, 'Invalid file type. Allowed: JPG, PNG, PDF, WEBP.');
            if ($_FILES['proof_file']['size'] > 5 * 1024 * 1024) achRespond(false, 'File too large (max 5MB).');

            $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
            $mime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['proof_file']['tmp_name']);
                finfo_close($finfo);
            } elseif (function_exists('mime_content_type')) {
                $mime = mime_content_type($_FILES['proof_file']['tmp_name']);
            }
            if (!empty($mime) && !in_array($mime, $allowed_mimes, true)) {
                achRespond(false, 'Invalid file content: only genuine images and PDFs are accepted.');
            }

            $fname = 'ach_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['proof_file']['tmp_name'], $uploads_dir . $fname))
                achRespond(false, 'File upload failed.');
            $proof_file = $fname;
        }

        $notes = trim($_POST['notes'] ?? '');

        // Stage 1 initial status: 'Pending SSC'
        $stmt = $conn->prepare(
            "INSERT INTO achievements (club_id, submitted_by, title, competition, award_date, proof_file, notes, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending SSC')"
        );
        $stmt->bind_param('iisssss', $club_id, $user_id, $title, $competition, $award_date, $proof_file, $notes);
        if (!$stmt->execute()) achRespond(false, 'Failed to submit: ' . $stmt->error);
        $new_id = $conn->insert_id;
        $stmt->close();

        // Notify SSC officers for Stage 2 verification
        $adv_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        $sscs = $conn->query("SELECT id FROM users WHERE role = 'ssc'");
        while ($o = $sscs->fetch_assoc()) {
            push_notification($conn, (int)$o['id'], 'New Achievement Submission',
                "Club Adviser $adv_name submitted \"$title\" for SSC verification.", 'achievement');
        }
        log_audit($conn, $user_id, 'achievement_submit', 'achievements', $new_id, "Submitted: $title (Pending SSC)");
        achRespond(true, 'Achievement submitted and forwarded to SSC for verification.', ['id' => $new_id]);
    }

    // ── STAGE 2: VERIFY achievement (SSC Officers) ─────────────
    case 'verify_ssc':
    case 'verify': {
        if (!in_array($user_role, ['ssc','admin'])) {
            achRespond(false, 'Only SSC Officers can verify achievements.');
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) achRespond(false, 'Invalid achievement ID.');

        $note = trim($_POST['notes'] ?? 'Verified and endorsed by SSC.');

        // SSC verification moves status to 'Pending Admin'
        $stmt = $conn->prepare("UPDATE achievements SET status='Pending Admin', verified_by=?, notes=? WHERE id=?");
        $stmt->bind_param('isi', $user_id, $note, $id);
        if (!$stmt->execute()) achRespond(false, 'Failed to verify achievement.');
        $stmt->close();

        $ach = $conn->query("SELECT submitted_by, title, club_id FROM achievements WHERE id=$id")->fetch_assoc();
        if ($ach) {
            // Notify submitter (Club Adviser)
            push_notification($conn, (int)$ach['submitted_by'], 'Achievement Verified by SSC 📋',
                "Your achievement \"{$ach['title']}\" has been verified by the SSC and forwarded to the Admin for final approval.", 'info');

            // Notify Admin for Stage 3 Approval
            $admins = $conn->query("SELECT id FROM users WHERE role = 'admin'");
            while ($adm = $admins->fetch_assoc()) {
                push_notification($conn, (int)$adm['id'], 'Achievement Awaiting Admin Approval',
                    "Achievement \"{$ach['title']}\" was verified by SSC and is now awaiting final Admin approval.", 'info');
            }
        }
        log_audit($conn, $user_id, 'achievement_verify_ssc', 'achievements', $id, "Verified by SSC #$id -> Pending Admin");
        achRespond(true, 'Achievement verified by SSC and forwarded to Admin for final approval.');
    }

    // ── STAGE 3: APPROVE achievement (Admin Final Clearance) ──
    case 'approve_admin':
    case 'approve': {
        if ($user_role !== 'admin') {
            achRespond(false, 'Only Administrators can approve and publish achievements to the directory.');
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) achRespond(false, 'Invalid achievement ID.');

        $note = trim($_POST['notes'] ?? 'Approved and cleared by Institutional Admin.');

        // Admin clearance moves status to 'Approved'
        $stmt = $conn->prepare("UPDATE achievements SET status='Approved', approved_by=?, notes=? WHERE id=?");
        $stmt->bind_param('isi', $user_id, $note, $id);
        if (!$stmt->execute()) achRespond(false, 'Failed to approve achievement.');
        $stmt->close();

        $ach = $conn->query("SELECT a.submitted_by, a.title, a.club_id, c.name AS club_name FROM achievements a JOIN clubs c ON c.id=a.club_id WHERE a.id=$id")->fetch_assoc();
        if ($ach) {
            // Notify submitter (Club Adviser)
            push_notification($conn, (int)$ach['submitted_by'], 'Achievement Approved & Published! 🏆',
                "Congratulations! \"{$ach['title']}\" has been officially approved by the Admin and posted to the Student Organizations Directory.", 'success');

            // Sync to isolated organization database if applicable
            require_once __DIR__ . '/org_db_manager.php';
            if (function_exists('syncOrgAchievements')) {
                syncOrgAchievements($conn, (int)$ach['club_id']);
            }
        }
        log_audit($conn, $user_id, 'achievement_approve_admin', 'achievements', $id, "Approved by Admin #$id -> Published to Directory");
        achRespond(true, 'Achievement officially approved and posted to the Student Organization Directory!');
    }

    // ── REJECT achievement (SSC / Admin) ─────────────────────
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
            push_notification($conn, (int)$ach['submitted_by'], 'Achievement Info Requested / Rejected',
                "Your achievement submission \"{$ach['title']}\" was rejected or needs revision. Remarks: $note", 'warning');
        }
        log_audit($conn, $user_id, 'achievement_reject', 'achievements', $id, "Rejected #$id: $note");
        achRespond(true, 'Achievement returned / rejected.');
    }

    // ── CLARIFY achievement (SSC / Admin) ────────────────────
    case 'clarify': {
        if (!in_array($user_role, ['ssc','admin'])) achRespond(false, 'Not authorized.');
        $id   = (int)($_POST['id']    ?? 0);
        $note = trim($_POST['notes']  ?? '');
        if ($id <= 0) achRespond(false, 'Invalid achievement ID.');
        if (empty($note)) achRespond(false, 'Please provide clarification instructions or remarks.');

        $stmt = $conn->prepare("UPDATE achievements SET notes=?, verified_by=? WHERE id=?");
        $stmt->bind_param('sii', $note, $user_id, $id);
        if (!$stmt->execute()) achRespond(false, 'Failed to update clarification notes.');
        $stmt->close();

        $ach = $conn->query("SELECT submitted_by, title FROM achievements WHERE id=$id")->fetch_assoc();
        if ($ach) {
            push_notification($conn, (int)$ach['submitted_by'], 'Achievement Clarification Requested',
                "Reviewers requested clarification on \"{$ach['title']}\": $note", 'warning');
        }
        log_audit($conn, $user_id, 'achievement_clarify', 'achievements', $id, "Clarify #$id: $note");
        achRespond(true, 'Clarification request saved and sent to adviser.');
    }

    // ── GET DETAILS (View Proof / Modal) ──────────────────────
    case 'get_details': {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) achRespond(false, 'Invalid achievement ID.');

        $stmt = $conn->prepare(
            "SELECT a.id, a.title, a.competition, a.award_date, a.proof_file, a.status, a.notes, a.created_at,
                    c.name AS club_name, c.code AS club_code,
                    u.first_name AS sub_first, u.last_name AS sub_last, u.role AS sub_role,
                    v.first_name AS ver_first, v.last_name AS ver_last, v.role AS ver_role,
                    ap.first_name AS app_first, ap.last_name AS app_last, ap.role AS app_role
             FROM achievements a
             JOIN clubs c ON c.id = a.club_id
             JOIN users u ON u.id = a.submitted_by
             LEFT JOIN users v ON v.id = a.verified_by
             LEFT JOIN users ap ON ap.id = a.approved_by
             WHERE a.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $ach = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$ach) achRespond(false, 'Achievement not found.');
        achRespond(true, 'OK', ['achievement' => $ach]);
    }

    default:
        achRespond(false, 'Unknown action.');
}
$conn->close();
