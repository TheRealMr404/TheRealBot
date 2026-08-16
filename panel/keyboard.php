<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

$method = $_SERVER['REQUEST_METHOD'];

// ۱. دریافت تنظیمات فعلی بدون وابستگی به ستون id
$stmt_get = $pdo->query("SELECT * FROM setting LIMIT 1");
$current_setting = $stmt_get->fetch(PDO::FETCH_ASSOC) ?: [];

if ($method == "POST") {
    $raw_payload = file_get_contents("php://input");
    $keyboard = json_decode($raw_payload, true);

    if (is_array($keyboard)) {
        $old_data = json_decode($current_setting['keyboardmain'] ?? '{}', true);

        // استخراج و نگاشت ویژگی‌های هر دکمه (استایل و ایموجی پرمیوم)
        $button_props_map = [];
        if (!empty($old_data['keyboard']) && is_array($old_data['keyboard'])) {
            foreach ($old_data['keyboard'] as $row) {
                if (is_array($row)) {
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
        }

        // الصاق مجدد استایل و ایموجی‌ها به چینش جدید
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

        // ذخیره در جدول setting
        $keyboardmain = ['keyboard' => $keyboard];
        $json_data = json_encode($keyboardmain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        update("setting", "keyboardmain", $json_data, null, null);

        header('Content-Type: application/json');
        echo json_encode(['status' => true, 'message' => 'کیبورد با موفقیت ذخیره شد']);
        exit;
    }
}

$action = filter_input(INPUT_GET, 'action');
if ($action === "reaset") {
    $default_keyboard = '{"keyboard":[[{"text":"text_sell"},{"text":"text_extend"}],[{"text":"text_usertest"},{"text":"text_wheel_luck"}],[{"text":"text_Purchased_services"},{"text":"accountwallet"}],[{"text":"text_affiliates"},{"text":"text_Tariff_list"}],[{"text":"text_support"},{"text":"text_help"}]]}';
    update("setting", "keyboardmain", $default_keyboard, null, null);
    header('Location: keyboard.php');
    exit;
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= $textbotlang['panel']['keyboardManageTitle'] ?? 'مدیریت کیبورد' ?></title>

    <script type="module" crossorigin src="js/sort_keyboard.js"></script>
    <link rel="stylesheet" crossorigin href="css/sort_keyboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap');

        * {
            font-family: 'Vazirmatn', system-ui, -apple-system, sans-serif !important;
        }

        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* نوار ابزار بالای صفحه با افکت شیشه‌ای (Glassmorphism) */
        .top-navbar {
            position: sticky;
            top: 0;
            z-index: 999;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            background: rgba(20, 25, 40, 0.75);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 14px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
        }

        .nav-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 1.15rem;
            color: #f1f5f9;
        }

        .nav-title i {
            color: #60a5fa;
            font-size: 1.25rem;
        }

        .nav-actions {
            display: flex;
            gap: 10px;
        }

        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }

        .nav-btn-back {
            background: rgba(255, 255, 255, 0.06);
            color: #cbd5e1;
            border-color: rgba(255, 255, 255, 0.1);
        }

        .nav-btn-back:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
            transform: translateY(-1px);
        }

        .nav-btn-reset {
            background: rgba(239, 68, 68, 0.12);
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.25);
        }

        .nav-btn-reset:hover {
            background: rgba(239, 68, 68, 0.22);
            color: #fca5a5;
            transform: translateY(-1px);
        }

        /* ساختار ریشه اپلیکیشن React */
        #root {
            flex: 1;
            padding: 35px 20px;
            max-width: 900px;
            width: 100%;
            margin: 0 auto;
        }
    </style>
</head>

<body>
    <header class="top-navbar">
        <div class="nav-title">
            <i class="fa-solid fa-layer-group"></i>
            <span><?= $textbotlang['panel']['keyboardManageTitle'] ?? 'طراحی و چیدمان کیبورد شیشه‌ای' ?></span>
        </div>
        <div class="nav-actions">
            <a class="nav-btn nav-btn-reset" href="keyboard.php?action=reaset" onclick="return confirm('آیا از بازنشانی چینش کیبورد به حالت اولیه مطمئن هستید؟')">
                <i class="fa-solid fa-rotate-left"></i>
                <span><?= $textbotlang['panel']['keyboardSaveBtn'] ?? 'تنظیمات اولیه' ?></span>
            </a>
            <a class="nav-btn nav-btn-back" href="index.php">
                <i class="fa-solid fa-arrow-right"></i>
                <span><?= $textbotlang['panel']['keyboardSortHint'] ?? 'بازگشت به پنل' ?></span>
            </a>
        </div>
    </header>

    <div id="root"></div>
</body>

</html>