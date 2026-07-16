<?php

declare(strict_types=1);

require_once __DIR__ . '/../../inc/config.php';
require_once __DIR__ . '/../../inc/icons.php';
require_auth();

const PW_VERSION = '2.0.0';

$pwPreferredStorage = dirname(__DIR__, 3) . '/storage/panel-power';
$pwFallbackStorage = __DIR__ . '/storage';
if (!is_dir($pwPreferredStorage)) {
    @mkdir($pwPreferredStorage, 0750, true);
}
$pwStorage = is_dir($pwPreferredStorage) && is_writable($pwPreferredStorage)
    ? $pwPreferredStorage
    : $pwFallbackStorage;
if (!is_dir($pwStorage)) {
    @mkdir($pwStorage, 0750, true);
}
define('PW_STORAGE', $pwStorage);
define('PW_BACKUPS', PW_STORAGE . '/backups');

function pw_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pw_admin(): string
{
    return (string) ($_SESSION['admin_user'] ?? 'admin');
}

function pw_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        throw new InvalidArgumentException('شناسه پایگاه داده نامعتبر است.');
    }
    return '`' . $name . '`';
}

function pw_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return $cache[$table] = false;
    }
}

function pw_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    if (!pw_table_exists($pdo, $table)) {
        return $cache[$table] = [];
    }
    try {
        $rows = $pdo->query('DESCRIBE ' . pw_ident($table))->fetchAll(PDO::FETCH_ASSOC);
        return $cache[$table] = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['Field'] ?? ''),
            $rows
        )));
    } catch (Throwable) {
        return $cache[$table] = [];
    }
}

function pw_has_col(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, pw_columns($pdo, $table), true);
}

function pw_scalar(PDO $pdo, string $sql, array $params = [], int|float|string $fallback = 0): int|float|string
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $fallback : $value;
    } catch (Throwable) {
        return $fallback;
    }
}

function pw_rows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function pw_money(int|float|string|null $amount): string
{
    return number_format((float) ($amount ?? 0), 0, '.', ',') . ' ت';
}

function pw_num(int|float|string|null $number): string
{
    return number_format((float) ($number ?? 0), 0, '.', ',');
}

function pw_atomic_json(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0640);
    return @rename($tmp, $path);
}

function pw_read_json(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : $fallback;
}

function pw_settings(): array
{
    $defaults = [
        'chart_days' => 14,
        'privacy_mode' => false,
        'compact_tables' => false,
        'default_range' => 30,
        'show_system_alerts' => true,
    ];
    $key = preg_replace('/[^A-Za-z0-9_.-]/', '_', pw_admin());
    return array_replace($defaults, pw_read_json(PW_STORAGE . '/settings-' . $key . '.json'));
}

function pw_log(string $event, array $context = []): void
{
    if (!is_dir(PW_STORAGE)) {
        @mkdir(PW_STORAGE, 0750, true);
    }
    $row = [
        'time' => time(),
        'admin' => pw_admin(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'event' => $event,
        'context' => $context,
    ];
    @file_put_contents(
        PW_STORAGE . '/activity.jsonl',
        json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function pw_activity(int $limit = 30): array
{
    $file = PW_STORAGE . '/activity.jsonl';
    if (!is_file($file)) {
        return [];
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -max(1, min(200, $limit)));
    $out = [];
    foreach (array_reverse($lines) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

function pw_range(): array
{
    $settings = pw_settings();
    $days = max(1, min(365, (int) ($_GET['days'] ?? $settings['default_range'])));
    $fromRaw = trim((string) ($_GET['from'] ?? ''));
    $toRaw = trim((string) ($_GET['to'] ?? ''));

    $to = $toRaw !== '' ? strtotime($toRaw . ' 23:59:59') : time();
    $from = $fromRaw !== '' ? strtotime($fromRaw . ' 00:00:00') : strtotime('-' . ($days - 1) . ' days midnight');
    if (!$from || !$to || $from > $to) {
        $to = time();
        $from = strtotime('-' . ($days - 1) . ' days midnight');
    }
    return [(int) $from, (int) $to, date('Y-m-d', (int) $from), date('Y-m-d', (int) $to)];
}

function pw_day_series(PDO $pdo, string $table, string $timeColumn, ?string $sumColumn, int $from, int $to): array
{
    if (!pw_table_exists($pdo, $table) || !pw_has_col($pdo, $table, $timeColumn)) {
        return [];
    }
    $select = $sumColumn && pw_has_col($pdo, $table, $sumColumn)
        ? 'COALESCE(SUM(' . pw_ident($sumColumn) . '),0)'
        : 'COUNT(*)';
    $sql = 'SELECT DATE(FROM_UNIXTIME(' . pw_ident($timeColumn) . ')) d, ' . $select . ' v '
        . 'FROM ' . pw_ident($table) . ' WHERE ' . pw_ident($timeColumn) . ' BETWEEN ? AND ? '
        . 'GROUP BY d ORDER BY d';
    $raw = pw_rows($pdo, $sql, [$from, $to]);
    $map = [];
    foreach ($raw as $row) {
        $map[(string) $row['d']] = (float) $row['v'];
    }
    $series = [];
    for ($d = strtotime(date('Y-m-d', $from)); $d <= $to; $d += 86400) {
        $key = date('Y-m-d', $d);
        $series[] = ['date' => $key, 'value' => $map[$key] ?? 0];
    }
    return $series;
}

function pw_status_tag(string $status): array
{
    $s = strtolower(trim($status));
    if (in_array($s, ['active', 'paid', 'success', 'completed'], true)) {
        return ['tag-ok', $status ?: 'فعال'];
    }
    if (in_array($s, ['waiting', 'pending', 'send_on_hold', 'sendedwarn'], true)) {
        return ['tag-warn', $status ?: 'در انتظار'];
    }
    if (in_array($s, ['block', 'blocked', 'reject', 'rejected', 'unsuccessful', 'unpaid', 'end_of_volume'], true)) {
        return ['tag-no', $status ?: 'ناموفق'];
    }
    return ['tag-plain', $status ?: '—'];
}

function pw_backup_files(): array
{
    if (!is_dir(PW_BACKUPS)) {
        return [];
    }
    $files = [];
    foreach (glob(PW_BACKUPS . '/*.sql*') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $files[] = [
            'name' => basename($path),
            'size' => filesize($path) ?: 0,
            'time' => filemtime($path) ?: 0,
        ];
    }
    usort($files, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);
    return $files;
}

function pw_file_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $value = max(0, $bytes);
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return number_format($value, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

function pw_safe_backup_name(string $name): ?string
{
    if (!preg_match('/^mirzabot-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.sql(?:\.gz)?$/', $name)) {
        return null;
    }
    return $name;
}

function pw_find_first_column(PDO $pdo, string $table, array $candidates): ?string
{
    $cols = pw_columns($pdo, $table);
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $cols, true)) {
            return $candidate;
        }
    }
    return null;
}

function pw_url(array $replace = []): string
{
    $params = array_merge($_GET, $replace);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        }
    }
    return 'power.php' . ($params ? '?' . http_build_query($params) : '');
}
