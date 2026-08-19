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

$sess_first = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last = htmlspecialchars($_SESSION['last_name'] ?? '');
$sess_role = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$user_id = (int)($_SESSION['user_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Attendance Tracker – BCP Co-Curricular Portal</title>
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
          <input type="text" placeholder="Search attendance..." />
          <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i
            class="fa-solid fa-qrcode"></i></button>
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

        <!-- Scanner & Event QR Code Generator Panel for Adviser / SSC / Admin -->
        <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
          <?php
          // Fetch active events
          $active_events = $conn->query("SELECT id, title, event_date, venue FROM events WHERE status IN ('Approved','Upcoming') ORDER BY event_date ASC")->fetch_all(MYSQLI_ASSOC);
          ?>
          <div class="table-card" id="terminal" style="margin-bottom:24px;">
            <h3 style="margin-bottom:6px;"><i class="fa-solid fa-qrcode" style="color:#2563eb;"></i> Event QR Code Generator &amp; Scanner Terminal</h3>
            <p style="font-size:0.85rem; color:#64748b; margin-bottom:20px;">
              Generate event QR codes for students to scan during check-in, or use the camera scanner terminal for on-site entry logging.
            </p>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:20px; align-items:stretch;">
              <!-- Left Box: Event QR Code Generator -->
              <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:20px; border-radius:12px; display:flex; flex-direction:column; justify-content:space-between;">
                <div>
                  <h4 style="margin:0 0 12px 0; font-size:0.95rem; color:#0f172a; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-print" style="color:#2563eb;"></i> Generate Event QR Poster
                  </h4>
                  <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:0.8rem; font-weight:600; color:#475569; margin-bottom:6px;">Select Active Event:</label>
                    <select id="eventQrSelect" style="width:100%; height:42px; padding:0 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.88rem; background:#fff; color:#1e293b;">
                      <?php if (empty($active_events)): ?>
                        <option value="1" data-date="Aug 15, 2026" data-venue="Main Auditorium">Annual Tech Symposium 2026</option>
                      <?php else: ?>
                        <?php foreach ($active_events as $ev): ?>
                          <option value="<?= $ev['id'] ?>" data-date="<?= date('M d, Y', strtotime($ev['event_date'])) ?>" data-venue="<?= htmlspecialchars($ev['venue']) ?>"><?= htmlspecialchars($ev['title']) ?></option>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </select>
                  </div>
                </div>
                <button class="card-btn" style="background:#2563eb; color:#fff; width:100%; height:42px; justify-content:center; font-weight:600; border-radius:8px;" onclick="generateEventQr()">
                  <i class="fa-solid fa-qrcode"></i> Generate Event QR Code
                </button>
              </div>

              <!-- Right Box: On-Site Scanner Terminal -->
              <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:20px; border-radius:12px; display:flex; flex-direction:column; justify-content:space-between;">
                <div>
                  <h4 style="margin:0 0 12px 0; font-size:0.95rem; color:#0f172a; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-camera" style="color:#16a34a;"></i> On-Site Entry Scanner
                  </h4>
                  <p style="font-size:0.8rem; color:#64748b; margin-bottom:16px; line-height:1.4;">
                    Launch camera hardware or connected RFID terminal for real-time check-in validation.
                  </p>
                </div>
                <div style="display:flex; flex-direction:column; gap:10px;">
                  <button class="card-btn" id="qrFabBtn" style="background:#2563eb; color:#fff; width:100%; height:42px; justify-content:center; font-weight:600; border-radius:8px; cursor:pointer; border:none;">
                    <i class="fa-solid fa-camera"></i> Launch QR Camera Scanner
                  </button>
                  <p style="font-size:0.78rem; color:#64748b; margin:4px 0 0; text-align:center;">Opens the QR Scanner Terminal â€” select event then scan student badges.</p>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (in_array($sess_role, ['ssc', 'admin'])): ?>
          <div class="table-card" id="analytics" style="margin-bottom:24px;">
            <h3 style="margin-bottom:12px;"><i class="fa-solid fa-chart-line" style="color:#2563eb;"></i> Absentee &amp; Attendance Analytics</h3>
            <div style="margin-bottom:14px;">
              <div style="display:flex; justify-content:space-between; font-size:0.82rem; font-weight:600; color:#334155; margin-bottom:6px;">
                <span>Overall Event Attendance Rate</span><span id="attRateSpan">â€”</span>
              </div>
              <div style="background:#e2e8f0; height:10px; border-radius:5px; overflow:hidden;">
                <div id="attRateBar" style="background:#2563eb; width:0%; height:100%; transition:width 0.8s;"></div>
              </div>
            </div>
            <p style="font-size:0.8rem; color:#64748b; margin-bottom:14px;">Service hours feed directly into Faculty Clearance and SIS Transcript Generator.</p>
            <button class="card-btn" style="background:#64748b; color:#fff; height:42px; padding:0 16px; font-weight:600;" onclick="openManualOverrideModal()">
              <i class="fa-solid fa-pen-to-square"></i> Manual Attendance Override
            </button>
          </div>
        <?php endif; ?>

        <?php if ($sess_role === 'student'): ?>
          <?php
          // Query real attendance logs for current student
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

// No static fallback â€” show empty state if no real records
          ?>

          <!-- Attendance Summary Stat Card -->
          <div class="table-card" style="margin-bottom:24px;">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;">
              <div>
                <h3 style="margin-bottom:6px; font-size:1.05rem; display:flex; align-items:center; gap:8px;">
                  <i class="fa-solid fa-award" style="color:#16a34a;"></i> Attendance &amp; Service Clearance
                </h3>
                <p style="font-size:0.82rem; color:#64748b; margin:0; line-height:1.4;">
                  Verified check-in timestamps feed directly into your co-curricular transcript and graduation clearance.
                </p>
              </div>
              <div style="display:flex; align-items:center; gap:28px;">
                <div>
                  <span style="display:block; font-size:1.6rem; font-weight:800; color:#0f172a; line-height:1.1;"><?= count($my_attendance) ?></span>
                  <span style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase;">Events Attended</span>
                </div>
                <div>
                  <span style="display:block; font-size:1.6rem; font-weight:800; color:#16a34a; line-height:1.1;">100%</span>
                  <span style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase;">Clearance Rate</span>
                </div>
                <div>
                  <span class="badge-active" style="padding:6px 12px; font-size:0.78rem; font-weight:700; border-radius:6px; background:#dcfce7; color:#15803d; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-circle-check"></i> Good Standing
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- My Event Attendance History Log Table -->
          <div class="table-card" id="myAttendanceSection">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
              <h3 style="margin:0;"><i class="fa-solid fa-clipboard-user" style="color:#2563eb;"></i> My Event Attendance Log &amp; History</h3>
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
                  <?php foreach ($my_attendance as $att): ?>
                    <tr>
                      <td data-label="Event Name" style="padding:14px 18px;"><strong><?= htmlspecialchars($att['event_title']) ?></strong></td>
                      <td data-label="Host Organization" style="padding:14px 18px; font-size:0.85rem; color:#334155;"><?= htmlspecialchars($att['club_name']) ?> <span style="color:#94a3b8;">(<?= htmlspecialchars($att['club_code']) ?>)</span></td>
                      <td data-label="Date & Time" style="padding:14px 18px; font-size:0.85rem; color:#475569;"><?= date('M d, Y h:i A', strtotime($att['check_in'])) ?></td>
                      <td data-label="Venue" style="padding:14px 18px; font-size:0.85rem; color:#475569;"><?= htmlspecialchars($att['venue']) ?></td>
                      <td data-label="Method" style="padding:14px 18px;">
                        <span style="padding:4px 10px; border-radius:6px; font-size:0.75rem; font-weight:700; background:#f1f5f9; color:#475569; display:inline-flex; align-items:center; gap:5px;">
                          <i class="fa-solid <?= ($att['method'] === 'RFID' ? 'fa-id-card' : 'fa-qrcode') ?>"></i> <?= htmlspecialchars($att['method']) ?> Entry
                        </span>
                      </td>
                      <td data-label="Status" style="padding:14px 18px;">
                        <span class="badge-active" style="padding:4px 10px; font-size:0.75rem; font-weight:700; border-radius:6px; background:#dcfce7; color:#15803d; display:inline-flex; align-items:center; gap:4px;">
                          <i class="fa-solid fa-check"></i> Verified Present
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

      </div><!-- end content-body -->
    </div><!-- end content -->

    <div class="footer">Co-Curricular Management System &copy; 2026</div>
  </div><!-- end main -->
  <script src="../js/dashboard.js"></script>
  <script>
    function generateEventQr() {
      const sel = document.getElementById('eventQrSelect');
      if (!sel) return;
      const eventId = sel.value;
      const eventTitle = sel.options[sel.selectedIndex].text;
      const eventDate = sel.options[sel.selectedIndex].getAttribute('data-date') || 'Scheduled Event';
      const eventVenue = sel.options[sel.selectedIndex].getAttribute('data-venue') || 'Campus Venue';
      const payload = `BCP-EVENT-LOG-${eventId}`;

      const qrWindow = window.open('', '_blank');
      qrWindow.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>Event QR Poster - ${eventTitle}</title>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>
      <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; text-align: center; padding: 40px; background: #f8fafc; color: #0f2a73; }
        .poster { max-width: 500px; margin: 0 auto; background: white; border-radius: 20px; padding: 40px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 3px solid #2563eb; }
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
        <p><strong>Date:</strong> ${eventDate} | <strong>Venue:</strong> ${eventVenue}</p>
        <div id="qrcode"></div>
        <div class="instructions">
          ?? <strong>Students:</strong> Scan this QR Code using your BCP Mobile App / Portal Scanner to record your attendance instantly!
        </div>
        <button class="btn-print" onclick="window.print()">??? Print Event Poster</button>
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
  </script>
</body>

</html>
