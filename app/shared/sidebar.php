<?php
// ============================================================
//  SIDEBAR.PHP  (shared/)
//  Role-Filtered Categorized Dropdown Sidebar
//  Strictly enforces RBAC visibility based on Section 4.2 of Blueprint
// ============================================================

$APP_ROOT   = $APP_ROOT   ?? '../';
$ACTIVE_NAV = $ACTIVE_NAV ?? '';
$user_role  = $_SESSION['role'] ?? 'student';
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" title="Go to Dashboard">
        <img src="<?= $APP_ROOT ?>images/BCP_LOGO.png" alt="BCP Logo" class="sidebar-logo-img"/>
      </a>
      <span class="sidebar-notif" id="bellBtn" title="Notifications">
        <i class="fa-solid fa-bell"></i>
        <span class="sidebar-notif-badge has-notif" id="bellBadge">3</span>
      </span>
    </div>
  </div>

  <div class="sidebar-nav">

    <!-- ══════════════════════════════════════
         GROUP 1 — Core Navigation (All Roles)
    ══════════════════════════════════════ -->
    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Core Navigation</div>
      <div class="brand-sub">General & Overview</div>
    </div>

    <!-- 1. Dashboard -->
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" class="sidebar-item <?= $ACTIVE_NAV==='dashboard'?'active':'' ?>">
        <i class="fa-solid fa-gauge"></i>
        <span>Dashboard</span>
      </a>
    </div>

    <!-- 2. Club Directory -->
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/club_directory.php" class="sidebar-item <?= $ACTIVE_NAV==='clubs'?'active':'' ?>">
        <i class="fa-solid fa-sitemap"></i>
        <span>Club Directory</span>
      </a>
    </div>

    <!-- ══════════════════════════════════════
         GROUP 2 — Governance
    ══════════════════════════════════════ -->
    <div class="sidebar-divider"></div>
    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Governance</div>
      <div class="brand-sub">Roster & Elections</div>
    </div>

    <!-- 3. Membership Roster -->
    <?php
      $roster_items = [];
      if ($user_role === 'student') {
          $roster_items[] = ['url' => $APP_ROOT . 'dashboard/roster.php#memberships', 'label' => 'My Club Memberships'];
      }
      if ($user_role === 'club_adviser') {
          $roster_items[] = ['url' => $APP_ROOT . 'dashboard/roster.php#applicants', 'label' => 'Applicant Queue'];
      }
      if (in_array($user_role, ['club_adviser', 'osa_director', 'admin'])) {
          $roster_items[] = ['url' => $APP_ROOT . 'dashboard/roster.php#master', 'label' => 'Master Member Roster'];
      }
    ?>
    <?php if (count($roster_items) === 1): ?>
      <div class="nav-group">
        <a href="<?= $roster_items[0]['url'] ?>" class="sidebar-item <?= $ACTIVE_NAV==='roster'?'active':'' ?>">
          <i class="fa-solid fa-users"></i>
          <span>Membership Roster</span>
        </a>
      </div>
    <?php elseif (count($roster_items) > 1): ?>
      <div class="nav-group">
        <button class="sidebar-item <?= $ACTIVE_NAV==='roster'?'active open':'' ?> dropdown-trigger" data-target="drop3">
          <i class="fa-solid fa-users"></i>
          <span>Membership Roster</span>
          <i class="fa-solid fa-chevron-down arrow"></i>
        </button>
        <div class="dropdown-menu <?= $ACTIVE_NAV==='roster'?'open':'' ?>" id="drop3">
          <?php foreach ($roster_items as $item): ?>
            <a href="<?= $item['url'] ?>" class="dropdown-item"><?= $item['label'] ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- 4. Elections — single direct link for all roles -->
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/elections.php" class="sidebar-item <?= $ACTIVE_NAV==='elections'?'active':'' ?>">
        <i class="fa-solid fa-check-to-slot"></i>
        <span>Elections & Voting</span>
      </a>
    </div>

    <!-- ══════════════════════════════════════
         GROUP 3 — Events & Records
    ══════════════════════════════════════ -->
    <div class="sidebar-divider"></div>
    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Events & Records</div>
      <div class="brand-sub">Activities & Awards</div>
    </div>

    <!-- 5. Events -->
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/events.php" class="sidebar-item <?= $ACTIVE_NAV==='events'?'active':'' ?>">
        <i class="fa-solid fa-calendar-days"></i>
        <span>Events & Activities</span>
      </a>
    </div>

    <!-- 6. Attendance Tracker -->
    <?php
      $attendance_items = [];
      if ($user_role === 'student') {
          $attendance_items[] = ['url' => $APP_ROOT . 'dashboard/attendance.php#logs', 'label' => 'My Attendance Logs'];
      }
      if (in_array($user_role, ['club_adviser', 'osa_director', 'admin'])) {
          $attendance_items[] = ['url' => $APP_ROOT . 'dashboard/attendance.php#scanner', 'label' => 'Scanner Terminal (QR / RFID)'];
      }
      if (in_array($user_role, ['osa_director', 'admin'])) {
          $attendance_items[] = ['url' => $APP_ROOT . 'dashboard/attendance.php#analytics', 'label' => 'Absentee Analytics'];
      }
    ?>
    <?php if (count($attendance_items) === 1): ?>
      <div class="nav-group">
        <a href="<?= $attendance_items[0]['url'] ?>" class="sidebar-item <?= $ACTIVE_NAV==='attendance'?'active':'' ?>">
          <i class="fa-solid fa-qrcode"></i>
          <span>Attendance Tracker</span>
        </a>
      </div>
    <?php elseif (count($attendance_items) > 1): ?>
      <div class="nav-group">
        <button class="sidebar-item <?= $ACTIVE_NAV==='attendance'?'active open':'' ?> dropdown-trigger" data-target="drop6">
          <i class="fa-solid fa-qrcode"></i>
          <span>Attendance Tracker</span>
          <i class="fa-solid fa-chevron-down arrow"></i>
        </button>
        <div class="dropdown-menu <?= $ACTIVE_NAV==='attendance'?'open':'' ?>" id="drop6">
          <?php foreach ($attendance_items as $item): ?>
            <a href="<?= $item['url'] ?>" class="dropdown-item"><?= $item['label'] ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- 7. Awards & Achievements -->
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/achievements.php" class="sidebar-item <?= $ACTIVE_NAV==='achievements'?'active':'' ?>">
        <i class="fa-solid fa-trophy"></i>
        <span>Awards & Achievements</span>
      </a>
    </div>

    <!-- ══════════════════════════════════════
         GROUP 4 — Finance
    ══════════════════════════════════════ -->
    <div class="sidebar-divider"></div>

    <!-- 8. Budget & Finance -->
    <?php
      $budget_items = [];
      if (in_array($user_role, ['club_adviser', 'admin'])) {
          $budget_items[] = ['url' => $APP_ROOT . 'dashboard/budget.php#requisition', 'label' => 'Requisition & Receipt Upload'];
      }
      if (in_array($user_role, ['club_adviser', 'osa_director', 'finance_officer', 'admin'])) {
          $budget_items[] = ['url' => $APP_ROOT . 'dashboard/budget.php#approvals', 'label' => 'Approval Portal & Disbursals'];
      }
    ?>
    <?php if (count($budget_items) === 1): ?>
      <div class="nav-group">
        <a href="<?= $budget_items[0]['url'] ?>" class="sidebar-item <?= $ACTIVE_NAV==='budget'?'active':'' ?>">
          <i class="fa-solid fa-hand-holding-dollar"></i>
          <span>Budget & Finance</span>
        </a>
      </div>
    <?php elseif (count($budget_items) > 1): ?>
      <div class="nav-group">
        <button class="sidebar-item <?= $ACTIVE_NAV==='budget'?'active open':'' ?> dropdown-trigger" data-target="drop8">
          <i class="fa-solid fa-hand-holding-dollar"></i>
          <span>Budget & Finance</span>
          <i class="fa-solid fa-chevron-down arrow"></i>
        </button>
        <div class="dropdown-menu <?= $ACTIVE_NAV==='budget'?'open':'' ?>" id="drop8">
          <?php foreach ($budget_items as $item): ?>
            <a href="<?= $item['url'] ?>" class="dropdown-item"><?= $item['label'] ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>



    <!-- ══════════════════════════════════════
         GROUP 5 — Administration (Admin & OSA ONLY)
    ══════════════════════════════════════ -->
    <?php if (in_array($user_role, ['admin', 'osa_director'])): ?>
    <div class="sidebar-divider"></div>
    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Administration</div>
      <div class="brand-sub">System & Security</div>
    </div>

    <!-- 10. System Administration -->
    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='admin'?'active open':'' ?> dropdown-trigger" data-target="drop10">
        <i class="fa-solid fa-shield-halved"></i>
        <span>System Administration</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu <?= $ACTIVE_NAV==='admin'?'open':'' ?>" id="drop10">
        <a href="<?= $APP_ROOT ?>dashboard/admin_system.php#roles" class="dropdown-item">Role & Access Control</a>
        <a href="<?= $APP_ROOT ?>dashboard/admin_system.php#logs" class="dropdown-item">System Audit Logs</a>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- end sidebar-nav -->
</aside><!-- end sidebar -->

<!-- Notification Panel Overlay & Panel -->
<div class="notif-overlay" id="notifOverlay"></div>
<div class="notif-panel" id="notifPanel">
  <div class="notif-header">
    <span>Notifications</span>
    <div class="notif-header-actions">
      <button class="notif-mark-all" id="notifMarkAll">Mark all read</button>
      <button class="notif-close" id="notifClose">×</button>
    </div>
  </div>
  <div class="notif-list" id="notifList">
    <div style="text-align:center;padding:24px;color:#94a3b8;font-size:0.85rem;">
      <i class="fa-solid fa-bell-slash" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
      Loading notifications...
    </div>
  </div>
</div>

<script>
// ── Live Notification Panel ───────────────────────────────────
(function() {
  const bellBtn   = document.getElementById('bellBtn');
  const badge     = document.getElementById('bellBadge');
  const panel     = document.getElementById('notifPanel');
  const overlay   = document.getElementById('notifOverlay');
  const list      = document.getElementById('notifList');
  const markAllBtn= document.getElementById('notifMarkAll');
  const closeBtn  = document.getElementById('notifClose');

  function timeSince(dateStr) {
    const d = new Date(dateStr.replace(' ','T'));
    const s = Math.floor((Date.now() - d) / 1000);
    if (s < 60)   return 'Just now';
    if (s < 3600) return Math.floor(s/60) + 'm ago';
    if (s < 86400)return Math.floor(s/3600) + 'h ago';
    return Math.floor(s/86400) + 'd ago';
  }

  function loadNotifs() {
    fetch('<?= $APP_ROOT ?>shared/notification_actions.php?action=list&limit=15')
      .then(r => r.json())
      .then(data => {
        if (!data.success) return;
        const count = data.unread || 0;
        badge.textContent = count;
        badge.classList.toggle('has-notif', count > 0);

        if (!data.notifications.length) {
          list.innerHTML = '<div style="text-align:center;padding:24px;color:#94a3b8;font-size:0.85rem;"><i class="fa-solid fa-bell-slash" style="font-size:2rem;display:block;margin-bottom:8px;"></i>No notifications yet.</div>';
          return;
        }

        list.innerHTML = data.notifications.map(n => `
          <div class="notif-item${n.is_read ? '' : ' unread'}" data-id="${n.id}" onclick="markRead(${n.id}, this)">
            <div class="notif-dot"></div>
            <div class="notif-text">
              <div class="notif-title">${n.title}</div>
              <div class="notif-desc">${n.message}</div>
            </div>
            <div class="notif-time">${timeSince(n.created_at)}</div>
          </div>`).join('');
      })
      .catch(() => {});
  }

  function openPanel() { panel.classList.add('open'); overlay.classList.add('active'); loadNotifs(); }
  function closePanel() { panel.classList.remove('open'); overlay.classList.remove('active'); }

  bellBtn?.addEventListener('click', openPanel);
  closeBtn?.addEventListener('click', closePanel);
  overlay?.addEventListener('click', closePanel);

  markAllBtn?.addEventListener('click', () => {
    fetch('<?= $APP_ROOT ?>shared/notification_actions.php', {
      method: 'POST',
      body: new URLSearchParams({ action: 'mark_read', id: 0 })
    }).then(() => loadNotifs());
  });

  window.markRead = function(id, el) {
    if (el.classList.contains('unread')) {
      el.classList.remove('unread');
      fetch('<?= $APP_ROOT ?>shared/notification_actions.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'mark_read', id })
      }).then(() => { const c = parseInt(badge.textContent)||0; badge.textContent = Math.max(0,c-1); badge.classList.toggle('has-notif', c-1 > 0); });
    }
  };

  // Initial badge count
  loadNotifs();
})();
</script>

<?php require_once __DIR__ . '/qr_modal.php'; ?>

