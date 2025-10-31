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

            $result = $db->createOrder($user_id, $product_id);
            echo json_encode($result);
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

            if (empty($name) || $price <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid data']);
                break;
            }

            $conn = $db->getConnection();
            $stmt = $conn->prepare("INSERT INTO products (name, description, price, stock_quantity, max_per_user)
                                   VALUES (?, ?, ?, ?, ?)");

            $success = $stmt->execute([$name, $description, $price, $stock_quantity, $max_per_user]);

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

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }

} catch (Exception $e) {
    logError("API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
