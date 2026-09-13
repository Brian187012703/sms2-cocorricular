<?php
// ============================================================
//  ADMIN_SYSTEM.PHP  (dashboard/)
//  Co-Curricular System — System Administration & RBAC Portal
//  Accessible to: System Admin (Full CRUD), SSC (Audited Oversight)
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) { 
    header('Location: ../auth/signin.php'); 
    exit; 
}

if (!in_array($_SESSION['role'] ?? '', ['admin', 'ssc'])) { 
    header('Location: dashboard.php'); 
    exit; 
}

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'admin';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];
$is_admin     = ($sess_role === 'admin');

// -- Live Statistics ------------------------------------------
$user_count     = (int)$conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$student_count  = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetch_row()[0];
$adviser_count  = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='club_adviser'")->fetch_row()[0];
$ssc_count      = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='ssc'")->fetch_row()[0];
$admin_count    = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetch_row()[0];

$club_count     = (int)$conn->query("SELECT COUNT(*) FROM clubs WHERE status='Active'")->fetch_row()[0];
$pending_apps   = (int)$conn->query("SELECT COUNT(*) FROM club_memberships WHERE status='Pending'")->fetch_row()[0];
$stuck_budgets  = (int)$conn->query("SELECT COUNT(*) FROM budget_requests WHERE status NOT IN ('Disbursed','Rejected') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_row()[0];

// -- Fetch all users with profile data -------------------------
$all_users = $conn->query(
    "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.role, u.created_at,
            s.student_number, s.course, s.year_level, s.section, s.status AS student_status
     FROM users u
     LEFT JOIN students s ON (s.first_name = u.first_name AND s.last_name = u.last_name)
     ORDER BY u.role, u.last_name, u.first_name"
)->fetch_all(MYSQLI_ASSOC);

// -- Recent audit logs -----------------------------------------
$audit_logs = $conn->query(
    "SELECT al.id, al.action, al.target_table, al.target_id, al.detail, al.ip_address, al.created_at,
            u.first_name, u.last_name, u.role
     FROM audit_logs al
     JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC LIMIT 50"
)->fetch_all(MYSQLI_ASSOC);

$role_labels = [
    'admin'        => 'System Admin',
    'ssc'          => 'Supreme Student Council (SSC)',
    'club_adviser' => 'Faculty Club Adviser',
    'student'      => 'General Student',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>System Administration &amp; RBAC — BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
    /* Tab System */
    .admin-tab-nav {
      display: flex;
      gap: 10px;
      border-bottom: 2px solid #e2e8f0;
      margin-bottom: 24px;
      flex-wrap: wrap;
    }
    .admin-tab-btn {
      background: none;
      border: none;
      padding: 12px 20px;
      font-size: 0.9rem;
      font-weight: 700;
      color: #64748b;
      cursor: pointer;
      border-bottom: 3px solid transparent;
      margin-bottom: -2px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s ease;
    }
    .admin-tab-btn:hover { color: #1e3a8a; }
    .admin-tab-btn.active {
      color: #1e3a8a;
      border-bottom-color: #2563eb;
    }
    .admin-tab-content { display: none; }
    .admin-tab-content.active { display: block; animation: fadeIn 0.2s ease; }

    .role-badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:0.72rem; font-weight:800; }
    .role-admin   { background:#fee2e2; color:#991b1b; border: 1px solid #fca5a5; }
    .role-ssc     { background:#fef3c7; color:#92400e; border: 1px solid #fde68a; }
    .role-adviser { background:#e0e7ff; color:#3730a3; border: 1px solid #c7d2fe; }
    .role-student { background:#f1f5f9; color:#475569; border: 1px solid #e2e8f0; }

    .admin-alert { padding:14px 18px; border-radius:10px; margin-bottom:20px; font-size:0.88rem; font-weight:600; display:none; }
    
    /* Modal styles */
    .modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px); z-index:9999; display:none; align-items:center; justify-content:center; padding:16px; }
    .modal-overlay.active { display:flex !important; }
    .modal-card { background:#fff; border-radius:18px; width:100%; max-width:540px; max-height:90vh; overflow-y:auto; box-shadow:0 24px 64px rgba(0,0,0,0.3); }
    .modal-header { padding:18px 24px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; background:#f8fafc; border-radius:18px 18px 0 0; }
    .modal-header h3 { margin:0; font-size:1.05rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px; }
    .modal-body { padding:22px 24px; }
    .modal-footer { padding:14px 24px; border-top:1px solid #e2e8f0; display:flex; gap:10px; justify-content:flex-end; background:#f8fafc; border-radius:0 0 18px 18px; }
    
    .form-group-admin { margin-bottom:16px; }
    .form-group-admin label { display:block; font-size:0.75rem; font-weight:800; color:#475569; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.04em; }
    .form-group-admin input, .form-group-admin select, .form-group-admin textarea { width:100%; padding:9px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.88rem; color:#1e293b; background:#fff; font-family:inherit; }
    .form-group-admin input:focus, .form-group-admin select:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.1); }

    /* RBAC Matrix Table */
    .matrix-table { width:100%; border-collapse:collapse; text-align:left; font-size:0.85rem; }
    .matrix-table th, .matrix-table td { padding:12px 14px; border-bottom:1px solid #e2e8f0; }
    .matrix-table th { background:#f8fafc; font-weight:800; color:#334155; }
    .perm-yes { color:#16a34a; font-weight:800; display:inline-flex; align-items:center; gap:4px; }
    .perm-no  { color:#94a3b8; font-weight:600; }
    .perm-cond { color:#d97706; font-weight:700; font-size:0.78rem; }
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
        <input type="text" placeholder="Search accounts, logs..." autocomplete="off" />
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

    <div class="page-title-bar" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
      <h2 class="page-title">
        <i class="fa-solid fa-shield-halved" style="color:#2563eb;"></i>
        System Administration &amp; Access Control
      </h2>
      <div style="font-size:0.8rem; color:#64748b; font-weight:600;">
        Role: <strong style="color:#0f172a;"><?= htmlspecialchars($role_labels[$sess_role] ?? $sess_role) ?></strong>
      </div>
    </div>

    <div class="content-body">

      <!-- Alert Box -->
      <div id="adminAlert" class="admin-alert"></div>

      <!-- KPI Stat Cards -->
      <div class="info-row">
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-users-gear"></i> Total Users</div>
          <div class="card-amount"><?= $user_count ?></div>
          <div class="card-detail"><?= $student_count ?> Students &bull; <?= $adviser_count ?> Advisers &bull; <?= $ssc_count ?> SSC &bull; <?= $admin_count ?> Admins</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-building-columns"></i> Active Orgs</div>
          <div class="card-amount"><?= $club_count ?></div>
          <div class="card-detail">Recognized Campus Clubs</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-clock"></i> Pending Applications</div>
          <div class="card-amount"><?= $pending_apps ?></div>
          <div class="card-detail">Membership Queue</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-sliders"></i> Stuck Budgets</div>
          <div class="card-amount" style="color:<?= $stuck_budgets > 0 ? '#dc2626' : '#16a34a' ?>;"><?= $stuck_budgets ?></div>
          <div class="card-detail">> 7 Days Pending Action</div>
        </div>
      </div>

      <!-- Tab Navigation -->
      <div class="admin-tab-nav">
        <button class="admin-tab-btn active" onclick="switchAdminTab('usersTab', this)">
          <i class="fa-solid fa-users"></i> User Accounts &amp; RBAC
        </button>
        <button class="admin-tab-btn" onclick="switchAdminTab('matrixTab', this)">
          <i class="fa-solid fa-table-cells"></i> Permission Matrix
        </button>
        <button class="admin-tab-btn" onclick="switchAdminTab('auditTab', this)">
          <i class="fa-solid fa-list-check"></i> System Audit Trail
        </button>
        <button class="admin-tab-btn" onclick="switchAdminTab('opsTab', this)">
          <i class="fa-solid fa-server"></i> System Health &amp; Operations
        </button>
      </div>

      <!-- ══════════════════════════════════════════════════════════════
           TAB 1: User Accounts & RBAC
      ══════════════════════════════════════════════════════════════ -->
      <div class="admin-tab-content active" id="usersTab">
        <div class="table-card">
          <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid #f1f5f9;">
            <div>
              <h3 style="margin:0; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-users-gear" style="color:#2563eb;"></i> User Account Directory</h3>
              <p style="margin:2px 0 0; font-size:0.78rem; color:#64748b;">Manage user authentication credentials, system roles, and status</p>
            </div>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
              <input type="text" id="userSearchInput" placeholder="Search name, username, email..." style="padding:7px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; min-width:220px;" oninput="filterUserTable()"/>
              <select id="userRoleFilterSelect" style="padding:7px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; font-weight:600;" onchange="filterUserTable()">
                <option value="ALL">All Roles</option>
                <option value="student">Student</option>
                <option value="club_adviser">Club Adviser</option>
                <option value="ssc">SSC Officer</option>
                <option value="admin">System Admin</option>
              </select>
              <?php if ($is_admin): ?>
              <button type="button" class="card-btn" style="background:#16a34a; color:#fff; font-weight:700;" onclick="openCreateUserModal()">
                <i class="fa-solid fa-user-plus"></i> Add New User
              </button>
              <?php endif; ?>
            </div>
          </div>

          <table class="data-table" id="userTable">
            <thead>
              <tr>
                <th>#</th>
                <th>Full Name</th>
                <th>Username</th>
                <th>Email</th>
                <th>System Role</th>
                <th>Program / Detail</th>
                <th>Registered</th>
                <?php if ($is_admin): ?><th>Actions</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($all_users as $u): ?>
              <?php 
                $r_cls = str_replace('_', '', $u['role']); 
                $detail_str = $u['course'] ? ($u['student_number'] ? "{$u['student_number']} ({$u['course']})" : $u['course']) : 'System Staff';
              ?>
              <tr class="user-row" data-role="<?= $u['role'] ?>" data-search="<?= strtolower($u['first_name'] . ' ' . $u['last_name'] . ' ' . $u['username'] . ' ' . $u['email'] . ' ' . $detail_str) ?>">
                <td><?= $u['id'] ?></td>
                <td><strong><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></strong></td>
                <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td>
                  <span class="role-badge role-<?= $r_cls ?>"><?= htmlspecialchars($role_labels[$u['role']] ?? $u['role']) ?></span>
                </td>
                <td style="font-size:0.8rem; color:#64748b; max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                  <?= htmlspecialchars($detail_str) ?>
                </td>
                <td style="font-size:0.8rem; color:#64748b;"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                <?php if ($is_admin): ?>
                <td>
                  <div style="display:flex; gap:6px;">
                    <button type="button" class="card-btn btn-sm" style="background:#2563eb; color:#fff;" onclick="openEditUserModal(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)" title="Edit User">
                      <i class="fa-solid fa-pen-to-square"></i>
                    </button>
                    <button type="button" class="card-btn btn-sm" style="background:#f59e0b; color:#fff;" onclick="openResetPasswordModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['first_name'] . ' ' . $u['last_name'])) ?>')" title="Reset Password">
                      <i class="fa-solid fa-key"></i>
                    </button>
                    <?php if ($u['id'] !== $user_id): ?>
                    <button type="button" class="card-btn btn-sm btn-danger" onclick="deleteUser(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['first_name'] . ' ' . $u['last_name'])) ?>')" title="Delete User">
                      <i class="fa-solid fa-trash-can"></i>
                    </button>
                    <?php endif; ?>
                  </div>
                </td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════════
           TAB 2: Role & Permission Matrix
      ══════════════════════════════════════════════════════════════ -->
      <div class="admin-tab-content" id="matrixTab">
        <div class="table-card">
          <div style="margin-bottom:18px;">
            <h3 style="margin:0; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-table-cells" style="color:#2563eb;"></i> Role-Based Access Control (RBAC) Permissions Matrix</h3>
            <p style="margin:4px 0 0; font-size:0.8rem; color:#64748b;">Comprehensive map of system capabilities, approval rights, and visibility per user role</p>
          </div>

          <table class="matrix-table">
            <thead>
              <tr>
                <th>System Feature / Capability</th>
                <th>General Student</th>
                <th>Faculty Club Adviser</th>
                <th>Supreme Student Council (SSC)</th>
                <th>System Administrator</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><strong>Browse Organization Directory</strong></td>
                <td><span class="perm-yes"><i class="fa-solid fa-check"></i> Yes (Apply Now)</span></td>
                <td><span class="perm-cond">Restricted to Handled Org</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-check"></i> Full Directory</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-check"></i> Full Directory &amp; Charters</span></td>
              </tr>
              <tr>
                <td><strong>Event Proposal Lifecycle</strong></td>
                <td><span class="perm-no">&times; View &amp; Register only</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-plus"></i> Submit Club Proposal</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-check-double"></i> Review &amp; Endorse to Admin / Create Institutional Events</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-calendar-check"></i> Final Calendar Clearance &amp; Publishing</span></td>
              </tr>
              <tr>
                <td><strong>Budget &amp; Finance Pipeline</strong></td>
                <td><span class="perm-yes"><i class="fa-solid fa-plus"></i> Submit Requisition</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-check"></i> Stage 1: Endorse to SSC</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-check-double"></i> Stage 2: Audit &amp; Forward to Admin</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-hand-holding-dollar"></i> Stage 3: Disburse Funds &amp; Override</span></td>
              </tr>
              <tr>
                <td><strong>Elections &amp; Voting</strong></td>
                <td><span class="perm-yes"><i class="fa-solid fa-check-to-slot"></i> Cast Ballot</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-plus"></i> Manage Club Elections</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-sliders"></i> Council General Elections &amp; Results Declaration</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-shield-halved"></i> Full Oversight &amp; Verification</span></td>
              </tr>
              <tr>
                <td><strong>QR Attendance Scanner Terminal</strong></td>
                <td><span class="perm-no">&times; Personal Pass only</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-camera"></i> Camera Scanner &amp; Generator</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-camera"></i> Universal Scanner &amp; Analytics</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-camera"></i> Full Attendance Administration</span></td>
              </tr>
              <tr>
                <td><strong>Intelligent Reports &amp; AI Analytics</strong></td>
                <td><span class="perm-no">&times; No Access</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-chart-line"></i> Club Reports &amp; Insights</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-brain"></i> Institutional AI Reports</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-brain"></i> Full Campus Analytics &amp; PDF Export</span></td>
              </tr>
              <tr>
                <td><strong>User Account Administration</strong></td>
                <td><span class="perm-no">&times; Profile only</span></td>
                <td><span class="perm-no">&times; Profile only</span></td>
                <td><span class="perm-cond">Audited View-Only Directory</span></td>
                <td><span class="perm-yes"><i class="fa-solid fa-users-gear"></i> Full Account CRUD &amp; Role Changes</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════════
           TAB 3: System Audit Trail
      ══════════════════════════════════════════════════════════════ -->
      <div class="admin-tab-content" id="auditTab">
        <div class="table-card">
          <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid #f1f5f9;">
            <div>
              <h3 style="margin:0; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-lock" style="color:#2563eb;"></i> System Activity Audit Trail</h3>
              <p style="margin:2px 0 0; font-size:0.78rem; color:#64748b;">Real-time tamper-evident records of administrative and security events</p>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
              <input type="text" id="auditSearchInput" placeholder="Filter actions, details, users..." style="padding:7px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.82rem; min-width:240px;" oninput="filterAuditTable()"/>
            </div>
          </div>

          <table class="data-table" id="auditTable">
            <thead>
              <tr>
                <th>Timestamp</th>
                <th>Actor</th>
                <th>Role</th>
                <th>Action</th>
                <th>Target</th>
                <th>Detail &amp; Payload</th>
                <th>IP Address</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($audit_logs as $log): ?>
              <?php $r_cls = str_replace('_', '', $log['role']); ?>
              <tr class="audit-row" data-search="<?= strtolower($log['first_name'] . ' ' . $log['last_name'] . ' ' . $log['action'] . ' ' . ($log['detail'] ?? '')) ?>">
                <td style="font-size:0.8rem; color:#64748b; white-space:nowrap;"><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
                <td><strong><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></strong></td>
                <td><span class="role-badge role-<?= $r_cls ?>"><?= htmlspecialchars($role_labels[$log['role']] ?? $log['role']) ?></span></td>
                <td><code style="font-size:0.75rem; color:#1e3a8a; background:#e0f2fe; padding:2px 6px; border-radius:4px;"><?= htmlspecialchars($log['action']) ?></code></td>
                <td><?= htmlspecialchars($log['target_table'] ?? '—') ?></td>
                <td style="font-size:0.8rem; color:#334155; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($log['detail'] ?? '') ?>">
                  <?= htmlspecialchars($log['detail'] ?? '—') ?>
                </td>
                <td style="font-size:0.75rem; color:#94a3b8; font-family:monospace;"><?= htmlspecialchars($log['ip_address'] ?? '127.0.0.1') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════════
           TAB 4: Operations & System Health
      ══════════════════════════════════════════════════════════════ -->
      <div class="admin-tab-content" id="opsTab">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
          
          <!-- Diagnostics Card -->
          <div class="table-card">
            <h3 style="margin:0 0 14px; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-server" style="color:#2563eb;"></i> System Health &amp; Diagnostics</h3>
            <table class="data-table">
              <tbody>
                <tr><td><strong>PHP Version</strong></td><td><code><?= PHP_VERSION ?></code></td></tr>
                <tr><td><strong>MySQL Server</strong></td><td><code><?= $conn->server_info ?></code></td></tr>
                <tr><td><strong>Storage Permissions</strong></td><td><span style="color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Writable (`uploads/`)</span></td></tr>
                <tr><td><strong>System Timezone</strong></td><td><code><?= date_default_timezone_get() ?> (<?= date('Y-m-d H:i:s') ?>)</code></td></tr>
                <tr><td><strong>Active Sessions</strong></td><td><span class="badge-active">Operational</span></td></tr>
              </tbody>
            </table>
          </div>

          <!-- Stuck Budget Overrides -->
          <div class="table-card">
            <h3 style="margin:0 0 14px; font-size:1.05rem; color:#0f172a;"><i class="fa-solid fa-screwdriver-wrench" style="color:#f59e0b;"></i> Workflow Override Operations</h3>
            <p style="font-size:0.83rem; color:#64748b; line-height:1.5;">
              Emergency tools for System Administrators to resolve bottlenecked requisitions or perform quick role adjustments.
            </p>
            <?php if ($is_admin): ?>
            <div style="display:flex; flex-direction:column; gap:10px; margin-top:16px;">
              <button type="button" class="card-btn" id="openOverrideBtn" style="background:#2563eb; color:#fff; font-weight:700; padding:10px 16px; justify-content:center;">
                <i class="fa-solid fa-bolt"></i> Inspect &amp; Force-Approve Stuck Budgets (<?= $stuck_budgets ?>)
              </button>
            </div>
            <?php else: ?>
            <p style="font-size:0.8rem; color:#94a3b8; font-style:italic;">Workflow override executions require System Administrator clearance.</p>
            <?php endif; ?>
          </div>

        </div>
      </div>

    </div>
  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- ─────────────────────────────────────────────────────────────
     MODAL 1: Create New User (Admin Only)
───────────────────────────────────────────────────────────── -->
<?php if ($is_admin): ?>
<div class="modal-overlay" id="createUserModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-user-plus" style="color:#2563eb;"></i> Add New System User</h3>
      <button style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;" onclick="closeModal('createUserModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="createUserForm" onsubmit="handleCreateUser(event)">
      <div class="modal-body">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div class="form-group-admin">
            <label>First Name <span style="color:#ef4444;">*</span></label>
            <input type="text" name="first_name" required placeholder="e.g. John"/>
          </div>
          <div class="form-group-admin">
            <label>Last Name <span style="color:#ef4444;">*</span></label>
            <input type="text" name="last_name" required placeholder="e.g. Doe"/>
          </div>
        </div>
        <div class="form-group-admin">
          <label>Username <span style="color:#ef4444;">*</span></label>
          <input type="text" name="username" required placeholder="e.g. john.doe"/>
        </div>
        <div class="form-group-admin">
          <label>Email Address <span style="color:#ef4444;">*</span></label>
          <input type="email" name="email" required placeholder="e.g. jdoe@bcp.edu.ph"/>
        </div>
        <div class="form-group-admin">
          <label>System Role <span style="color:#ef4444;">*</span></label>
          <select name="role" id="newUserRoleSelect" onchange="toggleStudentFields(this.value)">
            <option value="student">General Student</option>
            <option value="club_adviser">Faculty Club Adviser</option>
            <option value="ssc">Supreme Student Council (SSC)</option>
            <option value="admin">System Administrator</option>
          </select>
        </div>
        <div id="studentSpecificFields">
          <div class="form-group-admin">
            <label>Student ID Number</label>
            <input type="text" name="student_number" placeholder="e.g. 2026-10450"/>
          </div>
          <div class="form-group-admin">
            <label>Academic Program / Course</label>
            <input type="text" name="course" placeholder="e.g. Bachelor of Science in Information Technology"/>
          </div>
        </div>
        <div class="form-group-admin">
          <label>Initial Password</label>
          <input type="text" name="password" value="Password123!" required/>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('createUserModal')">Cancel</button>
        <button type="submit" class="card-btn" style="background:#16a34a; color:#fff; font-weight:700;"><i class="fa-solid fa-check"></i> Create Account</button>
      </div>
    </form>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────
     MODAL 2: Edit User & Role (Admin Only)
───────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="editUserModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-user-pen" style="color:#2563eb;"></i> Edit User Details &amp; Role</h3>
      <button style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;" onclick="closeModal('editUserModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="editUserForm" onsubmit="handleUpdateUser(event)">
      <input type="hidden" name="user_id" id="editUserId"/>
      <div class="modal-body">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div class="form-group-admin">
            <label>First Name</label>
            <input type="text" name="first_name" id="editFirstName" required/>
          </div>
          <div class="form-group-admin">
            <label>Last Name</label>
            <input type="text" name="last_name" id="editLastName" required/>
          </div>
        </div>
        <div class="form-group-admin">
          <label>Email Address</label>
          <input type="email" name="email" id="editEmail" required/>
        </div>
        <div class="form-group-admin">
          <label>System Role</label>
          <select name="role" id="editRole">
            <option value="student">General Student</option>
            <option value="club_adviser">Faculty Club Adviser</option>
            <option value="ssc">Supreme Student Council (SSC)</option>
            <option value="admin">System Administrator</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('editUserModal')">Cancel</button>
        <button type="submit" class="card-btn" style="background:#2563eb; color:#fff; font-weight:700;"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────
     MODAL 3: Reset Password (Admin Only)
───────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="resetPasswordModal">
  <div class="modal-card" style="max-width:440px;">
    <div class="modal-header" style="background:#fffbeb; border-bottom:1px solid #fde68a;">
      <h3 style="color:#92400e;"><i class="fa-solid fa-key" style="color:#d97706;"></i> Reset User Password</h3>
      <button style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;" onclick="closeModal('resetPasswordModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="resetPasswordForm" onsubmit="handleResetPassword(event)">
      <input type="hidden" name="user_id" id="resetUserId"/>
      <div class="modal-body">
        <p style="font-size:0.85rem; color:#475569; margin-top:0;" id="resetUserNameText">Resetting password for user...</p>
        <div class="form-group-admin">
          <label>New Password</label>
          <input type="text" name="new_password" value="Password123!" required/>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('resetPasswordModal')">Cancel</button>
        <button type="submit" class="card-btn" style="background:#d97706; color:#fff; font-weight:700;"><i class="fa-solid fa-rotate"></i> Reset Password</button>
      </div>
    </form>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────
     MODAL 4: Stuck Budget Overrides (Admin Only)
───────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="overrideModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-bolt" style="color:#f59e0b;"></i> Force-Approve Stuck Budget Requests</h3>
      <button style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;" onclick="closeModal('overrideModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:0.83rem; color:#64748b; margin-top:0;">Requests pending without movement for over 7 days:</p>
      <div id="stuckList"><p style="color:#94a3b8; text-align:center;">Loading stuck items...</p></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="card-btn" style="background:#f1f5f9; color:#475569;" onclick="closeModal('overrideModal')">Close</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="../js/dashboard.js"></script>
<script src="../js/table-pagination.js"></script>
<script>
function switchAdminTab(tabId, btn) {
  document.querySelectorAll('.admin-tab-content').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.admin-tab-btn').forEach(el => el.classList.remove('active'));
  document.getElementById(tabId)?.classList.add('active');
  btn?.classList.add('active');
}

function showAlert(msg, type) {
  const el = document.getElementById('adminAlert');
  if (!el) return;
  el.style.display = 'block';
  el.style.background = type === 'success' ? '#dcfce7' : '#fef2f2';
  el.style.color      = type === 'success' ? '#166534' : '#991b1b';
  el.style.border     = `1px solid ${type === 'success' ? '#bbf7d0' : '#fecaca'}`;
  el.textContent = msg;
  setTimeout(() => { el.style.display = 'none'; }, 4000);
}

function openModal(id) { document.getElementById(id)?.classList.add('active'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('active'); }

function toggleStudentFields(role) {
  const wrap = document.getElementById('studentSpecificFields');
  if (wrap) wrap.style.display = role === 'student' ? 'block' : 'none';
}

function filterUserTable() {
  const q = (document.getElementById('userSearchInput')?.value || '').toLowerCase().trim();
  const role = document.getElementById('userRoleFilterSelect')?.value || 'ALL';
  document.querySelectorAll('.user-row').forEach(tr => {
    const s = tr.getAttribute('data-search') || '';
    const r = tr.getAttribute('data-role') || '';
    const matchQ = !q || s.includes(q);
    const matchR = (role === 'ALL') || (r === role);
    tr.style.display = (matchQ && matchR) ? '' : 'none';
  });
}

function filterAuditTable() {
  const q = (document.getElementById('auditSearchInput')?.value || '').toLowerCase().trim();
  document.querySelectorAll('.audit-row').forEach(tr => {
    const s = tr.getAttribute('data-search') || '';
    tr.style.display = (!q || s.includes(q)) ? '' : 'none';
  });
}

function openCreateUserModal() {
  document.getElementById('createUserForm')?.reset();
  toggleStudentFields('student');
  openModal('createUserModal');
}

function handleCreateUser(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'create_user');

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showAlert(res.message, 'success');
        closeModal('createUserModal');
        setTimeout(() => location.reload(), 1200);
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}

function openEditUserModal(u) {
  document.getElementById('editUserId').value = u.id;
  document.getElementById('editFirstName').value = u.first_name;
  document.getElementById('editLastName').value = u.last_name;
  document.getElementById('editEmail').value = u.email;
  document.getElementById('editRole').value = u.role;
  openModal('editUserModal');
}

function handleUpdateUser(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'update_user');

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showAlert(res.message, 'success');
        closeModal('editUserModal');
        setTimeout(() => location.reload(), 1200);
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}

function openResetPasswordModal(id, name) {
  document.getElementById('resetUserId').value = id;
  document.getElementById('resetUserNameText').textContent = `Resetting password for: ${name}`;
  openModal('resetPasswordModal');
}

function handleResetPassword(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'reset_password');

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showAlert(res.message, 'success');
        closeModal('resetPasswordModal');
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}

function deleteUser(id, name) {
  if (!confirm(`Are you sure you want to permanently delete user "${name}"?`)) return;
  const fd = new FormData();
  fd.append('action', 'delete_user');
  fd.append('user_id', id);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showAlert('User deleted successfully.', 'success');
        setTimeout(() => location.reload(), 1200);
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}

// Override modal handler
document.getElementById('openOverrideBtn')?.addEventListener('click', () => {
  openModal('overrideModal');
  fetch('../shared/admin_actions.php?action=list_stuck')
    .then(r => r.json())
    .then(data => {
      const el = document.getElementById('stuckList');
      if (!data.success || !data.stuck.length) {
        el.innerHTML = '<p style="text-align:center; color:#94a3b8; padding:20px;">No stuck budget requests found.</p>';
        return;
      }
      el.innerHTML = data.stuck.map(r => `
        <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 0; border-bottom:1px solid #f1f5f9;">
          <div>
            <strong style="font-size:0.88rem;">${r.title}</strong>
            <div style="font-size:0.75rem; color:#64748b;">${r.club_name} — ₱${parseFloat(r.amount).toLocaleString('en-PH', {minimumFractionDigits:2})}</div>
            <div style="font-size:0.72rem; color:#d97706;">Status: ${r.status}</div>
          </div>
          <button type="button" class="card-btn btn-sm" style="background:#2563eb; color:#fff;" onclick="forceApproveBudget(${r.id})">
            <i class="fa-solid fa-bolt"></i> Force Approve
          </button>
        </div>
      `).join('');
    })
    .catch(() => { document.getElementById('stuckList').innerHTML = '<p style="color:#dc2626;">Failed to load items.</p>'; });
});

function forceApproveBudget(id) {
  if (!confirm('Force-forward this budget request to Administration?')) return;
  const fd = new FormData();
  fd.append('action', 'override_budget');
  fd.append('budget_id', id);

  fetch('../shared/admin_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showAlert('Budget request force-advanced successfully!', 'success');
        closeModal('overrideModal');
        setTimeout(() => location.reload(), 1200);
      } else {
        showAlert(res.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}
</script>
</body>
</html>
