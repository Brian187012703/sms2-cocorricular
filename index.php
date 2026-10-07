<?php
// ============================================================
//  INDEX.PHP — Root Entry Point & Health-Check Resilient Router
// ============================================================

// 1. Instant HTTP 200 for health probes and monitors (HostForge, curl, uptime bots)
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$isProbe = (
    $method === 'HEAD' ||
    isset($_GET['health']) ||
    stripos($ua, 'health') !== false ||
    stripos($ua, 'probe') !== false ||
    stripos($ua, 'curl') !== false ||
    stripos($ua, 'wget') !== false ||
    stripos($ua, 'hostforge') !== false ||
    stripos($ua, 'uptime') !== false ||
    stripos($ua, 'monitor') !== false ||
    stripos($ua, 'bot') !== false
);

if ($isProbe) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'healthy',
        'service' => 'sms-cocurricular',
        'timestamp' => date('c')
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// 2. If explicitly configured for Laravel frontend
if (getenv('FRONTEND_DRIVER') === 'laravel' && file_exists(__DIR__ . '/public/index.php')) {
    require __DIR__ . '/public/index.php';
    exit;
}

// 3. Serve HTTP 200 OK with immediate browser navigation to signin page
http_response_code(200);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="0;url=/app/auth/signin.php">
    <title>BCP Student Management System</title>
    <script>window.location.replace("/app/auth/signin.php");</script>
</head>
<body style="margin:0;padding:0;background:#0f172a;color:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;">
    <div style="text-align:center;padding:2rem;">
        <h2 style="margin:0 0 0.5rem 0;color:#38bdf8;font-size:1.5rem;">BCP SMS Co-Curricular</h2>
        <p style="margin:0;color:#94a3b8;font-size:0.95rem;">Loading application...</p>
        <p style="margin-top:1.25rem;"><a href="/app/auth/signin.php" style="color:#38bdf8;text-decoration:none;font-size:0.875rem;">Click here to sign in &rarr;</a></p>
    </div>
</body>
</html>
