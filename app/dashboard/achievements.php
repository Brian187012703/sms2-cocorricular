<?php
// ============================================================
//  ACHIEVEMENTS.PHP  (dashboard/)
//  Co-Curricular System — Achievement & Awards Ledger (Live DB + Real AJAX)
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php');
    exit;
}

// Module disabled system-wide
header('Location: dashboard.php');
exit;

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// -- Fetch verified achievements ------------------------------
$ach_where = '';
$ach_bind  = [];
$ach_types = '';

if ($sess_role === 'student') {
    // Students see only their own verified achievements
    $ach_where = 'WHERE a.submitted_by = ? AND a.status = "Verified"';
    $ach_bind  = [$user_id];
    $ach_types = 'i';
} elseif ($sess_role === 'club_adviser') {
    // Advisers see their club's verified achievements
    $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
    $cm->bind_param('i', $user_id);
    $cm->execute();
    $cm->bind_result($my_club_id);
    $cm->fetch();
    $cm->close();
    if (!empty($my_club_id)) {
        $ach_where = 'WHERE a.club_id = ? AND a.status = "Verified"';
        $ach_bind  = [(int)$my_club_id];
        $ach_types = 'i';
    } else {
        $ach_where = 'WHERE a.status = "Verified"';
    }
} else {
    // Adviser, SSC, Admin, Finance see all verified
    $ach_where = 'WHERE a.status = "Verified"';
}

$sql = "SELECT a.id, a.title, a.competition, a.award_date, a.status, a.notes, a.created_at,
               c.name AS club_name, c.code AS club_code,
               u.first_name, u.last_name
        FROM achievements a
        JOIN clubs c ON c.id = a.club_id
        JOIN users u ON u.id = a.submitted_by
        $ach_where
        ORDER BY a.award_date DESC";
$stmt = $conn->prepare($sql);
if ($ach_bind) $stmt->bind_param($ach_types, ...$ach_bind);
$stmt->execute();
$verified_achievements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Student pending count
$my_pending_count = 0;
if ($sess_role === 'student') {
    $stmt_p = $conn->prepare("SELECT COUNT(*) FROM achievements WHERE submitted_by = ? AND status = 'Pending'");
    $stmt_p->bind_param('i', $user_id);
    $stmt_p->execute();
    $stmt_p->bind_result($my_pending_count);
    $stmt_p->fetch();
    $stmt_p->close();
}

// -- Pending queue (SSC + Admin) ------------------------------
$pending_achievements = [];
if (in_array($sess_role, ['ssc', 'admin'])) {
    $pending_achievements = $conn->query(
        "SELECT a.id, a.title, a.competition, a.award_date, a.created_at,
                c.name AS club_name, u.first_name, u.last_name
         FROM achievements a
         JOIN clubs c ON c.id = a.club_id
         JOIN users u ON u.id = a.submitted_by
         WHERE a.status = 'Pending'
         ORDER BY a.created_at ASC"
    )->fetch_all(MYSQLI_ASSOC);
}

// -- Club list for student / adviser submit form (Joined Active Orgs Only) --
$clubs = [];
if (in_array($sess_role, ['student', 'club_adviser'])) {
    $stmt_c = $conn->prepare(
        "SELECT c.id, c.name, c.code
         FROM clubs c
         JOIN club_memberships cm ON cm.club_id = c.id
         WHERE cm.user_id = ? AND cm.status = 'Active' AND c.status = 'Active'
         ORDER BY c.name"
    );
    $stmt_c->bind_param('i', $user_id);
    $stmt_c->execute();
    $clubs = $stmt_c->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_c->close();
} else {
    $clubs = $conn->query("SELECT id, name, code FROM clubs WHERE status='Active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Awards & Achievements – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
    .modal-overlay { position:fixed;inset:0;background:rgba(10,12,30,0.65);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px; }
    .modal-overlay.active { display:flex;animation:fadeIn 0.2s ease; }
    @keyframes fadeIn { from{opacity:0}to{opacity:1} }
    .modal-card { background:#fff;border-radius:20px;width:100%;max-width:580px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,0.28);animation:slideUp 0.25s cubic-bezier(0.16,1,0.3,1); }
    @keyframes slideUp { from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1} }
    .modal-header { padding:18px 24px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border-radius:20px 20px 0 0; }
    .modal-header h3 { margin:0;font-size:1rem;color:#1a1a2e; }
    .modal-body { padding:20px 24px; }
    .modal-footer { padding:14px 24px;border-top:1px solid #e2e8f0;display:flex;gap:10px;justify-content:flex-end; }
    .form-group { margin-bottom:14px; }
    .form-group label { display:block;font-size:0.78rem;font-weight:700;color:#64748b;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.04em; }
    .form-group input, .form-group select, .form-group textarea { width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:0.88rem;color:#1a1a2e;transition:border-color 0.15s; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline:none;border-color:#2563eb; }
    .form-group textarea { min-height:80px;resize:vertical; }
    .ach-alert { padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem; display:none; }
    .form-row { display:grid;grid-template-columns:1fr 1fr;gap:12px; }
    @media(max-width:480px){.form-row{grid-template-columns:1fr;}}
  </style>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'achievements';
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
        <input type="text" id="achSearch" placeholder="Search achievements..." oninput="filterAch()"/>
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
        <i class="fa-solid fa-trophy"></i>
        Achievement & Awards Ledger
      </h2>
    </div>

    <div class="content-body">

      <!-- Alert -->
      <div id="achAlert" class="ach-alert"></div>

      <!-- Stats / Action Row -->
      <div class="info-row">
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-medal"></i> Verified Awards</div>
          <div class="card-amount"><?= count($verified_achievements) ?></div>
          <div class="card-detail">SSC-endorsed records</div>
        </div>

        <?php if ($sess_role === 'student'): ?>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-clock"></i> Pending Review</div>
          <div class="card-amount"><?= $my_pending_count ?></div>
          <div class="card-detail">Awaiting SSC sign-off</div>
        </div>
        <?php endif; ?>

        <?php if (in_array($sess_role, ['ssc', 'admin'])): ?>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-clock"></i> Pending Verification</div>
          <div class="card-amount"><?= count($pending_achievements) ?></div>
          <div class="card-detail">Awaiting SSC sign-off</div>
        </div>
        <?php endif; ?>

        <?php if (in_array($sess_role, ['student', 'club_adviser'])): ?>
        <div class="info-card" id="submit-card" style="display:flex; flex-direction:column; justify-content:space-between;">
          <div>
            <div class="card-label"><i class="fa-solid fa-upload"></i> Submit Achievement</div>
            <div class="card-detail" style="margin-top:4px;">
              Submit competition awards &amp; certificates for verification
            </div>
          </div>
          <div style="margin-top:12px;">
            <button class="card-btn" id="openSubmitBtn" style="width:100%; height:40px; justify-content:center; background:#2563eb; color:#fff; font-weight:700; border-radius:8px; cursor:pointer;">
              <i class="fa-solid fa-plus-circle"></i> Submit Competition Award
            </button>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Verified Achievements Table -->
      <div class="table-card" id="verify">
        <h3><i class="fa-solid fa-award" style="color:#2563eb;"></i>
          Verified Inter-School Awards & Competition Records
        </h3>
        <?php if (empty($verified_achievements)): ?>
          <p style="text-align:center;color:#94a3b8;padding:20px;">No verified achievements on record yet.</p>
        <?php else: ?>
        <table class="data-table" id="achTable">
          <thead>
            <tr>
              <th>Award Title</th>
              <th>Competition / Event</th>
              <th>Organization</th>
              <th>Award Date</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($verified_achievements as $a): ?>
            <tr class="ach-row">
              <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
              <td><?= htmlspecialchars($a['competition']) ?></td>
              <td><?= htmlspecialchars($a['club_name']) ?></td>
              <td><?= date('M d, Y', strtotime($a['award_date'])) ?></td>
              <td><span class="badge-active">Verified &amp; Endorsed</span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <!-- SSC Verification Queue (SSC/Admin Only) -->
      <?php if (in_array($sess_role, ['ssc', 'admin'])): ?>
      <div class="table-card" id="pending-queue">
        <h3>
          <i class="fa-solid fa-list-check" style="color:#f59e0b;"></i>
          Pending Achievement Verification Queue
          <?php if (count($pending_achievements) > 0): ?>
            <span style="background:#fef3c7;color:#d97706;font-size:0.75rem;padding:2px 8px;border-radius:10px;margin-left:8px;"><?= count($pending_achievements) ?> Pending</span>
          <?php endif; ?>
        </h3>
        <?php if (empty($pending_achievements)): ?>
          <p style="text-align:center;color:#94a3b8;padding:20px;">No achievements pending verification.</p>
        <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Submitted Award</th>
              <th>Student / Organization</th>
              <th>Submission Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pending_achievements as $p): ?>
            <tr id="ach-row-<?= $p['id'] ?>">
              <td><strong><?= htmlspecialchars($p['title']) ?></strong><br><span style="font-size:0.78rem;color:#94a3b8;"><?= htmlspecialchars($p['competition']) ?></span></td>
              <td><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?><br><span style="color:#94a3b8;font-size:0.78rem;"><?= htmlspecialchars($p['club_name']) ?></span></td>
              <td><?= date('M d, Y', strtotime($p['created_at'])) ?></td>
              <td style="display:flex;gap:6px;flex-wrap:wrap;">
                <button class="card-btn" style="background:#16a34a;color:#fff;" onclick="verifyAch(<?= $p['id'] ?>, 'verify')">
                  <i class="fa-solid fa-check"></i> Verify
                </button>
                <button class="card-btn btn-danger" onclick="verifyAch(<?= $p['id'] ?>, 'reject')">
                  <i class="fa-solid fa-times"></i> Reject
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div><!-- end content-body -->
  </div><!-- end content -->

  <div class="footer">eLearning Commons &copy; 2026</div>
</div><!-- end main -->

<!-- Submit Achievement Modal (Student / Adviser) -->
<?php if (in_array($sess_role, ['student', 'club_adviser'])): ?>
<div class="modal-overlay" id="submitAchModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-trophy" style="color:#2563eb;"></i> Submit Competition Achievement</h3>
      <button style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b;" id="closeAchModal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label>Award Title *</label>
          <input type="text" id="achTitle" placeholder="e.g. 1st Place – IT Quiz Bee"/>
        </div>
        <div class="form-group">
          <label>Competition / Event *</label>
          <input type="text" id="achCompetition" placeholder="e.g. PCS National Convention"/>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Award Date *</label>
          <input type="date" id="achDate"/>
        </div>
        <div class="form-group">
          <label>Organization *</label>
          <select id="achClub">
            <?php if (empty($clubs)): ?>
              <option value="">-- No Active Joined Organizations (Join a club first) --</option>
            <?php else: ?>
              <option value="">-- Select Your Organization --</option>
              <?php foreach ($clubs as $c): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)</option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Notes / Description</label>
        <textarea id="achNotes" placeholder="Describe the achievement, category, participants..."></textarea>
      </div>
      <p style="font-size:0.78rem;color:#94a3b8;margin:0;">After submission, the SSC will verify and endorse your achievement on the official ledger.</p>
    </div>
    <div class="modal-footer">
      <button class="card-btn" style="background:#f1f5f9;color:#64748b;" id="cancelAchBtn">Cancel</button>
      <button class="card-btn" id="submitAchBtn">
        <i class="fa-solid fa-paper-plane"></i> Submit for Verification
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="../js/dashboard.js"></script>
<script src="../js/table-pagination.js"></script>
<script>
// -- Alert helper ----------------------------------------------
function showAlert(msg, type) {
  const el = document.getElementById('achAlert');
  el.style.display = 'block';
  el.style.background = type === 'success' ? '#dcfce7' : '#fef2f2';
  el.style.color      = type === 'success' ? '#166534' : '#991b1b';
  el.style.border     = `1px solid ${type === 'success' ? '#bbf7d0' : '#fecaca'}`;
  el.textContent = msg;
  setTimeout(() => { el.style.display = 'none'; }, 5000);
}

// -- Search filter ---------------------------------------------
function filterAch() {
  const q = document.getElementById('achSearch').value.toLowerCase().trim();
  document.querySelectorAll('.ach-row').forEach(r => {
    r.style.display = (!q || r.textContent.toLowerCase().includes(q)) ? '' : 'none';
  });
}

// -- Submit Modal (Student/Adviser) ----------------------------
<?php if (in_array($sess_role, ['student', 'club_adviser'])): ?>
const submitModal = document.getElementById('submitAchModal');
document.getElementById('openSubmitBtn')?.addEventListener('click', () => { submitModal.classList.add('active'); });
document.getElementById('closeAchModal')?.addEventListener('click', () => { submitModal.classList.remove('active'); });
document.getElementById('cancelAchBtn')?.addEventListener('click', () => { submitModal.classList.remove('active'); });
submitModal?.addEventListener('click', e => { if (e.target === submitModal) submitModal.classList.remove('active'); });

document.getElementById('submitAchBtn')?.addEventListener('click', function() {
  const title       = document.getElementById('achTitle').value.trim();
  const competition = document.getElementById('achCompetition').value.trim();
  const date        = document.getElementById('achDate').value;
  const club_id     = document.getElementById('achClub').value;
  const notes       = document.getElementById('achNotes').value.trim();

  if (!title || !competition || !date || !club_id) {
    showAlert('Please fill in all required fields.', 'error'); return;
  }

  this.disabled = true;
  this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';

  const fd = new FormData();
  fd.set('action', 'submit');
  fd.set('title', title);
  fd.set('competition', competition);
  fd.set('award_date', date);
  fd.set('club_id', club_id);
  fd.set('notes', notes);

  fetch('../shared/achievement_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      this.disabled = false;
      this.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit for Verification';
      if (data.success) {
        submitModal.classList.remove('active');
        showAlert('Achievement submitted! Awaiting SSC verification.', 'success');
        // Clear fields
        ['achTitle','achCompetition','achDate','achNotes'].forEach(id => document.getElementById(id).value = '');
        document.getElementById('achClub').value = '';
      } else {
        showAlert(data.message || 'Submission failed.', 'error');
      }
    })
    .catch(() => {
      this.disabled = false;
      this.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit for Verification';
      showAlert('Network error. Please try again.', 'error');
    });
});
<?php endif; ?>

// -- Verify/Reject (SSC/Admin) ---------------------------------
<?php if (in_array($sess_role, ['ssc', 'admin'])): ?>
function verifyAch(id, action) {
  const label = action === 'verify' ? 'verify and endorse' : 'reject';
  if (!confirm(`Are you sure you want to ${label} this achievement?`)) return;

  const fd = new FormData();
  fd.set('action', action);
  fd.set('id', id);

  fetch('../shared/achievement_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showAlert(data.message, 'success');
        const row = document.getElementById('ach-row-' + id);
        if (row) row.remove();
        setTimeout(() => location.reload(), 1500);
      } else {
        showAlert(data.message, 'error');
      }
    })
    .catch(() => showAlert('Network error.', 'error'));
}
<?php endif; ?>
</script>
</body>
</html>
