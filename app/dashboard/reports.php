<?php
// ============================================================
//  REPORTS.PHP — Organization Performance & Analytics Reports
//  Standard organizational analytics with CSV & Print Export
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_any_permission(['reports.view.system', 'reports.view.institutional', 'reports.view.org'], null, 'dashboard.php');

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
    $stats['total_events']       = (int)$conn->query("SELECT COUNT(*) AS c FROM events WHERE club_id = $adviser_club_id")->fetch_assoc()['c'];
    $stats['approved_events']    = (int)$conn->query("SELECT COUNT(*) AS c FROM events WHERE club_id = $adviser_club_id AND status IN ('Approved','Upcoming','Completed')")->fetch_assoc()['c'];
    $stats['total_members']      = (int)$conn->query("SELECT COUNT(*) AS c FROM club_memberships WHERE club_id = $adviser_club_id AND status='Active'")->fetch_assoc()['c'];
    $stats['total_attendance']   = (int)$conn->query("SELECT COUNT(*) AS c FROM attendance_logs al JOIN events e ON e.id = al.event_id WHERE e.club_id = $adviser_club_id")->fetch_assoc()['c'];
    $stats['total_disbursed']    = $conn->query("SELECT COALESCE(SUM(amount),0) AS s FROM budget_requests WHERE club_id = $adviser_club_id AND status='Disbursed'")->fetch_assoc()['s'];
    $stats['total_achievements'] = (int)$conn->query("SELECT COUNT(*) AS c FROM achievements WHERE club_id = $adviser_club_id AND status='Verified'")->fetch_assoc()['c'];
    $stats['total_registrations']= (int)$conn->query("SELECT COUNT(*) AS c FROM event_registrations er JOIN events e ON e.id = er.event_id WHERE e.club_id = $adviser_club_id")->fetch_assoc()['c'];
} else {
    $stats['total_events']       = (int)$conn->query("SELECT COUNT(*) AS c FROM events")->fetch_assoc()['c'];
    $stats['approved_events']    = (int)$conn->query("SELECT COUNT(*) AS c FROM events WHERE status IN ('Approved','Upcoming','Completed')")->fetch_assoc()['c'];
    $stats['total_members']      = (int)$conn->query("SELECT COUNT(*) AS c FROM club_memberships WHERE status='Active'")->fetch_assoc()['c'];
    $stats['total_attendance']   = (int)$conn->query("SELECT COUNT(*) AS c FROM attendance_logs")->fetch_assoc()['c'];
    $stats['total_disbursed']    = $conn->query("SELECT COALESCE(SUM(amount),0) AS s FROM budget_requests WHERE status='Disbursed'")->fetch_assoc()['s'];
    $stats['total_achievements'] = (int)$conn->query("SELECT COUNT(*) AS c FROM achievements WHERE status='Verified'")->fetch_assoc()['c'];
    $stats['total_registrations']= (int)$conn->query("SELECT COUNT(*) AS c FROM event_registrations")->fetch_assoc()['c'];
    $stats['total_users']        = (int)$conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
    $stats['total_clubs']        = (int)$conn->query("SELECT COUNT(*) AS c FROM clubs WHERE status='Active' AND deleted_at IS NULL")->fetch_assoc()['c'];
    $stats['total_elections']    = (int)$conn->query("SELECT COUNT(*) AS c FROM elections")->fetch_assoc()['c'];
    $stats['total_votes']        = (int)$conn->query("SELECT COUNT(*) AS c FROM election_votes")->fetch_assoc()['c'];
    $stats['total_logs']         = (int)$conn->query("SELECT COUNT(*) AS c FROM audit_logs")->fetch_assoc()['c'];
    $stats['total_reqs']         = (int)$conn->query("SELECT COUNT(*) AS c FROM budget_requests")->fetch_assoc()['c'];
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
  /* ── Clean & Polished Reports Page Styles ─────────────────── */
  .content {
    flex: 1;
    display: flex;
    flex-direction: column;
  }
  .content-body {
    flex: 1;
  }
  .footer {
    margin-top: auto;
    flex-shrink: 0;
  }
  .page-title-bar {
    background: #ffffff;
    padding: 20px 24px;
    border-bottom: 1px solid #e8edf4;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
  }
  .page-title {
    margin: 0;
    font-size: 1.35rem;
    font-weight: 800;
    color: #063469;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.01em;
  }
  .org-scope-badge {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    padding: 7px 18px;
    border-radius: 24px;
    font-size: 0.82rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 1px 3px rgba(37,99,235,0.06);
  }

  /* 1. Sleek Compact Metrics Cards (Even Padding & Layout - Consistent Sizes) */
  .metrics-grid {
    display: grid !important;
    grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
    gap: 14px !important;
    margin-bottom: 22px !important;
    align-items: stretch !important;
  }
  .metric-card {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 12px !important;
    padding: 14px 16px !important;
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04) !important;
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease !important;
    min-width: 0 !important;
    width: 100% !important;
    height: 100% !important;
    min-height: 76px !important;
    box-sizing: border-box !important;
  }
  .metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08) !important;
    border-color: #cbd5e1 !important;
  }
  .metric-icon-wrap {
    width: 44px !important;
    height: 44px !important;
    border-radius: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 1.15rem !important;
    flex-shrink: 0 !important;
  }
  .metric-info {
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
    flex: 1 1 0 !important;
    min-width: 0 !important;
    overflow: hidden !important;
  }
  .metric-val {
    font-size: 1.35rem !important;
    font-weight: 800 !important;
    color: #0f172a !important;
    line-height: 1.15 !important;
    white-space: nowrap !important;
  }
  .metric-lbl {
    font-size: 0.72rem !important;
    font-weight: 700 !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    letter-spacing: 0.04em !important;
    margin-top: 3px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
  }

  /* 2. Modern Report Catalog Table */
  .report-catalog-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
    margin-bottom: 24px;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.04);
  }
  .catalog-header {
    padding: 18px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    background: #ffffff;
  }
  .catalog-title {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 9px;
  }
  .catalog-sub {
    font-size: 0.79rem;
    color: #64748b;
    margin-top: 4px;
    line-height: 1.4;
  }
  .catalog-count-badge {
    background: #f8fafc;
    color: #334155;
    border: 1px solid #e2e8f0;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 0.76rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .catalog-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.84rem;
    text-align: left;
    table-layout: auto;
  }
  .catalog-table thead th {
    background: #f8fafc;
    color: #475569;
    padding: 12px 16px;
    font-weight: 700;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 2px solid #e2e8f0;
  }
  .catalog-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    color: #334155;
    transition: background 0.15s ease;
  }
  .catalog-table tbody tr {
    cursor: pointer;
    transition: all 0.15s ease;
  }
  .catalog-table tbody tr:hover td {
    background: #f8faff;
  }
  .catalog-table tbody tr.active-row td {
    background: #eff6ff;
  }
  .catalog-table tbody tr.active-row {
    box-shadow: inset 4px 0 0 #2563eb;
  }
  .catalog-table tbody tr:last-child td {
    border-bottom: none;
  }

  /* Catalog Row Elements */
  .module-cell {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .module-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
  }
  .module-name-wrap {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
  }
  .module-title {
    font-size: 0.88rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.25;
    word-break: normal;
    overflow-wrap: break-word;
  }
  .module-tag {
    display: inline-block;
    font-size: 0.65rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 4px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    width: fit-content;
  }
  .tag-blue { background: #dbeafe; color: #1d4ed8; }
  .tag-green { background: #dcfce7; color: #15803d; }
  .tag-amber { background: #fef3c7; color: #b45309; }
  .tag-purple { background: #ede9fe; color: #6d28d9; }
  .tag-slate { background: #f1f5f9; color: #334155; }
  .tag-rose { background: #ffe4e6; color: #be123c; }
  .tag-cyan { background: #cffafe; color: #0e7490; }
  .tag-indigo { background: #e0e7ff; color: #4338ca; }

  .admin-metrics-grid {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 16px !important;
    margin-bottom: 24px !important;
    align-items: stretch !important;
  }
  @media (max-width: 1200px) {
    .admin-metrics-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
  }
  @media (max-width: 580px) {
    .admin-metrics-grid {
      grid-template-columns: 1fr !important;
    }
  }

  .desc-text {
    font-size: 0.80rem;
    color: #64748b;
    line-height: 1.45;
    overflow-wrap: break-word;
  }
  .stat-badge-cell {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.76rem;
    font-weight: 600;
    color: #334155;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 5px 10px;
    border-radius: 7px;
    line-height: 1.3;
    overflow-wrap: break-word;
  }

  .fmt-pills-row {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
  }
  .fmt-pill {
    display: inline-flex;
    align-items: center;
    font-size: 0.68rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 5px;
    background: #f8fafc;
    color: #475569;
    border: 1px solid #e2e8f0;
    white-space: nowrap;
    line-height: 1.3;
  }

  .btn-gen-row {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 0.80rem;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(37,99,235,0.2);
    transition: all 0.15s ease;
    white-space: nowrap;
  }
  .btn-gen-row:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(37,99,235,0.28);
  }
  .btn-gen-row.is-loading {
    background: #64748b;
    cursor: wait;
    box-shadow: none;
  }
  .btn-gen-row.is-active {
    background: #059669;
    box-shadow: 0 2px 6px rgba(5,150,105,0.2);
  }

  /* Responsive layout for tablets and mobile: transforms table rows into clean cards with zero horizontal scroll */
  @media (max-width: 920px) {
    .catalog-header {
      flex-direction: column;
      align-items: flex-start;
      gap: 12px;
      padding: 16px;
    }
    .catalog-table thead {
      display: none;
    }
    .catalog-table,
    .catalog-table tbody {
      display: block;
      width: 100%;
    }
    .catalog-table tbody {
      padding: 12px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .catalog-table tbody tr {
      display: grid;
      grid-template-columns: 1fr auto;
      grid-template-areas:
        "mod-title  mod-title"
        "mod-desc   mod-desc"
        "mod-scope  mod-fmt"
        "mod-action mod-action";
      gap: 10px 12px;
      align-items: center;
      background: #ffffff;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      padding: 16px;
      box-shadow: 0 1px 4px rgba(0,0,0,0.03);
      box-sizing: border-box;
      width: 100%;
    }
    .catalog-table tbody tr:hover td {
      background: transparent;
    }
    .catalog-table tbody tr:hover {
      border-color: #93c5fd;
      background: #f8faff;
    }
    .catalog-table tbody tr.active-row td {
      background: transparent;
    }
    .catalog-table tbody tr.active-row {
      border-color: #2563eb;
      background: #eff6ff;
      box-shadow: 0 3px 10px rgba(37,99,235,0.12);
    }
    .catalog-table tbody td {
      display: block;
      padding: 0;
      border: none;
      width: auto;
    }
    .catalog-table tbody td:nth-child(1) {
      grid-area: mod-title;
    }
    .catalog-table tbody td:nth-child(2) {
      grid-area: mod-desc;
    }
    .catalog-table tbody td:nth-child(3) {
      grid-area: mod-scope;
    }
    .catalog-table tbody td:nth-child(4) {
      grid-area: mod-fmt;
      text-align: right;
    }
    .catalog-table tbody td:nth-child(5) {
      grid-area: mod-action;
      padding-top: 10px;
      border-top: 1px dashed #e2e8f0;
      text-align: right;
    }
    .catalog-table tbody td:nth-child(5) .btn-gen-row {
      width: 100%;
      justify-content: center;
      padding: 10px 16px;
      font-size: 0.85rem;
    }
  }

  @media (max-width: 560px) {
    .catalog-table tbody tr {
      grid-template-columns: 1fr;
      grid-template-areas:
        "mod-title"
        "mod-desc"
        "mod-scope"
        "mod-fmt"
        "mod-action";
    }
    .catalog-table tbody td:nth-child(4) {
      text-align: left;
    }
  }

  /* 3. Report Output Modal Popup */
  .report-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.68);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px;
    box-sizing: border-box;
    opacity: 0;
    transition: opacity 0.2s ease-in-out;
  }
  .report-modal-overlay.active {
    display: flex !important;
    opacity: 1;
  }
  .report-modal-dialog {
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid rgba(226, 232, 240, 0.9);
    width: 100%;
    max-width: 1180px;
    max-height: 88vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(0,0,0,0.05);
    overflow: hidden;
    transform: scale(0.96) translateY(10px);
    transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .report-modal-overlay.active .report-modal-dialog {
    transform: scale(1) translateY(0);
  }
  .report-output-header {
    background: #0f172a;
    padding: 18px 24px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    flex-shrink: 0;
  }
  .report-output-header h3 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .report-body {
    padding: 24px;
    overflow-y: auto;
    flex: 1;
    max-height: calc(88vh - 80px);
    background: #fff;
  }

  /* Summary KPI Highlights in Report */
  .report-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 12px;
    margin-bottom: 22px;
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
    width: 38px; height: 38px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.95rem; flex-shrink: 0;
  }
  .report-summary-box .box-val { font-size: 1.25rem; font-weight: 800; color: #0f172a; line-height: 1.2; }
  .report-summary-box .box-lbl { font-size: 0.70rem; font-weight: 700; color: #64748b; text-transform: uppercase; }

  /* Report Table Styles */
  .report-table-wrap {
    width: 100%;
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin-bottom: 20px;
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f8fafc;
  }
  .report-table-wrap::-webkit-scrollbar {
    height: 5px;
  }
  .report-table-wrap::-webkit-scrollbar-track {
    background: #f8fafc;
    border-radius: 4px;
  }
  .report-table-wrap::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
  }
  .report-table-wrap::-webkit-scrollbar-button {
    display: none;
    width: 0;
    height: 0;
  }
  .report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
    text-align: left;
  }
  .report-table th {
    background: #f1f5f9;
    color: #334155;
    padding: 10px 14px;
    font-weight: 700;
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 1.5px solid #cbd5e1;
    overflow-wrap: break-word;
  }
  .report-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    overflow-wrap: break-word;
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

  .btn-close-report {
    background: rgba(255,255,255,0.15);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
  }
  .btn-close-report:hover {
    background: rgba(255,255,255,0.25);
  }

  /* Official Footer Note */
  .report-official-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
    font-size: 0.75rem;
    color: #64748b;
    flex-wrap: wrap;
    gap: 8px;
  }

  @media print {
    body { overflow: visible !important; }
    .sidebar, .topbar, .page-title-bar, .metrics-grid,
    .report-catalog-card, .btn-print, .btn-export-csv, .btn-close-report, .footer { display: none !important; }
    .report-modal-overlay {
      position: static !important;
      display: block !important;
      width: 100% !important;
      height: auto !important;
      background: none !important;
      backdrop-filter: none !important;
      padding: 0 !important;
      opacity: 1 !important;
      z-index: auto !important;
    }
    .report-modal-dialog {
      box-shadow: none !important;
      border: none !important;
      max-width: 100% !important;
      max-height: none !important;
      overflow: visible !important;
      transform: none !important;
      display: block !important;
    }
    .report-output-header {
      background: #1e293b !important;
      color: #fff !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .report-body {
      overflow: visible !important;
      max-height: none !important;
      padding: 16px 0 !important;
    }
    .report-table-wrap {
      overflow: visible !important;
      border: 1px solid #cbd5e1 !important;
    }
    .main { margin: 0 !important; padding: 0 !important; width: 100% !important; }
    .content { padding: 0 !important; }
  }

  @media (max-width: 1200px) {
    .metrics-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
      gap: 12px !important;
    }
  }
  @media (max-width: 640px) {
    .page-title-bar {
      padding: 14px 16px;
    }
    .metrics-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      gap: 10px !important;
    }
    .metric-card {
      padding: 12px 14px !important;
    }
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

    <!-- Page Title Bar (Clean Heading Only - No Extra Infos or Buttons) -->
    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-chart-column" style="color:#2563eb;"></i>
        <?= $sess_role === 'club_adviser' ? 'Organization Reports &amp; Analytics' : 'Performance Reports &amp; Analytics' ?>
      </h2>
    </div>

    <div class="content-body">

      <!-- 1. Sleek Compact Metrics Row (Evenly Padded, Distinct Cards) -->
      <?php if ($sess_role === 'admin'): ?>
      <div class="metrics-grid admin-metrics-grid">
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-users"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_users'] ?></div>
            <div class="metric-lbl">Total Users</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#dcfce7; color:#16a34a;"><i class="fa-solid fa-building-columns"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_clubs'] ?></div>
            <div class="metric-lbl">Active Clubs</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#ede9fe; color:#7c3aed;"><i class="fa-solid fa-peso-sign"></i></div>
          <div class="metric-info">
            <div class="metric-val">₱<?= number_format((float)$stats['total_disbursed']) ?></div>
            <div class="metric-lbl">Disbursed Funds</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-clipboard-check"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_attendance'] ?></div>
            <div class="metric-lbl">Attendance Logs</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#e0f2fe; color:#0284c7;"><i class="fa-solid fa-check-to-slot"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_elections'] ?></div>
            <div class="metric-lbl">Total Elections</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#fce7f3; color:#db2777;"><i class="fa-solid fa-trophy"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_achievements'] ?></div>
            <div class="metric-lbl">Verified Awards</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#f1f5f9; color:#64748b;"><i class="fa-solid fa-stamp"></i></div>
          <div class="metric-info">
            <div class="metric-val">0</div>
            <div class="metric-lbl">Certificates (N/A)</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#fee2e2; color:#dc2626;"><i class="fa-solid fa-server"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_logs'] ?></div>
            <div class="metric-lbl">Audit Logs</div>
          </div>
        </div>
      </div>

      <?php else: ?>
      <div class="metrics-grid">
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-calendar-days"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_events'] ?></div>
            <div class="metric-lbl">Total Events</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#dcfce7; color:#16a34a;"><i class="fa-solid fa-users"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_members'] ?></div>
            <div class="metric-lbl">Active Members</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-clipboard-check"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_attendance'] ?></div>
            <div class="metric-lbl">Attendance Logs</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#ede9fe; color:#7c3aed;"><i class="fa-solid fa-peso-sign"></i></div>
          <div class="metric-info">
            <div class="metric-val">₱<?= number_format((float)$stats['total_disbursed']) ?></div>
            <div class="metric-lbl">Funds Disbursed</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#fce7f3; color:#db2777;"><i class="fa-solid fa-trophy"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_achievements'] ?></div>
            <div class="metric-lbl">Verified Awards</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#e0f2fe; color:#0284c7;"><i class="fa-solid fa-user-plus"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_registrations'] ?></div>
            <div class="metric-lbl">Registrations</div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- 2. Reports Table Catalog -->
      <div class="report-catalog-card">
        <div class="catalog-header">
          <div>
            <div class="catalog-title">
              <i class="fa-solid fa-table-list" style="color:#2563eb;"></i>
              Available Report Modules
            </div>
            <div class="catalog-sub">
              Official institutional records, auditing logs, and summary reports ready for instant generation, CSV export, or printing.
            </div>
          </div>
          <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <?php if (!empty($adviser_club_name)): ?>
            <span class="org-scope-badge">
              <i class="fa-solid fa-building-columns"></i>
              <span><?= htmlspecialchars($adviser_club_name) ?> (<?= htmlspecialchars($adviser_club_code) ?>)</span>
            </span>
            <?php endif; ?>
            <span class="catalog-count-badge">
              <i class="fa-solid fa-layer-group" style="color:#2563eb;"></i> <?= $sess_role === 'admin' ? '8 Modules' : '5 Modules' ?>
            </span>
          </div>
        </div>

        <div class="table-wrap">
          <table class="catalog-table" id="reportCatalogTable">
            <thead>
              <tr>
                <th style="width: 26%;">Report Module</th>
                <th style="width: 34%;">Coverage &amp; Included Records</th>
                <th style="width: 18%;">Current Scope</th>
                <th style="width: 10%;">Output</th>
                <th style="width: 12%; text-align: right;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($sess_role === 'admin'): ?>
              <!-- Admin Row 1: System Usage -->
              <tr id="row-system_usage" data-type="system_usage" onclick="handleRowClick(event, 'system_usage')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#e0e7ff; color:#4338ca;">
                      <i class="fa-solid fa-server"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">System Usage</span>
                      <span class="module-tag tag-indigo">System Usage</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    System activity logs, session telemetry, database storage utilization, and multi-tenant organization database states.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-database" style="color:#4338ca;"></i>
                    <?= $stats['total_logs'] ?> Audit Logs
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="system_usage" onclick="generateReportType('system_usage', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Row 2: Organization Summary -->
              <tr id="row-organization_summary" data-type="organization_summary" onclick="handleRowClick(event, 'organization_summary')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#dcfce7; color:#15803d;">
                      <i class="fa-solid fa-building-columns"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Organization Summary</span>
                      <span class="module-tag tag-green">Organizations</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Accredited student organizations roster, faculty adviser appointments, membership scale, and council affiliations.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-users-viewfinder" style="color:#15803d;"></i>
                    <?= $stats['total_clubs'] ?> Active Clubs (<?= $stats['total_members'] ?> Members)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="organization_summary" onclick="generateReportType('organization_summary', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Row 3: Financial Summary -->
              <tr id="row-financial_summary" data-type="financial_summary" onclick="handleRowClick(event, 'financial_summary')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ede9fe; color:#6d28d9;">
                      <i class="fa-solid fa-coins"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Financial Summary</span>
                      <span class="module-tag tag-purple">Finance</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Multi-tier budget requisitions, approved allocations, institutional fund disbursements, and expenditure summaries.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-peso-sign" style="color:#6d28d9;"></i>
                    ₱<?= number_format((float)$stats['total_disbursed']) ?> Disbursed (<?= $stats['total_reqs'] ?> Requisitions)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="financial_summary" onclick="generateReportType('financial_summary', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Row 4: Attendance Summary -->
              <tr id="row-attendance_summary" data-type="attendance_summary" onclick="handleRowClick(event, 'attendance_summary')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#fef3c7; color:#b45309;">
                      <i class="fa-solid fa-clipboard-user"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Attendance Summary</span>
                      <span class="module-tag tag-amber">Attendance</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Campus-wide event turnout, QR scan validation logs, manual check-in overrides, and student participation rates.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-clipboard-check" style="color:#b45309;"></i>
                    <?= $stats['total_attendance'] ?> Verified Logs (<?= $stats['total_events'] ?> Events)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="attendance_summary" onclick="generateReportType('attendance_summary', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Row 5: Election Summary -->
              <tr id="row-election_summary" data-type="election_summary" onclick="handleRowClick(event, 'election_summary')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#dbeafe; color:#1d4ed8;">
                      <i class="fa-solid fa-check-to-slot"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Election Summary</span>
                      <span class="module-tag tag-blue">Elections</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Campus-wide and club student elections, candidate filings, audited ballot counts, and certification statuses.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-envelope-open-text" style="color:#1d4ed8;"></i>
                    <?= $stats['total_elections'] ?> Elections (<?= $stats['total_votes'] ?> Ballots)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="election_summary" onclick="generateReportType('election_summary', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Row 6: Achievement Summary -->
              <tr id="row-achievement_summary" data-type="achievement_summary" onclick="handleRowClick(event, 'achievement_summary')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ffe4e6; color:#be123c;">
                      <i class="fa-solid fa-trophy"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Achievement Summary</span>
                      <span class="module-tag tag-rose">Achievements</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Student and organization competition achievements, verification statuses, awards tally, and honors registry.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-award" style="color:#be123c;"></i>
                    <?= $stats['total_achievements'] ?> Verified Honors
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="achievement_summary" onclick="generateReportType('achievement_summary', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Row 7: Certificate Summary -->
              <tr id="row-certificate_summary" data-type="certificate_summary" onclick="handleRowClick(event, 'certificate_summary')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#f1f5f9; color:#475569;">
                      <i class="fa-solid fa-stamp"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Certificate Summary</span>
                      <span class="module-tag tag-slate">Credentials</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Institutional digital credentials audit, credential lifecycle status, and decommissioned module governance record.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-shield-halved" style="color:#475569;"></i>
                    Credential Engine Decommissioned (0 Active)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="certificate_summary" onclick="generateReportType('certificate_summary', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Row 8: User / Access Summary -->
              <tr id="row-user_access_summary" data-type="user_access_summary" onclick="handleRowClick(event, 'user_access_summary')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#cffafe; color:#0e7490;">
                      <i class="fa-solid fa-users-gear"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">User / Access Summary</span>
                      <span class="module-tag tag-cyan">RBAC &amp; Security</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    User account directory, Role-Based Access Control distribution (Admin, SSC, Adviser, Student), and account statuses.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-user-shield" style="color:#0e7490;"></i>
                    <?= $stats['total_users'] ?> Registered Accounts (4 Roles)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="user_access_summary" onclick="generateReportType('user_access_summary', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <?php else: ?>
              <!-- Adviser Row 1: Activity & Events -->
              <tr id="row-activity_events" data-type="activity_events" onclick="handleRowClick(event, 'activity_events')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#dbeafe; color:#2563eb;">
                      <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Activity &amp; Events Report</span>
                      <span class="module-tag tag-blue">Events</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Event participation, scheduled dates, approved venues, and event attendance turnout records.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-calendar-days" style="color:#2563eb;"></i>
                    <?= $stats['total_events'] ?> Events (<?= $stats['approved_events'] ?> Approved)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="activity_events" onclick="generateReportType('activity_events', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Adviser Row 2: Membership Roster & Officers -->
              <tr id="row-membership_engagement" data-type="membership_engagement" onclick="handleRowClick(event, 'membership_engagement')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#dcfce7; color:#16a34a;">
                      <i class="fa-solid fa-users-gear"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Membership Roster &amp; Officers</span>
                      <span class="module-tag tag-green">Roster</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Active club roster, elected student officers, academic programs, and membership registration dates.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-users" style="color:#16a34a;"></i>
                    <?= $stats['total_members'] ?> Active Members
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="membership_engagement" onclick="generateReportType('membership_engagement', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Adviser Row 3: Attendance Logs & Audit -->
              <tr id="row-attendance_analytics" data-type="attendance_analytics" onclick="handleRowClick(event, 'attendance_analytics')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#fef3c7; color:#d97706;">
                      <i class="fa-solid fa-clipboard-user"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Attendance Logs &amp; Audit</span>
                      <span class="module-tag tag-amber">Attendance</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    QR code check-in timestamps, verification methods, attendee student numbers, and event participation.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-clipboard-check" style="color:#d97706;"></i>
                    <?= $stats['total_attendance'] ?> Verified Logs
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="attendance_analytics" onclick="generateReportType('attendance_analytics', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Adviser Row 4: Budget & Financial Ledger -->
              <tr id="row-budget_financial" data-type="budget_financial" onclick="handleRowClick(event, 'budget_financial')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ede9fe; color:#7c3aed;">
                      <i class="fa-solid fa-coins"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Budget &amp; Financial Ledger</span>
                      <span class="module-tag tag-purple">Finance</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Organization expense requisitions, approved disbursals, budget categories, and financial status.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-peso-sign" style="color:#7c3aed;"></i>
                    ₱<?= number_format((float)$stats['total_disbursed']) ?> Disbursed
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="budget_financial" onclick="generateReportType('budget_financial', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Adviser Row 5: Comprehensive Semester Summary -->
              <tr id="row-comprehensive" data-type="comprehensive" onclick="handleRowClick(event, 'comprehensive')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#f1f5f9; color:#0f172a;">
                      <i class="fa-solid fa-file-waveform"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Comprehensive Semester Summary</span>
                      <span class="module-tag tag-slate">Executive</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Consolidated executive operational summary combining activities, members, and financial records.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-layer-group" style="color:#0f172a;"></i>
                    Consolidated Summary
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="comprehensive" onclick="generateReportType('comprehensive', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /content-body -->
  </div><!-- /content -->

  <!-- 3. Report Output Modal Dialog Popup -->
  <div class="report-modal-overlay" id="reportModalOverlay" onclick="handleReportBackdropClick(event)">
    <div class="report-modal-dialog" id="reportOutput" onclick="event.stopPropagation()">
      <div class="report-output-header">
        <div>
          <h3 id="reportOutputTitle"><i class="fa-solid fa-file-lines"></i> <span>Report</span></h3>
          <div style="font-size:0.75rem; color:#94a3b8; margin-top:3px;" id="reportOutputSubtitle">
            <?= $sess_role === 'admin' ? 'Institutional System Administration &amp; Audit Registry' : 'Organization: ' . htmlspecialchars($adviser_club_name) . ' (' . htmlspecialchars($adviser_club_code) . ')' ?>
          </div>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <button type="button" class="btn-export-csv" onclick="exportReportToCSV()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
          <button type="button" class="btn-export-csv" style="background:#107c41; color:#fff; border-color:#0b5e31;" onclick="exportReportToExcel()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
          <button type="button" class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / PDF</button>
          <button type="button" class="btn-close-report" onclick="closeReportOutput()" title="Close report popup"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
      </div>
      <div class="report-body" id="reportBody">
        <!-- Loaded dynamically -->
      </div>
    </div>
  </div>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div><!-- /main -->

<?php require_once __DIR__ . '/../shared/qr_modal.php'; ?>

<div id="toast" class="toast-notification" style="display:none;"></div>
<script src="../js/dashboard.js"></script>
<script>
let selectedReportType = '';
let currentReportData = null;

const reportNames = {
  // Admin 8 core system report modules
  'system_usage': 'System Usage & Telemetry Report',
  'organization_summary': 'Organization Summary & Accredited Roster Report',
  'financial_summary': 'Institutional Financial Summary & Disbursals Ledger',
  'attendance_summary': 'Institutional Attendance Summary & Audit Ledger',
  'election_summary': 'Student Governance Election Summary & Audit Report',
  'achievement_summary': 'Institutional Achievement Summary & Honors Ledger',
  'certificate_summary': 'Certificate Summary & Credentials Governance Audit',
  'user_access_summary': 'User / Access Summary & RBAC Audit Report',
  // Adviser report types
  'activity_events': 'Activity & Events Performance Report',
  'membership_engagement': 'Membership Roster & Officers Report',
  'attendance_analytics': 'Attendance Logs & Audit Report',
  'budget_financial': 'Budget & Financial Ledger Report',
  'comprehensive': 'Comprehensive Semester Performance Report'
};

function handleRowClick(event, type) {
  // If user clicked inside the button itself, let the button onclick handler handle it
  if (event.target.closest('.btn-gen-row')) return;
  const btn = document.querySelector(`.btn-gen-row[data-type="${type}"]`);
  generateReportType(type, btn, event);
}

async function generateReportType(type, btnEl, event) {
  if (event) event.stopPropagation();
  selectedReportType = type;

  // Highlight active row
  document.querySelectorAll('#reportCatalogTable tbody tr').forEach(r => r.classList.remove('active-row'));
  const activeRow = document.getElementById('row-' + type);
  if (activeRow) activeRow.classList.add('active-row');

  // Update button state
  document.querySelectorAll('.btn-gen-row').forEach(b => {
    b.classList.remove('is-active', 'is-loading');
    b.innerHTML = '<i class="fa-solid fa-play"></i> Generate';
  });

  if (btnEl) {
    btnEl.classList.add('is-loading');
    btnEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';
  }

  const overlay = document.getElementById('reportModalOverlay');
  const body = document.getElementById('reportBody');

  // Pop up the modal dialog immediately
  overlay.classList.add('active');
  document.body.style.overflow = 'hidden';
  document.getElementById('reportOutputTitle').querySelector('span').textContent = reportNames[selectedReportType] || 'Report';

  body.innerHTML = `
    <div style="text-align:center; padding:55px 20px; color:#64748b;">
      <i class="fa-solid fa-spinner fa-spin fa-2x" style="color:#2563eb; margin-bottom:14px;"></i>
      <div style="font-weight:700; font-size:1rem; color:#0f172a; margin-bottom:4px;">Compiling institutional records...</div>
      <div style="font-size:0.82rem; color:#64748b;">Fetching verified data from the database and building dynamic tables.</div>
    </div>`;

  try {
    const fd = new FormData();
    fd.append('action', 'get_report');
    fd.append('report_type', selectedReportType);
    const res = await fetch('../shared/report_actions.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (btnEl) {
      btnEl.classList.remove('is-loading');
      btnEl.classList.add('is-active');
      btnEl.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> Regenerate';
    }

    if (!data.success) {
      body.innerHTML = `<div style="padding:28px; color:#dc2626; font-weight:600; text-align:center;"><i class="fa-solid fa-triangle-exclamation fa-2x" style="margin-bottom:10px; display:block;"></i>${data.message}</div>`;
      return;
    }

    currentReportData = data;
    renderReport(data);

  } catch (err) {
    if (btnEl) {
      btnEl.classList.remove('is-loading');
      btnEl.innerHTML = '<i class="fa-solid fa-play"></i> Generate';
    }
    body.innerHTML = `<div style="padding:28px; color:#dc2626; font-weight:600; text-align:center;"><i class="fa-solid fa-triangle-exclamation fa-2x" style="margin-bottom:10px; display:block;"></i>An error occurred while generating the report. Please try again.</div>`;
  }
}

function closeReportOutput() {
  const overlay = document.getElementById('reportModalOverlay');
  if (overlay) overlay.classList.remove('active');
  document.body.style.overflow = '';
  document.querySelectorAll('#reportCatalogTable tbody tr').forEach(r => r.classList.remove('active-row'));
  document.querySelectorAll('.btn-gen-row').forEach(b => {
    b.classList.remove('is-active', 'is-loading');
    b.innerHTML = '<i class="fa-solid fa-play"></i> Generate';
  });
}

function handleReportBackdropClick(event) {
  if (event.target === document.getElementById('reportModalOverlay')) {
    closeReportOutput();
  }
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    const overlay = document.getElementById('reportModalOverlay');
    if (overlay && overlay.classList.contains('active')) {
      closeReportOutput();
    }
  }
});

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
    html += '<div class="table-wrap report-table-wrap"><table class="report-table" id="renderedReportTable"><thead><tr>';
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

function exportReportToExcel() {
  if (!currentReportData || !currentReportData.columns || !currentReportData.data) {
    alert('Please generate a report first before exporting.');
    return;
  }
  const title = currentReportData.title || 'Report';
  const cols = currentReportData.columns;
  const rows = currentReportData.data;

  let excelHtml = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
  excelHtml += '<head><meta charset="utf-8"/><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>' + title.substring(0, 31).replace(/[\\/\\?\\*\\[\\]]/g, '') + '</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head><body>';
  excelHtml += '<table border="1"><thead><tr style="background:#0f172a; color:#ffffff; font-weight:bold;">';
  cols.forEach(c => { excelHtml += '<th style="padding:6px 12px; background:#0f172a; color:#ffffff;">' + c + '</th>'; });
  excelHtml += '</tr></thead><tbody>';
  rows.forEach(r => {
    excelHtml += '<tr>';
    r.forEach(val => { excelHtml += '<td style="padding:4px 8px;">' + String(val !== null && val !== undefined ? val : '') + '</td>'; });
    excelHtml += '</tr>';
  });
  excelHtml += '</tbody></table></body></html>';

  const blob = new Blob([excelHtml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  const fname = title.replace(/[^a-zA-Z0-9]/g, '_') + '_' + Date.now() + '.xls';
  link.setAttribute('download', fname);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
</script>
</body>
</html>
