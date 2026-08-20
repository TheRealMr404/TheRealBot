<?php
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';

set_time_limit(0);
ini_set('memory_limit', '256M');

$info_path = __DIR__ . '/info';
$users_path = __DIR__ . '/users.json';

while (true) {
    if (is_file($info_path) && is_file($users_path)) {
        $info = json_decode(file_get_contents($info_path), true);
        $userid = json_decode(file_get_contents($users_path), true);

        if (!is_array($userid) || count($userid) == 0) {
            if (isset($info['id_admin'])) {
                deletemessage($info['id_admin'], $info['id_message']);
                sendmessage($info['id_admin'], "📌 عملیات برای تمامی کاربران درخواستی انجام شد.", null, 'HTML');
                @unlink($info_path);
                @unlink($users_path);
            }
            sleep(2);
            continue;
        }

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

        $count_remein = count($userid);
        $textprocces = "✏️ عملیات ارسال پیام درحال انجام می‌باشد...\n\n👥 تعداد نفرات باقی‌مانده: <b>$count_remein</b>";
        Editmessagetext($info['id_admin'], $info['id_message'], $textprocces, $cancelmessage);

        $batch_size = min(150, $count_remein);
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

                if (isset($meesage['ok']) && $meesage['ok'] === false && isset($meesage['description']) && strpos($meesage['description'], 'blocked by the user') !== false) {
                    $invoicecount = select("invoice", "*", "id_user", $target_chat_id, "count");
                    $userinfo = select("user", "Balance", "id", $target_chat_id, "select");
                    if ($invoicecount == 0 && isset($userinfo['Balance']) && $userinfo['Balance'] == 0) {
                        $stmt = $pdo->prepare("DELETE FROM user WHERE id = :uid");
                        $stmt->execute([':uid' => $target_chat_id]);
                    }
                }

                if (isset($meesage['ok']) && $meesage['ok'] && isset($info['pingmessage']) && $info['pingmessage'] == "yes") {
                    pinmessage($target_chat_id, $meesage['result']['message_id']);
                }
            } elseif ($info['type'] == "forwardmessage") {
                $meesage = forwardMessage($info['id_admin'], $info['message'], $target_chat_id);
                if (isset($meesage['ok']) && $meesage['ok'] && isset($info['pingmessage']) && $info['pingmessage'] == "yes") {
                    pinmessage($target_chat_id, $meesage['result']['message_id']);
                }
            }

            usleep(40000);
        }

        array_splice($userid, 0, $batch_size);
        file_put_contents($users_path, json_encode(array_values($userid), JSON_UNESCAPED_UNICODE));
    } else {
        sleep(2);
    }
}