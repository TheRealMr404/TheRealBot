<?php
// ۱. قفل انحصاری سخت‌افزاری در سطح فایل (Non-Blocking)
$lock_file = fopen(__DIR__ . '/send_process.lock', 'c+');
if (!$lock_file || !flock($lock_file, LOCK_EX | LOCK_NB)) {
    // اگر پروسس دیگری در حال اجراست، این پروسس در کسری از میلی‌ثانیه کشته می‌شود
    exit;
}

// بستن پروسس در صورت قطعی اتصال یا تایم‌اوت
ignore_user_abort(true);
set_time_limit(300);
ini_set('memory_limit', '256M');

date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';

$info_path = __DIR__ . '/info';
$users_path = __DIR__ . '/users.json';

// بررسی وجود صف ارسال
if (!is_file($info_path) || !is_file($users_path)) {
    flock($lock_file, LOCK_UN);
    fclose($lock_file);
    exit;
}

$info = json_decode(file_get_contents($info_path), true);
$userid = json_decode(file_get_contents($users_path), true);

if (!is_array($info) || !is_array($userid) || count($userid) === 0) {
    @unlink($users_path);
    @unlink($info_path);
    flock($lock_file, LOCK_UN);
    fclose($lock_file);
    exit;
}

if (!isset($info['count_success'])) $info['count_success'] = 0;
if (!isset($info['count_blocked'])) $info['count_blocked'] = 0;

// دریافت متون دکمه‌ها از دیتابیس
$datatextbotget = select("textbot", "*", null, null, "fetchAll");
$datatextbot = [
    'text_usertest' => '',
    'text_support' => '',
    'text_help' => '',
    'text_sell' => '',
    'text_affiliates' => '',
    'text_Add_Balance' => ''
];

if (is_array($datatextbotget)) {
    foreach ($datatextbotget as $row) {
        if (isset($datatextbot[$row['id_text']])) {
            $datatextbot[$row['id_text']] = $row['text'];
        }
    }
}

// ساخت دکمه‌ها با استایل و ایموجی کاستوم تلگرام
$keyboardbuy = json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_sell'], 'callback_data' => 'buy', 'style' => 'primary', 'icon_custom_emoji_id' => '5258236805890710909']]]]);
$keyboardstart = json_encode(['inline_keyboard' => [[['text' => "شروع", 'callback_data' => 'start', 'style' => 'primary']]]]);
$keyboardusertest = json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_usertest'], 'callback_data' => 'usertestbtn', 'style' => 'primary']]]]);
$keyboardhelpbtn = json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_help'], 'callback_data' => 'helpbtn', 'style' => 'primary']]]]);
$keyboardaffiliates = json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_affiliates'], 'callback_data' => 'affiliatesbtn', 'style' => 'primary']]]]);
$keyboardaddbalance = json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_Add_Balance'], 'callback_data' => 'Add_Balance', 'style' => 'success']]]]);

$cancelmessage = json_encode([
    'inline_keyboard' => [
        [['text' => "❌ لغو عملیات", 'callback_data' => 'cancel_sendmessage', 'style' => 'danger']]
    ]
]);

// پردازش بسته ۵۰تایی در هر بار اجرا برای حفظ ثبات و جلوگیری از هنگ رم
$batch_size = min(50, count($userid));
$current_batch = array_slice($userid, 0, $batch_size);

foreach ($current_batch as $item) {
    $target_chat_id = is_array($item) ? ($item['id'] ?? null) : (is_object($item) ? ($item->id ?? null) : $item);
    if (empty($target_chat_id)) continue;

    $meesage = null;

    if ($info['type'] == "unpinmessage") {
        unpinmessage($target_chat_id);
    } elseif ($info['type'] == "sendmessage" || $info['type'] == "xdaynotmessage") {
        $btn = $info['btnmessage'] ?? 'none';
        $reply_markup = null;

        if ($btn == "buy") $reply_markup = $keyboardbuy;
        elseif ($btn == "start") $reply_markup = $keyboardstart;
        elseif ($btn == "usertestbtn") $reply_markup = $keyboardusertest;
        elseif ($btn == "helpbtn") $reply_markup = $keyboardhelpbtn;
        elseif ($btn == "affiliatesbtn") $reply_markup = $keyboardaffiliates;
        elseif ($btn == "addbalance") $reply_markup = $keyboardaddbalance;

        $meesage = sendmessage($target_chat_id, $info['message'], $reply_markup, 'HTML');

        $is_ok = (is_array($meesage) && isset($meesage['ok']) && $meesage['ok'] === true);

        if ($is_ok) {
            $info['count_success']++;
            if (isset($info['pingmessage']) && $info['pingmessage'] == "yes") {
                pinmessage($target_chat_id, $meesage['result']['message_id']);
            }
        } else {
            $info['count_blocked']++;
            $desc = is_array($meesage) ? ($meesage['description'] ?? '') : '';
            if (strpos($desc, 'blocked by the user') !== false) {
                $invoicecount = select("invoice", "*", "id_user", $target_chat_id, "count");
                $userinfo = select("user", "Balance", "id", $target_chat_id, "select");
                if ($invoicecount == 0 && isset($userinfo['Balance']) && $userinfo['Balance'] == 0) {
                    $stmt = $pdo->prepare("DELETE FROM user WHERE id = :uid");
                    $stmt->execute([':uid' => $target_chat_id]);
                }
            }
        }
    } elseif ($info['type'] == "forwardmessage") {
        $meesage = forwardMessage($info['id_admin'], $info['message'], $target_chat_id);

        $is_ok = (is_array($meesage) && isset($meesage['ok']) && $meesage['ok'] === true);

        if ($is_ok) {
            $info['count_success']++;
            if (isset($info['pingmessage']) && $info['pingmessage'] == "yes") {
                pinmessage($target_chat_id, $meesage['result']['message_id']);
            }
        } else {
            $info['count_blocked']++;
        }
    }

    usleep(40000); // وقفه ۴۰ میلی‌ثانیه‌ای برای رعایت سقف درخواست‌های تلگرام (۳۰ درخواست در ثانیه)
}

// حذف افراد ارسال‌شده از صف
array_splice($userid, 0, $batch_size);
$count_remein = count($userid);

if ($count_remein > 0) {
    file_put_contents($users_path, json_encode(array_values($userid), JSON_UNESCAPED_UNICODE));
    file_put_contents($info_path, json_encode($info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // آپدیت متن پیام وضعیت برای ادمین
    if (isset($info['id_admin']) && isset($info['id_message'])) {
        $textprocces = "✏️ عملیات ارسال پیام درحال انجام می‌باشد...\n\n" .
                       "👥 تعداد نفرات باقی‌مانده: <b>{$count_remein}</b>\n" .
                       "✅ ارسال موفق: <b>{$info['count_success']}</b>\n" .
                       "🚫 ناموفق: <b>{$info['count_blocked']}</b>";

        telegram('editMessageText', [
            'chat_id' => $info['id_admin'],
            'message_id' => $info['id_message'],
            'text' => $textprocces,
            'parse_mode' => 'HTML',
            'reply_markup' => $cancelmessage
        ]);
    }
} else {
    // اتمام عملیات و ارسال گزارش نهایی
    if (isset($info['id_admin'])) {
        $count_success = (int)$info['count_success'];
        $count_blocked = (int)$info['count_blocked'];
        $count_total = $count_success + $count_blocked;

        $final_report = "📌 <b>عملیات ارسال همگانی با موفقیت به پایان رسید.</b>\n\n";
        $final_report .= "👥 <b>کل کاربران پردازش‌شده:</b> {$count_total}\n";
        $final_report .= "✅ <b>ارسال موفق:</b> {$count_success}\n";
        $final_report .= "🚫 <b>ناموفق (بلاک / خطا):</b> {$count_blocked}";

        if (isset($info['id_message'])) {
            deletemessage($info['id_admin'], $info['id_message']);
        }
        sendmessage($info['id_admin'], $final_report, null, 'HTML');
    }

    @unlink($users_path);
    @unlink($info_path);
}

// آزادسازی قفل و خروج نهایی
flock($lock_file, LOCK_UN);
fclose($lock_file);
exit;