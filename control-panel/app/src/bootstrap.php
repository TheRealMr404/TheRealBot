<?php
declare(strict_types=1);

define('MIRZA_PANEL_DATA', getenv('MIRZA_PANEL_DATA') ?: '/var/lib/mirza-panel');
define('MIRZA_PANEL_DB', MIRZA_PANEL_DATA . '/panel.sqlite');
define('MIRZA_PANEL_SOCKET', getenv('MIRZA_PANEL_SOCKET') ?: '/run/mirza-panel/control.sock');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set(getenv('TZ') ?: 'Asia/Tehran');

if (PHP_SAPI !== 'cli') {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $sessionDir = MIRZA_PANEL_DATA . '/sessions';
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0770, true);
    }
    session_save_path($sessionDir);
    ini_set('session.use_strict_mode', '1');
    session_name('mirza_manager');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

function panel_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!is_dir(MIRZA_PANEL_DATA)) {
        mkdir(MIRZA_PANEL_DATA, 0770, true);
    }
    $pdo = new PDO('sqlite:' . MIRZA_PANEL_DB, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=5000');
    panel_schema($pdo);
    return $pdo;
}

function panel_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_login_at TEXT,
    last_login_ip TEXT
);
CREATE TABLE IF NOT EXISTS bots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    customer_name TEXT NOT NULL,
    customer_phone TEXT NOT NULL DEFAULT '',
    bot_username TEXT NOT NULL,
    admin_id TEXT NOT NULL,
    domain TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    backup_schedule TEXT NOT NULL DEFAULT 'daily',
    backup_retention INTEGER NOT NULL DEFAULT 7,
    status TEXT NOT NULL DEFAULT 'provisioning',
    live_status TEXT NOT NULL DEFAULT 'unknown',
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT
);
CREATE TABLE IF NOT EXISTS operations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id TEXT NOT NULL UNIQUE,
    bot_id INTEGER,
    operation TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    message TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    started_at TEXT,
    finished_at TEXT,
    FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER,
    event TEXT NOT NULL,
    bot_id INTEGER,
    context TEXT NOT NULL DEFAULT '{}',
    ip_address TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS login_attempts (
    key_hash TEXT PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    first_attempt_at INTEGER NOT NULL,
    blocked_until INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_bots_expiry ON bots(expires_at, status, deleted_at);
CREATE INDEX IF NOT EXISTS idx_operations_created ON operations(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at DESC);
SQL);
}

function panel_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function panel_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function panel_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function panel_is_authenticated(): bool
{
    return isset($_SESSION['admin_id'], $_SESSION['auth_time'])
        && (time() - (int) $_SESSION['auth_time']) < 43200;
}

function panel_require_auth(bool $json = false): void
{
    if (panel_is_authenticated()) {
        $_SESSION['auth_time'] = time();
        return;
    }
    if ($json) {
        panel_json(['ok' => false, 'message' => 'نشست شما منقضی شده است.'], 401);
    }
    header('Location: login.php');
    exit;
}

function panel_csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function panel_verify_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
        panel_json(['ok' => false, 'message' => 'درخواست نامعتبر است؛ صفحه را تازه کنید.'], 419);
    }
}

function panel_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function panel_audit(string $event, ?int $botId = null, array $context = []): void
{
    $stmt = panel_db()->prepare('INSERT INTO audit_log (admin_id,event,bot_id,context,ip_address,created_at) VALUES (?,?,?,?,?,?)');
    $stmt->execute([
        $_SESSION['admin_id'] ?? null,
        $event,
        $botId,
        json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        panel_client_ip(),
        panel_now(),
    ]);
}

function panel_job_id(): string
{
    return bin2hex(random_bytes(16));
}

function panel_agent(array $request, float $timeout = 5.0): array
{
    $socket = @stream_socket_client('unix://' . MIRZA_PANEL_SOCKET, $errno, $error, $timeout);
    if (!is_resource($socket)) {
        throw new RuntimeException('ارتباط با سرویس مدیریت برقرار نشد.');
    }
    stream_set_timeout($socket, (int) max(1, ceil($timeout)));
    $encoded = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($encoded) > 65536 || fwrite($socket, $encoded . "\n") === false) {
        fclose($socket);
        throw new RuntimeException('ارسال فرمان مدیریت ناموفق بود.');
    }
    $response = fgets($socket, 65537);
    fclose($socket);
    if (!is_string($response) || $response === '') {
        throw new RuntimeException('پاسخ معتبری از سرویس مدیریت دریافت نشد.');
    }
    $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('پاسخ سرویس مدیریت قابل پردازش نیست.');
    }
    return $decoded;
}

function panel_fa_status(string $status): string
{
    return [
        'active' => 'فعال',
        'provisioning' => 'در حال ساخت',
        'suspended' => 'متوقف',
        'expired' => 'منقضی',
        'error' => 'نیازمند بررسی',
        'deleted' => 'حذف‌شده',
        'healthy' => 'سالم',
        'running' => 'در حال اجرا',
        'stopped' => 'خاموش',
        'unhealthy' => 'ناسالم',
        'exited' => 'خاموش',
        'created' => 'آماده شروع',
        'restarting' => 'در حال راه‌اندازی',
        'paused' => 'مکث‌شده',
        'dead' => 'متوقف کامل',
        'unknown' => 'نامشخص',
        'queued' => 'در صف',
        'working' => 'در حال انجام',
        'success' => 'موفق',
        'failed' => 'ناموفق',
        'create' => 'ساخت ربات',
        'start' => 'روشن کردن',
        'stop' => 'متوقف کردن',
        'restart' => 'راه‌اندازی مجدد',
        'update' => 'بروزرسانی',
        'backup' => 'ساخت بکاپ',
        'restore' => 'بازیابی بکاپ',
        'remove' => 'حذف ربات',
        'schedule' => 'تنظیم بکاپ',
        'expire' => 'پایان اعتبار',
    ][$status] ?? $status;
}

function panel_icon(string $name, int $size = 18): string
{
    $icons = [
        'grid' => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
        'bot' => '<rect width="18" height="10" x="3" y="11" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4M8 16h.01M16 16h.01"/>',
        'plus' => '<path d="M5 12h14M12 5v14"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'activity' => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'settings' => '<path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56V21h-4v-.09A1.7 1.7 0 0 0 9 19.35a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.63 15 1.7 1.7 0 0 0 3.07 14H3v-4h.09A1.7 1.7 0 0 0 4.65 9a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.63 1.7 1.7 0 0 0 10 3.07V3h4v.09A1.7 1.7 0 0 0 15 4.65a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.37 9 1.7 1.7 0 0 0 20.93 10H21v4h-.09A1.7 1.7 0 0 0 19.4 15Z"/>',
        'refresh' => '<path d="M20 6v5h-5M4 18v-5h5"/><path d="M18.5 9A7 7 0 0 0 6 6.5L4 9m2 6a7 7 0 0 0 12 2.5l2-2.5"/>',
        'play' => '<path d="m8 5 11 7-11 7Z"/>',
        'pause' => '<path d="M9 5v14M15 5v14"/>',
        'rotate' => '<path d="M20 12a8 8 0 1 1-2.34-5.66L20 8M20 3v5h-5"/>',
        'download' => '<path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"/>',
        'trash' => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 15H6L5 6M10 11v5M14 11v5"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'logout' => '<path d="M10 17l5-5-5-5M15 12H3M15 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'x' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'copy' => '<rect width="14" height="14" x="7" y="7" rx="2"/><path d="M17 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h2"/>',
        'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'external' => '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
        'more' => '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'alert' => '<path d="M10.3 3.7 2.8 17a2 2 0 0 0 1.7 3h15a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0ZM12 9v4M12 17h.01"/>',
    ];
    $body = $icons[$name] ?? $icons['activity'];
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

panel_db();
