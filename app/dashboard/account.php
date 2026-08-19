<?php
// ============================================================
//  ACCOUNT.PHP  (dashboard/)
//  Account Settings & Profile Management — Fully Integrated Layout
// ============================================================
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php');
    exit;
}
$first_name     = htmlspecialchars($_SESSION['first_name'] ?? '');
$last_name      = htmlspecialchars($_SESSION['last_name']  ?? '');
$email          = htmlspecialchars($_SESSION['email']      ?? '');
$username       = htmlspecialchars($_SESSION['username']   ?? '');
$sess_role      = $_SESSION['role'] ?? 'student';
$sess_initial   = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$user_id        = (int)$_SESSION['user_id'];

// Role Titles map
$role_labels = [
    'student'         => 'General Student',
    'club_adviser'    => 'Organization Adviser (Faculty Member)',
    'ssc'    => 'Supreme Student Council (SSC)',
    
    'admin'           => 'System Administrator'
];
$role = $role_labels[$sess_role] ?? 'User';

// Connection remains open for sidebar and qr_modal usage
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Account Settings – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/account.css?v=<?= filemtime(__DIR__ . '/../css/account.css') ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'account';
require_once __DIR__ . '/../shared/sidebar.php';
?>

<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
      <i class="fa-solid fa-bars"></i>
    </button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap">
        <input type="text" placeholder="Search account..."/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <button class="topbar-qr-btn" id="qrFabBtn" title="View Personal Attendance QR Code" type="button">
        <i class="fa-solid fa-qrcode"></i>
      </button>
      <a href="../dashboard/account.php" class="avatar" id="avatarBtn" title="Account Settings">
        <?= $sess_initial ?>
      </a>
    </div>
  </div>

  <!-- Content -->
  <div class="content">

    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-user-gear"></i>
        Account Settings &amp; Profile
      </h2>
    </div>

    <div class="content-body">

      <div class="account-grid">

        <!-- Left Column: User Profile Overview Card -->
        <div class="profile-hero-card">
          <div class="avatar-large" id="avatarCircle"><?= $sess_initial ?></div>
          <h3 class="profile-user-name" id="displayName"><?= $first_name . ' ' . $last_name ?></h3>
          <span class="profile-user-role"><?= $role ?></span>

          <div class="profile-details-list">
            <div class="profile-detail-item">
              <i class="fa-solid fa-envelope"></i>
              <span><?= $email ?></span>
            </div>
            <div class="profile-detail-item">
              <i class="fa-solid fa-id-badge"></i>
              <span>@<?= $username ?></span>
            </div>
          </div>

          <?php if ($sess_role === 'student'): ?>
          <div style="margin-top:20px; width:100%;">
            <button class="btn-cct-download" onclick="alert('Downloading official Co-Curricular Transcript (CCT) PDF...')">
              <i class="fa-solid fa-file-pdf"></i> Download Transcript (CCT)
            </button>
          </div>
          <?php endif; ?>
        </div>

        <!-- Right Column: Settings Forms -->
        <div class="profile-forms-column">

          <!-- Profile Information Card -->
          <div class="settings-card">
            <div class="settings-card-header">
              <i class="fa-solid fa-user-pen" style="color:#2563eb;"></i>
              <h2>Personal Profile Information</h2>
            </div>
            <div class="settings-card-body">
              <form id="profileForm" onsubmit="return false;">
                <div class="form-grid">
                  <div class="form-field">
                    <label>First Name <span>*</span></label>
                    <input type="text" name="first_name" value="<?= $first_name ?>" placeholder="First name" required/>
                    <span class="field-error"></span>
                  </div>
                  <div class="form-field">
                    <label>Last Name <span>*</span></label>
                    <input type="text" name="last_name" value="<?= $last_name ?>" placeholder="Last name" required/>
                    <span class="field-error"></span>
                  </div>
                  <div class="form-field full">
                    <label>Email Address <span>*</span></label>
                    <input type="email" name="email" value="<?= $email ?>" placeholder="email@example.com" required/>
                    <span class="field-error"></span>
                  </div>
                  <div class="form-field full">
                    <label>Username <span>*</span></label>
                    <input type="text" name="username" value="<?= $username ?>" placeholder="Username" required/>
                    <span class="field-error"></span>
                  </div>
                </div>
              </form>
            </div>
            <div class="settings-card-footer">
              <button class="btn-save" id="btnSaveProfile">
                <i class="fa-solid fa-floppy-disk"></i> Save Profile Changes
              </button>
            </div>
          </div>

          <!-- Change Password Card -->
          <div class="settings-card">
            <div class="settings-card-header">
              <i class="fa-solid fa-shield-halved" style="color:#2563eb;"></i>
              <h2>Security &amp; Password</h2>
            </div>
            <div class="settings-card-body">
              <form id="passwordForm" onsubmit="return false;">
                <div class="form-grid">
                  <div class="form-field full">
                    <label>Current Password <span>*</span></label>
                    <div class="password-wrap">
                      <input type="password" name="current_password" id="fCurrentPw" placeholder="Enter current password" autocomplete="current-password"/>
                      <button type="button" class="toggle-pw" data-target="fCurrentPw" title="Toggle visibility">
                        <i class="fa-solid fa-eye"></i>
                      </button>
                    </div>
                    <span class="field-error"></span>
                  </div>
                  <div class="form-field">
                    <label>New Password <span>*</span></label>
                    <div class="password-wrap">
                      <input type="password" name="new_password" id="fNewPw" placeholder="New password (min 6)" autocomplete="new-password"/>
                      <button type="button" class="toggle-pw" data-target="fNewPw" title="Toggle visibility">
                        <i class="fa-solid fa-eye"></i>
                      </button>
                    </div>
                    <span class="field-error"></span>
                  </div>
                  <div class="form-field">
                    <label>Confirm Password <span>*</span></label>
                    <div class="password-wrap">
                      <input type="password" name="confirm_password" id="fConfirmPw" placeholder="Repeat new password" autocomplete="new-password"/>
                      <button type="button" class="toggle-pw" data-target="fConfirmPw" title="Toggle visibility">
                        <i class="fa-solid fa-eye"></i>
                      </button>
                    </div>
                    <span class="field-error"></span>
                  </div>
                </div>
              </form>
            </div>
            <div class="settings-card-footer">
              <button class="btn-save" id="btnSavePassword">
                <i class="fa-solid fa-key"></i> Update Password
              </button>
            </div>
          </div>

          <!-- Danger Zone Card -->
          <div class="settings-card" style="border-color:#fecaca;">
            <div class="settings-card-header" style="background:#fff5f5; border-bottom-color:#fee2e2;">
              <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i>
              <h2 style="color:#dc2626;">Account Session &amp; Actions</h2>
            </div>
            <div class="danger-zone">
              <div class="danger-info">
                <h3>Sign Out</h3>
                <p>End your current session and return to the sign-in portal.</p>
              </div>
              <button class="btn-danger" id="btnSignOut">
                <i class="fa-solid fa-right-from-bracket"></i> Sign Out
              </button>
            </div>
            <div class="danger-zone" style="border-top:1px solid #fee2e2;">
              <div class="danger-info">
                <h3>Delete Account</h3>
                <p>Permanently remove your account and all session tokens. This cannot be undone.</p>
              </div>
              <button class="btn-danger" id="btnDeleteAccount">
                <i class="fa-solid fa-trash-can"></i> Delete Account
              </button>
            </div>
          </div>

        </div><!-- end profile-forms-column -->

      </div><!-- end account-grid -->

    </div><!-- end content-body -->
  </div><!-- end content -->

  <div class="footer">Co-Curricular Management System &copy; 2026</div>
</div><!-- end main -->

<script src="../js/dashboard.js"></script>
<script>
const API = '../shared/auth_actions.php';
const SIGNIN = '../auth/signin.php';

function showToast(message, type = 'success') {
    const labels = { success: 'Success', error: 'Error' };
    let toast = document.querySelector('.toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.className = 'toast';
        toast.innerHTML = `<div class="toast-label"><span class="toast-dot"></span><span class="toast-title"></span></div><div class="toast-msg"></div>`;
        document.body.appendChild(toast);
    }
    toast.querySelector('.toast-title').textContent = labels[type] ?? type;
    toast.querySelector('.toast-msg').textContent   = message;
    toast.className = `toast ${type}`;
    void toast.offsetWidth;
    toast.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => toast.classList.remove('show'), 3500);
}

function validateFields(form, fields) {
    let valid = true;
    fields.forEach(({ name, label }) => {
        const input = form.querySelector(`[name="${name}"]`);
        if (!input) return;
        const field = input.closest('.form-field');
        const err   = field?.querySelector('.field-error');
        if (!input.value.trim()) {
            input.classList.add('input-error');
            field?.classList.add('has-error');
            if (err) err.textContent = `${label} is required.`;
            valid = false;
        } else { clearErr(input); }
    });
    return valid;
}

function clearErr(input) {
    input.classList.remove('input-error');
    input.closest('.form-field')?.classList.remove('has-error');
    const e = input.closest('.form-field')?.querySelector('.field-error');
    if (e) e.textContent = '';
}

document.querySelectorAll('.form-field input').forEach(i => {
    i.addEventListener('input', () => { if (i.value.trim()) clearErr(i); });
});

document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
        const inp = document.getElementById(btn.dataset.target);
        inp.type  = inp.type === 'password' ? 'text' : 'password';
        btn.querySelector('i').className = inp.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
    });
});

async function postAction(fd) {
    return fetch(API, { method: 'POST', body: fd }).then(r => r.json());
}

document.getElementById('btnSaveProfile')?.addEventListener('click', async () => {
    const form = document.getElementById('profileForm');
    if (!validateFields(form, [
        { name: 'first_name', label: 'First Name' },
        { name: 'last_name',  label: 'Last Name'  },
        { name: 'email',      label: 'Email'      },
        { name: 'username',   label: 'Username'   },
    ])) return;
    const fd = new FormData(form);
    fd.set('action', 'update_profile');
    try {
        const data = await postAction(fd);
        if (data.success) {
            const first = form.querySelector('[name="first_name"]').value.trim();
            const last  = form.querySelector('[name="last_name"]').value.trim();
            document.getElementById('displayName').textContent  = `${first} ${last}`;
            document.getElementById('avatarCircle').textContent = first.charAt(0).toUpperCase();
            showToast(data.message, 'success');
        } else { showToast(data.message, 'error'); }
    } catch { showToast('Request failed.', 'error'); }
});

document.getElementById('btnSavePassword')?.addEventListener('click', async () => {
    const form = document.getElementById('passwordForm');
    if (!validateFields(form, [
        { name: 'current_password', label: 'Current Password' },
        { name: 'new_password',     label: 'New Password'     },
        { name: 'confirm_password', label: 'Confirm Password' },
    ])) return;
    const newPw = form.querySelector('[name="new_password"]').value;
    const conf  = form.querySelector('[name="confirm_password"]').value;
    if (newPw !== conf) {
        const inp = form.querySelector('[name="confirm_password"]');
        inp.classList.add('input-error');
        inp.closest('.form-field')?.classList.add('has-error');
        const e = inp.closest('.form-field')?.querySelector('.field-error');
        if (e) e.textContent = 'Passwords do not match.';
        return;
    }
    const fd = new FormData(form);
    fd.set('action', 'change_password');
    try {
        const data = await postAction(fd);
        if (data.success) { form.reset(); showToast(data.message, 'success'); }
        else { showToast(data.message, 'error'); }
    } catch { showToast('Request failed.', 'error'); }
});

document.getElementById('btnSignOut')?.addEventListener('click', async () => {
    const fd = new FormData(); fd.set('action', 'logout');
    await postAction(fd).catch(() => {});
    window.location.href = SIGNIN;
});

document.getElementById('btnDeleteAccount')?.addEventListener('click', async () => {
    if (!confirm('Permanently delete your account? This cannot be undone.')) return;
    const fd = new FormData(); fd.set('action', 'delete_account');
    try {
        const data = await postAction(fd);
        if (data.success) {
            showToast('Account deleted. Redirecting…', 'error');
            setTimeout(() => { window.location.href = SIGNIN; }, 2000);
        } else { showToast(data.message, 'error'); }
    } catch { showToast('Request failed.', 'error'); }
});
</script>



</body>
</html>
