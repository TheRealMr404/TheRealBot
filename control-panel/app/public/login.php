<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

if (panel_is_authenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!is_string($csrf) || !hash_equals(panel_csrf(), $csrf)) {
        $error = 'درخواست نامعتبر است. صفحه را تازه کنید.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $key = hash('sha256', panel_client_ip() . '|' . strtolower($username));
        $pdo = panel_db();
        $attempt = $pdo->prepare('SELECT * FROM login_attempts WHERE key_hash=?');
        $attempt->execute([$key]);
        $rate = $attempt->fetch();
        $now = time();

        if ($rate && (int) $rate['blocked_until'] > $now) {
            $wait = max(1, (int) ceil(((int) $rate['blocked_until'] - $now) / 60));
            $error = "ورود موقتاً محدود شده است. {$wait} دقیقه دیگر تلاش کنید.";
        } else {
            $stmt = $pdo->prepare('SELECT * FROM admins WHERE username=? LIMIT 1');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();
            if ($admin && password_verify($password, (string) $admin['password_hash'])) {
                $pdo->prepare('DELETE FROM login_attempts WHERE key_hash=?')->execute([$key]);
                $pdo->prepare('UPDATE admins SET last_login_at=?,last_login_ip=? WHERE id=?')
                    ->execute([panel_now(), panel_client_ip(), $admin['id']]);
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int) $admin['id'];
                $_SESSION['admin_username'] = (string) $admin['username'];
                $_SESSION['auth_time'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                panel_audit('admin.login');
                header('Location: index.php');
                exit;
            }

            $first = $rate ? (int) $rate['first_attempt_at'] : $now;
            $count = ($rate && ($now - $first) < 900) ? ((int) $rate['attempts'] + 1) : 1;
            $first = ($rate && ($now - $first) < 900) ? $first : $now;
            $blocked = $count >= 5 ? $now + 900 : 0;
            $pdo->prepare('INSERT INTO login_attempts (key_hash,attempts,first_attempt_at,blocked_until) VALUES (?,?,?,?) ON CONFLICT(key_hash) DO UPDATE SET attempts=excluded.attempts,first_attempt_at=excluded.first_attempt_at,blocked_until=excluded.blocked_until')
                ->execute([$key, $count, $first, $blocked]);
            usleep(random_int(250000, 500000));
            $error = 'نام کاربری یا رمز عبور درست نیست.';
        }
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>ورود | مرکز مدیریت ربات‌ها</title>
    <link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body class="login-page">
<main class="login-shell">
    <section class="login-brand" aria-label="مرکز مدیریت ربات‌ها">
        <div class="brand-mark"><?= panel_icon('bot', 28) ?></div>
        <div>
            <strong>Mirza Control</strong>
            <span>مرکز مدیریت ربات‌ها</span>
        </div>
    </section>
    <section class="login-panel">
        <div class="login-heading">
            <span class="secure-badge"><?= panel_icon('shield', 15) ?> دسترسی امن مدیر</span>
            <h1>ورود به پنل</h1>
            <p>برای مدیریت نصب‌ها وارد حساب مدیر شوید.</p>
        </div>
        <?php if ($error !== ''): ?>
            <div class="notice notice-error" role="alert"><?= panel_icon('alert', 17) ?><?= panel_h($error) ?></div>
        <?php endif; ?>
        <form method="post" class="login-form" autocomplete="on">
            <input type="hidden" name="_csrf" value="<?= panel_h(panel_csrf()) ?>">
            <label class="field">
                <span>نام کاربری</span>
                <input name="username" type="text" required maxlength="64" autocomplete="username" autofocus>
            </label>
            <label class="field">
                <span>رمز عبور</span>
                <input name="password" type="password" required maxlength="256" autocomplete="current-password">
            </label>
            <button class="btn btn-primary btn-block" type="submit">ورود به مدیریت</button>
        </form>
    </section>
    <p class="login-foot">نشست‌ها رمزگذاری و ورودهای ناموفق محدود می‌شوند.</p>
</main>
</body>
</html>
