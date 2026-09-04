<?php
// قفل سیستمی برای جلوگیری از اجرای همزمان چند کرون
$lock_file = fopen(__DIR__ . '/send_process.lock', 'c+');
if (!$lock_file || !flock($lock_file, LOCK_EX | LOCK_NB)) {
    exit;
}

ignore_user_abort(true);
set_time_limit(180);
ini_set('memory_limit', '256M');

date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';

$info_path = __DIR__ . '/info';
$users_path = __DIR__ . '/users.json';

if (!is_file($info_path) || !is_file($users_path)) {
    flock($lock_file, LOCK_UN);
    fclose($lock_file);
    exit;
}

$info = json_decode(file_get_contents($info_path), true);
$raw_users = json_decode(file_get_contents($users_path), true);

if (!is_array($info) || !is_array($raw_users) || count($raw_users) === 0) {
    @unlink($users_path);
    @unlink($info_path);
    flock($lock_file, LOCK_UN);
    fclose($lock_file);
    exit;
}

// نرمال‌سازی آیدی‌ها (چه آرایه آبجکت باشد چه آیدی خام)
$userid = [];
foreach ($raw_users as $u) {
    $uid = is_array($u) ? ($u['id'] ?? null) : (is_object($u) ? ($u->id ?? null) : $u);
    if (!empty($uid)) {
        $userid[] = $uid;
    }
}
$userid = array_values(array_unique($userid));

if (count($userid) === 0) {
    @unlink($users_path);
    @unlink($info_path);
    flock($lock_file, LOCK_UN);
    fclose($lock_file);
    exit;
}

if (!isset($info['count_success'])) $info['count_success'] = 0;
if (!isset($info['count_blocked'])) $info['count_blocked'] = 0;

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

$keyboardbuy = json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_sell'], 'callback_data' => 'buy']]]]);
$keyboardstart = json_encode(['inline_keyboard' => [[['text' => "شروع", 'callback_data' => 'start']]]]);
$keyboardusertest = json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_usertest'], 'callback_data' => 'usertestbtn']]]]);
$keyboardhelpbtn = json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_help'], 'callback_data' => 'helpbtn']]]]);
$keyboardaffiliates = json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_affiliates'], 'callback_data' => 'affiliatesbtn']]]]);
$keyboardaddbalance = json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_Add_Balance'], 'callback_data' => 'Add_Balance']]]]);

$cancelmessage = json_encode([
    'inline_keyboard' => [
        [['text' => "❌ لغو عملیات", 'callback_data' => 'cancel_sendmessage']]
    ]
]);

// در هر نوبت کرون (هر ۱ دقیقه) حداکثر ۱۰۰ پیام ارسال می‌شود
$batch_size = min(100, count($userid));
$current_batch = array_slice($userid, 0, $batch_size);

foreach ($current_batch as $target_chat_id) {
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

        if (is_array($meesage) && isset($meesage['ok']) && $meesage['ok'] === true) {
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

        if (is_array($meesage) && isset($meesage['ok']) && $meesage['ok'] === true) {
            $info['count_success']++;
            if (isset($info['pingmessage']) && $info['pingmessage'] == "yes") {
                pinmessage($target_chat_id, $meesage['result']['message_id']);
            }
        } else {
            $info['count_blocked']++;
        }
    }

    usleep(35000); // رعایت محدودیت نرخ تلگرام (~30 درخواست در ثانیه)
}

// حذف اعضای پردازش‌شده
array_splice($userid, 0, $batch_size);
$count_remein = count($userid);

if ($count_remein > 0) {
    file_put_contents($users_path, json_encode(array_values($userid), JSON_UNESCAPED_UNICODE));
    file_put_contents($info_path, json_encode($info, JSON_UNESCAPED_UNICODE));

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

flock($lock_file, LOCK_UN);
fclose($lock_file);
exit;