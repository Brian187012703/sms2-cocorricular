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
?>

<!-- Global Screen-Centered Modern Notification & Decision Engine -->
<script src="<?= $APP_ROOT ?>js/system-notifications.js?v=<?= filemtime(__DIR__ . '/../js/system-notifications.js') ?>"></script>

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
         PERMISSION-DRIVEN DYNAMIC NAVIGATION
         Enforces granular RBAC keys loaded from MySQL
    ══════════════════════════════════════════════════════════ -->

    <!-- SECTION 1: DASHBOARD / OVERVIEW -->
    <?php if (can_any(['dashboard.view.system', 'dashboard.view.institutional', 'dashboard.view.org', 'dashboard.view.own'])): ?>
      <div class="sidebar-brand sidebar-brand-2">
        <?php if (can('dashboard.view.system')): ?>
          <div class="brand-title">Control Center</div>
          <div class="brand-sub">Identity &amp; Master Data</div>
        <?php elseif (can('dashboard.view.institutional')): ?>
          <div class="brand-title">Executive Core</div>
          <div class="brand-sub">Council Oversight</div>
        <?php else: ?>
          <div class="brand-title">Core Navigation</div>
          <div class="brand-sub">General &amp; Overview</div>
        <?php endif; ?>
      </div>

      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" class="sidebar-item <?= $ACTIVE_NAV==='dashboard'?'active':'' ?>">
          <i class="fa-solid <?= can('dashboard.view.system') ? 'fa-gauge-high' : 'fa-gauge' ?>"></i>
          <span>
            <?= can('dashboard.view.system') ? 'Dashboard' : (can('dashboard.view.institutional') ? 'Executive Dashboard' : 'Dashboard') ?>
          </span>
        </a>
      </div>
    <?php endif; ?>

    <!-- SECTION 2: MASTER DATA & DIRECTORY -->
    <?php if (can_any(['users.manage.all', 'organization.crud', 'master_data.academic', 'organization.review.all', 'organization.view'])): ?>
      <!-- User & Access Management -->
      <?php if (can('users.manage.all')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/admin_system.php" class="sidebar-item <?= in_array($ACTIVE_NAV, ['admin', 'admin_users']) ? 'active' : '' ?>">
            <i class="fa-solid fa-users-gear"></i>
            <span>User &amp; Access Management</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- Organization Master Data (Admin) -->
      <?php if (can('organization.crud')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/admin_org_master.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_org_master') ? 'active' : '' ?>">
            <i class="fa-solid fa-sitemap"></i>
            <span>Organization Master Data</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- Academic Master Data (Admin) -->
      <?php if (can('master_data.academic')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/admin_academics.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_academics') ? 'active' : '' ?>">
            <i class="fa-solid fa-graduation-cap"></i>
            <span>Academic Master Data</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- Organization Directory / Governance (SSC / Student) -->
      <?php if (!can('organization.crud') && can('organization.view')): ?>
        <?php if (can('organization.review.all')): ?>
          <!-- SSC Governance -->
          <div class="nav-group">
            <a href="<?= $APP_ROOT ?>dashboard/club_directory.php" class="sidebar-item <?= $ACTIVE_NAV==='clubs'?'active':'' ?>">
              <i class="fa-solid fa-sitemap"></i>
              <span>Organization Governance</span>
            </a>
          </div>
        <?php elseif (!can('organization.manage.own')): ?>
          <!-- Student Directory -->
          <div class="nav-group">
            <a href="<?= $APP_ROOT ?>dashboard/club_directory.php" class="sidebar-item <?= $ACTIVE_NAV==='clubs'?'active':'' ?>">
              <i class="fa-solid fa-sitemap"></i>
              <span>Organization Directory</span>
            </a>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>

    <!-- SECTION 3: WORKFLOW ADMINISTRATION & GOVERNANCE -->
    <?php if (can_any([
        'membership.review.all', 'membership.review.own',
        'events.approve.admin', 'events.review.ssc', 'events.create.own', 'events.create.institutional', 'events.view',
        'budget.disburse.admin', 'budget.review.ssc', 'budget.endorse.adviser', 'budget.create.own', 'budget.view.all',
        'elections.admin', 'elections.oversight', 'elections.manage.org', 'elections.vote'
    ])): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <?php if (can('events.approve.admin')): ?>
          <div class="brand-title">Workflow Administration</div>
          <div class="brand-sub">Approvals &amp; Pipeline</div>
        <?php elseif (can('events.review.ssc')): ?>
          <div class="brand-title">Governance</div>
          <div class="brand-sub">Endorsements &amp; Voting</div>
        <?php elseif (can('events.create.own')): ?>
          <div class="brand-title">Governance</div>
          <div class="brand-sub">Roster &amp; Elections</div>
        <?php else: ?>
          <div class="brand-title">Governance</div>
          <div class="brand-sub">Events &amp; Elections</div>
        <?php endif; ?>
      </div>

      <!-- Roster Management / Oversight -->
      <?php if (can_any(['membership.review.all', 'membership.review.own'])): ?>
        <?php
          $is_roster_active = ($ACTIVE_NAV === 'roster');
          $roster_sub = $ACTIVE_SUB ?? ($_GET['view'] ?? 'queue');
          $roster_label = can('membership.review.all') ? 'Roster Oversight' : 'Roster Management';
        ?>
        <div class="nav-group">
          <button class="sidebar-item <?= $is_roster_active ? 'active open' : '' ?> dropdown-trigger" data-target="dropRoster">
            <i class="fa-solid fa-users"></i>
            <span><?= $roster_label ?></span>
            <i class="fa-solid fa-chevron-down arrow"></i>
          </button>
          <div class="dropdown-menu <?= $is_roster_active ? 'open' : '' ?>" id="dropRoster">
            <a href="<?= $APP_ROOT ?>dashboard/roster.php?view=queue" class="dropdown-item <?= ($is_roster_active && $roster_sub === 'queue') ? 'active' : '' ?>">Application Queue</a>
            <a href="<?= $APP_ROOT ?>dashboard/roster.php?view=roster" class="dropdown-item <?= ($is_roster_active && $roster_sub === 'roster') ? 'active' : '' ?>">Member Roster</a>
          </div>
        </div>
      <?php endif; ?>

      <!-- Events -->
      <?php if ($user_role === 'student'): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/events.php?view=calendar" class="sidebar-item <?= ($ACTIVE_NAV === 'events') ? 'active' : '' ?>">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Events</span>
          </a>
        </div>
      <?php elseif (can_any(['events.approve.admin', 'events.review.ssc', 'events.create.institutional', 'events.create.own', 'events.view'])): ?>
        <?php
          $is_events_active = ($ACTIVE_NAV === 'events');
          $events_sub = $ACTIVE_SUB ?? ($_GET['view'] ?? 'calendar');
        ?>
        <div class="nav-group">
          <button class="sidebar-item <?= $is_events_active ? 'active open' : '' ?> dropdown-trigger" data-target="dropEvents">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Events</span>
            <i class="fa-solid fa-chevron-down arrow"></i>
          </button>
          <div class="dropdown-menu <?= $is_events_active ? 'open' : '' ?>" id="dropEvents">
            <a href="<?= $APP_ROOT ?>dashboard/events.php?view=calendar" class="dropdown-item <?= ($is_events_active && $events_sub === 'calendar') ? 'active' : '' ?>">Active Calendar</a>
            <a href="<?= $APP_ROOT ?>dashboard/events.php?view=pipeline" class="dropdown-item <?= ($is_events_active && $events_sub === 'pipeline') ? 'active' : '' ?>">Event Proposals &amp; Pipeline</a>
          </div>
        </div>
      <?php endif; ?>

      <!-- Budget & Finance / Disbursement -->
      <?php if (can('budget.disburse.admin')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/budget.php" class="sidebar-item <?= ($ACTIVE_NAV==='budget')?'active':'' ?>">
            <i class="fa-solid fa-money-bill-transfer"></i>
            <span>Budget &amp; Disbursement</span>
          </a>
        </div>
      <?php elseif (can_any(['budget.review.ssc', 'budget.endorse.adviser', 'budget.create.own', 'budget.view.all'])): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/budget.php" class="sidebar-item <?= ($ACTIVE_NAV==='budget')?'active':'' ?>">
            <i class="fa-solid fa-hand-holding-dollar"></i>
            <span>Budget &amp; Finance</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- Elections -->
      <?php if (can('elections.admin')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/elections.php" class="sidebar-item <?= ($ACTIVE_NAV==='elections')?'active':'' ?>">
            <i class="fa-solid fa-check-to-slot"></i>
            <span>Election Administration</span>
          </a>
        </div>
      <?php elseif (can('elections.oversight')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/elections.php" class="sidebar-item <?= ($ACTIVE_NAV==='elections')?'active':'' ?>">
            <i class="fa-solid fa-check-to-slot"></i>
            <span>Elections &amp; Governance</span>
          </a>
        </div>
      <?php elseif (can_any(['elections.manage.org', 'elections.vote'])): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/elections.php" class="sidebar-item <?= ($ACTIVE_NAV==='elections')?'active':'' ?>">
            <i class="fa-solid fa-check-to-slot"></i>
            <span>Elections &amp; Voting</span>
          </a>
        </div>
      <?php endif; ?>

    <?php endif; ?>

    <!-- SECTION 4: RECORDS & TRACKING (Higher roles only) -->
    <?php if ($user_role !== 'student' && can_any(['achievements.view', 'achievements.submit', 'achievements.verify.ssc', 'attendance.view.analytics', 'attendance.track.org', 'attendance.override'])): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <div class="brand-title">Records &amp; Media</div>
        <div class="brand-sub">Validation &amp; Attendance</div>
      </div>

      <!-- Achievements & Awards -->
      <?php if (can_any(['achievements.view', 'achievements.submit', 'achievements.verify.ssc'])): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/achievements.php" class="sidebar-item <?= $ACTIVE_NAV==='achievements'?'active':'' ?>">
            <i class="fa-solid fa-award"></i>
            <span>Achievements &amp; Awards</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- Attendance Portal for Admin, SSC, Club Adviser -->
      <?php if ($user_role !== 'student'): ?>
        <?php
          if ($user_role === 'admin') {
              $attendance_items = [
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php', 'label' => 'All Attendance', 'id' => 'all'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php#sessions', 'label' => 'QR Sessions', 'id' => 'sessions'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_qr_generator.php', 'label' => 'Live QR Sessions', 'id' => 'generator'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php#attempts', 'label' => 'Scan Attempts &amp; Audit', 'id' => 'attempts'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_scanner.php', 'label' => 'Scanner Terminal', 'id' => 'scanner'],
              ];
          } elseif ($user_role === 'ssc') {
              $attendance_items = [
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php', 'label' => 'Attendance Monitor', 'id' => 'monitor'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_qr_generator.php', 'label' => 'Live QR Sessions', 'id' => 'generator'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php#attempts', 'label' => 'Scan Attempts', 'id' => 'attempts'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php#records', 'label' => 'Verification Records', 'id' => 'records']
              ];
          } else {
              // Club Adviser
              $attendance_items = [
                  ['url' => $APP_ROOT . 'dashboard/tracking_qr_generator.php', 'label' => 'Live Attendance &amp; QR', 'id' => 'generator'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_attendance_list.php', 'label' => 'Attendance Records', 'id' => 'attendance_list'],
                  ['url' => $APP_ROOT . 'dashboard/tracking_scanner.php', 'label' => 'Scanner Terminal', 'id' => 'scanner'],
              ];
          }
        ?>
        <div class="nav-group">
          <button class="sidebar-item <?= $ACTIVE_NAV==='attendance'?'active':'' ?> dropdown-trigger" data-target="dropTracking">
            <i class="fa-solid fa-qrcode"></i>
            <span>Attendance Portal</span>
            <i class="fa-solid fa-chevron-down arrow"></i>
          </button>
          <div class="dropdown-menu" id="dropTracking">
            <?php foreach ($attendance_items as $item): ?>
              <a href="<?= $item['url'] ?>" class="dropdown-item <?= ($ACTIVE_NAV==='attendance' && ($ACTIVE_SUB ?? '') === ($item['id'] ?? '')) ? 'active' : '' ?>"><?= $item['label'] ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- SECTION 5: COMMUNICATION -->
    <?php if (can_any(['announcements.manage.all', 'announcements.create.council', 'announcements.create.org'])): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <div class="brand-title">Communication</div>
        <div class="brand-sub">Broadcasts &amp; Notices</div>
      </div>

      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/announcements.php" class="sidebar-item <?= ($ACTIVE_NAV==='announcements')?'active':'' ?>">
          <i class="fa-solid fa-bullhorn"></i>
          <span>
            <?= can('announcements.manage.all') ? 'Announcement Administration' : (can('announcements.create.council') ? 'Council Announcements' : 'Announcements') ?>
          </span>
        </a>
      </div>
    <?php endif; ?>

    <!-- SECTION 6: ANALYTICS & REPORTS -->
    <?php if (can_any(['reports.view.system', 'reports.view.institutional', 'reports.view.org'])): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <div class="brand-title">Analytics</div>
        <div class="brand-sub">Intelligence &amp; Data</div>
      </div>

      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/reports.php" class="sidebar-item <?= ($ACTIVE_NAV==='reports')?'active':'' ?>">
          <i class="fa-solid fa-chart-column"></i>
          <span>
            <?= can('reports.view.system') ? 'System Reports' : (can('reports.view.institutional') ? 'Reports &amp; Analytics' : 'Organization Reports') ?>
          </span>
        </a>
      </div>
    <?php endif; ?>

    <!-- SECTION 7: SECURITY & AUDIT (Admin only) -->
    <?php if ($sess_role === 'admin'): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <div class="brand-title">Security</div>
        <div class="brand-sub">Logs &amp; Monitoring</div>
      </div>

      <!-- Audit Logs -->
      <?php if (can('audit.manage.full')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/admin_audit.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_audit') ? 'active' : '' ?>">
            <i class="fa-solid fa-list-check"></i>
            <span>Audit Logs</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- Security Monitoring -->
      <?php if (can('audit.manage.full')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/admin_security.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_security') ? 'active' : '' ?>">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Security Monitoring</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- System Health -->
      <?php if (can('system.health')): ?>
        <div class="nav-group">
          <a href="<?= $APP_ROOT ?>dashboard/admin_health.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_health') ? 'active' : '' ?>">
            <i class="fa-solid fa-heart-pulse"></i>
            <span>System Health</span>
          </a>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- SECTION 8: CONFIGURATION -->
    <?php if (can('settings.manage')): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-brand sidebar-brand-2">
        <div class="brand-title">Configuration</div>
        <div class="brand-sub">System Setup</div>
      </div>

      <!-- System Settings -->
      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/admin_settings.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_settings') ? 'active' : '' ?>">
          <i class="fa-solid fa-sliders"></i>
          <span>System Settings</span>
        </a>
      </div>

      <!-- Notification Settings -->
      <div class="nav-group">
        <a href="<?= $APP_ROOT ?>dashboard/admin_notifications.php" class="sidebar-item <?= ($ACTIVE_NAV === 'admin_notifications') ? 'active' : '' ?>">
          <i class="fa-solid fa-bell"></i>
          <span>Notification Settings</span>
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

  function openPanel() { 
    panel.style.display = '';
    overlay.style.display = '';
    panel.classList.add('open', 'active'); 
    overlay.classList.add('active', 'open'); 
    loadNotifs(); 
  }
  function closePanel() { 
    panel.classList.remove('open', 'active'); 
    overlay.classList.remove('active', 'open'); 
  }

  function togglePanel(e) {
    if (e) {
      e.stopPropagation();
      e.preventDefault();
    }
    const isOpen = panel.classList.contains('open') || panel.classList.contains('active');
    if (isOpen) {
      closePanel();
    } else {
      openPanel();
    }
  }

  window.openNotifPanel  = openPanel;
  window.closeNotifPanel = closePanel;
  window.loadLiveNotifs  = loadNotifs;

  bellBtn?.addEventListener('click', togglePanel);
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
<?php require_once __DIR__ . '/chat_modal.php'; ?>
<?php require_once __DIR__ . '/session_timeout_modal.php'; ?>
<script src="<?= $APP_ROOT ?>js/global-search.js?v=<?= filemtime(__DIR__ . '/../js/global-search.js') ?>" defer></script>

