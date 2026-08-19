<?php
// ============================================================
//  ANNOUNCEMENT_ACTIONS.PHP — Organization Announcements Backend
//  Actions: list, create, delete
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

function annRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

// ── Ensure org_announcements table exists ─────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS org_announcements (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id      INT UNSIGNED NOT NULL,
    author_id    INT UNSIGNED NOT NULL,
    title        VARCHAR(250) NOT NULL,
    category     ENUM('Event','Activity','Requirement / Submission','Meeting','General') NOT NULL DEFAULT 'General',
    priority     ENUM('Normal','Important','Urgent') NOT NULL DEFAULT 'Normal',
    content      TEXT NOT NULL,
    target_group VARCHAR(100) DEFAULT 'All Members',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_club (club_id),
    KEY idx_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

switch ($action) {

    // ── 1. LIST ANNOUNCEMENTS ──────────────────────────────────
    case 'list': {
        $club_id = (int)($_GET['club_id'] ?? 0);

        if (!$club_id && $user_role === 'club_adviser') {
            $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
            $cm->bind_param('i', $user_id);
            $cm->execute();
            $cm->bind_result($club_id);
            $cm->fetch();
            $cm->close();
        }

        if (!$club_id) {
            $r_first = $conn->query("SELECT id FROM clubs WHERE status='Active' LIMIT 1");
            if ($r_first && $row = $r_first->fetch_assoc()) {
                $club_id = (int)$row['id'];
            }
        }

        $sql = "SELECT a.id, a.club_id, a.author_id, a.title, a.category, a.priority, a.content, a.target_group, a.created_at,
                       c.name AS club_name, c.code AS club_code,
                       u.first_name, u.last_name
                FROM org_announcements a
                JOIN clubs c ON c.id = a.club_id
                JOIN users u ON u.id = a.author_id
                WHERE a.club_id = ?
                ORDER BY a.created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $club_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        annRespond(true, 'Announcements loaded.', ['announcements' => $items, 'club_id' => $club_id]);
    }

    // ── 2. CREATE ANNOUNCEMENT ─────────────────────────────────
    case 'create': {
        if (!in_array($user_role, ['club_adviser', 'admin', 'ssc'])) {
            annRespond(false, 'Permission denied: Only Club Advisers and SSC Officers can post announcements.');
        }

        $club_id      = (int)($_POST['club_id'] ?? 0);
        $title        = trim($_POST['title'] ?? '');
        $category     = trim($_POST['category'] ?? 'General');
        $priority     = trim($_POST['priority'] ?? 'Normal');
        $content      = trim($_POST['content'] ?? '');
        $target_group = trim($_POST['target_group'] ?? 'All Members');

        if (!$club_id) {
            $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
            $cm->bind_param('i', $user_id);
            $cm->execute();
            $cm->bind_result($club_id);
            $cm->fetch();
            $cm->close();
        }

        if (!$club_id || empty($title) || empty($content)) {
            annRespond(false, 'Please provide an announcement title, category, and message content.');
        }

        $allowed_cats = ['Event', 'Activity', 'Requirement / Submission', 'Meeting', 'General'];
        if (!in_array($category, $allowed_cats)) $category = 'General';

        $allowed_prio = ['Normal', 'Important', 'Urgent'];
        if (!in_array($priority, $allowed_prio)) $priority = 'Normal';

        $stmt = $conn->prepare("
            INSERT INTO org_announcements (club_id, author_id, title, category, priority, content, target_group)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iisssss', $club_id, $user_id, $title, $category, $priority, $content, $target_group);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $stmt->close();

            // Push notification to active club members
            $mems = $conn->query("SELECT user_id FROM club_memberships WHERE club_id = $club_id AND status = 'Active'");
            if ($mems) {
                while ($m = $mems->fetch_assoc()) {
                    if ((int)$m['user_id'] !== $user_id) {
                        push_notification($conn, (int)$m['user_id'], "New Announcement: $title", substr($content, 0, 150), $priority === 'Urgent' ? 'warning' : 'info');
                    }
                }
            }

            annRespond(true, 'Announcement posted successfully!', ['id' => $new_id]);
        } else {
            annRespond(false, 'Database error: ' . $conn->error);
        }
    }

    // ── 3. DELETE ANNOUNCEMENT ─────────────────────────────────
    case 'delete': {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) annRespond(false, 'Invalid announcement ID.');

        $stmt = $conn->prepare("SELECT author_id FROM org_announcements WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) annRespond(false, 'Announcement not found.');

        if ($row['author_id'] !== $user_id && !in_array($user_role, ['admin', 'ssc'])) {
            annRespond(false, 'Permission denied: You can only delete announcements posted by you.');
        }

        $del = $conn->prepare("DELETE FROM org_announcements WHERE id = ?");
        $del->bind_param('i', $id);
        if ($del->execute()) {
            $del->close();
            annRespond(true, 'Announcement deleted successfully.');
        } else {
            annRespond(false, 'Failed to delete announcement.');
        }
    }

    default:
        annRespond(false, 'Invalid action specified.');
}
