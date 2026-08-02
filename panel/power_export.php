<?php

declare(strict_types=1);

require_once __DIR__ . '/addons/power/bootstrap.php';

$type = (string) ($_GET['type'] ?? 'snapshot');
$allowed = ['users', 'invoices', 'payments', 'snapshot'];
if (!in_array($type, $allowed, true)) {
    http_response_code(400);
    exit('نوع خروجی نامعتبر است.');
}

$from = !empty($_GET['from']) ? strtotime((string) $_GET['from'] . ' 00:00:00') : null;
$to = !empty($_GET['to']) ? strtotime((string) $_GET['to'] . ' 23:59:59') : null;
if (($from && !$to) || (!$from && $to) || ($from && $to && $from > $to)) {
    http_response_code(400);
    exit('بازه تاریخ نامعتبر است.');
}

pw_log('export_downloaded', ['type' => $type, 'from' => $from, 'to' => $to]);

if ($type === 'snapshot') {
    $data = [
        'generated_at' => date(DATE_ATOM),
        'version' => PW_VERSION,
        'range' => ['from' => $from ? date('Y-m-d', $from) : null, 'to' => $to ? date('Y-m-d', $to) : null],
        'metrics' => [
            'users' => pw_table_exists($pdo, 'user') ? (int) pw_scalar($pdo, 'SELECT COUNT(*) FROM `user`') : 0,
            'orders' => pw_table_exists($pdo, 'invoice') ? (int) pw_scalar($pdo, 'SELECT COUNT(*) FROM `invoice`') : 0,
            'active_services' => pw_table_exists($pdo, 'invoice') ? (int) pw_scalar($pdo, "SELECT COUNT(*) FROM `invoice` WHERE `Status`='active'") : 0,
            'invoice_revenue' => pw_table_exists($pdo, 'invoice') ? (float) pw_scalar($pdo, 'SELECT COALESCE(SUM(`price_product`),0) FROM `invoice`') : 0,
            'paid_revenue' => pw_table_exists($pdo, 'Payment_report') ? (float) pw_scalar($pdo, "SELECT COALESCE(SUM(`price`),0) FROM `Payment_report` WHERE `payment_Status`='paid'") : 0,
            'waiting_payments' => pw_table_exists($pdo, 'Payment_report') ? (int) pw_scalar($pdo, "SELECT COUNT(*) FROM `Payment_report` WHERE `payment_Status`='waiting'") : 0,
        ],
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mirzabot-snapshot-' . date('Ymd-His') . '.json"');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$map = [
    'users' => ['table' => 'user', 'time' => 'register', 'columns' => ['id','username','namecustom','Balance','User_Status','agent','register','affiliates','score']],
    'invoices' => ['table' => 'invoice', 'time' => 'time_sell', 'columns' => ['id_user','username','name_product','price_product','Status','time_sell','namepanel','Location','Volume','Service_time']],
    'payments' => ['table' => 'Payment_report', 'time' => 'time', 'columns' => ['id_user','id_order','price','Payment_Method','payment_Status','time']],
];
$cfg = $map[$type];
if (!pw_table_exists($pdo, $cfg['table'])) {
    http_response_code(404);
    exit('جدول مورد نظر وجود ندارد.');
}
$available = pw_columns($pdo, $cfg['table']);
$columns = array_values(array_intersect($cfg['columns'], $available));
if (!$columns) {
    http_response_code(500);
    exit('ستون قابل خروجی پیدا نشد.');
}
$sql = 'SELECT ' . implode(',', array_map('pw_ident', $columns)) . ' FROM ' . pw_ident($cfg['table']);
$params = [];
if ($from && $to && in_array($cfg['time'], $available, true)) {
    $sql .= ' WHERE ' . pw_ident($cfg['time']) . ' BETWEEN ? AND ?';
    $params = [$from, $to];
}
if (in_array($cfg['time'], $available, true)) {
    $sql .= ' ORDER BY ' . pw_ident($cfg['time']) . ' DESC';
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="mirzabot-' . $type . '-' . date('Ymd-His') . '.csv"');
header('X-Content-Type-Options: nosniff');
$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $columns);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, array_map(static fn($v) => $v === null ? '' : (string) $v, $row));
}
fclose($out);
exit;
