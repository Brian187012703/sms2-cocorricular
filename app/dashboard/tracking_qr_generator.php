<?php
// ============================================================
//  TRACKING_QR_GENERATOR.PHP  (dashboard/)
//  Co-Curricular System — Event QR Poster Generator
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_role(['club_adviser', 'ssc', 'admin'], 'tracking_history.php');

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name'] ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)($_SESSION['user_id'] ?? 0);

// Fetch active & upcoming events
$active_events = [];
if ($sess_role === 'club_adviser') {
  $sess_user = $_SESSION['username'] ?? '';
  $ev_stmt = $conn->prepare("
    SELECT e.id, e.title, e.event_date, e.venue, e.status, c.name as club_name, c.code as club_code
    FROM events e
    LEFT JOIN clubs c ON c.id = e.club_id
    WHERE e.status IN ('Approved','Upcoming','Completed')
      AND (e.club_id IN (SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active')
           OR c.code = UPPER(SUBSTRING_INDEX(?, '.', 1)))
    ORDER BY e.event_date DESC
  ");
  $ev_stmt->bind_param('is', $user_id, $sess_user);
  $ev_stmt->execute();
  $active_events = $ev_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $ev_stmt->close();
} else {
  $ev_res = $conn->query("
    SELECT e.id, e.title, e.event_date, e.venue, e.status, c.name as club_name, c.code as club_code
    FROM events e
    LEFT JOIN clubs c ON c.id = e.club_id
    WHERE e.status IN ('Approved','Upcoming','Completed')
    ORDER BY e.event_date DESC
  ");
  if ($ev_res) {
    $active_events = $ev_res->fetch_all(MYSQLI_ASSOC);
  }
}

// Fetch enrolled students for Student Badge Generator (SSC & Admin only)
$all_students = [];
if ($sess_role !== 'club_adviser') {
  $st_res = $conn->query("
    SELECT u.id as user_id, u.username, u.first_name, u.last_name, u.email,
           s.student_number, s.course, s.year_level, s.section
    FROM users u
    LEFT JOIN students s ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
    WHERE u.role = 'student'
    ORDER BY s.student_number, u.last_name, u.first_name
  ");
  if ($st_res) {
    $all_students = $st_res->fetch_all(MYSQLI_ASSOC);
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Event QR Generator – Tracking Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <script src="../js/qrcode.min.js"></script>
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <style>
    .gen-container {
      display: grid;
      grid-template-columns: 1fr 1.2fr;
      gap: 24px;
      align-items: start;
    }
    @media (max-width: 992px) {
      .gen-container {
        grid-template-columns: 1fr;
      }
    }
    .preview-poster-card {
      background: #ffffff;
      border: 2px solid #2563eb;
      border-radius: 20px;
      padding: 36px 28px;
      text-align: center;
      box-shadow: 0 12px 30px rgba(37, 99, 235, 0.08);
      position: relative;
    }
    .preview-qr-box {
      width: 220px;
      height: 220px;
      margin: 20px auto;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 12px;
      position: relative;
    }
    .preview-qr-box canvas {
      display: none !important;
      visibility: hidden !important;
      position: absolute !important;
      pointer-events: none !important;
      width: 0 !important;
      height: 0 !important;
    }
    .preview-qr-box img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      display: block;
      margin: 0 auto;
    }
  </style>
</head>

<body>

  <?php
  $APP_ROOT = '../';
  $ACTIVE_NAV = 'attendance';
  $ACTIVE_SUB = 'generator';
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
            <i class="fa-solid fa-qrcode" style="color:#2563eb;"></i>
            <?= $sess_role === 'club_adviser' ? 'Event QR Poster Generator' : 'QR Code Generator &amp; ID Badge Center' ?>
          </h2>
          <p style="font-size:0.85rem; color:#64748b; margin:4px 0 0 0;">
            <?= $sess_role === 'club_adviser' ? 'Generate, customize, and print high-resolution official venue check-in posters for campus events.' : 'Generate, customize, and print high-resolution official QR badges for students and venue check-in posters for campus events.' ?>
          </p>
        </div>
      </div>

      <div class="content-body">

        <!-- Generator Mode Switcher Tabs (SSC/Admin only) -->
        <?php if ($sess_role !== 'club_adviser'): ?>
        <div style="display:flex; gap:10px; margin-bottom:24px; border-bottom:2px solid #e2e8f0; flex-wrap:wrap;">
          <button id="tabBtnEvents" type="button" onclick="switchGenTab('events')" style="padding:12px 20px; font-weight:700; font-size:0.92rem; border:none; background:none; cursor:pointer; border-bottom:3px solid #2563eb; color:#2563eb; display:inline-flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-calendar-check"></i> Event Attendance Posters
          </button>
          <button id="tabBtnStudents" type="button" onclick="switchGenTab('students')" style="padding:12px 20px; font-weight:700; font-size:0.92rem; border:none; background:none; cursor:pointer; border-bottom:3px solid transparent; color:#64748b; display:inline-flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-id-card"></i> Student QR ID Badges (All Programs)
          </button>
        </div>
        <?php endif; ?>

        <!-- 1. EVENT POSTER GENERATOR CONTAINER -->
        <div class="gen-container" id="containerEvents">

          <!-- Left Column: Controls & Configuration -->
          <div class="table-card">
            <h3 style="margin-bottom:6px;"><i class="fa-solid fa-sliders" style="color:#2563eb;"></i> Poster Configuration</h3>
            <p style="font-size:0.84rem; color:#64748b; margin-bottom:20px;">
              Select an approved activity to load its venue and schedule details into the official poster.
            </p>

            <div style="margin-bottom:18px;">
              <label style="display:block; font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px;">SELECT ACTIVE EVENT:</label>
              <select id="eventSelect" class="form-control" style="width:100%; height:44px; padding:0 12px; border-radius:10px; border:1px solid #cbd5e1; font-size:0.9rem; font-weight:600;" onchange="updatePosterPreview()">
                <?php if (empty($active_events)): ?>
                  <option value="1" data-title="Annual Tech Symposium 2026" data-date="Aug 15, 2026" data-venue="Main Campus Auditorium" data-org="Computer Studies Society">Annual Tech Symposium 2026</option>
                <?php else: ?>
                  <?php foreach ($active_events as $idx => $ev): ?>
                    <option value="<?= $ev['id'] ?>"
                            data-title="<?= htmlspecialchars($ev['title']) ?>"
                            data-date="<?= date('M d, Y', strtotime($ev['event_date'])) ?>"
                            data-venue="<?= htmlspecialchars($ev['venue'] ?? 'Main Campus Venue') ?>"
                            data-org="<?= htmlspecialchars($ev['club_name'] ?? 'College Event') ?> (<?= htmlspecialchars($ev['club_code'] ?? 'BCP') ?>)"
                            <?= $idx === 0 ? 'selected' : '' ?>>
                      <?= htmlspecialchars($ev['title']) ?> (<?= date('M d, Y', strtotime($ev['event_date'])) ?>)
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:20px;">
              <div style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:8px;">Poster Specifications</div>
              <div style="display:flex; justify-content:space-between; font-size:0.84rem; color:#334155; margin-bottom:6px;">
                <span>Format:</span> <strong>Standard Letter / A4 Poster</strong>
              </div>
              <div style="display:flex; justify-content:space-between; font-size:0.84rem; color:#334155; margin-bottom:6px;">
                <span>Payload Standard:</span> <code>BCP-EVENT-{id}</code>
              </div>
              <div style="display:flex; justify-content:space-between; font-size:0.84rem; color:#334155;">
                <span>Check-In Method:</span> <strong>Mobile Self Check-In</strong>
              </div>
            </div>

            <button class="card-btn" style="width:100%; height:46px; background:#2563eb; color:#fff; font-size:0.95rem; font-weight:700; border-radius:10px; justify-content:center; display:flex; align-items:center; gap:8px; cursor:pointer;" onclick="printEventPoster()">
              <i class="fa-solid fa-print"></i> Print Official Event Poster
            </button>
          </div>

          <!-- Right Column: Live Poster Preview -->
          <div>
            <div class="preview-poster-card">
              <div style="text-transform:uppercase; letter-spacing:1px; font-weight:800; font-size:0.75rem; color:#2563eb; margin-bottom:8px;">
                Bestlink College of the Philippines
              </div>
              <h3 id="prevTitle" style="font-size:1.4rem; color:#0f172a; margin:0 0 8px 0; line-height:1.3;">Loading Event Title...</h3>
              <p id="prevMeta" style="font-size:0.85rem; color:#64748b; margin:0 0 16px 0;">Date &amp; Venue</p>

              <div class="preview-qr-box" id="previewQrCanvas"></div>

              <div style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; border-radius:12px; padding:12px 16px; font-size:0.85rem; font-weight:600; line-height:1.4;">
                📢 <strong>Students:</strong> Point your camera or BCP Portal Scanner directly at this QR Code to log your attendance!
              </div>
            </div>
          </div>

        </div><!-- end #containerEvents -->


        <!-- 2. STUDENT BADGE GENERATOR CONTAINER (SSC & Admin only) -->
        <?php if ($sess_role !== 'club_adviser'): ?>
        <div class="gen-container" id="containerStudents" style="display:none;">

          <!-- Left Column: Controls & Configuration -->
          <div class="table-card">
            <h3 style="margin-bottom:6px;"><i class="fa-solid fa-id-badge" style="color:#059669;"></i> Student Badge Configuration</h3>
            <p style="font-size:0.84rem; color:#64748b; margin-bottom:20px;">
              Select any enrolled student from the official campus roster to generate their unique QR ID badge.
            </p>

            <div style="margin-bottom:18px;">
              <label style="display:block; font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px;">SELECT ENROLLED STUDENT:</label>
              <select id="studentSelect" class="form-control" style="width:100%; height:44px; padding:0 12px; border-radius:10px; border:1.5px solid #cbd5e1; font-size:0.9rem; font-weight:600;" onchange="updateStudentBadgePreview()">
                <?php foreach ($all_students as $idx => $st): ?>
                  <?php
                    $sNum = $st['student_number'] ?: ('ID#' . $st['user_id']);
                    $sName = $st['first_name'] . ' ' . $st['last_name'];
                    $sCourse = $st['course'] ?: 'Program';
                    $sYr = $st['year_level'] ?: '';
                    $sSec = $st['section'] ?: '';
                  ?>
                  <option value="<?= htmlspecialchars($st['user_id']) ?>"
                          data-num="<?= htmlspecialchars($st['student_number'] ?? '') ?>"
                          data-name="<?= htmlspecialchars($sName) ?>"
                          data-course="<?= htmlspecialchars($sCourse) ?>"
                          data-year="<?= htmlspecialchars($sYr) ?>"
                          data-sec="<?= htmlspecialchars($sSec) ?>"
                          <?= $idx === 0 ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sName) ?> — <?= htmlspecialchars($sNum) ?> (<?= htmlspecialchars($sCourse) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:16px; margin-bottom:20px;">
              <div style="font-size:0.75rem; font-weight:700; color:#166534; text-transform:uppercase; margin-bottom:8px;">Badge Specifications</div>
              <div style="display:flex; justify-content:space-between; font-size:0.84rem; color:#334155; margin-bottom:6px;">
                <span>Badge Format:</span> <strong>Official Student ID Card</strong>
              </div>
              <div style="display:flex; justify-content:space-between; font-size:0.84rem; color:#334155; margin-bottom:6px;">
                <span>Payload Standard:</span> <code id="badgePayloadPreviewCode">BCP-STUDENT-{student_no}</code>
              </div>
              <div style="display:flex; justify-content:space-between; font-size:0.84rem; color:#334155;">
                <span>Security Level:</span> <strong>High ECC Error Correction</strong>
              </div>
            </div>

            <div style="display:flex; gap:10px;">
              <button class="card-btn" style="flex:1; height:46px; background:#059669; color:#fff; font-size:0.92rem; font-weight:700; border-radius:10px; justify-content:center; display:flex; align-items:center; gap:8px; cursor:pointer;" onclick="printStudentBadgeFromGen()">
                <i class="fa-solid fa-print"></i> Print Official Badge
              </button>
              <button class="card-btn" style="height:46px; background:#2563eb; color:#fff; font-size:0.92rem; font-weight:700; padding:0 16px; border-radius:10px; display:flex; align-items:center; gap:6px; cursor:pointer;" onclick="downloadStudentQrFromGen()" title="Download PNG">
                <i class="fa-solid fa-download"></i> PNG
              </button>
            </div>
          </div>

          <!-- Right Column: Live Student Badge Preview -->
          <div>
            <div class="preview-poster-card" style="border-color:#059669; box-shadow:0 12px 30px rgba(5,150,105,0.08);">
              <div style="text-transform:uppercase; letter-spacing:1px; font-weight:800; font-size:0.75rem; color:#059669; margin-bottom:8px;">
                Bestlink College of the Philippines — Student ID Badge
              </div>
              <h3 id="prevStudentName" style="font-size:1.4rem; color:#0f172a; margin:0 0 6px 0; font-weight:800;">Juan Santos</h3>
              <div id="prevStudentNumPill" style="display:inline-block; font-size:0.85rem; font-weight:700; background:#dcfce7; color:#15803d; border:1px solid #86efac; border-radius:20px; padding:3px 12px; margin-bottom:6px;">
                Student ID: 2024-10001
              </div>
              <p id="prevStudentCourse" style="font-size:0.84rem; color:#64748b; margin:0 0 14px 0;">BSIT (IT-2A)</p>

              <div class="preview-qr-box" id="previewStudentQrCanvas"></div>

              <div style="background:#f8fafc; border:1px solid #e2e8f0; color:#334155; border-radius:12px; padding:10px 14px; font-size:0.82rem; font-weight:600; line-height:1.4;">
                <i class="fa-solid fa-qrcode" style="color:#059669; margin-right:4px;"></i> Unique Payload: <strong id="prevStudentPayloadStr">BCP-STUDENT-2024-10001</strong>
              </div>
            </div>
          </div>

        </div><!-- end #containerStudents -->
        <?php endif; ?>

      </div><!-- end content-body -->
    </div><!-- end content -->

    <div class="footer">eLearning Commons &copy; 2026</div>
  </div><!-- end main -->

  <script src="../js/dashboard.js"></script>
  <script>
    let qrcodeObj = null;

    function updatePosterPreview() {
      const sel = document.getElementById('eventSelect');
      if (!sel) return;
      const opt = sel.options[sel.selectedIndex];
      if (!opt) return;

      const eventId = opt.value;
      const title = opt.getAttribute('data-title') || opt.text;
      const dateStr = opt.getAttribute('data-date') || 'Scheduled Date';
      const venueStr = opt.getAttribute('data-venue') || 'Campus Venue';
      const orgStr = opt.getAttribute('data-org') || 'BCP Organization';

      document.getElementById('prevTitle').textContent = title;
      document.getElementById('prevMeta').innerHTML = `<strong>Host:</strong> ${orgStr} &bull; <strong>Date:</strong> ${dateStr} &bull; <strong>Venue:</strong> ${venueStr}`;

      const payload = `BCP-EVENT-${eventId}`;
      const box = document.getElementById('previewQrCanvas');
      box.innerHTML = '';
      qrcodeObj = new QRCode(box, {
        text: payload,
        width: 200,
        height: 200,
        colorDark: "#0f172a",
        colorLight: "#ffffff"
      });
      setTimeout(() => {
        box.querySelectorAll('canvas').forEach(c => c.remove());
        const imgs = box.querySelectorAll('img');
        for (let i = 1; i < imgs.length; i++) imgs[i].remove();
      }, 30);
    }

    function printEventPoster() {
      const sel = document.getElementById('eventSelect');
      if (!sel) return;
      const opt = sel.options[sel.selectedIndex];
      const eventId = opt.value;
      const eventTitle = opt.getAttribute('data-title') || opt.text;
      const eventDate = opt.getAttribute('data-date') || 'Scheduled Event';
      const eventVenue = opt.getAttribute('data-venue') || 'Campus Venue';
      const eventOrg = opt.getAttribute('data-org') || 'BCP Organization';
      const payload = `BCP-EVENT-${eventId}`;

      const qrWindow = window.open('', '_blank');
      qrWindow.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>Event QR Poster - ${eventTitle}</title>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>
      <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; text-align: center; padding: 40px; background: #f8fafc; color: #0f2a73; }
        .poster { max-width: 520px; margin: 0 auto; background: white; border-radius: 20px; padding: 40px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 3px solid #2563eb; }
        h1 { font-size: 1.8rem; margin: 0 0 10px 0; color: #0f2a73; }
        p { font-size: 1.05rem; color: #475569; margin: 6px 0; }
        #qrcode { display: flex; justify-content: center; margin: 30px 0; }
        .instructions { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 15px; border-radius: 12px; font-weight: 600; font-size: 0.95rem; }
        .btn-print { background: #2563eb; color: white; border: none; padding: 12px 28px; border-radius: 8px; font-weight: bold; font-size: 1rem; cursor: pointer; margin-top: 20px; }
        @media print { .btn-print { display: none; } }
      </style>
    </head>
    <body>
      <div class="poster">
        <div style="text-transform:uppercase; letter-spacing:1px; font-weight:800; font-size:0.85rem; color:#2563eb; margin-bottom:8px;">Bestlink College of the Philippines — Official Attendance Poster</div>
        <h1>${eventTitle}</h1>
        <p><strong>Host:</strong> ${eventOrg}</p>
        <p><strong>Date:</strong> ${eventDate} | <strong>Venue:</strong> ${eventVenue}</p>
        <div id="qrcode"></div>
        <div class="instructions">
          📢 <strong>Students:</strong> Scan this QR Code using your BCP Mobile App / Portal Scanner to record your attendance instantly!
        </div>
        <button class="btn-print" onclick="window.print()">🖨️ Print Event Poster</button>
      </div>
      <script>
        window.onload = function() {
          if (typeof QRCode !== 'undefined') {
            new QRCode(document.getElementById('qrcode'), {
              text: "${payload}",
              width: 240,
              height: 240
            });
          }
        };
      <\/script>
    </body>
    </html>
  `);
      qrWindow.document.close();
    }

    document.addEventListener('DOMContentLoaded', () => {
      updatePosterPreview();
    });

    let studentQrObj = null;

    function switchGenTab(tab) {
      const isEvents = tab === 'events';
      const btnEv = document.getElementById('tabBtnEvents');
      const btnSt = document.getElementById('tabBtnStudents');
      if (btnEv) {
        btnEv.style.borderBottomColor = isEvents ? '#2563eb' : 'transparent';
        btnEv.style.color = isEvents ? '#2563eb' : '#64748b';
      }
      if (btnSt) {
        btnSt.style.borderBottomColor = !isEvents ? '#059669' : 'transparent';
        btnSt.style.color = !isEvents ? '#059669' : '#64748b';
      }

      document.getElementById('containerEvents').style.display = isEvents ? 'grid' : 'none';
      document.getElementById('containerStudents').style.display = !isEvents ? 'grid' : 'none';

      if (!isEvents) {
        updateStudentBadgePreview();
      } else {
        updatePosterPreview();
      }
    }

    function updateStudentBadgePreview() {
      const sel = document.getElementById('studentSelect');
      if (!sel) return;
      const opt = sel.options[sel.selectedIndex];
      if (!opt) return;

      const sNum = opt.getAttribute('data-num') || '';
      const sName = opt.getAttribute('data-name') || 'Student';
      const sCourse = opt.getAttribute('data-course') || '';
      const sYr = opt.getAttribute('data-year') || '';
      const sSec = opt.getAttribute('data-sec') || '';
      const payload = sNum ? `BCP-STUDENT-${sNum}` : `BCP-STUDENT-${opt.value}`;

      document.getElementById('prevStudentName').textContent = sName;
      document.getElementById('prevStudentNumPill').textContent = sNum ? `Student ID: ${sNum}` : 'General Student';
      document.getElementById('prevStudentCourse').textContent = [sCourse, sYr, sSec ? `(${sSec})` : ''].filter(Boolean).join(' ');
      document.getElementById('prevStudentPayloadStr').textContent = payload;
      document.getElementById('badgePayloadPreviewCode').textContent = payload;

      const box = document.getElementById('previewStudentQrCanvas');
      if (!box) return;
      box.innerHTML = '';
      studentQrObj = new QRCode(box, {
        text: payload,
        width: 200,
        height: 200,
        colorDark: "#0f172a",
        colorLight: "#ffffff"
      });
      setTimeout(() => {
        box.querySelectorAll('canvas').forEach(c => c.remove());
        const imgs = box.querySelectorAll('img');
        for (let i = 1; i < imgs.length; i++) imgs[i].remove();
      }, 30);
    }

    function printStudentBadgeFromGen() {
      const sel = document.getElementById('studentSelect');
      if (!sel) return;
      const opt = sel.options[sel.selectedIndex];
      if (!opt) return;

      const sNum = opt.getAttribute('data-num') || '';
      const sName = opt.getAttribute('data-name') || 'Student';
      const sCourse = opt.getAttribute('data-course') || '';
      const sYr = opt.getAttribute('data-year') || '';
      const sSec = opt.getAttribute('data-sec') || '';
      const payload = sNum ? `BCP-STUDENT-${sNum}` : `BCP-STUDENT-${opt.value}`;
      const courseLine = [sCourse, sYr, sSec ? `(${sSec})` : ''].filter(Boolean).join(' ');

      const w = window.open('', '_blank');
      w.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
          <title>BCP Student Badge - ${sName}</title>
          <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>
          <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; text-align: center; padding: 40px; background: #f8fafc; color: #0f2a73; }
            .badge-card { max-width: 380px; margin: 0 auto; background: white; border-radius: 18px; padding: 32px 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 2.5px solid #059669; }
            .school-title { font-size: 0.82rem; font-weight: 800; text-transform: uppercase; color: #059669; letter-spacing: 0.5px; margin-bottom: 12px; }
            h2 { font-size: 1.4rem; margin: 0 0 4px 0; color: #0f2a73; font-weight: 800; }
            .student-num { font-size: 0.95rem; font-weight: 700; color: #059669; margin-bottom: 6px; }
            .course-info { font-size: 0.85rem; color: #475569; margin: 4px 0 16px 0; line-height: 1.4; }
            #qrcode { display: flex; justify-content: center; margin: 16px 0; }
            .payload-tag { font-family: monospace; font-size: 0.85rem; background: #f1f5f9; padding: 4px 10px; border-radius: 6px; color: #334155; font-weight: 700; display: inline-block; margin-top: 4px; }
            .instructions { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; padding: 10px; border-radius: 10px; font-weight: 600; font-size: 0.8rem; margin-top: 16px; }
            .btn-print { background: #059669; color: white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: bold; font-size: 0.95rem; cursor: pointer; margin-top: 18px; }
            @media print { .btn-print { display: none; } }
          </style>
        </head>
        <body>
          <div class="badge-card">
            <div class="school-title">Bestlink College of the Philippines</div>
            <h2>${sName}</h2>
            ${sNum ? `<div class="student-num">Student ID: ${sNum}</div>` : ''}
            <div class="course-info">${courseLine}</div>
            <div id="qrcode"></div>
            <div class="payload-tag">${payload}</div>
            <div class="instructions">Official Student Attendance &amp; Activity Badge</div>
            <button class="btn-print" onclick="window.print()">🖨️ Print Student Badge</button>
          </div>
          <script>
            window.onload = function() {
              if (typeof QRCode !== 'undefined') {
                new QRCode(document.getElementById('qrcode'), {
                  text: "${payload}",
                  width: 200,
                  height: 200,
                  colorDark: "#0f172a",
                  colorLight: "#ffffff"
                });
              }
            };
          <\/script>
        </body>
        </html>
      `);
      w.document.close();
    }

    function downloadStudentQrFromGen() {
      const box = document.getElementById('previewStudentQrCanvas');
      if (!box) return;
      const canvas = box.querySelector('canvas');
      const img = box.querySelector('img');
      let src = '';
      if (canvas && typeof canvas.toDataURL === 'function') {
        src = canvas.toDataURL('image/png');
      } else if (img && img.src) {
        src = img.src;
      }
      if (!src) return;
      const sel = document.getElementById('studentSelect');
      const opt = sel ? sel.options[sel.selectedIndex] : null;
      const sNum = opt ? (opt.getAttribute('data-num') || opt.value) : 'student';
      const sName = opt ? (opt.getAttribute('data-name') || 'Student').replace(/[^a-zA-Z0-9]/g, '_') : 'Student';
      const link = document.createElement('a');
      link.href = src;
      link.download = `${sName}_${sNum}_QR.png`;
      link.click();
    }
  </script>
</body>

</html>
