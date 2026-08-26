<?php
// ============================================================
//  TRACKING_QR_GENERATOR.PHP  (dashboard/)
//  Co-Curricular System — Event QR Poster Generator
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

// Only Staff/Adviser/SSC/Admin can generate event posters
if ($sess_role === 'student') {
  header('Location: tracking_history.php');
  exit;
}

// Fetch active & upcoming events
$active_events = [];
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
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <style>
    .gen-container {
      display: grid;
      grid-template-columns: minmax(320px, 1fr) minmax(320px, 1.2fr);
      gap: 24px;
      align-items: start;
    }
    @media (max-width: 860px) {
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
    }
    .preview-qr-box img {
      width: 100%;
      height: 100%;
      object-fit: contain;
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
            <i class="fa-solid fa-print" style="color:#2563eb;"></i>
            Event QR Code Generator
          </h2>
          <p style="font-size:0.85rem; color:#64748b; margin:4px 0 0 0;">
            Generate, customize, and print high-resolution venue attendance posters for students to scan during check-in.
          </p>
        </div>
      </div>

      <div class="content-body">

        <div class="gen-container">

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

        </div><!-- end .gen-container -->

      </div><!-- end content-body -->
    </div><!-- end content -->

    <div class="footer">Co-Curricular Management System &copy; 2026</div>
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
        new QRCode(document.getElementById('qrcode'), {
          text: "${payload}",
          width: 240,
          height: 240
        });
      <\/script>
    </body>
    </html>
  `);
      qrWindow.document.close();
    }

    document.addEventListener('DOMContentLoaded', updatePosterPreview);
  </script>
</body>

</html>
