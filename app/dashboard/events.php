<?php
// ============================================================
//  EVENTS.PHP — Events & Activity Center
//  Full DB integration: real data, working modals, role-gated
//  Integrated with Philippine Holidays & Conflict Detection Engine
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/ph_holidays.php';
session_start();

if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;

// Load all Philippine Holidays for 2025, 2026, 2027
$ph_holidays_all = get_ph_holidays(2025) + get_ph_holidays(2026) + get_ph_holidays(2027);

// -- Fetch events from DB -------------------------------------
$events = $conn->query(
    "SELECT e.id, e.title, e.description, e.event_date, e.venue,
            e.status, e.rejection_note, e.created_by,
            c.name AS club_name, c.code AS club_code,
            u.first_name, u.last_name
     FROM events e
     JOIN clubs c ON c.id = e.club_id
     LEFT JOIN users u ON u.id = e.created_by
     ORDER BY e.event_date ASC"
)->fetch_all(MYSQLI_ASSOC);

$clubs = $conn->query("SELECT id, name, code FROM clubs WHERE status='Active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// For student role, filter out unposted / pending / rejected events
if ($sess_role === 'student') {
    $events = array_values(array_filter($events, function($ev) {
        return in_array($ev['status'], ['Approved', 'Upcoming', 'Completed']);
    }));
}

// -- Calculate event statistics --------------------------------
$total_approved = 0;
$total_pending  = 0;
$total_upcoming = 0;
$today_str      = date('Y-m-d');

foreach ($events as $ev) {
    if ($ev['status'] === 'Approved' || $ev['status'] === 'Completed') {
        $total_approved++;
    } elseif ($ev['status'] === 'Pending OSA' || $ev['status'] === 'Pending SSC') {
        $total_pending++;
    }
    if (substr($ev['event_date'], 0, 10) >= $today_str && $ev['status'] !== 'Rejected') {
        $total_upcoming++;
    }
}

// -- Fetch user's registered events ----------------------------
$user_id = (int)$_SESSION['user_id'];
$my_reg_ids = [];
$r_reg = $conn->query("SELECT event_id FROM event_registrations WHERE user_id = $user_id AND status = 'Registered'");
if ($r_reg) {
    while ($row = $r_reg->fetch_assoc()) {
        $my_reg_ids[] = (int)$row['event_id'];
    }
}

$status_badges = [
    'Approved'    => 'badge-active',
    'Completed'   => 'badge-info',
    'Upcoming'    => 'badge-info',
    'Pending OSA' => 'badge-warning',
    'Pending SSC' => 'badge-warning',
    'Rejected'    => 'badge-inactive',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Events &amp; Activity Center ï¿½ BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <!-- jsPDF & AutoTable for direct client-side PDF downloads -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
  <style>
  /* ── AI Event Planner & Schedule Conflict Analyzer ───────── */
  .ai-recommendations-section {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(37,99,235,0.08);
    border: 1.5px solid #dbeafe;
    overflow: hidden;
    margin-bottom: 24px;
    transition: all 0.3s ease;
  }
  .ai-recommendations-section:hover { box-shadow: 0 8px 30px rgba(37,99,235,0.12); }
  .ai-rec-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 50%, #2563eb 100%);
    padding: 20px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
  }
  .ai-rec-header-left { display: flex; align-items: center; gap: 14px; flex: 1; min-width: 0; }
  .ai-rec-icon-wrap {
    width: 44px; height: 44px;
    border-radius: 12px;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.25);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; color: #93c5fd;
    position: relative; flex-shrink: 0;
  }
  .ai-pulse-dot {
    position: absolute; top: -2px; right: -2px;
    width: 10px; height: 10px; border-radius: 50%;
    background: #22c55e;
    border: 2px solid #0f172a;
    animation: aiPulse 2s ease-in-out infinite;
  }
  @keyframes aiPulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.3); opacity: 0.7; }
  }
  .ai-rec-header h3 { margin: 0; font-size: 1rem; font-weight: 800; color: #fff; }
  .ai-rec-header p { margin: 3px 0 0; font-size: 0.73rem; color: rgba(255,255,255,0.75); line-height: 1.4; }
  .ai-rec-generate-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 22px;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff; border: none; border-radius: 10px;
    font-size: 0.82rem; font-weight: 700; cursor: pointer;
    box-shadow: 0 4px 14px rgba(217,119,6,0.35);
    transition: all 0.2s ease; flex-shrink: 0;
  }
  .ai-rec-generate-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(217,119,6,0.45); }
  .ai-rec-generate-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
  .ai-rec-body { padding: 20px 24px; }
  .ai-rec-loading { display: flex; flex-direction: column; gap: 12px; padding: 10px 0; }
  .ai-shimmer-bar {
    height: 16px; border-radius: 8px;
    background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
  }
  .ai-shimmer-bar.short { width: 60%; }
  @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
  .ai-thinking-text {
    text-align: center; font-size: 0.82rem; font-weight: 600;
    color: #1e3a8a; margin-top: 8px;
    display: flex; align-items: center; justify-content: center; gap: 8px;
  }
  /* AI Proposal Grid & Cards */
  .ai-plans-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; margin-top: 14px; }
  .ai-plan-card {
    background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 14px;
    padding: 18px; display: flex; flex-direction: column; justify-content: space-between;
    transition: all 0.25s ease; box-shadow: 0 2px 8px rgba(0,0,0,0.03);
  }
  .ai-plan-card:hover { border-color: #3b82f6; background: #ffffff; box-shadow: 0 8px 24px rgba(37,99,235,0.1); transform: translateY(-3px); }
  .ai-plan-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
  .ai-plan-title { font-size: 0.95rem; font-weight: 800; color: #0f172a; line-height: 1.35; }
  .ai-score-pill {
    padding: 3px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 800;
    background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; white-space: nowrap;
  }
  .ai-plan-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
  .ai-meta-tag {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px; border-radius: 6px; font-size: 0.72rem; font-weight: 600;
    background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;
  }
  .ai-plan-desc { font-size: 0.8rem; color: #475569; line-height: 1.5; margin-bottom: 14px; flex-grow: 1; }
  .ai-conflict-box {
    background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;
    padding: 10px 12px; font-size: 0.74rem; color: #166534; margin-bottom: 14px; line-height: 1.4;
  }
  .ai-apply-plan-btn {
    width: 100%; padding: 9px 16px; border-radius: 8px; font-size: 0.82rem; font-weight: 700;
    background: #16a34a; color: #fff; border: none; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    transition: all 0.2s;
  }
  .ai-apply-plan-btn:hover { background: #15803d; box-shadow: 0 4px 12px rgba(22,163,74,0.3); }
  .ai-error-msg {
    padding: 16px; background: #fef2f2; border: 1px solid #fca5a5;
    border-radius: 10px; color: #dc2626; font-size: 0.85rem; font-weight: 600;
    display: flex; align-items: center; gap: 8px;
  }
  @media (max-width: 640px) {
    .ai-rec-header { flex-direction: column; align-items: stretch; }
    .ai-rec-generate-btn { justify-content: center; }
  }

  /* ── Modal & Form Layout System ── */
  .modal-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    display: none; align-items: center; justify-content: center;
    z-index: 9999; padding: 20px;
  }
  .modal-overlay.active { display: flex !important; }
  .modal {
    background: #ffffff; border-radius: 16px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.25);
    width: 100%; max-width: 580px; overflow: hidden;
    animation: modalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  }
  @keyframes modalPop {
    0% { transform: scale(0.95); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
  }
  .modal-header {
    background: linear-gradient(135deg, #1e3a8a, #2563eb);
    padding: 16px 22px;
    display: flex; justify-content: space-between; align-items: center;
    color: #ffffff;
  }
  .modal-header h3 {
    margin: 0; font-size: 1.05rem; font-weight: 700; color: #ffffff;
    display: flex; align-items: center; gap: 8px;
  }
  .modal-close {
    background: none; border: none; font-size: 1.4rem; color: #ffffff;
    opacity: 0.85; cursor: pointer; line-height: 1; transition: opacity 0.15s;
  }
  .modal-close:hover { opacity: 1; }
  .modal-body {
    padding: 22px 24px;
    max-height: calc(85vh - 120px); overflow-y: auto;
  }
  .modal-actions {
    padding: 14px 24px; background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex; justify-content: flex-end; gap: 10px;
  }
  .form-group {
    margin-bottom: 16px; width: 100%;
  }
  .form-group label {
    display: block; font-size: 0.8rem; font-weight: 700;
    color: #334155; margin-bottom: 6px; text-transform: uppercase;
    letter-spacing: 0.4px;
  }
  .form-group input,
  .form-group textarea,
  .form-group select {
    width: 100%; box-sizing: border-box;
    padding: 10px 14px;
    border: 1.5px solid #cbd5e1; border-radius: 8px;
    font-size: 0.88rem; color: #0f172a; background: #ffffff;
    font-family: inherit; transition: all 0.2s ease;
  }
  .form-group input:focus,
  .form-group textarea:focus,
  .form-group select:focus {
    outline: none; border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
  }
  .form-group textarea {
    resize: vertical; min-height: 80px; line-height: 1.45;
  }

  /* -- Calendar Card -- */
  .calendar-section {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    border: 1px solid #e2e8f0;
    overflow: hidden;
    margin-bottom: 24px;
  }
  .calendar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 20px;
    background: #1a3a8c;
    flex-wrap: wrap;
    gap: 10px;
  }
  .calendar-header-left h3 {
    font-size: 0.98rem;
    font-weight: 800;
    color: #fff;
    margin: 0 0 1px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .calendar-header-left p {
    font-size: 0.75rem;
    color: rgba(255,255,255,0.75);
    margin: 0;
  }
  .calendar-nav {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .cal-nav-btn {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    font-size: 0.82rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
  }
  .cal-nav-btn:hover {
    background: rgba(255,255,255,0.3);
  }
  .cal-month-label {
    font-size: 0.92rem;
    font-weight: 700;
    color: #fff;
    min-width: 130px;
    text-align: center;
  }
  .calendar-body {
    padding: 12px 16px;
  }
  .calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 4px;
    width: 100%;
    box-sizing: border-box;
  }
  .cal-day-header {
    text-align: center;
    font-size: 0.68rem;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 4px 2px;
    min-width: 0;
    overflow: hidden;
  }
  .cal-day-cell {
    min-height: 70px;
    min-width: 0;
    max-width: 100%;
    width: 100%;
    box-sizing: border-box;
    overflow: hidden;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    padding: 4px 5px 5px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    transition: border-color 0.15s, box-shadow 0.15s;
    cursor: pointer;
  }
  .cal-day-cell:hover:not(.other-month) {
    border-color: #93c5fd;
    box-shadow: 0 2px 8px rgba(37,99,235,0.1);
    background: #fff;
  }
  .cal-day-cell.other-month {
    opacity: 0.3;
    background: #f1f5f9;
    cursor: default;
    border-color: #e8ecf0;
  }
  .cal-day-cell.today {
    background: #eff6ff;
    border-color: #2563eb;
    border-width: 2px;
  }
  .cal-date-num {
    font-size: 0.75rem;
    font-weight: 700;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    margin-bottom: 1px;
    min-width: 0;
  }
  .today-bubble {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #2563eb;
    color: #fff;
    font-size: 0.7rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-left: auto;
  }
  .cal-event-pill {
    font-size: 0.62rem;
    padding: 2.5px 5px;
    border-radius: 4px;
    color: #fff;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
    cursor: pointer;
    transition: transform 0.12s, opacity 0.12s;
    line-height: 1.35;
  }
  .cal-event-pill:hover {
    transform: scale(1.02);
    opacity: 0.92;
  }
  .cal-pill-approved  { background: #16a34a; }
  .cal-pill-upcoming  { background: #2563eb; }
  .cal-pill-pending   { background: #d97706; }
  .cal-pill-completed { background: #64748b; }

  /* 🇵🇭 Philippine Holiday & Conflict Pill Badges */
  .cal-holiday-pill {
    font-size: 0.60rem;
    padding: 2.5px 5px;
    border-radius: 4px;
    font-weight: 800;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
    cursor: pointer;
    transition: transform 0.12s, opacity 0.12s, box-shadow 0.12s;
    line-height: 1.3;
    display: flex;
    align-items: center;
    gap: 3.5px;
    margin-bottom: 2px;
    overflow: hidden;
  }
  .cal-holiday-pill span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
    flex: 1;
  }
  .cal-holiday-pill:hover {
    transform: scale(1.02);
    box-shadow: 0 2px 6px rgba(0,0,0,0.12);
  }
  .cal-holiday-regular {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
  }
  .cal-holiday-special {
    background: #ede9fe;
    color: #5b21b6;
    border: 1px solid #c4b5fd;
  }
  .cal-holiday-exam {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
  }
  .cal-holiday-pill i {
    font-size: 0.65rem;
    flex-shrink: 0;
  }

  /* Live Conflict Alert Banners in Proposal Modal */
  .conflict-box {
    border-radius: 10px;
    padding: 12px 14px;
    margin-top: 10px;
    font-size: 0.82rem;
    line-height: 1.45;
    transition: all 0.2s ease;
  }
  .conflict-box-danger {
    background: #fef2f2;
    border: 1.5px solid #fca5a5;
    color: #991b1b;
  }
  .conflict-box-warning {
    background: #fffbeb;
    border: 1.5px solid #fcd34d;
    color: #92400e;
  }
  .conflict-box-safe {
    background: #f0fdf4;
    border: 1.5px solid #86efac;
    color: #166534;
  }
  .conflict-box-title {
    font-weight: 800;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .calendar-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    padding: 8px 18px;
    border-top: 1px solid #f1f5f9;
    background: #f8fafc;
  }
  .legend-item {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.72rem;
    color: #64748b;
    font-weight: 600;
  }
  .legend-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }
  @keyframes calPulse {
    0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.7); }
    50% { transform: scale(1.04); box-shadow: 0 0 0 10px rgba(37, 99, 235, 0); }
    100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(37, 99, 235, 0); }
  }
  .cal-highlight-pulse {
    animation: calPulse 1s infinite !important;
    border: 2.5px solid #2563eb !important;
    background: #dbeafe !important;
    z-index: 10;
  }
  @media (max-width: 768px) {
    .calendar-grid { gap: 2px; }
    .cal-day-cell { min-height: 60px; padding: 4px; }
    .cal-day-header { font-size: 0.6rem; padding: 4px 2px; }
  }
  @media (max-width: 560px) {
    .calendar-body { padding: 12px; }
    .calendar-header { padding: 14px 16px; }
    .calendar-legend { padding: 10px 16px; gap: 10px; }
  }
  </style>
</head>
<body>
<?php $APP_ROOT = '../'; $ACTIVE_NAV = 'events'; require_once __DIR__ . '/../shared/sidebar.php'; ?>

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
      <h2 class="page-title"><i class="fa-solid fa-calendar-days"></i> Events &amp; Activity Center</h2>
    </div>

    <div class="content-body">

      <?php if ($sess_role !== 'student'): ?>
      <!-- Stats Row -->
      <div class="info-row">
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-calendar-check"></i> Approved Events</div>
          <div class="card-amount"><?= $total_approved ?></div>
          <div class="card-detail">SSC-cleared activities</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-clock"></i> Pending SSC</div>
          <div class="card-amount"><?= $total_pending ?></div>
          <div class="card-detail">Awaiting sign-off</div>
        </div>
      </div><!-- /info-row -->
      <?php endif; ?>

      <!-- ──────────────────────────────────────────────────────
           AI EVENT PLANNER & SCHEDULE CONFLICT OPTIMIZER (Adviser & SSC)
      ────────────────────────────────────────────────────── -->
      <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
      <div class="ai-recommendations-section" id="aiPlannerSection">
        <div class="ai-rec-header">
          <div class="ai-rec-header-left">
            <div class="ai-rec-icon-wrap">
              <i class="fa-solid fa-brain"></i>
              <span class="ai-pulse-dot"></span>
            </div>
            <div>
              <h3><i class="fa-solid fa-wand-magic-sparkles" style="color:#f59e0b; margin-right:6px;"></i> AI Event Planner &amp; Schedule Conflict Optimizer</h3>
              <p>Generative AI event proposal engine analyzing historical activity trends, future campus schedules, and 2026 Philippine holidays to produce conflict-free dates.</p>
            </div>
          </div>
        </div>

        <!-- AI Prompt Controls Row -->
        <div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:14px 24px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
          <?php if (in_array($sess_role, ['ssc', 'admin'])): ?>
          <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:0.72rem; font-weight:700; color:#64748b;"><i class="fa-solid fa-sitemap"></i> Organization</label>
            <select id="aiPlannerClubSelect" style="padding:8px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; background:#fff; font-weight:600; color:#1e293b; height:38px;" title="Select organization to plan events for">
              <?php foreach ($clubs as $cl): ?>
              <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name']) ?> (<?= htmlspecialchars($cl['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <div style="flex:1; min-width:260px; display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:0.72rem; font-weight:700; color:#64748b;"><i class="fa-solid fa-lightbulb"></i> Custom Event Theme / Focus (Optional)</label>
            <input type="text" id="aiPlannerThemeInput" placeholder="e.g. AI & Cybersecurity Bootcamp, Cultural Dance Festival, Leadership Forum, Outreach..." style="padding:8px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.84rem; color:#1e293b; background:#fff; width:100%; height:38px;" onkeydown="if(event.key==='Enter') generateAIEventPlans();"/>
          </div>

          <div style="display:flex; align-items:flex-end; height:100%; padding-top:18px;">
            <button type="button" class="ai-rec-generate-btn" id="aiPlanBtn" onclick="generateAIEventPlans()" style="height:38px; padding:0 20px;">
              <i class="fa-solid fa-wand-magic-sparkles"></i>
              <span>Generate AI Event Ideas &amp; Dates</span>
            </button>
          </div>
        </div>

        <!-- AI Result Container -->
        <div class="ai-rec-body" id="aiPlannerBody" style="display:none;">
          <!-- Loading Shimmer -->
          <div class="ai-rec-loading" id="aiPlannerLoading" style="display:none;">
            <div class="ai-shimmer-bar"></div>
            <div class="ai-shimmer-bar short"></div>
            <div class="ai-shimmer-bar"></div>
            <div class="ai-thinking-text"><i class="fa-solid fa-brain fa-beat-fade"></i> Connecting to Google Gemini AI &amp; analyzing campus calendar schedules...</div>
          </div>
          <!-- Results -->
          <div id="aiPlannerResults"></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ----------------------------------------------------------
           ACTIVE INTERACTIVE EVENT CALENDAR
      ---------------------------------------------------------- -->
      <div class="calendar-section">
        <!-- Header -->
        <div class="calendar-header">
          <div class="calendar-header-left">
            <h3><i class="fa-solid fa-calendar-days"></i> Active Campus Event Calendar</h3>
            <p>Click any highlighted date or event pill to view details &amp; register</p>
          </div>
          <div class="calendar-nav">
            <button class="cal-nav-btn" id="calPrevBtn" title="Previous month">
              <i class="fa-solid fa-chevron-left"></i>
            </button>
            <span class="cal-month-label" id="calMonthTitle">August 2026</span>
            <button class="cal-nav-btn" id="calNextBtn" title="Next month">
              <i class="fa-solid fa-chevron-right"></i>
            </button>
          </div>
        </div>

        <!-- Grid -->
        <div class="calendar-body">
          <div class="calendar-grid" id="calendarGrid">
            <!-- Rendered by JS -->
          </div>
        </div>

        <!-- Legend -->
        <div class="calendar-legend">
          <div class="legend-item"><span class="legend-dot" style="background:#dc2626;"></span> 🇵🇭 Regular Holiday (Non-Working)</div>
          <div class="legend-item"><span class="legend-dot" style="background:#7c3aed;"></span> 🇵🇭 Special Non-Working Day</div>
          <div class="legend-item"><span class="legend-dot" style="background:#d97706;"></span> 🎓 Academic Exam Blackout</div>
          <div class="legend-item"><span class="legend-dot" style="background:#16a34a;"></span> Approved Event</div>
          <div class="legend-item"><span class="legend-dot" style="background:#2563eb;"></span> Upcoming Event</div>
          <div class="legend-item"><span class="legend-dot" style="background:#d97706;"></span> Pending SSC Review</div>
          <div class="legend-item"><span class="legend-dot" style="background:#64748b;"></span> Completed Event</div>
          <div class="legend-item"><i class="fa-solid fa-circle-check" style="color:#16a34a; font-size:0.85rem;"></i> You are registered</div>
        </div>
      </div><!-- /calendar-section -->

      <!-- Events Table -->
      <div class="table-card" id="eventsListCard">
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
          <div>
            <?php if ($sess_role === 'student'): ?>
              <h3 style="margin:0; font-size:1.1rem; color:#1e293b;"><i class="fa-solid fa-list-ul" style="color:#2563eb;"></i> List of Events Posted</h3>
              <span style="font-size:0.78rem; color:#64748b;">Official campus events posted for your participation</span>
            <?php else: ?>
              <h3 style="margin:0; font-size:1.1rem; color:#1e293b;"><i class="fa-solid fa-list-check" style="color:#2563eb;"></i> Campus Event Calendar & Approval Pipeline</h3>
              <span style="font-size:0.78rem; color:#64748b;"><?= count($events) ?> total events</span>
            <?php endif; ?>
          </div>

          <!-- Month Filter & Locating Controls -->
          <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <label for="monthFilterSelect" style="font-size:0.8rem; font-weight:600; color:#475569;"><i class="fa-solid fa-filter"></i> Month:</label>
            <select id="monthFilterSelect" class="card-btn" style="background:#fff; color:#1e293b; border:1px solid #cbd5e1; padding:6px 12px; font-weight:600; font-size:0.8rem;" onchange="filterEventsBySelectedMonth(this.value)">
              <option value="ALL">All Months</option>
              <option value="2026-01">January 2026</option>
              <option value="2026-02">February 2026</option>
              <option value="2026-03">March 2026</option>
              <option value="2026-04">April 2026</option>
              <option value="2026-05">May 2026</option>
              <option value="2026-06">June 2026</option>
              <option value="2026-07">July 2026</option>
              <option value="2026-08">August 2026</option>
              <option value="2026-09">September 2026</option>
              <option value="2026-10">October 2026</option>
              <option value="2026-11">November 2026</option>
              <option value="2026-12">December 2026</option>
            </select>

            <button type="button" class="card-btn btn-sm" style="background:#2563eb; color:#fff;" onclick="syncTableWithActiveCalMonth()" title="Show events posted under active calendar month">
              <i class="fa-solid fa-calendar-day"></i> Sync Active Month
            </button>
            <button type="button" class="card-btn btn-sm" style="background:#64748b; color:#fff;" onclick="filterEventsBySelectedMonth('ALL')" title="Show all events">
              Show All
            </button>
            <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
              <button type="button" class="card-btn btn-sm" id="openCreateEvent" style="background:#16a34a; color:#fff; font-weight:700;" onclick="openModal('createEventModal')" title="Create event proposal to submit to SSC for review and approval">
                <i class="fa-solid fa-calendar-plus"></i> Create Event Proposal
              </button>
            <?php endif; ?>
          </div>
        </div>

        <?php if (empty($events)): ?>
          <div style="text-align:center; padding:40px; color:#64748b;">
            <i class="fa-solid fa-calendar-xmark" style="font-size:2.5rem; margin-bottom:12px; display:block;"></i>
            No events yet.
            <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
              <div style="margin-top:14px;">
                <button type="button" class="card-btn" onclick="openModal('createEventModal')" style="background:#16a34a; color:#fff; font-weight:700;">
                  <i class="fa-solid fa-calendar-plus"></i> Create First Event Proposal
                </button>
              </div>
            <?php endif; ?>
          </div>
        <?php else: ?>
        <table class="data-table" id="eventTable">
          <thead>
            <tr>
              <th>Event Title</th>
              <th>Month Posted</th>
              <th>Host Organization</th>
              <th>Date & Time</th>
              <th>Venue</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($events as $ev): ?>
            <?php 
              $ev_date_str  = date('Y-m-d', strtotime($ev['event_date']));
              $ev_month_str = date('Y-m', strtotime($ev['event_date']));
              $ev_month_lbl = date('F Y', strtotime($ev['event_date']));
            ?>
            <tr data-id="<?= $ev['id'] ?>" data-date="<?= $ev_date_str ?>" data-month="<?= $ev_month_str ?>">
              <td>
                <strong><?= htmlspecialchars($ev['title']) ?></strong>
                <?php if ($ev['rejection_note']): ?>
                  <div style="font-size:0.72rem; color:#ef4444; margin-top:2px;"><i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($ev['rejection_note']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge-info" style="font-size:0.75rem; font-weight:700; background:#e0f2fe; color:#0369a1; padding:3px 8px; border-radius:4px;">
                  <i class="fa-solid fa-calendar-week"></i> <?= $ev_month_lbl ?>
                </span>
              </td>
              <td><span class="club-badge"><?= htmlspecialchars($ev['club_code']) ?></span> <?= htmlspecialchars($ev['club_name']) ?></td>
              <td style="font-size:0.82rem;">
                <?= date('M d, Y', strtotime($ev['event_date'])) ?><br>
                <span style="color:#64748b;"><?= date('h:i A', strtotime($ev['event_date'])) ?></span>
              </td>
              <td style="font-size:0.82rem;"><?= htmlspecialchars($ev['venue']) ?></td>
              <td><span class="<?= $status_badges[$ev['status']] ?? 'badge-info' ?>"><?= htmlspecialchars($ev['status']) ?></span></td>
              <td>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                  <button class="card-btn btn-sm" style="background:#4f46e5; color:#fff;" onclick="locateOnCalendar('<?= $ev_date_str ?>', <?= $ev['id'] ?>)" title="Locate event on Calendar">
                    <i class="fa-solid fa-location-crosshairs"></i> Locate
                  </button>
                  <button class="card-btn btn-sm" onclick="viewEvent(<?= htmlspecialchars(json_encode($ev)) ?>)">
                    <i class="fa-solid fa-eye"></i> Details
                  </button>
                  <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
                    <button class="card-btn btn-sm" style="background:#2563eb; color:#fff;" onclick="viewRegistrations(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')">
                      <i class="fa-solid fa-users-rectangle"></i> Registrations
                    </button>
                  <?php endif; ?>
                  <?php if (in_array($sess_role, ['ssc','admin']) && in_array($ev['status'], ['Pending OSA','Pending SSC'])): ?>
                    <button class="card-btn btn-sm btn-success" onclick="approveEvent(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')">
                      <i class="fa-solid fa-check"></i> Approve
                    </button>
                    <button class="card-btn btn-sm btn-danger" onclick="rejectEvent(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')">
                      <i class="fa-solid fa-times"></i> Reject
                    </button>
                  <?php elseif ($sess_role === 'club_adviser' && in_array($ev['status'], ['Pending OSA','Pending SSC','Rejected'])): ?>
                    <button class="card-btn btn-sm" onclick="editEvent(<?= htmlspecialchars(json_encode($ev)) ?>)">
                      <i class="fa-solid fa-edit"></i> Edit
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

    </div>
  </div>
  <div class="footer">Co-Curricular Management System &copy; 2026</div>
</div>

<!-- ------ CREATE EVENT MODAL (Passed to SSC for Review & Approval) ------ -->
<?php if (in_array($sess_role, ['club_adviser','ssc','admin'])): ?>
<div class="modal-overlay" id="createEventModal">
  <div class="modal modal-lg" style="max-width:600px; padding:0; overflow:hidden; border-radius:16px;">
    <div class="modal-header" style="background: linear-gradient(135deg, #1e3a8a, #2563eb); color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:1.08rem; color:#ffffff; font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-calendar-plus" style="color:#f59e0b;"></i> Create Event Proposal <span style="font-size:0.75rem; font-weight:500; opacity:0.85;">(SSC Review)</span>
      </h3>
      <button class="modal-close" onclick="closeModal('createEventModal')" type="button" style="color:#ffffff; opacity:0.9; font-size:1.1rem; background:none; border:none; cursor:pointer;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="createEventForm">
      <div class="modal-body" style="padding:24px;">
        <div class="form-group">
          <label>Event Title <span style="color:#ef4444;">*</span></label>
          <input type="text" name="title" placeholder="e.g. Annual Hackathon & Innovation Summit 2026" required/>
        </div>
        <div class="form-group">
          <label>Description &amp; Objectives</label>
          <textarea name="description" rows="3" placeholder="Specify event details, objectives, and schedule..."></textarea>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
          <div class="form-group">
            <label>Event Date &amp; Time <span style="color:#ef4444;">*</span></label>
            <input type="datetime-local" name="event_date" id="createEventDateInput" required onchange="autoCheckModalDate()"/>
          </div>
          <div class="form-group">
            <label>Venue <span style="color:#ef4444;">*</span></label>
            <input type="text" name="venue" id="createEventVenueInput" placeholder="e.g. Main Auditorium" required onchange="autoCheckModalDate()"/>
          </div>
        </div>
        <div style="margin-bottom:16px;">
          <button type="button" class="card-btn btn-sm" id="btnAuditModalDate" style="background:#4338ca; color:#fff; font-weight:700; padding:7px 14px; border-radius:8px;" onclick="runAICheckDateConflict()">
            <i class="fa-solid fa-shield-halved"></i> AI Audit Date &amp; Check Conflicts
          </button>
          <div id="conflictAuditResult" style="display:none; margin-top:10px; font-size:0.82rem; border-radius:10px; padding:12px 16px; line-height:1.45; box-shadow:0 2px 6px rgba(0,0,0,0.03);"></div>
        </div>
        <?php if (in_array($sess_role, ['club_adviser','ssc','admin'])): ?>
        <div class="form-group">
          <label>Host Organization <span style="color:#ef4444;">*</span></label>
          <select name="club_id" id="createEventClubSelect">
            <?php foreach ($clubs as $cl): ?>
            <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name']) ?> (<?= htmlspecialchars($cl['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-actions" style="padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:600;" onclick="closeModal('createEventModal')">Cancel</button>
        <button type="submit" class="card-btn" id="createEventBtn" style="background:#16a34a; color:#fff; font-weight:700; padding:9px 18px;"><i class="fa-solid fa-paper-plane"></i> Submit to SSC for Approval</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ------ VIEW EVENT MODAL ------ -->
<div class="modal-overlay" id="viewEventModal">
  <div class="modal modal-lg" style="max-width:560px; padding:0; overflow:hidden; border-radius:16px;">
    <div class="modal-header" style="background: #1a3a8c; color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:1.1rem; color:#ffffff; font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-calendar-check" style="color:#ffffff;"></i> Event Details
      </h3>
      <button class="modal-close" onclick="closeModal('viewEventModal')" type="button" style="color:#ffffff; opacity:0.9; font-size:1.1rem; background:none; border:none; cursor:pointer;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" style="padding:24px;">
      <div id="viewEventBody" style="color:#334155;"></div>
    </div>
  </div>
</div>

<!-- ------ VIEW PHILIPPINE HOLIDAY MODAL ------ -->
<div class="modal-overlay" id="viewHolidayModal">
  <div class="modal modal-lg" style="max-width:520px; padding:0; overflow:hidden; border-radius:16px;">
    <div class="modal-header" id="holidayModalHeader" style="background:#1a3a8c; color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:1.1rem; color:#ffffff; font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-flag" style="color:#facc15;"></i> <span id="holidayModalTitle">Philippine Holiday Details</span>
      </h3>
      <button class="modal-close" onclick="closeModal('viewHolidayModal')" type="button" style="color:#ffffff; opacity:0.9; font-size:1.1rem; background:none; border:none; cursor:pointer;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" style="padding:24px;">
      <div id="holidayModalBody" style="color:#334155;"></div>
    </div>
    <div class="modal-actions" style="padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end;">
      <button type="button" class="card-btn" style="background:#1a3a8c; color:#fff; font-weight:600; padding:8px 18px; border-radius:8px;" onclick="closeModal('viewHolidayModal')">Understood</button>
    </div>
  </div>
</div>

<!-- ------ DAY SCHEDULE MODAL (Multiple events / holiday on date) ------ -->
<div class="modal-overlay" id="dayScheduleModal">
  <div class="modal modal-lg" style="max-width:580px; padding:0; overflow:hidden; border-radius:16px;">
    <div class="modal-header" style="background: #1a3a8c; color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:1.1rem; color:#ffffff; font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-calendar-day" style="color:#60a5fa;"></i> <span id="dayScheduleTitle">Events on this Date</span>
      </h3>
      <button class="modal-close" onclick="closeModal('dayScheduleModal')" type="button" style="color:#ffffff; opacity:0.9; font-size:1.1rem; background:none; border:none; cursor:pointer;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" style="padding:24px;" id="dayScheduleBody">
    </div>
  </div>
</div>

<!-- ------ EDIT EVENT MODAL ------ -->
<?php if (in_array($sess_role, ['club_adviser','ssc','admin'])): ?>
<div class="modal-overlay" id="editEventModal">
  <div class="modal modal-lg" style="max-width:600px; padding:0; overflow:hidden; border-radius:16px;">
    <div class="modal-header" style="background: #1a3a8c; color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:1.08rem; color:#ffffff; font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-pen-to-square" style="color:#f59e0b;"></i> Edit Event Proposal
      </h3>
      <button class="modal-close" onclick="closeModal('editEventModal')" type="button" style="color:#ffffff; opacity:0.9; font-size:1.1rem; background:none; border:none; cursor:pointer;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="editEventForm">
      <div class="modal-body" style="padding:24px;">
        <input type="hidden" name="id" id="editEventId"/>
        <div class="form-group">
          <label>Event Title <span style="color:#ef4444;">*</span></label>
          <input type="text" name="title" id="editEventTitle" required/>
        </div>
        <div class="form-group">
          <label>Description &amp; Objectives</label>
          <textarea name="description" id="editEventDesc" rows="3"></textarea>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
          <div class="form-group">
            <label>Event Date &amp; Time <span style="color:#ef4444;">*</span></label>
            <input type="datetime-local" name="event_date" id="editEventDate" required/>
          </div>
          <div class="form-group">
            <label>Venue <span style="color:#ef4444;">*</span></label>
            <input type="text" name="venue" id="editEventVenue" required/>
          </div>
        </div>
      </div>
      <div class="modal-actions" style="padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:600;" onclick="closeModal('editEventModal')">Cancel</button>
        <button type="submit" class="card-btn" style="background:#2563eb; color:#fff; font-weight:700; padding:9px 18px;"><i class="fa-solid fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ------ REJECT EVENT MODAL ------ -->
<?php if (in_array($sess_role, ['ssc','admin'])): ?>
<div class="modal-overlay" id="rejectEventModal">
  <div class="modal" style="max-width:480px; padding:0; overflow:hidden; border-radius:16px;">
    <div class="modal-header" style="background:#dc2626; color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:1.05rem; color:#ffffff; font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-circle-xmark"></i> Reject Event Proposal
      </h3>
      <button class="modal-close" onclick="closeModal('rejectEventModal')" type="button" style="color:#ffffff; opacity:0.9; font-size:1.1rem; background:none; border:none; cursor:pointer;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" style="padding:24px;">
      <p id="rejectEventDesc" style="color:#475569; margin-bottom:16px; font-size:0.88rem; line-height:1.45;"></p>
      <div class="form-group">
        <label>Reason for Rejection <span style="color:#ef4444;">*</span></label>
        <textarea id="rejectEventNote" rows="3" placeholder="Specify reasons for rejecting this proposal..."></textarea>
      </div>
    </div>
    <div class="modal-actions" style="padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px;">
      <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:600;" onclick="closeModal('rejectEventModal')">Cancel</button>
      <button type="button" class="card-btn btn-danger" id="confirmRejectEventBtn" style="padding:9px 18px; font-weight:700;"><i class="fa-solid fa-times"></i> Reject Event</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ------ EVENT REGISTRATIONS ROSTER MODAL ------ -->
<div class="modal-overlay" id="eventRegistrationsModal" style="display:none;">
  <div class="modal modal-lg" style="max-width:860px; padding:0; overflow:hidden; border-radius:16px;">
    <div class="modal-header" style="background:#1a3a8c; color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:1.05rem; color:#ffffff; font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-users-rectangle" style="color:#ffffff;"></i> <span id="regModalHeaderTitle">Event Registration Roster</span>
      </h3>
      <button class="modal-close" onclick="closeModal('eventRegistrationsModal')" type="button" style="color:#ffffff; opacity:0.9; font-size:1.1rem; background:none; border:none; cursor:pointer;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" style="padding:24px; max-height:72vh; overflow-y:auto;">
      <!-- Filter bar -->
      <div style="margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; gap:10px;">
        <input type="text" id="regSearchInput" placeholder="Filter attendees by name, email, ID, or course..." style="width:100%; max-width:380px; padding:8px 14px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.85rem;" oninput="filterRegistrationsList(this.value)" />
      </div>

      <!-- Registrations Data Table -->
      <div style="overflow-x:auto; border:1px solid #e2e8f0; border-radius:10px;">
        <table class="data-table" style="width:100%; font-size:0.85rem; border-collapse:collapse;" id="regTable">
          <thead>
            <tr style="background:#f8fafc; color:#334155; text-align:left;">
              <th style="padding:10px 12px; width:35px; text-align:center;">#</th>
              <th style="padding:10px 12px;">Student Name</th>
              <th style="padding:10px 12px;">Student ID</th>
              <th style="padding:10px 12px;">Course &amp; Year</th>
              <th style="padding:10px 12px;">Email &amp; Phone</th>
              <th style="padding:10px 12px; text-align:center;">Status</th>
            </tr>
          </thead>
          <tbody id="regTableBody">
            <!-- populated by JS -->
          </tbody>
        </table>
      </div>
    </div>
    <div class="modal-actions" style="padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
      <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:600;" onclick="closeModal('eventRegistrationsModal')">Close</button>
      
      <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
      <button type="button" class="card-btn" onclick="exportRegistrationsPDF()" style="background:#2563eb; color:#fff; font-weight:700; display:inline-flex; align-items:center; gap:6px; font-size:0.85rem; cursor:pointer;">
        <i class="fa-solid fa-file-pdf"></i> Export
      </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<div id="toast" class="toast-notification" style="display:none;"></div>
<script src="../js/dashboard.js"></script>
<script>
const ROLE = '<?= $sess_role ?>';
const ALL_EVENTS = <?= json_encode($events, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const MY_REG_IDS = <?= json_encode($my_reg_ids) ?>;
const PH_HOLIDAYS = <?= json_encode($ph_holidays_all, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

let currentDate = new Date();

function renderCalendar() {
  const year = currentDate.getFullYear();
  const month = currentDate.getMonth();

  const monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
  const titleEl = document.getElementById('calMonthTitle');
  if (titleEl) titleEl.textContent = `${monthNames[month]} ${year}`;

  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const daysInPrevMonth = new Date(year, month, 0).getDate();

  const grid = document.getElementById('calendarGrid');
  if (!grid) return;
  grid.innerHTML = '';

  // Day name headers
  ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(d => {
    const dh = document.createElement('div');
    dh.className = 'cal-day-header';
    dh.textContent = d;
    grid.appendChild(dh);
  });

  // Trailing days of previous month
  for (let i = firstDay - 1; i >= 0; i--) {
    const cell = document.createElement('div');
    cell.className = 'cal-day-cell other-month';
    cell.innerHTML = `<div class="cal-date-num">${daysInPrevMonth - i}</div>`;
    grid.appendChild(cell);
  }

  // Current month days
  const today = new Date();
  for (let d = 1; d <= daysInMonth; d++) {
    const isToday = (today.getFullYear() === year && today.getMonth() === month && today.getDate() === d);
    const cell = document.createElement('div');
    cell.className = `cal-day-cell${isToday ? ' today' : ''}`;

    const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    const dayEvents = ALL_EVENTS.filter(ev => ev.event_date.startsWith(dateStr));
    const hol = PH_HOLIDAYS[dateStr] || null;
    cell.setAttribute('data-full-date', dateStr);

    // Set interactive hover title
    if (dayEvents.length === 1) {
      cell.title = `Click to view event: ${dayEvents[0].title}`;
    } else if (dayEvents.length > 1) {
      cell.title = `Click to view ${dayEvents.length} events scheduled on this date`;
    } else if (hol) {
      cell.title = `Click to view: ${hol.name}`;
    } else {
      cell.title = `Date: ${dateStr}`;
    }

    // The whole date box is the button to view the event/details
    cell.addEventListener('click', () => {
      if (dayEvents.length === 1) {
        viewEvent(dayEvents[0]);
      } else if (dayEvents.length > 1) {
        viewDayEventsModal(dateStr, dayEvents, hol);
      } else if (hol) {
        viewHolidayModal(hol);
      } else {
        filterEventsBySelectedMonth(`${year}-${String(month+1).padStart(2,'0')}`);
        const tr = document.querySelector(`#eventTable tbody tr[data-date="${dateStr}"]`);
        if (tr) {
          tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
          tr.style.background = '#eff6ff';
          setTimeout(() => tr.style.background = '', 2000);
        }
      }
    });

    // Date number with bubble highlight for today
    const dateNumEl = document.createElement('div');
    dateNumEl.className = 'cal-date-num';
    if (isToday) {
      dateNumEl.innerHTML = `<span class="today-bubble">${d}</span>`;
    } else {
      dateNumEl.textContent = d;
    }
    cell.appendChild(dateNumEl);

    // 🇵🇭 Render Philippine Holiday / Special Non-Working Day Pill
    if (hol) {
      const hPill = document.createElement('div');
      let hClass = 'cal-holiday-regular';
      let icon = '<i class="fa-solid fa-flag"></i>';
      if (hol.type === 'special_non_working') {
        hClass = 'cal-holiday-special';
        icon = '<i class="fa-solid fa-star"></i>';
      } else if (hol.type === 'exam_blackout') {
        hClass = 'cal-holiday-exam';
        icon = '<i class="fa-solid fa-graduation-cap"></i>';
      }
      hPill.className = `cal-holiday-pill ${hClass}`;
      hPill.title = `${hol.name} (${hol.category}): ${hol.description}`;
      hPill.innerHTML = `${icon} <span>${hol.name}</span>`;
      hPill.addEventListener('click', (e) => {
        e.stopPropagation();
        viewHolidayModal(hol);
      });
      cell.appendChild(hPill);
    }

    // Event pills
    dayEvents.forEach(ev => {
      let pillClass = 'cal-pill-approved';
      if (ev.status === 'Pending OSA' || ev.status === 'Pending SSC')  pillClass = 'cal-pill-pending';
      else if (ev.status === 'Completed') pillClass = 'cal-pill-completed';
      else if (ev.status === 'Upcoming')  pillClass = 'cal-pill-upcoming';

      const isReg = MY_REG_IDS.includes(parseInt(ev.id));
      const pill = document.createElement('div');
      pill.className = `cal-event-pill ${pillClass}`;
      pill.title = `${ev.title} — ${ev.club_code}`;
      pill.innerHTML = `${ev.club_code}: ${ev.title}${isReg ? ' <i class="fa-solid fa-circle-check"></i>' : ''}`;
      pill.addEventListener('click', e => {
        e.stopPropagation();
        viewEvent(ev);
      });
      cell.appendChild(pill);
    });

    grid.appendChild(cell);
  }

  // Leading days of next month
  const totalCells = firstDay + daysInMonth;
  const nextPad = (7 - (totalCells % 7)) % 7;
  for (let i = 1; i <= nextPad; i++) {
    const cell = document.createElement('div');
    cell.className = 'cal-day-cell other-month';
    cell.innerHTML = `<div class="cal-date-num">${i}</div>`;
    grid.appendChild(cell);
  }
}

function viewEventById(id) {
  const ev = ALL_EVENTS.find(e => parseInt(e.id) === parseInt(id));
  if (ev) viewEvent(ev);
}

function viewDayEventsModal(dateStr, events, hol) {
  const formattedDate = new Date(dateStr + 'T00:00:00').toLocaleDateString('en-PH', { dateStyle: 'full' });
  const titleEl = document.getElementById('dayScheduleTitle');
  if (titleEl) titleEl.textContent = `Schedule for ${formattedDate}`;

  let html = `<div style="display:flex; flex-direction:column; gap:12px;">`;

  if (hol) {
    html += `
      <div style="background:#fef2f2; border:1.5px solid #fecaca; border-radius:12px; padding:12px 16px; display:flex; justify-content:space-between; align-items:center; cursor:pointer;" onclick="closeModal('dayScheduleModal'); viewHolidayModal(PH_HOLIDAYS['${dateStr}']);">
        <div>
          <span style="font-size:0.72rem; font-weight:800; text-transform:uppercase; color:#991b1b;"><i class="fa-solid fa-flag"></i> ${hol.category}</span>
          <div style="font-weight:700; color:#991b1b; font-size:0.95rem; margin-top:2px;">${hol.name}</div>
        </div>
        <span style="font-size:0.8rem; color:#dc2626; font-weight:700;">Details <i class="fa-solid fa-chevron-right"></i></span>
      </div>`;
  }

  events.forEach(ev => {
    const isReg = MY_REG_IDS.includes(parseInt(ev.id));
    const timeFormatted = new Date(ev.event_date.replace(' ', 'T')).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' });
    const statusBadgeClass = ev.status === 'Approved' ? 'badge-active' : (ev.status === 'Rejected' ? 'badge-inactive' : 'badge-warning');

    html += `
      <div style="background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:12px; padding:14px 16px; cursor:pointer; transition:all 0.15s ease; display:flex; justify-content:space-between; align-items:center; gap:12px;"
           onmouseover="this.style.borderColor='#2563eb'; this.style.background='#eff6ff';"
           onmouseout="this.style.borderColor='#e2e8f0'; this.style.background='#f8fafc';"
           onclick="closeModal('dayScheduleModal'); viewEventById(${ev.id});">
        <div style="min-width:0; flex:1;">
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
            <span class="club-badge" style="font-size:0.75rem; padding:2px 8px;">${ev.club_code}</span>
            <span class="${statusBadgeClass}" style="font-size:0.72rem;">${ev.status}</span>
            ${isReg ? '<span style="color:#16a34a; font-size:0.75rem; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Registered</span>' : ''}
          </div>
          <h4 style="margin:0 0 4px; color:#0f172a; font-size:0.95rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${ev.title}</h4>
          <div style="font-size:0.78rem; color:#64748b; display:flex; gap:12px; flex-wrap:wrap;">
            <span><i class="fa-regular fa-clock"></i> ${timeFormatted}</span>
            <span><i class="fa-solid fa-location-dot"></i> ${ev.venue}</span>
          </div>
        </div>
        <button type="button" class="card-btn btn-sm" style="background:#2563eb; color:#fff; font-weight:700; flex-shrink:0; pointer-events:none;">
          View <i class="fa-solid fa-chevron-right"></i>
        </button>
      </div>`;
  });

  html += `</div>`;
  const bodyEl = document.getElementById('dayScheduleBody');
  if (bodyEl) bodyEl.innerHTML = html;
  openModal('dayScheduleModal');
}

function viewHolidayModal(hol) {
  if (!hol) return;
  const titleEl = document.getElementById('holidayModalTitle');
  const bodyEl = document.getElementById('holidayModalBody');

  let badgeColor = hol.type === 'regular' ? '#dc2626' : (hol.type === 'special_non_working' ? '#7c3aed' : '#d97706');
  let badgeBg = hol.type === 'regular' ? '#fee2e2' : (hol.type === 'special_non_working' ? '#ede9fe' : '#fef3c7');

  if (titleEl) titleEl.textContent = hol.name;

  const formattedDate = new Date(hol.date + 'T00:00:00').toLocaleDateString('en-PH', { dateStyle: 'full' });

  let adviceHtml = '';
  if (hol.type === 'regular') {
    adviceHtml = `
      <div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; padding:12px 14px; color:#991b1b; font-size:0.82rem; line-height:1.45;">
        <strong><i class="fa-solid fa-triangle-exclamation"></i> Regular Holiday Scheduling Restriction:</strong>
        <p style="margin:4px 0 0;">Under Philippine labor and academic regulations, campus facilities and administrative offices are closed. Co-curricular events on regular holidays require special Vice President for Academic Affairs &amp; SSC clearance.</p>
      </div>`;
  } else if (hol.type === 'special_non_working') {
    adviceHtml = `
      <div style="background:#f5f3ff; border:1px solid #c4b5fd; border-radius:10px; padding:12px 14px; color:#5b21b6; font-size:0.82rem; line-height:1.45;">
        <strong><i class="fa-solid fa-circle-info"></i> Special Non-Working Day Advisory:</strong>
        <p style="margin:4px 0 0;">Classes are suspended. Holding student organization workshops, rehearsals, or competitions requires administrative entry approval and security gate clearance.</p>
      </div>`;
  } else {
    adviceHtml = `
      <div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:10px; padding:12px 14px; color:#92400e; font-size:0.82rem; line-height:1.45;">
        <strong><i class="fa-solid fa-graduation-cap"></i> Academic Blackout Window:</strong>
        <p style="margin:4px 0 0;">Campus-wide blackout for examination week. All student club activities are strictly paused to support student academic review.</p>
      </div>`;
  }

  bodyEl.innerHTML = `
    <div style="display:flex; flex-direction:column; gap:16px;">
      <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
        <span style="font-size:0.75rem; font-weight:800; text-transform:uppercase; color:${badgeColor}; background:${badgeBg}; padding:4px 10px; border-radius:20px; border:1px solid ${badgeColor}33;">
          ${hol.category}
        </span>
        <span style="font-size:0.82rem; color:#64748b; font-weight:600;"><i class="fa-regular fa-calendar"></i> ${formattedDate}</span>
      </div>
      <div>
        <h3 style="margin:0 0 4px; color:#0f172a; font-size:1.2rem; font-weight:800;">${hol.name}</h3>
        <p style="margin:0; font-size:0.88rem; color:#64748b; font-style:italic;">${hol.filipino_name || ''}</p>
      </div>
      <p style="margin:0; font-size:0.9rem; line-height:1.55; color:#334155;">${hol.description}</p>
      ${adviceHtml}
    </div>`;

  openModal('viewHolidayModal');
}

async function autoCheckModalDate() {
  const dtInput = document.getElementById('createEventDateInput');
  const venueInput = document.getElementById('createEventVenueInput');
  const clubSelect = document.getElementById('createEventClubSelect');
  const resBox = document.getElementById('conflictAuditResult');
  if (!dtInput || !dtInput.value || !resBox) return;

  const dateVal = dtInput.value.slice(0, 10);
  const venueVal = venueInput ? venueInput.value.trim() : '';
  const clubIdVal = clubSelect ? clubSelect.value : 0;

  resBox.style.display = 'block';
  resBox.className = 'conflict-box conflict-box-warning';
  resBox.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking Philippine holidays & schedule conflicts...';

  try {
    const fd = new FormData();
    fd.append('action', 'check_conflict');
    fd.append('event_date', dateVal);
    fd.append('venue', venueVal);
    fd.append('club_id', clubIdVal);

    const res = await fetch('../shared/event_actions.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.success && data.analysis) {
      const a = data.analysis;
      if (a.level === 'danger') {
        resBox.className = 'conflict-box conflict-box-danger';
        let msgs = a.conflicts.map(c => `<li><strong>${c.title}:</strong> ${c.message}</li>`).join('');
        resBox.innerHTML = `
          <div class="conflict-box-title"><i class="fa-solid fa-triangle-exclamation"></i> Scheduling Conflict Detected!</div>
          <ul style="margin:6px 0 0 16px; padding:0; line-height:1.5;">${msgs}</ul>
          <div style="margin-top:6px; font-weight:600; font-size:0.78rem;">Recommendation: Select an alternate non-holiday academic date or change venue to avoid administrative rejection.</div>
        `;
      } else if (a.level === 'warning') {
        resBox.className = 'conflict-box conflict-box-warning';
        let msgs = a.conflicts.map(c => `<li><strong>${c.title}:</strong> ${c.message}</li>`).join('');
        resBox.innerHTML = `
          <div class="conflict-box-title"><i class="fa-solid fa-circle-exclamation"></i> Special Schedule Advisory</div>
          <ul style="margin:6px 0 0 16px; padding:0; line-height:1.5;">${msgs}</ul>
        `;
      } else {
        resBox.className = 'conflict-box conflict-box-safe';
        resBox.innerHTML = `
          <div class="conflict-box-title"><i class="fa-solid fa-circle-check"></i> Date & Venue Verified Conflict-Free</div>
          <div>${a.formatted_date} has no Philippine regular holiday, special non-working day, exam blackout, or venue collision conflicts.</div>
        `;
      }
    }
  } catch (err) {
    resBox.style.display = 'none';
  }
}

function runAICheckDateConflict() {
  autoCheckModalDate();
}

function viewEventById(id) {
  const ev = ALL_EVENTS.find(e => parseInt(e.id) === parseInt(id));
  if (ev) viewEvent(ev);
}

function locateOnCalendar(dateStr, eventId) {
  if (!dateStr) return;
  const parts = dateStr.split('-');
  const yr = parseInt(parts[0]);
  const mo = parseInt(parts[1]) - 1;

  currentDate = new Date(yr, mo, 1);
  renderCalendar();

  const calEl = document.querySelector('.calendar-section');
  if (calEl) {
    calEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  setTimeout(() => {
    const targetCell = document.querySelector(`.cal-day-cell[data-full-date="${dateStr}"]`);
    if (targetCell) {
      targetCell.classList.add('cal-highlight-pulse');
      setTimeout(() => targetCell.classList.remove('cal-highlight-pulse'), 3000);
    }
  }, 350);
}

function filterEventsBySelectedMonth(monthKey) {
  const select = document.getElementById('monthFilterSelect');
  if (select && monthKey) select.value = monthKey;

  const rows = document.querySelectorAll('#eventTable tbody tr');
  rows.forEach(tr => {
    const trMonth = tr.getAttribute('data-month');
    if (!monthKey || monthKey === 'ALL' || trMonth === monthKey) {
      tr.style.display = '';
    } else {
      tr.style.display = 'none';
    }
  });
}

function syncTableWithActiveCalMonth() {
  const yr = currentDate.getFullYear();
  const mo = String(currentDate.getMonth() + 1).padStart(2, '0');
  filterEventsBySelectedMonth(`${yr}-${mo}`);
}

function registerForEvent(id) {
  const fd = new FormData();
  fd.append('action', 'register');
  fd.append('event_id', id);
  fetch('../shared/event_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showToast(res.message, 'success');
        closeModal('viewEventModal');
        setTimeout(() => location.reload(), 1500);
      } else {
        showToast(res.message, 'error');
      }
    })
    .catch(() => showToast('Network error.', 'error'));
}

document.getElementById('calPrevBtn')?.addEventListener('click', () => {
  currentDate.setMonth(currentDate.getMonth() - 1);
  renderCalendar();
});

document.getElementById('calNextBtn')?.addEventListener('click', () => {
  currentDate.setMonth(currentDate.getMonth() + 1);
  renderCalendar();
});

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', renderCalendar);
} else {
  renderCalendar();
}

function openModal(id)  {
  const el = document.getElementById(id);
  if (el) { el.classList.add('active'); el.style.display = 'flex'; }
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.remove('active', 'open'); el.style.display = 'none'; }
}

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = 'toast-notification ' + type;
  t.style.display = 'block'; setTimeout(() => t.style.display = 'none', 3500);
}

function filterEventTable() {
  const q = document.getElementById('eventSearch').value.toLowerCase();
  document.querySelectorAll('#eventTable tbody tr').forEach(tr => {
    const match = tr.textContent.toLowerCase().includes(q);
    tr.setAttribute('data-search-hidden', match ? 'false' : 'true');
  });
  const tbl = document.getElementById('eventTable');
  if (tbl && tbl._paginator) {
    tbl._paginator.currentPage = 1;
    tbl._paginator.refresh();
  }
}

// View event details
function viewEvent(ev) {
  const isRegistered = MY_REG_IDS.includes(parseInt(ev.id));
  let regActionHtml = '';
  if (ev.status !== 'Rejected') {
    if (isRegistered) {
      regActionHtml = `
        <div style="padding:8px 14px; background:#dcfce7; border:1px solid #86efac; border-radius:8px; display:inline-flex; align-items:center; gap:6px; color:#15803d; font-weight:700; font-size:0.82rem;">
          <i class="fa-solid fa-circle-check"></i> Registered (Confirmed)
        </div>`;
    } else {
      regActionHtml = `
        <button type="button" class="card-btn" style="background:linear-gradient(135deg, #1e3a8a, #2563eb); color:#ffffff; font-weight:700; padding:10px 20px; border-radius:8px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 12px rgba(37,99,235,0.25); font-size:0.85rem;" onclick="registerForEvent(${ev.id})">
          <i class="fa-solid fa-user-plus"></i> Register for Event / Activity
        </button>`;
    }
  }
  const statusBadgeClass = ev.status === 'Approved' ? 'badge-active' : (ev.status === 'Rejected' ? 'badge-inactive' : 'badge-warning');
  const formattedDate = new Date(ev.event_date.replace(' ', 'T')).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
  document.getElementById('viewEventBody').innerHTML = `
    <div style="display:flex; flex-direction:column; gap:16px;">
      <div>
        <span style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Event Title</span>
        <h3 style="margin:4px 0 0; color:#0f172a; font-size:1.15rem; font-weight:800;">${ev.title}</h3>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; background:#f8fafc; padding:16px; border-radius:12px; border:1px solid #e2e8f0;">
        <div>
          <span style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase;">Host Org</span>
          <div style="font-size:0.88rem; font-weight:700; color:#1e293b; margin-top:2px;">${ev.club_name} <span style="color:#64748b; font-weight:600;">(${ev.club_code})</span></div>
        </div>
        <div>
          <span style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase;">Status</span>
          <div style="margin-top:4px;"><span class="${statusBadgeClass}">${ev.status}</span></div>
        </div>
        <div>
          <span style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase;">Date &amp; Time</span>
          <div style="font-size:0.88rem; font-weight:600; color:#1e293b; margin-top:2px;">${formattedDate}</div>
        </div>
        <div>
          <span style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase;">Venue</span>
          <div style="font-size:0.88rem; font-weight:600; color:#1e293b; margin-top:2px;">${ev.venue}</div>
        </div>
      </div>
      <div>
        <span style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Description</span>
        <p style="margin:6px 0 0; font-size:0.88rem; line-height:1.6; color:#334155;">${ev.description || 'No description provided.'}</p>
      </div>
      ${ev.rejection_note ? `
        <div style="color:#991b1b; background:#fef2f2; border:1px solid #fca5a5; padding:12px 14px; border-radius:10px; font-size:0.85rem;">
          <strong>Rejection Note:</strong> ${ev.rejection_note}
        </div>` : ''}
      <div style="margin-top:8px; padding-top:16px; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <button type="button" class="card-btn" style="background:#e2e8f0; color:#475569; font-weight:600; padding:10px 20px; border-radius:8px; border:none; cursor:pointer;" onclick="closeModal('viewEventModal')">Close</button>
        ${regActionHtml}
      </div>
    </div>`;
  openModal('viewEventModal');
}
// Edit event
function editEvent(ev) {
  document.getElementById('editEventId').value    = ev.id;
  document.getElementById('editEventTitle').value  = ev.title;
  document.getElementById('editEventDesc').value   = ev.description || '';
  document.getElementById('editEventVenue').value  = ev.venue;
  // Convert to datetime-local format
  const dt = new Date(ev.event_date);
  dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset());
  document.getElementById('editEventDate').value   = dt.toISOString().slice(0,16);
  openModal('editEventModal');
}

// Approve event
function approveEvent(id, title) {
  if (!confirm(`Approve event: "${title}"?`)) return;
  const fd = new FormData();
  fd.append('action', 'approve'); fd.append('id', id);
  fetch('../shared/event_actions.php', { method:'POST', body:fd })
    .then(r => r.json()).then(res => {
      if (res.success) { showToast('Event approved!'); setTimeout(() => location.reload(), 1500); }
      else showToast(res.message, 'error');
    });
}

// Reject event
let rejectEvId = 0;
function rejectEvent(id, title) {
  rejectEvId = id;
  document.getElementById('rejectEventDesc').textContent = `Reject proposal: "${title}"`;
  document.getElementById('rejectEventNote').value = '';
  openModal('rejectEventModal');
}

document.getElementById('confirmRejectEventBtn')?.addEventListener('click', async () => {
  const note = document.getElementById('rejectEventNote').value.trim();
  if (!note) { alert('Please provide a reason.'); return; }
  const fd = new FormData();
  fd.append('action','reject'); fd.append('id', rejectEvId); fd.append('note', note);
  const res = await fetch('../shared/event_actions.php', { method:'POST', body:fd }).then(r => r.json());
  closeModal('rejectEventModal');
  if (res.success) { showToast('Event rejected.', 'warning'); setTimeout(() => location.reload(), 1500); }
  else showToast(res.message, 'error');
});

// Create event proposal form submit
document.getElementById('createEventForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = document.getElementById('createEventBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...'; }
  const fd = new FormData(e.target);
  fd.append('action', 'create');
  try {
    const res = await fetch('../shared/event_actions.php', { method: 'POST', body: fd }).then(r => r.json());
    closeModal('createEventModal');
    if (res.success) {
      showToast('Event proposal submitted to SSC for review & approval!', 'success');
      setTimeout(() => location.reload(), 1200);
    } else {
      showToast(res.message || 'Failed to submit event proposal.', 'error');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit to SSC for Approval'; }
    }
  } catch (err) {
    showToast('Network or server error occurred.', 'error');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit to SSC for Approval'; }
  }
});

// Edit event form
document.getElementById('editEventForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target); fd.append('action','edit');
  const res = await fetch('../shared/event_actions.php', { method:'POST', body:fd }).then(r => r.json());
  closeModal('editEventModal');
  if (res.success) {
    showToast('Event updated!');
    setTimeout(() => location.reload(), 1500);
  } else showToast(res.message, 'error');
});

let currentViewingEventRegistrations = [];
let currentViewingEventMeta = null;

async function viewRegistrations(eventId, eventTitle) {
  const fd = new FormData();
  fd.append('action', 'list_registrations');
  fd.append('event_id', eventId);

  try {
    const res = await fetch('../shared/event_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) {
      alert('Error: ' + data.message);
      return;
    }

    const list = data.registrations || [];
    const ev = data.event || {};
    currentViewingEventRegistrations = list;
    currentViewingEventMeta = {
      id: eventId,
      title: eventTitle || ev.title || 'Campus Event',
      date: ev.event_date || '',
      venue: ev.venue || '',
      club: ev.club_name ? `${ev.club_name} (${ev.club_code || ''})` : ''
    };

    const headerTitle = document.getElementById('regModalHeaderTitle');
    if (headerTitle) {
      headerTitle.textContent = `Registration Roster — ${currentViewingEventMeta.title}`;
    }
    const searchInp = document.getElementById('regSearchInput');
    if (searchInp) searchInp.value = '';

    renderRegistrationsTable(list);
    document.getElementById('eventRegistrationsModal').style.display = 'flex';
  } catch (err) {
    alert('Network error retrieving registration list.');
  }
}

function renderRegistrationsTable(list) {
  const tbody = document.getElementById('regTableBody');
  if (!list || !list.length) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:24px; color:#64748b; font-style:italic;">No registered student attendees found.</td></tr>`;
    return;
  }

  let html = '';
  list.forEach((r, idx) => {
    const courseStr = [r.course || 'BSIT', r.year_level || '', r.section ? `Sec ${r.section}` : ''].filter(Boolean).join(' - ');
    html += `
      <tr style="border-bottom:1px solid #f1f5f9;">
        <td style="padding:10px 12px; text-align:center; color:#64748b; font-weight:600;">${idx + 1}</td>
        <td style="padding:10px 12px;">
          <strong style="color:#0f172a; font-size:0.9rem;">${r.first_name} ${r.last_name}</strong>
        </td>
        <td style="padding:10px 12px; font-weight:600; color:#334155; font-size:0.82rem;">${r.student_number || '—'}</td>
        <td style="padding:10px 12px; color:#475569;">${courseStr}</td>
        <td style="padding:10px 12px; font-size:0.82rem; color:#475569;">
          <div>${r.email || '—'}</div>
          ${r.phone ? `<div style="font-size:0.75rem; color:#64748b;">${r.phone}</div>` : ''}
        </td>
        <td style="padding:10px 12px; text-align:center;">
          <span style="background:#dcfce7; color:#166534; font-size:0.72rem; font-weight:800; padding:3px 8px; border-radius:12px; display:inline-flex; align-items:center; gap:4px;">
            <i class="fa-solid fa-circle-check"></i> ${r.status || 'Registered'}
          </span>
        </td>
      </tr>
    `;
  });
  tbody.innerHTML = html;
}

function filterRegistrationsList(query) {
  const q = (query || '').toLowerCase().trim();
  if (!q) {
    renderRegistrationsTable(currentViewingEventRegistrations);
    return;
  }
  const filtered = currentViewingEventRegistrations.filter(r => {
    const fullName = `${r.first_name || ''} ${r.last_name || ''}`.toLowerCase();
    const email = (r.email || '').toLowerCase();
    const course = (r.course || '').toLowerCase();
    const studentNo = (r.student_number || '').toLowerCase();
    return fullName.includes(q) || email.includes(q) || course.includes(q) || studentNo.includes(q);
  });
  renderRegistrationsTable(filtered);
}

function loadImage(src) {
  return new Promise((resolve) => {
    const img = new Image();
    img.crossOrigin = 'Anonymous';
    img.onload = () => resolve(img);
    img.onerror = () => resolve(null);
    img.src = src;
  });
}

async function exportRegistrationsPDF() {
  if (!currentViewingEventMeta) {
    alert('No event data selected.');
    return;
  }

  const list = currentViewingEventRegistrations || [];
  if (!list.length) {
    alert('No registered student attendees available to export.');
    return;
  }

  if (typeof window.jspdf === 'undefined' && typeof jsPDF === 'undefined') {
    printEventRegistrationsReport();
    return;
  }

  const { jsPDF } = window.jspdf || { jsPDF };
  const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
  const pageW = doc.internal.pageSize.getWidth();
  const pageH = doc.internal.pageSize.getHeight();
  const margin = 14;

  // 1. School Logo & Official Letterhead
  const logoImg = await loadImage('../images/BCP_LOGO.png');
  if (logoImg) {
    try {
      doc.addImage(logoImg, 'PNG', margin + 2, 7, 17, 17);
    } catch (e) {
      console.warn('Logo embed error:', e);
    }
  }

  doc.setTextColor(30, 58, 138); // #1e3a8a
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(13);
  doc.text('BESTLINK COLLEGE OF THE PHILIPPINES', margin + 23, 13);

  doc.setTextColor(71, 85, 105); // #475569
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(8.5);
  doc.text('Office of Student Affairs & Services \u2022 Campus Student Organizations', margin + 23, 18.5);

  doc.setTextColor(100, 116, 139); // #64748b
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(7.5);
  doc.text('Campus Co-Curricular Student Organization Management System', margin + 23, 23.5);

  // Divider Line
  doc.setDrawColor(30, 58, 138);
  doc.setLineWidth(0.5);
  doc.line(margin, 28, pageW - margin, 28);

  // 2. Document Title
  doc.setTextColor(15, 23, 42);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(11.5);
  doc.text('OFFICIAL EVENT REGISTRATION ROSTER', pageW / 2, 35, { align: 'center' });

  // 3. Event Summary Metadata Box
  doc.setFillColor(248, 250, 252);
  doc.setDrawColor(203, 213, 225);
  doc.roundedRect(margin, 39, pageW - margin * 2, 23, 2, 2, 'FD');

  doc.setFontSize(8);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(30, 41, 59);

  // Left column
  doc.text('Event Title:', margin + 4, 45);
  doc.setFont('helvetica', 'normal');
  const splitTitle = doc.splitTextToSize(String(currentViewingEventMeta.title || 'Campus Event'), 68);
  doc.text(splitTitle, margin + 22, 45);

  doc.setFont('helvetica', 'bold');
  doc.text('Date & Time:', margin + 4, 52);
  doc.setFont('helvetica', 'normal');
  doc.text(String(currentViewingEventMeta.date || 'TBD'), margin + 22, 52);

  doc.setFont('helvetica', 'bold');
  doc.text('Total Registered:', margin + 4, 58);
  doc.setFont('helvetica', 'normal');
  doc.text(`${list.length} Students`, margin + 28, 58);

  // Right column
  doc.setFont('helvetica', 'bold');
  doc.text('Host Org:', margin + 96, 45);
  doc.setFont('helvetica', 'normal');
  const splitClub = doc.splitTextToSize(String(currentViewingEventMeta.club || 'Campus Organization'), 68);
  doc.text(splitClub, margin + 112, 45);

  doc.setFont('helvetica', 'bold');
  doc.text('Venue:', margin + 96, 52);
  doc.setFont('helvetica', 'normal');
  const splitVenue = doc.splitTextToSize(String(currentViewingEventMeta.venue || 'Campus Facility'), 68);
  doc.text(splitVenue, margin + 112, 52);

  doc.setFont('helvetica', 'bold');
  doc.text('Export Date:', margin + 96, 58);
  doc.setFont('helvetica', 'normal');
  doc.text(new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }), margin + 115, 58);

  // 4. Table Body Rows
  const tableRows = list.map((r, idx) => {
    const courseStr = [r.course || 'BSIT', r.year_level || '', r.section ? `Sec ${r.section}` : ''].filter(Boolean).join(' - ');
    return [
      idx + 1,
      `${r.first_name || ''} ${r.last_name || ''}`,
      r.student_number || '—',
      courseStr,
      r.email || '',
      r.phone || 'N/A',
      r.status || 'Registered'
    ];
  });

  // 5. Render Table via autoTable
  doc.autoTable({
    startY: 66,
    head: [['#', 'Student Name', 'Student ID', 'Course & Year', 'Email', 'Contact Phone', 'Status']],
    body: tableRows,
    theme: 'grid',
    headStyles: {
      fillColor: [30, 58, 138],
      textColor: [255, 255, 255],
      fontStyle: 'bold',
      fontSize: 8,
      halign: 'left'
    },
    bodyStyles: {
      fontSize: 7.5,
      textColor: [30, 41, 59]
    },
    alternateRowStyles: {
      fillColor: [248, 250, 252]
    },
    columnStyles: {
      0: { halign: 'center', cellWidth: 8 },
      1: { fontStyle: 'bold', cellWidth: 38 },
      2: { cellWidth: 26 },
      3: { cellWidth: 32 },
      4: { cellWidth: 42 },
      5: { cellWidth: 22 },
      6: { halign: 'center', cellWidth: 16 }
    },
    margin: { left: margin, right: margin, bottom: 38 },
    didDrawPage: function(data) {
      doc.setFontSize(7.5);
      doc.setFont('helvetica', 'normal');
      doc.setTextColor(148, 163, 184);
      doc.text(
        `Page ${doc.internal.getNumberOfPages()}`,
        pageW / 2,
        pageH - 8,
        { align: 'center' }
      );
    }
  });

  // 6. Signatures ALWAYS at the Bottom of the Final Page
  const totalPages = doc.internal.getNumberOfPages();
  doc.setPage(totalPages);

  if (doc.lastAutoTable && doc.lastAutoTable.finalY > pageH - 42) {
    doc.addPage();
  }

  const signY = pageH - 28; // pinned to bottom
  const signW = (pageW - margin * 2) / 3;

  doc.setDrawColor(51, 65, 85);
  doc.setLineWidth(0.4);

  // Sign 1
  doc.line(margin + 4, signY, margin + signW - 4, signY);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(8);
  doc.setTextColor(15, 23, 42);
  doc.text('Event Coordinator / Lead', margin + signW / 2, signY + 4, { align: 'center' });
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(7);
  doc.setTextColor(100, 116, 139);
  doc.text('Activity In-Charge', margin + signW / 2, signY + 7.5, { align: 'center' });

  // Sign 2
  doc.line(margin + signW + 4, signY, margin + signW * 2 - 4, signY);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(8);
  doc.setTextColor(15, 23, 42);
  doc.text('Club Faculty Adviser', margin + signW * 1.5, signY + 4, { align: 'center' });
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(7);
  doc.setTextColor(100, 116, 139);
  doc.text('Faculty Supervision', margin + signW * 1.5, signY + 7.5, { align: 'center' });

  // Sign 3
  doc.line(margin + signW * 2 + 4, signY, pageW - margin - 4, signY);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(8);
  doc.setTextColor(15, 23, 42);
  doc.text('Director / Dean of Student Affairs', margin + signW * 2.5, signY + 4, { align: 'center' });
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(7);
  doc.setTextColor(100, 116, 139);
  doc.text('Office of Student Affairs', margin + signW * 2.5, signY + 7.5, { align: 'center' });

  // Direct PDF Download
  const cleanTitle = (currentViewingEventMeta.title || 'event').replace(/[^a-z0-9]/gi, '_').toLowerCase();
  doc.save(`${cleanTitle}_registration_roster_${new Date().toISOString().slice(0, 10)}.pdf`);
}

function exportRegistrationsCSV() {
  if (!currentViewingEventRegistrations || !currentViewingEventRegistrations.length) {
    alert('No registrations available to export.');
    return;
  }
  const headers = ['#', 'Student Number', 'First Name', 'Last Name', 'Email', 'Course', 'Year Level', 'Section', 'Contact Phone', 'Status', 'Registered At'];
  const rows = currentViewingEventRegistrations.map((r, idx) => [
    idx + 1,
    `"${r.student_number || ''}"`,
    `"${r.first_name || ''}"`,
    `"${r.last_name || ''}"`,
    `"${r.email || ''}"`,
    `"${r.course || ''}"`,
    `"${r.year_level || ''}"`,
    `"${r.section || ''}"`,
    `"${r.phone || ''}"`,
    `"${r.status || 'Registered'}"`,
    `"${r.registered_at || ''}"`
  ]);

  const csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + [headers.join(','), ...rows.map(e => e.join(','))].join('\n');
  const encodedUri = encodeURI(csvContent);
  const link = document.createElement('a');
  link.setAttribute('href', encodedUri);
  const cleanTitle = (currentViewingEventMeta?.title || 'event').replace(/[^a-z0-9]/gi, '_').toLowerCase();
  link.setAttribute('download', `${cleanTitle}_registrations_${new Date().toISOString().slice(0,10)}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

function printEventRegistrationsReport() {
  if (!currentViewingEventMeta) return;

  const list = currentViewingEventRegistrations || [];
  let rowsHtml = '';
  list.forEach((r, idx) => {
    const courseStr = [r.course || 'BSIT', r.year_level || '', r.section ? `Sec ${r.section}` : ''].filter(Boolean).join(' - ');
    rowsHtml += `
      <tr>
        <td style="padding:6px 8px; border:1px solid #cbd5e1; text-align:center;">${idx + 1}</td>
        <td style="padding:6px 8px; border:1px solid #cbd5e1; font-weight:700;">${r.first_name} ${r.last_name}</td>
        <td style="padding:6px 8px; border:1px solid #cbd5e1;">${r.student_number || '—'}</td>
        <td style="padding:6px 8px; border:1px solid #cbd5e1;">${courseStr}</td>
        <td style="padding:6px 8px; border:1px solid #cbd5e1;">${r.email || ''}</td>
        <td style="padding:6px 8px; border:1px solid #cbd5e1;">${r.phone || 'N/A'}</td>
        <td style="padding:6px 8px; border:1px solid #cbd5e1; text-align:center; color:#16a34a; font-weight:700;">${r.status || 'Registered'}</td>
      </tr>
    `;
  });

  const printWin = window.open('', '_blank', 'width=920,height=780');
  if (!printWin) {
    alert('Please allow popups to export/print event registrations.');
    return;
  }

  printWin.document.write(`
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8" />
      <title></title>
      <style>
        @page { size: portrait; margin: 12mm 15mm; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #0f172a; margin: 0; padding: 15px; font-size: 11px; line-height: 1.35; }
        .header { display: flex; align-items: center; justify-content: center; gap: 14px; border-bottom: 2px solid #1e3a8a; padding-bottom: 12px; margin-bottom: 12px; text-align: center; }
        .logo { width: 58px; height: 58px; object-fit: contain; }
        .header-text h1 { margin: 0; font-size: 16px; color: #1e3a8a; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px; }
        .header-text p { margin: 2px 0 0; font-size: 10.5px; color: #475569; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .doc-title { text-align: center; margin: 8px 0 10px; font-size: 14px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; background: #f8fafc; }
        .meta-table td { padding: 6px 10px; border: 1px solid #e2e8f0; font-size: 11px; }
        table.data-table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 10.5px; }
        table.data-table th { background: #f1f5f9; color: #1e293b; font-weight: 700; padding: 6px 8px; border: 1px solid #cbd5e1; text-align: left; }
        table.data-table td { padding: 6px 8px; border: 1px solid #cbd5e1; }
        .signatures { display: flex; justify-content: space-between; margin-top: 32px; page-break-inside: avoid; }
        .sign-box { width: 30%; text-align: center; font-size: 10.5px; }
        .sign-line { border-top: 1px solid #334155; margin-top: 40px; padding-top: 4px; font-weight: 700; color: #0f172a; }
        .print-btn-bar { text-align: right; margin-bottom: 12px; }
        .print-btn { background: #2563eb; color: #fff; border: none; padding: 8px 18px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 12px; }
        @media print {
          .print-btn-bar { display: none !important; }
          body { padding: 0; }
        }
      </style>
    </head>
    <body>
      <div class="print-btn-bar">
        <button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
      </div>

      <div class="header">
        <img class="logo" src="../images/BCP_LOGO.png" alt="Bestlink College of the Philippines Logo" />
        <div class="header-text">
          <h1>Bestlink College of the Philippines</h1>
          <p>Office of Student Affairs &amp; Services &bull; Campus Student Organizations</p>
          <p style="font-size:10px; color:#64748b; margin-top:1px;">Official Event Registration &amp; Attendance Roster</p>
        </div>
      </div>

      <div class="doc-title">Official Event Registration Roster</div>

      <table class="meta-table">
        <tr>
          <td style="width:50%;"><strong>Event Title:</strong> ${currentViewingEventMeta.title}</td>
          <td style="width:50%;"><strong>Host Organization:</strong> ${currentViewingEventMeta.club || 'Campus Organization'}</td>
        </tr>
        <tr>
          <td><strong>Scheduled Date &amp; Time:</strong> ${currentViewingEventMeta.date || 'TBD'}</td>
          <td><strong>Venue:</strong> ${currentViewingEventMeta.venue || 'Campus Facility'}</td>
        </tr>
        <tr>
          <td><strong>Total Registered Attendees:</strong> ${list.length} Students</td>
          <td><strong>Generated Date:</strong> ${new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</td>
        </tr>
      </table>

      <table class="data-table">
        <thead>
          <tr>
            <th style="width:30px; text-align:center;">#</th>
            <th>Student Name</th>
            <th>Student ID</th>
            <th>Course &amp; Year</th>
            <th>Email</th>
            <th>Contact Phone</th>
            <th style="text-align:center; width:80px;">Status</th>
          </tr>
        </thead>
        <tbody>
          ${rowsHtml || '<tr><td colspan="7" style="text-align:center; padding:16px;">No students registered yet.</td></tr>'}
        </tbody>
      </table>

      <div class="signatures">
        <div class="sign-box">
          <div class="sign-line">Event Lead / Organizer</div>
          <span style="color:#64748b; font-size:10px;">Activity In-Charge</span>
        </div>
        <div class="sign-box">
          <div class="sign-line">Organization Faculty Adviser</div>
          <span style="color:#64748b; font-size:10px;">Faculty Supervision</span>
        </div>
        <div class="sign-box">
          <div class="sign-line">Dean / Director of Student Affairs</div>
          <span style="color:#64748b; font-size:10px;">Office of Student Affairs</span>
        </div>
      </div>
    </body>
    </html>
  `);
  printWin.document.close();
  printWin.focus();
  setTimeout(() => {
    printWin.print();
  }, 400);
}

// ── AI Event Planner & Schedule Conflict Optimizer (Adviser & SSC) ──
let CURRENT_AI_PLANS = [];
let CURRENT_AI_CLUB_ID = 0;

async function generateAIEventPlans() {
  const btn = document.getElementById('aiPlanBtn');
  const body = document.getElementById('aiPlannerBody');
  const loading = document.getElementById('aiPlannerLoading');
  const results = document.getElementById('aiPlannerResults');
  const clubSelect = document.getElementById('aiPlannerClubSelect');
  const themeInput = document.getElementById('aiPlannerThemeInput');

  if (!btn || !body) return;

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Generating AI Event Proposals &amp; Schedules...</span>';
  body.style.display = 'block';
  loading.style.display = 'flex';
  results.innerHTML = '';

  try {
    const fd = new FormData();
    fd.append('action', 'plan_events');
    if (clubSelect) {
      fd.append('club_id', clubSelect.value);
    }
    if (themeInput && themeInput.value.trim()) {
      fd.append('theme', themeInput.value.trim());
    }
    fd.append('seed', Date.now());

    const res = await fetch('../shared/ai_actions.php?_t=' + Date.now(), { 
      method: 'POST', 
      body: fd,
      headers: { 'Cache-Control': 'no-cache' }
    });
    const data = await res.json();

    loading.style.display = 'none';

    if (!data.success) {
      results.innerHTML = `<div class="ai-error-msg"><i class="fa-solid fa-triangle-exclamation"></i> ${data.message || 'Failed to generate event plans.'}</div>`;
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> <span>Generate AI Event Ideas &amp; Dates</span>';
      return;
    }

    const parsed = data.parsed;
    if (!parsed || !parsed.plans) {
      results.innerHTML = `<div class="ai-error-msg"><i class="fa-solid fa-triangle-exclamation"></i> AI returned an unexpected response format. Please try again.</div>`;
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> <span>Regenerate</span>';
      return;
    }

    CURRENT_AI_PLANS = parsed.plans || [];
    CURRENT_AI_CLUB_ID = data.club_id || (clubSelect ? parseInt(clubSelect.value) : 1);

    let html = '';

    // Summary Box
    if (parsed.analysis_summary) {
      html += `
        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:14px 18px; margin-bottom:16px;">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:4px;">
            <div style="font-weight:800; color:#1e3a8a; font-size:0.92rem;">
              <i class="fa-solid fa-sparkles" style="color:#2563eb; margin-right:6px;"></i> Strategic AI Event Proposals for ${parsed.organization || 'Organization'}
            </div>
            <span style="font-size:0.72rem; font-weight:700; background:#dbeafe; color:#1e40af; padding:3px 8px; border-radius:4px;">
              <i class="fa-solid fa-microchip"></i> ${data.engine || 'Google Gemini AI'}
            </span>
          </div>
          <div style="font-size:0.82rem; color:#334155; line-height:1.5;">${parsed.analysis_summary}</div>
        </div>`;
    }

    // Grid of Proposed Plans
    html += '<div class="ai-plans-grid">';
    CURRENT_AI_PLANS.forEach((plan, idx) => {
      const dtStr = plan.recommended_date ? plan.recommended_date.replace('T', ' ') : 'N/A';
      const formattedDate = plan.recommended_date ? new Date(plan.recommended_date).toLocaleString('en-US', { dateStyle:'medium', timeStyle:'short' }) : dtStr;
      const score = plan.feasibility_score || 95;

      html += `
        <div class="ai-plan-card" style="animation: fadeInUp 0.4s ease ${idx * 0.12}s both;">
          <div>
            <div class="ai-plan-header">
              <div class="ai-plan-title">${plan.title}</div>
              <span class="ai-score-pill"><i class="fa-solid fa-shield-check"></i> ${score}% ${plan.clash_status || 'Conflict-Free'}</span>
            </div>
            <div class="ai-plan-meta">
              <span class="ai-meta-tag"><i class="fa-solid fa-calendar-day"></i> ${formattedDate}</span>
              <span class="ai-meta-tag"><i class="fa-solid fa-location-dot"></i> ${plan.recommended_venue || 'Campus Venue'}</span>
              <span class="ai-meta-tag" style="background:#fef3c7; color:#92400e; border-color:#fde68a;"><i class="fa-solid fa-tags"></i> ${plan.category || 'General'}</span>
            </div>
            <div class="ai-plan-desc">${plan.description}</div>
            <div class="ai-conflict-box">
              <strong><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Date Accessibility Analysis:</strong><br>
              ${plan.accessibility_verdict || 'Clear and convenient schedule for student attendance.'}<br>
              <span style="color:#047857; font-weight:600; display:inline-block; margin-top:3px;"><i class="fa-solid fa-calendar-check"></i> ${plan.holiday_check || 'No holiday conflicts.'}</span>
            </div>
          </div>
          <div>
            <button type="button" class="ai-apply-plan-btn" onclick="applyAIEventPlan(${idx})">
              <i class="fa-solid fa-pen-to-square"></i> Use This Plan (Auto-Fill Proposal)
            </button>
          </div>
        </div>`;
    });
    html += '</div>';

    if (parsed.scheduling_insights) {
      html += `
        <div style="margin-top:16px; padding:12px 16px; background:#fdf4ff; border:1px solid #f0abfc; border-radius:10px; font-size:0.78rem; color:#86198f;">
          <i class="fa-solid fa-lightbulb" style="margin-right:6px;"></i> <strong>SSC &amp; Adviser Scheduling Note:</strong> ${parsed.scheduling_insights}
        </div>`;
    }

    results.innerHTML = html;
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> <span>Regenerate Ideas</span>';

  } catch (err) {
    loading.style.display = 'none';
    results.innerHTML = `<div class="ai-error-msg"><i class="fa-solid fa-triangle-exclamation"></i> Network or server error. Please try again.</div>`;
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> <span>Generate AI Event Ideas &amp; Dates</span>';
  }
}

// Auto-fill Create Event Proposal Form from AI Plan
function applyAIEventPlan(planIndex) {
  const plan = CURRENT_AI_PLANS[planIndex];
  if (!plan) return;

  const titleInput = document.querySelector('#createEventForm input[name="title"]');
  const descInput  = document.querySelector('#createEventForm textarea[name="description"]');
  const dateInput  = document.querySelector('#createEventForm input[name="event_date"]');
  const venueInput = document.querySelector('#createEventForm input[name="venue"]');
  const clubSelect = document.getElementById('createEventClubSelect');

  if (titleInput) titleInput.value = plan.title || '';
  if (descInput)  descInput.value  = plan.description || '';
  if (venueInput) venueInput.value = plan.recommended_venue || 'Main Auditorium';
  
  if (dateInput && plan.recommended_date) {
    let dVal = plan.recommended_date;
    if (dVal.length === 16) dateInput.value = dVal;
    else if (dVal.length > 16) dateInput.value = dVal.slice(0, 16);
  }

  if (clubSelect && CURRENT_AI_CLUB_ID) {
    clubSelect.value = CURRENT_AI_CLUB_ID;
  }

  openModal('createEventModal');

  // Immediately display AI verified conflict badge
  const resBox = document.getElementById('conflictAuditResult');
  if (resBox) {
    resBox.style.display = 'block';
    resBox.style.background = '#f0fdf4';
    resBox.style.border = '1px solid #86efac';
    resBox.style.color = '#15803d';
    resBox.innerHTML = `<i class="fa-solid fa-circle-check"></i> <strong>AI Conflict Verified:</strong> ${plan.accessibility_verdict || 'Date is conflict-free and verified against campus holidays.'}`;
  }
}

// Live on-demand Date & Conflict Auditor in Create Modal
async function runAICheckDateConflict() {
  const dateInput  = document.getElementById('createEventDateInput');
  const venueInput = document.getElementById('createEventVenueInput');
  const resBox     = document.getElementById('conflictAuditResult');
  const btn        = document.getElementById('btnAuditModalDate');

  if (!dateInput || !dateInput.value) {
    alert('Please select an event date and time first.');
    dateInput?.focus();
    return;
  }

  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Auditing Calendar...'; }
  if (resBox) { resBox.style.display = 'none'; }

  try {
    const fd = new FormData();
    fd.append('action', 'check_schedule_conflict');
    fd.append('event_date', dateInput.value);
    fd.append('venue', venueInput?.value || '');

    const res = await fetch('../shared/ai_actions.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> AI Audit Date &amp; Check Conflicts'; }

    if (!data.success || !data.analysis) {
      if (resBox) {
        resBox.style.display = 'block';
        resBox.style.background = '#fef2f2';
        resBox.style.border = '1px solid #fca5a5';
        resBox.style.color = '#b91c1c';
        resBox.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> ${data.message || 'Audit check failed.'}`;
      }
      return;
    }

    const a = data.analysis;
    if (resBox) {
      resBox.style.display = 'block';
      let html = '';
      if (a.status === 'safe') {
        resBox.style.background = '#f0fdf4';
        resBox.style.border = '1px solid #86efac';
        resBox.style.color = '#15803d';
        html = `<strong><i class="fa-solid fa-circle-check"></i> Highly Accessible &amp; Conflict-Free (${a.score}% Feasibility)</strong><br>`;
        html += a.safe_notes.map(n => `<span style="display:block; margin-top:2px;">• ${n}</span>`).join('');
      } else if (a.status === 'warning') {
        resBox.style.background = '#fffbeb';
        resBox.style.border = '1px solid #fde68a';
        resBox.style.color = '#92400e';
        html = `<strong><i class="fa-solid fa-triangle-exclamation"></i> Schedule Warning (${a.score}% Feasibility)</strong><br>`;
        html += a.warnings.map(w => `<span style="display:block; margin-top:2px;">• ${w}</span>`).join('');
      } else {
        resBox.style.background = '#fef2f2';
        resBox.style.border = '1px solid #fca5a5';
        resBox.style.color = '#b91c1c';
        html = `<strong><i class="fa-solid fa-circle-xmark"></i> Scheduling Conflict Detected (${a.score}% Feasibility)</strong><br>`;
        html += a.conflicts.map(c => `<span style="display:block; margin-top:2px; font-weight:600;">• ${c}</span>`).join('');
      }
      resBox.innerHTML = html;
    }

  } catch (err) {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> AI Audit Date &amp; Check Conflicts'; }
  }
}

function autoCheckModalDate() {
  const resBox = document.getElementById('conflictAuditResult');
  if (resBox && resBox.style.display !== 'none') {
    resBox.style.display = 'none';
  }
}
</script>
<script src="../js/table-pagination.js"></script>
</body>
</html>
