<?php
// ============================================================
//  SECURITY.PHP — Central Authentication, RBAC & CSRF Protection
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate or retrieve CSRF token for the session
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Generate hidden input field for forms
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verify CSRF token from POST or headers
 */
function verify_csrf(bool $halt = true): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $valid = !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    
    if (!$valid && $halt) {
        http_response_code(403);
        $is_json = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
                || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        
        if ($is_json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security validation (CSRF) failed. Please refresh the page and try again.']);
        } else {
            echo '<h3>403 Forbidden</h3><p>Security validation (CSRF) failed. Please go back, refresh the page, and try again.</p>';
        }
        exit;
    }
    return $valid;
}

/**
 * Enforce authentication. Redirects to signin if not logged in.
 */
function require_auth(string $redirect_to = '../auth/signin.php'): void {
    if (empty($_SESSION['user_id'])) {
        header("Location: $redirect_to");
        exit;
    }
}

/**
 * Enforce role-based access control (RBAC)
 */
function require_role(array $allowed_roles, string $forbidden_redirect = '../dashboard/dashboard.php'): void {
    require_auth();
    $current_role = $_SESSION['role'] ?? 'student';
    if (!in_array($current_role, $allowed_roles, true)) {
        header("Location: $forbidden_redirect?error=unauthorized");
        exit;
    }
}

/**
 * Escape HTML output
 */
function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>
