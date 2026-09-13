<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/config.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string
{
    return 'Rp' . number_format($value, 0, ',', '.');
}

function get_payment(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            p.*,
            o.order_number,
            o.grand_total AS order_grand_total,
            o.order_date,
            o.status AS order_status,
            c.name AS customer_name,
            c.customer_type,
            c.phone AS customer_phone,
            c.email AS customer_email,
            c.address AS customer_address,
            cb.code AS cabang_code,
            cb.name AS cabang_name,
            cb.address AS cabang_address,
            u.name AS receiver_name
         FROM payments p
         INNER JOIN orders o ON o.id = p.order_id
         LEFT JOIN customers c ON c.id = o.customer_id
         LEFT JOIN cabangs cb ON cb.id = o.cabang_id
         LEFT JOIN users u ON u.id = p.received_by
         WHERE p.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    exit('ID pembayaran tidak valid.');
}

$payment = get_payment($pdo, $id);

if (!$payment) {
    http_response_code(404);
    exit('Data pembayaran tidak ditemukan.');
}

$paidStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0)
     FROM payments
     WHERE order_id = :order_id
       AND status = 'paid'"
);
$paidStmt->execute([':order_id' => (int)$payment['order_id']]);
$paidTotal = (float)$paidStmt->fetchColumn();

$remaining = max(0, (float)$payment['order_grand_total'] - $paidTotal);

$methodLabel = match ($payment['payment_method']) {
    'cash' => 'Cash',
    'transfer' => 'Transfer',
    'qris' => 'QRIS',
    'edc' => 'EDC',
    'other' => 'Lainnya',
    default => ucfirst((string)$payment['payment_method']),
};

$statusLabel = match ($payment['status']) {
    'pending' => 'Menunggu',
    'paid' => 'Lunas',
    'cancelled', 'canceled' => 'Dibatalkan',
    default => ucfirst((string)$payment['status']),
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bukti Pembayaran <?= h($payment['payment_number']) ?></title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            color: #111827;
            background: #fff;
        }
        .sheet {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
        }
        .top {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #111827;
        }
        .company strong {
            display: block;
            font-size: 17px;
            margin-bottom: 4px;
        }
        .company span {
            color: #6b7280;
            font-size: 11px;
        }
        .title {
            text-align: right;
        }
        .title h1 {
            margin: 0 0 5px;
            font-size: 22px;
        }
        .title p {
            margin: 0;
            font-size: 11px;
            color: #6b7280;
        }
        .meta {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 20px;
        }
        .meta > div {
            padding: 11px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
        }
        .meta span {
            display: block;
            margin-bottom: 4px;
            font-size: 9px;
            color: #6b7280;
            text-transform: uppercase;
        }
        .meta strong {
            font-size: 12px;
        }
        .section {
            margin-top: 22px;
        }
        .section h2 {
            margin: 0 0 10px;
            font-size: 13px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 9px 10px;
            border: 1px solid #e5e7eb;
            font-size: 11px;
            text-align: left;
        }
        th {
            background: #f9fafb;
            font-weight: 700;
        }
        .amount {
            margin-top: 20px;
            margin-left: auto;
            width: min(360px, 100%);
            border: 1px solid #e5e7eb;
        }
        .amount div {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 9px 11px;
            border-bottom: 1px solid #eef0f3;
            font-size: 11px;
        }
        .amount div:last-child {
            border-bottom: 0;
            background: #f9fafb;
            font-weight: 700;
            font-size: 12px;
        }
        .footer {
            display: flex;
            justify-content: space-between;
            gap: 30px;
            margin-top: 48px;
        }
        .sign {
            width: 220px;
            text-align: center;
            font-size: 10px;
            color: #6b7280;
        }
        .sign .space {
            height: 55px;
        }
        .print-bar {
            text-align: center;
            margin: 20px 0;
        }
        .print-bar button {
            border: 1px solid #111827;
            background: #111827;
            color: #fff;
            padding: 8px 14px;
            border-radius: 6px;
            cursor: pointer;
        }
        @media print {
            .print-bar { display: none; }
        }
    </style>
</head>
<body onload="window.print()">
<div class="sheet">

    <div class="print-bar">
        <button type="button" onclick="window.print()">Cetak</button>
    </div>

    <div class="top">
        <div class="company">
            <strong>PT. GIAN GANESHA NAWASENA</strong>
            <span>Sistem Vendor Cat Mobil</span>
        </div>
        <div class="title">
            <h1>BUKTI PEMBAYARAN</h1>
            <p><?= h($payment['payment_number']) ?></p>
        </div>
    </div>

    <div class="meta">
        <div>
            <span>No Pembayaran</span>
            <strong><?= h($payment['payment_number']) ?></strong>
        </div>
        <div>
            <span>Tanggal Pembayaran</span>
            <strong><?= h(date('d M Y', strtotime($payment['payment_date']))) ?></strong>
        </div>
        <div>
            <span>No Pesanan</span>
            <strong><?= h($payment['order_number']) ?></strong>
        </div>
        <div>
            <span>Status</span>
            <strong><?= h($statusLabel) ?></strong>
        </div>
    </div>

    <div class="section">
        <h2>Informasi Pelanggan</h2>
        <table>
            <tr>
                <th width="25%">Pelanggan</th>
                <td><?= h($payment['customer_name'] ?: '-') ?></td>
            </tr>
            <tr>
                <th>Jenis Pelanggan</th>
                <td><?= h($payment['customer_type'] ?: '-') ?></td>
            </tr>
            <tr>
                <th>Cabang</th>
                <td><?= h(($payment['cabang_code'] ? $payment['cabang_code'] . ' - ' : '') . ($payment['cabang_name'] ?: '-')) ?></td>
            </tr>
            <tr>
                <th>Diterima Oleh</th>
                <td><?= h($payment['receiver_name'] ?: '-') ?></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Rincian Pembayaran</h2>
        <table>
            <tr>
                <th>Metode Pembayaran</th>
                <th>Status</th>
                <th>Nominal Pembayaran</th>
            </tr>
            <tr>
                <td><?= h($methodLabel) ?></td>
                <td><?= h($statusLabel) ?></td>
                <td><?= h(money((float)$payment['amount'])) ?></td>
            </tr>
        </table>
    </div>

    <div class="amount">
        <div>
            <span>Total Pesanan</span>
            <strong><?= h(money((float)$payment['order_grand_total'])) ?></strong>
        </div>
        <div>
            <span>Total Pembayaran Paid</span>
            <strong><?= h(money($paidTotal)) ?></strong>
        </div>
        <div>
            <span>Sisa Tagihan</span>
            <strong><?= h(money($remaining)) ?></strong>
        </div>
    </div>

    <div class="footer">
        <div class="sign">
            <div class="space"></div>
            Pelanggan
        </div>
        <div class="sign">
            <div class="space"></div>
            Penerima Pembayaran
        </div>
    </div>

</div>
</body>
</html>
