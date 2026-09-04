<?php
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';

$datatextbotget = select("textbot", "*", null, null, "fetchAll");
$datatxtbot = array();
foreach ($datatextbotget as $row) {
    $datatxtbot[] = array(
        'id_text' => $row['id_text'],
        'text' => $row['text']
    );
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
if (!is_file('info')) return;
if (!is_file('users.json')) return;

$userid = json_decode(file_get_contents('users.json'));
if (is_file('info')) {
    $info = json_decode(file_get_contents('info'), true);
}
$count = 0;
if (count($userid) == 0) {
    if (isset($info['id_admin'])) {
        deletemessage($info['id_admin'], $info['id_message']);
        sendmessage($info['id_admin'], "📌 عملیات برای تمامی کاربران درخواستی انجام شد.", null, 'HTML');
        unlink('info');
        unlink('users.json');
    }
    return;
}
$count_remein = count($userid);
$textprocces = "✏️ عملیات ارسال پیام درحال انجام می باشد...

تعداد نفرات باقی مانده :  $count_remein";
$cancelmessage = json_encode([
    'inline_keyboard' => [
        [
            ['text' => "لغو عملیات", 'callback_data' => 'cancel_sendmessage', 'style' => 'danger'],
        ],
    ]
]);
Editmessagetext($info['id_admin'], $info['id_message'], $textprocces, $cancelmessage);

// دکمه‌ها همراه با استایل رنگی و آیکون پرمیوم تلگرام
$keyboardbuy = json_encode([
    'inline_keyboard' => [
        [
            ['text' => $datatextbot['text_sell'], 'callback_data' => 'buy', 'style' => 'primary', 'icon_custom_emoji_id' => 5258236805890710909],
        ],
    ]
]);
$keyboardstart = json_encode([
    'inline_keyboard' => [
        [
            ['text' => "شروع", 'callback_data' => 'start', 'style' => 'primary'],
        ],
    ]
]);
$keyboardusertest = json_encode([
    'inline_keyboard' => [
        [
            ['text' => $datatextbot['text_usertest'], 'callback_data' => 'usertestbtn', 'style' => 'primary'],
        ],
    ]
]);
$keyboardhelpbtn = json_encode([
    'inline_keyboard' => [
        [
            ['text' => $datatextbot['text_help'], 'callback_data' => 'helpbtn', 'style' => 'primary'],
        ],
    ]
]);
$keyboardaffiliates = json_encode([
    'inline_keyboard' => [
        [
            ['text' => $datatextbot['text_affiliates'], 'callback_data' => 'affiliatesbtn', 'style' => 'primary'],
        ],
    ]
]);
$keyboardaddbalance = json_encode([
    'inline_keyboard' => [
        [
            ['text' => $datatextbot['text_Add_Balance'], 'callback_data' => 'Add_Balance', 'style' => 'success'],
        ],
    ]
]);

for ($i = 0; $i < 20; $i++) {
    if (!isset($userid[$i])) break;
    $iduser = $userid[$i];
    unset($userid[$i]);
    $userid = array_values($userid);

    if ($info['type'] == "unpinmessage") {
        unpinmessage($iduser->id);
    } elseif ($info['type'] == "sendmessage" or $info['type'] == "xdaynotmessage") {
        if ($info['btnmessage'] == "none") {
            $meesage = sendmessage($iduser->id, $info['message'], null, 'HTML');
        } elseif ($info['btnmessage'] == "buy") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardbuy, 'HTML');
        } elseif ($info['btnmessage'] == "start") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardstart, 'HTML');
        } elseif ($info['btnmessage'] == "usertestbtn") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardusertest, 'HTML');
        } elseif ($info['btnmessage'] == "helpbtn") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardhelpbtn, 'HTML');
        } elseif ($info['btnmessage'] == "affiliatesbtn") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardaffiliates, 'HTML');
        } elseif ($info['btnmessage'] == "addbalance") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardaddbalance, 'HTML');
        }

        if (isset($meesage['ok']) && $meesage['ok'] == false and ($meesage['description'] ?? '') == "Forbidden: bot was blocked by the user") {
            $invoicecount = select("invoice", "*", "id_user", $iduser->id, "count");
            $userinfo = select("user", "Balance", "id", $iduser->id, "select");
            if ($invoicecount == 0 and $userinfo['Balance'] == 0) {
                $Id_user = $iduser->id;
                $stmt = $pdo->prepare("DELETE FROM user WHERE id = '$Id_user'");
                $stmt->execute();
            }
        }

        if (!empty($meesage['ok']) and isset($info['pingmessage']) && $info['pingmessage'] == "yes") {
            pinmessage($iduser->id, $meesage['result']['message_id']);
        }
    } elseif ($info['type'] == "forwardmessage") {
        $meesage = forwardMessage($info['id_admin'], $info['message'], $iduser->id);
        if (!empty($meesage['ok']) and isset($info['pingmessage']) && $info['pingmessage'] == "yes") {
            pinmessage($iduser->id, $meesage['result']['message_id']);
        }
    }
}

file_put_contents('users.json', json_encode($userid, true));