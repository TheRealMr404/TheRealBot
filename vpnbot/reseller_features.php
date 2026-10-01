<?php

function resellerBotSettingsDefaults()
{
    return [
        'bot_enabled' => true,
        'maintenance_text' => 'ربات موقتاً در حال بروزرسانی است. لطفاً کمی بعد دوباره تلاش کنید.',
        'welcome_text' => 'سلام {name} عزیز، به فروشگاه ما خوش آمدید.\n\nبرای ادامه یک بخش را انتخاب کنید:',
        'payment_intro_text' => 'روش پرداخت موردنظر را برای شارژ {amount} تومانی انتخاب کنید.',
        'payment_success_text' => "پرداخت شما با موفقیت تأیید شد.\n\nمبلغ: {amount} تومان\nروش پرداخت: {method}\nکد پیگیری: {order}\nموجودی جدید: {balance} تومان",
        'notify_admin_payment' => true,
        'min_deposit' => 10000,
        'max_deposit' => 100000000,
        'report_chat_id' => '',
        'payment_gateways' => [
            'card' => ['enabled' => true, 'title' => 'کارت به کارت', 'style' => 'primary', 'emoji_id' => '', 'order' => 10],
            'zarinpal' => ['enabled' => false, 'title' => 'زرین‌پال', 'style' => 'success', 'emoji_id' => '', 'order' => 20],
            'aqayepardakht' => ['enabled' => false, 'title' => 'آقای پرداخت', 'style' => 'primary', 'emoji_id' => '', 'order' => 30],
            'nowpayments' => ['enabled' => false, 'title' => 'NOWPayments', 'style' => 'success', 'emoji_id' => '', 'order' => 40],
        ],
    ];
}

function resellerBotNormalizeSettings($settings)
{
    $settings = is_array($settings) ? $settings : [];
    $defaults = resellerBotSettingsDefaults();
    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $settings)) {
            $settings[$key] = $value;
        }
    }
    $settings['bot_enabled'] = (bool) $settings['bot_enabled'];
    $settings['min_deposit'] = max(1000, (int) $settings['min_deposit']);
    $settings['max_deposit'] = max($settings['min_deposit'], (int) $settings['max_deposit']);
    $settings['maintenance_text'] = trim((string) $settings['maintenance_text']) ?: $defaults['maintenance_text'];
    $settings['welcome_text'] = trim((string) $settings['welcome_text']) ?: $defaults['welcome_text'];
    $settings['payment_intro_text'] = trim((string) $settings['payment_intro_text']) ?: $defaults['payment_intro_text'];
    $settings['payment_success_text'] = trim((string) $settings['payment_success_text']) ?: $defaults['payment_success_text'];
    $settings['notify_admin_payment'] = (bool) $settings['notify_admin_payment'];
    $settings['report_chat_id'] = trim((string) $settings['report_chat_id']);

    $currentGateways = is_array($settings['payment_gateways'] ?? null) ? $settings['payment_gateways'] : [];
    foreach ($defaults['payment_gateways'] as $key => $gatewayDefault) {
        $gateway = is_array($currentGateways[$key] ?? null) ? $currentGateways[$key] : [];
        $gateway = array_merge($gatewayDefault, $gateway);
        $gateway['enabled'] = (bool) $gateway['enabled'];
        $gateway['title'] = trim((string) $gateway['title']) ?: $gatewayDefault['title'];
        $gateway['style'] = in_array($gateway['style'], ['primary', 'success', 'danger'], true)
            ? $gateway['style']
            : $gatewayDefault['style'];
        $gateway['emoji_id'] = preg_match('/^\d{5,30}$/', (string) $gateway['emoji_id'])
            ? (string) $gateway['emoji_id']
            : '';
        $gateway['order'] = (int) $gateway['order'];
        $currentGateways[$key] = $gateway;
    }
    $settings['payment_gateways'] = array_intersect_key($currentGateways, $defaults['payment_gateways']);
    return $settings;
}

function resellerBotSaveSettings($botToken, array $settings)
{
    $settings = resellerBotNormalizeSettings($settings);
    update(
        'botsaz',
        'setting',
        json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'bot_token',
        $botToken
    );
    return $settings;
}

function resellerMainPaySetting($name, $default = '')
{
    $row = select('PaySetting', 'ValuePay', 'NamePay', $name, 'select');
    return is_array($row) && array_key_exists('ValuePay', $row) ? (string) $row['ValuePay'] : $default;
}

function resellerGatewayIsAvailable($gatewayKey)
{
    if ($gatewayKey === 'card') {
        // Manual card payments are owned and reviewed by the reseller admins.
        return true;
    }
    if ($gatewayKey === 'zarinpal') {
        return resellerMainPaySetting('zarinpalstatus', 'offzarinpal') === 'onzarinpal'
            && resellerMainPaySetting('merchant_zarinpal') !== '';
    }
    if ($gatewayKey === 'aqayepardakht') {
        return resellerMainPaySetting('statusaqayepardakht', 'offaqayepardakht') === 'onaqayepardakht'
            && resellerMainPaySetting('merchant_id_aqayepardakht') !== '';
    }
    if ($gatewayKey === 'nowpayments') {
        $apiKey = resellerMainPaySetting('marchent_tronseller');
        return resellerMainPaySetting('statusnowpayment', '0') === '1'
            && $apiKey !== ''
            && $apiKey !== '0';
    }
    return false;
}

function resellerGatewayAmountRange($gatewayKey)
{
    $map = [
        'zarinpal' => ['minbalancezarinpal', 'maxbalancezarinpal'],
        'aqayepardakht' => ['minbalanceaqayepardakht', 'maxbalanceaqayepardakht'],
        'nowpayments' => ['minbalancenowpayment', 'maxbalancenowpayment'],
    ];
    if (!isset($map[$gatewayKey])) {
        return ['min' => 0, 'max' => PHP_INT_MAX];
    }
    $minimum = max(0, (int) resellerMainPaySetting($map[$gatewayKey][0], '0'));
    $maximum = (int) resellerMainPaySetting($map[$gatewayKey][1], '0');
    return [
        'min' => $minimum,
        'max' => $maximum > 0 ? $maximum : PHP_INT_MAX,
    ];
}

function resellerGatewayCatalog(array $settings)
{
    $settings = resellerBotNormalizeSettings($settings);
    $callbacks = [
        'card' => 'reseller_pay_card',
        'zarinpal' => 'reseller_pay_zarinpal',
        'aqayepardakht' => 'reseller_pay_aqayepardakht',
        'nowpayments' => 'reseller_pay_nowpayments',
    ];
    $catalog = [];
    foreach ($settings['payment_gateways'] as $key => $gateway) {
        $gateway['key'] = $key;
        $gateway['callback_data'] = $callbacks[$key];
        $gateway['available'] = resellerGatewayIsAvailable($key);
        $catalog[] = $gateway;
    }
    usort($catalog, function ($left, $right) {
        return ((int) $left['order'] <=> (int) $right['order']) ?: strcmp($left['key'], $right['key']);
    });
    return $catalog;
}

function resellerPaymentKeyboard(array $settings, $backCallback = 'account')
{
    $rows = [];
    foreach (resellerGatewayCatalog($settings) as $gateway) {
        if (!$gateway['enabled'] || !$gateway['available']) {
            continue;
        }
        $button = [
            'text' => $gateway['title'],
            'callback_data' => $gateway['callback_data'],
            'style' => $gateway['style'],
        ];
        if ($gateway['emoji_id'] !== '') {
            $button['icon_custom_emoji_id'] = $gateway['emoji_id'];
        }
        $rows[] = [$button];
    }
    $rows[] = [[
        'text' => 'بازگشت',
        'callback_data' => $backCallback,
    ]];
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function resellerGatewayAdminView(array $settings)
{
    $rows = [];
    foreach (resellerGatewayCatalog($settings) as $gateway) {
        $status = $gateway['enabled'] ? 'روشن' : 'خاموش';
        if (!$gateway['available']) {
            $status = 'غیرفعال در ربات اصلی';
        }
        $rows[] = [
            ['text' => '↑', 'callback_data' => 'rsgw_move_' . $gateway['key'] . '_up'],
            ['text' => '↓', 'callback_data' => 'rsgw_move_' . $gateway['key'] . '_down'],
            ['text' => $gateway['title'], 'callback_data' => 'rsgw_edit_' . $gateway['key']],
            ['text' => $status, 'callback_data' => 'rsgw_toggle_' . $gateway['key']],
        ];
    }
    $rows[] = [[
        'text' => 'بازگشت به مدیریت',
        'callback_data' => 'admin',
    ]];
    return [
        'text' => "💳 <b>مدیریت درگاه‌های ربات نماینده</b>\n\n"
            . "درگاه‌های آنلاین از اطلاعات اتصال ربات اصلی استفاده می‌کنند. هر درگاه آنلاین که در ربات اصلی خاموش باشد، اینجا نیز برای کاربر نمایش داده نمی‌شود.\n\n"
            . "برای شخصی‌سازی روی نام درگاه بزنید و برای جابه‌جایی از فلش‌ها استفاده کنید.",
        'keyboard' => json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function resellerGatewayEditorView(array $settings, $gatewayKey)
{
    $settings = resellerBotNormalizeSettings($settings);
    if (!isset($settings['payment_gateways'][$gatewayKey])) {
        return null;
    }
    $gateway = $settings['payment_gateways'][$gatewayKey];
    $styleLabels = ['primary' => 'آبی', 'success' => 'سبز', 'danger' => 'قرمز'];
    $emoji = $gateway['emoji_id'] !== '' ? '<code>' . $gateway['emoji_id'] . '</code>' : 'تنظیم نشده';
    $rows = [
        [['text' => 'تغییر نام نمایشی', 'callback_data' => 'rsgw_title_' . $gatewayKey]],
        [['text' => 'رنگ بعدی', 'callback_data' => 'rsgw_style_' . $gatewayKey]],
        [
            ['text' => 'حذف ایموجی', 'callback_data' => 'rsgw_emoji_clear_' . $gatewayKey],
            ['text' => 'تنظیم ایموجی', 'callback_data' => 'rsgw_emoji_set_' . $gatewayKey],
        ],
        [['text' => 'بازگشت به درگاه‌ها', 'callback_data' => 'rsgw_back']],
    ];
    return [
        'text' => "🎨 <b>شخصی‌سازی درگاه</b>\n\n"
            . 'نام: <b>' . htmlspecialchars($gateway['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n"
            . 'رنگ: <b>' . ($styleLabels[$gateway['style']] ?? $gateway['style']) . "</b>\n"
            . "شناسه ایموجی: {$emoji}",
        'keyboard' => json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function resellerSendConfiguredReport(array $settings, $text, $botToken = null)
{
    $settings = resellerBotNormalizeSettings($settings);
    if ($settings['report_chat_id'] === '') {
        return false;
    }
    $response = telegram('sendmessage', [
        'chat_id' => $settings['report_chat_id'],
        'text' => (string) $text,
        'parse_mode' => 'HTML',
    ], $botToken);
    return is_array($response) && !empty($response['ok']);
}

function resellerRenderTextTemplate($template, array $values)
{
    $safeTemplate = htmlspecialchars((string) $template, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $replacements = [];
    foreach ($values as $key => $value) {
        $replacements['{' . $key . '}'] = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    return strtr(str_replace('\\n', "\n", $safeTemplate), $replacements);
}

function resellerBotWalletPath(array $bot, $userId)
{
    $ownerId = preg_replace('/\D+/', '', (string) ($bot['id_user'] ?? ''));
    $username = preg_replace('/[^A-Za-z0-9_]+/', '', (string) ($bot['username'] ?? ''));
    $userId = preg_replace('/\D+/', '', (string) $userId);
    if ($ownerId === '' || $username === '' || $userId === '') {
        return '';
    }
    return __DIR__ . '/' . $ownerId . $username . '/data/' . $userId . '/' . $userId . '.json';
}

function resellerCompleteOnlinePayment($orderId, $methodTitle, array $details = [])
{
    global $pdo;
    $orderId = trim((string) $orderId);
    if ($orderId === '' || !$pdo) {
        return ['ok' => false, 'msg' => 'Invalid reseller payment'];
    }

    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM Payment_report WHERE id_order = :id_order FOR UPDATE');
        $stmt->execute([':id_order' => $orderId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment || empty($payment['bottype'])) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'msg' => 'Reseller payment not found'];
        }
        if (($payment['payment_Status'] ?? '') === 'paid') {
            if ($startedTransaction) {
                $pdo->commit();
            }
            return ['ok' => true, 'already_paid' => true, 'payment' => $payment];
        }

        $bot = select('botsaz', '*', 'bot_token', $payment['bottype'], 'select');
        if (!$bot) {
            throw new RuntimeException('Reseller bot not found');
        }
        $walletPath = resellerBotWalletPath($bot, $payment['id_user']);
        if ($walletPath === '') {
            throw new RuntimeException('Invalid reseller wallet path');
        }
        $walletDirectory = dirname($walletPath);
        if (!is_dir($walletDirectory) && !mkdir($walletDirectory, 0750, true) && !is_dir($walletDirectory)) {
            throw new RuntimeException('Unable to create reseller wallet directory');
        }

        $handle = fopen($walletPath, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Unable to lock reseller wallet');
        }
        $rawWallet = stream_get_contents($handle);
        $wallet = json_decode((string) $rawWallet, true);
        $wallet = is_array($wallet) ? $wallet : [];
        $processed = is_array($wallet['processed_payments'] ?? null) ? $wallet['processed_payments'] : [];
        if (!in_array($orderId, $processed, true)) {
            $wallet['Balance'] = (int) ($wallet['Balance'] ?? 0) + (int) $payment['price'];
            $processed[] = $orderId;
            $wallet['processed_payments'] = array_slice(array_values(array_unique($processed)), -100);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($wallet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($handle);
        }
        flock($handle, LOCK_UN);
        fclose($handle);

        $update = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid', at_updated = :updated WHERE id_order = :id_order");
        $update->execute([':updated' => date('Y-m-d H:i:s'), ':id_order' => $orderId]);
        if ($startedTransaction) {
            $pdo->commit();
        }

        $settings = resellerBotNormalizeSettings(json_decode($bot['setting'] ?? '{}', true));
        $amount = number_format((int) $payment['price']);
        $balance = number_format((int) ($wallet['Balance'] ?? 0));
        $message = resellerRenderTextTemplate($settings['payment_success_text'], [
            'amount' => $amount,
            'balance' => $balance,
            'method' => $methodTitle,
            'order' => $orderId,
        ]);
        telegram('sendmessage', [
            'chat_id' => $payment['id_user'],
            'text' => $message,
            'parse_mode' => 'HTML',
        ], $bot['bot_token']);

        $adminIds = json_decode($bot['admin_ids'] ?? '[]', true);
        $adminIds = is_array($adminIds) ? $adminIds : [];
        $detailText = '';
        foreach ($details as $label => $value) {
            if ($value !== '' && $value !== null) {
                $detailText .= "\n{$label}: <code>" . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
            }
        }
        $adminMessage = "💳 <b>پرداخت جدید در ربات نماینده</b>\n\n"
            . "کاربر: <code>{$payment['id_user']}</code>\n"
            . "مبلغ: {$amount} تومان\n"
            . "درگاه: {$methodTitle}\n"
            . "سفارش: <code>{$orderId}</code>{$detailText}";
        if ($settings['notify_admin_payment']) {
            foreach (array_unique($adminIds) as $adminId) {
                telegram('sendmessage', [
                    'chat_id' => $adminId,
                    'text' => $adminMessage,
                    'parse_mode' => 'HTML',
                ], $bot['bot_token']);
            }
        }
        if ($settings['report_chat_id'] !== '') {
            telegram('sendmessage', [
                'chat_id' => $settings['report_chat_id'],
                'text' => $adminMessage,
                'parse_mode' => 'HTML',
            ], $bot['bot_token']);
        }
        return ['ok' => true, 'already_paid' => false, 'payment' => $payment, 'balance' => $wallet['Balance'] ?? 0];
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Reseller payment completion failed: ' . $exception->getMessage());
        return ['ok' => false, 'msg' => $exception->getMessage()];
    }
}
