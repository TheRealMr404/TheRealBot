<?php

declare(strict_types=1);

require_once __DIR__ . '/addons/power/bootstrap.php';

$name = pw_safe_backup_name((string) ($_GET['file'] ?? ''));
$token = (string) ($_GET['token'] ?? '');
if (!$name || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
    http_response_code(403);
    exit('دسترسی نامعتبر.');
}
$path = PW_BACKUPS . '/' . $name;
if (!is_file($path)) {
    http_response_code(404);
    exit('فایل پیدا نشد.');
}
pw_log('backup_downloaded', ['name' => $name]);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
