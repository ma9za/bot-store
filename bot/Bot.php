<?php
/**
 * Telegram Bot Handler - Simple Version
 * كل شيء يعمل من خلال Mini App
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

        // Process referral bonus
        if (isset($user_data['referred_by'])) {
            $this->db->createReferral($user_data['referred_by'], $this->user_id);
            $points = $this->db->getSetting('points_per_referral');
            $this->sendMessage($user_data['referred_by'], "🎉 مبروك! انضم صديقك وحصلت على $points نقطة!");
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

        if ($text === '/start' || strpos($text, '/start') === 0) {
            $this->handleStart();
        } elseif ($text === '/help') {
            $this->handleHelp();
        } elseif (isAdmin($this->user_id)) {
            if (strpos($text, '/broadcast') === 0) {
                $this->handleBroadcast($text);
            } elseif (strpos($text, '/addpoints') === 0) {
                $this->handleAddPoints($text);
            } elseif ($text === '/stats') {
                $this->handleStats();
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
        $text .= "👥 ادعُ أصدقاءك للحصول على نقاط إضافية\n\n";
        $text .= "كل شيء متاح من المتجر! 🚀";

        $this->sendMessage($this->chat_id, $text, null, 'Markdown');
    }

    private function handleHelp() {
        $text = "📚 *دليل الاستخدام*\n\n";
        $text .= "🛍 افتح المتجر من الزر بالأسفل للوصول إلى:\n";
        $text .= "• تصفح وشراء المنتجات\n";
        $text .= "• كسب النقاط\n";
        $text .= "• دعوة الأصدقاء\n";
        $text .= "• عرض إحصائياتك\n\n";

        if (isAdmin($this->user_id)) {
            $text .= "⚙️ *أوامر المشرف*:\n";
            $text .= "/stats - الإحصائيات\n";
            $text .= "/broadcast [رسالة] - إرسال جماعي\n";
            $text .= "/addpoints [user_id] [نقاط] - إضافة نقاط\n";
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
