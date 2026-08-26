<?php
// ============================================================
//  ANNOUNCEMENTS.PHP  (dashboard/)
//  Co-Curricular System — Adviser & Organization Announcements Module
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
$ACTIVE_NAV = 'announcements';

// ── Fetch Adviser's active club ──────────────────────────────
$adviser_club = null;
$stmt_ac = $conn->prepare("
    SELECT c.id, c.name, c.code, c.category, c.description
    FROM clubs c
    JOIN club_memberships cm ON cm.club_id = c.id
    WHERE cm.user_id = ? AND cm.status = 'Active'
    LIMIT 1
");
if ($stmt_ac) {
  $stmt_ac->bind_param('i', $user_id);
  $stmt_ac->execute();
  $res_ac = $stmt_ac->get_result();
  if ($res_ac && $row_ac = $res_ac->fetch_assoc()) {
    $adviser_club = $row_ac;
  }
  $stmt_ac->close();
}

if (!$adviser_club) {
  $r_c = $conn->query("SELECT id, name, code, category, description FROM clubs WHERE status = 'Active' ORDER BY name LIMIT 1");
  if ($r_c && $row_c = $r_c->fetch_assoc()) {
    $adviser_club = $row_c;
  }
}

$club_id = $adviser_club ? (int) $adviser_club['id'] : 1;

// ── Ensure org_announcements table exists ─────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS org_announcements (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id      INT UNSIGNED NOT NULL,
    author_id    INT UNSIGNED NOT NULL,
    title        VARCHAR(250) NOT NULL,
    category     ENUM('Event','Activity','Requirement / Submission','Meeting','General') NOT NULL DEFAULT 'General',
    priority     ENUM('Normal','Important','Urgent') NOT NULL DEFAULT 'Normal',
    content      TEXT NOT NULL,
    target_group VARCHAR(100) DEFAULT 'All Members',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_club (club_id),
    KEY idx_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Fetch announcements from database ─────────────────────────
$announcements = [];
$r_ann = $conn->query("
    SELECT a.id, a.club_id, a.author_id, a.title, a.category, a.priority, a.content, a.target_group, a.created_at,
           c.name AS club_name, c.code AS club_code,
           u.first_name, u.last_name
    FROM org_announcements a
    JOIN clubs c ON c.id = a.club_id
    JOIN users u ON u.id = a.author_id
    WHERE a.club_id = {$club_id}
    ORDER BY a.created_at DESC
");
if ($r_ann) {
  $announcements = $r_ann->fetch_all(MYSQLI_ASSOC);
}

// Demo fallback announcements if table was newly created
if (empty($announcements)) {
  $announcements = [
    [
      'id' => 1,
      'club_id' => $club_id,
      'author_id' => $user_id,
      'title' => 'Passing of Mid-Term Activity Clearance & Financial Reports',
      'category' => 'Requirement / Submission',
      'priority' => 'Urgent',
      'content' => 'All officer committees and project heads are required to submit their midterm activity accomplishment reports and liquidation documents by August 15, 2026. Non-compliance will delay budget releases.',
      'target_group' => 'Organization Officers',
      'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
      'club_name' => $adviser_club['name'] ?? 'Unassigned Club',
      'club_code' => $adviser_club['code'] ?? 'N/A',
      'first_name' => $sess_first ?: 'Faculty',
      'last_name' => $sess_last ?: 'Adviser'
    ],
    [
      'id' => 2,
      'club_id' => $club_id,
      'author_id' => $user_id,
      'title' => 'Annual Tech Hackathon 2026 Guidelines & Team Registration',
      'category' => 'Event',
      'priority' => 'Important',
      'content' => 'Registration for the 24-Hour Hackathon is officially open! Form teams of 3 to 4 members. Pre-event orientation meeting scheduled on Friday at 3:00 PM in Lab 304.',
      'target_group' => 'All Members',
      'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
      'club_name' => $adviser_club['name'] ?? 'Unassigned Club',
      'club_code' => $adviser_club['code'] ?? 'N/A',
      'first_name' => $sess_first ?: 'Faculty',
      'last_name' => $sess_last ?: 'Adviser'
    ],
    [
      'id' => 3,
      'club_id' => $club_id,
      'author_id' => $user_id,
      'title' => 'Executive Board & Faculty Adviser Monthly Assembly',
      'category' => 'Meeting',
      'priority' => 'Normal',
      'content' => 'Monthly alignment meeting with club officers regarding mid-year outreach projects and upcoming inter-school competitions. Attendance is mandatory for all executive officers.',
      'target_group' => 'Executive Board',
      'created_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
      'club_name' => $adviser_club['name'] ?? 'Unassigned Club',
      'club_code' => $adviser_club['code'] ?? 'N/A',
      'first_name' => $sess_first ?: 'Faculty',
      'last_name' => $sess_last ?: 'Adviser'
    ]
  ];
}

$total_ann = count($announcements);
$urgent_cnt = count(array_filter($announcements, fn($a) => $a['priority'] === 'Urgent'));
$req_cnt = count(array_filter($announcements, fn($a) => $a['category'] === 'Requirement / Submission'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Organization Announcements – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <style>
    .ann-category-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 700;
    }

    .cat-event {
      background: #dbeafe;
      color: #1e40af;
    }

    .cat-activity {
      background: #e0e7ff;
      color: #3730a3;
    }

    .cat-requirement {
      background: #fef3c7;
      color: #92400e;
    }

    .cat-meeting {
      background: #f3e8ff;
      color: #6b21a8;
    }

    .cat-general {
      background: #f1f5f9;
      color: #475569;
    }

    .prio-badge {
      font-size: 0.72rem;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 10px;
      text-transform: uppercase;
    }

    .prio-urgent {
      background: #fee2e2;
      color: #991b1b;
    }

    .prio-important {
      background: #ffedd5;
      color: #9a3412;
    }

    .prio-normal {
      background: #f1f5f9;
      color: #475569;
    }

    .ann-card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 16px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
      transition: all 0.2s ease;
    }

    .ann-card:hover {
      border-color: #cbd5e1;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.07);
    }

    .ann-meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 10px;
    }

    .ann-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: #0f172a;
      margin: 0 0 8px;
      line-height: 1.35;
    }

    .ann-content {
      font-size: 0.88rem;
      color: #334155;
      line-height: 1.6;
      white-space: pre-line;
    }

    .ann-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 14px;
      padding-top: 12px;
      border-top: 1px solid #f1f5f9;
      font-size: 0.8rem;
      color: #64748b;
    }
  </style>
</head>

<body>

  <?php
  $APP_ROOT = '../';
  $ACTIVE_NAV = 'announcements';
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
        <button class="topbar-qr-btn" id="qrFabBtn" title="View Personal Attendance QR Code" type="button">
          <i class="fa-solid fa-qrcode"></i>
        </button>
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

      <!-- Page Title Bar -->
      <div class="page-title-bar"
        style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
        <div>
          <h2 class="page-title">
            <i class="fa-solid fa-bullhorn"></i>
            Organization Announcements Module
          </h2>
          <div style="font-size:0.82rem; color:#64748b; margin-top:4px;">
            <i class="fa-solid fa-building-columns" style="color:#2563eb;"></i>
            Official management announcements for events, activities, meetings, and requirement submissions
          </div>
        </div>
        <?php if (in_array($sess_role, ['club_adviser', 'admin', 'ssc'])): ?>
          <button class="card-btn" onclick="openCreateModal()"
            style="background:#2563eb; color:#fff; font-weight:700; padding:10px 18px; border-radius:8px;">
            <i class="fa-solid fa-plus-circle"></i> Post Announcement
          </button>
        <?php endif; ?>
      </div>

      <div class="content-body">

        <!-- Alert box -->
        <div id="annAlert" style="display:none; padding:12px 16px; border-radius:8px; font-size:0.85rem;"></div>

        <!-- Stats Row -->
        <div class="info-row">
          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-bullhorn"></i> Total Announcements</div>
            <div class="card-amount"><?= $total_ann ?></div>
            <div class="card-detail">Published org notices</div>
          </div>

          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-triangle-exclamation"></i> Urgent Notices</div>
            <div class="card-amount"><?= $urgent_cnt ?></div>
            <div class="card-detail">High priority broadcasts</div>
          </div>

          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-file-signature"></i> Submissions / Requirements</div>
            <div class="card-amount"><?= $req_cnt ?></div>
            <div class="card-detail">Clearance &amp; passing notices</div>
          </div>

          <div class="info-card">
            <div class="card-label"><i class="fa-solid fa-building-columns"></i> Managed Organization</div>
            <div class="card-amount" style="font-size:1.1rem;"><?= htmlspecialchars($adviser_club['code'] ?? 'N/A') ?>
            </div>
            <div class="card-detail"><?= htmlspecialchars($adviser_club['name'] ?? 'Assigned Organization') ?></div>
          </div>
        </div>

        <!-- Filter Tabs Bar -->
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
          <button class="card-btn ann-filter-btn active" onclick="filterCategory('All', this)"
            style="background:#2563eb; color:#fff; font-size:0.8rem; padding:6px 14px; border-radius:20px;">
            <i class="fa-solid fa-layer-group"></i> All Notices
          </button>
          <button class="card-btn ann-filter-btn" onclick="filterCategory('Event', this)"
            style="background:#f1f5f9; color:#475569; font-size:0.8rem; padding:6px 14px; border-radius:20px;">
            <i class="fa-solid fa-calendar-check"></i> Events
          </button>
          <button class="card-btn ann-filter-btn" onclick="filterCategory('Activity', this)"
            style="background:#f1f5f9; color:#475569; font-size:0.8rem; padding:6px 14px; border-radius:20px;">
            <i class="fa-solid fa-trophy"></i> Activities
          </button>
          <button class="card-btn ann-filter-btn" onclick="filterCategory('Requirement / Submission', this)"
            style="background:#f1f5f9; color:#475569; font-size:0.8rem; padding:6px 14px; border-radius:20px;">
            <i class="fa-solid fa-file-lines"></i> Requirements &amp; Passing
          </button>
          <button class="card-btn ann-filter-btn" onclick="filterCategory('Meeting', this)"
            style="background:#f1f5f9; color:#475569; font-size:0.8rem; padding:6px 14px; border-radius:20px;">
            <i class="fa-solid fa-users"></i> Meetings
          </button>
        </div>

        <!-- Announcements Container -->
        <div id="annContainer">
          <?php foreach ($announcements as $ann): ?>
            <?php
            $cat = $ann['category'];
            $cat_class = 'cat-general';
            if ($cat === 'Event')
              $cat_class = 'cat-event';
            elseif ($cat === 'Activity')
              $cat_class = 'cat-activity';
            elseif ($cat === 'Requirement / Submission')
              $cat_class = 'cat-requirement';
            elseif ($cat === 'Meeting')
              $cat_class = 'cat-meeting';

            $prio = $ann['priority'];
            $prio_class = 'prio-normal';
            if ($prio === 'Urgent')
              $prio_class = 'prio-urgent';
            elseif ($prio === 'Important')
              $prio_class = 'prio-important';
            ?>
            <div class="ann-card" data-category="<?= htmlspecialchars($cat) ?>"
              data-title="<?= htmlspecialchars(strtolower($ann['title'])) ?>">
              <div class="ann-meta">
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                  <span class="ann-category-pill <?= $cat_class ?>">
                    <i class="fa-solid fa-tag"></i> <?= htmlspecialchars($cat) ?>
                  </span>
                  <span class="prio-badge <?= $prio_class ?>"><?= htmlspecialchars($prio) ?></span>
                  <span style="font-size:0.78rem; color:#64748b;">
                    <i class="fa-solid fa-users"></i> Target:
                    <strong><?= htmlspecialchars($ann['target_group'] ?? 'All Members') ?></strong>
                  </span>
                </div>
                <span style="font-size:0.78rem; color:#94a3b8;">
                  <i class="fa-solid fa-clock"></i> <?= date('M j, Y \a\t g:i A', strtotime($ann['created_at'])) ?>
                </span>
              </div>

              <h3 class="ann-title"><?= htmlspecialchars($ann['title']) ?></h3>
              <div class="ann-content"><?= htmlspecialchars($ann['content']) ?></div>

              <div class="ann-footer">
                <div>
                  <i class="fa-solid fa-user-tie" style="color:#2563eb;"></i> Posted by
                  <strong><?= htmlspecialchars($ann['first_name'] . ' ' . $ann['last_name']) ?></strong> &bull;
                  <?= htmlspecialchars($ann['club_name']) ?>
                </div>
                <?php if (in_array($sess_role, ['club_adviser', 'admin']) && ($ann['author_id'] == $user_id || $sess_role === 'admin')): ?>
                  <button class="card-btn" onclick="deleteAnnouncement(<?= $ann['id'] ?>)"
                    style="background:#fee2e2; color:#991b1b; padding:4px 10px; font-size:0.75rem;">
                    <i class="fa-solid fa-trash"></i> Delete
                  </button>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

      </div><!-- end content-body -->
    </div><!-- end content -->
  </div><!-- end main -->

  <!-- Create Announcement Modal -->
  <div class="qr-modal-overlay" id="createAnnModal"
    style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center;">
    <div
      style="background:#fff; border-radius:16px; width:520px; max-width:92vw; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.2);">
      <div
        style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
        <h3 style="margin:0; font-size:1.1rem; color:#0f172a;"><i class="fa-solid fa-bullhorn"
            style="color:#2563eb;"></i> Post Organization Announcement</h3>
        <button onclick="closeCreateModal()"
          style="background:none; border:none; font-size:1.1rem; cursor:pointer; color:#64748b;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>

      <form id="createAnnForm" onsubmit="submitAnnouncement(event)">
        <input type="hidden" name="club_id" value="<?= $club_id ?>" />
        <div style="margin-bottom:14px;">
          <label
            style="display:block; font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:4px;">Announcement
            Title *</label>
          <input type="text" name="title" required placeholder="e.g. Passing of Mid-Term Clearance Reports"
            style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.88rem;" />
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
          <div>
            <label style="display:block; font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:4px;">Category
              *</label>
            <select name="category"
              style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.85rem;">
              <option value="Requirement / Submission">Requirement / Submission</option>
              <option value="Event">Event</option>
              <option value="Activity">Activity</option>
              <option value="Meeting">Meeting</option>
              <option value="General">General</option>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:4px;">Priority
              Level</label>
            <select name="priority"
              style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.85rem;">
              <option value="Normal">Normal</option>
              <option value="Important">Important</option>
              <option value="Urgent">Urgent</option>
            </select>
          </div>
        </div>

        <div style="margin-bottom:14px;">
          <label style="display:block; font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:4px;">Target
            Audience</label>
          <select name="target_group"
            style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.85rem;">
            <option value="All Members">All Members</option>
            <option value="Organization Officers">Organization Officers &amp; Executive Board</option>
            <option value="Event Participants">Event Participants</option>
            <option value="Graduating Students">Graduating Students</option>
          </select>
        </div>

        <div style="margin-bottom:18px;">
          <label
            style="display:block; font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:4px;">Announcement
            Details / Content *</label>
          <textarea name="content" rows="4" required
            placeholder="Write full announcement details, guidelines, deadlines, and instructions here..."
            style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.85rem; font-family:inherit;"></textarea>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" onclick="closeCreateModal()" class="card-btn"
            style="background:#f1f5f9; color:#475569;">Cancel</button>
          <button type="submit" class="card-btn" style="background:#2563eb; color:#fff; font-weight:700;">
            <i class="fa-solid fa-paper-plane"></i> Publish Announcement
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function openCreateModal() {
      document.getElementById('createAnnModal').style.display = 'flex';
    }
    function closeCreateModal() {
      document.getElementById('createAnnModal').style.display = 'none';
    }

    function showAlert(msg, isSuccess) {
      const alertBox = document.getElementById('annAlert');
      alertBox.style.display = 'block';
      alertBox.style.background = isSuccess ? '#dcfce7' : '#fee2e2';
      alertBox.style.color = isSuccess ? '#15803d' : '#991b1b';
      alertBox.innerHTML = (isSuccess ? '<i class="fa-solid fa-circle-check"></i> ' : '<i class="fa-solid fa-triangle-exclamation"></i> ') + msg;
      setTimeout(() => { alertBox.style.display = 'none'; }, 4000);
    }

    function submitAnnouncement(e) {
      e.preventDefault();
      const form = document.getElementById('createAnnForm');
      const formData = new FormData(form);
      formData.append('action', 'create');

      fetch('../shared/announcement_actions.php', {
        method: 'POST',
        body: formData
      })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            showAlert(res.message, true);
            closeCreateModal();
            setTimeout(() => location.reload(), 1000);
          } else {
            showAlert(res.message, false);
          }
        })
        .catch(err => {
          showAlert('Error submitting announcement.', false);
        });
    }

    function deleteAnnouncement(id) {
      if (!confirm('Are you sure you want to delete this announcement?')) return;
      const formData = new FormData();
      formData.append('action', 'delete');
      formData.append('id', id);

      fetch('../shared/announcement_actions.php', {
        method: 'POST',
        body: formData
      })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            showAlert(res.message, true);
            setTimeout(() => location.reload(), 1000);
          } else {
            showAlert(res.message, false);
          }
        });
    }

    function filterCategory(cat, btn) {
      document.querySelectorAll('.ann-filter-btn').forEach(b => {
        b.style.background = '#f1f5f9';
        b.style.color = '#475569';
      });
      btn.style.background = '#2563eb';
      btn.style.color = '#fff';

      const cards = document.querySelectorAll('.ann-card');
      cards.forEach(card => {
        if (cat === 'All' || card.dataset.category === cat) {
          card.style.display = 'block';
        } else {
          card.style.display = 'none';
        }
      });
    }

    function filterAnnouncements() {
      const query = document.getElementById('annSearchInput').value.toLowerCase();
      const cards = document.querySelectorAll('.ann-card');
      cards.forEach(card => {
        if (card.dataset.title.includes(query)) {
          card.style.display = 'block';
        } else {
          card.style.display = 'none';
        }
      });
    }
  </script>
  <script src="../js/dashboard.js"></script>
</body>

</html>
