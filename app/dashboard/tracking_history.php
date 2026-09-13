<?php
// ============================================================
//  TRACKING_HISTORY.PHP  (dashboard/)
//  Co-Curricular System — Student Event Attendance Log & History
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) {
  header('Location: ../auth/signin.php');
  exit;
}

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name'] ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)($_SESSION['user_id'] ?? 0);

// Fetch current student's attendance history
$my_attendance = [];
$att_res = $conn->query("
  SELECT al.*, e.title as event_title, e.event_date, e.venue, c.name as club_name, c.code as club_code
  FROM attendance_logs al
  JOIN events e ON e.id = al.event_id
  LEFT JOIN clubs c ON c.id = e.club_id
  WHERE al.user_id = $user_id
  ORDER BY al.check_in DESC
");
if ($att_res) {
  $my_attendance = $att_res->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>My Attendance History – Tracking Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
</head>

<body>

  <?php
  $APP_ROOT = '../';
  $ACTIVE_NAV = 'attendance';
  $ACTIVE_SUB = 'history';
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
        <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i
            class="fa-solid fa-qrcode"></i></button>
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

      <div class="page-title-bar">
        <div>
          <h2 class="page-title">
            <i class="fa-solid fa-clipboard-user" style="color:#2563eb;"></i>
            My Event Attendance History
          </h2>
          <p style="font-size:0.85rem; color:#64748b; margin:4px 0 0 0;">
            Personal audit trail of all verified campus event attendances and co-curricular service logs.
          </p>
        </div>
      </div>

      <div class="content-body">

        <div class="table-card">
          <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
            <h3 style="margin:0;"><i class="fa-solid fa-clock-rotate-left" style="color:#2563eb;"></i> Verified Attendance Logs</h3>
            <span style="font-size:0.8rem; color:#64748b; font-weight:600;">Academic Year 2025&ndash;2026</span>
          </div>

          <div class="resp-table-wrap">
            <table class="data-table resp-table">
              <thead>
                <tr>
                  <th style="padding:14px 18px;">Event Name</th>
                  <th style="padding:14px 18px;">Host Organization</th>
                  <th style="padding:14px 18px;">Date &amp; Time</th>
                  <th style="padding:14px 18px;">Venue</th>
                  <th style="padding:14px 18px;">Check-In Method</th>
                  <th style="padding:14px 18px;">Verification Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($my_attendance)): ?>
                  <tr>
                    <td colspan="6" style="text-align:center; padding:35px 15px; color:#94a3b8;">
                      <i class="fa-solid fa-calendar-xmark" style="font-size:1.8rem; color:#cbd5e1; margin-bottom:8px; display:block;"></i>
                      No attendance records found yet. Scan an Event QR code when attending campus activities!
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($my_attendance as $att): ?>
                    <tr>
                      <td data-label="Event Name" style="padding:14px 18px;"><strong><?= htmlspecialchars($att['event_title']) ?></strong></td>
                      <td data-label="Host Organization" style="padding:14px 18px; font-size:0.85rem; color:#334155;"><?= htmlspecialchars($att['club_name'] ?? 'College Activity') ?> <span style="color:#94a3b8;">(<?= htmlspecialchars($att['club_code'] ?? 'BCP') ?>)</span></td>
                      <td data-label="Date & Time" style="padding:14px 18px; font-size:0.85rem; color:#475569;"><?= date('M d, Y h:i A', strtotime($att['check_in'])) ?></td>
                      <td data-label="Venue" style="padding:14px 18px; font-size:0.85rem; color:#475569;"><?= htmlspecialchars($att['venue'] ?? 'Campus Venue') ?></td>
                      <td data-label="Method" style="padding:14px 18px;">
                        <span style="padding:4px 10px; border-radius:6px; font-size:0.75rem; font-weight:700; background:#f1f5f9; color:#475569; display:inline-flex; align-items:center; gap:5px;">
                          <i class="fa-solid <?= ($att['method'] === 'RFID' ? 'fa-id-card' : 'fa-qrcode') ?>"></i> <?= htmlspecialchars($att['method']) ?>
                        </span>
                      </td>
                      <td data-label="Status" style="padding:14px 18px;">
                        <span class="badge-active" style="padding:4px 10px; font-size:0.75rem; font-weight:700; border-radius:6px; background:#dcfce7; color:#15803d; display:inline-flex; align-items:center; gap:4px;">
                          <i class="fa-solid fa-check"></i> Verified Present
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div><!-- end content-body -->
    </div><!-- end content -->

    <div class="footer">eLearning Commons &copy; 2026</div>
  </div><!-- end main -->

  <script src="../js/dashboard.js"></script>
  <script src="../js/table-pagination.js"></script>
</body>

</html>
