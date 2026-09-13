<?php
// ============================================================
//  BROADCASTS.PHP
//  RBAC: Students see org rooms they belong to
//        Officers see their org + can broadcast to members
//        SSC/Admin see all rooms
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();
// Inter-Club Messenger has been disabled system-wide per system specification.
header('Location: dashboard.php');
exit;

$sess_first = htmlspecialchars($_SESSION['first_name'] ?? 'User');
$sess_last = htmlspecialchars($_SESSION['last_name'] ?? '');
$sess_role = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id = $_SESSION['user_id'] ?? 1;

// -- RBAC: determine which channels this role can see --------
// In production this would be dynamic via DB membership queries.
// Here we use representative mock channels per role.
$all_channels = [
  ['id' => 'acads', 'acronym' => 'ACADS', 'name' => 'Association of Computer Engineering Academic Driven Students', 'members' => 34, 'last' => 'Reminder: Submit your term papers by Friday.', 'time' => '10:42 AM', 'unread' => 2],
  ['id' => 'aces', 'acronym' => 'ACES', 'name' => 'Association of Computer Engineering Students', 'members' => 58, 'last' => 'General Assembly this Saturday at 9AM.', 'time' => '9:15 AM', 'unread' => 5],
  ['id' => 'gems', 'acronym' => 'GEMs', 'name' => 'Guild of English Majors', 'members' => 29, 'last' => 'Debate practice moved to Wednesday.', 'time' => 'Yesterday', 'unread' => 0],
  ['id' => 'jfinex', 'acronym' => 'JFINEX', 'name' => 'Junior Financial Executives', 'members' => 41, 'last' => 'Finance seminar registration is open!', 'time' => 'Yesterday', 'unread' => 1],
  ['id' => 'sigma', 'acronym' => 'SIGMA', 'name' => "Students' Interactive Guild for Mathematics Major", 'members' => 27, 'last' => 'Problem set solutions posted on drive.', 'time' => 'Mon', 'unread' => 0],
  ['id' => 'dlc', 'acronym' => 'DLC', 'name' => 'Drum and Lyre Corporation', 'members' => 45, 'last' => 'Rehearsal schedule updated. Check pinned.', 'time' => 'Mon', 'unread' => 3],
  ['id' => 'cdc', 'acronym' => 'CDC', 'name' => 'Criminology Dance Company', 'members' => 22, 'last' => 'Costume fittings: Thu 25 PM.', 'time' => 'Sun', 'unread' => 0],
  ['id' => 'newslink', 'acronym' => 'NEWSLINK', 'name' => 'Newslink: The School Publications', 'members' => 18, 'last' => 'Issue draft due by Jul 28.', 'time' => 'Sun', 'unread' => 0],
];

// RBAC filter
if ($sess_role === 'student') {
  // Students only see 2 sample channels (their org memberships)
  $channels = array_slice($all_channels, 0, 2);
} elseif ($sess_role === 'club_adviser') {
  $channels = array_slice($all_channels, 0, 5);
} else {
  // SSC / Admin / Finance see all
  $channels = $all_channels;
}

// Mock messages for the active channel (first one)
$mock_messages = [
  ['sender' => 'Maria Santos', 'initial' => 'M', 'text' => 'Good morning everyone! Reminder about the upcoming midterm evaluations.', 'time' => '9:04 AM', 'self' => false],
  ['sender' => 'James Reyes', 'initial' => 'J', 'text' => 'Thanks for the heads up! Do we need to submit the activity log before or after?', 'time' => '9:07 AM', 'self' => false],
  ['sender' => $sess_first, 'initial' => $sess_initial, 'text' => 'I believe it\'s before the exam week based on the last announcement.', 'time' => '9:10 AM', 'self' => true],
  ['sender' => 'Maria Santos', 'initial' => 'M', 'text' => 'Correct! Please check the bulletin board for the exact deadline.', 'time' => '9:12 AM', 'self' => false],
  ['sender' => 'Leo Cruz', 'initial' => 'L', 'text' => 'Got it. Also, the org funds request form is now available on the shared drive.', 'time' => '9:18 AM', 'self' => false],
  ['sender' => $sess_first, 'initial' => $sess_initial, 'text' => 'Perfect, I\'ll fill it out today. Thank you!', 'time' => '9:20 AM', 'self' => true],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Announcements & Broadcasts � BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <style>
    .channel-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px;
      border-bottom: 1px solid #f1f5f9;
      cursor: pointer;
      transition: background 0.15s;
      border-left: 3px solid transparent;
    }

    .channel-item:hover {
      background: #f8fafc;
    }

    .channel-item.active {
      background: #eff6ff;
      border-left-color: #2563eb;
    }

    .channel-avatar {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      background: #1a3a8c;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 0.85rem;
      flex-shrink: 0;
    }

    .channel-name {
      font-size: 0.88rem;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 2px;
    }

    .channel-last {
      font-size: 0.78rem;
      color: #64748b;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 200px;
    }

    .channel-meta {
      margin-left: auto;
      text-align: right;
      flex-shrink: 0;
    }

    .channel-time {
      font-size: 0.7rem;
      color: #94a3b8;
    }

    .channel-badge {
      background: #ef4444;
      color: #fff;
      font-size: 0.7rem;
      font-weight: 700;
      padding: 1px 6px;
      border-radius: 10px;
      margin-top: 4px;
      display: inline-block;
    }

    .chat-bubble {
      max-width: 70%;
      padding: 10px 14px;
      border-radius: 14px;
      margin-bottom: 12px;
      font-size: 0.88rem;
      line-height: 1.45;
    }

    .chat-bubble.sent {
      background: #2563eb;
      color: #fff;
      margin-left: auto;
      border-bottom-right-radius: 2px;
    }

    .chat-bubble.received {
      background: #f1f5f9;
      color: #1e293b;
      border-bottom-left-radius: 2px;
    }

    .chat-bubble .sender {
      font-size: 0.72rem;
      font-weight: 700;
      color: #93c5fd;
      margin-bottom: 3px;
    }

    .chat-bubble.received .sender {
      color: #64748b;
    }

    .chat-bubble .time {
      font-size: 0.68rem;
      opacity: 0.75;
      margin-top: 4px;
      text-align: right;
    }
  </style>
</head>

<body>
  <?php $APP_ROOT = '../';
  $ACTIVE_NAV = 'broadcasts';
  require_once __DIR__ . '/../shared/sidebar.php'; ?>

  <div class="main">
    <div class="topbar">
      <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
      <span class="topbar-spacer"></span>
      <div class="topbar-right">
        <div class="search-wrap">
          <input type="text" placeholder="Search pages, events..." autocomplete="off" />
          <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i
            class="fa-solid fa-qrcode"></i></button>
        <a href="../dashboard/account.php" class="avatar" id="avatarBtn" title="Account Settings">
          <?php if (!empty($sess_pic) && file_exists(__DIR__ . '/../uploads/avatars/' . $sess_pic)): ?>
            <img src="../uploads/avatars/<?= htmlspecialchars($sess_pic) ?>" alt="Profile"/>
          <?php else: ?>
            <?= $sess_initial ?>
          <?php endif; ?>
        </a>
      </div>
    </div>

    <div class="content"
      style="padding:16px 20px; display:flex; flex-direction:column; height:calc(100vh - 60px); overflow:hidden;">

      <div style="margin-bottom:12px;">
        <h2
          style="font-size:1.25rem; font-weight:800; color:#1e3a8a; margin:0; display:flex; align-items:center; gap:8px;">
          <i class="fa-solid fa-bullhorn" style="color:#2563eb;"></i> Announcements &amp; Campus Broadcasts
        </h2>
        <div style="font-size:0.8rem; color:#64748b; margin-top:3px;">
          <?php
          $label_map = [
            'student' => 'You can message members of your enrolled organizations.',
            'club_adviser' => 'View and moderate your advised organization channels.',
            'ssc' => 'Full access to all organization channels.',
            'ssc' => 'Read-only access to organization communication feeds.',
            'admin' => 'Full system access to all messaging channels.',
          ];
          echo $label_map[$sess_role] ?? '';
          ?>
        </div>
      </div>

      <!-- Two-pane Messenger -->
      <div class="messenger-wrap" style="flex:1; min-height:0;">

        <!-- Left: Channel List -->
        <div class="messenger-sidebar">
          <div class="messenger-sidebar-header">
            <span class="messenger-sidebar-title">
              <i class="fa-solid fa-layer-group" style="color:#2563eb; margin-right:6px;"></i>
              My Channels
            </span>
            <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
              <button class="chat-header-btn" title="New Broadcast"
                onclick="alert('Compose a new broadcast to all your org members.')">
                <i class="fa-solid fa-plus"></i>
              </button>
            <?php endif; ?>
          </div>
          <div class="messenger-search">
            <input type="text" placeholder="Search channels..." id="channelSearch"
              oninput="filterChannels(this.value)" />
          </div>
          <div class="messenger-channel-list" id="channelList">
            <?php foreach ($channels as $i => $ch): ?>
              <div class="messenger-channel-item <?= $i === 0 ? 'active' : '' ?>"
                onclick="selectChannel(this, '<?= htmlspecialchars($ch['acronym'], ENT_QUOTES) ?>', '<?= htmlspecialchars($ch['name'], ENT_QUOTES) ?>', <?= $ch['members'] ?>)"
                data-search="<?= htmlspecialchars(strtolower($ch['name'] . ' ' . $ch['acronym'])) ?>">
                <div class="channel-avatar"><?= htmlspecialchars($ch['acronym']) ?></div>
                <div class="channel-info">
                  <div class="channel-name"><?= htmlspecialchars($ch['acronym']) ?> &mdash;
                    <?= htmlspecialchars(substr($ch['name'], 0, 26)) ?>  <?= strlen($ch['name']) > 26 ? '�' : '' ?></div>
                  <div class="channel-last-msg"><?= htmlspecialchars($ch['last']) ?></div>
                </div>
                <div class="channel-meta">
                  <span class="channel-time"><?= htmlspecialchars($ch['time']) ?></span>
                  <?php if ($ch['unread'] > 0): ?>
                    <span class="channel-unread"><?= $ch['unread'] ?></span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Right: Chat Window -->
        <div class="messenger-chat">
          <!-- Header -->
          <div class="messenger-chat-header" id="chatHeader">
            <div class="chat-header-avatar" id="chatAvatar"><?= htmlspecialchars($channels[0]['acronym'] ?? 'ORG') ?>
            </div>
            <div class="chat-header-info">
              <div class="chat-header-name" id="chatName">
                <?= htmlspecialchars($channels[0]['name'] ?? 'Select a channel') ?></div>
              <div class="chat-header-members" id="chatMembers"><i class="fa-solid fa-users"
                  style="font-size:.7rem;"></i> <?= $channels[0]['members'] ?? 0 ?> members</div>
            </div>
            <div class="chat-header-actions">
              <button class="chat-header-btn" title="Search in conversation"><i
                  class="fa-solid fa-magnifying-glass"></i></button>
              <button class="chat-header-btn" title="Channel Info"><i class="fa-solid fa-circle-info"></i></button>
            </div>
          </div>

          <!-- Messages -->
          <div class="messenger-messages" id="messagesPane">
            <div class="msg-day-label">Today &mdash; <?= date('F j, Y') ?></div>

            <?php foreach ($mock_messages as $msg): ?>
              <div class="msg-row <?= $msg['self'] ? 'self' : '' ?>">
                <?php if (!$msg['self']): ?>
                  <div class="msg-avatar"><?= htmlspecialchars($msg['initial']) ?></div>
                <?php endif; ?>
                <div class="msg-body">
                  <?php if (!$msg['self']): ?>
                    <div class="msg-sender"><?= htmlspecialchars($msg['sender']) ?></div>
                  <?php endif; ?>
                  <div class="msg-bubble"><?= htmlspecialchars($msg['text']) ?></div>
                  <div class="msg-time"><?= htmlspecialchars($msg['time']) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Input bar -->
          <?php
          $can_send = in_array($sess_role, ['student', 'club_adviser', 'ssc', 'admin']);
          ?>
          <?php if ($can_send): ?>
            <div class="messenger-input-bar">
              <button class="msg-attach-btn" title="Attach file"><i class="fa-solid fa-paperclip"></i></button>
              <div class="msg-input-wrap">
                <input type="text" id="msgInput" placeholder="Type a message to your org channel..."
                  onkeydown="if(event.key==='Enter') sendMessage()" />
              </div>
              <button class="msg-send-btn" onclick="sendMessage()" title="Send"><i
                  class="fa-solid fa-paper-plane"></i></button>
            </div>
          <?php else: ?>
            <div
              style="padding:12px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; font-size:0.82rem; color:#64748b; text-align:center;">
              <i class="fa-solid fa-lock" style="margin-right:4px;"></i> Read-only access for your role.
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <div class="footer" style="flex-shrink:0;">eLearning Commons &copy; 2026</div>
  </div>

  <script src="../js/dashboard.js"></script>
  <script>
    // -- Messenger functions --
    function selectChannel(el, acronym, name, members) {
      document.querySelectorAll('.messenger-channel-item').forEach(i => i.classList.remove('active'));
      el.classList.add('active');
      document.getElementById('chatAvatar').textContent = acronym;
      document.getElementById('chatName').textContent = name;
      document.getElementById('chatMembers').innerHTML = '<i class="fa-solid fa-users" style="font-size:.7rem;"></i> ' + members + ' members';
    }
    function sendMessage() {
      const input = document.getElementById('msgInput');
      const text = input.value.trim();
      if (!text) return;
      const pane = document.getElementById('messagesPane');
      const initial = '<?= $sess_initial ?>';
      const row = document.createElement('div');
      row.className = 'msg-row self';
      row.innerHTML = `<div class="msg-body"><div class="msg-bubble">${text}</div><div class="msg-time">Just now</div></div>`;
      pane.appendChild(row);
      pane.scrollTop = pane.scrollHeight;
      input.value = '';
    }
    function filterChannels(q) {
      q = q.toLowerCase().trim();
      document.querySelectorAll('.messenger-channel-item').forEach(item => {
        item.style.display = (!q || item.dataset.search.includes(q)) ? '' : 'none';
      });
    }

    <?php if ($sess_role === 'student'): ?>
        (function () {
          const qrBtn = document.getElementById('qrFabBtn'), overlay = document.getElementById('qrModalOverlay'), closeBtn = document.getElementById('closeQrModalBtn');
          let reader = null;
          function open() { overlay.classList.add('active'); }
          function close() { overlay.classList.remove('active'); stop(); }
          if (qrBtn) qrBtn.addEventListener('click', open);
          closeBtn?.addEventListener('click', close);
          overlay?.addEventListener('click', e => { if (e.target === overlay) close(); });
          document.getElementById('startScanBtn')?.addEventListener('click', startScan);
          async function startScan() {
            const v = document.getElementById('qrVideo'), p = document.getElementById('cameraPlaceholder'), sl = document.getElementById('qrScannerLine'), btn = document.getElementById('startScanBtn'), res = document.getElementById('qrScanResult'), rt = document.getElementById('qrScanText');
            if (typeof ZXing === 'undefined') { alert('Scanner not loaded.'); return; }
            try {
              reader = new ZXing.BrowserQRCodeReader(); btn.textContent = 'Scanning...'; btn.disabled = true;
              v.style.display = 'block'; p.style.display = 'none'; sl.style.display = 'block';
              const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
              v.srcObject = stream;
              reader.decodeFromVideoElement(v, (result) => { if (result) { rt.textContent = result.getText(); res.classList.add('active'); } });
            } catch (e) { alert('Camera access denied.'); btn.textContent = 'Start Camera Scanner'; btn.disabled = false; }
          }
          function stop() {
            if (reader) { reader.reset(); reader = null; }
            const v = document.getElementById('qrVideo');
            if (v?.srcObject) { v.srcObject.getTracks().forEach(t => t.stop()); v.srcObject = null; }
            const p = document.getElementById('cameraPlaceholder'), sl = document.getElementById('qrScannerLine'), btn = document.getElementById('startScanBtn');
            if (v) v.style.display = 'none'; if (p) p.style.display = 'flex'; if (sl) sl.style.display = 'none';
            if (btn) { btn.textContent = 'Start Camera Scanner'; btn.disabled = false; }
          }
        })();
      function switchQrTab(tab) {
        document.querySelectorAll('.qr-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.qr-tab-panel').forEach(p => p.classList.remove('active'));
        document.getElementById(tab === 'myqr' ? 'tabMyQr' : 'tabScan').classList.add('active');
        document.getElementById(tab === 'myqr' ? 'panelMyQr' : 'panelScan').classList.add('active');
      }
    <?php endif; ?>
  </script>
</body>

</html>
