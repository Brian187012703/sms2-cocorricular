<?php
// ============================================================
//  ELECTION_ACTIONS.PHP  (app/shared/)
//  Backend handler for election creation, candidate management,
//  voting, and results calculation.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';
require_once __DIR__ . '/security.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized session. Please sign in.']);
    exit;
}

// CSRF check on mutating requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

$user_id   = (int)$_SESSION['user_id'];
$sess_role = $_SESSION['role'] ?? 'student';
$action    = $_REQUEST['action'] ?? '';

// Helper: Get adviser handled club ID
function getAdviserClubId($conn, $user_id) {
    $stmt = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active' LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        return (int)$row['club_id'];
    }
    // Fallback to first active club
    $r = $conn->query("SELECT id FROM clubs WHERE status='Active' LIMIT 1");
    return ($r && $row = $r->fetch_assoc()) ? (int)$row['id'] : 1;
}

header('Content-Type: application/json');

// ── 1. CREATE ELECTION (Adviser, SSC, Admin) ─────────────────────────
if ($action === 'create_election') {
    if (!in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Only Advisers and Officers can establish elections.']);
        exit;
    }

    $title         = trim($_POST['title'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $closes_at     = trim($_POST['closes_at'] ?? '');
    $closing_date  = trim($_POST['closing_date'] ?? '');
    $closing_time  = trim($_POST['closing_time'] ?? '23:59');
    $positions_raw = trim($_POST['positions'] ?? 'President, Vice President, Secretary, Treasurer');
    $club_id       = (int)($_POST['club_id'] ?? 0);

    if (empty($title)) {
        echo json_encode(['success' => false, 'message' => 'Election title is required.']);
        exit;
    }

    if ($sess_role === 'club_adviser') {
        $club_id = getAdviserClubId($conn, $user_id);
    } elseif ($club_id <= 0) {
        $r = $conn->query("SELECT id FROM clubs WHERE status='Active' LIMIT 1");
        $club_id = ($r && $row = $r->fetch_assoc()) ? (int)$row['id'] : 1;
    }

    // Convert positions string to array
    $pos_arr = array_values(array_filter(array_map('trim', explode(',', $positions_raw))));
    if (empty($pos_arr)) {
        $pos_arr = ['President', 'Vice President', 'Secretary', 'Treasurer'];
    }

    $election_code = 'elec_' . time() . '_' . rand(100, 999);
    $positions_json = json_encode($pos_arr);

    if (!empty($closing_date)) {
        if (empty($closing_time)) $closing_time = '23:59';
        $closes_formatted = date('Y-m-d H:i:s', strtotime($closing_date . ' ' . $closing_time));
    } elseif (!empty($closes_at)) {
        $closes_formatted = date('Y-m-d H:i:s', strtotime($closes_at));
    } else {
        $closes_formatted = date('Y-m-d H:i:s', strtotime('+7 days'));
    }

    $stmt = $conn->prepare("INSERT INTO elections (election_code, club_id, title, description, closes_at, status, positions, created_by) VALUES (?, ?, ?, ?, ?, 'open', ?, ?)");
    $stmt->bind_param('sissssi', $election_code, $club_id, $title, $description, $closes_formatted, $positions_json, $user_id);

    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        $stmt->close();

        // Process candidates if provided in creation form
        $cands_raw = $_POST['candidates'] ?? '';
        if (!empty($cands_raw)) {
            $cands_arr = is_array($cands_raw) ? $cands_raw : json_decode($cands_raw, true);
            if (is_array($cands_arr)) {
                $cand_stmt = $conn->prepare("INSERT INTO election_candidates (election_id, candidate_code, name, position, party, year_level, program, gwa, platform_tag, achievements) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($cands_arr as $c) {
                    $c_name       = trim($c['name'] ?? '');
                    $c_pos        = trim($c['position'] ?? '');
                    if (empty($c_name) || empty($c_pos)) continue;
                    $c_party      = trim($c['party'] ?? 'Independent');
                    $c_year       = trim($c['year_level'] ?? '3rd Year');
                    $c_prog       = trim($c['program'] ?? 'BSIT');
                    $c_gwa        = trim($c['gwa'] ?? '1.5');
                    $c_tag        = trim($c['platform_tag'] ?? '');
                    $c_ach        = trim($c['achievements'] ?? '');
                    $cand_code    = 'cand_' . time() . '_' . rand(100, 999);
                    $ach_json     = json_encode(array_values(array_filter(array_map('trim', explode(',', $c_ach)))));

                    $cand_stmt->bind_param('isssssssss', $new_id, $cand_code, $c_name, $c_pos, $c_party, $c_year, $c_prog, $c_gwa, $c_tag, $ach_json);
                    $cand_stmt->execute();
                }
                $cand_stmt->close();
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Election pool created successfully!',
            'election_id' => $new_id,
            'election_code' => $election_code
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error creating election: ' . $conn->error]);
    }
    exit;
}

// ── 2. ADD CANDIDATE ──────────────────────────────────────────────────
if ($action === 'add_candidate') {
    if (!in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }

    $election_id   = (int)($_POST['election_id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $position      = trim($_POST['position'] ?? '');
    $party         = trim($_POST['party'] ?? 'Independent');
    $year_level    = trim($_POST['year_level'] ?? '3rd Year');
    $program       = trim($_POST['program'] ?? 'BSIT');
    $gwa           = trim($_POST['gwa'] ?? '1.5');
    $platform_tag  = trim($_POST['platform_tag'] ?? '');
    $achievements  = trim($_POST['achievements'] ?? '');

    if ($election_id <= 0 || empty($name) || empty($position)) {
        echo json_encode(['success' => false, 'message' => 'Candidate name and position are required.']);
        exit;
    }

    // Verify Adviser scoping
    if ($sess_role === 'club_adviser') {
        $my_club = getAdviserClubId($conn, $user_id);
        $chk = $conn->prepare("SELECT id FROM elections WHERE id = ? AND club_id = ?");
        $chk->bind_param('ii', $election_id, $my_club);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'You can only add candidates to elections of your handled organization.']);
            exit;
        }
        $chk->close();
    }

    $cand_code = 'cand_' . time() . '_' . rand(10, 99);
    $ach_json = json_encode(array_values(array_filter(array_map('trim', explode(',', $achievements)))));

    $stmt = $conn->prepare("INSERT INTO election_candidates (election_id, candidate_code, name, position, party, year_level, program, gwa, platform_tag, achievements) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isssssssss', $election_id, $cand_code, $name, $position, $party, $year_level, $program, $gwa, $platform_tag, $ach_json);

    if ($stmt->execute()) {
        $cid = $stmt->insert_id;
        $stmt->close();
        echo json_encode(['success' => true, 'message' => "Candidate {$name} added successfully!", 'candidate_id' => $cid]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error adding candidate: ' . $conn->error]);
    }
    exit;
}

// ── 3. DELETE CANDIDATE ───────────────────────────────────────────────
if ($action === 'delete_candidate') {
    if (!in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }

    $candidate_id = (int)($_POST['candidate_id'] ?? 0);
    if ($candidate_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid candidate specified.']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM election_candidates WHERE id = ?");
    $stmt->bind_param('i', $candidate_id);
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Candidate removed successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete candidate.']);
    }
    exit;
}

// ── 4. CLOSE ELECTION / PUBLISH RESULTS ──────────────────────────────
if ($action === 'close_election') {
    if (!in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }

    $election_id = (int)($_POST['election_id'] ?? 0);
    $status      = $_POST['status'] ?? 'closed';

    if ($election_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid election ID.']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE elections SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $election_id);
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Election status updated to ' . ucfirst($status) . '.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating election status.']);
    }
    exit;
}

// ── 5. CAST VOTE (Student Balloting) ─────────────────────────────────
if ($action === 'cast_vote') {
    if ($sess_role === 'club_adviser') {
        echo json_encode(['success' => false, 'message' => 'Club Advisers supervise and establish elections for their organization. Advisers do not vote in student balloting.']);
        exit;
    }

    $election_id = (int)($_POST['election_id'] ?? 0);
    $votes_data  = $_POST['votes'] ?? [];

    if ($election_id <= 0 || empty($votes_data)) {
        echo json_encode(['success' => false, 'message' => 'Please select your candidate choices before submitting.']);
        exit;
    }

    // Check student org membership
    if ($sess_role === 'student') {
        $chk_m = $conn->prepare("SELECT cm.id FROM club_memberships cm JOIN elections e ON e.club_id = cm.club_id WHERE e.id = ? AND cm.user_id = ? AND cm.status = 'Active'");
        $chk_m->bind_param('ii', $election_id, $user_id);
        $chk_m->execute();
        if (!$chk_m->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'You must be an approved member of this organization to vote in its election.']);
            exit;
        }
        $chk_m->close();
    }

    $conn->begin_transaction();
    try {
        // Check double voting in DB with lock
        $chk = $conn->prepare("SELECT id FROM election_votes WHERE election_id = ? AND user_id = ? FOR UPDATE");
        $chk->bind_param('ii', $election_id, $user_id);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $chk->close();
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'You have already cast your vote in this election.']);
            exit;
        }
        $chk->close();

        $votes_json = is_string($votes_data) ? $votes_data : json_encode($votes_data);

        // Save vote record
        $stmt = $conn->prepare("INSERT INTO election_votes (election_id, user_id, votes_json) VALUES (?, ?, ?)");
        $stmt->bind_param('iis', $election_id, $user_id, $votes_json);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception("Error recording vote: " . $err);
        }
        $stmt->close();

        // Increment candidate vote counts safely with prepared statement
        $decoded = is_string($votes_data) ? json_decode($votes_data, true) : $votes_data;
        if (is_array($decoded)) {
            $upd_stmt = $conn->prepare("UPDATE election_candidates SET votes_count = votes_count + 1 WHERE id = ? AND election_id = ?");
            foreach ($decoded as $pos => $cand_id) {
                $c_id = (int)$cand_id;
                if ($c_id > 0) {
                    $upd_stmt->bind_param('ii', $c_id, $election_id);
                    $upd_stmt->execute();
                }
            }
            $upd_stmt->close();
        }

        $conn->commit();
        $_SESSION['votes_cast'][] = $election_id;
        echo json_encode(['success' => true, 'message' => 'Your ballot has been cast and verified!']);
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── 6. APPOINT ELECTION WINNER AS OFFICER ────────────────────────────
if ($action === 'appoint_winner') {
    if (!in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Only Advisers and SSC Officers can appoint election winners.']);
        exit;
    }

    $election_id  = (int)($_POST['election_id'] ?? 0);
    $candidate_id = (int)($_POST['candidate_id'] ?? 0);

    if ($election_id <= 0 || $candidate_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid election or candidate ID.']);
        exit;
    }

    // 1. Get election and candidate info
    $stmt = $conn->prepare("
        SELECT ec.id, ec.name, ec.position, e.club_id, e.title as election_title, c.name as club_name, c.code as club_code
        FROM election_candidates ec
        JOIN elections e ON e.id = ec.election_id
        JOIN clubs c ON c.id = e.club_id
        WHERE ec.id = ? AND ec.election_id = ?
    ");
    $stmt->bind_param('ii', $candidate_id, $election_id);
    $stmt->execute();
    $cand = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$cand) {
        echo json_encode(['success' => false, 'message' => 'Candidate record not found for this election.']);
        exit;
    }

    $club_id   = (int)$cand['club_id'];
    $cand_name = trim($cand['name']);
    $position  = trim($cand['position']);

    // 2. Locate user in users table matching candidate name
    $parts = preg_split('/\s+/', $cand_name, 2);
    $first = $parts[0] ?? '';
    $last  = $parts[1] ?? '';

    $target_user_id = 0;
    $u_stmt = $conn->prepare("
        SELECT id FROM users
        WHERE (TRIM(CONCAT(first_name, ' ', last_name)) = ?
           OR (first_name = ? AND last_name = ?)
           OR username = ?)
        LIMIT 1
    ");
    $u_stmt->bind_param('ssss', $cand_name, $first, $last, $cand_name);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result()->fetch_assoc();
    $u_stmt->close();

    if ($u_res) {
        $target_user_id = (int)$u_res['id'];
    } else {
        // Fallback: search in students table
        $s_stmt = $conn->prepare("
            SELECT u.id FROM users u
            JOIN students s ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
            WHERE TRIM(CONCAT(s.first_name, ' ', s.last_name)) = ?
               OR s.student_number = ?
            LIMIT 1
        ");
        $s_stmt->bind_param('ss', $cand_name, $cand_name);
        $s_stmt->execute();
        $s_res = $s_stmt->get_result()->fetch_assoc();
        $s_stmt->close();
        if ($s_res) {
            $target_user_id = (int)$s_res['id'];
        }
    }

    if ($target_user_id <= 0) {
        echo json_encode(['success' => false, 'message' => "Could not locate a user account for candidate '$cand_name'."]);
        exit;
    }

    // 3. Update club_memberships role to 'Officer' (Do NOT change global users.role)
    $chk_mem = $conn->prepare("SELECT id, role FROM club_memberships WHERE club_id = ? AND user_id = ?");
    $chk_mem->bind_param('ii', $club_id, $target_user_id);
    $chk_mem->execute();
    $mem_row = $chk_mem->get_result()->fetch_assoc();
    $chk_mem->close();

    if ($mem_row) {
        $upd_mem = $conn->prepare("UPDATE club_memberships SET role = 'Officer', status = 'Active' WHERE id = ?");
        $upd_mem->bind_param('i', $mem_row['id']);
        $upd_mem->execute();
        $upd_mem->close();
    } else {
        $ins_mem = $conn->prepare("INSERT INTO club_memberships (club_id, user_id, role, status) VALUES (?, ?, 'Officer', 'Active')");
        $ins_mem->bind_param('ii', $club_id, $target_user_id);
        $ins_mem->execute();
        $ins_mem->close();
    }

    // 4. Mark candidate as appointed in election_candidates
    $conn->query("UPDATE election_candidates SET is_appointed = 1 WHERE id = $candidate_id");

    // 5. Notify student of officer appointment
    if (function_exists('push_notification')) {
        push_notification(
            $conn,
            $target_user_id,
            "Officer Proclamation: $position",
            "Congratulations! You have been officially proclaimed and appointed as $position of " . ($cand['club_code'] ?? 'your organization') . ".",
            'election'
        );
    }

    // 6. Log audit
    log_audit($conn, $user_id, 'appoint_officer', 'club_memberships', $target_user_id, "Appointed $cand_name as Officer ($position) for club $club_id");

    echo json_encode([
        'success'      => true,
        'message'      => "$cand_name has been successfully appointed as an Organization Officer ($position)!",
        'candidate_id' => $candidate_id,
        'user_id'      => $target_user_id
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
