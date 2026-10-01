<?php
// ============================================================
//  ADMIN_SETTINGS.PHP  (dashboard/)
//  Co-Curricular System — System Settings Configuration Store
//  Accessible to: System Admin
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_once __DIR__ . '/../shared/ai_config.php';
require_permission('settings.manage');

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'admin';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// Load system settings from database
$sys_settings = [];
$res_s = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($res_s) {
    while ($r = $res_s->fetch_assoc()) {
        $sys_settings[$r['setting_key']] = $r['setting_value'];
    }
}

// 1. Academic Configuration
$academic_year   = $sys_settings['academic_year'] ?? '2025-2026';
$active_semester = $sys_settings['active_semester'] ?? '1st Semester';

// 2. Organization Configuration
$org_categories    = $sys_settings['org_categories'] ?? "Academic\nAdvocacy\nCultural\nSports\nSpecial Interest\nReligious\nCommunity Outreach";
$allowed_org_types = $sys_settings['allowed_org_types'] ?? "Departmental Council\nInstitutional Club\nFraternity / Sorority\nSpecial Interest Guild\nStudent Publication\nHonor Society\nSports Varsity Club";

// 3. Notification Configuration
$notification_tpl       = $sys_settings['notification_templates'] ?? "Event Approval Notice\nBudget Disbursement Notice\nCouncil Endorsement Notice\nAccount Credentials Reset\nCharter Accreditation Update\nAttendance Check-in Alert";
$notification_priority  = $sys_settings['notification_default_priority'] ?? 'Normal';
$notification_retention = $sys_settings['notification_retention_days'] ?? '90 Days';

// 4. AI Configuration
$ai_provider    = $sys_settings['ai_provider'] ?? 'Google Gemini';
$ai_model       = $sys_settings['ai_model'] ?? 'gemini-2.0-flash';
$raw_api_key    = get_gemini_api_key($conn);
$has_api_key    = !empty(trim($raw_api_key));

// Secret Protection: The secret API key is NEVER output in plain text
$masked_key_display = $has_api_key ? '••••••••••••••••••••••••••••••••' : '';

// 5. Multi-Factor Authentication & Email Delivery Configuration
$mfa_enabled           = $sys_settings['mfa_enabled'] ?? '1';
$mfa_expiry_minutes    = $sys_settings['mfa_expiry_minutes'] ?? '10';
$mfa_resend_cooldown   = $sys_settings['mfa_resend_cooldown'] ?? '60';
$mfa_allow_dev_preview = $sys_settings['mfa_allow_dev_preview'] ?? '1';
$smtp_host             = $sys_settings['smtp_host'] ?? '';
$smtp_port             = $sys_settings['smtp_port'] ?? '587';
$smtp_user             = $sys_settings['smtp_user'] ?? '';
$smtp_pass             = $sys_settings['smtp_pass'] ?? '';
$has_smtp_pass         = !empty(trim($smtp_pass));
$smtp_crypto           = $sys_settings['smtp_crypto'] ?? 'tls';
$mail_from_name        = $sys_settings['mail_from_name'] ?? 'BCP Co-Curricular Management System';
$mail_from_email       = $sys_settings['mail_from_email'] ?? 'no-reply@bcp.edu.ph';
$masked_smtp_pass      = $has_smtp_pass ? '••••••••••••••••' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>System Settings — BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <meta name="csrf-token" content="<?= csrf_token() ?>"/>
  <script src="../js/page-loader.js"></script>
  <style>
    /* Settings Section Cards */
    .settings-section-card {
      background: #ffffff;
      border-radius: 16px;
      border: 1.5px solid #e2e8f0;
      box-shadow: 0 1px 4px rgba(0,0,0,0.03);
      margin-bottom: 20px;
      overflow: hidden;
      transition: border-color 0.15s ease;
    }
    .settings-section-card:hover {
      border-color: #cbd5e1;
    }
    .settings-section-header {
      padding: 16px 22px;
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
    }
    .settings-section-title {
      font-size: 0.96rem;
      font-weight: 800;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .settings-section-body {
      padding: 22px;
    }

    /* Form Fields */
    .form-group-setting {
      margin-bottom: 18px;
    }
    .form-group-setting:last-child {
      margin-bottom: 0;
    }
    .form-label-setting {
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 0.76rem;
      font-weight: 700;
      color: #334155;
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .form-input-setting, .form-select-setting, .form-textarea-setting {
      width: 100%;
      padding: 10px 14px;
      border: 1.5px solid #cbd5e1;
      border-radius: 8px;
      font-size: 0.86rem;
      color: #0f172a;
      background: #ffffff;
      font-family: inherit;
      outline: none;
      box-sizing: border-box;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .form-input-setting:focus, .form-select-setting:focus, .form-textarea-setting:focus {
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }
    .form-help-text {
      font-size: 0.74rem;
      color: #64748b;
      margin-top: 5px;
      line-height: 1.35;
    }

    /* Summary Overview Grid */
    .settings-summary-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
      margin-bottom: 20px;
    }
    @media (max-width: 1024px) {
      .settings-summary-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 580px) {
      .settings-summary-grid { grid-template-columns: 1fr; }
    }

    .settings-summary-box {
      background: #ffffff;
      border-radius: 12px;
      padding: 12px 16px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 1px 3px rgba(0,0,0,0.02);
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .summary-box-icon {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.90rem;
      flex-shrink: 0;
    }
    .summary-box-title {
      font-size: 0.70rem;
      font-weight: 700;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .summary-box-val {
      font-size: 0.90rem;
      font-weight: 800;
      color: #0f172a;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 160px;
    }

    /* Secret Status Badges */
    .badge-key-status {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 8px;
      border-radius: 12px;
      font-size: 0.71rem;
      font-weight: 700;
    }
    .badge-key-active {
      background: #ecfdf5;
      color: #047857;
      border: 1px solid #a7f3d0;
    }
    .badge-key-missing {
      background: #fef2f2;
      color: #dc2626;
      border: 1px solid #fecaca;
    }

    /* Secret Input Group */
    .secret-input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }
    .secret-input-wrapper input {
      padding-right: 44px;
      font-family: monospace;
      letter-spacing: 0.04em;
    }
    .btn-toggle-secret {
      position: absolute;
      right: 8px;
      background: none;
      border: none;
      color: #64748b;
      padding: 6px 10px;
      cursor: pointer;
      font-size: 0.85rem;
      border-radius: 6px;
      transition: color 0.15s ease;
    }
    .btn-toggle-secret:hover {
      color: #0f172a;
    }

    /* Two-column Form Row */
    .form-row-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    @media (max-width: 768px) {
      .form-row-2 { grid-template-columns: 1fr; }
    }

    /* Save Bar Footer */
    .save-actions-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      padding: 16px 22px;
      background: #ffffff;
      border-radius: 14px;
      border: 1.5px solid #e2e8f0;
      margin-top: 10px;
      margin-bottom: 24px;
    }

    /* Layout & Footer Anchor */
    .main {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .content {
      flex: 1;
      padding-bottom: 0 !important;
    }
    .footer {
      margin-top: auto;
      flex-shrink: 0;
    }
  </style>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'admin_settings';
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
      <div class="search-wrap" id="topbarSearchWrap">
        <i class="fa-solid fa-magnifying-glass search-icon"></i>
        <input type="text" placeholder="Search modules, events, clubs..." autocomplete="off" />
        <button type="button" class="search-clear-btn" aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i class="fa-solid fa-qrcode"></i></button>
      <a href="../dashboard/account.php" class="avatar" id="avatarBtn" title="Account Settings">
        <?php if (!empty($sess_pic) && file_exists(__DIR__ . '/../uploads/avatars/' . $sess_pic)): ?>
          <img src="../uploads/avatars/<?= htmlspecialchars($sess_pic) ?>" alt="Profile"/>
        <?php else: ?>
          <?= $sess_initial ?>
        <?php endif; ?>
      </a>
    </div>
  </div>

  <!-- Content -->
  <div class="content">

    <!-- Page Title Bar -->
    <div class="page-title-bar" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
      <div>
        <h2 class="page-title" style="margin:0; font-size:1.4rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:10px;">
          <i class="fa-solid fa-sliders" style="color:#2563eb;"></i>
          System Settings
        </h2>
        <div style="font-size:0.80rem; color:#64748b; margin-top:3px;">
          Institutional Configuration &bull; Academic Parameters &bull; Notification &amp; AI Integration
        </div>
      </div>
      <div style="display:flex; align-items:center; gap:8px;">
        <button type="button" class="btn btn-secondary" onclick="location.reload()" style="height:36px; padding:0 14px; font-size:0.82rem; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-arrows-rotate"></i> Reload
        </button>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('btnSubmitSettings').click()" style="height:36px; padding:0 16px; font-size:0.82rem; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-floppy-disk"></i> Save Settings
        </button>
      </div>
    </div>

    <div class="content-body" style="max-width: 980px;">

      <!-- Live Summary Bar -->
      <div class="settings-summary-grid">
        <div class="settings-summary-box">
          <div class="summary-box-icon" style="background:#eff6ff; color:#2563eb;"><i class="fa-solid fa-graduation-cap"></i></div>
          <div>
            <div class="summary-box-title">Academic Term</div>
            <div class="summary-box-val" id="summaryAcademicVal"><?= htmlspecialchars($academic_year) ?></div>
          </div>
        </div>

        <div class="settings-summary-box">
          <div class="summary-box-icon" style="background:#faf5ff; color:#8b5cf6;"><i class="fa-solid fa-sitemap"></i></div>
          <div>
            <div class="summary-box-title">Organizations</div>
            <div class="summary-box-val"><?= count(array_filter(explode("\n", $org_categories))) ?> Categories</div>
          </div>
        </div>

        <div class="settings-summary-box">
          <div class="summary-box-icon" style="background:#ecfdf5; color:#059669;"><i class="fa-solid fa-bell"></i></div>
          <div>
            <div class="summary-box-title">Notifications</div>
            <div class="summary-box-val"><?= htmlspecialchars($notification_priority) ?> Priority</div>
          </div>
        </div>

        <div class="settings-summary-box">
          <div class="summary-box-icon" style="background:#fffbeb; color:#d97706;"><i class="fa-solid fa-brain"></i></div>
          <div>
            <div class="summary-box-title">AI Provider</div>
            <div class="summary-box-val"><?= htmlspecialchars($ai_provider) ?></div>
          </div>
        </div>

        <div class="settings-summary-box">
          <div class="summary-box-icon" style="background:#eff6ff; color:#2563eb;"><i class="fa-solid fa-shield-halved"></i></div>
          <div>
            <div class="summary-box-title">Multi-Factor Auth</div>
            <div class="summary-box-val" style="color: <?= $mfa_enabled !== '0' ? '#16a34a' : '#dc2626' ?>;">
              <?= $mfa_enabled !== '0' ? 'Active (Email OTP)' : 'Disabled' ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Settings Form -->
      <form id="systemSettingsForm" onsubmit="handleSaveSystemSettings(event)">

        <!-- 1. ACADEMIC CONFIGURATION -->
        <div class="settings-section-card">
          <div class="settings-section-header">
            <div class="settings-section-title">
              <i class="fa-solid fa-graduation-cap" style="color:#2563eb;"></i>
              Academic Configuration
            </div>
            <span style="font-size:0.75rem; color:#64748b; font-weight:600;">Cycles &amp; Terms</span>
          </div>
          <div class="settings-section-body">
            <div class="form-row-2">
              <div class="form-group-setting">
                <label class="form-label-setting" for="academicYearInput">
                  <span>Academic Year <strong style="color:#dc2626;">*</strong></span>
                  <span style="font-size:0.70rem; color:#64748b; font-weight:500;">Format: YYYY-YYYY</span>
                </label>
                <input type="text" id="academicYearInput" name="academic_year" class="form-input-setting" value="<?= htmlspecialchars($academic_year) ?>" placeholder="e.g. 2025-2026" required />
                <div class="form-help-text">Active institutional school year governing student enrollment, organization charters, and activity logs.</div>
              </div>

              <div class="form-group-setting">
                <label class="form-label-setting" for="activeSemesterSelect">
                  <span>Active Semester <strong style="color:#dc2626;">*</strong></span>
                  <span style="font-size:0.70rem; color:#64748b; font-weight:500;">Grading Term</span>
                </label>
                <select id="activeSemesterSelect" name="active_semester" class="form-select-setting">
                  <option value="1st Semester" <?= $active_semester === '1st Semester' ? 'selected' : '' ?>>1st Semester</option>
                  <option value="2nd Semester" <?= $active_semester === '2nd Semester' ? 'selected' : '' ?>>2nd Semester</option>
                  <option value="Summer Term" <?= $active_semester === 'Summer Term' ? 'selected' : '' ?>>Summer Term</option>
                </select>
                <div class="form-help-text">Current operating term applied to event registrations, attendance records, and financial ledger cycles.</div>
              </div>
            </div>
          </div>
        </div>

        <!-- 2. ORGANIZATION CONFIGURATION -->
        <div class="settings-section-card">
          <div class="settings-section-header">
            <div class="settings-section-title">
              <i class="fa-solid fa-sitemap" style="color:#8b5cf6;"></i>
              Organization Configuration
            </div>
            <span style="font-size:0.75rem; color:#64748b; font-weight:600;">Classifications &amp; Charters</span>
          </div>
          <div class="settings-section-body">
            <div class="form-row-2">
              <div class="form-group-setting">
                <label class="form-label-setting" for="orgCategoriesTextarea">
                  <span>Organization Categories <strong style="color:#dc2626;">*</strong></span>
                  <span style="font-size:0.70rem; color:#64748b; font-weight:500;">One per line</span>
                </label>
                <textarea id="orgCategoriesTextarea" name="org_categories" rows="6" class="form-textarea-setting" style="font-family:monospace; font-size:0.83rem;"><?= htmlspecialchars($org_categories) ?></textarea>
                <div class="form-help-text">Accredited categories used across organization registration forms, directory filters, and institutional summaries.</div>
              </div>

              <div class="form-group-setting">
                <label class="form-label-setting" for="allowedOrgTypesTextarea">
                  <span>Allowed Organization Types <strong style="color:#dc2626;">*</strong></span>
                  <span style="font-size:0.70rem; color:#64748b; font-weight:500;">One per line</span>
                </label>
                <textarea id="allowedOrgTypesTextarea" name="allowed_org_types" rows="6" class="form-textarea-setting" style="font-family:monospace; font-size:0.83rem;"><?= htmlspecialchars($allowed_org_types) ?></textarea>
                <div class="form-help-text">Permitted student association governance frameworks recognized by Student Affairs &amp; Services.</div>
              </div>
            </div>
          </div>
        </div>

        <!-- 3. NOTIFICATION CONFIGURATION -->
        <div class="settings-section-card">
          <div class="settings-section-header">
            <div class="settings-section-title">
              <i class="fa-solid fa-bell" style="color:#059669;"></i>
              Notification Configuration
            </div>
            <span style="font-size:0.75rem; color:#64748b; font-weight:600;">Templates &amp; Data Retention</span>
          </div>
          <div class="settings-section-body">
            <div class="form-group-setting">
              <label class="form-label-setting" for="notifTemplatesTextarea">
                <span>Notification Templates</span>
                <span style="font-size:0.70rem; color:#64748b; font-weight:500;">System Notice Presets</span>
              </label>
              <textarea id="notifTemplatesTextarea" name="notification_templates" rows="5" class="form-textarea-setting" style="font-family:monospace; font-size:0.83rem;"><?= htmlspecialchars($notification_tpl) ?></textarea>
              <div class="form-help-text">Pre-approved message templates for multi-stage event approvals, budget releases, and student governance bulletins.</div>
            </div>

            <div class="form-row-2">
              <div class="form-group-setting">
                <label class="form-label-setting" for="defaultPrioritySelect">
                  <span>Default Priority</span>
                  <span style="font-size:0.70rem; color:#64748b; font-weight:500;">Urgency Level</span>
                </label>
                <select id="defaultPrioritySelect" name="notification_default_priority" class="form-select-setting">
                  <option value="Low" <?= $notification_priority === 'Low' ? 'selected' : '' ?>>Low (Background Info)</option>
                  <option value="Normal" <?= $notification_priority === 'Normal' ? 'selected' : '' ?>>Normal (Standard Bulletin)</option>
                  <option value="High" <?= $notification_priority === 'High' ? 'selected' : '' ?>>High (Important Notice)</option>
                  <option value="Urgent" <?= $notification_priority === 'Urgent' ? 'selected' : '' ?>>Urgent (Immediate Attention)</option>
                </select>
                <div class="form-help-text">Default dispatch urgency applied to automatic system notices and student alert banners.</div>
              </div>

              <div class="form-group-setting">
                <label class="form-label-setting" for="retentionSelect">
                  <span>Retention Period</span>
                  <span style="font-size:0.70rem; color:#64748b; font-weight:500;">Audit Lifetime</span>
                </label>
                <select id="retentionSelect" name="notification_retention_days" class="form-select-setting">
                  <option value="30 Days" <?= $notification_retention === '30 Days' ? 'selected' : '' ?>>30 Days (Monthly Prune)</option>
                  <option value="60 Days" <?= $notification_retention === '60 Days' ? 'selected' : '' ?>>60 Days (Bi-Monthly)</option>
                  <option value="90 Days" <?= $notification_retention === '90 Days' ? 'selected' : '' ?>>90 Days (Recommended Quarterly)</option>
                  <option value="180 Days" <?= $notification_retention === '180 Days' ? 'selected' : '' ?>>180 Days (Semester Cycle)</option>
                  <option value="365 Days" <?= $notification_retention === '365 Days' ? 'selected' : '' ?>>365 Days (Annual Archive)</option>
                  <option value="Indefinite" <?= $notification_retention === 'Indefinite' ? 'selected' : '' ?>>Indefinite (No Auto-Pruning)</option>
                </select>
                <div class="form-help-text">Lifespan of read notifications and dispatch telemetry before automatic database archiving.</div>
              </div>
            </div>
          </div>
        </div>

        <!-- 4. AI CONFIGURATION -->
        <div class="settings-section-card">
          <div class="settings-section-header">
            <div class="settings-section-title">
              <i class="fa-solid fa-brain" style="color:#d97706;"></i>
              AI Configuration
            </div>
            <div>
              <?php if ($has_api_key): ?>
                <span class="badge-key-status badge-key-active">
                  <i class="fa-solid fa-circle-check"></i> API Key Configured
                </span>
              <?php else: ?>
                <span class="badge-key-status badge-key-missing">
                  <i class="fa-solid fa-circle-exclamation"></i> Key Not Configured
                </span>
              <?php endif; ?>
            </div>
          </div>
          <div class="settings-section-body">
            <div class="form-row-2" style="margin-bottom:18px;">
              <div class="form-group-setting">
                <label class="form-label-setting" for="aiProviderSelect">
                  <span>AI Provider</span>
                  <span style="font-size:0.70rem; color:#64748b; font-weight:500;">Engine Vendor</span>
                </label>
                <select id="aiProviderSelect" name="ai_provider" class="form-select-setting" onchange="onAiProviderChange(this.value)">
                  <option value="Google Gemini" <?= $ai_provider === 'Google Gemini' ? 'selected' : '' ?>>Google Gemini (Google DeepMind)</option>
                  <option value="OpenAI" <?= $ai_provider === 'OpenAI' ? 'selected' : '' ?>>OpenAI (GPT Engine)</option>
                  <option value="Anthropic" <?= $ai_provider === 'Anthropic' ? 'selected' : '' ?>>Anthropic (Claude Engine)</option>
                  <option value="DeepSeek" <?= $ai_provider === 'DeepSeek' ? 'selected' : '' ?>>DeepSeek (Open Model)</option>
                </select>
                <div class="form-help-text">Integrated artificial intelligence provider powering event proposal drafting and financial audit intelligence.</div>
              </div>

              <div class="form-group-setting">
                <label class="form-label-setting" for="aiModelSelect">
                  <span>Model</span>
                  <span style="font-size:0.70rem; color:#64748b; font-weight:500;">Checkpoint</span>
                </label>
                <select id="aiModelSelect" name="ai_model" class="form-select-setting">
                  <option value="gemini-3.5-flash-lite" <?= in_array($ai_model, ['gemini-3.5-flash-lite', 'gemini-2.5-flash-lite', '']) ? 'selected' : '' ?>>gemini-3.5-flash-lite (Recommended — Free Tier Optimized)</option>
                  <option value="gemini-3.6-flash" <?= in_array($ai_model, ['gemini-3.6-flash', 'gemini-2.0-flash', 'gemini-1.5-flash']) ? 'selected' : '' ?>>gemini-3.6-flash (Active High Speed)</option>
                  <option value="gemini-3.7-flash" <?= $ai_model === 'gemini-3.7-flash' ? 'selected' : '' ?>>gemini-3.7-flash (Latest Generation)</option>
                  <option value="gemini-3.8-flash" <?= $ai_model === 'gemini-3.8-flash' ? 'selected' : '' ?>>gemini-3.8-flash (Next-Gen Flash)</option>
                  <option value="gemini-flash-lite-latest" <?= $ai_model === 'gemini-flash-lite-latest' ? 'selected' : '' ?>>gemini-flash-lite-latest (Dynamic Lightweight Flash)</option>
                  <option value="gemini-flash-latest" <?= $ai_model === 'gemini-flash-latest' ? 'selected' : '' ?>>gemini-flash-latest (Dynamic Latest Flash)</option>
                  <option value="gemini-2.5-pro" <?= $ai_model === 'gemini-2.5-pro' ? 'selected' : '' ?>>gemini-2.5-pro (Deep Multimodal Reasoning)</option>
                </select>
                <div class="form-help-text">Default base model used for automated proposals, budget audits, and natural language recommendations.</div>
              </div>
            </div>

            <!-- API Key Status & Secret Protection Field -->
            <div class="form-group-setting">
              <label class="form-label-setting" for="apiKeyInput">
                <span>
                  <i class="fa-solid fa-key" style="color:#d97706; margin-right:4px;"></i>
                  API Key &bull; Status: 
                  <strong style="color:<?= $has_api_key ? '#059669' : '#dc2626' ?>;">
                    <?= $has_api_key ? 'Encrypted &amp; Active' : 'Missing / Unconfigured' ?>
                  </strong>
                </span>
                <span style="font-size:0.70rem; color:#64748b; font-weight:600;">
                  <i class="fa-solid fa-lock"></i> Protected Secret
                </span>
              </label>

              <div class="secret-input-wrapper">
                <input type="password" 
                       id="apiKeyInput" 
                       name="gemini_api_key" 
                       class="form-input-setting" 
                       autocomplete="new-password"
                       value=""
                       placeholder="<?= $has_api_key ? $masked_key_display . ' (Leave empty to keep current)' : 'Enter new secret API key...' ?>" />
                <button type="button" class="btn-toggle-secret" id="btnToggleSecret" onclick="toggleSecretVisibility()" title="Toggle secret visibility">
                  <i class="fa-solid fa-eye" id="toggleSecretIcon"></i>
                </button>
              </div>

              <div class="form-help-text" style="display:flex; align-items:flex-start; gap:8px; margin-top:8px;">
                <i class="fa-solid fa-shield-halved" style="color:#2563eb; margin-top:2px;"></i>
                <span>
                  <strong>Security Policy:</strong> Secrets such as API keys are never displayed in plain text. If an API key is already configured, leave this field blank to retain the current secret. Enter a new key only when updating credentials.
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- 5. MULTI-FACTOR AUTHENTICATION & EMAIL CONFIGURATION -->
        <div class="settings-section-card">
          <div class="settings-section-header">
            <div class="settings-section-title">
              <i class="fa-solid fa-shield-halved" style="color:#2563eb;"></i>
              Multi-Factor Authentication (MFA) &amp; Email Delivery
            </div>
            <div>
              <?php if ($mfa_enabled !== '0'): ?>
                <span class="badge-key-status badge-key-active">
                  <i class="fa-solid fa-shield-check"></i> MFA Enforced
                </span>
              <?php else: ?>
                <span class="badge-key-status badge-key-missing">
                  <i class="fa-solid fa-shield-slash"></i> MFA Disabled
                </span>
              <?php endif; ?>
            </div>
          </div>
          <div class="settings-section-body">
            <div class="form-row-2" style="margin-bottom:18px;">
              <div class="form-group-setting">
                <label class="form-label-setting" for="mfaEnabledSelect">
                  <span>MFA Enforcement Policy <strong style="color:#dc2626;">*</strong></span>
                  <span style="font-size:0.70rem; color:#64748b; font-weight:500;">Access Security</span>
                </label>
                <select id="mfaEnabledSelect" name="mfa_enabled" class="form-select-setting">
                  <option value="1" <?= $mfa_enabled !== '0' ? 'selected' : '' ?>>Enabled (Enforced for Administrator &amp; SSC Officer — Live Gmail Delivery)</option>
                  <option value="0" <?= $mfa_enabled === '0' ? 'selected' : '' ?>>Disabled (Direct Login Only)</option>
                </select>
                <div class="form-help-text">Mandates email authorization code verification upon entering valid credentials for Administrator and SSC Officer accounts.</div>
              </div>

              <div class="form-group-setting">
                <label class="form-label-setting" for="mfaExpirySelect">
                  <span>Code Validity Duration</span>
                  <span style="font-size:0.70rem; color:#64748b; font-weight:500;">Expiration Window</span>
                </label>
                <select id="mfaExpirySelect" name="mfa_expiry_minutes" class="form-select-setting">
                  <option value="1" <?= $mfa_expiry_minutes === '1' ? 'selected' : '' ?>>1 Minute (Strict High Security — 60s)</option>
                  <option value="5" <?= $mfa_expiry_minutes === '5' ? 'selected' : '' ?>>5 Minutes (Strict Security)</option>
                  <option value="10" <?= $mfa_expiry_minutes === '10' ? 'selected' : '' ?>>10 Minutes (Standard Balanced)</option>
                  <option value="15" <?= $mfa_expiry_minutes === '15' ? 'selected' : '' ?>>15 Minutes (Extended Tolerance)</option>
                </select>
                <div class="form-help-text">Lifespan of each 6-digit OTP code before automatic expiration in the database.</div>
              </div>
            </div>

            <div class="form-row-2" style="margin-bottom:18px;">
              <div class="form-group-setting">
                <label class="form-label-setting" for="mfaCooldownSelect">
                  <span>Resend Code Cooldown</span>
                  <span style="font-size:0.70rem; color:#64748b; font-weight:500;">Anti-Spam Rate Limit</span>
                </label>
                <select id="mfaCooldownSelect" name="mfa_resend_cooldown" class="form-select-setting">
                  <option value="30" <?= $mfa_resend_cooldown === '30' ? 'selected' : '' ?>>30 Seconds</option>
                  <option value="60" <?= $mfa_resend_cooldown === '60' ? 'selected' : '' ?>>60 Seconds (Recommended)</option>
                  <option value="120" <?= $mfa_resend_cooldown === '120' ? 'selected' : '' ?>>120 Seconds</option>
                </select>
                <div class="form-help-text">Minimum delay required before an active user can request a newly generated verification code.</div>
              </div>

              <div class="form-group-setting">
                <label class="form-label-setting" for="mfaTargetRole">
                  <span>MFA Protected Roles</span>
                  <span style="font-size:0.70rem; color:#64748b; font-weight:500;">Role Isolation</span>
                </label>
                <input type="text" id="mfaTargetRole" class="form-input-setting" value="Administrator (admin) &amp; Supreme Student Council (ssc)" readonly style="background:#f1f5f9; cursor:not-allowed;" />
                <div class="form-help-text">MFA email OTP verification is strictly bound to Administrator and SSC Officer roles for verified authorization.</div>
              </div>
            </div>

            <!-- Email Transport / SMTP Parameters (Optional) -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-top:8px;">
              <div style="font-size:0.84rem; font-weight:700; color:#1e293b; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-envelope-circle-check" style="color:#2563eb;"></i>
                Email Transport &amp; SMTP Parameters (Optional)
              </div>
              <div class="form-row-2" style="margin-bottom:14px;">
                <div class="form-group-setting">
                  <label class="form-label-setting" for="mailFromNameInput">
                    <span>Sender Name</span>
                  </label>
                  <input type="text" id="mailFromNameInput" name="mail_from_name" class="form-input-setting" value="<?= htmlspecialchars($mail_from_name) ?>" placeholder="BCP Co-Curricular Management System" />
                </div>
                <div class="form-group-setting">
                  <label class="form-label-setting" for="mailFromEmailInput">
                    <span>Sender Email Address</span>
                  </label>
                  <input type="email" id="mailFromEmailInput" name="mail_from_email" class="form-input-setting" value="<?= htmlspecialchars($mail_from_email) ?>" placeholder="no-reply@bcp.edu.ph" />
                </div>
              </div>

              <div class="form-row-2" style="margin-bottom:14px;">
                <div class="form-group-setting">
                  <label class="form-label-setting" for="smtpHostInput">
                    <span>SMTP Host (Leave empty for PHP mail() fallback)</span>
                  </label>
                  <input type="text" id="smtpHostInput" name="smtp_host" class="form-input-setting" value="<?= htmlspecialchars($smtp_host) ?>" placeholder="e.g. smtp.gmail.com or mail.bcp.edu.ph" />
                </div>
                <div class="form-group-setting">
                  <label class="form-label-setting" for="smtpPortInput">
                    <span>SMTP Port</span>
                  </label>
                  <input type="number" id="smtpPortInput" name="smtp_port" class="form-input-setting" value="<?= htmlspecialchars($smtp_port) ?>" placeholder="587" />
                </div>
              </div>

              <div class="form-row-2">
                <div class="form-group-setting">
                  <label class="form-label-setting" for="smtpUserInput">
                    <span>SMTP Username</span>
                  </label>
                  <input type="text" id="smtpUserInput" name="smtp_user" class="form-input-setting" value="<?= htmlspecialchars($smtp_user) ?>" placeholder="e.g. notifications@bcp.edu.ph" />
                </div>
                <div class="form-group-setting">
                  <label class="form-label-setting" for="smtpPassInput">
                    <span>SMTP Password</span>
                  </label>
                  <div class="secret-input-wrapper">
                    <input type="password" id="smtpPassInput" name="smtp_pass" class="form-input-setting" autocomplete="new-password" placeholder="<?= $has_smtp_pass ? $masked_smtp_pass . ' (Leave empty to keep)' : 'Enter SMTP password...' ?>" />
                    <button type="button" class="btn-toggle-secret" onclick="toggleSmtpPassVisibility()" title="Toggle visibility">
                      <i class="fa-solid fa-eye" id="toggleSmtpPassIcon"></i>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Test Email Diagnostic Widget -->
              <div style="margin-top:14px; padding-top:14px; border-top:1px dashed #cbd5e1; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                <div style="flex:1; min-width:240px; display:flex; gap:8px;">
                  <input type="email" id="testEmailRecipient" class="form-input-setting" style="padding:7px 12px; font-size:0.82rem;" placeholder="Enter Gmail address to test (e.g. your_email@gmail.com)" />
                  <button type="button" class="btn btn-secondary" id="btnTestEmail" onclick="handleSendTestEmail()" style="white-space:nowrap; padding:0 14px; height:36px; font-size:0.80rem; border-radius:8px;">
                    <i class="fa-solid fa-paper-plane text-primary"></i> Send Test Email
                  </button>
                </div>
                <div id="testEmailFeedback" style="font-size:0.78rem; font-weight:600; display:none;"></div>
              </div>
            </div>

          </div>
        </div>

        <!-- Sticky Save Action Footer Bar -->
        <div class="save-actions-bar">
          <div style="font-size:0.80rem; color:#64748b;">
            <i class="fa-solid fa-circle-info" style="color:#2563eb;"></i>
            All institutional configuration changes take effect immediately across all system roles.
          </div>
          <div style="display:flex; align-items:center; gap:10px;">
            <button type="reset" class="btn btn-secondary" onclick="setTimeout(()=>location.reload(), 100)" style="height:38px; padding:0 16px; font-size:0.82rem; border-radius:8px;">
              Discard Changes
            </button>
            <button type="submit" id="btnSubmitSettings" class="btn btn-primary" style="height:38px; padding:0 22px; font-size:0.84rem; font-weight:700; border-radius:8px; display:inline-flex; align-items:center; gap:8px;">
              <i class="fa-solid fa-floppy-disk"></i> Save System Settings
            </button>
          </div>
        </div>

      </form>

    </div><!-- /content-body -->
  </div><!-- /content -->

  <div class="footer">eLearning Commons &copy; 2026</div>
</div><!-- /main -->

<?php require_once __DIR__ . '/../shared/qr_modal.php'; ?>

<div id="toast" class="toast-notification" style="display:none;"></div>
<script src="../js/dashboard.js"></script>
<script>
// Secret Visibility Toggle (Safe — operates only on what the user types)
let isSecretVisible = false;
function toggleSecretVisibility() {
  const input = document.getElementById('apiKeyInput');
  const icon = document.getElementById('toggleSecretIcon');
  isSecretVisible = !isSecretVisible;
  input.type = isSecretVisible ? 'text' : 'password';
  icon.className = isSecretVisible ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
}

let isSmtpSecretVisible = false;
function toggleSmtpPassVisibility() {
  const input = document.getElementById('smtpPassInput');
  const icon = document.getElementById('toggleSmtpPassIcon');
  isSmtpSecretVisible = !isSmtpSecretVisible;
  input.type = isSmtpSecretVisible ? 'text' : 'password';
  icon.className = isSmtpSecretVisible ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
}

// Adjust model options if provider changes
function onAiProviderChange(provider) {
  const modelSelect = document.getElementById('aiModelSelect');
  if (provider === 'Google Gemini') {
    modelSelect.innerHTML = `
      <option value="gemini-2.0-flash">gemini-2.0-flash (Recommended — High Speed)</option>
      <option value="gemini-1.5-pro">gemini-1.5-pro (Deep Multimodal Reasoning)</option>
      <option value="gemini-1.5-flash">gemini-1.5-flash (Standard Fast Tier)</option>
    `;
  } else if (provider === 'OpenAI') {
    modelSelect.innerHTML = `
      <option value="gpt-4o">gpt-4o (Omni Multimodal Flagship)</option>
      <option value="gpt-4o-mini">gpt-4o-mini (Lightweight High-Efficiency)</option>
    `;
  } else if (provider === 'Anthropic') {
    modelSelect.innerHTML = `
      <option value="claude-3-5-sonnet">claude-3-5-sonnet (High Precision Code & Analysis)</option>
      <option value="claude-3-haiku">claude-3-haiku (Rapid Response)</option>
    `;
  } else if (provider === 'DeepSeek') {
    modelSelect.innerHTML = `
      <option value="deepseek-v3">deepseek-v3 (Open General Intelligence)</option>
      <option value="deepseek-r1">deepseek-r1 (Reasoning Specialized)</option>
    `;
  }
}

// Handle Form Submit via AJAX
async function handleSaveSystemSettings(e) {
  e.preventDefault();
  const btn = document.getElementById('btnSubmitSettings');
  const origHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Persisting Settings...';

  const form = document.getElementById('systemSettingsForm');
  const fd = new FormData(form);
  fd.append('action', 'save_system_settings');

  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) fd.append('csrf_token', csrfMeta.content);

  try {
    const res = await fetch('../shared/admin_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      if (typeof showToast === 'function') {
        showToast(data.message || 'System settings saved successfully!', 'success');
      } else {
        alert(data.message || 'System settings saved successfully!');
      }
      setTimeout(() => location.reload(), 800);
    } else {
      alert(data.message || 'Failed to save system settings.');
    }
  } catch (err) {
    alert('An unexpected network error occurred while persisting settings. Please try again.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = origHtml;
  }
}

// Handle Send Test Email
async function handleSendTestEmail() {
  const recipientInput = document.getElementById('testEmailRecipient');
  const recipient = (recipientInput.value || '').trim();
  const feedback = document.getElementById('testEmailFeedback');
  const btn = document.getElementById('btnTestEmail');

  if (!recipient) {
    recipientInput.focus();
    if (feedback) {
      feedback.style.display = 'block';
      feedback.style.color = '#dc2626';
      feedback.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Please enter a recipient email address.';
    }
    return;
  }

  const origHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...';
  if (feedback) {
    feedback.style.display = 'block';
    feedback.style.color = '#2563eb';
    feedback.innerHTML = '<i class="fa-solid fa-paper-plane fa-fade"></i> Attempting SMTP delivery to ' + recipient + '...';
  }

  const fd = new FormData();
  fd.append('action', 'test_email_delivery');
  fd.append('recipient', recipient);

  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  if (csrfMeta) fd.append('csrf_token', csrfMeta.content);

  try {
    const res = await fetch('../shared/admin_actions.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      feedback.style.color = '#16a34a';
      feedback.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + (data.message || 'Test email dispatched successfully! Check inbox.');
      if (typeof showToast === 'function') showToast('Test email sent!', 'success');
    } else {
      feedback.style.color = '#dc2626';
      feedback.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> ' + (data.message || 'Failed to dispatch test email.');
    }
  } catch (err) {
    feedback.style.color = '#dc2626';
    feedback.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Network error connecting to email service.';
  } finally {
    btn.disabled = false;
    btn.innerHTML = origHtml;
  }
}
</script>
</body>
</html>
