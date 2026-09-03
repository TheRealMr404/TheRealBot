<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require __DIR__ . '/../vendor/autoload.php';

// آبان‌پی مقادیر کال‌بک را از طریق GET یا POST بازمی‌گرداند
$raw_input = file_get_contents("php://input");
$input_data = json_decode($raw_input, true) ?: [];
$params = array_merge($_GET, $_POST, $input_data);

file_put_contents(__DIR__ . "/aban_callback.log", print_r([
    "time" => date("Y-m-d H:i:s"),
    "params" => $params
], true) . "\n--------------------------\n", FILE_APPEND);

$order_id = $params['order_id'] ?? $params['factorNumber'] ?? null;
$authority = $params['track_id'] ?? $params['token'] ?? $params['authority'] ?? null;
$status = $params['status'] ?? null;

if (!$order_id) {
    exit("شناسه سفارش یافت نشد.");
}

$Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
if (!$Payment_report) {
    exit("سفارش در سیستم پیدا نشد.");
}

if ($Payment_report['payment_Status'] == "paid" || $Payment_report['payment_Status'] == "expire") {
    exit("این تراکنش قبلاً پردازش شده است.");
}

// بررسی اولیه وضعیت بازگشتی
if (isset($status) && !in_array(strtolower($status), ['success', '1', 'paid', 'ok'])) {
    exit("تراکنش ناموفق بود یا توسط کاربر لغو شد.");
}

$apiKey = select("PaySetting", "*", "NamePay", "api_abangateway", "select")['ValuePay'];

// استعلام و وریفای تراکنش
$verifyPayload = [
    "track_id" => $authority,
    "order_id" => (string) $order_id
];

$ch = curl_init("https://abangateway.ir/api/v1/payment/verify");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($verifyPayload),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . trim($apiKey),
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_TIMEOUT => 15
]);
$verifyResponse = curl_exec($ch);
curl_close($ch);

$res = json_decode($verifyResponse, true);

file_put_contents(__DIR__ . "/aban_verify.log", print_r([
    "time" => date("Y-m-d H:i:s"),
    "sent" => $verifyPayload,
    "response" => $res
], true) . "\n--------------------------\n", FILE_APPEND);

// شرط موفقیت در تایید تراکنش
$isVerified = (!empty($res['status']) && in_array(strtolower((string) $res['status']), ['success', '1', 'true', 'ok']))
    || (!empty($res['success']) && $res['success'] === true);

if ($isVerified) {
    $price = $Payment_report['price'];
    DirectPayment($order_id, "../images.jpg");

    // بررسی و اعمال کش‌بک
// تغییر این خط:
    $pricecashback = select("PaySetting", "ValuePay", "NamePay", "chashbackabangateway", "select")['ValuePay'];
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");

    if (!empty($pricecashback) && $pricecashback != "0") {
        $result = ($Payment_report['price'] * $pricecashback) / 100;
        $Balance_confrim = intval($Balance_id['Balance']) + $result;
        update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
        $text_report = "🎁 کاربر عزیز مبلغ " . number_format($result) . " تومان به عنوان هدیه واریز به حساب شما واریز گردید.";
        sendmessage($Balance_id['id'], $text_report, null, 'HTML');
    }

    $paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];
    $refNumber = $res['ref_number'] ?? $res['data']['ref_number'] ?? $authority;

    $text_reportpayment = "💵 پرداخت جدید (آبان پی)\n"
        . "- 👤 نام کاربری: @" . ($Balance_id['username'] ?? 'ندارد') . "\n"
        . "- 🆔 آیدی عددی: {$Balance_id['id']}\n"
        . "- 💸 مبلغ تراکنش: " . number_format($price) . " تومان\n"
        . "- 🔗 شماره پیگیری: {$refNumber}\n"
        . "- 💳 روش پرداخت: درگاه آبان‌پی";

    // تغییر وضعیت به paid
    $statement = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid', dec_not_confirmed = :dec WHERE id_order = :id_order");
    $statement->bindValue(':dec', $verifyResponse ?: json_encode($params));
    $statement->bindValue(':id_order', $Payment_report['id_order']);
    $statement->execute();

    $setting = select("setting", "*", null, null, "select");
    if (!empty($setting['Channel_Report'])) {
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $paymentreports,
            'text' => $text_reportpayment,
            'parse_mode' => "HTML"
        ]);
    }

    echo "پرداخت با موفقیت انجام شد. می‌توانید به ربات بازگردید.";
} else {
    echo "خطا در تایید تراکنش: " . ($res['message'] ?? 'نامشخص');
}