<?php
/**
 * Telegram Bot Webhook Handler
 * This file receives updates from Telegram API
 */

require_once 'config.php';
require_once 'database/Database.php';
require_once 'bot/Bot.php';

// Get incoming update
$content = file_get_contents('php://input');
$update = json_decode($content, true);

// Log update for debugging (يمكن تعطيله في الإنتاج)
if (defined('DEBUG') && DEBUG) {
    file_put_contents('updates.log', date('[Y-m-d H:i:s] ') . $content . PHP_EOL, FILE_APPEND);
}

// Process update
if ($update) {
    try {
        $bot = new Bot($update);
        $bot->process();
    } catch (Exception $e) {
        logError("Webhook Error: " . $e->getMessage());
    }
}

http_response_code(200);
