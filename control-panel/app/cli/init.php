<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$username = trim((string) ($argv[1] ?? 'admin'));
$password = trim((string) stream_get_contents(STDIN));
if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) {
    fwrite(STDERR, "Invalid username\n");
    exit(2);
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Password must contain at least 12 characters\n");
    exit(3);
}

$pdo = panel_db();
$stmt = $pdo->prepare('SELECT id FROM admins WHERE username=?');
$stmt->execute([$username]);
$id = $stmt->fetchColumn();
$hash = password_hash($password, PASSWORD_DEFAULT);
if ($id) {
    $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([$hash, $id]);
} else {
    $pdo->prepare('INSERT INTO admins (username,password_hash,created_at) VALUES (?,?,?)')
        ->execute([$username, $hash, panel_now()]);
}
fwrite(STDOUT, "ADMIN_READY\n");
