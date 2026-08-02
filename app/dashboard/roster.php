<?php
// ============================================================
//  ROSTER.PHP  (dashboard/)
//  Co-Curricular System — Membership Roster Module (RBAC Enforced, Live DB)
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php');
    exit;
}

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$user_id      = (int)$_SESSION['user_id'];

// ── Fetch data based on role ──────────────────────────────────

// My memberships (Student)
$my_memberships = [];
if ($sess_role === 'student') {
    $stmt = $conn->prepare(
        "SELECT cm.id, cm.role AS member_role, cm.status, cm.joined_at,
                c.name AS club_name, c.code AS club_code, c.category
         FROM club_memberships cm
         JOIN clubs c ON c.id = cm.club_id
         WHERE cm.user_id = ?
         ORDER BY cm.joined_at DESC"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $my_memberships = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Pending applicants (Adviser, OSA, Admin)
$pending_applicants = [];
if (in_array($sess_role, ['club_adviser', 'osa_director', 'admin'])) {
    $club_filter = '';
    $bind_params = [];
    $bind_types  = '';

    if ($sess_role === 'club_adviser') {
        $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
        $cm->bind_param('i', $user_id);
        $cm->execute();
        $cm->bind_result($my_club_id);
        $cm->fetch();
        $cm->close();
        if (!empty($my_club_id)) {
            $club_filter  = 'AND cm.club_id = ?';
            $bind_params[] = (int)$my_club_id;
            $bind_types   .= 'i';
        }
    }

    $sql = "SELECT cm.id, cm.club_id, cm.user_id, cm.joined_at,
                   c.name AS club_name, c.code AS club_code,
                   u.first_name, u.last_name, u.email
            FROM club_memberships cm
            JOIN clubs c ON c.id = cm.club_id
            JOIN users u ON u.id = cm.user_id
            WHERE cm.status = 'Pending' $club_filter
            ORDER BY cm.joined_at ASC";
    $stmt = $conn->prepare($sql);
    if ($bind_params) $stmt->bind_param($bind_types, ...$bind_params);
    $stmt->execute();
    $pending_applicants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Active members (Adviser, OSA, Admin)
$active_members = [];
if (in_array($sess_role, ['club_adviser', 'osa_director', 'admin'])) {
    $club_filter = '';
    $bind_params = [];
    $bind_types  = '';

    if ($sess_role === 'club_adviser') {
        if (!empty($my_club_id)) {
            $club_filter  = 'AND cm.club_id = ?';
            $bind_params[] = (int)$my_club_id;
            $bind_types   .= 'i';
        }
    }

    $sql = "SELECT cm.id, cm.role AS member_role, cm.status, cm.joined_at,
                   c.name AS club_name, c.code AS club_code,
                   u.first_name, u.last_name, u.email
            FROM club_memberships cm
            JOIN clubs c ON c.id = cm.club_id
            JOIN users u ON u.id = cm.user_id
            WHERE cm.status = 'Active' $club_filter
            ORDER BY c.name, cm.joined_at DESC";
    $stmt = $conn->prepare($sql);
    if ($bind_params) $stmt->bind_param($bind_types, ...$bind_params);
    $stmt->execute();
    $active_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$pending_count = count($pending_applicants);
$active_count  = count($active_members);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Membership Roster – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'roster';
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
        <input type="text" id="rosterSearch" placeholder="Search roster..." oninput="filterRoster()"/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i class="fa-solid fa-qrcode"></i></button>
      <a href="../dashboard/account.php" class="avatar" id="avatarBtn" title="Account Settings">
        <?= $sess_initial ?>
      </a>
    </div>
  </div>

  <!-- Content -->
  <div class="content">

    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-users"></i>
        <?php if ($sess_role === 'student'): ?>
          My Club Memberships
        <?php else: ?>
          Membership Roster Management
        <?php endif; ?>
      </h2>
    </div>

    <div class="content-body">

      <!-- Stats Row -->
      <div class="info-row">
        <?php if ($sess_role === 'student'): ?>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-user-check"></i> My Memberships</div>
          <div class="card-amount"><?= count($my_memberships) ?></div>
          <div class="card-detail"><?= count(array_filter($my_memberships, fn($m) => $m['status'] === 'Active')) ?> Active Club(s)</div>
        </div>
        <?php endif; ?>

        <?php if (in_array($sess_role, ['club_adviser', 'osa_director', 'admin'])): ?>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-clock"></i> Pending Applications</div>
          <div class="card-amount"><?= $pending_count ?></div>
          <div class="card-detail">Awaiting Approval</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-users"></i> Active Members</div>
          <div class="card-amount"><?= $active_count ?></div>
          <div class="card-detail">Across All Clubs</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-file-export"></i> Export Roster</div>
          <div class="card-detail" style="margin-top:6px;">
            <button class="card-btn" id="exportCsvBtn">
              <i class="fa-solid fa-download"></i> Export CSV
            </button>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Alert box -->
      <div id="rosterAlert" style="display:none; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:0.85rem;"></div>

      <!-- My Club Memberships (Student) -->
      <?php if ($sess_role === 'student'): ?>
      <div class="table-card" id="memberships">
        <h3><i class="fa-solid fa-id-badge" style="color:#2563eb;"></i> My Club Memberships</h3>
        <?php if (empty($my_memberships)): ?>
          <p style="text-align:center; color:#94a3b8; padding:20px;">
            You have no club memberships yet.
            <a href="club_directory.php" style="color:#2563eb; font-weight:600;">Browse clubs to apply →</a>
          </p>
        <?php else: ?>
        <table class="data-table" id="myMembershipsTable">
          <thead>
            <tr>
              <th>Organization</th>
              <th>Club Code</th>
              <th>My Role</th>
              <th>Applied / Joined Date</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($my_memberships as $m): ?>
            <tr class="roster-row">
              <td><strong><?= htmlspecialchars($m['club_name']) ?></strong></td>
              <td><?= htmlspecialchars($m['club_code']) ?></td>
              <td><?= htmlspecialchars($m['member_role']) ?></td>
              <td><?= date('M d, Y', strtotime($m['joined_at'])) ?></td>
              <td>
                <?php
                  $statusClass = match($m['status']) {
                    'Active'   => 'badge-active',
                    'Pending'  => 'badge-warning',
                    'Rejected' => 'badge-inactive',
                    default    => 'badge-info'
                  };
                ?>
                <span class="<?= $statusClass ?>"><?= htmlspecialchars($m['status']) ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Pending Applicant Queue (Adviser, OSA, Admin) -->
      <?php if (in_array($sess_role, ['club_adviser', 'osa_director', 'admin'])): ?>
      <div class="table-card" id="applicant-queue">
        <h3><i class="fa-solid fa-user-plus" style="color:#f59e0b;"></i>
          Pending Membership Applications
          <?php if ($pending_count > 0): ?>
            <span style="background:#fef3c7; color:#d97706; font-size:0.75rem; padding:2px 8px; border-radius:10px; margin-left:8px;"><?= $pending_count ?> Pending</span>
          <?php endif; ?>
        </h3>
        <?php if (empty($pending_applicants)): ?>
          <p style="text-align:center; color:#94a3b8; padding:20px;">No pending applications at this time.</p>
        <?php else: ?>
        <table class="data-table" id="pendingTable">
          <thead>
            <tr>
              <th>Student Name</th>
              <th>Email</th>
              <th>Requested Club</th>
              <th>Application Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pending_applicants as $ap): ?>
            <tr class="roster-row" id="applicant-row-<?= $ap['id'] ?>">
              <td><strong><?= htmlspecialchars($ap['first_name'] . ' ' . $ap['last_name']) ?></strong></td>
              <td><?= htmlspecialchars($ap['email']) ?></td>
              <td><?= htmlspecialchars($ap['club_name']) ?> <span style="color:#94a3b8;">(<?= htmlspecialchars($ap['club_code']) ?>)</span></td>
              <td><?= date('M d, Y h:i A', strtotime($ap['joined_at'])) ?></td>
              <td style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                <button class="card-btn" style="background:#16a34a; color:#fff;"
                  onclick="handleApplication(<?= $ap['id'] ?>, 'approve')">
                  <i class="fa-solid fa-check"></i> Approve
                </button>
                <button class="card-btn btn-danger"
                  onclick="handleApplication(<?= $ap['id'] ?>, 'reject')">
                  <i class="fa-solid fa-times"></i> Reject
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <!-- Master Roster (active members) -->
      <div class="table-card" id="master-roster">
        <h3><i class="fa-solid fa-address-book" style="color:#2563eb;"></i> Active Organization Member Roster</h3>
        <?php if (empty($active_members)): ?>
          <p style="text-align:center; color:#94a3b8; padding:20px;">No active members found.</p>
        <?php else: ?>
        <table class="data-table" id="masterRosterTable">
          <thead>
            <tr>
              <th>Member Name</th>
              <th>Email</th>
              <th>Club</th>
              <th>Assigned Role</th>
              <th>Joined Date</th>
              <th>Status</th>
              <?php if (in_array($sess_role, ['club_adviser', 'admin'])): ?>
              <th>Action</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($active_members as $mem): ?>
            <tr class="roster-row" id="member-row-<?= $mem['id'] ?>">
              <td><strong><?= htmlspecialchars($mem['first_name'] . ' ' . $mem['last_name']) ?></strong></td>
              <td><?= htmlspecialchars($mem['email']) ?></td>
              <td><?= htmlspecialchars($mem['club_name']) ?></td>
              <td><?= htmlspecialchars($mem['member_role']) ?></td>
              <td><?= date('M d, Y', strtotime($mem['joined_at'])) ?></td>
              <td><span class="badge-active">Active</span></td>
              <?php if (in_array($sess_role, ['club_adviser', 'admin'])): ?>
              <td>
                <button class="card-btn btn-danger" onclick="removeMember(<?= $mem['id'] ?>)">
                  <i class="fa-solid fa-user-minus"></i> Remove
                </button>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div><!-- end content-body -->
  </div><!-- end content -->

  <div class="footer">Co-Curricular Management System &copy; 2026</div>
</div><!-- end main -->

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
// ── Live AJAX actions ─────────────────────────────────────────
function showAlert(msg, type) {
  const el = document.getElementById('rosterAlert');
  el.style.display = 'block';
  el.style.background = type === 'success' ? '#dcfce7' : '#fef2f2';
  el.style.color      = type === 'success' ? '#166534' : '#991b1b';
  el.style.border     = `1px solid ${type === 'success' ? '#bbf7d0' : '#fecaca'}`;
  el.textContent = msg;
  setTimeout(() => { el.style.display = 'none'; }, 4000);
}

function handleApplication(id, action) {
  const fd = new FormData();
  fd.set('action', action);
  fd.set('id', id);
  fetch('../shared/roster_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showAlert(data.message, 'success');
        const row = document.getElementById('applicant-row-' + id);
        if (row) row.style.opacity = '0.4';
        setTimeout(() => { row?.remove(); location.reload(); }, 1000);
      } else {
        showAlert(data.message, 'error');
      }
    })
    .catch(() => showAlert('Network error. Please try again.', 'error'));
}

function removeMember(id) {
  if (!confirm('Are you sure you want to remove this member from the club?')) return;
  const fd = new FormData();
  fd.set('action', 'remove');
  fd.set('id', id);
  fetch('../shared/roster_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showAlert('Member removed.', 'success');
        const row = document.getElementById('member-row-' + id);
        if (row) row.remove();
      } else {
        showAlert(data.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}

// ── Search Filter ────────────────────────────────────────────
function filterRoster() {
  const q = document.getElementById('rosterSearch').value.toLowerCase().trim();
  document.querySelectorAll('.roster-row').forEach(row => {
    row.style.display = (!q || row.textContent.toLowerCase().includes(q)) ? '' : 'none';
  });
}

// ── Export CSV ───────────────────────────────────────────────
document.getElementById('exportCsvBtn')?.addEventListener('click', function() {
  fetch('../shared/roster_actions.php?action=export_csv')
    .then(r => r.json())
    .then(data => {
      if (!data.success) { showAlert(data.message, 'error'); return; }
      const rows = data.csv_data;
      if (!rows.length) { showAlert('No active members to export.', 'error'); return; }
      const headers = ['First Name','Last Name','Email','Club Name','Club Code','Role','Joined At'];
      const csv = [headers.join(','), ...rows.map(r =>
        [r.first_name, r.last_name, r.email, r.club_name, r.code, r.member_role, r.joined_at]
          .map(v => `"${(v||'').replace(/"/g,'""')}"`)
          .join(',')
      )].join('\n');
      const blob = new Blob([csv], { type: 'text/csv' });
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a'); a.href = url;
      a.download = 'BCP_Club_Roster_' + new Date().toISOString().slice(0,10) + '.csv';
      a.click(); URL.revokeObjectURL(url);
      showAlert('Roster exported successfully!', 'success');
    })
    .catch(() => showAlert('Export failed.', 'error'));
});
</script>
</body>
</html>
