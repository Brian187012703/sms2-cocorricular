<?php
// ============================================================
//  QR_MODAL.PHP (shared/)
//  Reusable Dual-Tab QR Code Modal & Camera Scanner
// ============================================================
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$qr_user_id   = $_SESSION['user_id'] ?? 0;
$qr_role      = $_SESSION['role'] ?? 'student';
$qr_first     = htmlspecialchars($_SESSION['first_name'] ?? 'User');
$qr_last      = htmlspecialchars($_SESSION['last_name']  ?? '');
$qr_code_str  = ($qr_role === 'student' ? 'BCP-STUDENT-' : 'BCP-STAFF-') . $qr_user_id;
$qr_role_lbl  = ucwords(str_replace('_', ' ', $qr_role));
$app_root_rel = $APP_ROOT ?? '../';
?>

<!-- Global Dual-Tab QR Modal -->
<div class="qr-modal-overlay" id="qrModalOverlay">
  <div class="qr-modal-card">
    <div class="qr-modal-header">
      <h3><i class="fa-solid fa-qrcode"></i> QR Code Center</h3>
      <button class="notif-close" id="closeQrModalBtn" title="Close" type="button">×</button>
    </div>
    <!-- Modal Tabs -->
    <div class="qr-modal-tabs">
      <button class="qr-tab-btn active" id="tabMyQr" type="button" onclick="switchGlobalQrTab('myqr')">
        <i class="fa-solid fa-id-card"></i> My QR Code
      </button>
      <button class="qr-tab-btn" id="tabScan" type="button" onclick="switchGlobalQrTab('scan')">
        <i class="fa-solid fa-camera"></i> Camera Scanner
      </button>
    </div>

    <!-- Tab 1: My QR Code -->
    <div class="qr-modal-body qr-tab-panel active" id="panelMyQr">
      <div class="qr-badge-role">
        <i class="fa-solid fa-id-badge"></i>
        <span><?= htmlspecialchars($qr_role_lbl) ?></span>
      </div>
      <div class="qr-code-frame">
        <div class="qr-scan-line"></div>
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?= urlencode($qr_code_str) ?>&margin=8&color=0f172a&bgcolor=ffffff"
             alt="Personal Attendance QR Code"/>
      </div>
      <div class="qr-student-name"><?= $qr_first . ' ' . $qr_last ?></div>
      <div class="qr-student-id">ID: <?= htmlspecialchars($qr_code_str) ?></div>
      <p class="qr-subtext">Present this QR code at campus event scanner stations for instant attendance logging.</p>
    </div>

    <!-- Tab 2: Camera Scanner -->
    <div class="qr-modal-body qr-tab-panel" id="panelScan" style="padding:16px 24px 24px;">
      <div class="qr-camera-wrap" id="qrCameraWrap">
        <video id="qrGlobalVideo" autoplay playsinline muted style="display:none; width:100%; height:100%; object-fit:cover; border-radius:12px;"></video>
        <canvas id="qrGlobalCanvas" style="display:none;"></canvas>
        <div class="qr-camera-overlay"></div>
        <div class="qr-scanner-line" id="qrGlobalScannerLine" style="display:none;"></div>
        <div class="qr-camera-placeholder" id="cameraGlobalPlaceholder">
          <i class="fa-solid fa-camera" style="font-size:2.5rem; color:#94a3b8; margin-bottom:8px;"></i>
          <span style="font-size:0.85rem; color:#64748b;">Camera scanner not active</span>
        </div>
      </div>
      <div class="qr-scanner-result" id="qrGlobalScanResult">
        <i class="fa-solid fa-circle-check"></i>
        <span id="qrGlobalScanText">Ready to scan...</span>
      </div>
      <button class="qr-scan-start-btn" id="startGlobalScanBtn" type="button">
        <i class="fa-solid fa-camera"></i> Start Camera Scanner
      </button>
      <p class="qr-subtext" style="margin-top:8px;">Point camera at any Student QR Code (<code>BCP-STUDENT-{id}</code>) to scan for attendance verification.</p>
    </div>
  </div>
</div>

<script src="https://unpkg.com/@zxing/library@0.21.1/umd/index.min.js"></script>
<script>
window.switchGlobalQrTab = function(tabName) {
  const tabMyQr = document.getElementById('tabMyQr');
  const tabScan = document.getElementById('tabScan');
  const panelMyQr = document.getElementById('panelMyQr');
  const panelScan = document.getElementById('panelScan');

  if (tabName === 'myqr') {
    tabMyQr?.classList.add('active');
    tabScan?.classList.remove('active');
    panelMyQr?.classList.add('active');
    panelScan?.classList.remove('active');
    if (window.stopGlobalQrScanner) window.stopGlobalQrScanner();
  } else {
    tabScan?.classList.add('active');
    tabMyQr?.classList.remove('active');
    panelScan?.classList.add('active');
    panelMyQr?.classList.remove('active');
  }
};

(function() {
  let codeReader = null;
  let isScanning = false;

  function initQrHandlers() {
    const overlay = document.getElementById('qrModalOverlay');
    const closeBtn = document.getElementById('closeQrModalBtn');
    const startScanBtn = document.getElementById('startGlobalScanBtn');

    // Bind all buttons with id="qrFabBtn" or class="topbar-qr-btn"
    document.querySelectorAll('#qrFabBtn, .topbar-qr-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        if (overlay) overlay.classList.add('active');
      });
    });

    closeBtn?.addEventListener('click', () => {
      if (overlay) overlay.classList.remove('active');
      stopScanner();
    });

    overlay?.addEventListener('click', (e) => {
      if (e.target === overlay) {
        overlay.classList.remove('active');
        stopScanner();
      }
    });

    startScanBtn?.addEventListener('click', () => {
      if (isScanning) {
        stopScanner();
      } else {
        startScanner();
      }
    });
  }

  async function startScanner() {
    const video = document.getElementById('qrGlobalVideo');
    const placeholder = document.getElementById('cameraGlobalPlaceholder');
    const scannerLine = document.getElementById('qrGlobalScannerLine');
    const resultBox = document.getElementById('qrGlobalScanResult');
    const resultText = document.getElementById('qrGlobalScanText');
    const startScanBtn = document.getElementById('startGlobalScanBtn');

    if (!window.ZXing) {
      if (resultText) resultText.textContent = 'Scanner library loading... Please retry.';
      return;
    }

    try {
      codeReader = new ZXing.BrowserMultiFormatReader();
      const videoDevices = await codeReader.listVideoInputDevices();

      if (!videoDevices || videoDevices.length === 0) {
        if (resultText) resultText.textContent = 'No camera device detected.';
        return;
      }

      const selectedDeviceId = videoDevices[0].deviceId;
      if (placeholder) placeholder.style.display = 'none';
      if (video) video.style.display = 'block';
      if (scannerLine) scannerLine.style.display = 'block';

      isScanning = true;
      if (startScanBtn) {
        startScanBtn.innerHTML = '<i class="fa-solid fa-stop"></i> Stop Camera Scanner';
        startScanBtn.style.background = '#ef4444';
      }

      codeReader.decodeFromVideoDevice(selectedDeviceId, 'qrGlobalVideo', (result, err) => {
        if (result) {
          const text = result.getText();
          if (resultText) resultText.textContent = 'Scanned: ' + text;
          if (resultBox) {
            resultBox.style.display = 'flex';
            resultBox.style.background = '#dcfce7';
            resultBox.style.color = '#15803d';
          }
          const eventSelect = document.getElementById('eventSelect') || document.getElementById('qrEventId');
          const eventId = eventSelect ? eventSelect.value : 0;
          if (eventId > 0) {
            logAttendanceViaQr(text, eventId);
          }
        }
      });
    } catch (err) {
      console.error('QR Scanner error:', err);
      if (resultText) resultText.textContent = 'Camera access denied or unavailable.';
    }
  }

  function stopScanner() {
    if (codeReader) {
      codeReader.reset();
      codeReader = null;
    }
    isScanning = false;
    const video = document.getElementById('qrGlobalVideo');
    const placeholder = document.getElementById('cameraGlobalPlaceholder');
    const scannerLine = document.getElementById('qrGlobalScannerLine');
    const startScanBtn = document.getElementById('startGlobalScanBtn');

    if (video) video.style.display = 'none';
    if (placeholder) placeholder.style.display = 'flex';
    if (scannerLine) scannerLine.style.display = 'none';
    if (startScanBtn) {
      startScanBtn.innerHTML = '<i class="fa-solid fa-camera"></i> Start Camera Scanner';
      startScanBtn.style.background = '#2563eb';
    }
  }

  window.stopGlobalQrScanner = stopScanner;

  function logAttendanceViaQr(qrData, eventId) {
    const fd = new FormData();
    fd.append('action', 'log_qr');
    fd.append('qr_data', qrData);
    fd.append('event_id', eventId);

    fetch('<?= $app_root_rel ?>shared/attendance_actions.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        const resultText = document.getElementById('qrGlobalScanText');
        if (resultText) resultText.textContent = (data.success ? '✅ ' : '❌ ') + data.message;
      })
      .catch(() => {});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initQrHandlers);
  } else {
    initQrHandlers();
  }
})();
</script>
