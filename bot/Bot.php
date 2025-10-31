<?php
/**
 * Telegram Bot Handler Class
 */

class Bot {
    private $db;
    private $update;
    private $message;
    private $chat_id;
    private $user_id;
    private $user;

    public function __construct($update) {
        $this->db = Database::getInstance();
        $this->update = $update;

        if (isset($update['message'])) {
            $this->message = $update['message'];
            $this->chat_id = $this->message['chat']['id'];
            $this->user_id = $this->message['from']['id'];
        } elseif (isset($update['callback_query'])) {
            $this->message = $update['callback_query']['message'];
            $this->chat_id = $this->message['chat']['id'];
            $this->user_id = $update['callback_query']['from']['id'];
        }

        // Initialize or get user
        if ($this->user_id) {
            $this->user = $this->db->getUser($this->user_id);
            if (!$this->user) {
                $this->createNewUser();
            } else {
                $this->db->updateUserActivity($this->user_id);
            }
        }
    }

    private function createNewUser() {
        $user_data = [
            'user_id' => $this->user_id,
            'username' => $this->message['from']['username'] ?? null,
            'first_name' => $this->message['from']['first_name'] ?? null,
            'last_name' => $this->message['from']['last_name'] ?? null
        ];

        // Check for referral
        if (isset($this->message['text'])) {
            $parts = explode(' ', $this->message['text']);
            if (count($parts) > 1 && strpos($parts[1], 'REF') === 0) {
                $referrer = $this->db->getUserByReferralCode($parts[1]);
                if ($referrer && $referrer['user_id'] != $this->user_id) {
                    $user_data['referred_by'] = $referrer['user_id'];
                }
            }
        }

        $this->db->createUser($user_data);
        $this->user = $this->db->getUser($this->user_id);

        // Process referral bonus
        if (isset($user_data['referred_by'])) {
            $this->db->createReferral($user_data['referred_by'], $this->user_id);
            $this->sendMessage($user_data['referred_by'], "🎉 مبروك! لقد قام صديقك بالانضمام وحصلت على " . $this->db->getSetting('points_per_referral') . " نقطة!");
        }
    }

    public function process() {
        try {
            // Check if user is blocked
            if ($this->user && $this->user['is_blocked']) {
                $this->sendMessage($this->chat_id, "❌ تم حظر حسابك من استخدام البوت.");
                return;
            }

            if (isset($this->update['message'])) {
                $this->handleMessage();
            } elseif (isset($this->update['callback_query'])) {
                $this->handleCallbackQuery();
            }
        } catch (Exception $e) {
            logError("Bot Process Error: " . $e->getMessage());
            $this->sendMessage($this->chat_id, "حدث خطأ، يرجى المحاولة لاحقاً.");
        }
    }

    private function handleMessage() {
        $text = $this->message['text'] ?? '';

        if ($text === '/start' || strpos($text, '/start') === 0) {
            $this->handleStart();
        } elseif ($text === '/help') {
            $this->handleHelp();
        } elseif ($text === '/mypoints' || $text === 'نقاطي') {
            $this->handleMyPoints();
        } elseif ($text === '/referral' || $text === 'دعوة الأصدقاء') {
            $this->handleReferral();
        } elseif ($text === '/store' || $text === 'المتجر') {
            $this->handleStore();
        } elseif ($text === '/earn' || $text === 'اكسب نقاط') {
            $this->handleEarn();
        } elseif (isAdmin($this->user_id)) {
            if ($text === '/admin' || $text === 'لوحة الإدارة') {
                $this->handleAdminPanel();
            } elseif ($text === '/stats' || $text === 'الإحصائيات') {
                $this->handleStats();
            } elseif (strpos($text, '/broadcast') === 0) {
                $this->handleBroadcast($text);
            }
        } else {
            $this->handleUnknownCommand();
        }
    }

    private function handleCallbackQuery() {
        $callback_data = $this->update['callback_query']['data'];
        $callback_id = $this->update['callback_query']['id'];

        $parts = explode(':', $callback_data);
        $action = $parts[0];

        switch ($action) {
            case 'buy':
                $product_id = $parts[1];
                $this->handleBuyProduct($product_id, $callback_id);
                break;

            case 'confirm_buy':
                $product_id = $parts[1];
                $this->handleConfirmBuy($product_id, $callback_id);
                break;

            case 'view_ad':
                $ad_id = $parts[1];
                $this->handleViewAd($ad_id, $callback_id);
                break;

            case 'admin_products':
                $this->handleAdminProducts($callback_id);
                break;

            case 'admin_ads':
                $this->handleAdminAds($callback_id);
                break;

            case 'admin_users':
                $this->handleAdminUsers($callback_id);
                break;

            default:
                $this->answerCallbackQuery($callback_id, "Unknown action");
        }
    }

    private function handleStart() {
        $welcome_message = $this->db->getSetting('welcome_message');

        $keyboard = [
            [
                ['text' => '🛍 المتجر', 'web_app' => ['url' => MINI_APP_URL]]
            ],
            [
                ['text' => '💰 اكسب نقاط'],
                ['text' => '👥 دعوة الأصدقاء']
            ],
            [
                ['text' => '💎 نقاطي'],
                ['text' => '📊 إحصائياتي']
            ]
        ];

        if (isAdmin($this->user_id)) {
            $keyboard[] = [['text' => '⚙️ لوحة الإدارة']];
        }

        $reply_markup = [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];

        $text = "$welcome_message\n\n";
        $text .= "👤 مرحباً " . ($this->user['first_name'] ?? 'عزيزي') . "!\n";
        $text .= "💎 نقاطك الحالية: " . $this->user['points'] . " نقطة\n\n";
        $text .= "🛍 تصفح المتجر واشتري المنتجات الرقمية\n";
        $text .= "💰 اكسب النقاط من خلال مشاهدة الإعلانات\n";
        $text .= "👥 ادعُ أصدقاءك واحصل على نقاط إضافية!\n\n";
        $text .= "اضغط على الأزرار بالأسفل للبدء 👇";

        $this->sendMessage($this->chat_id, $text, $reply_markup);
    }

    private function handleHelp() {
        $text = "📚 *دليل الاستخدام*\n\n";
        $text .= "🛍 *المتجر*: تصفح وشراء المنتجات الرقمية\n";
        $text .= "💰 *اكسب نقاط*: شاهد الإعلانات واحصل على نقاط\n";
        $text .= "👥 *دعوة الأصدقاء*: احصل على نقاط عند دعوة أصدقائك\n";
        $text .= "💎 *نقاطي*: عرض رصيدك وإحصائياتك\n\n";
        $text .= "📞 للدعم: @support";

        $this->sendMessage($this->chat_id, $text, null, 'Markdown');
    }

    private function handleMyPoints() {
        $text = "💎 *معلومات حسابك*\n\n";
        $text .= "💰 الرصيد الحالي: *" . $this->user['points'] . "* نقطة\n";
        $text .= "📈 إجمالي النقاط المكتسبة: " . $this->user['total_earned'] . " نقطة\n";
        $text .= "🛒 إجمالي النقاط المنفقة: " . $this->user['total_spent'] . " نقطة\n\n";

        // Referral stats
        $referral_stats = $this->db->getReferralStats($this->user_id);
        $text .= "👥 عدد دعواتك: " . $referral_stats['total'] . " شخص\n";
        $text .= "🎁 نقاط من الدعوات: " . $referral_stats['total_points'] . " نقطة\n";

        $this->sendMessage($this->chat_id, $text, null, 'Markdown');
    }

    private function handleReferral() {
        $referral_link = "https://t.me/" . BOT_USERNAME . "?start=" . $this->user['referral_code'];
        $points_per_referral = $this->db->getSetting('points_per_referral');

        $text = "👥 *نظام الدعوات*\n\n";
        $text .= "🎁 احصل على *$points_per_referral نقطة* عن كل صديق تدعوه!\n\n";
        $text .= "🔗 رابط دعوتك الخاص:\n";
        $text .= "`$referral_link`\n\n";
        $text .= "📊 إحصائياتك:\n";

        $referral_stats = $this->db->getReferralStats($this->user_id);
        $text .= "👥 عدد الدعوات: " . $referral_stats['total'] . "\n";
        $text .= "💎 النقاط المكتسبة: " . $referral_stats['total_points'] . "\n\n";
        $text .= "شارك الرابط مع أصدقائك الآن! 🚀";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📤 مشاركة الرابط', 'url' => "https://t.me/share/url?url=" . urlencode($referral_link) . "&text=" . urlencode("انضم معي في هذا المتجر الرائع واحصل على منتجات رقمية مجاناً!")]
                ]
            ]
        ];

        $this->sendMessage($this->chat_id, $text, $keyboard, 'Markdown');
    }

    private function handleStore() {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🛍 افتح المتجر', 'web_app' => ['url' => MINI_APP_URL]]
                ]
            ]
        ];

        $text = "🛍 *متجر المنتجات الرقمية*\n\n";
        $text .= "اضغط على الزر بالأسفل لفتح المتجر 👇\n";
        $text .= "💎 رصيدك: " . $this->user['points'] . " نقطة";

        $this->sendMessage($this->chat_id, $text, $keyboard, 'Markdown');
    }

    private function handleEarn() {
        $ads = $this->db->getActiveAds();

        if (empty($ads)) {
            $this->sendMessage($this->chat_id, "❌ لا توجد إعلانات متاحة حالياً، عد لاحقاً!");
            return;
        }

        $keyboard = ['inline_keyboard' => []];

        foreach ($ads as $ad) {
            $emoji = $ad['type'] === 'video' ? '🎥' : '🔗';
            $keyboard['inline_keyboard'][] = [
                ['text' => "$emoji {$ad['title']} (+{$ad['points_reward']} نقطة)", 'callback_data' => "view_ad:{$ad['id']}"]
            ];
        }

        $text = "💰 *اكسب النقاط*\n\n";
        $text .= "اختر أحد الإعلانات لمشاهدته والحصول على نقاط:\n\n";
        $text .= "💎 رصيدك الحالي: " . $this->user['points'] . " نقطة";

        $this->sendMessage($this->chat_id, $text, $keyboard, 'Markdown');
    }

    private function handleViewAd($ad_id, $callback_id) {
        $ad = $this->db->getAd($ad_id);

        if (!$ad || !$ad['is_active']) {
            $this->answerCallbackQuery($callback_id, "❌ الإعلان غير متاح");
            return;
        }

        // Record ad view
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->db->recordAdView($this->user_id, $ad_id, $ip);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔗 افتح الإعلان (+' . $ad['points_reward'] . ' نقطة)', 'url' => $ad['url']]
                ]
            ]
        ];

        $text = "📢 *{$ad['title']}*\n\n";
        if ($ad['description']) {
            $text .= "{$ad['description']}\n\n";
        }
        $text .= "💎 المكافأة: {$ad['points_reward']} نقطة\n\n";
        $text .= "اضغط على الزر بالأسفل لفتح الإعلان 👇\n";
        $text .= "بعد إكمال المشاهدة سيتم إضافة النقاط تلقائياً!";

        $this->sendMessage($this->chat_id, $text, $keyboard, 'Markdown');
        $this->answerCallbackQuery($callback_id);

        // For automatic completion (يمكن تحسينه مع callback من API الإعلانات)
        // Simulate completion after viewing (في الواقع يجب استخدام webhooks من API)
        // $this->db->completeAdView($this->user_id, $ad_id);
    }

    private function handleBuyProduct($product_id, $callback_id) {
        $product = $this->db->getProduct($product_id);

        if (!$product || !$product['is_active']) {
            $this->answerCallbackQuery($callback_id, "❌ المنتج غير متاح");
            return;
        }

        $price = $product['price'];
        if ($product['is_offer'] && $product['offer_price']) {
            $price = $product['offer_price'];
        }

        $text = "🛍 *{$product['name']}*\n\n";
        $text .= "{$product['description']}\n\n";
        $text .= "💰 السعر: *$price* نقطة\n";
        $text .= "💎 رصيدك: *{$this->user['points']}* نقطة\n\n";

        if ($this->user['points'] < $price) {
            $text .= "❌ رصيدك غير كافٍ لشراء هذا المنتج\n";
            $text .= "احتاج إلى " . ($price - $this->user['points']) . " نقطة إضافية";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '💰 اكسب المزيد من النقاط', 'callback_data' => 'earn']]
                ]
            ];
        } else {
            $text .= "هل أنت متأكد من الشراء؟";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ تأكيد الشراء', 'callback_data' => "confirm_buy:$product_id"],
                        ['text' => '❌ إلغاء', 'callback_data' => 'store']
                    ]
                ]
            ];
        }

        $this->editMessage($this->chat_id, $this->message['message_id'], $text, $keyboard, 'Markdown');
        $this->answerCallbackQuery($callback_id);
    }

    private function handleConfirmBuy($product_id, $callback_id) {
        $result = $this->db->createOrder($this->user_id, $product_id);

        if ($result['success']) {
            $text = "✅ *تم الشراء بنجاح!*\n\n";
            $text .= "🛍 المنتج: {$result['product_name']}\n\n";
            $text .= "📦 *محتوى المنتج:*\n";
            $text .= "`{$result['content']}`\n\n";
            $text .= "💎 رصيدك المتبقي: " . $this->db->getUser($this->user_id)['points'] . " نقطة\n\n";
            $text .= "شكراً لشرائك! ✨";

            $this->answerCallbackQuery($callback_id, "✅ تم الشراء بنجاح!");

            // Notify admin
            if (isAdmin(ADMIN_ID)) {
                $admin_text = "🛒 *عملية شراء جديدة*\n\n";
                $admin_text .= "👤 المستخدم: " . ($this->user['username'] ? "@{$this->user['username']}" : $this->user['first_name']) . "\n";
                $admin_text .= "🛍 المنتج: {$result['product_name']}\n";
                $admin_text .= "🆔 Order ID: {$result['order_id']}";

                $this->sendMessage(ADMIN_ID, $admin_text, null, 'Markdown');
            }
        } else {
            $text = "❌ فشل الشراء: " . $result['error'];
            $this->answerCallbackQuery($callback_id, "❌ " . $result['error']);
        }

        $this->editMessage($this->chat_id, $this->message['message_id'], $text, null, 'Markdown');
    }

    private function handleAdminPanel() {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📊 الإحصائيات', 'callback_data' => 'admin_stats'],
                    ['text' => '👥 المستخدمين', 'callback_data' => 'admin_users']
                ],
                [
                    ['text' => '🛍 المنتجات', 'callback_data' => 'admin_products'],
                    ['text' => '📢 الإعلانات', 'callback_data' => 'admin_ads']
                ],
                [
                    ['text' => '⚙️ الإعدادات', 'callback_data' => 'admin_settings']
                ]
            ]
        ];

        $text = "⚙️ *لوحة الإدارة*\n\n";
        $text .= "اختر القسم الذي تريد إدارته:";

        $this->sendMessage($this->chat_id, $text, $keyboard, 'Markdown');
    }

    private function handleStats() {
        $stats = $this->db->getStats();

        $text = "📊 *إحصائيات البوت*\n\n";
        $text .= "👥 إجمالي المستخدمين: " . $stats['total_users'] . "\n";
        $text .= "🟢 نشطين اليوم: " . $stats['active_today'] . "\n";
        $text .= "🛍 المنتجات النشطة: " . $stats['active_products'] . "\n";
        $text .= "🛒 إجمالي الطلبات: " . $stats['total_orders'] . "\n";
        $text .= "💰 إجمالي الإيرادات: " . $stats['total_revenue'] . " نقطة\n";
        $text .= "📺 مشاهدات الإعلانات: " . $stats['total_ad_views'] . "\n";

        $this->sendMessage($this->chat_id, $text, null, 'Markdown');
    }

    private function handleBroadcast($text) {
        // Extract message after /broadcast
        $message = trim(substr($text, 10));

        if (empty($message)) {
            $this->sendMessage($this->chat_id, "الاستخدام: /broadcast رسالتك هنا");
            return;
        }

        // Get all users
        $db = $this->db->getConnection();
        $stmt = $db->query("SELECT user_id FROM users WHERE is_blocked = 0");
        $users = $stmt->fetchAll();

        $sent = 0;
        $failed = 0;

        foreach ($users as $user) {
            $result = $this->sendMessage($user['user_id'], "📢 *رسالة من الإدارة*\n\n$message", null, 'Markdown');
            if ($result) {
                $sent++;
            } else {
                $failed++;
            }
            usleep(50000); // 50ms delay to avoid rate limiting
        }

        $this->sendMessage($this->chat_id, "✅ تم الإرسال إلى $sent مستخدم\n❌ فشل الإرسال إلى $failed مستخدم");
    }

    private function handleUnknownCommand() {
        $this->sendMessage($this->chat_id, "❌ أمر غير معروف. اكتب /help للمساعدة");
    }

    // Telegram API Methods
    private function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
        $data = [
            'chat_id' => $chat_id,
            'text' => $text
        ];

        if ($reply_markup) {
            $data['reply_markup'] = json_encode($reply_markup);
        }

        if ($parse_mode) {
            $data['parse_mode'] = $parse_mode;
        }

        return sendRequest('sendMessage', $data);
    }

    private function editMessage($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = null) {
        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $text
        ];

        if ($reply_markup) {
            $data['reply_markup'] = json_encode($reply_markup);
        }

        if ($parse_mode) {
            $data['parse_mode'] = $parse_mode;
        }

        return sendRequest('editMessageText', $data);
    }

    private function answerCallbackQuery($callback_id, $text = null, $show_alert = false) {
        $data = ['callback_query_id' => $callback_id];

        if ($text) {
            $data['text'] = $text;
            $data['show_alert'] = $show_alert;
        }

        return sendRequest('answerCallbackQuery', $data);
    }

    private function handleAdminProducts($callback_id) {
        $this->answerCallbackQuery($callback_id);
        $text = "🛍 *إدارة المنتجات*\n\n";
        $text .= "لإضافة منتج جديد، استخدم الأمر:\n";
        $text .= "`/addproduct`";

        $this->sendMessage($this->chat_id, $text, null, 'Markdown');
    }

    private function handleAdminAds($callback_id) {
        $this->answerCallbackQuery($callback_id);
        $text = "📢 *إدارة الإعلانات*\n\n";
        $text .= "لإضافة إعلان جديد، استخدم الأمر:\n";
        $text .= "`/addad`";

        $this->sendMessage($this->chat_id, $text, null, 'Markdown');
    }

    private function handleAdminUsers($callback_id) {
        $this->answerCallbackQuery($callback_id);
        $db = $this->db->getConnection();
        $stmt = $db->query("SELECT COUNT(*) as total,
                           SUM(CASE WHEN is_blocked = 1 THEN 1 ELSE 0 END) as blocked,
                           SUM(points) as total_points
                           FROM users");
        $stats = $stmt->fetch();

        $text = "👥 *إدارة المستخدمين*\n\n";
        $text .= "📊 إجمالي المستخدمين: " . $stats['total'] . "\n";
        $text .= "🚫 المحظورين: " . $stats['blocked'] . "\n";
        $text .= "💰 إجمالي النقاط: " . $stats['total_points'] . "\n";

        $this->sendMessage($this->chat_id, $text, null, 'Markdown');
    }
}
