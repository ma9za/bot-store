<?php
/**
 * API Endpoints for Mini App
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/ads.php';

$db = Database::getInstance();
$adsAPI = new AdsAPI();

// Handle JSON input
$json_input = file_get_contents('php://input');
$json_data = json_decode($json_input, true);

// Merge JSON data with POST for easier handling
if ($json_data && is_array($json_data)) {
    $_POST = array_merge($_POST, $json_data);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Verify Telegram Web App data (للأمان)
function verifyTelegramWebAppData($init_data) {
    // Implementation of Telegram Web App data verification
    // https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app

    parse_str($init_data, $params);
    $check_hash = $params['hash'] ?? '';
    unset($params['hash']);

    ksort($params);
    $data_check_string = http_build_query($params, '', "\n");

    $secret_key = hash_hmac('sha256', BOT_TOKEN, 'WebAppData', true);
    $hash = bin2hex(hash_hmac('sha256', $data_check_string, $secret_key, true));

    return hash_equals($hash, $check_hash);
}

// Get user from Telegram Web App data
function getUserFromWebApp() {
    $init_data = $_GET['_auth'] ?? $_POST['_auth'] ?? '';

    if (empty($init_data)) {
        return null;
    }

    // In development, you might skip verification
    // if (!verifyTelegramWebAppData($init_data)) {
    //     return null;
    // }

    parse_str($init_data, $params);
    $user_data = json_decode($params['user'] ?? '{}', true);

    return $user_data['id'] ?? null;
}

try {
    switch ($action) {
        case 'get_products':
            $products = $db->getProducts(true);

            // Add image URLs
            foreach ($products as &$product) {
                if ($product['image_path']) {
                    $product['image_url'] = BASE_URL . 'uploads/products/' . basename($product['image_path']);
                }

                // Calculate final price (with offers)
                $product['final_price'] = $product['price'];
                if ($product['is_offer'] && $product['offer_price']) {
                    if (!$product['offer_ends_at'] || strtotime($product['offer_ends_at']) > time()) {
                        $product['final_price'] = $product['offer_price'];
                        $product['discount_percentage'] = round((($product['price'] - $product['offer_price']) / $product['price']) * 100);
                    }
                }
            }

            echo json_encode(['success' => true, 'products' => $products]);
            break;

        case 'get_product':
            $product_id = $_GET['id'] ?? 0;
            $product = $db->getProduct($product_id);

            if (!$product) {
                echo json_encode(['success' => false, 'error' => 'Product not found']);
                break;
            }

            if ($product['image_path']) {
                $product['image_url'] = BASE_URL . 'uploads/products/' . basename($product['image_path']);
            }

            $product['final_price'] = $product['price'];
            if ($product['is_offer'] && $product['offer_price']) {
                if (!$product['offer_ends_at'] || strtotime($product['offer_ends_at']) > time()) {
                    $product['final_price'] = $product['offer_price'];
                }
            }

            echo json_encode(['success' => true, 'product' => $product]);
            break;

        case 'get_user':
            $user_id = $_GET['user_id'] ?? getUserFromWebApp();

            if (!$user_id) {
                echo json_encode(['success' => false, 'error' => 'User ID required']);
                break;
            }

            $user = $db->getUser($user_id);

            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                break;
            }

            // Get referral stats
            $referral_stats = $db->getReferralStats($user_id);
            $user['referral_stats'] = $referral_stats;

            echo json_encode(['success' => true, 'user' => $user]);
            break;

        case 'get_ads':
            $ads = $db->getActiveAds();
            echo json_encode(['success' => true, 'ads' => $ads]);
            break;

        case 'purchase':
            $user_id = $_POST['user_id'] ?? getUserFromWebApp();
            $product_id = $_POST['product_id'] ?? 0;

            if (!$user_id || !$product_id) {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                break;
            }

            $product = $db->getProduct($product_id);
            if (!$product) {
                echo json_encode(['success' => false, 'error' => 'Product not found']);
                break;
            }

            // Handle purchase based on content type
            if ($product['content_type'] === 'general' && $product['file_id']) {
                // General content - send file directly
                $price = $product['price'];
                if ($product['is_offer'] && $product['offer_price']) {
                    if (!$product['offer_ends_at'] || strtotime($product['offer_ends_at']) > time()) {
                        $price = $product['offer_price'];
                    }
                }

                // Deduct points
                if (!$db->deductPoints($user_id, $price, 'purchase', 'شراء: ' . $product['name'], $product_id)) {
                    echo json_encode(['success' => false, 'error' => 'Insufficient points']);
                    break;
                }

                // Create order record
                $conn = $db->getConnection();
                $stmt = $conn->prepare("INSERT INTO orders (user_id, product_id, product_name, points_spent, content_delivered)
                                       VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $product_id, $product['name'], $price, 'file:' . $product['file_id']]);
                $order_id = $conn->lastInsertId();

                // Update product stats
                $stmt = $conn->prepare("UPDATE products SET sales_count = sales_count + 1 WHERE id = ?");
                $stmt->execute([$product_id]);

                // Send file via bot
                require_once __DIR__ . '/../bot/Bot.php';
                $bot = new Bot([]);
                $bot->sendFile($user_id, $product['file_id'], $product['file_type'], "🎁 منتجك: {$product['name']}\n\nشكراً لشرائك!");

                // Notify admin
                $user = $db->getUser($user_id);
                $bot->notifyAdminPurchase($user, $product, $order_id);

                echo json_encode(['success' => true, 'order_id' => $order_id, 'type' => 'file']);
            } else {
                // Unique content - use existing flow
                $result = $db->createOrder($user_id, $product_id);

                if ($result['success']) {
                    // Notify admin
                    require_once __DIR__ . '/../bot/Bot.php';
                    $bot = new Bot([]);
                    $user = $db->getUser($user_id);
                    $bot->notifyAdminPurchase($user, $product, $result['order_id']);
                }

                echo json_encode($result);
            }
            break;

        case 'get_transactions':
            $user_id = $_GET['user_id'] ?? getUserFromWebApp();
            $limit = $_GET['limit'] ?? 50;

            if (!$user_id) {
                echo json_encode(['success' => false, 'error' => 'User ID required']);
                break;
            }

            $conn = $db->getConnection();
            $stmt = $conn->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$user_id, $limit]);
            $transactions = $stmt->fetchAll();

            echo json_encode(['success' => true, 'transactions' => $transactions]);
            break;

        case 'get_orders':
            $user_id = $_GET['user_id'] ?? getUserFromWebApp();
            $limit = $_GET['limit'] ?? 50;

            if (!$user_id) {
                echo json_encode(['success' => false, 'error' => 'User ID required']);
                break;
            }

            $conn = $db->getConnection();
            $stmt = $conn->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$user_id, $limit]);
            $orders = $stmt->fetchAll();

            echo json_encode(['success' => true, 'orders' => $orders]);
            break;

        case 'record_ad_view':
            $user_id = $_POST['user_id'] ?? getUserFromWebApp();
            $ad_id = $_POST['ad_id'] ?? 0;

            if (!$user_id || !$ad_id) {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                break;
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $result = $db->recordAdView($user_id, $ad_id, $ip);

            echo json_encode(['success' => $result]);
            break;

        case 'complete_ad_view':
            $user_id = $_POST['user_id'] ?? getUserFromWebApp();
            $ad_id = $_POST['ad_id'] ?? 0;

            if (!$user_id || !$ad_id) {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                break;
            }

            $result = $db->completeAdView($user_id, $ad_id);

            if ($result) {
                $ad = $db->getAd($ad_id);
                echo json_encode([
                    'success' => true,
                    'points_earned' => $ad['points_reward'],
                    'new_balance' => $db->getUser($user_id)['points']
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to complete ad view']);
            }
            break;

        case 'get_settings':
            $settings = [
                'points_per_video_ad' => $db->getSetting('points_per_video_ad'),
                'points_per_link_ad' => $db->getSetting('points_per_link_ad'),
                'points_per_referral' => $db->getSetting('points_per_referral'),
                'welcome_message' => $db->getSetting('welcome_message'),
                'store_active' => $db->getSetting('store_active'),
                'cpagrip_api_key' => $db->getSetting('cpagrip_api_key'),
                'cpagrip_user_id' => $db->getSetting('cpagrip_user_id'),
                'shortest_api_key' => $db->getSetting('shortest_api_key')
            ];

            echo json_encode(['success' => true, 'settings' => $settings]);
            break;

        case 'get_stats':
            $stats = $db->getStats();
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;

        // Admin endpoints
        case 'admin_add_product':
            $admin_id = $_POST['admin_id'] ?? 0;

            if (!isAdmin($admin_id)) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            $name = $_POST['name'] ?? '';
            $description = $_POST['description'] ?? '';
            $price = $_POST['price'] ?? 0;
            $stock_quantity = $_POST['stock_quantity'] ?? -1;
            $max_per_user = $_POST['max_per_user'] ?? 1;
            $image_file_id = $_POST['image_file_id'] ?? null;
            $content_type = $_POST['content_type'] ?? 'unique';
            $file_id = $_POST['file_id'] ?? null;
            $file_type = $_POST['file_type'] ?? null;

            if (empty($name) || $price <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid data']);
                break;
            }

            $conn = $db->getConnection();
            $stmt = $conn->prepare("INSERT INTO products (name, description, price, stock_quantity, max_per_user, image_file_id, content_type, file_id, file_type)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $success = $stmt->execute([$name, $description, $price, $stock_quantity, $max_per_user, $image_file_id, $content_type, $file_id, $file_type]);

            if ($success) {
                $product_id = $conn->lastInsertId();
                echo json_encode(['success' => true, 'product_id' => $product_id]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to add product']);
            }
            break;

        case 'admin_add_product_content':
            $admin_id = $_POST['admin_id'] ?? 0;

            if (!isAdmin($admin_id)) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            $product_id = $_POST['product_id'] ?? 0;
            $content = $_POST['content'] ?? '';

            if (!$product_id || empty($content)) {
                echo json_encode(['success' => false, 'error' => 'Invalid data']);
                break;
            }

            // Support bulk content (multiple lines)
            $contents = explode("\n", trim($content));
            $conn = $db->getConnection();
            $stmt = $conn->prepare("INSERT INTO product_content (product_id, content) VALUES (?, ?)");

            $added = 0;
            foreach ($contents as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $stmt->execute([$product_id, $line]);
                    $added++;
                }
            }

            echo json_encode(['success' => true, 'added' => $added]);
            break;

        case 'admin_add_ad':
            $admin_id = $_POST['admin_id'] ?? 0;

            if (!isAdmin($admin_id)) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            $type = $_POST['type'] ?? 'link';
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $url = $_POST['url'] ?? '';
            $points_reward = $_POST['points_reward'] ?? 0;

            if (empty($title) || empty($url) || $points_reward <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid data']);
                break;
            }

            $conn = $db->getConnection();
            $stmt = $conn->prepare("INSERT INTO ads (type, title, description, url, points_reward) VALUES (?, ?, ?, ?, ?)");
            $success = $stmt->execute([$type, $title, $description, $url, $points_reward]);

            if ($success) {
                echo json_encode(['success' => true, 'ad_id' => $conn->lastInsertId()]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to add ad']);
            }
            break;

        case 'update_settings':
            $admin_id = $_POST['admin_id'] ?? 0;

            if (!isAdmin($admin_id)) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            $conn = $db->getConnection();
            $updated = 0;

            // Update points settings
            if (isset($_POST['points_per_video_ad'])) {
                $db->updateSetting('points_per_video_ad', $_POST['points_per_video_ad']);
                $updated++;
            }
            if (isset($_POST['points_per_link_ad'])) {
                $db->updateSetting('points_per_link_ad', $_POST['points_per_link_ad']);
                $updated++;
            }
            if (isset($_POST['points_per_referral'])) {
                $db->updateSetting('points_per_referral', $_POST['points_per_referral']);
                $updated++;
            }

            // Update API settings
            if (isset($_POST['cpagrip_api_key'])) {
                $db->updateSetting('cpagrip_api_key', $_POST['cpagrip_api_key']);
                $updated++;
            }
            if (isset($_POST['cpagrip_user_id'])) {
                $db->updateSetting('cpagrip_user_id', $_POST['cpagrip_user_id']);
                $updated++;
            }
            if (isset($_POST['shortest_api_key'])) {
                $db->updateSetting('shortest_api_key', $_POST['shortest_api_key']);
                $updated++;
            }

            // Update welcome message
            if (isset($_POST['welcome_message'])) {
                $db->updateSetting('welcome_message', $_POST['welcome_message']);
                $updated++;
            }

            echo json_encode(['success' => true, 'updated' => $updated]);
            break;

        // Channels Endpoints
        case 'get_channels':
            $user_id = $_GET['user_id'] ?? getUserFromWebApp();
            $channels = $db->getActiveChannels();

            // Mark which ones user has joined
            foreach ($channels as &$channel) {
                $channel['joined'] = $db->hasUserJoinedChannel($user_id, $channel['id']);
            }

            echo json_encode(['success' => true, 'channels' => $channels]);
            break;

        case 'get_unjoined_channels':
            $user_id = $_GET['user_id'] ?? getUserFromWebApp();
            $channels = $db->getUnjoinedChannels($user_id);

            echo json_encode(['success' => true, 'channels' => $channels]);
            break;

        case 'admin_add_channel':
            $admin_id = $_POST['admin_id'] ?? 0;

            if (!isAdmin($admin_id)) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            $channel_id = $_POST['channel_id'] ?? '';
            $channel_username = $_POST['channel_username'] ?? '';
            $channel_title = $_POST['channel_title'] ?? '';
            $points_reward = $_POST['points_reward'] ?? 0;

            if (empty($channel_id) || empty($channel_title) || $points_reward <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid data']);
                break;
            }

            if ($db->addChannel($channel_id, $channel_username, $channel_title, $points_reward)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to add channel']);
            }
            break;

        case 'admin_update_channel':
            $admin_id = $_POST['admin_id'] ?? 0;

            if (!isAdmin($admin_id)) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            $channel_id = $_POST['channel_id'] ?? 0;
            $points_reward = $_POST['points_reward'] ?? 0;
            $is_active = $_POST['is_active'] ?? 1;

            if ($db->updateChannel($channel_id, $points_reward, $is_active)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update channel']);
            }
            break;

        case 'admin_delete_channel':
            $admin_id = $_POST['admin_id'] ?? 0;

            if (!isAdmin($admin_id)) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            $channel_id = $_POST['channel_id'] ?? 0;

            $conn = $db->getConnection();
            $stmt = $conn->prepare("DELETE FROM channels WHERE id = ?");
            $success = $stmt->execute([$channel_id]);

            echo json_encode(['success' => $success]);
            break;

        // Shortened Link Ads Endpoints
        case 'get_link_ads':
            $ads = $db->getActiveLinkAds();
            echo json_encode(['success' => true, 'ads' => $ads]);
            break;

        case 'generate_link':
            $user_id = $_POST['user_id'] ?? getUserFromWebApp();
            $ad_id = $_POST['ad_id'] ?? 0;

            if (!$user_id || !$ad_id) {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                break;
            }

            // Check if already clicked
            if ($db->hasUserClickedLinkAd($user_id, $ad_id)) {
                echo json_encode(['success' => false, 'error' => 'already_clicked']);
                break;
            }

            $ad = $db->getLinkAd($ad_id);
            if (!$ad) {
                echo json_encode(['success' => false, 'error' => 'Ad not found']);
                break;
            }

            // Generate token
            $token = $db->createLinkClick($user_id, $ad_id);

            // Create verification URL (user will send this token back to bot)
            $verify_url = 'https://t.me/' . BOT_USERNAME . '?start=' . $token;

            // Create shortened URL based on service
            $destination = $ad['destination_url'];
            $shortened_url = null;

            if ($ad['shortener_service'] === 'shorte.st') {
                $api_key = $db->getSetting('shortest_api_key');
                if ($api_key) {
                    $shortened_url = $adsAPI->createShortestLink($destination, $api_key);
                }
            }

            // If shortening failed, use direct URL
            if (!$shortened_url) {
                $shortened_url = $destination;
            }

            // Update the click record with shortened URL
            $conn = $db->getConnection();
            $stmt = $conn->prepare("UPDATE link_clicks SET shortened_url = ? WHERE token = ?");
            $stmt->execute([$shortened_url, $token]);

            echo json_encode([
                'success' => true,
                'token' => $token,
                'shortened_url' => $shortened_url,
                'verify_instructions' => 'انسخ الرمز وأرسله للبوت بعد زيارة الرابط'
            ]);
            break;

        case 'admin_add_link_ad':
            $admin_id = $_POST['admin_id'] ?? 0;

            if (!isAdmin($admin_id)) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $destination_url = $_POST['destination_url'] ?? '';
            $points_reward = $_POST['points_reward'] ?? 0;
            $shortener_service = $_POST['shortener_service'] ?? 'shorte.st';

            if (empty($title) || empty($destination_url) || $points_reward <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid data']);
                break;
            }

            $conn = $db->getConnection();
            $stmt = $conn->prepare("INSERT INTO shortened_link_ads (title, description, destination_url, points_reward, shortener_service)
                                    VALUES (?, ?, ?, ?, ?)");
            $success = $stmt->execute([$title, $description, $destination_url, $points_reward, $shortener_service]);

            if ($success) {
                echo json_encode(['success' => true, 'ad_id' => $conn->lastInsertId()]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to add ad']);
            }
            break;

        case 'admin_delete_link_ad':
            $admin_id = $_POST['admin_id'] ?? 0;

            if (!isAdmin($admin_id)) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            $ad_id = $_POST['ad_id'] ?? 0;

            $conn = $db->getConnection();
            $stmt = $conn->prepare("DELETE FROM shortened_link_ads WHERE id = ?");
            $success = $stmt->execute([$ad_id]);

            echo json_encode(['success' => $success]);
            break;

        // File Upload Endpoint
        case 'admin_upload_file':
            $admin_id = $_POST['admin_id'] ?? 0;

            if (!isAdmin($admin_id)) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                break;
            }

            // This endpoint receives file_id from Telegram (uploaded via bot)
            $file_id = $_POST['file_id'] ?? '';
            $file_type = $_POST['file_type'] ?? 'document';
            $file_name = $_POST['file_name'] ?? 'file';

            if (empty($file_id)) {
                echo json_encode(['success' => false, 'error' => 'No file provided']);
                break;
            }

            // Store file metadata
            echo json_encode([
                'success' => true,
                'file_id' => $file_id,
                'file_type' => $file_type,
                'file_name' => $file_name
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }

} catch (Exception $e) {
    logError("API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
