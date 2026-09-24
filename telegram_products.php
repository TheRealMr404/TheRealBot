<?php

const TELEGRAM_PRODUCTS_BUTTON = 'خدمات مجازی';

function telegramProductsEnsureColumn($table, $column, $definition)
{
    global $pdo;

    $allowed = ['telegram_product_categories', 'telegram_products', 'telegram_product_orders'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Invalid virtual services table.');
    }
    $quotedColumn = $pdo->quote($column);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$quotedColumn}");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (PDOException $e) {
            $mysqlCode = (int) ($e->errorInfo[1] ?? 0);
            if ($mysqlCode !== 1060) {
                throw $e;
            }
        }
    }
}

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
        button_style VARCHAR(20) NOT NULL DEFAULT 'primary',
        button_emoji_id VARCHAR(30) NULL,
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
        input_label VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        agent_scope VARCHAR(50) NOT NULL DEFAULT 'all',
        button_style VARCHAR(20) NOT NULL DEFAULT 'success',
        button_emoji_id VARCHAR(30) NULL,
        low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 3,
        max_per_user INT UNSIGNED NOT NULL DEFAULT 0,
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
        customer_input TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        delivery_payload TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at DATETIME NULL,
        delivered_at DATETIME NULL,
        refunded_at DATETIME NULL,
        INDEX idx_tg_orders_user (user_id, created_at),
        INDEX idx_tg_orders_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_product_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    telegramProductsEnsureColumn('telegram_products', 'input_label', "VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `delivery_type`");
    telegramProductsEnsureColumn('telegram_products', 'agent_scope', "VARCHAR(50) NOT NULL DEFAULT 'all' AFTER `input_label`");
    telegramProductsEnsureColumn('telegram_product_categories', 'button_style', "VARCHAR(20) NOT NULL DEFAULT 'primary' AFTER `title`");
    telegramProductsEnsureColumn('telegram_product_categories', 'button_emoji_id', "VARCHAR(30) NULL AFTER `button_style`");
    telegramProductsEnsureColumn('telegram_products', 'button_style', "VARCHAR(20) NOT NULL DEFAULT 'success' AFTER `agent_scope`");
    telegramProductsEnsureColumn('telegram_products', 'button_emoji_id', "VARCHAR(30) NULL AFTER `button_style`");
    telegramProductsEnsureColumn('telegram_products', 'low_stock_threshold', "INT UNSIGNED NOT NULL DEFAULT 3 AFTER `button_emoji_id`");
    telegramProductsEnsureColumn('telegram_products', 'max_per_user', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER `low_stock_threshold`");
    telegramProductsEnsureColumn('telegram_product_orders', 'customer_input', "TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `status`");
    telegramProductsEnsureColumn('telegram_product_orders', 'refunded_at', "DATETIME NULL AFTER `delivered_at`");

    $defaults = [
        'enabled' => '1',
        'store_title' => 'فروشگاه خدمات مجازی',
        'home_text' => 'از فهرست زیر دسته خدمات موردنظر را انتخاب کنید.',
        'category_text' => 'محصول موردنظر را انتخاب کنید.',
        'checkout_text' => 'لطفاً اطلاعات سفارش را بررسی و پرداخت را تأیید کنید.',
        'manual_pending_text' => 'پرداخت انجام شد و سفارش برای بررسی و تحویل ادمین ثبت شد.',
        'auto_success_text' => 'خرید با موفقیت انجام شد و محصول شما آماده است.',
        'out_of_stock_text' => 'موجودی این محصول در حال حاضر به پایان رسیده است.',
        'disabled_text' => 'بخش خدمات مجازی در حال حاضر غیرفعال است.',
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO telegram_product_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key, $value]);
    }
    try {
        $pdo->prepare("INSERT IGNORE INTO textbot (id_text, text) VALUES ('text_virtual_services', 'خدمات مجازی')")->execute();
    } catch (Throwable $e) {
        error_log('Virtual services text migration skipped: ' . $e->getMessage());
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS topicid (
            report VARCHAR(500) PRIMARY KEY NOT NULL,
            idreport TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->prepare("INSERT IGNORE INTO topicid (report, idreport) VALUES ('virtualservices', '0'), ('virtualservices_error', '0')")->execute();
    } catch (Throwable $e) {
        error_log('Virtual services topic migration skipped: ' . $e->getMessage());
    }

    $ready = true;
}

function telegramProductsSetting($key, $default = '')
{
    global $pdo;

    telegramProductsEnsureSchema();
    $stmt = $pdo->prepare('SELECT setting_value FROM telegram_product_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function telegramProductsSetSetting($key, $value)
{
    global $pdo;

    telegramProductsEnsureSchema();
    $stmt = $pdo->prepare('INSERT INTO telegram_product_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

function telegramProductsButtonText()
{
    global $datatextbot;

    return !empty($datatextbot['text_virtual_services'])
        ? (string) $datatextbot['text_virtual_services']
        : TELEGRAM_PRODUCTS_BUTTON;
}

function telegramProductsEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function telegramProductsSafeCustomText($value)
{
    $value = (string) $value;
    $tokens = [];
    $value = preg_replace_callback('/<tg-emoji\s+emoji-id=["\'](\d{5,30})["\']>(.*?)<\/tg-emoji>/us', function ($match) use (&$tokens) {
        $token = '%%TG_EMOJI_' . count($tokens) . '%%';
        $tokens[$token] = '<tg-emoji emoji-id="' . $match[1] . '">' . telegramProductsEscape(strip_tags($match[2])) . '</tg-emoji>';
        return $token;
    }, $value);
    $value = telegramProductsEscape($value);
    foreach ($tokens as $token => $html) {
        $value = str_replace($token, $html, $value);
    }
    return $value;
}

function telegramProductsPlainText($value)
{
    $value = preg_replace('/<tg-emoji\s+emoji-id=["\']\d+["\']>(.*?)<\/tg-emoji>/us', '$1', (string) $value);
    return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function telegramProductsStyledButton($text, $callbackData, $style = null, $emojiId = null)
{
    $button = [
        'text' => telegramProductsPlainText($text),
        'callback_data' => (string) $callbackData,
    ];
    if (in_array($style, ['primary', 'success', 'danger'], true)) {
        $button['style'] = $style;
    }
    if (preg_match('/^\d{5,30}$/', (string) $emojiId)) {
        $button['icon_custom_emoji_id'] = (string) $emojiId;
    }
    return $button;
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

function telegramProductsApiSucceeded($response)
{
    return is_array($response) && !empty($response['ok']);
}

function telegramProductsCompatibleMarkup($replyMarkup)
{
    if ($replyMarkup === null || $replyMarkup === '') {
        return $replyMarkup;
    }
    $markup = is_string($replyMarkup) ? json_decode($replyMarkup, true) : $replyMarkup;
    if (!is_array($markup)) {
        return $replyMarkup;
    }
    foreach (($markup['inline_keyboard'] ?? []) as $rowIndex => $row) {
        foreach ((array) $row as $buttonIndex => $button) {
            if (!is_array($button)) {
                continue;
            }
            unset($button['style'], $button['icon_custom_emoji_id']);
            $markup['inline_keyboard'][$rowIndex][$buttonIndex] = $button;
        }
    }
    return json_encode($markup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function telegramProductsEnsureReportTopic($reportKey, $topicName, $force = false)
{
    global $pdo, $setting;

    $channelId = (string) ($setting['Channel_Report'] ?? '');
    if ($channelId === '' || $channelId === '0') {
        return 0;
    }
    $stmt = $pdo->prepare('INSERT IGNORE INTO topicid (report, idreport) VALUES (?, ?)');
    $stmt->execute([$reportKey, '0']);
    $stmt = $pdo->prepare('SELECT idreport FROM topicid WHERE report = ?');
    $stmt->execute([$reportKey]);
    $threadId = (int) $stmt->fetchColumn();
    if ($threadId > 0) {
        return $threadId;
    }
    if ($force && $threadId < 0) {
        $stmt = $pdo->prepare("UPDATE topicid SET idreport = '0' WHERE report = ?");
        $stmt->execute([$reportKey]);
        $threadId = 0;
    }
    if ($threadId < 0) {
        return 0;
    }

    $response = telegram('createForumTopic', [
        'chat_id' => $channelId,
        'name' => $topicName,
    ]);
    $createdId = (int) ($response['result']['message_thread_id'] ?? 0);
    $stmt = $pdo->prepare('UPDATE topicid SET idreport = ? WHERE report = ?');
    $stmt->execute([$createdId > 0 ? (string) $createdId : '-1', $reportKey]);
    return $createdId;
}

function telegramProductsReport($type, $text)
{
    global $setting;

    try {
        $isError = in_array($type, ['error', 'stock'], true);
        $threadId = telegramProductsEnsureReportTopic(
            $isError ? 'virtualservices_error' : 'virtualservices',
            $isError ? 'خطاهای خدمات مجازی' : 'خدمات مجازی'
        );
        $channelId = (string) ($setting['Channel_Report'] ?? '');
        if ($threadId < 1 || $channelId === '' || $channelId === '0') {
            return false;
        }
        $response = telegram('sendMessage', [
            'chat_id' => $channelId,
            'message_thread_id' => $threadId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
        if (!is_array($response) || empty($response['ok'])) {
            global $pdo;
            $stmt = $pdo->prepare("UPDATE topicid SET idreport = '0' WHERE report = ?");
            $stmt->execute([$isError ? 'virtualservices_error' : 'virtualservices']);
            error_log('Virtual services topic report was rejected: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('Virtual services report failed: ' . $e->getMessage());
        return false;
    }
}

function telegramProductsReply($text, $replyMarkup = null, $preferEdit = true)
{
    global $from_id, $message_id, $datain;

    if ($preferEdit && $datain !== '' && intval($message_id) > 0) {
        $response = Editmessagetext($from_id, $message_id, $text, $replyMarkup, 'HTML');
        if (telegramProductsApiSucceeded($response)) {
            return $response;
        }
        $compatibleMarkup = telegramProductsCompatibleMarkup($replyMarkup);
        if ($compatibleMarkup !== $replyMarkup) {
            $response = Editmessagetext($from_id, $message_id, $text, $compatibleMarkup, 'HTML');
            if (telegramProductsApiSucceeded($response)) {
                return $response;
            }
        }
        $description = is_array($response) ? (string) ($response['description'] ?? '') : '';
        if (stripos($description, 'message is not modified') !== false) {
            return $response;
        }
        return sendmessage($from_id, $text, $compatibleMarkup ?? $replyMarkup, 'HTML');
    }

    $response = sendmessage($from_id, $text, $replyMarkup, 'HTML');
    if (telegramProductsApiSucceeded($response)) {
        return $response;
    }
    $compatibleMarkup = telegramProductsCompatibleMarkup($replyMarkup);
    if ($compatibleMarkup !== $replyMarkup) {
        return sendmessage($from_id, $text, $compatibleMarkup, 'HTML');
    }
    return $response;
}

function telegramProductsShowHome()
{
    global $pdo, $user;

    $stmt = $pdo->prepare("SELECT c.id, c.title, c.button_style, c.button_emoji_id, COUNT(p.id) AS product_count
        FROM telegram_product_categories c
        LEFT JOIN telegram_products p ON p.category_id = c.id AND p.is_active = 1
            AND (p.agent_scope = 'all' OR FIND_IN_SET(?, p.agent_scope) > 0)
        WHERE c.is_active = 1
        GROUP BY c.id, c.title, c.button_style, c.button_emoji_id, c.sort_order
        HAVING COUNT(p.id) > 0
        ORDER BY c.sort_order, c.id");
    $stmt->execute([$user['agent'] ?? 'f']);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($categories as $category) {
        $rows[] = [telegramProductsStyledButton(
            $category['title'] . ' (' . $category['product_count'] . ')',
            'tgp_cat_' . $category['id'],
            $category['button_style'],
            $category['button_emoji_id']
        )];
    }
    $rows[] = [['text' => 'سفارش‌های من', 'callback_data' => 'tgp_orders', 'style' => 'primary']];
    $rows[] = [['text' => 'بازگشت به منوی اصلی', 'callback_data' => 'tgp_main', 'style' => 'danger']];

    $text = '<b>' . telegramProductsSafeCustomText(telegramProductsSetting('store_title', telegramProductsButtonText())) . "</b>\n\n";
    $text .= telegramProductsSafeCustomText(telegramProductsSetting('home_text', 'دسته موردنظر را انتخاب کنید.'));
    if (!$categories) {
        $text = '<b>' . telegramProductsSafeCustomText(telegramProductsSetting('store_title', telegramProductsButtonText())) . "</b>\n\nدر حال حاضر محصول فعالی ثبت نشده است.";
    }

    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsShowCategory($categoryId)
{
    global $pdo, $user;

    $stmt = $pdo->prepare('SELECT id, title, button_style, button_emoji_id FROM telegram_product_categories WHERE id = ? AND is_active = 1');
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
            AND (p.agent_scope = 'all' OR FIND_IN_SET(?, p.agent_scope) > 0)
        ORDER BY p.sort_order, p.id");
    $stmt->execute([(int) $categoryId, $user['agent'] ?? 'f']);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($products as $product) {
        if ($product['delivery_type'] === 'auto' && (int) $product['stock_count'] === 0) {
            continue;
        }
        $rows[] = [telegramProductsStyledButton(
            $product['title'] . ' - ' . telegramProductsMoney($product['price']),
            'tgp_view_' . $product['id'],
            $product['button_style'],
            $product['button_emoji_id']
        )];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'tgp_home', 'style' => 'danger']];

    $text = '<b>' . telegramProductsSafeCustomText($category['title']) . "</b>\n\n";
    $text .= telegramProductsSafeCustomText(telegramProductsSetting('category_text', 'محصول موردنظر را انتخاب کنید.'));
    if (count($rows) === 1) {
        $text .= "\n\nمحصول موجودی در این دسته وجود ندارد.";
    }
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsGetProduct($productId)
{
    global $pdo, $user;

    $stmt = $pdo->prepare("SELECT p.*,
        (SELECT COUNT(*) FROM telegram_product_stock s WHERE s.product_id = p.id AND s.status = 'available') AS stock_count
        FROM telegram_products p
        WHERE p.id = ? AND p.is_active = 1
            AND (p.agent_scope = 'all' OR FIND_IN_SET(?, p.agent_scope) > 0)");
    $stmt->execute([(int) $productId, $user['agent'] ?? 'f']);
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
    $text = '<b>' . telegramProductsSafeCustomText($product['title']) . "</b>\n\n";
    if (!empty($product['description'])) {
        $text .= telegramProductsSafeCustomText($product['description']) . "\n\n";
    }
    $text .= '<b>قیمت:</b> ' . telegramProductsMoney($product['price']) . "\n";
    $text .= '<b>نوع تحویل:</b> ' . $delivery;
    if ($product['delivery_type'] === 'auto') {
        $text .= "\n<b>موجودی:</b> " . (int) $product['stock_count'];
    }
    if (!empty($product['input_label'])) {
        $text .= "\n<b>اطلاعات لازم:</b> " . telegramProductsSafeCustomText($product['input_label']);
    }
    if ((int) ($product['max_per_user'] ?? 0) > 0) {
        $text .= "\n<b>سقف خرید هر کاربر:</b> " . (int) $product['max_per_user'];
    }

    $rows = [];
    if ($product['delivery_type'] !== 'auto' || (int) $product['stock_count'] > 0) {
        $rows[] = [['text' => 'خرید با موجودی کیف پول', 'callback_data' => 'tgp_buy_' . $product['id'], 'style' => 'success']];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'tgp_cat_' . $product['category_id'], 'style' => 'danger']];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsCreateDraft($productId, $customerInput = null)
{
    global $pdo, $from_id;

    $product = telegramProductsGetProduct($productId);
    if (!$product || ($product['delivery_type'] === 'auto' && (int) $product['stock_count'] < 1)) {
        telegramProductsReply(telegramProductsSafeCustomText(telegramProductsSetting('out_of_stock_text', 'این محصول در حال حاضر قابل خرید نیست.')), null);
        return;
    }

    $maxPerUser = (int) ($product['max_per_user'] ?? 0);
    if ($maxPerUser > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM telegram_product_orders WHERE user_id = ? AND product_id = ? AND status IN ('paid_pending', 'delivered')");
        $stmt->execute([$from_id, $product['id']]);
        if ((int) $stmt->fetchColumn() >= $maxPerUser) {
            telegramProductsReply('سقف خرید مجاز شما برای این محصول تکمیل شده است.', null);
            return;
        }
    }

    $stmt = $pdo->prepare("INSERT INTO telegram_product_orders
        (user_id, product_id, product_title, price, delivery_type, customer_input, status)
        VALUES (?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([$from_id, $product['id'], $product['title'], $product['price'], $product['delivery_type'], $customerInput]);
    $orderId = $pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT Balance, agent, maxbuyagent FROM user WHERE id = ?');
    $stmt->execute([$from_id]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['Balance' => 0, 'agent' => 'f', 'maxbuyagent' => 0];
    $balance = (int) $wallet['Balance'];
    $creditLimit = $wallet['agent'] === 'n2' ? (int) $wallet['maxbuyagent'] : 0;
    $canPay = $balance >= (int) $product['price']
        || ($creditLimit > 0 && ($balance - (int) $product['price']) >= -$creditLimit);

    $text = "<b>تأیید خرید</b>\n\n";
    $text .= telegramProductsSafeCustomText(telegramProductsSetting('checkout_text', 'لطفاً اطلاعات سفارش را بررسی کنید.')) . "\n\n";
    $text .= '<b>محصول:</b> ' . telegramProductsEscape($product['title']) . "\n";
    $text .= '<b>مبلغ:</b> ' . telegramProductsMoney($product['price']) . "\n";
    $text .= '<b>موجودی کیف پول:</b> ' . telegramProductsMoney($balance);
    if ($customerInput !== null && $customerInput !== '') {
        $text .= "\n<b>اطلاعات سفارش:</b> " . telegramProductsEscape($customerInput);
    }

    $rows = [];
    if ($canPay) {
        $rows[] = [['text' => 'تأیید و پرداخت', 'callback_data' => 'tgp_pay_' . $orderId, 'style' => 'success']];
    } else {
        $text .= "\n\nموجودی کیف پول شما کافی نیست.";
        $rows[] = [['text' => 'افزایش موجودی', 'callback_data' => 'account']];
    }
    $rows[] = [['text' => 'انصراف', 'callback_data' => 'tgp_view_' . $product['id'], 'style' => 'danger']];
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

        $stmt = $pdo->prepare('SELECT Balance, agent, maxbuyagent FROM user WHERE id = ? FOR UPDATE');
        $stmt->execute([$from_id]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['Balance' => 0, 'agent' => 'f', 'maxbuyagent' => 0];
        $balance = (int) $wallet['Balance'];
        $creditLimit = $wallet['agent'] === 'n2' ? (int) $wallet['maxbuyagent'] : 0;
        $canPay = $balance >= (int) $order['price']
            || ($creditLimit > 0 && ($balance - (int) $order['price']) >= -$creditLimit);
        if (!$canPay) {
            $pdo->rollBack();
            telegramProductsReply('موجودی کیف پول برای این خرید کافی نیست.', json_encode(['inline_keyboard' => [[['text' => 'افزایش موجودی', 'callback_data' => 'account']], [['text' => 'بازگشت', 'callback_data' => 'tgp_home']]]], JSON_UNESCAPED_UNICODE));
            return;
        }

        $stmt = $pdo->prepare('SELECT max_per_user, is_active, price, delivery_type FROM telegram_products WHERE id = ?');
        $stmt->execute([$order['product_id']]);
        $currentProduct = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$currentProduct || (int) $currentProduct['is_active'] !== 1) {
            $pdo->rollBack();
            telegramProductsReply('این محصول غیرفعال شده است و مبلغی کسر نشد.', null);
            return;
        }
        if ((int) $currentProduct['price'] !== (int) $order['price'] || $currentProduct['delivery_type'] !== $order['delivery_type']) {
            $stmt = $pdo->prepare("UPDATE telegram_product_orders SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$order['id']]);
            $pdo->commit();
            telegramProductsReply('قیمت یا روش تحویل محصول تغییر کرده است. لطفاً سفارش تازه‌ای ثبت کنید؛ مبلغی کسر نشد.', json_encode(['inline_keyboard' => [[['text' => 'مشاهده محصول', 'callback_data' => 'tgp_view_' . $order['product_id']]]]], JSON_UNESCAPED_UNICODE));
            return;
        }
        $maxPerUser = (int) $currentProduct['max_per_user'];
        if ($maxPerUser > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM telegram_product_orders WHERE user_id = ? AND product_id = ? AND id != ? AND status IN ('paid_pending', 'delivered')");
            $stmt->execute([$from_id, $order['product_id'], $order['id']]);
            if ((int) $stmt->fetchColumn() >= $maxPerUser) {
                $pdo->rollBack();
                telegramProductsReply('سقف خرید مجاز شما برای این محصول تکمیل شده است و مبلغی کسر نشد.', null);
                return;
            }
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

        $newBalance = $balance - (int) $order['price'];
        $pdo->commit();
        if (function_exists('clearSelectCache')) {
            clearSelectCache('user');
        }

        if ($stock) {
            $remainingStock = 0;
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM telegram_product_stock WHERE product_id = ? AND status = 'available'");
            $stmt->execute([$order['product_id']]);
            $remainingStock = (int) $stmt->fetchColumn();
            $text = telegramProductsSafeCustomText(telegramProductsSetting('auto_success_text', 'خرید با موفقیت انجام شد.'));
            $text .= "\n\n<b>محصول:</b> " . telegramProductsEscape($order['product_title']);
            $text .= "\n<b>اطلاعات تحویل:</b>\n<code>" . telegramProductsEscape($stock['payload']) . '</code>';
            telegramProductsReply($text, json_encode(['inline_keyboard' => [[['text' => 'سفارش‌های من', 'callback_data' => 'tgp_orders']], [['text' => 'بازگشت به فروشگاه', 'callback_data' => 'tgp_home']]]], JSON_UNESCAPED_UNICODE));
            telegramProductsReport('sale', "<b>خرید خودکار خدمات مجازی</b>\n\n<b>سفارش:</b> <code>#{$order['id']}</code>\n<b>کاربر:</b> <code>" . telegramProductsEscape($from_id) . "</code>\n<b>محصول:</b> " . telegramProductsEscape($order['product_title']) . "\n<b>مبلغ:</b> " . telegramProductsMoney($order['price']) . "\n<b>مانده کیف پول:</b> " . telegramProductsMoney($newBalance) . "\n<b>موجودی باقی‌مانده:</b> {$remainingStock}");
            $product = telegramProductsGetProduct($order['product_id']);
            if ($product && $remainingStock <= (int) ($product['low_stock_threshold'] ?? 0)) {
                telegramProductsReport('stock', "<b>هشدار موجودی خدمات مجازی</b>\n\nمحصول <b>" . telegramProductsEscape($order['product_title']) . "</b> فقط <code>{$remainingStock}</code> موجودی قابل فروش دارد.");
            }
            return;
        }

        telegramProductsNotifyAdmins($order['id'], $order['product_title'], $from_id, $order['price'], $order['customer_input'] ?? '');
        telegramProductsReport('sale', "<b>سفارش دستی جدید خدمات مجازی</b>\n\n<b>سفارش:</b> <code>#{$order['id']}</code>\n<b>کاربر:</b> <code>" . telegramProductsEscape($from_id) . "</code>\n<b>محصول:</b> " . telegramProductsEscape($order['product_title']) . "\n<b>مبلغ:</b> " . telegramProductsMoney($order['price']) . "\n<b>مانده کیف پول:</b> " . telegramProductsMoney($newBalance) . (!empty($order['customer_input']) ? "\n<b>اطلاعات مشتری:</b> <code>" . telegramProductsEscape($order['customer_input']) . '</code>' : ''));
        $pendingText = telegramProductsSafeCustomText(telegramProductsSetting('manual_pending_text', 'پرداخت انجام شد و سفارش برای ادمین ارسال شد.'));
        telegramProductsReply($pendingText . "\n\n<b>شماره سفارش:</b> <code>{$order['id']}</code>", json_encode(['inline_keyboard' => [[['text' => 'سفارش‌های من', 'callback_data' => 'tgp_orders']], [['text' => 'بازگشت به فروشگاه', 'callback_data' => 'tgp_home']]]], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Telegram products checkout failed: ' . $e->getMessage());
        telegramProductsReport('error', "<b>خطای پرداخت خدمات مجازی</b>\n\n<code>" . telegramProductsEscape($e->getMessage()) . '</code>');
        telegramProductsReply('پرداخت انجام نشد. لطفاً دوباره تلاش کنید.', null);
    }
}

function telegramProductsNotifyAdmins($orderId, $title, $userId, $price, $customerInput = '')
{
    global $admin_ids;

    $text = "<b>سفارش دستی جدید محصولات تلگرامی</b>\n\n";
    $text .= '<b>شماره سفارش:</b> <code>' . (int) $orderId . "</code>\n";
    $text .= '<b>کاربر:</b> <code>' . telegramProductsEscape($userId) . "</code>\n";
    $text .= '<b>محصول:</b> ' . telegramProductsEscape($title) . "\n";
    $text .= '<b>مبلغ:</b> ' . telegramProductsMoney($price) . "\n\n";
    if ($customerInput !== '') {
        $text .= '<b>اطلاعات کاربر:</b> <code>' . telegramProductsEscape($customerInput) . "</code>\n\n";
    }
    $text .= 'برای مدیریت سفارش وارد بخش «خدمات مجازی» پنل ادمین شوید.';
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
        'refunded' => 'لغو و بازپرداخت‌شده',
    ];
    $text = "<b>سفارش‌های محصولات تلگرامی</b>\n\n";
    if (!$orders) {
        $text .= 'هنوز سفارشی ثبت نکرده‌اید.';
    }
    $rows = [];
    foreach ($orders as $order) {
        $status = $statusLabels[$order['status']] ?? $order['status'];
        $text .= '#' . $order['id'] . ' - ' . telegramProductsEscape($order['product_title']);
        $text .= "\n" . telegramProductsMoney($order['price']) . ' - ' . $status . "\n\n";
        $rows[] = [[
            'text' => 'مشاهده سفارش #' . $order['id'],
            'callback_data' => 'tgp_order_' . $order['id'],
        ]];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'tgp_home']];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsShowOrder($orderId)
{
    global $pdo, $from_id;

    $stmt = $pdo->prepare('SELECT * FROM telegram_product_orders WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $orderId, $from_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        telegramProductsReply('این سفارش پیدا نشد.', json_encode(['inline_keyboard' => [[['text' => 'بازگشت', 'callback_data' => 'tgp_orders']]]], JSON_UNESCAPED_UNICODE));
        return;
    }
    $labels = [
        'pending' => 'در انتظار پرداخت',
        'paid_pending' => 'در انتظار تحویل ادمین',
        'delivered' => 'تحویل‌شده',
        'cancelled' => 'لغوشده',
        'refunded' => 'لغو و بازپرداخت‌شده',
    ];
    $text = "<b>سفارش #{$order['id']}</b>\n\n";
    $text .= '<b>محصول:</b> ' . telegramProductsEscape($order['product_title']) . "\n";
    $text .= '<b>مبلغ:</b> ' . telegramProductsMoney($order['price']) . "\n";
    $text .= '<b>وضعیت:</b> ' . ($labels[$order['status']] ?? telegramProductsEscape($order['status'])) . "\n";
    if (!empty($order['customer_input'])) {
        $text .= '<b>اطلاعات سفارش:</b> <code>' . telegramProductsEscape($order['customer_input']) . "</code>\n";
    }
    if (!empty($order['delivery_payload'])) {
        $text .= "\n<b>اطلاعات تحویل:</b>\n<code>" . telegramProductsEscape($order['delivery_payload']) . '</code>';
    }
    telegramProductsReply($text, json_encode(['inline_keyboard' => [[['text' => 'بازگشت', 'callback_data' => 'tgp_orders']]]], JSON_UNESCAPED_UNICODE));
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
    global $from_id, $callback_query_id;

    try {
        return telegramProductsHandleRequestInternal();
    } catch (Throwable $e) {
        error_log('Virtual services user handler error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if ($callback_query_id) {
            telegram('answerCallbackQuery', [
                'callback_query_id' => $callback_query_id,
                'text' => 'خطا در بارگذاری خدمات مجازی. دوباره تلاش کنید.',
                'show_alert' => true,
            ]);
        }
        $details = telegramProductsIsAdmin($from_id)
            ? "\n\n<code>" . telegramProductsEscape($e->getMessage()) . '</code>'
            : '';
        sendmessage($from_id, 'خطایی در بارگذاری خدمات مجازی رخ داد.' . $details, null, 'HTML');
        return true;
    }
}

function telegramProductsHandleRequestInternal()
{
    global $text, $datain, $from_id, $callback_query_id, $keyboard, $user, $setting;

    $buttonText = telegramProductsButtonText();
    $isInputStep = strpos((string) ($user['step'] ?? ''), 'tg_product_input_') === 0;
    $isProductRequest = $text === $buttonText
        || $text === TELEGRAM_PRODUCTS_BUTTON
        || strpos($text, '/tg_') === 0
        || strpos($datain, 'tgp_') === 0
        || $isInputStep;
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
    if (telegramProductsSetting('enabled', '1') !== '1') {
        sendmessage($from_id, telegramProductsSafeCustomText(telegramProductsSetting('disabled_text', 'بخش خدمات مجازی در حال حاضر غیرفعال است.')), $keyboard, 'HTML');
        step('home', $from_id);
        return true;
    }
    if (isset($setting['keyboardmain']) && !check_active_btn($setting['keyboardmain'], 'text_virtual_services') && ($text === $buttonText || $text === TELEGRAM_PRODUCTS_BUTTON)) {
        sendmessage($from_id, 'این دکمه غیرفعال است.', $keyboard, 'HTML');
        return true;
    }
    if ($isInputStep && $datain !== '') {
        step('home', $from_id);
        $user['step'] = 'home';
        $isInputStep = false;
    }
    if ($isInputStep) {
        $productId = (int) str_replace('tg_product_input_', '', $user['step']);
        $product = telegramProductsGetProduct($productId);
        if (!$product) {
            step('home', $from_id);
            sendmessage($from_id, 'محصول موردنظر دیگر در دسترس نیست.', $keyboard, 'HTML');
            return true;
        }
        $customerInput = trim((string) $text);
        if ($customerInput === '' || mb_strlen($customerInput, 'UTF-8') > 500) {
            sendmessage($from_id, 'اطلاعات واردشده معتبر نیست. حداکثر ۵۰۰ کاراکتر ارسال کنید.', null, 'HTML');
            return true;
        }
        step('home', $from_id);
        telegramProductsCreateDraft($productId, $customerInput);
        return true;
    }
    if ($text === $buttonText || $text === TELEGRAM_PRODUCTS_BUTTON || $datain === 'tgp_home') {
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
    if (preg_match('/^tgp_order_(\d+)$/', $datain, $match)) {
        telegramProductsShowOrder($match[1]);
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
        $product = telegramProductsGetProduct($match[1]);
        if (!$product) {
            telegramProductsReply('این محصول در دسترس نیست.', null);
            return true;
        }
        if (!empty($product['input_label'])) {
            step('tg_product_input_' . $product['id'], $from_id);
            $prompt = '<b>' . telegramProductsSafeCustomText($product['input_label']) . "</b>\n\nاطلاعات را با دقت ارسال کنید.";
            telegramProductsReply($prompt, json_encode(['inline_keyboard' => [[['text' => 'انصراف', 'callback_data' => 'tgp_view_' . $product['id']]]]], JSON_UNESCAPED_UNICODE));
            return true;
        }
        telegramProductsCreateDraft($match[1], null);
        return true;
    }
    if (preg_match('/^tgp_pay_(\d+)$/', $datain, $match)) {
        telegramProductsPayOrder($match[1]);
        return true;
    }

    return true;
}
