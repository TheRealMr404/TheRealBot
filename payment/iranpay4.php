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

$order_id = trim((string) ($_GET['order_id'] ?? $_POST['order_id'] ?? ''));
$invoice_id = trim((string) ($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? $_GET['authority'] ?? $_POST['authority'] ?? ''));

function abangateway_finish(bool $ok, string $title, string $detail): never
{
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

$failedTitle = $textbotlang['paymentGateway']['statusFailed'] ?? 'پرداخت ناموفق';
$successTitle = $textbotlang['paymentGateway']['statusSuccess'] ?? 'پرداخت موفق';

if ($order_id === '' && $invoice_id === '') {
    abangateway_finish(false, $failedTitle, 'شناسه سفارش یا فاکتور ارسال نشد.');
}

if ($order_id !== '') {
    $payment = select("Payment_report", "*", "id_order", $order_id, "select");
} else {
    $payment = select("Payment_report", "*", "authority", $invoice_id, "select");
    if ($payment) {
        $order_id = $payment['id_order'];
    }
}

if (!$payment) {
    abangateway_finish(false, $failedTitle, 'این سفارش پیدا نشد.');
}

if ($payment['payment_Status'] === 'paid' || $payment['payment_Status'] === 'Paid') {
    abangateway_finish(true, $successTitle, 'این پرداخت قبلاً تایید شده است.');
}

$api_key = trim((string) getPaySettingValue('api_abangateway', ''));
$base_endpoint = rtrim((string) getPaySettingValue('endpointabangateway', 'https://abangateway.ir/api/v1'), '/');

if ($api_key === '' || $api_key === '0') {
    abangateway_finish(false, $failedTitle, 'درگاه پیکربندی نشده است.');
}

$priceToman = intval($payment['price']);
$targetInvoice = $invoice_id !== '' ? $invoice_id : ($payment['authority'] ?? '');

if (empty($targetInvoice)) {
    abangateway_finish(false, $failedTitle, 'شناسه فاکتور معتبر نیست.');
}

$verifyUrl = $base_endpoint . '/invoices/' . rawurlencode($targetInvoice) . '/verify';

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $verifyUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => '{}',
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $api_key,
    ],
]);
$result = curl_exec($curl);
$httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

$response = is_string($result) ? json_decode($result, true) : null;

$isVerified = false;

if ($httpCode === 200 && is_array($response)) {
    $returnedRial = intval($response['amount_rial'] ?? 0);
    $priceRial = $priceToman * 10;
    
    if (!empty($response['verified']) && ($returnedRial >= $priceRial || $returnedRial === 0)) {
        $isVerified = true;
    }
} elseif ($httpCode === 409 && isset($response['error']['code']) && $response['error']['code'] === 'already_verified') {
    $isVerified = true;
}

if (!$isVerified) {
    abangateway_finish(false, $failedTitle, 'پرداخت تایید نشد.');
}

if (!claimPaymentPaid($order_id)) {
    abangateway_finish(true, $successTitle, 'این پرداخت قبلاً تایید شده است.');
}

try {
    DirectPayment($order_id, "../images.jpg");
} catch (Throwable $error) {
    error_log("abangateway: DirectPayment failed for {$order_id}: " . $error->getMessage());
    abangateway_finish(false, $failedTitle, 'پرداخت تایید شد ولی تحویل سرویس خطا داد. با پشتیبانی تماس بگیرید.');
}

$cashback = intval(getPaySettingValue('chashbackabangateway', '0'));
if ($cashback > 0) {
    $buyer = select("user", "*", "id", $payment['id_user'], "select");
    if ($buyer) {
        $reward = intval($priceToman * $cashback / 100);
        if ($reward > 0) {
            update("user", "Balance", intval($buyer['Balance']) + $reward, "id", $payment['id_user']);
        }
    }
}

abangateway_finish(true, $successTitle, 'پرداخت شما با موفقیت انجام شد.');