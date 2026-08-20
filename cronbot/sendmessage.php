<?php
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';


function autoSetupCronJob() {
    $current_file = realpath(__FILE__);
    $php_bin = PHP_BINARY ? PHP_BINARY : '/usr/bin/php';
    
    $cron_command = "* * * * * {$php_bin} {$current_file} >/dev/null 2>&1";

    if (!function_exists('shell_exec') && !function_exists('exec')) {
        return false;
    }

    $current_cron = @shell_exec('crontab -l 2>/dev/null');
    
    if ($current_cron !== null && strpos($current_cron, $current_file) !== false) {
        return true;
    }

    $new_cron = trim((string)$current_cron);
    if (!empty($new_cron)) {
        $new_cron .= "\n";
    }
    $new_cron .= $cron_command . "\n";

    $tmp_file = tempnam(sys_get_temp_dir(), 'cron_');
    file_put_contents($tmp_file, $new_cron);

    @exec("crontab " . escapeshellarg($tmp_file));
    @unlink($tmp_file);

    return true;
}

autoSetupCronJob();

set_time_limit(120);
ini_set('max_execution_time', 120);

$datatextbotget = select("textbot", "*", null, null, "fetchAll");
$datatxtbot = array();
if (is_array($datatextbotget)) {
    foreach ($datatextbotget as $row) {
        $datatxtbot[] = array(
            'id_text' => $row['id_text'],
            'text' => $row['text']
        );
    }
}

$datatextbot = array(
    'text_usertest' => '',
    'text_support' => '',
    'text_help' => '',
    'text_sell' => '',
    'text_affiliates' => '',
    'text_Add_Balance' => ''
);

foreach ($datatxtbot as $item) {
    if (isset($datatextbot[$item['id_text']])) {
        $datatextbot[$item['id_text']] = $item['text'];
    }
}

if (!is_file('info') || !is_file('users.json')) return;

$userid = json_decode(file_get_contents('users.json'));
$info = json_decode(file_get_contents('info'), true);

if (!is_array($userid) || count($userid) == 0) {
    if (isset($info['id_admin'])) {
        deletemessage($info['id_admin'], $info['id_message']);
        sendmessage($info['id_admin'], "📌 عملیات برای تمامی کاربران درخواستی انجام شد.", null, 'HTML');
        @unlink('info');
        @unlink('users.json');
    }
    return;
}

$count_remein = count($userid);
$textprocces = "✏️ عملیات ارسال پیام درحال انجام می باشد...\n\nتعداد نفرات باقی مانده : $count_remein";
$cancelmessage = json_encode([
    'inline_keyboard' => [
        [
            ['text' => "لغو عملیات", 'callback_data' => 'cancel_sendmessage'],
        ],
    ]
]);
Editmessagetext($info['id_admin'], $info['id_message'], $textprocces, $cancelmessage);

$keyboardbuy = json_encode([
    'inline_keyboard' => [
        [['text' => $datatextbot['text_sell'], 'callback_data' => 'buy']]
    ]
]);
$keyboardstart = json_encode([
    'inline_keyboard' => [
        [['text' => "شروع", 'callback_data' => 'start']]
    ]
]);
$keyboardusertest = json_encode([
    'inline_keyboard' => [
        [['text' => $datatextbot['text_usertest'], 'callback_data' => 'usertestbtn']]
    ]
]);
$keyboardhelpbtn = json_encode([
    'inline_keyboard' => [
        [['text' => $datatextbot['text_help'], 'callback_data' => 'helpbtn']]
    ]
]);
$keyboardaffiliates = json_encode([
    'inline_keyboard' => [
        [['text' => $datatextbot['text_affiliates'], 'callback_data' => 'affiliatesbtn']]
    ]
]);
$keyboardaddbalance = json_encode([
    'inline_keyboard' => [
        [['text' => $datatextbot['text_Add_Balance'], 'callback_data' => 'Add_Balance']]
    ]
]);

$batch_size = min(150, count($userid));

for ($i = 0; $i < $batch_size; $i++) {
    if (!isset($userid[$i])) break;
    
    $iduser = $userid[$i];
    $target_chat_id = is_object($iduser) ? $iduser->id : (is_array($iduser) ? $iduser['id'] : $iduser);

    $meesage = null;

    if ($info['type'] == "unpinmessage") {
        unpinmessage($target_chat_id);
    } elseif ($info['type'] == "sendmessage" || $info['type'] == "xdaynotmessage") {
        if ($info['btnmessage'] == "none") {
            $meesage = sendmessage($target_chat_id, $info['message'], null, 'HTML');
        } elseif ($info['btnmessage'] == "buy") {
            $meesage = sendmessage($target_chat_id, $info['message'], $keyboardbuy, 'HTML');
        } elseif ($info['btnmessage'] == "start") {
            $meesage = sendmessage($target_chat_id, $info['message'], $keyboardstart, 'HTML');
        } elseif ($info['btnmessage'] == "usertestbtn") {
            $meesage = sendmessage($target_chat_id, $info['message'], $keyboardusertest, 'HTML');
        } elseif ($info['btnmessage'] == "helpbtn") {
            $meesage = sendmessage($target_chat_id, $info['message'], $keyboardhelpbtn, 'HTML');
        } elseif ($info['btnmessage'] == "affiliatesbtn") {
            $meesage = sendmessage($target_chat_id, $info['message'], $keyboardaffiliates, 'HTML');
        } elseif ($info['btnmessage'] == "addbalance") {
            $meesage = sendmessage($target_chat_id, $info['message'], $keyboardaddbalance, 'HTML');
        }

        if (isset($meesage['ok']) && $meesage['ok'] == false && isset($meesage['description']) && $meesage['description'] == "Forbidden: bot was blocked by the user") {
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

    unset($userid[$i]);
    
    usleep(40000); 
}

$userid = array_values($userid);
file_put_contents('users.json', json_encode($userid, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));