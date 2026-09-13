<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/config.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function qty($v): string { return rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ','); }

function detect_category_table(PDO $pdo): ?string {
    foreach (['categories', 'product_categories'] as $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $stmt->execute([':t' => $table]);
        if ((int)$stmt->fetchColumn() > 0) return $table;
    }
    return null;
}
function movement_delta(string $type, float $q): float {
    $type = strtolower($type);
    foreach (['out','keluar','sale','sales','penjualan','transfer_out','stock_transfer_out','adjustment_out'] as $needle) {
        if ($type === $needle || str_contains($type, $needle)) return -abs($q);
    }
    return $q < 0 ? $q : abs($q);
}
function movement_label(string $type): string {
    $type = strtolower($type);
    return match (true) {
        str_contains($type,'purchase') || str_contains($type,'pembelian') => 'Pembelian',
        str_contains($type,'transfer_in') || str_contains($type,'stock_transfer_in') => 'Transfer Masuk',
        str_contains($type,'transfer_out') || str_contains($type,'stock_transfer_out') => 'Transfer Keluar',
        str_contains($type,'sale') || str_contains($type,'penjualan') => 'Penjualan',
        str_contains($type,'adjustment') => 'Penyesuaian',
        default => ucwords(str_replace(['_','-'],' ',$type)),
    };
}
function movement_class(string $type, float $delta): string {
    if (str_contains(strtolower($type),'adjustment')) return 'adjustment';
    return $delta >= 0 ? 'incoming' : 'outgoing';
}

$branchId = (int)($_GET['branch_id'] ?? 0);
$productId = (int)($_GET['product_id'] ?? 0);

$catTable = detect_category_table($pdo);
$catJoin = $catTable ? "LEFT JOIN `{$catTable}` cat ON cat.id = p.category_id" : "";
$catExpr = $catTable ? "COALESCE(cat.name, CONCAT('Kategori #',p.category_id))" : "CONCAT('Kategori #',p.category_id)";

$branchStmt = $pdo->prepare("SELECT id,code,name FROM cabangs WHERE id=:id LIMIT 1");
$branchStmt->execute([':id'=>$branchId]);
$branch = $branchStmt->fetch();

$productStmt = $pdo->prepare("SELECT p.id,p.code,p.name,p.brand,p.unit,{$catExpr} AS category_name FROM products p {$catJoin} WHERE p.id=:id LIMIT 1");
$productStmt->execute([':id'=>$productId]);
$product = $productStmt->fetch();

if (!$branch || !$product) { http_response_code(404); exit('Data stok tidak ditemukan.'); }

$stockStmt = $pdo->prepare("SELECT stock,minimum_stock FROM branch_stocks WHERE cabang_id=:b AND product_id=:p LIMIT 1");
$stockStmt->execute([':b'=>$branchId,':p'=>$productId]);
$stock = $stockStmt->fetch() ?: ['stock'=>0,'minimum_stock'=>0];

$movStmt = $pdo->prepare(
    "SELECT sm.movement_type,sm.quantity,sm.reference_type,sm.reference_id,sm.notes,sm.movement_date,u.name AS creator_name
     FROM stock_movements sm
     INNER JOIN branch_stocks bs ON bs.id=sm.branch_stock_id
     LEFT JOIN users u ON u.id=sm.created_by
     WHERE bs.cabang_id=:b AND bs.product_id=:p
     ORDER BY sm.movement_date DESC,sm.id DESC"
);
$movStmt->execute([':b'=>$branchId,':p'=>$productId]);
$rows = $movStmt->fetchAll();

$running = (float)$stock['stock'];
$movements = [];
foreach ($rows as $row) {
    $delta = movement_delta((string)$row['movement_type'], (float)$row['quantity']);
    $after = $running;
    $running -= $delta;
    $movements[] = [
        'date'=>$row['movement_date'],
        'reference'=>($row['reference_type'] ? $row['reference_type'].' #'.(int)$row['reference_id'] : '-'),
        'type'=>movement_label((string)$row['movement_type']),
        'class'=>movement_class((string)$row['movement_type'],$delta),
        'in'=>$delta > 0 ? abs((float)$row['quantity']) : 0,
        'out'=>$delta < 0 ? abs((float)$row['quantity']) : 0,
        'balance'=>$after,
        'notes'=>$row['notes'] ?? '-',
        'creator'=>$row['creator_name'] ?? '-'
    ];
}
$movements=array_reverse($movements);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kartu Stok - <?= h($product['code']) ?></title>
<style>
body{font-family:Arial,sans-serif;color:#111;margin:32px;font-size:12px}
h1{font-size:20px;margin:0 0 8px}.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:18px 0}.box{border:1px solid #ddd;padding:10px;border-radius:6px}.box span{display:block;color:#777;font-size:10px;margin-bottom:4px}.box strong{font-size:13px}
table{width:100%;border-collapse:collapse;margin-top:15px}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f3f4f6;font-size:10px;text-transform:uppercase}td{font-size:11px}.in{color:#15803d}.out{color:#dc2626}@media print{body{margin:15px}}
</style>
</head>
<body onload="window.print()">
<h1>Kartu Stok</h1>
<div><?= h($product['code'].' - '.$product['name']) ?></div>
<div class="meta">
<div class="box"><span>Cabang</span><strong><?= h($branch['code'].' - '.$branch['name']) ?></strong></div>
<div class="box"><span>Brand</span><strong><?= h($product['brand'] ?: '-') ?></strong></div>
<div class="box"><span>Unit</span><strong><?= h($product['unit'] ?: '-') ?></strong></div>
<div class="box"><span>Saldo Saat Ini</span><strong><?= h(qty($stock['stock'])) ?></strong></div>
</div>
<table>
<thead><tr><th>Tanggal</th><th>Referensi</th><th>Jenis</th><th>Masuk</th><th>Keluar</th><th>Saldo</th><th>Keterangan</th><th>Petugas</th></tr></thead>
<tbody>
<?php if (!$movements): ?><tr><td colspan="8">Belum ada pergerakan stok.</td></tr>
<?php else: foreach($movements as $m): ?>
<tr>
<td><?= h(date('d M Y H:i',strtotime($m['date']))) ?></td><td><?= h($m['reference']) ?></td><td><?= h($m['type']) ?></td>
<td class="in"><?= $m['in']>0?'+'.h(qty($m['in'])):'-' ?></td><td class="out"><?= $m['out']>0?'-'.h(qty($m['out'])):'-' ?></td>
<td><strong><?= h(qty($m['balance'])) ?></strong></td><td><?= h($m['notes']) ?></td><td><?= h($m['creator']) ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</body>
</html>
