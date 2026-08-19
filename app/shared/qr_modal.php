<?php
// ============================================================
//  QR_MODAL.PHP (shared/)
//  Dual-Tab QR Modal: My QR Code + Live Camera Scanner
//  Scanner sends attendance to attendance_actions.php via AJAX
// ============================================================
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$app_root_rel = $APP_ROOT ?? '../';
$qr_user_id   = $_SESSION['user_id'] ?? 0;
$qr_role      = $_SESSION['role'] ?? 'student';
$qr_first     = htmlspecialchars($_SESSION['first_name'] ?? 'User');
$qr_last      = htmlspecialchars($_SESSION['last_name']  ?? '');
$qr_code_str  = 'BCP-' . strtoupper($qr_role === 'student' ? 'STUDENT' : 'STAFF') . '-' . $qr_user_id;
$qr_role_lbl  = match($qr_role) {
    'admin'        => 'System Admin',
    'ssc'          => 'SSC Officer',
    'club_adviser' => 'Club Adviser',
    default        => 'Student',
};
// Fetch active events for scanner tab (all roles)
$scan_events = [];
if (!isset($conn) || !($conn instanceof mysqli) || !@$conn->ping()) {
    require_once __DIR__ . '/db.php';
}
if (isset($conn) && $conn instanceof mysqli) {
    $ev_res = $conn->query("SELECT id, title, event_date FROM events WHERE status IN ('Approved','Upcoming') ORDER BY event_date ASC LIMIT 20");
    if ($ev_res) $scan_events = $ev_res->fetch_all(MYSQLI_ASSOC);
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
      <button class="notif-close" id="closeQrModalBtn" title="Close" type="button">&times;</button>
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
      </div>
    </div>

    <!-- Tab 1: My QR Code -->
    <div class="qr-modal-body qr-tab-panel active" id="panelMyQr">
      <div class="qr-badge-role">
        <i class="fa-solid fa-id-badge"></i>
        <span><?= htmlspecialchars($qr_role_lbl) ?></span>
      </div>
      <div class="qr-code-frame">
        <div class="qr-scan-line"></div>
        <img id="qrCodeImg"
             src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?= urlencode($qr_code_str) ?>&margin=8&color=0f172a&bgcolor=ffffff"
             alt="Personal Attendance QR Code"
             onerror="this.src='https://chart.googleapis.com/chart?chs=220x220&cht=qr&chl=<?= urlencode($qr_code_str) ?>'"/>
      </div>
      <div class="qr-student-name"><?= $qr_first . ' ' . $qr_last ?></div>
      <div class="qr-student-id">QR ID: <strong><?= htmlspecialchars($qr_code_str) ?></strong></div>
      <p class="qr-subtext">Present this QR code at event scanner terminals for instant attendance check-in.</p>
      <button onclick="downloadQR()" class="btn-download-qr">
        <i class="fa-solid fa-download"></i> Download QR
      </button>
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
      <div class="qr-camera-wrap" id="qrCameraWrap">
        <video id="qrGlobalVideo" autoplay playsinline muted style="display:none;width:100%;height:100%;object-fit:cover;border-radius:12px;"></video>
        <canvas id="qrGlobalCanvas" style="display:none;"></canvas>
        <div class="qr-camera-overlay"></div>
        <div class="qr-scanner-line" id="qrGlobalScannerLine" style="display:none;"></div>
        <div class="qr-camera-placeholder" id="cameraGlobalPlaceholder">
          <i class="fa-solid fa-camera"></i>
          <span>Camera scanner inactive</span>
        </div>
      </div>

      <!-- Scan Result -->
      <div class="qr-scanner-result" id="qrGlobalScanResult" style="display:none;">
        <i class="fa-solid fa-circle-check" id="qrResultIcon"></i>
        <span id="qrGlobalScanText">Ready to scan...</span>
      </div>

      <!-- Controls -->
      <button class="qr-scan-start-btn" id="startGlobalScanBtn" type="button">
        <i class="fa-solid fa-camera"></i> Start Camera Scanner
      </button>
      <p class="qr-subtext">
        <?php if (in_array($qr_role, ['club_adviser','ssc','admin'])): ?>
          Select an event above, then start scanner. Point camera at student QR badge (<code>BCP-STUDENT-{id}</code>).
        <?php else: ?>
          Start scanner and point camera at the venue Event QR code (<code>BCP-EVENT-{id}</code>).
        <?php endif; ?>
      </p>

      <!-- Recent Scans Log -->
      <div id="scanLog" class="qr-scan-log"></div>
    </div>

  </div>
</div>

<script src="https://unpkg.com/@zxing/library@0.21.1/umd/index.min.js"></script>
<script>
window.openGlobalQrModal = function() {
  const overlay = document.getElementById('qrModalOverlay');
  if (overlay) overlay.classList.add('active');
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
  const panelMyQr = document.getElementById('panelMyQr');
  const panelScan = document.getElementById('panelScan');
  if (panelMyQr) panelMyQr.classList.toggle('active', tabName === 'myqr');
  if (panelScan) panelScan.classList.toggle('active', tabName === 'scan');
  if (tabName === 'myqr' && typeof window.stopGlobalQrScanner === 'function') {
    window.stopGlobalQrScanner();
  }
};

function downloadQR() {
  const img = document.getElementById('qrCodeImg');
  if (!img) return;
  const link = document.createElement('a');
  link.href = img.src;
  link.download = 'BCP-QR-<?= $qr_code_str ?>.png';
  link.click();
}

(function() {
  let codeReader = null;
  let isScanning = false;
  let lastScanned = '';
  let lastScanTime = 0;

  // Global click delegation for opening & closing modal
  document.addEventListener('click', function(e) {
    const openBtn = e.target.closest('#qrFabBtn, .topbar-qr-btn, [data-open-qr]');
    if (openBtn) {
      e.preventDefault();
      window.openGlobalQrModal();
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
  }

  async function startScanner() {
    const video       = document.getElementById('qrGlobalVideo');
    const placeholder = document.getElementById('cameraGlobalPlaceholder');
    const scanLine    = document.getElementById('qrGlobalScannerLine');
    const resultBox   = document.getElementById('qrGlobalScanResult');
    const resultText  = document.getElementById('qrGlobalScanText');
    const startBtn    = document.getElementById('startGlobalScanBtn');

    if (!window.ZXing) {
      if (resultText) resultText.textContent = 'QR library still loading. Please wait a moment and try again.';
      if (resultBox) resultBox.style.display = 'flex';
      return;
    }

    try {
      codeReader = new ZXing.BrowserMultiFormatReader();
      const devices = await codeReader.listVideoInputDevices();

      if (!devices || devices.length === 0) {
        if (resultText) resultText.textContent = 'No camera detected on this device.';
        if (resultBox) { resultBox.style.display='flex'; resultBox.style.background='#fef2f2'; resultBox.style.color='#dc2626'; }
        return;
      }

      // Prefer back camera on mobile
      const device = devices.find(d => /back|rear|environment/i.test(d.label)) || devices[0];

      if (placeholder) placeholder.style.setProperty('display', 'none', 'important');
      if (video)    { video.style.display = 'block'; }
      if (scanLine) scanLine.style.display = 'block';
      if (resultBox){ resultBox.style.display = 'flex'; resultBox.style.background = '#f8fafc'; resultBox.style.color = '#64748b'; }
      if (resultText) resultText.textContent = 'Scanner active — point camera at a student QR badge...';

      isScanning = true;
      if (startBtn) {
        startBtn.innerHTML = '<i class="fa-solid fa-stop"></i> Stop Scanner';
        startBtn.style.background = '#ef4444';
      }

      codeReader.decodeFromVideoDevice(device.deviceId, 'qrGlobalVideo', (result, err) => {
        if (result) {
          const text = result.getText();
          const now  = Date.now();

          // Debounce: same code within 3 seconds = skip
          if (text === lastScanned && (now - lastScanTime) < 3000) return;
          lastScanned  = text;
          lastScanTime = now;

          if (resultText) resultText.textContent = '📷 Scanned: ' + text;
          if (resultBox) { resultBox.style.background='#fef9c3'; resultBox.style.color='#92400e'; }

          const eventId = parseInt(document.getElementById('scanEventSelect')?.value || '0');
          if (text.startsWith('BCP-EVENT-') || eventId > 0) {
            logAttendanceViaQr(text, eventId);
          } else {
            if (resultText) resultText.textContent = '⚠️ Scanned: ' + text + ' — Please select an event first!';
            if (resultBox) { resultBox.style.background='#fef2f2'; resultBox.style.color='#dc2626'; }
          }
        }
      });
    } catch (err) {
      console.error('QR Scanner error:', err);
      const msg = err.name === 'NotAllowedError'
        ? 'Camera access denied. Please allow camera access in your browser.'
        : 'Camera error: ' + (err.message || 'Unknown error');
      if (resultText) resultText.textContent = msg;
      if (resultBox) { resultBox.style.display='flex'; resultBox.style.background='#fef2f2'; resultBox.style.color='#dc2626'; }
      isScanning = false;
    }
  }

  function stopScanner() {
    if (codeReader) { codeReader.reset(); codeReader = null; }
    isScanning = false;
    const video       = document.getElementById('qrGlobalVideo');
    const placeholder = document.getElementById('cameraGlobalPlaceholder');
    const scanLine    = document.getElementById('qrGlobalScannerLine');
    const startBtn    = document.getElementById('startGlobalScanBtn');
    const resultBox   = document.getElementById('qrGlobalScanResult');
    const resultText  = document.getElementById('qrGlobalScanText');

    if (video)    { video.style.display='none'; video.srcObject=null; }
    if (placeholder) placeholder.style.setProperty('display', 'flex', 'important');
    if (scanLine) scanLine.style.display='none';
    if (resultBox){ resultBox.style.display='none'; }
    if (resultText) resultText.textContent = 'Ready to scan...';
    if (startBtn) {
      startBtn.innerHTML = '<i class="fa-solid fa-camera"></i> Start Camera Scanner';
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
        const ok = data.success || data.already_logged;
        if (resultText) resultText.textContent = (data.success ? '✅ ' : (data.already_logged ? '⚠️ ' : '❌ ')) + data.message;
        if (resultBox) {
          resultBox.style.background = data.success ? '#dcfce7' : (data.already_logged ? '#fef9c3' : '#fef2f2');
          resultBox.style.color      = data.success ? '#15803d' : (data.already_logged ? '#92400e' : '#dc2626');
          resultBox.style.display    = 'flex';
        }

        // Add to scan log
        const log = document.getElementById('scanLog');
        if (log && data.message) {
          const entry = document.createElement('div');
          entry.style.cssText = 'padding:4px 0;border-bottom:1px solid #f1f5f9;';
          entry.textContent = new Date().toLocaleTimeString() + ' — ' + data.message;
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
