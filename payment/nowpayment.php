<?php

ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../vpnbot/reseller_features.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../jdf.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();

function nowPaymentsSortPayload(&$value)
{
    if (!is_array($value)) {
        return;
    }
    foreach ($value as &$item) {
        nowPaymentsSortPayload($item);
    }
    unset($item);
    if (array_keys($value) !== range(0, count($value) - 1)) {
        ksort($value, SORT_STRING);
    }
}

function nowPaymentsCallbackResponse($statusCode, $message)
{
    http_response_code((int) $statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    exit((string) $message);
}

$rawPayload = file_get_contents('php://input');
$payload = json_decode((string) $rawPayload, true);
if (!is_array($payload) || empty($payload['payment_id'])) {
    nowPaymentsCallbackResponse(400, 'Invalid payload');
}

$ipnSecret = (string) (select('PaySetting', 'ValuePay', 'NamePay', 'nowpayment_ipn_secret', 'select')['ValuePay'] ?? '');
if ($ipnSecret !== '' && $ipnSecret !== '0') {
    $receivedSignature = strtolower(trim((string) ($_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? '')));
    if ($receivedSignature === '') {
        nowPaymentsCallbackResponse(401, 'Missing signature');
    }
    $sortedPayload = $payload;
    nowPaymentsSortPayload($sortedPayload);
    $signedPayload = json_encode($sortedPayload, JSON_UNESCAPED_SLASHES);
    $expectedSignature = hash_hmac('sha512', $signedPayload, trim($ipnSecret));
    if (!hash_equals($expectedSignature, $receivedSignature)) {
        nowPaymentsCallbackResponse(401, 'Invalid signature');
    }
}

if (($payload['payment_status'] ?? '') !== 'finished') {
    nowPaymentsCallbackResponse(202, 'Payment is not finished');
}

$paymentId = (string) $payload['payment_id'];
if (!preg_match('/^\d{1,30}$/', $paymentId)) {
    nowPaymentsCallbackResponse(400, 'Invalid payment id');
}

$providerPayment = StatusPayment($paymentId);
if (!is_array($providerPayment) || empty($providerPayment['payment_id'])) {
    error_log('NOWPayments status lookup failed for payment ' . $paymentId);
    nowPaymentsCallbackResponse(502, 'Unable to verify payment');
}
if ((string) ($providerPayment['payment_id'] ?? '') !== $paymentId) {
    nowPaymentsCallbackResponse(400, 'Payment id mismatch');
}
if (($providerPayment['payment_status'] ?? '') !== 'finished') {
    nowPaymentsCallbackResponse(202, 'Payment is not finished');
}

$invoiceId = trim((string) ($providerPayment['invoice_id'] ?? $payload['invoice_id'] ?? ''));
$orderId = trim((string) ($providerPayment['order_id'] ?? $payload['order_id'] ?? ''));
if ($invoiceId === '' && $orderId === '') {
    nowPaymentsCallbackResponse(400, 'Missing invoice reference');
}

if ($invoiceId !== '' && $orderId !== '') {
    $stmt = $pdo->prepare(
        "SELECT * FROM Payment_report
         WHERE Payment_Method = 'nowpayment'
           AND (dec_not_confirmed = :invoice_id OR id_order = :order_id)
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':invoice_id' => $invoiceId, ':order_id' => $orderId]);
} elseif ($invoiceId !== '') {
    $stmt = $pdo->prepare(
        "SELECT * FROM Payment_report
         WHERE Payment_Method = 'nowpayment' AND dec_not_confirmed = :invoice_id
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':invoice_id' => $invoiceId]);
} else {
    $stmt = $pdo->prepare(
        "SELECT * FROM Payment_report
         WHERE Payment_Method = 'nowpayment' AND id_order = :order_id
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
}
$paymentReport = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$paymentReport) {
    nowPaymentsCallbackResponse(404, 'Payment report not found');
}
if ($orderId !== '' && !hash_equals((string) $paymentReport['id_order'], $orderId)) {
    nowPaymentsCallbackResponse(400, 'Order mismatch');
}
if ($invoiceId !== '' && !hash_equals((string) $paymentReport['dec_not_confirmed'], $invoiceId)) {
    nowPaymentsCallbackResponse(400, 'Invoice mismatch');
}
if (($paymentReport['payment_Status'] ?? '') === 'paid') {
    nowPaymentsCallbackResponse(200, 'OK');
}

$details = [
    'شناسه پرداخت' => $paymentId,
    'ارز پرداختی' => strtoupper((string) ($providerPayment['pay_currency'] ?? '')),
    'مبلغ دریافتی' => $providerPayment['actually_paid'] ?? '',
];
if (!empty($paymentReport['bottype'])) {
    $resellerResult = resellerCompleteOnlinePayment($paymentReport['id_order'], 'NOWPayments', $details);
    if (empty($resellerResult['ok'])) {
        nowPaymentsCallbackResponse(500, 'Unable to complete reseller payment');
    }
    nowPaymentsCallbackResponse(200, 'OK');
}

$setting = select('setting', '*');
$textbotlang = languagechange('../text.json');
$datatextbot = [];
foreach (select('textbot', '*', null, null, 'fetchAll') as $row) {
    $datatextbot[$row['id_text']] = $row['text'];
}

DirectPayment($paymentReport['id_order'], '../images.jpg');
update('Payment_report', 'payment_Status', 'paid', 'id_order', $paymentReport['id_order']);
$paymentReport = select('Payment_report', '*', 'id_order', $paymentReport['id_order'], 'select');
if (($paymentReport['payment_Status'] ?? '') !== 'paid') {
    nowPaymentsCallbackResponse(500, 'Unable to complete payment');
}

$balanceUser = select('user', '*', 'id', $paymentReport['id_user'], 'select');
$cashbackPercent = (float) (select('PaySetting', 'ValuePay', 'NamePay', 'cashbacknowpayment', 'select')['ValuePay'] ?? 0);
if ($cashbackPercent > 0 && $balanceUser) {
    $cashback = ((int) $paymentReport['price'] * $cashbackPercent) / 100;
    $newBalance = (int) $balanceUser['Balance'] + $cashback;
    update('user', 'Balance', $newBalance, 'id', $balanceUser['id']);
    sendmessage($balanceUser['id'], "🎁 مبلغ " . number_format($cashback) . ' تومان به‌عنوان هدیه به کیف پول شما اضافه شد.', null, 'HTML');
}

$paymentReportTopic = select('topicid', 'idreport', 'report', 'paymentreport', 'select')['idreport'] ?? null;
$safeUsername = htmlspecialchars((string) ($balanceUser['username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeCurrency = htmlspecialchars(strtoupper((string) ($providerPayment['pay_currency'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safePaidAmount = htmlspecialchars((string) ($providerPayment['actually_paid'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$reportText = "💵 <b>پرداخت جدید NOWPayments</b>\n\n"
    . "نام کاربری: @{$safeUsername}\n"
    . "آیدی عددی: <code>{$paymentReport['id_user']}</code>\n"
    . 'مبلغ: ' . number_format((int) $paymentReport['price']) . " تومان\n"
    . "مبلغ دریافتی: {$safePaidAmount} {$safeCurrency}\n"
    . "شناسه پرداخت: <code>{$paymentId}</code>";
if (!empty($setting['Channel_Report'])) {
    $reportRequest = [
        'chat_id' => $setting['Channel_Report'],
        'text' => $reportText,
        'parse_mode' => 'HTML',
    ];
    if (!empty($paymentReportTopic)) {
        $reportRequest['message_thread_id'] = $paymentReportTopic;
    }
    telegram('sendmessage', $reportRequest);
}

nowPaymentsCallbackResponse(200, 'OK');
