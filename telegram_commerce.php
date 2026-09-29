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

function telegramCommerceAdminOnOff($value)
{
    return $value ? '✅ روشن' : '❌ خاموش';
}

function telegramCommerceAdminConfigured($value)
{
    return $value ? '✅ ثبت شده' : '❌ ثبت نشده';
}

function telegramCommerceMaskedAddress($address)
{
    $address = trim((string) $address);
    if ($address === '') return 'دریافت نشده';
    if (strlen($address) <= 20) return telegramProductsEscape($address);
    return telegramProductsEscape(substr($address, 0, 10) . '...' . substr($address, -8));
}

function telegramCommerceAdminErrorText($response)
{
    $error = $response['error'] ?? 'خطای نامشخص';
    if (is_array($error)) {
        $error = $error['message'] ?? json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return telegramProductsEscape((string) $error);
}

function telegramCommerceDeleteSensitiveMessage()
{
    global $from_id, $message_id;
    if (!empty($message_id)) {
        deletemessage($from_id, $message_id);
    }
}

function telegramCommerceWebhookUrl()
{
    global $domainhosts;
    $host = trim((string) $domainhosts);
    if ($host === '') return '';
    if (!preg_match('#^https?://#i', $host)) $host = 'https://' . $host;
    $parts = parse_url($host);
    if (!is_array($parts) || empty($parts['host'])) return '';
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
    return 'https://' . $parts['host'] . $port . $path . '/telegram_commerce_webhook.php';
}

function telegramCommerceAdminRegisterWebhook()
{
    $config = telegramCommerceConfig();
    $url = telegramCommerceWebhookUrl();
    if ($url === '' || strlen((string) $config['webhook_secret']) < 32) {
        virtualServicesAdminReply('اطلاعات داخلی webhook کامل نیست. گزینه نصب API را یک‌بار از اسکریپت نصب اجرا کنید.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]);
        return;
    }
    $list = telegramCommerceApi('GET', '/v1/webhooks');
    if ($list['ok']) {
        foreach ($list['data'] as $item) {
            if (!empty($item['active']) && hash_equals((string) ($item['url'] ?? ''), $url)) {
                virtualServicesAdminReply("<b>اعلان سفارش فعال است</b>\n\nآدرس webhook این ربات قبلاً ثبت شده و آماده دریافت وضعیت سفارش‌ها است.", [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]);
                return;
            }
        }
    }
    $response = telegramCommerceApi('POST', '/v1/webhooks', [
        'url' => $url,
        'secret' => $config['webhook_secret'],
        'events' => ['order.updated'],
    ]);
    if (!$response['ok']) {
        virtualServicesAdminReply("<b>فعال‌سازی اعلان ناموفق بود</b>\n\n<code>" . telegramCommerceAdminErrorText($response) . '</code>', [[['text' => 'تلاش دوباره', 'callback_data' => 'vsa_tc_webhook']], [['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]);
        return;
    }
    virtualServicesAdminReply("<b>اعلان سفارش فعال شد</b>\n\nاز این پس تغییر وضعیت سفارش، تحویل و بازگشت وجه خودکار به ربات اعلام می‌شود.", [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home', 'style' => 'success']]]);
}

function telegramCommerceAdminAuthStatus($jobId)
{
    if (!preg_match('/^[a-f0-9-]{36}$/i', (string) $jobId)) {
        telegramCommerceAdminHome();
        return;
    }
    $response = telegramCommerceApi('GET', '/v1/admin/fragment/auth/' . rawurlencode($jobId));
    if (!$response['ok']) {
        virtualServicesAdminReply("<b>دریافت وضعیت ورود ناموفق بود</b>\n\n<code>" . telegramCommerceAdminErrorText($response) . '</code>', [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]);
        return;
    }
    $job = $response['data'];
    $status = (string) ($job['status'] ?? 'pending');
    $texts = [
        'pending' => "درخواست ورود در صف است. چند لحظه دیگر وضعیت را تازه کنید.",
        'running' => "درخواست ورود در حال ارسال به تلگرام است.",
        'waiting_confirmation' => "درخواست ورود برای حساب تلگرام شما ارسال شد.\n\nداخل تلگرام روی <b>Confirm / تأیید</b> بزنید و سپس وضعیت را تازه کنید.",
        'finalizing' => "تأیید دریافت شد و نشست امن در حال ذخیره‌سازی است.",
        'succeeded' => "<b>ورود با موفقیت انجام شد</b>\n\nنشست فرگمنت به‌صورت رمزنگاری‌شده ذخیره شد و نیازی به تغییر فایل نیست.",
        'failed' => "<b>ورود تکمیل نشد</b>\n\n<code>" . telegramProductsEscape((string) ($job['error_message'] ?? 'خطای نامشخص')) . '</code>',
    ];
    $text = $texts[$status] ?? 'وضعیت ورود در حال بررسی است.';
    $rows = [];
    if (in_array($status, ['pending', 'running', 'waiting_confirmation', 'finalizing'], true)) {
        $rows[] = [['text' => 'تازه‌سازی وضعیت', 'callback_data' => 'vsa_tc_auth_' . $jobId, 'style' => 'primary']];
    }
    if ($status === 'failed') {
        $rows[] = [['text' => 'شروع دوباره ورود', 'callback_data' => 'vsa_tc_info_session']];
    }
    if ($status === 'succeeded') {
        $rows[] = [['text' => 'بررسی اتصال', 'callback_data' => 'vsa_tc_test', 'style' => 'success']];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']];
    virtualServicesAdminReply($text, $rows);
}

function telegramCommerceAdminHome()
{
    $enabled = telegramCommerceSetting('enabled', '0') === '1';
    $ready = telegramCommerceReady();
    $health = $ready ? telegramCommerceApi('GET', '/healthz') : ['ok' => false];
    $statusResponse = $ready ? telegramCommerceApi('GET', '/v1/admin/provider-status') : ['ok' => false, 'data' => []];
    $status = !empty($statusResponse['ok']) ? $statusResponse['data'] : [];
    $products = $ready ? telegramCommerceProducts(null, true) : null;
    $lastCheck = telegramCommerceSetting('last_connection_check', 'هنوز انجام نشده');
    $lastCheckOk = telegramCommerceSetting('last_connection_ok', '0') === '1';
    $lastWallet = telegramCommerceSetting('last_wallet_address', '');
    $lastBalance = telegramCommerceSetting('last_wallet_balance', '');
    $lastProfile = telegramCommerceSetting('last_fragment_profile', '');
    $walletVersion = $status['fragment_wallet_version'] ?? 'V5R1';
    $lowBalance = (float) ($status['fragment_low_balance_ton'] ?? 0);

    $text = "💎 <b>اتصال فرگمنت</b>\n\n";
    $text .= "پکیج‌های پریمیوم و استارز بعد از پرداخت کاربر، به‌صورت خودکار از Fragment/Telegram خریداری می‌شوند.\n\n";
    $text .= "<blockquote>";
    $text .= "🟢 فروش خودکار: " . telegramCommerceAdminOnOff($enabled) . "\n";
    $text .= "🔌 ارتباط API: " . telegramCommerceAdminConfigured(!empty($health['ok'])) . "\n";
    $text .= "🔐 نشست فرگمنت: " . telegramCommerceAdminConfigured(!empty($status['fragment_session_configured'])) . "\n";
    $text .= "💰 کیف پول: " . telegramCommerceAdminConfigured(!empty($status['fragment_wallet_configured'])) . "\n";
    if ($lastWallet !== '') $text .= "👛 آدرس: <code>" . telegramCommerceMaskedAddress($lastWallet) . "</code>\n";
    if ($lastBalance !== '') $text .= "💵 موجودی آخر: <code>" . telegramProductsEscape($lastBalance) . " TON</code>\n";
    if ($lastProfile !== '') $text .= "👤 حساب: " . telegramProductsEscape($lastProfile) . "\n";
    $text .= "🧬 نسخه کیف پول: <code>" . telegramProductsEscape($walletVersion) . "</code>\n";
    $text .= "🔑 کلید TON RPC: " . telegramCommerceAdminConfigured(!empty($status['fragment_ton_rpc_configured'])) . "\n";
    $text .= "👤 نمایش نام فرستنده: " . telegramCommerceAdminOnOff(!empty($status['fragment_show_sender'])) . "\n";
    $text .= "⚠️ هشدار موجودی کمتر از: <code>" . ($lowBalance > 0 ? telegramProductsEscape((string) $lowBalance) . ' TON' : 'خاموش') . "</code>\n";
    $text .= "📦 تعداد پلن‌ها: <code>" . (is_array($products) ? count($products) : 0) . "</code>\n";
    $text .= "🔄 آخرین بررسی: " . ($lastCheckOk ? '✅ ' : '') . telegramProductsEscape($lastCheck);
    $text .= "</blockquote>";
    $rows = [
        [['text' => 'فروش خودکار: ' . telegramCommerceAdminOnOff($enabled), 'callback_data' => 'vsa_tc_toggle', 'style' => $enabled ? 'success' : 'danger']],
        [
            ['text' => '💰 کلید کیف پول', 'callback_data' => 'vsa_tc_info_wallet'],
            ['text' => '🔐 ورود تلگرام (یک‌بار)', 'callback_data' => 'vsa_tc_info_session'],
        ],
        [
            ['text' => '🧬 نسخه: ' . $walletVersion, 'callback_data' => 'vsa_tc_info_version'],
            ['text' => '🔑 کلید TON RPC', 'callback_data' => 'vsa_tc_info_ton'],
        ],
        [
            ['text' => '👤 نام فرستنده: ' . (!empty($status['fragment_show_sender']) ? 'روشن' : 'خاموش'), 'callback_data' => 'vsa_tc_info_sender'],
            ['text' => '⚠️ هشدار موجودی', 'callback_data' => 'vsa_tc_info_balance'],
        ],
        [
            ['text' => '🔄 بررسی نشست', 'callback_data' => 'vsa_tc_refresh'],
            ['text' => '🧪 بررسی اتصال', 'callback_data' => 'vsa_tc_test'],
        ],
        [['text' => '📦 مدیریت پکیج‌ها', 'callback_data' => 'vsa_tc_products', 'style' => 'primary']],
        [
            ['text' => 'اعلان سفارش', 'callback_data' => 'vsa_tc_webhook'],
            ['text' => 'راهنما', 'callback_data' => 'vsa_tc_help'],
        ],
        [['text' => 'بازگشت', 'callback_data' => 'vsa_home', 'style' => 'danger']],
    ];
    if (!empty($status['fragment_session_configured'])) {
        array_splice($rows, count($rows) - 2, 0, [[['text' => 'حذف نشست فرگمنت', 'callback_data' => 'vsa_tc_logout_confirm']]]);
    }
    virtualServicesAdminReply($text, $rows);
}

function telegramCommerceAdminConnectionCheck()
{
    if (!telegramCommerceReady()) {
        telegramCommerceSetSetting('last_connection_check', date('Y-m-d H:i:s'));
        telegramCommerceSetSetting('last_connection_ok', '0');
        virtualServicesAdminReply('تنظیمات اتصال API هنوز کامل نشده است.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]);
        return;
    }
    $response = telegramCommerceApi('POST', '/v1/admin/provider-check?provider=fragment');
    telegramCommerceSetSetting('last_connection_check', date('Y-m-d H:i:s'));
    telegramCommerceSetSetting('last_connection_ok', $response['ok'] ? '1' : '0');
    if (!$response['ok']) {
        telegramCommerceSetSetting('last_connection_error', (string) $response['error']);
        virtualServicesAdminReply("<b>بررسی اتصال ناموفق بود</b>\n\n<code>" . telegramProductsEscape((string) $response['error']) . '</code>', [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]);
        return;
    }
    $data = $response['data'];
    telegramCommerceSetSetting('last_connection_error', '');
    telegramCommerceSetSetting('last_wallet_address', (string) ($data['wallet_address'] ?? ''));
    telegramCommerceSetSetting('last_wallet_balance', isset($data['balance_ton']) ? (string) $data['balance_ton'] : '');
    telegramCommerceSetSetting('last_fragment_profile', (string) ($data['profile_name'] ?? ''));
    $text = "<b>اتصال فرگمنت با موفقیت بررسی شد</b>\n\n";
    if (!empty($data['profile_name'])) $text .= 'حساب: ' . telegramProductsEscape($data['profile_name']) . "\n";
    if (!empty($data['wallet_address'])) $text .= 'کیف پول: <code>' . telegramCommerceMaskedAddress($data['wallet_address']) . "</code>\n";
    if (isset($data['balance_ton'])) $text .= 'موجودی: <code>' . telegramProductsEscape((string) $data['balance_ton']) . " TON</code>\n";
    if (!empty($data['low_balance'])) $text .= "\n⚠️ موجودی کیف پول از حد هشدار کمتر است.";
    virtualServicesAdminReply($text, [[['text' => 'بازگشت به وضعیت اتصال', 'callback_data' => 'vsa_tc_home']]]);
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
    if ($datain === '' && $state === 'vsa_tc_set_seed') {
        telegramCommerceDeleteSensitiveMessage();
        $seed = trim(preg_replace('/\s+/u', ' ', (string) $text));
        $wordCount = count(array_filter(explode(' ', $seed), 'strlen'));
        if (!in_array($wordCount, [12, 18, 24], true)) {
            virtualServicesAdminReply('عبارت بازیابی باید دقیقاً ۱۲، ۱۸ یا ۲۴ کلمه باشد. پیام شما حذف شد؛ دوباره ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_tc_home']]], false);
            return true;
        }
        $response = telegramCommerceApi('PATCH', '/v1/admin/provider-config', ['fragment_wallet_seed' => $seed]);
        virtualServicesAdminClearState();
        if (!$response['ok']) {
            virtualServicesAdminReply("<b>ثبت کلید کیف پول ناموفق بود</b>\n\n<code>" . telegramCommerceAdminErrorText($response) . '</code>', [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]], false);
            return true;
        }
        virtualServicesAdminReply("<b>کلید کیف پول با موفقیت ثبت شد</b>\n\nپیام حاوی عبارت بازیابی حذف و مقدار آن به‌صورت رمزنگاری‌شده ذخیره شد.", [[['text' => 'ادامه و ورود تلگرام', 'callback_data' => 'vsa_tc_info_session', 'style' => 'success']], [['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]], false);
        return true;
    }
    if ($datain === '' && $state === 'vsa_tc_set_ton_key') {
        telegramCommerceDeleteSensitiveMessage();
        $key = trim((string) $text);
        if ($key === '' || mb_strlen($key, 'UTF-8') > 500) {
            virtualServicesAdminReply('کلید معتبر نیست. پیام حذف شد؛ کلید TON RPC را دوباره ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_tc_home']]], false);
            return true;
        }
        $response = telegramCommerceApi('PATCH', '/v1/admin/provider-config', ['fragment_ton_api_key' => $key]);
        virtualServicesAdminClearState();
        $message = $response['ok'] ? '<b>کلید TON RPC با موفقیت ثبت شد</b>' : "<b>ثبت کلید ناموفق بود</b>\n\n<code>" . telegramCommerceAdminErrorText($response) . '</code>';
        virtualServicesAdminReply($message, [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]], false);
        return true;
    }
    if ($datain === '' && $state === 'vsa_tc_set_phone') {
        telegramCommerceDeleteSensitiveMessage();
        $digits = preg_replace('/\D+/', '', telegramCommerceNormalizeDigits((string) $text));
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            virtualServicesAdminReply('شماره معتبر نیست. شماره را با کد کشور، مثل <code>989121234567+</code> ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_tc_home']]], false);
            return true;
        }
        $response = telegramCommerceApi('POST', '/v1/admin/fragment/auth', ['phone' => '+' . $digits]);
        virtualServicesAdminClearState();
        if (!$response['ok'] || empty($response['data']['id'])) {
            virtualServicesAdminReply("<b>شروع ورود ناموفق بود</b>\n\n<code>" . telegramCommerceAdminErrorText($response) . '</code>', [[['text' => 'تلاش دوباره', 'callback_data' => 'vsa_tc_info_session']], [['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]], false);
            return true;
        }
        telegramCommerceAdminAuthStatus($response['data']['id']);
        return true;
    }
    if ($datain === '' && $state === 'vsa_tc_set_balance') {
        $value = str_replace(',', '.', telegramCommerceNormalizeDigits(trim((string) $text)));
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 1000000) {
            virtualServicesAdminReply('یک عدد معتبر وارد کنید. برای خاموش‌کردن هشدار عدد صفر را بفرستید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_tc_home']]]);
            return true;
        }
        $response = telegramCommerceApi('PATCH', '/v1/admin/provider-config', ['fragment_low_balance_ton' => (float) $value]);
        virtualServicesAdminClearState();
        if (!$response['ok']) {
            virtualServicesAdminReply('ثبت حد هشدار ناموفق بود: <code>' . telegramCommerceAdminErrorText($response) . '</code>', [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]);
            return true;
        }
        telegramCommerceAdminHome();
        return true;
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
    if ($datain === 'vsa_tc_test' || $datain === 'vsa_tc_refresh') { telegramCommerceAdminConnectionCheck(); return true; }
    if ($datain === 'vsa_tc_products') { telegramCommerceAdminProducts(); return true; }
    if ($datain === 'vsa_tc_webhook') { telegramCommerceAdminRegisterWebhook(); return true; }
    if ($datain === 'vsa_tc_help') {
        $help = "<b>راه‌اندازی فروش خودکار</b>\n\n۱. کلید کیف پول را از همین پنل ثبت کنید.\n۲. ورود تلگرام را بزنید و درخواست را داخل تلگرام تأیید کنید.\n۳. کلید TON RPC را ثبت و اتصال را بررسی کنید.\n۴. دکمه اعلان سفارش را یک‌بار بزنید.\n۵. پکیج‌ها را بسازید و فروش خودکار را روشن کنید.\n\nتمام وابستگی‌ها، API، worker، کلیدهای داخلی و زیرساخت webhook هنگام نصب خودکار آماده می‌شوند؛ نیازی به ویرایش فایل‌های سرور نیست.\n\nاطلاعات حساس در پیام نمایش داده نمی‌شوند و رمزنگاری‌شده نگهداری می‌شوند.";
        virtualServicesAdminReply($help, [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]); return true;
    }
    if ($datain === 'vsa_tc_info_wallet') {
        virtualServicesAdminSetState('vsa_tc_set_seed');
        virtualServicesAdminReply("<b>ثبت کلید کیف پول Fragment</b>\n\nعبارت بازیابی ۱۲، ۱۸ یا ۲۴ کلمه‌ای کیف پول را در یک پیام ارسال کنید.\n\nپیام پس از دریافت فوراً حذف و مقدار آن به‌صورت رمزنگاری‌شده ذخیره می‌شود.", [[['text' => 'انصراف', 'callback_data' => 'vsa_tc_home']]]);
        return true;
    }
    if ($datain === 'vsa_tc_info_session') {
        $statusResponse = telegramCommerceApi('GET', '/v1/admin/provider-status');
        if (!$statusResponse['ok'] || empty($statusResponse['data']['fragment_wallet_configured'])) {
            virtualServicesAdminReply('ابتدا کلید کیف پول را ثبت کنید، سپس ورود تلگرام را انجام دهید.', [[['text' => 'ثبت کلید کیف پول', 'callback_data' => 'vsa_tc_info_wallet', 'style' => 'primary']], [['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]);
            return true;
        }
        virtualServicesAdminSetState('vsa_tc_set_phone');
        virtualServicesAdminReply("<b>ورود تلگرام در Fragment</b>\n\nشماره حساب تلگرام را با کد کشور ارسال کنید.\nنمونه: <code>+989121234567</code>\n\nشماره پس از دریافت فوراً از گفتگو حذف می‌شود. سپس درخواست رسمی ورود در تلگرام برایتان باز می‌شود و باید آن را تأیید کنید.", [[['text' => 'انصراف', 'callback_data' => 'vsa_tc_home']]]);
        return true;
    }
    if ($datain === 'vsa_tc_info_ton') {
        virtualServicesAdminSetState('vsa_tc_set_ton_key');
        virtualServicesAdminReply("<b>ثبت کلید TON RPC</b>\n\nکلید Toncenter یا سرویس RPC سازگار را ارسال کنید. پیام پس از دریافت حذف و مقدار رمزنگاری می‌شود.", [[['text' => 'انصراف', 'callback_data' => 'vsa_tc_home']]]);
        return true;
    }
    if ($datain === 'vsa_tc_info_balance') {
        virtualServicesAdminSetState('vsa_tc_set_balance');
        virtualServicesAdminReply("<b>هشدار موجودی کیف پول</b>\n\nحداقل موجودی را برحسب TON وارد کنید. برای خاموش‌کردن هشدار عدد <code>0</code> را بفرستید.", [[['text' => 'انصراف', 'callback_data' => 'vsa_tc_home']]]);
        return true;
    }
    if ($datain === 'vsa_tc_info_version' || $datain === 'vsa_tc_info_sender') {
        $statusResponse = telegramCommerceApi('GET', '/v1/admin/provider-status');
        if (!$statusResponse['ok']) {
            virtualServicesAdminReply('دریافت تنظیمات ناموفق بود: <code>' . telegramCommerceAdminErrorText($statusResponse) . '</code>', [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]);
            return true;
        }
        $current = $statusResponse['data'];
        $payload = $datain === 'vsa_tc_info_version'
            ? ['fragment_wallet_version' => (($current['fragment_wallet_version'] ?? 'V5R1') === 'V5R1' ? 'V4R2' : 'V5R1')]
            : ['fragment_show_sender' => empty($current['fragment_show_sender'])];
        $response = telegramCommerceApi('PATCH', '/v1/admin/provider-config', $payload);
        if (!$response['ok']) {
            virtualServicesAdminReply('ذخیره تنظیمات ناموفق بود: <code>' . telegramCommerceAdminErrorText($response) . '</code>', [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]);
            return true;
        }
        telegramCommerceAdminHome();
        return true;
    }
    if (preg_match('/^vsa_tc_auth_([a-f0-9-]{36})$/i', $datain, $m)) { telegramCommerceAdminAuthStatus($m[1]); return true; }
    if ($datain === 'vsa_tc_logout_confirm') {
        virtualServicesAdminReply("<b>حذف نشست فرگمنت</b>\n\nبا حذف نشست، فروش خودکار تا ورود دوباره متوقف می‌شود. کلید کیف پول حذف نخواهد شد.", [[['text' => 'تأیید حذف نشست', 'callback_data' => 'vsa_tc_logout_do', 'style' => 'danger']], [['text' => 'انصراف', 'callback_data' => 'vsa_tc_home']]]);
        return true;
    }
    if ($datain === 'vsa_tc_logout_do') {
        $response = telegramCommerceApi('DELETE', '/v1/admin/fragment/session');
        if (!$response['ok']) {
            virtualServicesAdminReply('حذف نشست ناموفق بود: <code>' . telegramCommerceAdminErrorText($response) . '</code>', [[['text' => 'بازگشت', 'callback_data' => 'vsa_tc_home']]]);
            return true;
        }
        telegramCommerceAdminHome();
        return true;
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

