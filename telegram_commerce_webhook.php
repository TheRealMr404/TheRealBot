<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/botapi.php';
require_once __DIR__ . '/function.php';
require_once __DIR__ . '/telegram_products.php';
require_once __DIR__ . '/telegram_commerce.php';

telegramCommerceEnsureSchema();
$config = telegramCommerceConfig();
$body = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_COMMERCE_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_COMMERCE_SIGNATURE'] ?? '';
$eventId = $_SERVER['HTTP_X_COMMERCE_EVENT_ID'] ?? '';

if ($config['webhook_secret'] === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || !preg_match('/^v1=([a-f0-9]{64})$/i', $signature, $match)) {
    http_response_code(401);
    exit('invalid webhook');
}
$expected = hash_hmac('sha256', $timestamp . '.' . $body, $config['webhook_secret']);
if (!hash_equals($expected, strtolower($match[1]))) {
    http_response_code(401);
    exit('invalid signature');
}
$event = json_decode($body, true);
if (!is_array($event) || ($event['type'] ?? '') !== 'order.updated' || empty($event['order']['id']) || !preg_match('/^[a-f0-9-]{36}$/i', $eventId)) {
    http_response_code(400);
    exit('invalid event');
}
try {
    $shouldRefund = false;
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT IGNORE INTO telegram_commerce_webhook_events (event_id) VALUES (?)');
    $stmt->execute([$eventId]);
    if ($stmt->rowCount() === 0) {
        $pdo->commit();
        http_response_code(204);
        exit;
    }
    $remote = $event['order'];
    $stmt = $pdo->prepare('SELECT * FROM telegram_commerce_orders WHERE api_order_id=? FOR UPDATE');
    $stmt->execute([$remote['id']]);
    $local = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($local) {
        $status = substr((string) ($remote['status'] ?? $local['status']), 0, 40);
        $shouldRefund = $status === 'failed' && (int) $local['wallet_debited'] === 1 && (int) $local['wallet_refunded'] === 0;
        if ($shouldRefund) {
            $pdo->prepare('UPDATE user SET Balance=Balance+? WHERE id=?')->execute([(int) $local['amount'], $local['user_id']]);
        }
        $stmt = $pdo->prepare('UPDATE telegram_commerce_orders SET status=?,provider_reference=?,transaction_hash=?,failure_message=?,wallet_refunded=IF(?,1,wallet_refunded),completed_at=IF(?="fulfilled",NOW(),completed_at) WHERE id=?');
        $stmt->execute([$status, $remote['provider_reference'] ?? null, $remote['transaction_hash'] ?? null, $remote['failure_message'] ?? null, $shouldRefund ? 1 : 0, $status, $local['id']]);
    }
    $pdo->commit();
    if ($shouldRefund && function_exists('clearSelectCache')) clearSelectCache('user');
    if ($local && ($local['notified_status'] ?? '') !== $status && in_array($status, ['fulfilled', 'reconciliation_required', 'failed'], true)) {
        $message = $status === 'fulfilled'
            ? "سفارش خودکار شما با موفقیت انجام شد.\n\n<b>شماره سفارش:</b> <code>#{$local['id']}</code>"
            : "وضعیت سفارش خودکار شما نیازمند بررسی است.\n\n<b>شماره سفارش:</b> <code>#{$local['id']}</code>\n<b>وضعیت:</b> " . telegramProductsEscape(telegramCommerceStatusLabel($status));
        if ($status === 'failed' && !empty($shouldRefund)) $message .= "\n\nمبلغ کامل به کیف پول شما برگشت.";
        sendmessage($local['user_id'], $message, null, 'HTML');
        $pdo->prepare('UPDATE telegram_commerce_orders SET notified_status=? WHERE id=?')->execute([$status, $local['id']]);
    }
    http_response_code(204);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Telegram commerce webhook failed: ' . $e->getMessage());
    http_response_code(500);
}

