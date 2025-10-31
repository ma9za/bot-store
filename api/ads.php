<?php
/**
 * Ads API Integration
 * Handles CPAGrip and Shorte.st APIs
 */

class AdsAPI {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * CPAGrip API Integration
     * للحصول على محتوى إعلاني (فيديوهات، صفحات)
     */
    public function getCPAGripOffers() {
        $api_key = $this->db->getSetting('cpagrip_api_key');
        $user_id = $this->db->getSetting('cpagrip_user_id');

        if (empty($api_key) || empty($user_id)) {
            return ['success' => false, 'error' => 'CPAGrip API not configured'];
        }

        // CPAGrip API endpoint
        $url = CPAGRIP_API_URL . "publisher/offers.php?user_id=$user_id&api_key=$api_key";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            logError("CPAGrip API Error: " . $error);
            return ['success' => false, 'error' => $error];
        }

        $data = json_decode($result, true);

        if (!$data || !isset($data['offers'])) {
            return ['success' => false, 'error' => 'Invalid API response'];
        }

        return ['success' => true, 'offers' => $data['offers']];
    }

    /**
     * Generate CPAGrip tracking link
     */
    public function generateCPAGripLink($offer_id, $user_id) {
        $api_key = $this->db->getSetting('cpagrip_api_key');
        $cpa_user_id = $this->db->getSetting('cpagrip_user_id');

        // Generate unique tracking link
        $link = CPAGRIP_API_URL . "link.php?user_id=$cpa_user_id&api_key=$api_key&offer_id=$offer_id&sub_id=$user_id";

        return $link;
    }

    /**
     * Shorte.st API Integration
     * لاختصار الروابط مع الإعلانات
     */
    public function shortenLink($url, $user_id) {
        $api_key = $this->db->getSetting('shortest_api_key');

        if (empty($api_key)) {
            return ['success' => false, 'error' => 'Shorte.st API not configured'];
        }

        // Add custom alias for tracking
        $alias = 'usr' . $user_id . '_' . substr(md5($url . time()), 0, 6);

        $api_url = SHORTEST_API_URL . "shorten?token=$api_key";

        $data = json_encode([
            'url' => $url,
            'alias' => $alias
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            logError("Shorte.st API Error: " . $error);
            return ['success' => false, 'error' => $error];
        }

        $response = json_decode($result, true);

        if ($http_code !== 200 || !isset($response['shortenedUrl'])) {
            $error_msg = $response['error'] ?? 'Failed to shorten link';
            logError("Shorte.st API Error: " . $error_msg);
            return ['success' => false, 'error' => $error_msg];
        }

        return [
            'success' => true,
            'shortened_url' => $response['shortenedUrl'],
            'alias' => $alias
        ];
    }

    /**
     * Get Shorte.st statistics
     */
    public function getShortestStats($alias = null) {
        $api_key = $this->db->getSetting('shortest_api_key');

        if (empty($api_key)) {
            return ['success' => false, 'error' => 'Shorte.st API not configured'];
        }

        $url = SHORTEST_API_URL . "stats?token=$api_key";
        if ($alias) {
            $url .= "&alias=$alias";
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            logError("Shorte.st Stats Error: " . $error);
            return ['success' => false, 'error' => $error];
        }

        $data = json_decode($result, true);

        return ['success' => true, 'stats' => $data];
    }

    /**
     * Verify ad completion via webhook/callback
     * هذه الدالة تُستدعى من webhook الخاص بموقع الإعلانات
     */
    public function verifyAdCompletion($provider, $data) {
        if ($provider === 'cpagrip') {
            return $this->verifyCPAGripCompletion($data);
        } elseif ($provider === 'shortest') {
            return $this->verifyShortestCompletion($data);
        }

        return false;
    }

    private function verifyCPAGripCompletion($data) {
        // Verify CPAGrip callback signature
        $api_key = $this->db->getSetting('cpagrip_api_key');

        // Extract user_id from sub_id
        $user_id = $data['sub_id'] ?? null;
        $offer_id = $data['offer_id'] ?? null;
        $payout = $data['payout'] ?? 0;

        if (!$user_id || !$offer_id) {
            return false;
        }

        // Verify signature (if CPAGrip provides one)
        // $expected_signature = hash_hmac('sha256', $data['transaction_id'], $api_key);
        // if ($expected_signature !== $data['signature']) {
        //     return false;
        // }

        // Get the ad from database
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("SELECT * FROM ads WHERE type = 'video' AND url LIKE ? LIMIT 1");
        $stmt->execute(["%offer_id=$offer_id%"]);
        $ad = $stmt->fetch();

        if ($ad) {
            // Complete the ad view
            $this->db->completeAdView($user_id, $ad['id']);
            return true;
        }

        return false;
    }

    private function verifyShortestCompletion($data) {
        // Verify Shorte.st callback
        $alias = $data['alias'] ?? null;
        $clicks = $data['clicks'] ?? 0;

        if (!$alias || $clicks === 0) {
            return false;
        }

        // Extract user_id from alias
        if (preg_match('/usr(\d+)_/', $alias, $matches)) {
            $user_id = $matches[1];

            // Find the ad with this alias
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare("SELECT * FROM ads WHERE type = 'link' AND url LIKE ? LIMIT 1");
            $stmt->execute(["%$alias%"]);
            $ad = $stmt->fetch();

            if ($ad) {
                // Complete the ad view
                $this->db->completeAdView($user_id, $ad['id']);
                return true;
            }
        }

        return false;
    }

    /**
     * Create ad from CPAGrip offer
     */
    public function createAdFromCPAGripOffer($offer, $points_reward) {
        $conn = $this->db->getConnection();

        $user_id = $this->db->getSetting('cpagrip_user_id');
        $api_key = $this->db->getSetting('cpagrip_api_key');

        $url = $this->generateCPAGripLink($offer['id'], '{user_id}');

        $stmt = $conn->prepare("INSERT INTO ads (type, title, description, url, points_reward, api_provider, is_active)
                               VALUES (?, ?, ?, ?, ?, ?, ?)");

        return $stmt->execute([
            'video',
            $offer['name'],
            $offer['description'] ?? '',
            $url,
            $points_reward,
            'cpagrip',
            1
        ]);
    }

    /**
     * Create ad from shortened link
     */
    public function createAdFromShortLink($title, $description, $original_url, $user_id, $points_reward) {
        $result = $this->shortenLink($original_url, $user_id);

        if (!$result['success']) {
            return $result;
        }

        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("INSERT INTO ads (type, title, description, url, points_reward, api_provider, is_active)
                               VALUES (?, ?, ?, ?, ?, ?, ?)");

        $success = $stmt->execute([
            'link',
            $title,
            $description,
            $result['shortened_url'],
            $points_reward,
            'shortest',
            1
        ]);

        if ($success) {
            return [
                'success' => true,
                'ad_id' => $conn->lastInsertId(),
                'shortened_url' => $result['shortened_url']
            ];
        }

        return ['success' => false, 'error' => 'Failed to create ad'];
    }

    /**
     * Auto-complete ad after delay (fallback method)
     * يُستخدم في حالة عدم وجود webhook من موقع الإعلانات
     */
    public function scheduleAdCompletion($user_id, $ad_id, $delay_seconds = 30) {
        // في بيئة الإنتاج، يُفضل استخدام cron job أو queue system
        // هنا نستخدم طريقة بسيطة للتجربة

        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("INSERT INTO ad_views (user_id, ad_id, completed, completed_at)
                               VALUES (?, ?, 0, datetime('now', '+$delay_seconds seconds'))
                               ON CONFLICT DO NOTHING");

        return $stmt->execute([$user_id, $ad_id]);
    }
}
