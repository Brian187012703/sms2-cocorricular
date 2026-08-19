<?php
// ============================================================
//  BUDGET.PHP — Budget & Finance Management
//  3-Stage Workflow: Pending Adviser → Pending SSC → Pending Admin → Disbursed
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$user_id      = (int)$_SESSION['user_id'];
$APP_ROOT     = '../';
$ACTIVE_NAV   = 'budget';

if ($sess_role === 'student') {
    header('Location: dashboard.php');
    exit;
}

// Fetch clubs for requisition form
$user_clubs = $conn->query("SELECT id, name, code FROM clubs WHERE status='Active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Fetch budget requests based on role
$where  = '';
if ($sess_role === 'club_adviser') {
    $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
    $cm->bind_param('i', $user_id); $cm->execute();
    $cm->bind_result($my_club_id); $cm->fetch(); $cm->close();
    if (!empty($my_club_id)) $where = "WHERE br.club_id = $my_club_id";
} elseif ($sess_role === 'ssc') {
    $where = "WHERE br.status IN ('Pending SSC','Pending Admin','Disbursed','Rejected')";
}
// admin sees all

$budget_requests = $conn->query(
    "SELECT br.id, br.club_id, br.title, br.description, br.amount, br.status, br.notes, br.created_at,
            c.name AS club_name, c.code AS club_code,
            u.first_name, u.last_name
     FROM budget_requests br
     JOIN clubs c ON c.id = br.club_id
     JOIN users u ON u.id = br.requested_by
     $where ORDER BY br.created_at DESC"
)->fetch_all(MYSQLI_ASSOC);

// Metrics
$total_requested = 0; $pending_count = 0; $disbursed_total = 0; $rejected_count = 0;
foreach ($budget_requests as $req) {
    $total_requested += (float)$req['amount'];
    if (in_array($req['status'], ['Pending Adviser','Pending SSC','Pending Admin'])) $pending_count++;
    elseif ($req['status'] === 'Disbursed') $disbursed_total += (float)$req['amount'];
    elseif ($req['status'] === 'Rejected') $rejected_count++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Budget & Finance — BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
    .budget-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:24px; }
    .kpi-card { background:#fff; border-radius:14px; padding:20px; border:1px solid #e2e8f0; box-shadow:0 2px 8px rgba(0,0,0,0.04); }
    .kpi-title { font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; }
    .kpi-value { font-size:1.6rem; font-weight:800; color:#0f172a; margin-top:6px; }
    .status-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:0.72rem; font-weight:700; }
    .badge-pending-adviser { background:#fef3c7; color:#92400e; }
    .badge-pending-ssc     { background:#e0e7ff; color:#3730a3; }
    .badge-pending-admin   { background:#fce7f3; color:#9d174d; }
    .badge-disbursed       { background:#dcfce7; color:#166534; }
    .badge-rejected        { background:#fee2e2; color:#991b1b; }
    .workflow-bar { display:flex; align-items:center; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
    .wf-step { display:flex; align-items:center; gap:6px; }
    .wf-dot { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.7rem; font-weight:700; flex-shrink:0; }
    .wf-dot.active { background:#2563eb; color:#fff; }
    .wf-dot.done   { background:#16a34a; color:#fff; }
    .wf-dot.idle   { background:#e2e8f0; color:#94a3b8; }
    .wf-label { font-size:0.75rem; font-weight:600; color:#475569; }
    .wf-arrow { color:#cbd5e1; font-size:0.8rem; }
    .modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); display:none; align-items:center; justify-content:center; z-index:9999; }
    .modal-card { background:#fff; border-radius:16px; width:100%; max-width:560px; padding:28px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); max-height:90vh; overflow-y:auto; }
    .btn-sm { padding:5px 12px; font-size:0.78rem; border-radius:6px; border:none; cursor:pointer; font-weight:700; display:inline-flex; align-items:center; gap:5px; }
    .btn-success { background:#16a34a; color:#fff; }
    .btn-success:hover { background:#15803d; }
    .btn-warning { background:#d97706; color:#fff; }
    .btn-warning:hover { background:#b45309; }
    .btn-danger { background:#dc2626; color:#fff; }
    .btn-danger:hover { background:#b91c1c; }
    .btn-primary { background:#2563eb; color:#fff; }
    .btn-primary:hover { background:#1d4ed8; }
    .btn-secondary { background:#f1f5f9; color:#475569; }
    .form-control { width:100%; padding:10px 12px; border-radius:8px; border:1.5px solid #cbd5e1; font-size:0.9rem; }
    .form-control:focus { outline:none; border-color:#2563eb; }
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
          <input type="text" id="budgetSearch" placeholder="Search budget requests..." oninput="filterBudget(this.value)"/>
          <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i class="fa-solid fa-qrcode"></i></button>
        <a href="../dashboard/account.php" class="avatar" title="Account"><?= $sess_initial ?></a>
      </div>
    </div>

    <div class="content">
      <div class="page-title-bar">
        <h2 class="page-title"><i class="fa-solid fa-hand-holding-dollar"></i> Budget & Finance Management</h2>
      </div>

      <div class="content-body">

        <!-- Workflow Indicator -->
        <div style="background:#fff;border-radius:14px;padding:18px 22px;margin-bottom:20px;border:1px solid #e2e8f0;">
          <div style="font-size:0.8rem;font-weight:700;color:#64748b;margin-bottom:12px;text-transform:uppercase;letter-spacing:.5px;">Budget Approval Workflow</div>
          <div class="workflow-bar">
            <div class="wf-step">
              <div class="wf-dot <?= $sess_role==='club_adviser'?'active':'done' ?>"><i class="fa-solid fa-pen-to-square"></i></div>
              <div>
                <div class="wf-label">Stage 1</div>
                <div style="font-size:0.7rem;color:#94a3b8;">Adviser Endorsement</div>
              </div>
            </div>
            <div class="wf-arrow"><i class="fa-solid fa-arrow-right"></i></div>
            <div class="wf-step">
              <div class="wf-dot <?= $sess_role==='ssc'?'active':($sess_role==='admin'?'done':'idle') ?>"><i class="fa-solid fa-eye"></i></div>
              <div>
                <div class="wf-label">Stage 2</div>
                <div style="font-size:0.7rem;color:#94a3b8;">SSC Review & Edit</div>
              </div>
            </div>
            <div class="wf-arrow"><i class="fa-solid fa-arrow-right"></i></div>
            <div class="wf-step">
              <div class="wf-dot <?= $sess_role==='admin'?'active':'idle' ?>"><i class="fa-solid fa-circle-check"></i></div>
              <div>
                <div class="wf-label">Stage 3</div>
                <div style="font-size:0.7rem;color:#94a3b8;">Admin Approval & Disbursement</div>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI Cards -->
        <div class="budget-grid">
          <div class="kpi-card">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <span class="kpi-title">Total Requested</span>
              <div style="width:38px;height:38px;border-radius:10px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-coins"></i></div>
            </div>
            <div class="kpi-value">&#8369;<?= number_format($total_requested, 2) ?></div>
          </div>
          <div class="kpi-card">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <span class="kpi-title">Pending Approvals</span>
              <div style="width:38px;height:38px;border-radius:10px;background:#fffbeb;color:#d97706;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-hourglass-half"></i></div>
            </div>
            <div class="kpi-value"><?= $pending_count ?></div>
          </div>
          <div class="kpi-card">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <span class="kpi-title">Total Disbursed</span>
              <div style="width:38px;height:38px;border-radius:10px;background:#f0fdf4;color:#16a34a;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-circle-check"></i></div>
            </div>
            <div class="kpi-value">&#8369;<?= number_format($disbursed_total, 2) ?></div>
          </div>
          <div class="kpi-card">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <span class="kpi-title">Rejected Requests</span>
              <div style="width:38px;height:38px;border-radius:10px;background:#fef2f2;color:#dc2626;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-circle-xmark"></i></div>
            </div>
            <div class="kpi-value"><?= $rejected_count ?></div>
          </div>
        </div>

        <!-- Table Card -->
        <div class="card" style="padding:0;border-radius:14px;overflow:hidden;background:#fff;border:1px solid #e2e8f0;">
          <div style="padding:18px 22px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <div>
              <h3 style="margin:0 0 2px;font-size:1rem;color:#0f172a;">Requisitions & Disbursals Ledger</h3>
              <p style="margin:0;font-size:0.8rem;color:#64748b;">Track all budget requests through the 3-stage approval pipeline.</p>
            </div>
            <?php if (in_array($sess_role, ['club_adviser', 'admin'])): ?>
            <button class="btn btn-primary" onclick="openNewRequestModal()" style="height:38px;padding:0 16px;border:none;border-radius:8px;cursor:pointer;font-weight:700;display:flex;align-items:center;gap:6px;">
              <i class="fa-solid fa-plus"></i> New Requisition
            </button>
            <?php endif; ?>
          </div>
          <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;text-align:left;font-size:0.87rem;">
              <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;">
                  <th style="padding:13px 16px;">ID</th>
                  <th style="padding:13px 16px;">Organization & Requester</th>
                  <th style="padding:13px 16px;">Title</th>
                  <th style="padding:13px 16px;">Amount</th>
                  <th style="padding:13px 16px;">Stage / Status</th>
                  <th style="padding:13px 16px;">Notes</th>
                  <th style="padding:13px 16px;text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody id="budgetTableBody">
                <?php if (empty($budget_requests)): ?>
                  <tr>
                    <td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;">
                      <i class="fa-solid fa-folder-open" style="font-size:2rem;display:block;margin-bottom:8px;color:#cbd5e1;"></i>
                      No budget requests found.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($budget_requests as $req): ?>
                    <?php
                      $cls = match($req['status']) {
                          'Pending Adviser' => 'badge-pending-adviser',
                          'Pending SSC'     => 'badge-pending-ssc',
                          'Pending Admin'   => 'badge-pending-admin',
                          'Disbursed'       => 'badge-disbursed',
                          'Rejected'        => 'badge-rejected',
                          default           => 'badge-pending-adviser',
                      };
                      $can_approve = false;
                      $can_edit_ssc = false;
                      $can_reject = false;
                      if ($req['status'] === 'Pending Adviser' && in_array($sess_role, ['club_adviser','admin'])) {
                          $can_approve = true; $can_reject = true;
                      }
                      if ($req['status'] === 'Pending SSC' && in_array($sess_role, ['ssc','admin'])) {
                          $can_approve = true; $can_reject = true; $can_edit_ssc = true;
                      }
                      if ($req['status'] === 'Pending Admin' && $sess_role === 'admin') {
                          $can_approve = true; $can_reject = true;
                      }
                      $safe_title = htmlspecialchars(addslashes($req['title']));
                      $safe_desc  = htmlspecialchars(addslashes($req['description'] ?? ''));
                      $safe_notes = htmlspecialchars(addslashes($req['notes'] ?? ''));
                    ?>
                    <tr class="budget-row" data-search="<?= strtolower($req['title'].' '.$req['club_name'].' '.$req['first_name'].' '.$req['last_name'].' '.$req['status']) ?>" style="border-bottom:1px solid #f1f5f9;">
                      <td style="padding:13px 16px;font-weight:700;color:#64748b;">#<?= sprintf('%03d', $req['id']) ?></td>
                      <td style="padding:13px 16px;">
                        <strong style="display:block;color:#0f172a;"><?= htmlspecialchars($req['club_name']) ?></strong>
                        <span style="font-size:0.78rem;color:#64748b;"><?= htmlspecialchars($req['first_name'].' '.$req['last_name']) ?></span>
                      </td>
                      <td style="padding:13px 16px;">
                        <div style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($req['title']) ?></div>
                        <div style="font-size:0.76rem;color:#64748b;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($req['description'] ?: '—') ?></div>
                      </td>
                      <td style="padding:13px 16px;font-weight:800;color:#0f172a;white-space:nowrap;">&#8369;<?= number_format((float)$req['amount'],2) ?></td>
                      <td style="padding:13px 16px;"><span class="status-badge <?= $cls ?>"><?= htmlspecialchars($req['status']) ?></span></td>
                      <td style="padding:13px 16px;font-size:0.78rem;color:#475569;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($req['notes'] ?? '') ?>"><?= htmlspecialchars($req['notes'] ?: '—') ?></td>
                      <td style="padding:13px 16px;text-align:right;white-space:nowrap;">
                        <?php if ($can_edit_ssc): ?>
                          <button class="btn-sm btn-warning" onclick="openEditModal(<?= $req['id'] ?>,'<?= $safe_desc ?>','<?= $safe_notes ?>')" style="margin-right:4px;">
                            <i class="fa-solid fa-pen"></i> Edit
                          </button>
                        <?php endif; ?>
                        <?php if ($can_approve): ?>
                          <button class="btn-sm btn-success" onclick="approveRequest(<?= $req['id'] ?>,'<?= $safe_title ?>','<?= htmlspecialchars($req['status']) ?>')" style="margin-right:4px;">
                            <i class="fa-solid fa-check"></i>
                            <?php
                              if ($req['status'] === 'Pending Adviser') echo 'Endorse';
                              elseif ($req['status'] === 'Pending SSC') echo 'Forward';
                              else echo 'Disburse';
                            ?>
                          </button>
                        <?php endif; ?>
                        <?php if ($can_reject): ?>
                          <button class="btn-sm btn-danger" onclick="rejectRequest(<?= $req['id'] ?>,'<?= $safe_title ?>')">
                            <i class="fa-solid fa-xmark"></i> Reject
                          </button>
                        <?php endif; ?>
                        <?php if (!$can_approve && !$can_reject): ?>
                          <span style="font-size:0.75rem;color:#94a3b8;">—</span>
                        <?php endif; ?>
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

  <!-- New Request Modal -->
  <div class="modal-overlay" id="newRequestModal" style="display:none;">
    <div class="modal-card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
        <h3 style="margin:0;font-size:1.1rem;color:#0f172a;"><i class="fa-solid fa-file-invoice-dollar" style="color:#2563eb;margin-right:8px;"></i>Submit Budget Requisition</h3>
        <button onclick="closeModal('newRequestModal')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#64748b;">&times;</button>
      </div>
      <form id="newRequestForm" onsubmit="handleCreateRequest(event)">
        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:0.83rem;font-weight:600;color:#334155;margin-bottom:4px;">Organization</label>
          <select name="club_id" class="form-control" required>
            <?php foreach ($user_clubs as $c): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:0.83rem;font-weight:600;color:#334155;margin-bottom:4px;">Requisition Title</label>
          <input type="text" name="title" class="form-control" required placeholder="e.g. Workshop Supplies & Materials"/>
        </div>
        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:0.83rem;font-weight:600;color:#334155;margin-bottom:4px;">Requested Amount (₱)</label>
          <input type="number" step="0.01" min="1" name="amount" class="form-control" required placeholder="e.g. 15000.00"/>
        </div>
        <div style="margin-bottom:20px;">
          <label style="display:block;font-size:0.83rem;font-weight:600;color:#334155;margin-bottom:4px;">Description & Justification</label>
          <textarea name="description" rows="3" class="form-control" placeholder="Itemized breakdown, purpose, and expected timeline..."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" onclick="closeModal('newRequestModal')" class="btn-sm btn-secondary" style="border:none;">Cancel</button>
          <button type="submit" class="btn-sm btn-primary" style="border:none;"><i class="fa-solid fa-paper-plane"></i> Submit Request</button>
        </div>
      </form>
    </div>
  </div>

  <!-- SSC Edit Modal -->
  <div class="modal-overlay" id="editModal" style="display:none;">
    <div class="modal-card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
        <h3 style="margin:0;font-size:1.1rem;color:#0f172a;"><i class="fa-solid fa-pen-to-square" style="color:#d97706;margin-right:8px;"></i>SSC Edit — Budget Request</h3>
        <button onclick="closeModal('editModal')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#64748b;">&times;</button>
      </div>
      <p style="font-size:0.83rem;color:#64748b;margin-bottom:16px;">Update description or add review notes before forwarding to Admin.</p>
      <form id="editForm" onsubmit="handleEditRequest(event)">
        <input type="hidden" name="id" id="editId"/>
        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:0.83rem;font-weight:600;color:#334155;margin-bottom:4px;">Description</label>
          <textarea name="description" id="editDesc" rows="3" class="form-control"></textarea>
        </div>
        <div style="margin-bottom:20px;">
          <label style="display:block;font-size:0.83rem;font-weight:600;color:#334155;margin-bottom:4px;">SSC Review Notes</label>
          <textarea name="notes" id="editNotes" rows="3" class="form-control" placeholder="Add your SSC review notes here..."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" onclick="closeModal('editModal')" class="btn-sm btn-secondary" style="border:none;">Cancel</button>
          <button type="submit" class="btn-sm btn-warning" style="border:none;"><i class="fa-solid fa-save"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Search filter
    function filterBudget(q) {
      q = q.toLowerCase().trim();
      document.querySelectorAll('.budget-row').forEach(row => {
        row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
      });
    }

    function openNewRequestModal() { document.getElementById('newRequestModal').style.display = 'flex'; }
    function closeModal(id) {
      const el = document.getElementById(id);
      if (el) { el.classList.remove('active', 'open'); el.style.display = 'none'; }
    }

    function openEditModal(id, desc, notes) {
      document.getElementById('editId').value = id;
      document.getElementById('editDesc').value = desc;
      document.getElementById('editNotes').value = notes;
      document.getElementById('editModal').style.display = 'flex';
    }

    async function handleCreateRequest(e) {
      e.preventDefault();
      const btn = e.target.querySelector('[type=submit]');
      btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
      const fd = new FormData(e.target);
      fd.append('action', 'create');
      try {
        const res = await fetch('../shared/budget_actions.php', { method:'POST', body:fd });
        const data = await res.json();
        if (data.success) { alert('✅ ' + data.message); location.reload(); }
        else { alert('❌ ' + data.message); }
      } catch { alert('Network error.'); }
      btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Request';
    }

    async function handleEditRequest(e) {
      e.preventDefault();
      const fd = new FormData(e.target);
      fd.append('action', 'ssc_edit');
      try {
        const res = await fetch('../shared/budget_actions.php', { method:'POST', body:fd });
        const data = await res.json();
        if (data.success) { alert('✅ ' + data.message); location.reload(); }
        else { alert('❌ ' + data.message); }
      } catch { alert('Network error.'); }
    }

    async function approveRequest(id, title, status) {
      let label = 'Endorse';
      if (status === 'Pending SSC') label = 'Forward to Admin';
      if (status === 'Pending Admin') label = 'Disburse & Approve';
      const notes = prompt(`${label} "${title}"?\nAdd optional notes:`);
      if (notes === null) return;
      const fd = new FormData();
      fd.append('action','approve'); fd.append('id',id); fd.append('notes',notes);
      try {
        const res = await fetch('../shared/budget_actions.php', { method:'POST', body:fd });
        const data = await res.json();
        alert((data.success?'✅ ':'❌ ') + data.message);
        if (data.success) location.reload();
      } catch { alert('Network error.'); }
    }

    async function rejectRequest(id, title) {
      const reason = prompt(`Reject "${title}"?\nReason for rejection:`);
      if (!reason) return;
      const fd = new FormData();
      fd.append('action','reject'); fd.append('id',id); fd.append('reason',reason);
      try {
        const res = await fetch('../shared/budget_actions.php', { method:'POST', body:fd });
        const data = await res.json();
        alert((data.success?'✅ ':'❌ ') + data.message);
        if (data.success) location.reload();
      } catch { alert('Network error.'); }
    }
  </script>

  <script src="https://unpkg.com/@zxing/library@0.21.1/umd/index.min.js"></script>
  <script src="../js/dashboard.js"></script>
</body>
</html>
