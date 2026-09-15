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
$target_event_id = (int)($_GET['event_id'] ?? 0);
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
  <script src="../js/html5-qrcode.min.js"></script>
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
    #html5qrReader {
      width: 100% !important;
      height: 100% !important;
      border: none !important;
    }
    #html5qrReader video {
      width: 100% !important;
      height: 100% !important;
      object-fit: cover !important;
      border-radius: 14px !important;
    }
    #html5qrReader__scan_region {
      width: 100% !important;
      height: 100% !important;
    }
    #html5qrReader__header_message {
      display: none !important;
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
                <select id="scannerEventSelect" class="form-control" style="width:100%; height:42px; padding:0 12px; border-radius:10px; border:1px solid #cbd5e1; font-weight:600; font-size:0.88rem;" onchange="handleEventSelectionChange()">
                  <option value="">— Select Target Event —</option>
                  <?php foreach ($active_events as $ev): ?>
                    <option value="<?= $ev['id'] ?>" <?= ($target_event_id === (int)$ev['id']) ? 'selected' : '' ?> data-title="<?= htmlspecialchars($ev['title']) ?>">
                      <?= htmlspecialchars($ev['title']) ?> (<?= date('M d', strtotime($ev['event_date'])) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <div style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; padding:12px 14px; border-radius:10px; margin-bottom:14px; font-size:0.84rem; font-weight:600;">
                <i class="fa-solid fa-circle-info"></i> Point camera directly at the <code>BCP-EVENT-*</code> QR code poster at the venue to self-check in.
              </div>
            <?php endif; ?>

            <!-- Viewport -->
            <div class="scanner-viewport-box" id="scannerViewportBox">
              <div id="html5qrReader" style="width:100%; height:100%; display:none;"></div>
              <video id="scannerVideo" autoplay playsinline muted style="width:100%; height:100%; object-fit:cover; display:none;"></video>
              <div class="scanner-laser" id="scannerLaser" style="display:none;"></div>
              <div id="cameraStandbyBox" style="color:#94a3b8; text-align:center; padding:20px;">
                <i class="fa-solid fa-camera" style="font-size:2.5rem; margin-bottom:10px; color:#475569;"></i>
                <div style="font-weight:600; font-size:0.95rem; color:#cbd5e1;">Camera Currently Inactive</div>
                <div style="font-size:0.8rem; color:#64748b; margin-top:4px;">Click below to start live camera or select a QR image to scan.</div>
              </div>
            </div>

            <!-- Dedicated hidden reader for image file decoding (prevents element collision) -->
            <div id="html5qrReaderFile" style="display:none; width:1px; height:1px;"></div>

            <!-- Result Feedback Box -->
            <div id="scanResultAlert" style="display:none; margin-top:14px; padding:12px 16px; border-radius:10px; font-size:0.88rem; font-weight:700; align-items:center; gap:8px;"></div>

            <!-- Action Trigger Controls -->
            <div style="margin-top:16px; display:flex; flex-direction:column; gap:10px;">
              <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button id="toggleScannerBtn" type="button" class="card-btn" style="flex:1; min-width:180px; height:44px; background:#16a34a; color:#fff; font-size:0.92rem; font-weight:700; border-radius:10px; justify-content:center; display:flex; align-items:center; gap:8px; cursor:pointer;" onclick="toggleTerminalScanner()">
                  <i class="fa-solid fa-camera"></i> Start Terminal Scanner
                </button>
                <button id="uploadQrBtn" type="button" class="card-btn" style="height:44px; padding:0 16px; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; font-size:0.88rem; font-weight:600; border-radius:10px; justify-content:center; display:flex; align-items:center; gap:8px; cursor:pointer;" onclick="document.getElementById('terminalQrFileInput').click()" title="Scan from QR Code Image File">
                  <i class="fa-solid fa-image"></i> Scan Image File
                </button>
                <input type="file" id="terminalQrFileInput" accept="image/*" style="display:none;" onchange="handleTerminalFileUpload(event)" />
              </div>

              <!-- Manual / Badge ID Entry -->
              <div style="display:flex; gap:8px;">
                <div style="position:relative; flex:1;">
                  <i class="fa-solid fa-barcode" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.85rem;"></i>
                  <input type="text" id="manualQrInput" placeholder="Manual code entry (e.g. BCP-STUDENT-1 or 2024-10001)" style="width:100%; height:38px; padding:0 12px 0 34px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.82rem; font-weight:500; outline:none;" onkeydown="if(event.key==='Enter'){event.preventDefault();submitManualTerminalCode();}" />
                </div>
                <button type="button" onclick="submitManualTerminalCode()" style="height:38px; padding:0 14px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:600; font-size:0.82rem; cursor:pointer; display:flex; align-items:center; gap:6px;">
                  <i class="fa-solid fa-check"></i> Check In
                </button>
              </div>
            </div>
          </div>

          <!-- Right Column: Real-Time Attendance Stream Log (Selected Event Only) -->
          <div class="table-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
              <div>
                <h3 style="margin:0;"><i class="fa-solid fa-stream" style="color:#2563eb;"></i> Live Scan Stream</h3>
                <div id="streamEventSubtitle" style="font-size:0.78rem; color:#64748b; margin-top:2px;">
                  Showing check-ins for selected event only
                </div>
              </div>
              <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:0.75rem; color:#64748b; font-weight:700; background:#f1f5f9; padding:4px 10px; border-radius:999px;" id="scanCountLabel">No Event Selected</span>
                <button type="button" onclick="refreshSelectedEventStream(true)" title="Refresh Event Stream" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; width:30px; height:30px; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#475569;">
                  <i class="fa-solid fa-rotate-right" id="streamRefreshIcon"></i>
                </button>
              </div>
            </div>

            <div id="scanStreamLog" style="max-height:480px; min-height:220px; overflow-y:auto; border:1px solid #f1f5f9; border-radius:10px; background:#f8fafc;">
              <div id="emptyLogPrompt" style="text-align:center; padding:45px 20px; color:#94a3b8;">
                <i class="fa-solid fa-calendar-xmark" style="font-size:2.2rem; margin-bottom:10px; color:#cbd5e1;"></i>
                <p style="margin:0; font-size:0.88rem; font-weight:600; color:#475569;">Please select a target event above.</p>
                <p style="margin:4px 0 0 0; font-size:0.78rem; color:#94a3b8;">The live stream will display all check-ins for that specific event only.</p>
              </div>
            </div>

            <div style="margin-top:16px; display:flex; justify-content:space-between; align-items:center;">
              <span id="streamAutoSyncBadge" style="font-size:0.72rem; color:#94a3b8; display:flex; align-items:center; gap:5px;">
                <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#16a34a;"></span> Live Event Feed
              </span>
              <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
                <a href="tracking_attendance_list.php" class="card-btn" style="background:#f1f5f9; color:#334155; font-weight:600; padding:8px 16px; border-radius:8px; display:inline-flex; align-items:center; gap:6px; font-size:0.82rem;">
                  <i class="fa-solid fa-list-check"></i> View Full Attendance Roster &rarr;
                </a>
              <?php endif; ?>
            </div>
          </div>

        </div><!-- end .scanner-layout -->

      </div><!-- end content-body -->
    </div><!-- end content -->

    <div class="footer">eLearning Commons &copy; 2026</div>
  </div><!-- end main -->

  <script src="../js/dashboard.js"></script>
  <script>
    let html5QrScanner = null;
    let termCodeReader = null;
    let termScanning = false;
    let lastScannedVal = '';
    let lastScanStamp = 0;
    let streamSyncTimer = null;

    function escapeHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    // ── Live Scan Stream Linked Specifically to Selected Event ────────
    function handleEventSelectionChange() {
      const sel = document.getElementById('scannerEventSelect');
      const eventId = parseInt(sel?.value || '0');
      const eventTitle = sel ? (sel.options[sel.selectedIndex]?.getAttribute('data-title') || sel.options[sel.selectedIndex]?.text || '') : '';
      const sub = document.getElementById('streamEventSubtitle');
      if (sub) {
        sub.textContent = eventId > 0 ? `Target Event: ${eventTitle}` : 'Showing check-ins for selected event only';
      }
      loadEventStream(eventId, true);
    }

    async function loadEventStream(eventId, showSpinner = false) {
      const log = document.getElementById('scanStreamLog');
      const countLbl = document.getElementById('scanCountLabel');
      const icon = document.getElementById('streamRefreshIcon');

      if (!eventId || eventId <= 0) {
        if (countLbl) countLbl.textContent = 'No Event Selected';
        if (log) {
          log.innerHTML = `
            <div id="emptyLogPrompt" style="text-align:center; padding:45px 20px; color:#94a3b8;">
              <i class="fa-solid fa-calendar-xmark" style="font-size:2.2rem; margin-bottom:10px; color:#cbd5e1;"></i>
              <p style="margin:0; font-size:0.88rem; font-weight:600; color:#475569;">Please select a target event above.</p>
              <p style="margin:4px 0 0 0; font-size:0.78rem; color:#94a3b8;">The live stream will display all check-ins for that specific event only.</p>
            </div>
          `;
        }
        return;
      }

      if (icon) icon.classList.add('fa-spin');
      if (showSpinner && log) {
        log.innerHTML = `
          <div style="text-align:center; padding:45px 20px; color:#64748b;">
            <i class="fa-solid fa-spinner fa-spin" style="font-size:1.8rem; color:#2563eb; margin-bottom:10px;"></i>
            <div style="font-size:0.85rem; font-weight:600;">Loading event attendance stream...</div>
          </div>
        `;
      }

      try {
        const res = await fetch(`../shared/attendance_actions.php?action=list_event&event_id=${eventId}`);
        const data = await res.json();
        if (data.success) {
          const attendees = data.attendees || [];
          if (countLbl) {
            countLbl.textContent = `${attendees.length} Attendee${attendees.length === 1 ? '' : 's'} Recorded`;
          }
          if (log) {
            if (attendees.length === 0) {
              log.innerHTML = `
                <div id="emptyLogPrompt" style="text-align:center; padding:45px 20px; color:#94a3b8;">
                  <i class="fa-solid fa-clipboard-user" style="font-size:2.2rem; margin-bottom:10px; color:#cbd5e1;"></i>
                  <p style="margin:0; font-size:0.88rem; font-weight:600; color:#475569;">No attendees logged yet for this event.</p>
                  <p style="margin:4px 0 0 0; font-size:0.78rem; color:#94a3b8;">Start scanner or enter student ID/badge to record check-in live.</p>
                </div>
              `;
            } else {
              let html = '';
              attendees.forEach(att => {
                const timeStr = att.check_in ? new Date(att.check_in.replace(' ', 'T')).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit', second:'2-digit' }) : 'Logged';
                const methodLbl = att.method === 'QR_SELF' ? 'Self QR' : (att.method || 'QR');
                const idBadge = att.student_number ? `Student #: ${att.student_number}` : (att.email || `User ID #${att.user_id}`);
                html += `
                  <div class="feed-item" style="background:#ffffff; padding:10px 14px; border-bottom:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                      <div style="font-weight:700; color:#0f172a; font-size:0.88rem;">${escapeHtml(att.first_name + ' ' + att.last_name)}</div>
                      <div style="font-size:0.75rem; color:#64748b;">${escapeHtml(idBadge)}</div>
                    </div>
                    <div style="text-align:right;">
                      <div style="display:flex; align-items:center; gap:6px; justify-content:flex-end;">
                        <span style="font-size:0.7rem; font-weight:700; color:#1e40af; background:#eff6ff; padding:2px 6px; border-radius:4px; border:1px solid #bfdbfe;">${methodLbl}</span>
                        <span style="font-size:0.72rem; font-weight:700; color:#16a34a; background:#dcfce7; padding:2px 8px; border-radius:999px;">✓ Present</span>
                      </div>
                      <div style="font-size:0.72rem; color:#94a3b8; margin-top:2px;">${timeStr}</div>
                    </div>
                  </div>
                `;
              });
              log.innerHTML = html;
            }
          }
        }
      } catch (err) {
        console.warn('Error loading event attendance stream:', err);
      } finally {
        if (icon) icon.classList.remove('fa-spin');
      }
    }

    function refreshSelectedEventStream(showSpinner = false) {
      const sel = document.getElementById('scannerEventSelect');
      const eventId = parseInt(sel?.value || '0');
      if (eventId > 0) {
        loadEventStream(eventId, showSpinner);
      }
    }

    // Auto-sync stream every 4 seconds in background
    streamSyncTimer = setInterval(() => {
      if (!document.hidden) {
        refreshSelectedEventStream(false);
      }
    }, 4000);

    // ── Scanner Engine Controls ─────────────────────────────────────────
    async function toggleTerminalScanner() {
      if (termScanning) {
        await stopTerminalScanner();
      } else {
        await startTerminalScanner();
      }
    }

    async function startTerminalScanner() {
      const standby = document.getElementById('cameraStandbyBox');
      const readerDiv = document.getElementById('html5qrReader');
      const video = document.getElementById('scannerVideo');
      const laser = document.getElementById('scannerLaser');
      const btn = document.getElementById('toggleScannerBtn');
      const badge = document.getElementById('scannerStatusBadge');
      const alertBox = document.getElementById('scanResultAlert');

      // 1. Primary Engine: Html5Qrcode
      if (typeof Html5Qrcode !== 'undefined') {
        try {
          if (standby) standby.style.display = 'none';
          if (video) video.style.display = 'none';
          if (readerDiv) readerDiv.style.display = 'block';
          if (laser) laser.style.display = 'block';

          html5QrScanner = new Html5Qrcode("html5qrReader");
          const config = {
            fps: 15,
            qrbox: function(viewfinderWidth, viewfinderHeight) {
              const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
              const qrboxSize = Math.max(180, Math.floor(minEdge * 0.82));
              return { width: qrboxSize, height: qrboxSize };
            },
            aspectRatio: 1.333334
          };

          // Query available cameras to pick valid camera ID
          const cameras = await Html5Qrcode.getCameras().catch(() => []);
          let cameraConfig;
          if (cameras && cameras.length > 0) {
            const backCam = cameras.find(c => /back|rear|environment/i.test(c.label));
            cameraConfig = backCam ? backCam.id : cameras[0].id;
          } else {
            cameraConfig = { facingMode: "environment" };
          }

          try {
            await html5QrScanner.start(
              cameraConfig,
              config,
              (decodedText) => { handleDecodedCode(decodedText); },
              (err) => { /* Frame pass */ }
            );
          } catch (firstStartErr) {
            console.warn("Primary cameraConfig failed, retrying with facingMode user:", firstStartErr);
            await html5QrScanner.start(
              { facingMode: "user" },
              config,
              (decodedText) => { handleDecodedCode(decodedText); },
              (err) => { /* Frame pass */ }
            );
          }

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
          return;
        } catch (h5Err) {
          console.warn("Html5Qrcode live scan error, falling back to ZXing:", h5Err);
          if (html5QrScanner) {
            try { await html5QrScanner.stop(); html5QrScanner.clear(); } catch(e){}
            html5QrScanner = null;
          }
        }
      }

      // 2. Fallback to ZXing if Html5Qrcode fails
      if (window.ZXing) {
        try {
          if (typeof ZXing.BrowserQRCodeReader === 'function') {
            termCodeReader = new ZXing.BrowserQRCodeReader();
          } else {
            termCodeReader = new ZXing.BrowserMultiFormatReader();
          }

          if (readerDiv) readerDiv.style.display = 'none';
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

          termCodeReader.decodeFromVideoDevice(undefined, 'scannerVideo', (result, err) => {
            if (result) {
              handleDecodedCode(result.getText());
            }
          });
          return;
        } catch (zxErr) {
          console.error("ZXing fallback error:", zxErr);
          alert('Camera access failed: ' + (zxErr.message || 'Please permit camera permissions in your browser.'));
          await stopTerminalScanner();
          return;
        }
      }

      alert('Scanner library is loading. Please try again in a moment.');
    }

    function handleDecodedCode(rawCode) {
      if (!rawCode) return;
      const code = rawCode.trim();
      const now = Date.now();
      if (code === lastScannedVal && (now - lastScanStamp) < 2500) return;
      lastScannedVal = code;
      lastScanStamp = now;

      try { navigator.vibrate?.([70, 40, 70]); } catch (e) {}
      processScanData(code);
    }

    async function stopTerminalScanner() {
      termScanning = false;
      if (html5QrScanner) {
        try {
          await html5QrScanner.stop();
          html5QrScanner.clear();
        } catch(e) {}
        html5QrScanner = null;
      }
      if (termCodeReader) {
        try { termCodeReader.reset(); } catch (e) {}
        termCodeReader = null;
      }

      const readerDiv = document.getElementById('html5qrReader');
      const video = document.getElementById('scannerVideo');
      const standby = document.getElementById('cameraStandbyBox');
      const laser = document.getElementById('scannerLaser');
      const btn = document.getElementById('toggleScannerBtn');
      const badge = document.getElementById('scannerStatusBadge');

      if (readerDiv) readerDiv.style.display = 'none';
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

    async function handleTerminalFileUpload(e) {
      const file = e.target.files?.[0];
      if (!file) return;
      await scanFileImage(file);
      e.target.value = '';
    }

    async function scanFileImage(file) {
      const alertBox = document.getElementById('scanResultAlert');
      if (alertBox) {
        alertBox.style.display = 'flex';
        alertBox.style.background = '#eff6ff';
        alertBox.style.color = '#1e40af';
        alertBox.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Reading QR code from image...';
      }

      // Method 1: Html5Qrcode scanFile via dedicated hidden container
      if (typeof Html5Qrcode !== 'undefined') {
        try {
          const tempScanner = new Html5Qrcode("html5qrReaderFile");
          const decoded = await tempScanner.scanFile(file, false);
          try { tempScanner.clear(); } catch(e) {}
          if (decoded) {
            handleDecodedCode(decoded);
            return;
          }
        } catch (err) {
          console.warn("Html5Qrcode file scan attempt:", err);
        }
      }

      // Method 2: ZXing decodeFromImageUrl
      if (window.ZXing) {
        try {
          const reader = new ZXing.BrowserQRCodeReader();
          const objUrl = URL.createObjectURL(file);
          const result = await reader.decodeFromImageUrl(objUrl);
          URL.revokeObjectURL(objUrl);
          if (result) {
            handleDecodedCode(result.getText());
            return;
          }
        } catch (err) {
          console.warn("ZXing image decode attempt:", err);
        }
      }

      if (alertBox) {
        alertBox.style.display = 'flex';
        alertBox.style.background = '#fef2f2';
        alertBox.style.color = '#dc2626';
        alertBox.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Could not detect a valid QR code in this image. Please ensure the image is clear and well-lit.';
      }
    }

    function submitManualTerminalCode() {
      const input = document.getElementById('manualQrInput');
      const val = (input?.value || '').trim();
      if (!val) {
        alert('Please enter a student number or QR badge ID.');
        return;
      }
      processScanData(val);
      if (input) input.value = '';
    }

    // Drag and drop onto scanner viewport
    const vpBox = document.getElementById('scannerViewportBox');
    if (vpBox) {
      vpBox.addEventListener('dragover', (e) => { e.preventDefault(); vpBox.style.outline = '2px dashed #22c55e'; });
      vpBox.addEventListener('dragleave', () => { vpBox.style.outline = 'none'; });
      vpBox.addEventListener('drop', (e) => {
        e.preventDefault();
        vpBox.style.outline = 'none';
        if (e.dataTransfer.files?.[0]) {
          scanFileImage(e.dataTransfer.files[0]);
        }
      });
    }

    // Paste image from clipboard
    document.addEventListener('paste', (e) => {
      const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items || [];
      for (let item of items) {
        if (item.kind === 'file' && item.type.startsWith('image/')) {
          const file = item.getAsFile();
          if (file) scanFileImage(file);
          break;
        }
      }
    });

    function processScanData(qrData) {
      const eventId = parseInt(document.getElementById('scannerEventSelect')?.value || '0');
      const alertBox = document.getElementById('scanResultAlert');

      const isEventQr = /^BCP-EVENT(?:-LOG)?-\d+/i.test(qrData);

      if (!isEventQr && eventId <= 0) {
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

          // Instantly refresh the event's live stream from the database
          refreshSelectedEventStream(false);
        })
        .catch(() => {
          if (alertBox) {
            alertBox.style.background = '#fef2f2';
            alertBox.style.color = '#dc2626';
            alertBox.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Network error logging attendance.';
          }
        });
    }

    // Initialize event stream on page load if an event is selected
    document.addEventListener('DOMContentLoaded', () => {
      const sel = document.getElementById('scannerEventSelect');
      if (sel && sel.value) {
        handleEventSelectionChange();
      }
    });
  </script>
</body>

</html>
