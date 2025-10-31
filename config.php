<?php
/**
 * Telegram Digital Store Bot Configuration
 * @version 1.0
 */

// Bot Configuration
define('BOT_TOKEN', '8330185902:AAFMHcrEgPPAaiYYnz-cwpntBdTCY6qsqao');
define('BOT_USERNAME', 'STBEBOT');
define('ADMIN_ID', 7732118455);
define('BASE_URL', 'https://bot1.be-eb.net/store/');
define('WEBHOOK_URL', BASE_URL . 'webhook.php');

// Database Configuration
define('DB_PATH', __DIR__ . '/database/store.db');

// Mini App Configuration
define('MINI_APP_URL', BASE_URL . 'app/index.html');

// Ads API Configuration
// CPAGrip API (للفيديوهات والمحتوى)
define('CPAGRIP_API_KEY', 'YOUR_CPAGRIP_API_KEY'); // يتم تعديلها من لوحة الإدارة
define('CPAGRIP_USER_ID', 'YOUR_CPAGRIP_USER_ID');
define('CPAGRIP_API_URL', 'https://api.cpagrip.com/');

// Shorte.st API (لاختصار الروابط)
define('SHORTEST_API_KEY', 'YOUR_SHORTEST_API_KEY'); // يتم تعديلها من لوحة الإدارة
define('SHORTEST_API_URL', 'https://api.shorte.st/');

// Points Configuration (يمكن تعديلها من لوحة الإدارة)
define('POINTS_PER_VIDEO_AD', 10);
define('POINTS_PER_LINK_AD', 5);
define('POINTS_PER_REFERRAL', 20);

// Upload Configuration
define('UPLOAD_DIR', __DIR__ . '/uploads/products/');
define('MAX_FILE_SIZE', 10485760); // 10MB

// Timezone
date_default_timezone_set('Asia/Riyadh');

// Error Reporting (للتطوير فقط)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

// Helper Functions
function getTelegramAPI() {
    return 'https://api.telegram.org/bot' . BOT_TOKEN . '/';
}

function isAdmin($user_id) {
    return $user_id == ADMIN_ID;
}

function logError($message) {
    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, __DIR__ . '/error.log');
}

function sendRequest($method, $data = []) {
    $url = getTelegramAPI() . $method;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        logError("Curl Error: " . $error);
        return false;
    }

    return json_decode($result, true);
}

return true;
