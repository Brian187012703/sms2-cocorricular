<?php
// ============================================================
//  ADMIN_SYSTEM.PHP  (dashboard/)
//  Co-Curricular System — System Administration (Live DB + AJAX)
//  Accessible only to: admin, ssc
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }
if (!in_array($_SESSION['role'] ?? '', ['admin', 'ssc'])) { header('Location: dashboard.php'); exit; }

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'admin';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// -- Live stats ------------------------------------------------
$user_count   = (int)$conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$club_count   = (int)$conn->query("SELECT COUNT(*) FROM clubs WHERE status='Active'")->fetch_row()[0];
$pending_apps = (int)$conn->query("SELECT COUNT(*) FROM club_memberships WHERE status='Pending'")->fetch_row()[0];

// -- User list -------------------------------------------------
$all_users = $conn->query(
    "SELECT id, username, email, first_name, last_name, role, created_at
     FROM users ORDER BY role, last_name"
)->fetch_all(MYSQLI_ASSOC);

// -- Recent audit logs -----------------------------------------
$audit_logs = $conn->query(
    "SELECT al.action, al.target_table, al.detail, al.created_at,
            u.first_name, u.last_name, u.role
     FROM audit_logs al
     JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC LIMIT 30"
)->fetch_all(MYSQLI_ASSOC);

$role_labels = [
    'admin'          => 'System Admin',
    'ssc'            => 'Supreme Student Council (SSC)',
    'club_adviser'   => 'Club Adviser',
    'student'        => 'Student',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>System Administration – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
    .role-badge { display:inline-block;padding:2px 9px;border-radius:12px;font-size:0.72rem;font-weight:700;letter-spacing:0.02em; }
    .role-admin { background:#fee2e2;color:#991b1b; }
    .role-ssc { background:#fef3c7;color:#92400e; }
    .role-osa { background:#fef3c7;color:#92400e; }
    .role-adviser { background:#e0e7ff;color:#3730a3; }
    .role-officer { background:#ede9fe;color:#5b21b6; }
    .role-finance { background:#dcfce7;color:#166534; }
    .role-student { background:#f1f5f9;color:#475569; }
    .admin-alert { padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;display:none; }
    .role-select { padding:5px 8px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:0.8rem;color:#1a1a2e; }
    .modal-overlay { position:fixed;inset:0;background:rgba(10,12,30,0.65);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px; }
    .modal-overlay.active { display:flex;animation:fadeIn 0.2s ease; }
    @keyframes fadeIn{from{opacity:0}to{opacity:1}}
    .modal-card { background:#fff;border-radius:20px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,0.3); }
    .modal-header { padding:18px 24px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;border-radius:20px 20px 0 0;background:#f8fafc; }
    .modal-header h3 { margin:0;font-size:1rem;color:#1a1a2e; }
    .modal-body { padding:20px 24px; }
    .modal-footer { padding:14px 24px;border-top:1px solid #e2e8f0;display:flex;gap:10px;justify-content:flex-end; }
    .form-group { margin-bottom:14px; }
    .form-group label { display:block;font-size:0.78rem;font-weight:700;color:#64748b;margin-bottom:5px;text-transform:uppercase; }
    .form-group select, .form-group input { width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:0.88rem; }
  </style>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'admin';
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

  <!-- Content -->
  <div class="content">

    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-shield-halved"></i>
        System Administration &amp; Role Access Control
      </h2>
    </div>

    <div class="content-body">

      <!-- Alert box -->
      <div id="adminAlert" class="admin-alert"></div>

      <!-- Stats Row -->
      <div class="info-row">
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-users-gear"></i> Registered Users</div>
          <div class="card-amount"><?= $user_count ?></div>
          <div class="card-detail">Across all roles</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-building"></i> Active Clubs</div>
          <div class="card-amount"><?= $club_count ?></div>
          <div class="card-detail">Accredited organizations</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-clock"></i> Pending Applications</div>
          <div class="card-amount"><?= $pending_apps ?></div>
          <div class="card-detail">Club membership requests</div>
        </div>
        <?php if ($sess_role === 'admin'): ?>
        <div class="info-card" id="override-card" style="display:flex; flex-direction:column; justify-content:space-between;">
          <div>
            <div class="card-label"><i class="fa-solid fa-screwdriver-wrench"></i> Workflow Override</div>
            <div class="card-detail" style="margin-top:4px;">Force-approve stuck budget requests</div>
          </div>
          <div style="margin-top:12px;">
            <button class="card-btn" id="openOverrideBtn" style="width:100%; height:40px; justify-content:center; background:#2563eb; color:#fff; font-weight:700; border-radius:8px; cursor:pointer;">
              <i class="fa-solid fa-sliders"></i> Override Stuck Budget
            </button>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- User Management Table (Admin only can change roles; SSC can view) -->
      <div class="table-card" id="users">
        <h3>
          <i class="fa-solid fa-users-gear" style="color:#2563eb;"></i>
          User Account Management
          <?php if ($sess_role === 'admin'): ?>
          <span style="font-size:0.72rem;font-weight:500;color:#94a3b8;margin-left:8px;">Click a role badge to update it</span>
          <?php endif; ?>
        </h3>
        <?php if (empty($all_users)): ?>
          <p style="text-align:center;color:#94a3b8;padding:20px;">No users registered yet.</p>
        <?php else: ?>
        <table class="data-table" id="userTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Full Name</th>
              <th>Username</th>
              <th>Email</th>
              <th>Role</th>
              <th>Registered</th>
              <?php if ($sess_role === 'admin'): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($all_users as $u): ?>
            <?php $ridx = str_replace('_', '', $u['role']); ?>
            <tr class="user-row" id="user-row-<?= $u['id'] ?>">
              <td><?= $u['id'] ?></td>
              <td><strong><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></strong></td>
              <td><?= htmlspecialchars($u['username']) ?></td>
              <td><?= htmlspecialchars($u['email']) ?></td>
              <td>
                <span class="role-badge role-<?= $ridx ?>"><?= htmlspecialchars($role_labels[$u['role']] ?? $u['role']) ?></span>
              </td>
              <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
              <?php if ($sess_role === 'admin'): ?>
              <td>
                <?php if ($u['id'] !== $user_id): ?>
                <select class="role-select" onchange="updateRole(<?= $u['id'] ?>, this.value)">
                  <?php foreach ($role_labels as $rk => $rl): ?>
                  <option value="<?= $rk ?>" <?= $u['role'] === $rk ? 'selected' : '' ?>><?= $rl ?></option>
                  <?php endforeach; ?>
                </select>
                <?php else: ?>
                <span style="font-size:0.75rem;color:#94a3b8;">You</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <!-- Audit Log Table -->
      <div class="table-card" id="audit">
        <h3><i class="fa-solid fa-lock" style="color:#2563eb;"></i> System Activity Audit Log (Last 30 Actions)</h3>
        <?php if (empty($audit_logs)): ?>
          <p style="text-align:center;color:#94a3b8;padding:20px;">No audit logs recorded yet.</p>
        <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>User</th>
              <th>Role</th>
              <th>Action</th>
              <th>Target</th>
              <th>Detail</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($audit_logs as $log): ?>
            <tr>
              <td><?= date('M d h:i A', strtotime($log['created_at'])) ?></td>
              <td><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></td>
              <td><span class="role-badge role-<?= str_replace('_','',$log['role']) ?>"><?= htmlspecialchars($role_labels[$log['role']] ?? $log['role']) ?></span></td>
              <td><code style="font-size:0.75rem;"><?= htmlspecialchars($log['action']) ?></code></td>
              <td><?= htmlspecialchars($log['target_table'] ?? '—') ?></td>
              <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($log['detail'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

    </div><!-- end content-body -->
  </div><!-- end content -->

  <div class="footer">Co-Curricular Management System &copy; 2026</div>
</div><!-- end main -->

<!-- Override Modal (Admin only) -->
<?php if ($sess_role === 'admin'): ?>
<div class="modal-overlay" id="overrideModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-sliders" style="color:#f59e0b;"></i> Force-Approve Stuck Budget Request</h3>
      <button style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;" id="closeOverrideModal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:0.85rem;color:#64748b;margin:0 0 14px;">Requests older than 7 days without action will appear here.</p>
      <div id="stuckList"><p style="color:#94a3b8;text-align:center;">Loading...</p></div>
    </div>
    <div class="modal-footer">
      <button class="card-btn" style="background:#f1f5f9;color:#64748b;" id="cancelOverride">Close</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="../js/dashboard.js"></script>
<script>
// -- Alert -----------------------------------------------------
function showAlert(msg, type) {
  const el = document.getElementById('adminAlert');
  el.style.display = 'block';
  el.style.background = type === 'success' ? '#dcfce7' : '#fef2f2';
  el.style.color      = type === 'success' ? '#166534' : '#991b1b';
  el.style.border     = `1px solid ${type === 'success' ? '#bbf7d0' : '#fecaca'}`;
  el.textContent = msg;
  setTimeout(() => { el.style.display = 'none'; }, 5000);
}

// -- User search filter ----------------------------------------
function filterUsers() {
  const q = document.getElementById('adminSearch').value.toLowerCase().trim();
  document.querySelectorAll('.user-row').forEach(r => {
    r.style.display = (!q || r.textContent.toLowerCase().includes(q)) ? '' : 'none';
  });
}

// -- Update role (Admin only) ----------------------------------
<?php if ($sess_role === 'admin'): ?>
function updateRole(userId, newRole) {
  if (!confirm(`Update this user's role to "${newRole.replace(/_/g,' ')}"?`)) return;

  const fd = new FormData();
  fd.set('action', 'update_role');
  fd.set('user_id', userId);
  fd.set('new_role', newRole);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showAlert('Role updated successfully.', 'success');
        setTimeout(() => location.reload(), 1500);
      } else {
        showAlert(data.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}

// -- Override Modal ---------------------------------------------
const overrideModal = document.getElementById('overrideModal');
const roleMap = { admin:'System Admin',ssc:'Supreme Student Council (SSC)',club_adviser:'Club Adviser',ssc:'Supreme Student Council (SSC)',student:'Student' };

document.getElementById('openOverrideBtn')?.addEventListener('click', () => {
  overrideModal.classList.add('active');
  // Load stuck requests
  fetch('../shared/admin_actions.php?action=list_stuck')
    .then(r => r.json())
    .then(data => {
      const el = document.getElementById('stuckList');
      if (!data.success || !data.stuck.length) {
        el.innerHTML = '<p style="text-align:center;color:#94a3b8;">No stuck requests found.</p>'; return;
      }
      el.innerHTML = data.stuck.map(r => `
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f1f5f9;">
          <div>
            <strong style="font-size:0.88rem;">${r.title}</strong>
            <div style="font-size:0.75rem;color:#94a3b8;">${r.club_name} — ?${parseFloat(r.amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>
            <div style="font-size:0.75rem;color:#f59e0b;">Status: ${r.status}</div>
          </div>
          <button class="card-btn" style="background:#2563eb;color:#fff;white-space:nowrap;"
            onclick="forceApprove(${r.id})">
            <i class="fa-solid fa-bolt"></i> Force Approve
          </button>
        </div>`).join('');
    })
    .catch(() => { document.getElementById('stuckList').innerHTML = '<p style="color:#991b1b;">Failed to load.</p>'; });
});

document.getElementById('closeOverrideModal')?.addEventListener('click', () => overrideModal.classList.remove('active'));
document.getElementById('cancelOverride')?.addEventListener('click', () => overrideModal.classList.remove('active'));
overrideModal?.addEventListener('click', e => { if (e.target === overrideModal) overrideModal.classList.remove('active'); });

function forceApprove(id) {
  if (!confirm('Force-approve this budget request?')) return;
  const fd = new FormData();
  fd.set('action', 'override_budget');
  fd.set('budget_id', id);
  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showAlert('Budget request force-approved.', 'success');
        overrideModal.classList.remove('active');
      } else {
        showAlert(data.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}
<?php endif; ?>
</script>
<script src="../js/table-pagination.js"></script>
</body>
</html>
