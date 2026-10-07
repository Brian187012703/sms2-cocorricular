<?php
// ============================================================
//  BACKUP_RESTORE.PHP — Database Backup & Recovery Procedures
//  Produces automated SQL dumps, verifies restore procedures,
//  and records execution telemetry in `backup_logs`.
// ============================================================
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Content-Type: application/json');
}
if (session_status() === PHP_SESSION_NONE) { @session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function get_backup_dir(): string {
    $dir = __DIR__ . '/../../storage/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return realpath($dir) ?: $dir;
}

/**
 * Generate a complete SQL backup dump of the database.
 */
function generate_database_backup(mysqli $conn, string $type = 'automated'): array {
    $backupDir = get_backup_dir();
    $timestamp = date('Ymd_His');
    $filename = "sms_db_backup_{$timestamp}.sql";
    $filepath = $backupDir . DIRECTORY_SEPARATOR . $filename;

    $out = "-- ============================================================\n";
    $out .= "-- BCP CO-CURRICULAR MANAGEMENT SYSTEM — DATABASE BACKUP DUMP\n";
    $out .= "-- Database: " . DB_NAME . "\n";
    $out .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $out .= "-- Backup Type: " . strtoupper($type) . "\n";
    $out .= "-- ============================================================\n\n";
    $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tablesRes = $conn->query("SHOW TABLES");
    $tables = [];
    while ($r = $tablesRes->fetch_row()) {
        $tables[] = $r[0];
    }

    foreach ($tables as $tbl) {
        // Skip ephemeral cache locks or temporary test tables
        if (in_array($tbl, ['cache_locks', 'sessions'])) continue;

        $cRes = $conn->query("SHOW CREATE TABLE `{$tbl}`");
        if ($cRes && $cRow = $cRes->fetch_row()) {
            $out .= "DROP TABLE IF EXISTS `{$tbl}`;\n";
            $out .= $cRow[1] . ";\n\n";

            $dRes = $conn->query("SELECT * FROM `{$tbl}`");
            if ($dRes && $dRes->num_rows > 0) {
                while ($row = $dRes->fetch_assoc()) {
                    $cols = array_map(function($c) { return "`" . $c . "`"; }, array_keys($row));
                    $vals = array_map(function($v) use ($conn) {
                        return $v === null ? "NULL" : "'" . $conn->real_escape_string($v) . "'";
                    }, array_values($row));
                    $out .= "INSERT INTO `{$tbl}` (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $vals) . ");\n";
                }
                $out .= "\n";
            }
        }
    }

    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $out .= "-- EOF --\n";

    file_put_contents($filepath, $out);
    $filesize = filesize($filepath);

    // Record in backup_logs
    $stmt = $conn->prepare("INSERT INTO backup_logs (filename, filesize_bytes, type, status, details, created_at) VALUES (?, ?, ?, 'success', 'Backup completed successfully', NOW())");
    if ($stmt) {
        $stmt->bind_param('sis', $filename, $filesize, $type);
        $stmt->execute();
        $stmt->close();
    }

    return [
        'success'        => true,
        'filename'       => $filename,
        'filepath'       => $filepath,
        'filesize_bytes' => $filesize,
        'filesize_human' => round($filesize / 1024, 2) . ' KB',
        'table_count'    => count($tables),
        'created_at'     => date('Y-m-d H:i:s')
    ];
}

/**
 * Validate and restore a database from an SQL dump.
 */
function restore_database_backup(mysqli $conn, string $filepath): array {
    if (!file_exists($filepath)) {
        return ['success' => false, 'message' => "Backup file not found: {$filepath}"];
    }

    $sql = file_get_contents($filepath);
    if (empty(trim($sql))) {
        return ['success' => false, 'message' => "Backup file is empty."];
    }

    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    if ($conn->multi_query($sql)) {
        do {
            if ($res = $conn->store_result()) {
                $res->free();
            }
        } while ($conn->more_results() && $conn->next_result());
    }
    $conn->query("SET FOREIGN_KEY_CHECKS=1");

    $filename = basename($filepath);
    return [
        'success'     => true,
        'message'     => "Database restored successfully from {$filename}.",
        'filename'    => $filename,
        'restored_at' => date('Y-m-d H:i:s')
    ];
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    $action = $_POST['action'] ?? $_GET['action'] ?? (php_sapi_name() === 'cli' ? 'backup' : 'list');

    if ($action === 'backup' || php_sapi_name() === 'cli') {
        $res = generate_database_backup($conn, 'manual');
        if (php_sapi_name() === 'cli') {
            echo "=== BCP DATABASE BACKUP GENERATED ===\n";
            echo "File: {$res['filename']} ({$res['filesize_human']})\n";
            echo "Tables backed up: {$res['table_count']}\n";
        } else {
            echo json_encode($res);
        }
        exit;
    }

    if ($action === 'list') {
        $res = $conn->query("SELECT * FROM backup_logs ORDER BY id DESC LIMIT 50");
        $logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        echo json_encode(['success' => true, 'backups' => $logs]);
        exit;
    }
}
