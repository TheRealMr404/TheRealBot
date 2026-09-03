<?php

ini_set('error_log', 'error_log');
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

// کلید سکرت وبهوک که از داشبورد گرفتید
const ABAN_WEBHOOK_SECRET = 'whs_izsfippejxpm8vwpmhwm2hmh';

function aban_output(bool $ok, string $title, string $detail, bool $isWebhook = false): never
{
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

$failedTitle  = $textbotlang['paymentGateway']['statusFailed'] ?? 'پرداخت ناموفق';
$successTitle = $textbotlang['paymentGateway']['statusSuccess'] ?? 'پرداخت موفق';

$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$isWebhook = !empty($signature);

// الف: تشخیص و بررسی امضای وب‌هوک سرور
if ($isWebhook) {
    if (!hash_equals(hash_hmac('sha256', $rawBody, ABAN_WEBHOOK_SECRET), $signature)) {
        http_response_code(401);
        exit('Invalid signature');
    }

    $event = json_decode($rawBody, true);
    if (($event['event'] ?? '') !== 'invoice.paid') {
        http_response_code(200);
        exit;
    }

    $order_id   = trim((string) ($event['order_id'] ?? ''));
    $invoice_id = trim((string) ($event['invoice_id'] ?? ''));
} else {
    // ب: بازگشت مستقیم خریدار در مرورگر
    $order_id   = trim((string) ($_GET['order_id'] ?? $_POST['order_id'] ?? ''));
    $invoice_id = trim((string) ($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? ''));
}

if ($order_id === '' && $invoice_id === '') {
    aban_output(false, $failedTitle, 'شناسه سفارش نامشخص است.', $isWebhook);
}

// یافتن سفارش در جدول گزارش پرداخت
if ($order_id !== '') {
    $payment = select("Payment_report", "*", "id_order", $order_id, "select");
} else {
    $payment = select("Payment_report", "*", "authority", $invoice_id, "select");
    if ($payment) {
        $order_id = $payment['id_order'];
    }
}

if (!$payment) {
    aban_output(false, $failedTitle, 'سفارش در سیستم پیدا نشد.', $isWebhook);
}

// تسویه قبلی
if ($payment['payment_Status'] === 'paid' || $payment['payment_Status'] === 'Paid') {
    aban_output(true, $successTitle, 'این پرداخت قبلاً تایید شده است.', $isWebhook);
}

$api_key  = trim((string) getPaySettingValue('api_abangateway', ''));
$endpoint = rtrim((string) getPaySettingValue('endpointabangateway', 'https://api.abangateway.ir'), '/');

$targetInvoice = $invoice_id !== '' ? $invoice_id : ($payment['authority'] ?? '');

// پ: اجرای verify طبق نمونه کد رسمی
$verified = false;

if (!empty($targetInvoice) && !empty($api_key)) {
    $ch = curl_init($endpoint . "/api/v1/invoices/{$targetInvoice}/verify");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    $rawResponse = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $verifyData = json_decode($rawResponse ?: '{}', true);

    if ($httpCode === 200 && !empty($verifyData['verified'])) {
        $verified = true;
    } elseif ($httpCode >= 400 && ($verifyData['error']['code'] ?? '') === 'already_verified') {
        $verified = true;
    }
}

// در شرایطی که امضای وبهوک معتبر است اما استعلام مجدد ارور موقت بدهد
if (!$verified && $isWebhook) {
    $verified = true;
}

if (!$verified) {
    aban_output(false, $failedTitle, 'پرداخت مورد تایید درگاه قرار نگرفت.', $isWebhook);
}

// ت: تحویل یکباره سفارش (جلوگیری از race condition وبهوک و کاربر)
if (!claimPaymentPaid($order_id)) {
    aban_output(true, $successTitle, 'این پرداخت قبلاً تایید شده است.', $isWebhook);
}

// تحویل سرویس / شارژ اکانت
try {
    DirectPayment($order_id, "../images.jpg");
} catch (Throwable $error) {
    error_log("AbanGateway DirectPayment failed for {$order_id}: " . $error->getMessage());
    aban_output(false, $failedTitle, 'سرویس تحویل نشد، با پشتیبانی در ارتباط باشید.', $isWebhook);
}

// پاداش و کش‌بک
$cashback = intval(getPaySettingValue('chashbackabangateway', '0'));
if ($cashback > 0) {
    $buyer = select("user", "*", "id", $payment['id_user'], "select");
    if ($buyer) {
        $priceToman = intval($payment['price']);
        $reward = intval($priceToman * $cashback / 100);
        if ($reward > 0) {
            update("user", "Balance", intval($buyer['Balance']) + $reward, "id", $payment['id_user']);
        }
    }
}

aban_output(true, $successTitle, 'پرداخت و شارژ حساب با موفقیت انجام شد.', $isWebhook);