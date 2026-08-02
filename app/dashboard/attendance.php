<?php
// ============================================================
//  ATTENDANCE.PHP  (dashboard/)
//  Co-Curricular System — Attendance Tracker & Scanner Terminal (RBAC Filtered)
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php');
    exit;
}

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Attendance Tracker – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'attendance';
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
        <input type="text" placeholder="Search attendance..."/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i class="fa-solid fa-qrcode"></i></button>
      <a href="../dashboard/account.php" class="avatar" id="avatarBtn" title="Account Settings">
        <?= $sess_initial ?>
      </a>
    </div>
  </div>

  <!-- Content -->
  <div class="content">

    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-qrcode"></i>
        Hybrid Attendance Tracking Portal
      </h2>
    </div>

    <div class="content-body">

      <!-- Scanner Terminal Panel (HIDDEN from General Students) -->
      <?php if (in_array($sess_role, ['club_adviser', 'osa_director', 'admin'])): ?>
      <div class="table-card" id="terminal">
        <h3><i class="fa-solid fa-camera" style="color:#2563eb;"></i> Active Event Entry Scanner Terminal (Dual QR / RFID)</h3>
        <p style="font-size:0.85rem; color:#64748b; margin-bottom:14px;">
          Supports dynamic mobile QR scanning (< 1 sec check-in) and plug-and-play USB RFID readers for physical SMS ID cards.
        </p>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="card-btn" onclick="alert('PWA Camera QR Scanner Active! Position QR code in front of camera.')">
            <i class="fa-solid fa-camera-retro"></i> Launch Camera QR Scanner
          </button>
          <button class="card-btn" style="background:#16a34a;" onclick="alert('USB RFID Terminal Enabled! Listening for RFID card taps...')">
            <i class="fa-solid fa-id-card"></i> Enable USB RFID Listener
          </button>
        </div>
      </div>
      <?php endif; ?>

      <!-- Attendance History & Analytics -->
      <div class="tables-row">
        <div class="table-card">
          <h3><i class="fa-solid fa-history" style="color:#2563eb;"></i> My Event Attendance History</h3>
          <table class="data-table">
            <thead>
              <tr>
                <th>Event Name</th>
                <th>Date & Time</th>
                <th>Check-in Method</th>
                <th>Verification</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><strong>Tech Orientation 2025</strong></td>
                <td>Sep 10, 2025 • 09:12 AM</td>
                <td>Mobile QR Scan</td>
                <td><span class="badge-info">Verified Logged</span></td>
              </tr>
              <tr>
                <td><strong>University Convocation</strong></td>
                <td>Nov 20, 2025 • 08:30 AM</td>
                <td>Hardware RFID Tap</td>
                <td><span class="badge-info">Verified Logged</span></td>
              </tr>
            </tbody>
          </table>
        </div>

        <?php if (in_array($sess_role, ['osa_director', 'admin'])): ?>
        <div class="table-card" id="analytics">
          <h3><i class="fa-solid fa-chart-line" style="color:#2563eb;"></i> Absentee & Attendance Analytics</h3>
          <div style="margin-bottom:12px;">
            <div style="display:flex; justify-content:space-between; font-size:0.8rem; font-weight:600; margin-bottom:4px;">
              <span>Overall Event Attendance Rate</span><span>92%</span>
            </div>
            <div style="background:#e2e8f0; height:8px; border-radius:4px; overflow:hidden;">
              <div style="background:#2563eb; width:92%; height:100%;"></div>
            </div>
          </div>
          <p style="font-size:0.75rem; color:#64748b; margin-bottom:10px;">Service hours feed directly into Faculty Clearance and SIS Transcript Generator.</p>
          <button class="card-btn" style="background:#666;" onclick="alert('Manual Attendance Override Form Opened.')">Manual Attendance Override</button>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- end content-body -->
  </div><!-- end content -->

  <div class="footer">Co-Curricular Management System &copy; 2026</div>
</div><!-- end main -->

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
</body>
</html>
