<?php
// ============================================================
//  ERROR_HANDLER.PHP — System Error Recovery & Exception Logger
//  Recovers gracefully from failures and logs to `error_logs`.
// ============================================================

require_once __DIR__ . '/db.php';

/**
 * Log an error/exception and execute safe rollback if a database transaction is open.
 */
function bcp_log_and_recover_error(Throwable $e, ?mysqli $conn = null, string $context = ''): array {
    global $conn;
    $refId = 'ERR-' . strtoupper(substr(hash('crc32', microtime() . $e->getMessage()), 0, 8));

    // 1. Transaction Rollback Safety
    if ($conn) {
        try {
            @$conn->rollback();
        } catch (Throwable $ignore) {}
    }

    $errType    = get_class($e);
    $message    = $e->getMessage() . ($context ? " [Context: {$context}]" : '');
    $file       = $e->getFile();
    $line       = $e->getLine();
    $trace      = $e->getTraceAsString();
    $userId     = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $requestUri = $_SERVER['REQUEST_URI'] ?? 'CLI';

    // 2. Persist to error_logs table
    if ($conn) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO error_logs (error_type, message, file, line, trace, user_id, request_uri, recovered, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            if ($stmt) {
                $stmt->bind_param('sssisis', $errType, $message, $file, $line, $trace, $userId, $requestUri);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $logFail) {
            error_log("[BCP-FATAL-RECOVERY] {$refId}: {$errType}: {$message}");
        }
    }

    return [
        'success'         => false,
        'error_recovered' => true,
        'error_reference' => $refId,
        'message'         => 'An unexpected system error occurred. The transaction was safely rolled back and recorded for administrative review.'
    ];
}

/**
 * Execute a callable safely inside a database transaction with automated error recovery.
 */
function bcp_execute_atomic_transaction(mysqli $conn, callable $callback, string $context = 'Atomic_Op') {
    $conn->begin_transaction();
    try {
        $result = $callback($conn);
        $conn->commit();
        return $result;
    } catch (Throwable $e) {
        $recovery = bcp_log_and_recover_error($e, $conn, $context);
        return $recovery;
    }
}
