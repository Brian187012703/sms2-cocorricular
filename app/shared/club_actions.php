<?php
// ============================================================
//  CLUB_ACTIONS.PHP — Organization & Charters AJAX Handler
//  Actions: list, update_status, update_adviser, create_club
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

function cRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

switch ($action) {

    // ── LIST all clubs with statistics ────────────────────────
    case 'list': {
        $sql = "SELECT c.*, 
                       COUNT(DISTINCT cm.user_id) AS member_count,
                       COUNT(DISTINCT e.id) AS event_count
                FROM clubs c
                LEFT JOIN club_memberships cm ON (cm.club_id = c.id AND cm.status = 'Active')
                LEFT JOIN events e ON (e.club_id = c.id AND e.status IN ('Approved','Upcoming'))
                GROUP BY c.id
                ORDER BY c.category, c.name";
        $res = $conn->query($sql);
        $clubs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        cRespond(true, 'OK', ['clubs' => $clubs]);
    }

    // ── UPDATE CLUB STATUS (SSC / Admin only) ─────────────────
    case 'update_status': {
        if (!in_array($user_role, ['ssc', 'admin'])) {
            cRespond(false, 'Only SSC Officers and Administrators can modify charter status.');
        }

        $club_id = (int)($_POST['club_id'] ?? 0);
        $status  = trim($_POST['status'] ?? '');
        $valid_statuses = ['Active', 'Pending Charter', 'Suspended'];

        if ($club_id <= 0 || !in_array($status, $valid_statuses)) {
            cRespond(false, 'Invalid club ID or status specified.');
        }

        $stmt = $conn->prepare("UPDATE clubs SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $club_id);
        if (!$stmt->execute()) {
            cRespond(false, 'Failed to update club status: ' . $stmt->error);
        }
        $stmt->close();

        $c = $conn->query("SELECT name, code FROM clubs WHERE id = $club_id")->fetch_assoc();
        log_audit($conn, $user_id, 'club_status_update', 'clubs', $club_id, "Updated {$c['code']} charter status to $status");
        cRespond(true, "Charter status for \"{$c['name']}\" updated to \"$status\"!");
    }

    // ── UPDATE ADVISER ASSIGNMENT (SSC / Admin only) ──────────
    case 'update_adviser': {
        if (!in_array($user_role, ['ssc', 'admin'])) {
            cRespond(false, 'Not authorized.');
        }

        $club_id      = (int)($_POST['club_id'] ?? 0);
        $adviser_name = trim($_POST['adviser_name'] ?? '');

        if ($club_id <= 0 || empty($adviser_name)) {
            cRespond(false, 'Club and Adviser name are required.');
        }

        $stmt = $conn->prepare("UPDATE clubs SET adviser_name = ? WHERE id = ?");
        $stmt->bind_param('si', $adviser_name, $club_id);
        if (!$stmt->execute()) {
            cRespond(false, 'Failed to update adviser: ' . $stmt->error);
        }
        $stmt->close();

        log_audit($conn, $user_id, 'club_adviser_update', 'clubs', $club_id, "Assigned $adviser_name to club #$club_id");
        cRespond(true, "Faculty adviser assigned successfully!");
    }

    // ── CHARTER NEW ORGANIZATION (SSC / Admin) ────────────────
    case 'create_club': {
        if (!in_array($user_role, ['ssc', 'admin'])) {
            cRespond(false, 'Only SSC and Admin can charter organizations.');
        }

        $code         = strtoupper(trim($_POST['code'] ?? ''));
        $name         = trim($_POST['name'] ?? '');
        $category     = trim($_POST['category'] ?? 'Academic');
        $description  = trim($_POST['description'] ?? '');
        $adviser_name = trim($_POST['adviser_name'] ?? 'Unassigned');

        $valid_categories = ['Academic', 'Cultural', 'Sports', 'Advocacy', 'Religious'];
        if (!$code || !$name || !in_array($category, $valid_categories)) {
            cRespond(false, 'Please specify valid code, name, and category.');
        }

        // Check if code exists
        $chk = $conn->prepare("SELECT id FROM clubs WHERE code = ? LIMIT 1");
        $chk->bind_param('s', $code);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            cRespond(false, "Organization code \"$code\" is already taken.");
        }
        $chk->close();

        $stmt = $conn->prepare("INSERT INTO clubs (code, name, category, description, adviser_name, status) VALUES (?, ?, ?, ?, ?, 'Active')");
        $stmt->bind_param('sssss', $code, $name, $category, $description, $adviser_name);
        if (!$stmt->execute()) {
            cRespond(false, 'Failed to create organization: ' . $stmt->error);
        }
        $new_cid = $stmt->insert_id;
        $stmt->close();

        log_audit($conn, $user_id, 'club_create', 'clubs', $new_cid, "Chartered new organization: $name ($code)");
        cRespond(true, "Organization \"$name\" ($code) chartered successfully!", ['club_id' => $new_cid]);
    }

    default:
        cRespond(false, 'Unknown action.');
}
$conn->close();
