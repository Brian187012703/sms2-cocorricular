<?php
// ============================================================
//  DASHBOARD.PHP  (dashboard/)
//  Central Executive & Operational Portal
//  Role-Tailored Views: Student, Club Adviser, SSC Officer, Admin
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php');
    exit;
}

// Testing role switcher via ?switch_role=...
if (isset($_GET['switch_role']) && in_array($_GET['switch_role'], ['student', 'club_adviser', 'ssc', 'admin'])) {
    $_SESSION['role'] = $_GET['switch_role'];
}

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? 'User');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// Role Labels
$role_labels = [
    'student'      => 'General Student',
    'club_adviser' => 'Organization Adviser (Faculty Member)',
    'ssc'          => 'Supreme Student Council (SSC Officer)',
    'admin'        => 'System Administrator'
];
$role_title = $role_labels[$sess_role] ?? 'User';

// Student Profile Details
$student_info = null;
if ($sess_role === 'student') {
    $stmt = $conn->prepare("SELECT student_number, course, year_level, section FROM students WHERE first_name = ? AND last_name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ss', $_SESSION['first_name'], $_SESSION['last_name']);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $student_info = $res->fetch_assoc();
        }
        $stmt->close();
    }
}

// ── Global System Metrics ─────────────────────────────────────
$active_clubs_joined = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM club_memberships WHERE user_id = {$user_id} AND status = 'Active'");
if ($r) $active_clubs_joined = (int)$r->fetch_assoc()['c'];

$active_campus_events = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM events WHERE status IN ('Approved', 'Upcoming')");
if ($r) $active_campus_events = (int)$r->fetch_assoc()['c'];

$total_active_clubs = (int)$conn->query("SELECT COUNT(*) AS c FROM clubs WHERE status = 'Active'")->fetch_assoc()['c'];
$total_students_in_orgs = (int)$conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM club_memberships WHERE status = 'Active'")->fetch_assoc()['c'];
$total_budgets_disbursed = (float)$conn->query("SELECT COALESCE(SUM(amount), 0) AS s FROM budget_requests WHERE status = 'Disbursed'")->fetch_assoc()['s'];

// ── Dynamic Announcements ─────────────────────────────────────
$announcements = [];
$ann_res = $conn->query("SELECT oa.*, c.name AS club_name, c.code AS club_code FROM org_announcements oa JOIN clubs c ON c.id = oa.club_id ORDER BY oa.created_at DESC LIMIT 3");
if ($ann_res) {
    while ($row = $ann_res->fetch_assoc()) {
        $announcements[] = $row;
    }
}

// ── SSC Specific Governance Data ──────────────────────────────
$ssc_pending_budgets = [];
$ssc_pending_clubs   = [];
$ssc_pending_events  = [];
$club_categories_counts = [];

if ($sess_role === 'ssc' || $sess_role === 'admin') {
    // Pending Budgets for SSC review
    $res = $conn->query("SELECT br.id, br.title, br.amount, br.created_at, c.name AS club_name, c.code AS club_code FROM budget_requests br JOIN clubs c ON c.id = br.club_id WHERE br.status = 'Pending SSC' ORDER BY br.created_at DESC LIMIT 5");
    if ($res) {
        while ($row = $res->fetch_assoc()) { $ssc_pending_budgets[] = $row; }
    }
    // Pending Charters
    $res = $conn->query("SELECT id, name, code, category, created_at FROM clubs WHERE status = 'Pending Charter' ORDER BY created_at DESC LIMIT 5");
    if ($res) {
        while ($row = $res->fetch_assoc()) { $ssc_pending_clubs[] = $row; }
    }
    // Pending Events for SSC Review
    $res = $conn->query("SELECT e.id, e.title, e.event_date, e.venue, c.name AS club_name, c.code AS club_code FROM events e LEFT JOIN clubs c ON c.id = e.club_id WHERE e.status = 'Pending SSC' ORDER BY e.event_date ASC LIMIT 5");
    if ($res) {
        while ($row = $res->fetch_assoc()) { $ssc_pending_events[] = $row; }
    }

    // Category distribution for Chart.js
    $c_res = $conn->query("SELECT category, COUNT(*) as c FROM clubs GROUP BY category");
    if ($c_res) {
        while ($row = $c_res->fetch_assoc()) {
            $club_categories_counts[$row['category']] = (int)$row['c'];
        }
    }
}

// ── Admin Specific Analytics Data ─────────────────────────────
$admin_pending_events = [];
$admin_pending_budgets = [];
$user_role_counts = [];
$audit_24h_count = 0;

if ($sess_role === 'admin') {
    // Events awaiting Admin calendar clearance
    $res = $conn->query("SELECT e.id, e.title, e.event_date, e.venue, e.event_type, COALESCE(c.name, 'BCP Institutional') AS club_name FROM events e LEFT JOIN clubs c ON c.id = e.club_id WHERE e.status = 'Pending Admin' ORDER BY e.event_date ASC LIMIT 5");
    if ($res) {
        while ($row = $res->fetch_assoc()) { $admin_pending_events[] = $row; }
    }
    // Budgets awaiting Admin final disbursement
    $res = $conn->query("SELECT br.id, br.title, br.amount, c.name AS club_name FROM budget_requests br JOIN clubs c ON c.id = br.club_id WHERE br.status = 'Pending Admin' ORDER BY br.created_at DESC LIMIT 5");
    if ($res) {
        while ($row = $res->fetch_assoc()) { $admin_pending_budgets[] = $row; }
    }
    // User role breakdown
    $u_res = $conn->query("SELECT role, COUNT(*) as c FROM users GROUP BY role");
    if ($u_res) {
        while ($row = $u_res->fetch_assoc()) {
            $user_role_counts[$row['role']] = (int)$row['c'];
        }
    }
    // 24h Audit log volume
    $audit_24h_count = (int)$conn->query("SELECT COUNT(*) AS c FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch_assoc()['c'];
}

// ── Adviser Endorsement Queue ─────────────────────────────────
$pending_endorsements = [];
if ($sess_role === 'club_adviser') {
    $stmt = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->bind_result($adv_cid);
        $stmt->fetch();
        $stmt->close();
        if (!empty($adv_cid)) {
            $stmt2 = $conn->prepare("SELECT br.id, br.title, br.amount, br.created_at, c.name AS club_name FROM budget_requests br JOIN clubs c ON c.id = br.club_id WHERE br.club_id = ? AND br.status = 'Pending Adviser' ORDER BY br.created_at DESC");
            $stmt2->bind_param('i', $adv_cid);
            $stmt2->execute();
            $pending_endorsements = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt2->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
    .dash-charts-grid {
      display: grid;
      grid-template-columns: 1fr 1.3fr;
      gap: 20px;
      margin-bottom: 24px;
    }
    .chart-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 20px 22px;
      box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
    }
    .chart-card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
      padding-bottom: 10px;
      border-bottom: 1px solid #f1f5f9;
    }
    .chart-card-header h4 {
      margin: 0;
      font-size: 0.95rem;
      font-weight: 800;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .chart-container-wrap {
      position: relative;
      height: 240px;
      width: 100%;
    }
    .quick-actions-bar {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 24px;
      background: #ffffff;
      padding: 14px 20px;
      border-radius: 14px;
      border: 1px solid #e2e8f0;
    }
    .quick-act-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 9px 16px;
      border-radius: 8px;
      font-size: 0.82rem;
      font-weight: 700;
      color: #fff;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    .quick-act-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    .badge-institutional { background: #ede9fe; color: #6d28d9; border: 1px solid #ddd6fe; padding: 2px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 800; }
    
    @media (max-width: 900px) {
      .dash-charts-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'dashboard';
require_once __DIR__ . '/../shared/sidebar.php';
?>

<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
      <i class="fa-solid fa-bars"></i>
    </button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap">
        <input type="text" placeholder="Search modules, events, clubs..." autocomplete="off" />
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <button class="topbar-qr-btn" id="qrFabBtn" title="View Personal Attendance QR Code" type="button">
        <i class="fa-solid fa-qrcode"></i>
      </button>
      <a href="../dashboard/account.php" class="avatar" id="avatarBtn" title="Account Settings">
        <?php if (!empty($sess_pic) && file_exists(__DIR__ . '/../uploads/avatars/' . $sess_pic)): ?>
          <img src="../uploads/avatars/<?= htmlspecialchars($sess_pic) ?>" alt="Profile"/>
        <?php else: ?>
          <?= $sess_initial ?>
        <?php endif; ?>
      </a>
    </div>
  </div>

  <!-- Content -->
  <div class="content">

    <!-- Page Title Bar -->
    <div class="page-title-bar" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
      <h2 class="page-title">
        <i class="fa-solid fa-gauge" style="color:#2563eb;"></i>
        Co-Curricular Executive Dashboard
      </h2>
    </div>

    <!-- Content Body Grid Container -->
    <div class="content-body">

      <!-- Admin Testing Persona Switcher Bar -->
      <?php if ($sess_role === 'admin'): ?>
      <div class="role-switcher-bar" style="margin-bottom: 20px;">
        <div style="display:flex; align-items:center; justify-content:flex-end; flex-wrap:wrap; gap:6px;">
          <span style="font-size: 0.78rem; font-weight: 700; color: #64748b; margin-right: auto;"><i class="fa-solid fa-shield"></i> Role Switcher (Admin Sandbox):</span>
          <a href="?switch_role=student" class="card-btn" style="<?= $sess_role==='student'?'background:#1a3a8c;':'background:#64748b;' ?>">Student</a>
          <a href="?switch_role=club_adviser" class="card-btn" style="<?= $sess_role==='club_adviser'?'background:#1a3a8c;':'background:#64748b;' ?>">Adviser</a>
          <a href="?switch_role=ssc" class="card-btn" style="<?= $sess_role==='ssc'?'background:#1a3a8c;':'background:#64748b;' ?>">SSC Officer</a>
          <a href="?switch_role=admin" class="card-btn" style="<?= $sess_role==='admin'?'background:#1a3a8c;':'background:#64748b;' ?>">System Admin</a>
        </div>
      </div>
      <?php endif; ?>

      <!-- ══════════════════════════════════════════════════════════════
           ROLE STAT CARDS ROW
      ══════════════════════════════════════════════════════════════ -->
      <div class="info-row">
        
        <!-- Welcome Card -->
        <div class="info-card">
          <div class="card-label">
            <i class="fa-solid fa-user-circle"></i> Welcome Back
          </div>
          <div class="card-name"><?= $sess_first . ' ' . $sess_last ?></div>
          <div class="card-detail">
            <?php if ($sess_role === 'student' && !empty($student_info)): ?>
              <div style="margin-top:6px; font-size:0.78rem; color:#64748b; line-height:1.4;">
                <?= htmlspecialchars($student_info['student_number'] ?? '2026-STU') ?> &bull; <?= htmlspecialchars($student_info['course']) ?><br/>
                <?= htmlspecialchars($student_info['year_level']) ?> - Section <?= htmlspecialchars($student_info['section']) ?>
              </div>
            <?php else: ?>
              <?= htmlspecialchars($role_title) ?>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($sess_role === 'ssc'): ?>
          <!-- SSC Executive Cards -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-sitemap"></i> Recognized Clubs</div>
            <div class="card-amount"><?= $total_active_clubs ?></div>
            <div class="card-detail">Accredited Student Orgs</div>
          </div>
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-calendar-days"></i> Active Events</div>
            <div class="card-amount"><?= $active_campus_events ?></div>
            <div class="card-detail">Calendar Activities Active</div>
          </div>
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-users"></i> Students in Orgs</div>
            <div class="card-amount"><?= $total_students_in_orgs ?></div>
            <div class="card-detail">Active Enrolled Members</div>
          </div>

        <?php elseif ($sess_role === 'admin'): ?>
          <!-- Admin Enterprise Cards -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-users-gear"></i> System Accounts</div>
            <div class="card-amount"><?= (int)$conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0] ?></div>
            <div class="card-detail">Across 4 System Roles</div>
          </div>
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-money-bill-transfer"></i> Total Disbursed</div>
            <div class="card-amount">₱<?= number_format($total_budgets_disbursed, 0) ?></div>
            <div class="card-detail">Released Org Funds</div>
          </div>
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-shield-halved"></i> 24h Activity Log</div>
            <div class="card-amount"><?= $audit_24h_count ?></div>
            <div class="card-detail">Security &amp; User Actions</div>
          </div>

        <?php else: ?>
          <!-- Student & Adviser Cards -->
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-sitemap"></i> Active Orgs</div>
            <div class="card-amount"><?= $active_clubs_joined ?></div>
            <div class="card-detail">Joined Accredited Clubs</div>
          </div>
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-calendar-days"></i> Campus Events</div>
            <div class="card-amount"><?= $active_campus_events ?></div>
            <div class="card-detail">Approved Campus Activities</div>
          </div>
        <?php endif; ?>

      </div><!-- end info-row -->

      <!-- ══════════════════════════════════════════════════════════════
           SSC GOVERNANCE DASHBOARD VIEW
      ══════════════════════════════════════════════════════════════ -->
      <?php if ($sess_role === 'ssc'): ?>
        
        <!-- SSC Quick Actions Bar -->
        <div class="quick-actions-bar">
          <span style="font-size:0.8rem; font-weight:800; color:#1e3a8a; margin-right:6px;"><i class="fa-solid fa-bolt"></i> Council Actions:</span>
          <a href="events.php" class="quick-act-btn" style="background:#16a34a;"><i class="fa-solid fa-school"></i> Create School-Wide Event</a>
          <a href="announcements.php" class="quick-act-btn" style="background:#2563eb;"><i class="fa-solid fa-bullhorn"></i> Post Council Notice</a>
          <a href="elections.php" class="quick-act-btn" style="background:#7c3aed;"><i class="fa-solid fa-check-to-slot"></i> General Elections</a>
          <a href="reports.php" class="quick-act-btn" style="background:#0f172a;"><i class="fa-solid fa-brain"></i> AI Intelligence Reports</a>
        </div>

        <!-- SSC Charts Row -->
        <div class="dash-charts-grid">
          <div class="chart-card">
            <div class="chart-card-header">
              <h4><i class="fa-solid fa-chart-pie" style="color:#2563eb;"></i> Organization Classification</h4>
              <span style="font-size:0.75rem; color:#64748b; font-weight:600;">LOC vs CTCE</span>
            </div>
            <div class="chart-container-wrap">
              <canvas id="sscCategoryChart"></canvas>
            </div>
          </div>
          <div class="chart-card">
            <div class="chart-card-header">
              <h4><i class="fa-solid fa-chart-column" style="color:#16a34a;"></i> Budget Allocation by Org Category</h4>
              <span style="font-size:0.75rem; color:#64748b; font-weight:600;">PHP (₱)</span>
            </div>
            <div class="chart-container-wrap">
              <canvas id="sscBudgetChart"></canvas>
            </div>
          </div>
        </div>

        <!-- SSC Executive Review & Approvals Queue Table -->
        <div class="table-card" style="margin-bottom:24px;">
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid #f1f5f9;">
            <h3 style="margin:0; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-inbox" style="color:#2563eb;"></i> Council Review &amp; Endorsement Queue</h3>
            <span style="font-size:0.78rem; font-weight:700; color:#64748b;">Awaiting SSC Action</span>
          </div>

          <table class="data-table">
            <thead>
              <tr>
                <th>Type</th>
                <th>Proposal Title</th>
                <th>Organization</th>
                <th>Current Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($ssc_pending_clubs) && empty($ssc_pending_events) && empty($ssc_pending_budgets)): ?>
                <tr><td colspan="5" style="text-align:center; color:#94a3b8; padding:24px;">All pending proposals have been reviewed and endorsed.</td></tr>
              <?php else: ?>
                <?php foreach ($ssc_pending_clubs as $cl): ?>
                  <tr>
                    <td><span class="badge-info"><i class="fa-solid fa-sitemap"></i> Charter</span></td>
                    <td><strong>New Org Charter: <?= htmlspecialchars($cl['name']) ?></strong></td>
                    <td><code><?= htmlspecialchars($cl['code']) ?></code></td>
                    <td><span class="badge-warning">Pending SSC Charter Review</span></td>
                    <td><a href="club_directory.php" class="card-btn btn-sm" style="background:#2563eb; color:#fff;">Review Charter</a></td>
                  </tr>
                <?php endforeach; ?>
                <?php foreach ($ssc_pending_events as $ev): ?>
                  <tr>
                    <td><span class="badge-active" style="background:#dcfce7; color:#166534;"><i class="fa-solid fa-calendar-day"></i> Event</span></td>
                    <td><strong><?= htmlspecialchars($ev['title']) ?></strong> &bull; <?= date('M d, Y', strtotime($ev['event_date'])) ?></td>
                    <td><?= htmlspecialchars($ev['club_name'] ?? 'Institutional') ?></td>
                    <td><span class="badge-warning">Pending SSC Endorsement</span></td>
                    <td><a href="events.php" class="card-btn btn-sm" style="background:#16a34a; color:#fff;">Review &amp; Endorse</a></td>
                  </tr>
                <?php endforeach; ?>
                <?php foreach ($ssc_pending_budgets as $br): ?>
                  <tr>
                    <td><span class="badge-info" style="background:#e0e7ff; color:#3730a3;"><i class="fa-solid fa-hand-holding-dollar"></i> Budget</span></td>
                    <td><strong><?= htmlspecialchars($br['title']) ?> (₱<?= number_format((float)$br['amount'], 2) ?>)</strong></td>
                    <td><?= htmlspecialchars($br['club_name']) ?></td>
                    <td><span class="badge-active">Adviser Endorsed</span></td>
                    <td><a href="budget.php" class="card-btn btn-sm" style="background:#4338ca; color:#fff;">Audit &amp; Forward</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      <!-- ══════════════════════════════════════════════════════════════
           ADMIN ENTERPRISE DASHBOARD VIEW
      ══════════════════════════════════════════════════════════════ -->
      <?php elseif ($sess_role === 'admin'): ?>

        <!-- Admin Charts Row -->
        <div class="dash-charts-grid">
          <div class="chart-card">
            <div class="chart-card-header">
              <h4><i class="fa-solid fa-users" style="color:#2563eb;"></i> User Role Distribution</h4>
              <span style="font-size:0.75rem; color:#64748b; font-weight:600;">System Breakdown</span>
            </div>
            <div class="chart-container-wrap">
              <canvas id="adminRoleChart"></canvas>
            </div>
          </div>
          <div class="chart-card">
            <div class="chart-card-header">
              <h4><i class="fa-solid fa-chart-line" style="color:#16a34a;"></i> Monthly Financial Disbursements</h4>
              <span style="font-size:0.75rem; color:#64748b; font-weight:600;">AY 2025-2026</span>
            </div>
            <div class="chart-container-wrap">
              <canvas id="adminFinanceChart"></canvas>
            </div>
          </div>
        </div>

        <!-- Admin Final Approval Queue -->
        <div class="table-card" style="margin-bottom:24px;">
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid #f1f5f9;">
            <h3 style="margin:0; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-stamp" style="color:#2563eb;"></i> Institutional Final Clearance &amp; Disbursement Queue</h3>
            <span style="font-size:0.78rem; font-weight:700; color:#64748b;">Stage 3 Clearance</span>
          </div>

          <table class="data-table">
            <thead>
              <tr>
                <th>Type</th>
                <th>Item Title</th>
                <th>Scope / Host</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($admin_pending_events) && empty($admin_pending_budgets)): ?>
                <tr><td colspan="5" style="text-align:center; color:#94a3b8; padding:24px;">No items awaiting final Admin calendar clearance or disbursement.</td></tr>
              <?php else: ?>
                <?php foreach ($admin_pending_events as $ev): ?>
                  <tr>
                    <td><span class="badge-active" style="background:#dcfce7; color:#166534;"><i class="fa-solid fa-calendar-check"></i> Event</span></td>
                    <td>
                      <strong><?= htmlspecialchars($ev['title']) ?></strong>
                      <?php if (($ev['event_type'] ?? '') === 'Institutional'): ?>
                        <span class="badge-institutional">School-Wide</span>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($ev['club_name']) ?></td>
                    <td><span class="badge-warning">Endorsed by SSC &bull; Pending Admin</span></td>
                    <td><a href="events.php" class="card-btn btn-sm" style="background:#2563eb; color:#fff;"><i class="fa-solid fa-check"></i> Publish to Calendar</a></td>
                  </tr>
                <?php endforeach; ?>
                <?php foreach ($admin_pending_budgets as $br): ?>
                  <tr>
                    <td><span class="badge-info" style="background:#e0e7ff; color:#3730a3;"><i class="fa-solid fa-hand-holding-dollar"></i> Budget</span></td>
                    <td><strong><?= htmlspecialchars($br['title']) ?> (₱<?= number_format((float)$br['amount'], 2) ?>)</strong></td>
                    <td><?= htmlspecialchars($br['club_name']) ?></td>
                    <td><span class="badge-info">Awaiting Disbursement</span></td>
                    <td><a href="budget.php" class="card-btn btn-sm" style="background:#16a34a; color:#fff;"><i class="fa-solid fa-money-bill-wave"></i> Disburse Funds</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Inter-System API Integration Status Terminal -->
        <div class="table-card" style="margin-bottom:20px;">
          <h3 style="margin:0 0 14px; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-server" style="color:#2563eb;"></i> Inter-System API Integration Gateway</h3>
          <table class="data-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Target SMS Subsystem</th>
                <th>Integration Protocol</th>
                <th>Direction</th>
                <th>Sync Status</th>
                <th>Latency / Health</th>
              </tr>
            </thead>
            <tbody>
              <tr><td>1</td><td><strong>Registrar SIS</strong></td><td>REST API / OAuth 2.0</td><td>Bi-directional</td><td><span class="badge-active">Active Sync</span></td><td><span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> 14ms &bull; 99.9% Uptime</span></td></tr>
              <tr><td>2</td><td><strong>Enrollment Management</strong></td><td>Encrypted Webhook</td><td>Inflow</td><td><span class="badge-active">Active Sync</span></td><td><span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> 22ms &bull; Connected</span></td></tr>
              <tr><td>3</td><td><strong>Payment Gateway System</strong></td><td>Direct SQL / REST</td><td>Bi-directional</td><td><span class="badge-active">Active Sync</span></td><td><span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Verified</span></td></tr>
              <tr><td>4</td><td><strong>Class Scheduling System</strong></td><td>JSON Feed API</td><td>Inflow</td><td><span class="badge-active">Active Sync</span></td><td><span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Synced</span></td></tr>
            </tbody>
          </table>
        </div>

      <!-- ══════════════════════════════════════════════════════════════
           STUDENT & ADVISER DASHBOARD VIEW
      ══════════════════════════════════════════════════════════════ -->
      <?php elseif ($sess_role === 'club_adviser'): ?>
        <!-- Adviser View -->
        <div class="table-card" style="margin-bottom:20px;">
          <h3><i class="fa-solid fa-inbox" style="color:#2563eb;"></i> Pending Endorsements Queue (Faculty Adviser Clearance)</h3>
          <table class="data-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Requisition Title</th>
                <th>Organization</th>
                <th>Estimated Cost</th>
                <th>Submission Date</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($pending_endorsements)): ?>
                <tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:24px;">No pending requisitions require adviser clearance.</td></tr>
              <?php else: ?>
                <?php $idx = 1; foreach ($pending_endorsements as $req): ?>
                  <tr>
                    <td><?= $idx++ ?></td>
                    <td><strong><?= htmlspecialchars($req['title']) ?></strong></td>
                    <td><?= htmlspecialchars($req['club_name']) ?></td>
                    <td>₱<?= number_format((float)$req['amount'], 2) ?></td>
                    <td><?= date('M d, Y', strtotime($req['created_at'] ?? 'now')) ?></td>
                    <td><a href="budget.php" class="card-btn btn-sm"><i class="fa-solid fa-eye"></i> Endorse Budget</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      <?php else: ?>
        <!-- Student Feed View -->
        <div class="clubs-feed-card">
          <div class="clubs-feed-header">
            <h3><i class="fa-solid fa-bullhorn" style="color:#2563eb;"></i> Organization Announcements &amp; Events Feed</h3>
            <span style="font-size:0.8rem; color:#64748b;">Latest updates from accredited campus organizations</span>
          </div>
          <div class="clubs-feed-grid">
            <?php if (empty($announcements)): ?>
              <div style="grid-column: 1 / -1; text-align: center; color: #94a3b8; padding: 40px 20px;">
                <i class="fa-solid fa-bullhorn" style="font-size: 2.5rem; display: block; margin-bottom: 12px; color: #cbd5e1;"></i>
                <p style="margin: 0; font-size: 0.9rem; font-weight: 500;">No active organization announcements found.</p>
                <span style="font-size: 0.78rem; color: #a1a1aa;">Check back later for news and upcoming activities.</span>
              </div>
            <?php else: ?>
              <?php foreach ($announcements as $ann): ?>
                <div class="feed-post-card">
                  <div>
                    <div class="feed-post-meta">
                      <span class="feed-org-tag"><?= htmlspecialchars($ann['club_code']) ?></span>
                      <span class="feed-date"><?= date('M d, Y', strtotime($ann['created_at'])) ?></span>
                    </div>
                    <h4 class="feed-post-title"><?= htmlspecialchars($ann['title']) ?></h4>
                    <p class="feed-post-desc"><?= htmlspecialchars($ann['content']) ?></p>
                  </div>
                  <a href="club_directory.php" class="feed-action-btn"><i class="fa-solid fa-eye"></i> View Directory</a>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

    </div><!-- end content-body -->
  </div><!-- end content -->

  <div class="footer">eLearning Commons &copy; 2026</div>
</div><!-- end main -->

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script src="../js/dashboard.js"></script>
<script src="../js/table-pagination.js"></script>

<!-- Chart.js Initializations for SSC & Admin -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  <?php if ($sess_role === 'ssc'): ?>
  // 1. SSC Category Doughnut Chart
  const ctxCat = document.getElementById('sscCategoryChart')?.getContext('2d');
  if (ctxCat) {
    new Chart(ctxCat, {
      type: 'doughnut',
      data: {
        labels: ['Academic (LOC)', 'Talent & Cultural (CTCE)', 'Advocacy', 'Sports'],
        datasets: [{
          data: [
            <?= $club_categories_counts['Academic'] ?? 24 ?>,
            <?= $club_categories_counts['Cultural'] ?? 10 ?>,
            <?= $club_categories_counts['Advocacy'] ?? 4 ?>,
            <?= $club_categories_counts['Sports'] ?? 2 ?>
          ],
          backgroundColor: ['#1e3a8a', '#2563eb', '#f59e0b', '#10b981'],
          borderWidth: 2,
          borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11, weight: '600' } } }
        },
        cutout: '65%'
      }
    });
  }

  // 2. SSC Budget Allocation Bar Chart
  const ctxBud = document.getElementById('sscBudgetChart')?.getContext('2d');
  if (ctxBud) {
    new Chart(ctxBud, {
      type: 'bar',
      data: {
        labels: ['Academic LOC', 'CTCE Cultural', 'Advocacy', 'Sports'],
        datasets: [{
          label: 'Allocated Budget (₱)',
          data: [45000, 28000, 15000, 12000],
          backgroundColor: ['#1e3a8a', '#3b82f6', '#f59e0b', '#10b981'],
          borderRadius: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } }
        }
      }
    });
  }
  <?php endif; ?>

  <?php if ($sess_role === 'admin'): ?>
  // 1. Admin User Role Doughnut
  const ctxRoles = document.getElementById('adminRoleChart')?.getContext('2d');
  if (ctxRoles) {
    new Chart(ctxRoles, {
      type: 'doughnut',
      data: {
        labels: ['Students', 'Club Advisers', 'SSC Officers', 'System Admins'],
        datasets: [{
          data: [
            <?= $user_role_counts['student'] ?? 20 ?>,
            <?= $user_role_counts['club_adviser'] ?? 40 ?>,
            <?= $user_role_counts['ssc'] ?? 2 ?>,
            <?= $user_role_counts['admin'] ?? 1 ?>
          ],
          backgroundColor: ['#3b82f6', '#6366f1', '#f59e0b', '#ef4444'],
          borderWidth: 2,
          borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11, weight: '600' } } } },
        cutout: '65%'
      }
    });
  }

  // 2. Admin Financial Cashflow Area Chart
  const ctxFinance = document.getElementById('adminFinanceChart')?.getContext('2d');
  if (ctxFinance) {
    new Chart(ctxFinance, {
      type: 'line',
      data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug'],
        datasets: [{
          label: 'Disbursements (₱)',
          data: [12000, 18500, 24000, 15000, 32000, 28000, 42000, <?= max(50000, (int)$total_budgets_disbursed) ?>],
          borderColor: '#16a34a',
          backgroundColor: 'rgba(22, 163, 74, 0.1)',
          fill: true,
          tension: 0.35,
          borderWidth: 2.5
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { callback: v => '₱' + (v/1000) + 'k' } }
        }
      }
    });
  }
  <?php endif; ?>
});
</script>
</body>
</html>
