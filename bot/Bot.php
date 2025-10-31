<?php
/**
 * Telegram Bot Handler - Advanced Version
 * يدعم: الملفات، القنوات، الروابط المختصرة، الإشعارات
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

        // Process referral bonus - للطرفين
        if (isset($user_data['referred_by'])) {
            $this->db->createReferral($user_data['referred_by'], $this->user_id);
            $points = $this->db->getSetting('points_per_referral');

            // إخطار المُحيل
            $this->sendMessage($user_data['referred_by'], "🎉 *مبروك!*\n\nانضم صديقك وحصلت على *$points* نقطة!\n\n💎 استمر في الدعوة لكسب المزيد", null, 'Markdown');

            // إخطار المستخدم الجديد
            $this->sendMessage($this->user_id, "🎁 *مكافأة الانضمام!*\n\nحصلت على *$points* نقطة ترحيبية!\n\n✨ ابدأ التسوق الآن", null, 'Markdown');
        }
    }

    public function process() {
        try {
            if ($this->user && $this->user['is_blocked']) {
                $this->sendMessage($this->chat_id, "❌ تم حظر حسابك.");
                return;
            }

            if (isset($this->update['message'])) {
                $this->handleMessage();
            }
        } catch (Exception $e) {
            logError("Bot Error: " . $e->getMessage());
        }
    }

    private function handleMessage() {
        $text = $this->message['text'] ?? '';

        // التحقق من التوكن (للروابط المختصرة)
        if (strlen($text) > 20 && strpos($text, '_') !== false) {
            $this->handleTokenVerification($text);
            return;
        }

        if ($text === '/start' || strpos($text, '/start') === 0) {
            $this->handleStart();
        } elseif ($text === '/help') {
            $this->handleHelp();
        } elseif ($text === '/channels') {
            $this->handleCheckChannels();
        } elseif (isAdmin($this->user_id)) {
            // Admin commands
            if (strpos($text, '/broadcast') === 0) {
                $this->handleBroadcast($text);
            } elseif (strpos($text, '/addpoints') === 0) {
                $this->handleAddPoints($text);
            } elseif ($text === '/stats') {
                $this->handleStats();
            } elseif ($text === '/getchannels') {
                $this->handleGetBotChannels();
            }
        }
    }

    private function handleStart() {
        $name = $this->user['first_name'] ?? 'عزيزي';
        $points = $this->user['points'];

        $text = "👋 مرحباً *$name*!\n\n";
        $text .= "💎 نقاطك الحالية: *$points* نقطة\n\n";
        $text .= "🛍 افتح المتجر من الزر بالأسفل ↓\n";
        $text .= "💰 اكسب النقاط واشتري المنتجات\n";
        $text .= "👥 ادعُ أصدقاءك للحصول على نقاط إضافية\n";
        $text .= "📺 انضم للقنوات واربح نقاط\n\n";
        $text .= "كل شيء متاح من المتجر! 🚀";

        $this->sendMessage($this->chat_id, $text, null, 'Markdown');
    }

    private function handleHelp() {
        $text = "📚 *دليل الاستخدام*\n\n";
        $text .= "🛍 افتح المتجر من الزر بالأسفل للوصول إلى:\n";
        $text .= "• تصفح وشراء المنتجات\n";
        $text .= "• كسب النقاط من الإعلانات\n";
        $text .= "• دعوة الأصدقاء\n";
        $text .= "• الانضمام للقنوات\n";
        $text .= "• عرض إحصائياتك\n\n";

        if (isAdmin($this->user_id)) {
            $text .= "⚙️ *أوامر المشرف*:\n";
            $text .= "/stats - الإحصائيات\n";
            $text .= "/broadcast [رسالة] - إرسال جماعي\n";
            $text .= "/addpoints [user_id] [نقاط] - إضافة نقاط\n";
            $text .= "/getchannels - جلب قنوات البوت\n";
            $text .= "• لوحة الإدارة من المتجر\n\n";
        }

        $text .= "📞 للدعم: @support";

        $this->sendMessage($this->chat_id, $text, null, 'Markdown');
    }

    private function handleStats() {
        $stats = $this->db->getStats();

        $text = "📊 *إحصائيات البوت*\n\n";
        $text .= "👥 المستخدمين: {$stats['total_users']}\n";
        $text .= "🟢 نشطين اليوم: {$stats['active_today']}\n";
        $text .= "🛍 المنتجات: {$stats['active_products']}\n";
        $text .= "🛒 الطلبات: {$stats['total_orders']}\n";
        $text .= "💰 الإيرادات: {$stats['total_revenue']} نقطة\n";

        $this->sendMessage($this->chat_id, $text, null, 'Markdown');
    }

    private function handleBroadcast($text) {
        $message = trim(substr($text, 10));

        if (empty($message)) {
            $this->sendMessage($this->chat_id, "❌ الاستخدام:\n/broadcast رسالتك");
            return;
        }

        $conn = $this->db->getConnection();
        $stmt = $conn->query("SELECT user_id FROM users WHERE is_blocked = 0");
        $users = $stmt->fetchAll();

        $sent = 0;
        foreach ($users as $user) {
            if ($this->sendMessage($user['user_id'], "📢 *رسالة من الإدارة*\n\n$message", null, 'Markdown')) {
                $sent++;
            }
            usleep(50000); // 50ms
        }

        $this->sendMessage($this->chat_id, "✅ تم الإرسال إلى $sent مستخدم");
    }

    private function handleAddPoints($text) {
        $parts = explode(' ', $text);

        if (count($parts) < 3) {
            $this->sendMessage($this->chat_id, "❌ الاستخدام:\n/addpoints [user_id] [نقاط]");
            return;
        }

        $target_id = (int)$parts[1];
        $points = (int)$parts[2];

        if ($target_id <= 0 || $points == 0) {
            $this->sendMessage($this->chat_id, "❌ قيم غير صحيحة");
            return;
        }

        $target = $this->db->getUser($target_id);
        if (!$target) {
            $this->sendMessage($this->chat_id, "❌ المستخدم غير موجود");
            return;
        }

        if ($points > 0) {
            $this->db->addPoints($target_id, $points, 'admin_gift', 'هدية من الإدارة');
            $this->sendMessage($this->chat_id, "✅ تمت إضافة $points نقطة");
            $this->sendMessage($target_id, "🎁 حصلت على $points نقطة من الإدارة!");
        } else {
            $points = abs($points);
            if ($this->db->deductPoints($target_id, $points, 'admin_deduct', 'خصم من الإدارة')) {
                $this->sendMessage($this->chat_id, "✅ تم خصم $points نقطة");
            } else {
                $this->sendMessage($this->chat_id, "❌ رصيد غير كافٍ");
            }
        }
    }

    // Token Verification for Shortened Links
    private function handleTokenVerification($token) {
        $token = trim($token);

        if ($this->db->verifyLinkClick($token)) {
            $click = $this->db->getLinkClickByToken($token);
            $ad = $this->db->getLinkAd($click['ad_id']);

            $text = "✅ *تم التحقق بنجاح!*\n\n";
            $text .= "🎁 حصلت على *{$ad['points_reward']}* نقطة\n\n";
            $text .= "💎 إجمالي نقاطك: *" . $this->user['points'] + $ad['points_reward'] . "*\n\n";
            $text .= "استمر في كسب المزيد! 🚀";

            $this->sendMessage($this->chat_id, $text, null, 'Markdown');
        } else {
            $this->sendMessage($this->chat_id, "❌ الرمز غير صحيح أو تم استخدامه مسبقاً");
        }
    }

    // Channel Membership Check
    private function handleCheckChannels() {
        $channels = $this->db->getUnjoinedChannels($this->user_id);

        if (empty($channels)) {
            $this->sendMessage($this->chat_id, "✅ أنت مشترك في جميع القنوات المتاحة! 🎉");
            return;
        }

        $earned = 0;
        $joined = [];

        foreach ($channels as $channel) {
            try {
                $member = $this->checkChannelMembership($this->user_id, $channel['channel_id']);

                if ($member) {
                    if ($this->db->recordChannelJoin($this->user_id, $channel['id'], $channel['points_reward'])) {
                        $earned += $channel['points_reward'];
                        $joined[] = $channel['channel_title'];
                    }
                }
            } catch (Exception $e) {
                logError("Channel check error: " . $e->getMessage());
            }
        }

        if ($earned > 0) {
            $text = "🎉 *مبروك!*\n\n";
            $text .= "حصلت على *$earned* نقطة من الانضمام للقنوات:\n\n";
            foreach ($joined as $ch) {
                $text .= "✅ $ch\n";
            }
            $text .= "\n💎 إجمالي نقاطك الآن: *" . ($this->user['points'] + $earned) . "*";

            $this->sendMessage($this->chat_id, $text, null, 'Markdown');
        } else {
            $text = "📺 *قنوات متاحة للانضمام*\n\n";
            $text .= "انضم للقنوات التالية لكسب النقاط:\n\n";

            foreach ($channels as $channel) {
                $username = $channel['channel_username'] ? '@' . $channel['channel_username'] : '';
                $text .= "• {$channel['channel_title']} $username\n";
                $text .= "  💎 {$channel['points_reward']} نقطة\n\n";
            }

            $text .= "بعد الانضمام أرسل /channels مرة أخرى";

            $this->sendMessage($this->chat_id, $text, null, 'Markdown');
        }
    }

    // Get Bot Channels (Admin Only)
    private function handleGetBotChannels() {
        $result = sendRequest('getMyChannels', []);

        if ($result && isset($result['result'])) {
            $channels = $result['result'];

            if (empty($channels)) {
                $this->sendMessage($this->chat_id, "❌ البوت غير مضاف كمشرف في أي قناة");
                return;
            }

            $text = "📺 *القنوات المتاحة*\n\n";
            $text .= "القنوات التي البوت مشرف فيها:\n\n";

            foreach ($channels as $channel) {
                $text .= "• {$channel['title']}\n";
                $text .= "  ID: `{$channel['id']}`\n";
                if (isset($channel['username'])) {
                    $text .= "  @{$channel['username']}\n";
                }
                $text .= "\n";
            }

            $text .= "أضف هذه القنوات من لوحة الإدارة";

            $this->sendMessage($this->chat_id, $text, null, 'Markdown');
        } else {
            $this->sendMessage($this->chat_id, "❌ فشل جلب القنوات");
        }
    }

    // Check if user is member of channel
    private function checkChannelMembership($user_id, $channel_id) {
        $result = sendRequest('getChatMember', [
            'chat_id' => $channel_id,
            'user_id' => $user_id
        ]);

        if ($result && isset($result['result'])) {
            $status = $result['result']['status'];
            return in_array($status, ['member', 'administrator', 'creator']);
        }

        return false;
    }

    // Send notification to admin about purchase
    public function notifyAdminPurchase($user, $product, $order_id) {
        $text = "🛒 *طلب جديد!*\n\n";
        $text .= "👤 المستخدم: {$user['first_name']}";

        if (isset($user['username'])) {
            $text .= " (@{$user['username']})";
        }

        $text .= "\n🆔 ID: `{$user['user_id']}`\n\n";
        $text .= "📦 المنتج: {$product['name']}\n";
        $text .= "💰 السعر: {$product['price']} نقطة\n";
        $text .= "🔢 رقم الطلب: #{$order_id}\n\n";

        if ($product['content_type'] === 'unique') {
            $stmt = $this->db->getConnection()->prepare("SELECT COUNT(*) as count FROM product_content WHERE product_id = ? AND is_used = 0");
            $stmt->execute([$product['id']]);
            $remaining = $stmt->fetch()['count'];

            $text .= "📊 المخزون المتبقي: $remaining\n";
        } else {
            $text .= "📁 نوع المحتوى: عام (ملف)\n";
        }

        $text .= "\n🕒 " . date('Y-m-d H:i:s');

        $this->sendMessage(ADMIN_ID, $text, null, 'Markdown');
    }

    // Send file to user
    public function sendFile($chat_id, $file_id, $file_type, $caption = null) {
        $method = 'sendDocument';
        $param = 'document';

        if ($file_type === 'photo') {
            $method = 'sendPhoto';
            $param = 'photo';
        } elseif ($file_type === 'video') {
            $method = 'sendVideo';
            $param = 'video';
        } elseif ($file_type === 'audio') {
            $method = 'sendAudio';
            $param = 'audio';
        }

        $data = [
            'chat_id' => $chat_id,
            $param => $file_id
        ];

        if ($caption) {
            $data['caption'] = $caption;
        }

        return sendRequest($method, $data);
    }

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
}
