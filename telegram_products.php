<?php

const TELEGRAM_PRODUCTS_BUTTON = 'محصولات تلگرامی';

function telegramProductsEnsureSchema()
{
    global $pdo;

    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_products (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category_id INT UNSIGNED NOT NULL,
        title VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        price BIGINT UNSIGNED NOT NULL,
        delivery_type VARCHAR(20) NOT NULL DEFAULT 'manual',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tg_products_category (category_id, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_stock (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        payload TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'available',
        sold_to VARCHAR(200) NULL,
        order_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sold_at DATETIME NULL,
        INDEX idx_tg_stock_available (product_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(200) NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        product_title VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        price BIGINT UNSIGNED NOT NULL,
        delivery_type VARCHAR(20) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        delivery_payload TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at DATETIME NULL,
        delivered_at DATETIME NULL,
        INDEX idx_tg_orders_user (user_id, created_at),
        INDEX idx_tg_orders_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ready = true;
}

function telegramProductsEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function telegramProductsMoney($amount)
{
    return number_format((int) $amount, 0) . ' تومان';
}

function telegramProductsIsAdmin($userId)
{
    global $admin_ids;

    $adminIds = array_map('strval', is_array($admin_ids) ? $admin_ids : []);
    return in_array((string) $userId, $adminIds, true);
}

function telegramProductsReply($text, $replyMarkup = null, $preferEdit = true)
{
    global $from_id, $message_id, $datain;

    if ($preferEdit && $datain !== '' && intval($message_id) > 0) {
        return Editmessagetext($from_id, $message_id, $text, $replyMarkup, 'HTML');
    }

    return sendmessage($from_id, $text, $replyMarkup, 'HTML');
}

function telegramProductsShowHome()
{
    global $pdo;

    $stmt = $pdo->query("SELECT c.id, c.title, COUNT(p.id) AS product_count
        FROM telegram_product_categories c
        LEFT JOIN telegram_products p ON p.category_id = c.id AND p.is_active = 1
        WHERE c.is_active = 1
        GROUP BY c.id, c.title, c.sort_order
        HAVING COUNT(p.id) > 0
        ORDER BY c.sort_order, c.id");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($categories as $category) {
        $rows[] = [[
            'text' => $category['title'] . ' (' . $category['product_count'] . ')',
            'callback_data' => 'tgp_cat_' . $category['id'],
        ]];
    }
    $rows[] = [['text' => 'سفارش‌های من', 'callback_data' => 'tgp_orders']];
    $rows[] = [['text' => 'بازگشت به منوی اصلی', 'callback_data' => 'tgp_main']];

    $text = "<b>محصولات تلگرامی</b>\n\nدسته موردنظر را انتخاب کنید.";
    if (!$categories) {
        $text = "<b>محصولات تلگرامی</b>\n\nدر حال حاضر محصول فعالی ثبت نشده است.";
    }

    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsShowCategory($categoryId)
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT id, title FROM telegram_product_categories WHERE id = ? AND is_active = 1');
    $stmt->execute([(int) $categoryId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$category) {
        telegramProductsShowHome();
        return;
    }

    $stmt = $pdo->prepare("SELECT p.*,
        (SELECT COUNT(*) FROM telegram_product_stock s WHERE s.product_id = p.id AND s.status = 'available') AS stock_count
        FROM telegram_products p
        WHERE p.category_id = ? AND p.is_active = 1
        ORDER BY p.sort_order, p.id");
    $stmt->execute([(int) $categoryId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($products as $product) {
        if ($product['delivery_type'] === 'auto' && (int) $product['stock_count'] === 0) {
            continue;
        }
        $rows[] = [[
            'text' => $product['title'] . ' - ' . telegramProductsMoney($product['price']),
            'callback_data' => 'tgp_view_' . $product['id'],
        ]];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'tgp_home']];

    $text = '<b>' . telegramProductsEscape($category['title']) . '</b>\n\nمحصول موردنظر را انتخاب کنید.';
    if (count($rows) === 1) {
        $text .= "\n\nمحصول موجودی در این دسته وجود ندارد.";
    }
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsGetProduct($productId)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT p.*,
        (SELECT COUNT(*) FROM telegram_product_stock s WHERE s.product_id = p.id AND s.status = 'available') AS stock_count
        FROM telegram_products p
        WHERE p.id = ? AND p.is_active = 1");
    $stmt->execute([(int) $productId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function telegramProductsShowProduct($productId)
{
    $product = telegramProductsGetProduct($productId);
    if (!$product) {
        telegramProductsReply('این محصول در دسترس نیست.', json_encode(['inline_keyboard' => [[['text' => 'بازگشت', 'callback_data' => 'tgp_home']]]], JSON_UNESCAPED_UNICODE));
        return;
    }

    $delivery = $product['delivery_type'] === 'auto' ? 'تحویل خودکار' : 'تحویل توسط ادمین';
    $text = '<b>' . telegramProductsEscape($product['title']) . "</b>\n\n";
    $text .= telegramProductsEscape($product['description']) . "\n\n";
    $text .= '<b>قیمت:</b> ' . telegramProductsMoney($product['price']) . "\n";
    $text .= '<b>نوع تحویل:</b> ' . $delivery;
    if ($product['delivery_type'] === 'auto') {
        $text .= "\n<b>موجودی:</b> " . (int) $product['stock_count'];
    }

    $rows = [];
    if ($product['delivery_type'] !== 'auto' || (int) $product['stock_count'] > 0) {
        $rows[] = [['text' => 'خرید با موجودی کیف پول', 'callback_data' => 'tgp_buy_' . $product['id']]];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'tgp_cat_' . $product['category_id']]];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsCreateDraft($productId)
{
    global $pdo, $from_id;

    $product = telegramProductsGetProduct($productId);
    if (!$product || ($product['delivery_type'] === 'auto' && (int) $product['stock_count'] < 1)) {
        telegramProductsReply('این محصول در حال حاضر قابل خرید نیست.', null);
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO telegram_product_orders
        (user_id, product_id, product_title, price, delivery_type, status)
        VALUES (?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([$from_id, $product['id'], $product['title'], $product['price'], $product['delivery_type']]);
    $orderId = $pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT Balance FROM user WHERE id = ?');
    $stmt->execute([$from_id]);
    $balance = (int) $stmt->fetchColumn();

    $text = "<b>تأیید خرید</b>\n\n";
    $text .= '<b>محصول:</b> ' . telegramProductsEscape($product['title']) . "\n";
    $text .= '<b>مبلغ:</b> ' . telegramProductsMoney($product['price']) . "\n";
    $text .= '<b>موجودی کیف پول:</b> ' . telegramProductsMoney($balance);

    $rows = [];
    if ($balance >= (int) $product['price']) {
        $rows[] = [['text' => 'تأیید و پرداخت', 'callback_data' => 'tgp_pay_' . $orderId]];
    } else {
        $text .= "\n\nموجودی کیف پول شما کافی نیست.";
        $rows[] = [['text' => 'افزایش موجودی', 'callback_data' => 'account']];
    }
    $rows[] = [['text' => 'انصراف', 'callback_data' => 'tgp_view_' . $product['id']]];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsPayOrder($orderId)
{
    global $pdo, $from_id;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM telegram_product_orders WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([(int) $orderId, $from_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || $order['status'] !== 'pending') {
            $pdo->rollBack();
            telegramProductsReply('این سفارش قبلاً پردازش شده یا معتبر نیست.', null);
            return;
        }

        $stmt = $pdo->prepare('SELECT Balance FROM user WHERE id = ? FOR UPDATE');
        $stmt->execute([$from_id]);
        $balance = (int) $stmt->fetchColumn();
        if ($balance < (int) $order['price']) {
            $pdo->rollBack();
            telegramProductsReply('موجودی کیف پول برای این خرید کافی نیست.', json_encode(['inline_keyboard' => [[['text' => 'افزایش موجودی', 'callback_data' => 'account']], [['text' => 'بازگشت', 'callback_data' => 'tgp_home']]]], JSON_UNESCAPED_UNICODE));
            return;
        }

        $stock = null;
        if ($order['delivery_type'] === 'auto') {
            $stmt = $pdo->prepare("SELECT * FROM telegram_product_stock WHERE product_id = ? AND status = 'available' ORDER BY id LIMIT 1 FOR UPDATE");
            $stmt->execute([$order['product_id']]);
            $stock = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$stock) {
                $pdo->rollBack();
                telegramProductsReply('موجودی این محصول تمام شده و مبلغی از کیف پول کسر نشد.', null);
                return;
            }
        }

        $stmt = $pdo->prepare('UPDATE user SET Balance = Balance - ? WHERE id = ?');
        $stmt->execute([(int) $order['price'], $from_id]);

        if ($stock) {
            $stmt = $pdo->prepare("UPDATE telegram_product_stock SET status = 'sold', sold_to = ?, order_id = ?, sold_at = NOW() WHERE id = ?");
            $stmt->execute([$from_id, $order['id'], $stock['id']]);
            $stmt = $pdo->prepare("UPDATE telegram_product_orders SET status = 'delivered', delivery_payload = ?, paid_at = NOW(), delivered_at = NOW() WHERE id = ?");
            $stmt->execute([$stock['payload'], $order['id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE telegram_product_orders SET status = 'paid_pending', paid_at = NOW() WHERE id = ?");
            $stmt->execute([$order['id']]);
        }

        $pdo->commit();
        if (function_exists('clearSelectCache')) {
            clearSelectCache('user');
        }

        if ($stock) {
            $text = "خرید با موفقیت انجام شد.\n\n<b>محصول:</b> " . telegramProductsEscape($order['product_title']);
            $text .= "\n<b>اطلاعات تحویل:</b>\n<code>" . telegramProductsEscape($stock['payload']) . '</code>';
            telegramProductsReply($text, json_encode(['inline_keyboard' => [[['text' => 'سفارش‌های من', 'callback_data' => 'tgp_orders']], [['text' => 'بازگشت به فروشگاه', 'callback_data' => 'tgp_home']]]], JSON_UNESCAPED_UNICODE));
            return;
        }

        telegramProductsNotifyAdmins($order['id'], $order['product_title'], $from_id, $order['price']);
        telegramProductsReply("پرداخت انجام شد و سفارش برای ادمین ارسال شد.\n\n<b>شماره سفارش:</b> <code>{$order['id']}</code>", json_encode(['inline_keyboard' => [[['text' => 'سفارش‌های من', 'callback_data' => 'tgp_orders']], [['text' => 'بازگشت به فروشگاه', 'callback_data' => 'tgp_home']]]], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Telegram products checkout failed: ' . $e->getMessage());
        telegramProductsReply('پرداخت انجام نشد. لطفاً دوباره تلاش کنید.', null);
    }
}

function telegramProductsNotifyAdmins($orderId, $title, $userId, $price)
{
    global $admin_ids;

    $text = "<b>سفارش دستی جدید محصولات تلگرامی</b>\n\n";
    $text .= '<b>شماره سفارش:</b> <code>' . (int) $orderId . "</code>\n";
    $text .= '<b>کاربر:</b> <code>' . telegramProductsEscape($userId) . "</code>\n";
    $text .= '<b>محصول:</b> ' . telegramProductsEscape($title) . "\n";
    $text .= '<b>مبلغ:</b> ' . telegramProductsMoney($price) . "\n\n";
    $text .= "برای تحویل: <code>/tg_deliver {$orderId}|متن یا کد تحویل</code>";
    foreach ((array) $admin_ids as $adminId) {
        sendmessage($adminId, $text, null, 'HTML');
    }
}

function telegramProductsShowOrders()
{
    global $pdo, $from_id;

    $stmt = $pdo->prepare('SELECT id, product_title, price, status, created_at FROM telegram_product_orders WHERE user_id = ? ORDER BY id DESC LIMIT 10');
    $stmt->execute([$from_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusLabels = [
        'pending' => 'در انتظار پرداخت',
        'paid_pending' => 'در انتظار تحویل ادمین',
        'delivered' => 'تحویل‌شده',
        'cancelled' => 'لغوشده',
    ];
    $text = "<b>سفارش‌های محصولات تلگرامی</b>\n\n";
    if (!$orders) {
        $text .= 'هنوز سفارشی ثبت نکرده‌اید.';
    }
    foreach ($orders as $order) {
        $status = $statusLabels[$order['status']] ?? $order['status'];
        $text .= '#' . $order['id'] . ' - ' . telegramProductsEscape($order['product_title']);
        $text .= "\n" . telegramProductsMoney($order['price']) . ' - ' . $status . "\n\n";
    }
    telegramProductsReply($text, json_encode(['inline_keyboard' => [[['text' => 'بازگشت', 'callback_data' => 'tgp_home']]]], JSON_UNESCAPED_UNICODE));
}

function telegramProductsAdminCommand($text)
{
    global $pdo, $from_id;

    if (strpos($text, '/tg_') !== 0) {
        return false;
    }
    if (!telegramProductsIsAdmin($from_id)) {
        sendmessage($from_id, 'شما اجازه اجرای این دستور را ندارید.', null, 'HTML');
        return true;
    }

    if ($text === '/tg_help' || $text === '/tg_products_admin') {
        $help = "<b>مدیریت محصولات تلگرامی</b>\n\n";
        $help .= "<code>/tg_add_category عنوان دسته</code>\n";
        $help .= "<code>/tg_add_product شناسه‌دسته|عنوان|قیمت|auto یا manual|توضیحات</code>\n";
        $help .= "<code>/tg_add_stock شناسه‌محصول|کد یا لینک تحویل</code>\n";
        $help .= "<code>/tg_products</code>\n<code>/tg_orders</code>\n";
        $help .= "<code>/tg_deliver شماره‌سفارش|متن تحویل</code>\n";
        $help .= "<code>/tg_product_on شناسه</code>\n<code>/tg_product_off شناسه</code>";
        sendmessage($from_id, $help, null, 'HTML');
        return true;
    }

    if (preg_match('/^\/tg_add_category\s+(.+)$/u', $text, $match)) {
        $stmt = $pdo->prepare('INSERT INTO telegram_product_categories (title) VALUES (?)');
        $stmt->execute([trim($match[1])]);
        sendmessage($from_id, 'دسته با شناسه <code>' . $pdo->lastInsertId() . '</code> ساخته شد.', null, 'HTML');
        return true;
    }

    if (preg_match('/^\/tg_add_product\s+(.+)$/us', $text, $match)) {
        $parts = array_map('trim', explode('|', $match[1], 5));
        if (count($parts) !== 5 || !ctype_digit($parts[0]) || !ctype_digit($parts[2]) || !in_array($parts[3], ['auto', 'manual'], true)) {
            sendmessage($from_id, 'فرمت دستور صحیح نیست. دستور <code>/tg_help</code> را ببینید.', null, 'HTML');
            return true;
        }
        $stmt = $pdo->prepare('INSERT INTO telegram_products (category_id, title, price, delivery_type, description) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([(int) $parts[0], $parts[1], (int) $parts[2], $parts[3], $parts[4]]);
        sendmessage($from_id, 'محصول با شناسه <code>' . $pdo->lastInsertId() . '</code> ساخته شد.', null, 'HTML');
        return true;
    }

    if (preg_match('/^\/tg_add_stock\s+(\d+)\|(.+)$/us', $text, $match)) {
        $stmt = $pdo->prepare('INSERT INTO telegram_product_stock (product_id, payload) VALUES (?, ?)');
        $stmt->execute([(int) $match[1], trim($match[2])]);
        sendmessage($from_id, 'موجودی خودکار اضافه شد.', null, 'HTML');
        return true;
    }

    if ($text === '/tg_products') {
        $rows = $pdo->query('SELECT p.id, p.title, p.price, p.delivery_type, p.is_active, c.title AS category_title FROM telegram_products p LEFT JOIN telegram_product_categories c ON c.id = p.category_id ORDER BY p.id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
        $output = "<b>فهرست محصولات</b>\n\n";
        foreach ($rows as $row) {
            $output .= '#' . $row['id'] . ' ' . telegramProductsEscape($row['title']) . ' | ' . telegramProductsMoney($row['price']);
            $output .= ' | ' . $row['delivery_type'] . ' | ' . ((int) $row['is_active'] === 1 ? 'فعال' : 'غیرفعال') . "\n";
        }
        sendmessage($from_id, $output, null, 'HTML');
        return true;
    }

    if ($text === '/tg_orders') {
        $stmt = $pdo->query("SELECT id, user_id, product_title, price FROM telegram_product_orders WHERE status = 'paid_pending' ORDER BY id LIMIT 30");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $output = "<b>سفارش‌های منتظر تحویل</b>\n\n";
        if (!$rows) {
            $output .= 'سفارشی در انتظار تحویل نیست.';
        }
        foreach ($rows as $row) {
            $output .= '#' . $row['id'] . ' | کاربر ' . telegramProductsEscape($row['user_id']) . ' | ' . telegramProductsEscape($row['product_title']) . ' | ' . telegramProductsMoney($row['price']) . "\n";
        }
        sendmessage($from_id, $output, null, 'HTML');
        return true;
    }

    if (preg_match('/^\/tg_deliver\s+(\d+)\|(.+)$/us', $text, $match)) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM telegram_product_orders WHERE id = ? AND status = 'paid_pending' FOR UPDATE");
        $stmt->execute([(int) $match[1]]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $pdo->rollBack();
            sendmessage($from_id, 'سفارش منتظر تحویلی با این شناسه پیدا نشد.', null, 'HTML');
            return true;
        }
        $delivery = trim($match[2]);
        $stmt = $pdo->prepare("UPDATE telegram_product_orders SET status = 'delivered', delivery_payload = ?, delivered_at = NOW() WHERE id = ?");
        $stmt->execute([$delivery, $order['id']]);
        $pdo->commit();
        $message = "سفارش شما تحویل شد.\n\n<b>محصول:</b> " . telegramProductsEscape($order['product_title']);
        $message .= "\n<b>اطلاعات تحویل:</b>\n<code>" . telegramProductsEscape($delivery) . '</code>';
        sendmessage($order['user_id'], $message, null, 'HTML');
        sendmessage($from_id, 'سفارش تحویل و برای کاربر ارسال شد.', null, 'HTML');
        return true;
    }

    if (preg_match('/^\/tg_product_(on|off)\s+(\d+)$/', $text, $match)) {
        $stmt = $pdo->prepare('UPDATE telegram_products SET is_active = ? WHERE id = ?');
        $stmt->execute([$match[1] === 'on' ? 1 : 0, (int) $match[2]]);
        sendmessage($from_id, 'وضعیت محصول بروزرسانی شد.', null, 'HTML');
        return true;
    }

    sendmessage($from_id, 'دستور شناخته نشد. <code>/tg_help</code>', null, 'HTML');
    return true;
}

function telegramProductsHandleRequest()
{
    global $text, $datain, $from_id, $callback_query_id, $keyboard;

    $isProductRequest = $text === TELEGRAM_PRODUCTS_BUTTON
        || strpos($text, '/tg_') === 0
        || strpos($datain, 'tgp_') === 0;
    if (!$isProductRequest) {
        return false;
    }

    telegramProductsEnsureSchema();

    if ($callback_query_id) {
        telegram('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
    }

    if (telegramProductsAdminCommand($text)) {
        return true;
    }
    if ($text === TELEGRAM_PRODUCTS_BUTTON || $datain === 'tgp_home') {
        telegramProductsShowHome();
        return true;
    }
    if ($datain === 'tgp_main') {
        global $message_id;
        if ($message_id) {
            deletemessage($from_id, $message_id);
        }
        sendmessage($from_id, 'به منوی اصلی بازگشتید.', $keyboard, 'HTML');
        return true;
    }
    if ($datain === 'tgp_orders') {
        telegramProductsShowOrders();
        return true;
    }
    if (preg_match('/^tgp_cat_(\d+)$/', $datain, $match)) {
        telegramProductsShowCategory($match[1]);
        return true;
    }
    if (preg_match('/^tgp_view_(\d+)$/', $datain, $match)) {
        telegramProductsShowProduct($match[1]);
        return true;
    }
    if (preg_match('/^tgp_buy_(\d+)$/', $datain, $match)) {
        telegramProductsCreateDraft($match[1]);
        return true;
    }
    if (preg_match('/^tgp_pay_(\d+)$/', $datain, $match)) {
        telegramProductsPayOrder($match[1]);
        return true;
    }

    return true;
}

