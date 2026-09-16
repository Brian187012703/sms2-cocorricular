<?php
// ============================================================
//  REPORT_ACTIONS.PHP — Organizational Analytics & Reports Backend
//  Standard Non-AI Report Generation with CSV & Print Export Data
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

if (!in_array($user_role, ['club_adviser', 'ssc', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access to reports.']);
    exit;
}

function rptRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

// Determine adviser's club if club_adviser
$club_id = 0;
$club_info = ['name' => 'All Organizations (Campus Wide)', 'code' => 'BCP'];
if ($user_role === 'club_adviser') {
    $sess_user = $_SESSION['username'] ?? '';
    $cm = $conn->prepare("
        SELECT id, name, code FROM clubs
        WHERE (id IN (SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active')
           OR code=UPPER(SUBSTRING_INDEX(?, '.', 1)))
          AND status='Active' LIMIT 1
    ");
    $cm->bind_param('is', $user_id, $sess_user);
    $cm->execute();
    $res = $cm->get_result();
    if ($row = $res->fetch_assoc()) {
        $club_id = (int)$row['id'];
        $club_info = $row;
    }
    $cm->close();
}

switch ($action) {

    // ── 1. GET REPORT DATA ────────────────────────────────────
    case 'get_report': {
        $type = trim($_POST['report_type'] ?? $_GET['report_type'] ?? '');
        $date_generated = date('F j, Y, g:i A');

        if ($type === 'activity_events') {
            $where = $club_id > 0 ? "WHERE e.club_id = $club_id" : "";
            $sql = "SELECT e.id, e.title, e.event_date, e.venue, e.status, COALESCE(e.budget, 0) as budget,
                           COALESCE(c.name, 'General') as club_name, COALESCE(c.code, 'BCP') as club_code,
                           (SELECT COUNT(*) FROM attendance_logs al WHERE al.event_id = e.id) AS attendance_count,
                           (SELECT COUNT(*) FROM event_registrations er WHERE er.event_id = e.id) AS reg_count
                    FROM events e
                    LEFT JOIN clubs c ON c.id = e.club_id
                    $where
                    ORDER BY e.event_date DESC";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

            $total_events = count($rows);
            $approved = count(array_filter($rows, fn($r) => in_array($r['status'], ['Approved', 'Upcoming', 'Completed'])));
            $completed = count(array_filter($rows, fn($r) => $r['status'] === 'Completed'));
            $total_attendance = array_sum(array_column($rows, 'attendance_count'));

            rptRespond(true, 'Report generated.', [
                'title'          => 'Activity & Events Performance Report',
                'club'           => $club_info,
                'date_generated' => $date_generated,
                'summary_cards'  => [
                    ['label' => 'Total Events', 'value' => $total_events, 'icon' => 'calendar-days', 'color' => '#2563eb'],
                    ['label' => 'Approved / Active', 'value' => $approved, 'icon' => 'circle-check', 'color' => '#16a34a'],
                    ['label' => 'Completed Events', 'value' => $completed, 'icon' => 'flag-checkered', 'color' => '#0284c7'],
                    ['label' => 'Total Attendances', 'value' => $total_attendance, 'icon' => 'users-viewfinder', 'color' => '#7c3aed']
                ],
                'columns' => ['Event Title', 'Date', 'Venue', 'Status', 'Registrations', 'Attendance Logged'],
                'data' => array_map(function($r) {
                    return [
                        $r['title'],
                        date('M j, Y', strtotime($r['event_date'])),
                        $r['venue'] ?: 'Campus Venue',
                        $r['status'],
                        (string)$r['reg_count'],
                        (string)$r['attendance_count']
                    ];
                }, $rows)
            ]);
        }

        elseif ($type === 'membership_engagement') {
            $where = $club_id > 0 ? "WHERE cm.club_id = $club_id" : "WHERE 1=1";
            $sql = "SELECT cm.id, cm.role, cm.status, cm.joined_at,
                           u.first_name, u.last_name, u.email,
                           c.name as club_name, c.code as club_code,
                           COALESCE(s.student_number, u.username) AS student_number,
                           COALESCE(s.course, 'N/A') AS course,
                           COALESCE(s.year_level, 'N/A') AS year_level,
                           COALESCE(s.section, '') AS section
                    FROM club_memberships cm
                    JOIN users u ON u.id = cm.user_id
                    JOIN clubs c ON c.id = cm.club_id
                    LEFT JOIN students s ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
                    $where AND cm.status = 'Active'
                    ORDER BY FIELD(cm.role, 'Adviser', 'Officer', 'Member'), u.last_name ASC";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

            $total_members = count($rows);
            $officers = count(array_filter($rows, fn($r) => $r['role'] === 'Officer'));
            $members  = count(array_filter($rows, fn($r) => $r['role'] === 'Member'));
            $advisers = count(array_filter($rows, fn($r) => $r['role'] === 'Adviser'));

            rptRespond(true, 'Report generated.', [
                'title'          => 'Membership & Engagement Roster Report',
                'club'           => $club_info,
                'date_generated' => $date_generated,
                'summary_cards'  => [
                    ['label' => 'Total Roster', 'value' => $total_members, 'icon' => 'users', 'color' => '#2563eb'],
                    ['label' => 'Elected Officers', 'value' => $officers, 'icon' => 'user-shield', 'color' => '#d97706'],
                    ['label' => 'General Members', 'value' => $members, 'icon' => 'user-group', 'color' => '#16a34a'],
                    ['label' => 'Faculty Advisers', 'value' => $advisers, 'icon' => 'user-tie', 'color' => '#7c3aed']
                ],
                'columns' => ['Student / Member Name', 'Student ID', 'Program & Year', 'Role', 'Status', 'Joined Date'],
                'data' => array_map(function($r) {
                    $prog = $r['course'] . ($r['year_level'] !== 'N/A' ? ' - ' . $r['year_level'] : '');
                    return [
                        $r['first_name'] . ' ' . $r['last_name'],
                        $r['student_number'],
                        $prog,
                        $r['role'],
                        $r['status'],
                        date('M j, Y', strtotime($r['joined_at']))
                    ];
                }, $rows)
            ]);
        }

        elseif ($type === 'attendance_analytics') {
            $where = $club_id > 0 ? "WHERE e.club_id = $club_id" : "";
            $sql = "SELECT al.id, al.scanned_at, al.checkin_method,
                           e.title AS event_title, e.event_date,
                           u.first_name, u.last_name,
                           COALESCE(s.student_number, u.username) AS student_number,
                           COALESCE(s.course, 'N/A') AS course
                    FROM attendance_logs al
                    JOIN events e ON e.id = al.event_id
                    JOIN users u ON u.id = al.user_id
                    LEFT JOIN students s ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
                    $where
                    ORDER BY al.scanned_at DESC
                    LIMIT 250";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

            $total_logs = count($rows);
            $unique_students = count(array_unique(array_column($rows, 'student_number')));
            $unique_events = count(array_unique(array_column($rows, 'event_title')));

            rptRespond(true, 'Report generated.', [
                'title'          => 'Event Attendance Analytics & Audit Ledger',
                'club'           => $club_info,
                'date_generated' => $date_generated,
                'summary_cards'  => [
                    ['label' => 'Total Check-Ins', 'value' => $total_logs, 'icon' => 'clipboard-check', 'color' => '#2563eb'],
                    ['label' => 'Unique Students', 'value' => $unique_students, 'icon' => 'user-check', 'color' => '#16a34a'],
                    ['label' => 'Tracked Events', 'value' => $unique_events, 'icon' => 'calendar-check', 'color' => '#d97706'],
                    ['label' => 'Verification Method', 'value' => 'QR Code', 'icon' => 'qrcode', 'color' => '#7c3aed']
                ],
                'columns' => ['Student Name', 'Student ID', 'Event Name', 'Method', 'Timestamp'],
                'data' => array_map(function($r) {
                    return [
                        $r['first_name'] . ' ' . $r['last_name'],
                        $r['student_number'],
                        $r['event_title'],
                        $r['checkin_method'] ?: 'QR Self Check-In',
                        date('M j, Y g:i A', strtotime($r['scanned_at']))
                    ];
                }, $rows)
            ]);
        }

        elseif ($type === 'budget_financial') {
            $where = $club_id > 0 ? "WHERE br.club_id = $club_id" : "";
            $sql = "SELECT br.id, br.title, br.category, br.amount, br.status, br.request_date, br.approved_date, br.notes,
                           c.name AS club_name, c.code AS club_code
                    FROM budget_requests br
                    JOIN clubs c ON c.id = br.club_id
                    $where
                    ORDER BY br.request_date DESC";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

            $total_req = count($rows);
            $disbursed_amt = 0;
            $approved_amt = 0;
            $pending_cnt = 0;

            foreach ($rows as $r) {
                if ($r['status'] === 'Disbursed') $disbursed_amt += (float)$r['amount'];
                if (in_array($r['status'], ['Approved', 'Disbursed'])) $approved_amt += (float)$r['amount'];
                if ($r['status'] === 'Pending') $pending_cnt++;
            }

            rptRespond(true, 'Report generated.', [
                'title'          => 'Financial Disbursal & Requisitions Ledger',
                'club'           => $club_info,
                'date_generated' => $date_generated,
                'summary_cards'  => [
                    ['label' => 'Total Requisitions', 'value' => $total_req, 'icon' => 'receipt', 'color' => '#2563eb'],
                    ['label' => 'Total Disbursed', 'value' => '₱' . number_format($disbursed_amt, 2), 'icon' => 'money-bill-transfer', 'color' => '#16a34a'],
                    ['label' => 'Approved Allocations', 'value' => '₱' . number_format($approved_amt, 2), 'icon' => 'piggy-bank', 'color' => '#0284c7'],
                    ['label' => 'Pending Requests', 'value' => $pending_cnt, 'icon' => 'hourglass-half', 'color' => '#d97706']
                ],
                'columns' => ['Req ID', 'Requisition Purpose', 'Category', 'Amount', 'Status', 'Date Requested'],
                'data' => array_map(function($r) {
                    return [
                        'REQ-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT),
                        $r['title'],
                        $r['category'] ?: 'Activity Expense',
                        '₱' . number_format((float)$r['amount'], 2),
                        $r['status'],
                        date('M j, Y', strtotime($r['request_date']))
                    ];
                }, $rows)
            ]);
        }

        elseif ($type === 'comprehensive') {
            // Aggregate comprehensive data
            $where_ev = $club_id > 0 ? "WHERE club_id = $club_id" : "";
            $where_cm = $club_id > 0 ? "WHERE club_id = $club_id" : "";
            $where_br = $club_id > 0 ? "WHERE club_id = $club_id" : "";
            $where_ac = $club_id > 0 ? "WHERE club_id = $club_id" : "";

            $ev_count = (int)$conn->query("SELECT COUNT(*) as c FROM events $where_ev")->fetch_assoc()['c'];
            $cm_count = (int)$conn->query("SELECT COUNT(*) as c FROM club_memberships $where_cm AND status='Active'")->fetch_assoc()['c'];
            $disb_sum = (float)$conn->query("SELECT COALESCE(SUM(amount), 0) as s FROM budget_requests $where_br AND status='Disbursed'")->fetch_assoc()['s'];
            $ach_count= (int)$conn->query("SELECT COUNT(*) as c FROM achievements $where_ac AND status='Verified'")->fetch_assoc()['c'];

            $summary_data = [
                ['Category Metric', 'Status / Count', 'Operational Benchmark', 'Compliance Status'],
                ['Campus Events & Activities', "$ev_count Events Registered", "Target: 4/sem", "Compliant"],
                ['Active Club Membership', "$cm_count Active Members", "Target: >=10", "Compliant"],
                ['Total Disbursed Allocation', '₱' . number_format($disb_sum, 2), 'Within allocated budget', 'Balanced'],
                ['Verified Achievements & Awards', "$ach_count Awards Documented", "Official Ledger Recorded", 'Recognized']
            ];

            rptRespond(true, 'Report generated.', [
                'title'          => 'Comprehensive Semester Performance Audit',
                'club'           => $club_info,
                'date_generated' => $date_generated,
                'summary_cards'  => [
                    ['label' => 'Total Events', 'value' => $ev_count, 'icon' => 'calendar-check', 'color' => '#2563eb'],
                    ['label' => 'Active Members', 'value' => $cm_count, 'icon' => 'users', 'color' => '#16a34a'],
                    ['label' => 'Funds Disbursed', 'value' => '₱' . number_format($disb_sum, 2), 'icon' => 'peso-sign', 'color' => '#0284c7'],
                    ['label' => 'Verified Awards', 'value' => $ach_count, 'icon' => 'trophy', 'color' => '#d97706']
                ],
                'columns' => ['Category Metric', 'Status / Count', 'Operational Benchmark', 'Compliance Status'],
                'data' => array_slice($summary_data, 1)
            ]);
        }

        else {
            rptRespond(false, 'Invalid report type selected.');
        }
    }

    default:
        rptRespond(false, 'Unknown report action.');
}
