<?php
// ============================================================
//  EVENTS.PHP — Events & Activity Center
//  Full DB integration: real data, working modals, role-gated
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// ── Fetch events from DB ─────────────────────────────────────
$events = $conn->query(
    "SELECT e.id, e.title, e.description, e.event_date, e.venue,
            e.status, e.rejection_note, e.created_by,
            c.name AS club_name, c.code AS club_code,
            u.first_name, u.last_name
     FROM events e
     JOIN clubs c ON c.id = e.club_id
     LEFT JOIN users u ON u.id = e.created_by
     ORDER BY e.event_date ASC"
)->fetch_all(MYSQLI_ASSOC);

$clubs = $conn->query("SELECT id, name, code FROM clubs WHERE status='Active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// ── Calculate event statistics ────────────────────────────────
$total_approved = 0;
$total_pending  = 0;
$total_upcoming = 0;
$today_str      = date('Y-m-d');

foreach ($events as $ev) {
    if ($ev['status'] === 'Approved' || $ev['status'] === 'Completed') {
        $total_approved++;
    } elseif ($ev['status'] === 'Pending OSA') {
        $total_pending++;
    }
    if (substr($ev['event_date'], 0, 10) >= $today_str && $ev['status'] !== 'Rejected') {
        $total_upcoming++;
    }
}

// ── Fetch user's registered events ────────────────────────────
$user_id = (int)$_SESSION['user_id'];
$my_reg_ids = [];
$r_reg = $conn->query("SELECT event_id FROM event_registrations WHERE user_id = $user_id AND status = 'Registered'");
if ($r_reg) {
    while ($row = $r_reg->fetch_assoc()) {
        $my_reg_ids[] = (int)$row['event_id'];
    }
}

$status_badges = [
    'Approved'    => 'badge-active',
    'Completed'   => 'badge-info',
    'Upcoming'    => 'badge-info',
    'Pending OSA' => 'badge-warning',
    'Rejected'    => 'badge-inactive',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Events &amp; Activity Center – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
  /* ══ Calendar Card ══ */
  .calendar-section {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    border: 1px solid #e2e8f0;
    overflow: hidden;
    margin-bottom: 24px;
  }
  .calendar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 20px;
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    flex-wrap: wrap;
    gap: 10px;
  }
  .calendar-header-left h3 {
    font-size: 0.98rem;
    font-weight: 800;
    color: #fff;
    margin: 0 0 1px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .calendar-header-left p {
    font-size: 0.75rem;
    color: rgba(255,255,255,0.75);
    margin: 0;
  }
  .calendar-nav {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .cal-nav-btn {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    font-size: 0.82rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
  }
  .cal-nav-btn:hover {
    background: rgba(255,255,255,0.3);
  }
  .cal-month-label {
    font-size: 0.92rem;
    font-weight: 700;
    color: #fff;
    min-width: 130px;
    text-align: center;
  }
  .calendar-body {
    padding: 12px 16px;
  }
  .calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 4px;
  }
  .cal-day-header {
    text-align: center;
    font-size: 0.68rem;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 4px 2px;
  }
  .cal-day-cell {
    min-height: 70px;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    padding: 4px 5px 5px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    transition: border-color 0.15s, box-shadow 0.15s;
    cursor: pointer;
  }
  .cal-day-cell:hover:not(.other-month) {
    border-color: #93c5fd;
    box-shadow: 0 2px 8px rgba(37,99,235,0.1);
    background: #fff;
  }
  .cal-day-cell.other-month {
    opacity: 0.3;
    background: #f1f5f9;
    cursor: default;
    border-color: #e8ecf0;
  }
  .cal-day-cell.today {
    background: #eff6ff;
    border-color: #2563eb;
    border-width: 2px;
  }
  .cal-date-num {
    font-size: 0.75rem;
    font-weight: 700;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    margin-bottom: 1px;
  }
  .today-bubble {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #2563eb;
    color: #fff;
    font-size: 0.7rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-left: auto;
  }
  .cal-event-pill {
    font-size: 0.62rem;
    padding: 2px 5px;
    border-radius: 3px;
    color: #fff;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    cursor: pointer;
    transition: transform 0.12s, opacity 0.12s;
    line-height: 1.3;
  }
  .cal-event-pill:hover {
    transform: scale(1.03);
    opacity: 0.92;
  }
  .cal-pill-approved  { background: #16a34a; }
  .cal-pill-upcoming  { background: #2563eb; }
  .cal-pill-pending   { background: #d97706; }
  .cal-pill-completed { background: #64748b; }
  .calendar-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    padding: 8px 18px;
    border-top: 1px solid #f1f5f9;
    background: #f8fafc;
  }
  .legend-item {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.72rem;
    color: #64748b;
    font-weight: 600;
  }
  .legend-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }
  @media (max-width: 768px) {
    .calendar-grid { gap: 2px; }
    .cal-day-cell { min-height: 60px; padding: 4px; }
    .cal-day-header { font-size: 0.6rem; padding: 4px 2px; }
  }
  @media (max-width: 560px) {
    .calendar-body { padding: 12px; }
    .calendar-header { padding: 14px 16px; }
    .calendar-legend { padding: 10px 16px; gap: 10px; }
  }
  </style>
</head>
<body>
<?php $APP_ROOT = '../'; $ACTIVE_NAV = 'events'; require_once __DIR__ . '/../shared/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap">
        <input type="text" id="eventSearch" placeholder="Search events…" oninput="filterEventTable()"/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code" type="button"><i class="fa-solid fa-qrcode"></i></button>
      <a href="account.php" class="avatar" title="Account"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-calendar-days"></i> Events &amp; Activity Center</h2>
    </div>

    <div class="content-body">

      <!-- Stats Row -->
      <div class="info-row">
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-calendar-check"></i> Approved Events</div>
          <div class="card-amount"><?= $total_approved ?></div>
          <div class="card-detail">OSA-cleared activities</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-clock"></i> Pending OSA</div>
          <div class="card-amount"><?= $total_pending ?></div>
          <div class="card-detail">Awaiting sign-off</div>
        </div>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-calendar-days"></i> Upcoming Events</div>
          <div class="card-amount"><?= $total_upcoming ?></div>
          <div class="card-detail">Scheduled activities</div>
        </div>
        <?php if (in_array($sess_role, ['club_adviser','osa_director','admin'])): ?>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-plus"></i> New Proposal</div>
          <div class="card-detail" style="margin-top:6px;">
            <button class="card-btn" id="openCreateEvent">
              <i class="fa-solid fa-file-circle-plus"></i> Create Activity Proposal
            </button>
          </div>
        </div>
        <?php else: ?>
        <div class="info-card">
          <div class="card-label"><i class="fa-solid fa-building-circle-check"></i> Venue Checker</div>
          <div class="card-amount" style="font-size:0.95rem;">Real-time</div>
          <div class="card-detail">Room conflict verification</div>
        </div>
        <?php endif; ?>
      </div><!-- /info-row -->

      <!-- ══════════════════════════════════════════════════════════
           ACTIVE INTERACTIVE EVENT CALENDAR
      ══════════════════════════════════════════════════════════ -->
      <div class="calendar-section">
        <!-- Header -->
        <div class="calendar-header">
          <div class="calendar-header-left">
            <h3><i class="fa-solid fa-calendar-days"></i> Active Campus Event Calendar</h3>
            <p>Click any highlighted date or event pill to view details &amp; register</p>
          </div>
          <div class="calendar-nav">
            <button class="cal-nav-btn" id="calPrevBtn" title="Previous month">
              <i class="fa-solid fa-chevron-left"></i>
            </button>
            <span class="cal-month-label" id="calMonthTitle">August 2026</span>
            <button class="cal-nav-btn" id="calNextBtn" title="Next month">
              <i class="fa-solid fa-chevron-right"></i>
            </button>
          </div>
        </div>

        <!-- Grid -->
        <div class="calendar-body">
          <div class="calendar-grid" id="calendarGrid">
            <!-- Rendered by JS -->
          </div>
        </div>

        <!-- Legend -->
        <div class="calendar-legend">
          <div class="legend-item"><span class="legend-dot" style="background:#16a34a;"></span> Approved</div>
          <div class="legend-item"><span class="legend-dot" style="background:#2563eb;"></span> Upcoming</div>
          <div class="legend-item"><span class="legend-dot" style="background:#d97706;"></span> Pending OSA</div>
          <div class="legend-item"><span class="legend-dot" style="background:#64748b;"></span> Completed</div>
          <div class="legend-item"><i class="fa-solid fa-circle-check" style="color:#16a34a; font-size:0.85rem;"></i> You are registered</div>
        </div>
      </div><!-- /calendar-section -->

      <!-- Events Table -->
      <div class="table-card">
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
          <h3 style="margin:0;"><i class="fa-solid fa-list-check" style="color:#2563eb;"></i> Campus Event Calendar & Approval Pipeline</h3>
          <span style="font-size:0.78rem; color:#64748b;"><?= count($events) ?> total events</span>
        </div>
        <?php if (empty($events)): ?>
          <div style="text-align:center; padding:40px; color:#64748b;">
            <i class="fa-solid fa-calendar-xmark" style="font-size:2.5rem; margin-bottom:12px; display:block;"></i>
            No events yet. <?php if (in_array($sess_role, ['club_adviser','osa_director','admin'])): ?>
              <button class="card-btn" id="openCreateEvent2" style="margin-top:12px;">Create First Event</button>
            <?php endif; ?>
          </div>
        <?php else: ?>
        <table class="data-table" id="eventTable">
          <thead>
            <tr>
              <th>Event Title</th>
              <th>Host Organization</th>
              <th>Date & Time</th>
              <th>Venue</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($events as $ev): ?>
            <tr data-id="<?= $ev['id'] ?>">
              <td>
                <strong><?= htmlspecialchars($ev['title']) ?></strong>
                <?php if ($ev['rejection_note']): ?>
                  <div style="font-size:0.72rem; color:#ef4444; margin-top:2px;"><i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($ev['rejection_note']) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="club-badge"><?= htmlspecialchars($ev['club_code']) ?></span> <?= htmlspecialchars($ev['club_name']) ?></td>
              <td style="font-size:0.82rem;">
                <?= date('M d, Y', strtotime($ev['event_date'])) ?><br>
                <span style="color:#64748b;"><?= date('h:i A', strtotime($ev['event_date'])) ?></span>
              </td>
              <td style="font-size:0.82rem;"><?= htmlspecialchars($ev['venue']) ?></td>
              <td><span class="<?= $status_badges[$ev['status']] ?? 'badge-info' ?>"><?= htmlspecialchars($ev['status']) ?></span></td>
              <td>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                  <button class="card-btn btn-sm" onclick="viewEvent(<?= htmlspecialchars(json_encode($ev)) ?>)">
                    <i class="fa-solid fa-eye"></i> Details
                  </button>
                  <?php if (in_array($sess_role, ['osa_director','admin']) && $ev['status'] === 'Pending OSA'): ?>
                    <button class="card-btn btn-sm btn-success" onclick="approveEvent(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')">
                      <i class="fa-solid fa-check"></i> Approve
                    </button>
                    <button class="card-btn btn-sm btn-danger" onclick="rejectEvent(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['title'])) ?>')">
                      <i class="fa-solid fa-times"></i> Reject
                    </button>
                  <?php elseif ($sess_role === 'club_adviser' && in_array($ev['status'], ['Pending OSA','Rejected'])): ?>
                    <button class="card-btn btn-sm" onclick="editEvent(<?= htmlspecialchars(json_encode($ev)) ?>)">
                      <i class="fa-solid fa-edit"></i> Edit
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

    </div>
  </div>
  <div class="footer">Co-Curricular Management System &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ══════ CREATE EVENT MODAL ══════ -->
<?php if (in_array($sess_role, ['club_adviser','osa_director','admin'])): ?>
<div class="modal-overlay" id="createEventModal">
  <div class="modal-card" style="max-width:520px;">
    <div class="modal-header">
      <h3><i class="fa-solid fa-calendar-plus"></i> Create Activity Proposal</h3>
      <button class="notif-close" onclick="closeModal('createEventModal')">×</button>
    </div>
    <form id="createEventForm">
      <div class="form-group">
        <label>Event Title *</label>
        <input type="text" name="title" placeholder="e.g. Annual Hackathon 2026" required/>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" rows="3" placeholder="Brief description of the event…"></textarea>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
        <div class="form-group">
          <label>Event Date & Time *</label>
          <input type="datetime-local" name="event_date" required/>
        </div>
        <div class="form-group">
          <label>Venue *</label>
          <input type="text" name="venue" placeholder="e.g. Main Auditorium" required/>
        </div>
      </div>
      <?php if (in_array($sess_role, ['club_adviser','osa_director','admin'])): ?>
      <div class="form-group">
        <label>Host Organization</label>
        <select name="club_id">
          <?php foreach ($clubs as $cl): ?>
          <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="modal-actions">
        <button type="button" class="card-btn" style="background:#64748b;" onclick="closeModal('createEventModal')">Cancel</button>
        <button type="submit" class="card-btn" id="createEventBtn"><i class="fa-solid fa-paper-plane"></i> Submit Proposal</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ══════ VIEW EVENT MODAL ══════ -->
<div class="modal-overlay" id="viewEventModal">
  <div class="modal-card" style="max-width:520px;">
    <div class="modal-header">
      <h3><i class="fa-solid fa-calendar-check"></i> Event Details</h3>
      <button class="notif-close" onclick="closeModal('viewEventModal')">×</button>
    </div>
    <div id="viewEventBody" style="color:#475569; line-height:1.8;"></div>
    <div class="modal-actions" style="margin-top:16px;">
      <button class="card-btn" onclick="closeModal('viewEventModal')">Close</button>
    </div>
  </div>
</div>

<!-- ══════ EDIT EVENT MODAL ══════ -->
<?php if (in_array($sess_role, ['club_adviser','osa_director','admin'])): ?>
<div class="modal-overlay" id="editEventModal">
  <div class="modal-card" style="max-width:520px;">
    <div class="modal-header">
      <h3><i class="fa-solid fa-pen-to-square"></i> Edit Event Proposal</h3>
      <button class="notif-close" onclick="closeModal('editEventModal')">×</button>
    </div>
    <form id="editEventForm">
      <input type="hidden" name="id" id="editEventId"/>
      <div class="form-group">
        <label>Event Title *</label>
        <input type="text" name="title" id="editEventTitle" required/>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" id="editEventDesc" rows="3"></textarea>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
        <div class="form-group">
          <label>Event Date & Time *</label>
          <input type="datetime-local" name="event_date" id="editEventDate" required/>
        </div>
        <div class="form-group">
          <label>Venue *</label>
          <input type="text" name="venue" id="editEventVenue" required/>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="card-btn" style="background:#64748b;" onclick="closeModal('editEventModal')">Cancel</button>
        <button type="submit" class="card-btn"><i class="fa-solid fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ══════ REJECT EVENT MODAL ══════ -->
<?php if (in_array($sess_role, ['osa_director','admin'])): ?>
<div class="modal-overlay" id="rejectEventModal">
  <div class="modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-times-circle" style="color:#ef4444;"></i> Reject Event Proposal</h3>
      <button class="notif-close" onclick="closeModal('rejectEventModal')">×</button>
    </div>
    <p id="rejectEventDesc" style="color:#475569; margin-bottom:16px;"></p>
    <div class="form-group">
      <label>Reason for Rejection *</label>
      <textarea id="rejectEventNote" rows="3" placeholder="Specify reason…"></textarea>
    </div>
    <div class="modal-actions">
      <button class="card-btn" style="background:#64748b;" onclick="closeModal('rejectEventModal')">Cancel</button>
      <button class="card-btn btn-danger" id="confirmRejectEventBtn"><i class="fa-solid fa-times"></i> Reject Event</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div id="toast" class="toast-notification" style="display:none;"></div>
<script src="../js/dashboard.js"></script>
<script>
const ROLE = '<?= $sess_role ?>';
const ALL_EVENTS = <?= json_encode($events, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const MY_REG_IDS = <?= json_encode($my_reg_ids) ?>;

let currentDate = new Date();

function renderCalendar() {
  const year = currentDate.getFullYear();
  const month = currentDate.getMonth();

  const monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
  const titleEl = document.getElementById('calMonthTitle');
  if (titleEl) titleEl.textContent = `${monthNames[month]} ${year}`;

  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const daysInPrevMonth = new Date(year, month, 0).getDate();

  const grid = document.getElementById('calendarGrid');
  if (!grid) return;
  grid.innerHTML = '';

  // Day name headers
  ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(d => {
    const dh = document.createElement('div');
    dh.className = 'cal-day-header';
    dh.textContent = d;
    grid.appendChild(dh);
  });

  // Trailing days of previous month
  for (let i = firstDay - 1; i >= 0; i--) {
    const cell = document.createElement('div');
    cell.className = 'cal-day-cell other-month';
    cell.innerHTML = `<div class="cal-date-num">${daysInPrevMonth - i}</div>`;
    grid.appendChild(cell);
  }

  // Current month days
  const today = new Date();
  for (let d = 1; d <= daysInMonth; d++) {
    const isToday = (today.getFullYear() === year && today.getMonth() === month && today.getDate() === d);
    const cell = document.createElement('div');
    cell.className = `cal-day-cell${isToday ? ' today' : ''}`;

    const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    const dayEvents = ALL_EVENTS.filter(ev => ev.event_date.startsWith(dateStr));

    // Date number — use bubble highlight for today
    const dateNumEl = document.createElement('div');
    dateNumEl.className = 'cal-date-num';
    if (isToday) {
      dateNumEl.innerHTML = `<span class="today-bubble">${d}</span>`;
    } else {
      dateNumEl.textContent = d;
    }
    cell.appendChild(dateNumEl);

    // Event pills
    dayEvents.forEach(ev => {
      let pillClass = 'cal-pill-approved';
      if (ev.status === 'Pending OSA')  pillClass = 'cal-pill-pending';
      else if (ev.status === 'Completed') pillClass = 'cal-pill-completed';
      else if (ev.status === 'Upcoming')  pillClass = 'cal-pill-upcoming';

      const isReg = MY_REG_IDS.includes(parseInt(ev.id));
      const pill = document.createElement('div');
      pill.className = `cal-event-pill ${pillClass}`;
      pill.title = `${ev.title} — ${ev.club_code}`;
      pill.innerHTML = `${ev.club_code}: ${ev.title}${isReg ? ' <i class="fa-solid fa-circle-check"></i>' : ''}`;
      pill.addEventListener('click', e => { e.stopPropagation(); viewEventById(ev.id); });
      cell.appendChild(pill);
    });

    grid.appendChild(cell);
  }

  // Leading days of next month
  const totalCells = firstDay + daysInMonth;
  const nextPad = (7 - (totalCells % 7)) % 7;
  for (let i = 1; i <= nextPad; i++) {
    const cell = document.createElement('div');
    cell.className = 'cal-day-cell other-month';
    cell.innerHTML = `<div class="cal-date-num">${i}</div>`;
    grid.appendChild(cell);
  }
}

function viewEventById(id) {
  const ev = ALL_EVENTS.find(e => parseInt(e.id) === parseInt(id));
  if (ev) viewEvent(ev);
}

function registerForEvent(id) {
  const fd = new FormData();
  fd.append('action', 'register');
  fd.append('event_id', id);
  fetch('../shared/event_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showToast(res.message, 'success');
        closeModal('viewEventModal');
        setTimeout(() => location.reload(), 1500);
      } else {
        showToast(res.message, 'error');
      }
    })
    .catch(() => showToast('Network error.', 'error'));
}

document.getElementById('calPrevBtn')?.addEventListener('click', () => {
  currentDate.setMonth(currentDate.getMonth() - 1);
  renderCalendar();
});

document.getElementById('calNextBtn')?.addEventListener('click', () => {
  currentDate.setMonth(currentDate.getMonth() + 1);
  renderCalendar();
});

document.addEventListener('DOMContentLoaded', renderCalendar);

function openModal(id)  { document.getElementById(id)?.classList.add('active'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('active'); }

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = 'toast-notification ' + type;
  t.style.display = 'block'; setTimeout(() => t.style.display = 'none', 3500);
}

function filterEventTable() {
  const q = document.getElementById('eventSearch').value.toLowerCase();
  document.querySelectorAll('#eventTable tbody tr').forEach(tr => {
    tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

// View event details
function viewEvent(ev) {
  const isRegistered = MY_REG_IDS.includes(parseInt(ev.id));
  let regBtn = '';

  if (ev.status !== 'Rejected') {
    if (isRegistered) {
      regBtn = `
        <div style="margin-top:14px; padding:12px 16px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; display:flex; align-items:center; justify-content:space-between;">
          <span style="color:#166534; font-weight:700; font-size:0.85rem;"><i class="fa-solid fa-circle-check"></i> Registered for this Event</span>
          <span class="badge-active">Confirmed</span>
        </div>`;
    } else {
      regBtn = `
        <div style="margin-top:14px; text-align:right;">
          <button class="card-btn" style="background:#2563eb; color:#fff; padding:10px 20px;" onclick="registerForEvent(${ev.id})">
            <i class="fa-solid fa-user-plus"></i> Register for Event / Activity
          </button>
        </div>`;
    }
  }

  document.getElementById('viewEventBody').innerHTML = `
    <div style="display:flex; flex-direction:column; gap:10px;">
      <div><span style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase;">Event Title</span>
      <h3 style="margin:2px 0 0; color:#1a1a2e;">${ev.title}</h3></div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0;">
        <div><strong>Host Org:</strong><br>${ev.club_name} (${ev.club_code})</div>
        <div><strong>Status:</strong><br><span class="${ev.status==='Approved'?'badge-active':'badge-warning'}">${ev.status}</span></div>
        <div><strong>Date & Time:</strong><br>${new Date(ev.event_date.replace(' ','T')).toLocaleString('en-PH', {dateStyle:'medium',timeStyle:'short'})}</div>
        <div><strong>Venue:</strong><br>${ev.venue}</div>
      </div>
      <div><strong>Description:</strong><p style="margin:4px 0 0; font-size:0.88rem; line-height:1.6;">${ev.description || 'No description provided.'}</p></div>
      ${ev.rejection_note ? `<div style="color:#ef4444; background:#fef2f2; padding:10px; border-radius:8px;"><strong>Rejection Note:</strong> ${ev.rejection_note}</div>` : ''}
      ${regBtn}
    </div>
  `;
  openModal('viewEventModal');
}

// Edit event
function editEvent(ev) {
  document.getElementById('editEventId').value    = ev.id;
  document.getElementById('editEventTitle').value  = ev.title;
  document.getElementById('editEventDesc').value   = ev.description || '';
  document.getElementById('editEventVenue').value  = ev.venue;
  // Convert to datetime-local format
  const dt = new Date(ev.event_date);
  dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset());
  document.getElementById('editEventDate').value   = dt.toISOString().slice(0,16);
  openModal('editEventModal');
}

// Approve event
function approveEvent(id, title) {
  if (!confirm(`Approve event: "${title}"?`)) return;
  const fd = new FormData();
  fd.append('action', 'approve'); fd.append('id', id);
  fetch('../shared/event_actions.php', { method:'POST', body:fd })
    .then(r => r.json()).then(res => {
      if (res.success) { showToast('Event approved!'); setTimeout(() => location.reload(), 1500); }
      else showToast(res.message, 'error');
    });
}

// Reject event
let rejectEvId = 0;
function rejectEvent(id, title) {
  rejectEvId = id;
  document.getElementById('rejectEventDesc').textContent = `Reject proposal: "${title}"`;
  document.getElementById('rejectEventNote').value = '';
  openModal('rejectEventModal');
}

document.getElementById('confirmRejectEventBtn')?.addEventListener('click', async () => {
  const note = document.getElementById('rejectEventNote').value.trim();
  if (!note) { alert('Please provide a reason.'); return; }
  const fd = new FormData();
  fd.append('action','reject'); fd.append('id', rejectEvId); fd.append('note', note);
  const res = await fetch('../shared/event_actions.php', { method:'POST', body:fd }).then(r => r.json());
  closeModal('rejectEventModal');
  if (res.success) { showToast('Event rejected.', 'warning'); setTimeout(() => location.reload(), 1500); }
  else showToast(res.message, 'error');
});

// Create event
['openCreateEvent','openCreateEvent2'].forEach(id => {
  document.getElementById(id)?.addEventListener('click', () => openModal('createEventModal'));
});

document.getElementById('createEventForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = document.getElementById('createEventBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting…';
  const fd = new FormData(e.target); fd.append('action','create');
  const res = await fetch('../shared/event_actions.php', { method:'POST', body:fd }).then(r => r.json());
  btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Proposal';
  if (res.success) {
    closeModal('createEventModal');
    showToast('Event proposal submitted!');
    setTimeout(() => location.reload(), 1500);
  } else showToast(res.message, 'error');
});

// Edit event form
document.getElementById('editEventForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target); fd.append('action','edit');
  const res = await fetch('../shared/event_actions.php', { method:'POST', body:fd }).then(r => r.json());
  closeModal('editEventModal');
  if (res.success) { showToast('Event updated!'); setTimeout(() => location.reload(), 1500); }
  else showToast(res.message, 'error');
});
</script>
</body>
</html>
