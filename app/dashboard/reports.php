<?php
// ============================================================
//  REPORTS.PHP — Organization Performance & Analytics Reports
//  Standard organizational analytics with CSV & Print Export
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_role(['club_adviser', 'ssc', 'admin'], 'dashboard.php');

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// ── Determine Adviser Club Scope ─────────────────────────────
$adviser_club_id = 0;
$adviser_club_name = 'All Organizations (Campus Wide)';
$adviser_club_code = 'BCP';

if ($sess_role === 'club_adviser') {
    $sess_user = $_SESSION['username'] ?? '';
    $cm = $conn->prepare("
        SELECT id, name, code FROM clubs
        WHERE (id IN (SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active')
           OR code=UPPER(SUBSTRING_INDEX(?, '.', 1)))
          AND status='Active' LIMIT 1
    ");
    $cm->bind_param('is', $user_id, $sess_user);
    $cm->execute();
    $cm->bind_result($cid, $cname, $ccode);
    if ($cm->fetch()) {
        $adviser_club_id = (int)$cid;
        $adviser_club_name = $cname;
        $adviser_club_code = $ccode;
    }
    $cm->close();
}

// ── Aggregate Statistics for Dashboard Cards ────────────────
$stats = [];
if ($adviser_club_id > 0) {
    $stats['total_events']      = (int)$conn->query("SELECT COUNT(*) AS c FROM events WHERE club_id = $adviser_club_id")->fetch_assoc()['c'];
    $stats['approved_events']   = (int)$conn->query("SELECT COUNT(*) AS c FROM events WHERE club_id = $adviser_club_id AND status IN ('Approved','Upcoming','Completed')")->fetch_assoc()['c'];
    $stats['total_members']     = (int)$conn->query("SELECT COUNT(*) AS c FROM club_memberships WHERE club_id = $adviser_club_id AND status='Active'")->fetch_assoc()['c'];
    $stats['total_attendance']  = (int)$conn->query("SELECT COUNT(*) AS c FROM attendance_logs al JOIN events e ON e.id = al.event_id WHERE e.club_id = $adviser_club_id")->fetch_assoc()['c'];
    $stats['total_disbursed']   = $conn->query("SELECT COALESCE(SUM(amount),0) AS s FROM budget_requests WHERE club_id = $adviser_club_id AND status='Disbursed'")->fetch_assoc()['s'];
    $stats['total_achievements']= (int)$conn->query("SELECT COUNT(*) AS c FROM achievements WHERE club_id = $adviser_club_id AND status='Verified'")->fetch_assoc()['c'];
    $stats['total_registrations']= (int)$conn->query("SELECT COUNT(*) AS c FROM event_registrations er JOIN events e ON e.id = er.event_id WHERE e.club_id = $adviser_club_id")->fetch_assoc()['c'];
} else {
    $stats['total_events']      = (int)$conn->query("SELECT COUNT(*) AS c FROM events")->fetch_assoc()['c'];
    $stats['approved_events']   = (int)$conn->query("SELECT COUNT(*) AS c FROM events WHERE status IN ('Approved','Upcoming','Completed')")->fetch_assoc()['c'];
    $stats['total_members']     = (int)$conn->query("SELECT COUNT(*) AS c FROM club_memberships WHERE status='Active'")->fetch_assoc()['c'];
    $stats['total_attendance']  = (int)$conn->query("SELECT COUNT(*) AS c FROM attendance_logs")->fetch_assoc()['c'];
    $stats['total_disbursed']   = $conn->query("SELECT COALESCE(SUM(amount),0) AS s FROM budget_requests WHERE status='Disbursed'")->fetch_assoc()['s'];
    $stats['total_achievements']= (int)$conn->query("SELECT COUNT(*) AS c FROM achievements WHERE status='Verified'")->fetch_assoc()['c'];
    $stats['total_registrations']= (int)$conn->query("SELECT COUNT(*) AS c FROM event_registrations")->fetch_assoc()['c'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $sess_role === 'club_adviser' ? 'Organization Reports' : 'Performance Reports & Analytics' ?> – BCP Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
  /* ── Clean Reports Page Styles ─────────────────────────── */
  .reports-hero {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 24px;
    color: #fff;
    box-shadow: 0 8px 24px rgba(37,99,235,0.18);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
  }
  .reports-hero h2 {
    margin: 0 0 6px; font-size: 1.3rem; font-weight: 800;
    display: flex; align-items: center; gap: 10px;
  }
  .reports-hero p { margin: 0; font-size: 0.84rem; color: rgba(255,255,255,0.85); max-width: 650px; line-height: 1.5; }
  .org-tag-hero {
    background: rgba(255,255,255,0.2);
    border: 1px solid rgba(255,255,255,0.35);
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 0.85rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }

  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
  }
  .stat-card-mini {
    background: #fff;
    border-radius: 14px;
    padding: 18px;
    border: 1.5px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    transition: all 0.2s ease;
  }
  .stat-card-mini:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.06);
    border-color: #93c5fd;
  }
  .stat-card-mini .stat-icon {
    width: 38px; height: 38px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.95rem; margin-bottom: 10px;
  }
  .stat-card-mini .stat-value { font-size: 1.35rem; font-weight: 800; color: #0f172a; }
  .stat-card-mini .stat-label { font-size: 0.72rem; font-weight: 700; color: #64748b; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.03em; }

  /* Report Type Cards */
  .report-types { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; margin-bottom: 24px; }
  .report-type-card {
    background: #fff;
    border-radius: 14px;
    border: 1.5px solid #e2e8f0;
    padding: 20px;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
  }
  .report-type-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.06);
    border-color: #2563eb;
  }
  .report-type-card.active {
    border-color: #2563eb;
    background: #f8faff;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
  }
  .report-type-card .rt-icon {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; margin-bottom: 12px;
  }
  .report-type-card h4 { margin: 0 0 5px; font-size: 0.95rem; font-weight: 700; color: #0f172a; }
  .report-type-card p { margin: 0; font-size: 0.76rem; color: #64748b; line-height: 1.45; }

  /* Generate Button Bar */
  .generate-report-bar {
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    padding: 16px 20px;
    background: #fff;
    border-radius: 14px;
    border: 1.5px solid #e2e8f0;
    margin-bottom: 24px;
    flex-wrap: wrap;
  }
  .generate-report-bar .selected-label {
    font-size: 0.9rem; font-weight: 700; color: #1e293b;
    display: flex; align-items: center; gap: 8px;
  }
  .btn-generate {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 11px 24px;
    background: #2563eb;
    color: #fff; border: none; border-radius: 10px;
    font-size: 0.88rem; font-weight: 700; cursor: pointer;
    box-shadow: 0 4px 12px rgba(37,99,235,0.25);
    transition: all 0.15s ease;
  }
  .btn-generate:hover { background: #1d4ed8; transform: translateY(-1px); }
  .btn-generate:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

  /* Report Output Container */
  .report-output {
    background: #fff;
    border-radius: 16px;
    border: 1.5px solid #e2e8f0;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    margin-bottom: 24px;
    display: none;
  }
  .report-output.visible { display: block; }
  .report-output-header {
    background: #0f172a;
    padding: 20px 24px;
    color: #fff;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
  }
  .report-output-header h3 { margin: 0; font-size: 1.05rem; font-weight: 800; display: flex; align-items: center; gap: 10px; }
  .report-body { padding: 24px; }

  /* Summary KPI Highlights in Report */
  .report-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
  }
  .report-summary-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .report-summary-box .box-icon {
    width: 36px; height: 36px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.9rem; flex-shrink: 0;
  }
  .report-summary-box .box-val { font-size: 1.25rem; font-weight: 800; color: #0f172a; line-height: 1.2; }
  .report-summary-box .box-lbl { font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; }

  /* Report Table Styles */
  .report-table-wrap {
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin-bottom: 20px;
  }
  .report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    text-align: left;
  }
  .report-table th {
    background: #f1f5f9;
    color: #334155;
    padding: 12px 16px;
    font-weight: 700;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 1.5px solid #cbd5e1;
    white-space: nowrap;
  }
  .report-table td {
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
  }
  .report-table tr:hover td {
    background: #f8fafc;
  }
  .report-table tr:last-child td {
    border-bottom: none;
  }

  /* Export Action Buttons */
  .btn-export-csv {
    background: #059669;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
  }
  .btn-export-csv:hover { background: #047857; }

  .btn-print {
    background: #334155;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
  }
  .btn-print:hover { background: #1e293b; }

  /* Official Footer Note */
  .report-official-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
    font-size: 0.76rem;
    color: #64748b;
    flex-wrap: wrap;
    gap: 8px;
  }

  @media print {
    .sidebar, .topbar, .page-title-bar, .reports-hero, .stats-grid,
    .report-types, .generate-report-bar, .btn-print, .btn-export-csv { display: none !important; }
    .report-output { display: block !important; box-shadow: none; border: none; }
    .report-output-header { background: #1e293b !important; -webkit-print-color-adjust: exact; }
    .main { margin: 0; padding: 0; }
  }

  @media (max-width: 640px) {
    .report-types { grid-template-columns: 1fr; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
  }
  </style>
</head>
<body>
<?php $APP_ROOT = '../'; $ACTIVE_NAV = 'reports'; require_once __DIR__ . '/../shared/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap">
        <input type="text" placeholder="Search pages, events..." autocomplete="off" />
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code" type="button"><i class="fa-solid fa-qrcode"></i></button>
      <a href="account.php" class="avatar" id="avatarBtn" title="Account Settings">
        <?php if (!empty($sess_pic) && file_exists(__DIR__ . '/../uploads/avatars/' . $sess_pic)): ?>
          <img src="../uploads/avatars/<?= htmlspecialchars($sess_pic) ?>" alt="Profile"/>
        <?php else: ?>
          <?= $sess_initial ?>
        <?php endif; ?>
      </a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-chart-column" style="color:#2563eb;"></i>
        <?= $sess_role === 'club_adviser' ? 'Organization Reports & Analytics' : 'Performance Reports & Analytics' ?>
      </h2>
    </div>

    <div class="content-body">

      <!-- Quick Stats Overview -->
      <div class="stats-grid">
        <div class="stat-card-mini">
          <div class="stat-icon" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-calendar-days"></i></div>
          <div class="stat-value"><?= $stats['total_events'] ?></div>
          <div class="stat-label">Total Events</div>
        </div>
        <div class="stat-card-mini">
          <div class="stat-icon" style="background:#dcfce7; color:#16a34a;"><i class="fa-solid fa-users"></i></div>
          <div class="stat-value"><?= $stats['total_members'] ?></div>
          <div class="stat-label">Active Members</div>
        </div>
        <div class="stat-card-mini">
          <div class="stat-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-clipboard-check"></i></div>
          <div class="stat-value"><?= $stats['total_attendance'] ?></div>
          <div class="stat-label">Attendance Logs</div>
        </div>
        <div class="stat-card-mini">
          <div class="stat-icon" style="background:#ede9fe; color:#7c3aed;"><i class="fa-solid fa-peso-sign"></i></div>
          <div class="stat-value">₱<?= number_format((float)$stats['total_disbursed']) ?></div>
          <div class="stat-label">Funds Disbursed</div>
        </div>
        <div class="stat-card-mini">
          <div class="stat-icon" style="background:#fce7f3; color:#db2777;"><i class="fa-solid fa-trophy"></i></div>
          <div class="stat-value"><?= $stats['total_achievements'] ?></div>
          <div class="stat-label">Verified Awards</div>
        </div>
        <div class="stat-card-mini">
          <div class="stat-icon" style="background:#e0f2fe; color:#0284c7;"><i class="fa-solid fa-user-plus"></i></div>
          <div class="stat-value"><?= $stats['total_registrations'] ?></div>
          <div class="stat-label">Registrations</div>
        </div>
      </div>

      <!-- Report Type Selector -->
      <div class="report-types" id="reportTypeGrid">
        <div class="report-type-card" data-type="activity_events" onclick="selectReportType(this, 'activity_events')">
          <div class="rt-icon" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-calendar-check"></i></div>
          <h4>Activity &amp; Events Report</h4>
          <p>Event participation, scheduled dates, approved venues, and event attendance turnout records.</p>
        </div>
        <div class="report-type-card" data-type="membership_engagement" onclick="selectReportType(this, 'membership_engagement')">
          <div class="rt-icon" style="background:#dcfce7; color:#16a34a;"><i class="fa-solid fa-users-gear"></i></div>
          <h4>Membership Roster &amp; Officers</h4>
          <p>Active club roster, elected student officers, academic programs, and membership registration dates.</p>
        </div>
        <div class="report-type-card" data-type="attendance_analytics" onclick="selectReportType(this, 'attendance_analytics')">
          <div class="rt-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-clipboard-user"></i></div>
          <h4>Attendance Logs &amp; Audit</h4>
          <p>QR code check-in timestamps, verification methods, attendee student numbers, and event participation.</p>
        </div>
        <div class="report-type-card" data-type="budget_financial" onclick="selectReportType(this, 'budget_financial')">
          <div class="rt-icon" style="background:#ede9fe; color:#7c3aed;"><i class="fa-solid fa-coins"></i></div>
          <h4>Budget &amp; Financial Ledger</h4>
          <p>Organization expense requisitions, approved disbursals, budget categories, and financial status.</p>
        </div>
        <div class="report-type-card" data-type="comprehensive" onclick="selectReportType(this, 'comprehensive')">
          <div class="rt-icon" style="background:#f1f5f9; color:#0f172a;"><i class="fa-solid fa-file-waveform"></i></div>
          <h4>Comprehensive Semester Summary</h4>
          <p>Consolidated executive operational summary combining activities, members, and financial records.</p>
        </div>
      </div>

      <!-- Generate Bar -->
      <div class="generate-report-bar" id="generateBar">
        <div class="selected-label" id="selectedLabel">
          <i class="fa-solid fa-circle-info" style="color:#94a3b8;"></i>
          <span style="color:#94a3b8;">Select a report module above to generate official documentation</span>
        </div>
        <button type="button" class="btn-generate" id="generateBtn" disabled onclick="generateReport()">
          <i class="fa-solid fa-table-list"></i>
          Generate Report
        </button>
      </div>

      <!-- Report Output Panel -->
      <div class="report-output" id="reportOutput">
        <div class="report-output-header">
          <div>
            <h3 id="reportOutputTitle"><i class="fa-solid fa-file-lines"></i> <span>Report</span></h3>
            <div style="font-size:0.75rem; color:#94a3b8; margin-top:3px;" id="reportOutputSubtitle">
              Organization: <?= htmlspecialchars($adviser_club_name) ?> (<?= htmlspecialchars($adviser_club_code) ?>)
            </div>
          </div>
          <div style="display:flex; gap:10px; align-items:center;">
            <button type="button" class="btn-export-csv" onclick="exportReportToCSV()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
            <button type="button" class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / PDF</button>
          </div>
        </div>
        <div class="report-body" id="reportBody">
          <!-- Loaded dynamically -->
        </div>
      </div>

    </div><!-- /content-body -->
  </div>
</div>

<?php require_once __DIR__ . '/../shared/qr_modal.php'; ?>

<div id="toast" class="toast-notification" style="display:none;"></div>
<script src="../js/dashboard.js"></script>
<script>
let selectedReportType = '';
let currentReportData = null;

const reportNames = {
  'activity_events': 'Activity & Events Performance Report',
  'membership_engagement': 'Membership Roster & Officers Report',
  'attendance_analytics': 'Attendance Logs & Audit Report',
  'budget_financial': 'Budget & Financial Ledger Report',
  'comprehensive': 'Comprehensive Semester Performance Report'
};

function selectReportType(el, type) {
  document.querySelectorAll('.report-type-card').forEach(c => c.classList.remove('active'));
  el.classList.add('active');
  selectedReportType = type;

  document.getElementById('selectedLabel').innerHTML = `
    <i class="fa-solid fa-file-lines" style="color:#2563eb;"></i>
    <span><strong>${reportNames[type]}</strong> selected</span>`;
  document.getElementById('generateBtn').disabled = false;
}

async function generateReport() {
  if (!selectedReportType) return;

  const btn = document.getElementById('generateBtn');
  const output = document.getElementById('reportOutput');
  const body = document.getElementById('reportBody');

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';

  output.classList.add('visible');
  document.getElementById('reportOutputTitle').querySelector('span').textContent = reportNames[selectedReportType];

  body.innerHTML = `
    <div style="text-align:center; padding:40px 20px; color:#64748b;">
      <i class="fa-solid fa-spinner fa-spin fa-2x" style="color:#2563eb; margin-bottom:12px;"></i>
      <div style="font-weight:600; font-size:0.95rem;">Compiling records and generating report...</div>
    </div>`;

  output.scrollIntoView({ behavior: 'smooth', block: 'start' });

  try {
    const fd = new FormData();
    fd.append('action', 'get_report');
    fd.append('report_type', selectedReportType);
    const res = await fetch('../shared/report_actions.php', { method: 'POST', body: fd });
    const data = await res.json();

    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> Regenerate Report';

    if (!data.success) {
      body.innerHTML = `<div style="padding:24px; color:#dc2626; font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> ${data.message}</div>`;
      return;
    }

    currentReportData = data;
    renderReport(data);

  } catch (err) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-table-list"></i> Generate Report';
    body.innerHTML = `<div style="padding:24px; color:#dc2626; font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> An error occurred while generating the report. Please try again.</div>`;
  }
}

function renderReport(rpt) {
  const body = document.getElementById('reportBody');
  let html = '';

  // 1. Summary Highlights Row
  if (rpt.summary_cards && rpt.summary_cards.length) {
    html += '<div class="report-summary-grid">';
    rpt.summary_cards.forEach(c => {
      html += `
        <div class="report-summary-box">
          <div class="box-icon" style="background:${c.color}15; color:${c.color};">
            <i class="fa-solid fa-${c.icon}"></i>
          </div>
          <div>
            <div class="box-val">${c.value}</div>
            <div class="box-lbl">${c.label}</div>
          </div>
        </div>`;
    });
    html += '</div>';
  }

  // 2. Data Table
  if (rpt.columns && rpt.columns.length) {
    html += '<div class="report-table-wrap"><table class="report-table" id="renderedReportTable"><thead><tr>';
    rpt.columns.forEach(col => {
      html += `<th>${col}</th>`;
    });
    html += '</tr></thead><tbody>';

    if (!rpt.data || !rpt.data.length) {
      html += `<tr><td colspan="${rpt.columns.length}" style="text-align:center; padding:24px; color:#94a3b8;">No records found for this reporting period.</td></tr>`;
    } else {
      rpt.data.forEach(row => {
        html += '<tr>';
        row.forEach(cell => {
          html += `<td>${cell !== null && cell !== undefined ? cell : ''}</td>`;
        });
        html += '</tr>';
      });
    }

    html += '</tbody></table></div>';
  }

  // 3. Official Signoff / Footer
  const clubName = rpt.club ? rpt.club.name : 'Bestlink College of the Philippines';
  html += `
    <div class="report-official-footer">
      <div>
        <strong>Official Institutional Report</strong> &bull; ${clubName}
      </div>
      <div>
        Generated on: <strong>${rpt.date_generated || new Date().toLocaleString()}</strong>
      </div>
    </div>`;

  body.innerHTML = html;
}

function exportReportToCSV() {
  if (!currentReportData || !currentReportData.columns || !currentReportData.data) {
    alert('Please generate a report first before exporting.');
    return;
  }

  const cols = currentReportData.columns;
  const rows = currentReportData.data;

  let csv = [];
  // Header row
  csv.push(cols.map(c => `"${c.replace(/"/g, '""')}"`).join(','));

  // Data rows
  rows.forEach(r => {
    csv.push(r.map(val => `"${String(val || '').replace(/"/g, '""')}"`).join(','));
  });

  const csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + encodeURIComponent(csv.join('\r\n'));
  const link = document.createElement('a');
  link.setAttribute('href', csvContent);
  const fname = (currentReportData.title || 'Report').replace(/[^a-zA-Z0-9]/g, '_') + '_' + Date.now() + '.csv';
  link.setAttribute('download', fname);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
</script>
</body>
</html>
