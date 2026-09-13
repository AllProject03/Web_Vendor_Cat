<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/config.php';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string {
    return 'Rp ' . number_format($value, 0, ',', '.');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('ID pengeluaran tidak valid.');
}

$stmt = $pdo->prepare(
    "SELECT
        e.*,
        c.code AS cabang_code,
        c.name AS cabang_name,
        u.name AS creator_name,
        au.name AS approver_name
     FROM expenses e
     LEFT JOIN cabangs c ON c.id = e.cabang_id
     LEFT JOIN users u ON u.id = e.created_by
     LEFT JOIN users au ON au.id = e.approved_by
     WHERE e.id = :id
     LIMIT 1"
);
$stmt->execute([':id' => $id]);
$expense = $stmt->fetch();

if (!$expense) {
    http_response_code(404);
    exit('Data pengeluaran tidak ditemukan.');
}

$statusLabel = match ((string)$expense['status']) {
    'draft' => 'Draft',
    'submitted' => 'Menunggu Persetujuan',
    'approved' => 'Disetujui',
    'rejected' => 'Ditolak',
    'paid' => 'Dibayar',
    'cancelled', 'canceled' => 'Dibatalkan',
    default => ucwords(str_replace(['_', '-'], ' ', (string)$expense['status'])),
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($expense['expense_number']) ?> | Pengeluaran</title>
<style>
    *{box-sizing:border-box}
    body{margin:0;background:#eef1f5;color:#172033;font-family:Arial,sans-serif}
    .page{width:210mm;min-height:297mm;margin:10mm auto;padding:16mm;background:#fff}
    .head{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid #172033;padding-bottom:14px;margin-bottom:22px}
    .company{font-size:18px;font-weight:700}
    .sub{margin-top:4px;color:#6b7280;font-size:11px}
    .title{text-align:right}
    .title h1{margin:0;font-size:22px}
    .title div{margin-top:5px;color:#6b7280;font-size:11px}
    .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:18px}
    .box{border:1px solid #dfe3e8;border-radius:8px;padding:12px}
    .label{font-size:10px;color:#8a94a6;margin-bottom:5px}
    .value{font-size:13px;font-weight:600;line-height:1.45}
    .amount{font-size:20px}
    .description{margin-top:4px;line-height:1.6;white-space:pre-wrap}
    .footer{margin-top:45px;display:grid;grid-template-columns:1fr 1fr;gap:50px}
    .sign{text-align:center}
    .sign-space{height:65px}
    .sign-line{border-top:1px solid #9ca3af;padding-top:6px;font-size:11px}
    .status{display:inline-block;padding:5px 9px;border-radius:20px;font-size:10px;font-weight:700}
    .draft{background:#f3f4f6;color:#4b5563}
    .submitted{background:#fff7ed;color:#c2410c}
    .approved{background:#f5f3ff;color:#7c3aed}
    .rejected{background:#fef2f2;color:#dc2626}
    .paid{background:#ecfdf3;color:#15803d}
    .cancelled{background:#f3f4f6;color:#6b7280}
    @media print{
        body{background:#fff}
        .page{margin:0;box-shadow:none}
        @page{size:A4;margin:0}
    }
</style>
</head>
<body>
<div class="page">
    <div class="head">
        <div>
            <div class="company">PT. GIAN GANESHA NAWASENA</div>
            <div class="sub">Sistem Vendor Cat Mobil</div>
        </div>
        <div class="title">
            <h1>BUKTI PENGELUARAN</h1>
            <div><?= h($expense['expense_number']) ?></div>
        </div>
    </div>

    <div class="grid">
        <div class="box">
            <div class="label">Tanggal Pengeluaran</div>
            <div class="value"><?= h(date('d F Y', strtotime($expense['expense_date']))) ?></div>
        </div>
        <div class="box">
            <div class="label">Status</div>
            <div class="value"><span class="status <?= h((string)$expense['status']) ?>"><?= h($statusLabel) ?></span></div>
        </div>
        <div class="box">
            <div class="label">Cabang</div>
            <div class="value"><?= h(($expense['cabang_code'] ? $expense['cabang_code'] . ' - ' : '') . ($expense['cabang_name'] ?: '-')) ?></div>
        </div>
        <div class="box">
            <div class="label">Kategori</div>
            <div class="value"><?= h($expense['category']) ?></div>
        </div>
        <div class="box" style="grid-column:1/-1">
            <div class="label">Keterangan</div>
            <div class="value description"><?= h($expense['description']) ?></div>
        </div>
        <div class="box" style="grid-column:1/-1">
            <div class="label">Jumlah Pengeluaran</div>
            <div class="value amount"><?= h(money((float)$expense['amount'])) ?></div>
        </div>
        <div class="box">
            <div class="label">Dibuat Oleh</div>
            <div class="value"><?= h($expense['creator_name'] ?: '-') ?></div>
        </div>
        <div class="box">
            <div class="label">Disetujui Oleh</div>
            <div class="value"><?= h($expense['approver_name'] ?: '-') ?></div>
        </div>
    </div>

    <div class="footer">
        <div class="sign">
            <div class="sign-space"></div>
            <div class="sign-line">Dibuat Oleh</div>
        </div>
        <div class="sign">
            <div class="sign-space"></div>
            <div class="sign-line">Disetujui Oleh</div>
        </div>
    </div>
</div>
<script>window.addEventListener('load',()=>window.print());</script>
</body>
</html>
