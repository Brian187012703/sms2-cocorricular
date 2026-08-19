<?php
// ============================================================
//  REPORTS.PHP — Intelligent Reports Module
//  AI-Powered narrative reports for Advisers, SSC, and Admin
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$user_id      = (int)$_SESSION['user_id'];

// Students don't have access to reports
if ($sess_role === 'student') { header('Location: dashboard.php'); exit; }

// ── Aggregate Statistics for Dashboard Cards ────────────────
$stats = [];
$stats['total_events']      = (int)$conn->query("SELECT COUNT(*) AS c FROM events")->fetch_assoc()['c'];
$stats['approved_events']   = (int)$conn->query("SELECT COUNT(*) AS c FROM events WHERE status='Approved'")->fetch_assoc()['c'];
$stats['total_clubs']       = (int)$conn->query("SELECT COUNT(*) AS c FROM clubs WHERE status='Active'")->fetch_assoc()['c'];
$stats['total_members']     = (int)$conn->query("SELECT COUNT(*) AS c FROM club_memberships WHERE status='Active'")->fetch_assoc()['c'];
$stats['total_attendance']  = (int)$conn->query("SELECT COUNT(*) AS c FROM attendance_logs")->fetch_assoc()['c'];
$stats['total_budget_reqs'] = (int)$conn->query("SELECT COUNT(*) AS c FROM budget_requests")->fetch_assoc()['c'];
$stats['total_disbursed']   = $conn->query("SELECT COALESCE(SUM(amount),0) AS s FROM budget_requests WHERE status='Disbursed'")->fetch_assoc()['s'];
$stats['total_achievements']= (int)$conn->query("SELECT COUNT(*) AS c FROM achievements")->fetch_assoc()['c'];
$stats['total_registrations']= (int)$conn->query("SELECT COUNT(*) AS c FROM event_registrations")->fetch_assoc()['c'];
$stats['total_students']    = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role='student'")->fetch_assoc()['c'];
$stats['ai_interactions']   = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM ai_recommendation_logs");
if ($r) $stats['ai_interactions'] = (int)$r->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Intelligent Reports — BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
  /* ── Reports Page Styles ─────────────────────────────────── */
  .reports-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 40%, #7c3aed 100%);
    border-radius: 18px;
    padding: 32px 30px;
    margin-bottom: 28px;
    position: relative;
    overflow: hidden;
    color: #fff;
  }
  .reports-hero::after {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 200px; height: 200px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
    pointer-events: none;
  }
  .reports-hero h2 {
    margin: 0 0 6px; font-size: 1.35rem; font-weight: 800;
    display: flex; align-items: center; gap: 10px;
  }
  .reports-hero p { margin: 0; font-size: 0.82rem; color: rgba(255,255,255,0.7); max-width: 600px; }

  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 28px;
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
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
    border-color: #93c5fd;
  }
  .stat-card-mini .stat-icon {
    width: 36px; height: 36px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.9rem; margin-bottom: 10px;
  }
  .stat-card-mini .stat-value { font-size: 1.4rem; font-weight: 800; color: #0f172a; }
  .stat-card-mini .stat-label { font-size: 0.72rem; font-weight: 600; color: #64748b; margin-top: 2px; }

  /* Report Type Cards */
  .report-types { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 28px; }
  .report-type-card {
    background: #fff;
    border-radius: 16px;
    border: 1.5px solid #e2e8f0;
    padding: 24px;
    cursor: pointer;
    transition: all 0.25s ease;
    position: relative;
    overflow: hidden;
  }
  .report-type-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 32px rgba(0,0,0,0.1);
    border-color: #818cf8;
  }
  .report-type-card.active {
    border-color: #6d28d9;
    box-shadow: 0 0 0 3px rgba(109,40,217,0.15);
  }
  .report-type-card .rt-icon {
    width: 48px; height: 48px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; margin-bottom: 14px;
  }
  .report-type-card h4 { margin: 0 0 6px; font-size: 0.95rem; font-weight: 700; color: #0f172a; }
  .report-type-card p { margin: 0; font-size: 0.75rem; color: #64748b; line-height: 1.5; }

  /* Generate Button */
  .generate-report-bar {
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    padding: 18px 24px;
    background: #fff;
    border-radius: 14px;
    border: 1.5px solid #e2e8f0;
    margin-bottom: 24px;
    flex-wrap: wrap;
  }
  .generate-report-bar .selected-label {
    font-size: 0.88rem; font-weight: 700; color: #1e293b;
    display: flex; align-items: center; gap: 8px;
  }
  .btn-generate-ai {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 12px 28px;
    background: linear-gradient(135deg, #7c3aed, #6d28d9);
    color: #fff; border: none; border-radius: 12px;
    font-size: 0.88rem; font-weight: 700; cursor: pointer;
    box-shadow: 0 4px 16px rgba(109,40,217,0.3);
    transition: all 0.2s ease;
  }
  .btn-generate-ai:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(109,40,217,0.4); }
  .btn-generate-ai:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

  /* Report Output */
  .report-output {
    background: #fff;
    border-radius: 18px;
    border: 1.5px solid #e2e8f0;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    margin-bottom: 24px;
    display: none;
  }
  .report-output.visible { display: block; }
  .report-output-header {
    background: linear-gradient(135deg, #0f172a, #1e3a8a);
    padding: 22px 26px;
    color: #fff;
    display: flex; align-items: center; justify-content: space-between;
  }
  .report-output-header h3 { margin: 0; font-size: 1.05rem; font-weight: 800; display: flex; align-items: center; gap: 10px; }
  .report-output-header .badge-ai {
    padding: 4px 10px; border-radius: 20px; font-size: 0.65rem; font-weight: 700;
    background: rgba(139,92,246,0.3); border: 1px solid rgba(139,92,246,0.5); color: #c4b5fd;
  }
  .report-body { padding: 26px; }

  /* Report Sections */
  .report-section { margin-bottom: 24px; }
  .report-section-title {
    font-size: 0.82rem; font-weight: 700; color: #6d28d9;
    text-transform: uppercase; letter-spacing: 0.5px;
    margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #ede9fe;
    display: flex; align-items: center; gap: 8px;
  }
  .report-executive-summary {
    padding: 18px 20px;
    background: linear-gradient(135deg, #faf5ff, #ede9fe);
    border: 1px solid #ddd6fe;
    border-radius: 12px;
    font-size: 0.88rem;
    line-height: 1.7;
    color: #1e293b;
  }

  /* Finding Cards */
  .finding-cards { display: flex; flex-direction: column; gap: 10px; }
  .finding-card {
    display: flex; gap: 12px; padding: 14px;
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
    transition: all 0.2s ease;
  }
  .finding-card:hover { background: #eff6ff; border-color: #93c5fd; }
  .finding-icon {
    width: 38px; height: 38px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.85rem; flex-shrink: 0;
  }
  .finding-icon.high { background: #dcfce7; color: #16a34a; }
  .finding-icon.medium { background: #fef3c7; color: #d97706; }
  .finding-icon.low { background: #e0e7ff; color: #4f46e5; }
  .finding-text { font-size: 0.82rem; color: #334155; line-height: 1.5; }

  /* Trends */
  .trend-list { list-style: none; padding: 0; margin: 0; }
  .trend-list li {
    padding: 10px 14px; margin-bottom: 6px;
    background: #f0fdf4; border-left: 3px solid #16a34a;
    border-radius: 0 8px 8px 0;
    font-size: 0.82rem; color: #1e293b;
  }

  /* Recommendations Table */
  .rec-cards { display: flex; flex-direction: column; gap: 10px; }
  .rec-card {
    padding: 16px;
    background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px;
    transition: all 0.2s ease;
  }
  .rec-card:hover { border-color: #818cf8; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
  .rec-card-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 6px;
  }
  .rec-card-title { font-size: 0.85rem; font-weight: 700; color: #0f172a; }
  .rec-priority {
    padding: 2px 8px; border-radius: 4px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
  }
  .rec-priority.high { background: #fee2e2; color: #dc2626; }
  .rec-priority.medium { background: #fef3c7; color: #d97706; }
  .rec-priority.low { background: #dbeafe; color: #2563eb; }
  .rec-card-desc { font-size: 0.78rem; color: #475569; line-height: 1.5; }

  /* Risk Flags */
  .risk-cards { display: flex; flex-direction: column; gap: 10px; }
  .risk-card {
    padding: 14px 16px;
    border-radius: 10px; font-size: 0.82rem;
  }
  .risk-card.critical { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
  .risk-card.warning { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; }
  .risk-card.info { background: #eff6ff; border: 1px solid #93c5fd; color: #1e40af; }
  .risk-card strong { display: block; margin-bottom: 4px; }

  /* Health Score */
  .health-score-bar {
    display: flex; align-items: center; gap: 20px;
    padding: 20px 24px;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border-radius: 14px; border: 1px solid #e2e8f0;
    margin-top: 20px;
  }
  .health-circle {
    width: 72px; height: 72px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-direction: column; flex-shrink: 0;
    font-size: 1.4rem; font-weight: 900; color: #fff;
    box-shadow: 0 4px 14px rgba(0,0,0,0.15);
  }
  .health-circle.excellent { background: linear-gradient(135deg, #16a34a, #22c55e); }
  .health-circle.good { background: linear-gradient(135deg, #2563eb, #3b82f6); }
  .health-circle.fair { background: linear-gradient(135deg, #d97706, #f59e0b); }
  .health-circle.poor { background: linear-gradient(135deg, #dc2626, #ef4444); }
  .health-info h4 { margin: 0 0 4px; font-size: 1rem; font-weight: 700; color: #0f172a; }
  .health-info p { margin: 0; font-size: 0.8rem; color: #64748b; }

  /* Loading State */
  .report-loading {
    padding: 40px; text-align: center;
  }
  .report-loading .shimmer-block {
    height: 14px; border-radius: 8px; margin-bottom: 12px;
    background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
  }
  .report-loading .shimmer-block.w60 { width: 60%; }
  .report-loading .shimmer-block.w80 { width: 80%; }
  @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
  .report-thinking {
    margin-top: 16px; font-size: 0.85rem; font-weight: 600; color: #7c3aed;
    display: flex; align-items: center; justify-content: center; gap: 8px;
  }

  /* Print Optimized */
  .btn-print {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; background: #f1f5f9; color: #475569;
    border: 1px solid #cbd5e1; border-radius: 8px;
    font-size: 0.78rem; font-weight: 600; cursor: pointer; transition: all 0.15s ease;
  }
  .btn-print:hover { background: #e2e8f0; }
  @media print {
    .sidebar, .topbar, .page-title-bar, .reports-hero, .stats-grid,
    .report-types, .generate-report-bar, .btn-print { display: none !important; }
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
      <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code" type="button"><i class="fa-solid fa-qrcode"></i></button>
      <a href="account.php" class="avatar" title="Account"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-brain"></i> Intelligent Reports</h2>
    </div>

    <div class="content-body">

      <!-- Hero Banner -->
      <div class="reports-hero">
        <h2><i class="fa-solid fa-chart-line"></i> AI-Powered Intelligent Report Generator</h2>
        <p>Generate comprehensive, data-driven narrative reports with AI-powered insights, trend analysis, and actionable recommendations for campus co-curricular management.</p>
      </div>

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
          <div class="stat-value">₱<?= number_format($stats['total_disbursed']) ?></div>
          <div class="stat-label">Funds Disbursed</div>
        </div>
        <div class="stat-card-mini">
          <div class="stat-icon" style="background:#fce7f3; color:#db2777;"><i class="fa-solid fa-trophy"></i></div>
          <div class="stat-value"><?= $stats['total_achievements'] ?></div>
          <div class="stat-label">Achievements</div>
        </div>
        <div class="stat-card-mini">
          <div class="stat-icon" style="background:#e0f2fe; color:#0284c7;"><i class="fa-solid fa-robot"></i></div>
          <div class="stat-value"><?= $stats['ai_interactions'] ?></div>
          <div class="stat-label">AI Interactions</div>
        </div>
      </div>

      <!-- Report Type Selector -->
      <div class="report-types" id="reportTypeGrid">
        <div class="report-type-card" data-type="activity_events" onclick="selectReportType(this, 'activity_events')">
          <div class="rt-icon" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-calendar-check"></i></div>
          <h4>Activity & Events Report</h4>
          <p>Event participation trends, popular activities, registration rates, and approval pipeline efficiency.</p>
        </div>
        <div class="report-type-card" data-type="membership_engagement" onclick="selectReportType(this, 'membership_engagement')">
          <div class="rt-icon" style="background:#dcfce7; color:#16a34a;"><i class="fa-solid fa-users-gear"></i></div>
          <h4>Membership & Engagement</h4>
          <p>Club growth analytics, active vs inactive members, cross-organizational participation patterns.</p>
        </div>
        <div class="report-type-card" data-type="attendance_analytics" onclick="selectReportType(this, 'attendance_analytics')">
          <div class="rt-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-chart-bar"></i></div>
          <h4>Attendance Analytics</h4>
          <p>QR check-in patterns, absentee rates, peak activity times, and attendance compliance.</p>
        </div>
        <div class="report-type-card" data-type="budget_financial" onclick="selectReportType(this, 'budget_financial')">
          <div class="rt-icon" style="background:#ede9fe; color:#7c3aed;"><i class="fa-solid fa-coins"></i></div>
          <h4>Budget & Financial Summary</h4>
          <p>Disbursement trends, pending vs approved ratios, cost-per-event, and financial health.</p>
        </div>
        <div class="report-type-card" data-type="comprehensive" onclick="selectReportType(this, 'comprehensive')">
          <div class="rt-icon" style="background:linear-gradient(135deg, #fae8ff, #ede9fe); color:#7c3aed;"><i class="fa-solid fa-file-waveform"></i></div>
          <h4>Comprehensive Semester Report</h4>
          <p>Full organizational health assessment combining all data points into a holistic narrative report.</p>
        </div>
      </div>

      <!-- Generate Bar -->
      <div class="generate-report-bar" id="generateBar">
        <div class="selected-label" id="selectedLabel">
          <i class="fa-solid fa-circle-info" style="color:#94a3b8;"></i>
          <span style="color:#94a3b8;">Select a report type above to begin</span>
        </div>
        <button type="button" class="btn-generate-ai" id="generateBtn" disabled onclick="generateReport()">
          <i class="fa-solid fa-brain"></i>
          Generate AI Insights
        </button>
      </div>

      <!-- Report Output Panel -->
      <div class="report-output" id="reportOutput">
        <div class="report-output-header">
          <h3 id="reportOutputTitle"><i class="fa-solid fa-file-lines"></i> <span>Report</span></h3>
          <div style="display:flex; gap:8px; align-items:center;">
            <span class="badge-ai"><i class="fa-solid fa-robot"></i> Gemini AI</span>
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

const reportNames = {
  'activity_events': 'Activity & Events Report',
  'membership_engagement': 'Membership & Engagement Report',
  'attendance_analytics': 'Attendance Analytics Report',
  'budget_financial': 'Budget & Financial Summary',
  'comprehensive': 'Comprehensive Semester Report'
};

function selectReportType(el, type) {
  document.querySelectorAll('.report-type-card').forEach(c => c.classList.remove('active'));
  el.classList.add('active');
  selectedReportType = type;

  document.getElementById('selectedLabel').innerHTML = `
    <i class="fa-solid fa-file-lines" style="color:#6d28d9;"></i>
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
    <div class="report-loading">
      <div class="shimmer-block w80"></div>
      <div class="shimmer-block"></div>
      <div class="shimmer-block w60"></div>
      <div class="shimmer-block"></div>
      <div class="shimmer-block w80"></div>
      <div class="report-thinking"><i class="fa-solid fa-brain fa-beat-fade"></i> AI is analyzing system data and generating intelligent insights...</div>
    </div>`;

  output.scrollIntoView({ behavior: 'smooth', block: 'start' });

  try {
    const fd = new FormData();
    fd.append('action', 'generate_report');
    fd.append('report_type', selectedReportType);
    const res = await fetch('../shared/ai_actions.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (!data.success) {
      body.innerHTML = `<div style="padding:20px;color:#dc2626;font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> ${data.message}</div>`;
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-brain"></i> Retry';
      return;
    }

    const rpt = data.parsed;
    if (!rpt) {
      body.innerHTML = `<div style="padding:20px;color:#475569;font-size:0.88rem;white-space:pre-wrap;">${data.raw_text || 'AI returned an unstructured response.'}</div>`;
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> Regenerate';
      return;
    }

    renderReport(rpt);
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> Regenerate Report';

  } catch (err) {
    body.innerHTML = `<div style="padding:20px;color:#dc2626;font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> Network error. Please try again.</div>`;
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-brain"></i> Retry';
  }
}

function renderReport(rpt) {
  const body = document.getElementById('reportBody');
  let html = '';

  // Executive Summary
  if (rpt.executive_summary) {
    html += `
      <div class="report-section">
        <div class="report-section-title"><i class="fa-solid fa-scroll"></i> Executive Summary</div>
        <div class="report-executive-summary">${rpt.executive_summary}</div>
      </div>`;
  }

  // Key Findings
  if (rpt.key_findings && rpt.key_findings.length) {
    html += `<div class="report-section"><div class="report-section-title"><i class="fa-solid fa-magnifying-glass-chart"></i> Key Findings</div><div class="finding-cards">`;
    rpt.key_findings.forEach(f => {
      const icon = f.icon || 'circle-info';
      html += `
        <div class="finding-card">
          <div class="finding-icon ${f.impact}"><i class="fa-solid fa-${icon}"></i></div>
          <div class="finding-text">${f.finding}<br><span style="font-size:0.7rem;font-weight:600;color:#94a3b8;text-transform:uppercase;">Impact: ${f.impact}</span></div>
        </div>`;
    });
    html += '</div></div>';
  }

  // Trends
  if (rpt.trends && rpt.trends.length) {
    html += `<div class="report-section"><div class="report-section-title"><i class="fa-solid fa-arrow-trend-up"></i> Observed Trends</div><ul class="trend-list">`;
    rpt.trends.forEach(t => { html += `<li><i class="fa-solid fa-chart-line" style="color:#16a34a;margin-right:8px;"></i>${t}</li>`; });
    html += '</ul></div>';
  }

  // Recommendations
  if (rpt.recommendations && rpt.recommendations.length) {
    html += `<div class="report-section"><div class="report-section-title"><i class="fa-solid fa-lightbulb"></i> Recommendations</div><div class="rec-cards">`;
    rpt.recommendations.forEach(r => {
      html += `
        <div class="rec-card">
          <div class="rec-card-header">
            <div class="rec-card-title"><i class="fa-solid fa-check-circle" style="color:#6d28d9;margin-right:6px;"></i>${r.title}</div>
            <span class="rec-priority ${r.priority}">${r.priority}</span>
          </div>
          <div class="rec-card-desc">${r.description}</div>
        </div>`;
    });
    html += '</div></div>';
  }

  // Risk Flags
  if (rpt.risk_flags && rpt.risk_flags.length) {
    html += `<div class="report-section"><div class="report-section-title"><i class="fa-solid fa-triangle-exclamation"></i> Risk Flags</div><div class="risk-cards">`;
    rpt.risk_flags.forEach(r => {
      html += `
        <div class="risk-card ${r.severity}">
          <strong><i class="fa-solid fa-flag"></i> ${r.risk}</strong>
          <div style="font-size:0.78rem;margin-top:4px;"><strong>Mitigation:</strong> ${r.mitigation}</div>
        </div>`;
    });
    html += '</div></div>';
  }

  // Health Score
  if (rpt.overall_health_score) {
    const score = rpt.overall_health_score;
    const label = rpt.overall_health_label || 'N/A';
    const cls = score >= 80 ? 'excellent' : (score >= 60 ? 'good' : (score >= 40 ? 'fair' : 'poor'));
    html += `
      <div class="health-score-bar">
        <div class="health-circle ${cls}">${score}</div>
        <div class="health-info">
          <h4>Overall System Health: ${label}</h4>
          <p>Based on AI analysis of all co-curricular management data points including events, memberships, attendance, and budget metrics.</p>
        </div>
      </div>`;
  }

  // Timestamp
  html += `<div style="margin-top:20px;padding-top:14px;border-top:1px solid #e2e8f0;font-size:0.72rem;color:#94a3b8;text-align:right;">
    <i class="fa-solid fa-robot"></i> Generated by BCP Intelligent Reports Engine (Gemini AI) — ${new Date().toLocaleString('en-PH')}
  </div>`;

  body.innerHTML = html;
}

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = 'toast-notification ' + type;
  t.style.display = 'block'; setTimeout(() => t.style.display = 'none', 3500);
}
</script>
</body>
</html>
