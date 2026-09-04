<?php
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';

set_time_limit(0);
ini_set('memory_limit', '256M');

$info_path = __DIR__ . '/info';
$users_path = __DIR__ . '/users.json';
$trace_log_path = __DIR__ . '/sender_trace.log';

// تابع لاگ‌گیری ساختاریافته برای ردیابی منشا و روند اجرا
function record_trace($action, $data = []) {
    global $trace_log_path;
    $pid = getmypid();
    $ppid = function_exists('posix_getppid') ? posix_getppid() : 'N/A';
    $sapi = php_sapi_name();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI/LOCAL';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'NONE';
    $time = date('Y-m-d H:i:s');
    
    $payload = !empty($data) ? ' | DATA: ' . json_encode($data, JSON_UNESCAPED_UNICODE) : '';
    $line = "[{$time}] [PID:{$pid}|PPID:{$ppid}] [SAPI:{$sapi}] [IP:{$ip}] [UA:{$ua}] {$action}{$payload}\n";
    @file_put_contents($trace_log_path, $line, FILE_APPEND);
}

// ثبت ورود به اسکریپت در لحظه اجرا
record_trace("SCRIPT_LAUNCHED");

while (true) {
    clearstatcache();
    if (is_file($info_path) && is_file($users_path)) {
        $info = json_decode(file_get_contents($info_path), true);
        $userid = json_decode(file_get_contents($users_path), true);

        if (!is_array($info)) {
            record_trace("INFO_FILE_INVALID");
            sleep(2);
            continue;
        }

        if (!isset($info['count_success'])) $info['count_success'] = 0;
        if (!isset($info['count_blocked'])) $info['count_blocked'] = 0;

        if (!is_array($userid) || count($userid) == 0) {
            record_trace("BATCH_CYCLE_EMPTY_OR_DONE");
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
                record_trace("FINAL_REPORT_SENT_AND_CLEANUP", [
                    'admin_id' => $info['id_admin'],
                    'success' => $count_success,
                    'blocked' => $count_blocked
                ]);
                @unlink($users_path);
                @unlink($info_path);
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

        $keyboardbuy = json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_sell'], 'callback_data' => 'buy', 'style' => 'primary', 'icon_custom_emoji_id' => 5258236805890710909]]]]);
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

        $count_remein = count($userid);
        $textprocces = "✏️ عملیات ارسال پیام درحال انجام می‌باشد...\n\n" .
                       "👥 تعداد نفرات باقی‌مانده: <b>{$count_remein}</b>\n" .
                       "✅ ارسال موفق: <b>{$info['count_success']}</b>\n" .
                       "🚫 ناموفق: <b>{$info['count_blocked']}</b>";

        if (isset($info['id_admin']) && isset($info['id_message'])) {
            telegram('editMessageText', [
                'chat_id' => $info['id_admin'],
                'message_id' => $info['id_message'],
                'text' => $textprocces,
                'parse_mode' => 'HTML',
                'reply_markup' => $cancelmessage
            ]);
        }

        $batch_size = min(200, $count_remein);
        $current_batch = array_slice($userid, 0, $batch_size);

        record_trace("START_BATCH_PROCESSING", [
            'batch_size' => $batch_size,
            'remaining_before_batch' => $count_remein
        ]);

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

                $is_ok = false;
                if (is_array($meesage) && isset($meesage['ok']) && $meesage['ok'] == true) {
                    $is_ok = true;
                }

                if ($is_ok) {
                    $info['count_success']++;
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

                record_trace("DISPATCH_USER", [
                    'user_id' => $target_chat_id,
                    'status' => $is_ok ? 'SUCCESS' : 'FAILED',
                    'telegram_response' => $meesage
                ]);

                if ($is_ok && isset($info['pingmessage']) && $info['pingmessage'] == "yes") {
                    pinmessage($target_chat_id, $meesage['result']['message_id']);
                }
            } elseif ($info['type'] == "forwardmessage") {
                $meesage = forwardMessage($info['id_admin'], $info['message'], $target_chat_id);

                $is_ok = false;
                if (is_array($meesage) && isset($meesage['ok']) && $meesage['ok'] == true) {
                    $is_ok = true;
                }

                if ($is_ok) {
                    $info['count_success']++;
                } else {
                    $info['count_blocked']++;
                }

                record_trace("DISPATCH_FORWARD", [
                    'user_id' => $target_chat_id,
                    'status' => $is_ok ? 'SUCCESS' : 'FAILED',
                    'telegram_response' => $meesage
                ]);

                if ($is_ok && isset($info['pingmessage']) && $info['pingmessage'] == "yes") {
                    pinmessage($target_chat_id, $meesage['result']['message_id']);
                }
            }

            usleep(50000);
        }

        array_splice($userid, 0, $batch_size);

        if (count($userid) > 0) {
            file_put_contents($users_path, json_encode(array_values($userid), JSON_UNESCAPED_UNICODE));
            file_put_contents($info_path, json_encode($info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            record_trace("SAVED_PROGRESS", ['remaining_count' => count($userid)]);
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
            record_trace("ALL_USERS_FINISHED_FILES_REMOVED");
        }
    } else {
        sleep(2);
    }
}