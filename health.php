<?php
// ============================================================
//  HEALTH.PHP — HostForge Health Check Endpoint
// ============================================================

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'status' => 'healthy',
    'timestamp' => date('c'),
    'service' => 'sms-cocurricular',
    'uptime' => 'ok'
], JSON_UNESCAPED_SLASHES);
exit;
