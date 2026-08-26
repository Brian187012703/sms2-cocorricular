<?php
// ============================================================
//  ROSTER.PHP  (dashboard/)
//  Co-Curricular System � Membership Roster Module (RBAC Enforced, Live DB)
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) {
  header('Location: ../auth/signin.php');
  exit;
}

$sess_first = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last = htmlspecialchars($_SESSION['last_name'] ?? '');
$sess_role = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id = (int) $_SESSION['user_id'];

// -- Fetch data based on role ----------------------------------

// My memberships (Student)
$my_memberships = [];
if ($sess_role === 'student') {
    $stmt = $conn->prepare(
        "SELECT cm.id, cm.club_id, cm.role AS member_role, cm.status, cm.joined_at,
                c.name AS club_name, c.code AS club_code, c.category, c.description
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

// Fetch all active club members for organization roster view
$all_org_members = [];
$res_members = $conn->query(
    "SELECT cm.id, cm.club_id, cm.role AS member_role, cm.status, cm.joined_at,
            c.name AS club_name, c.code AS club_code,
            u.first_name, u.last_name, u.email,
            s.course, s.year_level
     FROM club_memberships cm
     JOIN clubs c ON c.id = cm.club_id
     JOIN users u ON u.id = cm.user_id
     LEFT JOIN students s ON (s.first_name = u.first_name AND s.last_name = u.last_name)
     WHERE cm.status = 'Active'
     ORDER BY c.name, cm.joined_at DESC"
);
if ($res_members) {
    $all_org_members = $res_members->fetch_all(MYSQLI_ASSOC);
}

// Fetch all active accredited clubs for organization choice selection
$all_clubs = $conn->query("SELECT id, name, code, category, description FROM clubs WHERE status = 'Active' ORDER BY category, name")->fetch_all(MYSQLI_ASSOC);

// Pending applicants (Adviser, SSC, Admin)
$pending_applicants = [];
if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
  $club_filter = '';
  $bind_params = [];
  $bind_types = '';

  if ($sess_role === 'club_adviser') {
    $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
    $cm->bind_param('i', $user_id);
    $cm->execute();
    $cm->bind_result($my_club_id);
    $cm->fetch();
    $cm->close();
    if (!empty($my_club_id)) {
      $club_filter = 'AND cm.club_id = ?';
      $bind_params[] = (int) $my_club_id;
      $bind_types .= 'i';
    }
  }

  $sql = "SELECT cm.id, cm.club_id, cm.user_id, cm.joined_at, cm.letter_intent, cm.letter_endorsement,
                 c.name AS club_name, c.code AS club_code,
                 COALESCE(NULLIF(ca.first_name,''), u.first_name) AS first_name,
                 COALESCE(NULLIF(ca.last_name,''), u.last_name) AS last_name,
                 COALESCE(NULLIF(ca.email,''), u.email) AS email,
                 COALESCE(NULLIF(ca.course,''), s.course, 'General Program') AS course,
                 COALESCE(NULLIF(ca.year_level,''), s.year_level, '1st Year') AS year_level,
                 COALESCE(NULLIF(ca.phone,''), s.phone) AS phone,
                 ca.student_id_no, ca.sex, ca.dob, ca.address, ca.motivation
          FROM club_memberships cm
          JOIN clubs c ON c.id = cm.club_id
          JOIN users u ON u.id = cm.user_id
          LEFT JOIN club_applications ca ON (ca.id = (SELECT MAX(id) FROM club_applications WHERE club_id = cm.club_id AND user_id = cm.user_id))
          LEFT JOIN students s ON (s.first_name = u.first_name AND s.last_name = u.last_name)
          WHERE cm.status = 'Pending' $club_filter
          ORDER BY cm.joined_at ASC";
  $stmt = $conn->prepare($sql);
  if ($bind_params)
    $stmt->bind_param($bind_types, ...$bind_params);
  $stmt->execute();
  $pending_applicants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

// Active members (Adviser, SSC, Admin)
$active_members = [];
if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])) {
  $club_filter = '';
  $bind_params = [];
  $bind_types = '';

  if ($sess_role === 'club_adviser') {
    if (!empty($my_club_id)) {
      $club_filter = 'AND cm.club_id = ?';
      $bind_params[] = (int) $my_club_id;
      $bind_types .= 'i';
    }
  }

  $sql = "SELECT cm.id, cm.role AS member_role, cm.status, cm.joined_at,
                   c.name AS club_name, c.code AS club_code,
                   u.first_name, u.last_name, u.email
            FROM club_memberships cm
            JOIN clubs c ON c.id = cm.club_id
            JOIN users u ON u.id = cm.user_id
            WHERE cm.status = 'Active'
              AND u.role NOT IN ('club_adviser', 'admin')
              AND LOWER(cm.role) != 'adviser'
              AND cm.role NOT LIKE '%adviser%'
              $club_filter
            ORDER BY u.first_name ASC, u.last_name ASC";
  $stmt = $conn->prepare($sql);
  if ($bind_params)
    $stmt->bind_param($bind_types, ...$bind_params);
  $stmt->execute();
  $active_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

$pending_count = count($pending_applicants);
$active_count = count($active_members);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Membership Roster � BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
</head>

<body>

  <?php
  $APP_ROOT = '../';
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
          <input type="text" placeholder="Search pages, events..." autocomplete="off" />
          <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i
            class="fa-solid fa-qrcode"></i></button>
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
          <i class="fa-solid fa-users"></i>
          <?php if ($sess_role === 'student'): ?>
            My Organization Memberships
          <?php else: ?>
            Membership Roster Management
          <?php endif; ?>
        </h2>
      </div>

      <div class="content-body">

        <!-- Stats Row (Adviser, SSC, Admin) -->
        <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
        <div class="info-row">
            <div class="info-card">
              <div class="card-label"><i class="fa-solid fa-clock"></i> Pending Applications</div>
              <div class="card-amount"><?= $pending_count ?></div>
              <div class="card-detail">Awaiting Approval</div>
            </div>
            <div class="info-card">
              <div class="card-label"><i class="fa-solid fa-users"></i> Active Members</div>
              <div class="card-amount"><?= $active_count ?></div>
              <div class="card-detail">Across All Organizations</div>
            </div>
            <div class="info-card" style="display:flex; flex-direction:column; justify-content:space-between;">
              <div class="card-label"><i class="fa-solid fa-file-export"></i> Export Roster</div>
              <div style="margin-top:10px;">
                <button class="card-btn" id="exportCsvBtn" style="width:100%; height:40px; justify-content:center; background:#2563eb; color:#fff; font-weight:700; border-radius:8px;">
                  <i class="fa-solid fa-download"></i> Export
                </button>
              </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Alert box -->
        <div id="rosterAlert"
          style="display:none; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:0.85rem;"></div>

        <!-- My Joined Organizations Cards & Roster (Student View) -->
        <?php if ($sess_role === 'student'): ?>
          <!-- 1. Joined Organizations Cards Grid (Shown First) -->
          <div class="table-card" id="joinedOrgsSection">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
              <div>
                <h3 style="margin:0; color:#1e293b;"><i class="fa-solid fa-sitemap" style="color:#2563eb;"></i> Joined Organizations</h3>
                <span style="font-size:0.8rem; color:#64748b;">Select an organization you joined to view its active member roster</span>
              </div>
            </div>

            <?php if (empty($my_memberships)): ?>
              <div style="text-align:center; padding:30px; background:#f8fafc; border-radius:10px; border:1px dashed #cbd5e1;">
                <i class="fa-solid fa-folder-open" style="font-size:2.5rem; color:#94a3b8; margin-bottom:10px;"></i>
                <p style="margin:0; font-weight:600; color:#475569;">You have not joined any campus organization yet.</p>
                <p style="margin:4px 0 0; font-size:0.82rem; color:#64748b;">Your registered organization memberships will appear here once approved by your adviser.</p>
              </div>
            <?php else: ?>
              <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:14px;" id="orgCardsGrid">
                <?php foreach ($my_memberships as $m): ?>
                  <?php 
                    $club_id   = (int)($m['club_id'] ?? 0);
                    $club_code = htmlspecialchars($m['club_code']);
                    $club_name = htmlspecialchars($m['club_name']);
                    $status    = $m['status'];
                    $role      = htmlspecialchars($m['member_role']);
                    $category  = htmlspecialchars($m['category'] ?? 'Academic');
                  ?>
                  <div class="org-joined-card" 
                       onclick="selectOrgRoster(<?= $club_id ?>, '<?= addslashes($club_name) ?>', '<?= addslashes($club_code) ?>')"
                       style="background:#fff; border:1.5px solid #cbd5e1; border-radius:12px; padding:16px; cursor:pointer; transition:all 0.2s; position:relative;"
                       onmouseover="this.style.borderColor='#2563eb'; this.style.boxShadow='0 4px 12px rgba(37,99,235,0.1)';"
                       onmouseout="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none';">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                      <span class="club-badge" style="font-size:0.82rem; padding:4px 10px;"><?= $club_code ?></span>
                      <span class="<?= $status === 'Active' ? 'badge-active' : ($status === 'Pending' ? 'badge-warning' : 'badge-inactive') ?>" style="font-size:0.75rem;">
                        <?= $status ?>
                      </span>
                    </div>
                    <h4 style="margin:4px 0; font-size:0.95rem; color:#1e293b; line-height:1.3;"><?= $club_name ?></h4>
                    <div style="font-size:0.78rem; color:#64748b; margin-bottom:12px;">
                      <i class="fa-solid fa-tag"></i> <?= $category ?> &bull; Role: <strong><?= $role ?></strong>
                    </div>
                    <div style="display:flex; align-items:center; justify-content:space-between; border-top:1px solid #f1f5f9; pt-2; margin-top:8px; padding-top:8px;">
                      <span style="font-size:0.75rem; color:#2563eb; font-weight:700;"><i class="fa-solid fa-eye"></i> Click to view roster</span>
                      <i class="fa-solid fa-chevron-right" style="color:#2563eb; font-size:0.8rem;"></i>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- 2. Selected Organization Roster Table (Hidden by default until clicked) -->
          <div class="table-card" id="selectedOrgRosterCard" style="display:none;">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:16px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
              <div>
                <h3 style="margin:0; color:#1e293b;" id="selectedRosterTitle">
                  <i class="fa-solid fa-users-rectangle" style="color:#2563eb;"></i> Organization Member Roster
                </h3>
                <span style="font-size:0.8rem; color:#64748b;" id="selectedRosterSub">Select an organization above to view its member roster</span>
              </div>
              <button type="button" class="card-btn" onclick="closeOrgRoster()" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:6px 14px; border-radius:8px; font-weight:600; font-size:0.82rem; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:all 0.2s;" onmouseover="this.style.background='#e2e8f0'; this.style.color='#1e293b';" onmouseout="this.style.background='#f1f5f9'; this.style.color='#475569';" title="Close member roster table">
                <i class="fa-solid fa-xmark" style="font-size:0.95rem;"></i> Close Roster
              </button>
            </div>

            <div id="rosterEmptyNotice" style="text-align:center; padding:30px; color:#64748b;">
              <i class="fa-solid fa-hand-pointer" style="font-size:2rem; color:#94a3b8; margin-bottom:8px; display:block;"></i>
              Click on an organization card above to view its member roster.
            </div>

            <div id="rosterTableWrap" style="display:none;">
              <table class="data-table" id="studentOrgRosterTable">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Member Name</th>
                    <th>Email</th>
                    <th>Course & Year</th>
                    <th>Assigned Role</th>
                    <th>Joined Date</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody id="studentOrgRosterBody">
                  <!-- Rendered dynamically by JS -->
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <!-- Pending Applicant Queue (Adviser, SSC, Admin) -->
        <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
          <div class="table-card" id="applicant-queue">
            <h3><i class="fa-solid fa-user-plus" style="color:#f59e0b;"></i>
              Pending Membership Applications
              <?php if ($pending_count > 0): ?>
                <span
                  style="background:#fef3c7; color:#d97706; font-size:0.75rem; padding:2px 8px; border-radius:10px; margin-left:8px;"><?= $pending_count ?>
                  Pending</span>
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
                    <th>Requested Organization</th>
                    <th>Application Date</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($pending_applicants as $ap): ?>
                    <tr class="roster-row" id="applicant-row-<?= $ap['id'] ?>">
                      <td><strong><?= htmlspecialchars($ap['first_name'] . ' ' . $ap['last_name']) ?></strong></td>
                      <td><?= htmlspecialchars($ap['email']) ?></td>
                      <td><?= htmlspecialchars($ap['club_name']) ?> <span
                          style="color:#94a3b8;">(<?= htmlspecialchars($ap['club_code']) ?>)</span></td>
                      <td><?= date('M d, Y h:i A', strtotime($ap['joined_at'])) ?></td>
                      <td style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                        <button class="card-btn" style="background:#2563eb; color:#fff;"
                          onclick="reviewApplicant(<?= htmlspecialchars(json_encode($ap)) ?>)">
                          <i class="fa-solid fa-file-lines"></i> Review & Letters
                        </button>
                        <button class="card-btn" style="background:#16a34a; color:#fff;"
                          onclick="handleApplication(<?= $ap['id'] ?>, 'approve')">
                          <i class="fa-solid fa-check"></i> Approve
                        </button>
                        <button class="card-btn btn-danger" onclick="handleApplication(<?= $ap['id'] ?>, 'reject')">
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
                    <th>Organization</th>
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
  <script src="../js/dashboard.js"></script>
  <script>
    const ALL_CLUBS = <?= json_encode($all_clubs, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const ALL_ORG_MEMBERS = <?= json_encode($all_org_members, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const MY_MEMBERSHIPS = <?= json_encode($my_memberships, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function openModal(id)  { const el = document.getElementById(id); if (el) { el.classList.add('active'); el.style.display = 'flex'; } }
    function closeModal(id) { const el = document.getElementById(id); if (el) { el.classList.remove('active', 'open'); el.style.display = 'none'; } }

    function selectOrgRoster(clubId, clubName, clubCode) {
      const rosterCard = document.getElementById('selectedOrgRosterCard');
      if (rosterCard) rosterCard.style.display = 'block';

      const titleEl = document.getElementById('selectedRosterTitle');
      const subEl = document.getElementById('selectedRosterSub');
      const emptyNotice = document.getElementById('rosterEmptyNotice');
      const tableWrap = document.getElementById('rosterTableWrap');
      const body = document.getElementById('studentOrgRosterBody');

      if (!clubId) {
        if (emptyNotice) emptyNotice.style.display = 'block';
        if (tableWrap) tableWrap.style.display = 'none';
        return;
      }

      if (titleEl) titleEl.innerHTML = `<i class="fa-solid fa-users-rectangle" style="color:#2563eb;"></i> Member Roster: <strong>${clubName}</strong> (${clubCode})`;
      if (subEl) subEl.textContent = `Official active roster of members for ${clubName}`;

      const members = ALL_ORG_MEMBERS.filter(m => parseInt(m.club_id) === parseInt(clubId));

      if (!members.length) {
        if (emptyNotice) {
          emptyNotice.innerHTML = `
            <i class="fa-solid fa-users-slash" style="font-size:2rem; color:#94a3b8; margin-bottom:8px; display:block;"></i>
            No active members currently listed for <strong>${clubName}</strong>.
          `;
          emptyNotice.style.display = 'block';
        }
        if (tableWrap) tableWrap.style.display = 'none';
      } else {
        if (emptyNotice) emptyNotice.style.display = 'none';
        if (tableWrap) tableWrap.style.display = 'block';

        let html = '';
        members.forEach((m, idx) => {
          const courseStr = (m.course || 'BSIT') + ' - ' + (m.year_level || '3rd Year');
          const dateStr = new Date(m.joined_at.replace(' ','T')).toLocaleDateString('en-PH', {month:'short', day:'numeric', year:'numeric'});
          html += `
            <tr class="roster-row">
              <td>${idx + 1}</td>
              <td><strong>${m.first_name} ${m.last_name}</strong></td>
              <td>${m.email}</td>
              <td style="font-size:0.82rem; color:#64748b;">${courseStr}</td>
              <td><span class="badge-info" style="font-size:0.75rem; font-weight:700;">${m.member_role}</span></td>
              <td style="font-size:0.82rem;">${dateStr}</td>
              <td><span class="badge-active">Active</span></td>
            </tr>
          `;
        });
        if (body) body.innerHTML = html;
        const sTbl = document.getElementById('studentOrgRosterTable');
        if (sTbl && window.initTablePagination) {
          if (sTbl._paginator) sTbl._paginator.refresh();
          else window.initTablePagination(sTbl, { pageSize: 5 });
        }
      }

      if (rosterCard) rosterCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function closeOrgRoster() {
      const rosterCard = document.getElementById('selectedOrgRosterCard');
      if (rosterCard) {
        rosterCard.style.display = 'none';
      }
    }

    // -- Live AJAX actions -----------------------------------------
    function showAlert(msg, type) {
      const el = document.getElementById('rosterAlert');
      if (!el) return;
      el.style.display = 'block';
      el.style.background = type === 'success' ? '#dcfce7' : '#fef2f2';
      el.style.color = type === 'success' ? '#166534' : '#991b1b';
      el.style.border = `1px solid ${type === 'success' ? '#bbf7d0' : '#fecaca'}`;
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
            const row = document.getElementById(`applicant-row-${id}`);
            if (row) {
              row.style.opacity = '0';
              setTimeout(() => {
                row.remove();
                document.querySelectorAll('#pendingTable, #masterRosterTable').forEach(t => t._paginator && t._paginator.refresh());
              }, 300);
            }
          } else {
            showAlert(data.message, 'error');
          }
        })
        .catch(() => showAlert('Network error. Please try again.', 'error'));
    }

    function removeMember(id) {
      if (!confirm('Are you sure you want to remove this member from the organization?')) return;
      const fd = new FormData();
      fd.set('action', 'remove_member');
      fd.set('id', id);
      fetch('../shared/roster_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showAlert(data.message, 'success');
            const row = document.getElementById(`member-row-${id}`);
            if (row) {
              row.style.opacity = '0';
              setTimeout(() => {
                row.remove();
                document.querySelectorAll('#pendingTable, #masterRosterTable').forEach(t => t._paginator && t._paginator.refresh());
              }, 300);
            }
          } else {
            showAlert(data.message, 'error');
          }
        })
        .catch(() => showAlert('Network error.', 'error'));
    }

    // -- Search Filter --------------------------------------------
    function filterRoster() {
      const q = document.getElementById('rosterSearch').value.toLowerCase().trim();
      document.querySelectorAll('.roster-row').forEach(row => {
        const match = (!q || row.textContent.toLowerCase().includes(q));
        row.setAttribute('data-search-hidden', match ? 'false' : 'true');
      });
      document.querySelectorAll('#pendingTable, #masterRosterTable, #studentOrgRosterTable').forEach(t => {
        if (t._paginator) {
          t._paginator.currentPage = 1;
          t._paginator.refresh();
        }
      });
    }

    // -- Export CSV -----------------------------------------------
    document.getElementById('exportCsvBtn')?.addEventListener('click', function () {
      fetch('../shared/roster_actions.php?action=export_csv')
        .then(r => r.json())
        .then(data => {
          if (!data.success) { showAlert(data.message, 'error'); return; }
          const rows = data.csv_data;
          if (!rows.length) { showAlert('No active members to export.', 'error'); return; }
          const headers = [
            'Student Number',
            'First Name',
            'Last Name',
            'Email',
            'Year',
            'Section',
            'Organization Name',
            'Organization Code',
            'Role',
            'Joined At'
          ];
          const csv = [headers.join(','), ...rows.map(r =>
            [
              r.student_number,
              r.first_name,
              r.last_name,
              r.email,
              r.year_level,
              r.section,
              r.club_name,
              r.code,
              r.member_role,
              r.joined_at
            ]
              .map(v => `"${(v || '').replace(/"/g, '""')}"`)
              .join(',')
          )].join('\n');
          const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a'); a.href = url;
          a.download = 'BCP_Organization_Roster_' + new Date().toISOString().slice(0, 10) + '.csv';
          a.click(); URL.revokeObjectURL(url);
          showAlert('Roster exported successfully!', 'success');
        });
    });

    function reviewApplicant(ap) {
      const intent = ap.letter_intent ? ap.letter_intent : 'I am passionate about contributing to this organization, developing my leadership skills, and participating in all co-curricular events.';
      const endorsement = ap.letter_endorsement ? ap.letter_endorsement : 'This student is in good academic standing, displays strong character, and is recommended for organization membership.';
      const courseInfo = (ap.course || 'BS Information Technology') + ' - ' + (ap.year_level || '3rd Year');

      const details = `
-------------------------------------------
APPLICANT GOVERNANCE PROFILE & LETTERS
-------------------------------------------
Applicant Name: ${ap.first_name} ${ap.last_name}
Email Address:  ${ap.email}
Program / Year: ${courseInfo}
Target Organization: ${ap.club_name} (${ap.club_code})
Application Date:   ${ap.joined_at}

-------------------------------------------
1. LETTER OF INTENT (Applicant Statement):
-------------------------------------------
"${intent}"

-------------------------------------------
2. LETTER OF ENDORSEMENT (Adviser/Faculty):
-------------------------------------------
"${endorsement}"
-------------------------------------------
  `;

      if (confirm(details + '\n\nDo you want to APPROVE this application?\nClick OK to Approve, or Cancel to close.')) {
        handleApplication(ap.id, 'approve');
      }
    }
  </script>
  <script src="../js/table-pagination.js"></script>
</body>

</html>
