<?php

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
        $rows[] = [[
            'text' => $category['title'] . ' | ' . $category['product_count'] . ' محصول | ' . $status,
            'callback_data' => 'vsa_cat_' . $category['id'],
        ]];
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
    $text .= "شناسه: <code>{$category['id']}</code>\nوضعیت: {$status}\nتعداد محصولات: {$category['product_count']}";
    $rows = [
        [
            ['text' => 'تغییر نام', 'callback_data' => 'vsa_cat_edit_' . $category['id']],
            ['text' => (int) $category['is_active'] === 1 ? 'غیرفعال‌سازی' : 'فعال‌سازی', 'callback_data' => 'vsa_cat_toggle_' . $category['id']],
        ],
        [
            ['text' => 'انتقال به بالاتر', 'callback_data' => 'vsa_cat_up_' . $category['id']],
            ['text' => 'انتقال به پایین‌تر', 'callback_data' => 'vsa_cat_down_' . $category['id']],
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
        $rows[] = [[
            'text' => '#' . $product['id'] . ' ' . $product['title'] . ' | ' . telegramProductsMoney($product['price']) . ' | ' . $status,
            'callback_data' => 'vsa_product_' . $product['id'],
        ]];
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
    $text .= 'اطلاعات درخواستی: ' . telegramProductsEscape($product['input_label'] ?: 'ندارد') . "\n";
    $text .= "موجودی خودکار: {$product['stock_count']} | فروش: {$product['order_count']}\n\n";
    $text .= telegramProductsEscape($product['description']);

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
    $enabled = telegramProductsSetting('enabled', '1') === '1';
    $text = "<b>متن‌ها و تنظیمات خدمات مجازی</b>\n\n";
    $text .= 'نام دکمه: ' . telegramProductsEscape(telegramProductsButtonText()) . "\n";
    $text .= 'وضعیت بخش: ' . ($enabled ? 'فعال' : 'غیرفعال');
    $rows = [
        [['text' => 'تغییر نام دکمه اصلی', 'callback_data' => 'vsa_text_button']],
        [['text' => 'متن صفحه نخست', 'callback_data' => 'vsa_text_home']],
        [['text' => 'پیام سفارش دستی', 'callback_data' => 'vsa_text_pending']],
        [['text' => 'پیام تحویل خودکار', 'callback_data' => 'vsa_text_auto']],
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
        $stmt->execute([$value]);
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
        $stmt->execute([$value, (int) ($data['id'] ?? 0)]);
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
        $data['title'] = $value;
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
        virtualServicesAdminSetState('vsa_add_delivery_wait', $data);
        virtualServicesAdminReply('نوع تحویل محصول را انتخاب کنید.', [
            [['text' => 'تحویل خودکار از موجودی', 'callback_data' => 'vsa_add_delivery_auto']],
            [['text' => 'تحویل دستی توسط ادمین', 'callback_data' => 'vsa_add_delivery_manual']],
            [['text' => 'انصراف', 'callback_data' => 'vsa_products']],
        ], false);
        return true;
    }
    if ($state === 'vsa_add_description') {
        $data['description'] = $value === '-' ? '' : $value;
        virtualServicesAdminSetState('vsa_add_input', $data);
        sendmessage($from_id, "عنوان اطلاعاتی که باید از مشتری بگیریم را ارسال کنید؛ مثل <code>یوزرنیم تلگرام</code>.\nاگر لازم نیست، یک خط تیره <code>-</code> بفرستید.", null, 'HTML');
        return true;
    }
    if ($state === 'vsa_add_input') {
        $data['input_label'] = $value === '-' ? null : $value;
        virtualServicesAdminSetState('vsa_add_scope_wait', $data);
        virtualServicesAdminReply('محصول برای کدام سطح کاربران نمایش داده شود؟', [
            [['text' => 'همه کاربران', 'callback_data' => 'vsa_add_scope_all']],
            [['text' => 'کاربر عادی', 'callback_data' => 'vsa_add_scope_f']],
            [['text' => 'نماینده', 'callback_data' => 'vsa_add_scope_n']],
            [['text' => 'نماینده پیشرفته', 'callback_data' => 'vsa_add_scope_n2']],
        ], false);
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
        sendmessage($from_id, 'سفارش با موفقیت تحویل شد.', null, 'HTML');
        virtualServicesAdminOrder($orderId);
        return true;
    }
    if (strpos($state, 'vsa_edit_') === 0) {
        $productId = (int) ($data['id'] ?? 0);
        $fieldMap = [
            'vsa_edit_title' => ['title', $value],
            'vsa_edit_price' => ['price', $value],
            'vsa_edit_desc' => ['description', $value === '-' ? '' : $value],
            'vsa_edit_input' => ['input_label', $value === '-' ? null : $value],
        ];
        if (!isset($fieldMap[$state])) {
            return false;
        }
        if ($state === 'vsa_edit_title' && mb_strlen($value, 'UTF-8') > 100) {
            sendmessage($from_id, 'نام محصول حداکثر باید ۱۰۰ کاراکتر باشد.', null, 'HTML');
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
    if (strpos($state, 'vsa_text_') === 0) {
        if ($state === 'vsa_text_button_edit') {
            if (mb_strlen($value, 'UTF-8') > 40) {
                sendmessage($from_id, 'نام دکمه حداکثر باید ۴۰ کاراکتر باشد.', null, 'HTML');
                return true;
            }
            $stmt = $pdo->prepare("INSERT INTO textbot (id_text, text) VALUES ('text_virtual_services', ?) ON DUPLICATE KEY UPDATE text = VALUES(text)");
            $stmt->execute([$value]);
            $datatextbot['text_virtual_services'] = $value;
            if (function_exists('clearSelectCache')) {
                clearSelectCache('textbot');
            }
        } else {
            $keyMap = [
                'vsa_text_home_edit' => 'home_text',
                'vsa_text_pending_edit' => 'manual_pending_text',
                'vsa_text_auto_edit' => 'auto_success_text',
            ];
            if (!isset($keyMap[$state])) {
                return false;
            }
            telegramProductsSetSetting($keyMap[$state], $value);
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
        $isStateContinuation = preg_match('/^vsa_add_(delivery|scope)_/', $datain) === 1;
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
            $categories = $pdo->query('SELECT id, title FROM telegram_product_categories WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
            $rows = [];
            foreach ($categories as $category) {
                $rows[] = [['text' => $category['title'], 'callback_data' => 'vsa_addcat_' . $category['id']]];
            }
            $rows[] = [['text' => 'انصراف', 'callback_data' => 'vsa_products']];
            virtualServicesAdminReply('ابتدا دسته محصول را انتخاب کنید.', $rows);
            return true;
        }
        if (preg_match('/^vsa_addcat_(\d+)$/', $datain, $match)) {
            virtualServicesAdminSetState('vsa_add_title', ['category_id' => (int) $match[1]]);
            virtualServicesAdminReply('نام محصول را ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_products']]]);
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
            $stmt = $pdo->prepare('INSERT INTO telegram_products (category_id, title, description, price, delivery_type, input_label, agent_scope) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([(int) $data['category_id'], $data['title'], $data['description'], (int) $data['price'], $data['delivery_type'], $data['input_label'], $match[1]]);
            $productId = $pdo->lastInsertId();
            virtualServicesAdminClearState();
            virtualServicesAdminProduct($productId);
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
        $textStateMap = [
            'vsa_text_button' => ['vsa_text_button_edit', 'نام جدید دکمه اصلی را ارسال کنید.'],
            'vsa_text_home' => ['vsa_text_home_edit', 'متن صفحه نخست خدمات مجازی را ارسال کنید.'],
            'vsa_text_pending' => ['vsa_text_pending_edit', 'پیام ثبت سفارش دستی را ارسال کنید.'],
            'vsa_text_auto' => ['vsa_text_auto_edit', 'پیام تحویل خودکار را ارسال کنید.'],
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
