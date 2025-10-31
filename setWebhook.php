<?php
/**
 * Set Telegram Webhook
 * Run this file once to setup the webhook
 */

require_once 'config.php';

// Set webhook
$url = getTelegramAPI() . 'setWebhook';
$data = [
    'url' => WEBHOOK_URL,
    'allowed_updates' => json_encode(['message', 'callback_query'])
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$result = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

echo "Setting webhook...\n";
echo "Webhook URL: " . WEBHOOK_URL . "\n\n";

if ($error) {
    echo "❌ Error: $error\n";
} else {
    $response = json_decode($result, true);
    if ($response['ok']) {
        echo "✅ Webhook set successfully!\n";
        echo "Description: " . ($response['description'] ?? 'N/A') . "\n";
    } else {
        echo "❌ Failed to set webhook\n";
        echo "Error: " . ($response['description'] ?? 'Unknown error') . "\n";
    }
}

// Get webhook info
echo "\n--- Webhook Info ---\n";
$info_url = getTelegramAPI() . 'getWebhookInfo';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $info_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$result = curl_exec($ch);
curl_close($ch);

$info = json_decode($result, true);
if ($info['ok']) {
    echo "URL: " . ($info['result']['url'] ?? 'Not set') . "\n";
    echo "Pending updates: " . ($info['result']['pending_update_count'] ?? 0) . "\n";
    if (isset($info['result']['last_error_message'])) {
        echo "Last error: " . $info['result']['last_error_message'] . "\n";
        echo "Last error date: " . date('Y-m-d H:i:s', $info['result']['last_error_date']) . "\n";
    }
} else {
    echo "Failed to get webhook info\n";
}
