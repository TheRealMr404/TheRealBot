<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
panel_require_auth(true);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
if ($method !== 'GET') {
    panel_verify_csrf();
}

function api_input(): array
{
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($type, 'application/json')) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

function api_required_string(array $data, string $key, int $max = 255): string
{
    $value = trim((string) ($data[$key] ?? ''));
    if ($value === '' || mb_strlen($value) > $max) {
        throw new InvalidArgumentException('اطلاعات واردشده کامل یا معتبر نیست.');
    }
    return $value;
}

function api_bot(string $slug): array
{
    $stmt = panel_db()->prepare('SELECT * FROM bots WHERE slug=? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$slug]);
    $bot = $stmt->fetch();
    if (!$bot) {
        throw new InvalidArgumentException('ربات موردنظر پیدا نشد.');
    }
    return $bot;
}

function api_enqueue(array $bot, string $operation, array $payload = []): string
{
    $allowed = ['create', 'start', 'stop', 'restart', 'update', 'backup', 'restore', 'remove', 'schedule'];
    if (!in_array($operation, $allowed, true)) {
        throw new InvalidArgumentException('عملیات انتخاب‌شده مجاز نیست.');
    }
    $jobId = panel_job_id();
    $pdo = panel_db();
    $pending = $pdo->prepare("SELECT id FROM operations WHERE bot_id=? AND status IN ('queued','working') LIMIT 1");
    $pending->execute([$bot['id']]);
    if ($pending->fetchColumn()) {
        throw new InvalidArgumentException('یک عملیات دیگر برای این ربات در حال انجام است.');
    }
    $stmt = $pdo->prepare('INSERT INTO operations (job_id,bot_id,operation,status,message,created_at) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$jobId, $bot['id'], $operation, 'queued', 'در صف اجرای امن روی سرور', panel_now()]);
    try {
        $response = panel_agent([
            'action' => 'enqueue',
            'job_id' => $jobId,
            'operation' => $operation,
            'slug' => $bot['slug'],
            'payload' => $payload,
        ]);
        if (empty($response['ok'])) {
            throw new RuntimeException((string) ($response['message'] ?? 'فرمان پذیرفته نشد.'));
        }
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE operations SET status='failed',message=?,finished_at=? WHERE job_id=?")
            ->execute(['ارتباط با سرویس مدیریت برقرار نشد.', panel_now(), $jobId]);
        throw $e;
    }
    panel_audit('bot.' . $operation . '.queued', (int) $bot['id']);
    return $jobId;
}

try {
    $data = api_input();
    $pdo = panel_db();

    if ($method === 'GET' && $action === 'snapshot') {
        $response = panel_agent(['action' => 'list']);
        $ops = $pdo->query("SELECT o.*,b.slug,b.customer_name FROM operations o LEFT JOIN bots b ON b.id=o.bot_id ORDER BY o.id DESC LIMIT 20")->fetchAll();
        panel_json(['ok' => true, 'live' => $response['bots'] ?? [], 'operations' => $ops]);
    }

    if ($method === 'GET' && $action === 'backups') {
        $slug = trim((string) ($_GET['slug'] ?? ''));
        api_bot($slug);
        $response = panel_agent(['action' => 'backups', 'slug' => $slug]);
        panel_json($response, !empty($response['ok']) ? 200 : 400);
    }

    if ($method === 'GET' && $action === 'logs') {
        $slug = trim((string) ($_GET['slug'] ?? ''));
        api_bot($slug);
        $response = panel_agent(['action' => 'logs', 'slug' => $slug], 8.0);
        panel_json($response, !empty($response['ok']) ? 200 : 400);
    }

    if ($method === 'POST' && $action === 'create') {
        $customer = api_required_string($data, 'customer_name', 100);
        $phone = trim((string) ($data['customer_phone'] ?? ''));
        $slug = strtolower(api_required_string($data, 'slug', 31));
        $username = ltrim(api_required_string($data, 'bot_username', 32), '@');
        $token = api_required_string($data, 'token', 100);
        $adminId = api_required_string($data, 'admin_id', 24);
        $domain = strtolower(api_required_string($data, 'domain', 253));
        $expiryInput = api_required_string($data, 'expires_at', 40);
        $schedule = (string) ($data['backup_schedule'] ?? 'daily');
        $retention = (int) ($data['backup_retention'] ?? 7);
        $notes = trim((string) ($data['notes'] ?? ''));

        if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $slug)) {
            throw new InvalidArgumentException('شناسه نصب باید با حرف انگلیسی شروع شود و فقط حروف کوچک، عدد و خط تیره داشته باشد.');
        }
        if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
            throw new InvalidArgumentException('نام کاربری ربات معتبر نیست.');
        }
        if (!preg_match('/^[0-9]{5,15}:[A-Za-z0-9_-]{20,80}$/', $token)) {
            throw new InvalidArgumentException('قالب توکن تلگرام معتبر نیست.');
        }
        if (!preg_match('/^-?[0-9]{5,20}$/', $adminId)) {
            throw new InvalidArgumentException('آیدی عددی مدیر معتبر نیست.');
        }
        if (!preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new InvalidArgumentException('دامنه واردشده معتبر نیست.');
        }
        if (mb_strlen($phone) > 32 || mb_strlen($notes) > 1000) {
            throw new InvalidArgumentException('طول اطلاعات واردشده بیش از حد مجاز است.');
        }
        if (!in_array($schedule, ['daily', 'weekly', 'off'], true) || $retention < 1 || $retention > 60) {
            throw new InvalidArgumentException('تنظیمات بکاپ معتبر نیست.');
        }
        $expiry = new DateTimeImmutable($expiryInput, new DateTimeZone(date_default_timezone_get()));
        if ($expiry->getTimestamp() <= time() + 300) {
            throw new InvalidArgumentException('زمان پایان باید حداقل پنج دقیقه بعد از زمان فعلی باشد.');
        }
        $expiresAt = $expiry->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        $now = panel_now();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO bots (slug,customer_name,customer_phone,bot_username,admin_id,domain,expires_at,backup_schedule,backup_retention,status,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$slug, $customer, $phone, $username, $adminId, $domain, $expiresAt, $schedule, $retention, 'provisioning', $notes, $now, $now]);
            $bot = api_bot($slug);
            $jobId = api_enqueue($bot, 'create', [
                'bot_username' => $username,
                'token' => $token,
                'admin_id' => $adminId,
                'domain' => $domain,
                'backup_schedule' => $schedule,
                'backup_retention' => $retention,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        panel_audit('bot.created', (int) $bot['id'], ['customer' => $customer]);
        panel_json(['ok' => true, 'job_id' => $jobId, 'message' => 'ساخت ربات در صف قرار گرفت. وضعیت به‌صورت خودکار بروزرسانی می‌شود.']);
    }

    if ($method === 'POST' && $action === 'operate') {
        $slug = api_required_string($data, 'slug', 31);
        $operation = api_required_string($data, 'operation', 20);
        if (!in_array($operation, ['start', 'stop', 'restart', 'update', 'backup', 'restore', 'remove'], true)) {
            throw new InvalidArgumentException('عملیات انتخاب‌شده مجاز نیست.');
        }
        $bot = api_bot($slug);
        $payload = [];
        if ($operation === 'restore') {
            $payload['backup'] = api_required_string($data, 'backup', 255);
        }
        $jobId = api_enqueue($bot, $operation, $payload);
        panel_json(['ok' => true, 'job_id' => $jobId, 'message' => 'عملیات در صف اجرا قرار گرفت.']);
    }

    if ($method === 'POST' && $action === 'extend') {
        $slug = api_required_string($data, 'slug', 31);
        $bot = api_bot($slug);
        $expiryInput = api_required_string($data, 'expires_at', 40);
        $expiry = new DateTimeImmutable($expiryInput, new DateTimeZone(date_default_timezone_get()));
        if ($expiry->getTimestamp() <= time() + 300) {
            throw new InvalidArgumentException('زمان تمدید باید بعد از زمان فعلی باشد.');
        }
        $expiresAt = $expiry->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $newStatus = $bot['status'] === 'expired' ? 'suspended' : $bot['status'];
        $pdo->prepare('UPDATE bots SET expires_at=?,status=?,updated_at=? WHERE id=?')
            ->execute([$expiresAt, $newStatus, panel_now(), $bot['id']]);
        panel_audit('bot.extended', (int) $bot['id'], ['expires_at' => $expiresAt]);
        panel_json(['ok' => true, 'message' => 'اعتبار ربات با موفقیت تمدید شد. برای سرویس‌دهی، ربات را روشن کنید.']);
    }

    if ($method === 'POST' && $action === 'change_password') {
        $current = (string) ($data['current_password'] ?? '');
        $new = (string) ($data['new_password'] ?? '');
        $confirm = (string) ($data['confirm_password'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM admins WHERE id=?');
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($current, (string) $admin['password_hash'])) {
            throw new InvalidArgumentException('رمز فعلی درست نیست.');
        }
        if ($new !== $confirm || strlen($new) < 12 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            throw new InvalidArgumentException('رمز جدید باید حداقل ۱۲ کاراکتر و شامل حرف و عدد باشد.');
        }
        $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
        panel_audit('admin.password_changed');
        session_regenerate_id(true);
        panel_json(['ok' => true, 'message' => 'رمز عبور با موفقیت تغییر کرد.']);
    }

    panel_json(['ok' => false, 'message' => 'درخواست شناخته نشد.'], 404);
} catch (PDOException $e) {
    error_log('Mirza panel database error: ' . $e->getMessage());
    $message = str_contains(strtolower($e->getMessage()), 'unique')
        ? 'این شناسه نصب یا دامنه قبلاً ثبت شده است.'
        : 'ثبت اطلاعات در پایگاه داده ناموفق بود.';
    panel_json(['ok' => false, 'message' => $message], 409);
} catch (InvalidArgumentException $e) {
    panel_json(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('Mirza panel API error: ' . $e->getMessage());
    panel_json(['ok' => false, 'message' => 'عملیات انجام نشد. ارتباط سرویس‌ها و گزارش سرور را بررسی کنید.'], 500);
}
