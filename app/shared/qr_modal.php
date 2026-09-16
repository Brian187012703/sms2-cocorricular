<?php
// ============================================================
//  QR_MODAL.PHP (shared/)
//  Dual-Tab QR Modal: My QR Code + Live Camera Scanner
//  Scanner sends attendance to attendance_actions.php via AJAX
// ============================================================
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$app_root_rel = $APP_ROOT ?? '../';
$qr_user_id   = (int)($_SESSION['user_id'] ?? 0);
$qr_role      = $_SESSION['role'] ?? 'student';
$qr_username  = $_SESSION['username'] ?? '';
$qr_first     = htmlspecialchars($_SESSION['first_name'] ?? 'User');
$qr_last      = htmlspecialchars($_SESSION['last_name']  ?? '');

if (!isset($conn) || !($conn instanceof mysqli) || !@$conn->ping()) {
    require_once __DIR__ . '/db.php';
}

// Student details lookup for unique student number
$qr_student_info = null;
if (isset($conn) && $conn instanceof mysqli && $qr_user_id > 0) {
    if ($qr_role === 'student') {
        $st_stmt = $conn->prepare("SELECT student_number, course, year_level, section FROM students WHERE user_id = ? OR (first_name = ? AND last_name = ?) LIMIT 1");
        if ($st_stmt) {
            $f = $_SESSION['first_name'] ?? '';
            $l = $_SESSION['last_name'] ?? '';
            $st_stmt->bind_param('iss', $qr_user_id, $f, $l);
            $st_stmt->execute();
            $qr_student_info = $st_stmt->get_result()->fetch_assoc();
            $st_stmt->close();
        }
    }
}

$qr_student_num = $qr_student_info['student_number'] ?? '';
$qr_course      = $qr_student_info['course'] ?? '';
$qr_year_sec    = trim(($qr_student_info['year_level'] ?? '') . ' ' . (!empty($qr_student_info['section']) ? '(' . $qr_student_info['section'] . ')' : ''));

if ($qr_role === 'student') {
    $qr_code_str = !empty($qr_student_num) ? ('BCP-STUDENT-' . $qr_student_num) : ('BCP-STUDENT-' . $qr_user_id);
} else {
    $qr_code_str = 'BCP-STAFF-' . (!empty($qr_username) ? $qr_username : $qr_user_id);
}

$qr_role_lbl  = match($qr_role) {
    'admin'        => 'System Administrator',
    'ssc'          => 'Supreme Student Council Officer',
    'club_adviser' => 'Organization Adviser (Faculty)',
    default        => 'General Student',
};

// Fetch active events for scanner tab & event posters
$scan_events = [];
if (isset($conn) && $conn instanceof mysqli) {
    $ev_res = $conn->query("SELECT e.id, e.title, e.event_date, e.venue, c.name as club_name, c.code as club_code
                            FROM events e
                            LEFT JOIN clubs c ON c.id = e.club_id
                            WHERE e.status IN ('Approved','Upcoming')
                            ORDER BY e.event_date ASC LIMIT 30");
    if ($ev_res) $scan_events = $ev_res->fetch_all(MYSQLI_ASSOC);
}

// Fetch all enrolled students so Staff/Adviser/Admin can view any student's unique QR code
$all_students_list = [];
if (isset($conn) && $conn instanceof mysqli) {
    $st_res = $conn->query("
        SELECT u.id as user_id, u.username, u.first_name, u.last_name, u.email,
               s.student_number, s.course, s.year_level, s.section
        FROM users u
        LEFT JOIN students s ON (s.user_id = u.id OR (u.first_name = s.first_name AND u.last_name = s.last_name))
        WHERE u.role = 'student'
        ORDER BY s.student_number, u.last_name, u.first_name
    ");
    if ($st_res) {
        $all_students_list = $st_res->fetch_all(MYSQLI_ASSOC);
    }
}
?>

<!-- ── QR Code Center Modal ── -->
<div class="qr-modal-overlay" id="qrModalOverlay">
  <div class="qr-modal-card">
    <!-- Modal Header -->
    <div class="qr-modal-header">
      <div class="qr-modal-title">
        <i class="fa-solid fa-qrcode"></i>
        <span>QR Code Center</span>
      </div>
      <button class="notif-close" id="closeQrModalBtn" title="Close" type="button" aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <!-- Segmented Tabs Wrapper -->
    <div class="qr-tabs-wrapper">
      <div class="qr-modal-tabs">
        <button class="qr-tab-btn active" id="tabMyQr" type="button" onclick="switchGlobalQrTab('myqr')">
          <i class="fa-solid fa-id-card"></i> My QR Code
        </button>
        <button class="qr-tab-btn" id="tabScan" type="button" onclick="switchGlobalQrTab('scan')">
          <i class="fa-solid fa-camera"></i> <?php echo in_array($qr_role, ['club_adviser','ssc','admin']) ? 'Attendance Scanner' : 'Event Check-In'; ?>
        </button>
        <?php if (in_array($qr_role, ['club_adviser','ssc','admin'])): ?>
        <button class="qr-tab-btn" id="tabEventPoster" type="button" onclick="switchGlobalQrTab('eventposter')">
          <i class="fa-solid fa-calendar-check"></i> Event QR Posters
        </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Tab 1: My QR Code / Student Badge Viewer -->
    <div class="qr-modal-body qr-tab-panel active" id="panelMyQr">
      <?php if (!empty($all_students_list) && in_array($qr_role, ['club_adviser', 'ssc', 'admin'])): ?>
      <!-- Staff Student Badge Selector -->
      <div style="margin-bottom:14px; text-align:left; background:#f8fafc; padding:10px 14px; border-radius:12px; border:1px solid #e2e8f0;">
        <label for="qrStudentPickerSelect" style="display:block; font-size:0.75rem; font-weight:700; color:#334155; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:4px;">
          <i class="fa-solid fa-users" style="color:#2563eb;"></i> Select Student Badge to View:
        </label>
        <select id="qrStudentPickerSelect" class="form-control" onchange="onQrStudentPickerChange(this.value)" style="width:100%; height:38px; padding:0 10px; border-radius:8px; border:1.5px solid #cbd5e1; font-size:0.84rem; font-weight:600; background:#fff; color:#0f172a; outline:none;">
          <option value="self">-- My Personal Staff Badge (<?= htmlspecialchars($qr_first . ' ' . $qr_last) ?>) --</option>
          <optgroup label="Enrolled Students (16 Academic Programs)">
            <?php foreach ($all_students_list as $st): ?>
              <?php
                $sNum = $st['student_number'] ?: ('ID#' . $st['user_id']);
                $sProg = $st['course'] ?: 'Program';
              ?>
              <option value="<?= htmlspecialchars($st['user_id']) ?>"
                      data-num="<?= htmlspecialchars($st['student_number'] ?? '') ?>"
                      data-name="<?= htmlspecialchars($st['first_name'] . ' ' . $st['last_name']) ?>"
                      data-course="<?= htmlspecialchars($st['course'] ?? '') ?>"
                      data-year="<?= htmlspecialchars($st['year_level'] ?? '') ?>"
                      data-sec="<?= htmlspecialchars($st['section'] ?? '') ?>"
                      data-uname="<?= htmlspecialchars($st['username'] ?? '') ?>">
                <?= htmlspecialchars($st['first_name'] . ' ' . $st['last_name']) ?> — <?= htmlspecialchars($sNum) ?> (<?= htmlspecialchars($sProg) ?>)
              </option>
            <?php endforeach; ?>
          </optgroup>
        </select>
      </div>
      <?php endif; ?>

      <div class="qr-badge-role" id="qrBadgeRoleBox">
        <i class="fa-solid fa-id-badge"></i>
        <span id="qrBadgeRoleText"><?= htmlspecialchars($qr_role_lbl) ?></span>
      </div>
      <div class="qr-code-frame" id="qrCodeFrame">
        <div class="qr-scan-line"></div>
        <div id="myQrCanvasBox" class="qr-canvas-box" style="width:200px; height:200px; display:flex; align-items:center; justify-content:center; margin:auto; overflow:hidden;"></div>
      </div>
      <div class="qr-student-name" id="qrStudentNameText"><?= $qr_first . ' ' . $qr_last ?></div>
      <div id="qrStudentNumBox" style="font-size:0.86rem; font-weight:700; color:#2563eb; margin:2px 0; <?= empty($qr_student_num) ? 'display:none;' : '' ?>">
        <i class="fa-solid fa-id-card-clip"></i> Student Number: <span id="qrStudentNumText"><?= htmlspecialchars($qr_student_num) ?></span>
      </div>
      <div id="qrCourseBox" style="font-size:0.78rem; color:#64748b; margin-bottom:4px;">
        <?= htmlspecialchars($qr_course . ' ' . $qr_year_sec) ?>
      </div>
      <div class="qr-student-id">Unique QR ID: <strong id="qrPayloadText"><?= htmlspecialchars($qr_code_str) ?></strong></div>
      <p class="qr-subtext" id="qrSubtextLine">Present this unique QR code at any event scanning terminal for instant attendance check-in.</p>
      <div style="display:flex; justify-content:center; gap:8px; margin-top:14px; flex-wrap:wrap;">
        <button type="button" onclick="downloadQR()" class="btn-download-qr" id="btnDownloadQrBadge" style="margin:0;">
          <i class="fa-solid fa-download"></i> Download QR Badge
        </button>
        <button type="button" onclick="printStudentBadge()" class="card-btn" id="btnPrintQrBadge" style="background:#1e3a8a; color:#ffffff; font-weight:700; padding:10px 18px; border-radius:10px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-size:0.85rem;">
          <i class="fa-solid fa-print"></i> Print Official Badge
        </button>
      </div>
    </div>

    <!-- Tab 2: Camera Scanner (All roles) -->
    <div class="qr-modal-body qr-tab-panel" id="panelScan">

      <?php if (in_array($qr_role, ['club_adviser','ssc','admin'])): ?>
      <!-- Staff Event Selector -->
      <div class="qr-select-wrapper">
        <label class="qr-select-label">
          <i class="fa-solid fa-calendar-days"></i> Select Active Event
        </label>
        <select id="scanEventSelect" class="qr-select-input">
          <option value="">— Choose an event —</option>
          <?php foreach ($scan_events as $se): ?>
            <option value="<?= $se['id'] ?>"><?= htmlspecialchars($se['title']) ?> (<?= date('M d', strtotime($se['event_date'])) ?>)</option>
          <?php endforeach; ?>
          <?php if (empty($scan_events)): ?>
            <option value="" disabled>No active events available</option>
          <?php endif; ?>
        </select>
      </div>
      <?php else: ?>
      <!-- Student Event Check-in Banner -->
      <div class="qr-info-banner">
        <div class="qr-banner-icon"><i class="fa-solid fa-circle-info"></i></div>
        <div class="qr-banner-text">
          <strong>Event Self Check-In</strong>
          <span>Point camera at the <code>BCP-EVENT-{id}</code> QR code posted at the venue.</span>
        </div>
      </div>
      <?php endif; ?>

      <!-- Camera Viewport -->
      <div class="qr-camera-wrap" id="qrCameraWrap" style="cursor:pointer;" title="Camera Viewport (Drop image or click Scan Image)">
        <div id="html5GlobalQrReader" style="display:none;width:100%;height:100%;object-fit:cover;border-radius:12px;overflow:hidden;"></div>
        <video id="qrGlobalVideo" autoplay playsinline muted style="display:none;width:100%;height:100%;object-fit:cover;border-radius:12px;"></video>
        <canvas id="qrGlobalCanvas" style="display:none;"></canvas>
        <div class="qr-camera-overlay"></div>
        <div class="qr-scanner-line" id="qrGlobalScannerLine" style="display:none;"></div>
        <div class="qr-camera-placeholder" id="cameraGlobalPlaceholder">
          <i class="fa-solid fa-camera"></i>
          <span>Camera scanner inactive</span>
        </div>
      </div>

      <!-- Dedicated hidden element for file scanning (prevents DOM collision) -->
      <div id="html5GlobalQrReaderFile" style="display:none; width:1px; height:1px;"></div>

      <!-- Scan Result -->
      <div class="qr-scanner-result" id="qrGlobalScanResult" style="display:none;">
        <i class="fa-solid fa-circle-check" id="qrResultIcon"></i>
        <span id="qrGlobalScanText">Ready to scan...</span>
      </div>

      <!-- Controls & Image Upload -->
      <div style="display:flex; gap:8px; width:100%; margin-bottom:8px;">
        <button class="qr-scan-start-btn" id="startGlobalScanBtn" type="button" style="flex:1; margin-top:0;">
          <i class="fa-solid fa-camera"></i> Start Scanner
        </button>
        <button class="qr-scan-start-btn" id="globalUploadQrBtn" type="button" style="width:auto; padding:0 14px; margin-top:0; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1;" title="Upload QR badge image file">
          <i class="fa-solid fa-image"></i>
        </button>
        <input type="file" id="globalQrFileInput" accept="image/*" style="display:none;" />
      </div>

      <!-- Manual Code Entry Row -->
      <div style="display:flex; gap:6px; width:100%; margin-bottom:10px;">
        <div style="position:relative; flex:1;">
          <i class="fa-solid fa-barcode" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.8rem;"></i>
          <input type="text" id="manualGlobalQrInput" placeholder="Enter QR ID or Student # e.g. BCP-STUDENT-1" style="width:100%; height:34px; padding:0 8px 0 28px; border:1px solid #cbd5e1; border-radius:6px; font-size:0.78rem; outline:none; box-sizing:border-box;" />
        </div>
        <button type="button" id="manualGlobalQrSubmitBtn" style="height:34px; padding:0 12px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-weight:600; font-size:0.78rem; cursor:pointer; display:flex; align-items:center; gap:4px; white-space:nowrap;">
          <i class="fa-solid fa-check"></i> Submit
        </button>
      </div>
      <p class="qr-subtext">
        <?php if (in_array($qr_role, ['club_adviser','ssc','admin'])): ?>
          Select an event above, then point camera at student QR badge (<code>BCP-STUDENT-{id}</code>) or upload/paste a badge image.
        <?php else: ?>
          Start scanner and point camera at the venue Event QR code (<code>BCP-EVENT-{id}</code>) or enter code manually.
        <?php endif; ?>
      </p>

      <!-- Recent Scans Log -->
      <div id="scanLog" class="qr-scan-log"></div>
    </div>

    <!-- Tab 3: Event QR Posters (Staff / Adviser / Admin) -->
    <?php if (in_array($qr_role, ['club_adviser','ssc','admin'])): ?>
    <div class="qr-modal-body qr-tab-panel" id="panelEventPoster" style="display:none;">
      <div class="qr-select-wrapper" style="margin-bottom:14px;">
        <label class="qr-select-label">
          <i class="fa-solid fa-calendar-days"></i> Select Approved Event to Generate Official Poster &amp; QR:
        </label>
        <select id="modalEventSelect" class="qr-select-input" onchange="renderEventQrInModal(this.value)">
          <option value="">— Select an Event —</option>
          <?php foreach ($scan_events as $idx => $sev): ?>
            <option value="<?= $sev['id'] ?>"
                    data-title="<?= htmlspecialchars($sev['title']) ?>"
                    data-date="<?= date('M d, Y', strtotime($sev['event_date'])) ?>"
                    data-venue="<?= htmlspecialchars($sev['venue'] ?? 'Campus Venue') ?>"
                    data-org="<?= htmlspecialchars($sev['club_name'] ?? 'Bestlink College') ?>"
                    <?= ($idx === 0) ? 'selected' : '' ?>>
              <?= htmlspecialchars($sev['title']) ?> (<?= date('M d', strtotime($sev['event_date'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="qr-code-frame" id="eventQrCodeFrame" style="margin-bottom:12px;">
        <div id="eventQrCanvasBox" class="qr-canvas-box" style="width:200px; height:200px; display:flex; align-items:center; justify-content:center; margin:auto; overflow:hidden;"></div>
      </div>

      <div id="modalEventTitle" style="font-weight:800; font-size:1.05rem; color:#0f172a; margin-bottom:4px; text-align:center;">Select an event</div>
      <div id="modalEventMeta" style="font-size:0.8rem; color:#64748b; margin-bottom:6px; text-align:center;">Event Venue &amp; Schedule</div>
      <div id="modalEventPayload" style="font-size:0.75rem; color:#2563eb; font-family:monospace; margin-bottom:14px; text-align:center; font-weight:700;">Payload: BCP-EVENT-1</div>

      <div style="display:flex; gap:8px; justify-content:center;">
        <button type="button" onclick="downloadEventQR()" class="btn-download-qr" style="margin:0; flex:1; max-width:180px;">
          <i class="fa-solid fa-download"></i> Download QR
        </button>
        <button type="button" onclick="printModalEventPoster()" class="btn-download-qr" style="margin:0; flex:1; max-width:180px; background:#1e293b;">
          <i class="fa-solid fa-print"></i> Print Poster
        </button>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<style>
#html5GlobalQrReader {
  width: 100% !important;
  height: 100% !important;
  border: none !important;
}
#html5GlobalQrReader video {
  width: 100% !important;
  height: 100% !important;
  object-fit: cover !important;
  border-radius: 12px !important;
}
#html5GlobalQrReader__scan_region {
  width: 100% !important;
  height: 100% !important;
}
#html5GlobalQrReader__header_message {
  display: none !important;
}
</style>

<script src="<?= $app_root_rel ?>js/qrcode.min.js"></script>
<script src="<?= $app_root_rel ?>js/html5-qrcode.min.js"></script>
<script src="https://unpkg.com/@zxing/library@0.21.1/umd/index.min.js"></script>
<script>
const ALL_STUDENTS_LOOKUP = <?= json_encode($all_students_list, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

const SELF_QR_DATA = {
  payload: '<?= $qr_code_str ?>',
  fullName: '<?= addslashes($qr_first . ' ' . $qr_last) ?>',
  roleLabel: '<?= addslashes($qr_role_lbl) ?>',
  studentNumber: '<?= addslashes($qr_student_num) ?>',
  course: '<?= addslashes($qr_course) ?>',
  yearSec: '<?= addslashes($qr_year_sec) ?>',
  username: '<?= addslashes($qr_username) ?>',
  role: '<?= addslashes($qr_role) ?>'
};

let activeQrData = Object.assign({}, SELF_QR_DATA);

function renderQrWithData(data) {
  activeQrData = Object.assign({}, data);
  const box = document.getElementById('myQrCanvasBox');
  if (!box) return;

  const roleEl = document.getElementById('qrBadgeRoleText');
  const nameEl = document.getElementById('qrStudentNameText');
  const numBox = document.getElementById('qrStudentNumBox');
  const numText = document.getElementById('qrStudentNumText');
  const courseBox = document.getElementById('qrCourseBox');
  const payloadEl = document.getElementById('qrPayloadText');

  if (roleEl) roleEl.textContent = data.roleLabel || 'Student';
  if (nameEl) nameEl.textContent = data.fullName;
  if (payloadEl) payloadEl.textContent = data.payload;

  if (data.studentNumber) {
    if (numBox) numBox.style.display = 'block';
    if (numText) numText.textContent = data.studentNumber;
  } else {
    if (numBox) numBox.style.display = 'none';
  }

  if (data.course) {
    if (courseBox) courseBox.textContent = [data.course, data.yearSec].filter(Boolean).join(' ');
  } else if (data.username && data.role !== 'student') {
    if (courseBox) courseBox.textContent = 'Username: ' + data.username;
  } else {
    if (courseBox) courseBox.textContent = '';
  }

  box.innerHTML = '';
  if (window.QRCode) {
    try {
      new QRCode(box, {
        text: data.payload,
        width: 190,
        height: 190,
        colorDark: "#0f172a",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.H
      });
      setTimeout(() => {
        box.querySelectorAll('canvas').forEach(c => c.remove());
        const images = box.querySelectorAll('img');
        for (let i = 1; i < images.length; i++) images[i].remove();
        if (images[0]) {
          images[0].style.cssText = 'width:190px!important;height:190px!important;display:block!important;margin:0 auto!important;border-radius:8px!important;';
        }
      }, 30);
    } catch (e) {
      console.warn('Local QRCode render failed, falling back to API:', e);
      box.innerHTML = '<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(data.payload) + '&margin=8&color=0f172a&bgcolor=ffffff" style="width:190px;height:190px;display:block;margin:0 auto;border-radius:8px;" alt="Personal Attendance QR Code" />';
    }
  } else {
    box.innerHTML = '<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(data.payload) + '&margin=8&color=0f172a&bgcolor=ffffff" style="width:190px;height:190px;display:block;margin:0 auto;border-radius:8px;" alt="Personal Attendance QR Code" />';
  }
}

function renderMyPersonalQr() {
  renderQrWithData(activeQrData);
}

function onQrStudentPickerChange(val) {
  if (val === 'self') {
    renderQrWithData(SELF_QR_DATA);
    return;
  }
  const sel = document.getElementById('qrStudentPickerSelect');
  if (!sel) return;
  const opt = sel.options[sel.selectedIndex];
  if (!opt) return;

  const sNum = opt.getAttribute('data-num') || '';
  const sName = opt.getAttribute('data-name') || 'Student';
  const sCourse = opt.getAttribute('data-course') || '';
  const sYr = opt.getAttribute('data-year') || '';
  const sSec = opt.getAttribute('data-sec') || '';
  const sUname = opt.getAttribute('data-uname') || '';
  const sPayload = sNum ? ('BCP-STUDENT-' + sNum) : ('BCP-STUDENT-' + val);

  renderQrWithData({
    payload: sPayload,
    fullName: sName,
    roleLabel: 'Enrolled Student',
    studentNumber: sNum,
    course: sCourse,
    yearSec: [sYr, sSec ? `(${sSec})` : ''].filter(Boolean).join(' '),
    username: sUname,
    role: 'student'
  });
}

window.openStudentQrModal = function(student) {
  if (!student) return;
  let target = null;
  if (typeof student === 'string' || typeof student === 'number') {
    target = ALL_STUDENTS_LOOKUP.find(s => String(s.user_id) === String(student) || String(s.student_number) === String(student) || s.username === String(student));
  } else if (typeof student === 'object') {
    target = student;
  }
  if (!target) return;

  const sNum = target.student_number || '';
  const sName = `${target.first_name || ''} ${target.last_name || ''}`.trim() || target.name || 'Student';
  const sCourse = target.course || '';
  const sYr = target.year_level || '';
  const sSec = target.section || '';
  const sPayload = sNum ? ('BCP-STUDENT-' + sNum) : ('BCP-STUDENT-' + (target.user_id || target.id || '1'));

  const sel = document.getElementById('qrStudentPickerSelect');
  if (sel) {
    const optMatch = Array.from(sel.options).find(o => o.value == (target.user_id || target.id) || o.getAttribute('data-num') === sNum);
    if (optMatch) sel.value = optMatch.value;
  }

  window.openGlobalQrModal('myqr');
  renderQrWithData({
    payload: sPayload,
    fullName: sName,
    roleLabel: target.role === 'student' ? 'Enrolled Student' : (target.role ? target.role.toUpperCase() : 'Enrolled Student'),
    studentNumber: sNum,
    course: sCourse,
    yearSec: [sYr, sSec ? `(${sSec})` : ''].filter(Boolean).join(' '),
    username: target.username || '',
    role: target.role || 'student'
  });
};

window.openGlobalQrModal = function(tab = 'myqr') {
  const overlay = document.getElementById('qrModalOverlay');
  const titleText = document.querySelector('.qr-modal-title span');
  const tabsWrap = document.querySelector('.qr-tabs-wrapper');
  
  if (titleText) titleText.textContent = 'QR Code Center';
  if (tabsWrap) tabsWrap.style.display = 'block';
  
  window.switchGlobalQrTab(tab);
  if (overlay) overlay.classList.add('active');

  if (tab === 'myqr') {
    renderMyPersonalQr();
  }
};

window.openScannerOnlyModal = function(eventId = null) {
  const overlay = document.getElementById('qrModalOverlay');
  const titleText = document.querySelector('.qr-modal-title span');
  const tabsWrap = document.querySelector('.qr-tabs-wrapper');
  
  if (titleText) titleText.textContent = 'Live Attendance Scanner';
  if (tabsWrap) tabsWrap.style.display = 'none'; // Show only scanning, hide QR code generation tab
  
  window.switchGlobalQrTab('scan');
  if (overlay) overlay.classList.add('active');
  
  if (eventId) {
    const sel = document.getElementById('scanEventSelect');
    if (sel) sel.value = eventId;
  }

  // Automatically start camera scanner
  setTimeout(() => {
    const startBtn = document.getElementById('startGlobalScanBtn');
    if (startBtn && !window.isGlobalQrScanning) {
      startBtn.click();
    }
  }, 150);
};

window.closeGlobalQrModal = function() {
  const overlay = document.getElementById('qrModalOverlay');
  if (overlay) overlay.classList.remove('active');
  if (typeof window.stopGlobalQrScanner === 'function') {
    window.stopGlobalQrScanner();
  }
};

window.switchGlobalQrTab = function(tabName) {
  document.getElementById('tabMyQr')?.classList.toggle('active', tabName === 'myqr');
  document.getElementById('tabScan')?.classList.toggle('active', tabName === 'scan');
  document.getElementById('tabEventPoster')?.classList.toggle('active', tabName === 'eventposter');

  const panelMyQr = document.getElementById('panelMyQr');
  const panelScan = document.getElementById('panelScan');
  const panelEvent = document.getElementById('panelEventPoster');

  if (panelMyQr) {
    panelMyQr.classList.toggle('active', tabName === 'myqr');
    panelMyQr.style.display = (tabName === 'myqr') ? 'block' : 'none';
  }
  if (panelScan) {
    panelScan.classList.toggle('active', tabName === 'scan');
    panelScan.style.display = (tabName === 'scan') ? 'block' : 'none';
  }
  if (panelEvent) {
    panelEvent.classList.toggle('active', tabName === 'eventposter');
    panelEvent.style.display = (tabName === 'eventposter') ? 'block' : 'none';
  }

  if (tabName === 'myqr') {
    renderMyPersonalQr();
    if (typeof window.stopGlobalQrScanner === 'function') {
      window.stopGlobalQrScanner();
    }
  } else if (tabName === 'eventposter') {
    if (typeof window.stopGlobalQrScanner === 'function') {
      window.stopGlobalQrScanner();
    }
    const sel = document.getElementById('modalEventSelect');
    if (sel && sel.value) {
      renderEventQrInModal(sel.value);
    }
  }
};

window.openGlobalEventQr = function(eventId) {
  window.openGlobalQrModal('eventposter');
  const sel = document.getElementById('modalEventSelect');
  if (sel) {
    sel.value = eventId;
    renderEventQrInModal(eventId);
  }
};

function renderEventQrInModal(eventId) {
  const sel = document.getElementById('modalEventSelect');
  if (!sel) return;
  const opt = eventId ? sel.querySelector(`option[value="${eventId}"]`) : sel.options[sel.selectedIndex];
  if (!opt || !opt.value) {
    document.getElementById('modalEventTitle').textContent = 'Please select an event above';
    document.getElementById('modalEventMeta').textContent = '';
    document.getElementById('modalEventPayload').textContent = '';
    const box = document.getElementById('eventQrCanvasBox');
    if (box) box.innerHTML = '<div style="color:#94a3b8;font-size:0.85rem;padding:20px 0;">Select an event above to generate QR</div>';
    return;
  }

  const evId = opt.value;
  const evTitle = opt.getAttribute('data-title') || opt.text;
  const evDate = opt.getAttribute('data-date') || 'Event Date';
  const evVenue = opt.getAttribute('data-venue') || 'Campus Venue';
  const evOrg = opt.getAttribute('data-org') || 'Bestlink College';
  const payload = `BCP-EVENT-${evId}`;

  document.getElementById('modalEventTitle').textContent = evTitle;
  document.getElementById('modalEventMeta').innerHTML = `<strong>Host:</strong> ${evOrg} &bull; <strong>Date:</strong> ${evDate} &bull; <strong>Venue:</strong> ${evVenue}`;
  document.getElementById('modalEventPayload').textContent = `Payload Standard: ${payload}`;

  const box = document.getElementById('eventQrCanvasBox');
  if (!box) return;
  box.innerHTML = '';
  if (window.QRCode) {
    try {
      new QRCode(box, {
        text: payload,
        width: 190,
        height: 190,
        colorDark: "#0f172a",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.H
      });
      setTimeout(() => {
        box.querySelectorAll('canvas').forEach(c => c.remove());
        const images = box.querySelectorAll('img');
        for (let i = 1; i < images.length; i++) images[i].remove();
        if (images[0]) {
          images[0].style.cssText = 'width:190px!important;height:190px!important;display:block!important;margin:0 auto!important;border-radius:8px!important;';
        }
      }, 30);
    } catch(e) {
      box.innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(payload)}&margin=8&color=0f172a&bgcolor=ffffff" style="width:190px;height:190px;display:block;margin:0 auto;border-radius:8px;" alt="Event QR Code" />`;
    }
  } else {
    box.innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(payload)}&margin=8&color=0f172a&bgcolor=ffffff" style="width:190px;height:190px;display:block;margin:0 auto;border-radius:8px;" alt="Event QR Code" />`;
  }
}

function downloadEventQR() {
  const box = document.getElementById('eventQrCanvasBox');
  if (!box) return;
  const canvas = box.querySelector('canvas');
  const img = box.querySelector('img');
  let src = '';
  if (canvas && typeof canvas.toDataURL === 'function') {
    src = canvas.toDataURL('image/png');
  } else if (img && img.src) {
    src = img.src;
  }
  if (!src) return;
  const sel = document.getElementById('modalEventSelect');
  const evId = sel ? sel.value : 'event';
  const link = document.createElement('a');
  link.href = src;
  link.download = `BCP-EVENT-${evId}-QR.png`;
  link.click();
}

function printModalEventPoster() {
  const sel = document.getElementById('modalEventSelect');
  if (!sel || !sel.value) {
    alert('Please select an event first.');
    return;
  }
  const opt = sel.options[sel.selectedIndex];
  const evId = opt.value;
  const evTitle = opt.getAttribute('data-title') || opt.text;
  const evDate = opt.getAttribute('data-date') || 'Event Date';
  const evVenue = opt.getAttribute('data-venue') || 'Campus Venue';
  const evOrg = opt.getAttribute('data-org') || 'Bestlink College';
  const payload = `BCP-EVENT-${evId}`;

  const w = window.open('', '_blank');
  w.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>Event QR Poster - ${evTitle}</title>
      <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; text-align: center; padding: 40px; background: #f8fafc; color: #0f2a73; }
        .poster { max-width: 520px; margin: 0 auto; background: white; border-radius: 20px; padding: 40px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 3px solid #2563eb; }
        h1 { font-size: 1.8rem; margin: 0 0 10px 0; color: #0f2a73; }
        p { font-size: 1.05rem; color: #475569; margin: 6px 0; }
        #qrcode { display: flex; justify-content: center; margin: 30px 0; }
        .instructions { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 15px; border-radius: 12px; font-weight: 600; font-size: 0.95rem; }
        .btn-print { background: #2563eb; color: white; border: none; padding: 12px 28px; border-radius: 8px; font-weight: bold; font-size: 1rem; cursor: pointer; margin-top: 20px; }
        @media print { .btn-print { display: none; } }
      </style>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>
    </head>
    <body>
      <div class="poster">
        <div style="text-transform:uppercase; letter-spacing:1px; font-weight:800; font-size:0.85rem; color:#2563eb; margin-bottom:8px;">Bestlink College of the Philippines — Official Attendance Poster</div>
        <h1>${evTitle}</h1>
        <p><strong>Host:</strong> ${evOrg}</p>
        <p><strong>Date:</strong> ${evDate} | <strong>Venue:</strong> ${evVenue}</p>
        <div id="qrcode"></div>
        <div class="instructions">
          📢 <strong>Students:</strong> Scan this QR Code using your BCP Mobile App / Portal Scanner to record your attendance instantly!
        </div>
        <button class="btn-print" onclick="window.print()">🖨️ Print Event Poster</button>
      </div>
      <script>
        window.onload = function() {
          new QRCode(document.getElementById('qrcode'), {
            text: "${payload}",
            width: 240,
            height: 240,
            colorDark: "#0f172a",
            colorLight: "#ffffff"
          });
        };
      <\/script>
    </body>
    </html>
  `);
  w.document.close();
}

function playScanChime(success = true) {
  try {
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) return;
    const ctx = new AudioContext();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain);
    gain.connect(ctx.destination);
    if (success) {
      osc.frequency.setValueAtTime(880, ctx.currentTime);
      osc.frequency.exponentialRampToValueAtTime(1320, ctx.currentTime + 0.12);
      gain.gain.setValueAtTime(0.3, ctx.currentTime);
      gain.gain.linearRampToValueAtTime(0.01, ctx.currentTime + 0.18);
      osc.start();
      osc.stop(ctx.currentTime + 0.18);
    } else {
      osc.frequency.setValueAtTime(380, ctx.currentTime);
      gain.gain.setValueAtTime(0.25, ctx.currentTime);
      gain.gain.linearRampToValueAtTime(0.01, ctx.currentTime + 0.22);
      osc.start();
      osc.stop(ctx.currentTime + 0.22);
    }
  } catch (e) {}
}

function downloadQR() {
  const box = document.getElementById('myQrCanvasBox');
  if (!box) return;
  const canvas = box.querySelector('canvas');
  const img = box.querySelector('img');
  let src = '';
  if (canvas && typeof canvas.toDataURL === 'function') {
    src = canvas.toDataURL('image/png');
  } else if (img && img.src) {
    src = img.src;
  }
  if (!src) return;
  const link = document.createElement('a');
  link.href = src;
  const safeName = (activeQrData.fullName || 'User').replace(/[^a-zA-Z0-9]/g, '_');
  link.download = `${safeName}_${activeQrData.payload}_QR.png`;
  link.click();
}

function printStudentBadge() {
  const d = activeQrData;
  const w = window.open('', '_blank');
  w.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>BCP Official Badge - ${d.fullName}</title>
      <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; text-align: center; padding: 40px; background: #f8fafc; color: #0f2a73; }
        .badge-card { max-width: 380px; margin: 0 auto; background: white; border-radius: 18px; padding: 32px 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 2.5px solid #2563eb; }
        .school-title { font-size: 0.82rem; font-weight: 800; text-transform: uppercase; color: #2563eb; letter-spacing: 0.5px; margin-bottom: 12px; }
        h2 { font-size: 1.4rem; margin: 0 0 4px 0; color: #0f2a73; font-weight: 800; }
        .student-num { font-size: 0.95rem; font-weight: 700; color: #2563eb; margin-bottom: 6px; }
        .course-info { font-size: 0.85rem; color: #475569; margin: 4px 0 16px 0; line-height: 1.4; }
        #qrcode { display: flex; justify-content: center; margin: 16px 0; }
        .payload-tag { font-family: monospace; font-size: 0.85rem; background: #f1f5f9; padding: 4px 10px; border-radius: 6px; color: #334155; font-weight: 700; display: inline-block; margin-top: 4px; }
        .instructions { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 10px; border-radius: 10px; font-weight: 600; font-size: 0.8rem; margin-top: 16px; }
        .btn-print { background: #2563eb; color: white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: bold; font-size: 0.95rem; cursor: pointer; margin-top: 18px; }
        @media print { .btn-print { display: none; } }
      </style>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>
    </head>
    <body>
      <div class="badge-card">
        <div class="school-title">Bestlink College of the Philippines</div>
        <h2>${d.fullName}</h2>
        ${d.studentNumber ? `<div class="student-num">Student ID: ${d.studentNumber}</div>` : ''}
        <div class="course-info">${d.course || ''} ${d.yearSec || ''}</div>
        <div id="qrcode"></div>
        <div class="payload-tag">${d.payload}</div>
        <div class="instructions">Official Attendance &amp; Activity Badge</div>
        <button class="btn-print" onclick="window.print()">🖨️ Print Badge</button>
      </div>
      <script>
        window.onload = function() {
          if (typeof QRCode !== 'undefined') {
            new QRCode(document.getElementById('qrcode'), {
              text: "${d.payload}",
              width: 200,
              height: 200,
              colorDark: "#0f172a",
              colorLight: "#ffffff"
            });
          }
        };
      <\/script>
    </body>
    </html>
  `);
  w.document.close();
}

(function() {
  let html5Scanner = null;
  let codeReader = null;
  let isScanning = false;
  let lastScanned = '';
  let lastScanTime = 0;

  window.isGlobalQrScanning = false;

  // Global click delegation for opening & closing modal
  document.addEventListener('click', function(e) {
    const scannerBtn = e.target.closest('#btnLaunchScanner, [data-open-scanner]');
    if (scannerBtn) {
      e.preventDefault();
      const eventId = scannerBtn.getAttribute('data-event-id');
      window.openScannerOnlyModal(eventId);
      return;
    }

    const openBtn = e.target.closest('#qrFabBtn, .topbar-qr-btn, [data-open-qr]');
    if (openBtn) {
      e.preventDefault();
      window.openGlobalQrModal('myqr');
      return;
    }

    const closeBtn = e.target.closest('#closeQrModalBtn, [data-close-qr]');
    if (closeBtn) {
      e.preventDefault();
      window.closeGlobalQrModal();
      return;
    }

    const overlay = document.getElementById('qrModalOverlay');
    if (e.target === overlay) {
      window.closeGlobalQrModal();
      return;
    }
  });

  // ESC key to close modal
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      window.closeGlobalQrModal();
    }
  });

  function initQrHandlers() {
    const startBtn = document.getElementById('startGlobalScanBtn');
    startBtn?.addEventListener('click', () => {
      if (isScanning) stopScanner();
      else startScanner();
    });

    const uploadBtn = document.getElementById('globalUploadQrBtn');
    const fileInput = document.getElementById('globalQrFileInput');
    uploadBtn?.addEventListener('click', () => {
      fileInput?.click();
    });

    fileInput?.addEventListener('change', async (e) => {
      const file = e.target.files?.[0];
      if (file) {
        await scanFileImage(file);
      }
      e.target.value = '';
    });

    const manualInput = document.getElementById('manualGlobalQrInput');
    const manualBtn = document.getElementById('manualGlobalQrSubmitBtn');
    
    manualBtn?.addEventListener('click', submitManualCode);
    manualInput?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        submitManualCode();
      }
    });

    // Drag-and-drop onto camera viewport
    const cameraWrap = document.getElementById('qrCameraWrap');
    if (cameraWrap) {
      cameraWrap.addEventListener('dragover', (e) => {
        e.preventDefault();
        cameraWrap.style.outline = '2px dashed #38bdf8';
      });
      cameraWrap.addEventListener('dragleave', () => {
        cameraWrap.style.outline = 'none';
      });
      cameraWrap.addEventListener('drop', async (e) => {
        e.preventDefault();
        cameraWrap.style.outline = 'none';
        const file = e.dataTransfer.files?.[0];
        if (file && file.type.startsWith('image/')) {
          await scanFileImage(file);
        }
      });
    }

    // Ctrl+V paste image handler when QR modal is active
    document.addEventListener('paste', async (e) => {
      const overlay = document.getElementById('qrModalOverlay');
      if (!overlay || !overlay.classList.contains('active')) return;
      const items = e.clipboardData?.items;
      if (!items) return;
      for (let i = 0; i < items.length; i++) {
        if (items[i].type.indexOf('image') !== -1) {
          const blob = items[i].getAsFile();
          if (blob) {
            await scanFileImage(blob);
            break;
          }
        }
      }
    });
  }

  function submitManualCode() {
    const input = document.getElementById('manualGlobalQrInput');
    const val = (input?.value || '').trim();
    if (!val) {
      alert('Please enter a student number or QR badge ID.');
      return;
    }
    processScannedCode(val);
    if (input) input.value = '';
  }

  async function scanFileImage(file) {
    const resultBox   = document.getElementById('qrGlobalScanResult');
    const resultText  = document.getElementById('qrGlobalScanText');

    if (resultText) resultText.textContent = '⏳ Analyzing image for QR code...';
    if (resultBox) {
      resultBox.style.display = 'flex';
      resultBox.style.background = '#eff6ff';
      resultBox.style.color = '#1e40af';
    }

    // 1. Try Html5Qrcode scanFile via dedicated hidden container
    if (typeof Html5Qrcode !== 'undefined') {
      try {
        const tempScanner = new Html5Qrcode("html5GlobalQrReaderFile");
        const decoded = await tempScanner.scanFile(file, false);
        try { tempScanner.clear(); } catch(e) {}
        if (decoded) {
          processScannedCode(decoded);
          return;
        }
      } catch (e) {
        console.warn('Html5Qrcode file scan notice:', e);
      }
    }

    // 2. Fallback to ZXing decodeFromImageUrl
    if (window.ZXing) {
      try {
        const reader = new ZXing.BrowserQRCodeReader();
        const objUrl = URL.createObjectURL(file);
        const result = await reader.decodeFromImageUrl(objUrl);
        URL.revokeObjectURL(objUrl);
        if (result) {
          processScannedCode(result.getText());
          return;
        }
      } catch (e) {
        console.warn('ZXing file scan notice:', e);
      }
    }

    if (resultText) resultText.textContent = '❌ No clear QR code found in this image.';
    if (resultBox) {
      resultBox.style.display = 'flex';
      resultBox.style.background = '#fef2f2';
      resultBox.style.color = '#dc2626';
    }
  }

  async function startScanner() {
    const video       = document.getElementById('qrGlobalVideo');
    const html5Div    = document.getElementById('html5GlobalQrReader');
    const placeholder = document.getElementById('cameraGlobalPlaceholder');
    const scanLine    = document.getElementById('qrGlobalScannerLine');
    const resultBox   = document.getElementById('qrGlobalScanResult');
    const resultText  = document.getElementById('qrGlobalScanText');
    const startBtn    = document.getElementById('startGlobalScanBtn');

    // 1. Primary Engine: Html5Qrcode (robust autofocus, multi-binarizer, inverted codes)
    if (typeof Html5Qrcode !== 'undefined') {
      try {
        if (placeholder) placeholder.style.setProperty('display', 'none', 'important');
        if (video) video.style.display = 'none';
        if (html5Div) html5Div.style.display = 'block';
        if (scanLine) scanLine.style.display = 'block';
        if (resultBox) { resultBox.style.display = 'flex'; resultBox.style.background = '#f8fafc'; resultBox.style.color = '#64748b'; }
        if (resultText) resultText.textContent = 'Scanner active — point camera directly at QR code...';

        html5Scanner = new Html5Qrcode("html5GlobalQrReader");
        const config = {
          fps: 15,
          qrbox: function(viewfinderWidth, viewfinderHeight) {
            const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
            const qrboxSize = Math.max(160, Math.floor(minEdge * 0.82));
            return { width: qrboxSize, height: qrboxSize };
          },
          aspectRatio: 1.0
        };

        // Query available cameras to pick valid camera ID
        const cameras = await Html5Qrcode.getCameras().catch(() => []);
        let cameraConfig;
        if (cameras && cameras.length > 0) {
          const backCam = cameras.find(c => /back|rear|environment/i.test(c.label));
          cameraConfig = backCam ? backCam.id : cameras[0].id;
        } else {
          cameraConfig = { facingMode: "environment" };
        }

        try {
          await html5Scanner.start(
            cameraConfig,
            config,
            (decodedText) => { processScannedCode(decodedText); },
            (err) => { /* Frame pass */ }
          );
        } catch (firstStartErr) {
          console.warn("Primary cameraConfig failed, retrying with facingMode user:", firstStartErr);
          await html5Scanner.start(
            { facingMode: "user" },
            config,
            (decodedText) => { processScannedCode(decodedText); },
            (err) => { /* Frame pass */ }
          );
        }

        isScanning = true;
        window.isGlobalQrScanning = true;
        if (startBtn) {
          startBtn.innerHTML = '<i class="fa-solid fa-stop"></i> Stop Scanner';
          startBtn.style.background = '#ef4444';
        }
        return;
      } catch (h5Err) {
        console.warn('Html5Qrcode live scan error, falling back to ZXing:', h5Err);
        if (html5Scanner) {
          try { await html5Scanner.stop(); html5Scanner.clear(); } catch(e){}
          html5Scanner = null;
        }
      }
    }

    // 2. Fallback Engine: ZXing
    if (window.ZXing) {
      try {
        if (typeof ZXing.BrowserQRCodeReader === 'function') {
          codeReader = new ZXing.BrowserQRCodeReader();
        } else {
          codeReader = new ZXing.BrowserMultiFormatReader();
        }
        const devices = await codeReader.listVideoInputDevices();

        if (!devices || devices.length === 0) {
          if (resultText) resultText.textContent = 'No camera detected on this device.';
          if (resultBox) { resultBox.style.display='flex'; resultBox.style.background='#fef2f2'; resultBox.style.color='#dc2626'; }
          return;
        }

        const device = devices.find(d => /back|rear|environment/i.test(d.label)) || devices[0];

        if (html5Div) html5Div.style.display = 'none';
        if (placeholder) placeholder.style.setProperty('display', 'none', 'important');
        if (video) video.style.display = 'block';
        if (scanLine) scanLine.style.display = 'block';
        if (resultBox){ resultBox.style.display = 'flex'; resultBox.style.background = '#f8fafc'; resultBox.style.color = '#64748b'; }
        if (resultText) resultText.textContent = 'Scanner active — point camera directly at QR code...';

        isScanning = true;
        window.isGlobalQrScanning = true;
        if (startBtn) {
          startBtn.innerHTML = '<i class="fa-solid fa-stop"></i> Stop Scanner';
          startBtn.style.background = '#ef4444';
        }

        codeReader.decodeFromVideoDevice(device.deviceId, 'qrGlobalVideo', (result, err) => {
          if (result) {
            processScannedCode(result.getText());
          }
        });
        return;
      } catch (zxErr) {
        console.error('ZXing Scanner error:', zxErr);
        const msg = zxErr.name === 'NotAllowedError'
          ? 'Camera access denied. Please allow camera permissions in your browser.'
          : 'Camera error: ' + (zxErr.message || 'Unknown error');
        if (resultText) resultText.textContent = msg;
        if (resultBox) { resultBox.style.display='flex'; resultBox.style.background='#fef2f2'; resultBox.style.color='#dc2626'; }
        isScanning = false;
        window.isGlobalQrScanning = false;
        return;
      }
    }

    if (resultText) resultText.textContent = 'QR library still loading. Please wait a moment and try again.';
    if (resultBox) resultBox.style.display = 'flex';
  }

  function processScannedCode(rawText) {
    if (!rawText) return;
    const text = rawText.trim();
    const now  = Date.now();

    // Debounce: same code within 2.5 seconds = skip
    if (text === lastScanned && (now - lastScanTime) < 2500) return;
    lastScanned  = text;
    lastScanTime = now;

    // Haptic feedback
    try { navigator.vibrate?.([60, 40, 60]); } catch (e) {}

    const resultBox   = document.getElementById('qrGlobalScanResult');
    const resultText  = document.getElementById('qrGlobalScanText');

    if (resultText) resultText.textContent = '📷 Scanned: ' + text;
    if (resultBox) { resultBox.style.display = 'flex'; resultBox.style.background='#fef9c3'; resultBox.style.color='#92400e'; }

    const eventId = parseInt(document.getElementById('scanEventSelect')?.value || '0');
    const isEventQr = /^BCP-EVENT(?:-LOG)?-\d+/i.test(text);

    if (isEventQr || eventId > 0 || <?= json_encode($qr_role === 'student') ?>) {
      logAttendanceViaQr(text, eventId);
    } else {
      if (resultText) resultText.textContent = '⚠️ Scanned: ' + text + ' — Please select an event above first!';
      if (resultBox) { resultBox.style.background='#fef2f2'; resultBox.style.color='#dc2626'; }
    }
  }

  async function stopScanner() {
    isScanning = false;
    window.isGlobalQrScanning = false;

    if (html5Scanner) {
      try {
        await html5Scanner.stop();
        html5Scanner.clear();
      } catch (e) {}
      html5Scanner = null;
    }

    if (codeReader) {
      try { codeReader.reset(); } catch (e) {}
      codeReader = null;
    }

    const video       = document.getElementById('qrGlobalVideo');
    const html5Div    = document.getElementById('html5GlobalQrReader');
    const placeholder = document.getElementById('cameraGlobalPlaceholder');
    const scanLine    = document.getElementById('qrGlobalScannerLine');
    const startBtn    = document.getElementById('startGlobalScanBtn');
    const resultBox   = document.getElementById('qrGlobalScanResult');
    const resultText  = document.getElementById('qrGlobalScanText');

    if (html5Div) html5Div.style.display = 'none';
    if (video) {
      if (video.srcObject && typeof video.srcObject.getTracks === 'function') {
        video.srcObject.getTracks().forEach(t => t.stop());
      }
      video.style.display = 'none';
      video.srcObject = null;
    }
    if (placeholder) placeholder.style.setProperty('display', 'flex', 'important');
    if (scanLine) scanLine.style.display='none';
    if (resultBox){ resultBox.style.display='none'; }
    if (resultText) resultText.textContent = 'Ready to scan...';
    if (startBtn) {
      startBtn.innerHTML = '<i class="fa-solid fa-camera"></i> Start Scanner';
      startBtn.style.background = '#2563eb';
    }
  }

  window.stopGlobalQrScanner = stopScanner;

  function logAttendanceViaQr(qrData, eventId) {
    const resultText = document.getElementById('qrGlobalScanText');
    const resultBox  = document.getElementById('qrGlobalScanResult');
    if (resultText) resultText.textContent = '⏳ Logging attendance...';

    const fd = new FormData();
    fd.append('action', 'log_qr');
    fd.append('qr_data', qrData);
    fd.append('event_id', eventId);

    fetch('<?= $app_root_rel ?>shared/attendance_actions.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        const ok = data.success;
        const isDup = data.already_logged;
        
        // Play audible feedback tone
        if (typeof playScanChime === 'function') {
          playScanChime(ok);
        }

        // If staff scanned an event poster, auto-select it in the dropdown
        if (data.is_event_switch && data.event_id) {
          const sel = document.getElementById('scanEventSelect');
          if (sel) sel.value = data.event_id;
        }

        let detailMsg = data.message;
        if (data.student_name && data.student_number) {
          detailMsg = `✅ ${data.student_name} (${data.student_number}) checked in successfully!`;
        }

        if (resultText) resultText.textContent = (ok ? '✅ ' : (isDup ? '⚠️ ' : '❌ ')) + (data.student_name ? detailMsg : data.message);
        if (resultBox) {
          resultBox.style.background = ok ? '#dcfce7' : (isDup ? '#fef9c3' : '#fef2f2');
          resultBox.style.color      = ok ? '#15803d' : (isDup ? '#92400e' : '#dc2626');
          resultBox.style.display    = 'flex';
        }

        // Add to scan log
        const log = document.getElementById('scanLog');
        if (log && data.message) {
          const entry = document.createElement('div');
          entry.style.cssText = 'padding:5px 0;border-bottom:1px solid #f1f5f9;font-size:0.75rem;display:flex;justify-content:space-between;align-items:center;';
          entry.innerHTML = `<span>${new Date().toLocaleTimeString()} &bull; <strong>${data.message}</strong></span> <span style="font-size:0.7rem;color:${ok ? '#16a34a' : '#d97706'};font-weight:700;">${ok ? 'LOGGED' : (isDup ? 'DUPLICATE' : 'REJECTED')}</span>`;
          log.prepend(entry);
        }
      })
      .catch(() => {
        if (resultText) resultText.textContent = '❌ Network error logging attendance.';
        if (resultBox) { resultBox.style.background='#fef2f2'; resultBox.style.color='#dc2626'; resultBox.style.display='flex'; }
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initQrHandlers);
  } else {
    initQrHandlers();
  }
})();
</script>
