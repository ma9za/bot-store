<?php
/**
 * Admin Panel
 * Simple web-based administration interface
 */

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database/Database.php';

// Simple authentication
$admin_password = 'admin123'; // Change this!

if (isset($_POST['login'])) {
    if ($_POST['password'] === $admin_password) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'كلمة مرور خاطئة';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

if (!isset($_SESSION['admin_logged_in'])) {
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>تسجيل الدخول - لوحة الإدارة</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .login-box {
                background: white;
                padding: 40px;
                border-radius: 20px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                width: 100%;
                max-width: 400px;
            }
            h1 {
                text-align: center;
                color: #667eea;
                margin-bottom: 30px;
                font-size: 2rem;
            }
            .form-group {
                margin-bottom: 20px;
            }
            label {
                display: block;
                margin-bottom: 8px;
                color: #333;
                font-weight: 600;
            }
            input[type="password"] {
                width: 100%;
                padding: 12px;
                border: 2px solid #e0e0e0;
                border-radius: 10px;
                font-size: 1rem;
                transition: border-color 0.3s;
            }
            input[type="password"]:focus {
                outline: none;
                border-color: #667eea;
            }
            button {
                width: 100%;
                padding: 14px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 10px;
                font-size: 1.1rem;
                font-weight: 600;
                cursor: pointer;
                transition: transform 0.2s;
            }
            button:hover {
                transform: translateY(-2px);
            }
            button:active {
                transform: translateY(0);
            }
            .error {
                background: #ff3b30;
                color: white;
                padding: 12px;
                border-radius: 10px;
                margin-bottom: 20px;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>🔐 لوحة الإدارة</h1>
            <?php if (isset($error)): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label for="password">كلمة المرور</label>
                    <input type="password" id="password" name="password" required autofocus>
                </div>
                <button type="submit" name="login">تسجيل الدخول</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Handle actions
$db = Database::getInstance();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add_product':
            $name = $_POST['name'] ?? '';
            $description = $_POST['description'] ?? '';
            $price = (int)($_POST['price'] ?? 0);
            $stock = (int)($_POST['stock'] ?? -1);
            $max_per_user = (int)($_POST['max_per_user'] ?? 1);

            if ($name && $price > 0) {
                $conn = $db->getConnection();
                $stmt = $conn->prepare("INSERT INTO products (name, description, price, stock_quantity, max_per_user)
                                       VALUES (?, ?, ?, ?, ?)");
                if ($stmt->execute([$name, $description, $price, $stock, $max_per_user])) {
                    $message = '✅ تمت إضافة المنتج بنجاح';
                } else {
                    $message = '❌ فشل إضافة المنتج';
                }
            }
            break;

        case 'add_product_content':
            $product_id = (int)($_POST['product_id'] ?? 0);
            $content = $_POST['content'] ?? '';

            if ($product_id && $content) {
                $contents = explode("\n", trim($content));
                $conn = $db->getConnection();
                $stmt = $conn->prepare("INSERT INTO product_content (product_id, content) VALUES (?, ?)");
                $added = 0;
                foreach ($contents as $line) {
                    $line = trim($line);
                    if ($line) {
                        $stmt->execute([$product_id, $line]);
                        $added++;
                    }
                }
                $message = "✅ تمت إضافة $added محتوى للمنتج";
            }
            break;

        case 'add_ad':
            $type = $_POST['type'] ?? 'link';
            $title = $_POST['title'] ?? '';
            $url = $_POST['url'] ?? '';
            $points = (int)($_POST['points'] ?? 0);
            $description = $_POST['ad_description'] ?? '';

            if ($title && $url && $points > 0) {
                $conn = $db->getConnection();
                $stmt = $conn->prepare("INSERT INTO ads (type, title, description, url, points_reward) VALUES (?, ?, ?, ?, ?)");
                if ($stmt->execute([$type, $title, $description, $url, $points])) {
                    $message = '✅ تمت إضافة الإعلان بنجاح';
                } else {
                    $message = '❌ فشل إضافة الإعلان';
                }
            }
            break;

        case 'update_points':
            $user_id = (int)($_POST['user_id'] ?? 0);
            $points = (int)($_POST['points_amount'] ?? 0);
            $operation = $_POST['operation'] ?? 'add';

            if ($user_id && $points != 0) {
                if ($operation === 'add') {
                    $db->addPoints($user_id, $points, 'admin_adjust', 'تعديل من الإدارة');
                    $message = "✅ تمت إضافة $points نقطة للمستخدم";
                } else {
                    $db->deductPoints($user_id, $points, 'admin_adjust', 'خصم من الإدارة');
                    $message = "✅ تم خصم $points نقطة من المستخدم";
                }
            }
            break;

        case 'update_settings':
            $settings = [
                'points_per_video_ad' => (int)($_POST['points_per_video_ad'] ?? 10),
                'points_per_link_ad' => (int)($_POST['points_per_link_ad'] ?? 5),
                'points_per_referral' => (int)($_POST['points_per_referral'] ?? 20),
                'cpagrip_api_key' => $_POST['cpagrip_api_key'] ?? '',
                'shortest_api_key' => $_POST['shortest_api_key'] ?? '',
                'welcome_message' => $_POST['welcome_message'] ?? ''
            ];

            foreach ($settings as $key => $value) {
                $db->updateSetting($key, $value);
            }
            $message = '✅ تم تحديث الإعدادات';
            break;
    }
}

// Get data
$stats = $db->getStats();
$products = $db->getProducts(false);
$ads = $db->getConnection()->query("SELECT * FROM ads ORDER BY created_at DESC")->fetchAll();

$conn = $db->getConnection();
$recent_users = $conn->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 10")->fetchAll();
$recent_orders = $conn->query("SELECT o.*, u.username, u.first_name FROM orders o
                               LEFT JOIN users u ON o.user_id = u.user_id
                               ORDER BY o.created_at DESC LIMIT 10")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة الإدارة</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        h1 {
            font-size: 1.8rem;
        }
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .stat-card h3 {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 10px;
        }
        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
        }
        .section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .section h2 {
            margin-bottom: 20px;
            color: #667eea;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        input[type="text"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            font-family: inherit;
        }
        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: #667eea;
        }
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-success {
            background: #34c759;
            color: white;
        }
        .btn-danger {
            background: #ff3b30;
            color: white;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .btn:active {
            transform: translateY(0);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: right;
            border-bottom: 1px solid #e0e0e0;
        }
        th {
            background: #f8f8f8;
            font-weight: 600;
            color: #667eea;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        .message {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-weight: 600;
            color: #666;
            transition: all 0.3s;
        }
        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>⚙️ لوحة الإدارة</h1>
            <a href="?logout=1" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> تسجيل الخروج
            </a>
        </div>
    </div>

    <div class="container">
        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, '✅') !== false ? 'success' : 'error'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3><i class="fas fa-users"></i> إجمالي المستخدمين</h3>
                <div class="value"><?php echo $stats['total_users']; ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-user-check"></i> نشطين اليوم</h3>
                <div class="value"><?php echo $stats['active_today']; ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-shopping-cart"></i> إجمالي الطلبات</h3>
                <div class="value"><?php echo $stats['total_orders']; ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-box"></i> المنتجات النشطة</h3>
                <div class="value"><?php echo $stats['active_products']; ?></div>
            </div>
        </div>

        <!-- Main Content Tabs -->
        <div class="section">
            <div class="tabs">
                <button class="tab active" onclick="switchTab('products')">المنتجات</button>
                <button class="tab" onclick="switchTab('ads')">الإعلانات</button>
                <button class="tab" onclick="switchTab('users')">المستخدمين</button>
                <button class="tab" onclick="switchTab('orders')">الطلبات</button>
                <button class="tab" onclick="switchTab('settings')">الإعدادات</button>
            </div>

            <!-- Products Tab -->
            <div id="products" class="tab-content active">
                <div class="grid-2">
                    <div>
                        <h2><i class="fas fa-plus"></i> إضافة منتج</h2>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_product">
                            <div class="form-group">
                                <label>اسم المنتج</label>
                                <input type="text" name="name" required>
                            </div>
                            <div class="form-group">
                                <label>الوصف</label>
                                <textarea name="description"></textarea>
                            </div>
                            <div class="form-group">
                                <label>السعر (نقطة)</label>
                                <input type="number" name="price" required min="1">
                            </div>
                            <div class="form-group">
                                <label>الكمية (-1 لـ غير محدود)</label>
                                <input type="number" name="stock" value="-1">
                            </div>
                            <div class="form-group">
                                <label>الحد الأقصى لكل مستخدم</label>
                                <input type="number" name="max_per_user" value="1" min="1">
                            </div>
                            <button type="submit" class="btn btn-primary">إضافة المنتج</button>
                        </form>
                    </div>

                    <div>
                        <h2><i class="fas fa-file-alt"></i> إضافة محتوى لمنتج</h2>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_product_content">
                            <div class="form-group">
                                <label>اختر المنتج</label>
                                <select name="product_id" required>
                                    <option value="">-- اختر --</option>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo $p['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>المحتوى (سطر واحد لكل محتوى)</label>
                                <textarea name="content" required placeholder="كود1&#10;كود2&#10;كود3"></textarea>
                            </div>
                            <button type="submit" class="btn btn-success">إضافة المحتوى</button>
                        </form>
                    </div>
                </div>

                <h2 style="margin-top: 30px;"><i class="fas fa-list"></i> المنتجات الحالية</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>الاسم</th>
                            <th>السعر</th>
                            <th>المبيعات</th>
                            <th>الكمية</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td><?php echo $p['id']; ?></td>
                                <td><?php echo $p['name']; ?></td>
                                <td><?php echo $p['price']; ?> نقطة</td>
                                <td><?php echo $p['sales_count']; ?></td>
                                <td><?php echo $p['stock_quantity'] == -1 ? '∞' : $p['stock_quantity']; ?></td>
                                <td>
                                    <span class="badge <?php echo $p['is_active'] ? 'badge-success' : 'badge-danger'; ?>">
                                        <?php echo $p['is_active'] ? 'نشط' : 'معطل'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Ads Tab -->
            <div id="ads" class="tab-content">
                <h2><i class="fas fa-plus"></i> إضافة إعلان</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="add_ad">
                    <div class="grid-2">
                        <div class="form-group">
                            <label>النوع</label>
                            <select name="type" required>
                                <option value="video">فيديو</option>
                                <option value="link">رابط</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>النقاط المكتسبة</label>
                            <input type="number" name="points" required min="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>العنوان</label>
                        <input type="text" name="title" required>
                    </div>
                    <div class="form-group">
                        <label>الوصف</label>
                        <textarea name="ad_description"></textarea>
                    </div>
                    <div class="form-group">
                        <label>رابط الإعلان</label>
                        <input type="text" name="url" required>
                    </div>
                    <button type="submit" class="btn btn-primary">إضافة الإعلان</button>
                </form>

                <h2 style="margin-top: 30px;"><i class="fas fa-list"></i> الإعلانات الحالية</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>العنوان</th>
                            <th>النوع</th>
                            <th>النقاط</th>
                            <th>المشاهدات</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ads as $ad): ?>
                            <tr>
                                <td><?php echo $ad['id']; ?></td>
                                <td><?php echo $ad['title']; ?></td>
                                <td><?php echo $ad['type'] === 'video' ? '🎥 فيديو' : '🔗 رابط'; ?></td>
                                <td><?php echo $ad['points_reward']; ?></td>
                                <td><?php echo $ad['view_count']; ?></td>
                                <td>
                                    <span class="badge <?php echo $ad['is_active'] ? 'badge-success' : 'badge-danger'; ?>">
                                        <?php echo $ad['is_active'] ? 'نشط' : 'معطل'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Users Tab -->
            <div id="users" class="tab-content">
                <h2><i class="fas fa-user-edit"></i> تعديل نقاط مستخدم</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="update_points">
                    <div class="grid-2">
                        <div class="form-group">
                            <label>معرف المستخدم (User ID)</label>
                            <input type="number" name="user_id" required>
                        </div>
                        <div class="form-group">
                            <label>كمية النقاط</label>
                            <input type="number" name="points_amount" required min="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>العملية</label>
                        <select name="operation" required>
                            <option value="add">إضافة</option>
                            <option value="deduct">خصم</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">تحديث النقاط</button>
                </form>

                <h2 style="margin-top: 30px;"><i class="fas fa-users"></i> المستخدمون الأخيرون</h2>
                <table>
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>الاسم</th>
                            <th>اسم المستخدم</th>
                            <th>النقاط</th>
                            <th>الدعوات</th>
                            <th>تاريخ التسجيل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_users as $user): ?>
                            <tr>
                                <td><?php echo $user['user_id']; ?></td>
                                <td><?php echo $user['first_name']; ?></td>
                                <td><?php echo $user['username'] ? '@' . $user['username'] : '-'; ?></td>
                                <td><?php echo $user['points']; ?></td>
                                <td><?php echo $user['referral_code']; ?></td>
                                <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Orders Tab -->
            <div id="orders" class="tab-content">
                <h2><i class="fas fa-shopping-cart"></i> آخر الطلبات</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>المستخدم</th>
                            <th>المنتج</th>
                            <th>النقاط</th>
                            <th>التاريخ</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_orders as $order): ?>
                            <tr>
                                <td>#<?php echo $order['id']; ?></td>
                                <td><?php echo $order['first_name'] . ($order['username'] ? ' @' . $order['username'] : ''); ?></td>
                                <td><?php echo $order['product_name']; ?></td>
                                <td><?php echo $order['points_spent']; ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?></td>
                                <td><span class="badge badge-success"><?php echo $order['status']; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Settings Tab -->
            <div id="settings" class="tab-content">
                <h2><i class="fas fa-cog"></i> إعدادات البوت</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="update_settings">

                    <h3 style="margin-bottom: 15px;">⚙️ إعدادات النقاط</h3>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>نقاط الإعلان (فيديو)</label>
                            <input type="number" name="points_per_video_ad" value="<?php echo $db->getSetting('points_per_video_ad'); ?>" min="1">
                        </div>
                        <div class="form-group">
                            <label>نقاط الإعلان (رابط)</label>
                            <input type="number" name="points_per_link_ad" value="<?php echo $db->getSetting('points_per_link_ad'); ?>" min="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>نقاط الدعوة</label>
                        <input type="number" name="points_per_referral" value="<?php echo $db->getSetting('points_per_referral'); ?>" min="1">
                    </div>

                    <h3 style="margin: 30px 0 15px;">🔑 إعدادات APIs</h3>
                    <div class="form-group">
                        <label>CPAGrip API Key</label>
                        <input type="text" name="cpagrip_api_key" value="<?php echo $db->getSetting('cpagrip_api_key'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Shorte.st API Key</label>
                        <input type="text" name="shortest_api_key" value="<?php echo $db->getSetting('shortest_api_key'); ?>">
                    </div>

                    <h3 style="margin: 30px 0 15px;">💬 رسائل البوت</h3>
                    <div class="form-group">
                        <label>رسالة الترحيب</label>
                        <textarea name="welcome_message"><?php echo $db->getSetting('welcome_message'); ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">💾 حفظ الإعدادات</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }
    </script>
</body>
</html>
