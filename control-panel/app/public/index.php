<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
panel_require_auth();

$pdo = panel_db();
$bots = $pdo->query("SELECT * FROM bots WHERE deleted_at IS NULL ORDER BY id DESC")->fetchAll();
$operations = $pdo->query("SELECT o.*,b.slug,b.customer_name FROM operations o LEFT JOIN bots b ON b.id=o.bot_id ORDER BY o.id DESC LIMIT 12")->fetchAll();
$audit = $pdo->query("SELECT a.*,b.slug FROM audit_log a LEFT JOIN bots b ON b.id=a.bot_id ORDER BY a.id DESC LIMIT 12")->fetchAll();
$liveBySlug = [];
$agentOnline = false;
try {
    $live = panel_agent(['action' => 'list']);
    $agentOnline = !empty($live['ok']);
    foreach (($live['bots'] ?? []) as $row) {
        if (isset($row['slug'])) {
            $liveBySlug[(string) $row['slug']] = $row;
        }
    }
} catch (Throwable $e) {
    error_log('Mirza panel agent: ' . $e->getMessage());
}

$now = time();
$active = 0;
$expiring = 0;
$expired = 0;
foreach ($bots as &$bot) {
    $live = $liveBySlug[$bot['slug']] ?? null;
    if ($live) {
        $bot['live_status'] = $live['status'] ?? $bot['live_status'];
    }
    $expiryTs = strtotime((string) $bot['expires_at']) ?: 0;
    $bot['remaining_days'] = (int) floor(($expiryTs - $now) / 86400);
    if ($bot['status'] === 'active') {
        $active++;
    }
    if ($expiryTs > $now && $expiryTs <= ($now + 7 * 86400)) {
        $expiring++;
    }
    if ($bot['status'] === 'expired' || ($expiryTs > 0 && $expiryTs <= $now)) {
        $expired++;
    }
}
unset($bot);

function cp_badge(string $status): string
{
    $class = match ($status) {
        'active', 'healthy', 'running', 'success' => 'good',
        'provisioning', 'queued', 'working' => 'wait',
        'expired', 'error', 'unhealthy', 'failed' => 'bad',
        default => 'muted',
    };
    return '<span class="status status-' . $class . '"><i></i>' . panel_h(panel_fa_status($status)) . '</span>';
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>مرکز مدیریت ربات‌ها</title>
    <link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body data-csrf="<?= panel_h(panel_csrf()) ?>">
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="side-brand">
            <div class="brand-mark"><?= panel_icon('bot', 24) ?></div>
            <div><strong>Mirza Control</strong><span>مدیریت ربات‌ها</span></div>
        </div>
        <nav class="side-nav" aria-label="منوی مدیریت">
            <button class="nav-item is-active" data-view-target="dashboard"><?= panel_icon('grid') ?><span>داشبورد</span></button>
            <button class="nav-item" data-view-target="bots"><?= panel_icon('bot') ?><span>ربات‌ها</span><b><?= count($bots) ?></b></button>
            <button class="nav-item" data-view-target="operations"><?= panel_icon('activity') ?><span>عملیات</span></button>
            <button class="nav-item" data-view-target="backups"><?= panel_icon('database') ?><span>بکاپ‌ها</span></button>
            <button class="nav-item" data-view-target="settings"><?= panel_icon('settings') ?><span>تنظیمات</span></button>
        </nav>
        <div class="side-status">
            <div><i class="<?= $agentOnline ? 'online' : 'offline' ?>"></i><span>سرویس مدیریت</span></div>
            <strong><?= $agentOnline ? 'متصل' : 'قطع' ?></strong>
        </div>
        <a class="nav-item logout-link" href="logout.php"><?= panel_icon('logout') ?><span>خروج امن</span></a>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <button class="icon-btn mobile-menu" id="mobileMenu" aria-label="باز کردن منو"><?= panel_icon('menu') ?></button>
            <div class="page-title"><h1 id="pageTitle">داشبورد</h1><p>نمای زنده نصب‌ها و مشتری‌ها</p></div>
            <div class="top-actions">
                <button class="icon-btn" id="refreshAll" title="تازه‌سازی وضعیت"><?= panel_icon('refresh') ?></button>
                <button class="btn btn-primary" data-open-modal="createBot"><?= panel_icon('plus', 17) ?>ربات جدید</button>
            </div>
        </header>

        <section class="view is-visible" data-view="dashboard">
            <div class="agent-alert <?= $agentOnline ? 'is-hidden' : '' ?>">
                <?= panel_icon('alert', 18) ?>
                <div><strong>Agent مدیریت در دسترس نیست</strong><span>نمایش اطلاعات ممکن است قدیمی باشد. وضعیت سرویس mirza-panel-agent را بررسی کنید.</span></div>
            </div>
            <div class="metrics-grid">
                <article class="metric"><span>کل ربات‌ها</span><strong><?= count($bots) ?></strong><small>نصب ثبت‌شده</small><?= panel_icon('bot', 23) ?></article>
                <article class="metric"><span>فعال</span><strong><?= $active ?></strong><small>در حال سرویس‌دهی</small><?= panel_icon('activity', 23) ?></article>
                <article class="metric"><span>رو به اتمام</span><strong><?= $expiring ?></strong><small>کمتر از ۷ روز</small><?= panel_icon('clock', 23) ?></article>
                <article class="metric"><span>منقضی</span><strong><?= $expired ?></strong><small>متوقف‌شده خودکار</small><?= panel_icon('pause', 23) ?></article>
            </div>

            <div class="section-head"><div><h2>مدیریت سریع</h2><p>وضعیت مهم‌ترین نصب‌ها</p></div><button class="text-btn" data-view-target="bots">مشاهده همه</button></div>
            <div class="bot-table-wrap">
                <table class="data-table <?= !$bots ? 'empty-table' : '' ?>" id="dashboardBots">
                    <thead><tr><th>مشتری</th><th>ربات</th><th>وضعیت</th><th>اعتبار</th><th>دامنه</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($bots, 0, 6) as $bot): ?>
                        <tr data-bot-row data-search="<?= panel_h(mb_strtolower($bot['customer_name'] . ' ' . $bot['slug'] . ' ' . $bot['bot_username'] . ' ' . $bot['domain'])) ?>">
                            <td><div class="customer-cell"><span><?= panel_h(mb_substr($bot['customer_name'], 0, 1)) ?></span><div><strong><?= panel_h($bot['customer_name']) ?></strong><small><?= panel_h($bot['customer_phone'] ?: 'بدون شماره تماس') ?></small></div></div></td>
                            <td><div class="mono-stack"><strong dir="ltr">@<?= panel_h($bot['bot_username']) ?></strong><small dir="ltr"><?= panel_h($bot['slug']) ?></small></div></td>
                            <td><?= cp_badge((string) $bot['status']) ?><small class="live-state"><?= panel_h(panel_fa_status((string) $bot['live_status'])) ?></small></td>
                            <td><div class="expiry"><strong><?= panel_h(date('Y/m/d', strtotime($bot['expires_at']) ?: time())) ?></strong><small class="<?= $bot['remaining_days'] < 7 ? 'text-danger' : '' ?>"><?= $bot['remaining_days'] >= 0 ? panel_h((string) $bot['remaining_days']) . ' روز مانده' : 'پایان یافته' ?></small></div></td>
                            <td><a class="domain-link" href="https://<?= panel_h($bot['domain']) ?>" target="_blank" rel="noopener noreferrer" dir="ltr"><?= panel_h($bot['domain']) ?><?= panel_icon('external', 13) ?></a></td>
                            <td><button class="icon-btn" data-bot-details='<?= panel_h(json_encode($bot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>' aria-label="مدیریت ربات"><?= panel_icon('more') ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$bots): ?><tr><td colspan="6"><div class="empty-state"><?= panel_icon('bot', 28) ?><strong>هنوز رباتی ساخته نشده</strong><span>اولین ربات مشتری را از دکمه «ربات جدید» اضافه کنید.</span></div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="dashboard-split">
                <section class="activity-panel">
                    <div class="section-head compact"><div><h2>عملیات اخیر</h2><p>صف نصب و نگهداری</p></div></div>
                    <div class="timeline">
                        <?php foreach (array_slice($operations, 0, 6) as $op): ?>
                            <div class="timeline-item"><i class="op-<?= panel_h($op['status']) ?>"></i><div><strong><?= panel_h($op['customer_name'] ?: $op['slug'] ?: 'سیستم') ?></strong><span><?= panel_h(panel_fa_status($op['operation'])) ?> · <?= panel_h(panel_fa_status($op['status'])) ?></span></div><time><?= panel_h(date('H:i', strtotime($op['created_at']) ?: time())) ?></time></div>
                        <?php endforeach; ?>
                        <?php if (!$operations): ?><div class="quiet-empty">عملیاتی ثبت نشده است.</div><?php endif; ?>
                    </div>
                </section>
                <section class="expiry-panel">
                    <div class="section-head compact"><div><h2>سررسیدهای نزدیک</h2><p>نیازمند پیگیری</p></div></div>
                    <div class="expiry-list">
                        <?php $near = array_values(array_filter($bots, static fn($b) => $b['remaining_days'] >= 0 && $b['remaining_days'] <= 14)); ?>
                        <?php foreach (array_slice($near, 0, 6) as $bot): ?>
                            <button data-bot-details='<?= panel_h(json_encode($bot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'><span><?= panel_h($bot['customer_name']) ?><small dir="ltr"><?= panel_h($bot['slug']) ?></small></span><strong><?= panel_h((string) $bot['remaining_days']) ?> روز</strong></button>
                        <?php endforeach; ?>
                        <?php if (!$near): ?><div class="quiet-empty">تا ۱۴ روز آینده سررسیدی وجود ندارد.</div><?php endif; ?>
                    </div>
                </section>
            </div>
        </section>

        <section class="view" data-view="bots">
            <div class="toolbar">
                <label class="search-box"><?= panel_icon('search', 17) ?><input id="botSearch" type="search" placeholder="جست‌وجوی مشتری، ربات یا دامنه"></label>
                <select id="statusFilter" class="select-compact" aria-label="فیلتر وضعیت"><option value="">همه وضعیت‌ها</option><option value="active">فعال</option><option value="provisioning">در حال ساخت</option><option value="suspended">متوقف</option><option value="expired">منقضی</option><option value="error">نیازمند بررسی</option></select>
                <button class="btn btn-primary" data-open-modal="createBot"><?= panel_icon('plus', 17) ?>ربات جدید</button>
            </div>
            <div class="bot-table-wrap">
                <table class="data-table <?= !$bots ? 'empty-table' : '' ?>" id="allBots">
                    <thead><tr><th>مشتری</th><th>ربات</th><th>وضعیت</th><th>اعتبار</th><th>بکاپ</th><th>دامنه</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($bots as $bot): ?>
                        <tr data-bot-row data-status="<?= panel_h($bot['status']) ?>" data-search="<?= panel_h(mb_strtolower($bot['customer_name'] . ' ' . $bot['slug'] . ' ' . $bot['bot_username'] . ' ' . $bot['domain'])) ?>">
                            <td><div class="customer-cell"><span><?= panel_h(mb_substr($bot['customer_name'], 0, 1)) ?></span><div><strong><?= panel_h($bot['customer_name']) ?></strong><small><?= panel_h($bot['customer_phone'] ?: 'بدون شماره تماس') ?></small></div></div></td>
                            <td><div class="mono-stack"><strong dir="ltr">@<?= panel_h($bot['bot_username']) ?></strong><small dir="ltr"><?= panel_h($bot['slug']) ?></small></div></td>
                            <td><?= cp_badge((string) $bot['status']) ?><small class="live-state"><?= panel_h(panel_fa_status((string) $bot['live_status'])) ?></small></td>
                            <td><div class="expiry"><strong><?= panel_h(date('Y/m/d', strtotime($bot['expires_at']) ?: time())) ?></strong><small class="<?= $bot['remaining_days'] < 7 ? 'text-danger' : '' ?>"><?= $bot['remaining_days'] >= 0 ? panel_h((string) $bot['remaining_days']) . ' روز مانده' : 'پایان یافته' ?></small></div></td>
                            <td><span><?= panel_h($bot['backup_schedule'] === 'daily' ? 'روزانه' : ($bot['backup_schedule'] === 'weekly' ? 'هفتگی' : 'خاموش')) ?></span><small class="live-state"><?= (int) $bot['backup_retention'] ?> نسخه</small></td>
                            <td><a class="domain-link" href="https://<?= panel_h($bot['domain']) ?>" target="_blank" rel="noopener noreferrer" dir="ltr"><?= panel_h($bot['domain']) ?><?= panel_icon('external', 13) ?></a></td>
                            <td><button class="icon-btn" data-bot-details='<?= panel_h(json_encode($bot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>' aria-label="مدیریت ربات"><?= panel_icon('more') ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$bots): ?><tr><td colspan="7"><div class="empty-state"><?= panel_icon('bot', 28) ?><strong>فهرست خالی است</strong><span>ربات جدیدی برای مشتری اضافه کنید.</span></div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="view" data-view="operations">
            <div class="section-head"><div><h2>تاریخچه عملیات</h2><p>نتیجه نصب، بروزرسانی، بکاپ و توقف‌ها</p></div></div>
            <div class="operation-list" id="operationList">
                <?php foreach ($operations as $op): ?>
                    <article><span class="operation-icon"><?= panel_icon($op['operation'] === 'backup' ? 'database' : 'activity') ?></span><div><strong><?= panel_h(panel_fa_status($op['operation'])) ?> · <?= panel_h($op['customer_name'] ?: $op['slug'] ?: 'سیستم') ?></strong><p><?= panel_h($op['message'] ?: 'در انتظار پردازش توسط سرویس مدیریت') ?></p></div><?= cp_badge((string) $op['status']) ?><time><?= panel_h(date('Y/m/d H:i', strtotime($op['created_at']) ?: time())) ?></time></article>
                <?php endforeach; ?>
                <?php if (!$operations): ?><div class="empty-state"><?= panel_icon('activity', 28) ?><strong>عملیاتی وجود ندارد</strong></div><?php endif; ?>
            </div>
        </section>

        <section class="view" data-view="backups">
            <div class="section-head"><div><h2>مدیریت بکاپ</h2><p>ساخت و بازیابی نسخه‌های هر ربات</p></div></div>
            <div class="backup-picker">
                <label class="field"><span>انتخاب ربات</span><select id="backupBot"><option value="">یک ربات را انتخاب کنید</option><?php foreach ($bots as $bot): ?><option value="<?= panel_h($bot['slug']) ?>"><?= panel_h($bot['customer_name']) ?> · <?= panel_h($bot['slug']) ?></option><?php endforeach; ?></select></label>
                <button class="btn btn-secondary" id="loadBackups"><?= panel_icon('refresh', 17) ?>نمایش نسخه‌ها</button>
                <button class="btn btn-primary" id="createBackup"><?= panel_icon('download', 17) ?>ساخت بکاپ</button>
            </div>
            <div id="backupList" class="backup-list"><div class="empty-state"><?= panel_icon('database', 28) ?><strong>یک ربات را انتخاب کنید</strong><span>نسخه‌های موجود اینجا نمایش داده می‌شوند.</span></div></div>
        </section>

        <section class="view" data-view="settings">
            <div class="settings-layout">
                <section>
                    <div class="section-head compact"><div><h2>امنیت حساب</h2><p>تغییر رمز مدیر پنل</p></div></div>
                    <form id="passwordForm" class="settings-form">
                        <label class="field"><span>رمز فعلی</span><input type="password" name="current_password" required autocomplete="current-password"></label>
                        <label class="field"><span>رمز جدید</span><input type="password" name="new_password" required minlength="12" autocomplete="new-password"><small>حداقل ۱۲ کاراکتر و ترکیبی از حروف و عدد</small></label>
                        <label class="field"><span>تکرار رمز جدید</span><input type="password" name="confirm_password" required autocomplete="new-password"></label>
                        <button class="btn btn-primary" type="submit">ذخیره رمز جدید</button>
                    </form>
                </section>
                <section>
                    <div class="section-head compact"><div><h2>وضعیت زیرساخت</h2><p>ارتباط اجزای پنل</p></div></div>
                    <div class="system-list">
                        <div><span><?= panel_icon('activity') ?>Agent میزبان</span><?= cp_badge($agentOnline ? 'active' : 'error') ?></div>
                        <div><span><?= panel_icon('database') ?>پایگاه داده پنل</span><?= cp_badge('healthy') ?></div>
                        <div><span><?= panel_icon('shield') ?>نشست امن</span><?= cp_badge('active') ?></div>
                    </div>
                </section>
                <section class="audit-section">
                    <div class="section-head compact"><div><h2>گزارش مدیریتی</h2><p>آخرین فعالیت‌های مدیر و سیستم</p></div></div>
                    <div class="audit-list"><?php foreach ($audit as $item): ?><div><span><?= panel_h($item['event']) ?><?= $item['slug'] ? ' · ' . panel_h($item['slug']) : '' ?></span><time><?= panel_h(date('Y/m/d H:i', strtotime($item['created_at']) ?: time())) ?></time></div><?php endforeach; ?><?php if (!$audit): ?><div class="quiet-empty">گزارشی ثبت نشده است.</div><?php endif; ?></div>
                </section>
            </div>
        </section>
    </main>
</div>

<div class="modal" id="createBot" aria-hidden="true">
    <div class="modal-backdrop" data-close-modal></div>
    <section class="modal-panel create-modal" role="dialog" aria-modal="true" aria-labelledby="createTitle">
        <header><div><span>نصب مستقل مشتری</span><h2 id="createTitle">ساخت ربات جدید</h2></div><button class="icon-btn" data-close-modal aria-label="بستن"><?= panel_icon('x') ?></button></header>
        <form id="createBotForm">
            <div class="form-section"><h3><b>۱</b>مشخصات مشتری</h3><div class="form-grid"><label class="field"><span>نام مشتری</span><input name="customer_name" required maxlength="100" placeholder="مثلاً فروشگاه آریا"></label><label class="field"><span>شماره تماس</span><input name="customer_phone" maxlength="32" inputmode="tel" placeholder="اختیاری"></label></div></div>
            <div class="form-section"><h3><b>۲</b>اطلاعات ربات</h3><div class="form-grid"><label class="field"><span>شناسه نصب</span><input name="slug" required maxlength="31" dir="ltr" placeholder="aria-shop" pattern="[a-z][a-z0-9-]{1,30}"><small>حروف کوچک انگلیسی، عدد و خط تیره</small></label><label class="field"><span>نام کاربری ربات</span><input name="bot_username" required maxlength="32" dir="ltr" placeholder="AriaShopBot"></label><label class="field field-wide"><span>توکن تلگرام</span><div class="password-input"><input name="token" type="password" required maxlength="100" dir="ltr" autocomplete="off" placeholder="123456789:AA..."><button type="button" data-toggle-secret aria-label="نمایش توکن"><?= panel_icon('eye', 17) ?></button></div><small>توکن فقط برای ساخت به Agent امن تحویل می‌شود و در پنل ذخیره نمی‌شود.</small></label><label class="field"><span>آیدی عددی مدیر ربات</span><input name="admin_id" required maxlength="24" inputmode="numeric" dir="ltr" placeholder="123456789"></label><label class="field"><span>دامنه اختصاصی</span><input name="domain" required maxlength="253" dir="ltr" placeholder="bot.example.com"></label></div></div>
            <div class="form-section"><h3><b>۳</b>اعتبار و نگهداری</h3><div class="form-grid"><label class="field"><span>تاریخ و ساعت پایان</span><input name="expires_at" type="datetime-local" required></label><label class="field"><span>بکاپ خودکار</span><select name="backup_schedule"><option value="daily">روزانه</option><option value="weekly">هفتگی</option><option value="off">خاموش</option></select></label><label class="field"><span>تعداد نسخه نگهداری</span><input name="backup_retention" type="number" min="1" max="60" value="7" required></label><label class="field field-wide"><span>یادداشت مدیر</span><textarea name="notes" maxlength="1000" rows="3" placeholder="توضیحات قرارداد، نوع مشتری یا موارد پیگیری"></textarea></label></div></div>
            <footer><button class="btn btn-ghost" type="button" data-close-modal>انصراف</button><button class="btn btn-primary" type="submit"><?= panel_icon('plus', 17) ?>ساخت و راه‌اندازی</button></footer>
        </form>
    </section>
</div>

<div class="drawer" id="botDrawer" aria-hidden="true">
    <div class="drawer-backdrop" data-close-drawer></div>
    <aside class="drawer-panel" role="dialog" aria-modal="true" aria-label="مدیریت ربات">
        <header><div><span id="drawerCustomer">مشتری</span><h2 id="drawerBot" dir="ltr">-</h2></div><button class="icon-btn" data-close-drawer aria-label="بستن"><?= panel_icon('x') ?></button></header>
        <div class="drawer-status"><div><span>وضعیت سرویس</span><strong id="drawerStatus">-</strong></div><div><span>پایان اعتبار</span><strong id="drawerExpiry">-</strong></div></div>
        <dl class="detail-list"><div><dt>شناسه نصب</dt><dd id="drawerSlug" dir="ltr">-</dd></div><div><dt>دامنه</dt><dd id="drawerDomain" dir="ltr">-</dd></div><div><dt>مدیر ربات</dt><dd id="drawerAdmin" dir="ltr">-</dd></div><div><dt>بکاپ خودکار</dt><dd id="drawerBackup">-</dd></div></dl>
        <div class="action-grid">
            <button data-operation="start"><?= panel_icon('play') ?><span>روشن کردن</span></button>
            <button data-operation="stop"><?= panel_icon('pause') ?><span>متوقف کردن</span></button>
            <button data-operation="restart"><?= panel_icon('rotate') ?><span>راه‌اندازی مجدد</span></button>
            <button data-operation="update"><?= panel_icon('refresh') ?><span>بروزرسانی</span></button>
            <button data-operation="backup"><?= panel_icon('database') ?><span>ساخت بکاپ</span></button>
            <button data-show-logs><?= panel_icon('activity') ?><span>گزارش اجرا</span></button>
        </div>
        <form id="extendForm" class="extend-form"><label class="field"><span>تمدید اعتبار تا</span><input name="expires_at" type="datetime-local" required></label><button class="btn btn-secondary" type="submit"><?= panel_icon('calendar', 17) ?>ثبت تمدید</button></form>
        <div class="drawer-notes"><span>یادداشت</span><p id="drawerNotes">-</p></div>
        <button class="danger-action" id="deleteBot"><?= panel_icon('trash', 17) ?>حذف کامل ربات و ساخت بکاپ نهایی</button>
    </aside>
</div>

<div class="modal" id="logsModal" aria-hidden="true"><div class="modal-backdrop" data-close-modal></div><section class="modal-panel log-modal" role="dialog" aria-modal="true"><header><div><span>۲۰۰ خط آخر</span><h2>گزارش اجرای ربات</h2></div><button class="icon-btn" data-close-modal><?= panel_icon('x') ?></button></header><pre id="logOutput" dir="ltr">در حال دریافت...</pre></section></div>
<div class="toast-stack" id="toastStack" aria-live="polite"></div>
<script src="assets/app.js?v=1" defer></script>
</body>
</html>
