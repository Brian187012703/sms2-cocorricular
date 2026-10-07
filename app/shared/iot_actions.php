<?php
// ============================================================
//  IOT_ACTIONS.PHP — Connected Device Integration & Telemetry
//  Supports RFID Scanners, Barcode Turnstiles & Kiosk Terminals
// ============================================================
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Content-Type: application/json');
}
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function iot_respond(bool $ok, string $msg, array $extra = []): void {
    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (empty($action)) {
    if (php_sapi_name() === 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== basename(__FILE__)) {
        return;
    }
    iot_respond(false, 'Unknown IoT action.');
}

// 1. Device Token Authentication Helper
function authenticate_iot_device(mysqli $conn): ?array {
    $token = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? $_POST['device_token'] ?? $_GET['device_token'] ?? '';
    if (empty($token) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $token = $m[1];
        }
    }
    if (empty($token)) {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT * FROM iot_devices WHERE (api_token = ? OR api_token = ?) AND status != 'maintenance' LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('ss', $token, $tokenHash);
    $stmt->execute();
    $device = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $device;
}

switch ($action) {

    // ── Ping / Heartbeat ──────────────────────────────────────
    case 'ping': {
        $device = authenticate_iot_device($conn);
        if (!$device) {
            iot_respond(false, 'Unauthorized IoT device.');
        }

        $now = date('Y-m-d H:i:s');
        $conn->query("UPDATE iot_devices SET last_ping_at = '{$now}', status = 'online' WHERE id = " . (int)$device['id']);
        iot_respond(true, 'Device online and synchronized.', [
            'device_uid' => $device['device_uid'],
            'server_time' => $now,
            'status' => 'online'
        ]);
    }

    // ── Transmit Real-Time Telemetry / Scan Event ────────────
    case 'transmit_telemetry':
    case 'log_event': {
        $start = microtime(true);
        $device = authenticate_iot_device($conn);
        if (!$device) {
            iot_respond(false, 'Unauthorized IoT device.');
        }

        $eventType = trim($_POST['event_type'] ?? 'badge_scan');
        $payloadRaw = $_POST['payload'] ?? $_POST['data'] ?? '';
        $payloadData = is_string($payloadRaw) ? json_decode($payloadRaw, true) : $payloadRaw;
        if (!is_array($payloadData)) {
            $payloadData = [
                'raw' => $payloadRaw,
                'badge_uid' => $_POST['badge_uid'] ?? $_POST['qr_data'] ?? '',
                'event_id'  => (int)($_POST['event_id'] ?? 0),
                'location'  => $device['location'] ?? 'Campus Gateway'
            ];
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $durationMs = (int)round((microtime(true) - $start) * 1000);
        $payloadJson = json_encode($payloadData);

        // Record in IoT Device Logs
        $stmt = $conn->prepare("
            INSERT INTO iot_device_logs (device_id, device_uid, event_type, payload, status, response_ms, ip_address, created_at)
            VALUES (?, ?, ?, ?, 'success', ?, ?, NOW())
        ");
        $devId = (int)$device['id'];
        $stmt->bind_param('isssis', $devId, $device['device_uid'], $eventType, $payloadJson, $durationMs, $clientIp);
        $stmt->execute();
        $logId = $stmt->insert_id;
        $stmt->close();

        // Update device last ping
        $conn->query("UPDATE iot_devices SET last_ping_at = NOW(), status = 'online' WHERE id = " . (int)$device['id']);

        // If the IoT payload includes student attendance badge scan, log attendance automatically
        $badgeCode = $payloadData['badge_uid'] ?? '';
        $targetEventId = (int)($payloadData['event_id'] ?? 0);
        $attendanceLogged = false;

        if (!empty($badgeCode) && $targetEventId > 0) {
            require_once __DIR__ . '/attendance_actions.php';
            // Resolve student
            $u = resolveUserFromQr($conn, $badgeCode);
            if ($u) {
                $uid = (int)$u['id'];
                // Check if already checked in
                $chk = $conn->query("SELECT id FROM attendance_logs WHERE event_id = {$targetEventId} AND user_id = {$uid} LIMIT 1");
                if ($chk && $chk->num_rows === 0) {
                    $ins = $conn->prepare("
                        INSERT INTO attendance_logs (event_id, user_id, check_in, method, logged_by, status)
                        VALUES (?, ?, NOW(), 'IoT_Hardware', ?, 'Present')
                    ");
                    $devSysUser = 1; // System Admin
                    $ins->bind_param('iii', $targetEventId, $uid, $devSysUser);
                    $ins->execute();
                    $ins->close();
                    $attendanceLogged = true;
                }
            }
        }

        iot_respond(true, 'Telemetry received and logged successfully in real time.', [
            'log_id' => $logId,
            'device_uid' => $device['device_uid'],
            'response_ms' => $durationMs,
            'attendance_processed' => $attendanceLogged,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    // ── List IoT Logs (For Evaluation Evidence & Admin View) ──
    case 'list_logs': {
        if (!in_array($_SESSION['role'] ?? '', ['admin', 'ssc'])) {
            // Allow token authenticated requests too
            $device = authenticate_iot_device($conn);
            if (!$device) {
                iot_respond(false, 'Unauthorized.');
            }
        }

        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $res = $conn->query("
            SELECT l.*, d.name AS device_name, d.type AS device_type, d.location
            FROM iot_device_logs l
            LEFT JOIN iot_devices d ON d.id = l.device_id
            ORDER BY l.id DESC
            LIMIT {$limit}
        ");
        $logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        foreach ($logs as &$log) {
            $log['payload'] = json_decode($log['payload'] ?? '{}', true);
        }

        iot_respond(true, 'OK', ['logs' => $logs, 'total' => count($logs)]);
    }

    // ── List Devices ──────────────────────────────────────────
    case 'list_devices': {
        $res = $conn->query("SELECT id, device_uid, name, type, location, status, last_ping_at, created_at FROM iot_devices ORDER BY id ASC");
        $devices = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        iot_respond(true, 'OK', ['devices' => $devices]);
    }

    default:
        iot_respond(false, 'Unknown IoT action.');
}
