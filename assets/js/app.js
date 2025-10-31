/**
 * Telegram Mini App - Store Application
 */

// Telegram Web App initialization
const tg = window.Telegram.WebApp;
tg.expand();
tg.enableClosingConfirmation();

// API Configuration
const API_URL = '../api/api.php';
let currentUser = null;
let products = [];
let ads = [];

// Initialize app
document.addEventListener('DOMContentLoaded', async () => {
    try {
        // Apply Telegram theme
        applyTelegramTheme();

        // Get user from Telegram
        const telegramUser = tg.initDataUnsafe?.user;

        if (telegramUser) {
            await loadUser(telegramUser.id);
        } else {
            // For testing outside Telegram
            console.warn('Running outside Telegram environment');
            await loadUser(7732118455); // Admin ID for testing
        }

        // Load initial data
        await Promise.all([
            loadProducts(),
            loadSettings()
        ]);

        // Setup event listeners
        setupEventListeners();

        // Hide loading, show app
        document.getElementById('loading').style.display = 'none';
        document.getElementById('app').style.display = 'block';

    } catch (error) {
        console.error('Initialization error:', error);
        showToast('حدث خطأ أثناء تحميل التطبيق', 'error');
    }
});

// Apply Telegram theme colors
function applyTelegramTheme() {
    const root = document.documentElement;

    if (tg.themeParams) {
        if (tg.themeParams.bg_color) root.style.setProperty('--tg-theme-bg-color', tg.themeParams.bg_color);
        if (tg.themeParams.text_color) root.style.setProperty('--tg-theme-text-color', tg.themeParams.text_color);
        if (tg.themeParams.hint_color) root.style.setProperty('--tg-theme-hint-color', tg.themeParams.hint_color);
        if (tg.themeParams.link_color) root.style.setProperty('--tg-theme-link-color', tg.themeParams.link_color);
        if (tg.themeParams.button_color) root.style.setProperty('--tg-theme-button-color', tg.themeParams.button_color);
        if (tg.themeParams.button_text_color) root.style.setProperty('--tg-theme-button-text-color', tg.themeParams.button_text_color);
        if (tg.themeParams.secondary_bg_color) root.style.setProperty('--tg-theme-secondary-bg-color', tg.themeParams.secondary_bg_color);
    }
}

// API Functions
async function apiRequest(action, params = {}, method = 'GET') {
    const url = new URL(API_URL, window.location.href);
    url.searchParams.append('action', action);

    // Add Telegram Web App data for authentication
    if (tg.initData) {
        url.searchParams.append('_auth', tg.initData);
    }

    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json'
        }
    };

    if (method === 'POST') {
        options.body = JSON.stringify({ ...params, _auth: tg.initData });
    } else {
        Object.keys(params).forEach(key => url.searchParams.append(key, params[key]));
    }

    const response = await fetch(url, options);
    const data = await response.json();

    if (!data.success) {
        throw new Error(data.error || 'Unknown error');
    }

    return data;
}

// Load user data
async function loadUser(userId) {
    try {
        const data = await apiRequest('get_user', { user_id: userId });
        currentUser = data.user;

        // Update UI
        document.getElementById('userName').textContent = currentUser.first_name || 'مستخدم';
        document.getElementById('userPoints').textContent = currentUser.points;
        document.getElementById('profileName').textContent = currentUser.first_name || 'مستخدم';
        document.getElementById('profilePoints').textContent = currentUser.points;
        document.getElementById('totalEarned').textContent = currentUser.total_earned;
        document.getElementById('totalSpent').textContent = currentUser.total_spent;
        document.getElementById('totalReferrals').textContent = currentUser.referral_stats.total;
        document.getElementById('profileReferrals').textContent = currentUser.referral_stats.total;
        document.getElementById('referralEarnings').textContent = currentUser.referral_stats.total_points;

    } catch (error) {
        console.error('Error loading user:', error);
        throw error;
    }
}

// Load products
async function loadProducts() {
    try {
        const data = await apiRequest('get_products');
        products = data.products;
        renderProducts(products);

        // Load orders for profile
        await loadOrders();

    } catch (error) {
        console.error('Error loading products:', error);
        document.getElementById('noProducts').style.display = 'block';
    }
}

// Render products
function renderProducts(productsToRender) {
    const container = document.getElementById('productsContainer');
    container.innerHTML = '';

    if (productsToRender.length === 0) {
        document.getElementById('noProducts').style.display = 'block';
        return;
    }

    document.getElementById('noProducts').style.display = 'none';

    productsToRender.forEach(product => {
        const card = createProductCard(product);
        container.appendChild(card);
    });
}

// Create product card
function createProductCard(product) {
    const card = document.createElement('div');
    card.className = 'product-card' + (product.is_offer ? ' offer' : '');
    card.onclick = () => openProductModal(product);

    const imageHTML = product.image_url
        ? `<img src="${product.image_url}" alt="${product.name}">`
        : '<i class="fas fa-box"></i>';

    const priceHTML = product.is_offer && product.offer_price
        ? `
            <div class="price-current">
                <i class="fas fa-gem"></i>
                ${product.final_price}
                <span class="discount-badge">-${product.discount_percentage}%</span>
            </div>
            <div class="price-old">${product.price} نقطة</div>
        `
        : `
            <div class="price-current">
                <i class="fas fa-gem"></i>
                ${product.price}
            </div>
        `;

    card.innerHTML = `
        <div class="product-image">${imageHTML}</div>
        <div class="product-info">
            <h3 class="product-name">${product.name}</h3>
            <p class="product-description">${product.description || 'منتج رقمي عالي الجودة'}</p>
            <div class="product-footer">
                <div class="product-price">${priceHTML}</div>
                <button class="product-buy-btn" onclick="event.stopPropagation(); openProductModal(${JSON.stringify(product).replace(/"/g, '&quot;')})">
                    <i class="fas fa-shopping-cart"></i>
                    شراء
                </button>
            </div>
        </div>
    `;

    return card;
}

// Open product modal
function openProductModal(product) {
    const modal = document.getElementById('productModal');
    const content = document.getElementById('productModalContent');

    const imageHTML = product.image_url
        ? `<img src="${product.image_url}" alt="${product.name}" style="width: 100%; height: 200px; object-fit: cover; border-radius: 12px;">`
        : '<div style="width: 100%; height: 200px; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 4rem;"><i class="fas fa-box"></i></div>';

    const priceInfo = product.is_offer && product.offer_price
        ? `
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span style="font-size: 2rem; font-weight: 700; color: var(--primary-color);">
                    <i class="fas fa-gem"></i> ${product.final_price}
                </span>
                <span style="font-size: 1.2rem; color: var(--tg-theme-hint-color); text-decoration: line-through;">
                    ${product.price}
                </span>
                <span style="background: var(--danger-color); color: white; padding: 4px 8px; border-radius: 6px; font-size: 0.85rem; font-weight: 600;">
                    -${product.discount_percentage}%
                </span>
            </div>
        `
        : `
            <div style="font-size: 2rem; font-weight: 700; color: var(--primary-color); margin-bottom: 10px;">
                <i class="fas fa-gem"></i> ${product.price}
            </div>
        `;

    const stockInfo = product.stock_quantity === -1
        ? '<span style="color: var(--success-color);"><i class="fas fa-check-circle"></i> متوفر</span>'
        : product.stock_quantity > 0
            ? `<span style="color: var(--success-color);"><i class="fas fa-check-circle"></i> متوفر (${product.stock_quantity} قطعة)</span>`
            : '<span style="color: var(--danger-color);"><i class="fas fa-times-circle"></i> نفذت الكمية</span>';

    const canPurchase = currentUser.points >= product.final_price && (product.stock_quantity === -1 || product.stock_quantity > 0);

    content.innerHTML = `
        <div style="padding: 20px;">
            ${imageHTML}
            <h2 style="font-size: 1.5rem; font-weight: 700; margin: 20px 0 10px;">${product.name}</h2>
            <p style="color: var(--tg-theme-hint-color); margin-bottom: 20px;">${product.description || 'منتج رقمي عالي الجودة'}</p>

            ${priceInfo}

            <div style="display: flex; justify-content: space-between; padding: 15px; background: var(--tg-theme-secondary-bg-color); border-radius: 12px; margin-bottom: 20px;">
                <div>
                    <div style="font-size: 0.85rem; color: var(--tg-theme-hint-color); margin-bottom: 5px;">رصيدك</div>
                    <div style="font-size: 1.2rem; font-weight: 600;">
                        <i class="fas fa-gem"></i> ${currentUser.points}
                    </div>
                </div>
                <div>
                    <div style="font-size: 0.85rem; color: var(--tg-theme-hint-color); margin-bottom: 5px;">الحالة</div>
                    <div style="font-size: 1rem; font-weight: 600;">
                        ${stockInfo}
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 10px;">
                <button
                    onclick="closeProductModal()"
                    style="flex: 1; padding: 15px; border: 2px solid var(--tg-theme-secondary-bg-color); background: transparent; color: var(--tg-theme-text-color); border-radius: 12px; cursor: pointer; font-weight: 600; font-size: 1rem;"
                >
                    إلغاء
                </button>
                <button
                    onclick="purchaseProduct(${product.id})"
                    ${!canPurchase ? 'disabled' : ''}
                    style="flex: 2; padding: 15px; border: none; background: ${canPurchase ? 'var(--gradient-primary)' : 'var(--tg-theme-hint-color)'}; color: white; border-radius: 12px; cursor: ${canPurchase ? 'pointer' : 'not-allowed'}; font-weight: 600; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 10px;"
                >
                    <i class="fas fa-shopping-cart"></i>
                    ${canPurchase ? 'تأكيد الشراء' : 'رصيد غير كافٍ'}
                </button>
            </div>

            ${!canPurchase && currentUser.points < product.final_price ? `
                <div style="margin-top: 15px; padding: 12px; background: rgba(255, 149, 0, 0.1); border-radius: 12px; text-align: center;">
                    <i class="fas fa-info-circle" style="color: var(--warning-color);"></i>
                    <span style="color: var(--warning-color); font-weight: 500;">
                        تحتاج إلى ${product.final_price - currentUser.points} نقطة إضافية
                    </span>
                </div>
            ` : ''}
        </div>
    `;

    modal.classList.add('active');
}

// Close product modal
function closeProductModal() {
    document.getElementById('productModal').classList.remove('active');
}

// Purchase product
async function purchaseProduct(productId) {
    try {
        tg.showConfirm('هل أنت متأكد من الشراء؟', async (confirmed) => {
            if (confirmed) {
                showToast('جاري معالجة الطلب...', 'info');

                const data = await apiRequest('purchase', {
                    user_id: currentUser.user_id,
                    product_id: productId
                }, 'POST');

                if (data.success) {
                    closeProductModal();

                    // Show success with product content
                    tg.showAlert(`✅ تم الشراء بنجاح!\n\n📦 محتوى المنتج:\n${data.content}\n\n💎 رصيدك المتبقي: ${data.new_balance || (currentUser.points - data.points_spent)} نقطة`);

                    // Reload user data
                    await loadUser(currentUser.user_id);
                    await loadProducts();

                    showToast('تم الشراء بنجاح!', 'success');
                } else {
                    showToast(data.error || 'فشل الشراء', 'error');
                }
            }
        });

    } catch (error) {
        console.error('Purchase error:', error);
        showToast('حدث خطأ أثناء الشراء: ' + error.message, 'error');
    }
}

// Load settings
async function loadSettings() {
    try {
        const data = await apiRequest('get_settings');
        const settings = data.settings;

        // Update points rewards in UI
        if (settings.points_per_video_ad) {
            document.getElementById('videoAdPoints').textContent = settings.points_per_video_ad;
        }
        if (settings.points_per_link_ad) {
            document.getElementById('linkAdPoints').textContent = settings.points_per_link_ad;
        }
        if (settings.points_per_referral) {
            document.getElementById('referralPoints').textContent = settings.points_per_referral;
        }

    } catch (error) {
        console.error('Error loading settings:', error);
    }
}

// Load ads
async function loadAds() {
    try {
        const data = await apiRequest('get_ads');
        ads = data.ads;
        return ads;
    } catch (error) {
        console.error('Error loading ads:', error);
        return [];
    }
}

// Show ads section
async function showAds(type) {
    const adsSection = document.getElementById('adsSection');
    const container = document.getElementById('adsContainer');

    container.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i></div>';
    adsSection.style.display = 'block';

    try {
        await loadAds();
        const filteredAds = type ? ads.filter(ad => ad.type === type) : ads;

        if (filteredAds.length === 0) {
            container.innerHTML = '<div class="empty-state"><i class="fas fa-ad"></i><p>لا توجد إعلانات متاحة حالياً</p></div>';
            return;
        }

        container.innerHTML = '';
        filteredAds.forEach(ad => {
            const adItem = createAdItem(ad);
            container.appendChild(adItem);
        });

    } catch (error) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>حدث خطأ أثناء تحميل الإعلانات</p></div>';
    }
}

// Create ad item
function createAdItem(ad) {
    const item = document.createElement('div');
    item.className = 'ad-item';
    item.onclick = () => openAd(ad);

    const icon = ad.type === 'video' ? 'fa-video' : 'fa-link';

    item.innerHTML = `
        <div class="ad-icon">
            <i class="fas ${icon}"></i>
        </div>
        <div class="ad-content">
            <div class="ad-title">${ad.title}</div>
            <div class="ad-reward">
                <i class="fas fa-gem"></i>
                +${ad.points_reward} نقطة
            </div>
        </div>
        <i class="fas fa-chevron-left" style="color: var(--tg-theme-hint-color);"></i>
    `;

    return item;
}

// Open ad
function openAd(ad) {
    tg.showConfirm(`مشاهدة: ${ad.title}\n\nستحصل على ${ad.points_reward} نقطة بعد إكمال المشاهدة`, (confirmed) => {
        if (confirmed) {
            // Record ad view
            apiRequest('record_ad_view', {
                user_id: currentUser.user_id,
                ad_id: ad.id
            }, 'POST');

            // Open ad URL
            tg.openLink(ad.url);

            // Simulate completion (في الواقع يجب استخدام webhook من API الإعلانات)
            setTimeout(async () => {
                try {
                    const result = await apiRequest('complete_ad_view', {
                        user_id: currentUser.user_id,
                        ad_id: ad.id
                    }, 'POST');

                    if (result.success) {
                        showToast(`تم إضافة ${result.points_earned} نقطة!`, 'success');
                        await loadUser(currentUser.user_id);
                    }
                } catch (error) {
                    console.error('Error completing ad:', error);
                }
            }, 5000); // 5 seconds delay for testing
        }
    });
}

// Hide ads section
function hideAds() {
    document.getElementById('adsSection').style.display = 'none';
}

// Show referral
function showReferral() {
    const referralLink = `https://t.me/STBEBOT?start=${currentUser.referral_code}`;

    const message = `👥 نظام الدعوات\n\n` +
        `🎁 احصل على ${document.getElementById('referralPoints').textContent} نقطة عن كل صديق!\n\n` +
        `📊 إحصائياتك:\n` +
        `• الدعوات: ${currentUser.referral_stats.total}\n` +
        `• النقاط المكتسبة: ${currentUser.referral_stats.total_points}\n\n` +
        `🔗 رابطك:\n${referralLink}`;

    tg.showPopup({
        title: 'ادعُ أصدقاءك',
        message: message,
        buttons: [
            { id: 'share', type: 'default', text: 'مشاركة الرابط' },
            { id: 'copy', type: 'default', text: 'نسخ الرابط' },
            { type: 'close' }
        ]
    }, (buttonId) => {
        if (buttonId === 'share') {
            const shareUrl = `https://t.me/share/url?url=${encodeURIComponent(referralLink)}&text=${encodeURIComponent('انضم معي في هذا المتجر الرائع!')}`;
            tg.openTelegramLink(shareUrl);
        } else if (buttonId === 'copy') {
            navigator.clipboard.writeText(referralLink);
            showToast('تم نسخ الرابط!', 'success');
        }
    });
}

// Load orders
async function loadOrders() {
    try {
        const data = await apiRequest('get_orders', { user_id: currentUser.user_id });
        const orders = data.orders;

        // Update total orders count
        document.getElementById('totalOrders').textContent = orders.length;

        const container = document.getElementById('ordersContainer');

        if (orders.length === 0) {
            container.style.display = 'none';
            document.getElementById('noOrders').style.display = 'block';
            return;
        }

        document.getElementById('noOrders').style.display = 'none';
        container.style.display = 'flex';
        container.innerHTML = '';

        // Show only last 10 orders
        orders.slice(0, 10).forEach(order => {
            const item = createOrderItem(order);
            container.appendChild(item);
        });

    } catch (error) {
        console.error('Error loading orders:', error);
    }
}

// Create order item
function createOrderItem(order) {
    const item = document.createElement('div');
    item.className = 'order-item';

    const date = new Date(order.created_at);
    const dateStr = date.toLocaleDateString('ar-EG', { year: 'numeric', month: 'short', day: 'numeric' });

    item.innerHTML = `
        <div class="order-header">
            <div class="order-name">${order.product_name}</div>
            <div class="order-status">مكتمل</div>
        </div>
        <div class="order-details">
            <div class="order-price">
                <i class="fas fa-gem"></i> ${order.points_spent} نقطة
            </div>
            <div class="order-date">${dateStr}</div>
        </div>
    `;

    return item;
}

// Setup event listeners
function setupEventListeners() {
    // Tab switching
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const tab = btn.dataset.tab;
            switchTab(tab);
        });
    });

    // Filter buttons
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const filter = btn.dataset.filter;
            if (filter === 'all') {
                renderProducts(products);
            } else if (filter === 'offers') {
                renderProducts(products.filter(p => p.is_offer));
            }
        });
    });

    // Refresh button
    document.getElementById('refreshBtn').addEventListener('click', async () => {
        const btn = document.getElementById('refreshBtn');
        btn.style.transform = 'rotate(360deg)';
        btn.style.transition = 'transform 0.5s';

        await Promise.all([
            loadUser(currentUser.user_id),
            loadProducts()
        ]);

        setTimeout(() => {
            btn.style.transform = '';
        }, 500);

        showToast('تم التحديث!', 'success');
    });

    // Close modals on background click
    document.getElementById('productModal').addEventListener('click', (e) => {
        if (e.target.id === 'productModal') {
            closeProductModal();
        }
    });

    document.getElementById('adModal').addEventListener('click', (e) => {
        if (e.target.id === 'adModal') {
            closeAdModal();
        }
    });
}

// Switch tab
function switchTab(tab) {
    // Update tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`[data-tab="${tab}"]`).classList.add('active');

    // Update tab content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    document.getElementById(`${tab}Tab`).classList.add('active');

    // Load data if needed
    if (tab === 'profile') {
        loadOrders();
    }
}

// Show toast notification
function showToast(message, type = 'info') {
    const toast = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMessage');

    toast.className = 'toast ' + type;
    toastMessage.textContent = message;
    toast.classList.add('active');

    setTimeout(() => {
        toast.classList.remove('active');
    }, 3000);
}

// Close ad modal
function closeAdModal() {
    document.getElementById('adModal').classList.remove('active');
}

// Haptic feedback
function hapticFeedback(type = 'impact') {
    if (tg.HapticFeedback) {
        if (type === 'impact') {
            tg.HapticFeedback.impactOccurred('medium');
        } else if (type === 'success') {
            tg.HapticFeedback.notificationOccurred('success');
        } else if (type === 'error') {
            tg.HapticFeedback.notificationOccurred('error');
        }
    }
}

// Add haptic feedback to buttons
document.addEventListener('click', (e) => {
    if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
        hapticFeedback('impact');
    }
});
