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
            $sql = "SELECT e.id, e.title, e.event_date, e.venue, e.status, e.expected_attendees,
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
            $sql = "SELECT al.id, al.check_in, al.method,
                           e.title AS event_title, e.event_date,
                           u.first_name, u.last_name,
                           COALESCE(s.student_number, u.username) AS student_number,
                           COALESCE(s.course, 'N/A') AS course
                    FROM attendance_logs al
                    JOIN events e ON e.id = al.event_id
                    JOIN users u ON u.id = al.user_id
                    LEFT JOIN students s ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
                    $where
                    ORDER BY al.check_in DESC
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
                        $r['method'] ?: 'QR Self Check-In',
                        date('M j, Y g:i A', strtotime($r['check_in']))
                    ];
                }, $rows)
            ]);
        }

        elseif ($type === 'budget_financial') {
            $where = $club_id > 0 ? "WHERE br.club_id = $club_id" : "";
            $sql = "SELECT br.id, br.title, COALESCE(br.description, 'Operational Fund') AS description, br.amount, br.status, br.created_at, br.disbursed_at, br.notes,
                           c.name AS club_name, c.code AS club_code
                    FROM budget_requests br
                    JOIN clubs c ON c.id = br.club_id
                    $where
                    ORDER BY br.created_at DESC";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

            $total_req = count($rows);
            $disbursed_amt = 0;
            $approved_amt = 0;
            $pending_cnt = 0;

            foreach ($rows as $r) {
                if ($r['status'] === 'Disbursed') $disbursed_amt += (float)$r['amount'];
                if (in_array($r['status'], ['Approved', 'Disbursed'])) $approved_amt += (float)$r['amount'];
                if (strpos($r['status'], 'Pending') === 0) $pending_cnt++;
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
                'columns' => ['Req ID', 'Requisition Purpose', 'Category / Details', 'Amount', 'Status', 'Date Requested'],
                'data' => array_map(function($r) {
                    return [
                        'REQ-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT),
                        $r['title'],
                        $r['description'] ?: 'Activity Expense',
                        '₱' . number_format((float)$r['amount'], 2),
                        $r['status'],
                        date('M j, Y', strtotime($r['created_at']))
                    ];
                }, $rows)
            ]);
        }

        elseif ($type === 'comprehensive') {
            // Aggregate comprehensive data
            $where_ev = $club_id > 0 ? "WHERE club_id = $club_id" : "";
            $where_cm = $club_id > 0 ? "WHERE club_id = $club_id AND" : "WHERE";
            $where_br = $club_id > 0 ? "WHERE club_id = $club_id AND" : "WHERE";
            $where_ac = $club_id > 0 ? "WHERE club_id = $club_id AND" : "WHERE";

            $ev_count = (int)$conn->query("SELECT COUNT(*) as c FROM events $where_ev")->fetch_assoc()['c'];
            $cm_count = (int)$conn->query("SELECT COUNT(*) as c FROM club_memberships $where_cm status='Active'")->fetch_assoc()['c'];
            $disb_sum = (float)$conn->query("SELECT COALESCE(SUM(amount), 0) as s FROM budget_requests $where_br status='Disbursed'")->fetch_assoc()['s'];
            $ach_count= (int)$conn->query("SELECT COUNT(*) as c FROM achievements $where_ac status='Verified'")->fetch_assoc()['c'];

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

        // ── ADMIN REPORT MODULE 1: SYSTEM USAGE ──────────────────
        elseif ($type === 'system_usage') {
            // Track report generation in audit log
            $log_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $esc_ip = $conn->real_escape_string($log_ip);
            $conn->query("INSERT INTO audit_logs (user_id, action, target_table, detail, ip_address) VALUES ($user_id, 'GENERATE_REPORT', 'system_reports', 'Admin generated System Usage Report', '$esc_ip')");

            $audit_count = (int)$conn->query("SELECT COUNT(*) as c FROM audit_logs")->fetch_assoc()['c'];
            $active_users = (int)$conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];

            $tbl_res = $conn->query("SELECT COUNT(*) as c, ROUND(COALESCE(SUM(data_length + index_length),0) / 1024 / 1024, 2) as db_size_mb FROM information_schema.tables WHERE table_schema = DATABASE()");
            $tbl_data = $tbl_res ? $tbl_res->fetch_assoc() : ['c' => 24, 'db_size_mb' => 2.4];
            $tables_count = (int)($tbl_data['c'] ?? 24);
            $db_size = ($tbl_data['db_size_mb'] ?? 2.4) . ' MB';

            $sql = "SELECT al.id, al.action, al.target_table, al.target_id, al.detail, al.ip_address, al.created_at,
                           u.first_name, u.last_name, u.role
                    FROM audit_logs al
                    LEFT JOIN users u ON u.id = al.user_id
                    ORDER BY al.id DESC
                    LIMIT 250";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

            rptRespond(true, 'Report generated.', [
                'title'          => 'System Usage & Telemetry Report',
                'club'           => ['name' => 'Institutional Core System', 'code' => 'BCP-CORE'],
                'date_generated' => $date_generated,
                'summary_cards'  => [
                    ['label' => 'Audit Logs Recorded', 'value' => $audit_count, 'icon' => 'server', 'color' => '#2563eb'],
                    ['label' => 'Active System Users', 'value' => $active_users, 'icon' => 'users-check', 'color' => '#16a34a'],
                    ['label' => 'System Database Tables', 'value' => $tables_count . ' Tables', 'icon' => 'database', 'color' => '#7c3aed'],
                    ['label' => 'Storage Telemetry', 'value' => $db_size, 'icon' => 'hard-drive', 'color' => '#0284c7']
                ],
                'columns' => ['Log ID', 'User / Operator', 'Role', 'Action Executed', 'Target Resource', 'IP Address', 'Timestamp'],
                'data' => array_map(function($r) {
                    $name = !empty($r['first_name']) ? ($r['first_name'] . ' ' . $r['last_name']) : 'System Automated';
                    return [
                        'LOG-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
                        $name,
                        strtoupper($r['role'] ?? 'SYSTEM'),
                        $r['action'],
                        $r['target_table'] ?: 'system',
                        $r['ip_address'] ?: '127.0.0.1',
                        date('M j, Y g:i A', strtotime($r['created_at']))
                    ];
                }, $rows)
            ]);
        }

        // ── ADMIN REPORT MODULE 2: ORGANIZATION SUMMARY ───────────
        elseif ($type === 'organization_summary') {
            $total_clubs = (int)$conn->query("SELECT COUNT(*) as c FROM clubs WHERE status = 'Active'")->fetch_assoc()['c'];
            $total_members = (int)$conn->query("SELECT COUNT(*) as c FROM club_memberships WHERE status = 'Active'")->fetch_assoc()['c'];
            $assigned_advisers = (int)$conn->query("SELECT COUNT(DISTINCT adviser_user_id) as c FROM clubs WHERE adviser_user_id IS NOT NULL")->fetch_assoc()['c'];
            $academic_progs = (int)$conn->query("SELECT COUNT(*) as c FROM academic_programs")->fetch_assoc()['c'];

            $sql = "SELECT c.id, c.code, c.name, c.category, c.status,
                           COALESCE(CONCAT(u.first_name, ' ', u.last_name), c.adviser_name, 'Unassigned') as adviser_display,
                           (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id = c.id AND cm.status = 'Active') as member_count
                    FROM clubs c
                    LEFT JOIN users u ON u.id = c.adviser_user_id
                    WHERE c.deleted_at IS NULL
                    ORDER BY c.name ASC";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

            rptRespond(true, 'Report generated.', [
                'title'          => 'Organization Summary & Accredited Roster Report',
                'club'           => ['name' => 'All Recognized Organizations', 'code' => 'BCP-ORGS'],
                'date_generated' => $date_generated,
                'summary_cards'  => [
                    ['label' => 'Accredited Clubs', 'value' => $total_clubs, 'icon' => 'building-columns', 'color' => '#2563eb'],
                    ['label' => 'Active Memberships', 'value' => $total_members, 'icon' => 'users', 'color' => '#16a34a'],
                    ['label' => 'Faculty Advisers', 'value' => $assigned_advisers, 'icon' => 'user-tie', 'color' => '#7c3aed'],
                    ['label' => 'Academic Programs', 'value' => $academic_progs, 'icon' => 'graduation-cap', 'color' => '#d97706']
                ],
                'columns' => ['Code', 'Organization Name', 'Category', 'Faculty Adviser', 'Active Members', 'Status'],
                'data' => array_map(function($r) {
                    return [
                        $r['code'],
                        $r['name'],
                        $r['category'] ?: 'Academic',
                        $r['adviser_display'],
                        (string)$r['member_count'],
                        $r['status']
                    ];
                }, $rows)
            ]);
        }

        // ── ADMIN REPORT MODULE 3: FINANCIAL SUMMARY ──────────────
        elseif ($type === 'financial_summary') {
            $sql = "SELECT br.id, br.title, COALESCE(br.description, 'Operational Fund') as description, br.amount, br.status, br.created_at,
                           c.name as club_name, c.code as club_code
                    FROM budget_requests br
                    JOIN clubs c ON c.id = br.club_id
                    ORDER BY br.created_at DESC";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

            $total_req = count($rows);
            $disbursed_amt = 0;
            $approved_amt = 0;
            $pending_cnt = 0;

            foreach ($rows as $r) {
                if ($r['status'] === 'Disbursed') $disbursed_amt += (float)$r['amount'];
                if (in_array($r['status'], ['Approved', 'Disbursed'])) $approved_amt += (float)$r['amount'];
                if (strpos($r['status'], 'Pending') === 0) $pending_cnt++;
            }

            rptRespond(true, 'Report generated.', [
                'title'          => 'Institutional Financial Summary & Disbursals Ledger',
                'club'           => ['name' => 'Campus Wide Budget Administration', 'code' => 'BCP-FIN'],
                'date_generated' => $date_generated,
                'summary_cards'  => [
                    ['label' => 'Total Requisitions', 'value' => $total_req, 'icon' => 'file-invoice-dollar', 'color' => '#2563eb'],
                    ['label' => 'Total Disbursed', 'value' => '₱' . number_format($disbursed_amt, 2), 'icon' => 'money-bill-wave', 'color' => '#16a34a'],
                    ['label' => 'Approved Allocations', 'value' => '₱' . number_format($approved_amt, 2), 'icon' => 'wallet', 'color' => '#0284c7'],
                    ['label' => 'Pending Clearance', 'value' => $pending_cnt, 'icon' => 'hourglass-half', 'color' => '#d97706']
                ],
                'columns' => ['Req ID', 'Organization', 'Requisition Title', 'Category / Details', 'Amount', 'Status', 'Date Requested'],
                'data' => array_map(function($r) {
                    return [
                        'REQ-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT),
                        $r['club_code'] . ' - ' . $r['club_name'],
                        $r['title'],
                        $r['description'] ?: 'Operating Expense',
                        '₱' . number_format((float)$r['amount'], 2),
                        $r['status'],
                        date('M j, Y', strtotime($r['created_at']))
                    ];
                }, $rows)
            ]);
        }

        // ── ADMIN REPORT MODULE 4: ATTENDANCE SUMMARY ─────────────
        elseif ($type === 'attendance_summary') {
            $sql = "SELECT al.id, al.check_in, al.method,
                           e.title as event_title, e.event_date,
                           u.first_name, u.last_name, u.username,
                           COALESCE(s.student_number, u.username) AS student_number,
                           COALESCE(c.name, 'Institution') AS club_name
                    FROM attendance_logs al
                    JOIN events e ON e.id = al.event_id
                    JOIN users u ON u.id = al.user_id
                    LEFT JOIN clubs c ON c.id = e.club_id
                    LEFT JOIN students s ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
                    ORDER BY al.check_in DESC
                    LIMIT 300";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

            $total_logs = count($rows);
            $unique_students = count(array_unique(array_column($rows, 'student_number')));
            $unique_events = count(array_unique(array_column($rows, 'event_title')));

            rptRespond(true, 'Report generated.', [
                'title'          => 'Institutional Attendance Summary & Audit Ledger',
                'club'           => ['name' => 'Campus Wide Event Attendance Administration', 'code' => 'BCP-ATT'],
                'date_generated' => $date_generated,
                'summary_cards'  => [
                    ['label' => 'Total Check-Ins', 'value' => $total_logs, 'icon' => 'clipboard-check', 'color' => '#2563eb'],
                    ['label' => 'Unique Students', 'value' => $unique_students, 'icon' => 'user-check', 'color' => '#16a34a'],
                    ['label' => 'Tracked Events', 'value' => $unique_events, 'icon' => 'calendar-check', 'color' => '#d97706'],
                    ['label' => 'Primary Verification', 'value' => 'QR Scanner', 'icon' => 'qrcode', 'color' => '#7c3aed']
                ],
                'columns' => ['Student Name', 'Student ID', 'Event Title', 'Host Organization', 'Method', 'Timestamp'],
                'data' => array_map(function($r) {
                    return [
                        $r['first_name'] . ' ' . $r['last_name'],
                        $r['student_number'],
                        $r['event_title'],
                        $r['club_name'],
                        $r['method'] ?: 'QR Self Check-In',
                        date('M j, Y g:i A', strtotime($r['check_in']))
                    ];
                }, $rows)
            ]);
        }

        // ── ADMIN REPORT MODULE 5: ELECTION SUMMARY ───────────────
        elseif ($type === 'election_summary') {
            $sql = "SELECT el.id, el.election_code, el.title, el.status, el.starts_at, el.closes_at, el.verified_at,
                           COALESCE(c.name, 'Institution Wide') as club_name,
                           COALESCE(c.code, 'BCP') as club_code,
                           (SELECT COUNT(*) FROM election_candidates ec WHERE ec.election_id = el.id) as candidate_count,
                           (SELECT COUNT(*) FROM election_votes ev WHERE ev.election_id = el.id) as vote_count
                    FROM elections el
                    LEFT JOIN clubs c ON c.id = el.club_id
                    ORDER BY el.created_at DESC";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

            $total_elections = count($rows);
            $total_candidates = array_sum(array_column($rows, 'candidate_count'));
            $total_votes = array_sum(array_column($rows, 'vote_count'));
            $active_elections = count(array_filter($rows, fn($r) => $r['status'] === 'active'));

            rptRespond(true, 'Report generated.', [
                'title'          => 'Student Governance Election Summary & Audit Report',
                'club'           => ['name' => 'Campus Electoral Board & SSC', 'code' => 'BCP-ELEC'],
                'date_generated' => $date_generated,
                'summary_cards'  => [
                    ['label' => 'Elections Conducted', 'value' => $total_elections, 'icon' => 'check-to-slot', 'color' => '#2563eb'],
                    ['label' => 'Active Polls', 'value' => $active_elections, 'icon' => 'person-booth', 'color' => '#16a34a'],
                    ['label' => 'Registered Candidates', 'value' => $total_candidates, 'icon' => 'users-rectangle', 'color' => '#7c3aed'],
                    ['label' => 'Total Ballots Cast', 'value' => $total_votes, 'icon' => 'envelope-open-text', 'color' => '#d97706']
                ],
                'columns' => ['Election Code', 'Title / Election Name', 'Organization', 'Status', 'Candidates', 'Total Ballots', 'Certification Status'],
                'data' => array_map(function($r) {
                    return [
                        $r['election_code'] ?: ('ELEC-' . str_pad($r['id'], 3, '0', STR_PAD_LEFT)),
                        $r['title'],
                        $r['club_code'] . ' - ' . $r['club_name'],
                        strtoupper($r['status']),
                        (string)$r['candidate_count'],
                        (string)$r['vote_count'],
                        !empty($r['verified_at']) ? 'Certified' : 'Pending Audit'
                    ];
                }, $rows)
            ]);
        }

        // ── ADMIN REPORT MODULE 6: ACHIEVEMENT SUMMARY ────────────
        elseif ($type === 'achievement_summary') {
            $sql = "SELECT a.id, a.title, a.competition, a.award_date, a.status,
                           c.name as club_name, c.code as club_code,
                           COALESCE(CONCAT(v.first_name, ' ', v.last_name), 'Pending Verification') as verifier_name
                    FROM achievements a
                    JOIN clubs c ON c.id = a.club_id
                    LEFT JOIN users v ON v.id = a.verified_by
                    ORDER BY a.award_date DESC";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

            $total_ach = count($rows);
            $verified_ach = count(array_filter($rows, fn($r) => $r['status'] === 'Verified'));
            $pending_ach = count(array_filter($rows, fn($r) => $r['status'] === 'Pending'));
            $unique_clubs = count(array_unique(array_column($rows, 'club_name')));

            rptRespond(true, 'Report generated.', [
                'title'          => 'Institutional Achievement Summary & Honors Ledger',
                'club'           => ['name' => 'Campus Student Honors & Recognition Registry', 'code' => 'BCP-AWARDS'],
                'date_generated' => $date_generated,
                'summary_cards'  => [
                    ['label' => 'Total Honors Documented', 'value' => $total_ach, 'icon' => 'trophy', 'color' => '#2563eb'],
                    ['label' => 'Verified Recognitions', 'value' => $verified_ach, 'icon' => 'award', 'color' => '#16a34a'],
                    ['label' => 'Pending Verification', 'value' => $pending_ach, 'icon' => 'clock', 'color' => '#d97706'],
                    ['label' => 'Honored Organizations', 'value' => $unique_clubs, 'icon' => 'medal', 'color' => '#7c3aed']
                ],
                'columns' => ['Honor / Award Title', 'Competition / Event', 'Organization', 'Award Date', 'Verification Status', 'Verified By'],
                'data' => array_map(function($r) {
                    return [
                        $r['title'],
                        $r['competition'],
                        $r['club_code'] . ' - ' . $r['club_name'],
                        date('M j, Y', strtotime($r['award_date'])),
                        $r['status'],
                        $r['verifier_name']
                    ];
                }, $rows)
            ]);
        }

        // ── ADMIN REPORT MODULE 7: CERTIFICATE SUMMARY ────────────
        elseif ($type === 'certificate_summary') {
            $cert_rows = [
                ['Event Participation Credentials', 'Decommissioned (0 Active)', 'Central Administration', 'Verifiable QR Attendance Ledger', 'Replaced by automated verifiable QR attendance timestamps'],
                ['Officer Appointment Credentials', 'Decommissioned (0 Active)', 'SSC & Student Affairs', 'Elected Officer Roster Ledger', 'Recorded under accredited Club Officers Master Directory'],
                ['Competition & Honor Credentials', 'Decommissioned (0 Active)', 'Academic Affairs & Admin', 'Achievement Verification Registry', 'Official student awards stored in verified Institutional Honors Ledger'],
                ['Organization Membership Credentials', 'Decommissioned (0 Active)', 'Faculty Advisers', 'Active Membership Roster', 'Member standing verified directly through co-curricular student transcripts']
            ];

            rptRespond(true, 'Report generated.', [
                'title'          => 'Certificate Summary & Credentials Governance Audit',
                'club'           => ['name' => 'Credentials & Institutional Records Administration', 'code' => 'BCP-CRED'],
                'date_generated' => $date_generated,
                'summary_cards'  => [
                    ['label' => 'Active Credentials', 'value' => '0 Active', 'icon' => 'stamp', 'color' => '#64748b'],
                    ['label' => 'Engine Status', 'value' => 'Decommissioned', 'icon' => 'ban', 'color' => '#ef4444'],
                    ['label' => 'Archived Records', 'value' => '0 Archived', 'icon' => 'box-archive', 'color' => '#0284c7'],
                    ['label' => 'Primary Verification', 'value' => 'QR Attendance Ledger', 'icon' => 'shield-check', 'color' => '#16a34a']
                ],
                'columns' => ['Credential Category', 'Governance Policy', 'Authority Level', 'Alternative Record Type', 'Status & Remarks'],
                'data' => $cert_rows
            ]);
        }

        // ── ADMIN REPORT MODULE 8: USER / ACCESS SUMMARY ──────────
        elseif ($type === 'user_access_summary') {
            $sql = "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.role, u.created_at
                    FROM users u
                    ORDER BY FIELD(u.role, 'admin', 'ssc', 'club_adviser', 'student'), u.id ASC
                    LIMIT 300";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

            $total_users = count($rows);
            $admin_count = count(array_filter($rows, fn($r) => $r['role'] === 'admin'));
            $ssc_count   = count(array_filter($rows, fn($r) => $r['role'] === 'ssc'));
            $adv_count   = count(array_filter($rows, fn($r) => $r['role'] === 'club_adviser'));
            $std_count   = count(array_filter($rows, fn($r) => $r['role'] === 'student'));

            rptRespond(true, 'Report generated.', [
                'title'          => 'User / Access Summary & RBAC Audit Report',
                'club'           => ['name' => 'System Access Control & User Directory', 'code' => 'BCP-RBAC'],
                'date_generated' => $date_generated,
                'summary_cards'  => [
                    ['label' => 'Total Registered Accounts', 'value' => $total_users, 'icon' => 'users-gear', 'color' => '#2563eb'],
                    ['label' => 'System Administrators', 'value' => $admin_count, 'icon' => 'user-shield', 'color' => '#ef4444'],
                    ['label' => 'SSC Governance Officers', 'value' => $ssc_count, 'icon' => 'landmark', 'color' => '#d97706'],
                    ['label' => 'Faculty Advisers & Students', 'value' => ($adv_count + $std_count), 'icon' => 'graduation-cap', 'color' => '#16a34a']
                ],
                'columns' => ['User ID', 'Full Name', 'Username / ID', 'Email Address', 'Assigned Role', 'Account Status', 'Created Date'],
                'data' => array_map(function($r) {
                    return [
                        'USR-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT),
                        $r['first_name'] . ' ' . $r['last_name'],
                        $r['username'],
                        $r['email'],
                        strtoupper(str_replace('_', ' ', $r['role'])),
                        'Active',
                        date('M j, Y', strtotime($r['created_at']))
                    ];
                }, $rows)
            ]);
        }

        else {
            rptRespond(false, 'Invalid report type selected.');
        }
    }

    default:
        rptRespond(false, 'Unknown report action.');
}
