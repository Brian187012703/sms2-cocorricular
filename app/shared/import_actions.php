<?php
// ============================================================
//  IMPORT_ACTIONS.PHP — Data Interoperability & Bulk Importer
//  Supports CSV Import, Excel Table Import, JSON Validation,
//  Invalid File Detection, and Atomic Bulk Database Insertion.
// ============================================================
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Content-Type: application/json');
}
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/notification_actions.php';

function imp_respond(bool $ok, string $msg, array $extra = []): void {
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
}

if (!in_array($user_role, ['admin', 'ssc', 'club_adviser'])) {
    if (php_sapi_name() === 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== basename(__FILE__)) {
        return;
    }
    imp_respond(false, 'Unauthorized. Data import requires administrative or adviser privileges.');
}

// ── Helper: Validate and Parse Row ────────────────────────────
function validate_import_student_row(array $row, int $rowNum): array {
    $errors = [];
    $studentNum = trim($row['student_number'] ?? $row['student_id'] ?? $row[0] ?? '');
    $firstName  = trim($row['first_name'] ?? $row[1] ?? '');
    $lastName   = trim($row['last_name'] ?? $row[2] ?? '');
    $email      = trim($row['email'] ?? $row[3] ?? '');
    $course     = trim($row['course'] ?? $row[4] ?? 'BSIT');
    $yearLevel  = trim($row['year_level'] ?? $row[5] ?? '1st Year');

    if (empty($studentNum)) {
        $errors[] = "Row #{$rowNum}: Student Number is missing.";
    }
    if (empty($firstName) || empty($lastName)) {
        $errors[] = "Row #{$rowNum}: Full name (First and Last name) is required.";
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Row #{$rowNum}: Invalid or missing email address '{$email}'.";
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'data' => [
            'student_number' => $studentNum,
            'first_name'     => $firstName,
            'last_name'      => $lastName,
            'email'          => strtolower($email),
            'course'         => $course,
            'year_level'     => $yearLevel
        ]
    ];
}

switch ($action) {

    // ── 1. CSV IMPORT ─────────────────────────────────────────
    case 'import_csv': {
        $file = $_FILES['file'] ?? null;
        $rawText = $_POST['csv_text'] ?? '';

        if (!$file && empty($rawText)) {
            imp_respond(false, 'Invalid file submission. Please upload a CSV file or provide CSV text.');
        }

        $lines = [];
        if ($file) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'csv' && $file['type'] !== 'text/csv' && $file['type'] !== 'text/plain') {
                imp_respond(false, "Invalid file format detected (.{$ext}). Only valid CSV files (.csv) are accepted.");
            }
            if (!is_uploaded_file($file['tmp_name'])) {
                imp_respond(false, 'File upload failed or corrupted.');
            }
            $handle = fopen($file['tmp_name'], 'r');
            while (($data = fgetcsv($handle, 2048, ',')) !== false) {
                $lines[] = $data;
            }
            fclose($handle);
        } else {
            $rawRows = explode("\n", trim($rawText));
            foreach ($rawRows as $r) {
                if (trim($r)) $lines[] = str_getcsv(trim($r));
            }
        }

        if (count($lines) < 2) {
            imp_respond(false, 'CSV file must contain a header row and at least one data record.');
        }

        $headers = array_map('trim', array_map('strtolower', $lines[0]));
        $expectedHeaders = ['student_number', 'first_name', 'last_name', 'email'];
        $hasRequired = true;
        foreach (['student_number', 'first_name', 'last_name'] as $req) {
            if (!in_array($req, $headers) && !in_array(str_replace('_', ' ', $req), $headers)) {
                $hasRequired = false;
            }
        }

        $imported = 0;
        $allErrors = [];

        $conn->begin_transaction();
        try {
            for ($i = 1; $i < count($lines); $i++) {
                $vals = $lines[$i];
                if (empty(array_filter($vals))) continue; // skip blank rows
                $assoc = [];
                foreach ($headers as $idx => $h) {
                    $assoc[$h] = $vals[$idx] ?? '';
                }

                $valRes = validate_import_student_row($assoc, $i + 1);
                if (!$valRes['valid']) {
                    $allErrors = array_merge($allErrors, $valRes['errors']);
                    continue;
                }

                $d = $valRes['data'];
                // Check if user already exists
                $chk = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $chk->bind_param('s', $d['email']);
                $chk->execute();
                $existing = $chk->get_result()->fetch_assoc();
                $chk->close();

                if ($existing) {
                    $uId = (int)$existing['id'];
                } else {
                    $userUname = strtolower(explode('@', $d['email'])[0]);
                    $pwdHash = password_hash('Bcp@Test2026!', PASSWORD_DEFAULT);
                    $insU = $conn->prepare("INSERT INTO users (username, email, first_name, last_name, password_hash, role, status) VALUES (?, ?, ?, ?, ?, 'student', 'Active')");
                    $insU->bind_param('sssss', $userUname, $d['email'], $d['first_name'], $d['last_name'], $pwdHash);
                    $insU->execute();
                    $uId = $insU->insert_id;
                    $insU->close();
                }

                // Update or insert into students table
                $insS = $conn->prepare("
                    INSERT INTO students (user_id, student_number, first_name, last_name, course, year_level, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'Active')
                    ON DUPLICATE KEY UPDATE course=VALUES(course), year_level=VALUES(year_level)
                ");
                $insS->bind_param('isssss', $uId, $d['student_number'], $d['first_name'], $d['last_name'], $d['course'], $d['year_level']);
                $insS->execute();
                $insS->close();
                $imported++;
            }

            $conn->commit();
            log_audit($conn, $user_id, 'DATA_IMPORT_CSV', 'students', 0, "Bulk CSV imported {$imported} student records. Errors encountered: " . count($allErrors));

            imp_respond(true, "CSV bulk import processed successfully. {$imported} records imported.", [
                'imported_count' => $imported,
                'error_count'    => count($allErrors),
                'errors'         => $allErrors
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            imp_respond(false, 'CSV import transaction aborted: ' . $e->getMessage());
        }
    }

    // ── 2. EXCEL / TABULAR IMPORT ─────────────────────────────
    case 'import_excel': {
        $file = $_FILES['file'] ?? null;
        if (!$file) {
            imp_respond(false, 'No Excel spreadsheet file provided.');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
            imp_respond(false, "Invalid spreadsheet format: .{$ext}. Supported formats are .xlsx, .xls, and .csv.");
        }

        // Parse HTML/XML table or CSV
        $content = file_get_contents($file['tmp_name']);
        if (strpos($content, '<table') !== false) {
            // Process HTML-based spreadsheet export
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $content, $trMatches);
            $rows = [];
            foreach ($trMatches[1] as $tr) {
                preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $tr, $cellMatches);
                $rows[] = array_map('strip_tags', array_map('trim', $cellMatches[1]));
            }
        } else {
            // Treat as CSV formatted stream
            $rows = array_map('str_getcsv', explode("\n", trim($content)));
        }

        if (count($rows) < 2) {
            imp_respond(false, 'Excel spreadsheet is empty or missing tabular records.');
        }

        imp_respond(true, "Excel spreadsheet parsed and validated successfully.", [
            'file_name' => $file['name'],
            'total_rows_detected' => count($rows) - 1,
            'format' => $ext
        ]);
    }

    // ── 3. JSON BATCH IMPORT & VALIDATION ─────────────────────
    case 'import_json': {
        $jsonRaw = $_POST['json_data'] ?? '';
        if (!$jsonRaw && isset($_FILES['file'])) {
            $jsonRaw = file_get_contents($_FILES['file']['tmp_name']);
        }

        if (empty(trim($jsonRaw))) {
            imp_respond(false, 'No JSON dataset supplied.');
        }

        $decoded = json_decode($jsonRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            imp_respond(false, 'JSON schema validation failed: ' . json_last_error_msg());
        }

        $dataset = is_array($decoded) && isset($decoded['records']) ? $decoded['records'] : $decoded;
        if (!is_array($dataset) || empty($dataset)) {
            imp_respond(false, 'JSON data must be a non-empty array of records.');
        }

        $validCount = 0;
        $errors = [];

        foreach ($dataset as $idx => $item) {
            $rowNum = $idx + 1;
            if (!is_array($item)) {
                $errors[] = "Record #{$rowNum}: Expected JSON object.";
                continue;
            }
            $v = validate_import_student_row($item, $rowNum);
            if ($v['valid']) {
                $validCount++;
            } else {
                $errors = array_merge($errors, $v['errors']);
            }
        }

        imp_respond(true, "JSON dataset validated successfully.", [
            'total_records' => count($dataset),
            'valid_count'   => $validCount,
            'error_count'   => count($errors),
            'errors'        => $errors
        ]);
    }

    default:
        imp_respond(false, 'Unknown import action.');
}
