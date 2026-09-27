<?php

const TELEGRAM_FRAGMENT_SECRET_PREFIX = 'fragment_secret_';

function telegramFragmentEnsureSchema()
{
    global $pdo;

    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_fragment_plans (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_type VARCHAR(20) NOT NULL,
        amount INT UNSIGNED NOT NULL,
        title VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        price BIGINT UNSIGNED NOT NULL,
        button_style VARCHAR(20) NOT NULL DEFAULT 'primary',
        button_emoji_id VARCHAR(30) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_fragment_plans_list (product_type, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_fragment_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(200) NOT NULL,
        plan_id INT UNSIGNED NOT NULL,
        recipient VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        product_type VARCHAR(20) NOT NULL,
        amount INT UNSIGNED NOT NULL,
        title VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        price BIGINT UNSIGNED NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        provider_tx VARCHAR(255) NULL,
        provider_response MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        error_message TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at DATETIME NULL,
        delivered_at DATETIME NULL,
        refunded_at DATETIME NULL,
        INDEX idx_fragment_orders_user (user_id, created_at),
        INDEX idx_fragment_orders_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ready = true;
}

function telegramFragmentSetting($key, $default = '')
{
    return telegramProductsSetting('fragment_' . $key, $default);
}

function telegramFragmentSetSetting($key, $value)
{
    telegramProductsSetSetting('fragment_' . $key, (string) $value);
}

function telegramFragmentEncryptionKey()
{
    global $APIKEY, $passworddb, $dbname;

    return hash('sha256', 'mirza-fragment-v1|' . (string) $APIKEY . '|' . (string) $passworddb . '|' . (string) $dbname . '|' . __DIR__, true);
}

function telegramFragmentEncrypt($plainText)
{
    $plainText = (string) $plainText;
    $key = telegramFragmentEncryptionKey();
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'sb1:' . base64_encode($nonce . sodium_crypto_secretbox($plainText, $nonce, $key));
    }
    if (function_exists('openssl_encrypt')) {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher !== false) {
            return 'og1:' . base64_encode($iv . $tag . $cipher);
        }
    }
    throw new RuntimeException('No supported encryption extension is available.');
}

function telegramFragmentDecrypt($encrypted)
{
    $encrypted = (string) $encrypted;
    $key = telegramFragmentEncryptionKey();
    if (strpos($encrypted, 'sb1:') === 0 && function_exists('sodium_crypto_secretbox_open')) {
        $data = base64_decode(substr($encrypted, 4), true);
        if ($data === false || strlen($data) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        return $plain === false ? '' : $plain;
    }
    if (strpos($encrypted, 'og1:') === 0 && function_exists('openssl_decrypt')) {
        $data = base64_decode(substr($encrypted, 4), true);
        if ($data === false || strlen($data) <= 28) {
            return '';
        }
        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $plain = openssl_decrypt(substr($data, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }
    return '';
}

function telegramFragmentSetSecret($name, $value)
{
    telegramProductsSetSetting(TELEGRAM_FRAGMENT_SECRET_PREFIX . $name, telegramFragmentEncrypt((string) $value));
}

function telegramFragmentSecret($name)
{
    $encrypted = telegramProductsSetting(TELEGRAM_FRAGMENT_SECRET_PREFIX . $name, '');
    return $encrypted === '' ? '' : telegramFragmentDecrypt($encrypted);
}

function telegramFragmentConfig()
{
    $cookies = json_decode(telegramFragmentSecret('cookies'), true);
    return [
        'cookies' => is_array($cookies) ? $cookies : [],
        'seed' => telegramFragmentSecret('seed'),
        'api_key' => telegramFragmentSecret('api_key'),
        'wallet_version' => telegramFragmentSetting('wallet_version', 'V5R1'),
        'api_provider' => telegramFragmentSetting('api_provider', 'toncenter'),
        'payment_method' => telegramFragmentSetting('payment_method', 'ton'),
        'show_sender' => telegramFragmentSetting('show_sender', '0') === '1',
    ];
}

function telegramFragmentRuntimeStatus()
{
    $script = __DIR__ . '/fragment_runtime/fragment_worker.py';
    $loginScript = __DIR__ . '/fragment_runtime/fragment_login.mjs';
    return [
        'worker' => is_file($script),
        'login_worker' => is_file($loginScript),
        'node_modules' => is_dir(__DIR__ . '/fragment_runtime/node_modules/fragment-tg'),
    ];
}

function telegramFragmentStatus()
{
    $config = telegramFragmentConfig();
    $runtime = telegramFragmentRuntimeStatus();
    $sessionReady = true;
    foreach (['stel_ssid', 'stel_dt', 'stel_token'] as $cookieKey) {
        if (trim((string) ($config['cookies'][$cookieKey] ?? '')) === '') {
            $sessionReady = false;
            break;
        }
    }
    $walletReady = trim($config['seed']) !== '';
    $apiReady = trim($config['api_key']) !== '';
    $runtimeReady = $runtime['worker'];
    return [
        'enabled' => telegramFragmentSetting('enabled', '0') === '1',
        'session' => $sessionReady,
        'wallet' => $walletReady,
        'api_key' => $apiReady,
        'runtime' => $runtimeReady,
        'login_runtime' => $runtime['login_worker'] && $runtime['node_modules'],
        'ready' => $sessionReady && $walletReady && $apiReady && $runtimeReady,
    ];
}

function telegramFragmentRunWorker(array $payload, $timeoutSeconds = 150)
{
    $script = __DIR__ . '/fragment_runtime/fragment_worker.py';
    if (!is_file($script) || !function_exists('proc_open')) {
        return ['ok' => false, 'code' => 'RUNTIME_MISSING', 'message' => 'پردازشگر محلی فرگمنت نصب نشده است.', 'retry_safe' => true];
    }
    $python = getenv('MIRZA_FRAGMENT_PYTHON');
    if (!$python) {
        $python = is_executable('/opt/mirza/fragment-venv/bin/python')
            ? '/opt/mirza/fragment-venv/bin/python'
            : 'python3';
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open([$python, $script], $descriptors, $pipes, __DIR__);
    if (!is_resource($process)) {
        return ['ok' => false, 'code' => 'RUNTIME_START_FAILED', 'message' => 'پردازشگر فرگمنت اجرا نشد.', 'retry_safe' => true];
    }

    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $started = microtime(true);
    $timedOut = false;
    while (true) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if ((microtime(true) - $started) > $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process, 15);
            usleep(300000);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            break;
        }
        usleep(100000);
    }
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if ($timedOut) {
        return ['ok' => false, 'code' => 'WORKER_TIMEOUT', 'message' => 'پاسخ خرید در زمان مقرر دریافت نشد.', 'retry_safe' => false];
    }
    $result = json_decode(trim($stdout), true);
    if (!is_array($result)) {
        error_log('Fragment worker invalid output: ' . substr($stderr, 0, 1000));
        return ['ok' => false, 'code' => 'INVALID_WORKER_OUTPUT', 'message' => 'پاسخ پردازشگر فرگمنت معتبر نبود.', 'retry_safe' => false];
    }
    return $result;
}

function telegramFragmentStartLogin($phone)
{
    $phone = trim((string) $phone);
    if (!preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
        return ['ok' => false, 'message' => 'شماره باید با کد کشور و علامت + وارد شود.'];
    }
    $script = __DIR__ . '/fragment_runtime/fragment_login.mjs';
    if (!is_file($script) || !is_dir(__DIR__ . '/fragment_runtime/node_modules/fragment-tg') || !function_exists('exec')) {
        return ['ok' => false, 'message' => 'وابستگی ورود فرگمنت نصب نشده است. ابتدا نصب‌کننده را بروزرسانی کنید.'];
    }
    $job = bin2hex(random_bytes(16));
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mirza_fragment_' . $job . '.json';
    $inputPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mirza_fragment_' . $job . '.input.json';
    if (@file_put_contents($inputPath, json_encode(['phone' => $phone], JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        return ['ok' => false, 'message' => 'فایل موقت امن برای ورود ساخته نشد.'];
    }
    @chmod($inputPath, 0600);
    $node = getenv('MIRZA_FRAGMENT_NODE') ?: 'node';
    $command = 'nohup ' . escapeshellarg($node) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($inputPath) . ' ' . escapeshellarg($path) . ' >/dev/null 2>&1 &';
    @exec($command, $unused, $code);
    telegramFragmentSetSetting('login_job', $job);
    telegramFragmentSetSetting('login_started_at', (string) time());
    return ['ok' => true, 'job' => $job];
}

function telegramFragmentReadLogin($job)
{
    if (!preg_match('/^[a-f0-9]{32}$/', (string) $job) || !hash_equals(telegramFragmentSetting('login_job', ''), (string) $job)) {
        return ['status' => 'error', 'error' => 'INVALID_LOGIN_JOB'];
    }
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mirza_fragment_' . $job . '.json';
    $inputPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mirza_fragment_' . $job . '.input.json';
    if (!is_file($path)) {
        if (time() - (int) telegramFragmentSetting('login_started_at', '0') > 300) {
            @unlink($inputPath);
            return ['status' => 'error', 'error' => 'LOGIN_START_FAILED'];
        }
        return ['status' => 'starting'];
    }
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data)) {
        return ['status' => 'waiting'];
    }
    if (($data['status'] ?? '') === 'success' && is_array($data['cookies'] ?? null) && $data['cookies']) {
        telegramFragmentSetSecret('cookies', json_encode($data['cookies'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        telegramFragmentSetSetting('session_saved_at', date('Y-m-d H:i:s'));
        telegramFragmentSetSetting('login_job', '');
        @unlink($path);
        @unlink($inputPath);
        return ['status' => 'success', 'user' => $data['user'] ?? null];
    }
    if (($data['status'] ?? '') === 'error') {
        telegramFragmentSetSetting('login_job', '');
        @unlink($path);
        @unlink($inputPath);
    }
    return $data;
}

function telegramFragmentButton($text, $callback, $style = null, $emojiId = null)
{
    $label = telegramProductsPlainText($text);
    if (mb_strlen($label, 'UTF-8') > 60) {
        $label = rtrim(mb_substr($label, 0, 59, 'UTF-8')) . '…';
    }
    return telegramProductsStyledButton($label, $callback, $style, $emojiId);
}

function telegramFragmentTypeLabel($type)
{
    return $type === 'premium' ? 'تلگرام پریمیوم' : 'تلگرام استارز';
}

function telegramFragmentStatusLabel($status)
{
    return [
        'pending' => 'در انتظار پرداخت',
        'processing' => 'در حال خرید از فرگمنت',
        'delivered' => 'تحویل‌شده',
        'failed_refunded' => 'ناموفق و بازپرداخت‌شده',
        'review' => 'نیازمند بررسی ادمین',
        'refunded' => 'بازپرداخت‌شده',
    ][$status] ?? (string) $status;
}

function telegramFragmentFindValue($value, array $keys)
{
    if (!is_array($value)) return null;
    foreach ($keys as $key) {
        if (array_key_exists($key, $value) && (is_scalar($value[$key]) || $value[$key] === null)) {
            return $value[$key];
        }
    }
    foreach ($value as $child) {
        if (is_array($child)) {
            $found = telegramFragmentFindValue($child, $keys);
            if ($found !== null) return $found;
        }
    }
    return null;
}

function telegramFragmentAdminHome()
{
    global $pdo;

    telegramFragmentEnsureSchema();
    $status = telegramFragmentStatus();
    $planCount = (int) $pdo->query('SELECT COUNT(*) FROM telegram_fragment_plans')->fetchColumn();
    $reviewCount = (int) $pdo->query("SELECT COUNT(*) FROM telegram_fragment_orders WHERE status = 'review'")->fetchColumn();
    $text = "<b>اتصال فرگمنت</b>\n\n";
    $text .= 'فروش خودکار: <b>' . ($status['enabled'] ? 'روشن' : 'خاموش') . "</b>\n";
    $text .= 'ورود تلگرام: ' . ($status['session'] ? 'ثبت‌شده' : 'ثبت‌نشده') . "\n";
    $text .= 'کیف پول: ' . ($status['wallet'] ? 'متصل' : 'تنظیم‌نشده') . "\n";
    $text .= 'کلید API شبکه TON: ' . ($status['api_key'] ? 'ثبت‌شده' : 'ثبت‌نشده') . "\n";
    $text .= 'پردازشگر خرید: ' . ($status['runtime'] ? 'آماده' : 'نصب‌نشده') . "\n";
    $text .= "پلن‌ها: <code>{$planCount}</code> | نیازمند بررسی: <code>{$reviewCount}</code>\n\n";
    if (telegramFragmentSetting('session_saved_at', '') !== '') {
        $text .= '<b>آخرین ذخیره نشست:</b> <code>' . telegramProductsEscape(telegramFragmentSetting('session_saved_at')) . "</code>\n";
    }
    if (telegramFragmentSetting('last_connection_at', '') !== '') {
        $text .= '<b>آخرین بررسی اتصال:</b> <code>' . telegramProductsEscape(telegramFragmentSetting('last_connection_at')) . "</code>\n\n";
    }
    $text .= $status['ready'] ? 'اتصال برای آزمایش آماده است.' : 'برای فعال‌سازی فروش، موارد ثبت‌نشده را تکمیل کنید.';

    $rows = [
        [telegramFragmentButton($status['enabled'] ? 'فروش خودکار روشن' : 'فروش خودکار خاموش', 'vsf_toggle', $status['enabled'] ? 'success' : 'danger')],
        [
            telegramFragmentButton('ورود تلگرام', 'vsf_login'),
            telegramFragmentButton('اتصال کیف پول', 'vsf_seed'),
        ],
        [
            telegramFragmentButton('کلید API شبکه TON', 'vsf_apikey'),
            telegramFragmentButton('نسخه کیف پول: ' . telegramFragmentSetting('wallet_version', 'V5R1'), 'vsf_walletver'),
        ],
        [
            telegramFragmentButton('نمایش نام فرستنده: ' . (telegramFragmentSetting('show_sender', '0') === '1' ? 'روشن' : 'خاموش'), 'vsf_sender'),
            telegramFragmentButton('هشدار موجودی', 'vsf_alert'),
        ],
        [
            telegramFragmentButton('مدیریت پلن‌ها', 'vsf_plans', 'primary'),
            telegramFragmentButton('سفارش‌ها', 'vsf_orders'),
        ],
        [telegramFragmentButton('بررسی اتصال', 'vsf_test', 'success')],
        [telegramFragmentButton('راهنمای نصب', 'vsf_help')],
        [telegramFragmentButton('بازگشت', 'vsa_home', 'danger')],
    ];
    virtualServicesAdminReply($text, $rows);
}

function telegramFragmentAdminPlans()
{
    global $pdo;

    telegramFragmentEnsureSchema();
    $plans = $pdo->query('SELECT * FROM telegram_fragment_plans ORDER BY product_type, sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($plans as $plan) {
        $label = ((int) $plan['is_active'] ? '' : '[خاموش] ') . $plan['title'] . ' - ' . telegramProductsMoney($plan['price']);
        $rows[] = [telegramFragmentButton($label, 'vsf_plan_' . $plan['id'], $plan['button_style'], $plan['button_emoji_id'])];
    }
    $rows[] = [
        telegramFragmentButton('افزودن پلن استارز', 'vsf_add_stars', 'success'),
        telegramFragmentButton('افزودن پلن پریمیوم', 'vsf_add_premium', 'success'),
    ];
    $rows[] = [telegramFragmentButton('بازگشت', 'vsf_home')];
    virtualServicesAdminReply("<b>پلن‌های فروش خودکار فرگمنت</b>\n\nبرای ویرایش هر پلن روی نام آن بزنید.", $rows);
}

function telegramFragmentGetPlan($id, $activeOnly = false)
{
    global $pdo;

    $sql = 'SELECT * FROM telegram_fragment_plans WHERE id = ?' . ($activeOnly ? ' AND is_active = 1' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function telegramFragmentAdminPlan($id)
{
    $plan = telegramFragmentGetPlan($id);
    if (!$plan) {
        virtualServicesAdminReply('پلن موردنظر پیدا نشد.', [[telegramFragmentButton('بازگشت', 'vsf_plans')]]);
        return;
    }
    $text = "<b>مدیریت پلن فرگمنت</b>\n\n";
    $text .= '<b>عنوان:</b> ' . telegramProductsEscape($plan['title']) . "\n";
    $text .= '<b>نوع:</b> ' . telegramFragmentTypeLabel($plan['product_type']) . "\n";
    $text .= '<b>مقدار:</b> ' . number_format((int) $plan['amount']) . ($plan['product_type'] === 'premium' ? ' ماه' : ' استارز') . "\n";
    $text .= '<b>قیمت:</b> ' . telegramProductsMoney($plan['price']) . "\n";
    $text .= '<b>وضعیت:</b> ' . ((int) $plan['is_active'] ? 'فعال' : 'غیرفعال');
    $rows = [
        [telegramFragmentButton('تغییر نام', 'vsf_pname_' . $plan['id']), telegramFragmentButton('تغییر قیمت', 'vsf_pprice_' . $plan['id'])],
        [telegramFragmentButton((int) $plan['is_active'] ? 'غیرفعال‌سازی' : 'فعال‌سازی', 'vsf_ptoggle_' . $plan['id'])],
        [telegramFragmentButton('بالاتر', 'vsf_pup_' . $plan['id']), telegramFragmentButton('پایین‌تر', 'vsf_pdown_' . $plan['id'])],
        [telegramFragmentButton('رنگ دکمه', 'vsf_pstyle_' . $plan['id']), telegramFragmentButton('ایموجی دکمه', 'vsf_pemoji_' . $plan['id'])],
        [telegramFragmentButton('حذف پلن', 'vsf_pdelete_' . $plan['id'], 'danger')],
        [telegramFragmentButton('بازگشت', 'vsf_plans')],
    ];
    virtualServicesAdminReply($text, $rows);
}

function telegramFragmentAdminOrders($onlyReview = false)
{
    global $pdo;

    $sql = 'SELECT * FROM telegram_fragment_orders' . ($onlyReview ? " WHERE status = 'review'" : '') . ' ORDER BY id DESC LIMIT 30';
    $orders = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $text = '<b>' . ($onlyReview ? 'سفارش‌های نیازمند بررسی' : 'سفارش‌های فرگمنت') . "</b>\n\n";
    $rows = [];
    if (!$orders) {
        $text .= 'سفارشی در این بخش وجود ندارد.';
    }
    foreach ($orders as $order) {
        $rows[] = [telegramFragmentButton('#' . $order['id'] . ' | ' . $order['title'] . ' | ' . telegramFragmentStatusLabel($order['status']), 'vsf_order_' . $order['id'])];
    }
    $rows[] = [telegramFragmentButton($onlyReview ? 'همه سفارش‌ها' : 'نیازمند بررسی', $onlyReview ? 'vsf_orders' : 'vsf_reviews')];
    $rows[] = [telegramFragmentButton('بازگشت', 'vsf_home')];
    virtualServicesAdminReply($text, $rows);
}

function telegramFragmentGetOrder($id, $forUpdate = false)
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT * FROM telegram_fragment_orders WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([(int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function telegramFragmentAdminOrder($id)
{
    $order = telegramFragmentGetOrder($id);
    if (!$order) {
        virtualServicesAdminReply('سفارش پیدا نشد.', [[telegramFragmentButton('بازگشت', 'vsf_orders')]]);
        return;
    }
    $text = "<b>سفارش فرگمنت #{$order['id']}</b>\n\n";
    $text .= '<b>کاربر:</b> <code>' . telegramProductsEscape($order['user_id']) . "</code>\n";
    $text .= '<b>گیرنده:</b> <code>' . telegramProductsEscape($order['recipient']) . "</code>\n";
    $text .= '<b>محصول:</b> ' . telegramProductsEscape($order['title']) . "\n";
    $text .= '<b>مبلغ:</b> ' . telegramProductsMoney($order['price']) . "\n";
    $text .= '<b>وضعیت:</b> ' . telegramFragmentStatusLabel($order['status']);
    if ($order['provider_tx']) {
        $text .= "\n<b>شناسه تراکنش:</b> <code>" . telegramProductsEscape($order['provider_tx']) . '</code>';
    }
    if ($order['error_message']) {
        $text .= "\n\n<b>خطا:</b> <code>" . telegramProductsEscape($order['error_message']) . '</code>';
    }
    $rows = [];
    if ($order['status'] === 'review') {
        $rows[] = [telegramFragmentButton('تأیید تحویل دستی', 'vsf_delivered_' . $order['id'], 'success')];
        $rows[] = [telegramFragmentButton('بازپرداخت کیف پول', 'vsf_refundask_' . $order['id'], 'danger')];
    }
    $rows[] = [telegramFragmentButton('بازگشت', 'vsf_orders')];
    virtualServicesAdminReply($text, $rows);
}

function telegramFragmentDeleteSecretMessage()
{
    global $from_id, $message_id;

    if ((int) $message_id > 0) {
        deletemessage($from_id, $message_id);
    }
}

function telegramFragmentAdminHandleState()
{
    global $pdo, $text, $user, $from_id;

    $state = (string) ($user['step'] ?? '');
    if (strpos($state, 'vsf_') !== 0) {
        return false;
    }
    $data = virtualServicesAdminStateData();
    $value = trim((string) $text);

    if ($state === 'vsf_login_phone') {
        virtualServicesAdminClearState();
        $result = telegramFragmentStartLogin($value);
        if (empty($result['ok'])) {
            virtualServicesAdminReply(telegramProductsEscape($result['message'] ?? 'شروع ورود ناموفق بود.'), [[telegramFragmentButton('تلاش دوباره', 'vsf_login')], [telegramFragmentButton('بازگشت', 'vsf_home')]]);
            return true;
        }
        $job = $result['job'];
        virtualServicesAdminReply("<b>درخواست ورود ارسال شد</b>\n\nاعلان ورود Fragment را داخل تلگرام تأیید کنید. سپس دکمه بررسی وضعیت را بزنید. این درخواست حدود چهار دقیقه اعتبار دارد.", [
            [telegramFragmentButton('بررسی وضعیت ورود', 'vsf_logincheck_' . $job, 'success')],
            [telegramFragmentButton('بازگشت', 'vsf_home')],
        ]);
        return true;
    }

    if ($state === 'vsf_cookie_value') {
        telegramFragmentDeleteSecretMessage();
        $cookies = json_decode($value, true);
        $validCookies = is_array($cookies);
        foreach (['stel_ssid', 'stel_dt', 'stel_token'] as $cookieKey) {
            if (trim((string) ($cookies[$cookieKey] ?? '')) === '') $validCookies = false;
        }
        if (!$validCookies) {
            virtualServicesAdminReply('JSON کوکی معتبر نیست. مقدار باید شامل stel_ssid، stel_dt و stel_token باشد.', [[telegramFragmentButton('انصراف', 'vsf_home')]]);
            return true;
        }
        telegramFragmentSetSecret('cookies', json_encode($cookies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        telegramFragmentSetSetting('session_saved_at', date('Y-m-d H:i:s'));
        virtualServicesAdminClearState();
        virtualServicesAdminReply('نشست فرگمنت به‌صورت رمزگذاری‌شده ذخیره شد.', [[telegramFragmentButton('بازگشت به اتصال فرگمنت', 'vsf_home')]]);
        return true;
    }

    if ($state === 'vsf_seed_value') {
        telegramFragmentDeleteSecretMessage();
        $words = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!in_array(count($words), [12, 18, 24], true) || strlen($value) > 500) {
            virtualServicesAdminReply('عبارت بازیابی معتبر نیست. عبارت ۱۲، ۱۸ یا ۲۴ کلمه‌ای کیف پول را با فاصله ارسال کنید.', [[telegramFragmentButton('انصراف', 'vsf_home')]]);
            return true;
        }
        telegramFragmentSetSecret('seed', implode(' ', $words));
        virtualServicesAdminClearState();
        virtualServicesAdminReply('کیف پول با رمزگذاری محلی ثبت شد. عبارت بازیابی در پیام‌ها نمایش داده نمی‌شود.', [[telegramFragmentButton('بررسی اتصال', 'vsf_test', 'success')], [telegramFragmentButton('بازگشت', 'vsf_home')]]);
        return true;
    }

    if ($state === 'vsf_api_value') {
        telegramFragmentDeleteSecretMessage();
        if (strlen($value) < 8 || strlen($value) > 300 || preg_match('/\s/u', $value)) {
            virtualServicesAdminReply('کلید API معتبر نیست. کلید Toncenter را بدون فاصله ارسال کنید.', [[telegramFragmentButton('انصراف', 'vsf_home')]]);
            return true;
        }
        telegramFragmentSetSecret('api_key', $value);
        virtualServicesAdminClearState();
        virtualServicesAdminReply('کلید API شبکه TON به‌صورت رمزگذاری‌شده ذخیره شد.', [[telegramFragmentButton('بازگشت', 'vsf_home')]]);
        return true;
    }

    if ($state === 'vsf_alert_value') {
        if (!ctype_digit($value) || (int) $value > 1000000000) {
            virtualServicesAdminReply('موجودی هشدار را به‌صورت عدد صحیح وارد کنید؛ برای غیرفعال‌کردن صفر بفرستید.', [[telegramFragmentButton('انصراف', 'vsf_home')]]);
            return true;
        }
        telegramFragmentSetSetting('balance_alert', (string) (int) $value);
        virtualServicesAdminClearState();
        telegramFragmentAdminHome();
        return true;
    }

    if ($state === 'vsf_plan_amount') {
        $type = ($data['type'] ?? '') === 'premium' ? 'premium' : 'stars';
        if (!ctype_digit($value) || (int) $value < ($type === 'stars' ? 50 : 1) || (int) $value > 10000000) {
            virtualServicesAdminReply('مقدار پلن معتبر نیست.', [[telegramFragmentButton('انصراف', 'vsf_plans')]]);
            return true;
        }
        $amount = (int) $value;
        if ($type === 'premium' && !in_array($amount, [3, 6, 12], true)) {
            virtualServicesAdminReply('مدت پریمیوم فقط می‌تواند ۳، ۶ یا ۱۲ ماه باشد.', [[telegramFragmentButton('انصراف', 'vsf_plans')]]);
            return true;
        }
        virtualServicesAdminSetState('vsf_plan_price', ['type' => $type, 'amount' => $amount]);
        virtualServicesAdminReply('قیمت فروش این پلن را به تومان و فقط به‌صورت عدد ارسال کنید.', [[telegramFragmentButton('انصراف', 'vsf_plans')]]);
        return true;
    }

    if ($state === 'vsf_plan_price') {
        if (!ctype_digit($value) || (int) $value < 1 || (float) $value > 1000000000000) {
            virtualServicesAdminReply('قیمت معتبر نیست. مبلغ را فقط به‌صورت عدد و به تومان ارسال کنید.', [[telegramFragmentButton('انصراف', 'vsf_plans')]]);
            return true;
        }
        $type = ($data['type'] ?? '') === 'premium' ? 'premium' : 'stars';
        $amount = (int) ($data['amount'] ?? 0);
        if ($amount < 1) {
            virtualServicesAdminClearState();
            telegramFragmentAdminPlans();
            return true;
        }
        $title = $type === 'premium' ? "پریمیوم {$amount} ماهه" : number_format($amount) . ' استارز';
        $sortOrder = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM telegram_fragment_plans')->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO telegram_fragment_plans (product_type, amount, title, price, sort_order) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$type, $amount, $title, (int) $value, $sortOrder]);
        virtualServicesAdminClearState();
        telegramFragmentAdminPlan($pdo->lastInsertId());
        return true;
    }

    if (in_array($state, ['vsf_plan_name', 'vsf_plan_editprice', 'vsf_plan_emoji'], true)) {
        $id = (int) ($data['id'] ?? 0);
        $plan = telegramFragmentGetPlan($id);
        if (!$plan) {
            virtualServicesAdminClearState();
            telegramFragmentAdminPlans();
            return true;
        }
        if ($state === 'vsf_plan_name') {
            $value = telegramProductsPlainText($value);
            if ($value === '' || mb_strlen($value, 'UTF-8') > 100) {
                virtualServicesAdminReply('نام پلن باید بین ۱ تا ۱۰۰ کاراکتر باشد.', [[telegramFragmentButton('انصراف', 'vsf_plan_' . $id)]]);
                return true;
            }
            $stmt = $pdo->prepare('UPDATE telegram_fragment_plans SET title = ? WHERE id = ?');
            $stmt->execute([$value, $id]);
        } elseif ($state === 'vsf_plan_editprice') {
            if (!ctype_digit($value) || (int) $value < 1 || (float) $value > 1000000000000) {
                virtualServicesAdminReply('قیمت معتبر نیست.', [[telegramFragmentButton('انصراف', 'vsf_plan_' . $id)]]);
                return true;
            }
            $stmt = $pdo->prepare('UPDATE telegram_fragment_plans SET price = ? WHERE id = ?');
            $stmt->execute([(int) $value, $id]);
        } else {
            $emoji = virtualServicesAdminEmojiId();
            if ($emoji === null) {
                virtualServicesAdminReply('یک ایموجی پریمیوم، شناسه عددی آن یا علامت - برای حذف بفرستید.', [[telegramFragmentButton('انصراف', 'vsf_plan_' . $id)]]);
                return true;
            }
            $stmt = $pdo->prepare('UPDATE telegram_fragment_plans SET button_emoji_id = ? WHERE id = ?');
            $stmt->execute([$emoji === '' ? null : $emoji, $id]);
        }
        virtualServicesAdminClearState();
        telegramFragmentAdminPlan($id);
        return true;
    }

    return false;
}

function telegramFragmentAdminHandle()
{
    global $pdo, $datain, $user;

    $isRequest = strpos((string) $datain, 'vsf_') === 0 || strpos((string) ($user['step'] ?? ''), 'vsf_') === 0;
    if (!$isRequest) {
        return false;
    }
    telegramFragmentEnsureSchema();
    if ($datain === '' && telegramFragmentAdminHandleState()) {
        return true;
    }
    if ($datain !== '' && strpos((string) ($user['step'] ?? ''), 'vsf_') === 0) {
        virtualServicesAdminClearState();
    }
    if ($datain === 'vsf_home') {
        telegramFragmentAdminHome();
        return true;
    }
    if ($datain === 'vsf_toggle') {
        $status = telegramFragmentStatus();
        if (!$status['enabled'] && !$status['ready']) {
            virtualServicesAdminReply('ابتدا ورود تلگرام، کیف پول، کلید API و پردازشگر محلی را کامل کنید.', [[telegramFragmentButton('بازگشت', 'vsf_home')]]);
            return true;
        }
        if (!$status['enabled']) {
            $health = telegramFragmentRunWorker(['action' => 'health'], 20);
            if (empty($health['ok'])) {
                virtualServicesAdminReply('پردازشگر خرید فرگمنت آماده نیست: <code>' . telegramProductsEscape($health['message'] ?? $health['code'] ?? 'RUNTIME_ERROR') . '</code>', [[telegramFragmentButton('راهنمای نصب', 'vsf_help')], [telegramFragmentButton('بازگشت', 'vsf_home')]]);
                return true;
            }
        }
        telegramFragmentSetSetting('enabled', $status['enabled'] ? '0' : '1');
        telegramFragmentAdminHome();
        return true;
    }
    if ($datain === 'vsf_login') {
        virtualServicesAdminSetState('vsf_login_phone');
        virtualServicesAdminReply("<b>ورود به Fragment</b>\n\nشماره تلگرام متصل به حساب Fragment را با کد کشور ارسال کنید.\nنمونه: <code>+989121234567</code>\n\nبعد از ارسال، درخواست ورود را داخل تلگرام تأیید می‌کنید؛ کد یا رمز دوم از شما گرفته نمی‌شود.", [
            [telegramFragmentButton('ثبت دستی کوکی نشست', 'vsf_cookie')],
            [telegramFragmentButton('انصراف', 'vsf_home')],
        ]);
        return true;
    }
    if ($datain === 'vsf_cookie') {
        virtualServicesAdminSetState('vsf_cookie_value');
        virtualServicesAdminReply("آبجکت JSON کوکی‌های نشست Fragment را ارسال کنید. پیام ارسالی بلافاصله حذف و مقدار آن رمزگذاری می‌شود.", [[telegramFragmentButton('انصراف', 'vsf_home')]]);
        return true;
    }
    if (preg_match('/^vsf_logincheck_([a-f0-9]{32})$/', $datain, $match)) {
        $result = telegramFragmentReadLogin($match[1]);
        if (($result['status'] ?? '') === 'success') {
            virtualServicesAdminReply('ورود با موفقیت تأیید شد و نشست فرگمنت به‌صورت رمزگذاری‌شده ذخیره شد.', [[telegramFragmentButton('بررسی اتصال', 'vsf_test', 'success')], [telegramFragmentButton('بازگشت', 'vsf_home')]]);
        } elseif (in_array($result['status'] ?? '', ['starting', 'waiting'], true)) {
            virtualServicesAdminReply('هنوز تأیید ورود دریافت نشده است. درخواست ورود را داخل تلگرام تأیید کرده و دوباره بررسی کنید.', [[telegramFragmentButton('بررسی دوباره', 'vsf_logincheck_' . $match[1], 'primary')], [telegramFragmentButton('بازگشت', 'vsf_home')]]);
        } else {
            virtualServicesAdminReply('ورود انجام نشد: <code>' . telegramProductsEscape($result['error'] ?? 'LOGIN_FAILED') . '</code>', [[telegramFragmentButton('شروع دوباره', 'vsf_login')], [telegramFragmentButton('بازگشت', 'vsf_home')]]);
        }
        return true;
    }
    if ($datain === 'vsf_seed') {
        virtualServicesAdminSetState('vsf_seed_value');
        virtualServicesAdminReply("<b>اتصال کیف پول TON</b>\n\nعبارت بازیابی کیف پولی را ارسال کنید که موجودی خریدهای Fragment داخل آن قرار دارد. پیام بلافاصله حذف و عبارت با کلید اختصاصی همین ربات رمزگذاری می‌شود.", [[telegramFragmentButton('انصراف', 'vsf_home')]]);
        return true;
    }
    if ($datain === 'vsf_apikey') {
        virtualServicesAdminSetState('vsf_api_value');
        virtualServicesAdminReply('کلید API سرویس Toncenter را ارسال کنید. پیام بلافاصله حذف و کلید رمزگذاری می‌شود.', [[telegramFragmentButton('انصراف', 'vsf_home')]]);
        return true;
    }
    if ($datain === 'vsf_walletver') {
        virtualServicesAdminReply('نسخه کیف پول متصل را انتخاب کنید.', [[telegramFragmentButton('V5R1', 'vsf_setwallet_V5R1', 'primary'), telegramFragmentButton('V4R2', 'vsf_setwallet_V4R2')], [telegramFragmentButton('بازگشت', 'vsf_home')]]);
        return true;
    }
    if (preg_match('/^vsf_setwallet_(V5R1|V4R2)$/', $datain, $match)) {
        telegramFragmentSetSetting('wallet_version', $match[1]);
        telegramFragmentAdminHome();
        return true;
    }
    if ($datain === 'vsf_sender') {
        telegramFragmentSetSetting('show_sender', telegramFragmentSetting('show_sender', '0') === '1' ? '0' : '1');
        telegramFragmentAdminHome();
        return true;
    }
    if ($datain === 'vsf_alert') {
        virtualServicesAdminSetState('vsf_alert_value');
        virtualServicesAdminReply('حداقل موجودی TON برای هشدار را فقط به‌صورت عدد وارد کنید. برای غیرفعال‌کردن هشدار صفر بفرستید.', [[telegramFragmentButton('انصراف', 'vsf_home')]]);
        return true;
    }
    if ($datain === 'vsf_test') {
        $status = telegramFragmentStatus();
        if (!$status['ready']) {
            virtualServicesAdminReply('تنظیمات اتصال کامل نیست. ورود، کیف پول، کلید API و پردازشگر را بررسی کنید.', [[telegramFragmentButton('بازگشت', 'vsf_home')]]);
            return true;
        }
        virtualServicesAdminReply('در حال بررسی نشست و کیف پول فرگمنت...', []);
        $result = telegramFragmentRunWorker(['action' => 'connection', 'config' => telegramFragmentConfig()], 60);
        if (!empty($result['ok'])) {
            telegramFragmentSetSetting('last_connection_at', date('Y-m-d H:i:s'));
            $wallet = is_array($result['wallet'] ?? null) ? $result['wallet'] : [];
            $address = telegramFragmentFindValue($wallet, ['address', 'wallet_address']);
            $balance = telegramFragmentFindValue($wallet, ['gram_balance', 'ton_balance', 'balance']);
            $message = "<b>اتصال موفق بود</b>\n\nنشست Fragment و کیف پول TON توسط پردازشگر محلی شناسایی شدند.";
            if ($address !== null && $address !== '') $message .= "\n<b>آدرس کیف پول:</b> <code>" . telegramProductsEscape($address) . '</code>';
            if ($balance !== null && is_numeric($balance)) {
                $message .= "\n<b>موجودی:</b> <code>" . telegramProductsEscape($balance) . ' TON</code>';
                telegramFragmentSetSetting('last_balance', (string) $balance);
                $alertAt = (float) telegramFragmentSetting('balance_alert', '0');
                if ($alertAt > 0 && (float) $balance <= $alertAt) {
                    telegramProductsReport('alert', "<b>هشدار موجودی کیف پول فرگمنت</b>\n\nموجودی فعلی: <code>" . telegramProductsEscape($balance) . " TON</code>\nحد هشدار: <code>" . telegramProductsEscape($alertAt) . ' TON</code>');
                }
            }
            virtualServicesAdminReply($message, [[telegramFragmentButton('بازگشت', 'vsf_home')]]);
        } else {
            virtualServicesAdminReply("<b>اتصال ناموفق بود</b>\n\n<code>" . telegramProductsEscape($result['message'] ?? $result['code'] ?? 'UNKNOWN_ERROR') . '</code>', [[telegramFragmentButton('تنظیم ورود', 'vsf_login')], [telegramFragmentButton('بازگشت', 'vsf_home')]]);
        }
        return true;
    }
    if ($datain === 'vsf_help') {
        $runtime = telegramFragmentRuntimeStatus();
        $text = "<b>راهنمای اتصال فرگمنت</b>\n\n1. ورود تلگرام را انجام دهید و اعلان را داخل تلگرام تأیید کنید.\n2. کیف پول TON و کلید Toncenter را ثبت کنید.\n3. نسخه کیف پول را انتخاب و اتصال را آزمایش کنید.\n4. پلن‌ها را بسازید و فروش خودکار را روشن کنید.\n\n";
        $text .= '<b>وضعیت نصب:</b> Python worker: ' . ($runtime['worker'] ? 'موجود' : 'ناموجود') . ' | Login runtime: ' . ($runtime['node_modules'] ? 'موجود' : 'ناموجود');
        virtualServicesAdminReply($text, [[telegramFragmentButton('بازگشت', 'vsf_home')]]);
        return true;
    }
    if ($datain === 'vsf_plans') {
        telegramFragmentAdminPlans();
        return true;
    }
    if ($datain === 'vsf_add_stars') {
        virtualServicesAdminSetState('vsf_plan_amount', ['type' => 'stars']);
        virtualServicesAdminReply('تعداد استارز این پلن را فقط به‌صورت عدد ارسال کنید. مقدار مجاز از ۵۰ تا ۱۰٬۰۰۰٬۰۰۰ است.', [[telegramFragmentButton('انصراف', 'vsf_plans')]]);
        return true;
    }
    if ($datain === 'vsf_add_premium') {
        virtualServicesAdminReply('مدت پلن پریمیوم را انتخاب کنید.', [[telegramFragmentButton('۳ ماه', 'vsf_prem_3'), telegramFragmentButton('۶ ماه', 'vsf_prem_6'), telegramFragmentButton('۱۲ ماه', 'vsf_prem_12')], [telegramFragmentButton('انصراف', 'vsf_plans')]]);
        return true;
    }
    if (preg_match('/^vsf_prem_(3|6|12)$/', $datain, $match)) {
        virtualServicesAdminSetState('vsf_plan_price', ['type' => 'premium', 'amount' => (int) $match[1]]);
        virtualServicesAdminReply('قیمت فروش این پلن را به تومان و فقط به‌صورت عدد ارسال کنید.', [[telegramFragmentButton('انصراف', 'vsf_plans')]]);
        return true;
    }
    if (preg_match('/^vsf_plan_(\d+)$/', $datain, $match)) {
        telegramFragmentAdminPlan($match[1]);
        return true;
    }
    if (preg_match('/^vsf_pname_(\d+)$/', $datain, $match)) {
        virtualServicesAdminSetState('vsf_plan_name', ['id' => (int) $match[1]]);
        virtualServicesAdminReply('نام جدید پلن را ارسال کنید.', [[telegramFragmentButton('انصراف', 'vsf_plan_' . $match[1])]]);
        return true;
    }
    if (preg_match('/^vsf_pprice_(\d+)$/', $datain, $match)) {
        virtualServicesAdminSetState('vsf_plan_editprice', ['id' => (int) $match[1]]);
        virtualServicesAdminReply('قیمت جدید را به تومان و فقط به‌صورت عدد ارسال کنید.', [[telegramFragmentButton('انصراف', 'vsf_plan_' . $match[1])]]);
        return true;
    }
    if (preg_match('/^vsf_pemoji_(\d+)$/', $datain, $match)) {
        virtualServicesAdminSetState('vsf_plan_emoji', ['id' => (int) $match[1]]);
        virtualServicesAdminReply('ایموجی پریمیوم یا شناسه عددی آن را بفرستید. برای حذف، علامت - ارسال کنید.', [[telegramFragmentButton('انصراف', 'vsf_plan_' . $match[1])]]);
        return true;
    }
    if (preg_match('/^vsf_pstyle_(\d+)$/', $datain, $match)) {
        $rows = virtualServicesAdminStyleRows('vsf_setstyle', (int) $match[1]);
        $rows[] = [telegramFragmentButton('بازگشت', 'vsf_plan_' . $match[1])];
        virtualServicesAdminReply('رنگ دکمه پلن را انتخاب کنید.', $rows);
        return true;
    }
    if (preg_match('/^vsf_setstyle_(\d+)_(primary|success|danger|none)$/', $datain, $match)) {
        $stmt = $pdo->prepare('UPDATE telegram_fragment_plans SET button_style = ? WHERE id = ?');
        $stmt->execute([$match[2], (int) $match[1]]);
        telegramFragmentAdminPlan($match[1]);
        return true;
    }
    if (preg_match('/^vsf_ptoggle_(\d+)$/', $datain, $match)) {
        $stmt = $pdo->prepare('UPDATE telegram_fragment_plans SET is_active = 1 - is_active WHERE id = ?');
        $stmt->execute([(int) $match[1]]);
        telegramFragmentAdminPlan($match[1]);
        return true;
    }
    if (preg_match('/^vsf_p(up|down)_(\d+)$/', $datain, $match)) {
        $delta = $match[1] === 'up' ? -10 : 10;
        $stmt = $pdo->prepare('UPDATE telegram_fragment_plans SET sort_order = sort_order + ? WHERE id = ?');
        $stmt->execute([$delta, (int) $match[2]]);
        telegramFragmentAdminPlan($match[2]);
        return true;
    }
    if (preg_match('/^vsf_pdelete_(\d+)$/', $datain, $match)) {
        virtualServicesAdminReply('حذف این پلن را تأیید می‌کنید؟ سفارش‌های قبلی حذف نمی‌شوند.', [[telegramFragmentButton('بله، حذف شود', 'vsf_pdeleteok_' . $match[1], 'danger')], [telegramFragmentButton('انصراف', 'vsf_plan_' . $match[1])]]);
        return true;
    }
    if (preg_match('/^vsf_pdeleteok_(\d+)$/', $datain, $match)) {
        $stmt = $pdo->prepare('DELETE FROM telegram_fragment_plans WHERE id = ?');
        $stmt->execute([(int) $match[1]]);
        telegramFragmentAdminPlans();
        return true;
    }
    if ($datain === 'vsf_orders' || $datain === 'vsf_reviews') {
        telegramFragmentAdminOrders($datain === 'vsf_reviews');
        return true;
    }
    if (preg_match('/^vsf_order_(\d+)$/', $datain, $match)) {
        telegramFragmentAdminOrder($match[1]);
        return true;
    }
    if (preg_match('/^vsf_delivered_(\d+)$/', $datain, $match)) {
        $stmt = $pdo->prepare("UPDATE telegram_fragment_orders SET status = 'delivered', delivered_at = NOW(), error_message = NULL WHERE id = ? AND status = 'review'");
        $stmt->execute([(int) $match[1]]);
        $order = telegramFragmentGetOrder($match[1]);
        if ($stmt->rowCount() && $order) {
            sendmessage($order['user_id'], '<b>سفارش فرگمنت شما تحویل شد.</b>\n\nشماره سفارش: <code>#' . $order['id'] . '</code>', null, 'HTML');
        }
        telegramFragmentAdminOrder($match[1]);
        return true;
    }
    if (preg_match('/^vsf_refundask_(\d+)$/', $datain, $match)) {
        virtualServicesAdminReply('بازپرداخت این سفارش به کیف پول کاربر را تأیید می‌کنید؟ این عملیات برگشت‌پذیر نیست.', [[telegramFragmentButton('تأیید بازپرداخت', 'vsf_refundok_' . $match[1], 'danger')], [telegramFragmentButton('انصراف', 'vsf_order_' . $match[1])]]);
        return true;
    }
    if (preg_match('/^vsf_refundok_(\d+)$/', $datain, $match)) {
        try {
            $pdo->beginTransaction();
            $order = telegramFragmentGetOrder($match[1], true);
            if (!$order || $order['status'] !== 'review') {
                $pdo->rollBack();
            } else {
                $stmt = $pdo->prepare('UPDATE user SET Balance = Balance + ? WHERE id = ?');
                $stmt->execute([(int) $order['price'], $order['user_id']]);
                $stmt = $pdo->prepare("UPDATE telegram_fragment_orders SET status = 'refunded', refunded_at = NOW() WHERE id = ? AND status = 'review'");
                $stmt->execute([$order['id']]);
                $pdo->commit();
                if (function_exists('clearSelectCache')) clearSelectCache('user');
                sendmessage($order['user_id'], '<b>مبلغ سفارش فرگمنت به کیف پول شما بازگشت.</b>\n\nشماره سفارش: <code>#' . $order['id'] . '</code>\nمبلغ: ' . telegramProductsMoney($order['price']), null, 'HTML');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Fragment manual refund failed: ' . $e->getMessage());
        }
        telegramFragmentAdminOrder($match[1]);
        return true;
    }
    return true;
}

function telegramFragmentShowHome()
{
    global $pdo;

    telegramFragmentEnsureSchema();
    $counts = ['stars' => 0, 'premium' => 0];
    $rowsData = $pdo->query("SELECT product_type, COUNT(*) AS total FROM telegram_fragment_plans WHERE is_active = 1 GROUP BY product_type")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsData as $row) {
        $counts[$row['product_type']] = (int) $row['total'];
    }
    $rows = [];
    if ($counts['stars']) {
        $rows[] = [telegramFragmentButton('خرید تلگرام استارز', 'tgf_list_stars', 'primary')];
    }
    if ($counts['premium']) {
        $rows[] = [telegramFragmentButton('خرید تلگرام پریمیوم', 'tgf_list_premium', 'success')];
    }
    $rows[] = [telegramFragmentButton('سفارش‌های فرگمنت من', 'tgf_orders')];
    $rows[] = [telegramFragmentButton('بازگشت به خدمات مجازی', 'tgp_home')];
    $text = "<b>استارز و پریمیوم تلگرام</b>\n\nپس از پرداخت از کیف پول ربات، سفارش شما به‌صورت خودکار از Fragment خریداری می‌شود. گیرنده را دقیق وارد کنید.";
    if (!$counts['stars'] && !$counts['premium']) {
        $text .= "\n\nدر حال حاضر پلن فعالی برای فروش ثبت نشده است.";
    }
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function telegramFragmentShowPlans($type)
{
    global $pdo;

    if (!in_array($type, ['stars', 'premium'], true)) {
        telegramFragmentShowHome();
        return;
    }
    $stmt = $pdo->prepare('SELECT * FROM telegram_fragment_plans WHERE product_type = ? AND is_active = 1 ORDER BY sort_order, id');
    $stmt->execute([$type]);
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($plans as $plan) {
        $rows[] = [telegramFragmentButton($plan['title'] . ' | ' . telegramProductsMoney($plan['price']), 'tgf_plan_' . $plan['id'], $plan['button_style'], $plan['button_emoji_id'])];
    }
    $rows[] = [telegramFragmentButton('بازگشت', 'tgf_home')];
    $text = '<b>' . telegramFragmentTypeLabel($type) . "</b>\n\nپلن موردنظر را انتخاب کنید.";
    if (!$plans) $text .= "\n\nپلن فعالی موجود نیست.";
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function telegramFragmentShowPlan($id)
{
    $plan = telegramFragmentGetPlan($id, true);
    if (!$plan) {
        telegramProductsReply('این پلن دیگر در دسترس نیست.', json_encode(['inline_keyboard' => [[telegramFragmentButton('بازگشت', 'tgf_home')]]], JSON_UNESCAPED_UNICODE));
        return;
    }
    $unit = $plan['product_type'] === 'premium' ? ' ماه' : ' استارز';
    $text = "<b>" . telegramProductsEscape($plan['title']) . "</b>\n\n";
    $text .= '<b>مقدار:</b> ' . number_format((int) $plan['amount']) . $unit . "\n";
    $text .= '<b>مبلغ:</b> ' . telegramProductsMoney($plan['price']) . "\n\n";
    $text .= 'برای ادامه، نام کاربری تلگرام گیرنده را وارد می‌کنید.';
    $rows = [[telegramFragmentButton('ادامه خرید', 'tgf_buy_' . $plan['id'], 'success')], [telegramFragmentButton('بازگشت', 'tgf_list_' . $plan['product_type'])]];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function telegramFragmentCreateOrder($planId, $recipient)
{
    global $pdo, $from_id;

    $plan = telegramFragmentGetPlan($planId, true);
    if (!$plan) return null;
    $stmt = $pdo->prepare("INSERT INTO telegram_fragment_orders (user_id, plan_id, recipient, product_type, amount, title, price, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([(string) $from_id, $plan['id'], $recipient, $plan['product_type'], $plan['amount'], $plan['title'], $plan['price']]);
    return telegramFragmentGetOrder($pdo->lastInsertId());
}

function telegramFragmentShowCheckout($orderId)
{
    global $from_id;

    $order = telegramFragmentGetOrder($orderId);
    if (!$order || (string) $order['user_id'] !== (string) $from_id) {
        telegramProductsReply('فاکتور پیدا نشد.', null);
        return;
    }
    $text = "<b>فاکتور خرید از فرگمنت</b>\n\n";
    $text .= '<b>محصول:</b> ' . telegramProductsEscape($order['title']) . "\n";
    $text .= '<b>گیرنده:</b> <code>' . telegramProductsEscape($order['recipient']) . "</code>\n";
    $text .= '<b>مبلغ:</b> ' . telegramProductsMoney($order['price']) . "\n\n";
    $text .= 'نام کاربری گیرنده را بررسی کنید. پس از تأیید، مبلغ از کیف پول مشترک ربات کسر و خرید خودکار آغاز می‌شود.';
    $rows = [];
    if ($order['status'] === 'pending') {
        $rows[] = [telegramFragmentButton('تأیید و پرداخت', 'tgf_pay_' . $order['id'], 'success')];
    } else {
        $text .= "\n\n<b>وضعیت:</b> " . telegramFragmentStatusLabel($order['status']);
    }
    $rows[] = [telegramFragmentButton('بازگشت', 'tgf_home')];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function telegramFragmentShowOrders()
{
    global $pdo, $from_id;

    $stmt = $pdo->prepare('SELECT * FROM telegram_fragment_orders WHERE user_id = ? ORDER BY id DESC LIMIT 15');
    $stmt->execute([(string) $from_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $text = "<b>سفارش‌های فرگمنت من</b>\n\n";
    $rows = [];
    if (!$orders) $text .= 'هنوز سفارشی ثبت نکرده‌اید.';
    foreach ($orders as $order) {
        $rows[] = [telegramFragmentButton('#' . $order['id'] . ' | ' . $order['title'] . ' | ' . telegramFragmentStatusLabel($order['status']), 'tgf_order_' . $order['id'])];
    }
    $rows[] = [telegramFragmentButton('بازگشت', 'tgf_home')];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function telegramFragmentShowOrder($id)
{
    global $from_id;

    $order = telegramFragmentGetOrder($id);
    if (!$order || (string) $order['user_id'] !== (string) $from_id) {
        telegramProductsReply('سفارش پیدا نشد.', null);
        return;
    }
    $text = "<b>سفارش فرگمنت #{$order['id']}</b>\n\n";
    $text .= '<b>محصول:</b> ' . telegramProductsEscape($order['title']) . "\n";
    $text .= '<b>گیرنده:</b> <code>' . telegramProductsEscape($order['recipient']) . "</code>\n";
    $text .= '<b>مبلغ:</b> ' . telegramProductsMoney($order['price']) . "\n";
    $text .= '<b>وضعیت:</b> ' . telegramFragmentStatusLabel($order['status']);
    if ($order['provider_tx']) $text .= "\n<b>شناسه تراکنش:</b> <code>" . telegramProductsEscape($order['provider_tx']) . '</code>';
    telegramProductsReply($text, json_encode(['inline_keyboard' => [[telegramFragmentButton('بازگشت', 'tgf_orders')]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function telegramFragmentPayOrder($orderId)
{
    global $pdo, $from_id;

    if (telegramProductsSetting('enabled', '1') !== '1' || !telegramFragmentStatus()['ready'] || telegramFragmentSetting('enabled', '0') !== '1') {
        telegramProductsReply('فروش خودکار فرگمنت موقتاً در دسترس نیست و مبلغی کسر نشد.', null);
        return;
    }
    try {
        $pdo->beginTransaction();
        $order = telegramFragmentGetOrder($orderId, true);
        if (!$order || (string) $order['user_id'] !== (string) $from_id || $order['status'] !== 'pending') {
            $pdo->rollBack();
            telegramProductsReply('این فاکتور قابل پرداخت نیست.', null);
            return;
        }
        $plan = telegramFragmentGetPlan($order['plan_id'], true);
        if (!$plan || (int) $plan['price'] !== (int) $order['price']) {
            $pdo->rollBack();
            telegramProductsReply('پلن یا قیمت آن تغییر کرده است. لطفاً خرید را دوباره آغاز کنید.', null);
            return;
        }
        $stmt = $pdo->prepare('SELECT Balance, agent, maxbuyagent FROM user WHERE id = ? FOR UPDATE');
        $stmt->execute([$from_id]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['Balance' => 0, 'agent' => 'f', 'maxbuyagent' => 0];
        $balance = (int) $wallet['Balance'];
        $credit = $wallet['agent'] === 'n2' ? (int) $wallet['maxbuyagent'] : 0;
        if (!($balance >= (int) $order['price'] || ($credit > 0 && $balance - (int) $order['price'] >= -$credit))) {
            $pdo->rollBack();
            telegramProductsReply('موجودی کیف پول برای این خرید کافی نیست.', json_encode(['inline_keyboard' => [[['text' => 'افزایش موجودی', 'callback_data' => 'account']], [telegramFragmentButton('بازگشت', 'tgf_order_' . $order['id'])]]], JSON_UNESCAPED_UNICODE));
            return;
        }
        $stmt = $pdo->prepare('UPDATE user SET Balance = Balance - ? WHERE id = ?');
        $stmt->execute([(int) $order['price'], $from_id]);
        $stmt = $pdo->prepare("UPDATE telegram_fragment_orders SET status = 'processing', paid_at = NOW() WHERE id = ? AND status = 'pending'");
        $stmt->execute([$order['id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Order state changed during payment.');
        }
        $pdo->commit();
        if (function_exists('clearSelectCache')) clearSelectCache('user');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Fragment charge failed: ' . $e->getMessage());
        telegramProductsReply('پرداخت انجام نشد. لطفاً دوباره تلاش کنید.', null);
        return;
    }

    telegramProductsReply("<b>پرداخت انجام شد</b>\n\nدر حال ثبت خرید شما در Fragment هستیم. این مرحله ممکن است کمی زمان ببرد.", null);
    $result = telegramFragmentRunWorker([
        'action' => 'purchase',
        'config' => telegramFragmentConfig(),
        'product' => $order['product_type'],
        'amount' => (int) $order['amount'],
        'username' => ltrim($order['recipient'], '@'),
    ]);
    $publicResult = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!empty($result['ok'])) {
        $stmt = $pdo->prepare("UPDATE telegram_fragment_orders SET status = 'delivered', provider_tx = ?, provider_response = ?, delivered_at = NOW(), error_message = NULL WHERE id = ? AND status = 'processing'");
        $stmt->execute([(string) ($result['transaction_id'] ?? ''), substr($publicResult, 0, 50000), $order['id']]);
        $newBalance = $balance - (int) $order['price'];
        telegramProductsReply("<b>خرید با موفقیت انجام شد</b>\n\n<b>محصول:</b> " . telegramProductsEscape($order['title']) . "\n<b>گیرنده:</b> <code>" . telegramProductsEscape($order['recipient']) . "</code>\n<b>شماره سفارش:</b> <code>#{$order['id']}</code>", json_encode(['inline_keyboard' => [[telegramFragmentButton('سفارش‌های من', 'tgf_orders')], [telegramFragmentButton('بازگشت', 'tgf_home')]]], JSON_UNESCAPED_UNICODE));
        telegramProductsReport('sale', "<b>خرید خودکار فرگمنت</b>\n\n<b>سفارش:</b> <code>#{$order['id']}</code>\n<b>کاربر:</b> <code>" . telegramProductsEscape($from_id) . "</code>\n<b>گیرنده:</b> <code>" . telegramProductsEscape($order['recipient']) . "</code>\n<b>محصول:</b> " . telegramProductsEscape($order['title']) . "\n<b>مبلغ:</b> " . telegramProductsMoney($order['price']) . "\n<b>مانده کیف پول:</b> " . telegramProductsMoney($newBalance));
        return;
    }

    $error = substr((string) ($result['message'] ?? $result['code'] ?? 'UNKNOWN_ERROR'), 0, 1000);
    if (!empty($result['retry_safe'])) {
        $refunded = false;
        try {
            $pdo->beginTransaction();
            $locked = telegramFragmentGetOrder($order['id'], true);
            if ($locked && $locked['status'] === 'processing') {
                $stmt = $pdo->prepare('UPDATE user SET Balance = Balance + ? WHERE id = ?');
                $stmt->execute([(int) $order['price'], $from_id]);
                $stmt = $pdo->prepare("UPDATE telegram_fragment_orders SET status = 'failed_refunded', refunded_at = NOW(), error_message = ?, provider_response = ? WHERE id = ? AND status = 'processing'");
                $stmt->execute([$error, substr($publicResult, 0, 50000), $order['id']]);
                $refunded = $stmt->rowCount() === 1;
            }
            $pdo->commit();
            if (function_exists('clearSelectCache')) clearSelectCache('user');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Fragment automatic refund failed: ' . $e->getMessage());
            telegramProductsReport('error', "<b>خطای بازپرداخت خودکار فرگمنت</b>\n\nسفارش: <code>#{$order['id']}</code>\n<code>" . telegramProductsEscape($e->getMessage()) . '</code>');
        }
        if (!$refunded) {
            $stmt = $pdo->prepare("UPDATE telegram_fragment_orders SET status = 'review', error_message = ?, provider_response = ? WHERE id = ? AND status = 'processing'");
            $stmt->execute(['Automatic refund needs review: ' . $error, substr($publicResult, 0, 50000), $order['id']]);
            telegramProductsReply("خرید انجام نشد و بازپرداخت نیازمند بررسی ادمین است.\n\nشماره سفارش: <code>#{$order['id']}</code>", json_encode(['inline_keyboard' => [[telegramFragmentButton('مشاهده سفارش', 'tgf_order_' . $order['id'])]]], JSON_UNESCAPED_UNICODE));
            return;
        }
        telegramProductsReply("خرید انجام نشد و مبلغ کامل به کیف پول شما بازگشت.\n\nشماره سفارش: <code>#{$order['id']}</code>", json_encode(['inline_keyboard' => [[telegramFragmentButton('بازگشت', 'tgf_home')]]], JSON_UNESCAPED_UNICODE));
        telegramProductsReport('error', "<b>خرید ناموفق فرگمنت و بازپرداخت</b>\n\nسفارش: <code>#{$order['id']}</code>\nخطا: <code>" . telegramProductsEscape($error) . '</code>');
        return;
    }

    $stmt = $pdo->prepare("UPDATE telegram_fragment_orders SET status = 'review', error_message = ?, provider_response = ? WHERE id = ? AND status = 'processing'");
    $stmt->execute([$error, substr($publicResult, 0, 50000), $order['id']]);
    telegramProductsReply("نتیجه خرید از فرگمنت قطعی دریافت نشد. سفارش برای بررسی ادمین ثبت شد و برای جلوگیری از خرید تکراری دوباره اجرا نمی‌شود.\n\nشماره سفارش: <code>#{$order['id']}</code>", json_encode(['inline_keyboard' => [[telegramFragmentButton('مشاهده سفارش', 'tgf_order_' . $order['id'])]]], JSON_UNESCAPED_UNICODE));
    telegramProductsReport('error', "<b>سفارش فرگمنت نیازمند بررسی</b>\n\nسفارش: <code>#{$order['id']}</code>\nکاربر: <code>" . telegramProductsEscape($from_id) . "</code>\nخطا: <code>" . telegramProductsEscape($error) . '</code>');
}

function telegramFragmentUserHandle()
{
    global $datain, $text, $user, $from_id;

    $step = (string) ($user['step'] ?? '');
    $isRequest = strpos((string) $datain, 'tgf_') === 0 || strpos($step, 'tgf_') === 0;
    if (!$isRequest) return false;
    telegramFragmentEnsureSchema();

    if (telegramProductsSetting('enabled', '1') !== '1' || telegramFragmentSetting('enabled', '0') !== '1' || !telegramFragmentStatus()['ready']) {
        if (strpos($step, 'tgf_') === 0) step('home', $from_id);
        telegramProductsReply('فروش خودکار استارز و پریمیوم موقتاً در دسترس نیست.', json_encode(['inline_keyboard' => [[telegramFragmentButton('بازگشت', 'tgp_home')]]], JSON_UNESCAPED_UNICODE));
        return true;
    }

    if ($datain !== '' && strpos($step, 'tgf_') === 0) {
        step('home', $from_id);
        $user['step'] = 'home';
        $step = 'home';
    }
    if (preg_match('/^tgf_recipient_(\d+)$/', $step, $match) && $datain === '') {
        $recipient = trim(telegramProductsPlainText((string) $text));
        if ($recipient !== '' && $recipient[0] !== '@') $recipient = '@' . $recipient;
        if (!preg_match('/^@[A-Za-z0-9_]{5,32}$/', $recipient)) {
            telegramProductsReply('نام کاربری معتبر نیست. آن را مانند <code>@username</code> ارسال کنید.', json_encode(['inline_keyboard' => [[telegramFragmentButton('انصراف', 'tgf_plan_' . $match[1])]]], JSON_UNESCAPED_UNICODE), false);
            return true;
        }
        step('home', $from_id);
        $order = telegramFragmentCreateOrder($match[1], $recipient);
        if (!$order) {
            telegramProductsReply('این پلن دیگر در دسترس نیست.', null, false);
            return true;
        }
        telegramFragmentShowCheckout($order['id']);
        return true;
    }
    if ($datain === 'tgf_home') {
        telegramFragmentShowHome();
        return true;
    }
    if (preg_match('/^tgf_list_(stars|premium)$/', $datain, $match)) {
        telegramFragmentShowPlans($match[1]);
        return true;
    }
    if (preg_match('/^tgf_plan_(\d+)$/', $datain, $match)) {
        telegramFragmentShowPlan($match[1]);
        return true;
    }
    if (preg_match('/^tgf_buy_(\d+)$/', $datain, $match)) {
        $plan = telegramFragmentGetPlan($match[1], true);
        if (!$plan) {
            telegramProductsReply('این پلن دیگر در دسترس نیست.', null);
            return true;
        }
        step('tgf_recipient_' . $plan['id'], $from_id);
        telegramProductsReply("<b>نام کاربری گیرنده</b>\n\nنام کاربری تلگرام شخصی را ارسال کنید که استارز یا پریمیوم باید برای او خریداری شود.\nنمونه: <code>@username</code>", json_encode(['inline_keyboard' => [[telegramFragmentButton('انصراف', 'tgf_plan_' . $plan['id'])]]], JSON_UNESCAPED_UNICODE));
        return true;
    }
    if (preg_match('/^tgf_pay_(\d+)$/', $datain, $match)) {
        telegramFragmentPayOrder($match[1]);
        return true;
    }
    if ($datain === 'tgf_orders') {
        telegramFragmentShowOrders();
        return true;
    }
    if (preg_match('/^tgf_order_(\d+)$/', $datain, $match)) {
        telegramFragmentShowOrder($match[1]);
        return true;
    }
    return true;
}

