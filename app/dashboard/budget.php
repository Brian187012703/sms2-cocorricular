<?php
// ============================================================
//  BUDGET.PHP — Budget & Financial Management Module
//  3-Stage Approval Pipeline: Adviser -> SSC -> Admin
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/notification_actions.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: text/html; charset=UTF-8');

if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php');
    exit;
}

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// Fetch user clubs for new request dropdown
if ($sess_role === 'student' || $sess_role === 'club_adviser') {
    $stmt = $conn->prepare("SELECT c.id, c.name, c.code FROM clubs c JOIN club_memberships cm ON cm.club_id=c.id WHERE cm.user_id=? AND cm.status='Active' ORDER BY c.name");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user_clubs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $user_clubs = $conn->query("SELECT id, name, code FROM clubs WHERE status='Active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}

// Fetch budget requests based on role
$where = '';
if ($sess_role === 'club_adviser') {
    $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
    $cm->bind_param('i', $user_id);
    $cm->execute();
    $cm->bind_result($my_club_id);
    $cm->fetch();
    $cm->close();
    if (!empty($my_club_id)) {
        $where = "WHERE br.club_id = " . (int)$my_club_id;
    }
} elseif ($sess_role === 'ssc') {
    $where = "WHERE br.status IN ('Pending SSC','Pending Admin','Disbursed','Rejected')";
}
// admin sees all

$budget_requests = $conn->query(
    "SELECT br.id, br.club_id, br.title, br.description, br.amount, br.status, br.notes, br.created_at, br.updated_at,
            c.name AS club_name, c.code AS club_code,
            u.first_name, u.last_name, u.email
     FROM budget_requests br
     JOIN clubs c ON c.id = br.club_id
     JOIN users u ON u.id = br.requested_by
     $where ORDER BY br.created_at DESC"
)->fetch_all(MYSQLI_ASSOC);

// Metrics
$total_requested = 0;
$pending_count   = 0;
$disbursed_total = 0;
$rejected_count  = 0;
foreach ($budget_requests as $req) {
    $total_requested += (float)$req['amount'];
    if (in_array($req['status'], ['Pending Adviser', 'Pending SSC', 'Pending Admin'])) {
        $pending_count++;
    } elseif ($req['status'] === 'Disbursed') {
        $disbursed_total += (float)$req['amount'];
    } elseif ($req['status'] === 'Rejected') {
        $rejected_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Budget &amp; Finance Management — BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
    /* Clean, Modern Professional Budget UI (Solid Palette - No Gradients) */
    .budget-section-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 22px 24px;
      margin-bottom: 24px;
      box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
    }
    
    /* 3-Stage Pipeline Stepper */
    .wf-stepper-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 1px solid #f1f5f9;
    }
    .wf-stepper-title {
      font-size: 0.85rem;
      font-weight: 800;
      color: #1a3a8c;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .wf-pipeline {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 16px;
      position: relative;
    }
    .wf-card {
      background: #f8fafc;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      padding: 16px;
      display: flex;
      align-items: flex-start;
      gap: 12px;
      transition: all 0.2s ease;
      position: relative;
    }
    .wf-card.active {
      background: #eff6ff;
      border-color: #93c5fd;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08);
    }
    .wf-card.done {
      background: #f0fdf4;
      border-color: #bbf7d0;
    }
    .wf-badge {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.95rem;
      flex-shrink: 0;
      background: #e2e8f0;
      color: #64748b;
      font-weight: 700;
    }
    .wf-card.active .wf-badge {
      background: #1a3a8c;
      color: #ffffff;
    }
    .wf-card.done .wf-badge {
      background: #16a34a;
      color: #ffffff;
    }
    .wf-info {
      flex: 1;
    }
    .wf-stage-tag {
      font-size: 0.68rem;
      font-weight: 800;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 2px;
    }
    .wf-card.active .wf-stage-tag {
      color: #2563eb;
    }
    .wf-card.done .wf-stage-tag {
      color: #16a34a;
    }
    .wf-name {
      font-size: 0.9rem;
      font-weight: 700;
      color: #0f172a;
      line-height: 1.3;
      margin-bottom: 3px;
    }
    .wf-desc {
      font-size: 0.75rem;
      color: #64748b;
      line-height: 1.35;
    }

    /* KPI Metrics Grid */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      margin-bottom: 24px;
    }
    .kpi-box {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 18px 20px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .kpi-box:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
      border-color: #cbd5e1;
    }
    .kpi-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 10px;
    }
    .kpi-label {
      font-size: 0.72rem;
      font-weight: 800;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    .kpi-icon-wrap {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem;
    }
    .kpi-icon-blue    { background: #eff6ff; color: #1a3a8c; }
    .kpi-icon-amber   { background: #fffbeb; color: #d97706; }
    .kpi-icon-green   { background: #f0fdf4; color: #16a34a; }
    .kpi-icon-red     { background: #fef2f2; color: #dc2626; }
    
    .kpi-num {
      font-size: 1.65rem;
      font-weight: 800;
      color: #0f172a;
      line-height: 1.1;
      letter-spacing: -0.02em;
    }
    .kpi-subtext {
      font-size: 0.72rem;
      color: #94a3b8;
      font-weight: 600;
      margin-top: 6px;
    }

    /* Ledger Table & Toolbar */
    .ledger-container {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 2px 12px rgba(15, 23, 42, 0.04);
    }
    .ledger-header {
      padding: 18px 24px;
      background: #ffffff;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 16px;
    }
    .ledger-title h3 {
      margin: 0;
      font-size: 1.1rem;
      font-weight: 800;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .ledger-title p {
      margin: 3px 0 0;
      font-size: 0.8rem;
      color: #64748b;
    }
    .ledger-actions-bar {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .search-input-wrap {
      position: relative;
      min-width: 240px;
    }
    .search-input-wrap i {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
      font-size: 0.85rem;
    }
    .search-input-wrap input {
      width: 100%;
      padding: 8px 12px 8px 34px;
      font-size: 0.85rem;
      border: 1.5px solid #cbd5e1;
      border-radius: 8px;
      background: #f8fafc;
      transition: all 0.2s;
    }
    .search-input-wrap input:focus {
      outline: none;
      background: #ffffff;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
    }
    .filter-select {
      padding: 8px 12px;
      font-size: 0.85rem;
      font-weight: 600;
      border: 1.5px solid #cbd5e1;
      border-radius: 8px;
      background: #f8fafc;
      color: #334155;
      cursor: pointer;
    }
    .filter-select:focus {
      outline: none;
      border-color: #2563eb;
    }

    .btn-create-req {
      background: #1a3a8c;
      color: #ffffff;
      font-weight: 700;
      font-size: 0.85rem;
      padding: 8px 18px;
      border-radius: 8px;
      border: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 7px;
      transition: background 0.15s ease, transform 0.15s ease;
    }
    .btn-create-req:hover {
      background: #2563eb;
      transform: translateY(-1px);
    }

    /* Table & Rows */
    .ledger-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 0.88rem;
    }
    .ledger-table thead tr {
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
      color: #475569;
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .ledger-table th {
      padding: 14px 18px;
      font-weight: 800;
    }
    .ledger-table tbody tr {
      border-bottom: 1px solid #f1f5f9;
      transition: background 0.15s ease;
    }
    .ledger-table tbody tr:hover {
      background: #f8fafc;
    }
    .ledger-table td {
      padding: 15px 18px;
      vertical-align: middle;
      color: #1e293b;
    }

    .org-code-chip {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #1a3a8c;
      color: #ffffff;
      font-size: 0.65rem;
      font-weight: 800;
      padding: 3px 7px;
      border-radius: 6px;
      letter-spacing: 0.03em;
      margin-bottom: 3px;
    }
    .org-name-text {
      font-weight: 700;
      color: #0f172a;
      font-size: 0.88rem;
    }
    .requester-meta {
      font-size: 0.74rem;
      color: #64748b;
      margin-top: 1px;
    }
    .req-title-text {
      font-weight: 700;
      color: #0f172a;
      font-size: 0.92rem;
      margin-bottom: 2px;
    }
    .req-desc-excerpt {
      font-size: 0.78rem;
      color: #64748b;
      max-width: 260px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .req-amount-val {
      font-size: 1.05rem;
      font-weight: 800;
      color: #0f172a;
      white-space: nowrap;
    }

    /* Status Badges */
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.72rem;
      font-weight: 700;
      white-space: nowrap;
    }
    .status-pill-adviser { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
    .status-pill-ssc     { background: #ede9fe; color: #5b21b6; border: 1px solid #ddd6fe; }
    .status-pill-admin   { background: #fce7f3; color: #9d174d; border: 1px solid #fbcfe8; }
    .status-pill-disbursed { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .status-pill-rejected  { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

    /* Action Buttons */
    .action-btn-group {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 6px;
    }
    .act-btn {
      padding: 6px 12px;
      font-size: 0.75rem;
      font-weight: 700;
      border-radius: 7px;
      border: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      transition: all 0.15s ease;
      white-space: nowrap;
    }
    .act-btn-approve {
      background: #16a34a;
      color: #ffffff;
    }
    .act-btn-approve:hover {
      background: #15803d;
    }
    .act-btn-edit {
      background: #d97706;
      color: #ffffff;
    }
    .act-btn-edit:hover {
      background: #b45309;
    }
    .act-btn-reject {
      background: #dc2626;
      color: #ffffff;
    }
    .act-btn-reject:hover {
      background: #b91c1c;
    }
    .act-btn-view {
      background: #f1f5f9;
      color: #475569;
      border: 1px solid #cbd5e1;
    }
    .act-btn-view:hover {
      background: #e2e8f0;
      color: #0f172a;
    }

    /* Modal Layouts */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(4px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 20px;
    }
    .modal-dialog {
      background: #ffffff;
      border-radius: 16px;
      width: 100%;
      max-width: 580px;
      overflow: hidden;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
      animation: modalFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes modalFadeIn {
      from { opacity: 0; transform: scale(0.96) translateY(8px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
    }
    .modal-header-solid {
      background: #1a3a8c;
      color: #ffffff;
      padding: 18px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .modal-header-solid h3 {
      margin: 0;
      font-size: 1.05rem;
      font-weight: 800;
      color: #ffffff;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .modal-close-btn {
      background: none;
      border: none;
      color: #ffffff;
      opacity: 0.85;
      font-size: 1.15rem;
      cursor: pointer;
      transition: opacity 0.15s;
    }
    .modal-close-btn:hover {
      opacity: 1;
    }
    .modal-body-pad {
      padding: 24px;
      max-height: calc(85vh - 120px);
      overflow-y: auto;
    }
    .modal-footer-pad {
      padding: 14px 24px;
      background: #f8fafc;
      border-top: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
    }

    .form-group-custom {
      margin-bottom: 16px;
    }
    .form-group-custom label {
      display: block;
      font-size: 0.82rem;
      font-weight: 700;
      color: #334155;
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .form-control-custom {
      width: 100%;
      padding: 10px 14px;
      font-size: 0.9rem;
      border: 1.5px solid #cbd5e1;
      border-radius: 8px;
      background: #ffffff;
      color: #0f172a;
      transition: all 0.2s;
    }
    .form-control-custom:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    /* Currency Input Group */
    .currency-input-wrap {
      position: relative;
      display: flex;
      align-items: center;
    }
    .currency-prefix {
      position: absolute;
      left: 14px;
      font-weight: 800;
      color: #64748b;
      font-size: 1rem;
      pointer-events: none;
    }
    .currency-input-wrap input {
      padding-left: 32px !important;
      font-weight: 700;
      font-size: 1rem;
    }

    @media (max-width: 992px) {
      .kpi-grid { grid-template-columns: repeat(2, 1fr); }
      .wf-pipeline { grid-template-columns: 1fr; }
    }
    @media (max-width: 576px) {
      .kpi-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

  <?php $APP_ROOT = '../'; $ACTIVE_NAV = 'budget'; require_once __DIR__ . '/../shared/sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
      <span class="topbar-spacer"></span>
      <div class="topbar-right">
        <div class="search-wrap">
          <input type="text" placeholder="Search pages, events..." autocomplete="off" />
          <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i class="fa-solid fa-qrcode"></i></button>
        <a href="../dashboard/account.php" class="avatar" id="avatarBtn" title="Account Settings">
          <?php if (!empty($sess_pic) && file_exists(__DIR__ . '/../uploads/avatars/' . $sess_pic)): ?>
            <img src="../uploads/avatars/<?= htmlspecialchars($sess_pic) ?>" alt="Profile"/>
          <?php else: ?>
            <?= $sess_initial ?>
          <?php endif; ?>
        </a>
      </div>
    </div>

    <div class="content">
      <div class="page-title-bar" style="margin-bottom: 20px;">
        <h2 class="page-title"><i class="fa-solid fa-hand-holding-dollar"></i> Budget &amp; Financial Management</h2>
      </div>

      <div class="content-body">

        <!-- KPI Metrics Summary Grid -->
        <div class="kpi-grid">
          <!-- Total Requested -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Total Requested</span>
              <div class="kpi-icon-wrap kpi-icon-blue"><i class="fa-solid fa-coins"></i></div>
            </div>
            <div class="kpi-num">&#8369;<?= number_format($total_requested, 2) ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-file-invoice"></i> All submitted requisitions</div>
          </div>

          <!-- Pending Approvals -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Pending Approvals</span>
              <div class="kpi-icon-wrap kpi-icon-amber"><i class="fa-solid fa-hourglass-half"></i></div>
            </div>
            <div class="kpi-num"><?= $pending_count ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-arrows-spin"></i> Awaiting pipeline review</div>
          </div>

          <!-- Total Disbursed -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Total Disbursed</span>
              <div class="kpi-icon-wrap kpi-icon-green"><i class="fa-solid fa-circle-check"></i></div>
            </div>
            <div class="kpi-num">&#8369;<?= number_format($disbursed_total, 2) ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-hand-holding-dollar"></i> Successfully released funds</div>
          </div>

          <!-- Rejected Requests -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Rejected Requests</span>
              <div class="kpi-icon-wrap kpi-icon-red"><i class="fa-solid fa-circle-xmark"></i></div>
            </div>
            <div class="kpi-num"><?= $rejected_count ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-circle-info"></i> Returned with feedback</div>
          </div>
        </div>

        <!-- Ledger & Requisitions Management Container -->
        <div class="ledger-container">
          <div class="ledger-header">
            <div class="ledger-title">
              <h3><i class="fa-solid fa-receipt" style="color:#1a3a8c;"></i> Requisitions &amp; Disbursals Ledger</h3>
              <p>Real-time audit log of all organizational budget allocations and disbursements.</p>
            </div>
            
            <div class="ledger-actions-bar">
              <!-- Live Search Filter -->
              <div class="search-input-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="budgetSearchInput" placeholder="Search title, club, requester..." onkeyup="filterLedgerTable()"/>
              </div>

              <!-- Status Filter Dropdown -->
              <select class="filter-select" id="budgetStatusFilter" onchange="filterLedgerTable()">
                <option value="ALL">All Statuses</option>
                <option value="Pending Adviser">Stage 1: Pending Adviser</option>
                <option value="Pending SSC">Stage 2: Pending SSC</option>
                <option value="Pending Admin">Stage 3: Pending Admin</option>
                <option value="Disbursed">Disbursed (Released)</option>
                <option value="Rejected">Rejected</option>
              </select>

              <?php if (in_array($sess_role, ['student', 'club_adviser', 'admin'])): ?>
              <button class="btn-create-req" onclick="openNewRequestModal()">
                <i class="fa-solid fa-plus"></i> New Requisition
              </button>
              <?php endif; ?>
            </div>
          </div>

          <!-- Responsive Table -->
          <div style="overflow-x:auto;">
            <table class="ledger-table" id="budgetLedgerTable">
              <thead>
                <tr>
                  <th style="width:70px;">ID</th>
                  <th style="width:240px;">Organization &amp; Requester</th>
                  <th>Requisition Details</th>
                  <th style="width:140px;">Requested</th>
                  <th style="width:180px;">Stage / Status</th>
                  <th>Review Notes</th>
                  <th style="width:160px; text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody id="budgetTableBody">
                <?php if (empty($budget_requests)): ?>
                  <tr id="emptyRow">
                    <td colspan="7" style="text-align:center; padding:50px 20px; color:#94a3b8;">
                      <div style="width:56px; height:56px; border-radius:50%; background:#f1f5f9; color:#94a3b8; display:flex; align-items:center; justify-content:center; margin:0 auto 12px; font-size:1.5rem;">
                        <i class="fa-solid fa-folder-open"></i>
                      </div>
                      <h4 style="margin:0 0 4px; color:#475569; font-size:1rem; font-weight:700;">No Budget Requisitions Found</h4>
                      <p style="margin:0; font-size:0.82rem;">There are currently no budget proposals filed under this category.</p>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($budget_requests as $req): ?>
                    <?php
                      $status = $req['status'];
                      $pill_class = match($status) {
                          'Pending Adviser' => 'status-pill-adviser',
                          'Pending SSC'     => 'status-pill-ssc',
                          'Pending Admin'   => 'status-pill-admin',
                          'Disbursed'       => 'status-pill-disbursed',
                          'Rejected'        => 'status-pill-rejected',
                          default           => 'status-pill-adviser',
                      };
                      $pill_icon = match($status) {
                          'Pending Adviser' => '<i class="fa-solid fa-clock"></i>',
                          'Pending SSC'     => '<i class="fa-solid fa-file-signature"></i>',
                          'Pending Admin'   => '<i class="fa-solid fa-user-shield"></i>',
                          'Disbursed'       => '<i class="fa-solid fa-circle-check"></i>',
                          'Rejected'        => '<i class="fa-solid fa-circle-xmark"></i>',
                          default           => '<i class="fa-solid fa-clock"></i>',
                      };

                      $can_approve  = false;
                      $can_edit_ssc = false;
                      $can_reject   = false;

                      if ($status === 'Pending Adviser' && in_array($sess_role, ['club_adviser', 'admin'])) {
                          $can_approve = true;
                          $can_reject  = true;
                      }
                      if ($status === 'Pending SSC' && in_array($sess_role, ['ssc', 'admin'])) {
                          $can_approve  = true;
                          $can_reject   = true;
                          $can_edit_ssc = true;
                      }
                      if ($status === 'Pending Admin' && $sess_role === 'admin') {
                          $can_approve = true;
                          $can_reject  = true;
                      }

                      $safe_title = htmlspecialchars(addslashes($req['title']));
                      $safe_desc  = htmlspecialchars(addslashes($req['description'] ?? ''));
                      $safe_notes = htmlspecialchars(addslashes($req['notes'] ?? ''));
                      $date_str   = date('M d, Y', strtotime($req['created_at']));
                    ?>
                    <tr class="budget-data-row" 
                        data-status="<?= htmlspecialchars($status) ?>"
                        data-search="<?= htmlspecialchars(strtolower($req['title'] . ' ' . $req['club_name'] . ' ' . $req['club_code'] . ' ' . $req['first_name'] . ' ' . $req['last_name'])) ?>">
                      
                      <!-- ID -->
                      <td>
                        <strong style="color:#64748b; font-size:0.8rem;">#<?= str_pad($req['id'], 4, '0', STR_PAD_LEFT) ?></strong>
                      </td>

                      <!-- Org & Requester -->
                      <td>
                        <div class="org-code-chip"><?= htmlspecialchars($req['club_code']) ?></div>
                        <div class="org-name-text"><?= htmlspecialchars($req['club_name']) ?></div>
                        <div class="requester-meta">
                          <i class="fa-solid fa-user" style="font-size:0.65rem;"></i> <?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?>
                          &bull; <?= $date_str ?>
                        </div>
                      </td>

                      <!-- Title & Excerpt -->
                      <td>
                        <div class="req-title-text"><?= htmlspecialchars($req['title']) ?></div>
                        <div class="req-desc-excerpt" title="<?= htmlspecialchars($req['description'] ?? '') ?>">
                          <?= htmlspecialchars($req['description'] ?: 'No description specified.') ?>
                        </div>
                      </td>

                      <!-- Amount -->
                      <td>
                        <div class="req-amount-val">&#8369;<?= number_format((float)$req['amount'], 2) ?></div>
                      </td>

                      <!-- Stage / Status -->
                      <td>
                        <span class="status-pill <?= $pill_class ?>">
                          <?= $pill_icon ?> <?= htmlspecialchars($status) ?>
                        </span>
                      </td>

                      <!-- Notes -->
                      <td>
                        <div style="font-size:0.78rem; color:#475569; line-height:1.35; max-width:220px;">
                          <?= htmlspecialchars($req['notes'] ?: '—') ?>
                        </div>
                      </td>

                      <!-- Actions -->
                      <td>
                        <div class="action-btn-group">
                          <?php if ($can_approve): ?>
                            <?php
                              $action_lbl = match($status) {
                                  'Pending Adviser' => 'Endorse',
                                  'Pending SSC'     => 'Forward',
                                  'Pending Admin'   => 'Disburse',
                                  default           => 'Approve',
                              };
                            ?>
                            <button type="button" class="act-btn act-btn-approve" 
                                    onclick="promptApprove(<?= $req['id'] ?>, '<?= $safe_title ?>', '<?= $status ?>')" 
                                    title="<?= $action_lbl ?> Requisition">
                              <i class="fa-solid fa-check"></i> <?= $action_lbl ?>
                            </button>
                          <?php endif; ?>

                          <?php if ($can_edit_ssc): ?>
                            <button type="button" class="act-btn act-btn-edit" 
                                    onclick="openEditModal(<?= $req['id'] ?>, '<?= $safe_desc ?>', '<?= $safe_notes ?>')" 
                                    title="Edit Line Items &amp; Notes">
                              <i class="fa-solid fa-pen-to-square"></i> Audit
                            </button>
                          <?php endif; ?>

                          <?php if ($can_reject): ?>
                            <button type="button" class="act-btn act-btn-reject" 
                                    onclick="promptReject(<?= $req['id'] ?>, '<?= $safe_title ?>')" 
                                    title="Reject Requisition">
                              <i class="fa-solid fa-xmark"></i> Reject
                            </button>
                          <?php endif; ?>

                          <button type="button" class="act-btn act-btn-view" 
                                  onclick="viewRequisitionDetails(<?= htmlspecialchars(json_encode($req), ENT_QUOTES) ?>)" 
                                  title="View Full Details">
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
    </div>
    <div class="footer">Co-Curricular Management System &copy; 2026</div>
  </div>

  <!-- -------------------------------------------------------------------------
       MODAL 1: Submit New Budget Requisition
  -------------------------------------------------------------------------- -->
  <div class="modal-overlay" id="newRequestModal">
    <div class="modal-dialog">
      <div class="modal-header-solid">
        <h3><i class="fa-solid fa-file-invoice-dollar" style="color:#facc15;"></i> Submit Budget Requisition</h3>
        <button class="modal-close-btn" onclick="closeModal('newRequestModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form id="newRequestForm" onsubmit="handleCreateRequest(event)">
        <div class="modal-body-pad">
          <div class="form-group-custom">
            <label>Host Organization <span style="color:#ef4444;">*</span></label>
            <select name="club_id" class="form-control-custom" required>
              <?php foreach ($user_clubs as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group-custom">
            <label>Requisition Title <span style="color:#ef4444;">*</span></label>
            <input type="text" name="title" class="form-control-custom" required placeholder="e.g. IT Week Technical Workshop Supplies &amp; Tokens"/>
          </div>

          <div class="form-group-custom">
            <label>Requested Amount (PHP) <span style="color:#ef4444;">*</span></label>
            <div class="currency-input-wrap">
              <span class="currency-prefix">&#8369;</span>
              <input type="number" step="0.01" min="1" name="amount" class="form-control-custom" required placeholder="0.00"/>
            </div>
          </div>

          <div class="form-group-custom" style="margin-bottom:0;">
            <label>Description &amp; Itemized Justification <span style="color:#ef4444;">*</span></label>
            <textarea name="description" rows="4" class="form-control-custom" required placeholder="Specify detailed item breakdown, unit prices, event purpose, and timeline..."></textarea>
          </div>
        </div>
        <div class="modal-footer-pad">
          <button type="button" onclick="closeModal('newRequestModal')" class="act-btn act-btn-view">Cancel</button>
          <button type="submit" class="act-btn act-btn-approve" id="submitReqBtn" style="padding:9px 18px;">
            <i class="fa-solid fa-paper-plane"></i> Submit Requisition
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- -------------------------------------------------------------------------
       MODAL 2: SSC Line-Item Audit & Edit
  -------------------------------------------------------------------------- -->
  <div class="modal-overlay" id="editModal">
    <div class="modal-dialog">
      <div class="modal-header-solid" style="background:#1a3a8c;">
        <h3><i class="fa-solid fa-pen-to-square" style="color:#facc15;"></i> SSC Line-Item Audit &amp; Review</h3>
        <button class="modal-close-btn" onclick="closeModal('editModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form id="editForm" onsubmit="handleEditRequest(event)">
        <input type="hidden" name="id" id="editId"/>
        <div class="modal-body-pad">
          <p style="font-size:0.83rem; color:#64748b; margin-top:0; margin-bottom:16px;">
            Update description or add official SSC audit notes before forwarding to Administration for disbursement.
          </p>
          <div class="form-group-custom">
            <label>Requisition Description</label>
            <textarea name="description" id="editDesc" rows="3" class="form-control-custom"></textarea>
          </div>
          <div class="form-group-custom" style="margin-bottom:0;">
            <label>SSC Review &amp; Audit Notes</label>
            <textarea name="notes" id="editNotes" rows="3" class="form-control-custom" placeholder="Add line-item recommendations or audit notes..."></textarea>
          </div>
        </div>
        <div class="modal-footer-pad">
          <button type="button" onclick="closeModal('editModal')" class="act-btn act-btn-view">Cancel</button>
          <button type="submit" class="act-btn act-btn-edit" style="padding:9px 18px;">
            <i class="fa-solid fa-floppy-disk"></i> Save Audit Notes
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- -------------------------------------------------------------------------
       MODAL 3: Requisition Full Details View
  -------------------------------------------------------------------------- -->
  <div class="modal-overlay" id="viewReqDetailsModal">
    <div class="modal-dialog">
      <div class="modal-header-solid">
        <h3><i class="fa-solid fa-file-lines" style="color:#facc15;"></i> Requisition Details</h3>
        <button class="modal-close-btn" onclick="closeModal('viewReqDetailsModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="modal-body-pad" id="viewReqDetailsBody">
        <!-- Rendered dynamically -->
      </div>
      <div class="modal-footer-pad">
        <button type="button" onclick="closeModal('viewReqDetailsModal')" class="act-btn act-btn-view">Close</button>
      </div>
    </div>
  </div>

  <!-- -------------------------------------------------------------------------
       MODAL 4: Action Confirmation Dialog (Approval / Rejection)
  -------------------------------------------------------------------------- -->
  <div class="modal-overlay" id="actionPromptModal">
    <div class="modal-dialog" style="max-width:480px;">
      <div class="modal-header-solid" id="actionPromptHeader">
        <h3 id="actionPromptTitle"><i class="fa-solid fa-shield-halved"></i> Confirm Action</h3>
        <button class="modal-close-btn" onclick="closeModal('actionPromptModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="modal-body-pad">
        <p id="actionPromptMessage" style="font-size:0.9rem; color:#1e293b; line-height:1.45; margin-top:0; font-weight:600;"></p>
        <div class="form-group-custom" style="margin-bottom:0;">
          <label id="actionPromptInputLabel">Notes / Feedback (Optional)</label>
          <textarea id="actionPromptInput" rows="3" class="form-control-custom" placeholder="Provide notes or justification..."></textarea>
        </div>
      </div>
      <div class="modal-footer-pad">
        <button type="button" onclick="closeModal('actionPromptModal')" class="act-btn act-btn-view">Cancel</button>
        <button type="button" id="actionPromptConfirmBtn" class="act-btn act-btn-approve" style="padding:9px 18px;">
          Confirm Action
        </button>
      </div>
    </div>
  </div>

  <script>
    // Live Ledger Table Search & Filter
    function filterLedgerTable() {
      const q = document.getElementById('budgetSearchInput').value.toLowerCase().trim();
      const statusFilter = document.getElementById('budgetStatusFilter').value;
      const rows = document.querySelectorAll('.budget-data-row');
      let visibleCount = 0;

      rows.forEach(row => {
        const rowSearch = row.dataset.search || '';
        const rowStatus = row.dataset.status || '';

        const matchesQuery = !q || rowSearch.includes(q);
        const matchesStatus = statusFilter === 'ALL' || rowStatus === statusFilter;

        if (matchesQuery && matchesStatus) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      const emptyRow = document.getElementById('emptyRow');
      if (emptyRow) {
        emptyRow.style.display = visibleCount === 0 ? '' : 'none';
      }
    }

    function openNewRequestModal() {
      document.getElementById('newRequestModal').style.display = 'flex';
    }

    function openEditModal(id, desc, notes) {
      document.getElementById('editId').value = id;
      document.getElementById('editDesc').value = desc;
      document.getElementById('editNotes').value = notes;
      document.getElementById('editModal').style.display = 'flex';
    }

    function closeModal(id) {
      const el = document.getElementById(id);
      if (el) el.style.display = 'none';
    }

    // View Requisition Details
    function viewRequisitionDetails(req) {
      const body = document.getElementById('viewReqDetailsBody');
      const formattedAmount = '₱' + parseFloat(req.amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const formattedDate = new Date(req.created_at).toLocaleDateString('en-PH', { dateStyle: 'medium' });

      body.innerHTML = `
        <div style="display:flex; flex-direction:column; gap:16px;">
          <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
            <div>
              <span class="org-code-chip">${req.club_code}</span>
              <h4 style="margin:4px 0 0; font-size:1.1rem; color:#0f172a;">${req.title}</h4>
            </div>
            <div style="text-align:right;">
              <div style="font-size:0.75rem; color:#64748b; font-weight:700; text-transform:uppercase;">Amount</div>
              <div style="font-size:1.3rem; font-weight:800; color:#1a3a8c;">${formattedAmount}</div>
            </div>
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; background:#f8fafc; padding:14px; border-radius:10px; border:1px solid #e2e8f0;">
            <div>
              <span style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Organization</span>
              <div style="font-size:0.85rem; font-weight:700; color:#0f172a; margin-top:2px;">${req.club_name}</div>
            </div>
            <div>
              <span style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Requested By</span>
              <div style="font-size:0.85rem; font-weight:600; color:#0f172a; margin-top:2px;">${req.first_name} ${req.last_name}</div>
            </div>
            <div>
              <span style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Date Filed</span>
              <div style="font-size:0.85rem; font-weight:600; color:#0f172a; margin-top:2px;">${formattedDate}</div>
            </div>
            <div>
              <span style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Current Stage</span>
              <div style="font-size:0.85rem; font-weight:700; color:#1a3a8c; margin-top:2px;">${req.status}</div>
            </div>
          </div>

          <div>
            <span style="font-size:0.72rem; font-weight:800; color:#64748b; text-transform:uppercase;">Itemized Description &amp; Justification</span>
            <p style="margin:6px 0 0; font-size:0.88rem; line-height:1.55; color:#334155; white-space:pre-line;">${req.description || 'No description specified.'}</p>
          </div>

          <div>
            <span style="font-size:0.72rem; font-weight:800; color:#64748b; text-transform:uppercase;">Audit &amp; Review History</span>
            <p style="margin:6px 0 0; font-size:0.85rem; color:#475569; background:#f1f5f9; padding:10px 12px; border-radius:8px;">${req.notes || 'No review notes logged yet.'}</p>
          </div>
        </div>
      `;

      document.getElementById('viewReqDetailsModal').style.display = 'flex';
    }

    // Modal Action Confirmation Dialogs
    let pendingAction = null;

    function promptApprove(id, title, status) {
      let label = 'Endorse Requisition';
      let msg = `Are you sure you want to endorse and forward "${title}" to Stage 2 (SSC Review)?`;

      if (status === 'Pending SSC') {
        label = 'Forward to Admin';
        msg = `Audit complete. Forward "${title}" to Stage 3 (Admin Final Approval)?`;
      } else if (status === 'Pending Admin') {
        label = 'Disburse & Release Funds';
        msg = `Final authorization: Approve and disburse funding for "${title}"?`;
      }

      document.getElementById('actionPromptTitle').innerHTML = `<i class="fa-solid fa-check-circle" style="color:#22c55e;"></i> ${label}`;
      document.getElementById('actionPromptHeader').style.background = '#1a3a8c';
      document.getElementById('actionPromptMessage').textContent = msg;
      document.getElementById('actionPromptInputLabel').textContent = 'Audit / Approval Notes (Optional)';
      document.getElementById('actionPromptInput').value = '';
      document.getElementById('actionPromptConfirmBtn').className = 'act-btn act-btn-approve';
      document.getElementById('actionPromptConfirmBtn').innerHTML = `<i class="fa-solid fa-check"></i> Confirm ${label}`;

      pendingAction = async () => {
        const notes = document.getElementById('actionPromptInput').value.trim();
        const fd = new FormData();
        fd.append('action', 'approve');
        fd.append('id', id);
        fd.append('notes', notes);

        const btn = document.getElementById('actionPromptConfirmBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

        try {
          const res = await fetch('../shared/budget_actions.php', { method: 'POST', body: fd });
          const data = await res.json();
          closeModal('actionPromptModal');
          if (data.success) {
            alert('✓ ' + data.message);
            location.reload();
          } else {
            alert('✗ ' + data.message);
          }
        } catch {
          alert('Network error.');
        }
        btn.disabled = false;
      };

      document.getElementById('actionPromptModal').style.display = 'flex';
    }

    function promptReject(id, title) {
      document.getElementById('actionPromptTitle').innerHTML = `<i class="fa-solid fa-times-circle" style="color:#ef4444;"></i> Reject Requisition`;
      document.getElementById('actionPromptHeader').style.background = '#dc2626';
      document.getElementById('actionPromptMessage').textContent = `Please specify the feedback or reasons for rejecting "${title}":`;
      document.getElementById('actionPromptInputLabel').textContent = 'Reason for Rejection *';
      document.getElementById('actionPromptInput').value = '';
      document.getElementById('actionPromptConfirmBtn').className = 'act-btn act-btn-reject';
      document.getElementById('actionPromptConfirmBtn').innerHTML = `<i class="fa-solid fa-times"></i> Confirm Rejection`;

      pendingAction = async () => {
        const reason = document.getElementById('actionPromptInput').value.trim();
        if (!reason) {
          alert('Please enter a reason for rejection.');
          return;
        }

        const fd = new FormData();
        fd.append('action', 'reject');
        fd.append('id', id);
        fd.append('reason', reason);

        const btn = document.getElementById('actionPromptConfirmBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

        try {
          const res = await fetch('../shared/budget_actions.php', { method: 'POST', body: fd });
          const data = await res.json();
          closeModal('actionPromptModal');
          if (data.success) {
            alert('✓ ' + data.message);
            location.reload();
          } else {
            alert('✗ ' + data.message);
          }
        } catch {
          alert('Network error.');
        }
        btn.disabled = false;
      };

      document.getElementById('actionPromptModal').style.display = 'flex';
    }

    document.getElementById('actionPromptConfirmBtn').addEventListener('click', () => {
      if (typeof pendingAction === 'function') pendingAction();
    });

    // Handle Create Request
    async function handleCreateRequest(e) {
      e.preventDefault();
      const btn = document.getElementById('submitReqBtn');
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';

      const fd = new FormData(e.target);
      fd.append('action', 'create');

      try {
        const res = await fetch('../shared/budget_actions.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
          alert('✓ ' + data.message);
          location.reload();
        } else {
          alert('✗ ' + data.message);
        }
      } catch {
        alert('Network error.');
      }
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Requisition';
    }

    // Handle Edit Request
    async function handleEditRequest(e) {
      e.preventDefault();
      const fd = new FormData(e.target);
      fd.append('action', 'ssc_edit');

      try {
        const res = await fetch('../shared/budget_actions.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
          alert('✓ ' + data.message);
          location.reload();
        } else {
          alert('✗ ' + data.message);
        }
      } catch {
        alert('Network error.');
      }
    }

    // Filter budget ledger table
    function filterLedgerTable() {
      const q = (document.getElementById('budgetSearchInput')?.value || '').toLowerCase().trim();
      const statusFilter = (document.getElementById('budgetStatusFilter')?.value || 'ALL');
      
      const rows = document.querySelectorAll('#budgetTableBody tr:not(#emptyRow)');
      rows.forEach(tr => {
        const text = tr.textContent.toLowerCase();
        const matchesQuery = !q || text.includes(q);
        const matchesStatus = (statusFilter === 'ALL') || tr.textContent.includes(statusFilter);
        const isVisible = matchesQuery && matchesStatus;
        tr.setAttribute('data-search-hidden', isVisible ? 'false' : 'true');
      });

      const tbl = document.getElementById('budgetLedgerTable');
      if (tbl && tbl._paginator) {
        tbl._paginator.currentPage = 1;
        tbl._paginator.refresh();
      }
    }
  </script>

  <script src="https://unpkg.com/@zxing/library@0.21.1/umd/index.min.js"></script>
  <script src="../js/dashboard.js"></script>
  <script src="../js/table-pagination.js"></script>
</body>
</html>
