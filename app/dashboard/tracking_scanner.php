<?php
// ============================================================
//  TRACKING_SCANNER.PHP  (dashboard/)
//  Co-Curricular System — On-Site Attendance Scanner Terminal
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

if ($sess_role === 'student') {
  header('Location: tracking_history.php');
  exit;
}

// Fetch active events for staff event selection
$active_events = [];
$ev_res = $conn->query("
  SELECT e.id, e.title, e.event_date, e.venue, c.name as club_name, c.code as club_code
  FROM events e
  LEFT JOIN clubs c ON c.id = e.club_id
  WHERE e.status IN ('Approved','Upcoming')
  ORDER BY e.event_date ASC
");
if ($ev_res) {
  $active_events = $ev_res->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>On-Site Scanner Terminal – Tracking Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <script src="https://unpkg.com/@zxing/library@0.21.1/umd/index.min.js"></script>
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <style>
    .scanner-layout {
      display: grid;
      grid-template-columns: 1fr 1.1fr;
      gap: 24px;
      align-items: start;
    }
    @media (max-width: 992px) {
      .scanner-layout {
        grid-template-columns: 1fr;
      }
    }
    .scanner-viewport-box {
      background: #0f172a;
      border-radius: 16px;
      position: relative;
      overflow: hidden;
      aspect-ratio: 4/3;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    }
    .scanner-laser {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, transparent, #22c55e, transparent);
      box-shadow: 0 0 12px #22c55e;
      animation: laserSweep 2s ease-in-out infinite;
      z-index: 5;
    }
    @keyframes laserSweep {
      0%, 100% { top: 10%; }
      50% { top: 90%; }
    }
    .feed-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 14px;
      border-bottom: 1px solid #f1f5f9;
      font-size: 0.85rem;
      animation: fadeIn 0.3s ease;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-4px); }
      to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>

<body>

  <?php
  $APP_ROOT = '../';
  $ACTIVE_NAV = 'attendance';
  $ACTIVE_SUB = 'scanner';
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
            <i class="fa-solid fa-camera" style="color:#16a34a;"></i>
            On-Site Scanner Terminal
          </h2>
          <p style="font-size:0.85rem; color:#64748b; margin:4px 0 0 0;">
            Real-time camera scanning terminal for instant student badge check-in and venue attendance verification.
          </p>
        </div>
      </div>

      <div class="content-body">

        <div class="scanner-layout">

          <!-- Left Column: Camera Viewport & Controls -->
          <div class="table-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
              <h3 style="margin:0;"><i class="fa-solid fa-video" style="color:#2563eb;"></i> Camera Feed</h3>
              <span id="scannerStatusBadge" style="font-size:0.75rem; font-weight:700; background:#f1f5f9; color:#64748b; padding:4px 10px; border-radius:999px;">
                ● Standby
              </span>
            </div>

            <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
              <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px;">Target Event for Check-In:</label>
                <select id="scannerEventSelect" class="form-control" style="width:100%; height:42px; padding:0 12px; border-radius:10px; border:1px solid #cbd5e1; font-weight:600; font-size:0.88rem;">
                  <option value="">— Select Target Event —</option>
                  <?php foreach ($active_events as $ev): ?>
                    <option value="<?= $ev['id'] ?>"><?= htmlspecialchars($ev['title']) ?> (<?= date('M d', strtotime($ev['event_date'])) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <div style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; padding:12px 14px; border-radius:10px; margin-bottom:14px; font-size:0.84rem; font-weight:600;">
                <i class="fa-solid fa-circle-info"></i> Point camera directly at the <code>BCP-EVENT-*</code> QR code poster at the venue to self-check in.
              </div>
            <?php endif; ?>

            <!-- Viewport -->
            <div class="scanner-viewport-box">
              <video id="scannerVideo" autoplay playsinline muted style="width:100%; height:100%; object-fit:cover; display:none;"></video>
              <div class="scanner-laser" id="scannerLaser" style="display:none;"></div>
              <div id="cameraStandbyBox" style="color:#94a3b8; text-align:center; padding:20px;">
                <i class="fa-solid fa-camera" style="font-size:2.5rem; margin-bottom:10px; color:#475569;"></i>
                <div style="font-weight:600; font-size:0.95rem; color:#cbd5e1;">Camera Currently Inactive</div>
                <div style="font-size:0.8rem; color:#64748b; margin-top:4px;">Click the button below to start the live camera stream.</div>
              </div>
            </div>

            <!-- Result Feedback Box -->
            <div id="scanResultAlert" style="display:none; margin-top:14px; padding:12px 16px; border-radius:10px; font-size:0.88rem; font-weight:700; align-items:center; gap:8px;"></div>

            <!-- Action Trigger Button -->
            <div style="margin-top:16px; display:flex; gap:10px;">
              <button id="toggleScannerBtn" class="card-btn" style="flex:1; height:46px; background:#16a34a; color:#fff; font-size:0.95rem; font-weight:700; border-radius:10px; justify-content:center; display:flex; align-items:center; gap:8px; cursor:pointer;" onclick="toggleTerminalScanner()">
                <i class="fa-solid fa-camera"></i> Start Terminal Scanner
              </button>
            </div>
          </div>

          <!-- Right Column: Real-Time Attendance Stream Log -->
          <div class="table-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
              <h3 style="margin:0;"><i class="fa-solid fa-stream" style="color:#2563eb;"></i> Live Scan Stream</h3>
              <span style="font-size:0.78rem; color:#64748b; font-weight:600;" id="scanCountLabel">0 Scans This Session</span>
            </div>

            <div id="scanStreamLog" style="max-height:460px; overflow-y:auto; border:1px solid #f1f5f9; border-radius:10px; background:#f8fafc;">
              <div id="emptyLogPrompt" style="text-align:center; padding:40px 20px; color:#94a3b8;">
                <i class="fa-solid fa-qrcode" style="font-size:2rem; margin-bottom:10px; color:#cbd5e1;"></i>
                <p style="margin:0; font-size:0.85rem;">Scanned badges and check-ins will appear here in real-time.</p>
              </div>
            </div>

            <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
              <div style="margin-top:16px; text-align:right;">
                <a href="tracking_attendance_list.php" class="card-btn" style="background:#f1f5f9; color:#334155; font-weight:600; padding:8px 16px; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
                  <i class="fa-solid fa-list-check"></i> View Full Attendance Roster &rarr;
                </a>
              </div>
            <?php endif; ?>
          </div>

        </div><!-- end .scanner-layout -->

      </div><!-- end content-body -->
    </div><!-- end content -->

    <div class="footer">eLearning Commons &copy; 2026</div>
  </div><!-- end main -->

  <script src="../js/dashboard.js"></script>
  <script>
    let termCodeReader = null;
    let termScanning = false;
    let lastScannedVal = '';
    let lastScanStamp = 0;
    let scanCount = 0;

    async function toggleTerminalScanner() {
      if (termScanning) {
        stopTerminalScanner();
      } else {
        startTerminalScanner();
      }
    }

    async function startTerminalScanner() {
      const video = document.getElementById('scannerVideo');
      const standby = document.getElementById('cameraStandbyBox');
      const laser = document.getElementById('scannerLaser');
      const btn = document.getElementById('toggleScannerBtn');
      const badge = document.getElementById('scannerStatusBadge');
      const alertBox = document.getElementById('scanResultAlert');

      if (!window.ZXing) {
        alert('Scanner library is loading. Please try again in a moment.');
        return;
      }

      try {
        if (typeof ZXing.BrowserQRCodeReader === 'function') {
          termCodeReader = new ZXing.BrowserQRCodeReader();
        } else {
          termCodeReader = new ZXing.BrowserMultiFormatReader();
        }

        const devices = await termCodeReader.listVideoInputDevices();
        if (!devices || !devices.length) {
          alert('No video camera detected on this system.');
          return;
        }

        const device = devices.find(d => /back|rear|environment/i.test(d.label)) || devices[0];

        if (standby) standby.style.display = 'none';
        if (video) video.style.display = 'block';
        if (laser) laser.style.display = 'block';

        termScanning = true;
        if (badge) {
          badge.innerHTML = '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,0.2);margin-right:5px;"></span> Live Scanning';
          badge.style.background = '#dcfce7';
          badge.style.color = '#15803d';
        }
        if (btn) {
          btn.innerHTML = '<i class="fa-solid fa-stop"></i> Stop Terminal Scanner';
          btn.style.background = '#ef4444';
        }

        termCodeReader.decodeFromVideoDevice(device.deviceId, 'scannerVideo', (result, err) => {
          if (result) {
            const code = result.getText().trim();
            const now = Date.now();
            if (code === lastScannedVal && (now - lastScanStamp) < 3000) return;
            lastScannedVal = code;
            lastScanStamp = now;

            try { navigator.vibrate?.([70, 40, 70]); } catch (e) {}
            processScanData(code);
          }
        });
      } catch (err) {
        console.error('Camera error:', err);
        alert('Camera access failed: ' + (err.message || 'Please permit camera permissions.'));
        stopTerminalScanner();
      }
    }

    function stopTerminalScanner() {
      if (termCodeReader) {
        try { termCodeReader.reset(); } catch (e) {}
        termCodeReader = null;
      }
      termScanning = false;

      const video = document.getElementById('scannerVideo');
      const standby = document.getElementById('cameraStandbyBox');
      const laser = document.getElementById('scannerLaser');
      const btn = document.getElementById('toggleScannerBtn');
      const badge = document.getElementById('scannerStatusBadge');

      if (video) {
        if (video.srcObject && typeof video.srcObject.getTracks === 'function') {
          video.srcObject.getTracks().forEach(t => t.stop());
        }
        video.style.display = 'none';
        video.srcObject = null;
      }
      if (standby) standby.style.display = 'block';
      if (laser) laser.style.display = 'none';
      if (badge) {
        badge.textContent = '● Standby';
        badge.style.background = '#f1f5f9';
        badge.style.color = '#64748b';
      }
      if (btn) {
        btn.innerHTML = '<i class="fa-solid fa-camera"></i> Start Terminal Scanner';
        btn.style.background = '#16a34a';
      }
    }

    function processScanData(qrData) {
      const eventId = parseInt(document.getElementById('scannerEventSelect')?.value || '0');
      const alertBox = document.getElementById('scanResultAlert');

      const isEventQr = /^BCP-EVENT(?:-LOG)?-\d+/i.test(qrData);
      const isStudent = <?= json_encode($sess_role === 'student') ?>;

      if (!isEventQr && !isStudent && eventId <= 0) {
        if (alertBox) {
          alertBox.style.display = 'flex';
          alertBox.style.background = '#fef2f2';
          alertBox.style.color = '#dc2626';
          alertBox.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Please select a target event above before scanning student badges!';
        }
        return;
      }

      if (alertBox) {
        alertBox.style.display = 'flex';
        alertBox.style.background = '#eff6ff';
        alertBox.style.color = '#1e40af';
        alertBox.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Validating check-in...';
      }

      const fd = new FormData();
      fd.append('action', 'log_qr');
      fd.append('qr_data', qrData);
      fd.append('event_id', eventId);

      fetch('../shared/attendance_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          const success = data.success;
          const isDup = data.already_logged;
          if (alertBox) {
            alertBox.style.background = success ? '#dcfce7' : (isDup ? '#fef9c3' : '#fef2f2');
            alertBox.style.color = success ? '#15803d' : (isDup ? '#92400e' : '#dc2626');
            alertBox.innerHTML = `<i class="fa-solid ${success ? 'fa-circle-check' : (isDup ? 'fa-triangle-exclamation' : 'fa-circle-xmark')}"></i> ${data.message}`;
          }

          // Add to live stream
          addScanStreamItem(qrData, data.message, success, isDup);
        })
        .catch(() => {
          if (alertBox) {
            alertBox.style.background = '#fef2f2';
            alertBox.style.color = '#dc2626';
            alertBox.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Network error logging attendance.';
          }
        });
    }

    function addScanStreamItem(qrCode, msg, success, isDup) {
      const log = document.getElementById('scanStreamLog');
      const empty = document.getElementById('emptyLogPrompt');
      if (empty) empty.style.display = 'none';

      scanCount++;
      const countLbl = document.getElementById('scanCountLabel');
      if (countLbl) countLbl.textContent = `${scanCount} Scans This Session`;

      const item = document.createElement('div');
      item.className = 'feed-item';
      item.style.background = success ? '#ffffff' : (isDup ? '#fefce8' : '#fff5f5');
      item.innerHTML = `
        <div>
          <div style="font-weight:700; color:#0f172a;">${msg}</div>
          <div style="font-size:0.75rem; color:#64748b;">Code: <code>${qrCode}</code></div>
        </div>
        <div style="text-align:right;">
          <span style="font-size:0.75rem; font-weight:700; color:${success ? '#16a34a' : (isDup ? '#ca8a04' : '#dc2626')};">
            ${success ? '✓ Logged' : (isDup ? '⚠ Already Present' : '✗ Failed')}
          </span>
          <div style="font-size:0.72rem; color:#94a3b8;">${new Date().toLocaleTimeString()}</div>
        </div>
      `;
      log.prepend(item);
    }
  </script>
</body>

</html>
