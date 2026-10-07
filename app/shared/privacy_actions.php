<?php
// ============================================================
//  PRIVACY_ACTIONS.PHP — Personal Data Protection & Consent Store
//  Compliance with Republic Act 10173 (Data Privacy Act of 2012)
//  Covers Consent Management, Right to Erasure / Deletion Requests
// ============================================================
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Content-Type: application/json');
}
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/notification_actions.php';

function priv_respond(bool $ok, string $msg, array $extra = []): void {
    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'student';

if (empty($action)) {
    if (php_sapi_name() === 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== basename(__FILE__)) {
        return;
    }
    priv_respond(false, 'Unknown privacy action.');
}

switch ($action) {

    // ── Get Data Privacy Notice / Policy ──────────────────────
    case 'get_policy': {
        priv_respond(true, 'OK', [
            'compliance' => 'Republic Act No. 10173 (Philippine Data Privacy Act of 2012)',
            'institution' => 'Bestlink College of the Philippines (BCP)',
            'portal' => 'Co-Curricular & Student Affairs Management Portal',
            'version' => '2026.1',
            'effective_date' => '2026-01-01',
            'principles' => [
                'Transparency' => 'Personal information collected is used solely for accredited co-curricular tracking, membership rosters, campus elections, and event attendance.',
                'Legitimate Purpose' => 'Data is gathered strictly to facilitate verified student activities and institutional leadership documentation.',
                'Proportionality' => 'Only necessary academic and identity identifiers (student ID, program, email) are processed with role-scoped access control.'
            ],
            'rights' => [
                'Right to be Informed',
                'Right to Access',
                'Right to Object',
                'Right to Erasure or Blocking (Data Deletion Request)',
                'Right to Damages',
                'Right to Data Portability'
            ]
        ]);
    }

    // ── Record User Consent ───────────────────────────────────
    case 'record_consent': {
        if ($user_id <= 0) priv_respond(false, 'Authentication required to register privacy consent.');

        $consentType = trim($_POST['consent_type'] ?? 'dpa_general_consent_2026');
        $version = trim($_POST['version'] ?? '1.0');
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser';

        $stmt = $conn->prepare("
            INSERT INTO user_consents (user_id, consent_type, version, ip_address, user_agent, agreed_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('issss', $user_id, $consentType, $version, $clientIp, $userAgent);
        $ok = $stmt->execute();
        $stmt->close();

        log_audit($conn, $user_id, 'DPA_CONSENT_GRANTED', 'user_consents', $user_id, "User granted consent for {$consentType} v{$version}.");
        priv_respond(true, 'Data privacy consent recorded successfully.', ['user_id' => $user_id, 'agreed_at' => date('Y-m-d H:i:s')]);
    }

    // ── Check Consent Status ──────────────────────────────────
    case 'check_consent': {
        if ($user_id <= 0) priv_respond(false, 'Not authenticated.');

        $stmt = $conn->prepare("SELECT * FROM user_consents WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $consent = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        priv_respond(true, 'OK', [
            'has_consented' => !empty($consent),
            'consent_record' => $consent
        ]);
    }

    // ── Submit Right to Erasure / Personal Data Deletion Request ──
    case 'request_deletion': {
        if ($user_id <= 0) priv_respond(false, 'Authentication required to submit data deletion request.');

        $reason = trim($_POST['reason'] ?? 'User invoked Right to Erasure under Section 16 of RA 10173.');
        if (strlen($reason) < 10) {
            priv_respond(false, 'Please provide an adequate justification or statement for your deletion request.');
        }

        // Check if pending request exists
        $chk = $conn->query("SELECT id FROM data_deletion_requests WHERE user_id = {$user_id} AND status = 'pending' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            priv_respond(false, 'You already have a pending data deletion request under review by the Data Protection Officer.');
        }

        $stmt = $conn->prepare("
            INSERT INTO data_deletion_requests (user_id, reason, status, requested_at)
            VALUES (?, ?, 'pending', NOW())
        ");
        $stmt->bind_param('is', $user_id, $reason);
        $stmt->execute();
        $reqId = $stmt->insert_id;
        $stmt->close();

        log_audit($conn, $user_id, 'DPA_DELETION_REQUESTED', 'data_deletion_requests', $reqId, "Submitted personal data erasure request #{$reqId}.");

        priv_respond(true, 'Your data deletion request has been submitted successfully to the Data Protection Officer.', [
            'request_id'   => $reqId,
            'status'       => 'pending',
            'requested_at' => date('Y-m-d H:i:s')
        ]);
    }

    // ── List Deletion Requests (Admin / DPO View) ─────────────
    case 'list_deletion_requests': {
        if (!in_array($user_role, ['admin', 'ssc'])) {
            priv_respond(false, 'Access denied. Only Institutional Administrators can review data deletion requests.');
        }

        $res = $conn->query("
            SELECT ddr.*, u.username, u.email, u.first_name, u.last_name, u.role
            FROM data_deletion_requests ddr
            JOIN users u ON u.id = ddr.user_id
            ORDER BY ddr.id DESC
        ");
        $requests = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        priv_respond(true, 'OK', ['requests' => $requests, 'total' => count($requests)]);
    }

    // ── Process Deletion Request (Admin / DPO) ────────────────
    case 'process_deletion_request': {
        if ($user_role !== 'admin') priv_respond(false, 'Only System Administrator can process deletion requests.');

        $reqId = (int)($_POST['request_id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['approved', 'rejected', 'completed']) ? $_POST['status'] : 'completed';
        $remarks = trim($_POST['remarks'] ?? 'Processed per DPA standard guidelines.');

        $req = $conn->query("SELECT * FROM data_deletion_requests WHERE id = {$reqId} LIMIT 1")->fetch_assoc();
        if (!$req) priv_respond(false, 'Request not found.');

        $targetUid = (int)$req['user_id'];

        if ($status === 'completed' || $status === 'approved') {
            // Anonymize personal identifying information (PII) to satisfy Right to Erasure
            // while preserving relational integrity of campus records
            $anonName = 'DPA_ANONYMIZED_USER_' . $targetUid;
            $anonEmail = "anonymized_{$targetUid}@dpa.bcp.local";
            $conn->query("
                UPDATE users 
                SET first_name = 'Anonymized', 
                    last_name = 'Student', 
                    email = '{$anonEmail}', 
                    status = 'Inactive'
                WHERE id = {$targetUid}
            ");
            $conn->query("UPDATE students SET student_number = 'DELETED-{$targetUid}' WHERE user_id = {$targetUid}");
        }

        $stmt = $conn->prepare("
            UPDATE data_deletion_requests 
            SET status = ?, processed_at = NOW(), processed_by = ?, admin_remarks = ?
            WHERE id = ?
        ");
        $stmt->bind_param('sisi', $status, $user_id, $remarks, $reqId);
        $stmt->execute();
        $stmt->close();

        log_audit($conn, $user_id, 'DPA_DELETION_PROCESSED', 'data_deletion_requests', $reqId, "Data deletion request #{$reqId} status updated to {$status}.");

        priv_respond(true, "Data deletion request #{$reqId} updated to {$status}.", [
            'request_id' => $reqId,
            'status' => $status
        ]);
    }

    default:
        priv_respond(false, 'Unknown privacy action.');
}
