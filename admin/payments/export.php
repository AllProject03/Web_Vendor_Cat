<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/config.php';

function clean($value): string {
    return str_replace(["\r", "\n", '"'], [' ', ' ', '""'], (string)$value);
}

$search = trim($_GET['search'] ?? '');
$method = trim($_GET['method'] ?? '');
$status = trim($_GET['status'] ?? '');
$branch = max(0, (int)($_GET['branch'] ?? 0));

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        p.payment_number LIKE :search_payment
        OR o.order_number LIKE :search_order
        OR c.name LIKE :search_customer
        OR c.phone LIKE :search_phone
    )";
    $like = '%' . $search . '%';
    $params[':search_payment'] = $like;
    $params[':search_order'] = $like;
    $params[':search_customer'] = $like;
    $params[':search_phone'] = $like;
}

if ($method !== '') {
    $where[] = 'p.payment_method = :method';
    $params[':method'] = $method;
}

if ($status !== '') {
    $where[] = 'p.status = :status';
    $params[':status'] = $status;
}

if ($branch > 0) {
    $where[] = 'o.cabang_id = :branch';
    $params[':branch'] = $branch;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT
        p.payment_number,
        o.order_number,
        p.payment_date,
        c.name AS customer_name,
        o.grand_total,
        p.amount,
        p.payment_method,
        p.status,
        cb.code AS branch_code,
        cb.name AS branch_name,
        u.name AS received_name
     FROM payments p
     INNER JOIN orders o ON o.id = p.order_id
     LEFT JOIN customers c ON c.id = o.customer_id
     LEFT JOIN cabangs cb ON cb.id = o.cabang_id
     LEFT JOIN users u ON u.id = p.received_by
     $whereSql
     ORDER BY p.id DESC"
);

$stmt->execute($params);

$filename = 'export-pembayaran-' . date('Ymd-His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'No Pembayaran',
    'No Pesanan',
    'Tanggal',
    'Pelanggan',
    'Total Pesanan',
    'Nominal Bayar',
    'Metode',
    'Status',
    'Cabang',
    'Diterima Oleh'
]);

foreach ($stmt->fetchAll() as $row) {
    fputcsv($out, [
        clean($row['payment_number']),
        clean($row['order_number']),
        $row['payment_date'],
        clean($row['customer_name']),
        $row['grand_total'],
        $row['amount'],
        clean($row['payment_method']),
        clean($row['status']),
        clean(($row['branch_code'] ? $row['branch_code'] . ' - ' : '') . ($row['branch_name'] ?? '')),
        clean($row['received_name'] ?? ''),
    ]);
}

fclose($out);
exit;
