<?php

ini_set('display_errors', '0');
ini_set('error_log', __DIR__ . '/error_log');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../jdf.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$textbotlang = languagechange();

// تابع لاگ‌گیری دقیق در فایل abangateway_debug.log
function aban_log($title, $data) {
    $file = __DIR__ . '/abangateway_debug.log';
    $time = date('Y-m-d H:i:s');
    $content = is_scalar($data) ? (string)$data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    @file_put_contents($file, "[{$time}] === {$title} ===\n{$content}\n\n", FILE_APPEND);
}

function abangateway_finish(bool $ok, string $title, string $detail, bool $isWebhook = false): never
{
    aban_log("RESPONSE TO CLIENT/GATEWAY", [
        'ok' => $ok,
        'title' => $title,
        'detail' => $detail,
        'isWebhook' => $isWebhook
    ]);

    if ($isWebhook) {
        http_response_code($ok ? 200 : 400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $detail], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:48px 20px">'
        . '<div style="font-size:44px">' . ($ok ? '&#10003;' : '&#10005;') . '</div>'
        . '<h2 style="color:' . ($ok ? '#2ecc71' : '#e74c3c') . '">'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p style="color:#666">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</body></html>';
    exit;
}

// ثبت تمامی ورودی‌های ارسالی به فایل
$rawBody = file_get_contents('php://input');
aban_log("INCOMING REQUEST", [
    'GET' => $_GET,
    'POST' => $_POST,
    'RAW_BODY' => $rawBody,
    'X_SIGNATURE' => $_SERVER['HTTP_X_SIGNATURE'] ?? null,
    'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? null
]);

$failedTitle  = $textbotlang['paymentGateway']['statusFailed'] ?? 'پرداخت ناموفق';
$successTitle = $textbotlang['paymentGateway']['statusSuccess'] ?? 'پرداخت موفق';

$isWebhook = !empty($_SERVER['HTTP_X_SIGNATURE']);
$order_id = trim((string) ($_GET['order_id'] ?? $_POST['order_id'] ?? ''));
$invoice_id = trim((string) ($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? $_GET['authority'] ?? $_POST['authority'] ?? ''));

// اگر از طریق وب‌هوک JSON آمده باشد
if ($rawBody !== '') {
    $jsonInput = json_decode($rawBody, true);
    if (is_array($jsonInput)) {
        if ($order_id === '' && !empty($jsonInput['order_id'])) {
            $order_id = trim((string) $jsonInput['order_id']);
        }
        if ($invoice_id === '' && !empty($jsonInput['invoice_id'])) {
            $invoice_id = trim((string) $jsonInput['invoice_id']);
        }
    }
}

aban_log("PARSED IDS", ['order_id' => $order_id, 'invoice_id' => $invoice_id]);

if ($order_id === '' && $invoice_id === '') {
    abangateway_finish(false, $failedTitle, 'شناسه سفارش یا فاکتور دریافت نشد.', $isWebhook);
}

// بررسی دیتابیس
if ($order_id !== '') {
    $payment = select("Payment_report", "*", "id_order", $order_id, "select");
} else {
    $payment = select("Payment_report", "*", "authority", $invoice_id, "select");
    if ($payment) {
        $order_id = $payment['id_order'];
    }
}

aban_log("DATABASE RECORD", $payment ?: 'RECORD NOT FOUND');

if (!$payment) {
    abangateway_finish(false, $failedTitle, 'سفارش در دیتابیس یافت نشد.', $isWebhook);
}

if ($payment['payment_Status'] === 'paid' || $payment['payment_Status'] === 'Paid') {
    abangateway_finish(true, $successTitle, 'این پرداخت قبلاً تایید شده است.', $isWebhook);
}

$api_key = trim((string) getPaySettingValue('apiiranpay4', getPaySettingValue('api_abangateway', '')));
$endpoint = rtrim((string) getPaySettingValue('endpointiranpay4', getPaySettingValue('endpointabangateway', 'https://api.abangateway.ir')), '/');

aban_log("CONFIG VALUES", ['api_key' => $api_key ? substr($api_key, 0, 8) . '***' : 'EMPTY', 'endpoint' => $endpoint]);

if ($api_key === '' || $api_key === '0') {
    abangateway_finish(false, $failedTitle, 'کلید درگاه تنظیم نشده است.', $isWebhook);
}

$targetInvoice = $invoice_id !== '' ? $invoice_id : ($payment['authority'] ?? '');

// تعیین آدرس و متد وریفای بر اساس اینکه میرزاپرو است یا API رسمی
$isMirza = str_contains($endpoint, 'mirzapro');

if ($isMirza) {
    $verifyUrl = $endpoint . '/verify';
    $postPayload = json_encode([
        'order_id' => $order_id,
        'amount'   => intval($payment['price']),
    ], JSON_UNESCAPED_UNICODE);
} else {
    $verifyUrl = $endpoint . '/api/v1/invoices/' . rawurlencode($targetInvoice) . '/verify';
    $postPayload = '{}';
}

aban_log("VERIFY REQUEST", [
    'mode' => $isMirza ? 'MirzaPro' : 'Official API',
    'url' => $verifyUrl,
    'payload' => $postPayload
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $verifyUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postPayload,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
]);

$responseRaw = curl_exec($ch);
$httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError   = curl_error($ch);
curl_close($ch);

aban_log("VERIFY RESPONSE", [
    'http_code' => $httpCode,
    'curl_error' => $curlError ?: 'None',
    'response_raw' => $responseRaw
]);

$verifyData = is_string($responseRaw) ? json_decode($responseRaw, true) : null;
$verified = false;

if ($httpCode === 200 && is_array($verifyData)) {
    if (!empty($verifyData['verified']) || !empty($verifyData['success']) || ($verifyData['status'] ?? '') === 'paid') {
        $verified = true;
    }
} elseif ($httpCode === 400 || $httpCode === 409) {
    $errCode = $verifyData['error']['code'] ?? $verifyData['message'] ?? '';
    if ($errCode === 'already_verified') {
        $verified = true;
    }
}

if (!$verified) {
    abangateway_finish(false, $failedTitle, 'درگاه پرداخت را تایید نکرد. جزئیات در فایل لاگ ثبت شد.', $isWebhook);
}

if (!claimPaymentPaid($order_id)) {
    abangateway_finish(true, $successTitle, 'این پرداخت قبلاً تایید شده است.', $isWebhook);
}

try {
    DirectPayment($order_id, "../images.jpg");
    aban_log("DIRECT PAYMENT", "Success for order: {$order_id}");
} catch (Throwable $e) {
    aban_log("DIRECT PAYMENT ERROR", $e->getMessage());
    abangateway_finish(false, $failedTitle, 'پرداخت تایید شد اما در ساخت اکانت خطایی رخ داد.', $isWebhook);
}

// کش‌بک
$cashback = intval(getPaySettingValue('chashbackiranpay4', getPaySettingValue('chashbackabangateway', '0')));
if ($cashback > 0) {
    $buyer = select("user", "*", "id", $payment['id_user'], "select");
    if ($buyer) {
        $reward = intval(intval($payment['price']) * $cashback / 100);
        if ($reward > 0) {
            update("user", "Balance", intval($buyer['Balance']) + $reward, "id", $payment['id_user']);
        }
    }
}

abangateway_finish(true, $successTitle, 'پرداخت شما با موفقیت تایید و اعمال شد.', $isWebhook);