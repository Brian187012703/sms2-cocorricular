<?php
// ============================================================
//  BUDGET_ACTIONS.PHP — Budget & Finance AJAX Handler
//  Workflow: Pending Adviser → Pending SSC → Pending Admin → Disbursed
//  Roles: club_adviser endorses, ssc reviews/edits, admin approves & disburses
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

function bdRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

switch ($action) {

    // ── 1. LIST Budget Requests ──────────────────────────────
    case 'list': {
        $where  = 'WHERE br.deleted_at IS NULL';
        $params = [];
        $types  = '';

        if ($user_role === 'student') {
            $where  .= " AND br.requested_by = ?";
            $params = [$user_id];
            $types  = 'i';
        } elseif ($user_role === 'club_adviser') {
            $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
            $cm->bind_param('i', $user_id);
            $cm->execute();
            $cm->bind_result($my_club_id);
            $cm->fetch();
            $cm->close();
            if (!empty($my_club_id)) {
                $where  .= " AND br.club_id = ?";
                $params = [(int)$my_club_id];
                $types  = 'i';
            }
        } elseif ($user_role === 'ssc') {
            $where .= " AND br.status IN ('Pending SSC','Pending Admin','Disbursed','Rejected')";
        }
        // admin sees all active (non-deleted) requests

        $sql = "SELECT br.id, br.club_id, br.title, br.description, br.amount, br.status, br.notes, br.created_at, br.updated_at,
                       c.name AS club_name, c.code AS club_code,
                       u.first_name, u.last_name, u.email
                FROM budget_requests br
                JOIN clubs c ON c.id = br.club_id
                JOIN users u ON u.id = br.requested_by
                $where
                ORDER BY br.created_at DESC";

        $stmt = $conn->prepare($sql);
        if ($types && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        bdRespond(true, 'Budget requests loaded.', ['requests' => $requests]);
    }

    // ── 2. CREATE Budget Request ─────────────────────────────
    case 'create': {
        if (!in_array($user_role, ['student', 'club_adviser', 'admin'])) {
            bdRespond(false, 'You do not have permission to submit budget requests.');
        }

        $club_id     = (int)($_POST['club_id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $amount      = (float)($_POST['amount'] ?? 0);

        if (!$club_id || empty($title) || $amount <= 0) {
            bdRespond(false, 'Please provide a valid club, title, and positive amount.');
        }

        $stmt = $conn->prepare(
            "INSERT INTO budget_requests (club_id, title, description, amount, status, requested_by, notes)
             VALUES (?, ?, ?, ?, 'Pending Adviser', ?, 'Submitted — awaiting Adviser endorsement.')"
        );
        $stmt->bind_param('issdi', $club_id, $title, $description, $amount, $user_id);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $stmt->close();

            // Notify Club Advisers of new request
            $advisers = $conn->query(
                "SELECT cm.user_id FROM club_memberships cm
                 JOIN users u ON u.id=cm.user_id
                 WHERE cm.club_id=$club_id AND cm.status='Active' AND u.role='club_adviser'"
            );
            if ($advisers) {
                while ($adv = $advisers->fetch_assoc()) {
                    push_notification($conn, (int)$adv['user_id'], 'New Budget Request',
                        "A new budget request '$title' (₱" . number_format($amount, 2) . ") needs your endorsement.", 'info');
                }
            }
            bdRespond(true, 'Budget request submitted successfully!', ['id' => $new_id]);
        } else {
            bdRespond(false, 'Failed to submit budget request: ' . $conn->error);
        }
    }

    // ── 3. APPROVE/FORWARD Budget Request ───────────────────
    // Stage 1: club_adviser  → Pending Adviser → Pending SSC
    // Stage 2: ssc           → Pending SSC     → Pending Admin
    // Stage 3: admin         → Pending Admin   → Disbursed
    case 'approve': {
        $id    = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if (!$id) bdRespond(false, 'Invalid budget request ID.');

        $stmt = $conn->prepare("SELECT br.*, c.name AS club_name FROM budget_requests br JOIN clubs c ON c.id=br.club_id WHERE br.id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$req) bdRespond(false, 'Budget request not found.');

        $current_status = $req['status'];
        $next_status    = '';
        $note_append    = '';

        if ($current_status === 'Pending Adviser') {
            if (!in_array($user_role, ['club_adviser', 'admin'])) {
                bdRespond(false, 'Only Club Advisers can endorse budget requests at this stage.');
            }
            $next_status = 'Pending SSC';
            $note_append = "Endorsed by Club Adviser" . ($notes ? ": $notes" : ".");

            // Notify SSC officers
            $sscs = $conn->query("SELECT id FROM users WHERE role='ssc'");
            while ($s = $sscs->fetch_assoc()) {
                push_notification($conn, (int)$s['id'], 'Budget Endorsed',
                    "Budget request '{$req['title']}' has been endorsed by adviser and needs SSC review.", 'info');
            }

        } elseif ($current_status === 'Pending SSC') {
            if (!in_array($user_role, ['ssc', 'admin'])) {
                bdRespond(false, 'Only SSC Officers can forward budget requests at this stage.');
            }
            $next_status = 'Pending Admin';
            $note_append = "Reviewed & forwarded by SSC" . ($notes ? ": $notes" : ".");

            // Notify Admins
            $admins = $conn->query("SELECT id FROM users WHERE role='admin'");
            while ($a = $admins->fetch_assoc()) {
                push_notification($conn, (int)$a['id'], 'Budget Needs Approval',
                    "Budget request '{$req['title']}' has been reviewed by SSC and needs Admin disbursement.", 'info');
            }

        } elseif ($current_status === 'Pending Admin') {
            if (!in_array($user_role, ['admin'])) {
                bdRespond(false, 'Only System Admins can approve and disburse budget requests at this stage.');
            }
            $next_status = 'Disbursed';
            $note_append = "Approved & Disbursed by System Admin" . ($notes ? ": $notes" : ".");

        } else {
            bdRespond(false, "Budget request is in '$current_status' state and cannot be advanced further.");
        }

        $upd = $conn->prepare("UPDATE budget_requests SET status=?, notes=? WHERE id=?");
        $upd->bind_param('ssi', $next_status, $note_append, $id);
        if ($upd->execute()) {
            $upd->close();
            push_notification($conn, (int)$req['requested_by'], 'Budget Request Updated',
                "Your request '{$req['title']}' is now: $next_status.", 'success');
            log_audit($conn, $user_id, "budget_$next_status", 'budget_requests', $id,
                "Budget #$id moved to $next_status by $user_role");
            bdRespond(true, "Budget request forwarded to '$next_status'.", ['new_status' => $next_status]);
        } else {
            bdRespond(false, 'Database update failed: ' . $conn->error);
        }
    }

    // ── 4. SSC EDIT budget request (notes/description) ──────
    case 'ssc_edit': {
        if (!in_array($user_role, ['ssc', 'admin'])) {
            bdRespond(false, 'Not authorized.');
        }
        $id          = (int)($_POST['id'] ?? 0);
        $notes       = trim($_POST['notes'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if (!$id) bdRespond(false, 'Invalid request ID.');

        $upd = $conn->prepare("UPDATE budget_requests SET notes=?, description=? WHERE id=? AND status='Pending SSC'");
        $upd->bind_param('ssi', $notes, $description, $id);
        if ($upd->execute()) {
            $upd->close();
            bdRespond(true, 'Budget request details updated by SSC.');
        } else {
            bdRespond(false, 'Failed to update: ' . $conn->error);
        }
    }

    // ── 5. REJECT Budget Request ─────────────────────────────
    case 'reject': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'admin'])) {
            bdRespond(false, 'Not authorized to reject budget requests.');
        }

        $id     = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? $_POST['notes'] ?? 'No specific reason provided.');

        if (!$id) bdRespond(false, 'Invalid budget request ID.');

        $upd_note = "Rejected by " . ucwords(str_replace('_', ' ', $user_role)) . ": " . $reason;
        $upd = $conn->prepare("UPDATE budget_requests SET status='Rejected', notes=? WHERE id=?");
        $upd->bind_param('si', $upd_note, $id);

        if ($upd->execute()) {
            $upd->close();
            $req = $conn->query("SELECT requested_by, title FROM budget_requests WHERE id=$id")->fetch_assoc();
            if ($req) {
                push_notification($conn, (int)$req['requested_by'], 'Budget Request Rejected',
                    "Your request '{$req['title']}' was rejected. Reason: $reason", 'danger');
            }
            log_audit($conn, $user_id, 'budget_rejected', 'budget_requests', $id, "Rejected by $user_role: $reason");
            bdRespond(true, 'Budget request rejected.');
        } else {
            bdRespond(false, 'Database update failed: ' . $conn->error);
        }
    }

    default:
        bdRespond(false, 'Invalid action specified.');
}
