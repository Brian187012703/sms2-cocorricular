<?php
// ============================================================
//  AI_CONFIG.PHP — Centralized AI Configuration
//  Google Gemini 2.0 Flash / 1.5 Flash (Free Tier)
//  Get your free key at: https://aistudio.google.com/apikey
// ============================================================

if (!defined('GEMINI_DEFAULT_KEY')) {
    define('GEMINI_DEFAULT_KEY', '');
}
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', 'gemini-2.0-flash');
}
if (!defined('AI_MAX_TOKENS')) {
    define('AI_MAX_TOKENS', 2048);
}
if (!defined('AI_TEMPERATURE')) {
    define('AI_TEMPERATURE', 0.7);
}

/**
 * Retrieve the active Gemini API key from session, database, or default config
 */
function get_gemini_api_key(?mysqli $conn = null): string {
    if (!empty($_SESSION['gemini_api_key'])) {
        return trim($_SESSION['gemini_api_key']);
    }

    if ($conn) {
        $check = $conn->query("SHOW TABLES LIKE 'system_settings'");
        if ($check && $check->num_rows > 0) {
            $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
            if ($res && $row = $res->fetch_assoc()) {
                $k = trim($row['setting_value'] ?? '');
                if (!empty($k)) {
                    $_SESSION['gemini_api_key'] = $k;
                    return $k;
                }
            }
        }
    }

    return defined('GEMINI_DEFAULT_KEY') ? GEMINI_DEFAULT_KEY : '';
}

/**
 * Save the Gemini API key to DB and session
 */
function save_gemini_api_key(string $key, mysqli $conn): bool {
    $cleanKey = trim($key);
    $_SESSION['gemini_api_key'] = $cleanKey;

    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('gemini_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    if ($stmt) {
        $stmt->bind_param('ss', $cleanKey, $cleanKey);
        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }
    return false;
}
