<?php
// ============================================================
//  SIDEBAR.PHP  (shared/)
//  Role-Filtered Categorized Dropdown Sidebar
//  Strictly enforces RBAC visibility based on Institutional Workflow:
//  Student → Adviser → SSC Officer → System Admin
// ============================================================
require_once __DIR__ . '/security.php';

$APP_ROOT   = $APP_ROOT   ?? '../';
$ACTIVE_NAV = $ACTIVE_NAV ?? '';
$user_role  = $_SESSION['role'] ?? 'student';
$real_role  = $_SESSION['real_role'] ?? $user_role;
$is_impersonating = ($user_role !== $real_role && $real_role === 'admin');
?>

<!-- Global CSRF Token Meta Tag -->
<meta name="csrf-token" content="<?= csrf_token() ?>">

<!-- Global CSRF Auto-Attacher for fetch & AJAX requests -->
<script>
window.CSRF_TOKEN = "<?= csrf_token() ?>";
(function() {
  const origFetch = window.fetch;
  window.fetch = function(url, options = {}) {
    options = options || {};
    options.headers = options.headers || {};
    if (options.headers instanceof Headers) {
      if (!options.headers.has('X-CSRF-Token')) {
        options.headers.append('X-CSRF-Token', window.CSRF_TOKEN);
      }
    } else if (Array.isArray(options.headers)) {
      options.headers.push(['X-CSRF-Token', window.CSRF_TOKEN]);
    } else {
      options.headers['X-CSRF-Token'] = window.CSRF_TOKEN;
    }
    return origFetch(url, options);
  };
})();
</script>

<?php if ($is_impersonating): ?>
<!-- ══════════════════════════════════════════════════════════════
     ADMIN IMPERSONATION AMBER BANNER
══════════════════════════════════════════════════════════════ -->
<div class="impersonation-amber-banner" style="position:fixed; top:0; left:0; right:0; z-index:99999; background:#fef3c7; border-bottom:2px solid #f59e0b; color:#92400e; padding:8px 20px; font-size:0.84rem; font-weight:700; display:flex; align-items:center; justify-content:space-between; box-shadow:0 4px 12px rgba(0,0,0,0.08);">
  <div style="display:flex; align-items:center; gap:10px;">
    <i class="fa-solid fa-triangle-exclamation" style="color:#d97706; font-size:1.05rem;"></i>
    <span>Admin Sandbox: Viewing system as <strong><?= htmlspecialchars(ucwords(str_replace('_',' ',$user_role))) ?></strong></span>
  </div>
  <a href="<?= $APP_ROOT ?>dashboard/dashboard.php?switch_role=admin" style="background:#d97706; color:#fff; text-decoration:none; padding:5px 14px; border-radius:6px; font-size:0.76rem; font-weight:800; display:inline-flex; align-items:center; gap:6px; transition:background 0.2s;">
    <i class="fa-solid fa-arrow-rotate-left"></i> Revert to System Admin
  </a>
</div>
<style>
  body { padding-top: 38px !important; }
  .sidebar { top: 38px !important; height: calc(100vh - 38px) !important; }
  .topbar { top: 38px !important; }
</style>
<?php endif; ?>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" title="Go to Dashboard">
        <img src="<?= $APP_ROOT ?>images/BCP_LOGO.png" alt="BCP Logo" class="sidebar-logo-img"/>
      </a>
      <span class="sidebar-notif" id="bellBtn" title="Notifications">
        <i class="fa-solid fa-bell"></i>
        <span class="sidebar-notif-badge has-notif" id="bellBadge">0</span>
      </span>
    </div>
  </div>

  <div class="sidebar-nav">

    <!-- ══════════════════════════════════════════════════════════
         CORE NAVIGATION (All Roles)
    ══════════════════════════════════════════════════════════ -->
    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Core Navigation</div>
      <div class="brand-sub">General &amp; Overview</div>
    </div>

    <!-- 1. Dashboard -->
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" class="sidebar-item <?= $ACTIVE_NAV==='dashboard'?'active':'' ?>">
        <i class="fa-solid fa-gauge"></i>
        <span>Dashboard</span>
      </a>
    </div>

    <!-- 2. Organization Directory (Hidden for Adviser) -->
    <?php if ($user_role !== 'club_adviser'): ?>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/club_directory.php" class="sidebar-item <?= $ACTIVE_NAV==='clubs'?'active':'' ?>">
        <i class="fa-solid fa-sitemap"></i>
        <span>Organization Directory</span>
      </a>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════
         GOVERNANCE & LIFECYCLE
    ══════════════════════════════════════════════════════════ -->
    <div class="sidebar-divider"></div>
    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Governance</div>
      <div class="brand-sub">Roster &amp; Elections</div>
    </div>

    <!-- 3. Roster Management (Sidepanel Dropdown for Adviser & Officers) -->
    <?php if ($user_role === 'student'): ?>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/roster.php" class="sidebar-item <?= $ACTIVE_NAV==='roster'?'active':'' ?>">
        <i class="fa-solid fa-users"></i>
        <span>Membership Roster</span>
      </a>
    </div>
    <?php else: ?>
      <?php
        $is_roster_active = ($ACTIVE_NAV === 'roster');
        $roster_sub = $ACTIVE_SUB ?? ($_GET['view'] ?? 'queue');
      ?>
      <div class="nav-group">
        <button class="sidebar-item <?= $is_roster_active ? 'active open' : '' ?> dropdown-trigger" data-target="dropRoster">
          <i class="fa-solid fa-users"></i>
          <span>Roster Management</span>
          <i class="fa-solid fa-chevron-down arrow"></i>
        </button>
        <div class="dropdown-menu <?= $is_roster_active ? 'open' : '' ?>" id="dropRoster">
          <a href="<?= $APP_ROOT ?>dashboard/roster.php?view=queue" class="dropdown-item <?= ($is_roster_active && $roster_sub === 'queue') ? 'active' : '' ?>">Application Queue</a>
          <a href="<?= $APP_ROOT ?>dashboard/roster.php?view=roster" class="dropdown-item <?= ($is_roster_active && $roster_sub === 'roster') ? 'active' : '' ?>">Member Roster</a>
        </div>
      </div>
    <?php endif; ?>

    <!-- 4. Events & Activities (Sidepanel Dropdown for Adviser & Officers) -->
    <?php if ($user_role === 'student'): ?>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/events.php" class="sidebar-item <?= $ACTIVE_NAV==='events'?'active':'' ?>">
        <i class="fa-solid fa-calendar-days"></i>
        <span>Events &amp; Activities</span>
      </a>
    </div>
    <?php else: ?>
      <?php
        $is_events_active = ($ACTIVE_NAV === 'events');
        $events_sub = $ACTIVE_SUB ?? ($_GET['view'] ?? 'calendar');
      ?>
      <div class="nav-group">
        <button class="sidebar-item <?= $is_events_active ? 'active open' : '' ?> dropdown-trigger" data-target="dropEvents">
          <i class="fa-solid fa-calendar-days"></i>
          <span>Events &amp; Activities</span>
          <i class="fa-solid fa-chevron-down arrow"></i>
        </button>
        <div class="dropdown-menu <?= $is_events_active ? 'open' : '' ?>" id="dropEvents">
          <a href="<?= $APP_ROOT ?>dashboard/events.php?view=calendar" class="dropdown-item <?= ($is_events_active && $events_sub === 'calendar') ? 'active' : '' ?>">Active Calendar</a>
          <a href="<?= $APP_ROOT ?>dashboard/events.php?view=pipeline" class="dropdown-item <?= ($is_events_active && $events_sub === 'pipeline') ? 'active' : '' ?>">Campus Event Calendar &amp; Approval Pipeline</a>
        </div>
      </div>
    <?php endif; ?>

    <!-- 5. Budget & Finance (Adviser, SSC, Admin ONLY - Student Access Terminated) -->
    <?php if (in_array($user_role, ['club_adviser', 'ssc', 'admin'])): ?>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/budget.php" class="sidebar-item <?= $ACTIVE_NAV==='budget'?'active':'' ?>">
        <i class="fa-solid fa-hand-holding-dollar"></i>
        <span>Budget &amp; Finance</span>
      </a>
    </div>
    <?php endif; ?>

    <!-- 6. Elections & Voting -->
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/elections.php" class="sidebar-item <?= $ACTIVE_NAV==='elections'?'active':'' ?>">
        <i class="fa-solid fa-check-to-slot"></i>
        <span>Elections &amp; Voting</span>
      </a>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         ACTIVITIES & RECORDS
    ══════════════════════════════════════════════════════════ -->
    <div class="sidebar-divider"></div>
    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Records &amp; Media</div>
      <div class="brand-sub">Achievements &amp; Tracking</div>
    </div>

    <!-- 7. Achievements & Awards (Reactivated for all roles) -->
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/achievements.php" class="sidebar-item <?= $ACTIVE_NAV==='achievements'?'active':'' ?>">
        <i class="fa-solid fa-award"></i>
        <span>Achievements &amp; Awards</span>
      </a>
    </div>

    <!-- 8. Tracking Portal / My Attendance History -->
    <?php if ($user_role === 'student'): ?>
      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/tracking_history.php" class="sidebar-item <?= $ACTIVE_NAV==='attendance'?'active':'' ?>">
          <i class="fa-solid fa-clipboard-user"></i>
          <span>My Attendance History</span>
        </a>
      </div>
    <?php else: ?>
      <?php
        $attendance_items = [
            ['url' => $APP_ROOT . 'dashboard/tracking_qr_generator.php', 'label' => 'Event QR Generator', 'id' => 'generator'],
            ['url' => $APP_ROOT . 'dashboard/tracking_scanner.php', 'label' => 'On-Site Scanner Terminal', 'id' => 'scanner'],
            ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php', 'label' => 'Attendance List per Event', 'id' => 'attendance_list'],
        ];
        if (in_array($user_role, ['ssc', 'admin'])) {
            $attendance_items[] = ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php#analytics', 'label' => 'Absentee Analytics & Overrides', 'id' => 'analytics'];
        }
      ?>
      <div class="nav-group">
        <button class="sidebar-item <?= $ACTIVE_NAV==='attendance'?'active':'' ?> dropdown-trigger" data-target="dropTracking">
          <i class="fa-solid fa-qrcode"></i>
          <span>Tracking Portal</span>
          <i class="fa-solid fa-chevron-down arrow"></i>
        </button>
        <div class="dropdown-menu" id="dropTracking">
          <?php foreach ($attendance_items as $item): ?>
            <a href="<?= $item['url'] ?>" class="dropdown-item <?= ($ACTIVE_NAV==='attendance' && ($ACTIVE_SUB ?? '') === ($item['id'] ?? '')) ? 'active' : '' ?>"><?= $item['label'] ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- 9. Announcements (Campus-wide & Org) -->
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/announcements.php" class="sidebar-item <?= $ACTIVE_NAV==='announcements'?'active':'' ?>">
        <i class="fa-solid fa-bullhorn"></i>
        <span>Announcements</span>
      </a>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         INTELLIGENCE & REPORTS (Adviser, SSC, Admin)
    ══════════════════════════════════════════════════════════ -->
    <?php if ($user_role !== 'student'): ?>
    <div class="sidebar-divider"></div>
    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title"><?= $user_role === 'club_adviser' ? 'Reports & Analytics' : 'Analytics & Reports' ?></div>
      <div class="brand-sub"><?= $user_role === 'club_adviser' ? 'Organizational Insights' : 'Performance Insights' ?></div>
    </div>

    <!-- 10. Reports & Analytics -->
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/reports.php" class="sidebar-item <?= $ACTIVE_NAV==='reports'?'active':'' ?>">
        <i class="fa-solid fa-chart-column"></i>
        <span><?= $user_role === 'club_adviser' ? 'Organization Reports' : 'Reports & Analytics' ?></span>
      </a>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════
         ADMINISTRATION & AUDIT
    ══════════════════════════════════════════════════════════ -->
    <?php if ($user_role === 'ssc'): ?>
      <!-- SSC Officer Item 11: System Audit Logs (read-only) -->
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <div class="brand-title">Oversight</div>
        <div class="brand-sub">Audit Trail</div>
      </div>
      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/admin_system.php?tab=auditTab" class="sidebar-item <?= ($ACTIVE_NAV==='admin'||($ACTIVE_TAB??'')==='auditTab')?'active':'' ?>">
          <i class="fa-solid fa-list-check"></i>
          <span>System Audit Logs (Read-Only)</span>
        </a>
      </div>
    <?php elseif ($user_role === 'admin'): ?>
      <!-- System Admin Exclusive Items 10, 11, 12 -->
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <div class="brand-title">Administration</div>
        <div class="brand-sub">System &amp; Security</div>
      </div>

      <!-- 10. User & Access Management (Admin-exclusive) -->
      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/admin_system.php?tab=usersTab" class="sidebar-item <?= ($ACTIVE_NAV==='admin'&&($ACTIVE_TAB??'')==='usersTab')?'active':'' ?>">
          <i class="fa-solid fa-users-gear"></i>
          <span>User &amp; Access Control</span>
        </a>
      </div>

      <!-- 11. System Administration (Admin-exclusive) -->
      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/admin_system.php?tab=opsTab" class="sidebar-item <?= ($ACTIVE_NAV==='admin'&&($ACTIVE_TAB??'')==='opsTab')?'active':'' ?>">
          <i class="fa-solid fa-server"></i>
          <span>System Administration</span>
        </a>
      </div>

      <!-- 12. System Audit Logs (Full Access) -->
      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/admin_system.php?tab=auditTab" class="sidebar-item <?= ($ACTIVE_NAV==='admin'&&($ACTIVE_TAB??'')==='auditTab')?'active':'' ?>">
          <i class="fa-solid fa-list-check"></i>
          <span>System Audit Logs</span>
        </a>
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

