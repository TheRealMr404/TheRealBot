<?php

declare(strict_types=1);

require_once __DIR__ . '/addons/power/bootstrap.php';

$allowedSections = ['dashboard', 'reports', 'search', 'customers', 'finance', 'operations', 'security', 'backups', 'tools', 'activity', 'preferences'];
$section = (string) ($_GET['section'] ?? 'dashboard');
if (!in_array($section, $allowedSections, true)) {
    $section = 'dashboard';
}

$sectionTitles = [
    'dashboard' => 'مرکز کنترل',
    'reports' => 'گزارش‌های پیشرفته',
    'search' => 'جست‌وجوی سراسری',
    'customers' => 'هوش مشتریان',
    'finance' => 'تحلیل مالی',
    'operations' => 'سلامت و عملیات',
    'security' => 'مرکز امنیت',
    'backups' => 'بکاپ پایگاه داده',
    'tools' => 'ابزارهای مدیریتی',
    'activity' => 'رویدادهای افزونه',
    'preferences' => 'تنظیمات افزونه',
];

pw_log('page_view', ['section' => $section]);
$settings = pw_settings();
$pageTitle = $sectionTitles[$section];
$pageLede = 'افزونه مدیریتی مستقل؛ بدون تغییر در فایل‌ها و صفحات قبلی پنل.';
$activeNav = '';
include __DIR__ . '/inc/layout_head.php';

$totalUsers = pw_table_exists($pdo, 'user') ? (int) pw_scalar($pdo, 'SELECT COUNT(*) FROM `user`') : 0;
$totalOrders = pw_table_exists($pdo, 'invoice') ? (int) pw_scalar($pdo, 'SELECT COUNT(*) FROM `invoice`') : 0;
$totalRevenue = pw_table_exists($pdo, 'invoice') && pw_has_col($pdo, 'invoice', 'price_product')
    ? (float) pw_scalar($pdo, "SELECT COALESCE(SUM(`price_product`),0) FROM `invoice` WHERE `Status` IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold')")
    : 0;
$waitingPayments = pw_table_exists($pdo, 'Payment_report')
    ? (int) pw_scalar($pdo, "SELECT COUNT(*) FROM `Payment_report` WHERE `payment_Status`='waiting'")
    : 0;
?>
<link rel="stylesheet" href="addons/power/assets/power.css?v=<?= pw_h(PW_VERSION) ?>">

<div class="power-shell <?= !empty($settings['compact_tables']) ? 'power-compact' : '' ?>" data-privacy="<?= !empty($settings['privacy_mode']) ? '1' : '0' ?>">
    <section class="power-hero fade-up">
        <div>
            <div class="power-kicker">MIRZA POWER SUITE <span>v<?= pw_h(PW_VERSION) ?></span></div>
            <h2><?= pw_h($sectionTitles[$section]) ?></h2>
            <p>گزارش، بکاپ، عیب‌یابی، امنیت و ابزارهای تکمیلی در یک ماژول مستقل.</p>
        </div>
        <div class="power-hero-actions">
            <button class="btn btn-ghost" type="button" data-privacy-toggle><?= icon('eye', 15) ?> حالت حریم خصوصی</button>
            <a class="btn btn-primary" href="index.php"><?= icon('arrow-left', 15) ?> پنل اصلی</a>
        </div>
    </section>

    <nav class="power-tabs" aria-label="بخش‌های افزونه">
        <?php
        $nav = [
            'dashboard' => ['dashboard', 'مرکز کنترل'],
            'reports' => ['chart', 'گزارش‌ها'],
            'search' => ['search', 'جست‌وجو'],
            'customers' => ['users', 'مشتریان'],
            'finance' => ['wallet', 'مالی'],
            'operations' => ['server', 'عملیات'],
            'security' => ['block', 'امنیت'],
            'backups' => ['package', 'بکاپ'],
            'tools' => ['settings', 'ابزارها'],
            'activity' => ['invoice', 'رویدادها'],
            'preferences' => ['settings', 'تنظیمات'],
        ];
        foreach ($nav as $key => [$ico, $label]): ?>
            <a href="power.php?section=<?= pw_h($key) ?>" class="power-tab <?= $section === $key ? 'active' : '' ?>">
                <?= icon($ico, 15) ?><span><?= pw_h($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($section === 'dashboard'):
        $today = strtotime('today');
        $week = strtotime('-6 days midnight');
        $month = strtotime(date('Y-m-01'));
        $newToday = pw_table_exists($pdo, 'user') && pw_has_col($pdo, 'user', 'register')
            ? (int) pw_scalar($pdo, 'SELECT COUNT(*) FROM `user` WHERE `register` >= ?', [$today]) : 0;
        $newWeek = pw_table_exists($pdo, 'user') && pw_has_col($pdo, 'user', 'register')
            ? (int) pw_scalar($pdo, 'SELECT COUNT(*) FROM `user` WHERE `register` >= ?', [$week]) : 0;
        $activeServices = pw_table_exists($pdo, 'invoice') && pw_has_col($pdo, 'invoice', 'Status')
            ? (int) pw_scalar($pdo, "SELECT COUNT(*) FROM `invoice` WHERE `Status`='active'") : 0;
        $blockedUsers = pw_table_exists($pdo, 'user') && pw_has_col($pdo, 'user', 'User_Status')
            ? (int) pw_scalar($pdo, "SELECT COUNT(*) FROM `user` WHERE `User_Status`='block'") : 0;
        $walletBalance = pw_table_exists($pdo, 'user') && pw_has_col($pdo, 'user', 'Balance')
            ? (float) pw_scalar($pdo, 'SELECT COALESCE(SUM(`Balance`),0) FROM `user`') : 0;
        $monthRevenue = pw_table_exists($pdo, 'invoice') && pw_has_col($pdo, 'invoice', 'time_sell') && pw_has_col($pdo, 'invoice', 'price_product')
            ? (float) pw_scalar($pdo, 'SELECT COALESCE(SUM(`price_product`),0) FROM `invoice` WHERE `time_sell` >= ?', [$month]) : 0;
        $avgOrder = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
        $chartDays = max(7, min(60, (int) $settings['chart_days']));
        $chartFrom = strtotime('-' . ($chartDays - 1) . ' days midnight');
        $salesSeries = pw_day_series($pdo, 'invoice', 'time_sell', 'price_product', $chartFrom, time());
        $userSeries = pw_day_series($pdo, 'user', 'register', null, $chartFrom, time());
        $alerts = [];
        if ($waitingPayments > 0) $alerts[] = ['warn', "$waitingPayments پرداخت در انتظار بررسی است.", 'payment.php?status=waiting'];
        if ($blockedUsers > 0) $alerts[] = ['no', "$blockedUsers کاربر مسدود در سامانه وجود دارد.", 'users.php?status=block'];
        if (!is_dir(PW_BACKUPS) || count(pw_backup_files()) === 0) $alerts[] = ['warn', 'هنوز هیچ بکاپی با افزونه ساخته نشده است.', 'power.php?section=backups'];
        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') $alerts[] = ['no', 'پنل با HTTPS تشخیص داده نشد.', 'power.php?section=security'];
        ?>
        <div class="power-grid power-kpis fade-up">
            <div class="power-kpi"><span>کل کاربران</span><strong><?= pw_num($totalUsers) ?></strong><small>+<?= pw_num($newToday) ?> امروز · +<?= pw_num($newWeek) ?> هفت روز</small></div>
            <div class="power-kpi good"><span>درآمد محاسبه‌شده</span><strong data-sensitive><?= pw_money($totalRevenue) ?></strong><small><?= pw_money($monthRevenue) ?> این ماه</small></div>
            <div class="power-kpi"><span>سرویس فعال</span><strong><?= pw_num($activeServices) ?></strong><small>از <?= pw_num($totalOrders) ?> سفارش</small></div>
            <div class="power-kpi warn"><span>موجودی کیف پول‌ها</span><strong data-sensitive><?= pw_money($walletBalance) ?></strong><small>میانگین سفارش: <?= pw_money($avgOrder) ?></small></div>
        </div>

        <div class="power-two">
            <section class="card power-card fade-up d1">
                <div class="card-head"><div><div class="card-title">روند فروش <?= $chartDays ?> روزه</div><div class="card-subtitle">براساس زمان ثبت سفارش</div></div><a class="btn-link" href="power.php?section=reports">جزئیات ←</a></div>
                <div class="power-chart" data-chart='<?= pw_h(json_encode($salesSeries, JSON_UNESCAPED_UNICODE)) ?>' data-kind="money"></div>
            </section>
            <section class="card power-card fade-up d2">
                <div class="card-head"><div><div class="card-title">رشد کاربران</div><div class="card-subtitle">ثبت‌نام روزانه</div></div><a class="btn-link" href="power.php?section=customers">تحلیل ←</a></div>
                <div class="power-chart" data-chart='<?= pw_h(json_encode($userSeries, JSON_UNESCAPED_UNICODE)) ?>' data-kind="count"></div>
            </section>
        </div>

        <div class="power-two">
            <section class="card power-card">
                <div class="card-head"><div><div class="card-title">هشدارهای هوشمند</div><div class="card-subtitle">مواردی که نیاز به توجه دارند</div></div></div>
                <div class="power-alert-list">
                    <?php if (!$alerts): ?><div class="power-empty-good">مورد مهمی شناسایی نشد.</div><?php endif; ?>
                    <?php foreach ($alerts as [$type, $text, $url]): ?><a href="<?= pw_h($url) ?>" class="power-alert <?= pw_h($type) ?>"><span></span><b><?= pw_h($text) ?></b><em>بررسی ←</em></a><?php endforeach; ?>
                </div>
            </section>
            <section class="card power-card">
                <div class="card-head"><div><div class="card-title">دسترسی سریع</div><div class="card-subtitle">عملیات پرتکرار پنل</div></div></div>
                <div class="power-quick">
                    <a href="users.php"><?= icon('users', 20) ?><span>کاربران</span></a>
                    <a href="invoice.php"><?= icon('invoice', 20) ?><span>سفارشات</span></a>
                    <a href="payment.php"><?= icon('card', 20) ?><span>تراکنش‌ها</span></a>
                    <a href="product.php"><?= icon('package', 20) ?><span>محصولات</span></a>
                    <a href="power.php?section=backups"><?= icon('server', 20) ?><span>بکاپ فوری</span></a>
                    <a href="power.php?section=search"><?= icon('search', 20) ?><span>جست‌وجوی کل</span></a>
                </div>
            </section>
        </div>

    <?php elseif ($section === 'reports'):
        [$from, $to, $fromDate, $toDate] = pw_range();
        $sales = pw_day_series($pdo, 'invoice', 'time_sell', 'price_product', $from, $to);
        $registrations = pw_day_series($pdo, 'user', 'register', null, $from, $to);
        $rangeRevenue = pw_table_exists($pdo, 'invoice') && pw_has_col($pdo, 'invoice', 'time_sell')
            ? (float) pw_scalar($pdo, 'SELECT COALESCE(SUM(`price_product`),0) FROM `invoice` WHERE `time_sell` BETWEEN ? AND ?', [$from, $to]) : 0;
        $rangeOrders = pw_table_exists($pdo, 'invoice') && pw_has_col($pdo, 'invoice', 'time_sell')
            ? (int) pw_scalar($pdo, 'SELECT COUNT(*) FROM `invoice` WHERE `time_sell` BETWEEN ? AND ?', [$from, $to]) : 0;
        $rangeUsers = pw_table_exists($pdo, 'user') && pw_has_col($pdo, 'user', 'register')
            ? (int) pw_scalar($pdo, 'SELECT COUNT(*) FROM `user` WHERE `register` BETWEEN ? AND ?', [$from, $to]) : 0;
        $topProducts = pw_table_exists($pdo, 'invoice')
            ? pw_rows($pdo, 'SELECT COALESCE(NULLIF(`name_product`,\'\'),\'بدون نام\') label, COUNT(*) qty, COALESCE(SUM(`price_product`),0) amount FROM `invoice` WHERE `time_sell` BETWEEN ? AND ? GROUP BY label ORDER BY amount DESC LIMIT 12', [$from, $to]) : [];
        $statusRows = pw_table_exists($pdo, 'invoice')
            ? pw_rows($pdo, 'SELECT COALESCE(NULLIF(`Status`,\'\'),\'نامشخص\') label, COUNT(*) qty FROM `invoice` WHERE `time_sell` BETWEEN ? AND ? GROUP BY label ORDER BY qty DESC', [$from, $to]) : [];
        ?>
        <form class="card power-filter" method="get">
            <input type="hidden" name="section" value="reports">
            <div class="field"><label>از تاریخ</label><input class="input" type="date" name="from" value="<?= pw_h($fromDate) ?>"></div>
            <div class="field"><label>تا تاریخ</label><input class="input" type="date" name="to" value="<?= pw_h($toDate) ?>"></div>
            <button class="btn btn-primary" type="submit"><?= icon('search', 14) ?> اعمال بازه</button>
            <a class="btn btn-ghost" href="power_export.php?type=snapshot&from=<?= pw_h($fromDate) ?>&to=<?= pw_h($toDate) ?>">خروجی JSON</a>
        </form>
        <div class="power-grid power-kpis">
            <div class="power-kpi good"><span>فروش بازه</span><strong data-sensitive><?= pw_money($rangeRevenue) ?></strong></div>
            <div class="power-kpi"><span>سفارش‌های بازه</span><strong><?= pw_num($rangeOrders) ?></strong></div>
            <div class="power-kpi"><span>کاربر جدید</span><strong><?= pw_num($rangeUsers) ?></strong></div>
            <div class="power-kpi warn"><span>میانگین سفارش</span><strong data-sensitive><?= pw_money($rangeOrders ? $rangeRevenue / $rangeOrders : 0) ?></strong></div>
        </div>
        <div class="power-two">
            <section class="card power-card"><div class="card-head"><div><div class="card-title">نمودار فروش</div><div class="card-subtitle"><?= pw_h($fromDate) ?> تا <?= pw_h($toDate) ?></div></div></div><div class="power-chart" data-chart='<?= pw_h(json_encode($sales, JSON_UNESCAPED_UNICODE)) ?>' data-kind="money"></div></section>
            <section class="card power-card"><div class="card-head"><div><div class="card-title">نمودار ثبت‌نام</div><div class="card-subtitle">کاربران جدید روزانه</div></div></div><div class="power-chart" data-chart='<?= pw_h(json_encode($registrations, JSON_UNESCAPED_UNICODE)) ?>' data-kind="count"></div></section>
        </div>
        <div class="power-two">
            <section class="card power-card"><div class="card-head"><div><div class="card-title">محصولات پرفروش</div></div><a href="power_export.php?type=invoices&from=<?= pw_h($fromDate) ?>&to=<?= pw_h($toDate) ?>" class="btn-link">CSV سفارش‌ها</a></div><div class="tbl-wrap"><table class="tbl-md"><thead><tr><th>محصول</th><th>تعداد</th><th>فروش</th></tr></thead><tbody><?php foreach ($topProducts as $row): ?><tr><td><?= pw_h($row['label']) ?></td><td><?= pw_num($row['qty']) ?></td><td data-sensitive><?= pw_money($row['amount']) ?></td></tr><?php endforeach; ?><?php if (!$topProducts): ?><tr><td colspan="3">داده‌ای موجود نیست.</td></tr><?php endif; ?></tbody></table></div></section>
            <section class="card power-card"><div class="card-head"><div><div class="card-title">توزیع وضعیت سفارش</div></div></div><div class="power-bars"><?php $max = max(array_column($statusRows ?: [['qty'=>1]], 'qty')); foreach ($statusRows as $row): [$tag] = pw_status_tag((string)$row['label']); ?><div class="power-bar-row"><div><span class="tag <?= pw_h($tag) ?>"><?= pw_h($row['label']) ?></span><b><?= pw_num($row['qty']) ?></b></div><i><em style="width:<?= (float)$row['qty']/$max*100 ?>%"></em></i></div><?php endforeach; ?></div></section>
        </div>

    <?php elseif ($section === 'search'):
        $q = trim((string) ($_GET['q'] ?? ''));
        $results = ['users'=>[], 'invoices'=>[], 'payments'=>[], 'products'=>[], 'services'=>[]];
        if ($q !== '') {
            $like = '%' . $q . '%';
            if (pw_table_exists($pdo, 'user')) $results['users'] = pw_rows($pdo, "SELECT * FROM `user` WHERE CAST(`id` AS CHAR) LIKE ? OR COALESCE(`username`,'') LIKE ? OR COALESCE(`namecustom`,'') LIKE ? LIMIT 40", [$like,$like,$like]);
            if (pw_table_exists($pdo, 'invoice')) $results['invoices'] = pw_rows($pdo, "SELECT * FROM `invoice` WHERE CAST(`id_user` AS CHAR) LIKE ? OR COALESCE(`name_product`,'') LIKE ? OR COALESCE(`username`,'') LIKE ? LIMIT 40", [$like,$like,$like]);
            if (pw_table_exists($pdo, 'Payment_report')) $results['payments'] = pw_rows($pdo, "SELECT * FROM `Payment_report` WHERE CAST(`id_user` AS CHAR) LIKE ? OR CAST(`id_order` AS CHAR) LIKE ? LIMIT 40", [$like,$like]);
            if (pw_table_exists($pdo, 'product')) {
                $pc = pw_find_first_column($pdo, 'product', ['name_product','name','code_product']);
                if ($pc) $results['products'] = pw_rows($pdo, 'SELECT * FROM `product` WHERE ' . pw_ident($pc) . ' LIKE ? LIMIT 40', [$like]);
            }
            if (pw_table_exists($pdo, 'service_other')) {
                $sc = pw_find_first_column($pdo, 'service_other', ['name','username','name_panel','Location']);
                if ($sc) $results['services'] = pw_rows($pdo, 'SELECT * FROM `service_other` WHERE ' . pw_ident($sc) . ' LIKE ? LIMIT 40', [$like]);
            }
            pw_log('global_search', ['query_length' => mb_strlen($q), 'counts' => array_map('count', $results)]);
        }
        ?>
        <form class="card power-search" method="get"><input type="hidden" name="section" value="search"><div class="search-box"><span><?= icon('search', 18) ?></span><input name="q" value="<?= pw_h($q) ?>" placeholder="آیدی کاربر، نام، یوزرنیم، سفارش، تراکنش یا محصول..." autofocus><button class="search-btn">جست‌وجوی همه</button></div></form>
        <?php if ($q !== ''): ?>
            <div class="power-search-summary">نتیجه برای «<?= pw_h($q) ?>»: <?= pw_num(array_sum(array_map('count',$results))) ?> مورد</div>
            <div class="power-search-grid">
                <section class="card power-card"><div class="card-head"><div class="card-title">کاربران (<?= count($results['users']) ?>)</div></div><div class="power-result-list"><?php foreach ($results['users'] as $r): ?><a href="user.php?id=<?= pw_h($r['id'] ?? '') ?>"><b><?= pw_h($r['namecustom'] ?? $r['username'] ?? 'بدون نام') ?></b><span data-sensitive><?= pw_h($r['id'] ?? '') ?> · <?= pw_money($r['Balance'] ?? 0) ?></span></a><?php endforeach; ?><?php if (!$results['users']): ?><div class="empty">موردی یافت نشد.</div><?php endif; ?></div></section>
                <section class="card power-card"><div class="card-head"><div class="card-title">سفارش‌ها (<?= count($results['invoices']) ?>)</div></div><div class="power-result-list"><?php foreach ($results['invoices'] as $r): ?><a href="invoice.php?q=<?= urlencode((string)($r['id_user'] ?? '')) ?>"><b><?= pw_h($r['name_product'] ?? 'بدون نام') ?></b><span data-sensitive><?= pw_h($r['id_user'] ?? '') ?> · <?= pw_money($r['price_product'] ?? 0) ?></span></a><?php endforeach; ?><?php if (!$results['invoices']): ?><div class="empty">موردی یافت نشد.</div><?php endif; ?></div></section>
                <section class="card power-card"><div class="card-head"><div class="card-title">تراکنش‌ها (<?= count($results['payments']) ?>)</div></div><div class="power-result-list"><?php foreach ($results['payments'] as $r): ?><a href="payment.php?q=<?= urlencode((string)($r['id_order'] ?? '')) ?>"><b><?= pw_h($r['id_order'] ?? 'بدون شناسه') ?></b><span data-sensitive><?= pw_h($r['id_user'] ?? '') ?> · <?= pw_money($r['price'] ?? 0) ?></span></a><?php endforeach; ?><?php if (!$results['payments']): ?><div class="empty">موردی یافت نشد.</div><?php endif; ?></div></section>
                <section class="card power-card"><div class="card-head"><div class="card-title">محصولات و سرویس‌ها (<?= count($results['products']) + count($results['services']) ?>)</div></div><div class="power-result-list"><?php foreach (array_merge($results['products'],$results['services']) as $r): ?><a href="product.php"><b><?= pw_h($r['name_product'] ?? $r['name'] ?? $r['username'] ?? 'رکورد') ?></b><span><?= pw_h($r['name_panel'] ?? $r['Location'] ?? $r['code_product'] ?? '') ?></span></a><?php endforeach; ?><?php if (!$results['products'] && !$results['services']): ?><div class="empty">موردی یافت نشد.</div><?php endif; ?></div></section>
            </div>
        <?php endif; ?>

    <?php elseif ($section === 'customers'):
        $topBalance = pw_table_exists($pdo, 'user') ? pw_rows($pdo, 'SELECT * FROM `user` ORDER BY `Balance` DESC LIMIT 20') : [];
        $recentUsers = pw_table_exists($pdo, 'user') ? pw_rows($pdo, 'SELECT * FROM `user` ORDER BY `register` DESC LIMIT 20') : [];
        $blocked = pw_table_exists($pdo, 'user') ? (int) pw_scalar($pdo, "SELECT COUNT(*) FROM `user` WHERE `User_Status`='block'") : 0;
        $agents = pw_table_exists($pdo, 'user') ? (int) pw_scalar($pdo, "SELECT COUNT(*) FROM `user` WHERE `agent` IN ('n','n2','all')") : 0;
        $buyers = pw_table_exists($pdo, 'invoice') ? (int) pw_scalar($pdo, 'SELECT COUNT(DISTINCT `id_user`) FROM `invoice`') : 0;
        $conversion = $totalUsers > 0 ? ($buyers / $totalUsers) * 100 : 0;
        $topBuyers = pw_table_exists($pdo, 'invoice') ? pw_rows($pdo, 'SELECT `id_user`, COUNT(*) orders_count, COALESCE(SUM(`price_product`),0) spent FROM `invoice` GROUP BY `id_user` ORDER BY spent DESC LIMIT 20') : [];
        ?>
        <div class="power-grid power-kpis"><div class="power-kpi"><span>خریداران یکتا</span><strong><?= pw_num($buyers) ?></strong></div><div class="power-kpi good"><span>نرخ تبدیل تقریبی</span><strong><?= number_format($conversion,1) ?>٪</strong></div><div class="power-kpi warn"><span>نمایندگان</span><strong><?= pw_num($agents) ?></strong></div><div class="power-kpi"><span>کاربران مسدود</span><strong><?= pw_num($blocked) ?></strong></div></div>
        <div class="power-two">
            <section class="card power-card"><div class="card-head"><div><div class="card-title">مشتریان برتر براساس خرید</div></div><a href="power_export.php?type=users" class="btn-link">CSV کاربران</a></div><div class="tbl-wrap"><table class="tbl-md"><thead><tr><th>کاربر</th><th>سفارش</th><th>مجموع خرید</th></tr></thead><tbody><?php foreach ($topBuyers as $r): ?><tr><td><a href="user.php?id=<?= pw_h($r['id_user']) ?>" class="btn-link" data-sensitive><?= pw_h($r['id_user']) ?></a></td><td><?= pw_num($r['orders_count']) ?></td><td data-sensitive><?= pw_money($r['spent']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
            <section class="card power-card"><div class="card-head"><div><div class="card-title">بیشترین موجودی کیف پول</div></div></div><div class="tbl-wrap"><table class="tbl-md"><thead><tr><th>کاربر</th><th>نام</th><th>موجودی</th></tr></thead><tbody><?php foreach ($topBalance as $r): ?><tr><td><a href="user.php?id=<?= pw_h($r['id']) ?>" class="btn-link" data-sensitive><?= pw_h($r['id']) ?></a></td><td><?= pw_h($r['namecustom'] ?? $r['username'] ?? '—') ?></td><td data-sensitive><?= pw_money($r['Balance'] ?? 0) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
        </div>
        <section class="card power-card"><div class="card-head"><div><div class="card-title">کاربران تازه‌وارد</div><div class="card-subtitle">آخرین ۲۰ ثبت‌نام</div></div></div><div class="power-customer-cards"><?php foreach ($recentUsers as $r): ?><a href="user.php?id=<?= pw_h($r['id']) ?>"><div class="power-avatar"><?= pw_h(mb_substr((string)($r['namecustom'] ?? $r['username'] ?? 'U'),0,1)) ?></div><b><?= pw_h($r['namecustom'] ?? $r['username'] ?? 'بدون نام') ?></b><span data-sensitive><?= pw_h($r['id']) ?></span><small><?= safe_date($r['register'] ?? null,'Y/m/d H:i') ?></small></a><?php endforeach; ?></div></section>

    <?php elseif ($section === 'finance'):
        $paidTotal = pw_table_exists($pdo, 'Payment_report') ? (float) pw_scalar($pdo, "SELECT COALESCE(SUM(`price`),0) FROM `Payment_report` WHERE `payment_Status`='paid'") : 0;
        $paidToday = pw_table_exists($pdo, 'Payment_report') ? (float) pw_scalar($pdo, "SELECT COALESCE(SUM(`price`),0) FROM `Payment_report` WHERE `payment_Status`='paid' AND `time`>=?", [strtotime('today')]) : 0;
        $paidMonth = pw_table_exists($pdo, 'Payment_report') ? (float) pw_scalar($pdo, "SELECT COALESCE(SUM(`price`),0) FROM `Payment_report` WHERE `payment_Status`='paid' AND `time`>=?", [strtotime(date('Y-m-01'))]) : 0;
        $paidCount = pw_table_exists($pdo, 'Payment_report') ? (int) pw_scalar($pdo, "SELECT COUNT(*) FROM `Payment_report` WHERE `payment_Status`='paid'") : 0;
        $failedCount = pw_table_exists($pdo, 'Payment_report') ? (int) pw_scalar($pdo, "SELECT COUNT(*) FROM `Payment_report` WHERE `payment_Status` IN ('Unpaid','reject','expire')") : 0;
        $methods = pw_table_exists($pdo, 'Payment_report') ? pw_rows($pdo, 'SELECT COALESCE(NULLIF(`Payment_Method`,\'\'),\'نامشخص\') label, COUNT(*) qty, COALESCE(SUM(CASE WHEN `payment_Status`=\'paid\' THEN `price` ELSE 0 END),0) amount FROM `Payment_report` GROUP BY label ORDER BY amount DESC LIMIT 20') : [];
        $recent = pw_table_exists($pdo, 'Payment_report') ? pw_rows($pdo, 'SELECT * FROM `Payment_report` ORDER BY `time` DESC LIMIT 25') : [];
        ?>
        <div class="power-grid power-kpis"><div class="power-kpi good"><span>کل پرداخت موفق</span><strong data-sensitive><?= pw_money($paidTotal) ?></strong></div><div class="power-kpi"><span>امروز</span><strong data-sensitive><?= pw_money($paidToday) ?></strong></div><div class="power-kpi warn"><span>این ماه</span><strong data-sensitive><?= pw_money($paidMonth) ?></strong></div><div class="power-kpi"><span>میانگین پرداخت</span><strong data-sensitive><?= pw_money($paidCount ? $paidTotal/$paidCount : 0) ?></strong></div></div>
        <div class="power-two"><section class="card power-card"><div class="card-head"><div><div class="card-title">عملکرد روش‌های پرداخت</div></div><a href="power_export.php?type=payments" class="btn-link">CSV تراکنش‌ها</a></div><div class="tbl-wrap"><table class="tbl-md"><thead><tr><th>روش</th><th>تعداد</th><th>موفق</th></tr></thead><tbody><?php foreach($methods as $r): ?><tr><td><?= pw_h($r['label']) ?></td><td><?= pw_num($r['qty']) ?></td><td data-sensitive><?= pw_money($r['amount']) ?></td></tr><?php endforeach; ?></tbody></table></div></section><section class="card power-card"><div class="card-head"><div><div class="card-title">سلامت پرداخت</div></div></div><div class="power-donut" data-good="<?= $paidCount ?>" data-bad="<?= $failedCount ?>"><strong><?= $paidCount+$failedCount ? number_format($paidCount/($paidCount+$failedCount)*100,1) : 0 ?>٪</strong><span>نرخ موفقیت ثبت‌شده</span></div><div class="power-mini-stats"><div><b><?= pw_num($paidCount) ?></b><span>موفق</span></div><div><b><?= pw_num($failedCount) ?></b><span>ناموفق/منقضی</span></div><div><b><?= pw_num($waitingPayments) ?></b><span>در انتظار</span></div></div></section></div>
        <section class="card power-card"><div class="card-head"><div><div class="card-title">آخرین تراکنش‌ها</div></div></div><div class="tbl-wrap"><table class="tbl-lg"><thead><tr><th>کاربر</th><th>شناسه</th><th>مبلغ</th><th>روش</th><th>زمان</th><th>وضعیت</th></tr></thead><tbody><?php foreach($recent as $r): [$cls,$lbl]=pw_status_tag((string)($r['payment_Status']??'')); ?><tr><td data-sensitive><?= pw_h($r['id_user']??'—') ?></td><td data-sensitive><?= pw_h($r['id_order']??'—') ?></td><td data-sensitive><?= pw_money($r['price']??0) ?></td><td><?= pw_h($r['Payment_Method']??'—') ?></td><td><?= safe_date($r['time']??null,'Y/m/d H:i') ?></td><td><span class="tag <?= pw_h($cls) ?>"><?= pw_h($lbl) ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>

    <?php elseif ($section === 'operations'):
        $dbOk = false; $dbVersion = '—';
        try { $dbOk = (bool)$pdo->query('SELECT 1')->fetchColumn(); $dbVersion = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION); } catch(Throwable) {}
        $tables = [];
        try { $names = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); foreach($names as $name){ $tables[]=['name'=>(string)$name,'rows'=>(int)pw_scalar($pdo,'SELECT COUNT(*) FROM '.pw_ident((string)$name))]; } } catch(Throwable) {}
        $diskFree = @disk_free_space(__DIR__) ?: 0; $diskTotal = @disk_total_space(__DIR__) ?: 0;
        $checks = [
            ['اتصال پایگاه داده',$dbOk,$dbOk?'اتصال برقرار است':'اتصال برقرار نشد'],
            ['افزونه PDO MySQL',extension_loaded('pdo_mysql'),'برای ارتباط دیتابیس لازم است'],
            ['افزونه JSON',extension_loaded('json'),'برای تنظیمات و گزارش‌ها'],
            ['افزونه OpenSSL',extension_loaded('openssl'),'برای عملیات رمزنگاری'],
            ['افزونه ZIP',extension_loaded('zip'),'اختیاری؛ برای بسته‌بندی فایل‌ها'],
            ['فشرده‌سازی Gzip',function_exists('gzopen'),'برای بکاپ کم‌حجم'],
            ['پوشه ذخیره‌سازی',is_writable(PW_STORAGE),'باید توسط PHP قابل نوشتن باشد'],
        ];
        ?>
        <div class="power-grid power-kpis"><div class="power-kpi <?= $dbOk?'good':'bad' ?>"><span>پایگاه داده</span><strong><?= $dbOk?'متصل':'خطا' ?></strong><small>MySQL <?= pw_h($dbVersion) ?></small></div><div class="power-kpi"><span>PHP</span><strong><?= pw_h(PHP_VERSION) ?></strong><small><?= pw_h(PHP_SAPI) ?></small></div><div class="power-kpi"><span>فضای خالی</span><strong><?= pw_file_size((int)$diskFree) ?></strong><small>از <?= pw_file_size((int)$diskTotal) ?></small></div><div class="power-kpi warn"><span>جداول شناسایی‌شده</span><strong><?= count($tables) ?></strong><small><?= pw_num(array_sum(array_column($tables,'rows'))) ?> رکورد</small></div></div>
        <div class="power-two"><section class="card power-card"><div class="card-head"><div><div class="card-title">بررسی پیش‌نیازها</div></div></div><div class="power-checks"><?php foreach($checks as [$name,$ok,$desc]): ?><div><span class="power-check <?= $ok?'ok':'no' ?>"><?= $ok?'✓':'!' ?></span><b><?= pw_h($name) ?></b><small><?= pw_h($desc) ?></small></div><?php endforeach; ?></div></section><section class="card power-card"><div class="card-head"><div><div class="card-title">اطلاعات اجرا</div></div></div><div class="kv-list"><div class="kv"><span class="kv-key">سیستم‌عامل</span><span class="kv-val cm"><?= pw_h(PHP_OS_FAMILY) ?></span></div><div class="kv"><span class="kv-key">سرور وب</span><span class="kv-val cm"><?= pw_h($_SERVER['SERVER_SOFTWARE']??'—') ?></span></div><div class="kv"><span class="kv-key">محدودیت حافظه</span><span class="kv-val cm"><?= pw_h(ini_get('memory_limit')) ?></span></div><div class="kv"><span class="kv-key">حداکثر زمان اجرا</span><span class="kv-val cm"><?= pw_h(ini_get('max_execution_time')) ?> ثانیه</span></div><div class="kv"><span class="kv-key">زمان سرور</span><span class="kv-val cm"><?= date('Y/m/d H:i:s') ?></span></div></div></section></div>
        <section class="card power-card"><div class="card-head"><div><div class="card-title">جداول پایگاه داده</div><div class="card-subtitle">نمایش نام و تعداد رکورد، بدون تغییر داده</div></div></div><div class="power-table-grid"><?php foreach($tables as $t): ?><div><b><?= pw_h($t['name']) ?></b><span><?= pw_num($t['rows']) ?> رکورد</span></div><?php endforeach; ?></div></section>

    <?php elseif ($section === 'security'):
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $cookie = session_get_cookie_params();
        $adminHashes = [];
        if (pw_table_exists($pdo,'admin')) $adminHashes = pw_rows($pdo,'SELECT `username`,`password` FROM `admin` LIMIT 50');
        $hashed = 0; foreach($adminHashes as $a){ if(str_starts_with((string)($a['password']??''),'$2') || str_starts_with((string)($a['password']??''),'$argon')) $hashed++; }
        $rootConfig = realpath(__DIR__.'/../config.php');
        $rootFunction = realpath(__DIR__.'/../function.php');
        $securityChecks = [
            ['HTTPS فعال',$https,'پنل مدیریت باید فقط روی HTTPS اجرا شود.'],
            ['Cookie فقط HTTPS',!empty($cookie['secure']),'ویژگی Secure برای نشست توصیه می‌شود.'],
            ['Cookie غیرقابل‌خواندن با JS',!empty($cookie['httponly']),'HttpOnly احتمال سرقت نشست را کاهش می‌دهد.'],
            ['SameSite نشست',!empty($cookie['samesite']),'حداقل Lax پیشنهاد می‌شود.'],
            ['نمایش خطا غیرفعال',!filter_var(ini_get('display_errors'),FILTER_VALIDATE_BOOLEAN),'خطاها نباید در محیط واقعی نمایش داده شوند.'],
            ['expose_php غیرفعال',!filter_var(ini_get('expose_php'),FILTER_VALIDATE_BOOLEAN),'نسخه PHP را مخفی می‌کند.'],
            ['رمزهای ادمین هش شده',count($adminHashes)===0 || $hashed===count($adminHashes),$hashed.' از '.count($adminHashes).' حساب دارای هش شناخته‌شده است.'],
            ['فایل تنظیمات محدود',!$rootConfig || ((fileperms($rootConfig)&0004)===0),'دسترسی عمومی خواندن به config بهتر است بسته باشد.'],
            ['فایل توابع محدود',!$rootFunction || ((fileperms($rootFunction)&0004)===0),'دسترسی عمومی خواندن بهتر است بسته باشد.'],
        ];
        $score = (int)round(array_sum(array_map(fn($x)=>$x[1]?1:0,$securityChecks))/count($securityChecks)*100);
        ?>
        <div class="power-security-score"><div class="power-score-ring" style="--score:<?= $score ?>"><strong><?= $score ?>٪</strong></div><div><h3>امتیاز تنظیمات امنیتی</h3><p>این امتیاز فقط براساس تنظیمات قابل مشاهده در محیط فعلی است و جای تست نفوذ را نمی‌گیرد.</p></div></div>
        <section class="card power-card"><div class="card-head"><div><div class="card-title">چک‌لیست امنیت</div></div></div><div class="power-security-list"><?php foreach($securityChecks as [$name,$ok,$desc]): ?><div class="<?= $ok?'pass':'fail' ?>"><span><?= $ok?'✓':'!' ?></span><div><b><?= pw_h($name) ?></b><small><?= pw_h($desc) ?></small></div></div><?php endforeach; ?></div></section>
        <section class="card power-card"><div class="card-head"><div><div class="card-title">پیشنهادهای تکمیلی</div></div></div><div class="power-recommendations"><div><b>ورود دومرحله‌ای</b><span>برای مدیران کد یک‌بارمصرف یا کلید امنیتی اضافه شود.</span></div><div><b>محدودسازی IP</b><span>در صورت ثابت‌بودن محل مدیریت، دسترسی پنل به IPهای مشخص محدود شود.</span></div><div><b>هدرهای امنیتی</b><span>CSP، HSTS، X-Frame-Options و Referrer-Policy روی وب‌سرور تنظیم شوند.</span></div><div><b>چرخه بکاپ</b><span>حداقل یک نسخه خارج از سرور و تست دوره‌ای بازیابی نگهداری شود.</span></div><div><b>ثبت رویدادها</b><span>عملیات حساس ادمین به‌همراه زمان، IP و نتیجه ثبت شود.</span></div><div><b>اصل حداقل دسترسی</b><span>کاربر MySQL فقط مجوزهای مورد نیاز ربات و پنل را داشته باشد.</span></div></div></section>

    <?php elseif ($section === 'backups'):
        $backups = pw_backup_files();
        ?>
        <section class="card power-card power-backup-hero"><div><h3>بکاپ مستقل پایگاه داده</h3><p>ساخت فایل SQL با ساختار جدول‌ها و داده‌ها. فایل‌های بکاپ در پوشه محافظت‌شده افزونه ذخیره می‌شوند.</p></div><form action="power_action.php" method="post" data-confirm="ساخت بکاپ ممکن است روی دیتابیس بزرگ زمان‌بر باشد. ادامه می‌دهید؟"><input type="hidden" name="_csrf" value="<?= pw_h(csrf_token()) ?>"><input type="hidden" name="action" value="create_backup"><button class="btn btn-primary" type="submit"><?= icon('plus',15) ?> ساخت بکاپ جدید</button></form></section>
        <div class="power-grid power-kpis"><div class="power-kpi"><span>تعداد بکاپ</span><strong><?= count($backups) ?></strong></div><div class="power-kpi"><span>حجم مجموع</span><strong><?= pw_file_size((int)array_sum(array_column($backups,'size'))) ?></strong></div><div class="power-kpi good"><span>آخرین بکاپ</span><strong><?= $backups?date('Y/m/d', $backups[0]['time']):'ندارد' ?></strong></div><div class="power-kpi warn"><span>فشرده‌سازی</span><strong><?= function_exists('gzopen')?'GZIP':'SQL' ?></strong></div></div>
        <section class="card power-card"><div class="card-head"><div><div class="card-title">نسخه‌های موجود</div><div class="card-subtitle">دانلود یا حذف امن از داخل نشست مدیریت</div></div></div><div class="tbl-wrap"><table class="tbl-md"><thead><tr><th>نام فایل</th><th>زمان</th><th>حجم</th><th>عملیات</th></tr></thead><tbody><?php foreach($backups as $b): ?><tr><td class="cm"><?= pw_h($b['name']) ?></td><td><?= date('Y/m/d H:i:s',$b['time']) ?></td><td><?= pw_file_size($b['size']) ?></td><td><div class="power-actions"><a class="btn btn-ghost btn-sm" href="power_download.php?file=<?= urlencode($b['name']) ?>&token=<?= urlencode(csrf_token()) ?>">دانلود</a><form action="power_action.php" method="post" data-confirm="این بکاپ حذف شود؟"><input type="hidden" name="_csrf" value="<?= pw_h(csrf_token()) ?>"><input type="hidden" name="action" value="delete_backup"><input type="hidden" name="file" value="<?= pw_h($b['name']) ?>"><button class="btn btn-no btn-sm">حذف</button></form></div></td></tr><?php endforeach; ?><?php if(!$backups): ?><tr><td colspan="4"><div class="empty">هنوز بکاپی ساخته نشده است.</div></td></tr><?php endif; ?></tbody></table></div></section>

    <?php elseif ($section === 'tools'):
        $notesFile = PW_STORAGE.'/notes-'.preg_replace('/[^A-Za-z0-9_.-]/','_',pw_admin()).'.json';
        $notes = pw_read_json($notesFile,['text'=>'','updated'=>0]);
        $dupePayments = pw_table_exists($pdo,'Payment_report') ? pw_rows($pdo,"SELECT `id_order`,COUNT(*) qty FROM `Payment_report` WHERE COALESCE(`id_order`,'')<>'' GROUP BY `id_order` HAVING COUNT(*)>1 ORDER BY qty DESC LIMIT 20") : [];
        $dupeUsers = pw_table_exists($pdo,'user') ? pw_rows($pdo,"SELECT `username`,COUNT(*) qty FROM `user` WHERE COALESCE(`username`,'') NOT IN ('','none') GROUP BY `username` HAVING COUNT(*)>1 ORDER BY qty DESC LIMIT 20") : [];
        $badPrices = pw_table_exists($pdo,'invoice') ? (int)pw_scalar($pdo,'SELECT COUNT(*) FROM `invoice` WHERE `price_product`<0') : 0;
        $orphanInvoices = pw_table_exists($pdo,'invoice') && pw_table_exists($pdo,'user') ? (int)pw_scalar($pdo,'SELECT COUNT(*) FROM `invoice` i LEFT JOIN `user` u ON CAST(u.`id` AS CHAR)=CAST(i.`id_user` AS CHAR) WHERE u.`id` IS NULL') : 0;
        ?>
        <div class="power-two"><section class="card power-card"><div class="card-head"><div><div class="card-title">یادداشت مدیر</div><div class="card-subtitle">ذخیره محلی در فایل افزونه</div></div></div><form action="power_action.php" method="post"><input type="hidden" name="_csrf" value="<?= pw_h(csrf_token()) ?>"><input type="hidden" name="action" value="save_notes"><textarea class="input power-notes" name="text" maxlength="10000" placeholder="کارهای مهم، یادآوری تمدید سرور، پیگیری پرداخت‌ها..."><?= pw_h($notes['text']??'') ?></textarea><div class="power-form-foot"><small><?= !empty($notes['updated'])?'آخرین تغییر: '.date('Y/m/d H:i',$notes['updated']):'هنوز ذخیره نشده' ?></small><button class="btn btn-primary">ذخیره یادداشت</button></div></form></section><section class="card power-card"><div class="card-head"><div><div class="card-title">خروجی سریع</div></div></div><div class="power-export-grid"><a href="power_export.php?type=users"><?= icon('users',18) ?><b>کاربران CSV</b><span>مشخصات و موجودی</span></a><a href="power_export.php?type=invoices"><?= icon('invoice',18) ?><b>سفارش‌ها CSV</b><span>محصول، مبلغ، وضعیت</span></a><a href="power_export.php?type=payments"><?= icon('card',18) ?><b>تراکنش‌ها CSV</b><span>روش و نتیجه پرداخت</span></a><a href="power_export.php?type=snapshot"><?= icon('chart',18) ?><b>تصویر JSON</b><span>خلاصه آماری پنل</span></a></div></section></div>
        <div class="power-grid power-kpis"><div class="power-kpi <?= $dupePayments?'bad':'good' ?>"><span>شناسه پرداخت تکراری</span><strong><?= count($dupePayments) ?></strong></div><div class="power-kpi <?= $dupeUsers?'warn':'good' ?>"><span>یوزرنیم تکراری</span><strong><?= count($dupeUsers) ?></strong></div><div class="power-kpi <?= $badPrices?'bad':'good' ?>"><span>قیمت منفی</span><strong><?= $badPrices ?></strong></div><div class="power-kpi <?= $orphanInvoices?'warn':'good' ?>"><span>سفارش بدون کاربر</span><strong><?= $orphanInvoices ?></strong></div></div>
        <div class="power-two"><section class="card power-card"><div class="card-head"><div><div class="card-title">پرداخت‌های تکراری احتمالی</div></div></div><div class="power-result-list"><?php foreach($dupePayments as $r): ?><a href="payment.php?q=<?= urlencode((string)$r['id_order']) ?>"><b data-sensitive><?= pw_h($r['id_order']) ?></b><span><?= pw_num($r['qty']) ?> بار ثبت</span></a><?php endforeach; ?><?php if(!$dupePayments): ?><div class="power-empty-good">موردی پیدا نشد.</div><?php endif; ?></div></section><section class="card power-card"><div class="card-head"><div><div class="card-title">یوزرنیم‌های تکراری احتمالی</div></div></div><div class="power-result-list"><?php foreach($dupeUsers as $r): ?><a href="users.php?q=<?= urlencode((string)$r['username']) ?>"><b data-sensitive>@<?= pw_h($r['username']) ?></b><span><?= pw_num($r['qty']) ?> کاربر</span></a><?php endforeach; ?><?php if(!$dupeUsers): ?><div class="power-empty-good">موردی پیدا نشد.</div><?php endif; ?></div></section></div>

    <?php elseif ($section === 'activity'):
        $activity = pw_activity(100);
        ?>
        <section class="card power-card"><div class="card-head"><div><div class="card-title">رویدادهای افزونه</div><div class="card-subtitle">بازدیدها، جست‌وجوها، بکاپ‌ها و تغییر تنظیمات افزونه</div></div><form action="power_action.php" method="post" data-confirm="گزارش رویدادهای افزونه پاک شود؟"><input type="hidden" name="_csrf" value="<?= pw_h(csrf_token()) ?>"><input type="hidden" name="action" value="clear_activity"><button class="btn btn-ghost btn-sm">پاک‌سازی گزارش</button></form></div><div class="power-timeline"><?php foreach($activity as $a): ?><div><span></span><section><b><?= pw_h($a['event']??'event') ?></b><small><?= date('Y/m/d H:i:s',(int)($a['time']??0)) ?> · <?= pw_h($a['admin']??'—') ?> · <em data-sensitive><?= pw_h($a['ip']??'—') ?></em></small><?php if(!empty($a['context'])): ?><code><?= pw_h(json_encode($a['context'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></code><?php endif; ?></section></div><?php endforeach; ?><?php if(!$activity): ?><div class="empty">رویدادی ثبت نشده است.</div><?php endif; ?></div></section>

    <?php elseif ($section === 'preferences'): ?>
        <section class="card power-card power-settings-card"><div class="card-head"><div><div class="card-title">تنظیمات اختصاصی افزونه</div><div class="card-subtitle">این تنظیمات به فایل‌های پنل اصلی وابسته نیستند.</div></div></div><form action="power_action.php" method="post"><input type="hidden" name="_csrf" value="<?= pw_h(csrf_token()) ?>"><input type="hidden" name="action" value="save_preferences"><div class="power-settings-grid"><div class="field"><label>تعداد روز نمودار داشبورد</label><input class="input" type="number" min="7" max="60" name="chart_days" value="<?= pw_h($settings['chart_days']) ?>"></div><div class="field"><label>بازه پیش‌فرض گزارش</label><select class="select" name="default_range"><?php foreach([7,14,30,60,90,180,365] as $d): ?><option value="<?= $d ?>" <?= (int)$settings['default_range']===$d?'selected':'' ?>><?= $d ?> روز</option><?php endforeach; ?></select></div><label class="power-switch"><input type="checkbox" name="privacy_mode" value="1" <?= !empty($settings['privacy_mode'])?'checked':'' ?>><span></span><div><b>حریم خصوصی پیش‌فرض</b><small>مقادیر حساس در ورود به افزونه مخفی شوند.</small></div></label><label class="power-switch"><input type="checkbox" name="compact_tables" value="1" <?= !empty($settings['compact_tables'])?'checked':'' ?>><span></span><div><b>جدول‌های فشرده</b><small>فاصله ردیف‌ها کمتر شود.</small></div></label><label class="power-switch"><input type="checkbox" name="show_system_alerts" value="1" <?= !empty($settings['show_system_alerts'])?'checked':'' ?>><span></span><div><b>هشدارهای سیستمی</b><small>هشدارهای امنیت و بکاپ نمایش داده شوند.</small></div></label></div><div class="power-form-foot"><span></span><button class="btn btn-primary"><?= icon('check',14) ?> ذخیره تنظیمات</button></div></form></section>
    <?php endif; ?>
</div>

<script src="addons/power/assets/power.js?v=<?= pw_h(PW_VERSION) ?>"></script>
<?php include __DIR__ . '/inc/layout_foot.php'; ?>
