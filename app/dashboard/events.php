<?php
// ============================================================
//  EVENTS.PHP — Events & Activity Center
//  Full DB integration: real data, working modals, role-gated
//  Integrated with Philippine Holidays & Conflict Detection Engine
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_once __DIR__ . '/../shared/ph_holidays.php';
require_auth();
require_any_permission(['events.view', 'events.create.own', 'events.create.institutional', 'events.approve.admin']);

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;

// Load all Philippine Holidays for 2025, 2026, 2027
$ph_holidays_all = get_ph_holidays(2025) + get_ph_holidays(2026) + get_ph_holidays(2027);

// -- Fetch events from DB -------------------------------------
$events = $conn->query(
    "SELECT e.id, e.club_id, e.event_type, e.title, e.description, e.event_date, e.venue,
            e.expected_attendees, e.attachment, e.created_at,
            e.status, e.endorsement_notes, e.rejection_note, e.created_by,
            COALESCE(c.name, 'BCP Institutional / Campus-Wide') AS club_name,
            COALESCE(c.code, 'INSTITUTIONAL') AS club_code,
            COALESCE(c.adviser_name, 'Prof. BCP Faculty Adviser') AS adviser_name,
            u.first_name, u.last_name, u.role AS creator_role
     FROM events e
     LEFT JOIN clubs c ON c.id = e.club_id
     LEFT JOIN users u ON u.id = e.created_by
     WHERE e.deleted_at IS NULL
     ORDER BY e.event_date ASC"
)->fetch_all(MYSQLI_ASSOC);

$clubs = $conn->query("SELECT id, name, code FROM clubs WHERE status='Active' AND deleted_at IS NULL ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// For student role, filter out unposted / pending / rejected events
if ($sess_role === 'student') {
    $events = array_values(array_filter($events, function($ev) {
        return in_array($ev['status'], ['Approved', 'Upcoming', 'Completed']);
    }));
}

// Map dates & venues to detect venue collisions
$venue_date_map = [];
foreach ($events as $ev) {
    $dt = substr($ev['event_date'], 0, 10);
    $v = strtolower(trim($ev['venue']));
    if ($v && $ev['status'] !== 'Rejected') {
        $venue_date_map[$dt . '|' . $v] = ($venue_date_map[$dt . '|' . $v] ?? 0) + 1;
    }
}

// Statistics calculations
$total_approved      = 0;
$total_pending_ssc   = 0;
$total_pending_admin = 0;
$total_upcoming      = 0;
$today_str           = date('Y-m-d');
$now_ts              = time();

// 6 SSC Specific KPI Metrics
$ssc_pending_count        = 0;
$ssc_endorsed_month_count = 0;
$ssc_rejected_count       = 0;
$ssc_upcoming_count       = 0;
$ssc_institutional_count  = 0;
$ssc_venue_conflicts      = 0;

// 6 Admin Specific KPI Metrics (Matching exact prompt requirements)
$admin_pending_approval_count = 0; // Events that passed SSC review
$admin_approved_today_count   = 0; // Final approvals today
$admin_rejected_count         = 0; // Final rejections
$admin_upcoming_count         = 0; // Approved future events
$admin_venue_conflicts_count  = 0; // Scheduling issues
$admin_overdue_count          = 0; // Items exceeding configured review SLA (>= 7 days)

// Database query for final approvals today from audit logs
$r_appr_today = $conn->query("
    SELECT COUNT(DISTINCT target_id) AS cnt 
    FROM audit_logs 
    WHERE action IN ('event_admin_approve', 'event_admin_override') 
      AND DATE(created_at) = CURDATE()
");
$admin_approved_today_db = (int)($r_appr_today ? ($r_appr_today->fetch_assoc()['cnt'] ?? 0) : 0);
$admin_approved_today_loop = 0;

foreach ($events as &$ev) {
    $dt = substr($ev['event_date'], 0, 10);
    $v = strtolower(trim($ev['venue']));
    $ev_ts = strtotime($ev['event_date']);
    $sub_ts = !empty($ev['created_at']) ? strtotime($ev['created_at']) : $ev_ts;

    $ev['has_venue_conflict'] = ($v && ($venue_date_map[$dt . '|' . $v] ?? 0) > 1);
    $ev['days_pending'] = max(0, floor(($now_ts - $sub_ts) / 86400));
    $ev['event_ref_id'] = 'EVT-' . date('Y', $ev_ts) . '-' . str_pad($ev['id'], 4, '0', STR_PAD_LEFT);

    if ($ev['status'] === 'Approved' || $ev['status'] === 'Completed') {
        $total_approved++;
    } elseif ($ev['status'] === 'Pending SSC' || $ev['status'] === 'Pending OSA') {
        $total_pending_ssc++;
    } elseif ($ev['status'] === 'Pending Admin') {
        $total_pending_admin++;
    }
    if ($dt >= $today_str && $ev['status'] !== 'Rejected') {
        $total_upcoming++;
    }

    // SSC 6 Metrics calculation
    if (in_array($ev['status'], ['Pending SSC', 'Pending OSA'])) {
        $ssc_pending_count++;
    }
    if (in_array($ev['status'], ['Pending Admin', 'Approved']) && (strpos($ev['endorsement_notes'] ?? '', 'Endorsed by SSC') !== false || $ev['status'] === 'Pending Admin')) {
        $ssc_endorsed_month_count++;
    }
    if (in_array($ev['status'], ['Rejected', 'Returned'])) {
        $ssc_rejected_count++;
    }
    if ($ev['status'] === 'Approved' && $ev_ts >= $now_ts) {
        $ssc_upcoming_count++;
    }
    if ($ev['event_type'] === 'Institutional') {
        $ssc_institutional_count++;
    }
    if ($ev['has_venue_conflict'] && in_array($ev['status'], ['Pending SSC', 'Pending Admin', 'Approved', 'Upcoming'])) {
        $ssc_venue_conflicts++;
    }

    // Admin 6 Metrics calculation
    if ($ev['status'] === 'Pending Admin') {
        $admin_pending_approval_count++;
    }
    if ($ev['status'] === 'Approved' && substr($ev['created_at'] ?? '', 0, 10) === $today_str) {
        $admin_approved_today_loop++;
    }
    if ($ev['status'] === 'Rejected') {
        $admin_rejected_count++;
    }
    if ($ev['status'] === 'Approved' && $ev_ts >= $now_ts) {
        $admin_upcoming_count++;
    }
    if ($ev['has_venue_conflict'] && in_array($ev['status'], ['Pending Admin', 'Pending SSC', 'Approved', 'Upcoming'])) {
        $admin_venue_conflicts_count++;
    }
    if ($ev['days_pending'] >= 7 && in_array($ev['status'], ['Pending Admin', 'Pending SSC'])) {
        $admin_overdue_count++;
    }
}
unset($ev);
$admin_approved_today_count = max($admin_approved_today_db, $admin_approved_today_loop);

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
    'Approved'      => 'badge-active',
    'Completed'     => 'badge-info',
    'Upcoming'      => 'badge-info',
    'Pending SSC'   => 'badge-warning',
    'Pending OSA'   => 'badge-warning',
    'Pending Admin' => 'badge-info',
    'Returned'      => 'badge-warning',
    'Rejected'      => 'badge-inactive',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Events — BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/system-notifications.js?v=<?= filemtime(__DIR__ . '/../js/system-notifications.js') ?>"></script>
  <script src="../js/page-loader.js"></script>
  <!-- jsPDF & AutoTable for direct client-side PDF downloads -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
  <script src="../js/qrcode.min.js"></script>
  <style>
  /* ── Event Table Action Buttons ───────────────────────────── */
  .event-act-group {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex-wrap: nowrap;
    white-space: nowrap;
  }
  .event-act-btn {
    height: 30px;
    padding: 0 11px;
    font-size: 0.76rem;
    font-weight: 600;
    border-radius: 7px;
    border: 1px solid transparent;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.15s ease;
    white-space: nowrap;
    text-decoration: none;
    line-height: 1;
  }
  .event-act-btn-locate {
    background: #eef2ff;
    color: #4f46e5;
    border-color: #e0e7ff;
  }
  .event-act-btn-locate:hover {
    background: #4f46e5;
    color: #ffffff;
    border-color: #4f46e5;
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.2);
  }
  .event-act-btn-details {
    background: #f1f5f9;
    color: #334155;
    border-color: #cbd5e1;
  }
  .event-act-btn-details:hover {
    background: #1e293b;
    color: #ffffff;
    border-color: #1e293b;
    box-shadow: 0 2px 8px rgba(30, 41, 59, 0.2);
  }
  .event-act-btn-qr {
    background: #059669;
    color: #ffffff;
    border-color: #059669;
  }
  .event-act-btn-qr:hover {
    background: #047857;
    border-color: #047857;
    box-shadow: 0 2px 8px rgba(5, 150, 105, 0.25);
  }
  .event-act-btn-reg {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
  }
  .event-act-btn-reg:hover {
    background: #1d4ed8;
    border-color: #1d4ed8;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.2);
  }
  .event-act-btn-endorse {
    background: #16a34a;
    color: #ffffff;
    border-color: #16a34a;
  }
  .event-act-btn-endorse:hover {
    background: #15803d;
  }
  .event-act-btn-reject {
    background: #dc2626;
    color: #ffffff;
    border-color: #dc2626;
  }
  .event-act-btn-reject:hover {
    background: #b91c1c;
  }
  .event-act-btn-edit {
    background: #d97706;
    color: #ffffff;
    border-color: #d97706;
  }
  .event-act-btn-edit:hover {
    background: #b45309;
  }

  /* ── Admin Clearance Pipeline Pagination Toolbar ───────────── */
  #adminEventQueueCard .pagination-toolbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: wrap !important;
    gap: 12px !important;
    padding: 14px 6px 4px !important;
    margin-top: 10px !important;
    border-top: 1px solid #f1f5f9 !important;
  }
  #adminEventQueueCard .pagination-info {
    display: none !important;
  }
  #adminEventQueueCard .pagination-controls {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 14px !important;
    flex-wrap: wrap !important;
    width: auto !important;
  }

  /* ── Admin Clearance Pipeline Table: Compact & Fluid (No Side-Scrolling) ── */
  #adminEventQueueTable {
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    table-layout: fixed !important;
    border-collapse: separate;
    border-spacing: 0;
  }
  #adminEventQueueTable th,
  #adminEventQueueTable td {
    padding: 7px 8px !important;
    font-size: 0.77rem !important;
    vertical-align: middle !important;
    word-break: break-word;
  }
  #adminEventQueueTable th {
    padding: 8px 8px !important;
    font-size: 0.70rem !important;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #475569;
    white-space: nowrap;
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
  }
  #adminEventQueueTable .club-badge {
    font-size: 0.66rem !important;
    padding: 1px 5px !important;
    font-weight: 700;
  }
  #adminEventQueueTable .badge-active,
  #adminEventQueueTable .badge-inactive,
  #adminEventQueueTable .badge-warning,
  #adminEventQueueTable .badge-info,
  #adminEventQueueTable .badge-purple,
  #adminEventQueueTable .badge-danger {
    font-size: 0.66rem !important;
    padding: 2px 5px !important;
    line-height: 1.2 !important;
    border-radius: 4px;
    white-space: nowrap;
  }
  #adminEventQueueTable .event-act-group {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 3px !important;
    flex-wrap: nowrap !important;
    width: 100%;
  }
  #adminEventQueueTable .admin-tbl-act-btn {
    width: 26px;
    height: 26px;
    padding: 0;
    border-radius: 5px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    border: none;
    cursor: pointer;
    transition: all 0.15s ease;
    flex-shrink: 0;
    text-decoration: none;
    line-height: 1;
  }
  #adminEventQueueTable .admin-tbl-act-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
    filter: brightness(1.1);
  }
  #adminEventQueueTable .admin-tbl-act-btn:disabled,
  #adminEventQueueTable .admin-tbl-act-btn.btn-disabled {
    background: #e2e8f0 !important;
    color: #94a3b8 !important;
    border: 1px solid #cbd5e1 !important;
    cursor: not-allowed !important;
    opacity: 0.65 !important;
    transform: none !important;
    box-shadow: none !important;
  }
  #adminEventQueueTable .admin-tbl-act-btn:disabled:hover,
  #adminEventQueueTable .admin-tbl-act-btn.btn-disabled:hover {
    transform: none !important;
    box-shadow: none !important;
    filter: none !important;
  }

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
  /* AI Proposal Grid & Cards - 3 Aligned in a Row, Perfectly Fitted */
  .ai-plans-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-top: 8px;
    width: 100%;
    box-sizing: border-box;
  }
  .ai-plan-card {
    background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px;
    padding: 12px 14px; display: flex; flex-direction: column; justify-content: space-between;
    transition: all 0.25s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.03); min-height: 200px;
    min-width: 0; width: 100%; box-sizing: border-box; overflow: hidden;
  }
  .ai-plan-card:hover { border-color: #3b82f6; background: #ffffff; box-shadow: 0 6px 20px rgba(37,99,235,0.1); transform: translateY(-2px); }
  .ai-plan-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: 6px; min-width: 0; }
  .ai-plan-title {
    font-size: 0.85rem; font-weight: 700; color: #0f172a; line-height: 1.3;
    overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; min-width: 0; word-break: break-word;
  }
  .ai-score-pill {
    padding: 2px 6px; border-radius: 5px; font-size: 0.68rem; font-weight: 700;
    background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; white-space: nowrap; flex-shrink: 0;
  }
  .ai-plan-meta { display: flex; flex-direction: column; gap: 4px; margin-bottom: 8px; min-width: 0; }
  .ai-meta-tag {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 2px 7px; border-radius: 5px; font-size: 0.7rem; font-weight: 600;
    background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;
    width: fit-content; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; box-sizing: border-box;
  }
  .ai-plan-desc {
    font-size: 0.73rem; color: #475569; line-height: 1.35; margin-bottom: 8px;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-word;
  }
  .ai-conflict-box {
    background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px;
    padding: 5px 8px; font-size: 0.7rem; color: #166534; margin-bottom: 8px;
    display: flex; align-items: center; gap: 6px; overflow: hidden; box-sizing: border-box; min-width: 0;
  }
  .ai-conflict-box span {
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0;
  }
  .ai-apply-plan-btn {
    width: 100%; padding: 7px 12px; border-radius: 7px; font-size: 0.78rem; font-weight: 700;
    background: #16a34a; color: #fff; border: none; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: 5px;
    transition: all 0.2s; box-sizing: border-box;
  }
  .ai-apply-plan-btn:hover { background: #15803d; box-shadow: 0 3px 8px rgba(22,163,74,0.3); }
  .ai-error-msg {
    padding: 12px 16px; background: #fef2f2; border: 1px solid #fca5a5;
    border-radius: 8px; color: #dc2626; font-size: 0.82rem; font-weight: 600;
    display: flex; align-items: center; gap: 8px; box-sizing: border-box;
  }
  @media (max-width: 860px) {
    .ai-plans-grid { grid-template-columns: 1fr; }
  }

  /* ── AI Event Planner Button (Normal Action Button) ────────── */
  .ai-fab-btn {
    display: none !important;
  }
  .btn-ai-planner {
    background: #2563eb !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    padding: 8px 14px !important;
    border-radius: 8px !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    font-size: 0.82rem !important;
    border: none !important;
    cursor: pointer !important;
    transition: background 0.15s ease, transform 0.15s ease !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
  }
  .btn-ai-planner:hover {
    background: #1d4ed8 !important;
    color: #ffffff !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 3px 8px rgba(37, 99, 235, 0.25) !important;
  }
  .btn-ai-planner:active {
    transform: translateY(0) !important;
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
  .form-grid-2 {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 16px;
    width: 100%;
    box-sizing: border-box;
  }
  .form-grid-2 .form-group {
    min-width: 0;
    margin-bottom: 16px;
  }
  @media (max-width: 580px) {
    .form-grid-2 {
      grid-template-columns: 1fr !important;
      gap: 0 !important;
    }
  }

  /* Events View Switcher Tabs */
  .events-view-switcher {
    display: inline-flex;
    background: #e2e8f0;
    padding: 4px;
    border-radius: 10px;
    gap: 4px;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.06);
  }
  .view-toggle-btn {
    border: none;
    background: transparent;
    padding: 8px 16px;
    border-radius: 7px;
    font-size: 0.85rem;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  }
  .view-toggle-btn:hover {
    color: #1e293b;
    background: rgba(255, 255, 255, 0.6);
  }
  .view-toggle-btn.active {
    background: #ffffff;
    color: #1a3a8c;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
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
  .calendar-header-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
  }
  .cal-search-box {
    position: relative;
    display: inline-flex;
    align-items: center;
    background: rgba(255, 255, 255, 0.16);
    border: 1.5px solid rgba(255, 255, 255, 0.32);
    border-radius: 20px;
    padding: 0 12px;
    height: 36px;
    width: 230px;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    box-sizing: border-box;
  }
  .cal-search-box:focus-within {
    background: #ffffff;
    border-color: #ffffff;
    box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.35), 0 4px 14px rgba(0, 0, 0, 0.15);
    width: 270px;
  }
  .cal-search-box .cal-search-icon {
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.82rem;
    margin-right: 8px;
    flex-shrink: 0;
  }
  .cal-search-box:focus-within .cal-search-icon {
    color: #1a3a8c;
  }
  .cal-search-box input {
    background: transparent;
    border: none;
    outline: none;
    color: #ffffff;
    font-size: 0.82rem;
    font-weight: 500;
    width: 100%;
    font-family: inherit;
    padding: 0;
  }
  .cal-search-box:focus-within input {
    color: #0f172a;
  }
  .cal-search-box input::placeholder {
    color: rgba(255, 255, 255, 0.7);
  }
  .cal-search-box:focus-within input::placeholder {
    color: #94a3b8;
  }
  .cal-search-clear {
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.8);
    cursor: pointer;
    padding: 2px 4px;
    font-size: 0.8rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
  .cal-search-box:focus-within .cal-search-clear {
    color: #64748b;
  }
  .cal-search-clear:hover {
    color: #ef4444 !important;
  }
  .cal-search-results-dropdown {
    position: absolute;
    top: 42px;
    left: 0;
    width: 320px;
    max-width: 90vw;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    z-index: 100;
    max-height: 260px;
    overflow-y: auto;
    display: none;
  }
  .cal-search-result-item {
    padding: 9px 12px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    font-size: 0.8rem;
    color: #1e293b;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    transition: background 0.15s;
  }
  .cal-search-result-item:last-child {
    border-bottom: none;
  }
  .cal-search-result-item:hover {
    background: #eff6ff;
  }
  .cal-search-result-title {
    font-weight: 700;
    color: #1a3a8c;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cal-search-result-date {
    font-size: 0.72rem;
    color: #64748b;
    white-space: nowrap;
  }

  /* Search active highlights on calendar */
  .cal-pill-dimmed {
    opacity: 0.18 !important;
    filter: grayscale(0.85) !important;
  }
  .cal-pill-matched {
    box-shadow: 0 0 0 2px #facc15, 0 2px 8px rgba(0, 0, 0, 0.25) !important;
    transform: scale(1.05) !important;
    font-weight: 900 !important;
  }
  .cal-cell-matched {
    background: #eff6ff !important;
    border: 2px solid #2563eb !important;
  }
  .cal-cell-dimmed {
    opacity: 0.4;
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
    .event-act-group {
      display: flex !important;
      flex-wrap: wrap !important;
      gap: 6px !important;
      width: 100% !important;
      justify-content: flex-end !important;
    }
    .event-act-btn {
      flex: 1 1 auto !important;
      justify-content: center !important;
      min-height: 32px !important;
    }
  }
  @media (max-width: 640px) {
    .calendar-header-right {
      width: 100%;
      justify-content: space-between;
      gap: 8px;
    }
    .cal-search-box {
      width: 100%;
      flex: 1 1 160px;
      min-width: 130px;
    }
    .cal-search-box:focus-within {
      width: 100%;
    }
    .cal-month-label {
      min-width: 105px;
      font-size: 0.84rem;
    }
  }
  @media (max-width: 560px) {
    .calendar-body { padding: 12px; }
    .calendar-header { padding: 14px 16px; }
    .calendar-legend { padding: 10px 16px; gap: 10px; }
  }

  /* SSC Events & Activities Custom Styling */
  .ssc-metric-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    border: 1px solid #e2e8f0;
  }
  .ssc-metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
    border-color: #93c5fd;
  }
  .ssc-metric-card.active-card-filter {
    border: 2px solid #2563eb !important;
    background: #f0f7ff !important;
  }
  .ssc-review-grid {
    display: grid;
    grid-template-columns: 1.1fr 1fr;
    gap: 22px;
  }
  @media (max-width: 860px) {
    .ssc-review-grid {
      grid-template-columns: 1fr;
    }
  }
  .ssc-checklist-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
  }
  .ssc-checklist-item:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
  }
  .ssc-checklist-item input[type="checkbox"] {
    margin-top: 3px;
    width: 17px;
    height: 17px;
    accent-color: #16a34a;
    cursor: pointer;
  }
  .ssc-checklist-label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #1e293b;
    line-height: 1.35;
  }
  .ssc-detail-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 8px 0;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.84rem;
  }
  .ssc-detail-label {
    color: #64748b;
    font-weight: 600;
    width: 38%;
    flex-shrink: 0;
  }
  .ssc-detail-val {
    color: #1e293b;
    font-weight: 600;
    text-align: right;
    width: 62%;
    word-break: break-word;
  }
  </style>
</head>
<body>
<?php
$events_view = $_GET['view'] ?? 'calendar';
$APP_ROOT = '../';
$ACTIVE_NAV = 'events';
$ACTIVE_SUB = $events_view;
require_once __DIR__ . '/../shared/sidebar.php';
?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap" id="topbarSearchWrap">
        <i class="fa-solid fa-magnifying-glass search-icon"></i>
        <input type="text" placeholder="Search modules, events, clubs..." autocomplete="off" />
        <button type="button" class="search-clear-btn" aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>
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
    <div class="page-title-bar" style="margin-bottom:20px;">
      <h2 class="page-title" style="margin:0;">
        <i class="fa-solid fa-calendar-days"></i>
        Events
      </h2>
    </div>

    <div class="content-body">

      <!-- ----------------------------------------------------------
           ACTIVE INTERACTIVE EVENT CALENDAR
      ---------------------------------------------------------- -->
      <div class="calendar-section" id="activeCalendarSection" <?= ($sess_role !== 'student' && $events_view === 'pipeline') ? 'style="display:none;"' : '' ?>>
        <!-- Header -->
        <div class="calendar-header">
          <div class="calendar-header-left">
            <h3><i class="fa-solid fa-calendar-days"></i> Active Campus Event Calendar</h3>
            <p>Click any highlighted date or event pill to view details &amp; register</p>
          </div>
          <div class="calendar-header-right">
            <div class="cal-search-box" id="calSearchBox">
              <i class="fa-solid fa-magnifying-glass cal-search-icon"></i>
              <input type="text" id="calSearchInput" placeholder="Search calendar events..." autocomplete="off" />
              <button type="button" id="calSearchClear" class="cal-search-clear" title="Clear search" style="display:none;"><i class="fa-solid fa-xmark"></i></button>
              <div class="cal-search-results-dropdown" id="calSearchResults"></div>
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

      <?php if ($sess_role === 'ssc'): ?>
      <div id="sscPipelineSection" <?= ($events_view === 'calendar') ? 'style="display:none;"' : '' ?>>
        <!-- ──────────────────────────────────────────────────────────
             1. SSC EVENT DASHBOARD CARDS (6 Metrics)
        ────────────────────────────────────────────────────────── -->
        <div class="info-row" style="grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin-bottom: 22px;">
          <!-- 1. Pending SSC Review -->
          <div class="info-card ssc-metric-card" id="cardPendingSsc" onclick="filterSscByCard('Pending SSC')" style="cursor:pointer;" title="Click to filter queue by Pending SSC Review">
            <div class="card-label"><i class="fa-solid fa-clock-rotate-left" style="color:#d97706;"></i> Pending SSC Review</div>
            <div class="card-amount" style="color:#d97706;"><?= $ssc_pending_count ?></div>
            <div class="card-detail">Events waiting for SSC action.</div>
          </div>
          <!-- 2. Endorsed This Month -->
          <div class="info-card ssc-metric-card" id="cardEndorsedMonth" onclick="filterSscByCard('Pending Admin')" style="cursor:pointer;" title="Click to filter queue by Endorsed events">
            <div class="card-label"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Endorsed This Month</div>
            <div class="card-amount" style="color:#16a34a;"><?= $ssc_endorsed_month_count ?></div>
            <div class="card-detail">Events endorsed by SSC in selected period.</div>
          </div>
          <!-- 3. Rejected Events -->
          <div class="info-card ssc-metric-card" id="cardRejectedEvents" onclick="filterSscByCard('Rejected_Returned')" style="cursor:pointer;" title="Click to filter queue by Rejected / Returned events">
            <div class="card-label"><i class="fa-solid fa-ban" style="color:#dc2626;"></i> Rejected Events</div>
            <div class="card-amount" style="color:#dc2626;"><?= $ssc_rejected_count ?></div>
            <div class="card-detail">Events rejected / returned for revision.</div>
          </div>
          <!-- 4. Upcoming Events -->
          <div class="info-card ssc-metric-card" id="cardUpcomingEvents" onclick="filterSscByCard('Approved')" style="cursor:pointer;" title="Click to filter queue by Approved & Upcoming events">
            <div class="card-label"><i class="fa-solid fa-calendar-check" style="color:#2563eb;"></i> Upcoming Events</div>
            <div class="card-amount" style="color:#2563eb;"><?= $ssc_upcoming_count ?></div>
            <div class="card-detail">Approved future activities.</div>
          </div>
          <!-- 5. Institutional Events -->
          <div class="info-card ssc-metric-card" id="cardInstitutionalEvents" onclick="filterSscByCard('Institutional')" style="cursor:pointer;" title="Click to filter queue by Institutional events">
            <div class="card-label"><i class="fa-solid fa-building-columns" style="color:#7c3aed;"></i> Institutional Events</div>
            <div class="card-amount" style="color:#7c3aed;"><?= $ssc_institutional_count ?></div>
            <div class="card-detail">School-wide or council-level activities.</div>
          </div>
          <!-- 6. Venue Conflicts -->
          <div class="info-card ssc-metric-card" id="cardVenueConflicts" onclick="filterSscByCard('Conflict')" style="cursor:pointer;" title="Click to filter queue by Potential Venue Conflicts">
            <div class="card-label"><i class="fa-solid fa-triangle-exclamation" style="color:#ea580c;"></i> Venue Conflicts</div>
            <div class="card-amount" style="color:#ea580c;"><?= $ssc_venue_conflicts ?></div>
            <div class="card-detail">Potential scheduling conflicts needing attention.</div>
          </div>
        </div>

        <!-- ──────────────────────────────────────────────────────────
             2. EVENT APPROVAL QUEUE (11 Columns, Search, Filter, 5 items/page Pagination)
        ────────────────────────────────────────────────────────── -->
        <div class="card" id="sscApprovalQueueCard">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
          <div>
            <h3 style="margin:0; font-size:1.15rem; color:#1e293b; display:flex; align-items:center; gap:8px;">
              <i class="fa-solid fa-list-check" style="color:#2563eb;"></i> Event Approval Queue
              <?php if ($ssc_pending_count > 0): ?>
                <span style="background:#fef3c7; color:#d97706; font-size:0.75rem; padding:3px 10px; border-radius:12px; font-weight:700;">
                  <?= $ssc_pending_count ?> Pending SSC Review
                </span>
              <?php endif; ?>
            </h3>
          </div>
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <?php if (can_any(['events.create.own', 'events.create.institutional'])): ?>
              <button type="button" class="card-btn btn-sm btn-ai-planner" onclick="openModal('aiPlannerModal')" title="Open AI Event Planner &amp; Schedule Conflict Optimizer" aria-label="Open AI Event Planner">
                <i class="fa-solid fa-wand-magic-sparkles"></i> AI Event Planner
              </button>
            <?php endif; ?>
            <button type="button" class="card-btn btn-sm" id="openCreateEventSsc" style="background:#16a34a; color:#fff; font-weight:700; padding:8px 14px; border-radius:8px;" onclick="openCreateEventModal()" title="Create Institutional / Council Event Proposal">
              <i class="fa-solid fa-calendar-plus"></i> Create Institutional Proposal
            </button>
          </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div style="display:flex; flex-wrap:wrap; gap:12px; margin-bottom:16px; align-items:center; background:#f8fafc; padding:12px 14px; border-radius:8px; border:1px solid #e2e8f0;">
          <!-- Search Input -->
          <div style="flex:1; min-width:220px; position:relative;">
            <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.85rem;"></i>
            <input type="text" id="sscQueueSearchInput" placeholder="Search event title, ID, organization, venue, submitted by..." onkeyup="filterSscApprovalQueue()" style="width:100%; padding:8px 12px 8px 34px; border-radius:6px; border:1px solid #cbd5e1; font-size:0.85rem; box-sizing:border-box;" />
          </div>

          <!-- Status Filter -->
          <div style="min-width:160px;">
            <select id="sscQueueStatusFilter" onchange="filterSscApprovalQueue()" style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid #cbd5e1; font-size:0.85rem; background:#fff; cursor:pointer;">
              <option value="">All Statuses</option>
              <option value="Pending SSC">Pending SSC Review</option>
              <option value="Pending Admin">Endorsed to Admin</option>
              <option value="Approved">Approved</option>
              <option value="Returned">Returned for Revision</option>
              <option value="Rejected">Rejected</option>
            </select>
          </div>

          <!-- Type Filter -->
          <div style="min-width:140px;">
            <select id="sscQueueTypeFilter" onchange="filterSscApprovalQueue()" style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid #cbd5e1; font-size:0.85rem; background:#fff; cursor:pointer;">
              <option value="">All Event Types</option>
              <option value="Club">Club Event</option>
              <option value="Institutional">Institutional</option>
            </select>
          </div>

          <!-- Reset Filter Button -->
          <div>
            <button type="button" onclick="resetSscQueueFilter()" class="card-btn btn-sm" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-size:0.82rem; padding:7px 12px; border-radius:6px; cursor:pointer;">
              <i class="fa-solid fa-rotate-left"></i> Reset
            </button>
          </div>
        </div>

        <!-- Active filter alert notice if filtering by conflicts -->
        <div id="sscConflictNoticeBar" style="display:none; margin-bottom:14px; background:#fff7ed; border:1px solid #fdba74; color:#c2410c; padding:8px 14px; border-radius:8px; font-size:0.82rem; justify-content:space-between; align-items:center;">
          <span><i class="fa-solid fa-triangle-exclamation"></i> Filtering by <strong>Venue Conflicts &amp; Overlaps</strong></span>
          <button type="button" onclick="resetSscQueueFilter()" style="background:none; border:none; color:#c2410c; cursor:pointer; font-weight:700;"><i class="fa-solid fa-xmark"></i> Clear</button>
        </div>

        <!-- Table Wrap -->
        <div class="table-wrap">
          <table id="sscApprovalQueueTable" class="table-wide data-table resp-table mobile-card-table">
            <thead>
              <tr>
                <th>Event ID</th>
                <th>Event Title</th>
                <th>Organization</th>
                <th>Event Type</th>
                <th>Date</th>
                <th>Venue</th>
                <th>Submitted By</th>
                <th>Submitted Date</th>
                <th>Status</th>
                <th>Days Pending</th>
                <th style="text-align:right; min-width:140px;">Action</th>
              </tr>
            </thead>
            <tbody id="sscApprovalQueueBody">
              <?php if (empty($events)): ?>
                <tr id="sscQueueEmptyRow">
                  <td colspan="11" class="empty-state-cell" style="text-align:center; padding:36px 16px; color:#94a3b8;">
                    <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; width:100%; margin:0 auto;">
                      <i class="fa-solid fa-calendar-xmark" style="font-size:2.2rem; margin-bottom:10px; display:inline-block; color:#94a3b8;"></i>
                      <span style="font-weight:600; font-size:0.9rem; color:#475569; text-align:center;">No event proposals in queue.</span>
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <tr id="sscQueueEmptyRow" style="display:none;">
                  <td colspan="11" class="empty-state-cell" style="text-align:center; padding:36px 16px; color:#94a3b8;">
                    <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; width:100%; margin:0 auto;">
                      <i class="fa-solid fa-magnifying-glass" style="font-size:2rem; margin-bottom:8px; display:inline-block; color:#94a3b8;"></i>
                      <span style="font-weight:600; font-size:0.9rem; color:#475569; text-align:center;">No events match the current filter criteria.</span>
                    </div>
                  </td>
                </tr>
                <?php foreach ($events as $ev): 
                  $ev_ts = strtotime($ev['event_date']);
                  $sub_ts = !empty($ev['created_at']) ? strtotime($ev['created_at']) : $ev_ts;
                  $dp = (int)$ev['days_pending'];
                  $is_pending = in_array($ev['status'], ['Pending SSC', 'Pending OSA']);
                ?>
                  <tr class="queue-row ssc-queue-row" 
                      id="ssc-event-row-<?= $ev['id'] ?>"
                      data-id="<?= $ev['id'] ?>"
                      data-status="<?= htmlspecialchars($ev['status']) ?>"
                      data-type="<?= htmlspecialchars($ev['event_type']) ?>"
                      data-conflict="<?= $ev['has_venue_conflict'] ? '1' : '0' ?>"
                      data-date="<?= date('Y-m-d', $ev_ts) ?>">
                    <!-- 1. Event ID -->
                    <td data-label="Event ID">
                      <span style="font-family:monospace; font-weight:700; color:#2563eb; font-size:0.82rem;"><?= htmlspecialchars($ev['event_ref_id']) ?></span>
                    </td>
                    <!-- 2. Event Title -->
                    <td data-label="Event Title">
                      <strong><?= htmlspecialchars($ev['title']) ?></strong>
                      <?php if ($ev['has_venue_conflict']): ?>
                        <div>
                          <span class="badge-danger" style="display:inline-block; font-size:0.68rem; padding:2px 6px; border-radius:4px; margin-top:3px; background:#fef2f2; color:#b91c1c; border:1px solid #fca5a5;">
                            <i class="fa-solid fa-triangle-exclamation"></i> Venue Conflict
                          </span>
                        </div>
                      <?php endif; ?>
                      <?php if ($ev['rejection_note']): ?>
                        <div style="font-size:0.72rem; color:#dc2626; margin-top:2px;">
                          <i class="fa-solid fa-comment-dots"></i> <?= htmlspecialchars($ev['rejection_note']) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <!-- 3. Organization -->
                    <td data-label="Organization">
                      <span class="club-badge"><?= htmlspecialchars($ev['club_code']) ?></span>
                      <span style="font-size:0.82rem; color:#334155;"><?= htmlspecialchars($ev['club_name']) ?></span>
                    </td>
                    <!-- 4. Event Type -->
                    <td data-label="Event Type">
                      <span class="badge-<?= $ev['event_type'] === 'Institutional' ? 'purple' : 'info' ?>" style="font-size:0.75rem; font-weight:700; padding:3px 8px; border-radius:4px;">
                        <?= htmlspecialchars($ev['event_type']) ?>
                      </span>
                    </td>
                    <!-- 5. Date -->
                    <td data-label="Date" style="font-size:0.82rem;">
                      <?= date('M d, Y', $ev_ts) ?><br>
                      <span style="color:#64748b; font-size:0.76rem;"><?= date('h:i A', $ev_ts) ?></span>
                    </td>
                    <!-- 6. Venue -->
                    <td data-label="Venue" style="font-size:0.82rem;">
                      <?= htmlspecialchars($ev['venue']) ?>
                      <?php if ($ev['has_venue_conflict']): ?>
                        <div style="font-size:0.72rem; color:#ea580c; font-weight:700;">
                          <i class="fa-solid fa-triangle-exclamation"></i> Overlapping Venue
                        </div>
                      <?php endif; ?>
                    </td>
                    <!-- 7. Submitted By -->
                    <td data-label="Submitted By" style="font-size:0.82rem;">
                      <?= htmlspecialchars(trim(($ev['first_name'] ?? '') . ' ' . ($ev['last_name'] ?? '')) ?: 'Adviser / Submitter') ?><br>
                      <span style="font-size:0.72rem; color:#64748b;"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $ev['creator_role'] ?? 'adviser'))) ?></span>
                    </td>
                    <!-- 8. Submitted Date -->
                    <td data-label="Submitted Date" style="font-size:0.82rem; color:#475569;">
                      <?= date('M d, Y', $sub_ts) ?>
                    </td>
                    <!-- 9. Status -->
                    <td data-label="Status">
                      <span class="<?= $status_badges[$ev['status']] ?? 'badge-info' ?>" style="font-size:0.75rem; font-weight:700;">
                        <?= htmlspecialchars($ev['status']) ?>
                      </span>
                    </td>
                    <!-- 10. Days Pending -->
                    <td data-label="Days Pending">
                      <?php if ($is_pending): ?>
                        <?php if ($dp === 0): ?>
                          <span style="font-weight:700; color:#16a34a; font-size:0.8rem;">Today</span>
                        <?php else: ?>
                          <span style="font-weight:700; color:<?= $dp > 5 ? '#dc2626' : '#d97706' ?>; font-size:0.8rem;">
                            <?= $dp ?> <?= $dp === 1 ? 'day' : 'days' ?>
                          </span>
                        <?php endif; ?>
                      <?php else: ?>
                        <span style="color:#94a3b8; font-size:0.8rem;">—</span>
                      <?php endif; ?>
                    </td>
                    <!-- 11. Action -->
                    <td data-label="Action" style="text-align:right; white-space:nowrap;">
                      <div style="display:inline-flex; gap:4px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
                        <!-- SSC Review Button (Opens 3-in-1 Review Panel Modal) -->
                        <button type="button" class="card-btn btn-sm" style="background:#2563eb; color:#fff; font-weight:700; padding:6px 10px; font-size:0.78rem; display:inline-flex; align-items:center; gap:5px;" onclick="openSscReviewPanel(<?= htmlspecialchars(json_encode($ev)) ?>)" title="Open SSC Event Review Panel">
                          <i class="fa-solid fa-clipboard-check"></i> Review
                        </button>
                        <?php if ($is_pending): ?>
                          <button type="button" class="card-btn btn-sm" style="background:#16a34a; color:#fff; font-weight:700; padding:6px 8px; font-size:0.78rem;" onclick="quickSscEndorse(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')" title="Quick Endorse to Admin">
                            <i class="fa-solid fa-check"></i>
                          </button>
                          <button type="button" class="card-btn btn-sm" style="background:#f59e0b; color:#fff; font-weight:700; padding:6px 8px; font-size:0.78rem;" onclick="openReturnEventModal(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')" title="Return for Revision">
                            <i class="fa-solid fa-rotate-left"></i>
                          </button>
                          <button type="button" class="card-btn btn-sm" style="background:#dc2626; color:#fff; font-weight:700; padding:6px 8px; font-size:0.78rem;" onclick="quickSscReject(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')" title="Reject Event">
                            <i class="fa-solid fa-xmark"></i>
                          </button>
                        <?php else: ?>
                          <button type="button" class="card-btn btn-sm btn-disabled" disabled style="background:#e2e8f0; color:#94a3b8; padding:6px 8px; font-size:0.78rem; cursor:not-allowed;" title="Endorse Unavailable (Status: <?= htmlspecialchars($ev['status']) ?>)">
                            <i class="fa-solid fa-check"></i>
                          </button>
                          <button type="button" class="card-btn btn-sm btn-disabled" disabled style="background:#e2e8f0; color:#94a3b8; padding:6px 8px; font-size:0.78rem; cursor:not-allowed;" title="Return Unavailable (Status: <?= htmlspecialchars($ev['status']) ?>)">
                            <i class="fa-solid fa-rotate-left"></i>
                          </button>
                          <button type="button" class="card-btn btn-sm btn-disabled" disabled style="background:#e2e8f0; color:#94a3b8; padding:6px 8px; font-size:0.78rem; cursor:not-allowed;" title="Reject Unavailable (Status: <?= htmlspecialchars($ev['status']) ?>)">
                            <i class="fa-solid fa-xmark"></i>
                          </button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      </div>
      <?php endif; ?>

      <?php if ($sess_role === 'admin'): ?>
      <div id="adminPipelineSection" <?= ($events_view === 'calendar') ? 'style="display:none;"' : '' ?>>
        <!-- ──────────────────────────────────────────────────────────
             ADMIN EVENT ADMINISTRATION CARDS (6 Metrics)
             1. Pending Admin Approval: Events that passed SSC review.
             2. Approved Today: Final approvals today.
             3. Rejected: Final rejections.
             4. Upcoming: Approved future events.
             5. Venue Conflicts: Scheduling issues.
             6. Overdue Proposals: Items exceeding configured review SLA.
        ────────────────────────────────────────────────────────── -->
        <div class="info-row" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 22px;">
          <!-- 1. Pending Admin Approval -->
          <div class="info-card admin-metric-card" id="cardAdminPendingApproval" onclick="filterAdminByCard('Pending Admin')" style="cursor:pointer; transition:all 0.2s ease;" title="Click to filter by Pending Admin Approval (Passed SSC review)">
            <div class="card-label"><i class="fa-solid fa-hourglass-half" style="color:#d97706;"></i> Pending Admin Approval</div>
            <div class="card-amount" style="color:#d97706;"><?= $admin_pending_approval_count ?></div>
            <div class="card-detail">Events that passed SSC review.</div>
          </div>
          <!-- 2. Approved Today -->
          <div class="info-card admin-metric-card" id="cardAdminApprovedToday" onclick="filterAdminByCard('Approved Today')" style="cursor:pointer; transition:all 0.2s ease;" title="Click to filter by Final approvals today">
            <div class="card-label"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Approved Today</div>
            <div class="card-amount" style="color:#16a34a;"><?= $admin_approved_today_count ?></div>
            <div class="card-detail">Final approvals today.</div>
          </div>
          <!-- 3. Rejected -->
          <div class="info-card admin-metric-card" id="cardAdminRejected" onclick="filterAdminByCard('Rejected')" style="cursor:pointer; transition:all 0.2s ease;" title="Click to filter by Final rejections">
            <div class="card-label"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Rejected</div>
            <div class="card-amount" style="color:#dc2626;"><?= $admin_rejected_count ?></div>
            <div class="card-detail">Final rejections.</div>
          </div>
          <!-- 4. Upcoming -->
          <div class="info-card admin-metric-card" id="cardAdminUpcoming" onclick="filterAdminByCard('Upcoming')" style="cursor:pointer; transition:all 0.2s ease;" title="Click to filter by Approved future events">
            <div class="card-label"><i class="fa-solid fa-calendar-check" style="color:#2563eb;"></i> Upcoming</div>
            <div class="card-amount" style="color:#2563eb;"><?= $admin_upcoming_count ?></div>
            <div class="card-detail">Approved future events.</div>
          </div>
          <!-- 5. Venue Conflicts -->
          <div class="info-card admin-metric-card" id="cardAdminVenueConflicts" onclick="filterAdminByCard('Venue Conflicts')" style="cursor:pointer; transition:all 0.2s ease;" title="Click to filter by Scheduling issues">
            <div class="card-label"><i class="fa-solid fa-triangle-exclamation" style="color:#ea580c;"></i> Venue Conflicts</div>
            <div class="card-amount" style="color:#ea580c;"><?= $admin_venue_conflicts_count ?></div>
            <div class="card-detail">Scheduling issues.</div>
          </div>
          <!-- 6. Overdue Proposals -->
          <div class="info-card admin-metric-card" id="cardAdminOverdueProposals" onclick="filterAdminByCard('Overdue Proposals')" style="cursor:pointer; transition:all 0.2s ease;" title="Click to filter by Items exceeding configured review SLA">
            <div class="card-label"><i class="fa-solid fa-fire-flame-curved" style="color:#9333ea;"></i> Overdue Proposals</div>
            <div class="card-amount" style="color:#9333ea;"><?= $admin_overdue_count ?></div>
            <div class="card-detail">Items exceeding configured review SLA.</div>
          </div>
        </div>

        <!-- ──────────────────────────────────────────────────────────
             ADMIN EVENT ADMINISTRATION TABLE (8 Columns Specified)
             Columns: Event ID | Event | Organization | Date | Venue | SSC Review | Admin Status | Action
        ────────────────────────────────────────────────────────── -->
        <div class="card" id="adminEventQueueCard">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
            <div>
              <h3 style="margin:0; font-size:1.15rem; color:#1e293b; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-calendar-check" style="color:#2563eb;"></i> Event Administration &amp; Clearance Pipeline
                <?php if ($admin_pending_approval_count > 0): ?>
                  <span style="background:#fef3c7; color:#d97706; font-size:0.75rem; padding:3px 10px; border-radius:12px; font-weight:700;">
                    <?= $admin_pending_approval_count ?> Pending Admin Approval
                  </span>
                <?php endif; ?>
              </h3>
              <p style="margin:4px 0 0 0; font-size:0.8rem; color:#64748b;">Review SSC-endorsed activities, resolve venue scheduling collisions, and issue administrative clearance.</p>
            </div>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
              <?php if (can_any(['events.create.own', 'events.create.institutional'])): ?>
                <button type="button" class="card-btn btn-sm btn-ai-planner" onclick="openModal('aiPlannerModal')" title="Open AI Event Planner &amp; Schedule Conflict Optimizer" aria-label="Open AI Event Planner">
                  <i class="fa-solid fa-wand-magic-sparkles"></i> AI Event Planner
                </button>
              <?php endif; ?>
              <button type="button" class="card-btn btn-sm" style="background:#16a34a; color:#fff; font-weight:700; padding:8px 14px; border-radius:8px;" onclick="openCreateEventModal()" title="Create Institutional / Administrative Event">
                <i class="fa-solid fa-calendar-plus"></i> Create Institutional Event
              </button>
            </div>
          </div>

          <!-- Filter & Search Toolbar -->
          <div style="display:flex; flex-wrap:wrap; gap:12px; margin-bottom:16px; align-items:center; background:#f8fafc; padding:12px 14px; border-radius:8px; border:1px solid #e2e8f0;">
            <!-- Search Input -->
            <div style="flex:1; min-width:220px; position:relative;">
              <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.85rem;"></i>
              <input type="text" id="adminQueueSearchInput" placeholder="Search Event ID, title, organization, venue, review notes..." onkeyup="filterAdminEventQueue()" style="width:100%; padding:8px 12px 8px 34px; border-radius:6px; border:1px solid #cbd5e1; font-size:0.85rem; box-sizing:border-box;" />
            </div>

            <!-- Admin Status Filter -->
            <div style="min-width:180px;">
              <select id="adminQueueStatusFilter" onchange="filterAdminEventQueue()" style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid #cbd5e1; font-size:0.85rem; background:#fff; cursor:pointer;">
                <option value="">All Admin States</option>
                <option value="Pending Admin">Pending Admin Approval</option>
                <option value="Approved">Approved</option>
                <option value="Returned">Returned</option>
                <option value="Rejected">Rejected</option>
              </select>
            </div>

            <!-- Organization Filter -->
            <div style="min-width:160px;">
              <select id="adminQueueOrgFilter" onchange="filterAdminEventQueue()" style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid #cbd5e1; font-size:0.85rem; background:#fff; cursor:pointer;">
                <option value="">All Organizations</option>
                <option value="INSTITUTIONAL">BCP Institutional</option>
                <?php foreach ($clubs as $c): ?>
                  <option value="<?= htmlspecialchars($c['code']) ?>"><?= htmlspecialchars($c['code']) ?> - <?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Reset Filter Button -->
            <div>
              <button type="button" onclick="resetAdminQueueFilter()" class="card-btn btn-sm" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-size:0.82rem; padding:7px 12px; border-radius:6px; cursor:pointer;">
                <i class="fa-solid fa-rotate-left"></i> Reset
              </button>
            </div>
          </div>

          <!-- Active card filter indicator badge bar -->
          <div id="adminFilterNoticeBar" style="display:none; margin-bottom:14px; background:#eff6ff; border:1px solid #93c5fd; color:#1d4ed8; padding:8px 14px; border-radius:8px; font-size:0.82rem; justify-content:space-between; align-items:center;">
            <span id="adminFilterNoticeText"><i class="fa-solid fa-filter"></i> Filtering table records</span>
            <button type="button" onclick="resetAdminQueueFilter()" style="background:none; border:none; color:#1d4ed8; cursor:pointer; font-weight:700;"><i class="fa-solid fa-xmark"></i> Clear</button>
          </div>

          <!-- Table Wrap with Responsive Scroller & Pagination -->
          <div class="table-responsive">
            <table id="adminEventQueueTable" class="data-table resp-table mobile-card-table no-auto-paginate" style="width:100%;">
              <thead>
                <tr>
                  <th style="width:8%;">Event ID</th>
                  <th style="width:23%;">Event</th>
                  <th style="width:14%;">Organization</th>
                  <th style="width:11%;">Date</th>
                  <th style="width:11%;">Venue</th>
                  <th style="width:10%;">SSC Review</th>
                  <th style="width:9%;">Admin Status</th>
                  <th style="text-align:right; width:14%;">Action</th>
                </tr>
              </thead>
              <tbody id="adminEventQueueBody">
                <?php if (empty($events)): ?>
                  <tr id="adminQueueEmptyRow">
                    <td colspan="8" class="empty-state-cell" style="text-align:center; padding:36px 16px; color:#94a3b8;">
                      <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; width:100%; margin:0 auto;">
                        <i class="fa-solid fa-calendar-xmark" style="font-size:2.2rem; margin-bottom:10px; display:inline-block; color:#94a3b8;"></i>
                        <span style="font-weight:600; font-size:0.9rem; color:#475569; text-align:center;">No event proposals in queue.</span>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <tr id="adminQueueEmptyRow" style="display:none;">
                    <td colspan="8" class="empty-state-cell" style="text-align:center; padding:36px 16px; color:#94a3b8;">
                      <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; width:100%; margin:0 auto;">
                        <i class="fa-solid fa-magnifying-glass" style="font-size:2rem; margin-bottom:8px; display:inline-block; color:#94a3b8;"></i>
                        <span style="font-weight:600; font-size:0.9rem; color:#475569; text-align:center;">No events match the current filter criteria.</span>
                      </div>
                    </td>
                  </tr>
                  <?php foreach ($events as $ev): 
                    $ev_ts = strtotime($ev['event_date']);
                    $sub_ts = !empty($ev['created_at']) ? strtotime($ev['created_at']) : $ev_ts;
                    $dp = (int)$ev['days_pending'];
                    $is_overdue = ($dp >= 7 && in_array($ev['status'], ['Pending Admin', 'Pending SSC']));
                    $is_appr_today = ($ev['status'] === 'Approved' && substr($ev['created_at'] ?? '', 0, 10) === $today_str);
                    $is_upcoming = ($ev['status'] === 'Approved' && $ev_ts >= $now_ts);
                  ?>
                    <tr class="admin-queue-row"
                        id="admin-event-row-<?= $ev['id'] ?>"
                        data-id="<?= $ev['id'] ?>"
                        data-status="<?= htmlspecialchars($ev['status']) ?>"
                        data-type="<?= htmlspecialchars($ev['event_type']) ?>"
                        data-org="<?= htmlspecialchars($ev['club_code']) ?>"
                        data-conflict="<?= $ev['has_venue_conflict'] ? '1' : '0' ?>"
                        data-overdue="<?= $is_overdue ? '1' : '0' ?>"
                        data-approved-today="<?= $is_appr_today ? '1' : '0' ?>"
                        data-upcoming="<?= $is_upcoming ? '1' : '0' ?>"
                        data-date="<?= date('Y-m-d', $ev_ts) ?>">
                      <!-- 1. Event ID -->
                      <td data-label="Event ID">
                        <span style="font-family:monospace; font-weight:700; color:#2563eb; font-size:0.75rem; word-break:break-all;"><?= htmlspecialchars($ev['event_ref_id']) ?></span>
                      </td>
                      <!-- 2. Event -->
                      <td data-label="Event">
                        <div style="font-weight:700; color:#1e293b; font-size:0.79rem; line-height:1.25; margin-bottom:2px;" title="<?= htmlspecialchars($ev['title']) ?>">
                          <?= htmlspecialchars($ev['title']) ?>
                        </div>
                        <div style="display:flex; align-items:center; gap:4px; flex-wrap:wrap;">
                          <span class="badge-<?= $ev['event_type'] === 'Institutional' ? 'purple' : 'info' ?>" style="font-size:0.62rem; font-weight:700; padding:1px 5px; border-radius:3px;">
                            <?= htmlspecialchars($ev['event_type']) ?>
                          </span>
                          <?php if ($ev['has_venue_conflict']): ?>
                            <span class="badge-danger" style="font-size:0.62rem; padding:1px 5px; border-radius:3px; background:#fef2f2; color:#b91c1c; border:1px solid #fca5a5;">
                              <i class="fa-solid fa-triangle-exclamation"></i> Conflict
                            </span>
                          <?php endif; ?>
                          <?php if ($is_overdue): ?>
                            <span style="font-size:0.62rem; padding:1px 5px; border-radius:3px; background:#faf5ff; color:#7e22ce; border:1px solid #d8b4fe; font-weight:700;">
                              <i class="fa-solid fa-fire-flame-curved"></i> SLA (<?= $dp ?>d)
                            </span>
                          <?php endif; ?>
                        </div>
                        <?php if ($ev['rejection_note']): ?>
                          <div style="font-size:0.68rem; color:#dc2626; margin-top:2px; line-height:1.2;">
                            <i class="fa-solid fa-comment-dots"></i> <?= htmlspecialchars($ev['rejection_note']) ?>
                          </div>
                        <?php endif; ?>
                      </td>
                      <!-- 3. Organization -->
                      <td data-label="Organization">
                        <div>
                          <span class="club-badge" style="font-size:0.66rem; padding:1px 5px;"><?= htmlspecialchars($ev['club_code']) ?></span>
                        </div>
                        <div style="font-size:0.72rem; color:#475569; margin-top:2px; line-height:1.2; word-break:break-word;" title="<?= htmlspecialchars($ev['club_name']) ?>">
                          <?= htmlspecialchars($ev['club_name']) ?>
                        </div>
                      </td>
                      <!-- 4. Date -->
                      <td data-label="Date">
                        <div style="font-weight:600; color:#1e293b; font-size:0.75rem; white-space:nowrap;"><?= date('M d, Y', $ev_ts) ?></div>
                        <div style="color:#64748b; font-size:0.69rem; white-space:nowrap; margin-top:1px;"><i class="fa-regular fa-clock" style="font-size:0.65rem;"></i> <?= date('h:i A', $ev_ts) ?></div>
                      </td>
                      <!-- 5. Venue -->
                      <td data-label="Venue">
                        <div style="color:#1e293b; font-weight:500; font-size:0.75rem; line-height:1.2; word-break:break-word;" title="<?= htmlspecialchars($ev['venue']) ?>">
                          <i class="fa-solid fa-location-dot" style="color:#64748b; font-size:0.69rem;"></i> <?= htmlspecialchars($ev['venue']) ?>
                        </div>
                        <?php if ($ev['has_venue_conflict']): ?>
                          <div style="font-size:0.66rem; color:#ea580c; font-weight:700; margin-top:2px;">
                            <i class="fa-solid fa-triangle-exclamation"></i> Conflict
                          </div>
                        <?php endif; ?>
                      </td>
                      <!-- 6. SSC Review -->
                      <td data-label="SSC Review">
                        <?php if (in_array($ev['status'], ['Pending Admin', 'Approved']) || strpos($ev['endorsement_notes'] ?? '', 'Endorsed') !== false): ?>
                          <span class="badge-active" style="display:inline-flex; align-items:center; gap:3px; font-size:0.66rem; font-weight:700; padding:2px 5px;">
                            <i class="fa-solid fa-circle-check"></i> Passed SSC
                          </span>
                          <?php if (!empty($ev['endorsement_notes'])): ?>
                            <div style="font-size:0.67rem; color:#64748b; margin-top:2px; line-height:1.2; word-break:break-word;" title="<?= htmlspecialchars($ev['endorsement_notes']) ?>">
                              <?= htmlspecialchars(mb_strimwidth($ev['endorsement_notes'], 0, 36, '...')) ?>
                            </div>
                          <?php endif; ?>
                        <?php elseif ($ev['status'] === 'Pending SSC'): ?>
                          <span class="badge-warning" style="display:inline-flex; align-items:center; gap:3px; font-size:0.66rem; font-weight:700; padding:2px 5px;">
                            <i class="fa-solid fa-clock"></i> In Review
                          </span>
                        <?php elseif ($ev['status'] === 'Returned'): ?>
                          <span class="badge-warning" style="display:inline-flex; align-items:center; gap:3px; font-size:0.66rem; font-weight:700; padding:2px 5px;">
                            <i class="fa-solid fa-rotate-left"></i> Returned
                          </span>
                        <?php elseif ($ev['status'] === 'Rejected'): ?>
                          <span class="badge-inactive" style="display:inline-flex; align-items:center; gap:3px; font-size:0.66rem; font-weight:700; padding:2px 5px;">
                            <i class="fa-solid fa-ban"></i> SSC Rejected
                          </span>
                        <?php else: ?>
                          <span class="badge-info" style="font-size:0.66rem; font-weight:700; padding:2px 5px;">
                            <?= htmlspecialchars($ev['status']) ?>
                          </span>
                        <?php endif; ?>
                      </td>
                      <!-- 7. Admin Status -->
                      <td data-label="Admin Status">
                        <?php if ($ev['status'] === 'Pending Admin'): ?>
                          <span class="badge-warning" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-size:0.66rem; font-weight:700; display:inline-flex; align-items:center; gap:3px; padding:2px 5px;">
                            <i class="fa-solid fa-hourglass-half"></i> Pending
                          </span>
                        <?php elseif ($ev['status'] === 'Approved'): ?>
                          <span class="badge-active" style="font-size:0.66rem; font-weight:700; display:inline-flex; align-items:center; gap:3px; padding:2px 5px;">
                            <i class="fa-solid fa-circle-check"></i> Approved
                          </span>
                        <?php elseif ($ev['status'] === 'Rejected'): ?>
                          <span class="badge-inactive" style="font-size:0.66rem; font-weight:700; display:inline-flex; align-items:center; gap:3px; padding:2px 5px;">
                            <i class="fa-solid fa-ban"></i> Rejected
                          </span>
                        <?php elseif ($ev['status'] === 'Returned'): ?>
                          <span class="badge-warning" style="font-size:0.66rem; font-weight:700; display:inline-flex; align-items:center; gap:3px; padding:2px 5px;">
                            <i class="fa-solid fa-rotate-left"></i> Returned
                          </span>
                        <?php else: ?>
                          <span class="badge-info" style="font-size:0.66rem; font-weight:700; padding:2px 5px;">
                            <?= htmlspecialchars($ev['status']) ?>
                          </span>
                        <?php endif; ?>
                      </td>
                      <!-- 8. Action (Approve / override / return / reject / details) -->
                      <td data-label="Action" style="text-align:right;">
                        <div class="event-act-group">
                          <!-- 1. Approve Button -->
                          <?php if ($ev['status'] === 'Pending Admin'): ?>
                            <button type="button" class="admin-tbl-act-btn" style="background:#16a34a; color:#fff;" onclick="adminApproveEvent(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')" title="Approve and Publish to Campus Calendar" aria-label="Approve">
                              <i class="fa-solid fa-check"></i>
                            </button>
                          <?php else: ?>
                            <button type="button" class="admin-tbl-act-btn btn-disabled" disabled title="Approve Unavailable (Status: <?= htmlspecialchars($ev['status']) ?>)" aria-label="Approve Disabled">
                              <i class="fa-solid fa-check"></i>
                            </button>
                          <?php endif; ?>

                          <!-- 2. Override Button -->
                          <button type="button" class="admin-tbl-act-btn" style="background:#7c3aed; color:#fff;" onclick="openAdminOverrideModal(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>', <?= $ev['has_venue_conflict'] ? 'true' : 'false' ?>)" title="Admin Override Clearance (Force approve or clear conflicts)" aria-label="Override">
                            <i class="fa-solid fa-bolt"></i>
                          </button>

                          <!-- 3. Return Button -->
                          <?php if ($ev['status'] !== 'Returned' && $ev['status'] !== 'Rejected'): ?>
                            <button type="button" class="admin-tbl-act-btn" style="background:#f59e0b; color:#fff;" onclick="openReturnEventModal(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')" title="Return for Revision" aria-label="Return">
                              <i class="fa-solid fa-rotate-left"></i>
                            </button>
                          <?php else: ?>
                            <button type="button" class="admin-tbl-act-btn btn-disabled" disabled title="<?= $ev['status'] === 'Returned' ? 'Already Returned for Revision' : 'Return Unavailable' ?>" aria-label="Return Disabled">
                              <i class="fa-solid fa-rotate-left"></i>
                            </button>
                          <?php endif; ?>

                          <!-- 4. Reject Button -->
                          <?php if ($ev['status'] !== 'Rejected'): ?>
                            <button type="button" class="admin-tbl-act-btn" style="background:#dc2626; color:#fff;" onclick="rejectEvent(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')" title="Reject Event Proposal" aria-label="Reject">
                              <i class="fa-solid fa-xmark"></i>
                            </button>
                          <?php else: ?>
                            <button type="button" class="admin-tbl-act-btn btn-disabled" disabled title="Already Rejected" aria-label="Reject Disabled">
                              <i class="fa-solid fa-xmark"></i>
                            </button>
                          <?php endif; ?>

                          <!-- 5. Details Button -->
                          <button type="button" class="admin-tbl-act-btn" style="background:#f1f5f9; color:#334155; border:1px solid #cbd5e1;" onclick="viewEvent(<?= htmlspecialchars(json_encode($ev)) ?>)" title="View Event Proposal Details" aria-label="Details">
                            <i class="fa-solid fa-eye"></i>
                          </button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($sess_role !== 'ssc' && $sess_role !== 'admin'): ?>
      <!-- Events Table -->
      <div class="card" id="eventsListCard" <?= ($sess_role !== 'student' && $events_view === 'calendar') ? 'style="display:none;"' : '' ?>>
        <div class="filter-toolbar-wrap" style="margin-bottom:16px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
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
          <div class="filter-controls-group">
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
            <?php if (can_any(['events.create.own', 'events.create.institutional'])): ?>
              <button type="button" class="card-btn btn-sm btn-ai-planner" onclick="openModal('aiPlannerModal')" title="Open AI Event Planner &amp; Schedule Conflict Optimizer" aria-label="Open AI Event Planner">
                <i class="fa-solid fa-wand-magic-sparkles"></i> AI Event Planner
              </button>
            <?php endif; ?>
            <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
              <button type="button" class="card-btn btn-sm btn-full-mobile" id="openCreateEvent" style="background:#16a34a; color:#fff; font-weight:700;" onclick="openCreateEventModal()" title="Create event proposal to submit to SSC for review and approval">
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
                <button type="button" class="card-btn" onclick="openCreateEventModal()" style="background:#16a34a; color:#fff; font-weight:700;">
                  <i class="fa-solid fa-calendar-plus"></i> Create First Event Proposal
                </button>
              </div>
            <?php endif; ?>
          </div>
        <?php else: ?>
        <div class="table-wrap">
          <table id="eventTable" class="table-wide">
          <thead>
            <tr>
              <th>Event Title</th>
              <th>Month Posted</th>
              <th>Host Organization</th>
              <th>Date & Time</th>
              <th>Venue</th>
              <th>Status</th>
              <th style="text-align:right; white-space:nowrap;">Actions</th>
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
              <td style="text-align:right; white-space:nowrap;">
                <div class="event-act-group">
                  <button type="button" class="event-act-btn event-act-btn-locate" onclick="locateOnCalendar('<?= $ev_date_str ?>', <?= $ev['id'] ?>)" title="Locate event on Calendar">
                    <i class="fa-solid fa-location-crosshairs"></i> Locate
                  </button>
                  <button type="button" class="event-act-btn event-act-btn-details" onclick="viewEvent(<?= htmlspecialchars(json_encode($ev)) ?>)" title="View Event Details">
                    <i class="fa-solid fa-eye"></i> Details
                  </button>
                  <button type="button" class="event-act-btn event-act-btn-qr" onclick="if(window.openGlobalEventQr){ window.openGlobalEventQr(<?= (int)$ev['id'] ?>); } else { window.showSystemModal({ title: 'QR Unavailable', message: 'QR viewer is currently unavailable.', type: 'warning' }); }" title="View Event QR Code & Official Attendance Poster">
                    <i class="fa-solid fa-qrcode"></i> QR
                  </button>
                  <?php if (can_any(['events.review.ssc', 'events.approve.admin', 'events.create.own'])): ?>
                    <button type="button" class="event-act-btn event-act-btn-reg" onclick="viewRegistrations(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')" title="View Event Registrations">
                      <i class="fa-solid fa-users-rectangle"></i> Registrations
                    </button>
                  <?php endif; ?>
                  
                  <?php /* STAGE 2: SSC Endorsement */ ?>
                  <?php if (can('events.review.ssc') && !can('events.approve.admin')): ?>
                    <?php if (in_array($ev['status'], ['Pending SSC', 'Pending OSA'])): ?>
                      <button type="button" class="event-act-btn event-act-btn-endorse" onclick="endorseEvent(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')" title="Endorse to Admin">
                        <i class="fa-solid fa-arrow-right"></i> Endorse
                      </button>
                      <button type="button" class="event-act-btn event-act-btn-reject" onclick="rejectEvent(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')" title="Reject Event">
                        <i class="fa-solid fa-times"></i> Reject
                      </button>
                    <?php else: ?>
                      <button type="button" class="event-act-btn btn-disabled" disabled title="Endorsement Unavailable (Status: <?= htmlspecialchars($ev['status']) ?>)">
                        <i class="fa-solid fa-arrow-right"></i> Endorse
                      </button>
                      <button type="button" class="event-act-btn btn-disabled" disabled title="Reject Unavailable (Status: <?= htmlspecialchars($ev['status']) ?>)">
                        <i class="fa-solid fa-times"></i> Reject
                      </button>
                    <?php endif; ?>

                  <?php /* STAGE 3: Admin Final Calendar Approval */ ?>
                  <?php elseif (can('events.approve.admin')): ?>
                    <?php if ($ev['status'] === 'Pending Admin'): ?>
                      <button type="button" class="event-act-btn event-act-btn-endorse" onclick="adminApproveEvent(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')" title="Approve for Calendar">
                        <i class="fa-solid fa-check"></i> Approve
                      </button>
                      <button type="button" class="event-act-btn event-act-btn-reject" onclick="rejectEvent(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')" title="Reject Event">
                        <i class="fa-solid fa-times"></i> Reject
                      </button>
                    <?php else: ?>
                      <button type="button" class="event-act-btn btn-disabled" disabled title="Approval Unavailable (Status: <?= htmlspecialchars($ev['status']) ?>)">
                        <i class="fa-solid fa-check"></i> Approve
                      </button>
                      <button type="button" class="event-act-btn btn-disabled" disabled title="Reject Unavailable (Status: <?= htmlspecialchars($ev['status']) ?>)">
                        <i class="fa-solid fa-times"></i> Reject
                      </button>
                    <?php endif; ?>

                  <?php elseif (can('events.edit.own')): ?>
                    <?php if (in_array($ev['status'], ['Pending SSC', 'Pending OSA', 'Rejected'])): ?>
                      <button type="button" class="event-act-btn event-act-btn-edit" onclick="editEvent(<?= htmlspecialchars(json_encode($ev)) ?>)" title="Edit Event Proposal">
                        <i class="fa-solid fa-edit"></i> Edit
                      </button>
                    <?php else: ?>
                      <button type="button" class="event-act-btn btn-disabled" disabled title="Edit Locked (Event is <?= htmlspecialchars($ev['status']) ?>)">
                        <i class="fa-solid fa-edit"></i> Edit
                      </button>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- ────────────────────────────────────────────────────────────
     AI EVENT PLANNER DIALOG MODAL
──────────────────────────────────────────────────────────── -->
<?php if (can_any(['events.create.own', 'events.create.institutional'])): ?>
<!-- AI Event Planner Dialog Modal -->
<div class="modal-overlay" id="aiPlannerModal">
  <div class="modal modal-lg" style="max-width:980px; width:95%; max-height:90vh; display:flex; flex-direction:column; padding:0; overflow:hidden; border-radius:18px; box-shadow:0 25px 50px -12px rgba(15,23,42,0.35);">
    <div class="modal-header" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 50%, #2563eb 100%); color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
      <div style="display:flex; align-items:center; gap:12px;">
        <div class="ai-rec-icon-wrap">
          <i class="fa-solid fa-brain"></i>
          <span class="ai-pulse-dot"></span>
        </div>
        <div>
          <h3 style="margin:0; font-size:1.05rem; font-weight:800; color:#fff; display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-wand-magic-sparkles" style="color:#f59e0b;"></i> AI Event Planner
          </h3>
          <p style="margin:2px 0 0; font-size:0.75rem; color:rgba(255,255,255,0.85); line-height:1.3;">
            Generate conflict-free event proposals and schedules.
          </p>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('aiPlannerModal')" type="button" style="color:#ffffff; opacity:0.9; font-size:1.2rem; background:none; border:none; cursor:pointer; padding:6px; margin-left:12px;" aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <!-- AI Prompt Controls Row -->
    <div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:14px 24px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; flex-shrink:0;">
      <?php if ($sess_role === 'ssc'): ?>
      <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:0.72rem; font-weight:700; color:#64748b;"><i class="fa-solid fa-sitemap"></i> Organization</label>
        <select id="aiPlannerClubSelect" style="padding:8px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; background:#fff; font-weight:600; color:#1e293b; height:38px;" title="Select organization to plan events for">
          <?php foreach ($clubs as $cl): ?>
          <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name']) ?> (<?= htmlspecialchars($cl['code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div style="flex:1; min-width:240px; display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:0.72rem; font-weight:700; color:#64748b;"><i class="fa-solid fa-lightbulb"></i> Theme / Topic (Optional)</label>
        <input type="text" id="aiPlannerThemeInput" placeholder="e.g. Leadership Seminar, Sports Fest, Workshop..." style="padding:8px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.84rem; color:#1e293b; background:#fff; width:100%; height:38px;" onkeydown="if(event.key==='Enter') generateAIEventPlans();"/>
      </div>

      <div style="display:flex; align-items:flex-end; height:100%; padding-top:18px;">
        <button type="button" class="ai-rec-generate-btn" id="aiPlanBtn" onclick="generateAIEventPlans()" style="height:38px; padding:0 18px;">
          <i class="fa-solid fa-wand-magic-sparkles"></i>
          <span>Generate Ideas</span>
        </button>
      </div>
    </div>

    <!-- AI Result Container -->
    <div class="ai-rec-body" id="aiPlannerBody" style="padding:16px 20px; overflow-y:auto; overflow-x:hidden; flex:1; max-height:calc(90vh - 170px); width:100%; box-sizing:border-box;">
      <!-- Initial Guide / Empty State -->
      <div id="aiPlannerEmptyState" style="text-align:center; padding:36px 20px; color:#64748b;">
        <div style="width:56px; height:56px; border-radius:16px; background:linear-gradient(135deg, #eff6ff, #dbeafe); color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:1.5rem; margin:0 auto 14px; box-shadow:0 8px 16px rgba(37,99,235,0.12);">
          <i class="fa-solid fa-wand-magic-sparkles"></i>
        </div>
        <h4 style="font-size:0.98rem; font-weight:700; color:#1e293b; margin:0 0 6px;">Smart Event Suggestions</h4>
        <p style="font-size:0.82rem; color:#64748b; max-width:380px; margin:0 auto; line-height:1.5;">
          Choose an organization and click <strong>Generate Ideas</strong> to preview recommended dates and venues.
        </p>
      </div>

      <!-- Loading Shimmer -->
      <div class="ai-rec-loading" id="aiPlannerLoading" style="display:none;">
        <div class="ai-shimmer-bar"></div>
        <div class="ai-shimmer-bar short"></div>
        <div class="ai-shimmer-bar"></div>
        <div class="ai-thinking-text"><i class="fa-solid fa-brain fa-beat-fade"></i> Generating event proposals...</div>
      </div>

      <!-- Results -->
      <div id="aiPlannerResults" style="width:100%; box-sizing:border-box; overflow-x:hidden;"></div>
    </div>

    <!-- Modal Footer -->
    <div class="modal-actions" style="padding:10px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; align-items:center; flex-shrink:0;">
      <span style="font-size:0.75rem; color:#64748b;"><i class="fa-solid fa-shield-halved" style="color:#16a34a;"></i> Checked against campus calendar &amp; holidays</span>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ------ CREATE EVENT MODAL (Supports Club & School-Wide Events) ------ -->
<?php if (can_any(['events.create.own', 'events.create.institutional', 'events.approve.admin'])): ?>
<div class="modal-overlay" id="createEventModal">
  <div class="modal modal-lg" style="max-width:600px; padding:0; overflow:hidden; border-radius:16px;">
    <div class="modal-header" style="background: linear-gradient(135deg, #1e3a8a, #2563eb); color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:1.08rem; color:#ffffff; font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-calendar-plus" style="color:#f59e0b;"></i> 
        <span id="createEventModalTitle">Create Event Proposal</span>
      </h3>
      <button class="modal-close" onclick="closeModal('createEventModal')" type="button" style="color:#ffffff; opacity:0.9; font-size:1.1rem; background:none; border:none; cursor:pointer;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="createEventForm" autocomplete="off" enctype="multipart/form-data">
      <div class="modal-body" style="padding:24px;">
        
        <?php if (can_any(['events.create.institutional', 'events.approve.admin'])): ?>
        <div class="form-group" style="margin-bottom:14px;">
          <label style="font-weight:700; font-size:0.8rem; color:#475569;">Event Scope / Type <span style="color:#ef4444;">*</span></label>
          <select name="event_type" id="createEventTypeSelect" onchange="toggleEventScopeFields(this.value)" style="width:100%; padding:9px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.85rem; font-weight:600;">
            <option value="Club">Organization / Club Event</option>
            <option value="Institutional" <?= ($sess_role === 'ssc') ? 'selected' : '' ?>>School-Wide / Institutional Event (e.g. Foundation Day, Valentine's Day)</option>
          </select>
        </div>
        <?php endif; ?>

        <div class="form-group">
          <label>Event Title <span style="color:#ef4444;">*</span></label>
          <input type="text" name="title" id="createEventTitleInput" placeholder="e.g. BCP Foundation Day Grand Fest / Tech Hackathon" required/>
        </div>
        <div class="form-group">
          <label>Description &amp; Objectives</label>
          <textarea name="description" rows="3" placeholder="Specify event details, objectives, target attendees, and schedule..."></textarea>
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <label>Event Date &amp; Time <span style="color:#ef4444;">*</span></label>
            <input type="datetime-local" name="event_date" id="createEventDateInput" required onchange="autoCheckModalDate()"/>
          </div>
          <div class="form-group">
            <label>Venue <span style="color:#ef4444;">*</span></label>
            <input type="text" name="venue" id="createEventVenueInput" placeholder="e.g. Main Gymnasium / Auditorium" required onchange="autoCheckModalDate()"/>
          </div>
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <label>Expected Attendees</label>
            <input type="number" name="expected_attendees" min="0" placeholder="e.g. 150" value="0"/>
          </div>
          <div class="form-group">
            <label>Proposal Document (PDF / DOCX / JPG)</label>
            <input type="file" name="attachment" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" style="padding:7px 10px; font-size:0.8rem; background:#f8fafc; border:1.5px dashed #cbd5e1; border-radius:8px;"/>
          </div>
        </div>
        <div style="margin-bottom:16px;">
          <button type="button" class="card-btn" id="btnAuditModalDate" style="background:linear-gradient(135deg, #4338ca, #3b82f6); color:#fff; font-weight:700; padding:10px 16px; border-radius:8px; width:100%; display:inline-flex; align-items:center; justify-content:center; gap:8px; font-size:0.84rem; border:none; cursor:pointer; box-shadow:0 2px 6px rgba(67,56,202,0.25);" onclick="runAICheckDateConflict()">
            <i class="fa-solid fa-shield-halved"></i> AI Audit Date &amp; Check Conflicts
          </button>
          <div id="conflictAuditResult" style="display:none; margin-top:10px; font-size:0.82rem; border-radius:10px; padding:12px 16px; line-height:1.45; box-shadow:0 2px 6px rgba(0,0,0,0.03);"></div>
        </div>

        <div class="form-group" id="hostOrgGroupWrap">
          <label>Host Organization <span style="color:#ef4444;">*</span></label>
          <select name="club_id" id="createEventClubSelect">
            <?php foreach ($clubs as $cl): ?>
            <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name']) ?> (<?= htmlspecialchars($cl['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-actions" style="padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:600;" onclick="closeModal('createEventModal')">Cancel</button>
        <button type="submit" class="card-btn" id="createEventBtn" style="background:#16a34a; color:#fff; font-weight:700; padding:9px 18px;">
          <i class="fa-solid fa-paper-plane"></i> <?= ($sess_role === 'admin') ? 'Create & Publish Event' : (($sess_role === 'ssc') ? 'Endorse Event to Admin' : 'Submit to SSC for Review') ?>
        </button>
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
        <div class="form-grid-2">
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
      <div class="table-wrap">
        <table class="table-wide" style="width:100%; font-size:0.85rem; border-collapse:collapse;" id="regTable">
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
    <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
    <div class="modal-actions" style="padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; align-items:center;">
      <button type="button" class="card-btn" onclick="exportRegistrationsPDF()" style="background:#2563eb; color:#fff; font-weight:700; display:inline-flex; align-items:center; gap:6px; font-size:0.85rem; cursor:pointer;">
        <i class="fa-solid fa-file-pdf"></i> Export
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ────────────────────────────────────────────────────────────
     SSC EVENT REVIEW PANEL MODAL (6-Point Validation Checklist)
──────────────────────────────────────────────────────────── -->
<?php if ($sess_role === 'ssc' || $sess_role === 'admin'): ?>
<div class="modal-overlay" id="sscEventReviewModal" style="display:none;">
  <div class="modal modal-lg" style="max-width:980px; width:95%; max-height:92vh; display:flex; flex-direction:column; padding:0; overflow:hidden; border-radius:18px; box-shadow:0 25px 50px -12px rgba(15,23,42,0.35);">
    <!-- Modal Header -->
    <div class="modal-header" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 50%, #2563eb 100%); color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
      <div style="display:flex; align-items:center; gap:12px;">
        <div style="width:40px; height:40px; border-radius:10px; background:rgba(255,255,255,0.15); display:flex; align-items:center; justify-content:center; font-size:1.2rem; color:#fff;">
          <i class="fa-solid fa-clipboard-check"></i>
        </div>
        <div>
          <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#fff; display:flex; align-items:center; gap:8px;">
            SSC Event Review Panel
            <span id="sscRevRefBadge" style="font-size:0.75rem; font-weight:700; background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:6px; font-family:monospace;"></span>
          </h3>
          <span id="sscRevStatusBadge" style="font-size:0.74rem; font-weight:600; opacity:0.9;"></span>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('sscEventReviewModal')" type="button" style="color:#ffffff; opacity:0.9; font-size:1.2rem; background:none; border:none; cursor:pointer; padding:6px;" aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <!-- Modal Body (Scrollable dual-column) -->
    <div class="modal-body" style="padding:22px 26px; overflow-y:auto; flex:1; max-height:calc(92vh - 150px);">
      <input type="hidden" id="sscRevEventId" value=""/>

      <div class="ssc-review-grid">
        <!-- LEFT COLUMN: Event Details -->
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px;">
          <h4 style="margin:0 0 14px; font-size:0.95rem; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:8px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
            <i class="fa-solid fa-circle-info" style="color:#2563eb;"></i> Event Proposal Details
          </h4>

          <div style="margin-bottom:12px;">
            <div style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase;">Event Title</div>
            <div id="sscRevTitle" style="font-size:1rem; font-weight:800; color:#0f172a; margin-top:2px;"></div>
          </div>

          <div class="ssc-detail-row">
            <span class="ssc-detail-label">Host Organization:</span>
            <span class="ssc-detail-val" id="sscRevClub"></span>
          </div>
          <div class="ssc-detail-row">
            <span class="ssc-detail-label">Faculty Adviser:</span>
            <span class="ssc-detail-val" id="sscRevAdviser"></span>
          </div>
          <div class="ssc-detail-row">
            <span class="ssc-detail-label">Event Type:</span>
            <span class="ssc-detail-val" id="sscRevType"></span>
          </div>
          <div class="ssc-detail-row">
            <span class="ssc-detail-label">Date &amp; Time:</span>
            <span class="ssc-detail-val" id="sscRevDateTime"></span>
          </div>
          <div class="ssc-detail-row">
            <span class="ssc-detail-label">Venue:</span>
            <span class="ssc-detail-val" id="sscRevVenue"></span>
          </div>
          <div class="ssc-detail-row">
            <span class="ssc-detail-label">Expected Attendees:</span>
            <span class="ssc-detail-val" id="sscRevAttendees"></span>
          </div>
          <div class="ssc-detail-row">
            <span class="ssc-detail-label">Submitted By:</span>
            <span class="ssc-detail-val" id="sscRevSubmitter"></span>
          </div>
          <div class="ssc-detail-row">
            <span class="ssc-detail-label">Submitted Date:</span>
            <span class="ssc-detail-val" id="sscRevSubmitDate"></span>
          </div>

          <!-- Schedule & Venue Collision Banner -->
          <div id="sscRevConflictBanner" style="display:none; margin-top:14px; padding:12px 14px; border-radius:8px; font-size:0.8rem; line-height:1.45;"></div>

          <!-- Description & Objectives -->
          <div style="margin-top:14px;">
            <div style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:4px;">Description &amp; Objectives:</div>
            <div id="sscRevDesc" style="font-size:0.82rem; color:#334155; line-height:1.5; background:#fff; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0; min-height:60px; max-height:140px; overflow-y:auto;"></div>
          </div>

          <!-- Attachments Section -->
          <div style="margin-top:14px;">
            <div style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:6px;">Proposal Document / Attachments:</div>
            <div id="sscRevAttachmentWrap"></div>
          </div>
        </div>

        <!-- RIGHT COLUMN: Validation Checklist & Decision -->
        <div style="display:flex; flex-direction:column; justify-content:space-between;">
          <div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
              <div>
                <h4 style="margin:0; font-size:0.95rem; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:8px;">
                  <i class="fa-solid fa-list-check" style="color:#16a34a;"></i> Validation Checklist
                </h4>
                <span style="font-size:0.74rem; color:#64748b;">Verify 6 criteria before endorsement</span>
              </div>
              <div style="display:flex; align-items:center; gap:8px;">
                <span id="sscChecklistBadge" style="font-size:0.75rem; font-weight:700; background:#eff6ff; color:#2563eb; padding:3px 8px; border-radius:6px; border:1px solid #bfdbfe;">
                  0 of 6 verified
                </span>
                <button type="button" class="card-btn btn-sm" onclick="toggleAllSscChecklist()" style="font-size:0.72rem; padding:3px 8px; background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;">
                  Check All
                </button>
              </div>
            </div>

            <!-- 6 Checklist Items -->
            <div style="margin-bottom:16px;">
              <label class="ssc-checklist-item">
                <input type="checkbox" id="chk_ssc_org" onchange="updateSscChecklistCount()"/>
                <div class="ssc-checklist-label">
                  <strong>Recognized Organization</strong>
                  <div style="font-size:0.72rem; color:#64748b; font-weight:400;">Organization holds active accreditation and is in good standing</div>
                </div>
              </label>

              <label class="ssc-checklist-item">
                <input type="checkbox" id="chk_ssc_adviser" onchange="updateSscChecklistCount()"/>
                <div class="ssc-checklist-label">
                  <strong>Adviser Endorsement</strong>
                  <div style="font-size:0.72rem; color:#64748b; font-weight:400;">Faculty adviser has verified and endorsed the activity proposal</div>
                </div>
              </label>

              <label class="ssc-checklist-item">
                <input type="checkbox" id="chk_ssc_schedule" onchange="updateSscChecklistCount()"/>
                <div class="ssc-checklist-label">
                  <strong>Schedule Clearance</strong>
                  <div style="font-size:0.72rem; color:#64748b; font-weight:400;">No conflict with academic exam blackouts or institutional events</div>
                </div>
              </label>

              <label class="ssc-checklist-item">
                <input type="checkbox" id="chk_ssc_venue" onchange="updateSscChecklistCount()"/>
                <div class="ssc-checklist-label">
                  <strong>Venue Availability</strong>
                  <div style="font-size:0.72rem; color:#64748b; font-weight:400;">Facility cleared of collisions and suitable for attendee capacity</div>
                </div>
              </label>

              <label class="ssc-checklist-item">
                <input type="checkbox" id="chk_ssc_info" onchange="updateSscChecklistCount()"/>
                <div class="ssc-checklist-label">
                  <strong>Complete Information</strong>
                  <div style="font-size:0.72rem; color:#64748b; font-weight:400;">Program objectives, schedule, timeline and expected attendees complete</div>
                </div>
              </label>

              <label class="ssc-checklist-item">
                <input type="checkbox" id="chk_ssc_attachments" onchange="updateSscChecklistCount()"/>
                <div class="ssc-checklist-label">
                  <strong>Required Attachments</strong>
                  <div style="font-size:0.72rem; color:#64748b; font-weight:400;">Event proposal, safety protocol &amp; budget sheet attached</div>
                </div>
              </label>
            </div>

            <!-- Review Remarks Input -->
            <div class="form-group" style="margin-bottom:8px;">
              <label style="font-size:0.78rem; font-weight:700; color:#334155;">SSC Review Remarks / Instructions:</label>
              <textarea id="sscReviewRemarks" rows="3" placeholder="Specify endorsement remarks, feedback, or revision requirements..." style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:10px 12px; font-size:0.83rem; line-height:1.4; box-sizing:border-box;"></textarea>
            </div>
          </div>

          <!-- Review Panel Action Buttons (3 Decisions) -->
          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px; margin-top:14px;">
            <div style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:8px;">Endorsement Decision:</div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1.3fr; gap:8px;">
              <!-- 1. Return for Revision -->
              <button type="button" class="card-btn" style="background:#f59e0b; color:#fff; font-weight:700; padding:10px 8px; font-size:0.78rem; display:inline-flex; align-items:center; justify-content:center; gap:5px;" onclick="submitSscReviewDecision('return')" title="Return proposal to club adviser for revisions">
                <i class="fa-solid fa-rotate-left"></i> Return for Revision
              </button>
              <!-- 2. Reject -->
              <button type="button" class="card-btn" style="background:#dc2626; color:#fff; font-weight:700; padding:10px 8px; font-size:0.78rem; display:inline-flex; align-items:center; justify-content:center; gap:5px;" onclick="submitSscReviewDecision('reject')" title="Reject event proposal">
                <i class="fa-solid fa-xmark"></i> Reject
              </button>
              <!-- 3. Endorse to Admin -->
              <button type="button" class="card-btn" style="background:#16a34a; color:#fff; font-weight:700; padding:10px 12px; font-size:0.82rem; display:inline-flex; align-items:center; justify-content:center; gap:6px;" onclick="submitSscReviewDecision('endorse')" title="Endorse proposal and forward to System Admin">
                <i class="fa-solid fa-paper-plane"></i> Endorse to Admin
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Footer -->
    <div class="modal-actions" style="padding:12px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
      <span style="font-size:0.75rem; color:#64748b;"><i class="fa-solid fa-shield-halved" style="color:#2563eb;"></i> SSC Official Governance Oversight</span>
      <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:600;" onclick="closeModal('sscEventReviewModal')">Close</button>
    </div>
  </div>
</div>

<!-- Return for Revision Standalone Modal -->
<div class="modal-overlay" id="returnEventModal" style="display:none;">
  <div class="modal" style="max-width:500px; padding:0; overflow:hidden; border-radius:16px;">
    <div class="modal-header" style="background:#f59e0b; color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:1.05rem; color:#ffffff; font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-rotate-left"></i> Return Event for Revision
      </h3>
      <button class="modal-close" onclick="closeModal('returnEventModal')" type="button" style="color:#ffffff; opacity:0.9; font-size:1.1rem; background:none; border:none; cursor:pointer;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" style="padding:24px;">
      <input type="hidden" id="returnEventId" value=""/>
      <p id="returnEventDesc" style="color:#475569; margin-bottom:14px; font-size:0.86rem; line-height:1.45;"></p>
      <div class="form-group">
        <label style="font-weight:700; font-size:0.8rem; color:#334155;">Required Revisions / Feedback <span style="color:#ef4444;">*</span></label>
        <textarea id="returnEventNote" rows="3" placeholder="Detail the revisions needed from the organization adviser..." style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:10px 12px; font-size:0.83rem; box-sizing:border-box;"></textarea>
      </div>
    </div>
    <div class="modal-actions" style="padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px;">
      <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:600;" onclick="closeModal('returnEventModal')">Cancel</button>
      <button type="button" class="card-btn" id="confirmReturnEventBtn" style="background:#f59e0b; color:#fff; padding:9px 18px; font-weight:700;" onclick="submitReturnEvent()"><i class="fa-solid fa-rotate-left"></i> Return for Revision</button>
    </div>
  </div>
</div>

<?php if ($sess_role === 'admin'): ?>
<!-- Admin Override Clearance Modal -->
<div class="modal-overlay" id="adminOverrideModal" style="display:none;">
  <div class="modal" style="max-width:520px; padding:0; overflow:hidden; border-radius:16px;">
    <div class="modal-header" style="background:linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:1.05rem; color:#ffffff; font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-bolt"></i> Admin Override Clearance
      </h3>
      <button class="modal-close" onclick="closeModal('adminOverrideModal')" type="button" style="color:#ffffff; opacity:0.9; font-size:1.1rem; background:none; border:none; cursor:pointer;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" style="padding:24px;">
      <input type="hidden" id="adminOverrideEventId" value=""/>
      <p id="adminOverrideEventDesc" style="color:#1e293b; font-weight:600; margin-bottom:12px; font-size:0.9rem; line-height:1.4;"></p>
      
      <div id="adminOverrideConflictAlert" style="display:none; margin-bottom:14px; background:#fff7ed; border:1px solid #fdba74; color:#c2410c; padding:10px 14px; border-radius:8px; font-size:0.82rem; line-height:1.4;">
        <i class="fa-solid fa-triangle-exclamation" style="margin-right:4px;"></i><strong>Schedule Notice:</strong> This event has an overlapping venue conflict. Executing this override grants priority administrative clearance and publishes the event to the campus calendar.
      </div>

      <div class="form-group" style="margin-bottom:12px;">
        <label style="font-weight:700; font-size:0.8rem; color:#334155; margin-bottom:6px; display:block;">
          Override Justification / Clearance Note <span style="color:#ef4444;">*</span>
        </label>
        <textarea id="adminOverrideReason" rows="3" placeholder="e.g. Authorized priority venue booking / Special executive clearance..." style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:10px 12px; font-size:0.83rem; box-sizing:border-box;"></textarea>
      </div>

      <p style="font-size:0.75rem; color:#64748b; margin:0; line-height:1.35;">
        <i class="fa-solid fa-shield-halved" style="color:#7c3aed;"></i> This will immediately set the status to <strong>Approved</strong>, publish the event to the active campus calendar, log an override entry in the system audit trail, and notify all stakeholders.
      </p>
    </div>
    <div class="modal-actions" style="padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px;">
      <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:600;" onclick="closeModal('adminOverrideModal')">Cancel</button>
      <button type="button" class="card-btn" id="confirmAdminOverrideBtn" style="background:#7c3aed; color:#fff; padding:9px 18px; font-weight:700;" onclick="submitAdminOverride()">
        <i class="fa-solid fa-bolt"></i> Grant Override Clearance
      </button>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script src="../js/dashboard.js?v=<?= filemtime(__DIR__ . '/../js/dashboard.js') ?>"></script>
<script src="../js/table-pagination.js"></script>
<script>
const ROLE = '<?= $sess_role ?>';
const ALL_EVENTS = <?= json_encode($events, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const MY_REG_IDS = <?= json_encode($my_reg_ids) ?>;
const PH_HOLIDAYS = <?= json_encode($ph_holidays_all, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

let currentDate = new Date();
let calSearchQuery = '';

// Universal tokenized search matcher: matches any letter or multi-word query across fields
function matchesSearchQuery(text, query) {
  if (!query) return true;
  if (!text) return false;
  const words = query.toLowerCase().trim().split(/\s+/).filter(w => w.length > 0);
  if (words.length === 0) return true;
  const target = text.toLowerCase();
  return words.every(word => target.includes(word));
}

function switchEventsView(viewName) {
  const calSection = document.getElementById('activeCalendarSection');
  const sscPipeline = document.getElementById('sscPipelineSection');
  const adminPipeline = document.getElementById('adminPipelineSection');
  const eventsList = document.getElementById('eventsListCard');
  const btnCal = document.getElementById('btnViewCalendar');
  const btnPipe = document.getElementById('btnViewPipeline');

  if (viewName === 'calendar') {
    if (calSection) calSection.style.display = 'block';
    if (sscPipeline) sscPipeline.style.display = 'none';
    if (adminPipeline) adminPipeline.style.display = 'none';
    if (eventsList && ROLE !== 'student') eventsList.style.display = 'none';

    btnCal?.classList.add('active');
    btnPipe?.classList.remove('active');

    document.querySelectorAll('#dropEvents a').forEach(a => {
      a.classList.toggle('active', a.href.includes('view=calendar'));
    });

    try {
      history.replaceState(null, '', 'events.php?view=calendar');
    } catch (e) {}

    if (typeof renderCalendar === 'function') {
      renderCalendar();
    }
  } else {
    if (calSection && ROLE !== 'student') calSection.style.display = 'none';
    if (sscPipeline) sscPipeline.style.display = 'block';
    if (adminPipeline) adminPipeline.style.display = 'block';
    if (eventsList) eventsList.style.display = 'block';

    btnCal?.classList.remove('active');
    btnPipe?.classList.add('active');

    document.querySelectorAll('#dropEvents a').forEach(a => {
      a.classList.toggle('active', a.href.includes('view=pipeline'));
    });

    try {
      history.replaceState(null, '', 'events.php?view=pipeline');
    } catch (e) {}
  }
}
window.switchEventsView = switchEventsView;

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

    let cellHasMatch = false;

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

    // 🇵🇭 Render Philippine Holiday / Special Non-Working Day / Academic Exam Blackout Pill
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

      if (calSearchQuery) {
        const holFullText = [hol.name, hol.filipino_name, hol.category, hol.type, hol.description, 'exam', 'midterm', 'final', 'examination'].filter(Boolean).join(' ');
        if (matchesSearchQuery(holFullText, calSearchQuery)) {
          cellHasMatch = true;
          hClass += ' cal-pill-matched';
        } else {
          hClass += ' cal-pill-dimmed';
        }
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

      if (calSearchQuery) {
        const evFullText = [ev.title, ev.club_code, ev.club_name, ev.venue, ev.description].filter(Boolean).join(' ');
        if (matchesSearchQuery(evFullText, calSearchQuery)) {
          cellHasMatch = true;
          pillClass += ' cal-pill-matched';
        } else {
          pillClass += ' cal-pill-dimmed';
        }
      }

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

    if (calSearchQuery) {
      if (cellHasMatch) {
        cell.classList.add('cal-cell-matched');
      } else {
        cell.classList.add('cal-cell-dimmed');
      }
    }

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
  const regBtn = document.querySelector('#viewEventModal button[onclick*="registerForEvent"]');
  if (regBtn) {
    regBtn.disabled = true;
    regBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Registering...';
  }

  const fd = new FormData();
  fd.append('action', 'register');
  fd.append('event_id', id);

  fetch('../shared/event_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        const intId = parseInt(id);
        if (!MY_REG_IDS.includes(intId)) {
          MY_REG_IDS.push(intId);
        }
        if (regBtn) {
          regBtn.outerHTML = `
            <div style="padding:8px 14px; background:#dcfce7; border:1px solid #86efac; border-radius:8px; display:inline-flex; align-items:center; gap:6px; color:#15803d; font-weight:700; font-size:0.82rem;">
              <i class="fa-solid fa-circle-check"></i> Registered (Confirmed)
            </div>`;
        }
        renderCalendar();
        showToast(res.message, 'success');
      } else {
        if (regBtn) {
          regBtn.disabled = false;
          regBtn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Register for Event / Activity';
        }
        showToast(res.message || 'Registration failed.', 'error');
      }
    })
    .catch(() => {
      if (regBtn) {
        regBtn.disabled = false;
        regBtn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Register for Event / Activity';
      }
      showToast('Network error while processing registration.', 'error');
    });
}

document.getElementById('calPrevBtn')?.addEventListener('click', () => {
  currentDate.setMonth(currentDate.getMonth() - 1);
  renderCalendar();
});

document.getElementById('calNextBtn')?.addEventListener('click', () => {
  currentDate.setMonth(currentDate.getMonth() + 1);
  renderCalendar();
});

// Calendar Search Bar & Live Dropdown Handler
const calSearchInput = document.getElementById('calSearchInput');
const calSearchClear = document.getElementById('calSearchClear');
const calSearchResults = document.getElementById('calSearchResults');

if (calSearchInput) {
  calSearchInput.addEventListener('input', (e) => {
    calSearchQuery = e.target.value.trim().toLowerCase();

    if (calSearchClear) {
      calSearchClear.style.display = calSearchQuery ? 'inline-flex' : 'none';
    }

    renderCalendar();

    // Render quick search dropdown for matches across all months
    if (calSearchResults) {
      if (!calSearchQuery || calSearchQuery.length < 1) {
        calSearchResults.style.display = 'none';
        calSearchResults.innerHTML = '';
      } else {
        // 1. Search across ALL_EVENTS
        const eventMatches = ALL_EVENTS.filter(ev => {
          const t = [ev.title, ev.club_code, ev.club_name, ev.venue, ev.description].filter(Boolean).join(' ');
          return matchesSearchQuery(t, calSearchQuery);
        }).map(ev => ({
          type: 'event',
          id: ev.id,
          title: ev.title,
          badge: ev.club_code || 'CAMPUS',
          badgeColor: '#2563eb',
          sub: ev.venue || 'Campus Venue',
          dateStr: ev.event_date.slice(0, 10),
          raw: ev
        }));

        // 2. Search across PH_HOLIDAYS (including Academic Exam Blackouts)
        const holidayMatches = [];
        for (const [dStr, hol] of Object.entries(PH_HOLIDAYS)) {
          const t = [hol.name, hol.filipino_name, hol.category, hol.type, hol.description, 'exam', 'midterm', 'final', 'examination'].filter(Boolean).join(' ');
          if (matchesSearchQuery(t, calSearchQuery)) {
            let bColor = '#dc2626';
            if (hol.type === 'special_non_working') bColor = '#7c3aed';
            else if (hol.type === 'exam_blackout') bColor = '#d97706';

            holidayMatches.push({
              type: 'holiday',
              id: dStr,
              title: hol.name,
              badge: hol.category || 'Holiday',
              badgeColor: bColor,
              sub: hol.filipino_name || (hol.type === 'exam_blackout' ? 'Academic Blackout' : 'Philippine Holiday'),
              dateStr: dStr,
              raw: hol
            });
          }
        }

        const matches = [...eventMatches, ...holidayMatches].sort((a, b) => a.dateStr.localeCompare(b.dateStr));

        if (matches.length === 0) {
          calSearchResults.innerHTML = `<div style="padding:12px; font-size:0.8rem; color:#64748b; text-align:center;"><i class="fa-solid fa-circle-question" style="margin-right:6px;"></i>No matching events or holidays found</div>`;
          calSearchResults.style.display = 'block';
        } else {
          calSearchResults.innerHTML = matches.slice(0, 12).map(item => {
            const itemDate = new Date(item.dateStr + 'T00:00:00');
            const dateFmt = !isNaN(itemDate) ? itemDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : item.dateStr;
            return `
              <div class="cal-search-result-item" data-type="${item.type}" data-id="${item.id}" data-date="${item.dateStr}">
                <div style="min-width:0; flex:1;">
                  <div class="cal-search-result-title">${item.title}</div>
                  <div style="font-size:0.72rem; color:#64748b; display:flex; gap:6px; align-items:center;">
                    <span style="font-weight:700; color:${item.badgeColor};">${item.badge}</span> &bull; <span>${item.sub}</span>
                  </div>
                </div>
                <div class="cal-search-result-date">${dateFmt}</div>
              </div>
            `;
          }).join('');
          calSearchResults.style.display = 'block';

          calSearchResults.querySelectorAll('.cal-search-result-item').forEach(el => {
            el.addEventListener('click', () => {
              const type = el.getAttribute('data-type');
              const id = el.getAttribute('data-id');
              const dateStr = el.getAttribute('data-date');
              calSearchResults.style.display = 'none';

              const parts = dateStr.split('-');
              const yr = parseInt(parts[0]);
              const mo = parseInt(parts[1]) - 1;
              currentDate = new Date(yr, mo, 1);
              renderCalendar();

              locateOnCalendar(dateStr, id);

              if (type === 'event') {
                const foundEv = ALL_EVENTS.find(e => parseInt(e.id) === parseInt(id));
                if (foundEv) setTimeout(() => viewEvent(foundEv), 350);
              } else {
                const foundHol = PH_HOLIDAYS[dateStr];
                if (foundHol) setTimeout(() => viewHolidayModal(foundHol), 350);
              }
            });
          });
        }
      }
    }
  });

  // Support Enter key to automatically jump to the first matching event or holiday
  calSearchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      const firstItem = calSearchResults?.querySelector('.cal-search-result-item');
      if (firstItem) {
        firstItem.click();
      }
    }
  });

  document.addEventListener('click', (e) => {
    if (!e.target.closest('#calSearchBox') && calSearchResults) {
      calSearchResults.style.display = 'none';
    }
  });
}

if (calSearchClear) {
  calSearchClear.addEventListener('click', () => {
    if (calSearchInput) calSearchInput.value = '';
    calSearchQuery = '';
    calSearchClear.style.display = 'none';
    if (calSearchResults) {
      calSearchResults.style.display = 'none';
      calSearchResults.innerHTML = '';
    }
    renderCalendar();
    if (calSearchInput) calSearchInput.focus();
  });
}

function resetCreateEventForm() {
  const form = document.getElementById('createEventForm');
  if (form) form.reset();
  const resBox = document.getElementById('conflictAuditResult');
  if (resBox) {
    resBox.style.display = 'none';
    resBox.innerHTML = '';
  }
  const btnAudit = document.getElementById('btnAuditModalDate');
  if (btnAudit) {
    btnAudit.disabled = false;
    btnAudit.innerHTML = '<i class="fa-solid fa-shield-halved"></i> AI Audit Date &amp; Check Conflicts';
  }
}

function openCreateEventModal() {
  resetCreateEventForm();
  openModal('createEventModal');
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    renderCalendar();
    resetCreateEventForm();
  });
} else {
  renderCalendar();
  resetCreateEventForm();
}

function openModal(id)  {
  const el = document.getElementById(id);
  if (el) { el.classList.add('active'); el.style.display = 'flex'; }
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.remove('active', 'open'); el.style.display = 'none'; }
  if (id === 'createEventModal') {
    resetCreateEventForm();
  }
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
  if (ROLE !== 'club_adviser' && ev.status !== 'Rejected') {
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

      <div style="margin-top:4px; padding-top:14px; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:600; padding:8px 16px; border-radius:8px; cursor:pointer;" onclick="closeModal('viewEventModal')">Close</button>
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

// Endorse Event (SSC -> Admin)
async function endorseEvent(id, title) {
  const notes = await window.showDecisionModal(
    'Endorse Event Proposal?',
    `Endorse event "${title}" to System Admin for final calendar clearance. Enter endorsement notes:`,
    { defaultValue: 'Endorsed by SSC.', confirmText: 'Endorse & Forward', requireInput: false }
  );
  if (notes === false || notes === null) return;
  const fd = new FormData();
  fd.append('action', 'ssc_endorse');
  fd.append('id', id);
  fd.append('notes', notes);
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) fd.append('csrf_token', csrfMeta.content);
  fetch('../shared/event_actions.php', { method: 'POST', body: fd })
    .then(r => r.json()).then(res => {
      if (res.success) { showToast('Event endorsed to System Admin!', 'success'); setTimeout(() => location.reload(), 1200); }
      else showToast(res.message, 'error');
    }).catch(() => showToast('Network error.', 'error'));
}

// Admin Final Approve (Admin -> Approved on Calendar)
async function adminApproveEvent(id, title) {
  const confirmed = await window.showConfirmModal(
    'Approve & Publish Event?',
    `Grant final clearance and publish "${title}" to the active campus calendar?`,
    { type: 'decision', confirmText: 'Approve & Publish' }
  );
  if (!confirmed) return;
  const fd = new FormData();
  fd.append('action', 'admin_approve');
  fd.append('id', id);
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) fd.append('csrf_token', csrfMeta.content);
  fetch('../shared/event_actions.php', { method: 'POST', body: fd })
    .then(r => r.json()).then(res => {
      if (res.success) { showToast('Event approved and posted to campus calendar!', 'success'); setTimeout(() => location.reload(), 1200); }
      else showToast(res.message, 'error');
    }).catch(() => showToast('Network error.', 'error'));
}

// Approve event (generic legacy alias)
function approveEvent(id, title) {
  <?php if ($sess_role === 'ssc'): ?>
    endorseEvent(id, title);
  <?php else: ?>
    adminApproveEvent(id, title);
  <?php endif; ?>
}

// Toggle Institutional vs Club fields in modal
function toggleEventScopeFields(val) {
  const wrap = document.getElementById('hostOrgGroupWrap');
  const titleInp = document.getElementById('createEventTitleInput');
  if (wrap) {
    if (val === 'Institutional') {
      wrap.style.display = 'none';
      if (titleInp && !titleInp.value) titleInp.placeholder = 'e.g. BCP Foundation Day 2026 Grand Celebration';
    } else {
      wrap.style.display = 'block';
      if (titleInp && !titleInp.value) titleInp.placeholder = 'e.g. Annual Hackathon & Innovation Summit 2026';
    }
  }
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
  if (!note) {
    await window.showSystemModal({
      title: 'Reason Required',
      message: 'Please provide a reason for rejecting this event proposal.',
      type: 'warning'
    });
    return;
  }
  const confirmed = await window.showConfirmModal(
    'Reject Event Proposal?',
    'Do you want to reject this event proposal with the specified reason?',
    { type: 'error', danger: true, confirmText: 'Yes, Reject Proposal' }
  );
  if (!confirmed) return;
  const fd = new FormData();
  fd.append('action','reject'); fd.append('id', rejectEvId); fd.append('note', note);
  const res = await fetch('../shared/event_actions.php', { method:'POST', body:fd }).then(r => r.json());
  closeModal('rejectEventModal');
  if (res.success) { showToast('Event rejected.', 'warning'); setTimeout(() => location.reload(), 1500); }
  else showToast(res.message, 'error');
});

// ── SSC EVENT REVIEW PANEL & QUEUE FUNCTIONS ─────────────────
let currentSscReviewEvent = null;

function openSscReviewPanel(ev) {
  currentSscReviewEvent = ev;
  const idEl = document.getElementById('sscRevEventId');
  if (idEl) idEl.value = ev.id;

  const refBadge = document.getElementById('sscRevRefBadge');
  if (refBadge) refBadge.textContent = ev.event_ref_id || ('EVT-' + ev.id);

  const statusBadge = document.getElementById('sscRevStatusBadge');
  if (statusBadge) {
    statusBadge.textContent = ev.status;
    statusBadge.style.color = (ev.status === 'Approved') ? '#86efac' : (ev.status === 'Rejected' ? '#fca5a5' : '#fde68a');
  }

  const titleEl = document.getElementById('sscRevTitle');
  if (titleEl) titleEl.textContent = ev.title;

  const clubEl = document.getElementById('sscRevClub');
  if (clubEl) clubEl.textContent = `${ev.club_code} - ${ev.club_name}`;

  const adviserEl = document.getElementById('sscRevAdviser');
  if (adviserEl) adviserEl.textContent = ev.adviser_name || 'Prof. BCP Faculty Adviser';

  const typeEl = document.getElementById('sscRevType');
  if (typeEl) typeEl.textContent = ev.event_type || 'Club';

  const dtEl = document.getElementById('sscRevDateTime');
  if (dtEl) {
    const d = new Date(ev.event_date);
    dtEl.textContent = !isNaN(d) ? d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : ev.event_date;
  }

  const venueEl = document.getElementById('sscRevVenue');
  if (venueEl) venueEl.textContent = ev.venue;

  const attEl = document.getElementById('sscRevAttendees');
  if (attEl) attEl.textContent = (ev.expected_attendees && parseInt(ev.expected_attendees) > 0) ? `${ev.expected_attendees} Expected Attendees` : 'Not specified';

  const subEl = document.getElementById('sscRevSubmitter');
  if (subEl) {
    const fullName = `${ev.first_name || ''} ${ev.last_name || ''}`.trim() || 'Club Adviser';
    const roleTxt = ev.creator_role ? ` (${ev.creator_role.replace('_', ' ')})` : '';
    subEl.textContent = fullName + roleTxt;
  }

  const subDtEl = document.getElementById('sscRevSubmitDate');
  if (subDtEl) {
    const sd = new Date(ev.created_at || ev.event_date);
    subDtEl.textContent = !isNaN(sd) ? sd.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
  }

  const descEl = document.getElementById('sscRevDesc');
  if (descEl) descEl.textContent = ev.description || 'No description or event objectives provided.';

  // Schedule & Venue Collision Banner
  const banner = document.getElementById('sscRevConflictBanner');
  if (banner) {
    if (ev.has_venue_conflict) {
      banner.style.display = 'block';
      banner.style.background = '#fff7ed';
      banner.style.border = '1px solid #fdba74';
      banner.style.color = '#9a3412';
      banner.innerHTML = `<i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i><strong>Schedule Notice:</strong> Another event is also scheduled at <strong>"${ev.venue}"</strong> on this date. Confirm venue coordination prior to endorsement.`;
    } else {
      banner.style.display = 'block';
      banner.style.background = '#f0fdf4';
      banner.style.border = '1px solid #86efac';
      banner.style.color = '#166534';
      banner.innerHTML = `<i class="fa-solid fa-circle-check" style="margin-right:6px;"></i><strong>Schedule Cleared:</strong> No conflicting events or calendar blackouts found for this date &amp; venue.`;
    }
  }

  // Attachment link
  const attWrap = document.getElementById('sscRevAttachmentWrap');
  if (attWrap) {
    if (ev.attachment) {
      attWrap.innerHTML = `
        <a href="../uploads/events/${encodeURIComponent(ev.attachment)}" target="_blank" class="card-btn btn-sm" style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; font-weight:700; display:inline-flex; align-items:center; gap:6px; text-decoration:none; padding:7px 12px; border-radius:8px;">
          <i class="fa-solid fa-file-pdf" style="font-size:1rem; color:#dc2626;"></i>
          <span>Download / View Proposal Document (${ev.attachment})</span>
        </a>
      `;
    } else {
      attWrap.innerHTML = `<span style="font-size:0.8rem; color:#94a3b8; font-style:italic;"><i class="fa-solid fa-file-circle-xmark" style="margin-right:4px;"></i> No proposal document attached</span>`;
    }
  }

  // Checklist initialization
  const isApprovedOrEndorsed = in_array_js(ev.status, ['Pending Admin', 'Approved', 'Completed']);
  ['chk_ssc_org', 'chk_ssc_adviser', 'chk_ssc_schedule', 'chk_ssc_venue', 'chk_ssc_info', 'chk_ssc_attachments'].forEach(chkId => {
    const chk = document.getElementById(chkId);
    if (chk) {
      if (isApprovedOrEndorsed) {
        chk.checked = true;
      } else if (ev.status === 'Rejected') {
        chk.checked = false;
      } else {
        // Default pending checks
        if (chkId === 'chk_ssc_org' || chkId === 'chk_ssc_adviser' || chkId === 'chk_ssc_info') {
          chk.checked = true;
        } else if (chkId === 'chk_ssc_venue' || chkId === 'chk_ssc_schedule') {
          chk.checked = !ev.has_venue_conflict;
        } else {
          chk.checked = !!ev.attachment;
        }
      }
    }
  });
  updateSscChecklistCount();

  // Remarks initialization
  const remEl = document.getElementById('sscReviewRemarks');
  if (remEl) {
    remEl.value = ev.rejection_note || (ev.endorsement_notes ? ev.endorsement_notes.replace('Endorsed by SSC: ', '') : '');
  }

  openModal('sscEventReviewModal');
}

function in_array_js(val, arr) {
  return arr.indexOf(val) !== -1;
}

function updateSscChecklistCount() {
  const chkIds = ['chk_ssc_org', 'chk_ssc_adviser', 'chk_ssc_schedule', 'chk_ssc_venue', 'chk_ssc_info', 'chk_ssc_attachments'];
  let count = 0;
  chkIds.forEach(id => {
    const el = document.getElementById(id);
    if (el && el.checked) count++;
  });
  const badge = document.getElementById('sscChecklistBadge');
  if (badge) {
    badge.textContent = `${count} of ${chkIds.length} verified`;
    if (count === chkIds.length) {
      badge.style.background = '#ecfdf5';
      badge.style.color = '#047857';
      badge.style.borderColor = '#a7f3d0';
    } else {
      badge.style.background = '#eff6ff';
      badge.style.color = '#2563eb';
      badge.style.borderColor = '#bfdbfe';
    }
  }
}

function toggleAllSscChecklist() {
  const chkIds = ['chk_ssc_org', 'chk_ssc_adviser', 'chk_ssc_schedule', 'chk_ssc_venue', 'chk_ssc_info', 'chk_ssc_attachments'];
  const allChecked = chkIds.every(id => {
    const el = document.getElementById(id);
    return el && el.checked;
  });
  chkIds.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.checked = !allChecked;
  });
  updateSscChecklistCount();
}

async function submitSscReviewDecision(decision) {
  const idEl = document.getElementById('sscRevEventId');
  const id = idEl ? parseInt(idEl.value) : 0;
  if (!id) {
    await window.showSystemModal({
      title: 'Invalid Selection',
      message: 'No event proposal was selected for review.',
      type: 'error'
    });
    return;
  }

  const remarks = (document.getElementById('sscReviewRemarks')?.value || '').trim();

  if (decision === 'return') {
    if (!remarks) {
      await window.showSystemModal({
        title: 'Instructions Required',
        message: 'Please provide instructions or remarks for the required revisions.',
        type: 'warning'
      });
      document.getElementById('sscReviewRemarks')?.focus();
      return;
    }

    const confirmed = await window.showConfirmModal(
      'Return Event for Revision?',
      'Do you want to return this event proposal for revision? The organizing club will be notified with your instructions and must submit a revised proposal.',
      { type: 'warning', warning: true, confirmText: 'Yes, Return for Revision' }
    );
    if (!confirmed) return;

    const fd = new FormData();
    fd.append('action', 'return_for_revision');
    fd.append('id', id);
    fd.append('note', remarks);

    try {
      const res = await fetch('../shared/event_actions.php', { method: 'POST', body: fd }).then(r => r.json());
      closeModal('sscEventReviewModal');
      if (res.success) {
        showToast('Event proposal returned for revision.', 'warning');
        setTimeout(() => location.reload(), 1200);
      } else {
        showToast(res.message || 'Failed to return proposal.', 'error');
      }
    } catch (err) {
      showToast('Network error.', 'error');
    }
    return;
  }

  if (decision === 'reject') {
    if (!remarks) {
      await window.showSystemModal({
        title: 'Reason Required',
        message: 'Please provide a reason for rejecting this event proposal.',
        type: 'warning'
      });
      document.getElementById('sscReviewRemarks')?.focus();
      return;
    }

    const confirmed = await window.showConfirmModal(
      'Reject Event Proposal?',
      'Do you want to reject this event proposal? This will log your rejection rationale into the governance audit trail.',
      { type: 'error', danger: true, confirmText: 'Yes, Reject Proposal' }
    );
    if (!confirmed) return;

    const fd = new FormData();
    fd.append('action', 'reject');
    fd.append('id', id);
    fd.append('note', remarks);

    try {
      const res = await fetch('../shared/event_actions.php', { method: 'POST', body: fd }).then(r => r.json());
      closeModal('sscEventReviewModal');
      if (res.success) {
        showToast('Event proposal rejected.', 'warning');
        setTimeout(() => location.reload(), 1200);
      } else {
        showToast(res.message || 'Failed to reject event.', 'error');
      }
    } catch (err) {
      showToast('Network error.', 'error');
    }
    return;
  }

  if (decision === 'endorse') {
    const chkLabels = [];
    if (document.getElementById('chk_ssc_org')?.checked) chkLabels.push('Recognized Org');
    if (document.getElementById('chk_ssc_adviser')?.checked) chkLabels.push('Adviser Endorsed');
    if (document.getElementById('chk_ssc_schedule')?.checked) chkLabels.push('Schedule Cleared');
    if (document.getElementById('chk_ssc_venue')?.checked) chkLabels.push('Venue Verified');
    if (document.getElementById('chk_ssc_info')?.checked) chkLabels.push('Info Complete');
    if (document.getElementById('chk_ssc_attachments')?.checked) chkLabels.push('Attachments Verified');

    const confirmed = await window.showConfirmModal(
      'Endorse Proposal to Admin?',
      `Are you sure you want to endorse this event to System Admin? (${chkLabels.length} compliance checkpoints verified).`,
      { type: 'decision', confirmText: 'Endorse & Submit' }
    );
    if (!confirmed) return;

    const fd = new FormData();
    fd.append('action', 'ssc_endorse');
    fd.append('id', id);
    fd.append('notes', remarks || 'Verified and endorsed by SSC.');
    chkLabels.forEach(lbl => fd.append('checklist[]', lbl));

    try {
      const res = await fetch('../shared/event_actions.php', { method: 'POST', body: fd }).then(r => r.json());
      closeModal('sscEventReviewModal');
      if (res.success) {
        showToast('Event successfully endorsed to System Admin!', 'success');
        setTimeout(() => location.reload(), 1200);
      } else {
        showToast(res.message || 'Failed to endorse event.', 'error');
      }
    } catch (err) {
      showToast('Network error.', 'error');
    }
    return;
  }
}

// ── SSC APPROVAL QUEUE SEARCH & FILTERS ──────────────────────
window.sscFilterConflictOnly = false;

function filterSscApprovalQueue() {
  const q = (document.getElementById('sscQueueSearchInput')?.value || '').toLowerCase().trim();
  const statusFilter = document.getElementById('sscQueueStatusFilter')?.value || '';
  const typeFilter = document.getElementById('sscQueueTypeFilter')?.value || '';
  const conflictOnly = window.sscFilterConflictOnly;

  const rows = document.querySelectorAll('#sscApprovalQueueTable tbody tr.queue-row');
  let matchCount = 0;

  rows.forEach(tr => {
    const text = tr.innerText.toLowerCase();
    const rowStatus = tr.getAttribute('data-status') || '';
    const rowType = tr.getAttribute('data-type') || '';
    const rowConflict = tr.getAttribute('data-conflict') === '1';

    let match = true;
    if (q && !text.includes(q)) match = false;
    if (statusFilter) {
      if (statusFilter === 'Rejected_Returned') {
        if (rowStatus !== 'Rejected' && rowStatus !== 'Returned') match = false;
      } else if (statusFilter === 'Approved') {
        if (rowStatus !== 'Approved' && rowStatus !== 'Upcoming' && rowStatus !== 'Completed') match = false;
      } else if (rowStatus !== statusFilter) {
        match = false;
      }
    }
    if (typeFilter && rowType !== typeFilter) match = false;
    if (conflictOnly && !rowConflict) match = false;

    if (match) {
      tr.removeAttribute('data-search-hidden');
      matchCount++;
    } else {
      tr.setAttribute('data-search-hidden', 'true');
    }
  });

  const emptyRow = document.getElementById('sscQueueEmptyRow');
  if (emptyRow) {
    emptyRow.style.display = matchCount === 0 ? '' : 'none';
  }

  // Refresh paginator
  const tbl = document.getElementById('sscApprovalQueueTable');
  if (tbl && tbl._paginator) {
    tbl._paginator.currentPage = 1;
    tbl._paginator.render();
  }
}

function filterSscByCard(filterKey) {
  // Clear card active styling
  document.querySelectorAll('.ssc-metric-card').forEach(c => c.classList.remove('active-card-filter'));

  const sFilter = document.getElementById('sscQueueStatusFilter');
  const tFilter = document.getElementById('sscQueueTypeFilter');
  const conflictNotice = document.getElementById('sscConflictNoticeBar');

  if (filterKey === 'Conflict') {
    window.sscFilterConflictOnly = !window.sscFilterConflictOnly;
    if (window.sscFilterConflictOnly) {
      document.getElementById('cardVenueConflicts')?.classList.add('active-card-filter');
      if (conflictNotice) conflictNotice.style.display = 'flex';
    } else {
      if (conflictNotice) conflictNotice.style.display = 'none';
    }
    if (sFilter) sFilter.value = '';
    if (tFilter) tFilter.value = '';
  } else if (filterKey === 'Institutional') {
    window.sscFilterConflictOnly = false;
    if (conflictNotice) conflictNotice.style.display = 'none';
    document.getElementById('cardInstitutionalEvents')?.classList.add('active-card-filter');
    if (tFilter) tFilter.value = 'Institutional';
    if (sFilter) sFilter.value = '';
  } else if (filterKey === 'Rejected_Returned') {
    window.sscFilterConflictOnly = false;
    if (conflictNotice) conflictNotice.style.display = 'none';
    document.getElementById('cardRejectedEvents')?.classList.add('active-card-filter');
    if (sFilter) sFilter.value = 'Returned';
    if (tFilter) tFilter.value = '';
  } else if (filterKey === 'Pending SSC') {
    window.sscFilterConflictOnly = false;
    if (conflictNotice) conflictNotice.style.display = 'none';
    document.getElementById('cardPendingSsc')?.classList.add('active-card-filter');
    if (sFilter) sFilter.value = 'Pending SSC';
    if (tFilter) tFilter.value = '';
  } else if (filterKey === 'Pending Admin') {
    window.sscFilterConflictOnly = false;
    if (conflictNotice) conflictNotice.style.display = 'none';
    document.getElementById('cardEndorsedMonth')?.classList.add('active-card-filter');
    if (sFilter) sFilter.value = 'Pending Admin';
    if (tFilter) tFilter.value = '';
  } else if (filterKey === 'Approved') {
    window.sscFilterConflictOnly = false;
    if (conflictNotice) conflictNotice.style.display = 'none';
    document.getElementById('cardUpcomingEvents')?.classList.add('active-card-filter');
    if (sFilter) sFilter.value = 'Approved';
    if (tFilter) tFilter.value = '';
  }

  filterSscApprovalQueue();

  // Auto-scroll to queue table if in pipeline view
  const queueCard = document.getElementById('sscApprovalQueueCard');
  if (queueCard && queueCard.style.display !== 'none') {
    queueCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}

function resetSscQueueFilter() {
  window.sscFilterConflictOnly = false;
  const sInput = document.getElementById('sscQueueSearchInput');
  if (sInput) sInput.value = '';
  const sFilter = document.getElementById('sscQueueStatusFilter');
  if (sFilter) sFilter.value = '';
  const tFilter = document.getElementById('sscQueueTypeFilter');
  if (tFilter) tFilter.value = '';
  const notice = document.getElementById('sscConflictNoticeBar');
  if (notice) notice.style.display = 'none';
  document.querySelectorAll('.ssc-metric-card').forEach(c => c.classList.remove('active-card-filter'));
  filterSscApprovalQueue();
}

function quickSscEndorse(id, title) {
  endorseEvent(id, title);
}

function quickSscReject(id, title) {
  rejectEvent(id, title);
}

let returnTargetEvId = 0;
function openReturnEventModal(id, title) {
  returnTargetEvId = id;
  const idEl = document.getElementById('returnEventId');
  if (idEl) idEl.value = id;
  const descEl = document.getElementById('returnEventDesc');
  if (descEl) descEl.textContent = `Return proposal: "${title}" for revisions.`;
  const noteEl = document.getElementById('returnEventNote');
  if (noteEl) noteEl.value = '';
  openModal('returnEventModal');
}

async function submitReturnEvent() {
  const note = (document.getElementById('returnEventNote')?.value || '').trim();
  if (!note) {
    await window.showSystemModal({
      title: 'Instructions Required',
      message: 'Please provide instructions for the revision.',
      type: 'warning'
    });
    document.getElementById('returnEventNote')?.focus();
    return;
  }
  const confirmed = await window.showConfirmModal(
    'Return Event for Revision?',
    'Do you want to return this event proposal to the club organizers for revision?',
    { type: 'warning', confirmText: 'Yes, Return for Revision' }
  );
  if (!confirmed) return;

  const id = returnTargetEvId || parseInt(document.getElementById('returnEventId')?.value || '0');
  const fd = new FormData();
  fd.append('action', 'return_for_revision');
  fd.append('id', id);
  fd.append('note', note);

  try {
    const res = await fetch('../shared/event_actions.php', { method: 'POST', body: fd }).then(r => r.json());
    closeModal('returnEventModal');
    if (res.success) {
      showToast('Event proposal returned for revision.', 'warning');
      setTimeout(() => location.reload(), 1200);
    } else {
      showToast(res.message || 'Failed to return event.', 'error');
    }
  } catch (err) {
    showToast('Network error.', 'error');
  }
}

// ── ADMIN EVENT ADMINISTRATION QUEUE FUNCTIONS ───────────────
let currentAdminOverrideId = 0;

function openAdminOverrideModal(id, title, hasConflict) {
  currentAdminOverrideId = id;
  const idEl = document.getElementById('adminOverrideEventId');
  if (idEl) idEl.value = id;
  const descEl = document.getElementById('adminOverrideEventDesc');
  if (descEl) descEl.textContent = `Grant administrative override clearance for "${title}"`;
  const alertEl = document.getElementById('adminOverrideConflictAlert');
  if (alertEl) alertEl.style.display = hasConflict ? 'block' : 'none';
  const reasonEl = document.getElementById('adminOverrideReason');
  if (reasonEl) reasonEl.value = '';
  openModal('adminOverrideModal');
}

async function submitAdminOverride() {
  const reason = (document.getElementById('adminOverrideReason')?.value || '').trim();
  if (!reason) {
    await window.showSystemModal({
      title: 'Justification Required',
      message: 'Please enter an administrative override justification.',
      type: 'warning'
    });
    document.getElementById('adminOverrideReason')?.focus();
    return;
  }

  const confirmed = await window.showConfirmModal(
    'Grant Admin Override Clearance?',
    'Do you want to grant administrative override clearance and immediately approve this event proposal?',
    { type: 'warning', confirmText: 'Yes, Grant Clearance' }
  );
  if (!confirmed) return;

  const id = currentAdminOverrideId || parseInt(document.getElementById('adminOverrideEventId')?.value || '0');
  const btn = document.getElementById('confirmAdminOverrideBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...'; }

  const fd = new FormData();
  fd.append('action', 'admin_override');
  fd.append('id', id);
  fd.append('reason', reason);

  try {
    const res = await fetch('../shared/event_actions.php', { method: 'POST', body: fd }).then(r => r.json());
    closeModal('adminOverrideModal');
    if (res.success) {
      showToast('Event approved via administrative override clearance!', 'success');
      setTimeout(() => location.reload(), 1200);
    } else {
      showToast(res.message || 'Failed to apply override.', 'error');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Grant Override Clearance'; }
    }
  } catch (err) {
    showToast('Network error occurred.', 'error');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Grant Override Clearance'; }
  }
}

let activeAdminCardFilter = null;

function filterAdminByCard(type) {
  const cards = document.querySelectorAll('.admin-metric-card');
  const noticeBar = document.getElementById('adminFilterNoticeBar');
  const noticeText = document.getElementById('adminFilterNoticeText');

  // Toggle if clicked again
  if (activeAdminCardFilter === type) {
    resetAdminQueueFilter();
    return;
  }

  activeAdminCardFilter = type;
  cards.forEach(c => c.style.outline = 'none');

  if (noticeBar) noticeBar.style.display = 'flex';
  if (noticeText) noticeText.innerHTML = `<i class="fa-solid fa-filter"></i> Filtering table by <strong>${type}</strong>`;

  // Highlight active card
  const cardMap = {
    'Pending Admin': 'cardAdminPendingApproval',
    'Approved Today': 'cardAdminApprovedToday',
    'Rejected': 'cardAdminRejected',
    'Upcoming': 'cardAdminUpcoming',
    'Venue Conflicts': 'cardAdminVenueConflicts',
    'Overdue Proposals': 'cardAdminOverdueProposals'
  };
  const activeCardEl = document.getElementById(cardMap[type]);
  if (activeCardEl) {
    activeCardEl.style.outline = '2px solid #2563eb';
    activeCardEl.style.outlineOffset = '2px';
  }

  const rows = document.querySelectorAll('.admin-queue-row');
  let matchCount = 0;

  rows.forEach(r => {
    let show = false;
    const status = r.getAttribute('data-status');
    const conflict = r.getAttribute('data-conflict');
    const overdue = r.getAttribute('data-overdue');
    const apprToday = r.getAttribute('data-approved-today');
    const upcoming = r.getAttribute('data-upcoming');

    if (type === 'Pending Admin') {
      show = (status === 'Pending Admin');
    } else if (type === 'Approved Today') {
      show = (apprToday === '1' || status === 'Approved');
    } else if (type === 'Rejected') {
      show = (status === 'Rejected');
    } else if (type === 'Upcoming') {
      show = (upcoming === '1');
    } else if (type === 'Venue Conflicts') {
      show = (conflict === '1');
    } else if (type === 'Overdue Proposals') {
      show = (overdue === '1');
    }

    if (show) {
      r.removeAttribute('data-search-hidden');
      matchCount++;
    } else {
      r.setAttribute('data-search-hidden', 'true');
    }
  });

  const emptyRow = document.getElementById('adminQueueEmptyRow');
  if (emptyRow) {
    emptyRow.style.display = (matchCount === 0) ? '' : 'none';
  }

  const tbl = document.getElementById('adminEventQueueTable');
  if (tbl && tbl._paginator) {
    tbl._paginator.currentPage = 1;
    tbl._paginator.render();
  }
}

function filterAdminEventQueue() {
  const searchVal = (document.getElementById('adminQueueSearchInput')?.value || '').toLowerCase().trim();
  const statusVal = document.getElementById('adminQueueStatusFilter')?.value || '';
  const orgVal = document.getElementById('adminQueueOrgFilter')?.value || '';

  // Reset active card highlight if toolbar filters are used
  if (activeAdminCardFilter) {
    document.querySelectorAll('.admin-metric-card').forEach(c => c.style.outline = 'none');
    const noticeBar = document.getElementById('adminFilterNoticeBar');
    if (noticeBar) noticeBar.style.display = 'none';
    activeAdminCardFilter = null;
  }

  const rows = document.querySelectorAll('.admin-queue-row');
  let matchCount = 0;

  rows.forEach(r => {
    const text = r.textContent.toLowerCase();
    const status = r.getAttribute('data-status');
    const org = r.getAttribute('data-org');

    const matchesSearch = !searchVal || text.includes(searchVal);
    const matchesStatus = !statusVal || (status === statusVal);
    const matchesOrg = !orgVal || (org === orgVal);

    if (matchesSearch && matchesStatus && matchesOrg) {
      r.removeAttribute('data-search-hidden');
      matchCount++;
    } else {
      r.setAttribute('data-search-hidden', 'true');
    }
  });

  const emptyRow = document.getElementById('adminQueueEmptyRow');
  if (emptyRow) {
    emptyRow.style.display = (matchCount === 0) ? '' : 'none';
  }

  const tbl = document.getElementById('adminEventQueueTable');
  if (tbl && tbl._paginator) {
    tbl._paginator.currentPage = 1;
    tbl._paginator.render();
  }
}

function resetAdminQueueFilter() {
  activeAdminCardFilter = null;
  document.querySelectorAll('.admin-metric-card').forEach(c => c.style.outline = 'none');
  const noticeBar = document.getElementById('adminFilterNoticeBar');
  if (noticeBar) noticeBar.style.display = 'none';

  const sInp = document.getElementById('adminQueueSearchInput');
  if (sInp) sInp.value = '';
  const stFilter = document.getElementById('adminQueueStatusFilter');
  if (stFilter) stFilter.value = '';
  const orgFilter = document.getElementById('adminQueueOrgFilter');
  if (orgFilter) orgFilter.value = '';

  const rows = document.querySelectorAll('.admin-queue-row');
  rows.forEach(r => {
    r.removeAttribute('data-search-hidden');
  });

  const emptyRow = document.getElementById('adminQueueEmptyRow');
  if (emptyRow) {
    emptyRow.style.display = (rows.length === 0) ? '' : 'none';
  }

  const tbl = document.getElementById('adminEventQueueTable');
  if (tbl && tbl._paginator) {
    tbl._paginator.currentPage = 1;
    tbl._paginator.render();
  }
}

// Auto initialize pagination for admin table when loaded
function initAdminEventQueuePagination() {
  const tbl = document.getElementById('adminEventQueueTable');
  if (tbl && window.initTablePagination && !tbl._paginator) {
    window.initTablePagination(tbl, {
      pageSize: 10,
      showPageSizeSelector: false,
      showInfo: false
    });
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAdminEventQueuePagination);
} else {
  initAdminEventQueuePagination();
}

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
      await window.showSystemModal({
        title: 'Registration Error',
        message: data.message || 'Failed to retrieve registrations.',
        type: 'error'
      });
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
    await window.showSystemModal({
      title: 'Network Error',
      message: 'Network error retrieving registration list. Please check your connection.',
      type: 'error'
    });
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
    await window.showSystemModal({
      title: 'Selection Required',
      message: 'No event was selected for PDF export.',
      type: 'warning'
    });
    return;
  }

  const list = currentViewingEventRegistrations || [];
  if (!list.length) {
    await window.showSystemModal({
      title: 'Export Unavailable',
      message: 'No registered student attendees available to export.',
      type: 'info'
    });
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
    window.showSystemModal({
      title: 'Export Unavailable',
      message: 'No registered student attendees available to export.',
      type: 'info'
    });
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
    window.showSystemModal({
      title: 'Popups Blocked',
      message: 'Please allow browser popups to export and print official event registrations.',
      type: 'warning'
    });
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
        table.export-table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 10.5px; }
        table.export-table th { background: #f1f5f9; color: #1e293b; font-weight: 700; padding: 6px 8px; border: 1px solid #cbd5e1; text-align: left; }
        table.export-table td { padding: 6px 8px; border: 1px solid #cbd5e1; }
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

      <div class="table-wrap table-compact">
        <table class="meta-table table-compact">
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
      </div>

      <div class="table-wrap">
        <table class="export-table">
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
      </div>

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
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Generating...</span>';
  body.style.display = 'block';
  const emptyState = document.getElementById('aiPlannerEmptyState');
  if (emptyState) emptyState.style.display = 'none';
  loading.style.display = 'flex';
  results.innerHTML = '';

  try {
    const fd = new FormData();
    fd.append('action', 'plan_events');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
      fd.append('csrf_token', csrfMeta.content);
    }
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
      btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> <span>Generate Ideas</span>';
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

    const isGemini = data.engine && data.engine.toLowerCase().includes('gemini');
    if (!isGemini) {
      let warnText = 'No Google Gemini API key configured in System Settings. Showing rule-based procedural recommendations.';
      if (data.google_error && !data.google_error.toLowerCase().includes('no api key')) {
        warnText = `API Key is active, but Google returned: "${data.google_error}". Showing rule-based procedural recommendations while Google recovers.`;
      }
      html += `
        <div style="margin-bottom:14px; padding:10px 14px; background:#fffbeb; border:1.5px solid #fde68a; border-radius:10px; font-size:0.82rem; color:#92400e; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
          <div><i class="fa-solid fa-triangle-exclamation" style="color:#d97706; margin-right:6px;"></i><strong>Notice:</strong> ${warnText}</div>
          <a href="../dashboard/admin_settings.php" target="_blank" style="color:#b45309; font-weight:700; text-decoration:underline;">System Settings &rarr;</a>
        </div>`;
    }

    // Grid of Proposed Plans - 3 Aligned in a Row
    html += '<div class="ai-plans-grid">';
    CURRENT_AI_PLANS.forEach((plan, idx) => {
      const dtStr = plan.recommended_date ? plan.recommended_date.replace('T', ' ') : 'N/A';
      const formattedDate = plan.recommended_date ? new Date(plan.recommended_date).toLocaleString('en-US', { dateStyle:'medium', timeStyle:'short' }) : dtStr;
      const score = plan.feasibility_score || 95;

      html += `
        <div class="ai-plan-card" style="animation: fadeInUp 0.35s ease ${idx * 0.08}s both;">
          <div>
            <div class="ai-plan-header">
              <div class="ai-plan-title">${plan.title}</div>
              <span class="ai-score-pill"><i class="fa-solid fa-shield-check"></i> ${score}%</span>
            </div>
            <div class="ai-plan-meta">
              <span class="ai-meta-tag"><i class="fa-solid fa-calendar-day"></i> ${formattedDate}</span>
              <span class="ai-meta-tag"><i class="fa-solid fa-location-dot"></i> ${plan.recommended_venue || 'Campus Venue'}</span>
            </div>
            <div class="ai-plan-desc">${plan.description}</div>
            <div class="ai-conflict-box" title="${plan.accessibility_verdict || 'Schedule Clear'}">
              <i class="fa-solid fa-circle-check" style="color:#16a34a; flex-shrink:0;"></i>
              <span>${plan.holiday_check || 'No schedule conflicts'}</span>
            </div>
          </div>
          <div>
            <button type="button" class="ai-apply-plan-btn" onclick="applyAIEventPlan(${idx})">
              <i class="fa-solid fa-pen-to-square"></i> Use This Plan
            </button>
          </div>
        </div>`;
    });
    html += '</div>';

    results.innerHTML = html;
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> <span>Regenerate Ideas</span>';

  } catch (err) {
    loading.style.display = 'none';
    results.innerHTML = `<div class="ai-error-msg"><i class="fa-solid fa-triangle-exclamation"></i> Network or server error. Please try again.</div>`;
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> <span>Generate Ideas</span>';
  }
}

// Auto-fill Create Event Proposal Form from AI Plan
function applyAIEventPlan(planIndex) {
  const plan = CURRENT_AI_PLANS[planIndex];
  if (!plan) return;

  closeModal('aiPlannerModal');

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
    await window.showSystemModal({
      title: 'Date Selection Required',
      message: 'Please select an event date and time first before running conflict audit.',
      type: 'warning'
    });
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
</body>
</html>
