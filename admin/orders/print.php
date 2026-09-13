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

function order_status_label(string $status): string
{
    return match ($status) {
        'draft' => 'Draft',
        'pending' => 'Menunggu',
        'processing' => 'Diproses',
        'completed' => 'Selesai',
        'cancelled', 'canceled' => 'Dibatalkan',
        default => ucwords(str_replace(['_', '-'], ' ', $status)),
    };
}

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    exit('ID penjualan tidak valid.');
}

$stmt = $pdo->prepare(
    "SELECT
        o.*,
        c.name AS customer_name,
        c.customer_type,
        c.phone AS customer_phone,
        c.email AS customer_email,
        c.address AS customer_address,
        cb.code AS cabang_code,
        cb.name AS cabang_name,
        cb.address AS cabang_address,
        cb.phone AS cabang_phone,
        v.plate_number,
        v.brand AS vehicle_brand,
        v.model AS vehicle_model,
        v.year AS vehicle_year,
        v.color AS vehicle_color,
        v.vin_number,
        u.name AS creator_name
     FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     LEFT JOIN cabangs cb ON cb.id = o.cabang_id
     LEFT JOIN vehicles v ON v.id = o.vehicle_id
     LEFT JOIN users u ON u.id = o.created_by
     WHERE o.id = :id
     LIMIT 1"
);
$stmt->execute([':id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    exit('Data penjualan tidak ditemukan.');
}

$productStmt = $pdo->prepare(
    "SELECT
        od.*,
        p.code AS product_code,
        p.name AS product_name,
        p.unit AS product_unit
     FROM order_details od
     LEFT JOIN products p ON p.id = od.product_id
     WHERE od.order_id = :order_id
     ORDER BY od.id ASC"
);
$productStmt->execute([':order_id' => $orderId]);
$products = $productStmt->fetchAll();

$serviceStmt = $pdo->prepare(
    "SELECT
        os.*,
        s.name AS service_name,
        s.service_type
     FROM order_services os
     LEFT JOIN services s ON s.id = os.service_id
     WHERE os.order_id = :order_id
     ORDER BY os.id ASC"
);
$serviceStmt->execute([':order_id' => $orderId]);
$services = $serviceStmt->fetchAll();

$totalItems = count($products) + count($services);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($order['order_number']) ?> | Cetak Penjualan</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 30px;
            background: #f3f4f6;
            color: #111827;
            font-family: Arial, Helvetica, sans-serif;
        }
        .print-page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 18mm;
            background: #fff;
        }
        .top {
            display: flex;
            justify-content: space-between;
            gap: 30px;
            border-bottom: 2px solid #111827;
            padding-bottom: 14px;
            margin-bottom: 18px;
        }
        .company h1 {
            margin: 0 0 5px;
            font-size: 20px;
        }
        .company p,
        .meta p {
            margin: 3px 0;
            font-size: 12px;
            color: #4b5563;
        }
        .meta { text-align: right; }
        .meta .number {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 6px;
        }
        .section {
            margin-top: 18px;
        }
        .section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 8px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 18px;
            border: 1px solid #d1d5db;
            padding: 12px;
        }
        .info-item small {
            display: block;
            color: #6b7280;
            font-size: 10px;
            margin-bottom: 3px;
        }
        .info-item strong {
            display: block;
            font-size: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        th, td {
            border: 1px solid #d1d5db;
            padding: 8px;
            font-size: 11px;
            vertical-align: top;
        }
        th {
            background: #f3f4f6;
            text-align: left;
        }
        td.num, th.num { text-align: right; }
        .totals {
            width: 330px;
            margin-left: auto;
            margin-top: 16px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 5px 0;
            font-size: 12px;
        }
        .grand {
            margin-top: 5px;
            padding-top: 8px;
            border-top: 2px solid #111827;
            font-size: 15px;
            font-weight: 700;
        }
        .footer {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
            color: #6b7280;
            font-size: 10px;
        }
        .screen-actions {
            width: 210mm;
            margin: 0 auto 15px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .screen-actions button {
            border: 0;
            padding: 9px 14px;
            border-radius: 7px;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-print { background: #111827; color: #fff; }
        .btn-close { background: #e5e7eb; color: #111827; }

        @media print {
            @page { size: A4; margin: 0; }
            body { background: #fff; padding: 0; }
            .print-page { width: auto; min-height: auto; margin: 0; padding: 15mm; }
            .screen-actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="screen-actions">
        <button class="btn-close" type="button" onclick="window.close()">Tutup</button>
        <button class="btn-print" type="button" onclick="window.print()">Cetak</button>
    </div>

    <div class="print-page">
        <div class="top">
            <div class="company">
                <h1>PT. GIAN GANESHA NAWASENA</h1>
                <p>Sistem Vendor Cat Mobil</p>
                <p><?= h(($order['cabang_code'] ? $order['cabang_code'] . ' - ' : '') . ($order['cabang_name'] ?: '-')) ?></p>
                <?php if (!empty($order['cabang_address'])): ?><p><?= h($order['cabang_address']) ?></p><?php endif; ?>
                <?php if (!empty($order['cabang_phone'])): ?><p><?= h($order['cabang_phone']) ?></p><?php endif; ?>
            </div>
            <div class="meta">
                <div class="number"><?= h($order['order_number']) ?></div>
                <p>Tanggal: <?= h(date('d/m/Y', strtotime($order['order_date']))) ?></p>
                <p>Status: <?= h(order_status_label((string)$order['status'])) ?></p>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Informasi Pelanggan & Kendaraan</div>
            <div class="info-grid">
                <div class="info-item">
                    <small>Pelanggan</small>
                    <strong><?= h($order['customer_name'] ?: '-') ?></strong>
                </div>
                <div class="info-item">
                    <small>Jenis Pelanggan</small>
                    <strong><?= h($order['customer_type'] ?: '-') ?></strong>
                </div>
                <div class="info-item">
                    <small>Telepon</small>
                    <strong><?= h($order['customer_phone'] ?: '-') ?></strong>
                </div>
                <div class="info-item">
                    <small>Email</small>
                    <strong><?= h($order['customer_email'] ?: '-') ?></strong>
                </div>
                <div class="info-item">
                    <small>Kendaraan</small>
                    <strong><?= h(trim(($order['vehicle_brand'] ?? '') . ' ' . ($order['vehicle_model'] ?? '')) ?: '-') ?></strong>
                </div>
                <div class="info-item">
                    <small>Nomor Polisi</small>
                    <strong><?= h($order['plate_number'] ?: '-') ?></strong>
                </div>
                <div class="info-item">
                    <small>Tahun / Warna</small>
                    <strong><?= h(($order['vehicle_year'] ?: '-') . ' / ' . ($order['vehicle_color'] ?: '-')) ?></strong>
                </div>
                <div class="info-item">
                    <small>Dibuat Oleh</small>
                    <strong><?= h($order['creator_name'] ?: '-') ?></strong>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Item Penjualan (<?= $totalItems ?>)</div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 35px;">No</th>
                        <th>Item</th>
                        <th style="width: 70px;">Qty</th>
                        <th class="num" style="width: 110px;">Harga</th>
                        <th class="num" style="width: 110px;">Diskon</th>
                        <th class="num" style="width: 120px;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; ?>
                    <?php foreach ($products as $item): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <strong><?= h(($item['product_code'] ? $item['product_code'] . ' - ' : '') . ($item['product_name'] ?: $item['description'])) ?></strong>
                                <?php if (!empty($item['description'])): ?><br><small><?= h($item['description']) ?></small><?php endif; ?>
                            </td>
                            <td><?= (int)$item['quantity'] ?> <?= h($item['product_unit'] ?: '') ?></td>
                            <td class="num"><?= h(money((float)$item['price'])) ?></td>
                            <td class="num"><?= h(money((float)$item['discount'])) ?></td>
                            <td class="num"><?= h(money((float)$item['subtotal'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($services as $item): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <strong><?= h($item['service_name'] ?: '-') ?></strong>
                                <?php if (!empty($item['service_type'])): ?><br><small><?= h($item['service_type']) ?></small><?php endif; ?>
                            </td>
                            <td><?= (int)$item['quantity'] ?></td>
                            <td class="num"><?= h(money((float)$item['price'])) ?></td>
                            <td class="num"><?= h(money((float)$item['discount'])) ?></td>
                            <td class="num"><?= h(money((float)$item['subtotal'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$products && !$services): ?>
                        <tr><td colspan="6">Tidak ada item.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="totals">
            <div class="total-row"><span>Subtotal</span><strong><?= h(money((float)$order['subtotal'])) ?></strong></div>
            <div class="total-row"><span>Diskon</span><strong><?= h(money((float)$order['discount'])) ?></strong></div>
            <div class="total-row"><span>Pajak</span><strong><?= h(money((float)$order['tax'])) ?></strong></div>
            <div class="total-row grand"><span>Grand Total</span><strong><?= h(money((float)$order['grand_total'])) ?></strong></div>
        </div>

        <div class="footer">
            <span>Dokumen dicetak dari VendorCat</span>
            <span><?= h(date('d/m/Y H:i')) ?></span>
        </div>
    </div>

    <script>
        window.addEventListener('load', () => {
            setTimeout(() => window.print(), 250);
        });
    </script>
</body>
</html>
