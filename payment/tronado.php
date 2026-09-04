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

// خواندن بدنه خام درخواست
$raw_input = file_get_contents("php://input");
$data = json_decode($raw_input, true);

// ثبت لاگ کال‌بک ارسالی ترونادو
file_put_contents(__DIR__ . "/tronado_callback.log", print_r([
    "time"       => date("Y-m-d H:i:s"),
    "raw_input"  => $raw_input,
    "parsed"     => $data,
    "sig_header" => $_SERVER['HTTP_X_TRONADO_SIG'] ?? null
], true) . "\n--------------------------\n", FILE_APPEND);

if (!$data) {
    http_response_code(400);
    exit("No data received");
}

// استخراج PaymentId (پوشش هر دو حالت نگارشی PaymentId و PaymentID)
$order_id = $data['PaymentId'] ?? $data['PaymentID'] ?? null;

if (!$order_id) {
    http_response_code(400);
    exit("PaymentId missing");
}

// بررسی وجود سفارش در دیتابیس
$Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
if (!$Payment_report) {
    http_response_code(404);
    exit("Order not found");
}

// جلوگیری از پردازش سفارش‌های منقضی یا قبلاً تایید شده
if ($Payment_report['payment_Status'] == "expire" || $Payment_report['payment_Status'] == "paid") {
    http_response_code(200);
    echo json_encode(["status" => true, "msg" => "Already completed"]);
    exit;
}

// تایید پرداخت قطعی طبق مستندات: OrderStatusID == 30 یا IsPaid == true
$isPaidStatus = (isset($data['OrderStatusID']) && (int)$data['OrderStatusID'] === 30) 
             || (!empty($data['IsPaid']) && ($data['IsPaid'] === true || $data['IsPaid'] === "true" || $data['IsPaid'] == 1));

// اگر وضعیت هنوز در انتظار، ارسال عکس به ادمین یا لغو شده است، عملیاتی انجام ندهید
if (!$isPaidStatus) {
    http_response_code(200);
    echo json_encode(["status" => false, "msg" => "Pending or not accepted yet"]);
    exit;
}

// اعتبارسنجی نهایی با استعلام GetStatusByPaymentID
$apitronseller = select("PaySetting", "*", "NamePay", "apiternado", "select")['ValuePay'];

$ch = curl_init("https://bot.tronado.cloud/Order/GetStatusByPaymentID");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(["PaymentID" => (string)$order_id]),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . trim($apitronseller)
    ],
    CURLOPT_TIMEOUT        => 15
]);
$verifyResponse = curl_exec($ch);
curl_close($ch);

$verifyData = json_decode($verifyResponse, true);

// اگر ترونادو پاسخ استعلام داد، شرط ۳۰ بودن تایید شود؛ در غیر این صورت تایید اولیه معتبر است
$isVerified = true;
if (!empty($verifyData) && isset($verifyData['OrderStatusID'])) {
    if ((int)$verifyData['OrderStatusID'] !== 30 && !$verifyData['IsPaid']) {
        $isVerified = false;
    }
}

if ($isVerified) {
    // ارسال فوری پاسخ ۲۰۰ به ترونادو
    http_response_code(200);
    echo json_encode(["status" => true]);

    $setting = select("setting", "*", null, null, "select");
    $price = $Payment_report['price'];

    $textbotlang = languagechange('../text.json');
    DirectPayment($order_id, "../images.jpg");

    // کش‌بک و شارژ کاربر
    $pricecashback = select("PaySetting", "ValuePay", "NamePay", "chashbackiranpay2", "select")['ValuePay'];
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");

    if (!empty($pricecashback) && $pricecashback != "0") {
        $result = ($Payment_report['price'] * $pricecashback) / 100;
        $Balance_confrim = intval($Balance_id['Balance']) + $result;
        update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
        $text_report = "🎁 کاربر عزیز مبلغ $result تومان به عنوان هدیه واریز به حساب شما واریز گردید.";
        sendmessage($Balance_id['id'], $text_report, null, 'HTML');
    }

    $paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];
    $balancelow = "";
    if (isset($data['ActualTronAmount']) && isset($data['TronAmount']) && floatval($data['TronAmount']) < floatval($data['ActualTronAmount'])) {
        $balancelow = "❌ کاربر کمتر از مبلغ تعیین شده واریز کرده است.\n";
    }

    $hashDisplay = $data['Hash'] ?? 'نامشخص';
    $tronAmountReceived = $data['TronAmount'] ?? $data['ActualTronAmount'] ?? 0;

    $text_reportpayment = "💵 پرداخت جدید\n"
        . $balancelow
        . "- 👤 نام کاربری: @" . ($Balance_id['username'] ?? 'ندارد') . "\n"
        . "- 🆔 آیدی عددی: {$Balance_id['id']}\n"
        . "- 💸 مبلغ تراکنش: {$price}\n"
        . "- 🔗 <a href=\"https://tronscan.org/#/transaction/{$hashDisplay}\">لینک تراکنش</a>\n"
        . "- 📥 ترون دریافتی: {$tronAmountReceived}\n"
        . "- 💳 روش پرداخت: ترونادو";

    $statement = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid', dec_not_confirmed = :dec WHERE id_order = :id_order");
    $statement->bindValue(':dec', $raw_input);
    $statement->bindValue(':id_order', $Payment_report['id_order']);
    $statement->execute();

    if (!empty($setting['Channel_Report'])) {
        telegram('sendmessage', [
            'chat_id'           => $setting['Channel_Report'],
            'message_thread_id' => $paymentreports,
            'text'              => $text_reportpayment,
            'parse_mode'        => "HTML"
        ]);
    }
}