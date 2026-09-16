<?php
// ============================================================
//  REGISTER.PHP  (auth/)
//  Self-registration is disabled; all accounts are pre-provisioned.
// ============================================================
header('Location: signin.php');
exit;

$csrf_token = generate_csrf_token();

// Fetch active academic programs
$programs = [];
$res = $conn->query("SELECT code, name FROM academic_programs WHERE status = 'Active' ORDER BY code ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $programs[] = $row;
    }
}
if (empty($programs)) {
    $programs = [
        ['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology'],
        ['code' => 'BSCS', 'name' => 'Bachelor of Science in Computer Science'],
        ['code' => 'BSCpE', 'name' => 'Bachelor of Science in Computer Engineering'],
        ['code' => 'BSHM', 'name' => 'Bachelor of Science in Hospitality Management'],
        ['code' => 'BSTM', 'name' => 'Bachelor of Science in Tourism Management'],
        ['code' => 'BSBA', 'name' => 'Bachelor of Science in Business Administration'],
        ['code' => 'BSA', 'name' => 'Bachelor of Science in Accountancy'],
        ['code' => 'BEEd', 'name' => 'Bachelor of Elementary Education'],
        ['code' => 'BSEd', 'name' => 'Bachelor of Secondary Education'],
        ['code' => 'BSCrim', 'name' => 'Bachelor of Science in Criminology'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Student Registration – BCP Co-Curricular Management System</title>
  <link rel="stylesheet" href="../css/auth.css?v=1.2" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <style>
    .card.register-card {
      max-width: 980px;
      min-height: auto;
    }
    .grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }
    @media (max-width: 768px) {
      .grid-2 {
        grid-template-columns: 1fr;
      }
    }
    .form-group select {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid #d1d5db;
      border-radius: 8px;
      font-size: 14px;
      color: #1f2937;
      background-color: #fff;
      transition: border-color 0.2s;
    }
    .form-group select:focus {
      border-color: #2563eb;
      outline: none;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }
    .auth-error {
      padding: 12px 14px;
      border-radius: 8px;
      background-color: #fef2f2;
      border: 1px solid #f87171;
      color: #b91c1c;
      font-size: 13.5px;
      margin-bottom: 16px;
    }
    .auth-success {
      padding: 12px 14px;
      border-radius: 8px;
      background-color: #f0fdf4;
      border: 1px solid #4ade80;
      color: #15803d;
      font-size: 13.5px;
      margin-bottom: 16px;
    }
    .signin-link {
      text-align: center;
      margin-top: 16px;
      font-size: 14px;
      color: #6b7280;
    }
    .signin-link a {
      color: #2563eb;
      font-weight: 600;
      text-decoration: none;
    }
    .signin-link a:hover {
      text-decoration: underline;
    }
  </style>
</head>

<body>
  <div class="outer">
    <div class="card register-card">
      <!-- Left Branding Panel -->
      <div class="left">
        <div class="left-top">
          <img src="../images/BCP_LOGO.png" alt="BCP Logo" class="left-logo" />
          <p class="left-school">Bestlink College of the Philippines</p>
        </div>
        <div class="left-body">
          <h1>Student Registration</h1>
          <p class="subtitle" style="font-weight: 600; color: #93c5fd; line-height: 1.4;">Create your official Co-Curricular student profile</p>
          <p>
            Join student clubs, monitor attendance via dynamic QR codes, participate in digital ballots, and receive personalized <span>AI-based activity recommendations</span>.
          </p>
        </div>
      </div>

      <!-- Right Registration Form -->
      <div class="right" style="padding: 28px 32px;">
        <img class="bcp-logo" src="../images/BCP_LOGO.png" alt="BCP Logo" style="height: 52px; margin-bottom: 12px;" />

        <h2 style="margin-bottom: 4px;">Student Account Sign Up</h2>
        <p style="font-size: 13px; color: #6b7280; margin-bottom: 20px;">Fill in your verified academic details</p>

        <div class="auth-error" id="authError" style="display:none;"></div>
        <div class="auth-success" id="authSuccess" style="display:none;"></div>

        <form id="registerForm" style="width: 100%;">
          <input type="hidden" id="csrfToken" value="<?= htmlspecialchars($csrf_token) ?>" />

          <div class="grid-2">
            <div class="form-group">
              <label><i class="fa-solid fa-user"></i> First Name</label>
              <input type="text" id="first_name" required placeholder="e.g. Juan" />
            </div>
            <div class="form-group">
              <label><i class="fa-solid fa-user"></i> Last Name</label>
              <input type="text" id="last_name" required placeholder="e.g. Dela Cruz" />
            </div>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label><i class="fa-solid fa-id-card"></i> Student Number</label>
              <input type="text" id="student_number" required placeholder="e.g. 2024-00123" />
            </div>
            <div class="form-group">
              <label><i class="fa-solid fa-graduation-cap"></i> Academic Program</label>
              <select id="course" required>
                <option value="" disabled selected>-- Select Program --</option>
                <?php foreach ($programs as $p): ?>
                  <option value="<?= htmlspecialchars($p['code']) ?>">
                    <?= htmlspecialchars($p['code'] . ' – ' . $p['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label><i class="fa-solid fa-layer-group"></i> Year Level</label>
              <select id="year_level" required>
                <option value="1">1st Year</option>
                <option value="2">2nd Year</option>
                <option value="3">3rd Year</option>
                <option value="4">4th Year</option>
              </select>
            </div>
            <div class="form-group">
              <label><i class="fa-solid fa-users-rectangle"></i> Section</label>
              <input type="text" id="section" placeholder="e.g. 1A or 201" />
            </div>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label><i class="fa-solid fa-envelope"></i> Email Address</label>
              <input type="email" id="email" required placeholder="e.g. student@bcp.edu.ph" />
            </div>
            <div class="form-group">
              <label><i class="fa-solid fa-phone"></i> Contact Phone</label>
              <input type="tel" id="phone" placeholder="e.g. 09123456789" />
            </div>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label><i class="fa-solid fa-at"></i> Desired Username</label>
              <input type="text" id="username" required autocomplete="username" placeholder="e.g. jdelacruz" />
            </div>
            <div class="form-group">
              <label><i class="fa-solid fa-calendar"></i> Birthday</label>
              <input type="date" id="birthday" />
            </div>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label><i class="fa-solid fa-lock"></i> Password</label>
              <div class="password-wrap">
                <input type="password" id="password" required autocomplete="new-password" placeholder="Min. 6 characters" />
                <button type="button" class="toggle-pw" data-target="password" title="Toggle visibility">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
            </div>
            <div class="form-group">
              <label><i class="fa-solid fa-lock"></i> Confirm Password</label>
              <div class="password-wrap">
                <input type="password" id="confirm_password" required autocomplete="new-password" placeholder="Repeat password" />
                <button type="button" class="toggle-pw" data-target="confirm_password" title="Toggle visibility">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
            </div>
          </div>

          <button type="submit" class="btn-signin" id="btnRegister" style="margin-top: 12px;">
            Complete Registration
            <i class="fa-solid fa-user-plus"></i>
          </button>
        </form>

        <div class="signin-link">
          Already registered? <a href="signin.php">Sign In here</a>
        </div>
      </div>
    </div>
  </div>

  <script>
    document.getElementById('registerForm').addEventListener('submit', async function (e) {
      e.preventDefault();
      const errBox = document.getElementById('authError');
      const succBox = document.getElementById('authSuccess');
      const btn = document.getElementById('btnRegister');

      errBox.style.display = 'none';
      succBox.style.display = 'none';

      const pw = document.getElementById('password').value;
      const cpw = document.getElementById('confirm_password').value;

      if (pw !== cpw) {
        errBox.textContent = 'Passwords do not match. Please re-check.';
        errBox.style.display = 'block';
        return;
      }

      if (pw.length < 6) {
        errBox.textContent = 'Password must be at least 6 characters.';
        errBox.style.display = 'block';
        return;
      }

      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating Account…';

      const fd = new FormData();
      fd.set('action', 'register');
      fd.set('csrf_token', document.getElementById('csrfToken').value);
      fd.set('first_name', document.getElementById('first_name').value.trim());
      fd.set('last_name', document.getElementById('last_name').value.trim());
      fd.set('student_number', document.getElementById('student_number').value.trim());
      fd.set('course', document.getElementById('course').value);
      fd.set('year_level', document.getElementById('year_level').value);
      fd.set('section', document.getElementById('section').value.trim());
      fd.set('email', document.getElementById('email').value.trim());
      fd.set('phone', document.getElementById('phone').value.trim());
      fd.set('username', document.getElementById('username').value.trim());
      fd.set('birthday', document.getElementById('birthday').value);
      fd.set('password', pw);
      fd.set('confirm_password', cpw);

      try {
        const res = await fetch('../shared/auth_actions.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
          succBox.textContent = data.message;
          succBox.style.display = 'block';
          document.getElementById('registerForm').reset();
          setTimeout(() => {
            window.location.href = 'signin.php?registered=1';
          }, 1800);
        } else {
          errBox.textContent = data.message || 'Registration failed.';
          errBox.style.display = 'block';
          btn.disabled = false;
          btn.innerHTML = 'Complete Registration <i class="fa-solid fa-user-plus"></i>';
        }
      } catch (err) {
        errBox.textContent = 'Network or server error. Please try again.';
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = 'Complete Registration <i class="fa-solid fa-user-plus"></i>';
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
