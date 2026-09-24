<?php

function telegramProductsAdminRole($adminId)
{
    global $pdo, $admin_ids;
    $stmt = $pdo->prepare('SELECT role_key FROM telegram_product_admin_roles WHERE admin_id = ? AND is_active = 1');
    $stmt->execute([(string) $adminId]);
    $role = $stmt->fetchColumn();
    if ($role !== false) {
        return (string) $role;
    }
    $ids = array_values(array_map('strval', (array) $admin_ids));
    return isset($ids[0]) && $ids[0] === (string) $adminId ? 'owner' : 'manager';
}

function telegramProductsAdminCan($adminId, $permission)
{
    $permissions = [
        'owner' => ['catalog', 'orders', 'finance', 'discounts', 'warranty', 'settings', 'roles'],
        'manager' => ['catalog', 'orders', 'finance', 'discounts', 'warranty', 'settings'],
        'operator' => ['orders', 'warranty'],
        'catalog' => ['catalog'],
        'support' => ['orders', 'warranty'],
    ];
    return in_array($permission, $permissions[telegramProductsAdminRole($adminId)] ?? [], true);
}

function telegramProductsRequireAdminPermission($permission)
{
    global $from_id;
    if (telegramProductsAdminCan($from_id, $permission)) {
        return true;
    }
    virtualServicesAdminReply('شما برای انجام این عملیات دسترسی لازم را ندارید.', [[['text' => 'بازگشت', 'callback_data' => 'vsa_home']]]);
    return false;
}

function telegramProductsFields($productId)
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM telegram_product_fields WHERE product_id = ? AND is_active = 1 ORDER BY sort_order, id');
    $stmt->execute([(int) $productId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function telegramProductsValidateField(array $field, $value)
{
    $value = trim((string) $value);
    if ($value === '' && (int) $field['is_required'] === 0) {
        return [true, ''];
    }
    if ($value === '') {
        return [false, 'این فیلد الزامی است.'];
    }
    $length = mb_strlen($value, 'UTF-8');
    if ($length < (int) $field['min_length'] || $length > (int) $field['max_length']) {
        return [false, 'طول مقدار واردشده مجاز نیست.'];
    }
    $type = $field['field_type'];
    if ($type === 'username' && !preg_match('/^@?[A-Za-z0-9_]{5,32}$/', $value)) {
        return [false, 'یوزرنیم معتبر نیست.'];
    }
    if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return [false, 'ایمیل معتبر نیست.'];
    }
    if ($type === 'phone' && !preg_match('/^\+?[0-9]{10,15}$/', preg_replace('/[\s-]/', '', $value))) {
        return [false, 'شماره تماس معتبر نیست.'];
    }
    if ($type === 'number' && !is_numeric($value)) {
        return [false, 'فقط مقدار عددی مجاز است.'];
    }
    if (!empty($field['validation_pattern']) && @preg_match($field['validation_pattern'], '') !== false && !preg_match($field['validation_pattern'], $value)) {
        return [false, 'فرمت مقدار واردشده صحیح نیست.'];
    }
    return [true, $value];
}

function telegramProductsStartForm(array $product)
{
    global $from_id;
    $fields = telegramProductsFields($product['id']);
    if (!$fields) {
        telegramProductsCreateDraft($product['id'], null);
        return;
    }
    update('user', 'Processing_value', json_encode(['product_id' => (int) $product['id'], 'field_index' => 0, 'answers' => []], JSON_UNESCAPED_UNICODE), 'id', $from_id);
    step('tgp_form_input', $from_id);
    telegramProductsAskFormField($product['id'], 0, $fields);
}

function telegramProductsAskFormField($productId, $index, $fields = null)
{
    $fields = $fields ?: telegramProductsFields($productId);
    if (!isset($fields[$index])) {
        return;
    }
    $field = $fields[$index];
    $text = '<b>' . telegramProductsSafeCustomText($field['label']) . '</b>';
    $text .= "\n\nفیلد " . ($index + 1) . ' از ' . count($fields);
    $rows = [];
    if ($field['field_type'] === 'select') {
        $options = json_decode((string) $field['options_json'], true) ?: [];
        foreach ($options as $optionIndex => $option) {
            $rows[] = [['text' => (string) $option, 'callback_data' => "tgp_formopt_{$productId}_{$field['id']}_{$optionIndex}"]];
        }
    }
    if ((int) $field['is_required'] === 0) {
        $rows[] = [['text' => 'رد کردن این فیلد', 'callback_data' => "tgp_formskip_{$productId}_{$field['id']}"]];
    }
    $rows[] = [['text' => 'انصراف', 'callback_data' => 'tgp_view_' . $productId, 'style' => 'danger']];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE), false);
}

function telegramProductsFormSession()
{
    global $user;
    $data = json_decode((string) ($user['Processing_value'] ?? ''), true);
    return is_array($data) ? $data : [];
}

function telegramProductsSaveFormAnswer($value, $fieldId = null)
{
    global $from_id, $user;
    $session = telegramProductsFormSession();
    $productId = (int) ($session['product_id'] ?? 0);
    $fields = telegramProductsFields($productId);
    $index = (int) ($session['field_index'] ?? 0);
    $field = $fields[$index] ?? null;
    if (!$field || ($fieldId !== null && (int) $field['id'] !== (int) $fieldId)) {
        step('home', $from_id);
        telegramProductsReply('فرم منقضی شده است. دوباره خرید را آغاز کنید.', null, false);
        return true;
    }
    [$valid, $normalized] = telegramProductsValidateField($field, $value);
    if (!$valid) {
        sendmessage($from_id, $normalized, null, 'HTML');
        return true;
    }
    $session['answers'][(string) $field['id']] = ['label' => $field['label'], 'value' => $normalized];
    $session['field_index'] = ++$index;
    update('user', 'Processing_value', json_encode($session, JSON_UNESCAPED_UNICODE), 'id', $from_id);
    $user['Processing_value'] = json_encode($session, JSON_UNESCAPED_UNICODE);
    if (isset($fields[$index])) {
        telegramProductsAskFormField($productId, $index, $fields);
        return true;
    }
    step('home', $from_id);
    update('user', 'Processing_value', '0', 'id', $from_id);
    telegramProductsCreateDraft($productId, json_encode(array_values($session['answers']), JSON_UNESCAPED_UNICODE));
    return true;
}

function telegramProductsDiscountResult($code, array $product, $userId, $lock = false)
{
    global $pdo;
    $sql = 'SELECT * FROM telegram_product_discounts WHERE code = ? AND is_active = 1' . ($lock ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([mb_strtoupper(trim((string) $code), 'UTF-8')]);
    $discount = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$discount) return [false, 'کد تخفیف معتبر نیست.'];
    $now = time();
    if (!empty($discount['starts_at']) && strtotime($discount['starts_at']) > $now) return [false, 'زمان استفاده از این کد هنوز شروع نشده است.'];
    if (!empty($discount['expires_at']) && strtotime($discount['expires_at']) < $now) return [false, 'این کد تخفیف منقضی شده است.'];
    if ((int) $discount['usage_limit'] > 0 && (int) $discount['used_count'] >= (int) $discount['usage_limit']) return [false, 'ظرفیت استفاده از این کد تکمیل شده است.'];
    if ((int) $discount['product_id'] > 0 && (int) $discount['product_id'] !== (int) $product['id']) return [false, 'این کد برای محصول انتخابی قابل استفاده نیست.'];
    if ((int) $discount['category_id'] > 0 && (int) $discount['category_id'] !== (int) $product['category_id']) return [false, 'این کد برای این دسته قابل استفاده نیست.'];
    if ((int) $product['price'] < (int) $discount['min_purchase']) return [false, 'مبلغ سفارش از حداقل خرید این کد کمتر است.'];
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM telegram_product_discount_redemptions WHERE discount_id = ? AND user_id = ?');
    $stmt->execute([$discount['id'], $userId]);
    if ((int) $discount['per_user_limit'] > 0 && (int) $stmt->fetchColumn() >= (int) $discount['per_user_limit']) return [false, 'سقف استفاده شما از این کد تکمیل شده است.'];
    $amount = $discount['discount_type'] === 'percent'
        ? (int) floor((int) $product['price'] * (int) $discount['discount_value'] / 100)
        : (int) $discount['discount_value'];
    if ((int) $discount['max_discount'] > 0) $amount = min($amount, (int) $discount['max_discount']);
    $discount['calculated_amount'] = min($amount, (int) $product['price']);
    return [true, $discount];
}

function telegramProductsLoyalty($userId, $lock = false)
{
    global $pdo;
    $pdo->prepare('INSERT IGNORE INTO telegram_product_loyalty (user_id) VALUES (?)')->execute([(string) $userId]);
    $stmt = $pdo->prepare('SELECT * FROM telegram_product_loyalty WHERE user_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([(string) $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['points' => 0, 'lifetime_earned' => 0, 'lifetime_spent' => 0];
}

function telegramProductsPreparePayment(array $order, array $product)
{
    global $pdo;

    $loyalty = telegramProductsLoyalty($order['user_id'], true);
    $original = (int) $product['price'];
    $discount = null;
    $discountAmount = 0;
    if (!empty($order['discount_code'])) {
        [$valid, $result] = telegramProductsDiscountResult(
            $order['discount_code'],
            ['id' => $product['id'], 'category_id' => $product['category_id'], 'price' => $original],
            $order['user_id'],
            true
        );
        if (!$valid) {
            return [false, $result];
        }
        $discount = $result;
        $discountAmount = (int) $result['calculated_amount'];
    }

    $afterDiscount = max(0, $original - $discountAmount);
    $pointValue = max(1, (int) telegramProductsSetting('loyalty_point_value', '1000'));
    $maxPercent = min(100, max(0, (int) telegramProductsSetting('loyalty_max_percent', '20')));
    $maxPointDiscount = (int) floor($afterDiscount * $maxPercent / 100);
    $pointsUsed = min((int) $order['points_used'], (int) $loyalty['points'], (int) floor($maxPointDiscount / $pointValue));
    $payable = max(0, $afterDiscount - ($pointsUsed * $pointValue));
    $spendPerPoint = max(1, (int) telegramProductsSetting('loyalty_spend_per_point', '10000'));
    $pointsEarned = telegramProductsSetting('loyalty_enabled', '1') === '1' ? (int) floor($payable / $spendPerPoint) : 0;

    $stmt = $pdo->prepare('UPDATE telegram_product_orders SET original_price = ?, price = ?, discount_amount = ?, points_used = ?, points_earned = ? WHERE id = ?');
    $stmt->execute([$original, $payable, $discountAmount, $pointsUsed, $pointsEarned, $order['id']]);
    $order['original_price'] = $original;
    $order['price'] = $payable;
    $order['discount_amount'] = $discountAmount;
    $order['points_used'] = $pointsUsed;
    $order['points_earned'] = $pointsEarned;

    return [true, ['order' => $order, 'loyalty' => $loyalty, 'discount' => $discount]];
}

function telegramProductsFinalizePayment(array $order, array $product, array $financial, $deliveryPayload = null)
{
    global $pdo;

    if (!empty($financial['discount'])) {
        $discount = $financial['discount'];
        $stmt = $pdo->prepare('INSERT INTO telegram_product_discount_redemptions (discount_id, order_id, user_id, amount) VALUES (?, ?, ?, ?)');
        $stmt->execute([$discount['id'], $order['id'], $order['user_id'], $order['discount_amount']]);
        $pdo->prepare('UPDATE telegram_product_discounts SET used_count = used_count + 1 WHERE id = ?')->execute([$discount['id']]);
    }

    $points = max(0, (int) $financial['loyalty']['points'] - (int) $order['points_used'] + (int) $order['points_earned']);
    $stmt = $pdo->prepare('UPDATE telegram_product_loyalty SET points = ?, lifetime_earned = lifetime_earned + ?, lifetime_spent = lifetime_spent + ?, updated_at = NOW() WHERE user_id = ?');
    $stmt->execute([$points, (int) $order['points_earned'], (int) $order['points_used'], $order['user_id']]);

    $warrantyUntil = (int) ($product['warranty_days'] ?? 0) > 0
        ? date('Y-m-d H:i:s', time() + ((int) $product['warranty_days'] * 86400))
        : null;
    $pdo->prepare('UPDATE telegram_product_orders SET points_earned = ?, warranty_until = ? WHERE id = ?')
        ->execute([(int) $order['points_earned'], $warrantyUntil, $order['id']]);

    if ($deliveryPayload !== null && $deliveryPayload !== '') {
        $pdo->prepare("INSERT INTO telegram_product_deliveries (order_id, delivery_type, payload) VALUES (?, 'initial', ?)")
            ->execute([$order['id'], $deliveryPayload]);
    }
}

function telegramProductsReverseOrderBenefits(array $order)
{
    global $pdo;

    $loyalty = telegramProductsLoyalty($order['user_id'], true);
    $restored = max(0, (int) $loyalty['points'] - (int) $order['points_earned'] + (int) $order['points_used']);
    $stmt = $pdo->prepare('UPDATE telegram_product_loyalty SET points=?, lifetime_earned=GREATEST(0,lifetime_earned-?), lifetime_spent=GREATEST(0,lifetime_spent-?), updated_at=NOW() WHERE user_id=?');
    $stmt->execute([$restored, (int) $order['points_earned'], (int) $order['points_used'], $order['user_id']]);

    $stmt = $pdo->prepare('SELECT discount_id FROM telegram_product_discount_redemptions WHERE order_id=? FOR UPDATE');
    $stmt->execute([$order['id']]);
    $discountId = $stmt->fetchColumn();
    if ($discountId !== false) {
        $pdo->prepare('DELETE FROM telegram_product_discount_redemptions WHERE order_id=?')->execute([$order['id']]);
        $pdo->prepare('UPDATE telegram_product_discounts SET used_count=GREATEST(0,used_count-1) WHERE id=?')->execute([$discountId]);
    }
}

function telegramProductsFormatCustomerInput($value)
{
    $decoded = json_decode((string) $value, true);
    if (!is_array($decoded)) {
        return telegramProductsEscape($value);
    }
    $lines = [];
    foreach ($decoded as $answer) {
        if (!is_array($answer)) continue;
        $lines[] = '<b>' . telegramProductsSafeCustomText($answer['label'] ?? 'فیلد') . ':</b> <code>' . telegramProductsEscape($answer['value'] ?? '') . '</code>';
    }
    return implode("\n", $lines);
}

function telegramProductsCheckout($orderId)
{
    global $pdo, $from_id;
    $stmt = $pdo->prepare('SELECT o.*, p.category_id FROM telegram_product_orders o JOIN telegram_products p ON p.id = o.product_id WHERE o.id = ? AND o.user_id = ? AND o.status = \'pending\'');
    $stmt->execute([(int) $orderId, $from_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        telegramProductsReply('سفارش پرداخت‌نشده پیدا نشد.', null);
        return;
    }
    $walletStmt = $pdo->prepare('SELECT Balance, agent, maxbuyagent FROM user WHERE id = ?');
    $walletStmt->execute([$from_id]);
    $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC) ?: ['Balance' => 0, 'agent' => 'f', 'maxbuyagent' => 0];
    $balance = (int) $wallet['Balance'];
    $creditLimit = $wallet['agent'] === 'n2' ? (int) $wallet['maxbuyagent'] : 0;
    $text = "<b>فاکتور خدمات مجازی</b>\n\n";
    $text .= telegramProductsSafeCustomText(telegramProductsSetting('checkout_text', 'لطفاً اطلاعات سفارش را بررسی کنید.')) . "\n\n";
    $text .= '<b>محصول:</b> ' . telegramProductsEscape($order['product_title']) . "\n";
    $text .= '<b>مبلغ اولیه:</b> ' . telegramProductsMoney($order['original_price'] ?: $order['price']) . "\n";
    if ((int) $order['discount_amount'] > 0) $text .= '<b>تخفیف:</b> -' . telegramProductsMoney($order['discount_amount']) . "\n";
    if ((int) $order['points_used'] > 0) $text .= '<b>امتیاز مصرفی:</b> ' . (int) $order['points_used'] . "\n";
    $text .= '<b>مبلغ قابل پرداخت:</b> ' . telegramProductsMoney($order['price']) . "\n";
    $text .= '<b>موجودی کیف پول:</b> ' . telegramProductsMoney($balance);
    if (!empty($order['customer_input'])) $text .= "\n\n<b>اطلاعات سفارش:</b>\n" . telegramProductsFormatCustomerInput($order['customer_input']);
    $rows = [
        [['text' => empty($order['discount_code']) ? 'ثبت کد تخفیف' : 'تغییر کد تخفیف', 'callback_data' => 'tgp_discount_' . $order['id'], 'style' => 'primary']],
    ];
    if (!empty($order['discount_code'])) $rows[] = [['text' => 'حذف کد تخفیف', 'callback_data' => 'tgp_discountclear_' . $order['id']]];
    if (telegramProductsSetting('loyalty_enabled', '1') === '1') {
        $loyalty = telegramProductsLoyalty($from_id);
        $rows[] = [['text' => (int) $order['points_used'] > 0 ? 'لغو مصرف امتیاز' : 'استفاده از امتیاز (' . (int) $loyalty['points'] . ')', 'callback_data' => 'tgp_points_' . $order['id']]];
    }
    $canPay = $balance >= (int) $order['price'] || ($creditLimit > 0 && ($balance - (int) $order['price']) >= -$creditLimit);
    if ($canPay) $rows[] = [['text' => 'پرداخت نهایی', 'callback_data' => 'tgp_pay_' . $order['id'], 'style' => 'success']];
    else $rows[] = [['text' => 'افزایش موجودی', 'callback_data' => 'account']];
    $rows[] = [['text' => 'انصراف', 'callback_data' => 'tgp_view_' . $order['product_id'], 'style' => 'danger']];
    telegramProductsReply($text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE));
}

function telegramProductsRepriceOrder($orderId, $discountCode = null, $togglePoints = false)
{
    global $pdo, $from_id;
    $stmt = $pdo->prepare('SELECT o.*, p.category_id, p.price AS current_price, p.id AS current_product_id FROM telegram_product_orders o JOIN telegram_products p ON p.id = o.product_id WHERE o.id = ? AND o.user_id = ? AND o.status = \'pending\'');
    $stmt->execute([(int) $orderId, $from_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) return [false, 'سفارش معتبر نیست.'];
    $original = (int) $order['current_price'];
    $discountAmount = (int) $order['discount_amount'];
    $code = $order['discount_code'];
    if ($discountCode !== null) {
        [$valid, $result] = telegramProductsDiscountResult($discountCode, ['id' => $order['product_id'], 'category_id' => $order['category_id'], 'price' => $original], $from_id);
        if (!$valid) return [false, $result];
        $discountAmount = (int) $result['calculated_amount'];
        $code = $result['code'];
    }
    $points = (int) $order['points_used'];
    if ($togglePoints) {
        $points = $points > 0 ? 0 : (int) telegramProductsLoyalty($from_id)['points'];
    }
    $afterDiscount = max(0, $original - $discountAmount);
    $pointValue = max(1, (int) telegramProductsSetting('loyalty_point_value', '1000'));
    $maxPercent = min(100, max(0, (int) telegramProductsSetting('loyalty_max_percent', '20')));
    $maxPointDiscount = (int) floor($afterDiscount * $maxPercent / 100);
    $points = min($points, (int) floor($maxPointDiscount / $pointValue));
    $payable = max(0, $afterDiscount - ($points * $pointValue));
    $stmt = $pdo->prepare('UPDATE telegram_product_orders SET original_price = ?, price = ?, discount_amount = ?, discount_code = ?, points_used = ? WHERE id = ?');
    $stmt->execute([$original, $payable, $discountAmount, $code, $points, $order['id']]);
    return [true, $order['id']];
}

function telegramProductsRequestWarranty($orderId, $reason)
{
    global $pdo, $from_id;
    $stmt = $pdo->prepare("SELECT * FROM telegram_product_orders WHERE id = ? AND user_id = ? AND status = 'delivered' AND warranty_until IS NOT NULL AND warranty_until >= NOW()");
    $stmt->execute([(int) $orderId, $from_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) return [false, 'مهلت گارانتی این سفارش به پایان رسیده است.'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM telegram_product_warranties WHERE order_id = ? AND status = 'pending'");
    $stmt->execute([$orderId]);
    if ((int) $stmt->fetchColumn() > 0) return [false, 'یک درخواست گارانتی باز برای این سفارش دارید.'];
    $pdo->prepare('INSERT INTO telegram_product_warranties (order_id, user_id, reason) VALUES (?, ?, ?)')->execute([$orderId, $from_id, trim($reason)]);
    telegramProductsReport('alert', "<b>درخواست گارانتی جدید</b>\n\nسفارش: <code>#{$orderId}</code>\nکاربر: <code>" . telegramProductsEscape($from_id) . '</code>');
    return [true, 'درخواست گارانتی ثبت شد و در صف بررسی قرار گرفت.'];
}

function telegramProductsProfessionalAlerts()
{
    global $pdo;
    $hours = max(1, (int) telegramProductsSetting('pending_alert_hours', '3'));
    $stmt = $pdo->prepare("SELECT id, user_id, product_title, created_at FROM telegram_product_orders WHERE status = 'paid_pending' AND pending_alerted_at IS NULL AND paid_at <= DATE_SUB(NOW(), INTERVAL ? HOUR) LIMIT 20");
    $stmt->execute([$hours]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
        telegramProductsReport('alert', "<b>سفارش معطل خدمات مجازی</b>\n\nسفارش: <code>#{$order['id']}</code>\nکاربر: <code>" . telegramProductsEscape($order['user_id']) . "</code>\nمحصول: " . telegramProductsEscape($order['product_title']));
        $pdo->prepare('UPDATE telegram_product_orders SET pending_alerted_at = NOW() WHERE id = ?')->execute([$order['id']]);
    }
    $today = date('Y-m-d');
    if (telegramProductsSetting('daily_summary_enabled', '1') === '1' && telegramProductsSetting('last_daily_summary', '') !== $today && (int) date('G') >= 20) {
        $stats = $pdo->query("SELECT COUNT(*) orders_count, COALESCE(SUM(price),0) revenue FROM telegram_product_orders WHERE status IN ('paid_pending','delivered') AND DATE(paid_at)=CURDATE()")->fetch(PDO::FETCH_ASSOC) ?: ['orders_count' => 0, 'revenue' => 0];
        telegramProductsReport('alert', "<b>خلاصه روزانه خدمات مجازی</b>\n\nتعداد فروش: <code>" . (int) $stats['orders_count'] . "</code>\nفروش: " . telegramProductsMoney($stats['revenue']));
        telegramProductsSetSetting('last_daily_summary', $today);
    }
}

function telegramProductsMaybeRunAlerts()
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $lastScan = (int) telegramProductsSetting('last_alert_scan', '0');
    if (time() - $lastScan < 300) return;
    telegramProductsSetSetting('last_alert_scan', (string) time());
    telegramProductsProfessionalAlerts();
}

function telegramProductsFeatureUserHandle()
{
    global $pdo, $from_id, $text, $datain, $user;
    $step = (string) ($user['step'] ?? '');
    if ($step === 'tgp_form_input' && $datain === '') return telegramProductsSaveFormAnswer($text);
    if (preg_match('/^tgp_formopt_(\d+)_(\d+)_(\d+)$/', $datain, $m)) {
        $fields = telegramProductsFields($m[1]);
        foreach ($fields as $field) if ((int) $field['id'] === (int) $m[2]) {
            $options = json_decode((string) $field['options_json'], true) ?: [];
            return telegramProductsSaveFormAnswer($options[(int) $m[3]] ?? '', $field['id']);
        }
        return true;
    }
    if (preg_match('/^tgp_formskip_(\d+)_(\d+)$/', $datain, $m)) return telegramProductsSaveFormAnswer('', $m[2]);
    if (preg_match('/^tgp_discount_(\d+)$/', $datain, $m)) {
        step('tgp_discount_input_' . $m[1], $from_id);
        update('user', 'Processing_value', (string) $m[1], 'id', $from_id);
        telegramProductsReply('کد تخفیف را ارسال کنید.', json_encode(['inline_keyboard' => [[['text' => 'انصراف', 'callback_data' => 'tgp_checkout_' . $m[1]]]]], JSON_UNESCAPED_UNICODE));
        return true;
    }
    if (preg_match('/^tgp_discountclear_(\d+)$/', $datain, $m)) {
        $stmt = $pdo->prepare("UPDATE telegram_product_orders SET discount_code=NULL,discount_amount=0,price=original_price,points_used=0 WHERE id=? AND user_id=? AND status='pending'");
        $stmt->execute([(int) $m[1], $from_id]);
        telegramProductsCheckout($m[1]);
        return true;
    }
    if (preg_match('/^tgp_discount_input_(\d+)$/', $step, $m) && $datain === '') {
        [$ok, $result] = telegramProductsRepriceOrder($m[1], $text, false);
        step('home', $from_id);
        if (!$ok) sendmessage($from_id, telegramProductsEscape($result), null, 'HTML');
        telegramProductsCheckout($m[1]);
        return true;
    }
    if (preg_match('/^tgp_points_(\d+)$/', $datain, $m)) {
        telegramProductsRepriceOrder($m[1], null, true);
        telegramProductsCheckout($m[1]);
        return true;
    }
    if (preg_match('/^tgp_checkout_(\d+)$/', $datain, $m)) { telegramProductsCheckout($m[1]); return true; }
    if (preg_match('/^tgp_selfresend_(\d+)$/', $datain, $m)) {
        $stmt = $pdo->prepare("SELECT o.*, p.max_resends FROM telegram_product_orders o JOIN telegram_products p ON p.id=o.product_id WHERE o.id=? AND o.user_id=? AND o.status='delivered'");
        $stmt->execute([(int) $m[1], $from_id]); $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || empty($order['delivery_payload']) || (int) $order['resend_count'] >= (int) $order['max_resends']) { telegramProductsReply('امکان ارسال مجدد برای این سفارش وجود ندارد.', null); return true; }
        $stmt = $pdo->prepare('UPDATE telegram_product_orders SET resend_count=resend_count+1 WHERE id=? AND resend_count < ?');
        $stmt->execute([$order['id'], $order['max_resends']]);
        if ($stmt->rowCount() !== 1) { telegramProductsReply('سقف ارسال مجدد این سفارش تکمیل شده است.', null); return true; }
        $pdo->prepare("INSERT INTO telegram_product_deliveries (order_id,delivery_type,payload) VALUES (?, 'self_resend', ?)")->execute([$order['id'], $order['delivery_payload']]);
        sendmessage($from_id, "<b>ارسال مجدد سفارش #{$order['id']}</b>\n\n<code>" . telegramProductsEscape($order['delivery_payload']) . '</code>', null, 'HTML');
        return true;
    }
    if (preg_match('/^tgp_warranty_(\d+)$/', $datain, $m)) {
        step('tgp_warranty_input_' . $m[1], $from_id);
        telegramProductsReply('مشکل سفارش را کامل توضیح دهید.', json_encode(['inline_keyboard' => [[['text' => 'انصراف', 'callback_data' => 'tgp_order_' . $m[1]]]]], JSON_UNESCAPED_UNICODE));
        return true;
    }
    if (preg_match('/^tgp_warranty_input_(\d+)$/', $step, $m) && $datain === '') {
        $reason = trim((string) $text);
        if (mb_strlen($reason, 'UTF-8') < 10 || mb_strlen($reason, 'UTF-8') > 1000) { sendmessage($from_id, 'شرح مشکل باید بین ۱۰ تا ۱۰۰۰ کاراکتر باشد.', null, 'HTML'); return true; }
        [$ok, $message] = telegramProductsRequestWarranty($m[1], $reason); step('home', $from_id); sendmessage($from_id, telegramProductsEscape($message), null, 'HTML'); return true;
    }
    return false;
}

function telegramProductsAdminPermissionForRequest($callback, $state = '', $incomingText = '')
{
    $value = $callback !== '' ? $callback : $state;
    if ($incomingText === 'مدیریت خدمات مجازی' || in_array($callback, ['vsa_home', 'vsa_exit'], true)) return null;
    if (strpos($value, 'vsa_fx_role') === 0) return 'roles';
    if (strpos($value, 'vsa_fx_discount') === 0 || strpos($value, 'vsa_fx_d') === 0) return 'discounts';
    if (strpos($value, 'vsa_fx_w') === 0) return 'warranty';
    if (strpos($value, 'vsa_fx_loyalty') === 0 || strpos($value, 'vsa_fx_alert') === 0) return 'settings';
    if (strpos($value, 'vsa_fx_field') === 0 || strpos($value, 'vsa_fx_ftype') === 0 || strpos($value, 'vsa_fx_product_') === 0 || strpos($value, 'vsa_fx_mode_') === 0) return 'catalog';
    if (preg_match('/^vsa_(categor|cat_|product|pe_|ptoggle|pstyle|pemoji|plowstock|pmax|pdelivery|pscope|pset|pcat|stock|begin|add)/', $value)) return 'catalog';
    if (preg_match('/^vsa_(orders|recent|order_|deliver_|resend_)/', $value)) return 'orders';
    if (preg_match('/^vsa_(stats|refund_)/', $value)) return 'finance';
    if (preg_match('/^vsa_(settings|toggle|topics|text_)/', $value)) return 'settings';
    return null;
}

function telegramProductsAdminFields($productId)
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT id, title, product_mode FROM telegram_products WHERE id = ?');
    $stmt->execute([(int) $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) { virtualServicesAdminProducts(); return; }
    $stmt = $pdo->prepare('SELECT * FROM telegram_product_fields WHERE product_id = ? ORDER BY sort_order, id');
    $stmt->execute([$productId]);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $types = ['text' => 'متن', 'username' => 'یوزرنیم', 'email' => 'ایمیل', 'phone' => 'موبایل', 'number' => 'عدد', 'select' => 'انتخابی'];
    $text = '<b>فرم و قوانین ورودی</b>\n\nمحصول: ' . telegramProductsEscape($product['title']);
    $rows = [];
    foreach ($fields as $field) {
        $label = ((int) $field['is_active'] ? 'فعال' : 'خاموش') . ' | ' . ($types[$field['field_type']] ?? $field['field_type']) . ' | ' . telegramProductsPlainText($field['label']);
        $rows[] = [['text' => $label, 'callback_data' => 'vsa_fx_field_' . $field['id']]];
    }
    if (!$fields) $text .= "\n\nبرای محصول فرم‌دار حداقل یک فیلد بسازید.";
    $rows[] = [['text' => 'افزودن فیلد', 'callback_data' => 'vsa_fx_field_add_' . $productId, 'style' => 'success']];
    $rows[] = [['text' => 'بازگشت به محصول', 'callback_data' => 'vsa_product_' . $productId]];
    virtualServicesAdminReply($text, $rows);
}

function telegramProductsAdminField($fieldId)
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM telegram_product_fields WHERE id = ?');
    $stmt->execute([(int) $fieldId]);
    $field = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$field) { virtualServicesAdminProducts(); return; }
    $text = '<b>' . telegramProductsSafeCustomText($field['label']) . "</b>\n\n";
    $text .= 'نوع: <code>' . telegramProductsEscape($field['field_type']) . "</code>\n";
    $text .= 'الزامی: ' . ((int) $field['is_required'] ? 'بله' : 'خیر') . "\n";
    $text .= 'طول مجاز: ' . (int) $field['min_length'] . ' تا ' . (int) $field['max_length'] . "\n";
    $text .= 'وضعیت: ' . ((int) $field['is_active'] ? 'فعال' : 'غیرفعال');
    $rows = [
        [['text' => (int) $field['is_required'] ? 'اختیاری‌کردن' : 'الزامی‌کردن', 'callback_data' => 'vsa_fx_field_req_' . $field['id']]],
        [['text' => 'قوانین طول و الگو', 'callback_data' => 'vsa_fx_field_rules_' . $field['id']]],
        [['text' => (int) $field['is_active'] ? 'غیرفعال‌کردن' : 'فعال‌کردن', 'callback_data' => 'vsa_fx_field_toggle_' . $field['id']]],
        [['text' => 'حذف فیلد', 'callback_data' => 'vsa_fx_field_delete_' . $field['id'], 'style' => 'danger']],
        [['text' => 'بازگشت', 'callback_data' => 'vsa_fx_fields_' . $field['product_id']]],
    ];
    virtualServicesAdminReply($text, $rows);
}

function telegramProductsAdminDiscounts()
{
    global $pdo;
    $discounts = $pdo->query('SELECT * FROM telegram_product_discounts ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($discounts as $discount) {
        $value = $discount['discount_type'] === 'percent' ? $discount['discount_value'] . '%' : telegramProductsMoney($discount['discount_value']);
        $rows[] = [['text' => $discount['code'] . ' | ' . $value . ' | ' . ((int) $discount['is_active'] ? 'فعال' : 'خاموش'), 'callback_data' => 'vsa_fx_discount_' . $discount['id']]];
    }
    $rows[] = [['text' => 'ساخت کد تخفیف', 'callback_data' => 'vsa_fx_discount_add', 'style' => 'success']];
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_home']];
    virtualServicesAdminReply("<b>کدهای تخفیف</b>\n\nکدها با محدودیت کل، محدودیت هر کاربر، حداقل خرید، سقف تخفیف، تاریخ انقضا و دامنه محصول کنترل می‌شوند.", $rows);
}

function telegramProductsAdminDiscount($discountId)
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM telegram_product_discounts WHERE id = ?');
    $stmt->execute([(int) $discountId]);
    $d = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$d) { telegramProductsAdminDiscounts(); return; }
    $scope = (int) $d['product_id'] ? 'محصول #' . $d['product_id'] : ((int) $d['category_id'] ? 'دسته #' . $d['category_id'] : 'همه محصولات');
    $value = $d['discount_type'] === 'percent' ? $d['discount_value'] . ' درصد' : telegramProductsMoney($d['discount_value']);
    $text = '<b>کد ' . telegramProductsEscape($d['code']) . "</b>\n\n";
    $text .= "مقدار: {$value}\nدامنه: {$scope}\nحداقل خرید: " . telegramProductsMoney($d['min_purchase']) . "\n";
    $text .= 'سقف تخفیف: ' . ((int) $d['max_discount'] ? telegramProductsMoney($d['max_discount']) : 'نامحدود') . "\n";
    $text .= 'مصرف: ' . (int) $d['used_count'] . '/' . ((int) $d['usage_limit'] ?: 'نامحدود') . ' | هر کاربر: ' . ((int) $d['per_user_limit'] ?: 'نامحدود') . "\n";
    $text .= 'انقضا: ' . ($d['expires_at'] ?: 'بدون انقضا') . "\nوضعیت: " . ((int) $d['is_active'] ? 'فعال' : 'غیرفعال');
    $rows = [
        [['text' => 'محدودیت‌ها و تاریخ', 'callback_data' => 'vsa_fx_discount_limits_' . $d['id']]],
        [['text' => 'دامنه استفاده', 'callback_data' => 'vsa_fx_dscope_' . $d['id']]],
        [['text' => (int) $d['is_active'] ? 'غیرفعال‌سازی' : 'فعال‌سازی', 'callback_data' => 'vsa_fx_discount_toggle_' . $d['id']]],
        [['text' => 'حذف کد', 'callback_data' => 'vsa_fx_discount_delete_' . $d['id'], 'style' => 'danger']],
        [['text' => 'بازگشت', 'callback_data' => 'vsa_fx_discounts']],
    ];
    virtualServicesAdminReply($text, $rows);
}

function telegramProductsAdminWarranties()
{
    global $pdo;
    $items = $pdo->query("SELECT w.*, o.product_title FROM telegram_product_warranties w JOIN telegram_product_orders o ON o.id=w.order_id ORDER BY (w.status='pending') DESC, w.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($items as $w) $rows[] = [['text' => '#' . $w['id'] . ' | سفارش ' . $w['order_id'] . ' | ' . $w['status'], 'callback_data' => 'vsa_fx_warranty_' . $w['id']]];
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_home']];
    virtualServicesAdminReply("<b>درخواست‌های گارانتی</b>\n\nدرخواست‌های باز در ابتدای فهرست نمایش داده می‌شوند.", $rows);
}

function telegramProductsAdminWarranty($warrantyId)
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT w.*, o.product_title, o.customer_input FROM telegram_product_warranties w JOIN telegram_product_orders o ON o.id=w.order_id WHERE w.id=?');
    $stmt->execute([(int) $warrantyId]);
    $w = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$w) { telegramProductsAdminWarranties(); return; }
    $text = "<b>گارانتی #{$w['id']}</b>\n\nسفارش: <code>#{$w['order_id']}</code>\nکاربر: <code>" . telegramProductsEscape($w['user_id']) . "</code>\nمحصول: " . telegramProductsEscape($w['product_title']) . "\nوضعیت: " . telegramProductsEscape($w['status']) . "\n\n<b>شرح مشکل:</b>\n" . telegramProductsEscape($w['reason']);
    if (!empty($w['admin_note'])) $text .= "\n\n<b>پاسخ ادمین:</b> " . telegramProductsEscape($w['admin_note']);
    $rows = [];
    if ($w['status'] === 'pending') {
        $rows[] = [['text' => 'تأیید و جایگزینی', 'callback_data' => 'vsa_fx_wapprove_' . $w['id'], 'style' => 'success']];
        $rows[] = [['text' => 'رد درخواست', 'callback_data' => 'vsa_fx_wreject_' . $w['id'], 'style' => 'danger']];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_fx_warranties']];
    virtualServicesAdminReply($text, $rows);
}

function telegramProductsAdminLoyalty()
{
    global $pdo;
    $stats = $pdo->query('SELECT COUNT(*) users_count, COALESCE(SUM(points),0) points_count, COALESCE(SUM(lifetime_earned),0) earned_count FROM telegram_product_loyalty')->fetch(PDO::FETCH_ASSOC);
    $enabled = telegramProductsSetting('loyalty_enabled', '1') === '1';
    $text = "<b>باشگاه مشتریان</b>\n\nوضعیت: " . ($enabled ? 'فعال' : 'غیرفعال') . "\n";
    $text .= 'هر امتیاز پس از خرید: ' . telegramProductsMoney(telegramProductsSetting('loyalty_spend_per_point', '10000')) . "\n";
    $text .= 'ارزش هر امتیاز: ' . telegramProductsMoney(telegramProductsSetting('loyalty_point_value', '1000')) . "\n";
    $text .= 'حداکثر سهم امتیاز از فاکتور: ' . (int) telegramProductsSetting('loyalty_max_percent', '20') . "%\n";
    $text .= 'اعضا: ' . (int) $stats['users_count'] . ' | امتیاز در گردش: ' . (int) $stats['points_count'];
    $rows = [
        [['text' => $enabled ? 'غیرفعال‌سازی' : 'فعال‌سازی', 'callback_data' => 'vsa_fx_loyalty_toggle']],
        [['text' => 'تنظیم نرخ‌ها', 'callback_data' => 'vsa_fx_loyalty_rates']],
        [['text' => 'بازگشت', 'callback_data' => 'vsa_home']],
    ];
    virtualServicesAdminReply($text, $rows);
}

function telegramProductsAdminAlerts()
{
    $daily = telegramProductsSetting('daily_summary_enabled', '1') === '1';
    $text = "<b>اعلان‌های حرفه‌ای</b>\n\nهشدار سفارش معطل پس از: " . (int) telegramProductsSetting('pending_alert_hours', '3') . " ساعت\nگزارش روزانه: " . ($daily ? 'فعال' : 'غیرفعال') . "\nتاپیک: هشدارهای خدمات مجازی";
    $rows = [
        [['text' => 'تنظیم زمان هشدار', 'callback_data' => 'vsa_fx_alert_hours']],
        [['text' => $daily ? 'خاموش‌کردن خلاصه روزانه' : 'فعال‌کردن خلاصه روزانه', 'callback_data' => 'vsa_fx_alert_daily']],
        [['text' => 'بررسی و ارسال اعلان‌ها اکنون', 'callback_data' => 'vsa_fx_alert_run', 'style' => 'success']],
        [['text' => 'بازگشت', 'callback_data' => 'vsa_home']],
    ];
    virtualServicesAdminReply($text, $rows);
}

function telegramProductsAdminRoles()
{
    global $admin_ids;
    $labels = ['owner' => 'مالک', 'manager' => 'مدیر', 'operator' => 'اپراتور سفارش', 'catalog' => 'مدیر محصولات', 'support' => 'پشتیبان'];
    $rows = [];
    foreach ((array) $admin_ids as $adminId) {
        $role = telegramProductsAdminRole($adminId);
        $rows[] = [['text' => $adminId . ' | ' . ($labels[$role] ?? $role), 'callback_data' => 'vsa_fx_role_' . $adminId]];
    }
    $rows[] = [['text' => 'بازگشت', 'callback_data' => 'vsa_home']];
    virtualServicesAdminReply("<b>سطح دسترسی ادمین‌ها</b>\n\nمدیر: همه بخش‌ها جز تعیین دسترسی\nاپراتور: سفارش و گارانتی\nمدیر محصولات: دسته، محصول و موجودی\nپشتیبان: سفارش و گارانتی", $rows);
}

function telegramProductsResolveWarranty($warrantyId, $payload)
{
    global $pdo, $from_id;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT w.*, o.product_id, o.product_title, p.product_mode FROM telegram_product_warranties w JOIN telegram_product_orders o ON o.id=w.order_id JOIN telegram_products p ON p.id=o.product_id WHERE w.id=? AND w.status='pending' FOR UPDATE");
        $stmt->execute([(int) $warrantyId]);
        $w = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$w) { $pdo->rollBack(); return [false, 'درخواست قبلاً پردازش شده است.']; }
        if ($w['product_mode'] === 'stock') {
            $stmt = $pdo->prepare("SELECT * FROM telegram_product_stock WHERE product_id=? AND status='available' ORDER BY id LIMIT 1 FOR UPDATE");
            $stmt->execute([$w['product_id']]);
            $stock = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$stock) { $pdo->rollBack(); return [false, 'موجودی جایگزین برای این محصول وجود ندارد.']; }
            $payload = $stock['payload'];
            $pdo->prepare("UPDATE telegram_product_stock SET status='sold', sold_to=?, order_id=?, sold_at=NOW() WHERE id=?")->execute([$w['user_id'], $w['order_id'], $stock['id']]);
        }
        if (trim((string) $payload) === '') { $pdo->rollBack(); return [false, 'اطلاعات جایگزین نمی‌تواند خالی باشد.']; }
        $pdo->prepare("UPDATE telegram_product_warranties SET status='approved', admin_id=?, replacement_payload=?, resolved_at=NOW() WHERE id=?")->execute([$from_id, $payload, $w['id']]);
        $pdo->prepare('UPDATE telegram_product_orders SET delivery_payload=?, resend_count=resend_count+1 WHERE id=?')->execute([$payload, $w['order_id']]);
        $pdo->prepare("INSERT INTO telegram_product_deliveries (order_id, delivery_type, payload, admin_id) VALUES (?, 'warranty', ?, ?)")->execute([$w['order_id'], $payload, $from_id]);
        $pdo->commit();
        sendmessage($w['user_id'], "<b>درخواست گارانتی تأیید شد</b>\n\nسفارش: <code>#{$w['order_id']}</code>\nاطلاعات جایگزین:\n<code>" . telegramProductsEscape($payload) . '</code>', null, 'HTML');
        telegramProductsReport('alert', "<b>گارانتی تأیید شد</b>\n\nدرخواست: <code>#{$w['id']}</code>\nسفارش: <code>#{$w['order_id']}</code>");
        return [true, 'جایگزین با موفقیت تحویل شد.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function telegramProductsAdminFeatureState()
{
    global $pdo, $from_id, $text, $user;
    $state = (string) ($user['step'] ?? '');
    if (strpos($state, 'vsa_fx_') !== 0) return false;
    $value = trim((string) $text);
    $data = virtualServicesAdminStateData();
    if ($value === '') { sendmessage($from_id, 'مقدار نمی‌تواند خالی باشد.', null, 'HTML'); return true; }

    if ($state === 'vsa_fx_field_label') {
        if (mb_strlen($value, 'UTF-8') > 100) { sendmessage($from_id, 'عنوان فیلد حداکثر ۱۰۰ کاراکتر باشد.', null, 'HTML'); return true; }
        $data['label'] = virtualServicesAdminCustomText();
        virtualServicesAdminSetState('vsa_fx_field_type_wait', $data);
        virtualServicesAdminReply('نوع اعتبارسنجی این فیلد را انتخاب کنید.', [[
            ['text' => 'متن', 'callback_data' => 'vsa_fx_ftype_text'], ['text' => 'یوزرنیم', 'callback_data' => 'vsa_fx_ftype_username']
        ], [
            ['text' => 'ایمیل', 'callback_data' => 'vsa_fx_ftype_email'], ['text' => 'موبایل', 'callback_data' => 'vsa_fx_ftype_phone']
        ], [
            ['text' => 'عدد', 'callback_data' => 'vsa_fx_ftype_number'], ['text' => 'گزینه‌ای', 'callback_data' => 'vsa_fx_ftype_select']
        ]], false);
        return true;
    }
    if ($state === 'vsa_fx_field_options') {
        $options = array_values(array_filter(array_map('trim', explode(',', $value))));
        if (count($options) < 2 || count($options) > 20) { sendmessage($from_id, 'بین ۲ تا ۲۰ گزینه با ویرگول جدا کنید.', null, 'HTML'); return true; }
        $pdo->prepare("INSERT INTO telegram_product_fields (product_id,label,field_type,options_json) VALUES (?,?, 'select', ?)")->execute([$data['product_id'], $data['label'], json_encode($options, JSON_UNESCAPED_UNICODE)]);
        virtualServicesAdminClearState(); telegramProductsAdminFields($data['product_id']); return true;
    }
    if ($state === 'vsa_fx_field_rules') {
        $parts = array_map('trim', explode('|', $value, 3));
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || (int) $parts[1] < (int) $parts[0] || (int) $parts[1] > 3000) { sendmessage($from_id, 'فرمت صحیح: <code>حداقل|حداکثر|الگو یا -</code>', null, 'HTML'); return true; }
        $pattern = $parts[2] === '-' ? null : $parts[2];
        if ($pattern !== null && @preg_match($pattern, '') === false) { sendmessage($from_id, 'عبارت منظم معتبر نیست.', null, 'HTML'); return true; }
        $pdo->prepare('UPDATE telegram_product_fields SET min_length=?, max_length=?, validation_pattern=? WHERE id=?')->execute([(int) $parts[0], (int) $parts[1], $pattern, $data['id']]);
        virtualServicesAdminClearState(); telegramProductsAdminField($data['id']); return true;
    }
    if ($state === 'vsa_fx_discount_code') {
        $code = mb_strtoupper($value, 'UTF-8');
        if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $code)) { sendmessage($from_id, 'کد باید ۳ تا ۳۰ کاراکتر انگلیسی، عدد، خط تیره یا زیرخط باشد.', null, 'HTML'); return true; }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM telegram_product_discounts WHERE code=?'); $stmt->execute([$code]);
        if ((int) $stmt->fetchColumn()) { sendmessage($from_id, 'این کد قبلاً ساخته شده است.', null, 'HTML'); return true; }
        virtualServicesAdminSetState('vsa_fx_discount_type_wait', ['code' => $code]);
        virtualServicesAdminReply('نوع تخفیف را انتخاب کنید.', [[['text' => 'درصدی', 'callback_data' => 'vsa_fx_dtype_percent'], ['text' => 'مبلغ ثابت', 'callback_data' => 'vsa_fx_dtype_fixed']]], false); return true;
    }
    if ($state === 'vsa_fx_discount_value') {
        if (!ctype_digit($value) || (int) $value < 1 || (($data['type'] ?? '') === 'percent' && (int) $value > 100)) { sendmessage($from_id, 'مقدار تخفیف معتبر نیست.', null, 'HTML'); return true; }
        $pdo->prepare('INSERT INTO telegram_product_discounts (code,discount_type,discount_value) VALUES (?,?,?)')->execute([$data['code'], $data['type'], (int) $value]);
        $id = $pdo->lastInsertId(); virtualServicesAdminClearState(); telegramProductsAdminDiscount($id); return true;
    }
    if ($state === 'vsa_fx_discount_limits') {
        $parts = array_map('trim', explode('|', $value));
        if (count($parts) !== 5 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2]) || !ctype_digit($parts[3])) { sendmessage($from_id, 'فرمت صحیح: <code>حداقل خرید|سقف تخفیف|ظرفیت کل|هر کاربر|تاریخ یا -</code>', null, 'HTML'); return true; }
        $expires = $parts[4] === '-' ? null : $parts[4] . ' 23:59:59';
        if ($expires !== null && !preg_match('/^\d{4}-\d{2}-\d{2} 23:59:59$/', $expires)) { sendmessage($from_id, 'تاریخ را مانند <code>2026-12-30</code> وارد کنید.', null, 'HTML'); return true; }
        $pdo->prepare('UPDATE telegram_product_discounts SET min_purchase=?,max_discount=?,usage_limit=?,per_user_limit=?,expires_at=? WHERE id=?')->execute([(int) $parts[0], (int) $parts[1], (int) $parts[2], (int) $parts[3], $expires, $data['id']]);
        virtualServicesAdminClearState(); telegramProductsAdminDiscount($data['id']); return true;
    }
    if ($state === 'vsa_fx_product_number') {
        if (!ctype_digit($value) || (int) $value > 3650) { sendmessage($from_id, 'یک عدد صحیح بین صفر تا ۳۶۵۰ ارسال کنید.', null, 'HTML'); return true; }
        $field = $data['field'] === 'warranty_days' ? 'warranty_days' : 'max_resends';
        $pdo->prepare("UPDATE telegram_products SET {$field}=? WHERE id=?")->execute([(int) $value, $data['id']]);
        virtualServicesAdminClearState(); virtualServicesAdminProduct($data['id']); return true;
    }
    if ($state === 'vsa_fx_loyalty_rates') {
        $parts = array_map('trim', explode('|', $value));
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2]) || (int) $parts[0] < 1 || (int) $parts[1] < 1 || (int) $parts[2] > 100) { sendmessage($from_id, 'فرمت صحیح: <code>مبلغ کسب امتیاز|ارزش امتیاز|حداکثر درصد</code>', null, 'HTML'); return true; }
        telegramProductsSetSetting('loyalty_spend_per_point', $parts[0]); telegramProductsSetSetting('loyalty_point_value', $parts[1]); telegramProductsSetSetting('loyalty_max_percent', $parts[2]);
        virtualServicesAdminClearState(); telegramProductsAdminLoyalty(); return true;
    }
    if ($state === 'vsa_fx_alert_hours') {
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 168) { sendmessage($from_id, 'زمان باید بین ۱ تا ۱۶۸ ساعت باشد.', null, 'HTML'); return true; }
        telegramProductsSetSetting('pending_alert_hours', $value); virtualServicesAdminClearState(); telegramProductsAdminAlerts(); return true;
    }
    if ($state === 'vsa_fx_warranty_payload') {
        [$ok, $message] = telegramProductsResolveWarranty($data['id'], $value); virtualServicesAdminClearState(); sendmessage($from_id, telegramProductsEscape($message), null, 'HTML'); telegramProductsAdminWarranty($data['id']); return true;
    }
    if ($state === 'vsa_fx_warranty_reject') {
        $stmt = $pdo->prepare("SELECT * FROM telegram_product_warranties WHERE id=? AND status='pending'"); $stmt->execute([$data['id']]); $w = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($w) { $pdo->prepare("UPDATE telegram_product_warranties SET status='rejected',admin_id=?,admin_note=?,resolved_at=NOW() WHERE id=?")->execute([$from_id, $value, $w['id']]); sendmessage($w['user_id'], "درخواست گارانتی سفارش <code>#{$w['order_id']}</code> رد شد.\n\n<b>توضیح:</b> " . telegramProductsEscape($value), null, 'HTML'); }
        virtualServicesAdminClearState(); telegramProductsAdminWarranty($data['id']); return true;
    }
    return false;
}

function telegramProductsAdminFeatureHandleRequest()
{
    global $pdo, $from_id, $datain, $user, $admin_ids;
    if ($datain === '' && strpos((string) ($user['step'] ?? ''), 'vsa_fx_') === 0) return telegramProductsAdminFeatureState();
    if (strpos($datain, 'vsa_fx_') !== 0) return false;
    $isStateContinuation = preg_match('/^vsa_fx_(ftype|dtype)_/', $datain) === 1;
    if (!$isStateContinuation && strpos((string) ($user['step'] ?? ''), 'vsa_fx_') === 0) virtualServicesAdminClearState();

    if ($datain === 'vsa_fx_discounts') { telegramProductsAdminDiscounts(); return true; }
    if ($datain === 'vsa_fx_discount_add') { virtualServicesAdminSetState('vsa_fx_discount_code'); virtualServicesAdminReply('کد تخفیف جدید را با حروف انگلیسی ارسال کنید.', [[['text' => 'انصراف', 'callback_data' => 'vsa_fx_discounts']]]); return true; }
    if (preg_match('/^vsa_fx_dtype_(percent|fixed)$/', $datain, $m)) { $data=virtualServicesAdminStateData(); $data['type']=$m[1]; virtualServicesAdminSetState('vsa_fx_discount_value',$data); virtualServicesAdminReply($m[1]==='percent'?'درصد تخفیف را بین ۱ تا ۱۰۰ ارسال کنید.':'مبلغ تخفیف را به تومان ارسال کنید.', [[['text'=>'انصراف','callback_data'=>'vsa_fx_discounts']]]); return true; }
    if (preg_match('/^vsa_fx_discount_(\d+)$/', $datain, $m)) { telegramProductsAdminDiscount($m[1]); return true; }
    if (preg_match('/^vsa_fx_discount_toggle_(\d+)$/', $datain, $m)) { $pdo->prepare('UPDATE telegram_product_discounts SET is_active=1-is_active WHERE id=?')->execute([$m[1]]); telegramProductsAdminDiscount($m[1]); return true; }
    if (preg_match('/^vsa_fx_discount_delete_(\d+)$/', $datain, $m)) { $stmt=$pdo->prepare('SELECT used_count FROM telegram_product_discounts WHERE id=?');$stmt->execute([$m[1]]); if((int)$stmt->fetchColumn()>0)$pdo->prepare('UPDATE telegram_product_discounts SET is_active=0 WHERE id=?')->execute([$m[1]]);else $pdo->prepare('DELETE FROM telegram_product_discounts WHERE id=?')->execute([$m[1]]); telegramProductsAdminDiscounts(); return true; }
    if (preg_match('/^vsa_fx_discount_limits_(\d+)$/', $datain, $m)) { virtualServicesAdminSetState('vsa_fx_discount_limits',['id'=>(int)$m[1]]); virtualServicesAdminReply("مقادیر را به این شکل بفرستید:\n<code>حداقل خرید|سقف تخفیف|ظرفیت کل|سقف هر کاربر|YYYY-MM-DD یا -</code>\n\nعدد صفر یعنی نامحدود.", [[['text'=>'انصراف','callback_data'=>'vsa_fx_discount_'.$m[1]]]]); return true; }
    if (preg_match('/^vsa_fx_dscope_(\d+)$/', $datain, $m)) { virtualServicesAdminReply('دامنه کد را انتخاب کنید.', [[['text'=>'همه محصولات','callback_data'=>'vsa_fx_dglobal_'.$m[1]]],[['text'=>'یک دسته','callback_data'=>'vsa_fx_dcats_'.$m[1]],['text'=>'یک محصول','callback_data'=>'vsa_fx_dproducts_'.$m[1]]],[['text'=>'بازگشت','callback_data'=>'vsa_fx_discount_'.$m[1]]]]); return true; }
    if (preg_match('/^vsa_fx_dglobal_(\d+)$/', $datain, $m)) { $pdo->prepare('UPDATE telegram_product_discounts SET product_id=NULL,category_id=NULL WHERE id=?')->execute([$m[1]]); telegramProductsAdminDiscount($m[1]); return true; }
    if (preg_match('/^vsa_fx_dcats_(\d+)$/', $datain, $m)) { $rows=[]; foreach($pdo->query('SELECT id,title FROM telegram_product_categories ORDER BY sort_order,id LIMIT 50')->fetchAll(PDO::FETCH_ASSOC) as $x)$rows[]=[['text'=>$x['title'],'callback_data'=>'vsa_fx_dcat_'.$m[1].'_'.$x['id']]]; $rows[]=[['text'=>'بازگشت','callback_data'=>'vsa_fx_discount_'.$m[1]]]; virtualServicesAdminReply('دسته را انتخاب کنید.',$rows); return true; }
    if (preg_match('/^vsa_fx_dproducts_(\d+)$/', $datain, $m)) { $rows=[]; foreach($pdo->query('SELECT id,title FROM telegram_products ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC) as $x)$rows[]=[['text'=>$x['title'],'callback_data'=>'vsa_fx_dproduct_'.$m[1].'_'.$x['id']]]; $rows[]=[['text'=>'بازگشت','callback_data'=>'vsa_fx_discount_'.$m[1]]]; virtualServicesAdminReply('محصول را انتخاب کنید.',$rows); return true; }
    if (preg_match('/^vsa_fx_dcat_(\d+)_(\d+)$/', $datain, $m)) { $pdo->prepare('UPDATE telegram_product_discounts SET product_id=NULL,category_id=? WHERE id=?')->execute([$m[2],$m[1]]); telegramProductsAdminDiscount($m[1]); return true; }
    if (preg_match('/^vsa_fx_dproduct_(\d+)_(\d+)$/', $datain, $m)) { $pdo->prepare('UPDATE telegram_product_discounts SET product_id=?,category_id=NULL WHERE id=?')->execute([$m[2],$m[1]]); telegramProductsAdminDiscount($m[1]); return true; }

    if (preg_match('/^vsa_fx_fields_(\d+)$/', $datain, $m)) { telegramProductsAdminFields($m[1]); return true; }
    if (preg_match('/^vsa_fx_field_add_(\d+)$/', $datain, $m)) { virtualServicesAdminSetState('vsa_fx_field_label',['product_id'=>(int)$m[1]]); virtualServicesAdminReply('عنوان فیلد را ارسال کنید؛ مانند «یوزرنیم تلگرام».',[[['text'=>'انصراف','callback_data'=>'vsa_fx_fields_'.$m[1]]]]); return true; }
    if (preg_match('/^vsa_fx_ftype_(text|username|email|phone|number|select)$/', $datain, $m)) { $data=virtualServicesAdminStateData(); if($m[1]==='select'){virtualServicesAdminSetState('vsa_fx_field_options',$data);virtualServicesAdminReply('گزینه‌ها را با ویرگول جدا کنید؛ مانند <code>یک ماهه,سه ماهه,یک ساله</code>.',[],false);}else{$pdo->prepare('INSERT INTO telegram_product_fields (product_id,label,field_type) VALUES (?,?,?)')->execute([$data['product_id'],$data['label'],$m[1]]);virtualServicesAdminClearState();telegramProductsAdminFields($data['product_id']);} return true; }
    if (preg_match('/^vsa_fx_field_(\d+)$/', $datain, $m)) { telegramProductsAdminField($m[1]); return true; }
    if (preg_match('/^vsa_fx_field_req_(\d+)$/', $datain, $m)) { $pdo->prepare('UPDATE telegram_product_fields SET is_required=1-is_required WHERE id=?')->execute([$m[1]]);telegramProductsAdminField($m[1]);return true; }
    if (preg_match('/^vsa_fx_field_toggle_(\d+)$/', $datain, $m)) { $pdo->prepare('UPDATE telegram_product_fields SET is_active=1-is_active WHERE id=?')->execute([$m[1]]);telegramProductsAdminField($m[1]);return true; }
    if (preg_match('/^vsa_fx_field_rules_(\d+)$/', $datain, $m)) { virtualServicesAdminSetState('vsa_fx_field_rules',['id'=>(int)$m[1]]);virtualServicesAdminReply("قانون را بفرستید: <code>حداقل طول|حداکثر طول|Regex یا -</code>\nنمونه: <code>5|32|-</code>",[[['text'=>'انصراف','callback_data'=>'vsa_fx_field_'.$m[1]]]]);return true; }
    if (preg_match('/^vsa_fx_field_delete_(\d+)$/', $datain, $m)) { $stmt=$pdo->prepare('SELECT product_id FROM telegram_product_fields WHERE id=?');$stmt->execute([$m[1]]);$pid=(int)$stmt->fetchColumn();$pdo->prepare('DELETE FROM telegram_product_fields WHERE id=?')->execute([$m[1]]);telegramProductsAdminFields($pid);return true; }
    if (preg_match('/^vsa_fx_mode_(\d+)$/', $datain, $m)) { $stmt=$pdo->prepare('SELECT product_mode,input_label FROM telegram_products WHERE id=?');$stmt->execute([$m[1]]);$product=$stmt->fetch(PDO::FETCH_ASSOC);$new=($product['product_mode']??'form')==='stock'?'form':'stock';$delivery=$new==='stock'?'auto':'manual';$pdo->prepare('UPDATE telegram_products SET product_mode=?,delivery_type=? WHERE id=?')->execute([$new,$delivery,$m[1]]);if($new==='form'){ $stmt=$pdo->prepare('SELECT COUNT(*) FROM telegram_product_fields WHERE product_id=?');$stmt->execute([$m[1]]);if((int)$stmt->fetchColumn()===0)$pdo->prepare("INSERT INTO telegram_product_fields (product_id,label,field_type) VALUES (?,?,'text')")->execute([$m[1],$product['input_label']?:'اطلاعات سفارش']);}virtualServicesAdminProduct($m[1]);return true; }
    if (preg_match('/^vsa_fx_product_(warranty|resends)_(\d+)$/', $datain, $m)) { virtualServicesAdminSetState('vsa_fx_product_number',['id'=>(int)$m[2],'field'=>$m[1]==='warranty'?'warranty_days':'max_resends']);virtualServicesAdminReply($m[1]==='warranty'?'مدت گارانتی را به روز وارد کنید؛ صفر یعنی بدون گارانتی.':'تعداد دفعات ارسال مجدد توسط کاربر را وارد کنید؛ صفر یعنی غیرفعال.',[[['text'=>'انصراف','callback_data'=>'vsa_product_'.$m[2]]]]);return true; }

    if ($datain === 'vsa_fx_warranties') { telegramProductsAdminWarranties(); return true; }
    if (preg_match('/^vsa_fx_warranty_(\d+)$/', $datain, $m)) { telegramProductsAdminWarranty($m[1]); return true; }
    if (preg_match('/^vsa_fx_wapprove_(\d+)$/', $datain, $m)) { $stmt=$pdo->prepare('SELECT p.product_mode FROM telegram_product_warranties w JOIN telegram_product_orders o ON o.id=w.order_id JOIN telegram_products p ON p.id=o.product_id WHERE w.id=?');$stmt->execute([$m[1]]);if($stmt->fetchColumn()==='stock'){[$ok,$msg]=telegramProductsResolveWarranty($m[1],null);virtualServicesAdminReply(telegramProductsEscape($msg),[[['text'=>'بازگشت','callback_data'=>'vsa_fx_warranty_'.$m[1]]]]);}else{virtualServicesAdminSetState('vsa_fx_warranty_payload',['id'=>(int)$m[1]]);virtualServicesAdminReply('اطلاعات جایگزین را ارسال کنید.',[[['text'=>'انصراف','callback_data'=>'vsa_fx_warranty_'.$m[1]]]]);}return true; }
    if (preg_match('/^vsa_fx_wreject_(\d+)$/', $datain, $m)) { virtualServicesAdminSetState('vsa_fx_warranty_reject',['id'=>(int)$m[1]]);virtualServicesAdminReply('دلیل رد درخواست را برای کاربر ارسال کنید.',[[['text'=>'انصراف','callback_data'=>'vsa_fx_warranty_'.$m[1]]]]);return true; }

    if ($datain === 'vsa_fx_loyalty') { telegramProductsAdminLoyalty(); return true; }
    if ($datain === 'vsa_fx_loyalty_toggle') { telegramProductsSetSetting('loyalty_enabled',telegramProductsSetting('loyalty_enabled','1')==='1'?'0':'1');telegramProductsAdminLoyalty();return true; }
    if ($datain === 'vsa_fx_loyalty_rates') { virtualServicesAdminSetState('vsa_fx_loyalty_rates');virtualServicesAdminReply("مقادیر را بفرستید:\n<code>مبلغ لازم برای کسب ۱ امتیاز|ارزش هر امتیاز|حداکثر درصد فاکتور</code>",[[['text'=>'انصراف','callback_data'=>'vsa_fx_loyalty']]]);return true; }
    if ($datain === 'vsa_fx_alerts') { telegramProductsAdminAlerts(); return true; }
    if ($datain === 'vsa_fx_alert_hours') { virtualServicesAdminSetState('vsa_fx_alert_hours');virtualServicesAdminReply('زمان هشدار سفارش معطل را به ساعت، بین ۱ تا ۱۶۸ وارد کنید.',[[['text'=>'انصراف','callback_data'=>'vsa_fx_alerts']]]);return true; }
    if ($datain === 'vsa_fx_alert_daily') { telegramProductsSetSetting('daily_summary_enabled',telegramProductsSetting('daily_summary_enabled','1')==='1'?'0':'1');telegramProductsAdminAlerts();return true; }
    if ($datain === 'vsa_fx_alert_run') { telegramProductsProfessionalAlerts();virtualServicesAdminReply('سفارش‌های معطل بررسی و اعلان‌های لازم ارسال شد.',[[['text'=>'بازگشت','callback_data'=>'vsa_fx_alerts']]]);return true; }

    if ($datain === 'vsa_fx_roles') { telegramProductsAdminRoles(); return true; }
    if (preg_match('/^vsa_fx_role_(-?\d+)$/', $datain, $m)) { $ids=array_values(array_map('strval',(array)$admin_ids));if(isset($ids[0])&&$ids[0]===(string)$m[1]){virtualServicesAdminReply('ادمین اصلی همیشه مالک است و سطح او قابل کاهش نیست.',[[['text'=>'بازگشت','callback_data'=>'vsa_fx_roles']]]);return true;}virtualServicesAdminReply('سطح دسترسی را انتخاب کنید.',[[['text'=>'مدیر','callback_data'=>'vsa_fx_roleset_'.$m[1].'_manager']],[['text'=>'اپراتور سفارش','callback_data'=>'vsa_fx_roleset_'.$m[1].'_operator']],[['text'=>'مدیر محصولات','callback_data'=>'vsa_fx_roleset_'.$m[1].'_catalog']],[['text'=>'پشتیبان','callback_data'=>'vsa_fx_roleset_'.$m[1].'_support']],[['text'=>'بازگشت','callback_data'=>'vsa_fx_roles']]]);return true; }
    if (preg_match('/^vsa_fx_roleset_(-?\d+)_(manager|operator|catalog|support)$/', $datain, $m)) { if(!in_array((string)$m[1],array_map('strval',(array)$admin_ids),true))return true;$pdo->prepare('INSERT INTO telegram_product_admin_roles (admin_id,role_key,is_active) VALUES (?,?,1) ON DUPLICATE KEY UPDATE role_key=VALUES(role_key),is_active=1,updated_at=NOW()')->execute([$m[1],$m[2]]);telegramProductsAdminRoles();return true; }
    return false;
}
