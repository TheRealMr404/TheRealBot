<?php

function virtualServicesAdminCustomText()
{
    global $update, $text;

    $value = function_exists('convertCustomEmojiToHTML') && isset($update['message'])
        ? convertCustomEmojiToHTML($update['message'])
        : (string) $text;
    return trim((string) $value);
}

function virtualServicesAdminEmojiId()
{
    global $update, $text;

    $value = trim((string) $text);
    if (in_array(mb_strtolower($value, 'UTF-8'), ['-', '0', 'none', 'حذف'], true)) {
        return '';
    }
    if (preg_match('/^\d{5,30}$/', $value)) {
        return $value;
    }
    if (function_exists('convertCustomEmojiToHTML') && isset($update['message'])) {
        $html = convertCustomEmojiToHTML($update['message']);
        if (preg_match('/emoji-id="(\d{5,30})"/', $html, $match)) {
            return $match[1];
        }
    }
    return null;
}

function virtualServicesAdminStyleLabel($style)
{
    return ['primary' => 'آبی', 'success' => 'سبز', 'danger' => 'قرمز', 'none' => 'ساده'][$style] ?? 'ساده';
}

function virtualServicesAdminStyleRows($prefix, $id = null)
{
    $suffix = $id === null ? '' : '_' . (int) $id;
    return [[
        ['text' => 'آبی', 'callback_data' => $prefix . $suffix . '_primary', 'style' => 'primary'],
        ['text' => 'سبز', 'callback_data' => $prefix . $suffix . '_success', 'style' => 'success'],
        ['text' => 'قرمز', 'callback_data' => $prefix . $suffix . '_danger', 'style' => 'danger'],
        ['text' => 'ساده', 'callback_data' => $prefix . $suffix . '_none'],
    ]];
}

function virtualServicesAdminKeyboard($rows)
{
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function virtualServicesAdminReply($text, $rows = [], $edit = true)
{
    global $from_id, $message_id, $datain;

    $keyboard = virtualServicesAdminKeyboard($rows);
    if ($edit && $datain !== '' && intval($message_id) > 0) {
        return Editmessagetext($from_id, $message_id, $text, $keyboard, 'HTML');
    }
    return sendmessage($from_id, $text, $keyboard, 'HTML');
}

function virtualServicesAdminSetState($state, array $data = [])
{
    global $from_id, $user;

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    update('user', 'Processing_value', $json, 'id', $from_id);
    step($state, $from_id);
    $user['Processing_value'] = $json;
    $user['step'] = $state;
}

function virtualServicesAdminStateData()
{
    global $user;

    $data = json_decode((string) ($user['Processing_value'] ?? ''), true);
    return is_array($data) ? $data : [];
}

function virtualServicesAdminClearState()
{
    virtualServicesAdminSetState('home', []);
}

function virtualServicesAdminHome()
{
    global $pdo;

    $categoryCount = (int) $pdo->query('SELECT COUNT(*) FROM telegram_product_categories')->fetchColumn();
    $productCount = (int) $pdo->query('SELECT COUNT(*) FROM telegram_products')->fetchColumn();
    $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM telegram_product_orders WHERE status = 'paid_pending'")->fetchColumn();
    $stockCount = (int) $pdo->query("SELECT COUNT(*) FROM telegram_product_stock WHERE status = 'available'")->fetchColumn();
    $enabled = telegramProductsSetting('enabled', '1') === '1';

    $text = "<b>مدیریت خدمات مجازی</b>\n\n";
    $text .= 'وضعیت فروشگاه: ' . ($enabled ? 'فعال' : 'غیرفعال') . "\n";
    $text .= "دسته‌ها: <code>{$categoryCount}</code> | محصولات: <code>{$productCount}</code>\n";
    $text .= "موجودی خودکار: <code>{$stockCount}</code> | منتظر تحویل: <code>{$pendingCount}</code>";

    $rows = [
        [
            ['text' => 'دسته‌بندی‌ها', 'callback_data' => 'vsa_categories'],
            ['text' => 'محصولات', 'callback_data' => 'vsa_products'],
        ],
        [
            ['text' => 'سفارش‌های منتظر تحویل' . ($pendingCount ? " ({$pendingCount})" : ''), 'callback_data' => 'vsa_orders'],
        ],
        [
            ['text' => 'سفارش‌های اخیر', 'callback_data' => 'vsa_recent'],
            ['text' => 'آمار فروش', 'callback_data' => 'vsa_stats'],
        ],
        [
            ['text' => 'متن‌ها و تنظیمات', 'callback_data' => 'vsa_settings'],
            ['text' => $enabled ? 'غیرفعال‌سازی' : 'فعال‌سازی', 'callback_data' => 'vsa_toggle'],
        ],
        [['text' => 'بازگشت به پنل مدیریت', 'callback_data' => 'vsa_exit']],
    ];
    virtualServicesAdminReply($text, $rows);
}

function virtualServicesAdminCategories()
{
    global $pdo;

    $rows = [];
    $categories = $pdo->query("SELECT c.*, COUNT(p.id) AS product_count
        FROM telegram_product_categories c
        LEFT JOIN telegram_products p ON p.category_id = c.id
        GROUP BY c.id ORDER BY c.sort_order, c.id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($categories as $category) {
        $status = (int) $category['is_active'] === 1 ? 'فعال' : 'غیرفعال';
        $rows[] = [[telegramProductsStyledButton(
            $category['title'] . ' | ' . $category['product_count'] . ' محصول | ' . $status,
            'vsa_cat_' . $category['id'],
            $category['button_style'],
            $category['button_emoji_id']
        )]];
    }
    $rows[] = [['text' => 'افزودن دسته جدید', 'callback_data' => 'vsa_cat_add']];
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_home']];
    $text = "<b>دسته‌بندی خدمات مجازی</b>\n\nبرای مدیریت هر دسته روی آن بزنید.";
    if (!$categories) {
        $text .= "\n\nهنوز دسته‌ای ساخته نشده است.";
    }
    virtualServicesAdminReply($text, $rows);
}

function virtualServicesAdminCategory($categoryId)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT c.*, COUNT(p.id) AS product_count FROM telegram_product_categories c LEFT JOIN telegram_products p ON p.category_id = c.id WHERE c.id = ? GROUP BY c.id");
    $stmt->execute([(int) $categoryId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$category) {
        virtualServicesAdminCategories();
        return;
    }
    $status = (int) $category['is_active'] === 1 ? 'فعال' : 'غیرفعال';
    $text = '<b>' . telegramProductsEscape($category['title']) . "</b>\n\n";
    $text .= "شناسه: <code>{$category['id']}</code>\nوضعیت: {$status}\nتعداد محصولات: {$category['product_count']}\n";
    $text .= 'رنگ دکمه: ' . virtualServicesAdminStyleLabel($category['button_style']) . "\n";
    $text .= 'ایموجی دکمه: ' . (!empty($category['button_emoji_id']) ? '<code>' . telegramProductsEscape($category['button_emoji_id']) . '</code>' : 'ندارد');
    $rows = [
        [
            ['text' => 'تغییر نام', 'callback_data' => 'vsa_cat_edit_' . $category['id']],
            ['text' => (int) $category['is_active'] === 1 ? 'غیرفعال‌سازی' : 'فعال‌سازی', 'callback_data' => 'vsa_cat_toggle_' . $category['id']],
        ],
        [
            ['text' => 'انتقال به بالاتر', 'callback_data' => 'vsa_cat_up_' . $category['id']],
            ['text' => 'انتقال به پایین‌تر', 'callback_data' => 'vsa_cat_down_' . $category['id']],
        ],
        [
            ['text' => 'رنگ دکمه', 'callback_data' => 'vsa_cat_style_' . $category['id']],
            ['text' => 'ایموجی دکمه', 'callback_data' => 'vsa_cat_emoji_' . $category['id']],
        ],
        [['text' => 'حذف دسته خالی', 'callback_data' => 'vsa_cat_delete_' . $category['id']]],
        [['text' => 'بازگشت', 'callback_data' => 'vsa_categories']],
    ];
    virtualServicesAdminReply($text, $rows);
}

function virtualServicesAdminProducts()
{
    global $pdo;

    $products = $pdo->query("SELECT p.*, c.title AS category_title,
        (SELECT COUNT(*) FROM telegram_product_stock s WHERE s.product_id = p.id AND s.status = 'available') AS stock_count
        FROM telegram_products p LEFT JOIN telegram_product_categories c ON c.id = p.category_id
        ORDER BY p.id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($products as $product) {
        $status = (int) $product['is_active'] === 1 ? 'فعال' : 'غیرفعال';
        $rows[] = [[telegramProductsStyledButton(
            '#' . $product['id'] . ' ' . $product['title'] . ' | ' . telegramProductsMoney($product['price']) . ' | ' . $status,
            'vsa_product_' . $product['id'],
            $product['button_style'],
            $product['button_emoji_id']
        )]];
    }
    $rows[] = [['text' => 'افزودن محصول', 'callback_data' => 'vsa_product_add']];
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_home']];
    $text = "<b>محصولات خدمات مجازی</b>\n\nمحصول موردنظر را انتخاب کنید.";
    if (!$products) {
        $text .= "\n\nهنوز محصولی ثبت نشده است.";
    }
    virtualServicesAdminReply($text, $rows);
}

function virtualServicesAdminProduct($productId)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT p.*, c.title AS category_title,
        (SELECT COUNT(*) FROM telegram_product_stock s WHERE s.product_id = p.id AND s.status = 'available') AS stock_count,
        (SELECT COUNT(*) FROM telegram_product_orders o WHERE o.product_id = p.id AND o.status != 'pending') AS order_count
        FROM telegram_products p LEFT JOIN telegram_product_categories c ON c.id = p.category_id WHERE p.id = ?");
    $stmt->execute([(int) $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        virtualServicesAdminProducts();
        return;
    }
    $delivery = $product['delivery_type'] === 'auto' ? 'خودکار' : 'دستی';
    $status = (int) $product['is_active'] === 1 ? 'فعال' : 'غیرفعال';
    $scopeLabels = ['all' => 'همه', 'f' => 'کاربر عادی', 'n' => 'نماینده', 'n2' => 'نماینده پیشرفته'];
    $text = '<b>' . telegramProductsEscape($product['title']) . "</b>\n\n";
    $text .= 'دسته: ' . telegramProductsEscape($product['category_title'] ?? 'بدون دسته') . "\n";
    $text .= 'قیمت: ' . telegramProductsMoney($product['price']) . "\n";
    $text .= "تحویل: {$delivery} | وضعیت: {$status}\n";
    $text .= 'نمایش برای: ' . ($scopeLabels[$product['agent_scope']] ?? telegramProductsEscape($product['agent_scope'])) . "\n";
    $text .= 'اطلاعات درخواستی: ' . telegramProductsSafeCustomText($product['input_label'] ?: 'ندارد') . "\n";
    $text .= 'رنگ دکمه: ' . virtualServicesAdminStyleLabel($product['button_style']) . ' | ایموجی: ' . (!empty($product['button_emoji_id']) ? '<code>' . telegramProductsEscape($product['button_emoji_id']) . '</code>' : 'ندارد') . "\n";
    $text .= 'هشدار موجودی: ' . (int) $product['low_stock_threshold'] . ' | سقف خرید هر کاربر: ' . ((int) $product['max_per_user'] ?: 'نامحدود') . "\n";
    $text .= "موجودی خودکار: {$product['stock_count']} | فروش: {$product['order_count']}\n\n";
    $text .= telegramProductsSafeCustomText($product['description']);

    $rows = [
        [
            ['text' => 'نام', 'callback_data' => 'vsa_pe_title_' . $product['id']],
            ['text' => 'قیمت', 'callback_data' => 'vsa_pe_price_' . $product['id']],
            ['text' => 'توضیحات', 'callback_data' => 'vsa_pe_desc_' . $product['id']],
        ],
        [
            ['text' => 'اطلاعات مشتری', 'callback_data' => 'vsa_pe_input_' . $product['id']],
            ['text' => 'دسته', 'callback_data' => 'vsa_pcat_' . $product['id']],
        ],
        [
            ['text' => 'نوع تحویل: ' . $delivery, 'callback_data' => 'vsa_pdelivery_' . $product['id']],
            ['text' => 'سطح کاربران', 'callback_data' => 'vsa_pscope_' . $product['id']],
        ],
        [
            ['text' => 'رنگ دکمه', 'callback_data' => 'vsa_pstyle_' . $product['id']],
            ['text' => 'ایموجی دکمه', 'callback_data' => 'vsa_pemoji_' . $product['id']],
        ],
        [
            ['text' => 'هشدار موجودی', 'callback_data' => 'vsa_plowstock_' . $product['id']],
            ['text' => 'سقف خرید', 'callback_data' => 'vsa_pmax_' . $product['id']],
        ],
        [
            ['text' => 'انتقال به بالاتر', 'callback_data' => 'vsa_product_up_' . $product['id']],
            ['text' => 'انتقال به پایین‌تر', 'callback_data' => 'vsa_product_down_' . $product['id']],
        ],
        [['text' => 'مدیریت موجودی خودکار', 'callback_data' => 'vsa_stock_' . $product['id']]],
        [['text' => (int) $product['is_active'] === 1 ? 'غیرفعال‌سازی محصول' : 'فعال‌سازی محصول', 'callback_data' => 'vsa_ptoggle_' . $product['id']]],
        [['text' => 'بازگشت', 'callback_data' => 'vsa_products']],
    ];
    virtualServicesAdminReply($text, $rows);
}

function virtualServicesAdminOrderList($recent = false)
{
    global $pdo;

    if ($recent) {
        $stmt = $pdo->query("SELECT * FROM telegram_product_orders WHERE status != 'pending' ORDER BY id DESC LIMIT 30");
        $title = 'سفارش‌های اخیر';
    } else {
        $stmt = $pdo->query("SELECT * FROM telegram_product_orders WHERE status = 'paid_pending' ORDER BY id LIMIT 30");
        $title = 'سفارش‌های منتظر تحویل';
    }
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($orders as $order) {
        $rows[] = [[
            'text' => '#' . $order['id'] . ' | ' . $order['product_title'] . ' | کاربر ' . $order['user_id'],
            'callback_data' => 'vsa_order_' . $order['id'],
        ]];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_home']];
    $text = '<b>' . $title . '</b>';
    if (!$orders) {
        $text .= "\n\nموردی وجود ندارد.";
    }
    virtualServicesAdminReply($text, $rows);
}

function virtualServicesAdminStock($productId)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT p.id, p.title,
        SUM(CASE WHEN s.status = 'available' THEN 1 ELSE 0 END) AS available_count,
        SUM(CASE WHEN s.status = 'sold' THEN 1 ELSE 0 END) AS sold_count
        FROM telegram_products p LEFT JOIN telegram_product_stock s ON s.product_id = p.id
        WHERE p.id = ? GROUP BY p.id, p.title");
    $stmt->execute([(int) $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        virtualServicesAdminProducts();
        return;
    }
    $available = (int) ($product['available_count'] ?? 0);
    $sold = (int) ($product['sold_count'] ?? 0);
    $text = '<b>موجودی خودکار ' . telegramProductsEscape($product['title']) . "</b>\n\n";
    $text .= "قابل فروش: <code>{$available}</code>\nفروخته‌شده: <code>{$sold}</code>";
    $rows = [
        [['text' => 'افزودن گروهی کد یا لینک', 'callback_data' => 'vsa_stock_add_' . $product['id']]],
        [['text' => 'حذف موجودی‌های فروخته‌نشده', 'callback_data' => 'vsa_stock_clear_' . $product['id']]],
        [['text' => 'بازگشت به محصول', 'callback_data' => 'vsa_product_' . $product['id']]],
    ];
    virtualServicesAdminReply($text, $rows);
}

function virtualServicesAdminOrder($orderId)
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT * FROM telegram_product_orders WHERE id = ?');
    $stmt->execute([(int) $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        virtualServicesAdminOrderList(false);
        return;
    }
    $labels = ['pending' => 'پرداخت‌نشده', 'paid_pending' => 'منتظر تحویل', 'delivered' => 'تحویل‌شده', 'refunded' => 'بازپرداخت‌شده', 'cancelled' => 'لغوشده'];
    $text = "<b>سفارش #{$order['id']}</b>\n\n";
    $text .= 'کاربر: <code>' . telegramProductsEscape($order['user_id']) . "</code>\n";
    $text .= 'محصول: ' . telegramProductsEscape($order['product_title']) . "\n";
    $text .= 'مبلغ: ' . telegramProductsMoney($order['price']) . "\n";
    $text .= 'وضعیت: ' . ($labels[$order['status']] ?? telegramProductsEscape($order['status'])) . "\n";
    if (!empty($order['customer_input'])) {
        $text .= 'اطلاعات مشتری: <code>' . telegramProductsEscape($order['customer_input']) . "</code>\n";
    }
    if (!empty($order['delivery_payload'])) {
        $text .= 'اطلاعات تحویل: <code>' . telegramProductsEscape($order['delivery_payload']) . "</code>\n";
    }
    $rows = [];
    if ($order['status'] === 'paid_pending') {
        $rows[] = [['text' => 'تحویل سفارش', 'callback_data' => 'vsa_deliver_' . $order['id']]];
        $rows[] = [['text' => 'لغو و بازپرداخت کیف پول', 'callback_data' => 'vsa_refund_' . $order['id']]];
    }
    if ($order['status'] === 'delivered' && !empty($order['delivery_payload'])) {
        $rows[] = [['text' => 'ارسال دوباره اطلاعات تحویل', 'callback_data' => 'vsa_resend_' . $order['id']]];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_orders']];
    virtualServicesAdminReply($text, $rows);
}

function virtualServicesAdminSettings()
{
    global $pdo;

    $enabled = telegramProductsSetting('enabled', '1') === '1';
    $topics = $pdo->query("SELECT report, idreport FROM topicid WHERE report IN ('virtualservices', 'virtualservices_error')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $topicsReady = (int) ($topics['virtualservices'] ?? 0) > 0 && (int) ($topics['virtualservices_error'] ?? 0) > 0;
    $text = "<b>متن‌ها و تنظیمات خدمات مجازی</b>\n\n";
    $text .= 'نام دکمه: ' . telegramProductsEscape(telegramProductsButtonText()) . "\n";
    $text .= 'وضعیت بخش: ' . ($enabled ? 'فعال' : 'غیرفعال') . "\n";
    $text .= 'تاپیک‌های گزارش: ' . ($topicsReady ? 'آماده' : 'در انتظار ساخت');
    $rows = [
        [['text' => 'تغییر نام دکمه اصلی', 'callback_data' => 'vsa_text_button']],
        [['text' => 'عنوان فروشگاه', 'callback_data' => 'vsa_text_title']],
        [['text' => 'متن صفحه نخست', 'callback_data' => 'vsa_text_home']],
        [['text' => 'متن صفحه دسته', 'callback_data' => 'vsa_text_category']],
        [['text' => 'متن تأیید خرید', 'callback_data' => 'vsa_text_checkout']],
        [['text' => 'پیام سفارش دستی', 'callback_data' => 'vsa_text_pending']],
        [['text' => 'پیام تحویل خودکار', 'callback_data' => 'vsa_text_auto']],
        [['text' => 'پیام اتمام موجودی', 'callback_data' => 'vsa_text_stockout']],
        [['text' => 'پیام غیرفعال بودن', 'callback_data' => 'vsa_text_disabled']],
        [['text' => 'ساخت یا بازسازی تاپیک‌های گزارش', 'callback_data' => 'vsa_topics_rebuild']],
        [['text' => $enabled ? 'غیرفعال‌سازی کل بخش' : 'فعال‌سازی کل بخش', 'callback_data' => 'vsa_toggle']],
        [['text' => 'بازگشت', 'callback_data' => 'vsa_home']],
    ];
    virtualServicesAdminReply($text, $rows);
}

function virtualServicesAdminStats()
{
    global $pdo;

    $stats = $pdo->query("SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status IN ('paid_pending','delivered') THEN 1 ELSE 0 END) AS paid_orders,
        COALESCE(SUM(CASE WHEN status IN ('paid_pending','delivered') THEN price ELSE 0 END), 0) AS revenue,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_orders,
        SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) AS refunded_orders
        FROM telegram_product_orders")->fetch(PDO::FETCH_ASSOC);
    $text = "<b>آمار خدمات مجازی</b>\n\n";
    $text .= 'کل سفارش‌ها: <code>' . (int) $stats['total_orders'] . "</code>\n";
    $text .= 'سفارش‌های پرداخت‌شده: <code>' . (int) $stats['paid_orders'] . "</code>\n";
    $text .= 'تحویل‌شده: <code>' . (int) $stats['delivered_orders'] . "</code>\n";
    $text .= 'بازپرداخت‌شده: <code>' . (int) $stats['refunded_orders'] . "</code>\n";
    $text .= 'فروش خالص ثبت‌شده: <code>' . telegramProductsMoney($stats['revenue']) . '</code>';
    virtualServicesAdminReply($text, [[['text' => 'بازگشت', 'callback_data' => 'vsa_home']]]);
}

function virtualServicesAdminRefund($orderId)
{
    global $pdo;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM telegram_product_orders WHERE id = ? AND status = 'paid_pending' FOR UPDATE");
        $stmt->execute([(int) $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $pdo->rollBack();
            return false;
        }
        $stmt = $pdo->prepare('UPDATE user SET Balance = Balance + ? WHERE id = ?');
        $stmt->execute([(int) $order['price'], $order['user_id']]);
        $stmt = $pdo->prepare("UPDATE telegram_product_orders SET status = 'refunded', refunded_at = NOW() WHERE id = ?");
        $stmt->execute([$order['id']]);
        $pdo->commit();
        if (function_exists('clearSelectCache')) {
            clearSelectCache('user');
        }
        sendmessage($order['user_id'], 'سفارش <code>#' . $order['id'] . '</code> لغو شد و مبلغ ' . telegramProductsMoney($order['price']) . ' به کیف پول شما بازگشت.', null, 'HTML');
        telegramProductsReport('refund', "<b>بازپرداخت سفارش خدمات مجازی</b>\n\n<b>سفارش:</b> <code>#{$order['id']}</code>\n<b>کاربر:</b> <code>" . telegramProductsEscape($order['user_id']) . "</code>\n<b>محصول:</b> " . telegramProductsEscape($order['product_title']) . "\n<b>مبلغ:</b> " . telegramProductsMoney($order['price']));
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function virtualServicesAdminHandleState()
{
    global $pdo, $from_id, $text, $user, $datatextbot;

    $state = (string) ($user['step'] ?? '');
    if (strpos($state, 'vsa_') !== 0) {
        return false;
    }
    $value = trim((string) $text);
    $data = virtualServicesAdminStateData();
    if ($value === '') {
        sendmessage($from_id, 'مقدار نمی‌تواند خالی باشد.', null, 'HTML');
        return true;
    }
    if (mb_strlen($value, 'UTF-8') > 3500) {
        sendmessage($from_id, 'متن ارسال‌شده بیش از حد طولانی است.', null, 'HTML');
        return true;
    }

    if ($state === 'vsa_cat_add') {
        if (mb_strlen($value, 'UTF-8') > 100) {
            sendmessage($from_id, 'نام دسته حداکثر باید ۱۰۰ کاراکتر باشد.', null, 'HTML');
            return true;
        }
        $stmt = $pdo->prepare('INSERT INTO telegram_product_categories (title) VALUES (?)');
        $stmt->execute([telegramProductsPlainText($value)]);
        virtualServicesAdminClearState();
        sendmessage($from_id, 'دسته جدید ساخته شد.', null, 'HTML');
        virtualServicesAdminCategories();
        return true;
    }
    if ($state === 'vsa_cat_edit') {
        if (mb_strlen($value, 'UTF-8') > 100) {
            sendmessage($from_id, 'نام دسته حداکثر باید ۱۰۰ کاراکتر باشد.', null, 'HTML');
            return true;
        }
        $stmt = $pdo->prepare('UPDATE telegram_product_categories SET title = ? WHERE id = ?');
        $stmt->execute([telegramProductsPlainText($value), (int) ($data['id'] ?? 0)]);
        virtualServicesAdminClearState();
        sendmessage($from_id, 'نام دسته بروزرسانی شد.', null, 'HTML');
        virtualServicesAdminCategory($data['id'] ?? 0);
        return true;
    }
    if ($state === 'vsa_add_title') {
        if (mb_strlen($value, 'UTF-8') > 100) {
            sendmessage($from_id, 'نام محصول حداکثر باید ۱۰۰ کاراکتر باشد.', null, 'HTML');
            return true;
        }
        $data['title'] = telegramProductsPlainText($value);
        virtualServicesAdminSetState('vsa_add_price', $data);
        sendmessage($from_id, 'قیمت محصول را به تومان و فقط به‌صورت عدد ارسال کنید.', null, 'HTML');
        return true;
    }
    if ($state === 'vsa_add_price') {
        if (!ctype_digit($value) || (float) $value > 1000000000000) {
            sendmessage($from_id, 'قیمت باید عدد صحیح و بدون جداکننده باشد.', null, 'HTML');
            return true;
        }
        $data['price'] = (int) $value;
        virtualServicesAdminSetState('vsa_add_description', $data);
        sendmessage($from_id, "<b>مرحله توضیحات</b>\n\nتوضیحات محصول را ارسال کنید. می‌توانید از ایموجی معمولی یا پریمیوم استفاده کنید. برای توضیح خالی <code>-</code> بفرستید.", null, 'HTML');
        return true;
    }
    if ($state === 'vsa_add_description') {
        $data['description'] = $value === '-' ? '' : virtualServicesAdminCustomText();
        if (($data['delivery_type'] ?? 'manual') === 'manual') {
            virtualServicesAdminSetState('vsa_add_input', $data);
            sendmessage($from_id, "<b>اطلاعات سفارش دستی</b>\n\nعنوان اطلاعاتی که باید از مشتری بگیریم را ارسال کنید؛ مثل <code>یوزرنیم تلگرام</code>. اگر لازم نیست <code>-</code> بفرستید.", null, 'HTML');
            return true;
        }
        $data['input_label'] = null;
        virtualServicesAdminSetState('vsa_add_scope_wait', $data);
        virtualServicesAdminReply('<b>محدوده نمایش</b>\n\nمحصول خودکار برای کدام سطح کاربران نمایش داده شود؟', [
            [['text' => 'همه کاربران', 'callback_data' => 'vsa_add_scope_all']],
            [['text' => 'کاربر عادی', 'callback_data' => 'vsa_add_scope_f']],
            [['text' => 'نماینده', 'callback_data' => 'vsa_add_scope_n']],
            [['text' => 'نماینده پیشرفته', 'callback_data' => 'vsa_add_scope_n2']],
        ], false);
        return true;
    }
    if ($state === 'vsa_add_input') {
        if ($value !== '-' && mb_strlen($value, 'UTF-8') > 100) {
            sendmessage($from_id, 'عنوان اطلاعات مشتری حداکثر باید ۱۰۰ کاراکتر باشد.', null, 'HTML');
            return true;
        }
        $inputLabel = $value === '-' ? null : virtualServicesAdminCustomText();
        if ($inputLabel !== null && mb_strlen($inputLabel, 'UTF-8') > 190) {
            sendmessage($from_id, 'به دلیل تعداد زیاد ایموجی‌های پریمیوم، متن ذخیره‌شده طولانی است. عنوان کوتاه‌تری ارسال کنید.', null, 'HTML');
            return true;
        }
        $data['input_label'] = $inputLabel;
        virtualServicesAdminSetState('vsa_add_scope_wait', $data);
        virtualServicesAdminReply('محصول برای کدام سطح کاربران نمایش داده شود؟', [
            [['text' => 'همه کاربران', 'callback_data' => 'vsa_add_scope_all']],
            [['text' => 'کاربر عادی', 'callback_data' => 'vsa_add_scope_f']],
            [['text' => 'نماینده', 'callback_data' => 'vsa_add_scope_n']],
            [['text' => 'نماینده پیشرفته', 'callback_data' => 'vsa_add_scope_n2']],
        ], false);
        return true;
    }
    if ($state === 'vsa_add_emoji') {
        $emojiId = virtualServicesAdminEmojiId();
        if ($emojiId === null) {
            sendmessage($from_id, 'یک ایموجی پریمیوم، شناسه عددی آن، یا <code>-</code> برای بدون ایموجی ارسال کنید.', null, 'HTML');
            return true;
        }
        $stmt = $pdo->prepare('INSERT INTO telegram_products (category_id, title, description, price, delivery_type, input_label, agent_scope, button_style, button_emoji_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            (int) ($data['category_id'] ?? 0),
            $data['title'] ?? '',
            $data['description'] ?? '',
            (int) ($data['price'] ?? 0),
            $data['delivery_type'] ?? 'manual',
            $data['input_label'] ?? null,
            $data['agent_scope'] ?? 'all',
            $data['button_style'] ?? 'success',
            $emojiId === '' ? null : $emojiId,
        ]);
        $productId = (int) $pdo->lastInsertId();
        virtualServicesAdminClearState();
        sendmessage($from_id, 'محصول با موفقیت ساخته شد.', null, 'HTML');
        if (($data['delivery_type'] ?? 'manual') === 'auto') {
            virtualServicesAdminReply('محصول خودکار ساخته شد. برای فعال‌شدن فروش، موجودی کد یا لینک آن را اضافه کنید.', [
                [['text' => 'افزودن موجودی', 'callback_data' => 'vsa_stock_add_' . $productId, 'style' => 'success']],
                [['text' => 'مشاهده محصول', 'callback_data' => 'vsa_product_' . $productId]],
            ], false);
            return true;
        }
        virtualServicesAdminProduct($productId);
        return true;
    }
    if ($state === 'vsa_stock_add') {
        $productId = (int) ($data['id'] ?? 0);
        $items = preg_split('/\R/u', $value);
        $items = array_values(array_unique(array_filter(array_map('trim', $items))));
        foreach ($items as $item) {
            if (mb_strlen($item, 'UTF-8') > 3000) {
                sendmessage($from_id, 'هر کد یا لینک باید کمتر از ۳۰۰۰ کاراکتر باشد.', null, 'HTML');
                return true;
            }
        }
        $stmt = $pdo->prepare('INSERT INTO telegram_product_stock (product_id, payload) VALUES (?, ?)');
        foreach ($items as $item) {
            $stmt->execute([$productId, $item]);
        }
        virtualServicesAdminClearState();
        sendmessage($from_id, count($items) . ' مورد به موجودی خودکار اضافه شد.', null, 'HTML');
        virtualServicesAdminProduct($productId);
        return true;
    }
    if ($state === 'vsa_deliver') {
        $orderId = (int) ($data['id'] ?? 0);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM telegram_product_orders WHERE id = ? AND status = 'paid_pending' FOR UPDATE");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $pdo->rollBack();
            virtualServicesAdminClearState();
            sendmessage($from_id, 'این سفارش قبلاً پردازش شده است.', null, 'HTML');
            return true;
        }
        $stmt = $pdo->prepare("UPDATE telegram_product_orders SET status = 'delivered', delivery_payload = ?, delivered_at = NOW() WHERE id = ?");
        $stmt->execute([$value, $orderId]);
        $pdo->commit();
        virtualServicesAdminClearState();
        $message = "سفارش شما تحویل شد.\n\n<b>محصول:</b> " . telegramProductsEscape($order['product_title']);
        $message .= "\n<b>اطلاعات تحویل:</b>\n<code>" . telegramProductsEscape($value) . '</code>';
        sendmessage($order['user_id'], $message, null, 'HTML');
        telegramProductsReport('delivery', "<b>تحویل سفارش دستی خدمات مجازی</b>\n\n<b>سفارش:</b> <code>#{$orderId}</code>\n<b>کاربر:</b> <code>" . telegramProductsEscape($order['user_id']) . "</code>\n<b>محصول:</b> " . telegramProductsEscape($order['product_title']));
        sendmessage($from_id, 'سفارش با موفقیت تحویل شد.', null, 'HTML');
        virtualServicesAdminOrder($orderId);
        return true;
    }
    if (in_array($state, ['vsa_edit_title', 'vsa_edit_price', 'vsa_edit_desc', 'vsa_edit_input'], true)) {
        $productId = (int) ($data['id'] ?? 0);
        $fieldMap = [
            'vsa_edit_title' => ['title', telegramProductsPlainText($value)],
            'vsa_edit_price' => ['price', $value],
            'vsa_edit_desc' => ['description', $value === '-' ? '' : virtualServicesAdminCustomText()],
            'vsa_edit_input' => ['input_label', $value === '-' ? null : virtualServicesAdminCustomText()],
        ];
        if (!isset($fieldMap[$state])) {
            return false;
        }
        if ($state === 'vsa_edit_title' && mb_strlen($value, 'UTF-8') > 100) {
            sendmessage($from_id, 'نام محصول حداکثر باید ۱۰۰ کاراکتر باشد.', null, 'HTML');
            return true;
        }
        if ($state === 'vsa_edit_input' && $value !== '-' && mb_strlen($value, 'UTF-8') > 100) {
            sendmessage($from_id, 'عنوان اطلاعات مشتری حداکثر باید ۱۰۰ کاراکتر باشد.', null, 'HTML');
            return true;
        }
        if ($state === 'vsa_edit_input' && $fieldMap[$state][1] !== null && mb_strlen($fieldMap[$state][1], 'UTF-8') > 190) {
            sendmessage($from_id, 'به دلیل تعداد زیاد ایموجی‌های پریمیوم، متن ذخیره‌شده طولانی است. عنوان کوتاه‌تری ارسال کنید.', null, 'HTML');
            return true;
        }
        if ($state === 'vsa_edit_price' && (!ctype_digit($value) || (float) $value > 1000000000000)) {
            sendmessage($from_id, 'قیمت باید فقط عدد باشد.', null, 'HTML');
            return true;
        }
        [$field, $fieldValue] = $fieldMap[$state];
        $stmt = $pdo->prepare("UPDATE telegram_products SET `{$field}` = ? WHERE id = ?");
        $stmt->execute([$fieldValue, $productId]);
        virtualServicesAdminClearState();
        sendmessage($from_id, 'محصول بروزرسانی شد.', null, 'HTML');
        virtualServicesAdminProduct($productId);
        return true;
    }
    if (in_array($state, ['vsa_cat_emoji_edit', 'vsa_product_emoji_edit'], true)) {
        $emojiId = virtualServicesAdminEmojiId();
        if ($emojiId === null) {
            sendmessage($from_id, 'یک ایموجی پریمیوم، شناسه عددی آن، یا <code>-</code> برای حذف ارسال کنید.', null, 'HTML');
            return true;
        }
        $table = $state === 'vsa_cat_emoji_edit' ? 'telegram_product_categories' : 'telegram_products';
        $stmt = $pdo->prepare("UPDATE {$table} SET button_emoji_id = ? WHERE id = ?");
        $stmt->execute([$emojiId === '' ? null : $emojiId, (int) ($data['id'] ?? 0)]);
        $id = (int) ($data['id'] ?? 0);
        virtualServicesAdminClearState();
        $state === 'vsa_cat_emoji_edit' ? virtualServicesAdminCategory($id) : virtualServicesAdminProduct($id);
        return true;
    }
    if (in_array($state, ['vsa_edit_lowstock', 'vsa_edit_max'], true)) {
        if (!ctype_digit($value) || (int) $value > 1000000) {
            sendmessage($from_id, 'مقدار باید یک عدد صحیح بین صفر تا ۱۰۰۰۰۰۰ باشد.', null, 'HTML');
            return true;
        }
        $field = $state === 'vsa_edit_lowstock' ? 'low_stock_threshold' : 'max_per_user';
        $stmt = $pdo->prepare("UPDATE telegram_products SET {$field} = ? WHERE id = ?");
        $stmt->execute([(int) $value, (int) ($data['id'] ?? 0)]);
        $id = (int) ($data['id'] ?? 0);
        virtualServicesAdminClearState();
        virtualServicesAdminProduct($id);
        return true;
    }
    if (strpos($state, 'vsa_text_') === 0) {
        if ($state === 'vsa_text_button_edit') {
            if (mb_strlen($value, 'UTF-8') > 40) {
                sendmessage($from_id, 'نام دکمه حداکثر باید ۴۰ کاراکتر باشد.', null, 'HTML');
                return true;
            }
            $stmt = $pdo->prepare("INSERT INTO textbot (id_text, text) VALUES ('text_virtual_services', ?) ON DUPLICATE KEY UPDATE text = VALUES(text)");
            $buttonText = telegramProductsPlainText($value);
            $stmt->execute([$buttonText]);
            $datatextbot['text_virtual_services'] = $buttonText;
            if (function_exists('clearSelectCache')) {
                clearSelectCache('textbot');
            }
        } else {
            $keyMap = [
                'vsa_text_title_edit' => 'store_title',
                'vsa_text_home_edit' => 'home_text',
                'vsa_text_category_edit' => 'category_text',
                'vsa_text_checkout_edit' => 'checkout_text',
                'vsa_text_pending_edit' => 'manual_pending_text',
                'vsa_text_auto_edit' => 'auto_success_text',
                'vsa_text_stockout_edit' => 'out_of_stock_text',
                'vsa_text_disabled_edit' => 'disabled_text',
            ];
            if (!isset($keyMap[$state])) {
                return false;
            }
            telegramProductsSetSetting($keyMap[$state], virtualServicesAdminCustomText());
        }
        virtualServicesAdminClearState();
        sendmessage($from_id, 'متن با موفقیت ذخیره شد.', null, 'HTML');
        virtualServicesAdminSettings();
        return true;
    }

    return false;
}

function telegramProductsAdminPanelHandleRequest()
{
    global $pdo, $from_id, $text, $datain, $callback_query_id, $message_id, $user, $admin_ids, $textbotlang, $keyboardadmin;

    if (!in_array((string) $from_id, array_map('strval', (array) $admin_ids), true)) {
        return false;
    }
    if ($text === ($textbotlang['Admin']['backadmin'] ?? null) || $text === ($textbotlang['Admin']['backmenu'] ?? null)) {
        return false;
    }
    $isRequest = $text === 'مدیریت خدمات مجازی'
        || strpos($datain, 'vsa_') === 0
        || strpos((string) ($user['step'] ?? ''), 'vsa_') === 0;
    if (!$isRequest) {
        return false;
    }

    try {
        telegramProductsEnsureSchema();
        if ($callback_query_id) {
            telegram('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
        }
        $isStateContinuation = preg_match('/^vsa_(begin|addcat_|add_(delivery|scope|style)_)/', $datain) === 1;
        if ($datain !== '' && strpos((string) ($user['step'] ?? ''), 'vsa_') === 0 && !$isStateContinuation) {
            virtualServicesAdminClearState();
        }
        if ($datain === '' && virtualServicesAdminHandleState()) {
            return true;
        }
        if ($text === 'مدیریت خدمات مجازی' || $datain === 'vsa_home') {
            virtualServicesAdminClearState();
            virtualServicesAdminHome();
            return true;
        }
        if ($datain === 'vsa_exit') {
            virtualServicesAdminClearState();
            if ($message_id) {
                deletemessage($from_id, $message_id);
            }
            sendmessage($from_id, 'به پنل مدیریت بازگشتید.', $keyboardadmin, 'HTML');
            return true;
        }
        if ($datain === 'vsa_toggle') {
            telegramProductsSetSetting('enabled', telegramProductsSetting('enabled', '1') === '1' ? '0' : '1');
            virtualServicesAdminHome();
            return true;
        }
        if ($datain === 'vsa_categories') {
            virtualServicesAdminCategories();
            return true;
        }
        if ($datain === 'vsa_cat_add') {
            virtualServicesAdminSetState('vsa_cat_add');
            virtualServicesAdminReply('نام دسته جدید را ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_categories']]]);
            return true;
        }
        if (preg_match('/^vsa_cat_(\d+)$/', $datain, $match)) {
            virtualServicesAdminCategory($match[1]);
            return true;
        }
        if (preg_match('/^vsa_cat_edit_(\d+)$/', $datain, $match)) {
            virtualServicesAdminSetState('vsa_cat_edit', ['id' => (int) $match[1]]);
            virtualServicesAdminReply('نام جدید دسته را ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_cat_' . $match[1]]]]);
            return true;
        }
        if (preg_match('/^vsa_cat_toggle_(\d+)$/', $datain, $match)) {
            $stmt = $pdo->prepare('UPDATE telegram_product_categories SET is_active = 1 - is_active WHERE id = ?');
            $stmt->execute([(int) $match[1]]);
            virtualServicesAdminCategory($match[1]);
            return true;
        }
        if (preg_match('/^vsa_cat_style_(\d+)$/', $datain, $match)) {
            $rows = virtualServicesAdminStyleRows('vsa_set_cat_style', (int) $match[1]);
            $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_cat_' . $match[1]]];
            virtualServicesAdminReply('رنگ دکمه این دسته را انتخاب کنید.', $rows);
            return true;
        }
        if (preg_match('/^vsa_set_cat_style_(\d+)_(primary|success|danger|none)$/', $datain, $match)) {
            $stmt = $pdo->prepare('UPDATE telegram_product_categories SET button_style = ? WHERE id = ?');
            $stmt->execute([$match[2], (int) $match[1]]);
            virtualServicesAdminCategory($match[1]);
            return true;
        }
        if (preg_match('/^vsa_cat_emoji_(\d+)$/', $datain, $match)) {
            virtualServicesAdminSetState('vsa_cat_emoji_edit', ['id' => (int) $match[1]]);
            virtualServicesAdminReply("یک ایموجی پریمیوم یا شناسه عددی آن را ارسال کنید. برای حذف ایموجی <code>-</code> بفرستید.", [[['text' => 'انصراف', 'callback_data' => 'vsa_cat_' . $match[1]]]]);
            return true;
        }
        if (preg_match('/^vsa_cat_(up|down)_(\d+)$/', $datain, $match)) {
            $delta = $match[1] === 'up' ? -1 : 1;
            $stmt = $pdo->prepare('UPDATE telegram_product_categories SET sort_order = sort_order + ? WHERE id = ?');
            $stmt->execute([$delta, (int) $match[2]]);
            virtualServicesAdminCategory($match[2]);
            return true;
        }
        if (preg_match('/^vsa_cat_delete_(\d+)$/', $datain, $match)) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM telegram_products WHERE category_id = ?');
            $stmt->execute([(int) $match[1]]);
            if ((int) $stmt->fetchColumn() > 0) {
                virtualServicesAdminReply('این دسته دارای محصول است و قابل حذف نیست. ابتدا محصولات را جابه‌جا کنید.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_cat_' . $match[1]]]]);
                return true;
            }
            $stmt = $pdo->prepare('DELETE FROM telegram_product_categories WHERE id = ?');
            $stmt->execute([(int) $match[1]]);
            virtualServicesAdminCategories();
            return true;
        }
        if ($datain === 'vsa_products') {
            virtualServicesAdminProducts();
            return true;
        }
        if ($datain === 'vsa_product_add') {
            virtualServicesAdminClearState();
            virtualServicesAdminReply("<b>ساخت محصول جدید</b>\n\nابتدا نوع فروش محصول را مشخص کنید تا فقط اطلاعات مرتبط با همان روش پرسیده شود.", [
                [['text' => 'فروش خودکار', 'callback_data' => 'vsa_begin_auto', 'style' => 'success']],
                [['text' => 'فروش دستی', 'callback_data' => 'vsa_begin_manual', 'style' => 'primary']],
                [['text' => 'انصراف', 'callback_data' => 'vsa_products']],
            ]);
            return true;
        }
        if (preg_match('/^vsa_begin_(auto|manual)$/', $datain, $match)) {
            $categories = $pdo->query('SELECT id, title FROM telegram_product_categories WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
            if (!$categories) {
                virtualServicesAdminReply('برای ساخت محصول ابتدا یک دسته فعال بسازید.', [
                    [['text' => 'ساخت دسته', 'callback_data' => 'vsa_cat_add']],
                    [['text' => 'بازگشت', 'callback_data' => 'vsa_products']],
                ]);
                return true;
            }
            virtualServicesAdminSetState('vsa_add_category_wait', ['delivery_type' => $match[1]]);
            $rows = [];
            foreach ($categories as $category) {
                $rows[] = [['text' => telegramProductsPlainText($category['title']), 'callback_data' => 'vsa_addcat_' . $category['id']]];
            }
            $rows[] = [['text' => 'انصراف', 'callback_data' => 'vsa_products']];
            virtualServicesAdminReply('<b>مرحله ۲: دسته‌بندی</b>\n\nدسته محصول را انتخاب کنید.', $rows);
            return true;
        }
        if (preg_match('/^vsa_addcat_(\d+)$/', $datain, $match)) {
            $data = virtualServicesAdminStateData();
            $data['category_id'] = (int) $match[1];
            virtualServicesAdminSetState('vsa_add_title', $data);
            virtualServicesAdminReply('<b>مرحله ۳: نام محصول</b>\n\nنامی کوتاه و واضح برای محصول ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_products']]]);
            return true;
        }
        if (preg_match('/^vsa_add_delivery_(auto|manual)$/', $datain, $match)) {
            $data = virtualServicesAdminStateData();
            $data['delivery_type'] = $match[1];
            virtualServicesAdminSetState('vsa_add_description', $data);
            virtualServicesAdminReply('توضیحات محصول را ارسال کنید. برای توضیح خالی، <code>-</code> بفرستید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_products']]]);
            return true;
        }
        if (preg_match('/^vsa_add_scope_(all|f|n|n2)$/', $datain, $match)) {
            $data = virtualServicesAdminStateData();
            $data['agent_scope'] = $match[1];
            virtualServicesAdminSetState('vsa_add_style_wait', $data);
            $rows = virtualServicesAdminStyleRows('vsa_add_style');
            $rows[] = [['text' => 'انصراف', 'callback_data' => 'vsa_products']];
            virtualServicesAdminReply('<b>ظاهر دکمه محصول</b>\n\nرنگ دکمه را انتخاب کنید.', $rows);
            return true;
        }
        if (preg_match('/^vsa_add_style_(primary|success|danger|none)$/', $datain, $match)) {
            $data = virtualServicesAdminStateData();
            $data['button_style'] = $match[1];
            virtualServicesAdminSetState('vsa_add_emoji', $data);
            virtualServicesAdminReply("<b>ایموجی دکمه محصول</b>\n\nیک ایموجی پریمیوم بفرستید تا شناسه آن خودکار استخراج شود؛ می‌توانید شناسه عددی را هم ارسال کنید. برای نداشتن ایموجی <code>-</code> بفرستید.", [[['text' => 'انصراف', 'callback_data' => 'vsa_products']]]);
            return true;
        }
        if (preg_match('/^vsa_product_(\d+)$/', $datain, $match)) {
            virtualServicesAdminProduct($match[1]);
            return true;
        }
        if (preg_match('/^vsa_pe_(title|price|desc|input)_(\d+)$/', $datain, $match)) {
            $prompts = ['title' => 'نام جدید را ارسال کنید.', 'price' => 'قیمت جدید را فقط به‌صورت عدد ارسال کنید.', 'desc' => 'توضیحات جدید را ارسال کنید؛ برای خالی‌کردن <code>-</code>.', 'input' => 'عنوان اطلاعات مشتری را ارسال کنید؛ برای حذف <code>-</code>.'];
            virtualServicesAdminSetState('vsa_edit_' . $match[1], ['id' => (int) $match[2]]);
            virtualServicesAdminReply($prompts[$match[1]], [[['text' => 'انصراف', 'callback_data' => 'vsa_product_' . $match[2]]]]);
            return true;
        }
        if (preg_match('/^vsa_ptoggle_(\d+)$/', $datain, $match)) {
            $stmt = $pdo->prepare('UPDATE telegram_products SET is_active = 1 - is_active WHERE id = ?');
            $stmt->execute([(int) $match[1]]);
            virtualServicesAdminProduct($match[1]);
            return true;
        }
        if (preg_match('/^vsa_pstyle_(\d+)$/', $datain, $match)) {
            $rows = virtualServicesAdminStyleRows('vsa_set_product_style', (int) $match[1]);
            $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_product_' . $match[1]]];
            virtualServicesAdminReply('رنگ دکمه این محصول را انتخاب کنید.', $rows);
            return true;
        }
        if (preg_match('/^vsa_set_product_style_(\d+)_(primary|success|danger|none)$/', $datain, $match)) {
            $stmt = $pdo->prepare('UPDATE telegram_products SET button_style = ? WHERE id = ?');
            $stmt->execute([$match[2], (int) $match[1]]);
            virtualServicesAdminProduct($match[1]);
            return true;
        }
        if (preg_match('/^vsa_pemoji_(\d+)$/', $datain, $match)) {
            virtualServicesAdminSetState('vsa_product_emoji_edit', ['id' => (int) $match[1]]);
            virtualServicesAdminReply("یک ایموجی پریمیوم یا شناسه عددی آن را ارسال کنید. برای حذف ایموجی <code>-</code> بفرستید.", [[['text' => 'انصراف', 'callback_data' => 'vsa_product_' . $match[1]]]]);
            return true;
        }
        if (preg_match('/^vsa_plowstock_(\d+)$/', $datain, $match)) {
            virtualServicesAdminSetState('vsa_edit_lowstock', ['id' => (int) $match[1]]);
            virtualServicesAdminReply('وقتی موجودی به این عدد یا کمتر رسید هشدار ارسال شود. عدد <code>0</code> یعنی فقط هنگام اتمام موجودی.', [[['text' => 'انصراف', 'callback_data' => 'vsa_product_' . $match[1]]]]);
            return true;
        }
        if (preg_match('/^vsa_pmax_(\d+)$/', $datain, $match)) {
            virtualServicesAdminSetState('vsa_edit_max', ['id' => (int) $match[1]]);
            virtualServicesAdminReply('حداکثر تعداد خرید این محصول برای هر کاربر را ارسال کنید. عدد <code>0</code> یعنی نامحدود.', [[['text' => 'انصراف', 'callback_data' => 'vsa_product_' . $match[1]]]]);
            return true;
        }
        if (preg_match('/^vsa_product_(up|down)_(\d+)$/', $datain, $match)) {
            $delta = $match[1] === 'up' ? -1 : 1;
            $stmt = $pdo->prepare('UPDATE telegram_products SET sort_order = sort_order + ? WHERE id = ?');
            $stmt->execute([$delta, (int) $match[2]]);
            virtualServicesAdminProduct($match[2]);
            return true;
        }
        if (preg_match('/^vsa_pdelivery_(\d+)$/', $datain, $match)) {
            $stmt = $pdo->prepare("UPDATE telegram_products SET delivery_type = IF(delivery_type = 'auto', 'manual', 'auto') WHERE id = ?");
            $stmt->execute([(int) $match[1]]);
            virtualServicesAdminProduct($match[1]);
            return true;
        }
        if (preg_match('/^vsa_pscope_(\d+)$/', $datain, $match)) {
            $id = (int) $match[1];
            virtualServicesAdminReply('سطح کاربران را انتخاب کنید.', [
                [['text' => 'همه کاربران', 'callback_data' => "vsa_pset_scope_{$id}_all"]],
                [['text' => 'کاربر عادی', 'callback_data' => "vsa_pset_scope_{$id}_f"]],
                [['text' => 'نماینده', 'callback_data' => "vsa_pset_scope_{$id}_n"]],
                [['text' => 'نماینده پیشرفته', 'callback_data' => "vsa_pset_scope_{$id}_n2"]],
                [['text' => 'بازگشت', 'callback_data' => "vsa_product_{$id}"]],
            ]);
            return true;
        }
        if (preg_match('/^vsa_pset_scope_(\d+)_(all|f|n|n2)$/', $datain, $match)) {
            $stmt = $pdo->prepare('UPDATE telegram_products SET agent_scope = ? WHERE id = ?');
            $stmt->execute([$match[2], (int) $match[1]]);
            virtualServicesAdminProduct($match[1]);
            return true;
        }
        if (preg_match('/^vsa_pcat_(\d+)$/', $datain, $match)) {
            $productId = (int) $match[1];
            $categories = $pdo->query('SELECT id, title FROM telegram_product_categories WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
            $rows = [];
            foreach ($categories as $category) {
                $rows[] = [['text' => $category['title'], 'callback_data' => "vsa_psetcat_{$productId}_{$category['id']}"]];
            }
            $rows[] = [['text' => 'بازگشت', 'callback_data' => "vsa_product_{$productId}"]];
            virtualServicesAdminReply('دسته جدید را انتخاب کنید.', $rows);
            return true;
        }
        if (preg_match('/^vsa_psetcat_(\d+)_(\d+)$/', $datain, $match)) {
            $stmt = $pdo->prepare('UPDATE telegram_products SET category_id = ? WHERE id = ?');
            $stmt->execute([(int) $match[2], (int) $match[1]]);
            virtualServicesAdminProduct($match[1]);
            return true;
        }
        if (preg_match('/^vsa_stock_(\d+)$/', $datain, $match)) {
            virtualServicesAdminStock($match[1]);
            return true;
        }
        if (preg_match('/^vsa_stock_add_(\d+)$/', $datain, $match)) {
            virtualServicesAdminSetState('vsa_stock_add', ['id' => (int) $match[1]]);
            virtualServicesAdminReply("کدها یا لینک‌ها را ارسال کنید. هر مورد باید در یک خط جدا باشد.", [[['text' => 'انصراف', 'callback_data' => 'vsa_product_' . $match[1]]]]);
            return true;
        }
        if (preg_match('/^vsa_stock_clear_(\d+)$/', $datain, $match)) {
            $stmt = $pdo->prepare("DELETE FROM telegram_product_stock WHERE product_id = ? AND status = 'available'");
            $stmt->execute([(int) $match[1]]);
            virtualServicesAdminStock($match[1]);
            return true;
        }
        if ($datain === 'vsa_orders') {
            virtualServicesAdminOrderList(false);
            return true;
        }
        if ($datain === 'vsa_recent') {
            virtualServicesAdminOrderList(true);
            return true;
        }
        if (preg_match('/^vsa_order_(\d+)$/', $datain, $match)) {
            virtualServicesAdminOrder($match[1]);
            return true;
        }
        if (preg_match('/^vsa_deliver_(\d+)$/', $datain, $match)) {
            virtualServicesAdminSetState('vsa_deliver', ['id' => (int) $match[1]]);
            virtualServicesAdminReply('متن، کد یا لینک تحویل را ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_order_' . $match[1]]]]);
            return true;
        }
        if (preg_match('/^vsa_refund_(\d+)$/', $datain, $match)) {
            if (!virtualServicesAdminRefund($match[1])) {
                virtualServicesAdminReply('سفارش قابل بازپرداخت نیست یا قبلاً پردازش شده است.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_order_' . $match[1]]]]);
                return true;
            }
            virtualServicesAdminOrder($match[1]);
            return true;
        }
        if (preg_match('/^vsa_resend_(\d+)$/', $datain, $match)) {
            $stmt = $pdo->prepare("SELECT * FROM telegram_product_orders WHERE id = ? AND status = 'delivered'");
            $stmt->execute([(int) $match[1]]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order || empty($order['delivery_payload'])) {
                virtualServicesAdminReply('اطلاعات تحویلی برای ارسال مجدد وجود ندارد.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_order_' . $match[1]]]]);
                return true;
            }
            $message = "اطلاعات سفارش شما دوباره ارسال شد.\n\n<b>محصول:</b> " . telegramProductsEscape($order['product_title']);
            $message .= "\n<b>اطلاعات تحویل:</b>\n<code>" . telegramProductsEscape($order['delivery_payload']) . '</code>';
            sendmessage($order['user_id'], $message, null, 'HTML');
            virtualServicesAdminOrder($match[1]);
            return true;
        }
        if ($datain === 'vsa_settings') {
            virtualServicesAdminSettings();
            return true;
        }
        if ($datain === 'vsa_topics_rebuild') {
            $salesTopic = telegramProductsEnsureReportTopic('virtualservices', 'خدمات مجازی', true);
            $errorTopic = telegramProductsEnsureReportTopic('virtualservices_error', 'خطاهای خدمات مجازی', true);
            if ($salesTopic > 0 && $errorTopic > 0) {
                virtualServicesAdminReply('هر دو تاپیک گزارش با موفقیت ساخته شدند.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_settings']]]);
            } else {
                virtualServicesAdminReply('ساخت تاپیک کامل نشد. گروه گزارش باید سوپرگروه انجمنی باشد و ربات دسترسی مدیریت تاپیک‌ها را داشته باشد.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_settings']]]);
            }
            return true;
        }
        $textStateMap = [
            'vsa_text_button' => ['vsa_text_button_edit', 'نام جدید دکمه اصلی را ارسال کنید.'],
            'vsa_text_title' => ['vsa_text_title_edit', 'عنوان بالای فروشگاه را ارسال کنید. ایموجی معمولی و پریمیوم پشتیبانی می‌شود.'],
            'vsa_text_home' => ['vsa_text_home_edit', 'متن صفحه نخست خدمات مجازی را ارسال کنید. ایموجی معمولی و پریمیوم پشتیبانی می‌شود.'],
            'vsa_text_category' => ['vsa_text_category_edit', 'متن راهنمای صفحه دسته‌بندی را ارسال کنید.'],
            'vsa_text_checkout' => ['vsa_text_checkout_edit', 'متن راهنمای تأیید خرید را ارسال کنید.'],
            'vsa_text_pending' => ['vsa_text_pending_edit', 'پیام ثبت سفارش دستی را ارسال کنید.'],
            'vsa_text_auto' => ['vsa_text_auto_edit', 'پیام تحویل خودکار را ارسال کنید.'],
            'vsa_text_stockout' => ['vsa_text_stockout_edit', 'پیام اتمام موجودی محصول را ارسال کنید.'],
            'vsa_text_disabled' => ['vsa_text_disabled_edit', 'پیام غیرفعال بودن بخش را ارسال کنید.'],
        ];
        if (isset($textStateMap[$datain])) {
            virtualServicesAdminSetState($textStateMap[$datain][0]);
            virtualServicesAdminReply($textStateMap[$datain][1], [[['text' => 'انصراف', 'callback_data' => 'vsa_settings']]]);
            return true;
        }
        if ($datain === 'vsa_stats') {
            virtualServicesAdminStats();
            return true;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Virtual services admin error: ' . $e->getMessage());
        sendmessage($from_id, 'خطایی در مدیریت خدمات مجازی رخ داد. جزئیات در error_log ثبت شد.', null, 'HTML');
        return true;
    }

    return true;
}
