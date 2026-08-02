<?php
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: ../dashboard/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Student Registration – BCP Co-Curricular Management System</title>
  <link rel="stylesheet" href="../css/auth.css" />
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<div class="outer">
  <div class="card">
    <!-- Left panel -->
    <div class="left">
      <div class="left-top">
        <img src="../images/BCP_LOGO.png" alt="BCP Logo" class="left-logo"/>
        <p class="left-school">Bestlink College of the Philippines</p>
      </div>
      <div class="left-body">
        <h1>Co-Curricular Management System</h1>
        <p class="subtitle">Student Account Registration</p>
        <p>
          Register your student account to access organizational activities, apply for club memberships, track events, and manage achievements.
        </p>
      </div>
    </div>

    <!-- Right panel -->
    <div class="right">
      <img class="bcp-logo" src="../images/BCP_LOGO.png" alt="Bestlink College of the Philippines Logo" />

      <h2>Register Student Account</h2>

      <div class="auth-error" id="authError" style="display:none;"></div>

      <form id="registerForm" style="width:100%">
        <div style="display: flex; gap: 10px;">
          <div class="form-group" style="flex: 1;">
            <label><i class="fa-solid fa-id-card"></i> First Name</label>
            <input type="text" id="firstName" required />
          </div>
          <div class="form-group" style="flex: 1;">
            <label><i class="fa-solid fa-id-card"></i> Last Name</label>
            <input type="text" id="lastName" required />
          </div>
        </div>

        <div class="form-group">
          <label><i class="fa-solid fa-user"></i> Username</label>
          <input type="text" id="username" autocomplete="username" required />
        </div>

        <div class="form-group">
          <label><i class="fa-solid fa-envelope"></i> Email Address</label>
          <input type="email" id="email" autocomplete="email" required />
        </div>

        <div class="form-group">
          <label><i class="fa-solid fa-lock"></i> Password</label>
          <input type="password" id="password" autocomplete="new-password" required />
        </div>

        <button type="submit" class="btn-signin" id="btnRegister">
          Register Account
          <i class="fa-solid fa-user-plus"></i>
        </button>

        <p style="text-align: center; margin-top: 15px; font-size: 0.9rem; color: #64748b;">
          Already have an account? <a href="signin.php" style="color: #2563eb; text-decoration: none; font-weight: 600;">Sign In</a>
        </p>
      </form>

    </div>
  </div>
</div>

<script>
document.getElementById('registerForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const firstName = document.getElementById('firstName').value.trim();
    const lastName  = document.getElementById('lastName').value.trim();
    const username  = document.getElementById('username').value.trim();
    const email     = document.getElementById('email').value.trim();
    const password  = document.getElementById('password').value;
    const errorBox  = document.getElementById('authError');
    const btn       = document.getElementById('btnRegister');

    errorBox.style.display = 'none';
    if (!firstName || !lastName || !username || !email || !password) return showError('Please fill in all fields.');

    btn.disabled    = true;
    btn.textContent = 'Registering account…';

    const fd = new FormData();
    fd.set('action', 'register');
    fd.set('first_name', firstName);
    fd.set('last_name', lastName);
    fd.set('username', username);
    fd.set('email', email);
    fd.set('password', password);

    try {
        const data = await fetch('../shared/auth_actions.php', { method: 'POST', body: fd }).then(r => r.json());
        if (data.success) {
            window.location.href = '../dashboard/loading.php';
        } else {
            showError(data.message);
            btn.disabled  = false;
            btn.innerHTML = 'Register Account <i class="fa-solid fa-user-plus"></i>';
        }
    } catch {
        showError('Registration request failed. Please try again.');
        btn.disabled  = false;
        btn.innerHTML = 'Register Account <i class="fa-solid fa-user-plus"></i>';
    }

    function showError(msg) {
        errorBox.textContent   = msg;
        errorBox.style.display = 'block';
    }
});
</script>
</body>
</html>
