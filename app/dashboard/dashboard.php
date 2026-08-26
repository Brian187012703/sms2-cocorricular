<?php
require_once __DIR__ . '/../shared/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php');
    exit;
}

// Allow testing role switcher via ?switch_role=...
if (isset($_GET['switch_role']) && in_array($_GET['switch_role'], ['student','club_adviser','ssc','finance_officer','admin'])) {
    $_SESSION['role'] = $_GET['switch_role'];
}

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? 'User');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;

// Role Titles map
$role_labels = [
    'student'         => 'General Student',
    'club_adviser'    => 'Organization Adviser (Faculty Member)',
    'ssc'             => 'SSC Officer / Board Member',
    'finance_officer' => 'Finance / Cashier Officer',
    'admin'           => 'System Administrator'
];

$role_title = $role_labels[$sess_role] ?? 'User';

$user_id = (int)($_SESSION['user_id'] ?? 0);

// Fetch student profile details (Student Number, Section, Program, Year) if student role
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

// DB Counts
$active_clubs_joined = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM club_memberships WHERE user_id = {$user_id} AND status = 'Active'");
if ($r) $active_clubs_joined = (int)$r->fetch_assoc()['c'];

$active_campus_events = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM events WHERE status IN ('Approved', 'Upcoming')");
if ($r) $active_campus_events = (int)$r->fetch_assoc()['c'];

$total_budgets = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM budget_requests"); if ($r) $total_budgets = (int)$r->fetch_assoc()['c'];

// Fetch dynamic announcements
$announcements = [];
$ann_res = $conn->query("SELECT oa.*, c.name AS club_name, c.code AS club_code FROM org_announcements oa JOIN clubs c ON c.id = oa.club_id ORDER BY oa.created_at DESC LIMIT 3");
if ($ann_res) {
    while ($row = $ann_res->fetch_assoc()) {
        $announcements[] = $row;
    }
}

// Fetch adviser endorsement queue
$adviser_club_id = 0;
if ($sess_role === 'club_adviser') {
    $stmt = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->bind_result($adviser_club_id);
        $stmt->fetch();
        $stmt->close();
    }
}

$pending_endorsements = [];
if ($sess_role === 'club_adviser' && $adviser_club_id > 0) {
    $stmt = $conn->prepare("SELECT br.id, br.title, br.amount, c.name AS club_name FROM budget_requests br JOIN clubs c ON c.id = br.club_id WHERE br.club_id = ? AND br.status = 'Pending Adviser' ORDER BY br.created_at DESC");
    if ($stmt) {
        $stmt->bind_param('i', $adviser_club_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $pending_endorsements[] = $row;
        }
        $stmt->close();
    }
}

// Fetch SSC pending lists
$ssc_pending_budgets = [];
$ssc_pending_clubs = [];
$ssc_pending_events = [];

if ($sess_role === 'ssc') {
    $res = $conn->query("SELECT br.id, br.title, br.amount, c.name AS club_name FROM budget_requests br JOIN clubs c ON c.id = br.club_id WHERE br.status = 'Pending SSC' ORDER BY br.created_at DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $ssc_pending_budgets[] = $row;
        }
    }
    $res = $conn->query("SELECT id, name, code, category FROM clubs WHERE status = 'Pending Charter' ORDER BY created_at DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $ssc_pending_clubs[] = $row;
        }
    }
    $res = $conn->query("SELECT e.id, e.title, e.event_date, c.name AS club_name FROM events e JOIN clubs c ON c.id = e.club_id WHERE e.status = 'Pending SSC' ORDER BY e.event_date ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $ssc_pending_events[] = $row;
        }
    }
}

// Fetch Finance pending lists
$finance_pending_budgets = [];
if ($sess_role === 'finance_officer') {
    $res = $conn->query("SELECT br.id, br.title, br.amount, c.name AS club_name FROM budget_requests br JOIN clubs c ON c.id = br.club_id WHERE br.status IN ('Pending Admin', 'Pending Finance') ORDER BY br.created_at DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $finance_pending_budgets[] = $row;
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
        <input type="text" placeholder="Search pages, events..." autocomplete="off" />
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
        <a href="?switch_role=ssc" class="card-btn" style="<?= $sess_role==='ssc'?'background:#1a3a8c;':'background:#64748b;' ?>">SSC</a>
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
          <?php if ($sess_role === 'student' && !empty($student_info)): ?>
            <div style="margin-top:6px; font-size:0.8rem; color:#64748b; line-height:1.4; text-align:left;">
              <?php if (!empty($student_info['student_number'])): ?>
                <strong>Student Number:</strong> <?= htmlspecialchars($student_info['student_number']) ?><br/>
              <?php endif; ?>
              <strong>Program:</strong> <?= htmlspecialchars($student_info['course']) ?><br/>
              <strong>Year &amp; Section:</strong> <?= htmlspecialchars($student_info['year_level']) ?> - <?= htmlspecialchars($student_info['section']) ?>
            </div>
          <?php else: ?>
            Co-Curricular Student Portal &amp; Management Module
          <?php endif; ?>
        </div>
      </div>

      <!-- Stat Card 2: Active Clubs Joined -->
      <?php if ($sess_role !== 'club_adviser'): ?>
      <div class="info-card">
        <div class="card-label">
          <i class="fa-solid fa-sitemap"></i>
          Active Organizations Joined
        </div>
        <div class="card-amount"><?= $active_clubs_joined ?></div>
        <div class="card-detail">Joined Accredited Organizations</div>
      </div>
      <?php endif; ?>

      <!-- Stat Card 3: Active Campus Events -->
      <div class="info-card">
        <div class="card-label">
          <i class="fa-solid fa-calendar-days"></i>
          Active Campus Events
        </div>
        <div class="card-amount"><?= $active_campus_events ?></div>
        <div class="card-detail">Upcoming Approved Campus Activities</div>
      </div>

      <!-- Stat Card 4: Total Budget Allocated -->
      <?php if ($sess_role !== 'student'): ?>
      <div class="info-card">
        <div class="card-label">
          <i class="fa-solid fa-file-invoice-dollar"></i>
          Total Org Budgets
        </div>
        <div class="card-amount"><?= $total_budgets ?></div>
        <div class="card-detail">Finance &amp; SSC Pipeline</div>
      </div>
      <?php endif; ?>

    </div><!-- end info-row -->

    <?php if ($sess_role === 'student'): ?>
      <!-- CLUBS ANNOUNCEMENTS & EVENTS FEED -->
      <div class="clubs-feed-card">
        <div class="clubs-feed-header">
          <h3><i class="fa-solid fa-bullhorn" style="color:#2563eb;"></i> Organization Announcements & Events Feed</h3>
          <span style="font-size:0.8rem; color:#64748b;">Latest updates from campus organizations</span>
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

    <!-- Dynamic Role Component (Default Template Box / Table Layout) -->

    <?php if ($sess_role === 'club_adviser'): ?>
      <!-- CLUB ADVISER VIEW -->
      <div class="table-card" style="margin-bottom:20px;">
        <h3><i class="fa-solid fa-inbox" style="color:#2563eb;"></i> Pending Endorsements Queue (Faculty Adviser Clearance)</h3>
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Request Title</th>
              <th>Organization</th>
              <th>Estimated Cost</th>
              <th>Submission Date</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($pending_endorsements)): ?>
              <tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:20px;">No pending endorsements queue at this time.</td></tr>
            <?php else: ?>
              <?php $idx = 1; foreach ($pending_endorsements as $req): ?>
                <tr>
                  <td><?= $idx++ ?></td>
                  <td><strong><?= htmlspecialchars($req['title']) ?></strong></td>
                  <td><?= htmlspecialchars($req['club_name']) ?></td>
                  <td>₱<?= number_format($req['amount'], 2) ?></td>
                  <td><?= date('M d, Y', strtotime($req['created_at'] ?? 'now')) ?></td>
                  <td>
                    <a href="budget.php" class="card-btn"><i class="fa-solid fa-eye"></i> Go to Budget Portal</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($sess_role === 'ssc'): ?>
      <!-- SSC OFFICER VIEW -->
      <div class="table-card" style="margin-bottom:20px;">
        <h3><i class="fa-solid fa-building-columns" style="color:#2563eb;"></i> SSC Executive Approvals &amp; Charter Reviews</h3>
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Category</th>
              <th>Proposal / Item Title</th>
              <th>Organization</th>
              <th>Current Stage</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($ssc_pending_budgets) && empty($ssc_pending_clubs) && empty($ssc_pending_events)): ?>
              <tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:20px;">No pending SSC approvals or reviews found.</td></tr>
            <?php else: ?>
              <?php $sidx = 1; ?>
              <?php foreach ($ssc_pending_clubs as $cl): ?>
                <tr>
                  <td><?= $sidx++ ?></td>
                  <td><span class="badge-info">Charter</span></td>
                  <td><strong>New Org Charter: <?= htmlspecialchars($cl['name']) ?></strong></td>
                  <td><?= htmlspecialchars($cl['code']) ?></td>
                  <td><span class="badge-warning">Pending SSC Review</span></td>
                  <td><a href="club_directory.php" class="card-btn">Review Charter</a></td>
                </tr>
              <?php endforeach; ?>
              <?php foreach ($ssc_pending_budgets as $br): ?>
                <tr>
                  <td><?= $sidx++ ?></td>
                  <td><span class="badge-info">Budget</span></td>
                  <td><strong><?= htmlspecialchars($br['title']) ?> (₱<?= number_format($br['amount'], 2) ?>)</strong></td>
                  <td><?= htmlspecialchars($br['club_name']) ?></td>
                  <td><span class="badge-active">Adviser Endorsed</span></td>
                  <td><a href="budget.php" class="card-btn">Review Budget</a></td>
                </tr>
              <?php endforeach; ?>
              <?php foreach ($ssc_pending_events as $ev): ?>
                <tr>
                  <td><?= $sidx++ ?></td>
                  <td><span class="badge-info">Event</span></td>
                  <td><strong><?= htmlspecialchars($ev['title']) ?></strong></td>
                  <td><?= htmlspecialchars($ev['club_name']) ?></td>
                  <td><?= date('M d, Y', strtotime($ev['event_date'])) ?></td>
                  <td><a href="events.php" class="card-btn">Review Activity</a></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
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
              <th>#</th>
              <th>Target SMS System</th>
              <th>Sync Protocol</th>
              <th>Data Direction</th>
              <th>Connection Status</th>
              <th>Health Check</th>
            </tr>
          </thead>
          <tbody>
            <tr><td>1</td><td><strong>Registrar SIS</strong></td><td>REST API / OAuth 2.0</td><td>Bi-directional</td><td><span class="badge-active">Active Sync</span></td><td><span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> 99.9% Uptime</span></td></tr>
            <tr><td>2</td><td><strong>Enrollment Management</strong></td><td>Encrypted Webhook</td><td>Inflow</td><td><span class="badge-active">Active Sync</span></td><td><span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Connected</span></td></tr>
            <tr><td>3</td><td><strong>Payment Management System</strong></td><td>Direct SQL / REST</td><td>Bi-directional</td><td><span class="badge-active">Active Sync</span></td><td><span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Verified</span></td></tr>
            <tr><td>4</td><td><strong>Class Scheduling System</strong></td><td>JSON Feed API</td><td>Inflow</td><td><span class="badge-active">Active Sync</span></td><td><span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Synced</span></td></tr>
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
<script src="../js/table-pagination.js"></script>
</body>
</html>
