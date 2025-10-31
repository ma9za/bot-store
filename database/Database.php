<?php
/**
 * Database Manager Class
 * Handles all SQLite database operations
 */

class Database {
    private static $instance = null;
    private $db;

    private function __construct() {
        try {
            $this->db = new PDO('sqlite:' . DB_PATH);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->createTables();
        } catch (PDOException $e) {
            logError("Database Connection Error: " . $e->getMessage());
            die("Database connection failed");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function createTables() {
        // Users Table
        $this->db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER UNIQUE NOT NULL,
            username TEXT,
            first_name TEXT,
            last_name TEXT,
            points INTEGER DEFAULT 0,
            total_earned INTEGER DEFAULT 0,
            total_spent INTEGER DEFAULT 0,
            referral_code TEXT UNIQUE,
            referred_by INTEGER,
            is_blocked INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Products Table
        $this->db->exec("CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT,
            image_file_id TEXT,
            price INTEGER NOT NULL,
            stock_quantity INTEGER DEFAULT -1,
            max_per_user INTEGER DEFAULT 1,
            content_type TEXT DEFAULT 'unique',
            file_id TEXT,
            file_type TEXT,
            is_offer INTEGER DEFAULT 0,
            offer_price INTEGER,
            offer_ends_at DATETIME,
            category TEXT,
            is_active INTEGER DEFAULT 1,
            sales_count INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Product Content Table (للمنتجات الرقمية - كودات، روابط، etc)
        $this->db->exec("CREATE TABLE IF NOT EXISTS product_content (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            is_used INTEGER DEFAULT 0,
            used_by INTEGER,
            used_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )");

        // Orders Table
        $this->db->exec("CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            points_spent INTEGER NOT NULL,
            content_delivered TEXT,
            status TEXT DEFAULT 'completed',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(user_id),
            FOREIGN KEY (product_id) REFERENCES products(id)
        )");

        // Ads Table
        $this->db->exec("CREATE TABLE IF NOT EXISTS ads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            url TEXT NOT NULL,
            points_reward INTEGER NOT NULL,
            api_provider TEXT,
            is_active INTEGER DEFAULT 1,
            view_count INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Ad Views Table (لتتبع مشاهدات الإعلانات)
        $this->db->exec("CREATE TABLE IF NOT EXISTS ad_views (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            ad_id INTEGER NOT NULL,
            completed INTEGER DEFAULT 0,
            points_earned INTEGER DEFAULT 0,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users(user_id),
            FOREIGN KEY (ad_id) REFERENCES ads(id)
        )");

        // Referrals Table
        $this->db->exec("CREATE TABLE IF NOT EXISTS referrals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            referrer_id INTEGER NOT NULL,
            referred_id INTEGER NOT NULL,
            points_earned INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (referrer_id) REFERENCES users(user_id),
            FOREIGN KEY (referred_id) REFERENCES users(user_id)
        )");

        // Transactions Table (لتتبع جميع حركات النقاط)
        $this->db->exec("CREATE TABLE IF NOT EXISTS transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            amount INTEGER NOT NULL,
            description TEXT,
            reference_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(user_id)
        )");

        // Settings Table
        $this->db->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Notifications Table (للإشعارات الإدارية)
        $this->db->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            is_read INTEGER DEFAULT 0,
            type TEXT DEFAULT 'info',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(user_id)
        )");

        // Shortened Link Ads Table (روابط الإعلانات المختصرة)
        $this->db->exec("CREATE TABLE IF NOT EXISTS shortened_link_ads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT,
            destination_url TEXT NOT NULL,
            points_reward INTEGER NOT NULL,
            shortener_service TEXT DEFAULT 'shorte.st',
            is_active INTEGER DEFAULT 1,
            view_count INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Link Clicks Table (تتبع نقرات الروابط)
        $this->db->exec("CREATE TABLE IF NOT EXISTS link_clicks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            ad_id INTEGER NOT NULL,
            token TEXT UNIQUE NOT NULL,
            shortened_url TEXT,
            clicked INTEGER DEFAULT 0,
            verified INTEGER DEFAULT 0,
            points_earned INTEGER DEFAULT 0,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            clicked_at DATETIME,
            verified_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users(user_id),
            FOREIGN KEY (ad_id) REFERENCES shortened_link_ads(id)
        )");

        // Channels Table (قنوات الاشتراك الإجباري)
        $this->db->exec("CREATE TABLE IF NOT EXISTS channels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel_id TEXT UNIQUE NOT NULL,
            channel_username TEXT,
            channel_title TEXT NOT NULL,
            points_reward INTEGER NOT NULL,
            is_active INTEGER DEFAULT 1,
            join_count INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Channel Joins Table (تتبع انضمام المستخدمين للقنوات)
        $this->db->exec("CREATE TABLE IF NOT EXISTS channel_joins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            channel_id INTEGER NOT NULL,
            points_earned INTEGER DEFAULT 0,
            verified INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(user_id),
            FOREIGN KEY (channel_id) REFERENCES channels(id),
            UNIQUE(user_id, channel_id)
        )");

        // Initialize default settings
        $this->initializeSettings();
    }

    private function initializeSettings() {
        $defaults = [
            'points_per_video_ad' => POINTS_PER_VIDEO_AD,
            'points_per_link_ad' => POINTS_PER_LINK_AD,
            'points_per_referral' => POINTS_PER_REFERRAL,
            'cpagrip_api_key' => CPAGRIP_API_KEY,
            'cpagrip_user_id' => CPAGRIP_USER_ID,
            'shortest_api_key' => SHORTEST_API_KEY,
            'welcome_message' => 'مرحباً بك في متجر المنتجات الرقمية! 🎉',
            'store_active' => '1',
            'min_purchase_points' => '0'
        ];

        $stmt = $this->db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        foreach ($defaults as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    }

    public function getConnection() {
        return $this->db;
    }

    // User Methods
    public function getUser($user_id) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    }

    public function createUser($user_data) {
        $referral_code = $this->generateReferralCode($user_data['user_id']);

        $stmt = $this->db->prepare("INSERT INTO users (user_id, username, first_name, last_name, referral_code, referred_by)
                                    VALUES (?, ?, ?, ?, ?, ?)");

        return $stmt->execute([
            $user_data['user_id'],
            $user_data['username'] ?? null,
            $user_data['first_name'] ?? null,
            $user_data['last_name'] ?? null,
            $referral_code,
            $user_data['referred_by'] ?? null
        ]);
    }

    public function updateUserActivity($user_id) {
        $stmt = $this->db->prepare("UPDATE users SET last_activity = CURRENT_TIMESTAMP WHERE user_id = ?");
        return $stmt->execute([$user_id]);
    }

    public function addPoints($user_id, $points, $type, $description, $reference_id = null) {
        $this->db->beginTransaction();
        try {
            // Update user points
            $stmt = $this->db->prepare("UPDATE users SET points = points + ?, total_earned = total_earned + ? WHERE user_id = ?");
            $stmt->execute([$points, $points, $user_id]);

            // Record transaction
            $stmt = $this->db->prepare("INSERT INTO transactions (user_id, type, amount, description, reference_id)
                                       VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $type, $points, $description, $reference_id]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Add Points Error: " . $e->getMessage());
            return false;
        }
    }

    public function deductPoints($user_id, $points, $type, $description, $reference_id = null) {
        $this->db->beginTransaction();
        try {
            // Check if user has enough points
            $user = $this->getUser($user_id);
            if ($user['points'] < $points) {
                return false;
            }

            // Update user points
            $stmt = $this->db->prepare("UPDATE users SET points = points - ?, total_spent = total_spent + ? WHERE user_id = ?");
            $stmt->execute([$points, $points, $user_id]);

            // Record transaction
            $stmt = $this->db->prepare("INSERT INTO transactions (user_id, type, amount, description, reference_id)
                                       VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $type, -$points, $description, $reference_id]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Deduct Points Error: " . $e->getMessage());
            return false;
        }
    }

    private function generateReferralCode($user_id) {
        return 'REF' . strtoupper(substr(md5($user_id . time()), 0, 8));
    }

    public function getUserByReferralCode($code) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE referral_code = ?");
        $stmt->execute([$code]);
        return $stmt->fetch();
    }

    // Product Methods
    public function getProducts($active_only = true) {
        $sql = "SELECT * FROM products" . ($active_only ? " WHERE is_active = 1" : "") . " ORDER BY created_at DESC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    public function getProduct($product_id) {
        $stmt = $this->db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        return $stmt->fetch();
    }

    public function createOrder($user_id, $product_id) {
        $this->db->beginTransaction();
        try {
            $product = $this->getProduct($product_id);

            if (!$product || !$product['is_active']) {
                throw new Exception("Product not available");
            }

            // Check stock
            if ($product['stock_quantity'] != -1 && $product['stock_quantity'] <= 0) {
                throw new Exception("Out of stock");
            }

            // Check user purchase limit
            if ($product['max_per_user'] > 0) {
                $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ? AND product_id = ?");
                $stmt->execute([$user_id, $product_id]);
                $purchased = $stmt->fetch()['count'];

                if ($purchased >= $product['max_per_user']) {
                    throw new Exception("Purchase limit reached");
                }
            }

            // Get price (check for offers)
            $price = $product['price'];
            if ($product['is_offer'] && $product['offer_price'] &&
                (!$product['offer_ends_at'] || strtotime($product['offer_ends_at']) > time())) {
                $price = $product['offer_price'];
            }

            // Deduct points
            if (!$this->deductPoints($user_id, $price, 'purchase', 'Purchase: ' . $product['name'], $product_id)) {
                throw new Exception("Insufficient points");
            }

            // Get unused content
            $stmt = $this->db->prepare("SELECT * FROM product_content WHERE product_id = ? AND is_used = 0 LIMIT 1");
            $stmt->execute([$product_id]);
            $content = $stmt->fetch();

            if (!$content) {
                // Refund points
                $this->addPoints($user_id, $price, 'refund', 'Refund: ' . $product['name'] . ' (Out of stock)');
                throw new Exception("Product content not available");
            }

            // Mark content as used
            $stmt = $this->db->prepare("UPDATE product_content SET is_used = 1, used_by = ?, used_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$user_id, $content['id']]);

            // Create order
            $stmt = $this->db->prepare("INSERT INTO orders (user_id, product_id, product_name, points_spent, content_delivered)
                                       VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $product_id, $product['name'], $price, $content['content']]);

            $order_id = $this->db->lastInsertId();

            // Update product stats
            $stmt = $this->db->prepare("UPDATE products SET sales_count = sales_count + 1,
                                       stock_quantity = CASE WHEN stock_quantity = -1 THEN -1 ELSE stock_quantity - 1 END
                                       WHERE id = ?");
            $stmt->execute([$product_id]);

            $this->db->commit();

            return [
                'success' => true,
                'order_id' => $order_id,
                'content' => $content['content'],
                'product_name' => $product['name']
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Create Order Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Ad Methods
    public function getActiveAds() {
        $stmt = $this->db->query("SELECT * FROM ads WHERE is_active = 1 ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    public function getAd($ad_id) {
        $stmt = $this->db->prepare("SELECT * FROM ads WHERE id = ?");
        $stmt->execute([$ad_id]);
        return $stmt->fetch();
    }

    public function recordAdView($user_id, $ad_id, $ip_address = null) {
        $stmt = $this->db->prepare("INSERT INTO ad_views (user_id, ad_id, ip_address) VALUES (?, ?, ?)");
        return $stmt->execute([$user_id, $ad_id, $ip_address]);
    }

    public function completeAdView($user_id, $ad_id) {
        $this->db->beginTransaction();
        try {
            $ad = $this->getAd($ad_id);

            // Mark ad view as completed
            $stmt = $this->db->prepare("UPDATE ad_views SET completed = 1, completed_at = CURRENT_TIMESTAMP,
                                       points_earned = ? WHERE user_id = ? AND ad_id = ? AND completed = 0");
            $stmt->execute([$ad['points_reward'], $user_id, $ad_id]);

            // Add points to user
            $this->addPoints($user_id, $ad['points_reward'], 'ad_view', 'Ad View: ' . $ad['title'], $ad_id);

            // Update ad view count
            $stmt = $this->db->prepare("UPDATE ads SET view_count = view_count + 1 WHERE id = ?");
            $stmt->execute([$ad_id]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Complete Ad View Error: " . $e->getMessage());
            return false;
        }
    }

    // Referral Methods
    public function createReferral($referrer_id, $referred_id) {
        $points = $this->getSetting('points_per_referral');
        $bonus_for_new = $this->getSetting('points_for_new_referral') ?: $points; // نفس النقاط للطرفين

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO referrals (referrer_id, referred_id, points_earned) VALUES (?, ?, ?)");
            $stmt->execute([$referrer_id, $referred_id, $points]);

            // إعطاء نقاط للمُحيل
            $this->addPoints($referrer_id, $points, 'referral', 'مكافأة دعوة مستخدم جديد 🎁', $referred_id);

            // إعطاء نقاط للمستخدم الجديد
            $this->addPoints($referred_id, $bonus_for_new, 'referral_bonus', 'مكافأة الانضمام عبر دعوة 🎉', $referrer_id);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Create Referral Error: " . $e->getMessage());
            return false;
        }
    }

    public function getReferralStats($user_id) {
        $stmt = $this->db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(points_earned), 0) as total_points
                                    FROM referrals WHERE referrer_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    }

    // Settings Methods
    public function getSetting($key) {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['value'] : null;
    }

    public function updateSetting($key, $value) {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
        return $stmt->execute([$key, $value]);
    }

    // Statistics Methods
    public function getStats() {
        $stats = [];

        $stmt = $this->db->query("SELECT COUNT(*) as count FROM users");
        $stats['total_users'] = $stmt->fetch()['count'];

        $stmt = $this->db->query("SELECT COUNT(*) as count FROM users WHERE DATE(last_activity) = DATE('now')");
        $stats['active_today'] = $stmt->fetch()['count'];

        $stmt = $this->db->query("SELECT COUNT(*) as count FROM products WHERE is_active = 1");
        $stats['active_products'] = $stmt->fetch()['count'];

        $stmt = $this->db->query("SELECT COUNT(*) as count FROM orders");
        $stats['total_orders'] = $stmt->fetch()['count'];

        $stmt = $this->db->query("SELECT COALESCE(SUM(points_spent), 0) as total FROM orders");
        $stats['total_revenue'] = $stmt->fetch()['total'];

        $stmt = $this->db->query("SELECT COUNT(*) as count FROM ad_views WHERE completed = 1");
        $stats['total_ad_views'] = $stmt->fetch()['count'];

        return $stats;
    }

    // Shortened Link Ads Methods
    public function getActiveLinkAds() {
        $stmt = $this->db->query("SELECT * FROM shortened_link_ads WHERE is_active = 1 ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    public function getLinkAd($ad_id) {
        $stmt = $this->db->prepare("SELECT * FROM shortened_link_ads WHERE id = ?");
        $stmt->execute([$ad_id]);
        return $stmt->fetch();
    }

    public function generateLinkToken($user_id, $ad_id) {
        return bin2hex(random_bytes(16)) . '_' . $user_id . '_' . $ad_id . '_' . time();
    }

    public function createLinkClick($user_id, $ad_id, $shortened_url = null) {
        $token = $this->generateLinkToken($user_id, $ad_id);

        $stmt = $this->db->prepare("INSERT INTO link_clicks (user_id, ad_id, token, shortened_url) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $ad_id, $token, $shortened_url]);

        return $token;
    }

    public function getLinkClickByToken($token) {
        $stmt = $this->db->prepare("SELECT * FROM link_clicks WHERE token = ?");
        $stmt->execute([$token]);
        return $stmt->fetch();
    }

    public function verifyLinkClick($token) {
        $this->db->beginTransaction();
        try {
            $click = $this->getLinkClickByToken($token);

            if (!$click || $click['verified']) {
                return false; // Already verified or not found
            }

            $ad = $this->getLinkAd($click['ad_id']);

            // Mark as verified
            $stmt = $this->db->prepare("UPDATE link_clicks SET verified = 1, points_earned = ?, verified_at = CURRENT_TIMESTAMP WHERE token = ?");
            $stmt->execute([$ad['points_reward'], $token]);

            // Add points to user
            $this->addPoints($click['user_id'], $ad['points_reward'], 'link_ad', 'مشاهدة إعلان: ' . $ad['title'], $click['ad_id']);

            // Update ad stats
            $stmt = $this->db->prepare("UPDATE shortened_link_ads SET view_count = view_count + 1 WHERE id = ?");
            $stmt->execute([$click['ad_id']]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Verify Link Click Error: " . $e->getMessage());
            return false;
        }
    }

    public function hasUserClickedLinkAd($user_id, $ad_id) {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM link_clicks WHERE user_id = ? AND ad_id = ? AND verified = 1");
        $stmt->execute([$user_id, $ad_id]);
        return $stmt->fetch()['count'] > 0;
    }

    // Channel Methods
    public function getActiveChannels() {
        $stmt = $this->db->query("SELECT * FROM channels WHERE is_active = 1 ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    public function getChannel($channel_id) {
        $stmt = $this->db->prepare("SELECT * FROM channels WHERE id = ? OR channel_id = ?");
        $stmt->execute([$channel_id, $channel_id]);
        return $stmt->fetch();
    }

    public function addChannel($channel_id, $channel_username, $channel_title, $points_reward) {
        $stmt = $this->db->prepare("INSERT INTO channels (channel_id, channel_username, channel_title, points_reward)
                                    VALUES (?, ?, ?, ?)");
        return $stmt->execute([$channel_id, $channel_username, $channel_title, $points_reward]);
    }

    public function updateChannel($id, $points_reward, $is_active) {
        $stmt = $this->db->prepare("UPDATE channels SET points_reward = ?, is_active = ? WHERE id = ?");
        return $stmt->execute([$points_reward, $is_active, $id]);
    }

    public function recordChannelJoin($user_id, $channel_db_id, $points_earned) {
        $this->db->beginTransaction();
        try {
            // Check if already joined
            $stmt = $this->db->prepare("SELECT * FROM channel_joins WHERE user_id = ? AND channel_id = ?");
            $stmt->execute([$user_id, $channel_db_id]);

            if ($stmt->fetch()) {
                return false; // Already joined
            }

            // Record join
            $stmt = $this->db->prepare("INSERT INTO channel_joins (user_id, channel_id, points_earned) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $channel_db_id, $points_earned]);

            // Add points
            $channel = $this->getChannel($channel_db_id);
            $this->addPoints($user_id, $points_earned, 'channel_join', 'الانضمام للقناة: ' . $channel['channel_title'], $channel_db_id);

            // Update channel stats
            $stmt = $this->db->prepare("UPDATE channels SET join_count = join_count + 1 WHERE id = ?");
            $stmt->execute([$channel_db_id]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Record Channel Join Error: " . $e->getMessage());
            return false;
        }
    }

    public function hasUserJoinedChannel($user_id, $channel_db_id) {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM channel_joins WHERE user_id = ? AND channel_id = ?");
        $stmt->execute([$user_id, $channel_db_id]);
        return $stmt->fetch()['count'] > 0;
    }

    public function getUnjoinedChannels($user_id) {
        $stmt = $this->db->prepare("
            SELECT c.* FROM channels c
            WHERE c.is_active = 1
            AND c.id NOT IN (
                SELECT channel_id FROM channel_joins WHERE user_id = ?
            )
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }

    // Product Methods for new content types
    public function updateProductContentType($product_id, $content_type, $file_id = null, $file_type = null) {
        $stmt = $this->db->prepare("UPDATE products SET content_type = ?, file_id = ?, file_type = ? WHERE id = ?");
        return $stmt->execute([$content_type, $file_id, $file_type, $product_id]);
    }

    public function getProductWithContent($product_id) {
        $product = $this->getProduct($product_id);

        if ($product && $product['content_type'] === 'unique') {
            // Get available content count
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM product_content WHERE product_id = ? AND is_used = 0");
            $stmt->execute([$product_id]);
            $product['available_content'] = $stmt->fetch()['count'];
        }

        return $product;
    }
}
