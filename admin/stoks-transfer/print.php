<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/config.php';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function status_label_print(string $status): string {
    return match ($status) {
        'draft' => 'Draft',
        'pending', 'waiting' => 'Menunggu Persetujuan',
        'approved' => 'Disetujui',
        'in_transit', 'shipped', 'shipping' => 'Dalam Pengiriman',
        'received', 'completed' => 'Diterima',
        'cancelled', 'canceled', 'rejected' => 'Dibatalkan',
        default => ucwords(str_replace(['_', '-'], ' ', $status)),
    };
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('ID transfer tidak valid.');
}

$stmt = $pdo->prepare(
    "SELECT
        st.*,
        fb.code AS from_code, fb.name AS from_name,
        tb.code AS to_code, tb.name AS to_name,
        cu.name AS created_name,
        au.name AS approved_name,
        ru.name AS received_name
     FROM stock_transfers st
     LEFT JOIN cabangs fb ON fb.id = st.from_cabang_id
     LEFT JOIN cabangs tb ON tb.id = st.to_cabang_id
     LEFT JOIN users cu ON cu.id = st.created_by
     LEFT JOIN users au ON au.id = st.approved_by
     LEFT JOIN users ru ON ru.id = st.received_by
     WHERE st.id = :id
     LIMIT 1"
);
$stmt->execute([':id' => $id]);
$transfer = $stmt->fetch();
if (!$transfer) {
    http_response_code(404);
    exit('Transfer tidak ditemukan.');
}

$detailStmt = $pdo->prepare(
    "SELECT d.*, p.code AS product_code, p.name AS product_name, p.brand, p.unit
     FROM stock_transfer_details d
     LEFT JOIN products p ON p.id = d.product_id
     WHERE d.stock_transfer_id = :id
     ORDER BY d.id ASC"
);
$detailStmt->execute([':id' => $id]);
$lines = $detailStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($transfer['transfer_number']) ?> - Transfer Stok</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#fff;color:#111827;font-family:Arial,Helvetica,sans-serif;font-size:12px}
.print-wrap{width:210mm;max-width:100%;margin:0 auto;padding:18mm 16mm}
.header{display:flex;justify-content:space-between;gap:30px;border-bottom:2px solid #111827;padding-bottom:14px}
.brand{font-size:18px;font-weight:700}.sub{margin-top:3px;color:#6b7280;font-size:11px}
.title{text-align:right}.title h1{margin:0 0 5px;font-size:22px}.title p{margin:0;color:#6b7280}
.info{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin:18px 0}
.info div{border:1px solid #e5e7eb;border-radius:7px;padding:9px}.info span{display:block;color:#6b7280;font-size:9px;text-transform:uppercase;margin-bottom:4px}.info strong{font-size:11px}
table{width:100%;border-collapse:collapse}th{background:#f3f4f6;border-bottom:1px solid #d1d5db;padding:9px;text-align:left;font-size:10px}td{padding:9px;border-bottom:1px solid #e5e7eb;font-size:11px}td.num,th.num{text-align:right}
.notes{margin-top:16px;border:1px solid #e5e7eb;border-radius:7px;padding:11px}.notes strong{display:block;margin-bottom:5px}.notes p{margin:0;color:#4b5563;white-space:pre-wrap}
.signatures{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:35px}.sig{text-align:center}.sig .line{height:50px;border-bottom:1px solid #111827;margin-bottom:7px}.sig small{color:#6b7280}
.footer{margin-top:24px;padding-top:9px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:9px;text-align:center}
@media print{.print-wrap{padding:0;width:auto}.no-print{display:none!important}@page{size:A4;margin:12mm}}
</style>
</head>
<body>
<div class="print-wrap">
    <div class="header">
        <div>
            <div class="brand">PT. GIAN GANESHA NAWASENA</div>
            <div class="sub">Sistem Vendor Cat Mobil</div>
        </div>
        <div class="title">
            <h1>TRANSFER STOK</h1>
            <p><?= h($transfer['transfer_number']) ?></p>
        </div>
    </div>

    <div class="info">
        <div><span>Tanggal</span><strong><?= h(date('d M Y', strtotime($transfer['transfer_date']))) ?></strong></div>
        <div><span>Cabang Asal</span><strong><?= h(($transfer['from_code'] ? $transfer['from_code'].' - ' : '').$transfer['from_name']) ?></strong></div>
        <div><span>Cabang Tujuan</span><strong><?= h(($transfer['to_code'] ? $transfer['to_code'].' - ' : '').$transfer['to_name']) ?></strong></div>
        <div><span>Status</span><strong><?= h(status_label_print((string)$transfer['status'])) ?></strong></div>
    </div>

    <table>
        <thead><tr><th>No</th><th>Produk</th><th>Unit</th><th class="num">Qty Transfer</th><th class="num">Qty Diterima</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $i => $line): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= h($line['product_code'].' - '.$line['product_name']) ?></strong><?php if ($line['brand']): ?><br><span style="color:#6b7280"><?= h($line['brand']) ?></span><?php endif; ?></td>
                <td><?= h($line['unit'] ?: '-') ?></td>
                <td class="num"><?= h($line['quantity']) ?></td>
                <td class="num"><?= h($line['received_quantity']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="notes">
        <strong>Catatan</strong>
        <p><?= h($transfer['notes'] ?: '-') ?></p>
    </div>

    <div class="info" style="grid-template-columns:repeat(3,1fr)">
        <div><span>Dibuat Oleh</span><strong><?= h($transfer['created_name'] ?: '-') ?></strong></div>
        <div><span>Disetujui Oleh</span><strong><?= h($transfer['approved_name'] ?: '-') ?></strong></div>
        <div><span>Diterima Oleh</span><strong><?= h($transfer['received_name'] ?: '-') ?></strong></div>
    </div>

    <div class="signatures">
        <div class="sig"><div class="line"></div><strong>Pengirim</strong><small><?= h($transfer['from_name']) ?></small></div>
        <div class="sig"><div class="line"></div><strong>Petugas Pengiriman</strong><small>Transfer Stok</small></div>
        <div class="sig"><div class="line"></div><strong>Penerima</strong><small><?= h($transfer['to_name']) ?></small></div>
    </div>

    <div class="footer">Dokumen dicetak dari VendorCat • <?= date('d M Y H:i') ?></div>
</div>
<script>
window.addEventListener('load', function(){ window.print(); });
</script>
</body>
</html>
