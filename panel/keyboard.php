<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

$keyboard = json_decode(file_get_contents("php://input"), true);
$method = $_SERVER['REQUEST_METHOD'];

if ($method == "POST" && is_array($keyboard)) {
    // ۱. خواندن تنظیمات قبلی از دیتابیس
    $current_setting = select("setting", "*", "id", "1", "select");
    $old_data = json_decode($current_setting['keyboardmain'] ?? '{}', true);

    // ۲. نگاشت خصوصیات هر دکمه (استایل و ایموجی پرمیوم)
    $button_props_map = [];
    if (!empty($old_data['keyboard']) && is_array($old_data['keyboard'])) {
        foreach ($old_data['keyboard'] as $row) {
            foreach ($row as $btn) {
                if (!empty($btn['text'])) {
                    $button_props_map[$btn['text']] = [
                        'style' => $btn['style'] ?? null,
                        'icon_custom_emoji_id' => $btn['icon_custom_emoji_id'] ?? null
                    ];
                }
            }
        }
    }

    // ۳. بازگرداندن استایل و ایموجی به چینش جدید ارسالی از وب
    foreach ($keyboard as &$row) {
        if (is_array($row)) {
            foreach ($row as &$btn) {
                $btn_key = $btn['text'] ?? '';
                if (isset($button_props_map[$btn_key])) {
                    if (empty($btn['style']) && !empty($button_props_map[$btn_key]['style'])) {
                        $btn['style'] = $button_props_map[$btn_key]['style'];
                    }
                    if (empty($btn['icon_custom_emoji_id']) && !empty($button_props_map[$btn_key]['icon_custom_emoji_id'])) {
                        $btn['icon_custom_emoji_id'] = $button_props_map[$btn_key]['icon_custom_emoji_id'];
                    }
                }
            }
        }
    }
    unset($row);
    unset($btn);

    // ۴. ذخیره ساختار با حفظ تمام ویژگی‌ها در دیتابیس
    $keyboardmain = ['keyboard' => $keyboard];
    update("setting", "keyboardmain", json_encode($keyboardmain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null, null);

    // ۵. ارسال پاسخ تایید به فرانت‌اند React و خروج
    header('Content-Type: application/json');
    echo json_encode(['status' => true, 'message' => 'کیبورد با موفقیت ذخیره شد']);
    exit;
} else {
    $keyboardmain = '{"keyboard":[[{"text":"text_sell"},{"text":"text_extend"}],[{"text":"text_usertest"},{"text":"text_wheel_luck"}],[{"text":"text_Purchased_services"},{"text":"accountwallet"}],[{"text":"text_affiliates"},{"text":"text_Tariff_list"}],[{"text":"text_support"},{"text":"text_help"}]]}';
    $action = filter_input(INPUT_GET, 'action');
    if ($action === "reaset") {
        update("setting", "keyboardmain", $keyboardmain, null, null);
        header('Location: keyboard.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="FA">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= $textbotlang['panel']['keyboardManageTitle'] ?></title>

    <script type="module" crossorigin src="js/sort_keyboard.js"></script>
    <link rel="stylesheet" crossorigin href="css/sort_keyboard.css">
    <style>
        @import url(https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap);

        * {
            font-family: 'Vazirmatn' !important;
        }

        button {
            font-family: yekan;
        }

        .btnback {
            position: fixed;
            top: 10px;
            left: 10px;
            padding: 7px;
            background-color: #3d3d3d;
            color: #fff;
            border-radius: 6px;
            font-family: yekan;
            font-size: 13px;
            font-weight: bold;
            text-decoration: none;
            z-index: 9999;
        }

        .btndefult {
            position: fixed;
            top: 10px;
            left: 150px;
            padding: 7px;
            background-color: #fff;
            border: 2px solid #3d3d3d;
            color: #3d3d3d;
            border-radius: 6px;
            font-family: yekan;
            font-size: 13px;
            font-weight: bold;
            text-decoration: none;
            z-index: 9999;
        }
    </style>
</head>

<body>
    <a class="btnback" href="index.php"><?= $textbotlang['panel']['keyboardSortHint'] ?></a>
    <a class="btndefult" href="keyboard.php?action=reaset"><?= $textbotlang['panel']['keyboardSaveBtn'] ?></a>
    <div id="root"></div>
</body>

</html>