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
      </div>
    </div>

    <!-- Tab 1: My QR Code -->
    <div class="qr-modal-body qr-tab-panel active" id="panelMyQr">
      <div class="qr-badge-role">
        <i class="fa-solid fa-id-badge"></i>
        <span><?= htmlspecialchars($qr_role_lbl) ?></span>
      </div>
      <div class="qr-code-frame" id="qrCodeFrame">
        <div class="qr-scan-line"></div>
        <div id="myQrCanvasBox" style="width:200px; height:200px; display:flex; align-items:center; justify-content:center; margin:auto;"></div>
        <img id="qrCodeImg" style="display:none; width:200px; height:200px; object-fit:contain;" alt="Personal Attendance QR Code" />
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
function renderMyPersonalQr() {
  const box = document.getElementById('myQrCanvasBox');
  const img = document.getElementById('qrCodeImg');
  const payload = '<?= $qr_code_str ?>';
  if (box && window.QRCode) {
    box.innerHTML = '';
    new QRCode(box, {
      text: payload,
      width: 190,
      height: 190,
      colorDark: "#0f172a",
      colorLight: "#ffffff",
      correctLevel: QRCode.CorrectLevel.H
    });
    setTimeout(() => {
      const generated = box.querySelector('canvas') || box.querySelector('img');
      if (generated && img) {
        img.src = (generated.tagName === 'CANVAS') ? generated.toDataURL('image/png') : generated.src;
      }
    }, 100);
  } else if (img) {
    img.style.display = 'block';
    img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(payload) + '&margin=8&color=0f172a&bgcolor=ffffff';
  }
}

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
  const panelMyQr = document.getElementById('panelMyQr');
  const panelScan = document.getElementById('panelScan');
  if (panelMyQr) panelMyQr.classList.toggle('active', tabName === 'myqr');
  if (panelScan) panelScan.classList.toggle('active', tabName === 'scan');
  if (tabName === 'myqr') {
    renderMyPersonalQr();
    if (typeof window.stopGlobalQrScanner === 'function') {
      window.stopGlobalQrScanner();
    }
  }
};

function downloadQR() {
  const box = document.getElementById('myQrCanvasBox');
  const canvas = box ? box.querySelector('canvas') : null;
  const img = box ? box.querySelector('img') : document.getElementById('qrCodeImg');
  const src = canvas ? canvas.toDataURL('image/png') : (img ? img.src : '');
  if (!src) return;
  const link = document.createElement('a');
  link.href = src;
  link.download = 'BCP-QR-<?= $qr_code_str ?>.png';
  link.click();
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
          entry.style.cssText = 'padding:4px 0;border-bottom:1px solid #f1f5f9;font-size:0.75rem;';
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
