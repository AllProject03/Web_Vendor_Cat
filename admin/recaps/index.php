<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/config.php';

$pageTitle = 'Rekap Per Cabang';

/* =====================================================
   IDENTITAS PERUSAHAAN - DARI SETTINGS
   Hanya untuk BRAND SIDEBAR
   ===================================================== */
function load_company_brand(PDO $pdo): array
{
    $brand = [
        'name' => 'PT. GIAN GANESHA NAWASENA',
        'tagline' => 'Sistem Vendor Cat Mobil',
        'logo' => '../../assets/img/logo.png',
    ];

    try {
        $stmt = $pdo->prepare(
            "SELECT setting_key, setting_value
             FROM settings
             WHERE setting_key IN ('company_name', 'company_tagline')
             LIMIT 2"
        );
        $stmt->execute();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['setting_key'] ?? '');
            $value = trim((string)($row['setting_value'] ?? ''));
            if ($key === 'company_name' && $value !== '') {
                $brand['name'] = $value;
            }
            if ($key === 'company_tagline' && $value !== '') {
                $brand['tagline'] = $value;
            }
        }
    } catch (Throwable $e) {
        // Jangan hentikan halaman jika settings bermasalah.
    }

    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $projectRoot = dirname(dirname(dirname($scriptName)));
    if ($projectRoot !== '' && $projectRoot !== '/' && $projectRoot !== '.') {
        $brand['logo'] = rtrim($projectRoot, '/') . '/admin/settings/logo.php?v=20260913';
    }

    return $brand;
}

$companyBrand = load_company_brand($pdo);
$companyName = $companyBrand['name'];
$companyTagline = $companyBrand['tagline'];
$companyLogo = $companyBrand['logo'];

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string {
    return 'Rp ' . number_format($value, 0, ',', '.');
}

function qty(float $value): string {
    return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
}

function normalize_date(string $date, string $fallback): string {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return ($dt && $dt->format('Y-m-d') === $date) ? $date : $fallback;
}

function page_url(int $page, int $branchId, string $search, string $start, string $end, array $extra = []): string {
    $params = [
        'page' => $page,
        'branch' => $branchId,
        'q' => $search,
        'start' => $start,
        'end' => $end,
    ];
    foreach ($extra as $key => $value) {
        $params[$key] = $value;
    }
    return 'index.php?' . http_build_query($params);
}

function print_url(int $branchId, string $search, string $start, string $end): string {
    return 'print.php?' . http_build_query([
        'branch' => $branchId,
        'q' => $search,
        'start' => $start,
        'end' => $end,
    ]);
}

function status_label(string $status): string {
    return match ($status) {
        'active' => 'Aktif',
        'inactive' => 'Tidak Aktif',
        default => ucwords(str_replace(['_', '-'], ' ', $status)),
    };
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$startDate = normalize_date(trim((string)($_GET['start'] ?? $monthStart)), $monthStart);
$endDate = normalize_date(trim((string)($_GET['end'] ?? $today)), $today);
if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$branchId = max(0, (int)($_GET['branch'] ?? 0));
$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';
$perPage = $isPrint ? PHP_INT_MAX : 5;

$branches = $pdo->query(
    "SELECT id, code, name, address, phone, status
     FROM cabangs
     WHERE status = 'active'
     ORDER BY name ASC"
)->fetchAll();

/* ==========================================================
   FILTER CABANG
========================================================== */
$filteredBranches = [];
foreach ($branches as $branch) {
    $matchesId = $branchId <= 0 || (int)$branch['id'] === $branchId;
    $haystack = strtolower(trim(($branch['code'] ?? '') . ' ' . ($branch['name'] ?? '')));
    $matchesSearch = $search === '' || str_contains($haystack, strtolower($search));

    if ($matchesId && $matchesSearch) {
        $filteredBranches[] = $branch;
    }
}

$visibleBranchIds = array_map(static fn(array $b): int => (int)$b['id'], $filteredBranches);

/* ==========================================================
   DATA AGGREGATION
   Sumber:
   - orders          : penjualan selesai
   - payments        : pembayaran berhasil
   - purchases       : pembelian selesai diterima
   - expenses        : pengeluaran dibayar
   - branch_stocks   : saldo stok saat ini
   - stock_transfers : arus transfer per cabang
========================================================== */
$metrics = [];
foreach ($filteredBranches as $branch) {
    $metrics[(int)$branch['id']] = [
        'sales' => 0.0,
        'sales_count' => 0,
        'payments' => 0.0,
        'payment_count' => 0,
        'purchases' => 0.0,
        'purchase_count' => 0,
        'expenses' => 0.0,
        'expense_count' => 0,
        'outstanding' => 0.0,
        'stock' => 0.0,
        'products' => 0,
        'low_stock' => 0,
        'transfer_out' => 0,
        'transfer_in' => 0,
    ];
}

$baseIds = $visibleBranchIds;

if ($baseIds) {
    $placeholders = [];
    $baseParams = [];
    foreach ($baseIds as $index => $id) {
        $key = ':bid' . $index;
        $placeholders[] = $key;
        $baseParams[$key] = $id;
    }
    $inSql = implode(',', $placeholders);

    // Penjualan selesai.
    $stmt = $pdo->prepare(
        "SELECT cabang_id,
                COUNT(*) AS trx_count,
                COALESCE(SUM(grand_total),0) AS amount
         FROM orders
         WHERE status = 'completed'
           AND DATE(order_date) BETWEEN :sales_start AND :sales_end
           AND cabang_id IN ($inSql)
         GROUP BY cabang_id"
    );
    $params = array_merge([':sales_start' => $startDate, ':sales_end' => $endDate], $baseParams);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['cabang_id'];
        if (isset($metrics[$id])) {
            $metrics[$id]['sales'] = (float)$row['amount'];
            $metrics[$id]['sales_count'] = (int)$row['trx_count'];
        }
    }

    // Pembayaran berhasil.
    $stmt = $pdo->prepare(
        "SELECT o.cabang_id,
                COUNT(pm.id) AS trx_count,
                COALESCE(SUM(pm.amount),0) AS amount
         FROM payments pm
         INNER JOIN orders o ON o.id = pm.order_id
         WHERE pm.status = 'paid'
           AND DATE(pm.payment_date) BETWEEN :payment_start AND :payment_end
           AND o.cabang_id IN ($inSql)
         GROUP BY o.cabang_id"
    );
    $params = array_merge([':payment_start' => $startDate, ':payment_end' => $endDate], $baseParams);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['cabang_id'];
        if (isset($metrics[$id])) {
            $metrics[$id]['payments'] = (float)$row['amount'];
            $metrics[$id]['payment_count'] = (int)$row['trx_count'];
        }
    }

    // Pembelian selesai diterima.
    $stmt = $pdo->prepare(
        "SELECT cabang_id,
                COUNT(*) AS trx_count,
                COALESCE(SUM(grand_total),0) AS amount
         FROM purchases
         WHERE status = 'received'
           AND DATE(purchase_date) BETWEEN :purchase_start AND :purchase_end
           AND cabang_id IN ($inSql)
         GROUP BY cabang_id"
    );
    $params = array_merge([':purchase_start' => $startDate, ':purchase_end' => $endDate], $baseParams);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['cabang_id'];
        if (isset($metrics[$id])) {
            $metrics[$id]['purchases'] = (float)$row['amount'];
            $metrics[$id]['purchase_count'] = (int)$row['trx_count'];
        }
    }

    // Pengeluaran yang sudah dibayar.
    $stmt = $pdo->prepare(
        "SELECT cabang_id,
                COUNT(*) AS trx_count,
                COALESCE(SUM(amount),0) AS amount
         FROM expenses
         WHERE status = 'paid'
           AND DATE(expense_date) BETWEEN :expense_start AND :expense_end
           AND cabang_id IN ($inSql)
         GROUP BY cabang_id"
    );
    $params = array_merge([':expense_start' => $startDate, ':expense_end' => $endDate], $baseParams);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['cabang_id'];
        if (isset($metrics[$id])) {
            $metrics[$id]['expenses'] = (float)$row['amount'];
            $metrics[$id]['expense_count'] = (int)$row['trx_count'];
        }
    }

    // Saldo stok saat ini.
    $stmt = $pdo->prepare(
        "SELECT cabang_id,
                COUNT(*) AS product_count,
                COALESCE(SUM(stock),0) AS total_stock,
                SUM(CASE WHEN stock <= minimum_stock THEN 1 ELSE 0 END) AS low_stock_count
         FROM branch_stocks
         WHERE cabang_id IN ($inSql)
         GROUP BY cabang_id"
    );
    $stmt->execute($baseParams);
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['cabang_id'];
        if (isset($metrics[$id])) {
            $metrics[$id]['stock'] = (float)$row['total_stock'];
            $metrics[$id]['products'] = (int)$row['product_count'];
            $metrics[$id]['low_stock'] = (int)$row['low_stock_count'];
        }
    }

    // Transfer keluar: sudah dikirim/diterima.
    $stmt = $pdo->prepare(
        "SELECT from_cabang_id AS cabang_id,
                COUNT(*) AS trx_count
         FROM stock_transfers
         WHERE status IN ('shipped','received')
           AND DATE(transfer_date) BETWEEN :out_start AND :out_end
           AND from_cabang_id IN ($inSql)
         GROUP BY from_cabang_id"
    );
    $params = array_merge([':out_start' => $startDate, ':out_end' => $endDate], $baseParams);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['cabang_id'];
        if (isset($metrics[$id])) {
            $metrics[$id]['transfer_out'] = (int)$row['trx_count'];
        }
    }

    // Transfer masuk: yang sudah diterima.
    $stmt = $pdo->prepare(
        "SELECT to_cabang_id AS cabang_id,
                COUNT(*) AS trx_count
         FROM stock_transfers
         WHERE status = 'received'
           AND DATE(transfer_date) BETWEEN :in_start AND :in_end
           AND to_cabang_id IN ($inSql)
         GROUP BY to_cabang_id"
    );
    $params = array_merge([':in_start' => $startDate, ':in_end' => $endDate], $baseParams);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['cabang_id'];
        if (isset($metrics[$id])) {
            $metrics[$id]['transfer_in'] = (int)$row['trx_count'];
        }
    }
}

$rows = [];
foreach ($filteredBranches as $branch) {
    $id = (int)$branch['id'];
    $m = $metrics[$id];
    $m['outstanding'] = max(0.0, $m['sales'] - $m['payments']);
    $m['cash_flow'] = $m['payments'] - $m['expenses'];
    $rows[] = array_merge($branch, $m);
}

usort($rows, static function (array $a, array $b): int {
    return ($b['sales'] <=> $a['sales']) ?: strcasecmp((string)$a['name'], (string)$b['name']);
});

/* ==========================================================
   SUMMARY
========================================================== */
$summarySales = array_sum(array_column($rows, 'sales'));
$summaryPayments = array_sum(array_column($rows, 'payments'));
$summaryPurchases = array_sum(array_column($rows, 'purchases'));
$summaryExpenses = array_sum(array_column($rows, 'expenses'));
$summaryStock = array_sum(array_column($rows, 'stock'));
$summaryOrders = array_sum(array_column($rows, 'sales_count'));
$summaryProducts = array_sum(array_column($rows, 'products'));
$summaryLowStock = array_sum(array_column($rows, 'low_stock'));
$summaryOutstanding = array_sum(array_column($rows, 'outstanding'));
$summaryTransferOut = array_sum(array_column($rows, 'transfer_out'));
$summaryTransferIn = array_sum(array_column($rows, 'transfer_in'));

$totalFiltered = count($rows);
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$pagedRows = array_slice($rows, $offset, $perPage);
$fromRow = $totalFiltered > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalFiltered);

/* Top 5 for visual performance. */
$chartRows = array_slice($rows, 0, 5);
$maxSales = 1.0;
foreach ($chartRows as $row) {
    $maxSales = max($maxSales, (float)$row['sales']);
}

/* ==========================================================
   CSV EXPORT
========================================================== */
if (($_GET['export'] ?? '') === 'csv') {
    $filename = 'rekap_per_cabang_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Kode Cabang', 'Nama Cabang', 'Penjualan', 'Pembayaran', 'Piutang',
        'Pembelian', 'Pengeluaran', 'Cash Flow', 'Transaksi Penjualan',
        'Produk', 'Stok', 'Stok Menipis/Habis', 'Transfer Keluar', 'Transfer Masuk', 'Status'
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['code'], $row['name'], $row['sales'], $row['payments'], $row['outstanding'],
            $row['purchases'], $row['expenses'], $row['cash_flow'], $row['sales_count'],
            $row['products'], $row['stock'], $row['low_stock'], $row['transfer_out'],
            $row['transfer_in'], status_label((string)$row['status'])
        ]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?> | <?= h($companyName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="../../assets/css/admin.css?v=20260907">
<link rel="stylesheet" href="style.css?v=20260913-recap">
</head>
<body class="recaps-page">
<aside class="sidebar" id="sidebar">
    <div class="brand"><img src="<?= h($companyLogo) ?>" class="img-fluid" alt="<?= h($companyName) ?>"><div class="brand-text"><div class="brand-name"><?= h($companyName) ?></div><small><?= h($companyTagline) ?></small></div></div>
    <nav class="sidebar-menu">
        <div class="menu-section"><div class="menu-title">MENU UTAMA</div><a href="../admin.php" class="menu-item"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a></div>
        <div class="menu-section"><div class="menu-title">MASTER DATA</div>
            <a href="../customers/" class="menu-item"><i class="bi bi-people"></i><span>Pelanggan</span></a>
            <a href="../vehicles/" class="menu-item"><i class="bi bi-car-front"></i><span>Kendaraan</span></a>
            <a href="../product/" class="menu-item"><i class="bi bi-box-seam"></i><span>Produk</span></a>
            <a href="../service/" class="menu-item"><i class="bi bi-tools"></i><span>Jasa</span></a>
            <a href="../supplier/" class="menu-item"><i class="bi bi-truck"></i><span>Supplier</span></a>
            <a href="../cabang/" class="menu-item"><i class="bi bi-shop"></i><span>Cabang</span></a>
            <a href="../users/" class="menu-item"><i class="bi bi-person-badge"></i><span>Pengguna</span></a>
            <a href="../roles/" class="menu-item"><i class="bi bi-shield-lock"></i><span>Role & Hak Akses</span></a>
        </div>
        <div class="menu-section"><div class="menu-title">TRANSAKSI</div>
            <a href="../orders/" class="menu-item"><i class="bi bi-cart3"></i><span>Penjualan</span></a>
            <a href="../purchases/" class="menu-item"><i class="bi bi-bag"></i><span>Pembelian</span></a>
            <a href="../stoks-transfer/" class="menu-item"><i class="bi bi-arrow-left-right"></i><span>Transfer Stok</span></a>
            <a href="../payments/" class="menu-item"><i class="bi bi-credit-card"></i><span>Pembayaran</span></a>
            <a href="../expenses/" class="menu-item"><i class="bi bi-receipt"></i><span>Pengeluaran</span></a>
            <a href="../stoks/" class="menu-item"><i class="bi bi-boxes"></i><span>Stok / Persediaan</span></a>
        </div>
        <div class="menu-section"><div class="menu-title">LAPORAN</div>
            <a href="../reports/" class="menu-item"><i class="bi bi-bar-chart-line"></i><span>Laporan</span></a>
            <a href="./" class="menu-item active"><i class="bi bi-pie-chart"></i><span>Rekap Cabang</span></a>
        </div>
        <div class="menu-section"><div class="menu-title">PENGATURAN</div>
            <a href="../settings/" class="menu-item"><i class="bi bi-gear"></i><span>Pengaturan</span></a>
            <a href="../logout.php" class="menu-item"><i class="bi bi-box-arrow-right"></i><span>Keluar</span></a>
        </div>
        <div class="sidebar-footer"><div class="paint-decoration"><i class="bi bi-paint-bucket"></i></div><strong>Better Paint</strong><span>Brighter Drive</span></div>
    </nav>
</aside>

<main class="main">
<div class="page-container">
    <header class="page-header">
        <div>
            <div class="breadcrumb"><span>Dashboard</span><i class="bi bi-chevron-right"></i><strong>Rekap Cabang</strong></div>
            <h1>Rekap Per Cabang</h1>
            <p>Ringkasan performa transaksi, pembayaran, pengeluaran, stok, dan transfer setiap cabang.</p>
        </div>
        <?php if ($isPrint): ?>
        <div class="print-heading">
            <strong>LAPORAN REKAP PER CABANG</strong>
            <span>Periode <?= h(date('d/m/Y', strtotime($startDate))) ?> s/d <?= h(date('d/m/Y', strtotime($endDate))) ?></span>
            <?php if ($branchId > 0): ?><span>Cabang: <?= h((string)($filteredBranches[0]['code'] ?? '')) ?> - <?= h((string)($filteredBranches[0]['name'] ?? '')) ?></span><?php else: ?><span>Cabang: Semua Cabang</span><?php endif; ?>
            <?php if ($search !== ''): ?><span>Pencarian: <?= h($search) ?></span><?php endif; ?>
            <span>Dicetak: <?= h(date('d/m/Y H:i')) ?></span>
        </div>
        <?php endif; ?>
        <div class="header-actions">
            <a class="btn-secondary" target="_blank" rel="noopener" href="<?= h(print_url($branchId,$search,$startDate,$endDate)) ?>"><i class="bi bi-printer"></i> Cetak</a>
            <a class="btn-primary" href="<?= h(page_url(1,$branchId,$search,$startDate,$endDate,['export'=>'csv'])) ?>"><i class="bi bi-download"></i> Export CSV</a>
        </div>
    </header>

    <form method="get" class="recap-filter">
        <div class="filter-group filter-search">
            <label for="q">Cari Cabang</label>
            <div class="search-input"><i class="bi bi-search"></i><input id="q" type="text" name="q" value="<?= h($search) ?>" placeholder="Kode atau nama cabang"></div>
        </div>
        <div class="filter-group">
            <label for="branch">Cabang</label>
            <select id="branch" name="branch">
                <option value="0">Semua Cabang</option>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= (int)$branch['id'] ?>" <?= $branchId === (int)$branch['id'] ? 'selected' : '' ?>><?= h($branch['code'] . ' - ' . $branch['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="start">Tanggal Mulai</label>
            <input id="start" type="date" name="start" value="<?= h($startDate) ?>">
        </div>
        <div class="filter-group">
            <label for="end">Tanggal Akhir</label>
            <input id="end" type="date" name="end" value="<?= h($endDate) ?>">
        </div>
        <button type="submit" class="btn-filter"><i class="bi bi-funnel"></i> Terapkan</button>
    </form>

    <section class="summary-grid">
        <div class="summary-card"><div class="summary-icon blue"><i class="bi bi-cart-check"></i></div><div><span>Penjualan Selesai</span><strong><?= h(money($summarySales)) ?></strong><small><?= number_format($summaryOrders,0,',','.') ?> transaksi</small></div></div>
        <div class="summary-card"><div class="summary-icon purple"><i class="bi bi-credit-card"></i></div><div><span>Pembayaran Diterima</span><strong><?= h(money($summaryPayments)) ?></strong><small>Piutang tercatat <?= h(money($summaryOutstanding)) ?></small></div></div>
        <div class="summary-card"><div class="summary-icon green"><i class="bi bi-bag-check"></i></div><div><span>Pembelian Selesai</span><strong><?= h(money($summaryPurchases)) ?></strong><small><?= number_format(array_sum(array_column($rows,'purchase_count')),0,',','.') ?> transaksi</small></div></div>
        <div class="summary-card"><div class="summary-icon orange"><i class="bi bi-receipt"></i></div><div><span>Pengeluaran Dibayar</span><strong><?= h(money($summaryExpenses)) ?></strong><small>Periode <?= h(date('d/m/Y',strtotime($startDate))) ?> – <?= h(date('d/m/Y',strtotime($endDate))) ?></small></div></div>
    </section>

    <section class="summary-mini-grid">
        <div class="mini-card"><span><i class="bi bi-boxes"></i> Total Stok</span><strong><?= h(qty($summaryStock)) ?></strong><small>Saldo stok semua cabang</small></div>
        <div class="mini-card"><span><i class="bi bi-exclamation-triangle"></i> Stok Menipis / Habis</span><strong><?= number_format($summaryLowStock,0,',','.') ?></strong><small>Item di bawah minimum</small></div>
        <div class="mini-card"><span><i class="bi bi-arrow-up-right"></i> Transfer Keluar</span><strong><?= number_format($summaryTransferOut,0,',','.') ?></strong><small>Transfer terkirim</small></div>
        <div class="mini-card"><span><i class="bi bi-arrow-down-left"></i> Transfer Masuk</span><strong><?= number_format($summaryTransferIn,0,',','.') ?></strong><small>Transfer diterima</small></div>
    </section>

    <section class="dashboard-grid">
        <div class="report-card performance-card">
            <div class="card-header"><div><h2>Performa Penjualan per Cabang</h2><p>5 cabang dengan nilai penjualan selesai tertinggi pada periode terpilih.</p></div></div>
            <div class="performance-list">
                <?php if ($chartRows): ?>
                    <?php foreach ($chartRows as $row): $percent = $summarySales > 0 ? round(((float)$row['sales'] / $summarySales) * 100) : 0; $width = max(4, round(((float)$row['sales'] / $maxSales) * 100)); ?>
                        <div class="performance-row">
                            <div class="performance-top"><strong><?= h($row['code'] . ' - ' . $row['name']) ?></strong><span><?= h(money((float)$row['sales'])) ?></span></div>
                            <div class="progress"><i style="width:<?= $width ?>%"></i></div>
                            <small><?= (int)$percent ?>% dari total penjualan</small>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state"><i class="bi bi-bar-chart"></i><strong>Belum ada data cabang</strong><span>Ubah filter atau pastikan sudah ada cabang aktif.</span></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="report-card snapshot-card">
            <div class="card-header"><div><h2>Snapshot Cabang</h2><p>Ringkasan posisi operasional pada data saat ini.</p></div></div>
            <div class="snapshot-list">
                <div><span>Jumlah cabang</span><strong><?= number_format($totalFiltered,0,',','.') ?></strong></div>
                <div><span>Total produk di cabang</span><strong><?= number_format($summaryProducts,0,',','.') ?></strong></div>
                <div><span>Total stok</span><strong><?= h(qty($summaryStock)) ?></strong></div>
                <div><span>Stok perlu perhatian</span><strong><?= number_format($summaryLowStock,0,',','.') ?></strong></div>
                <div><span>Cash flow pembayaran − pengeluaran</span><strong><?= h(money($summaryPayments - $summaryExpenses)) ?></strong></div>
            </div>
            <div class="snapshot-note"><i class="bi bi-info-circle"></i><span>Rekap tidak menghitung laba. Nilai di atas merupakan ringkasan transaksi dari tabel operasional yang sudah digunakan oleh sistem.</span></div>
        </div>
    </section>

    <section class="report-card table-card">
        <div class="card-header">
            <div><h2>Rekap Per Cabang</h2><p>Data <?= $totalFiltered > 0 ? h($fromRow) . '–' . h($toRow) . ' dari ' . h($totalFiltered) : '0' ?> cabang · periode <?= h(date('d M Y',strtotime($startDate))) ?> s/d <?= h(date('d M Y',strtotime($endDate))) ?></p></div>
            <div class="table-actions"><a href="<?= h(page_url(1,$branchId,$search,$startDate,$endDate,['export'=>'csv'])) ?>"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a><a target="_blank" rel="noopener" href="<?= h(print_url($branchId,$search,$startDate,$endDate)) ?>"><i class="bi bi-printer"></i> Print</a></div>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Cabang</th>
                        <th>Penjualan</th>
                        <th>Pembayaran</th>
                        <th>Piutang</th>
                        <th>Pembelian</th>
                        <th>Pengeluaran</th>
                        <th>Stok</th>
                        <th>Low Stock</th>
                        <th>Transfer</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$pagedRows): ?>
                    <tr><td colspan="11"><div class="empty-state table-empty"><i class="bi bi-shop"></i><strong>Tidak ada data rekap</strong><span>Ubah cabang, kata kunci, atau periode laporan.</span></div></td></tr>
                <?php else: ?>
                    <?php foreach ($pagedRows as $i => $row): ?>
                        <tr>
                            <td><?= $offset + $i + 1 ?></td>
                            <td><strong><?= h($row['code']) ?></strong><small><?= h($row['name']) ?></small></td>
                            <td><strong><?= h(money((float)$row['sales'])) ?></strong><small><?= number_format((int)$row['sales_count'],0,',','.') ?> transaksi</small></td>
                            <td><?= h(money((float)$row['payments'])) ?><small><?= number_format((int)$row['payment_count'],0,',','.') ?> pembayaran</small></td>
                            <td><?= h(money((float)$row['outstanding'])) ?></td>
                            <td><?= h(money((float)$row['purchases'])) ?><small><?= number_format((int)$row['purchase_count'],0,',','.') ?> transaksi</small></td>
                            <td><?= h(money((float)$row['expenses'])) ?><small><?= number_format((int)$row['expense_count'],0,',','.') ?> transaksi</small></td>
                            <td><strong><?= h(qty((float)$row['stock'])) ?></strong><small><?= number_format((int)$row['products'],0,',','.') ?> item</small></td>
                            <td><span class="status <?= (int)$row['low_stock'] > 0 ? 'warning' : 'success' ?>"><?= number_format((int)$row['low_stock'],0,',','.') ?></span></td>
                            <td><span class="transfer-count"><i class="bi bi-arrow-up-right"></i><?= (int)$row['transfer_out'] ?></span><span class="transfer-count incoming"><i class="bi bi-arrow-down-left"></i><?= (int)$row['transfer_in'] ?></span></td>
                            <td><span class="status success"><?= h(status_label((string)$row['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$isPrint): ?>
        <div class="pagination">
            <span>Menampilkan <?= $fromRow ?>–<?= $toRow ?> dari <?= $totalFiltered ?> cabang</span>
            <div class="pagination-controls">
                <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page > 1 ? h(page_url($page - 1,$branchId,$search,$startDate,$endDate)) : '#' ?>"><i class="bi bi-chevron-left"></i></a>
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                if ($startPage > 1):
                ?><a href="<?= h(page_url(1,$branchId,$search,$startDate,$endDate)) ?>">1</a><?php
                    if ($startPage > 2): ?><span class="ellipsis">...</span><?php endif;
                endif;
                for ($p = $startPage; $p <= $endPage; $p++):
                ?><a class="<?= $p === $page ? 'active' : '' ?>" href="<?= h(page_url($p,$branchId,$search,$startDate,$endDate)) ?>"><?= $p ?></a><?php
                endfor;
                if ($endPage < $totalPages):
                    if ($endPage < $totalPages - 1): ?><span class="ellipsis">...</span><?php endif;
                ?><a href="<?= h(page_url($totalPages,$branchId,$search,$startDate,$endDate)) ?>"><?= $totalPages ?></a><?php endif; ?>
                <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page < $totalPages ? h(page_url($page + 1,$branchId,$search,$startDate,$endDate)) : '#' ?>"><i class="bi bi-chevron-right"></i></a>
            </div>
        </div>
        <?php endif; ?>
    </section>
</div>
</main>

<?php if ($isPrint): ?>
<script>
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 250);
});
</script>
<?php endif; ?>
</body>
</html>
