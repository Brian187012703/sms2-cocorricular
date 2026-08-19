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
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Sign In – BCP Co-Curricular Management System</title>
  <link rel="stylesheet" href="../css/auth.css?v=1.1" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
</head>

<body>
  <div class="outer">
    <div class="card">
      <!-- Left panel -->
      <div class="left">
        <div class="left-top">
          <img src="../images/BCP_LOGO.png" alt="BCP Logo" class="left-logo" />
          <p class="left-school">Bestlink College of the Philippines</p>
        </div>
        <div class="left-body">
          <h1>Co-Curricular Management System</h1>
          <p class="subtitle" style="font-weight: 600; color: #93c5fd; line-height: 1.4;">with Intelligent Report &amp; AI-Based Activity Recommendations</p>
          <p>
            An intelligent portal for Bestlink College of the Philippines empowering student organizations, dynamic event tracking, <span>AI-based activity recommendations</span>, and <span>intelligent analytical reporting</span>.
          </p>
        </div>
      </div>

      <!-- Right panel -->
      <div class="right">
        <img class="bcp-logo" src="../images/BCP_LOGO.png" alt="Bestlink College of the Philippines Logo" />

        <h2>Sign In Account</h2>

        <div class="auth-error" id="authError" style="display:none;"></div>

        <form id="signinForm" style="width:100%">
          <div class="form-group">
            <label>
              <i class="fa-solid fa-user"></i>
              Username
            </label>
            <input type="text" id="username" autocomplete="username" />
          </div>

          <div class="form-group">
            <label>
              <i class="fa-solid fa-lock"></i>
              Password
            </label>
            <div class="password-wrap">
              <input type="password" id="password" autocomplete="current-password" />
              <button type="button" class="toggle-pw" data-target="password" title="Toggle visibility">
                <i class="fa-solid fa-eye"></i>
              </button>
            </div>
          </div>

          <button type="submit" class="btn-signin" id="btnSignin">
            Sign In
            <i class="fa-solid fa-arrow-right"></i>
          </button>
        </form>

      </div>
    </div>
  </div>

  <script>
    document.getElementById('signinForm').addEventListener('submit', async function (e) {
      e.preventDefault();
      const username = document.getElementById('username').value.trim();
      const password = document.getElementById('password').value;
      const errorBox = document.getElementById('authError');
      const btn = document.getElementById('btnSignin');

      errorBox.style.display = 'none';
      if (!username || !password) return showError('Please enter your username and password.');

      btn.disabled = true;
      btn.textContent = 'Signing in…';

      const fd = new FormData();
      fd.set('action', 'login');
      fd.set('username', username);
      fd.set('password', password);

      try {
        const data = await fetch('../shared/auth_actions.php', { method: 'POST', body: fd }).then(r => r.json());
        if (data.success) {
          window.location.href = '../dashboard/loading.php';
        } else {
          showError(data.message);
          btn.disabled = false;
          btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
        }
      } catch {
        showError('Request failed. Please try again.');
        btn.disabled = false;
        btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
      }

      function showError(msg) {
        errorBox.textContent = msg;
        errorBox.style.display = 'block';
      }
    });

    document.querySelectorAll('.toggle-pw').forEach(btn => {
      btn.addEventListener('click', () => {
        const inp = document.getElementById(btn.dataset.target);
        inp.type = inp.type === 'password' ? 'text' : 'password';
        btn.querySelector('i').className = inp.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
      });
    });
  </script>
</body>

</html>