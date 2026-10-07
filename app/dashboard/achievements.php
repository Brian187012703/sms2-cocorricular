<?php
// ============================================================
//  ACHIEVEMENTS.PHP  (dashboard/)
//  2.8 Achievements & Organizational Recognition
//  Database-Driven Ledger with Full Workflow (Verify / Reject / Clarify)
// ============================================================
require_once __DIR__ . '/../shared/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../shared/security.php';
require_auth();
require_any_permission(['achievements.view', 'achievements.submit', 'achievements.verify.ssc', 'achievements.admin']);

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// -- Role Scope Filter ----------------------------------------
$scope_where  = '';
$scope_params = [];
$scope_types  = '';
$adviser_club_id = null;

if ($sess_role === 'student') {
    // Students see their own submissions
    $scope_where  = 'WHERE a.submitted_by = ?';
    $scope_params = [$user_id];
    $scope_types  = 'i';
} elseif ($sess_role === 'club_adviser') {
    // Advisers see their assigned club's achievements
    $sess_user = $_SESSION['username'] ?? '';
    $cm = $conn->prepare("SELECT id FROM clubs WHERE (id IN (SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active') OR code=UPPER(SUBSTRING_INDEX(?, '.', 1))) AND status='Active' LIMIT 1");
    $cm->bind_param('is', $user_id, $sess_user);
    $cm->execute();
    $cm->bind_result($adviser_club_id);
    $cm->fetch();
    $cm->close();
    if ($adviser_club_id) {
        $scope_where  = 'WHERE a.club_id = ?';
        $scope_params = [(int)$adviser_club_id];
        $scope_types  = 'i';
    } else {
        $scope_where  = 'WHERE 1=0';
    }
} else {
    // SSC and Admin have institutional scope across all clubs
    $scope_where  = 'WHERE 1=1';
}

// ── 5 Metric Cards Telemetry (Database Driven) ────────────────

// 1. Pending SSC Verification
$q_pending_ssc = "SELECT COUNT(*) FROM achievements a $scope_where AND a.status IN ('Pending SSC', 'Pending')";
$stmt = $conn->prepare($q_pending_ssc);
if ($scope_params) $stmt->bind_param($scope_types, ...$scope_params);
$stmt->execute();
$stat_pending_ssc = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

// 2. Pending Admin Clearance
$q_pending_admin = "SELECT COUNT(*) FROM achievements a $scope_where AND a.status = 'Pending Admin'";
$stmt = $conn->prepare($q_pending_admin);
if ($scope_params) $stmt->bind_param($scope_types, ...$scope_params);
$stmt->execute();
$stat_pending_admin = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

// 3. Approved & Published Achievements
$q_approved = "SELECT COUNT(*) FROM achievements a $scope_where AND a.status IN ('Approved', 'Verified')";
$stmt = $conn->prepare($q_approved);
if ($scope_params) $stmt->bind_param($scope_types, ...$scope_params);
$stmt->execute();
$stat_approved = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

// 4. Rejected Submissions
$q_rejected = "SELECT COUNT(*) FROM achievements a $scope_where AND a.status = 'Rejected'";
$stmt = $conn->prepare($q_rejected);
if ($scope_params) $stmt->bind_param($scope_types, ...$scope_params);
$stmt->execute();
$stat_rejected = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

// 5. Achievements This Year
$q_year = "SELECT COUNT(*) FROM achievements a $scope_where AND a.status IN ('Approved', 'Verified') AND YEAR(a.award_date) = YEAR(CURDATE())";
$stmt = $conn->prepare($q_year);
if ($scope_params) $stmt->bind_param($scope_types, ...$scope_params);
$stmt->execute();
$stat_this_year = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

// ── Fetch Full Achievements Ledger ───────────────────────────
$sql = "SELECT a.id, a.title, a.competition, a.award_date, a.proof_file, a.status, a.notes, a.created_at,
               c.id AS club_id, c.name AS club_name, c.code AS club_code,
               u.id AS submitter_id, u.first_name AS sub_first, u.last_name AS sub_last, u.role AS sub_role,
               v.id AS verifier_id, v.first_name AS ver_first, v.last_name AS ver_last, v.role AS ver_role,
               ap.id AS approver_id, ap.first_name AS app_first, ap.last_name AS app_last, ap.role AS app_role
        FROM achievements a
        JOIN clubs c ON c.id = a.club_id
        JOIN users u ON u.id = a.submitted_by
        LEFT JOIN users v ON v.id = a.verified_by
        LEFT JOIN users ap ON ap.id = a.approved_by
        $scope_where
        ORDER BY FIELD(a.status, 'Pending SSC', 'Pending', 'Pending Admin', 'Approved', 'Verified', 'Rejected'), a.award_date DESC, a.created_at DESC";
$stmt = $conn->prepare($sql);
if ($scope_params) $stmt->bind_param($scope_types, ...$scope_params);
$stmt->execute();
$achievements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Club List for Submit Form --------------------------------
$clubs = [];
if ($sess_role === 'club_adviser') {
    $stmt_c = $conn->prepare(
        "SELECT c.id, c.name, c.code
         FROM clubs c
         WHERE (c.id IN (SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active')
            OR c.code = UPPER(SUBSTRING_INDEX(?, '.', 1)))
           AND c.status = 'Active'
         ORDER BY c.name"
    );
    $stmt_c->bind_param('is', $user_id, $sess_user);
    $stmt_c->execute();
    $clubs = $stmt_c->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_c->close();
} elseif ($sess_role === 'student') {
    $stmt_c = $conn->prepare(
        "SELECT c.id, c.name, c.code
         FROM clubs c
         JOIN club_memberships cm ON cm.club_id = c.id
         WHERE cm.user_id = ? AND cm.status = 'Active' AND c.status = 'Active'
         ORDER BY c.name"
    );
    $stmt_c->bind_param('i', $user_id);
    $stmt_c->execute();
    $clubs = $stmt_c->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_c->close();
} else {
    $clubs = $conn->query("SELECT id, name, code FROM clubs WHERE status='Active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= csrf_token() ?>"/>
  <title>Achievements &amp; Organizational Recognition – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
    /* Metric Cards Grid */
    .metrics-grid-5 {
      display: grid !important;
      grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
      gap: 14px !important;
      margin-bottom: 22px !important;
      align-items: stretch !important;
    }
    @media (max-width: 1100px) {
      .metrics-grid-5 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
    }
    @media (max-width: 768px) {
      .metrics-grid-5 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; gap: 10px !important; }
    }
    @media (max-width: 480px) {
      .metrics-grid-5 { grid-template-columns: 1fr !important; }
    }

    /* Status Filter Tabs */
    .status-tab {
      padding: 6px 14px;
      font-size: 0.82rem;
      font-weight: 700;
      border-radius: 20px;
      border: 1px solid #cbd5e1;
      background: #f8fafc;
      color: #64748b;
      cursor: pointer;
      transition: all 0.15s ease;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .status-tab:hover {
      background: #e2e8f0;
      color: #1e293b;
    }
    .status-tab.active {
      background: #2563eb;
      color: #ffffff;
      border-color: #2563eb;
      box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
    }

    /* Modal Styling */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.65);
      z-index: 2100;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
      backdrop-filter: blur(3px);
    }
    .modal-overlay.active {
      display: flex !important;
      animation: modalFadeIn 0.2s ease;
    }
    @keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
    .modal-card {
      background: #ffffff;
      border-radius: 16px;
      width: 100%;
      max-width: 580px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 24px 60px rgba(15, 23, 42, 0.3);
      animation: modalSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      box-sizing: border-box;
    }
    @keyframes modalSlideUp { from { transform: translateY(18px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .modal-header {
      padding: 18px 24px;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #f8fafc;
      border-radius: 16px 16px 0 0;
    }
    .modal-header h3 { margin: 0; font-size: 1.05rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; }
    .modal-body { padding: 22px 24px; box-sizing: border-box; }
    .modal-footer {
      padding: 14px 24px;
      border-top: 1px solid #e2e8f0;
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      background: #f8fafc;
      border-radius: 0 0 16px 16px;
    }
    .form-group { margin-bottom: 15px; }
    .form-group label {
      display: block;
      font-size: 0.78rem;
      font-weight: 700;
      color: #475569;
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .form-group input, .form-group select, .form-group textarea {
      width: 100%;
      padding: 9px 12px;
      border: 1.5px solid #cbd5e1;
      border-radius: 8px;
      font-size: 0.88rem;
      color: #0f172a;
      box-sizing: border-box;
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 480px) { .form-row { grid-template-columns: 1fr; } }
    .ach-alert {
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 16px;
      font-size: 0.86rem;
      font-weight: 500;
      display: none;
    }
    .badge-status {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 700;
      white-space: nowrap;
    }
    .badge-pending { background: #fef3c7; color: #b45309; }
    .badge-verified { background: #dcfce7; color: #15803d; }
    .badge-rejected { background: #fee2e2; color: #b91c1c; }
    .action-btn-group {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: nowrap;
      white-space: nowrap;
    }
  </style>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'achievements';
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
      <div class="search-wrap" id="topbarSearchWrap">
        <i class="fa-solid fa-magnifying-glass search-icon"></i>
        <input type="text" placeholder="Search modules, events, clubs..." autocomplete="off" />
        <button type="button" class="search-clear-btn" aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i class="fa-solid fa-qrcode"></i></button>
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
        <i class="fa-solid fa-trophy" style="color:#2563eb;"></i>
        Achievements &amp; Organizational Recognition
      </h2>
    </div>

    <div class="content-body">

      <!-- Alert Banner -->
      <div id="achAlert" class="ach-alert"></div>

      <!-- 5 Metric Cards (Pure Database-Driven & Zero Hardcoded Definitions) -->
      <div class="metrics-grid-5">
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-clock" style="color:#d97706;"></i> Pending SSC</div>
          <div class="card-amount"><?= number_format($stat_pending_ssc) ?></div>
        </div>

        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-hourglass-half" style="color:#7e22ce;"></i> Pending Admin</div>
          <div class="card-amount"><?= number_format($stat_pending_admin) ?></div>
        </div>

        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Approved &amp; Published</div>
          <div class="card-amount"><?= number_format($stat_approved) ?></div>
        </div>

        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Rejected</div>
          <div class="card-amount"><?= number_format($stat_rejected) ?></div>
        </div>

        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-calendar-check" style="color:#2563eb;"></i> Achievements This Year</div>
          <div class="card-amount"><?= number_format($stat_this_year) ?></div>
        </div>
      </div>

      <!-- Main Ledger Card -->
      <div class="card">
        <!-- Filter and Search Row -->
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:16px; flex-wrap:wrap;">
          <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <button type="button" class="status-tab active" data-filter="all" onclick="filterStatus('all', this)">
              All <span style="font-size:0.75rem; background:#e2e8f0; color:#1e293b; padding:1px 6px; border-radius:10px;"><?= count($achievements) ?></span>
            </button>
            <button type="button" class="status-tab" data-filter="Pending SSC" onclick="filterStatus('Pending SSC', this)">
              Pending SSC <span style="font-size:0.75rem; background:#fef3c7; color:#b45309; padding:1px 6px; border-radius:10px;"><?= $stat_pending_ssc ?></span>
            </button>
            <button type="button" class="status-tab" data-filter="Pending Admin" onclick="filterStatus('Pending Admin', this)">
              Pending Admin <span style="font-size:0.75rem; background:#f3e8ff; color:#7e22ce; padding:1px 6px; border-radius:10px;"><?= $stat_pending_admin ?></span>
            </button>
            <button type="button" class="status-tab" data-filter="Approved" onclick="filterStatus('Approved', this)">
              Approved <span style="font-size:0.75rem; background:#dcfce7; color:#15803d; padding:1px 6px; border-radius:10px;"><?= $stat_approved ?></span>
            </button>
            <button type="button" class="status-tab" data-filter="Rejected" onclick="filterStatus('Rejected', this)">
              Rejected <span style="font-size:0.75rem; background:#fee2e2; color:#b91c1c; padding:1px 6px; border-radius:10px;"><?= $stat_rejected ?></span>
            </button>
          </div>
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <div style="position:relative; width:260px; max-width:100%;">
              <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.85rem;"></i>
              <input type="text" id="achSearchInput" placeholder="Filter achievements..." oninput="filterAchTable()" style="width:100%; padding:8px 12px 8px 34px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.85rem; outline:none; box-sizing:border-box;"/>
            </div>
            <?php if (in_array($sess_role, ['club_adviser', 'admin'])): ?>
            <button type="button" class="card-btn" id="openSubmitBtn" onclick="openSubmitModal()" style="background:#2563eb; color:#fff; font-weight:700; padding:8px 16px; border-radius:8px; display:inline-flex; align-items:center; gap:8px; border:none; cursor:pointer; font-size:0.85rem; white-space:nowrap; box-shadow:0 2px 6px rgba(37,99,235,0.22);">
              <i class="fa-solid fa-plus-circle"></i> Submit Achievement
            </button>
            <?php endif; ?>
          </div>
        </div>

        <?php if (empty($achievements)): ?>
          <div style="text-align:center; padding:48px 16px; color:#94a3b8;">
            <i class="fa-solid fa-trophy" style="font-size:2.8rem; color:#cbd5e1; margin-bottom:12px; display:block;"></i>
            <div style="font-weight:600; font-size:1rem; color:#64748b;">No achievements found</div>
            <div style="font-size:0.82rem; margin-top:4px;">No achievement records currently match your scope or query.</div>
          </div>
        <?php else: ?>
        <div class="table-wrap">
          <table id="achTable" class="table-wide">
            <thead>
              <tr>
                <th>Achievement</th>
                <th>Organization</th>
                <th>Competition</th>
                <th>Award Date</th>
                <th>Submitted By</th>
                <th>Status</th>
                <th>Verification Flow</th>
                <th style="text-align:center;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($achievements as $a): ?>
              <tr class="ach-table-row" data-status="<?= htmlspecialchars($a['status']) ?>" id="ach-row-<?= $a['id'] ?>">
                <!-- 1. Achievement Title -->
                <td>
                  <strong style="color:#0f172a; font-size:0.9rem;"><?= htmlspecialchars($a['title']) ?></strong>
                  <?php if (!empty($a['notes'])): ?>
                    <div style="font-size:0.75rem; color:#64748b; margin-top:2px; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($a['notes']) ?>">
                      <i class="fa-regular fa-comment-dots" style="color:#94a3b8; margin-right:3px;"></i><?= htmlspecialchars($a['notes']) ?>
                    </div>
                  <?php endif; ?>
                </td>

                <!-- 2. Organization -->
                <td>
                  <div style="font-weight:600; color:#1e293b; font-size:0.86rem;"><?= htmlspecialchars($a['club_name']) ?></div>
                  <span style="font-size:0.70rem; background:#f1f5f9; color:#475569; padding:1px 6px; border-radius:4px; font-weight:700; display:inline-block; margin-top:2px;"><?= htmlspecialchars($a['club_code']) ?></span>
                </td>

                <!-- 3. Competition -->
                <td>
                  <span style="font-size:0.85rem; color:#334155; font-weight:500;"><?= htmlspecialchars($a['competition']) ?></span>
                </td>

                <!-- 4. Award Date -->
                <td>
                  <span style="font-size:0.84rem; color:#475569; white-space:nowrap;">
                    <i class="fa-regular fa-calendar" style="color:#94a3b8; margin-right:4px;"></i><?= date('M d, Y', strtotime($a['award_date'])) ?>
                  </span>
                </td>

                <!-- 5. Submitted By -->
                <td>
                  <div style="font-weight:600; color:#0f172a; font-size:0.84rem;"><?= htmlspecialchars($a['sub_first'] . ' ' . $a['sub_last']) ?></div>
                  <span style="font-size:0.70rem; color:#64748b; text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $a['sub_role'])) ?></span>
                </td>

                <!-- 6. Status -->
                <td>
                  <?php if (in_array($a['status'], ['Approved', 'Verified'])): ?>
                    <span class="badge-status badge-verified" style="background:#dcfce7; color:#15803d;"><i class="fa-solid fa-circle-check"></i> Approved</span>
                  <?php elseif ($a['status'] === 'Pending Admin'): ?>
                    <span class="badge-status badge-pending-admin" style="background:#f3e8ff; color:#7e22ce;"><i class="fa-solid fa-hourglass-half"></i> Pending Admin</span>
                  <?php elseif (in_array($a['status'], ['Pending SSC', 'Pending'])): ?>
                    <span class="badge-status badge-pending" style="background:#fef3c7; color:#b45309;"><i class="fa-solid fa-clock"></i> Pending SSC</span>
                  <?php else: ?>
                    <span class="badge-status badge-rejected" style="background:#fee2e2; color:#b91c1c;"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>
                  <?php endif; ?>
                </td>

                <!-- 7. Verification Flow (SSC & Admin) -->
                <td>
                  <div style="font-size:0.78rem; line-height:1.4;">
                    <?php if (!empty($a['ver_first'])): ?>
                      <div style="color:#0f172a;">
                        <span style="font-size:0.68rem; background:#eff6ff; color:#2563eb; padding:1px 5px; border-radius:4px; font-weight:700;">SSC</span>
                        <?= htmlspecialchars($a['ver_first'] . ' ' . $a['ver_last']) ?>
                      </div>
                    <?php else: ?>
                      <div style="color:#d97706; font-size:0.74rem;"><i class="fa-solid fa-clock"></i> Awaiting SSC</div>
                    <?php endif; ?>

                    <?php if (!empty($a['app_first'])): ?>
                      <div style="color:#0f172a; margin-top:2px;">
                        <span style="font-size:0.68rem; background:#f0fdf4; color:#16a34a; padding:1px 5px; border-radius:4px; font-weight:700;">ADMIN</span>
                        <?= htmlspecialchars($a['app_first'] . ' ' . $a['app_last']) ?>
                      </div>
                    <?php elseif ($a['status'] === 'Pending Admin'): ?>
                      <div style="color:#7e22ce; font-size:0.74rem; margin-top:2px;"><i class="fa-solid fa-hourglass-half"></i> Awaiting Admin</div>
                    <?php endif; ?>
                  </div>
                </td>

                <!-- 8. Action (Verify / Approve / Reject / Clarify / Details) -->
                <td>
                  <div class="action-btn-group" style="justify-content:center; gap:4px; flex-wrap:nowrap;">
                    <?php if ($sess_role === 'ssc'): ?>
                      <?php if (in_array($a['status'], ['Pending SSC', 'Pending'])): ?>
                        <button type="button" class="card-btn" style="background:#16a34a; color:#fff; padding:4px 8px; font-size:0.73rem; border-radius:6px; border:none; cursor:pointer; white-space:nowrap;" title="Verify achievement and forward to Admin" onclick="verifyAch(<?= $a['id'] ?>, 'verify_ssc')">
                          <i class="fa-solid fa-check"></i> Verify &amp; Pass to Admin
                        </button>
                        <button type="button" class="card-btn btn-danger" style="padding:4px 8px; font-size:0.73rem; border-radius:6px; cursor:pointer; white-space:nowrap;" title="Reject submission" onclick="openRejectModal(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title'])) ?>')">
                          <i class="fa-solid fa-times"></i> Reject
                        </button>
                        <button type="button" class="card-btn" style="background:#f59e0b; color:#fff; padding:4px 8px; font-size:0.73rem; border-radius:6px; border:none; cursor:pointer; white-space:nowrap;" title="Request Clarification" onclick="openClarifyModal(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title'])) ?>')">
                          <i class="fa-solid fa-comment-dots"></i> Clarify
                        </button>
                      <?php endif; ?>
                    <?php elseif ($sess_role === 'admin'): ?>
                      <?php if ($a['status'] === 'Pending Admin'): ?>
                        <button type="button" class="card-btn" style="background:#2563eb; color:#fff; padding:4px 8px; font-size:0.73rem; border-radius:6px; border:none; cursor:pointer; white-space:nowrap;" title="Approve and post to Student Org Directory" onclick="approveAch(<?= $a['id'] ?>)">
                          <i class="fa-solid fa-circle-check"></i> Approve &amp; Publish
                        </button>
                        <button type="button" class="card-btn btn-danger" style="padding:4px 8px; font-size:0.73rem; border-radius:6px; cursor:pointer; white-space:nowrap;" title="Reject submission" onclick="openRejectModal(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title'])) ?>')">
                          <i class="fa-solid fa-times"></i> Reject
                        </button>
                        <button type="button" class="card-btn" style="background:#f59e0b; color:#fff; padding:4px 8px; font-size:0.73rem; border-radius:6px; border:none; cursor:pointer; white-space:nowrap;" title="Request Clarification" onclick="openClarifyModal(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title'])) ?>')">
                          <i class="fa-solid fa-comment-dots"></i> Clarify
                        </button>
                      <?php elseif (in_array($a['status'], ['Pending SSC', 'Pending'])): ?>
                        <button type="button" class="card-btn" style="background:#16a34a; color:#fff; padding:4px 8px; font-size:0.73rem; border-radius:6px; border:none; cursor:pointer; white-space:nowrap;" title="Verify as SSC and advance to Pending Admin" onclick="verifyAch(<?= $a['id'] ?>, 'verify_ssc')">
                          <i class="fa-solid fa-check"></i> Verify (SSC)
                        </button>
                        <button type="button" class="card-btn" style="background:#2563eb; color:#fff; padding:4px 8px; font-size:0.73rem; border-radius:6px; border:none; cursor:pointer; white-space:nowrap;" title="Directly Approve and Publish" onclick="approveAch(<?= $a['id'] ?>)">
                          <i class="fa-solid fa-circle-check"></i> Direct Approve
                        </button>
                        <button type="button" class="card-btn btn-danger" style="padding:4px 8px; font-size:0.73rem; border-radius:6px; cursor:pointer; white-space:nowrap;" title="Reject submission" onclick="openRejectModal(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title'])) ?>')">
                          <i class="fa-solid fa-times"></i> Reject
                        </button>
                      <?php endif; ?>
                    <?php endif; ?>
                    <!-- Universal View Details & Proof button -->
                    <button type="button" class="card-btn" style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; padding:4px 8px; font-size:0.73rem; border-radius:6px; cursor:pointer; white-space:nowrap;" title="View Details & Proof" onclick="openProofModal(<?= (int)$a['id'] ?>)">
                      <i class="fa-solid fa-eye"></i> Details
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- end content-body -->
  </div><!-- end content -->

  <div class="footer">eLearning Commons &copy; 2026</div>
</div><!-- end main -->

<!-- ── Submit Achievement Modal (Student / Adviser / SSC / Admin) ── -->
<div class="modal-overlay" id="submitAchModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-trophy" style="color:#2563eb;"></i> Submit Competition Achievement</h3>
      <button style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;" id="closeAchModal" aria-label="Close modal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label>Achievement Title *</label>
          <input type="text" id="achTitle" placeholder="e.g. 1st Place – National IT Hackathon 2026"/>
        </div>
        <div class="form-group">
          <label>Event / Competition *</label>
          <input type="text" id="achCompetition" placeholder="e.g. PSITE Student Convention 2026"/>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Date of Recognition *</label>
          <input type="date" id="achDate"/>
        </div>
        <div class="form-group">
          <label>Recipient Organization *</label>
          <select id="achClub">
            <?php if (empty($clubs)): ?>
              <option value="">-- No Active Joined Organizations (Join a club first) --</option>
            <?php else: ?>
              <?php if ($sess_role !== 'club_adviser'): ?>
                <option value="">-- Select Recipient Organization --</option>
              <?php endif; ?>
              <?php foreach ($clubs as $c): ?>
              <option value="<?= $c['id'] ?>" <?= ($sess_role === 'club_adviser' && $c['id'] == $adviser_club_id) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)
              </option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Proof / Attachment Evidence (PDF, PNG, JPG)</label>
        <input type="file" id="achProofFile" accept=".pdf,.png,.jpg,.jpeg,.webp"/>
        <p style="font-size:0.75rem; color:#94a3b8; margin:4px 0 0;">Upload official certificate, medal photo, or formal ranking tally (max 5MB).</p>
      </div>
      <div class="form-group">
        <label>Notes / Description</label>
        <textarea id="achNotes" placeholder="Describe the achievement category, participants, or scope..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="card-btn" style="background:#f1f5f9;color:#64748b;" id="cancelAchBtn">Cancel</button>
      <button class="card-btn" id="submitAchBtn" style="background:#2563eb; color:#fff; font-weight:700;">
        <i class="fa-solid fa-paper-plane"></i> Submit for Verification
      </button>
    </div>
  </div>
</div>

<!-- ── View Proof & Details Modal ────────────────────────────── -->
<div class="modal-overlay" id="proofDetailsModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3 id="proofModalTitle"><i class="fa-solid fa-award" style="color:#2563eb;"></i> Achievement Details &amp; Evidence</h3>
      <button style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;" onclick="closeProofModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="proofModalBody">
      <!-- Populated dynamically via JS -->
    </div>
    <div class="modal-footer">
      <button class="card-btn" style="background:#f1f5f9;color:#64748b;" onclick="closeProofModal()">Close</button>
    </div>
  </div>
</div>

<!-- ── Clarify Modal (SSC / Admin) ───────────────────────────── -->
<div class="modal-overlay" id="clarifyModal">
  <div class="modal-card" style="max-width:480px;">
    <div class="modal-header">
      <h3><i class="fa-solid fa-comment-dots" style="color:#f59e0b;"></i> Request Clarification</h3>
      <button style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;" onclick="closeClarifyModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="clarifyAchId"/>
      <div style="font-weight:600; color:#0f172a; margin-bottom:8px;" id="clarifyAchTitle"></div>
      <div class="form-group">
        <label>Clarification Notes / Required Documents *</label>
        <textarea id="clarifyNotes" style="min-height:90px;" placeholder="Specify what information or evidence needs to be clarified or submitted..."></textarea>
      </div>
      <p style="font-size:0.75rem; color:#64748b; margin:0;">The submitter will receive an instant notification with this instruction.</p>
    </div>
    <div class="modal-footer">
      <button class="card-btn" style="background:#f1f5f9;color:#64748b;" onclick="closeClarifyModal()">Cancel</button>
      <button class="card-btn" id="submitClarifyBtn" style="background:#f59e0b; color:#fff; font-weight:700;" onclick="submitClarify()">
        <i class="fa-solid fa-paper-plane"></i> Send Clarification
      </button>
    </div>
  </div>
</div>

<!-- ── Reject Modal (SSC / Admin) ────────────────────────────── -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal-card" style="max-width:480px;">
    <div class="modal-header">
      <h3><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Reject Achievement Submission</h3>
      <button style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;" onclick="closeRejectModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="rejectAchId"/>
      <div style="font-weight:600; color:#0f172a; margin-bottom:8px;" id="rejectAchTitle"></div>
      <div class="form-group">
        <label>Reason for Rejection *</label>
        <textarea id="rejectNotes" style="min-height:90px;" placeholder="Explain why this achievement or evidence was rejected..."></textarea>
      </div>
      <p style="font-size:0.75rem; color:#dc2626; margin:0;">This submission will be marked as Rejected on the official ledger.</p>
    </div>
    <div class="modal-footer">
      <button class="card-btn" style="background:#f1f5f9;color:#64748b;" onclick="closeRejectModal()">Cancel</button>
      <button class="card-btn btn-danger" id="submitRejectBtn" style="font-weight:700;" onclick="submitReject()">
        <i class="fa-solid fa-times"></i> Confirm Rejection
      </button>
    </div>
  </div>
</div>

<script src="../js/dashboard.js?v=<?= filemtime(__DIR__ . '/../js/dashboard.js') ?>"></script>
<script src="../js/table-pagination.js"></script>
<script>
// -- CSRF Token & Data Registry --------------------------------
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '<?= csrf_token() ?>';
const achDataList = <?= json_encode($achievements, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || [];
const achDataMap = {};
achDataList.forEach(item => { if (item && item.id) achDataMap[item.id] = item; });

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// -- Alert Helper ----------------------------------------------
function showAlert(msg, type) {
  const el = document.getElementById('achAlert');
  if (!el) return;
  el.style.display = 'block';
  el.style.background = type === 'success' ? '#dcfce7' : '#fef2f2';
  el.style.color      = type === 'success' ? '#166534' : '#991b1b';
  el.style.border     = `1px solid ${type === 'success' ? '#bbf7d0' : '#fecaca'}`;
  el.textContent = msg;
  window.scrollTo({ top: 0, behavior: 'smooth' });
  setTimeout(() => { el.style.display = 'none'; }, 5000);
}

// -- Live Filter by Status & Search ---------------------------
let currentStatusFilter = 'all';

function filterStatus(status, btn) {
  currentStatusFilter = status;
  document.querySelectorAll('.status-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  applyFilters();
}

function filterAchTable() {
  applyFilters();
}

function applyFilters() {
  const q = document.getElementById('achSearchInput')?.value.toLowerCase().trim() || '';
  document.querySelectorAll('.ach-table-row').forEach(row => {
    const rowStatus = row.dataset.status;
    const matchesStatus = (currentStatusFilter === 'all' || rowStatus === currentStatusFilter);
    const matchesSearch = !q || row.textContent.toLowerCase().includes(q);
    row.style.display = (matchesStatus && matchesSearch) ? '' : 'none';
  });
}

// -- Submit Modal Handling ------------------------------------
const submitModal = document.getElementById('submitAchModal');

function openSubmitModal() {
  if (!submitModal) return;
  submitModal.style.display = 'flex';
  submitModal.classList.add('active');
  document.body.style.overflow = 'hidden';
}

function closeSubmitModal() {
  if (!submitModal) return;
  submitModal.classList.remove('active');
  submitModal.style.display = 'none';
  document.body.style.overflow = '';
}

document.getElementById('openSubmitBtn')?.addEventListener('click', openSubmitModal);
document.getElementById('closeAchModal')?.addEventListener('click', closeSubmitModal);
document.getElementById('cancelAchBtn')?.addEventListener('click', closeSubmitModal);
submitModal?.addEventListener('click', e => { if (e.target === submitModal) closeSubmitModal(); });

document.getElementById('submitAchBtn')?.addEventListener('click', function() {
  const title       = document.getElementById('achTitle').value.trim();
  const competition = document.getElementById('achCompetition').value.trim();
  const date        = document.getElementById('achDate').value;
  const club_id     = document.getElementById('achClub').value;
  const notes       = document.getElementById('achNotes').value.trim();
  const fileInput   = document.getElementById('achProofFile');

  if (!title || !competition || !date || !club_id) {
    showAlert('Please fill in all required fields (Title, Event, Date, Organization).', 'error');
    return;
  }

  this.disabled = true;
  this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';

  const fd = new FormData();
  if (CSRF_TOKEN) fd.set('csrf_token', CSRF_TOKEN);
  fd.set('action', 'submit');
  fd.set('title', title);
  fd.set('competition', competition);
  fd.set('award_date', date);
  fd.set('club_id', club_id);
  fd.set('notes', notes);
  if (fileInput && fileInput.files.length > 0) {
    fd.append('proof_file', fileInput.files[0]);
  }

  fetch('../shared/achievement_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      this.disabled = false;
      this.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit for Verification';
      if (data.success) {
        closeSubmitModal();
        showAlert(data.message || 'Achievement submitted for SSC verification.', 'success');
        ['achTitle','achCompetition','achDate','achNotes'].forEach(id => {
          const el = document.getElementById(id);
          if (el) el.value = '';
        });
        if (fileInput) fileInput.value = '';
        setTimeout(() => location.reload(), 1400);
      } else {
        showAlert(data.message || 'Submission failed.', 'error');
      }
    })
    .catch(() => {
      this.disabled = false;
      this.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit for Verification';
      showAlert('Network error occurred. Please try again.', 'error');
    });
});

// -- Proof & Details Modal ------------------------------------
const proofModal = document.getElementById('proofDetailsModal');

function closeProofModal() {
  if (proofModal) {
    proofModal.classList.remove('active');
    proofModal.style.display = 'none';
    document.body.style.overflow = '';
  }
}

proofModal?.addEventListener('click', e => { if (e.target === proofModal) closeProofModal(); });

function openProofModal(itemOrId) {
  let item = null;
  if (typeof itemOrId === 'object' && itemOrId !== null) {
    item = itemOrId;
  } else if (achDataMap[itemOrId]) {
    item = achDataMap[itemOrId];
  } else if (Array.isArray(achDataList)) {
    item = achDataList.find(a => a.id == itemOrId) || null;
  }
  if (!item) return;

  const body = document.getElementById('proofModalBody');
  if (!body) return;

  let proofContent = '<span style="color:#94a3b8; font-style:italic;">No file attachment submitted.</span>';
  if (item.proof_file) {
    const fileUrl = '../uploads/achievements/' + encodeURIComponent(item.proof_file);
    const isImg = item.proof_file.match(/\.(jpg|jpeg|png|webp)$/i);
    if (isImg) {
      proofContent = `
        <div style="margin-top:8px; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; background:#0f172a; text-align:center;">
          <img src="${fileUrl}" style="max-width:100%; max-height:340px; object-fit:contain;" alt="Proof Evidence"/>
        </div>
        <div style="margin-top:8px; text-align:right;">
          <a href="${fileUrl}" target="_blank" class="card-btn" style="display:inline-flex; align-items:center; gap:6px; background:#eff6ff; color:#2563eb; text-decoration:none;">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Full Image
          </a>
        </div>
      `;
    } else {
      proofContent = `
        <div style="margin-top:8px; padding:16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; display:flex; align-items:center; justify-content:space-between;">
          <div style="display:flex; align-items:center; gap:10px;">
            <i class="fa-solid fa-file-pdf" style="font-size:2rem; color:#dc2626;"></i>
            <div>
              <div style="font-weight:600; color:#0f172a; font-size:0.88rem;">${escapeHtml(item.proof_file)}</div>
              <div style="font-size:0.75rem; color:#64748b;">PDF Document Attachment</div>
            </div>
          </div>
          <a href="${fileUrl}" target="_blank" class="card-btn" style="background:#2563eb; color:#fff; text-decoration:none;">
            <i class="fa-solid fa-file-arrow-down"></i> View / Download PDF
          </a>
        </div>
      `;
    }
  }

  let statusBadge = '';
  if (item.status === 'Approved' || item.status === 'Verified') {
    statusBadge = '<span class="badge-status badge-verified" style="background:#dcfce7; color:#15803d;"><i class="fa-solid fa-circle-check"></i> Approved</span>';
  } else if (item.status === 'Pending Admin') {
    statusBadge = '<span class="badge-status badge-pending-admin" style="background:#f3e8ff; color:#7e22ce;"><i class="fa-solid fa-hourglass-half"></i> Pending Admin</span>';
  } else if (item.status === 'Pending SSC' || item.status === 'Pending') {
    statusBadge = '<span class="badge-status badge-pending" style="background:#fef3c7; color:#b45309;"><i class="fa-solid fa-clock"></i> Pending SSC</span>';
  } else {
    statusBadge = '<span class="badge-status badge-rejected" style="background:#fee2e2; color:#b91c1c;"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>';
  }

  body.innerHTML = `
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:14px;">
      <div>
        <h4 style="margin:0; font-size:1.15rem; color:#0f172a; font-weight:700;">${escapeHtml(item.title)}</h4>
        <div style="color:#2563eb; font-weight:600; font-size:0.88rem; margin-top:2px;">${escapeHtml(item.competition)}</div>
      </div>
      <div>${statusBadge}</div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; font-size:0.84rem;">
      <div style="background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0;">
        <span style="color:#64748b; font-size:0.75rem; font-weight:700; text-transform:uppercase; display:block;">Recipient Organization</span>
        <strong style="color:#0f172a;">${escapeHtml(item.club_name)} (${escapeHtml(item.club_code)})</strong>
      </div>
      <div style="background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0;">
        <span style="color:#64748b; font-size:0.75rem; font-weight:700; text-transform:uppercase; display:block;">Date of Recognition</span>
        <strong style="color:#0f172a;">${escapeHtml(item.award_date)}</strong>
      </div>
      <div style="background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0;">
        <span style="color:#64748b; font-size:0.75rem; font-weight:700; text-transform:uppercase; display:block;">Submitted By (Adviser)</span>
        <strong style="color:#0f172a;">${escapeHtml(item.sub_first)} ${escapeHtml(item.sub_last)}</strong> (${escapeHtml(item.sub_role || 'club_adviser')})
      </div>
      <div style="background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0;">
        <span style="color:#64748b; font-size:0.75rem; font-weight:700; text-transform:uppercase; display:block;">Stage 2: SSC Verification</span>
        <strong style="color:#0f172a;">${item.ver_first ? (escapeHtml(item.ver_first) + ' ' + escapeHtml(item.ver_last)) : '<span style="color:#d97706;">Awaiting SSC</span>'}</strong>
      </div>
      <div style="background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0; grid-column: span 2;">
        <span style="color:#64748b; font-size:0.75rem; font-weight:700; text-transform:uppercase; display:block;">Stage 3: Admin Approval</span>
        <strong style="color:#0f172a;">${item.app_first ? (escapeHtml(item.app_first) + ' ' + escapeHtml(item.app_last) + ' (Approved)') : (item.status === 'Pending Admin' ? '<span style="color:#7e22ce;">Awaiting Admin Final Clearance</span>' : '<span style="color:#94a3b8;">Pending Review</span>')}</strong>
      </div>
    </div>

    ${item.notes ? `
    <div style="margin-bottom:16px; background:#eff6ff; border-left:4px solid #2563eb; padding:10px 14px; border-radius:0 8px 8px 0;">
      <div style="font-size:0.75rem; font-weight:700; color:#1e40af; text-transform:uppercase; margin-bottom:4px;">Notes &amp; Review Remarks</div>
      <div style="font-size:0.86rem; color:#1e3a8a;">${escapeHtml(item.notes)}</div>
    </div>
    ` : ''}

    <div>
      <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:6px; text-transform:uppercase;">Proof &amp; Evidence Document</label>
      ${proofContent}
    </div>
  `;

  if (proofModal) {
    proofModal.style.display = 'flex';
    proofModal.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
}

// -- Clarify Modal --------------------------------------------
const clarifyModal = document.getElementById('clarifyModal');

function closeClarifyModal() {
  if (clarifyModal) {
    clarifyModal.classList.remove('active');
    clarifyModal.style.display = 'none';
    document.body.style.overflow = '';
  }
}

clarifyModal?.addEventListener('click', e => { if (e.target === clarifyModal) closeClarifyModal(); });

function openClarifyModal(id, title) {
  const idEl = document.getElementById('clarifyAchId');
  const titleEl = document.getElementById('clarifyAchTitle');
  const notesEl = document.getElementById('clarifyNotes');
  if (idEl) idEl.value = id;
  if (titleEl) titleEl.textContent = 'Achievement: ' + title;
  if (notesEl) notesEl.value = '';
  if (clarifyModal) {
    clarifyModal.style.display = 'flex';
    clarifyModal.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
}

async function submitClarify() {
  const id    = document.getElementById('clarifyAchId').value;
  const notes = document.getElementById('clarifyNotes').value.trim();
  const btn   = document.getElementById('submitClarifyBtn');

  if (!notes) {
    alert('Please enter clarification notes for the requester.');
    return;
  }

  let confirmed = true;
  if (typeof window.showConfirmModal === 'function') {
    confirmed = await window.showConfirmModal(
      'Request Clarification?',
      'Do you want to request clarification from the student for this achievement application?',
      { type: 'warning', warning: true, confirmText: 'Yes, Send Clarification' }
    );
  } else {
    confirmed = confirm('Do you want to request clarification from the student for this achievement application?');
  }
  if (!confirmed) return;

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...';

  const fd = new FormData();
  if (CSRF_TOKEN) fd.set('csrf_token', CSRF_TOKEN);
  fd.set('action', 'clarify');
  fd.set('id', id);
  fd.set('notes', notes);

  fetch('../shared/achievement_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Clarification';
      if (data.success) {
        closeClarifyModal();
        showAlert(data.message || 'Clarification request saved and sent.', 'success');
        setTimeout(() => location.reload(), 1200);
      } else {
        alert(data.message || 'Failed to send clarification.');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Clarification';
      alert('Network error occurred.');
    });
}

// -- Reject Modal ---------------------------------------------
const rejectModal = document.getElementById('rejectModal');

function closeRejectModal() {
  if (rejectModal) {
    rejectModal.classList.remove('active');
    rejectModal.style.display = 'none';
    document.body.style.overflow = '';
  }
}

rejectModal?.addEventListener('click', e => { if (e.target === rejectModal) closeRejectModal(); });

function openRejectModal(id, title) {
  const idEl = document.getElementById('rejectAchId');
  const titleEl = document.getElementById('rejectAchTitle');
  const notesEl = document.getElementById('rejectNotes');
  if (idEl) idEl.value = id;
  if (titleEl) titleEl.textContent = 'Achievement: ' + title;
  if (notesEl) notesEl.value = '';
  if (rejectModal) {
    rejectModal.style.display = 'flex';
    rejectModal.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
}

async function submitReject() {
  const id    = document.getElementById('rejectAchId').value;
  const notes = document.getElementById('rejectNotes').value.trim();
  const btn   = document.getElementById('submitRejectBtn');

  if (!notes) {
    alert('Please provide a reason for rejection.');
    return;
  }

  let confirmed = true;
  if (typeof window.showConfirmModal === 'function') {
    confirmed = await window.showConfirmModal(
      'Reject Achievement Application?',
      'Do you want to reject this achievement application with the stated reason?',
      { type: 'error', danger: true, confirmText: 'Yes, Reject Application' }
    );
  } else {
    confirmed = confirm('Do you want to reject this achievement application?');
  }
  if (!confirmed) return;

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rejecting...';

  const fd = new FormData();
  if (CSRF_TOKEN) fd.set('csrf_token', CSRF_TOKEN);
  fd.set('action', 'reject');
  fd.set('id', id);
  fd.set('notes', notes);

  fetch('../shared/achievement_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-times"></i> Confirm Rejection';
      if (data.success) {
        closeRejectModal();
        showAlert(data.message || 'Achievement marked as Rejected.', 'success');
        setTimeout(() => location.reload(), 1200);
      } else {
        alert(data.message || 'Failed to reject.');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-times"></i> Confirm Rejection';
      alert('Network error occurred.');
    });
}

// -- Direct Verify Action (SSC / Admin) -----------------------
async function verifyAch(id, action) {
  let confirmed = true;
  if (typeof window.showConfirmModal === 'function') {
    confirmed = await window.showConfirmModal(
      'Verify Achievement & Forward to Admin?',
      'Do you want to verify and endorse this achievement and forward it to the Admin for final clearance?',
      { type: 'decision', confirmText: 'Yes, Verify & Pass to Admin' }
    );
  } else {
    confirmed = confirm('Do you want to verify and endorse this achievement and forward it to the Admin for final clearance?');
  }
  if (!confirmed) return;

  const fd = new FormData();
  if (CSRF_TOKEN) fd.set('csrf_token', CSRF_TOKEN);
  fd.set('action', action || 'verify_ssc');
  fd.set('id', id);

  fetch('../shared/achievement_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showAlert(data.message, 'success');
        setTimeout(() => location.reload(), 1200);
      } else {
        showAlert(data.message, 'error');
      }
    })
    .catch(() => showAlert('Network error occurred.', 'error'));
}

// -- Stage 3: Admin Final Approval Action ---------------------
async function approveAch(id) {
  let confirmed = true;
  if (typeof window.showConfirmModal === 'function') {
    confirmed = await window.showConfirmModal(
      'Approve & Publish Achievement?',
      'Do you want to approve this achievement and officially publish it to the Student Organizations Directory?',
      { type: 'decision', confirmText: 'Yes, Approve & Publish' }
    );
  } else {
    confirmed = confirm('Do you want to approve this achievement and officially publish it to the Student Organizations Directory?');
  }
  if (!confirmed) return;

  const fd = new FormData();
  if (CSRF_TOKEN) fd.set('csrf_token', CSRF_TOKEN);
  fd.set('action', 'approve_admin');
  fd.set('id', id);

  fetch('../shared/achievement_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showAlert(data.message, 'success');
        setTimeout(() => location.reload(), 1200);
      } else {
        showAlert(data.message, 'error');
      }
    })
    .catch(() => showAlert('Network error occurred.', 'error'));
}
</script>
</body>
</html>
