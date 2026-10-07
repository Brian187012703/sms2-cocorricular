<?php
// app/shared/chat_modal.php — Inter-Club Communication Portal Modal
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

$chat_sess_user_id = $_SESSION['user_id'] ?? 0;
$chat_sess_role    = $_SESSION['role'] ?? 'student';
$chat_sess_name    = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$app_root_chat     = isset($APP_ROOT) ? $APP_ROOT : (defined('APP_ROOT') ? APP_ROOT : '../');

$user_club_code = '';
$user_club_name = '';

if ($chat_sess_role === 'student' && $chat_sess_user_id > 0) {
    $c_q = $conn->query("
        SELECT c.code, c.name FROM club_memberships cm
        JOIN clubs c ON c.id = cm.club_id
        WHERE cm.user_id = {$chat_sess_user_id} AND LOWER(cm.status) IN ('active', 'approved')
        LIMIT 1
    ");
    if ($c_q && $c_row = $c_q->fetch_assoc()) {
        $user_club_code = $c_row['code'];
        $user_club_name = $c_row['name'];
    }
} elseif ($chat_sess_role === 'club_adviser' && $chat_sess_user_id > 0) {
    $c_q = $conn->query("
        SELECT c.code, c.name FROM clubs c
        WHERE (c.adviser_user_id = {$chat_sess_user_id} OR EXISTS (
            SELECT 1 FROM club_memberships cm WHERE cm.club_id = c.id AND cm.user_id = {$chat_sess_user_id} AND cm.role IN ('Adviser', 'Club Adviser') AND LOWER(cm.status) IN ('active', 'approved')
        )) AND c.deleted_at IS NULL
        LIMIT 1
    ");
    if ($c_q && $c_row = $c_q->fetch_assoc()) {
        $user_club_code = $c_row['code'];
        $user_club_name = $c_row['name'];
    }
}
?>

<!-- ── INTER-CLUB CHAT MODAL ── -->
<div class="chat-modal-overlay modal-overlay" id="interClubChatModalOverlay" aria-modal="true" role="dialog">
  <div class="chat-modal-card">

    <!-- Modal Left Sidebar -->
    <div class="chat-sidebar-pane">
      <!-- Sidebar Header -->
      <div class="chat-sidebar-header">
        <div class="chat-app-brand">
          <div class="chat-brand-icon">
            <i class="fa-solid fa-comment-dots"></i>
          </div>
          <div class="chat-brand-meta">
            <h3 class="chat-brand-title">Inter-Club Chat</h3>
          </div>
        </div>
      </div>

      <!-- Search Input -->
      <div class="chat-search-wrap">
        <i class="fa-solid fa-magnifying-glass chat-search-icon"></i>
        <input type="text" id="chatSearchInput"
          placeholder="Search..."
          oninput="onChatSearch(this.value)" autocomplete="off" />
      </div>

      <!-- Filter Tabs / Pills -->
      <div class="chat-filter-pills">
        <button type="button" class="chat-filter-btn active" id="chatFilterGroups" onclick="switchChatFilter('groups')">
          <i class="fa-solid fa-users"></i> Groups
        </button>
        <button type="button" class="chat-filter-btn" id="chatFilterDirect" onclick="switchChatFilter('direct')">
          <i class="fa-solid fa-comment"></i> Direct
        </button>
        <button type="button" class="chat-filter-btn" id="chatFilterDirectory" onclick="switchChatFilter('directory')">
          <i class="fa-solid fa-address-book"></i> Directory
        </button>
      </div>

      <!-- Channels / Contact List -->
      <div class="chat-channel-list" id="chatChannelList">
        <div class="chat-loading-state">
          <i class="fa-solid fa-circle-notch fa-spin"></i> Loading conversations...
        </div>
      </div>
    </div>

    <!-- Modal Right Content Pane -->
    <div class="chat-main-pane">
      <!-- Close Button (Pinned Top Right) -->
      <button type="button" class="chat-modal-close" id="chatModalCloseBtn" onclick="closeInterClubChatModal()" aria-label="Close Modal" title="Close">
        <i class="fa-solid fa-xmark"></i>
      </button>

      <!-- ACTIVE CONVERSATION PANE -->
      <div class="chat-active-view" id="chatActiveView">
        <!-- Conversation Header -->
        <div class="chat-conversation-header">
          <div class="chat-active-avatar" id="chatActiveAvatar"><?= htmlspecialchars(substr($user_club_code ?: 'IC', 0, 2)) ?></div>
          <div class="chat-active-meta">
            <h4 class="chat-active-title" id="chatActiveTitle"><?= htmlspecialchars($user_club_name ?: 'Inter-Club Conversation') ?></h4>
            <span class="chat-active-subtitle" id="chatActiveSubtitle"></span>
          </div>
        </div>

        <!-- Messages Thread -->
        <div class="chat-messages-wrap" id="chatMessagesWrap">
          <div class="chat-loading-state">
            <i class="fa-solid fa-circle-notch fa-spin"></i> Loading messages...
          </div>
        </div>

        <!-- Message Input Bar -->
        <form class="chat-input-bar" id="chatInputForm" onsubmit="handleSendChatMessage(event)">
          <div class="chat-input-field-wrap">
            <input type="text" id="chatMessageInput" placeholder="Type a message..." autocomplete="off" />
          </div>
          <button type="submit" class="chat-send-btn" id="chatSendBtn" title="Send Message" aria-label="Send">
            <i class="fa-solid fa-paper-plane"></i>
          </button>
        </form>
      </div>

    </div>

  </div>
</div>

<script>
(function() {
  const CHAT_API_URL = '<?= $app_root_chat ?>shared/chat_actions.php';
  const CURRENT_USER_ID = <?= (int)$chat_sess_user_id ?>;
  const CURRENT_USER_ROLE = '<?= htmlspecialchars($chat_sess_role) ?>';

  let allChannels = [];
  let directoryContacts = [];
  let activeChannelId = null;
  let activeFilter = 'groups'; // 'groups', 'direct', 'directory'
  let chatPollingTimer = null;
  let unreadBadgeTimer = null;

  // Global open / close
  window.openInterClubChatModal = function(targetChannelId = null) {
    const overlay = document.getElementById('interClubChatModalOverlay');
    if (!overlay) return;
    overlay.classList.add('active');
    overlay.style.removeProperty('display');
    document.body.classList.add('chat-modal-open');

    loadChannelsList().then(() => {
      if (targetChannelId) {
        selectChatChannel(targetChannelId);
      } else if (!activeChannelId && allChannels.length > 0) {
        const groups = allChannels.filter(c => c.type === 'club_group' || c.type === 'adviser_ssc');
        if (groups.length > 0) {
          selectChatChannel(groups[0].id);
        } else {
          selectChatChannel(allChannels[0].id);
        }
      }
    });

    startChatPolling();
  };

  window.closeInterClubChatModal = function() {
    const overlay = document.getElementById('interClubChatModalOverlay');
    if (overlay) {
      overlay.classList.remove('active', 'open');
      overlay.style.removeProperty('display');
    }
    document.body.classList.remove('chat-modal-open');
    stopChatPolling();
    checkUnreadBadge();
  };

  window.switchChatFilter = function(filter) {
    activeFilter = filter;
    document.getElementById('chatFilterGroups')?.classList.toggle('active', filter === 'groups');
    document.getElementById('chatFilterDirect')?.classList.toggle('active', filter === 'direct');
    document.getElementById('chatFilterDirectory')?.classList.toggle('active', filter === 'directory');

    const searchInput = document.getElementById('chatSearchInput');
    if (searchInput) {
      searchInput.value = '';
      if (filter === 'directory') {
        searchInput.placeholder = 'Search contacts directory...';
      } else if (filter === 'direct') {
        searchInput.placeholder = 'Search direct messages...';
      } else {
        searchInput.placeholder = CURRENT_USER_ROLE === 'student' ? 'Search club channels...' : 'Search channels...';
      }
    }

    if (filter === 'directory') {
      showDirectoryHeroView();
      loadDirectory();
    } else if (filter === 'direct') {
      renderChannels();
      const directChannels = allChannels.filter(c => c.type === 'direct');
      const isCurrentActiveDirect = directChannels.some(c => parseInt(c.id) === activeChannelId);
      if (isCurrentActiveDirect && activeChannelId) {
        selectChatChannel(activeChannelId);
      } else if (directChannels.length > 0) {
        selectChatChannel(directChannels[0].id);
      } else {
        showNoDirectSelectedView();
      }
    } else {
      // 'groups'
      renderChannels();
      const groupChannels = allChannels.filter(c => c.type === 'club_group' || c.type === 'adviser_ssc');
      const isCurrentActiveGroup = groupChannels.some(c => parseInt(c.id) === activeChannelId);
      if (isCurrentActiveGroup && activeChannelId) {
        selectChatChannel(activeChannelId);
      } else if (groupChannels.length > 0) {
        selectChatChannel(groupChannels[0].id);
      } else {
        showNoGroupSelectedView();
      }
    }
  };

  window.onChatSearch = function(query) {
    const q = (query || '').toLowerCase().trim();
    if (activeFilter === 'directory') {
      renderDirectory(q);
    } else {
      renderChannels(q);
    }
  };

  window.showChatHero = function() {
    if (allChannels.length > 0) {
      selectChatChannel(allChannels[0].id);
    }
  };

  window.startFirstConversation = function() {
    if (allChannels.length > 0) {
      selectChatChannel(allChannels[0].id);
    } else {
      switchChatFilter('directory');
    }
  };

  function showDirectoryHeroView() {
    activeChannelId = null;
    const titleEl = document.getElementById('chatActiveTitle');
    const subEl = document.getElementById('chatActiveSubtitle');
    const avEl = document.getElementById('chatActiveAvatar');
    const wrap = document.getElementById('chatMessagesWrap');
    const inputForm = document.getElementById('chatInputForm');

    if (titleEl) titleEl.textContent = 'Directory';
    if (subEl) subEl.textContent = '';
    if (avEl) avEl.innerHTML = '<i class="fa-solid fa-address-book" style="font-size:1.05rem;"></i>';
    if (inputForm) inputForm.style.display = 'none';

    if (wrap) {
      wrap.innerHTML = `
        <div class="chat-messages-empty" style="margin:auto; text-align:center; padding:30px 20px;">
          <i class="fa-solid fa-address-book" style="font-size:2.2rem; color:#2563eb; opacity:0.7; margin-bottom:10px; display:block;"></i>
          <h4 style="margin:0; color:#0f172a; font-size:0.95rem;">Select a contact to message</h4>
        </div>
      `;
    }
  }

  function showNoDirectSelectedView() {
    activeChannelId = null;
    const titleEl = document.getElementById('chatActiveTitle');
    const subEl = document.getElementById('chatActiveSubtitle');
    const avEl = document.getElementById('chatActiveAvatar');
    const wrap = document.getElementById('chatMessagesWrap');
    const inputForm = document.getElementById('chatInputForm');

    if (titleEl) titleEl.textContent = 'Direct Messages';
    if (subEl) subEl.textContent = '';
    if (avEl) avEl.textContent = 'DM';
    if (inputForm) inputForm.style.display = 'none';

    if (wrap) {
      wrap.innerHTML = `
        <div class="chat-messages-empty" style="margin:auto; text-align:center; padding:30px 20px;">
          <i class="fa-solid fa-comments" style="font-size:2.2rem; color:#2563eb; opacity:0.7; margin-bottom:10px; display:block;"></i>
          <h4 style="margin:0 0 10px 0; color:#0f172a; font-size:0.95rem;">No direct messages yet</h4>
          <button type="button" class="btn-chat-outline-sm" style="display:inline-flex; align-items:center; gap:6px;" onclick="switchChatFilter('directory')">
            <i class="fa-solid fa-plus"></i> Open Directory
          </button>
        </div>
      `;
    }
  }

  function showNoGroupSelectedView() {
    activeChannelId = null;
    const titleEl = document.getElementById('chatActiveTitle');
    const subEl = document.getElementById('chatActiveSubtitle');
    const avEl = document.getElementById('chatActiveAvatar');
    const wrap = document.getElementById('chatMessagesWrap');
    const inputForm = document.getElementById('chatInputForm');

    if (titleEl) titleEl.textContent = 'Channels';
    if (subEl) subEl.textContent = '';
    if (avEl) avEl.textContent = 'IC';
    if (inputForm) inputForm.style.display = 'none';

    if (wrap) {
      wrap.innerHTML = `
        <div class="chat-messages-empty" style="margin:auto; text-align:center; padding:30px 20px;">
          <i class="fa-solid fa-users" style="font-size:2.2rem; color:#64748b; opacity:0.6; margin-bottom:10px; display:block;"></i>
          <h4 style="margin:0; color:#0f172a; font-size:0.95rem;">No channels available</h4>
        </div>
      `;
    }
  }

  async function loadChannelsList() {
    try {
      const res = await fetch(`${CHAT_API_URL}?action=get_channels`);
      const data = await res.json();
      if (data.success && Array.isArray(data.channels)) {
        allChannels = data.channels;
        // Only update channel list rendering if not browsing Directory
        if (activeFilter !== 'directory') {
          const searchVal = document.getElementById('chatSearchInput')?.value || '';
          renderChannels(searchVal);
        }
      }
    } catch (e) {
      console.warn('Failed to load chat channels:', e);
    }
  }

  function renderChannels(searchFilter = '') {
    if (activeFilter === 'directory') return;
    const container = document.getElementById('chatChannelList');
    if (!container) return;

    let filtered = allChannels.filter(c => {
      if (activeFilter === 'groups') return c.type === 'club_group' || c.type === 'adviser_ssc';
      if (activeFilter === 'direct') return c.type === 'direct';
      return true;
    });

    if (searchFilter) {
      filtered = filtered.filter(c => (c.name || '').toLowerCase().includes(searchFilter) || (c.last_message || '').toLowerCase().includes(searchFilter));
    }

    if (filtered.length === 0) {
      container.innerHTML = `
        <div class="chat-empty-list">
          <i class="fa-solid fa-comments"></i>
          <p>No ${activeFilter === 'direct' ? 'direct messages' : 'group channels'} found.</p>
          <button type="button" class="btn-chat-outline-sm" onclick="switchChatFilter('directory')">
            <i class="fa-solid fa-plus"></i> Start a Conversation
          </button>
        </div>
      `;
      return;
    }

    container.innerHTML = filtered.map(c => {
      const isActive = activeChannelId === parseInt(c.id);
      const isUnread = parseInt(c.unread_count) > 0;
      const initials = (c.name || 'CH').substring(0, 2).toUpperCase();
      const isAdviserSsc = (c.type === 'adviser_ssc');
      const iconType = isAdviserSsc ? '<i class="fa-solid fa-building-columns" style="font-size:0.68rem; margin-right:3px; color:#2563eb;"></i>' : '';

      return `
        <div class="chat-channel-item ${isActive ? 'active' : ''} ${isUnread ? 'unread' : ''}" onclick="selectChatChannel(${c.id})">
          <div class="chat-channel-avatar ${isAdviserSsc ? 'adviser-ssc' : (c.type === 'direct' ? 'direct' : '')}">
            ${initials}
          </div>
          <div class="chat-channel-info">
            <div class="chat-channel-name-row">
              <span class="chat-channel-name" title="${escapeHtml(c.name)}">${iconType}${escapeHtml(c.name)}</span>
              <span class="chat-channel-time">${formatTimeShort(c.last_message_time || c.updated_at)}</span>
            </div>
            <div class="chat-channel-msg-row">
              <span class="chat-channel-snippet">${escapeHtml(c.last_message || 'No messages yet')}</span>
              ${isUnread ? `<span class="chat-unread-badge">${c.unread_count}</span>` : ''}
            </div>
          </div>
        </div>
      `;
    }).join('');
  }

  async function loadDirectory() {
    const container = document.getElementById('chatChannelList');
    if (!container) return;
    container.innerHTML = '<div class="chat-loading-state"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading contacts directory...</div>';

    try {
      const res = await fetch(`${CHAT_API_URL}?action=get_directory`);
      const data = await res.json();
      if (data.success && Array.isArray(data.contacts)) {
        directoryContacts = data.contacts;
        const searchVal = document.getElementById('chatSearchInput')?.value || '';
        renderDirectory(searchVal);
      } else {
        container.innerHTML = `<div class="chat-empty-list"><p>${escapeHtml(data.message || 'No directory contacts found.')}</p></div>`;
      }
    } catch (e) {
      container.innerHTML = '<div class="chat-empty-list"><p>Failed to load directory. Please try again.</p></div>';
    }
  }

  function renderDirectory(searchFilter = '') {
    const container = document.getElementById('chatChannelList');
    if (!container) return;

    let filtered = directoryContacts;
    if (CURRENT_USER_ROLE === 'club_adviser' || CURRENT_USER_ROLE === 'ssc' || CURRENT_USER_ROLE === 'student') {
      filtered = filtered.filter(u => u.role !== 'admin');
    }
    if (searchFilter) {
      const sf = searchFilter.toLowerCase();
      filtered = filtered.filter(u => 
        (u.name || '').toLowerCase().includes(sf) || 
        (u.club_name || '').toLowerCase().includes(sf) || 
        (u.role_label || '').toLowerCase().includes(sf)
      );
    }

    if (filtered.length === 0) {
      container.innerHTML = `
        <div class="chat-empty-list">
          <i class="fa-solid fa-user-xmark"></i>
          <p>${searchFilter ? 'No contacts match your search.' : 'No matching contacts found under your organizational scope.'}</p>
        </div>
      `;
      return;
    }

    container.innerHTML = filtered.map(u => `
      <div class="chat-directory-item" role="button" tabindex="0" onclick="startDirectWith(${u.id}, this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();startDirectWith(${u.id}, this);}" title="Message ${escapeHtml(u.name)}">
        <div class="chat-channel-avatar direct">${escapeHtml(u.initial || 'U')}</div>
        <div class="chat-channel-info">
          <div class="chat-channel-name-row">
            <span class="chat-channel-name" title="${escapeHtml(u.name)}">${escapeHtml(u.name)}</span>
            <span class="chat-role-tag role-${escapeHtml(u.role)}">${escapeHtml(u.role_label)}</span>
          </div>
          <div class="chat-channel-msg-row">
            <span class="chat-channel-snippet" title="${escapeHtml(u.club_name)}">${escapeHtml(u.club_name)}</span>
          </div>
        </div>
      </div>
    `).join('');
  }

  window.startDirectWith = async function(targetUserId, cardEl = null) {
    let originalSnippet = null;
    let snippetEl = null;
    if (cardEl) {
      cardEl.style.pointerEvents = 'none';
      cardEl.style.opacity = '0.6';
      snippetEl = cardEl.querySelector('.chat-channel-snippet');
      if (snippetEl) {
        originalSnippet = snippetEl.innerHTML;
        snippetEl.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin" style="margin-right:4px;"></i> Opening conversation...';
      }
    }
    try {
      const fd = new FormData();
      fd.append('action', 'create_direct');
      fd.append('target_user_id', targetUserId);

      const res = await fetch(CHAT_API_URL, { method: 'POST', body: fd });
      const data = await res.json();
      if (data.success && data.channel_id) {
        await loadChannelsList();
        switchChatFilter('direct');
        selectChatChannel(data.channel_id);
      } else {
        alert(data.message || 'Could not start direct conversation.');
        if (cardEl) {
          cardEl.style.pointerEvents = '';
          cardEl.style.opacity = '';
          if (snippetEl && originalSnippet !== null) snippetEl.innerHTML = originalSnippet;
        }
      }
    } catch (e) {
      alert('Network error initiating direct chat.');
      if (cardEl) {
        cardEl.style.pointerEvents = '';
        cardEl.style.opacity = '';
        if (snippetEl && originalSnippet !== null) snippetEl.innerHTML = originalSnippet;
      }
    }
  };

  window.selectChatChannel = async function(channelId) {
    activeChannelId = parseInt(channelId);

    // Make input bar visible for active channel
    const inputForm = document.getElementById('chatInputForm');
    if (inputForm) inputForm.style.display = '';

    // Highlight item
    document.querySelectorAll('.chat-channel-item').forEach(el => el.classList.remove('active'));
    if (activeFilter !== 'directory') {
      renderChannels(document.getElementById('chatSearchInput')?.value || '');
    }

    await loadChatMessages(channelId, true);
  };

  async function loadChatMessages(channelId, autoScroll = false) {
    const wrap = document.getElementById('chatMessagesWrap');
    if (!wrap) return;

    try {
      const res = await fetch(`${CHAT_API_URL}?action=get_messages&channel_id=${channelId}`);
      const data = await res.json();
      if (data.success) {
        // Update active header
        const c = data.channel;
        const titleEl = document.getElementById('chatActiveTitle');
        const subEl = document.getElementById('chatActiveSubtitle');
        const avEl = document.getElementById('chatActiveAvatar');
        const inputForm = document.getElementById('chatInputForm');

        if (inputForm) inputForm.style.display = '';
        if (titleEl) titleEl.textContent = c.name;
        if (subEl) subEl.textContent = (c.type === 'direct' ? 'Direct Message' : '');
        if (avEl) avEl.textContent = (c.name || 'CH').substring(0, 2).toUpperCase();

        renderMessages(data.messages, wrap, autoScroll);
      }
    } catch (e) {
      console.warn('Error loading messages:', e);
    }
  }

  function renderMessages(messages, wrap, autoScroll = false) {
    if (!messages || messages.length === 0) {
      wrap.innerHTML = `
        <div class="chat-messages-empty">
          <i class="fa-solid fa-comment-dots"></i>
          <p style="margin:0;">No messages yet</p>
        </div>
      `;
      return;
    }

    const html = messages.map(m => {
      const roleBadge = m.sender_role ? `<span class="chat-msg-role role-${m.sender_role}">${formatRole(m.sender_role)}</span>` : '';
      return `
        <div class="chat-msg-row ${m.is_self ? 'self' : ''}">
          ${!m.is_self ? `<div class="chat-msg-avatar" title="${escapeHtml(m.sender_name)}">${m.sender_initial}</div>` : ''}
          <div class="chat-msg-bubble-wrap">
            ${!m.is_self ? `<div class="chat-msg-sender-name">${escapeHtml(m.sender_name)} ${roleBadge}</div>` : ''}
            <div class="chat-msg-bubble">
              ${escapeHtml(m.message)}
            </div>
            <div class="chat-msg-timestamp">${m.time_formatted}</div>
          </div>
        </div>
      `;
    }).join('');

    wrap.innerHTML = html;
    if (autoScroll) {
      wrap.scrollTop = wrap.scrollHeight;
    }
  }

  window.handleSendChatMessage = async function(e) {
    if (e) e.preventDefault();
    if (!activeChannelId && allChannels.length > 0) {
      activeChannelId = parseInt(allChannels[0].id);
    }
    if (!activeChannelId) {
      alert('Please select a conversation room first.');
      return;
    }

    const input = document.getElementById('chatMessageInput');
    const sendBtn = document.getElementById('chatSendBtn');
    const text = (input?.value || '').trim();
    if (!text) return;

    input.value = '';
    if (sendBtn) sendBtn.disabled = true;

    try {
      const fd = new FormData();
      fd.append('action', 'send_message');
      fd.append('channel_id', activeChannelId);
      fd.append('message', text);

      const res = await fetch(CHAT_API_URL, { method: 'POST', body: fd });
      const data = await res.json();
      if (data.success) {
        await loadChatMessages(activeChannelId, true);
        loadChannelsList();
      } else {
        alert(data.message || 'Could not send message.');
        input.value = text;
      }
    } catch (err) {
      console.error('Chat send error:', err);
      alert('Network error sending message.');
      input.value = text;
    } finally {
      if (sendBtn) sendBtn.disabled = false;
      input?.focus();
    }
  };

  function startChatPolling() {
    stopChatPolling();
    chatPollingTimer = setInterval(() => {
      if (activeChannelId && activeFilter !== 'directory') {
        loadChatMessages(activeChannelId, false);
      }
      loadChannelsList();
    }, 3500);
  }

  function stopChatPolling() {
    if (chatPollingTimer) {
      clearInterval(chatPollingTimer);
      chatPollingTimer = null;
    }
  }

  async function checkUnreadBadge() {
    try {
      const res = await fetch(`${CHAT_API_URL}?action=unread_count`);
      const data = await res.json();
      const dot = document.getElementById('chatBadgeDot');
      if (dot) {
        if (data.success && parseInt(data.unread) > 0) {
          dot.style.display = 'block';
        } else {
          dot.style.display = 'none';
        }
      }
    } catch (e) {}
  }

  // Ensure button in topbar
  function ensureChatButton() {
    const qrBtn = document.getElementById('qrFabBtn');
    if (qrBtn && !document.getElementById('chatFabBtn')) {
      const chatBtn = document.createElement('button');
      chatBtn.className = 'topbar-chat-btn';
      chatBtn.id = 'chatFabBtn';
      chatBtn.title = 'Inter-Club Chat';
      chatBtn.type = 'button';
      chatBtn.onclick = openInterClubChatModal;
      chatBtn.innerHTML = '<i class="fa-solid fa-comment-dots"></i><span class="chat-badge-dot" id="chatBadgeDot" style="display:none;"></span>';
      qrBtn.parentNode.insertBefore(chatBtn, qrBtn);
    }
  }

  function formatTimeShort(dateStr) {
    if (!dateStr) return '';
    try {
      const d = new Date(dateStr.replace(' ', 'T'));
      const now = new Date();
      if (d.toDateString() === now.toDateString()) {
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      }
      return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    } catch(e) {
      return '';
    }
  }

  function formatRole(role) {
    if (role === 'club_adviser') return 'Adviser';
    if (role === 'ssc') return 'SSC Officer';
    if (role === 'admin') return 'Admin';
    return 'Student';
  }

  function escapeHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Backdrop click & Escape key listeners
  const chatOverlayEl = document.getElementById('interClubChatModalOverlay');
  if (chatOverlayEl) {
    chatOverlayEl.addEventListener('click', (e) => {
      if (e.target === chatOverlayEl) {
        closeInterClubChatModal();
      }
    });
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      const overlay = document.getElementById('interClubChatModalOverlay');
      if (overlay && overlay.classList.contains('active')) {
        closeInterClubChatModal();
      }
    }
  });

  // Lifecycle initialization
  document.addEventListener('DOMContentLoaded', () => {
    ensureChatButton();
    checkUnreadBadge();
    unreadBadgeTimer = setInterval(checkUnreadBadge, 12000);
  });
  ensureChatButton();
  checkUnreadBadge();
})();
</script>
