<?php
// app/shared/chat_actions.php — Role-Governed Inter-Club Messaging API
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$userRole = $_SESSION['role'] ?? '';

if (!$userId || empty($userRole)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Helper: check if student belongs to club
function studentInClub(mysqli $conn, int $userId, int $clubId): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM club_memberships WHERE user_id = ? AND club_id = ? AND LOWER(status) IN ('active', 'approved')");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $userId, $clubId);
    $stmt->execute();
    $res = $stmt->get_result();
    $cnt = (int)$res->fetch_row()[0];
    $stmt->close();
    return $cnt > 0;
}

// Helper: check if adviser advises club
function adviserHandlesClub(mysqli $conn, int $userId, int $clubId): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM clubs c 
        WHERE c.id = ? 
          AND (c.adviser_user_id = ? 
               OR EXISTS (
                   SELECT 1 FROM club_memberships cm 
                   WHERE cm.club_id = c.id 
                     AND cm.user_id = ? 
                     AND cm.role IN ('Adviser', 'Club Adviser') 
                     AND LOWER(cm.status) IN ('active', 'approved')
               ))
          AND c.deleted_at IS NULL
    ");
    if (!$stmt) return false;
    $stmt->bind_param('iii', $clubId, $userId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $cnt = (int)$res->fetch_row()[0];
    $stmt->close();
    return $cnt > 0;
}

// Helper: verify user channel access
function canAccessChannel(mysqli $conn, int $userId, string $userRole, int $channelId): ?array {
    $stmt = $conn->prepare("SELECT c.* FROM chat_channels c WHERE c.id = ?");
    if (!$stmt) return null;
    $stmt->bind_param('i', $channelId);
    $stmt->execute();
    $res = $stmt->get_result();
    $channel = $res->fetch_assoc();
    $stmt->close();

    if (!$channel) return null;

    if ($userRole === 'admin') {
        return $channel;
    }

    if ($channel['type'] === 'club_group') {
        $clubId = (int)$channel['club_id'];
        if ($userRole === 'student') {
            return studentInClub($conn, $userId, $clubId) ? $channel : null;
        }
        if ($userRole === 'club_adviser') {
            return adviserHandlesClub($conn, $userId, $clubId) ? $channel : null;
        }
        return null;
    }

    if ($channel['type'] === 'adviser_ssc') {
        if ($userRole === 'student') {
            return null; // Students strictly barred from Adviser-SSC desk
        }
        if ($userRole === 'ssc') {
            return $channel; // SSC has campus-wide governance access
        }
        if ($userRole === 'club_adviser') {
            $clubId = (int)$channel['club_id'];
            return adviserHandlesClub($conn, $userId, $clubId) ? $channel : null;
        }
        return null;
    }

    if ($channel['type'] === 'direct') {
        // Must be in chat_members
        $stmt2 = $conn->prepare("SELECT COUNT(*) FROM chat_members WHERE channel_id = ? AND user_id = ?");
        if (!$stmt2) return null;
        $stmt2->bind_param('ii', $channelId, $userId);
        $stmt2->execute();
        $cnt = (int)$stmt2->get_result()->fetch_row()[0];
        $stmt2->close();
        if ($cnt <= 0) return null;

        // Restriction: adviser, ssc, and student roles must not be able to message or access direct chats with admin
        if (in_array($userRole, ['club_adviser', 'ssc', 'student'])) {
            $hasAdmin = $conn->query("
                SELECT COUNT(*) FROM chat_members cm 
                JOIN users u ON u.id = cm.user_id 
                WHERE cm.channel_id = {$channelId} AND u.role = 'admin'
            ")->fetch_row()[0];
            if ((int)$hasAdmin > 0) {
                return null;
            }
        }

        return $channel;
    }

    return null;
}

// ── ACTION: GET_CHANNELS ──
if ($action === 'get_channels') {
    $channels = [];

    // Query channels by role
    if ($userRole === 'student') {
        // Active club group rooms + direct chats (excluding admin)
        $sql = "
            SELECT c.*, cl.code as club_code, cl.name as club_name,
                   cm.last_read_at,
                   (SELECT COUNT(*) FROM chat_messages m WHERE m.channel_id = c.id AND (cm.last_read_at IS NULL OR m.created_at > cm.last_read_at) AND m.sender_id != {$userId}) as unread_count,
                   (SELECT m.message FROM chat_messages m WHERE m.channel_id = c.id ORDER BY m.id DESC LIMIT 1) as last_message,
                   (SELECT m.created_at FROM chat_messages m WHERE m.channel_id = c.id ORDER BY m.id DESC LIMIT 1) as last_message_time
            FROM chat_channels c
            LEFT JOIN clubs cl ON cl.id = c.club_id
            LEFT JOIN chat_members cm ON cm.channel_id = c.id AND cm.user_id = {$userId}
            WHERE (
                (c.type = 'club_group' AND c.club_id IN (
                    SELECT club_id FROM club_memberships WHERE user_id = {$userId} AND LOWER(status) IN ('active', 'approved')
                ))
                OR (c.type = 'direct' AND EXISTS (
                    SELECT 1 FROM chat_members m WHERE m.channel_id = c.id AND m.user_id = {$userId}
                ) AND NOT EXISTS (
                    SELECT 1 FROM chat_members m2 JOIN users u2 ON u2.id = m2.user_id WHERE m2.channel_id = c.id AND u2.role = 'admin'
                ))
            )
            ORDER BY COALESCE(last_message_time, c.updated_at) DESC
        ";
    } elseif ($userRole === 'club_adviser') {
        // Handled club rooms + Adviser & SSC desks + direct chats (excluding admin)
        $sql = "
            SELECT c.*, cl.code as club_code, cl.name as club_name,
                   cm.last_read_at,
                   (SELECT COUNT(*) FROM chat_messages m WHERE m.channel_id = c.id AND (cm.last_read_at IS NULL OR m.created_at > cm.last_read_at) AND m.sender_id != {$userId}) as unread_count,
                   (SELECT m.message FROM chat_messages m WHERE m.channel_id = c.id ORDER BY m.id DESC LIMIT 1) as last_message,
                   (SELECT m.created_at FROM chat_messages m WHERE m.channel_id = c.id ORDER BY m.id DESC LIMIT 1) as last_message_time
            FROM chat_channels c
            LEFT JOIN clubs cl ON cl.id = c.club_id
            LEFT JOIN chat_members cm ON cm.channel_id = c.id AND cm.user_id = {$userId}
            WHERE (
                (c.type IN ('club_group', 'adviser_ssc') AND c.club_id IN (
                    SELECT id FROM clubs WHERE (adviser_user_id = {$userId} OR id IN (
                        SELECT club_id FROM club_memberships WHERE user_id = {$userId} AND role IN ('Adviser', 'Club Adviser') AND LOWER(status) IN ('active', 'approved')
                    )) AND deleted_at IS NULL
                ))
                OR (c.type = 'direct' AND EXISTS (
                    SELECT 1 FROM chat_members m WHERE m.channel_id = c.id AND m.user_id = {$userId}
                ) AND NOT EXISTS (
                    SELECT 1 FROM chat_members m2 JOIN users u2 ON u2.id = m2.user_id WHERE m2.channel_id = c.id AND u2.role = 'admin'
                ))
            )
            ORDER BY COALESCE(last_message_time, c.updated_at) DESC
        ";
    } elseif ($userRole === 'ssc') {
        // All Adviser-SSC desks + direct chats (excluding admin)
        $sql = "
            SELECT c.*, cl.code as club_code, cl.name as club_name,
                   cm.last_read_at,
                   (SELECT COUNT(*) FROM chat_messages m WHERE m.channel_id = c.id AND (cm.last_read_at IS NULL OR m.created_at > cm.last_read_at) AND m.sender_id != {$userId}) as unread_count,
                   (SELECT m.message FROM chat_messages m WHERE m.channel_id = c.id ORDER BY m.id DESC LIMIT 1) as last_message,
                   (SELECT m.created_at FROM chat_messages m WHERE m.channel_id = c.id ORDER BY m.id DESC LIMIT 1) as last_message_time
            FROM chat_channels c
            LEFT JOIN clubs cl ON cl.id = c.club_id
            LEFT JOIN chat_members cm ON cm.channel_id = c.id AND cm.user_id = {$userId}
            WHERE (
                c.type = 'adviser_ssc'
                OR (c.type = 'direct' AND EXISTS (
                    SELECT 1 FROM chat_members m WHERE m.channel_id = c.id AND m.user_id = {$userId}
                ) AND NOT EXISTS (
                    SELECT 1 FROM chat_members m2 JOIN users u2 ON u2.id = m2.user_id WHERE m2.channel_id = c.id AND u2.role = 'admin'
                ))
            )
            ORDER BY COALESCE(last_message_time, c.updated_at) DESC
        ";
    } else {
        // Admin: All channels
        $sql = "
            SELECT c.*, cl.code as club_code, cl.name as club_name,
                   cm.last_read_at,
                   (SELECT COUNT(*) FROM chat_messages m WHERE m.channel_id = c.id AND (cm.last_read_at IS NULL OR m.created_at > cm.last_read_at) AND m.sender_id != {$userId}) as unread_count,
                   (SELECT m.message FROM chat_messages m WHERE m.channel_id = c.id ORDER BY m.id DESC LIMIT 1) as last_message,
                   (SELECT m.created_at FROM chat_messages m WHERE m.channel_id = c.id ORDER BY m.id DESC LIMIT 1) as last_message_time
            FROM chat_channels c
            LEFT JOIN clubs cl ON cl.id = c.club_id
            LEFT JOIN chat_members cm ON cm.channel_id = c.id AND cm.user_id = {$userId}
            ORDER BY COALESCE(last_message_time, c.updated_at) DESC
        ";
    }

    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            // For direct chat, format recipient name
            if ($row['type'] === 'direct') {
                $cId = (int)$row['id'];
                $otherRes = $conn->query("
                    SELECT u.id, u.first_name, u.last_name, u.role, u.profile_pic
                    FROM chat_members cm
                    JOIN users u ON u.id = cm.user_id
                    WHERE cm.channel_id = {$cId} AND cm.user_id != {$userId}
                    LIMIT 1
                ");
                if ($otherRes && $other = $otherRes->fetch_assoc()) {
                    $row['name'] = trim($other['first_name'] . ' ' . $other['last_name']);
                    $row['direct_role'] = $other['role'];
                    $row['direct_pic'] = $other['profile_pic'] ?? null;
                }
            }
            $row['unread_count'] = (int)($row['unread_count'] ?? 0);
            $channels[] = $row;
        }
    }

    echo json_encode(['success' => true, 'channels' => $channels]);
    exit;
}

// ── ACTION: GET_MESSAGES ──
if ($action === 'get_messages') {
    $channelId = isset($_GET['channel_id']) ? (int)$_GET['channel_id'] : (int)($_POST['channel_id'] ?? 0);
    $channel = canAccessChannel($conn, $userId, $userRole, $channelId);

    if (!$channel) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied to this channel.']);
        exit;
    }

    // Mark as read for this user (only insert if group channel, or already member of direct channel)
    if ($channel['type'] !== 'direct' || $conn->query("SELECT 1 FROM chat_members WHERE channel_id = {$channelId} AND user_id = {$userId} LIMIT 1")->num_rows > 0) {
        $conn->query("
            INSERT INTO chat_members (channel_id, user_id, last_read_at)
            VALUES ({$channelId}, {$userId}, NOW())
            ON DUPLICATE KEY UPDATE last_read_at = NOW()
        ");
    }

    $stmt = $conn->prepare("
        SELECT m.id, m.channel_id, m.sender_id, m.message, m.created_at,
               u.first_name, u.last_name, u.role, u.username, u.profile_pic
        FROM chat_messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.channel_id = ?
        ORDER BY m.id ASC
        LIMIT 200
    ");
    $stmt->bind_param('i', $channelId);
    $stmt->execute();
    $res = $stmt->get_result();
    $messages = [];
    while ($m = $res->fetch_assoc()) {
        $messages[] = [
            'id' => (int)$m['id'],
            'sender_id' => (int)$m['sender_id'],
            'sender_name' => trim($m['first_name'] . ' ' . $m['last_name']),
            'sender_role' => $m['role'],
            'sender_initial' => strtoupper(substr($m['first_name'] ?? 'U', 0, 1)),
            'sender_pic' => $m['profile_pic'] ?? null,
            'message' => $m['message'],
            'created_at' => $m['created_at'],
            'time_formatted' => date('h:i A', strtotime($m['created_at'])),
            'date_formatted' => date('M d, Y', strtotime($m['created_at'])),
            'is_self' => ((int)$m['sender_id'] === $userId),
        ];
    }
    $stmt->close();

    // Channel metadata formatting
    $title = $channel['name'];
    $desc = $channel['description'];
    if ($channel['type'] === 'direct') {
        $otherRes = $conn->query("
            SELECT u.first_name, u.last_name, u.role, u.profile_pic
            FROM chat_members cm
            JOIN users u ON u.id = cm.user_id
            WHERE cm.channel_id = {$channelId} AND cm.user_id != {$userId}
            LIMIT 1
        ");
        if ($otherRes && $other = $otherRes->fetch_assoc()) {
            $title = trim($other['first_name'] . ' ' . $other['last_name']);
            $desc = 'Direct Conversation • ' . ucfirst(str_replace('_', ' ', $other['role']));
        }
    }

    echo json_encode([
        'success' => true,
        'channel' => [
            'id' => (int)$channel['id'],
            'name' => $title,
            'type' => $channel['type'],
            'club_id' => $channel['club_id'],
            'description' => $desc,
        ],
        'messages' => $messages,
    ]);
    exit;
}

// ── ACTION: SEND_MESSAGE ──
if ($action === 'send_message') {
    $channelId = (int)($_POST['channel_id'] ?? 0);
    $text = trim($_POST['message'] ?? '');

    if ($channelId <= 0 || empty($text)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Message content and valid channel are required.']);
        exit;
    }

    $channel = canAccessChannel($conn, $userId, $userRole, $channelId);
    if (!$channel) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You are not authorized to post in this channel.']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO chat_messages (channel_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param('iis', $channelId, $userId, $text);
    $ok = $stmt->execute();
    $msgId = (int)$stmt->insert_id;
    $stmt->close();

    if ($ok) {
        // Touch channel update time and mark sender read
        $conn->query("UPDATE chat_channels SET updated_at = NOW() WHERE id = {$channelId}");
        $conn->query("
            INSERT INTO chat_members (channel_id, user_id, last_read_at)
            VALUES ({$channelId}, {$userId}, NOW())
            ON DUPLICATE KEY UPDATE last_read_at = NOW()
        ");

        echo json_encode([
            'success' => true,
            'message_id' => $msgId,
            'message' => [
                'id' => $msgId,
                'sender_id' => $userId,
                'sender_name' => trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
                'sender_role' => $userRole,
                'message' => $text,
                'created_at' => date('Y-m-d H:i:s'),
                'time_formatted' => date('h:i A'),
                'is_self' => true,
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save message.']);
    }
    exit;
}

// ── ACTION: GET_DIRECTORY (Allowed contacts for Direct Messaging) ──
if ($action === 'get_directory') {
    $contacts = [];

    if ($userRole === 'student') {
        // Students can talk with peers and the assigned faculty adviser within their accredited organization(s)
        $sql = "
            (SELECT DISTINCT u.id, u.first_name, u.last_name, u.role, u.username, u.profile_pic, c.name as club_name
             FROM users u
             JOIN club_memberships cm ON cm.user_id = u.id AND LOWER(cm.status) IN ('active', 'approved')
             JOIN clubs c ON c.id = cm.club_id
             WHERE cm.club_id IN (
                 SELECT club_id FROM club_memberships WHERE user_id = {$userId} AND LOWER(status) IN ('active', 'approved')
             )
               AND u.id != {$userId}
               AND c.deleted_at IS NULL)
            UNION
            (SELECT DISTINCT u.id, u.first_name, u.last_name, u.role, u.username, u.profile_pic, c.name as club_name
             FROM users u
             JOIN clubs c ON c.adviser_user_id = u.id
             WHERE c.id IN (
                 SELECT club_id FROM club_memberships WHERE user_id = {$userId} AND LOWER(status) IN ('active', 'approved')
             )
               AND u.id != {$userId}
               AND c.deleted_at IS NULL)
            ORDER BY role DESC, first_name ASC
        ";
    } elseif ($userRole === 'club_adviser') {
        // Adviser can talk with their handled students AND SSC officers (Admin excluded)
        $sql = "
            (SELECT DISTINCT u.id, u.first_name, u.last_name, u.role, u.username, u.profile_pic, c.name as club_name
             FROM users u
             JOIN club_memberships cm ON cm.user_id = u.id AND LOWER(cm.status) IN ('active', 'approved')
             JOIN clubs c ON c.id = cm.club_id
             WHERE (c.adviser_user_id = {$userId} OR c.id IN (
                 SELECT club_id FROM club_memberships WHERE user_id = {$userId} AND role IN ('Adviser', 'Club Adviser') AND LOWER(status) IN ('active', 'approved')
             )) AND u.role = 'student' AND c.deleted_at IS NULL)
            UNION
            (SELECT id, first_name, last_name, role, username, profile_pic, 'Supreme Student Council' as club_name
             FROM users
             WHERE role = 'ssc' AND id != {$userId})
            ORDER BY role DESC, first_name ASC
        ";
    } elseif ($userRole === 'ssc') {
        // SSC can talk with all Club Advisers and fellow SSC officers (Admin excluded)
        $sql = "
            (SELECT DISTINCT u.id, u.first_name, u.last_name, u.role, u.username, u.profile_pic, c.name as club_name
             FROM users u
             JOIN clubs c ON (c.adviser_user_id = u.id OR c.id IN (
                 SELECT club_id FROM club_memberships cm WHERE cm.user_id = u.id AND cm.role IN ('Adviser', 'Club Adviser') AND LOWER(cm.status) IN ('active', 'approved')
             ))
             WHERE u.role = 'club_adviser' AND c.deleted_at IS NULL)
            UNION
            (SELECT id, first_name, last_name, role, username, profile_pic, 'Supreme Student Council' as club_name
             FROM users
             WHERE role = 'ssc' AND id != {$userId})
            ORDER BY role DESC, first_name ASC
        ";
    } else {
        // Admin: Full campus registry directory
        $sql = "
            SELECT id, first_name, last_name, role, username, profile_pic,
                   COALESCE(
                       (SELECT c.name FROM clubs c JOIN club_memberships cm ON cm.club_id = c.id WHERE cm.user_id = users.id AND LOWER(cm.status) IN ('active', 'approved') LIMIT 1),
                       (SELECT c.name FROM clubs c WHERE c.adviser_user_id = users.id AND c.deleted_at IS NULL LIMIT 1),
                       'Campus Registry'
                   ) as club_name
            FROM users
            WHERE id != {$userId}
            ORDER BY role DESC, first_name ASC
        ";
    }

    $res = $conn->query($sql);
    if ($res) {
        while ($u = $res->fetch_assoc()) {
            $contacts[] = [
                'id' => (int)$u['id'],
                'name' => trim($u['first_name'] . ' ' . $u['last_name']),
                'role' => $u['role'],
                'role_label' => ucfirst(str_replace('_', ' ', $u['role'])),
                'club_name' => $u['club_name'] ?? 'Organization',
                'initial' => strtoupper(substr($u['first_name'] ?? 'U', 0, 1)),
                'profile_pic' => $u['profile_pic'] ?? null,
            ];
        }
    }

    echo json_encode(['success' => true, 'contacts' => $contacts]);
    exit;
}

// ── ACTION: CREATE_DIRECT (Start or open 1-on-1 direct conversation) ──
if ($action === 'create_direct') {
    $targetId = (int)($_POST['target_user_id'] ?? 0);
    if ($targetId <= 0 || $targetId === $userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid direct chat target.']);
        exit;
    }

    // Verify target existence
    $tgtRes = $conn->query("SELECT id, first_name, last_name, role FROM users WHERE id = {$targetId} LIMIT 1");
    if (!$tgtRes || !$target = $tgtRes->fetch_assoc()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Target user not found.']);
        exit;
    }

    // Role boundary checks: adviser, ssc, and student must not message admin
    if ($target['role'] === 'admin' && in_array($userRole, ['club_adviser', 'ssc', 'student'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Direct messaging with Administrators is not permitted for your role.']);
        exit;
    }

    if ($userRole === 'student') {
        // Target must belong to the same active club OR be the assigned faculty adviser of that club
        $checkSameClub = $conn->query("
            SELECT COUNT(*) FROM clubs c
            WHERE c.deleted_at IS NULL
              AND c.id IN (
                  SELECT club_id FROM club_memberships 
                  WHERE user_id = {$userId} AND LOWER(status) IN ('active', 'approved')
              )
              AND (
                  c.adviser_user_id = {$targetId}
                  OR EXISTS (
                      SELECT 1 FROM club_memberships cm2 
                      WHERE cm2.club_id = c.id AND cm2.user_id = {$targetId} AND LOWER(cm2.status) IN ('active', 'approved')
                  )
              )
        ");
        if (!$checkSameClub || (int)$checkSameClub->fetch_row()[0] === 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Students may only direct message active members and advisers within their accredited organization.']);
            exit;
        }
    } elseif ($userRole === 'club_adviser') {
        // Adviser can only message handled students OR SSC officers
        if ($target['role'] === 'student') {
            $checkHandled = $conn->query("
                SELECT COUNT(*) FROM clubs c
                JOIN club_memberships cm ON cm.club_id = c.id
                WHERE (c.adviser_user_id = {$userId} OR c.id IN (
                    SELECT club_id FROM club_memberships WHERE user_id = {$userId} AND role IN ('Adviser', 'Club Adviser') AND LOWER(status) IN ('active', 'approved')
                )) AND cm.user_id = {$targetId} AND LOWER(cm.status) IN ('active', 'approved')
                   AND c.deleted_at IS NULL
            ");
            if (!$checkHandled || (int)$checkHandled->fetch_row()[0] === 0) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Advisers can only communicate with students in their handled organization.']);
                exit;
            }
        } elseif ($target['role'] !== 'ssc') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Direct communication restricted to handled students and SSC officers.']);
            exit;
        }
    } elseif ($userRole === 'ssc') {
        // SSC can message Club Advisers and fellow SSC officers
        if (!in_array($target['role'], ['club_adviser', 'ssc'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'SSC officers may only direct message Club Advisers and Council management desks.']);
            exit;
        }
    }

    // Look for existing direct channel between these two users
    $findSql = "
        SELECT c.id FROM chat_channels c
        WHERE c.type = 'direct'
          AND EXISTS (SELECT 1 FROM chat_members m WHERE m.channel_id = c.id AND m.user_id = {$userId})
          AND EXISTS (SELECT 1 FROM chat_members m WHERE m.channel_id = c.id AND m.user_id = {$targetId})
        LIMIT 1
    ";
    $findRes = $conn->query($findSql);
    if ($findRes && $row = $findRes->fetch_assoc()) {
        echo json_encode(['success' => true, 'channel_id' => (int)$row['id']]);
        exit;
    }

    // Otherwise create direct channel
    $u1Name = $_SESSION['first_name'] ?? 'User';
    $u2Name = $target['first_name'] ?? 'User';
    $chanName = "Direct: {$u1Name} & {$u2Name}";

    $stmt = $conn->prepare("INSERT INTO chat_channels (name, type, description, created_by, created_at, updated_at) VALUES (?, 'direct', 'Direct Conversation', ?, NOW(), NOW())");
    $stmt->bind_param('si', $chanName, $userId);
    $stmt->execute();
    $newChanId = (int)$stmt->insert_id;
    $stmt->close();

    if ($newChanId > 0) {
        $conn->query("INSERT INTO chat_members (channel_id, user_id, created_at) VALUES ({$newChanId}, {$userId}, NOW()), ({$newChanId}, {$targetId}, NOW())");
        echo json_encode(['success' => true, 'channel_id' => $newChanId]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to initialize direct conversation.']);
    }
    exit;
}

// ── ACTION: UNREAD_COUNT ──
if ($action === 'unread_count') {
    // Total count of unread messages across accessible channels
    $unreadCount = 0;
    if ($userRole === 'student') {
        $uRes = $conn->query("
            SELECT COUNT(m.id)
            FROM chat_messages m
            JOIN chat_channels c ON c.id = m.channel_id
            LEFT JOIN chat_members cm ON cm.channel_id = c.id AND cm.user_id = {$userId}
            WHERE m.sender_id != {$userId}
              AND (cm.last_read_at IS NULL OR m.created_at > cm.last_read_at)
              AND (
                  (c.type = 'club_group' AND c.club_id IN (
                      SELECT club_id FROM club_memberships WHERE user_id = {$userId} AND status = 'Active'
                  ))
                  OR (c.type = 'direct' AND EXISTS (
                      SELECT 1 FROM chat_members mem WHERE mem.channel_id = c.id AND mem.user_id = {$userId}
                  ))
              )
        ");
    } elseif ($userRole === 'club_adviser') {
        $uRes = $conn->query("
            SELECT COUNT(m.id)
            FROM chat_messages m
            JOIN chat_channels c ON c.id = m.channel_id
            LEFT JOIN chat_members cm ON cm.channel_id = c.id AND cm.user_id = {$userId}
            WHERE m.sender_id != {$userId}
              AND (cm.last_read_at IS NULL OR m.created_at > cm.last_read_at)
              AND (
                  (c.type IN ('club_group', 'adviser_ssc') AND c.club_id IN (
                      SELECT id FROM clubs WHERE adviser_user_id = {$userId} AND deleted_at IS NULL
                  ))
                  OR (c.type = 'direct' AND EXISTS (
                      SELECT 1 FROM chat_members mem WHERE mem.channel_id = c.id AND mem.user_id = {$userId}
                  ))
              )
        ");
    } elseif ($userRole === 'ssc') {
        $uRes = $conn->query("
            SELECT COUNT(m.id)
            FROM chat_messages m
            JOIN chat_channels c ON c.id = m.channel_id
            LEFT JOIN chat_members cm ON cm.channel_id = c.id AND cm.user_id = {$userId}
            WHERE m.sender_id != {$userId}
              AND (cm.last_read_at IS NULL OR m.created_at > cm.last_read_at)
              AND (
                  c.type = 'adviser_ssc'
                  OR (c.type = 'direct' AND EXISTS (
                      SELECT 1 FROM chat_members mem WHERE mem.channel_id = c.id AND mem.user_id = {$userId}
                  ))
              )
        ");
    } else {
        $uRes = $conn->query("
            SELECT COUNT(m.id)
            FROM chat_messages m
            JOIN chat_channels c ON c.id = m.channel_id
            LEFT JOIN chat_members cm ON cm.channel_id = c.id AND cm.user_id = {$userId}
            WHERE m.sender_id != {$userId}
              AND (cm.last_read_at IS NULL OR m.created_at > cm.last_read_at)
        ");
    }

    if ($uRes) {
        $unreadCount = (int)$uRes->fetch_row()[0];
    }

    echo json_encode(['success' => true, 'unread' => $unreadCount]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unrecognized chat action.']);
