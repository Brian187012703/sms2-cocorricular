<?php
// ============================================================
//  SEARCH_ACTIONS.PHP (shared/)
//  Role-Based Access Control (RBAC) Search API
//  Strictly searches authorized pages, modules, events, and clubs.
// ============================================================
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$query     = trim($_GET['q'] ?? '');
$q_lower   = strtolower($query);

// ── 1. ROLE-BASED ACCESS CONTROL (RBAC) MODULE REGISTRY ──────
$all_modules = [
    [
        'title'       => 'Dashboard Overview',
        'url'         => '../dashboard/dashboard.php',
        'icon'        => 'fa-solid fa-gauge-high',
        'description' => 'System metrics, upcoming schedules, and news feeds',
        'keywords'    => 'dashboard home overview metrics analytics feeds schedule notifications',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin', 'finance_officer'],
    ],
    [
        'title'       => 'Organization Directory',
        'url'         => '../dashboard/club_directory.php',
        'icon'        => 'fa-solid fa-sitemap',
        'description' => 'Browse recognized student clubs and organizations',
        'keywords'    => 'organizations clubs directory charter join apply officers leaders',
        'roles'       => ['student', 'ssc', 'admin'],
    ],
    [
        'title'       => ($user_role === 'student') ? 'Membership Roster' : 'Applicant Queue & Member Roster',
        'url'         => '../dashboard/roster.php',
        'icon'        => 'fa-solid fa-users',
        'description' => ($user_role === 'student') ? 'View your joined organizations and fellow members' : 'Manage student applications and active members',
        'keywords'    => 'roster members applicants applications governance approve endorse letter intent',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'Elections & Voting',
        'url'         => '../dashboard/elections.php',
        'icon'        => 'fa-solid fa-check-to-slot',
        'description' => 'Official student council and club election ballots',
        'keywords'    => 'elections vote ballot candidate president winners voting results polls',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'Events & Activities',
        'url'         => '../dashboard/events.php',
        'icon'        => 'fa-solid fa-calendar-days',
        'description' => 'Campus calendar, activity proposals, and schedules',
        'keywords'    => 'events calendar activities schedule seminars workshops campus schedule month',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'My Attendance History',
        'url'         => '../dashboard/tracking_history.php',
        'icon'        => 'fa-solid fa-clipboard-user',
        'description' => 'Your personal verified event attendance records',
        'keywords'    => 'my attendance history logs check in verified events service records',
        'roles'       => ['student'],
    ],
    [
        'title'       => 'Event QR Generator',
        'url'         => '../dashboard/tracking_qr_generator.php',
        'icon'        => 'fa-solid fa-print',
        'description' => 'Generate and print official attendance posters',
        'keywords'    => 'qr generator print poster venue attendance tracking portal',
        'roles'       => ['club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'On-Site Scanner Terminal',
        'url'         => '../dashboard/tracking_scanner.php',
        'icon'        => 'fa-solid fa-camera',
        'description' => 'Live camera scanning terminal for badge check-ins',
        'keywords'    => 'scanner camera attendance verify check in terminal entry badge rfid',
        'roles'       => ['club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'Attendance List per Event',
        'url'         => '../dashboard/tracking_attendance_list.php',
        'icon'        => 'fa-solid fa-clipboard-list',
        'description' => 'Real-time attendee rosters and CSV export',
        'keywords'    => 'attendance list records present logs roster participants export csv',
        'roles'       => ['club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'Budget & Finance',
        'url'         => '../dashboard/budget.php',
        'icon'        => 'fa-solid fa-hand-holding-dollar',
        'description' => 'Financial allocations and expense requisitions',
        'keywords'    => 'budget finance money disbursements requisitions proposals funding expenses',
        'roles'       => ['club_adviser', 'ssc', 'admin', 'finance_officer'],
    ],
    [
        'title'       => 'Intelligent Reports & Analytics',
        'url'         => '../dashboard/reports.php',
        'icon'        => 'fa-solid fa-brain',
        'description' => 'AI-powered co-curricular insights and summaries',
        'keywords'    => 'reports analytics ai insights intelligence audit summary statistics charts',
        'roles'       => ['club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'System Administration & Security',
        'url'         => '../dashboard/admin_system.php',
        'icon'        => 'fa-solid fa-shield-halved',
        'description' => 'User accounts, permissions, and security controls',
        'keywords'    => 'admin system users accounts roles security audit logs reset permissions',
        'roles'       => ['ssc', 'admin'],
    ],
    [
        'title'       => 'Campus Announcements',
        'url'         => '../dashboard/announcements.php',
        'icon'        => 'fa-solid fa-bullhorn',
        'description' => 'Official institutional notices and updates',
        'keywords'    => 'announcements news notice bulletins updates broadcast',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'Community Broadcasts',
        'url'         => '../dashboard/broadcasts.php',
        'icon'        => 'fa-solid fa-tower-broadcast',
        'description' => 'Organization feeds and community updates',
        'keywords'    => 'broadcasts feed community social posts discussions updates',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin'],
    ],
    [
        'title'       => 'Account & Profile Settings',
        'url'         => '../dashboard/account.php',
        'icon'        => 'fa-solid fa-user-gear',
        'description' => 'Update password, profile photo, and preferences',
        'keywords'    => 'account profile settings password avatar picture security student info',
        'roles'       => ['student', 'club_adviser', 'ssc', 'admin', 'finance_officer'],
    ],
];

// Filter Modules by strict RBAC and user search query
$matching_modules = [];
foreach ($all_modules as $mod) {
    if (!in_array($user_role, $mod['roles'])) continue;
    if (empty($query) || str_contains(strtolower($mod['title'] . ' ' . $mod['keywords'] . ' ' . $mod['description']), $q_lower)) {
        $matching_modules[] = [
            'title'       => $mod['title'],
            'url'         => $mod['url'],
            'icon'        => $mod['icon'],
            'description' => $mod['description'],
            'type'        => 'Module',
        ];
    }
}

// ── 2. SEARCH EVENTS (ROLE PERMITTED) ─────────────────────────
$matching_events = [];
$event_sql = "
    SELECT e.id, e.title, e.event_date, e.venue, e.status, c.name as club_name, c.code as club_code
    FROM events e
    LEFT JOIN clubs c ON c.id = e.club_id
    WHERE e.status IN ('Approved','Upcoming','Completed')
";
if (!empty($query)) {
    $esc = $conn->real_escape_string($query);
    $event_sql .= " AND (e.title LIKE '%$esc%' OR e.venue LIKE '%$esc%' OR c.name LIKE '%$esc%' OR c.code LIKE '%$esc%')";
}
$event_sql .= " ORDER BY e.event_date DESC LIMIT 6";
$ev_res = $conn->query($event_sql);
if ($ev_res) {
    while ($ev = $ev_res->fetch_assoc()) {
        $dateStr = date('M d, Y', strtotime($ev['event_date']));
        $matching_events[] = [
            'title'       => $ev['title'],
            'url'         => '../dashboard/events.php?event_id=' . $ev['id'],
            'icon'        => 'fa-solid fa-calendar-check',
            'description' => "{$dateStr} &bull; " . ($ev['venue'] ?? 'Campus Venue') . " (" . ($ev['club_code'] ?? 'BCP') . ")",
            'type'        => 'Event',
        ];
    }
}

// ── 3. SEARCH CLUBS (FOR ROLES WITH CLUB DIRECTORY ACCESS) ────
$matching_clubs = [];
if (in_array($user_role, ['student', 'ssc', 'admin'])) {
    $club_sql = "SELECT id, name, code, category FROM clubs WHERE status = 'Active'";
    if (!empty($query)) {
        $esc = $conn->real_escape_string($query);
        $club_sql .= " AND (name LIKE '%$esc%' OR code LIKE '%$esc%' OR category LIKE '%$esc%')";
    }
    $club_sql .= " ORDER BY name ASC LIMIT 5";
    $cl_res = $conn->query($club_sql);
    if ($cl_res) {
        while ($cl = $cl_res->fetch_assoc()) {
            $matching_clubs[] = [
                'title'       => "{$cl['name']} ({$cl['code']})",
                'url'         => '../dashboard/club_directory.php?search=' . urlencode($cl['code']),
                'icon'        => 'fa-solid fa-sitemap',
                'description' => $cl['category'] . ' Organization',
                'type'        => 'Organization',
            ];
        }
    }
}

echo json_encode([
    'success' => true,
    'query'   => $query,
    'results' => [
        'modules' => array_slice($matching_modules, 0, 6),
        'events'  => $matching_events,
        'clubs'   => $matching_clubs,
    ],
    'total'   => count($matching_modules) + count($matching_events) + count($matching_clubs),
]);
