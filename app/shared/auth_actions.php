<?php
// ============================================================
//  AUTH_ACTIONS.PHP  (shared/)
//  Handles all user-auth AJAX requests.
// ============================================================
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function respond(bool $ok, string $msg, array $extra = []): void
{
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
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

        $stmt = $conn->prepare(
            'SELECT id, username, email, first_name, last_name, password_hash, role, profile_pic
         FROM users WHERE username = ? LIMIT 1'
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($password, $user['password_hash']))
            respond(false, 'Invalid username or password.');

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['profile_pic'] = $user['profile_pic'] ?? null;

        respond(true, 'Login successful.', [
            'role' => $user['role'],
            'profile_pic' => $user['profile_pic'] ?? null
        ]);
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

        // Update database
        $stmt = $conn->prepare('UPDATE users SET profile_pic = ? WHERE id = ?');
        $stmt->bind_param('si', $filename, $userId);
        if ($stmt->execute()) {
            $stmt->close();
            $_SESSION['profile_pic'] = $filename;
            respond(true, 'Profile picture updated successfully!', [
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

        $stmt = $conn->prepare('UPDATE users SET profile_pic = NULL WHERE id = ?');
        $stmt->bind_param('i', $userId);
        if ($stmt->execute()) {
            $stmt->close();
            $_SESSION['profile_pic'] = null;
            respond(true, 'Profile picture removed successfully.');
        }
        $stmt->close();
        respond(false, 'Failed to remove profile picture.');
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

    case 'logout': {
        session_destroy();
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
