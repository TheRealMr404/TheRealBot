<?php

declare(strict_types=1);

require_once __DIR__ . '/addons/power/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}
csrf_check_post();

$action = (string) ($_POST['action'] ?? '');

function power_redirect(string $section, string $type, string $message): never
{
    flash($type, $message);
    header('Location: power.php?section=' . rawurlencode($section));
    exit;
}

function power_sql_value(PDO $pdo, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return $pdo->quote((string) $value);
}

function power_create_backup(PDO $pdo): array
{
    if (!is_dir(PW_BACKUPS) && !@mkdir(PW_BACKUPS, 0750, true) && !is_dir(PW_BACKUPS)) {
        throw new RuntimeException('پوشه بکاپ قابل ساخت نیست.');
    }

    $suffix = bin2hex(random_bytes(3));
    $base = 'mirzabot-' . date('Ymd-His') . '-' . $suffix . '.sql';
    $useGzip = function_exists('gzopen');
    $name = $base . ($useGzip ? '.gz' : '');
    $path = PW_BACKUPS . '/' . $name;

    if ($useGzip) {
        $handle = gzopen($path, 'wb9');
        $write = static function (string $text) use ($handle): void {
            if (gzwrite($handle, $text) === false) {
                throw new RuntimeException('خطا هنگام نوشتن بکاپ فشرده.');
            }
        };
    } else {
        $handle = fopen($path, 'wb');
        $write = static function (string $text) use ($handle): void {
            if (fwrite($handle, $text) === false) {
                throw new RuntimeException('خطا هنگام نوشتن بکاپ.');
            }
        };
    }

    if (!$handle) {
        throw new RuntimeException('امکان ساخت فایل بکاپ وجود ندارد.');
    }

    try {
        $write("-- MirzaBot Power Suite database backup\n");
        $write('-- Generated: ' . date(DATE_ATOM) . "\n");
        $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $rowCount = 0;
        foreach ($tables as $table) {
            $table = (string) $table;
            $quotedTable = pw_ident($table);
            $createRow = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
            if (!$createRow || empty($createRow[1])) {
                continue;
            }

            $write("\n-- Table: {$table}\nDROP TABLE IF EXISTS {$quotedTable};\n");
            $write((string) $createRow[1] . ";\n\n");

            $stmt = $pdo->query('SELECT * FROM ' . $quotedTable);
            $columns = [];
            $batch = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!$columns) {
                    $columns = array_keys($row);
                }
                $values = array_map(static fn(mixed $v): string => power_sql_value($pdo, $v), array_values($row));
                $batch[] = '(' . implode(',', $values) . ')';
                $rowCount++;
                if (count($batch) >= 100) {
                    $colSql = implode(',', array_map('pw_ident', $columns));
                    $write("INSERT INTO {$quotedTable} ({$colSql}) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch && $columns) {
                $colSql = implode(',', array_map('pw_ident', $columns));
                $write("INSERT INTO {$quotedTable} ({$colSql}) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
        }
        $write("\nSET FOREIGN_KEY_CHECKS=1;\n");
    } catch (Throwable $e) {
        if ($useGzip) {
            gzclose($handle);
        } else {
            fclose($handle);
        }
        @unlink($path);
        throw $e;
    }

    if ($useGzip) {
        gzclose($handle);
    } else {
        fclose($handle);
    }
    @chmod($path, 0640);
    return ['name' => $name, 'size' => filesize($path) ?: 0, 'rows' => $rowCount ?? 0];
}

try {
    switch ($action) {
        case 'create_backup':
            $result = power_create_backup($pdo);
            pw_log('backup_created', $result);
            power_redirect('backups', 'success', 'بکاپ با موفقیت ساخته شد: ' . $result['name'] . ' (' . pw_file_size((int) $result['size']) . ')');

        case 'delete_backup':
            $name = pw_safe_backup_name((string) ($_POST['file'] ?? ''));
            if (!$name) {
                throw new RuntimeException('نام فایل بکاپ نامعتبر است.');
            }
            $path = PW_BACKUPS . '/' . $name;
            if (!is_file($path)) {
                throw new RuntimeException('فایل بکاپ پیدا نشد.');
            }
            if (!@unlink($path)) {
                throw new RuntimeException('حذف فایل بکاپ انجام نشد.');
            }
            pw_log('backup_deleted', ['name' => $name]);
            power_redirect('backups', 'success', 'بکاپ حذف شد.');

        case 'save_notes':
            $text = trim((string) ($_POST['text'] ?? ''));
            if (mb_strlen($text) > 10000) {
                throw new RuntimeException('یادداشت بیش از حد طولانی است.');
            }
            $key = preg_replace('/[^A-Za-z0-9_.-]/', '_', pw_admin());
            if (!pw_atomic_json(PW_STORAGE . '/notes-' . $key . '.json', ['text' => $text, 'updated' => time()])) {
                throw new RuntimeException('ذخیره یادداشت انجام نشد. دسترسی پوشه را بررسی کنید.');
            }
            pw_log('notes_saved', ['length' => mb_strlen($text)]);
            power_redirect('tools', 'success', 'یادداشت مدیر ذخیره شد.');

        case 'save_preferences':
            $chartDays = max(7, min(60, (int) ($_POST['chart_days'] ?? 14)));
            $defaultRange = (int) ($_POST['default_range'] ?? 30);
            if (!in_array($defaultRange, [7, 14, 30, 60, 90, 180, 365], true)) {
                $defaultRange = 30;
            }
            $data = [
                'chart_days' => $chartDays,
                'default_range' => $defaultRange,
                'privacy_mode' => isset($_POST['privacy_mode']),
                'compact_tables' => isset($_POST['compact_tables']),
                'show_system_alerts' => isset($_POST['show_system_alerts']),
            ];
            $key = preg_replace('/[^A-Za-z0-9_.-]/', '_', pw_admin());
            if (!pw_atomic_json(PW_STORAGE . '/settings-' . $key . '.json', $data)) {
                throw new RuntimeException('تنظیمات ذخیره نشد.');
            }
            pw_log('preferences_saved', ['settings' => $data]);
            power_redirect('preferences', 'success', 'تنظیمات افزونه ذخیره شد.');

        case 'clear_activity':
            $file = PW_STORAGE . '/activity.jsonl';
            if (is_file($file) && !@unlink($file)) {
                throw new RuntimeException('پاک‌سازی گزارش انجام نشد.');
            }
            power_redirect('activity', 'success', 'گزارش رویدادهای افزونه پاک شد.');

        default:
            throw new RuntimeException('عملیات ناشناخته است.');
    }
} catch (Throwable $e) {
    pw_log('action_failed', ['action' => $action, 'message' => $e->getMessage()]);
    $section = match ($action) {
        'create_backup', 'delete_backup' => 'backups',
        'save_preferences' => 'preferences',
        default => 'tools',
    };
    power_redirect($section, 'error', $e->getMessage());
}
