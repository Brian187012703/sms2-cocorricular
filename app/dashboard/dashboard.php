<?php
// ============================================================
//  DASHBOARD.PHP  (dashboard/)
//  Co-Curricular Management System — Aligned to Template Design
// ============================================================
session_start();
require_once __DIR__ . '/../shared/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php');
    exit;
}

// Allow testing role switcher via ?switch_role=...
if (isset($_GET['switch_role']) && in_array($_GET['switch_role'], ['student','club_adviser','osa_director','finance_officer','admin'])) {
    $_SESSION['role'] = $_GET['switch_role'];
}

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? 'User');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// Role Titles map
$role_labels = [
    'student'         => 'General Student',
    'club_adviser'    => 'Club Adviser (Faculty Member)',
    'osa_director'    => 'OSA Director / Coordinator',
    'finance_officer' => 'Finance / Cashier Officer',
    'admin'           => 'System Administrator'
];

$role_title = $role_labels[$sess_role] ?? 'User';

$user_id = (int)($_SESSION['user_id'] ?? 0);

// DB Counts
$active_clubs_joined = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM club_memberships WHERE user_id = {$user_id} AND status = 'Active'");
if ($r) $active_clubs_joined = (int)$r->fetch_assoc()['c'];

$active_campus_events = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM events WHERE status IN ('Approved', 'Upcoming')");
if ($r) $active_campus_events = (int)$r->fetch_assoc()['c'];

$total_budgets = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM budget_requests"); if ($r) $total_budgets = (int)$r->fetch_assoc()['c'];
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
        <input type="text" placeholder="Search..."/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <button class="topbar-qr-btn" id="qrFabBtn" title="View Personal Attendance QR Code" type="button">
        <i class="fa-solid fa-qrcode"></i>
      </button>
      <a href="../dashboard/account.php" class="avatar" id="avatarBtn" title="Account Settings">
        <?= $sess_initial ?>
      </a>
    </div>
  </div>

  <!-- Content -->
  <div class="content">

    <!-- Page Title Bar -->
    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-gauge"></i>
        Co-Curricular Dashboard
      </h2>
    </div>

    <!-- Content Body Grid Container -->
    <div class="content-body">

    <!-- Role Persona Switcher Bar for Admin testing (No role label shown) -->
    <?php if ($sess_role === 'admin'): ?>
    <div class="role-switcher-bar" style="margin-bottom: 20px;">
      <div style="display:flex; align-items:center; justify-content:flex-end; flex-wrap:wrap; gap:6px;">
        <span style="font-size: 0.78rem; font-weight: 600; color: #64748b; margin-right: auto;">Role Switcher (Admin Mode):</span>
        <a href="?switch_role=student" class="card-btn" style="<?= $sess_role==='student'?'background:#1a3a8c;':'background:#64748b;' ?>">Student</a>
        <a href="?switch_role=club_adviser" class="card-btn" style="<?= $sess_role==='club_adviser'?'background:#1a3a8c;':'background:#64748b;' ?>">Adviser</a>
        <a href="?switch_role=osa_director" class="card-btn" style="<?= $sess_role==='osa_director'?'background:#1a3a8c;':'background:#64748b;' ?>">OSA</a>
        <a href="?switch_role=finance_officer" class="card-btn" style="<?= $sess_role==='finance_officer'?'background:#1a3a8c;':'background:#64748b;' ?>">Finance</a>
        <a href="?switch_role=admin" class="card-btn" style="<?= $sess_role==='admin'?'background:#1a3a8c;':'background:#64748b;' ?>">Admin</a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Info-Row Stats Cards -->
    <div class="info-row">

      <!-- Welcome Card -->
      <div class="info-card">
        <div class="card-label">
          <i class="fa-solid fa-user-graduate"></i>
          Welcome Back
        </div>
        <div class="card-name"><?= $sess_first . ' ' . $sess_last ?></div>
        <div class="card-detail">
          Co-Curricular Student Portal &amp; Management Module
        </div>
      </div>

      <!-- Stat Card 2: Active Clubs Joined -->
      <div class="info-card">
        <div class="card-label">
          <i class="fa-solid fa-sitemap"></i>
          Active Clubs Joined
        </div>
        <div class="card-amount"><?= $active_clubs_joined ?></div>
        <div class="card-detail">Joined Accredited Organizations</div>
      </div>

      <!-- Stat Card 3: Active Campus Events -->
      <div class="info-card">
        <div class="card-label">
          <i class="fa-solid fa-calendar-check"></i>
          Active Campus Events
        </div>
        <div class="card-amount"><?= $active_campus_events ?></div>
        <div class="card-detail">Approved &amp; Scheduled Activities</div>
      </div>

      <?php if ($sess_role !== 'student'): ?>
      <!-- Stat Card 4 (Visible to Officers, Advisers, OSA, Finance, Admin) -->
      <div class="info-card">
        <div class="card-label">
          <i class="fa-solid fa-coins"></i>
          Budget Requisitions
        </div>
        <div class="card-amount"><?= $total_budgets ?></div>
        <div class="card-detail">Finance & OSA Pipeline</div>
      </div>
      <?php endif; ?>

    </div><!-- end info-row -->

    <?php if ($sess_role === 'student'): ?>
      <!-- CLUBS ANNOUNCEMENTS & EVENTS FEED (Replaces Engagement Metrics for Students) -->
      <div class="clubs-feed-card">
        <div class="clubs-feed-header">
          <h3><i class="fa-solid fa-bullhorn" style="color:#2563eb;"></i> Clubs Announcements & Events Feed</h3>
          <span style="font-size:0.8rem; color:#64748b;">Latest updates from campus organizations</span>
        </div>
        <div class="clubs-feed-grid">

          <div class="feed-post-card">
            <div>
              <div class="feed-post-meta">
                <span class="feed-org-tag">IT Society</span>
                <span class="feed-date">Aug 10, 2026</span>
              </div>
              <h4 class="feed-post-title">IT Society Officer Elections 2026</h4>
              <p class="feed-post-desc">Cast your digital ballots for the incoming Executive Board of the IT Society. Voting closes at 5:00 PM.</p>
            </div>
            <a href="elections.php#booth" class="feed-action-btn"><i class="fa-solid fa-check-to-slot"></i> Cast Vote Now</a>
          </div>

          <div class="feed-post-card">
            <div>
              <div class="feed-post-meta">
                <span class="feed-org-tag">CS Executive Council</span>
                <span class="feed-date">Aug 12, 2026</span>
              </div>
              <h4 class="feed-post-title">Annual Hackathon 2026 Registration</h4>
              <p class="feed-post-desc">Compete in 24-hour coding challenge! Open for all computer science and IT students. Form your teams today.</p>
            </div>
            <a href="events.php" class="feed-action-btn"><i class="fa-solid fa-calendar-check"></i> Register for Event</a>
          </div>

          <div class="feed-post-card">
            <div>
              <div class="feed-post-meta">
                <span class="feed-org-tag">Cultural Arts Guild</span>
                <span class="feed-date">Aug 15, 2026</span>
              </div>
              <h4 class="feed-post-title">Campus Performing Arts Auditions</h4>
              <p class="feed-post-desc">Calling all dancers, singers, and theater enthusiasts for the 2026 Mid-Year Cultural Showcase audition round.</p>
            </div>
            <a href="events.php" class="feed-action-btn"><i class="fa-solid fa-calendar-days"></i> View Event Details</a>
          </div>

        </div>
      </div>

    <?php else: ?>
      <!-- Engagement Metrics Chart (For non-student roles) -->
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <h3><i class="fa-solid fa-chart-bar" style="color:#2563eb;"></i> Co-Curricular Engagement Metrics</h3>
            <div class="chart-sub">Campus-wide activity summary for AY 2025-2026</div>
          </div>
        </div>
        <div class="chart-wrap">
          <canvas id="dashboardChart"></canvas>
        </div>
      </div>
    <?php endif; ?>

    <!-- Dynamic Role Component (Default Template Box / Table Layout) -->

    <?php if ($sess_role === 'student'): ?>
      <!-- GENERAL STUDENT VIEW: Attendance History prompt -->
      <div class="table-card" style="margin-bottom:20px;">
        <h3><i class="fa-solid fa-calendar-check" style="color:#2563eb;"></i> My Upcoming Events</h3>
        <p style="font-size:0.85rem; color:#64748b; margin:8px 0 14px;">Browse and register for upcoming campus events organized by your clubs.</p>
        <a href="events.php" class="card-btn" style="display:inline-flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-calendar-days"></i> View All Events
        </a>
      </div>

    <?php elseif ($sess_role === 'club_adviser'): ?>
      <!-- CLUB ADVISER VIEW -->
      <div class="table-card" style="margin-bottom:20px;">
        <h3><i class="fa-solid fa-inbox" style="color:#2563eb;"></i> Pending Endorsements Queue (Faculty Adviser Clearance)</h3>
        <table class="data-table">
          <thead>
            <tr>
              <th>Request Title</th>
              <th>Club</th>
              <th>Amount / Details</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Stage Costumes & Sound System</td>
              <td>BCP Cultural Arts</td>
              <td>₱18,000.00</td>
              <td>
                <button class="card-btn" onclick="alert('Endorsed to OSA!')">Endorse</button>
                <button class="card-btn" style="background:#ef4444;" onclick="alert('Returned to Officer.')">Return</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

    <?php elseif ($sess_role === 'osa_director'): ?>
      <!-- OSA DIRECTOR VIEW -->
      <div class="table-card" style="margin-bottom:20px;">
        <h3><i class="fa-solid fa-building-columns" style="color:#2563eb;"></i> OSA Executive Approvals & Accreditation Reports</h3>
        <table class="data-table">
          <thead>
            <tr>
              <th>Approval Type</th>
              <th>Submitted By</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>New Club Charter: Robotics Guild</td>
              <td>Student Founding Officers</td>
              <td><span class="badge-inactive">Pending Review</span></td>
              <td><button class="card-btn" onclick="alert('Charter Approved!')">Approve Charter</button></td>
            </tr>
            <tr>
              <td>Outreach Bus Rental & Food Packs</td>
              <td>BCP Campus Volunteers</td>
              <td><span class="badge-active">Adviser Endorsed</span></td>
              <td><button class="card-btn" onclick="alert('Budget Approved & Forwarded to Finance!')">Approve Budget</button></td>
            </tr>
          </tbody>
        </table>
      </div>

    <?php elseif ($sess_role === 'finance_officer'): ?>
      <!-- FINANCE OFFICER VIEW -->
      <div class="table-card" style="margin-bottom:20px;">
        <h3><i class="fa-solid fa-money-check-dollar" style="color:#2563eb;"></i> Pending Budget Disbursement Vouchers</h3>
        <table class="data-table">
          <thead>
            <tr>
              <th>Voucher #</th>
              <th>Organization</th>
              <th>Description</th>
              <th>Amount</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>DV-2026-004</td>
              <td>IT Society</td>
              <td>Hackathon T-Shirts & Snacks</td>
              <td>₱15,000.00</td>
              <td><button class="card-btn" onclick="alert('Cash / Check Advance Disbursed!')">Release Advance</button></td>
            </tr>
          </tbody>
        </table>
      </div>

    <?php elseif ($sess_role === 'admin'): ?>
      <!-- SYSTEM ADMIN VIEW -->
      <div class="table-card" style="margin-bottom:20px;">
        <h3><i class="fa-solid fa-server" style="color:#2563eb;"></i> Inter-System API Integration Status</h3>
        <table class="data-table">
          <thead>
            <tr>
              <th>Target SMS System</th>
              <th>Integration Direction</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr><td>Registrar SIS</td><td>Bi-directional</td><td><span class="badge-active">Active Sync</span></td></tr>
            <tr><td>Enrollment Management</td><td>Inflow</td><td><span class="badge-active">Active Sync</span></td></tr>
            <tr><td>Payment Management System</td><td>Bi-directional</td><td><span class="badge-active">Active Sync</span></td></tr>
            <tr><td>Class Scheduling System</td><td>Inflow</td><td><span class="badge-active">Active Sync</span></td></tr>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    </div><!-- end content-body -->
  </div><!-- end content -->

  <div class="footer">Co-Curricular Management System &copy; 2026</div>
</div><!-- end main -->

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script src="../js/dashboard.js"></script>
</body>
</html>
