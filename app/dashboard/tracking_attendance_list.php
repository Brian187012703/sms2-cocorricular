<?php
// ============================================================
//  TRACKING_ATTENDANCE_LIST.PHP  (dashboard/)
//  Co-Curricular System — Event Attendance Rosters & Logs
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

// Fetch all events for event selector
$events = [];
$ev_res = $conn->query("
  SELECT e.id, e.title, e.event_date, e.venue, c.name as club_name, c.code as club_code
  FROM events e
  LEFT JOIN clubs c ON c.id = e.club_id
  ORDER BY e.event_date DESC
");
if ($ev_res) {
  $events = $ev_res->fetch_all(MYSQLI_ASSOC);
}

// Analytics summary
$total_events_count     = (int)($conn->query("SELECT COUNT(*) FROM events WHERE status IN ('Approved','Upcoming','Completed')")->fetch_row()[0] ?? 0);
$total_attendance_logs  = (int)($conn->query("SELECT COUNT(*) FROM attendance_logs")->fetch_row()[0] ?? 0);
$unique_attendees_count = (int)($conn->query("SELECT COUNT(DISTINCT user_id) FROM attendance_logs")->fetch_row()[0] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Attendance List per Event – Tracking Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <style>
    .filter-header-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 16px;
      margin-bottom: 20px;
      padding-bottom: 16px;
      border-bottom: 1px solid #f1f5f9;
    }
    .event-dropdown-select {
      height: 42px;
      padding: 0 14px;
      border-radius: 10px;
      border: 1px solid #cbd5e1;
      font-size: 0.9rem;
      font-weight: 600;
      background: #f8fafc;
      color: #1e293b;
      min-width: 280px;
      max-width: 420px;
      outline: none;
    }
    .event-dropdown-select:focus {
      border-color: #2563eb;
      background: #fff;
    }
    .stat-badge-pill {
      background: #f1f5f9;
      color: #334155;
      padding: 6px 14px;
      border-radius: 8px;
      font-size: 0.82rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
  </style>
</head>

<body>

  <?php
  $APP_ROOT = '../';
  $ACTIVE_NAV = 'attendance';
  $ACTIVE_SUB = 'attendance_list';
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
            <i class="fa-solid fa-clipboard-list" style="color:#2563eb;"></i>
            Attendance List per Event
          </h2>
          <p style="font-size:0.85rem; color:#64748b; margin:4px 0 0 0;">
            Review real-time verified attendee records, search student participants, and export official activity reports.
          </p>
        </div>
      </div>

      <div class="content-body">

        <!-- Attendee Table Card -->
        <div class="table-card" style="margin-bottom:24px;">
          <div class="filter-header-bar">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
              <label style="font-size:0.85rem; font-weight:700; color:#0f172a; white-space:nowrap;">
                <i class="fa-solid fa-calendar-check" style="color:#2563eb;"></i> Select Event:
              </label>
              <select id="eventFilterSelect" class="event-dropdown-select" onchange="loadEventAttendees(this.value)">
                <?php foreach ($events as $idx => $ev): ?>
                  <option value="<?= $ev['id'] ?>" <?= $idx === 0 ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ev['title']) ?> (<?= date('M d, Y', strtotime($ev['event_date'])) ?>)
                  </option>
                <?php endforeach; ?>
                <?php if (empty($events)): ?>
                  <option value="">No events recorded</option>
                <?php endif; ?>
              </select>
            </div>

            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
              <div style="position:relative;">
                <input type="text" id="attendeeSearchInput" placeholder="Filter attendee roster..." onkeyup="filterAttendeeTable()" style="height:40px; padding:0 12px 0 32px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.85rem;" />
                <i class="fa-solid fa-filter" style="position:absolute; left:10px; top:12px; color:#94a3b8; font-size:0.8rem;"></i>
              </div>
              <span class="stat-badge-pill" id="attendeeCountPill">
                <i class="fa-solid fa-check-double" style="color:#16a34a;"></i> Verified Present: <strong id="attendeeCountNum">0</strong>
              </span>
              <button class="card-btn" onclick="exportEventCsv()" style="height:40px; padding:0 14px; background:#f1f5f9; color:#334155; font-weight:700; border:1px solid #cbd5e1; border-radius:8px; display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                <i class="fa-solid fa-file-csv" style="color:#16a34a;"></i> Export CSV
              </button>
            </div>
          </div>

          <!-- Responsive Attendee Table -->
          <div class="resp-table-wrap">
            <table class="data-table resp-table" id="eventAttendeeTable">
              <thead>
                <tr>
                  <th style="width:50px;">#</th>
                  <th>Student Name</th>
                  <th>Email Address</th>
                  <th>Check-In Time</th>
                  <th>Entry Method</th>
                  <th>Verification Status</th>
                </tr>
              </thead>
              <tbody id="eventAttendeeTableBody">
                <tr>
                  <td colspan="6" style="text-align:center; padding:35px 15px; color:#94a3b8;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size:1.4rem; color:#2563eb; margin-bottom:8px; display:block;"></i>
                    Loading attendee logs for this event...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Section: Absentee Analytics & Manual Override (SSC / Admin) -->
        <?php if (in_array($sess_role, ['ssc', 'admin'])): ?>
          <div class="table-card" id="analytics" style="margin-bottom:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
              <h3 style="margin:0;"><i class="fa-solid fa-chart-line" style="color:#2563eb;"></i> Absentee &amp; Attendance Analytics</h3>
              <button class="card-btn" style="background:#2563eb; color:#fff; height:38px; padding:0 16px; font-weight:600; border-radius:8px;" onclick="openManualOverrideModal()">
                <i class="fa-solid fa-pen-to-square"></i> Manual Attendance Override
              </button>
            </div>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
              <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:16px; border-radius:10px;">
                <div style="font-size:0.75rem; color:#64748b; font-weight:700; text-transform:uppercase;">Total Active Events</div>
                <div style="font-size:1.5rem; font-weight:800; color:#0f172a; margin-top:4px;"><?= number_format($total_events_count) ?></div>
              </div>
              <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:16px; border-radius:10px;">
                <div style="font-size:0.75rem; color:#64748b; font-weight:700; text-transform:uppercase;">Total Recorded Check-Ins</div>
                <div style="font-size:1.5rem; font-weight:800; color:#16a34a; margin-top:4px;"><?= number_format($total_attendance_logs) ?></div>
              </div>
              <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:16px; border-radius:10px;">
                <div style="font-size:0.75rem; color:#64748b; font-weight:700; text-transform:uppercase;">Unique Student Attendees</div>
                <div style="font-size:1.5rem; font-weight:800; color:#2563eb; margin-top:4px;"><?= number_format($unique_attendees_count) ?></div>
              </div>
            </div>
          </div>
        <?php endif; ?>

      </div><!-- end content-body -->
    </div><!-- end content -->

    <div class="footer">Co-Curricular Management System &copy; 2026</div>
  </div><!-- end main -->

  <!-- Manual Override Modal (SSC / Admin) -->
  <div class="modal" id="manualOverrideModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:16px; width:90%; max-width:480px; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.2);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h3 style="margin:0; font-size:1.1rem; color:#0f172a;"><i class="fa-solid fa-pen-to-square" style="color:#2563eb;"></i> Manual Attendance Override</h3>
        <button onclick="closeModal('manualOverrideModal')" style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;">&times;</button>
      </div>
      <form id="manualOverrideForm" onsubmit="handleManualOverride(event)">
        <div style="margin-bottom:14px;">
          <label style="display:block; font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px;">Event *</label>
          <select name="event_id" required class="event-dropdown-select" style="width:100%; max-width:none;">
            <?php foreach ($events as $ev): ?>
              <option value="<?= $ev['id'] ?>"><?= htmlspecialchars($ev['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="margin-bottom:14px;">
          <label style="display:block; font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px;">Student ID or User ID *</label>
          <input type="number" name="user_id" required placeholder="Enter student user ID" class="form-control" style="width:100%; height:42px; padding:0 12px; border-radius:8px; border:1px solid #cbd5e1;" />
        </div>
        <div style="margin-bottom:20px;">
          <label style="display:block; font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px;">Check-In Timestamp</label>
          <input type="datetime-local" name="check_in" value="<?= date('Y-m-d\TH:i') ?>" class="form-control" style="width:100%; height:42px; padding:0 12px; border-radius:8px; border:1px solid #cbd5e1;" />
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" onclick="closeModal('manualOverrideModal')" class="card-btn" style="background:#f1f5f9; color:#475569; height:40px; padding:0 16px; font-weight:600; border-radius:8px;">Cancel</button>
          <button type="submit" class="card-btn" style="height:40px; padding:0 20px; background:#2563eb; color:#fff; font-weight:700; border-radius:8px;">Save Override</button>
        </div>
      </form>
    </div>
  </div>

  <script src="../js/dashboard.js"></script>
  <script>
    let currentEventAttendees = [];

    function loadEventAttendees(eventId) {
      if (!eventId) return;
      const tbody = document.getElementById('eventAttendeeTableBody');
      const countNum = document.getElementById('attendeeCountNum');
      if (tbody) {
        tbody.innerHTML = `
          <tr>
            <td colspan="6" style="text-align:center; padding:35px 15px; color:#94a3b8;">
              <i class="fa-solid fa-spinner fa-spin" style="font-size:1.4rem; color:#2563eb; margin-bottom:8px; display:block;"></i>
              Loading attendee logs...
            </td>
          </tr>
        `;
      }

      fetch(`../shared/attendance_actions.php?action=list_event&event_id=${eventId}`)
        .then(r => r.json())
        .then(data => {
          if (!data.success) {
            if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px; color:#ef4444;">${data.message || 'Error loading attendees.'}</td></tr>`;
            return;
          }

          currentEventAttendees = data.attendees || [];
          if (countNum) countNum.textContent = currentEventAttendees.length;

          if (!currentEventAttendees.length) {
            if (tbody) {
              tbody.innerHTML = `
                <tr>
                  <td colspan="6" style="text-align:center; padding:35px 20px; color:#94a3b8;">
                    <i class="fa-solid fa-user-slash" style="font-size:1.6rem; color:#cbd5e1; margin-bottom:8px; display:block;"></i>
                    No attendance records found for this event yet.
                  </td>
                </tr>
              `;
            }
          } else {
            let html = '';
            currentEventAttendees.forEach((att, idx) => {
              const timeStr = new Date(att.check_in.replace(' ', 'T')).toLocaleString('en-PH', { month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'numeric', hour12:true });
              html += `
                <tr class="attendee-row">
                  <td>${idx + 1}</td>
                  <td><strong>${att.first_name} ${att.last_name}</strong></td>
                  <td style="color:#64748b; font-size:0.85rem;">${att.email}</td>
                  <td style="font-size:0.85rem; color:#334155;">${timeStr}</td>
                  <td>
                    <span style="padding:4px 10px; border-radius:6px; font-size:0.75rem; font-weight:700; background:#f1f5f9; color:#475569; display:inline-flex; align-items:center; gap:5px;">
                      <i class="fa-solid ${att.method === 'RFID' ? 'fa-id-card' : (att.method === 'Manual' ? 'fa-pen-to-square' : 'fa-qrcode')}"></i> ${att.method}
                    </span>
                  </td>
                  <td>
                    <span class="badge-active" style="padding:4px 10px; font-size:0.75rem; font-weight:700; border-radius:6px; background:#dcfce7; color:#15803d; display:inline-flex; align-items:center; gap:4px;">
                      <i class="fa-solid fa-check"></i> Present
                    </span>
                  </td>
                </tr>
              `;
            });
            if (tbody) tbody.innerHTML = html;
          }

          const tbl = document.getElementById('eventAttendeeTable');
          if (tbl && window.initTablePagination) {
            if (tbl._paginator) tbl._paginator.refresh();
            else window.initTablePagination(tbl, { pageSize: 5 });
          }
        })
        .catch(() => {
          if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px; color:#ef4444;">Network error loading attendees.</td></tr>`;
        });
    }

    function filterAttendeeTable() {
      const q = (document.getElementById('attendeeSearchInput')?.value || '').toLowerCase().trim();
      document.querySelectorAll('#eventAttendeeTableBody tr.attendee-row').forEach(tr => {
        const match = !q || tr.textContent.toLowerCase().includes(q);
        tr.setAttribute('data-search-hidden', match ? 'false' : 'true');
      });
      const tbl = document.getElementById('eventAttendeeTable');
      if (tbl && tbl._paginator) {
        tbl._paginator.currentPage = 1;
        tbl._paginator.refresh();
      }
    }

    function exportEventCsv() {
      if (!currentEventAttendees.length) {
        alert('No attendees to export for this event.');
        return;
      }
      const sel = document.getElementById('eventFilterSelect');
      const eventTitle = sel ? sel.options[sel.selectedIndex].text.replace(/[^a-zA-Z0-9]/g, '_') : 'Event';
      const headers = ['#', 'First Name', 'Last Name', 'Email', 'Check-In Time', 'Method'];
      const csv = [headers.join(','), ...currentEventAttendees.map((a, idx) => [
        idx + 1,
        `"${(a.first_name || '').replace(/"/g, '""')}"`,
        `"${(a.last_name || '').replace(/"/g, '""')}"`,
        `"${(a.email || '').replace(/"/g, '""')}"`,
        `"${(a.check_in || '').replace(/"/g, '""')}"`,
        `"${(a.method || '').replace(/"/g, '""')}"`
      ].join(','))].join('\n');

      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a'); a.href = url;
      a.download = `Attendance_${eventTitle}_${new Date().toISOString().slice(0,10)}.csv`;
      a.click(); URL.revokeObjectURL(url);
    }

    function openManualOverrideModal() {
      const modal = document.getElementById('manualOverrideModal');
      if (modal) modal.style.display = 'flex';
    }
    function closeModal(id) {
      const modal = document.getElementById(id);
      if (modal) modal.style.display = 'none';
    }

    function handleManualOverride(e) {
      e.preventDefault();
      const fd = new FormData(e.target);
      fd.append('action', 'log_manual');
      fetch('../shared/attendance_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            alert('✓ ' + data.message);
            closeModal('manualOverrideModal');
            const eventId = document.getElementById('eventFilterSelect')?.value;
            if (eventId) loadEventAttendees(eventId);
          } else {
            alert('✗ ' + data.message);
          }
        })
        .catch(() => alert('Network error submitting override.'));
    }

    document.addEventListener('DOMContentLoaded', () => {
      const firstEvent = document.getElementById('eventFilterSelect')?.value;
      if (firstEvent) {
        loadEventAttendees(firstEvent);
      }
    });
  </script>
  <script src="../js/table-pagination.js"></script>
</body>

</html>
