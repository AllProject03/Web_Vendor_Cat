<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/config.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string
{
    return 'Rp ' . number_format($value, 0, ',', '.');
}

function status_label(string $status): string
{
    return match ($status) {
        'draft' => 'Draft',
        'pending' => 'Menunggu',
        'processing' => 'Diproses',
        'partial', 'partially_received' => 'Sebagian Diterima',
        'received', 'completed' => 'Diterima',
        'cancelled', 'canceled' => 'Dibatalkan',
        default => ucwords(str_replace(['_', '-'], ' ', $status)),
    };
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('ID pembelian tidak valid.');
}

$stmt = $pdo->prepare(
    "SELECT
        p.*,
        s.code AS supplier_code,
        s.name AS supplier_name,
        s.contact_person,
        s.phone AS supplier_phone,
        s.email AS supplier_email,
        s.address AS supplier_address,
        c.code AS cabang_code,
        c.name AS cabang_name,
        c.address AS cabang_address,
        c.phone AS cabang_phone,
        u.name AS creator_name
     FROM purchases p
     LEFT JOIN suppliers s ON s.id = p.supplier_id
     LEFT JOIN cabangs c ON c.id = p.cabang_id
     LEFT JOIN users u ON u.id = p.created_by
     WHERE p.id = :id
     LIMIT 1"
);
$stmt->execute([':id' => $id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    http_response_code(404);
    exit('Pembelian tidak ditemukan.');
}

$detailStmt = $pdo->prepare(
    "SELECT
        pd.*,
        pr.code AS product_code,
        pr.name AS product_name,
        pr.brand AS product_brand,
        pr.unit AS product_unit
     FROM purchase_details pd
     LEFT JOIN products pr ON pr.id = pd.product_id
     WHERE pd.purchase_id = :purchase_id
     ORDER BY pd.id ASC"
);
$detailStmt->execute([':purchase_id' => $id]);
$details = $detailStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cetak <?= h($purchase['purchase_number']) ?></title>
<style>
    @page { size: A4; margin: 14mm; }
    * { box-sizing: border-box; }
    body { margin:0; font-family: Arial, sans-serif; color:#111827; font-size:12px; }
    .sheet { width:100%; max-width:900px; margin:0 auto; }
    .top { display:flex; justify-content:space-between; gap:30px; padding-bottom:16px; border-bottom:2px solid #111827; }
    .brand { font-size:17px; font-weight:700; margin-bottom:4px; }
    .muted { color:#6b7280; }
    h1 { margin:0 0 6px; font-size:21px; }
    .meta { text-align:right; }
    .meta strong { display:block; font-size:15px; }
    .info { display:grid; grid-template-columns:1fr 1fr; gap:15px; margin:18px 0; }
    .info-box { border:1px solid #d1d5db; border-radius:7px; padding:12px; }
    .label { color:#6b7280; font-size:10px; margin-bottom:4px; text-transform:uppercase; }
    .value { font-weight:700; }
    table { width:100%; border-collapse:collapse; }
    th { background:#f3f4f6; border:1px solid #d1d5db; padding:8px; text-align:left; font-size:10px; }
    td { border:1px solid #d1d5db; padding:8px; vertical-align:top; }
    .right { text-align:right; }
    .center { text-align:center; }
    .summary { width:330px; margin:15px 0 0 auto; }
    .summary div { display:flex; justify-content:space-between; gap:20px; padding:5px 0; }
    .summary .grand { margin-top:5px; padding-top:8px; border-top:2px solid #111827; font-size:14px; font-weight:700; }
    .footer { margin-top:30px; display:grid; grid-template-columns:1fr 1fr; gap:50px; }
    .sign { padding-top:48px; text-align:center; border-top:1px solid #9ca3af; }
    .actions { margin-bottom:20px; display:flex; gap:8px; }
    button { padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; background:#fff; cursor:pointer; }
    @media print {
        .actions { display:none; }
        .sheet { max-width:none; }
    }
</style>
</head>
<body>
<div class="sheet">
    <div class="actions">
        <button onclick="window.print()">Cetak</button>
        <button onclick="window.close()">Tutup</button>
    </div>

    <div class="top">
        <div>
            <div class="brand">PT. GIAN GANESHA NAWASENA</div>
            <div class="muted">Sistem Vendor Cat Mobil</div>
            <div class="muted">Dokumen Pembelian</div>
        </div>
        <div class="meta">
            <h1>PEMBELIAN</h1>
            <strong><?= h($purchase['purchase_number']) ?></strong>
            <div class="muted"><?= h(date('d M Y', strtotime($purchase['purchase_date']))) ?></div>
            <div class="muted">Status: <?= h(status_label((string)$purchase['status'])) ?></div>
        </div>
    </div>

    <div class="info">
        <div class="info-box">
            <div class="label">Supplier</div>
            <div class="value"><?= h($purchase['supplier_name'] ?: '-') ?></div>
            <div><?= h($purchase['supplier_code'] ?: '-') ?></div>
            <?php if ($purchase['supplier_phone']): ?><div><?= h($purchase['supplier_phone']) ?></div><?php endif; ?>
            <?php if ($purchase['supplier_address']): ?><div><?= h($purchase['supplier_address']) ?></div><?php endif; ?>
        </div>
        <div class="info-box">
            <div class="label">Cabang Tujuan</div>
            <div class="value"><?= h(($purchase['cabang_code'] ? $purchase['cabang_code'] . ' - ' : '') . ($purchase['cabang_name'] ?: '-')) ?></div>
            <?php if ($purchase['cabang_phone']): ?><div><?= h($purchase['cabang_phone']) ?></div><?php endif; ?>
            <?php if ($purchase['cabang_address']): ?><div><?= h($purchase['cabang_address']) ?></div><?php endif; ?>
            <div style="margin-top:6px;">Dibuat oleh: <?= h($purchase['creator_name'] ?: '-') ?></div>
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th>No</th>
            <th>Produk</th>
            <th>Harga</th>
            <th>Qty</th>
            <th>Diterima</th>
            <th>Diskon</th>
            <th>Subtotal</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($details as $i => $detail): ?>
            <tr>
                <td class="center"><?= $i + 1 ?></td>
                <td>
                    <strong><?= h($detail['product_name'] ?: '-') ?></strong><br>
                    <span class="muted"><?= h($detail['product_code'] ?: '-') ?><?= $detail['product_unit'] ? ' · ' . h($detail['product_unit']) : '' ?></span>
                </td>
                <td class="right"><?= h(money((float)$detail['price'])) ?></td>
                <td class="center"><?= number_format((int)$detail['quantity'], 0, ',', '.') ?></td>
                <td class="center"><?= number_format((int)$detail['received_quantity'], 0, ',', '.') ?></td>
                <td class="right"><?= h(money((float)$detail['discount'])) ?></td>
                <td class="right"><?= h(money((float)$detail['subtotal'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="summary">
        <div><span>Subtotal</span><strong><?= h(money((float)$purchase['subtotal'])) ?></strong></div>
        <div><span>Diskon</span><strong><?= h(money((float)$purchase['discount'])) ?></strong></div>
        <div><span>Pajak</span><strong><?= h(money((float)$purchase['tax'])) ?></strong></div>
        <div class="grand"><span>Grand Total</span><strong><?= h(money((float)$purchase['grand_total'])) ?></strong></div>
    </div>

    <div class="footer">
        <div></div>
        <div class="sign">Disetujui / Diperiksa</div>
    </div>
</div>

<script>
window.addEventListener('load', function () {
    setTimeout(function () {
        window.print();
    }, 300);
});
</script>
</body>
</html>
