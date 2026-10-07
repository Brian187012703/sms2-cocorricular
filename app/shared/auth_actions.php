<?php
// ============================================================
//  AUTH_ACTIONS.PHP  (shared/)
//  Handles all user-auth AJAX requests.
// ============================================================
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';


$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (!function_exists('respond')) {
    function respond(bool $ok, string $msg, array $extra = []): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
        exit;
    }
}

if (!function_exists('respond_fast')) {
    /**
     * Immediately flush JSON response to HTTP client and close connection,
     * allowing heavy background tasks (such as SMTP email delivery) to continue
     * without delaying the user interface or credentials verification.
     */
    function respond_fast(bool $ok, string $msg, array $extra = []): void
    {
        ignore_user_abort(true);
        set_time_limit(120);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        header('Content-Type: application/json');
        $payload = json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
        header('Content-Length: ' . strlen($payload));
        header('Connection: close');

        echo $payload;

        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}

function requireSession(): void
{
    if (empty($_SESSION['user_id'])) {
        respond(false, 'Not authenticated.');
    }
}

switch ($action) {

    case 'login': {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$username || !$password)
            respond(false, 'Username and password are required.');

        // Normalize username aliases (e.g. scc.admin, ssc.officer aliases) for clean index hit
        $lookupUser = ($username === 'admin' || $username === 'ssc.admin') ? 'scc.admin' : $username;
        if (strcasecmp($username, 'ssc@bcp.edu.ph') === 0 || strcasecmp($username, 'ssc.officer') === 0) {
            $lookupUser = 'ssc.officer';
        }

        $stmt = $conn->prepare(
            'SELECT id, username, email, first_name, last_name, password_hash, role, status, profile_pic, failed_login_attempts, locked_until
         FROM users 
         WHERE username = ? 
            OR email = ? 
         LIMIT 1'
        );
        $stmt->bind_param('ss', $lookupUser, $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // 1. Account Lockout Check
        if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $remainingMins = max(1, (int)ceil((strtotime($user['locked_until']) - time()) / 60));
            require_once __DIR__ . '/notification_actions.php';
            log_audit($conn, (int)$user['id'], 'AUTH_LOCKED_ATTEMPT', 'users', (int)$user['id'], "Login rejected: Account locked until {$user['locked_until']}.", 'warning', $username);
            respond(false, "Account is temporarily locked due to multiple failed login attempts. Please try again in {$remainingMins} minute(s) or contact an administrator.");
        }

        $isValidPassword = $user && password_verify($password, $user['password_hash']);

        if (!$user || !$isValidPassword) {
            require_once __DIR__ . '/notification_actions.php';
            log_audit($conn, $user ? (int)$user['id'] : null, 'AUTH_LOGIN_FAILED', 'users', $user ? (int)$user['id'] : 0, "Failed authentication attempt for username: " . htmlspecialchars($username), 'warning', $username);

            if ($user) {
                $failedCount = ((int)($user['failed_login_attempts'] ?? 0)) + 1;
                if ($failedCount >= 5) {
                    $lockUntil = date('Y-m-d H:i:s', time() + 900); // 15 mins lock
                    $conn->query("UPDATE users SET failed_login_attempts = {$failedCount}, locked_until = '{$lockUntil}' WHERE id = " . (int)$user['id']);
                    log_audit($conn, (int)$user['id'], 'AUTH_ACCOUNT_LOCKED', 'users', (int)$user['id'], "Account locked for 15 minutes after 5 consecutive failed login attempts.", 'critical', $username);
                    respond(false, 'Account has been temporarily locked due to 5 consecutive failed login attempts. Please try again in 15 minutes.');
                } else {
                    $conn->query("UPDATE users SET failed_login_attempts = {$failedCount} WHERE id = " . (int)$user['id']);
                    $rem = 5 - $failedCount;
                    respond(false, "Invalid username or password. ({$rem} attempt(s) remaining before temporary lockout).");
                }
            }

            respond(false, 'Invalid username or password.');
        }

        // Reset failed counter on successful authentication
        if ($user) {
            $conn->query("UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = " . (int)$user['id']);
        }


        if (isset($user['status']) && strcasecmp($user['status'], 'Active') !== 0) {
            respond(false, 'Your account has been deactivated. Please contact an institutional administrator.');
        }

        // Multi-Factor Authentication Check
        // Enabled for all system roles: 'ssc', 'admin', 'club_adviser', and 'student'
        $mfa_enabled = false;
        if (in_array($user['role'], ['ssc', 'admin', 'club_adviser', 'student'], true)) {
            try {
                $mfa_setting_res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'mfa_enabled' LIMIT 1");
                if ($mfa_setting_res && $mfa_row = $mfa_setting_res->fetch_assoc()) {
                    $mfa_enabled = ($mfa_row['setting_value'] !== '0' && strtolower($mfa_row['setting_value']) !== 'false');
                }
            } catch (Throwable $e) {}
        }

        if ($mfa_enabled) {
            require_once __DIR__ . '/mail_helper.php';
            $fullName = trim($user['first_name'] . ' ' . $user['last_name']);
            $mfaResult = issue_mfa_code(
                $conn,
                (int)$user['id'],
                $user['email'],
                $fullName ?: $user['username'],
                'login'
            );

            if (!$mfaResult['success']) {
                respond(false, $mfaResult['message'] ?? 'Failed to issue verification code. Please try again.');
            }

            // Set pending MFA challenge session (user_id is NOT yet authenticated)
            $_SESSION = [];
            $_SESSION['mfa_pending']     = true;
            $_SESSION['mfa_user_id']     = (int)$user['id'];
            $_SESSION['mfa_username']    = $user['username'];
            $_SESSION['mfa_email']       = $user['email'];
            $_SESSION['mfa_name']        = $fullName;
            $_SESSION['mfa_role']        = $user['role'];
            $_SESSION['mfa_profile_pic'] = $user['profile_pic'] ?? null;
            $_SESSION['mfa_created_at']  = time();

            require_once __DIR__ . '/notification_actions.php';
            log_audit(
                $conn,
                (int)$user['id'],
                'AUTH_MFA_CHALLENGE_ISSUED',
                'mfa_codes',
                $mfaResult['code_id'] ?? 0,
                "Issued 6-digit email MFA challenge for user {$user['username']} to {$mfaResult['email_masked']}",
                'info',
                $user['username']
            );

            // Instantly complete HTTP credentials check for user (<0.1s response)
            respond_fast(true, 'A 6-digit verification code has been dispatched to your email address.', [
                'mfa_required'    => true,
                'email_masked'    => $mfaResult['email_masked'],
                'expires_in'      => $mfaResult['expires_in'] ?? 600,
                'resend_cooldown' => $mfaResult['resend_cooldown'] ?? 60
            ]);

            // Dispatch email in background without blocking credentials verification
            dispatch_mfa_email(
                $conn,
                (int)$mfaResult['code_id'],
                $user['email'],
                $fullName ?: $user['username'],
                $mfaResult['code'],
                (int)(($mfaResult['expires_in'] ?? 60) / 60)
            );
            exit;
        }

        // Direct authentication fallback (if MFA disabled in system_settings)
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['real_role'] = $user['role'];
        $_SESSION['profile_pic'] = $user['profile_pic'] ?? null;
        $_SESSION['last_activity'] = time();
        $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . (int)$user['id']);
        load_user_permissions($conn, $user['role']);

        respond(true, 'Login successful.', [
            'role' => $user['role'],
            'profile_pic' => $user['profile_pic'] ?? null
        ]);
    }

    case 'verify_mfa': {
        if (empty($_SESSION['mfa_pending']) || empty($_SESSION['mfa_user_id'])) {
            respond(false, 'Your verification session has expired. Please sign in again.');
        }

        $userId = (int)$_SESSION['mfa_user_id'];
        $code = trim($_POST['code'] ?? '');

        if (!$code) {
            respond(false, 'Please enter the 6-digit verification code.');
        }

        require_once __DIR__ . '/mail_helper.php';
        $verifyRes = verify_mfa_code($conn, $userId, $code, 'login');

        if (!$verifyRes['success']) {
            respond(false, $verifyRes['message'], [
                'remaining' => $verifyRes['remaining'] ?? null,
                'expired'   => $verifyRes['expired'] ?? false,
                'locked'    => $verifyRes['locked'] ?? false
            ]);
        }

        // Verification succeeded! Fetch complete user record
        $stmt = $conn->prepare("SELECT id, username, email, first_name, last_name, role, profile_pic FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            respond(false, 'User account could not be found.');
        }

        // Cleanly elevate from pending MFA to authenticated user session
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['real_role'] = $user['role'];
        $_SESSION['profile_pic'] = $user['profile_pic'] ?? null;
        $_SESSION['last_activity'] = time();
        $_SESSION['mfa_verified'] = true;

        $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . (int)$user['id']);
        load_user_permissions($conn, $user['role']);

        require_once __DIR__ . '/notification_actions.php';
        log_audit(
            $conn,
            (int)$user['id'],
            'AUTH_LOGIN_SUCCESS',
            'users',
            (int)$user['id'],
            "Successful authorization with email MFA verification for: " . htmlspecialchars($user['username']),
            'info',
            $user['username']
        );

        respond(true, 'Authentication verified successfully!', [
            'role'        => $user['role'],
            'profile_pic' => $user['profile_pic'] ?? null
        ]);
    }

    case 'resend_mfa': {
        if (empty($_SESSION['mfa_pending']) || empty($_SESSION['mfa_user_id'])) {
            respond(false, 'Verification session expired. Please sign in again.');
        }

        $userId = (int)$_SESSION['mfa_user_id'];
        $userEmail = $_SESSION['mfa_email'] ?? '';
        $userName = $_SESSION['mfa_name'] ?? ($_SESSION['mfa_username'] ?? 'User');

        require_once __DIR__ . '/mail_helper.php';
        $resendRes = issue_mfa_code($conn, $userId, $userEmail, $userName, 'login');

        if (!$resendRes['success']) {
            respond(false, $resendRes['message'], [
                'cooldown' => $resendRes['cooldown'] ?? false,
                'wait'     => $resendRes['wait'] ?? 0
            ]);
        }

        // Instantly notify client and reset countdown
        respond_fast(true, 'A new verification code has been dispatched to your email.', [
            'email_masked'    => $resendRes['email_masked'],
            'expires_in'      => $resendRes['expires_in'] ?? 600,
            'resend_cooldown' => $resendRes['resend_cooldown'] ?? 60
        ]);

        // Background delivery of code via SMTP
        dispatch_mfa_email(
            $conn,
            (int)$resendRes['code_id'],
            $userEmail,
            $userName,
            $resendRes['code'],
            (int)(($resendRes['expires_in'] ?? 60) / 60)
        );
        exit;
    }

    case 'cancel_mfa': {
        $_SESSION = [];
        respond(true, 'Sign in cancelled.');
    }

    case 'get_mfa_status': {
        if (!empty($_SESSION['mfa_pending']) && !empty($_SESSION['mfa_user_id'])) {
            require_once __DIR__ . '/mail_helper.php';
            $userId = (int)$_SESSION['mfa_user_id'];
            $chk = $conn->query("SELECT expires_at, TIMESTAMPDIFF(SECOND, NOW(), expires_at) as remaining_seconds FROM mfa_codes WHERE user_id = $userId AND purpose = 'login' AND is_used = 0 ORDER BY id DESC LIMIT 1");
            $r = $chk ? $chk->fetch_assoc() : null;
            respond(true, 'Pending MFA session', [
                'pending'           => true,
                'email_masked'      => mask_email($_SESSION['mfa_email'] ?? ''),
                'remaining_seconds' => max(0, (int)($r['remaining_seconds'] ?? 0))
            ]);
        }
        respond(true, 'No pending MFA challenge', ['pending' => false]);
    }

    case 'register': {
        respond(false, 'Public self-registration is disabled. All student and institutional accounts are pre-provisioned by the administration.');
    }

    case 'upload_avatar': {
        requireSession();
        $userId = (int)$_SESSION['user_id'];

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => 'Image exceeds server upload_max_filesize limit.',
                UPLOAD_ERR_FORM_SIZE  => 'Image exceeds form MAX_FILE_SIZE limit.',
                UPLOAD_ERR_PARTIAL    => 'Image was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No image file was selected.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write image to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.'
            ];
            $errCode = $_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE;
            respond(false, $errMap[$errCode] ?? 'File upload error occurred.');
        }

        $file = $_FILES['avatar'];
        $maxBytes = 5 * 1024 * 1024; // 5 MB
        if ($file['size'] > $maxBytes) {
            respond(false, 'Profile picture must not exceed 5MB.');
        }

        // Validate image format via getimagesize
        $imgInfo = @getimagesize($file['tmp_name']);
        if ($imgInfo === false) {
            respond(false, 'The uploaded file is not a valid image.');
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $mime = $imgInfo['mime'] ?? '';
        if (!in_array($mime, $allowedMimes, true)) {
            respond(false, 'Only JPG, PNG, WEBP, or GIF image formats are allowed.');
        }

        // Determine extension
        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif'
        ];
        $ext = $extMap[$mime] ?? 'jpg';

        $uploadDir = __DIR__ . '/../uploads/avatars/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = 'avatar_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            respond(false, 'Failed to save uploaded picture. Check folder permissions.');
        }

        // Fetch and remove previous avatar if exists
        $stmt = $conn->prepare('SELECT profile_pic FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $oldPic = $stmt->get_result()->fetch_assoc()['profile_pic'] ?? null;
        $stmt->close();

        if ($oldPic && $oldPic !== $filename) {
            $oldPath = $uploadDir . basename($oldPic);
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        // 1. Record in dedicated profile_photos table in database
        $conn->query("UPDATE profile_photos SET is_current = 0 WHERE user_id = $userId");
        $relPath = 'uploads/avatars/' . $filename;
        $fileSize = (int)$file['size'];
        $photoStmt = $conn->prepare("INSERT INTO profile_photos (user_id, file_name, file_path, file_size, mime_type, is_current) VALUES (?, ?, ?, ?, ?, 1)");
        if ($photoStmt) {
            $photoStmt->bind_param('issis', $userId, $filename, $relPath, $fileSize, $mime);
            $photoStmt->execute();
            $photoStmt->close();
        }

        // 2. Update master users table
        $stmt = $conn->prepare('UPDATE users SET profile_pic = ? WHERE id = ?');
        $stmt->bind_param('si', $filename, $userId);
        if ($stmt->execute()) {
            $stmt->close();
            $_SESSION['profile_pic'] = $filename;

            // 3. Sync to dedicated organization databases where user is a member
            require_once __DIR__ . '/org_db_manager.php';
            $club_res = $conn->query("SELECT c.code FROM club_memberships cm JOIN clubs c ON c.id = cm.club_id WHERE cm.user_id = $userId");
            if ($club_res) {
                while ($crow = $club_res->fetch_assoc()) {
                    $org_c = get_org_db_connection($crow['code']);
                    if ($org_c) {
                        $upd_mem = $org_c->prepare("UPDATE org_members SET profile_pic = ? WHERE user_id = ?");
                        if ($upd_mem) {
                            $upd_mem->bind_param('si', $filename, $userId);
                            $upd_mem->execute();
                            $upd_mem->close();
                        }
                        $org_c->close();
                    }
                }
            }

            respond(true, 'Profile picture updated and stored in database successfully!', [
                'profile_pic' => $filename,
                'avatar_url'  => '../uploads/avatars/' . $filename
            ]);
        }
        $stmt->close();
        respond(false, 'Failed to update user profile picture in database.');
    }

    case 'remove_avatar': {
        requireSession();
        $userId = (int)$_SESSION['user_id'];

        $stmt = $conn->prepare('SELECT profile_pic FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $oldPic = $stmt->get_result()->fetch_assoc()['profile_pic'] ?? null;
        $stmt->close();

        if ($oldPic) {
            $oldPath = __DIR__ . '/../uploads/avatars/' . basename($oldPic);
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        // Update profile_photos table
        $conn->query("UPDATE profile_photos SET is_current = 0 WHERE user_id = $userId");

        $stmt = $conn->prepare('UPDATE users SET profile_pic = NULL WHERE id = ?');
        $stmt->bind_param('i', $userId);
        if ($stmt->execute()) {
            $stmt->close();
            $_SESSION['profile_pic'] = null;

            // Sync with dedicated organization databases
            require_once __DIR__ . '/org_db_manager.php';
            $club_res = $conn->query("SELECT c.code FROM club_memberships cm JOIN clubs c ON c.id = cm.club_id WHERE cm.user_id = $userId");
            if ($club_res) {
                while ($crow = $club_res->fetch_assoc()) {
                    $org_c = get_org_db_connection($crow['code']);
                    if ($org_c) {
                        $org_c->query("UPDATE org_members SET profile_pic = NULL WHERE user_id = $userId");
                        $org_c->close();
                    }
                }
            }

            respond(true, 'Profile picture removed successfully.');
        }
        $stmt->close();
        respond(false, 'Failed to remove profile picture.');
    }

    case 'get_avatar_history': {
        requireSession();
        $userId = (int)$_SESSION['user_id'];
        $res = $conn->query("SELECT id, file_name, file_path, file_size, mime_type, is_current, uploaded_at FROM profile_photos WHERE user_id = $userId ORDER BY uploaded_at DESC");
        $photos = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        respond(true, 'Avatar history fetched successfully.', ['photos' => $photos]);
    }

    case 'update_profile': {
        requireSession();
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');

        if (!$first_name || !$last_name || !$email || !$username)
            respond(false, 'All profile fields are required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            respond(false, 'Invalid email address.');

        $userId = $_SESSION['user_id'];
        $stmt = $conn->prepare(
            'SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ? LIMIT 1'
        );
        $stmt->bind_param('ssi', $username, $email, $userId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0)
            respond(false, 'Username or email is already used by another account.');
        $stmt->close();

        $stmt = $conn->prepare(
            'UPDATE users SET first_name=?, last_name=?, email=?, username=? WHERE id=?'
        );
        $stmt->bind_param('ssssi', $first_name, $last_name, $email, $username, $userId);
        if ($stmt->execute()) {
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            $_SESSION['email'] = $email;
            $_SESSION['username'] = $username;
            respond(true, 'Profile updated successfully.');
        }
        respond(false, 'Failed to update profile.');
    }

    case 'change_password': {
        requireSession();
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$current || !$new || !$confirm)
            respond(false, 'All password fields are required.');
        if (strlen($new) < 6)
            respond(false, 'New password must be at least 6 characters.');
        if ($new !== $confirm)
            respond(false, 'New passwords do not match.');

        $userId = $_SESSION['user_id'];
        $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($current, $row['password_hash']))
            respond(false, 'Current password is incorrect.');

        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->bind_param('si', $newHash, $userId);
        if ($stmt->execute())
            respond(true, 'Password changed successfully.');
        respond(false, 'Failed to update password.');
    }

    case 'keepalive':
    case 'heartbeat': {
        requireSession();
        $_SESSION['last_activity'] = time();
        respond(true, 'Session extended successfully.', [
            'timeout' => defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 300,
            'time'    => time()
        ]);
    }

    case 'logout': {
        unset($_SESSION['role'], $_SESSION['real_role']);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        @session_destroy();
        respond(true, 'Logged out.');
    }

    case 'delete_account': {
        requireSession();
        $userId = $_SESSION['user_id'];
        $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
        $stmt->bind_param('i', $userId);
        if ($stmt->execute()) {
            session_destroy();
            respond(true, 'Account deleted.');
        }
        respond(false, 'Failed to delete account.');
    }

    default:
        respond(false, 'Unknown action.');
}

$conn->close();
