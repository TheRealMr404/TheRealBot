<?php

function telegramCommerceNormalizeDigits($value)
{
    return strtr((string) $value, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

function telegramCommerceConfig()
{
    global $dbname;
    static $config = null;
    if ($config !== null) return $config;

    $safeDb = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $dbname);
    $values = [];
    foreach (["/etc/mirza/telegram-commerce-{$safeDb}.env", '/etc/mirza/telegram-commerce.env'] as $file) {
        if (is_readable($file)) {
            $parsed = parse_ini_file($file, false, INI_SCANNER_RAW);
            if (is_array($parsed)) $values = array_merge($values, $parsed);
        }
    }
    $read = static function ($name, $default = '') use ($values) {
        $environment = getenv($name);
        if ($environment !== false && $environment !== '') return trim((string) $environment);
        return isset($values[$name]) ? trim((string) $values[$name]) : $default;
    };
    $config = [
        'url' => rtrim($read('TELEGRAM_COMMERCE_API_URL', 'http://127.0.0.1:8088'), '/'),
        'key' => $read('TELEGRAM_COMMERCE_API_KEY'),
        'webhook_secret' => $read('TELEGRAM_COMMERCE_WEBHOOK_SECRET'),
    ];
    return $config;
}

function telegramCommerceEnsureSchema()
{
    global $pdo;
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_commerce_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_commerce_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(200) NOT NULL,
        api_order_id VARCHAR(36) NULL UNIQUE,
        api_product_id VARCHAR(36) NOT NULL,
        product_title VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        product_kind VARCHAR(20) NOT NULL,
        recipient VARCHAR(100) NOT NULL,
        amount BIGINT UNSIGNED NOT NULL,
        currency VARCHAR(12) NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'draft',
        idempotency_key VARCHAR(100) NOT NULL UNIQUE,
        wallet_debited TINYINT(1) NOT NULL DEFAULT 0,
        wallet_refunded TINYINT(1) NOT NULL DEFAULT 0,
        provider_reference VARCHAR(255) NULL,
        transaction_hash VARCHAR(255) NULL,
        failure_message TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        notified_status VARCHAR(40) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        paid_at DATETIME NULL,
        completed_at DATETIME NULL,
        INDEX idx_tc_user (user_id, created_at),
        INDEX idx_tc_status (status, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_commerce_webhook_events (
        event_id VARCHAR(64) PRIMARY KEY,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $stmt = $pdo->prepare('INSERT IGNORE INTO telegram_commerce_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach (['enabled' => '0', 'menu_title' => 'استارز و پریمیوم خودکار'] as $key => $value) {
        $stmt->execute([$key, $value]);
    }
    $ready = true;
}

function telegramCommerceSetting($key, $default = '')
{
    global $pdo;
    telegramCommerceEnsureSchema();
    $stmt = $pdo->prepare('SELECT setting_value FROM telegram_commerce_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function telegramCommerceSetSetting($key, $value)
{
    global $pdo;
    telegramCommerceEnsureSchema();
    $stmt = $pdo->prepare('INSERT INTO telegram_commerce_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $stmt->execute([$key, (string) $value]);
}

function telegramCommerceReady()
{
    $config = telegramCommerceConfig();
    return $config['key'] !== '' && preg_match('#^https?://#i', $config['url']);
}

function telegramCommerceApi($method, $path, $payload = null, array $extraHeaders = [])
{
    $config = telegramCommerceConfig();
    if (!telegramCommerceReady()) return ['ok' => false, 'status' => 0, 'error' => 'API_NOT_CONFIGURED', 'data' => null];
    $curl = curl_init($config['url'] . $path);
    $headers = array_merge(['Accept: application/json', 'X-API-Key: ' . $config['key']], $extraHeaders);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'ok' => $status >= 200 && $status < 300 && is_array($data),
        'status' => $status,
        'error' => $error !== '' ? $error : (is_array($data) ? ($data['detail'] ?? null) : 'INVALID_API_RESPONSE'),
        'data' => $data,
    ];
}

function telegramCommerceProducts($kind = null, $includeInactive = false)
{
    $path = $includeInactive ? '/v1/admin/products' : '/v1/products';
    if (!$includeInactive && in_array($kind, ['stars', 'premium'], true)) $path .= '?kind=' . rawurlencode($kind);
    $response = telegramCommerceApi('GET', $path);
    return $response['ok'] && is_array($response['data']) ? $response['data'] : null;
}

function telegramCommerceProduct($productId, $includeInactive = false)
{
    $products = telegramCommerceProducts(null, $includeInactive);
    if (!is_array($products)) return null;
    foreach ($products as $product) {
        if (($product['id'] ?? '') === $productId) return $product;
    }
    return null;
}

function telegramCommerceMoney($amount, $currency)
{
    $labels = ['IRR' => 'ریال', 'TOMAN' => 'تومان'];
    $currency = strtoupper((string) $currency);
    return number_format((int) $amount) . ' ' . ($labels[$currency] ?? telegramProductsEscape($currency));
}

function telegramCommerceAddHomeButton(array &$rows)
{
    if (telegramCommerceSetting('enabled', '0') !== '1' || !telegramCommerceReady()) return;
    $button = [['text' => telegramCommerceSetting('menu_title', 'استارز و پریمیوم خودکار'), 'callback_data' => 'tgp_tc_home', 'style' => 'success']];
    $insertAt = max(0, count($rows) - 2);
    array_splice($rows, $insertAt, 0, [$button]);
}

function telegramCommerceShowHome()
{
    $rows = [
        [['text' => 'خرید استارز تلگرام', 'callback_data' => 'tgp_tc_kind_stars', 'style' => 'primary']],
        [['text' => 'خرید تلگرام پریمیوم', 'callback_data' => 'tgp_tc_kind_premium', 'style' => 'success']],
        [['text' => 'سفارش‌های خودکار من', 'callback_data' => 'tgp_tc_orders']],
        [['text' => 'بازگشت', 'callback_data' => 'tgp_home']],
    ];
    telegramProductsReply("<b>استارز و پریمیوم خودکار</b>\n\nسرویس موردنظر را انتخاب کنید. پس از پرداخت، سفارش به‌صورت خودکار پردازش می‌شود.", json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramCommerceShowProducts($kind)
{
    $products = telegramCommerceProducts($kind);
    if ($products === null) {
        telegramProductsReply('ارتباط با سرویس فروش برقرار نشد. لطفاً کمی بعد دوباره تلاش کنید.', json_encode(['inline_keyboard' => [[['text' => 'بازگشت', 'callback_data' => 'tgp_tc_home']]]], JSON_UNESCAPED_UNICODE));
        return;
    }
    $rows = [];
    foreach ($products as $product) {
        $rows[] = [[
            'text' => ($product['title'] ?? 'محصول') . ' - ' . telegramCommerceMoney($product['price_amount'] ?? 0, $product['price_currency'] ?? ''),
            'callback_data' => 'tgp_tc_p_' . $product['id'],
        ]];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'tgp_tc_home']];
    $title = $kind === 'stars' ? 'پکیج‌های استارز' : 'پلن‌های تلگرام پریمیوم';
    $text = '<b>' . $title . "</b>\n\nپلن موردنظر را انتخاب کنید.";
    if (!$products) $text .= "\n\nدر حال حاضر پلن فعالی ثبت نشده است.";
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramCommerceShowProduct($productId)
{
    $product = telegramCommerceProduct($productId);
    if (!$product) {
        telegramProductsReply('این پلن در دسترس نیست.', json_encode(['inline_keyboard' => [[['text' => 'بازگشت', 'callback_data' => 'tgp_tc_home']]]], JSON_UNESCAPED_UNICODE));
        return;
    }
    $text = '<b>' . telegramProductsEscape($product['title']) . "</b>\n\n";
    if (!empty($product['description'])) $text .= telegramProductsEscape($product['description']) . "\n\n";
    if (($product['kind'] ?? '') === 'stars') $text .= '<b>تعداد استارز:</b> ' . (int) ($product['units'] ?? 0) . "\n";
    if (($product['kind'] ?? '') === 'premium') $text .= '<b>مدت اشتراک:</b> ' . (int) ($product['months'] ?? 0) . " ماه\n";
    $text .= '<b>مبلغ:</b> ' . telegramCommerceMoney($product['price_amount'], $product['price_currency']);
    $rows = [
        [['text' => 'ادامه خرید', 'callback_data' => 'tgp_tc_buy_' . $product['id'], 'style' => 'success']],
        [['text' => 'بازگشت', 'callback_data' => 'tgp_tc_kind_' . $product['kind']]],
    ];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramCommerceCreateDraft($productId, $recipient)
{
    global $pdo, $from_id;
    $product = telegramCommerceProduct($productId);
    if (!$product) {
        telegramProductsReply('پلن انتخاب‌شده دیگر در دسترس نیست.', null);
        return;
    }
    $recipient = ltrim(trim((string) $recipient), '@');
    if (!preg_match('/^(?:[A-Za-z][A-Za-z0-9_]{4,31}|\d{5,20})$/', $recipient)) {
        telegramProductsReply('نام کاربری یا شناسه عددی معتبر نیست. نمونه صحیح: <code>username</code>', null);
        return;
    }
    $idempotency = 'mirza-' . substr(hash('sha256', $from_id . '|' . $productId . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX)), 0, 48);
    $stmt = $pdo->prepare("INSERT INTO telegram_commerce_orders
        (user_id, api_product_id, product_title, product_kind, recipient, amount, currency, idempotency_key)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$from_id, $product['id'], $product['title'], $product['kind'], $recipient, (int) $product['price_amount'], strtoupper($product['price_currency']), $idempotency]);
    telegramCommerceShowCheckout((int) $pdo->lastInsertId());
}

function telegramCommerceShowCheckout($orderId)
{
    global $pdo, $from_id;
    $stmt = $pdo->prepare("SELECT * FROM telegram_commerce_orders WHERE id=? AND user_id=?");
    $stmt->execute([(int) $orderId, $from_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) return;
    $text = "<b>فاکتور خرید خودکار</b>\n\n";
    $text .= '<b>محصول:</b> ' . telegramProductsEscape($order['product_title']) . "\n";
    $text .= '<b>گیرنده:</b> @' . telegramProductsEscape($order['recipient']) . "\n";
    $text .= '<b>مبلغ:</b> ' . telegramCommerceMoney($order['amount'], $order['currency']) . "\n\n";
    $text .= 'اطلاعات گیرنده را بررسی و سپس پرداخت را تأیید کنید.';
    $rows = [
        [['text' => 'تأیید و پرداخت', 'callback_data' => 'tgp_tc_pay_' . $order['id'], 'style' => 'success']],
        [['text' => 'انصراف', 'callback_data' => 'tgp_tc_home', 'style' => 'danger']],
    ];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramCommerceRefundLocalOrder($orderId, $reason, $allowSubmitted = false)
{
    global $pdo;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM telegram_commerce_orders WHERE id=? FOR UPDATE');
        $stmt->execute([(int) $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order && (int) $order['wallet_debited'] === 1 && (int) $order['wallet_refunded'] === 0 && ($allowSubmitted || empty($order['api_order_id']))) {
            $pdo->prepare('UPDATE user SET Balance=Balance+? WHERE id=?')->execute([(int) $order['amount'], $order['user_id']]);
            $pdo->prepare("UPDATE telegram_commerce_orders SET wallet_refunded=1,status='failed',failure_message=? WHERE id=?")->execute([$reason, $order['id']]);
        }
        $pdo->commit();
        if (function_exists('clearSelectCache')) clearSelectCache('user');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function telegramCommerceSubmitOrder($orderId)
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM telegram_commerce_orders WHERE id=?');
    $stmt->execute([(int) $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || !(int) $order['wallet_debited'] || !empty($order['api_order_id'])) return $order;
    $payload = [
        'product_id' => $order['api_product_id'],
        'recipient' => $order['recipient'],
        'client_reference' => 'mirza-order-' . $order['id'],
        'payment' => [
            'external_reference' => 'mirza-wallet-' . $order['id'],
            'amount' => (int) $order['amount'],
            'currency' => $order['currency'],
            'method' => 'mirza_wallet',
        ],
    ];
    $response = telegramCommerceApi('POST', '/v1/orders', $payload, ['Idempotency-Key: ' . $order['idempotency_key']]);
    if ($response['ok']) {
        $apiOrder = $response['data'];
        $stmt = $pdo->prepare('UPDATE telegram_commerce_orders SET api_order_id=?,status=?,provider_reference=?,transaction_hash=?,failure_message=NULL WHERE id=?');
        $stmt->execute([$apiOrder['id'], $apiOrder['status'], $apiOrder['provider_reference'] ?? null, $apiOrder['transaction_hash'] ?? null, $order['id']]);
    } elseif (in_array((int) $response['status'], [400, 401, 403, 404, 409, 422], true)) {
        telegramCommerceRefundLocalOrder($order['id'], (string) $response['error']);
    } else {
        $stmt = $pdo->prepare("UPDATE telegram_commerce_orders SET status='submission_uncertain',failure_message=? WHERE id=?");
        $stmt->execute([(string) $response['error'], $order['id']]);
    }
    $stmt = $pdo->prepare('SELECT * FROM telegram_commerce_orders WHERE id=?');
    $stmt->execute([$order['id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function telegramCommercePayOrder($orderId)
{
    global $pdo, $from_id;
    $product = null;
    $stmt = $pdo->prepare('SELECT * FROM telegram_commerce_orders WHERE id=? AND user_id=?');
    $stmt->execute([(int) $orderId, $from_id]);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$before || $before['status'] !== 'draft') {
        telegramProductsReply('این سفارش قبلاً پردازش شده یا معتبر نیست.', null);
        return;
    }
    $product = telegramCommerceProduct($before['api_product_id']);
    if (!$product || (int) $product['price_amount'] !== (int) $before['amount'] || strtoupper($product['price_currency']) !== $before['currency']) {
        telegramProductsReply('قیمت یا وضعیت پلن تغییر کرده است؛ مبلغی کسر نشد.', null);
        return;
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM telegram_commerce_orders WHERE id=? AND user_id=? FOR UPDATE');
        $stmt->execute([(int) $orderId, $from_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || $order['status'] !== 'draft') throw new RuntimeException('ORDER_ALREADY_PROCESSED');
        $stmt = $pdo->prepare('SELECT Balance,agent,maxbuyagent FROM user WHERE id=? FOR UPDATE');
        $stmt->execute([$from_id]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        $balance = (int) ($wallet['Balance'] ?? 0);
        $credit = ($wallet['agent'] ?? '') === 'n2' ? (int) ($wallet['maxbuyagent'] ?? 0) : 0;
        if ($balance < (int) $order['amount'] && !($credit > 0 && $balance - (int) $order['amount'] >= -$credit)) {
            $pdo->rollBack();
            telegramProductsReply('موجودی کیف پول برای این خرید کافی نیست.', json_encode(['inline_keyboard' => [[['text' => 'افزایش موجودی', 'callback_data' => 'account']], [['text' => 'بازگشت', 'callback_data' => 'tgp_tc_p_' . $order['api_product_id']]]]], JSON_UNESCAPED_UNICODE));
            return;
        }
        $pdo->prepare('UPDATE user SET Balance=Balance-? WHERE id=?')->execute([(int) $order['amount'], $from_id]);
        $pdo->prepare("UPDATE telegram_commerce_orders SET wallet_debited=1,status='submitting',paid_at=NOW() WHERE id=?")->execute([$order['id']]);
        $pdo->commit();
        if (function_exists('clearSelectCache')) clearSelectCache('user');
        $order = telegramCommerceSubmitOrder($order['id']);
        if ($order['status'] === 'failed' && (int) $order['wallet_refunded'] === 1) {
            telegramProductsReply('ثبت سفارش توسط سرویس رد شد و مبلغ کامل به کیف پول برگشت.', null);
            return;
        }
        telegramProductsReply("پرداخت ثبت شد و سفارش وارد صف پردازش خودکار شد.\n\n<b>شماره سفارش:</b> <code>#{$order['id']}</code>", json_encode(['inline_keyboard' => [[['text' => 'مشاهده وضعیت', 'callback_data' => 'tgp_tc_order_' . $order['id']]], [['text' => 'بازگشت', 'callback_data' => 'tgp_tc_home']]]], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Telegram commerce payment error: ' . $e->getMessage());
        telegramProductsReply('پرداخت انجام نشد. لطفاً دوباره تلاش کنید.', null);
    }
}

function telegramCommerceRefreshOrder(array $order)
{
    global $pdo;
    if ($order['status'] === 'submission_uncertain' && empty($order['api_order_id'])) {
        return telegramCommerceSubmitOrder($order['id']);
    }
    if (empty($order['api_order_id'])) return $order;
    $response = telegramCommerceApi('GET', '/v1/orders/' . rawurlencode($order['api_order_id']));
    if (!$response['ok']) return $order;
    $api = $response['data'];
    $stmt = $pdo->prepare('UPDATE telegram_commerce_orders SET status=?,provider_reference=?,transaction_hash=?,failure_message=?,completed_at=IF(?="fulfilled",NOW(),completed_at) WHERE id=?');
    $stmt->execute([$api['status'], $api['provider_reference'] ?? null, $api['transaction_hash'] ?? null, $api['failure_message'] ?? null, $api['status'], $order['id']]);
    if ($api['status'] === 'failed' && (int) $order['wallet_debited'] === 1 && (int) $order['wallet_refunded'] === 0) {
        telegramCommerceRefundLocalOrder($order['id'], (string) ($api['failure_message'] ?? 'PROVIDER_REJECTED'), true);
        $order['wallet_refunded'] = 1;
    }
    return array_merge($order, [
        'status' => $api['status'],
        'provider_reference' => $api['provider_reference'] ?? null,
        'transaction_hash' => $api['transaction_hash'] ?? null,
        'failure_message' => $api['failure_message'] ?? null,
    ]);
}

function telegramCommerceStatusLabel($status)
{
    return [
        'draft' => 'در انتظار پرداخت', 'submitting' => 'در حال ثبت', 'submission_uncertain' => 'در حال بررسی ثبت',
        'awaiting_payment' => 'در انتظار پرداخت', 'queued' => 'در صف پردازش', 'processing' => 'در حال خرید',
        'fulfilled' => 'انجام‌شده', 'reconciliation_required' => 'نیازمند بررسی پشتیبانی',
        'failed' => 'ناموفق', 'canceled' => 'لغوشده', 'refunded' => 'بازپرداخت‌شده',
    ][$status] ?? $status;
}

function telegramCommerceShowOrder($orderId)
{
    global $pdo, $from_id;
    $stmt = $pdo->prepare('SELECT * FROM telegram_commerce_orders WHERE id=? AND user_id=?');
    $stmt->execute([(int) $orderId, $from_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        telegramProductsReply('سفارش پیدا نشد.', null);
        return;
    }
    $order = telegramCommerceRefreshOrder($order);
    $text = '<b>سفارش خودکار #' . (int) $order['id'] . "</b>\n\n";
    $text .= '<b>محصول:</b> ' . telegramProductsEscape($order['product_title']) . "\n";
    $text .= '<b>گیرنده:</b> @' . telegramProductsEscape($order['recipient']) . "\n";
    $text .= '<b>مبلغ:</b> ' . telegramCommerceMoney($order['amount'], $order['currency']) . "\n";
    $text .= '<b>وضعیت:</b> ' . telegramProductsEscape(telegramCommerceStatusLabel($order['status']));
    if (!empty($order['transaction_hash'])) $text .= "\n<b>شناسه تراکنش:</b> <code>" . telegramProductsEscape($order['transaction_hash']) . '</code>';
    if ($order['status'] === 'reconciliation_required') $text .= "\n\nسفارش برای جلوگیری از خرید تکراری به بررسی پشتیبانی ارسال شده است.";
    $rows = [];
    if (in_array($order['status'], ['queued', 'processing', 'submission_uncertain'], true)) $rows[] = [['text' => 'تازه‌سازی وضعیت', 'callback_data' => 'tgp_tc_order_' . $order['id']]];
    $rows[] = [['text' => 'بازگشت به سفارش‌ها', 'callback_data' => 'tgp_tc_orders']];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramCommerceShowOrders()
{
    global $pdo, $from_id;
    $stmt = $pdo->prepare('SELECT * FROM telegram_commerce_orders WHERE user_id=? ORDER BY id DESC LIMIT 15');
    $stmt->execute([$from_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($orders as $order) {
        $rows[] = [['text' => '#' . $order['id'] . ' - ' . $order['product_title'] . ' - ' . telegramCommerceStatusLabel($order['status']), 'callback_data' => 'tgp_tc_order_' . $order['id']]];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'tgp_tc_home']];
    $text = "<b>سفارش‌های خودکار من</b>" . (!$orders ? "\n\nهنوز سفارشی ثبت نکرده‌اید." : '');
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramCommerceHandleUserRequest()
{
    global $datain, $text, $user, $from_id;
    $stepName = (string) ($user['step'] ?? '');
    if (strpos((string) $datain, 'tgp_tc_') !== 0 && strpos($stepName, 'tgp_tc_') !== 0) return false;
    telegramCommerceEnsureSchema();
    if (telegramCommerceSetting('enabled', '0') !== '1') {
        telegramProductsReply('فروش خودکار استارز و پریمیوم در حال حاضر غیرفعال است.', null);
        step('home', $from_id);
        return true;
    }
    if (strpos($stepName, 'tgp_tc_recipient_') === 0 && $datain === '') {
        $productId = substr($stepName, strlen('tgp_tc_recipient_'));
        step('home', $from_id);
        telegramCommerceCreateDraft($productId, $text);
        return true;
    }
    if ($datain === 'tgp_tc_home') { telegramCommerceShowHome(); return true; }
    if ($datain === 'tgp_tc_orders') { telegramCommerceShowOrders(); return true; }
    if (preg_match('/^tgp_tc_kind_(stars|premium)$/', $datain, $m)) { telegramCommerceShowProducts($m[1]); return true; }
    if (preg_match('/^tgp_tc_p_([a-f0-9-]{36})$/i', $datain, $m)) { telegramCommerceShowProduct($m[1]); return true; }
    if (preg_match('/^tgp_tc_buy_([a-f0-9-]{36})$/i', $datain, $m)) {
        $product = telegramCommerceProduct($m[1]);
        if (!$product) { telegramProductsReply('این پلن در دسترس نیست.', null); return true; }
        step('tgp_tc_recipient_' . $m[1], $from_id);
        $hint = ($product['provider'] ?? '') === 'telegram_bot'
            ? 'شناسه عددی تلگرام گیرنده را ارسال کنید.'
            : 'نام کاربری تلگرام گیرنده را بدون @ ارسال کنید.';
        telegramProductsReply("<b>اطلاعات گیرنده</b>\n\n{$hint}\nاطلاعات را با دقت بررسی کنید؛ سفارش خریداری‌شده قابل برگشت نیست.", json_encode(['inline_keyboard' => [[['text' => 'انصراف', 'callback_data' => 'tgp_tc_p_' . $m[1]]]]], JSON_UNESCAPED_UNICODE));
        return true;
    }
    if (preg_match('/^tgp_tc_pay_(\d+)$/', $datain, $m)) { telegramCommercePayOrder($m[1]); return true; }
    if (preg_match('/^tgp_tc_order_(\d+)$/', $datain, $m)) { telegramCommerceShowOrder($m[1]); return true; }
    return true;
}

function telegramCommerceAdminHomeButton(array &$rows)
{
    $insertAt = max(0, count($rows) - 1);
    array_splice($rows, $insertAt, 0, [[['text' => 'فروش خودکار استارز و پریمیوم', 'callback_data' => 'vsa_tc_home']]]);
}

function telegramCommerceAdminHome()
{
    $enabled = telegramCommerceSetting('enabled', '0') === '1';
    $ready = telegramCommerceReady();
    $health = $ready ? telegramCommerceApi('GET', '/healthz') : ['ok' => false];
    $products = $ready ? telegramCommerceProducts(null, true) : null;
    $text = "<b>فروش خودکار استارز و پریمیوم</b>\n\n";
    $text .= 'وضعیت فروش: ' . ($enabled ? 'فعال' : 'غیرفعال') . "\n";
    $text .= 'تنظیمات اتصال: ' . ($ready ? 'تکمیل' : 'ناقص') . "\n";
    $text .= 'ارتباط با API: ' . (!empty($health['ok']) ? 'برقرار' : 'قطع') . "\n";
    $text .= 'تعداد پلن‌ها: <code>' . (is_array($products) ? count($products) : 0) . "</code>\n\n";
    $text .= 'کلید API در پیام یا دیتابیس ربات ذخیره نمی‌شود و باید در فایل محافظت‌شده سرور قرار بگیرد.';
    $rows = [
        [['text' => $enabled ? 'غیرفعال‌سازی فروش' : 'فعال‌سازی فروش', 'callback_data' => 'vsa_tc_toggle']],
        [['text' => 'مدیریت پلن‌ها', 'callback_data' => 'vsa_tc_products'], ['text' => 'بررسی اتصال', 'callback_data' => 'vsa_tc_test']],
        [['text' => 'راهنمای اتصال و webhook', 'callback_data' => 'vsa_tc_help']],
        [['text' => 'بازگشت', 'callback_data' => 'vsa_home']],
    ];
    virtualServicesAdminReply($text, $rows);
}

function telegramCommerceAdminProducts()
{
    $products = telegramCommerceProducts(null, true);
    if ($products === null) {
        virtualServicesAdminReply('دریافت پلن‌ها از API ناموفق بود.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]);
        return;
    }
    $rows = [];
    foreach ($products as $product) {
        $rows[] = [['text' => $product['title'] . ' | ' . telegramCommerceMoney($product['price_amount'], $product['price_currency']) . ' | ' . (!empty($product['active']) ? 'فعال' : 'غیرفعال'), 'callback_data' => 'vsa_tc_p_' . $product['id']]];
    }
    $rows[] = [['text' => 'افزودن پکیج استارز', 'callback_data' => 'vsa_tc_add_stars'], ['text' => 'افزودن پلن پریمیوم', 'callback_data' => 'vsa_tc_add_premium']];
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']];
    virtualServicesAdminReply("<b>مدیریت پلن‌های خودکار</b>\n\nبرای ویرایش هر پلن روی آن بزنید.", $rows);
}

function telegramCommerceAdminProduct($productId)
{
    $product = telegramCommerceProduct($productId, true);
    if (!$product) { telegramCommerceAdminProducts(); return; }
    $text = '<b>' . telegramProductsEscape($product['title']) . "</b>\n\n";
    $text .= 'نوع: ' . ($product['kind'] === 'stars' ? 'استارز' : 'پریمیوم') . "\n";
    $text .= 'قیمت: ' . telegramCommerceMoney($product['price_amount'], $product['price_currency']) . "\n";
    $text .= 'ارائه‌دهنده: <code>' . telegramProductsEscape($product['provider'] ?? 'پیش‌فرض') . "</code>\n";
    $text .= 'وضعیت: ' . (!empty($product['active']) ? 'فعال' : 'غیرفعال');
    $rows = [
        [['text' => 'تغییر قیمت', 'callback_data' => 'vsa_tc_price_' . $product['id']], ['text' => !empty($product['active']) ? 'غیرفعال‌سازی' : 'فعال‌سازی', 'callback_data' => 'vsa_tc_togglep_' . $product['id']]],
        [['text' => 'بازگشت', 'callback_data' => 'vsa_tc_products']],
    ];
    virtualServicesAdminReply($text, $rows);
}

function telegramCommerceAdminHandleRequest()
{
    global $datain, $text, $user, $from_id;
    $state = (string) ($user['step'] ?? '');
    if (strpos((string) $datain, 'vsa_tc_') !== 0 && strpos($state, 'vsa_tc_') !== 0) return false;
    telegramCommerceEnsureSchema();
    if ($datain !== '' && strpos($state, 'vsa_tc_') === 0) {
        virtualServicesAdminClearState();
        $state = 'home';
    }
    if ($datain === '' && preg_match('/^vsa_tc_price_([a-f0-9-]{36})$/i', $state, $m)) {
        $price = telegramCommerceNormalizeDigits(trim((string) $text));
        if (!ctype_digit($price) || (int) $price < 1) {
            virtualServicesAdminReply('قیمت معتبر را فقط به‌صورت عدد ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_tc_p_' . $m[1]]]]);
            return true;
        }
        $response = telegramCommerceApi('PATCH', '/v1/admin/products/' . $m[1], ['price_amount' => (int) $price]);
        virtualServicesAdminClearState();
        if (!$response['ok']) { virtualServicesAdminReply('تغییر قیمت ناموفق بود: <code>' . telegramProductsEscape($response['error']) . '</code>', [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_products']]]); return true; }
        telegramCommerceAdminProduct($m[1]); return true;
    }
    if ($datain === '' && preg_match('/^vsa_tc_add_(stars|premium)$/', $state, $m)) {
        $parts = array_map('trim', explode('|', telegramCommerceNormalizeDigits((string) $text)));
        if (count($parts) !== 4 || !ctype_digit($parts[1]) || !ctype_digit($parts[2]) || !in_array($parts[3], ['fragment', 'telegram_bot', 'mock'], true)) {
            virtualServicesAdminReply('فرمت اطلاعات صحیح نیست. نمونه را دقیقاً مطابق راهنما ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_tc_products']]]); return true;
        }
        $kind = $m[1];
        $payload = [
            'sku' => $kind . '-' . $parts[1] . '-' . substr(hash('sha256', microtime(true)), 0, 8),
            'kind' => $kind,
            'title' => $parts[0],
            'price_amount' => (int) $parts[2],
            'price_currency' => 'TOMAN',
            'provider' => $parts[3],
        ];
        if ($kind === 'stars') $payload['units'] = (int) $parts[1]; else $payload['months'] = (int) $parts[1];
        $response = telegramCommerceApi('POST', '/v1/admin/products', $payload);
        virtualServicesAdminClearState();
        if (!$response['ok']) { virtualServicesAdminReply('ساخت پلن ناموفق بود: <code>' . telegramProductsEscape($response['error']) . '</code>', [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_products']]]); return true; }
        telegramCommerceAdminProducts(); return true;
    }
    if ($datain === 'vsa_tc_home') { virtualServicesAdminClearState(); telegramCommerceAdminHome(); return true; }
    if ($datain === 'vsa_tc_toggle') { telegramCommerceSetSetting('enabled', telegramCommerceSetting('enabled', '0') === '1' ? '0' : '1'); telegramCommerceAdminHome(); return true; }
    if ($datain === 'vsa_tc_test') { telegramCommerceAdminHome(); return true; }
    if ($datain === 'vsa_tc_products') { telegramCommerceAdminProducts(); return true; }
    if ($datain === 'vsa_tc_help') {
        global $dbname;
        $safeDb = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $dbname);
        $help = "<b>راهنمای اتصال امن</b>\n\nفایل <code>/etc/mirza/telegram-commerce-{$safeDb}.env</code> را با دسترسی <code>640</code> بسازید:\n\n<code>TELEGRAM_COMMERCE_API_URL=http://127.0.0.1:8088\nTELEGRAM_COMMERCE_API_KEY=...\nTELEGRAM_COMMERCE_WEBHOOK_SECRET=...</code>\n\nآدرس webhook ربات:\n<code>https://دامنه-ربات/telegram_commerce_webhook.php</code>";
        virtualServicesAdminReply($help, [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]); return true;
    }
    if (preg_match('/^vsa_tc_p_([a-f0-9-]{36})$/i', $datain, $m)) { telegramCommerceAdminProduct($m[1]); return true; }
    if (preg_match('/^vsa_tc_togglep_([a-f0-9-]{36})$/i', $datain, $m)) {
        $product = telegramCommerceProduct($m[1], true);
        if ($product) telegramCommerceApi('PATCH', '/v1/admin/products/' . $m[1], ['active' => empty($product['active'])]);
        telegramCommerceAdminProduct($m[1]); return true;
    }
    if (preg_match('/^vsa_tc_price_([a-f0-9-]{36})$/i', $datain, $m)) {
        virtualServicesAdminSetState('vsa_tc_price_' . $m[1]);
        virtualServicesAdminReply('قیمت جدید را مطابق واحد کیف پول ربات و فقط به‌صورت عدد ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_tc_p_' . $m[1]]]]); return true;
    }
    if (preg_match('/^vsa_tc_add_(stars|premium)$/', $datain, $m)) {
        virtualServicesAdminSetState('vsa_tc_add_' . $m[1]);
        $sample = $m[1] === 'stars' ? 'عنوان | تعداد استارز | قیمت | fragment' : 'عنوان | تعداد ماه (3 یا 6 یا 12) | قیمت | fragment';
        virtualServicesAdminReply("اطلاعات پلن را در یک پیام ارسال کنید:\n\n<code>{$sample}</code>\n\nارائه‌دهنده می‌تواند <code>fragment</code>، <code>telegram_bot</code> یا <code>mock</code> باشد.", [[['text' => 'انصراف', 'callback_data' => 'vsa_tc_products']]]); return true;
    }
    return true;
}

