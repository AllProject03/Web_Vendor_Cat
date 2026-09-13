<?php
/* =====================================================
   CEK LOGIN
===================================================== */
require_once __DIR__ . '/../config/auth.php';


/* =====================================================
   KONEKSI DATABASE
===================================================== */
require_once __DIR__ . '/../config/config.php';

require_once __DIR__ . '/settings/company.php';

$company = get_company_profile($pdo);
$companyName = $company['company_name'] !== '' ? $company['company_name'] : 'PT. GIAN GANESHA NAWASENA';
$companyTagline = $company['company_tagline'] !== '' ? $company['company_tagline'] : 'Sistem Vendor Cat Mobil';
$companyLogo = !empty($company['company_logo_url']) ? $company['company_logo_url'] : '../assets/img/logo.png';


/* =====================================================
   HELPER FORMAT RUPIAH
===================================================== */
function rupiah($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function shortRupiah($value): string {
    $value = (float)$value;
    if ($value >= 1000000000) return 'Rp ' . number_format($value / 1000000000, 1, ',', '.') . ' M';
    if ($value >= 1000000) return 'Rp ' . number_format($value / 1000000, 1, ',', '.') . ' Jt';
    if ($value >= 1000) return 'Rp ' . number_format($value / 1000, 0, ',', '.') . ' Rb';
    return rupiah($value);
}


/* =====================================================
   DATA ADMIN LOGIN
===================================================== */
$namaAdmin = $_SESSION['user_name'] ?? 'Admin';
$roleAdmin = $_SESSION['role_name'] ?? 'Administrator';

// ==========================================
// PERIODE BULAN
// ==========================================

$awalBulan = date('Y-m-01 00:00:00');

$awalBulanBerikutnya = date(
    'Y-m-01 00:00:00',
    strtotime('+1 month')
);

$awalBulanLalu = date(
    'Y-m-01 00:00:00',
    strtotime('-1 month')
);

/* =====================================================
   STATUS LABEL
===================================================== */
function statusLabel($status): string {
    return match ($status) {
        'completed', 'received' => 'Selesai',
        'pending' => 'Menunggu',
        'processing' => 'Diproses',
        'ordered' => 'Dipesan',
        'partial' => 'Sebagian',
        'requested' => 'Diminta',
        'approved' => 'Disetujui',
        'shipped' => 'Dikirim',
        'cancelled' => 'Dibatalkan',
        'draft' => 'Draft',
        default => ucfirst((string)$status),
    };
}

function statusBadgeClass($type, $status): string {
    if ($type === 'sale') {
        return $status === 'completed' ? 'success' : 'pending';
    }
    if ($type === 'purchase') {
        return $status === 'received' ? 'received' : 'pending';
    }
    return in_array($status, ['received', 'approved'], true) ? 'success' : 'pending';
}

/* =====================================================
   STATUS PERSENTASE
===================================================== */
function growthPercent($current, $previous): float {
    $current = (float)$current;
    $previous = (float)$previous;
    if ($previous == 0) {
        return $current > 0 ? 100 : 0;
    }
    return (($current - $previous) / abs($previous)) * 100;
}


function growthClass($growth): string {
    $growth = (float)$growth;
    if ($growth > 0) {
        return 'positive';
    }
    if ($growth < 0) {
        return 'negative';
    }
    return 'neutral';
}


function growthIcon($growth): string {
    $growth = (float)$growth;
    if ($growth > 0) {
        return 'bi-arrow-up';
    }
    if ($growth < 0) {
        return 'bi-arrow-down';
    }
    return '';
}


// ==========================================
// TOTAL PENJUALAN BULAN INI
// ==========================================
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(grand_total), 0)
    FROM orders
    WHERE status = 'completed'
      AND order_date >= :awal
      AND order_date < :akhir
");

$stmt->execute([
    ':awal'  => $awalBulan,
    ':akhir' => $awalBulanBerikutnya
]);

$penjualanBulanIni = (float) $stmt->fetchColumn();


// ==========================================
// TOTAL PENJUALAN BULAN LALU
// ==========================================
$penjualan_lalu = $pdo->prepare("
    SELECT COALESCE(SUM(grand_total), 0)
    FROM orders
    WHERE status = 'completed'
      AND order_date >= :awal
      AND order_date < :akhir
");

$penjualan_lalu->execute([
    ':awal'  => $awalBulanLalu,
    ':akhir' => $awalBulan
]);

$penjualanBulanLalu = (float) $penjualan_lalu->fetchColumn();


// ==========================================
// PERTUMBUHAN PENJUALAN
// ==========================================
$growthPenjualan = growthPercent(
    $penjualanBulanIni,
    $penjualanBulanLalu
);

// ==========================================
// TOTAL ORDER BULAN INI
// ==========================================
$stmt = $pdo->prepare("SELECT COUNT(*)
    FROM orders
    WHERE status <> 'cancelled'
      AND order_date >= :awal
      AND order_date < :akhir
");

$stmt->execute([
    ':awal'  => $awalBulan,
    ':akhir' => $awalBulanBerikutnya
]);

$orderBulanIni = (int) $stmt->fetchColumn();


// ==========================================
// TOTAL ORDER BULAN LALU
// ==========================================
$stmt = $pdo->prepare(" SELECT COUNT(*)
    FROM orders
    WHERE status <> 'cancelled'
      AND order_date >= :awal
      AND order_date < :akhir
");

$stmt->execute([
    ':awal'  => $awalBulanLalu,
    ':akhir' => $awalBulan
]);

$orderBulanLalu = (int) $stmt->fetchColumn();


// ==========================================
// PERTUMBUHAN ORDER
// ==========================================
$growthOrder = growthPercent(
    $orderBulanIni,
    $orderBulanLalu
);

// ==========================================
// TOTAL STOK PRODUK
// ==========================================
$stmt = $pdo->query("
    SELECT COALESCE(SUM(stock), 0)
    FROM branch_stocks
");

$totalStock = (float) $stmt->fetchColumn();


// ==========================================
// PRODUK YANG PERLU DIRESTOCK
// ==========================================
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM branch_stocks
    WHERE stock <= minimum_stock
");

$stokMenipisCount = (int) $stmt->fetchColumn();

// ==========================================
// TOTAL PELANGGAN
// ==========================================
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM customers
");

$totalCustomer = (int) $stmt->fetchColumn();


// ==========================================
// PELANGGAN BARU BULAN INI
// ==========================================
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM customers
    WHERE created_at >= :awal
      AND created_at < :akhir
");

$stmt->execute([
    ':awal'  => $awalBulan,
    ':akhir' => $awalBulanBerikutnya
]);

$pelangganBulanIni = (int) $stmt->fetchColumn();


// ==========================================
// PELANGGAN BARU BULAN LALU
// ==========================================
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM customers
    WHERE created_at >= :awal
      AND created_at < :akhir
");

$stmt->execute([
    ':awal'  => $awalBulanLalu,
    ':akhir' => $awalBulan
]);

$pelangganBulanLalu = (int) $stmt->fetchColumn();


// ==========================================
// PERTUMBUHAN PELANGGAN
// ==========================================
$growthPelanggan = growthPercent(
    $pelangganBulanIni,
    $pelangganBulanLalu
);

/* =====================================================
   TRANSAKSI TERBARU
===================================================== */
$transaksi = $pdo->query("SELECT o.order_date AS tanggal, 'Penjualan' AS jenis,c.name AS pihak, o.grand_total AS total, o.status AS status
    FROM orders o
    INNER JOIN customers c ON c.id = o.customer_id
    ORDER BY o.order_date DESC
    LIMIT 5
");

$transaksiTerbaru = $transaksi->fetchAll();

/* =====================================================
   STOK MENIPIS
===================================================== */
$stok_menipis = $pdo->query(" SELECT
        p.name AS product_name,
        p.unit,
        bs.stock,
        bs.minimum_stock,
        c.name AS cabang_name

    FROM branch_stocks bs
    INNER JOIN products p ON p.id = bs.product_id
    INNER JOIN cabangs c ON c.id = bs.cabang_id
    WHERE bs.stock <= bs.minimum_stock
    ORDER BY bs.stock ASC
    LIMIT 5
");
$stokMenipis = $stok_menipis->fetchAll();

/* =====================================================
   DATA GRAFIK PENJUALAN 7 HARI TERAKHIR
===================================================== */
$grafikPenjualan = [];
for ($i = 6; $i >= 0; $i--) {
    $tanggal = date('Y-m-d',strtotime("-$i days"));
    $mulai = $tanggal . ' 00:00:00';
    $akhir = date('Y-m-d 00:00:00',strtotime($tanggal . ' +1 day'));

    $grafik = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) AS total_penjualan, COUNT(*) AS total_order
        FROM orders
        WHERE status = 'completed'
          AND order_date >= :mulai
          AND order_date < :akhir
    ");

    $grafik->execute([
        ':mulai' => $mulai,
        ':akhir' => $akhir
    ]);

    $data = $grafik->fetch();

    $grafikPenjualan[] = [
        'tanggal' => $tanggal,
        'label' => date('d M', strtotime($tanggal)),
        'penjualan' => (float)$data['total_penjualan'],
        'order' => (int)$data['total_order']
    ];
}

/* =========================================================
   DATA DONUT CHART - PENJUALAN BERDASARKAN KATEGORI
   ========================================================= */
$donut = $pdo->prepare("SELECT
        pc.name AS category_name,
        COALESCE(SUM(od.subtotal), 0) AS total_penjualan
    FROM order_details od
    INNER JOIN orders o
        ON o.id = od.order_id
    INNER JOIN products p
        ON p.id = od.product_id
    INNER JOIN product_categories pc
        ON pc.id = p.category_id
    WHERE o.status = 'completed'
      AND o.order_date >= :awal
      AND o.order_date < :akhir
    GROUP BY pc.id, pc.name
    ORDER BY total_penjualan DESC
");

$donut->execute([
    ':awal'  => $awalBulan,
    ':akhir' => $awalBulanBerikutnya
]);

$dataKategori = $donut->fetchAll();

$totalKategori = 0;

foreach ($dataKategori as $kategori) {
    $totalKategori += (float) $kategori['total_penjualan'];
}

$warnaKategori = [
    '#2377df',
    '#ef3b4f',
    '#31c48d',
    '#7c3aed',
    '#f59e0b',
    '#eab839',
    '#06b6d4',
    '#ec4899'
];

$gradientParts = [];
$persentaseMulai = 0;

foreach ($dataKategori as $index => $kategori) {
    if ($totalKategori <= 0) {
        break;
    }

    $persentase = ((float) $kategori['total_penjualan'] / $totalKategori) * 100;
    $persentaseAkhir = $persentaseMulai + $persentase;
    $warna = $warnaKategori[$index % count($warnaKategori)];

    $gradientParts[] = $warna . ' ' .
        round($persentaseMulai, 2) . '% ' .
        round($persentaseAkhir, 2) . '%';

    $persentaseMulai = $persentaseAkhir;
}

$donutGradient = $totalKategori > 0
    ? 'conic-gradient(' . implode(', ', $gradientParts) . ')'
    : 'conic-gradient(#e5e7eb 0% 100%)';

function formatRupiahDashboard($nominal)
{
    return 'Rp ' . number_format((float) $nominal, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c1a2d">
    <title>Admin | <?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.4.1/dist/css/bootstrap.min.css" integrity="sha384-Vkoo8x4CGsO3+Hhxv8T/Q5PaXtkKtu6ug5TOeNV6gBiFeWPGFN9MuhOf23Q9Ifjh" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../assets/css/admin.css">

</head>
<body>
<input type="checkbox" id="sidebarToggle" class="sidebar-toggle" aria-hidden="true">

<!-- =========================================================
     SIDEBAR
========================================================= -->
<aside class="sidebar" id="sidebar">

    <!-- =====================================================
         BRAND
    ====================================================== -->
    <div class="brand">
        <img src="<?= htmlspecialchars($companyLogo, ENT_QUOTES, 'UTF-8') ?>" class="img-fluid" alt="<?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?>">

        <div class="brand-text">
            <div class="brand-name"><?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></div>
            <small><?= htmlspecialchars($companyTagline, ENT_QUOTES, 'UTF-8') ?></small>
        </div>
    </div>



    <!-- =====================================================
         SIDEBAR MENU
    ====================================================== -->
    <nav class="sidebar-menu">
        <!-- =================================================
             MENU UTAMA
        ================================================== -->
        <div class="menu-section">
            <div class="menu-title">MENU UTAMA</div>
            <!-- DASHBOARD -->
            <a href="admin.php" class="menu-item active">
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>
        </div>

        <!-- =================================================
             MASTER DATA
        ================================================== -->
        <div class="menu-section">

            <div class="menu-title">MASTER DATA</div>

            <!-- PELANGGAN -->
            <a href="customers/" class="menu-item">
                <i class="bi bi-people"></i>
                <span>Pelanggan</span>
            </a>

            <!-- KENDARAAN -->
            <a href="vehicles/" class="menu-item">
                <i class="bi bi-car-front"></i>
                <span>Kendaraan</span>
            </a>

            <!-- PRODUK -->
            <a href="product/" class="menu-item">
                <i class="bi bi-box-seam"></i>
                <span>Produk</span>
            </a>

            <!-- JASA -->
            <a href="service/" class="menu-item">
                <i class="bi bi-tools"></i>
                <span>Jasa</span>
            </a>

            <!-- SUPPLIER -->
            <a href="supplier/" class="menu-item">
                <i class="bi bi-truck"></i>
                <span>Supplier</span>
            </a>

            <!-- CABANG -->
            <a href="cabang/" class="menu-item">
                <i class="bi bi-shop"></i>
                <span>Cabang</span>
            </a>

            <!-- PENGGUNA -->
            <a href="users/" class="menu-item">
                <i class="bi bi-person-badge"></i>
                <span>Pengguna</span>
            </a>

            <!-- ROLE & HAK AKSES -->
            <a href="roles/" class="menu-item">
                <i class="bi bi-shield-lock"></i>
                <span>Role & Hak Akses</span>
            </a>

        </div>

        <!-- =================================================
             TRANSAKSI
        ================================================== -->
        <div class="menu-section">
            <div class="menu-title">
                TRANSAKSI
            </div>

            <!-- PENJUALAN -->
            <a href="orders/" class="menu-item">
                <i class="bi bi-cart3"></i>
                <span>Penjualan</span>
            </a>


            <!-- PEMBELIAN -->
            <a href="purchases/" class="menu-item">
                <i class="bi bi-bag"></i>
                <span>Pembelian</span>
            </a>

            <!-- TRANSFER STOK -->
            <a href="stoks-transfer/" class="menu-item">
                <i class="bi bi-arrow-left-right"></i>
                <span>Transfer Stok</span>
            </a>

            <!-- PEMBAYARAN -->
            <a href="payments/" class="menu-item">
                <i class="bi bi-credit-card"></i>
                <span>Pembayaran</span>
            </a>

            <!-- PENGELUARAN -->
            <a href="expenses/" class="menu-item">
                <i class="bi bi-receipt"></i>
                <span>Pengeluaran</span>
            </a>

            <!-- STOK -->
            <a href="stoks/" class="menu-item">
                <i class="bi bi-boxes"></i>
                <span>Stok / Persediaan</span>
            </a>
        </div>

        <!-- =================================================
             LAPORAN
        ================================================== -->
        <div class="menu-section">
            <div class="menu-title">
                LAPORAN
            </div>

            <!-- LAPORAN -->
            <a href="reports/" class="menu-item">
                <i class="bi bi-bar-chart-line"></i>
                <span>Laporan</span>
            </a>

            <!-- REKAP CABANG -->
            <a href="recaps/" class="menu-item">
                <i class="bi bi-pie-chart"></i>
                <span>Rekap Cabang</span>
            </a>
        </div>

        <!-- =================================================
             PENGATURAN
        ================================================== -->
        <div class="menu-section">
            <div class="menu-title">
                PENGATURAN
            </div>

            <!-- PENGATURAN -->
            <a href="settings/" class="menu-item">
                <i class="bi bi-gear"></i>
                <span>Pengaturan</span>
            </a>

            <a href="logout.php" class="menu-item">
                <i class="bi bi-box-arrow-right"></i>
                <span>Keluar</span>
            </a>
        </div>

         <!-- =====================================================
         SIDEBAR FOOTER
        ====================================================== -->
        <div class="sidebar-footer">
            <div class="paint-decoration">
                <i class="bi bi-paint-bucket"></i>
            </div>
            <strong>Better Paint</strong>
            <span>Brighter Drive</span>
        </div>
    </nav>
</aside>



<!-- =========================================================
     MAIN
========================================================= -->
<main class="main">
    <!-- =====================================================
         TOPBAR
    ====================================================== -->
    <header class="topbar">
        <!-- MOBILE MENU -->
        <label class="mobile-menu" for="sidebarToggle" aria-label="Buka menu">
            <i class="bi bi-list"></i>
        </label>

        <!-- =================================================
             SEARCH
        ================================================== -->
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" id="globalSearch" placeholder="Cari pelanggan, produk, order..." autocomplete="off">
            
            <div
                class="global-search-results"
                id="globalSearchResults">
            </div>

        </div>

        <!-- =================================================
             TOPBAR RIGHT
        ================================================== -->
        <div class="topbar-right">
            <!-- NOTIFICATION -->
            <button class="icon-button" type="button" title="Notifikasi">
                <i class="bi bi-bell"></i>
                <!-- <span class="notification-badge">3</span> -->
            </button>


            <!-- FULLSCREEN -->
            <!-- PROFILE -->
            <div class="profile">
                <div class="avatar">
                    <i class="bi bi-person-fill"></i>
                </div>

                <div class="profile-info">
                    <strong><?= htmlspecialchars($namaAdmin) ?></strong>
                    <small><?= htmlspecialchars($roleAdmin) ?></small>
                </div>
            </div>
        </div>
    </header>

    <div class="content" id="mainContent">
        <!-- =================================================
             PAGE HEADER
        ================================================== -->
        <div class="page-header">
            <div>
                <h1>Dashboard</h1>
                <p>Selamat datang di sistem vendor cat mobil. Berikut ringkasan data hari ini.</p>
            </div>

            <div class="date-info">
                <div class="date-box">
                    <i class="bi bi-calendar3"></i>
                    <span><?= date('l, j F Y') ?></span>
                </div>

                <div class="date-box">
                    <i class="bi bi-clock"></i>
                    <span id="currentTime">00.00 WIB</span>
                </div>
            </div>
        </div>

        <!-- =================================================
             STATISTICS
        ================================================== -->
        <div class="stats-grid">
            <!-- TOTAL PENJUALAN -->
            <div class="stat-card">
                <div class="stat-icon blue">
                    <i class="bi bi-cart3"></i>
                </div>

                <div class="stat-content">
                    <span>Total Penjualan</span>
                    <h2><?= rupiah($penjualanBulanIni) ?></h2>

                    <div class="stat-growth <?= growthClass($growthPenjualan) ?>">
                        <?php if ($growthPenjualan > 0): ?>
                            <i class="bi bi-arrow-up"></i>
                        <?php elseif ($growthPenjualan < 0): ?>
                            <i class="bi bi-arrow-down"></i>
                        <?php endif; ?>
                        <?= number_format(abs($growthPenjualan), 1, ',', '.') ?>%
                        <small>dari bulan lalu</small>
                    </div>
                </div>
            </div>

            <!-- TOTAL ORDER -->
            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="bi bi-box-seam"></i>
                </div>

                <div class="stat-content">
                    <span>Total Order</span>
                    <h2><?= number_format($orderBulanIni, 0, ',', '.') ?></h2>

                    <div class="stat-growth <?= growthClass($growthOrder) ?>">
                        <?php if ($growthOrder > 0): ?>
                            <i class="bi bi-arrow-up"></i>
                        <?php elseif ($growthOrder < 0): ?>
                            <i class="bi bi-arrow-down"></i>
                        <?php endif; ?>
                        <?= number_format(abs($growthOrder),1,',','.') ?>%
                        <small>dari bulan lalu</small>
                    </div>
                </div>
            </div>

            <!-- STOK -->
            <div class="stat-card">
                <div class="stat-icon red">
                    <i class="bi bi-boxes"></i>
                </div>

                <div class="stat-content">
                    <span>Stok Produk</span>
                    <h2><?= number_format($totalStock, 0, ',', '.') ?> Item</h2>

                    <div class="stat-growth neutral">
                        <?php if ($stokMenipisCount > 0): ?>
                            <i class="bi bi-exclamation-triangle"></i>
                        <?php endif; ?>
                        <?= number_format( $stokMenipisCount, 0, ',', '.') ?>
                        <small>produk perlu direstock</small>

                    </div>
                </div>
            </div>

            <!-- PELANGGAN -->
            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="bi bi-people-fill"></i>
                </div>

                <div class="stat-content">
                    <span>Jumlah Pelanggan</span>
                    <h2><?= number_format($totalCustomer, 0, ',', '.') ?></h2>

                     <div class="stat-growth <?= growthClass($growthPelanggan) ?>">
                        <?php if ($growthPelanggan > 0): ?>
                            <i class="bi bi-arrow-up"></i>
                        <?php elseif ($growthPelanggan < 0): ?>
                            <i class="bi bi-arrow-down"></i>
                        <?php endif; ?>
                        <?= number_format(abs($growthPelanggan),1,',','.') ?>%
                        <small>pelanggan baru dari bulan lalu</small>

                    </div>
                </div>
            </div>
        </div>

        <!-- =================================================
             CHART SECTION
        ================================================== -->
        <div class="chart-grid">
            <!-- SALES CHART -->
            <div class="card sales-card">
                <div class="card-header">
                    <div>
                        <h3>Grafik Penjualan</h3>
                        <span>Performa penjualan 7 hari terakhir</span>
                    </div>
                </div>

                <div class="legend">
                    <span><i class="legend-blue"></i>Penjualan</span>
                    <span><i class="legend-red"></i>Order</span>
                </div>

                <div class="chart-container php-chart">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>

            <!-- CATEGORY -->
            <div class="card category-card">
                <div class="card-header">
                    <div>
                        <h3>Penjualan per Kategori</h3>
                        <span>Distribusi penjualan bulan ini</span>
                    </div>
                </div>

                <div class="category-chart php-donut">
                    <div class="donut-ring" style="background: <?= htmlspecialchars($donutGradient, ENT_QUOTES, 'UTF-8') ?>;"></div>

                    <div class="chart-center">
                        <span>Total</span>
                        <strong>
                            <?php if ($totalKategori > 0): ?>
                                Rp <?= number_format($totalKategori / 1000000, 1, ',', '.') ?> Jt
                            <?php else: ?>
                                Rp 0 Jt
                            <?php endif; ?>
                        </strong>
                    </div>
                </div>

                <div class="category-list">
                    <?php if (!empty($dataKategori)): ?>
                        <?php foreach ($dataKategori as $index => $kategori): ?>
                            <?php
                            $nilaiKategori = (float) $kategori['total_penjualan'];
                            $persentaseKategori = $totalKategori > 0
                                ? ($nilaiKategori / $totalKategori) * 100
                                : 0;
                            $warna = $warnaKategori[$index % count($warnaKategori)];
                            ?>
                            <div>
                                <span>
                                    <i class="dot" style="background-color: <?= $warna ?>;"></i>
                                    <?= htmlspecialchars($kategori['category_name']) ?>
                                </span>

                                <strong>
                                    <?= number_format($persentaseKategori, 0) ?>%
                                </strong>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div>
                            <span style="color:#94a3b8;">
                                Belum ada penjualan bulan ini
                            </span>
                            <strong>0%</strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>



        <!-- =================================================
             BOTTOM GRID
        ================================================== -->
        <div class="bottom-grid">
            <!-- =================================================
                 TRANSAKSI TERBARU
            ================================================== -->
            <div class="card transaction-card">
                <div class="card-header">
                    <div>
                        <h3>Transaksi Terbaru</h3>
                        <span>Aktivitas transaksi terbaru</span>
                    </div>
                    <a href="orders/" class="dashboard-link">Lihat Semua</a>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>Jenis</th>
                                <th>Pelanggan / Supplier</th>
                                <th>Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($transaksiTerbaru)): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center;">Belum ada transaksi.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transaksiTerbaru as $index => $trx): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= date('d-m-Y', strtotime($trx['tanggal'])) ?></td>
                                        <td><span class="badge sales">Penjualan</span></td>
                                        <td><?= htmlspecialchars($trx['pihak']) ?></td>
                                        <td><?= rupiah($trx['total']) ?></td>
                                        <td><?php if ($trx['status'] == 'completed'): ?>
                                            <span class="badge success">Selesai</span>
                                            
                                            <?php elseif ($trx['status'] == 'pending'): ?>
                                                <span class="badge pending">Menunggu</span>

                                            <?php elseif ($trx['status'] == 'processing'): ?>
                                                <span class="badge pending">Diproses</span>

                                            <?php elseif ($trx['status'] === 'draft'): ?>
                                                <span class="badge pending">Draft</span>

                                            <?php else: ?>
                                                <span class="badge pending"><?= htmlspecialchars($trx['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- =================================================
                 STOK MENIPIS
            ================================================== -->
            <div class="card stock-card">
                <div class="card-header">
                    <div>
                        <h3>Stok Menipis</h3>
                        <span>Produk yang perlu segera direstock</span>
                    </div>

                    <a href="stoks/" class="dashboard-link">Lihat Semua</a>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Produk</th>
                                <th>Stok</th>
                                <th>Satuan</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($stokMenipis)): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center;">Tidak ada stok menipis.</td>
                                </tr>

                            <?php else: ?>
                                <?php foreach ($stokMenipis as $index => $stok): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($stok['product_name']) ?></td>
                                        
                                        <td> <?php $kelasStok = ($stok['stock'] <= ($stok['minimum_stock'] / 2))? 'stock-danger' : 'stock-warning';?>
                                            <span class="<?= $kelasStok ?>">
                                                <?= number_format($stok['stock'],0,',','.') ?>
                                            </span>
                                        </td>

                                        <td><?= htmlspecialchars($stok['unit']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =================================================
             FOOTER
        ================================================== -->
        <footer>
            <span>© <?= date('Y') ?> <?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?>. All rights reserved.</span>
            <span><?= htmlspecialchars($companyTagline, ENT_QUOTES, 'UTF-8') ?></span>
        </footer>
    </div>
</main>
</body>

<script>
/* =====================================================
   DATA GRAFIK DARI PHP
===================================================== */
const salesData = <?= json_encode(
    $grafikPenjualan,
    JSON_UNESCAPED_UNICODE
) ?>;


/* =====================================================
   INISIALISASI GRAFIK
===================================================== */
document.addEventListener(
    'DOMContentLoaded',
    function () {
        const canvas =
            document.getElementById('salesChart');

        if (!canvas) {
            return;
        }

        const ctx =canvas.getContext('2d');

        /* ---------------------------------------------
           LABEL
        --------------------------------------------- */
        const labels = salesData.map(item => item.label);

        /* ---------------------------------------------
           DATA PENJUALAN
        --------------------------------------------- */
        const penjualan = salesData.map(item => item.penjualan);

        /* ---------------------------------------------
           DATA ORDER
        --------------------------------------------- */
        const order =salesData.map(item => item.order);

        /* ---------------------------------------------
           BUAT CHART
        --------------------------------------------- */
        new Chart(ctx, {
                data: {
                    labels: labels,
                    datasets: [

                        /* ==========================
                           PENJUALAN
                        ========================== */
                        {
                            type: 'bar',
                            label: 'Penjualan',
                            data: penjualan,
                            backgroundColor: '#2377df',
                            borderRadius: 6,
                            barThickness: 35,
                            yAxisID: 'y'
                        },

                        /* ==========================
                           ORDER
                        ========================== */

                        {
                            type: 'line',
                            label: 'Order',
                            data: order,
                            borderColor: '#ef3b4f',
                            backgroundColor: '#ef3b4f',
                            borderWidth: 3,
                            tension: 0.35,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            yAxisID: 'y1'
                        }
                    ]
                },


                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    }, 
                    
                    plugins: {
                        legend: {
                            display: false
                        },

                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    /* PENJUALAN */
                                    if (context.dataset.label == 'Penjualan') {
                                        return ('Penjualan: Rp ' + new Intl.NumberFormat('id-ID').format(context.raw));
                                    }
                                    
                                    /* ORDER */
                                    return ('Order: ' +context.raw);
                                }
                            }
                        }
                    },

                    scales: {
                        /* ======================
                           X AXIS
                        ====================== */
                        x: {
                            grid: {
                                display: false
                            }
                        },


                        /* ======================
                           Y PENJUALAN
                        ====================== */
                        y: {
                            beginAtZero: true,
                            position: 'left',
                            ticks: {
                                callback: function (value) {return ( 'Rp ' + new Intl .NumberFormat('id-ID', { notation: 'compact'}).format(value));}
                            }
                        },


                        /* ======================
                           Y ORDER
                        ====================== */
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            grid: {
                                drawOnChartArea: false
                            },
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            }
        );
    }
);

function updateClock() {
    const now = new Date();

    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');

    document.getElementById('currentTime').textContent =
        `${hours}.${minutes} WIB`;
}

updateClock();
setInterval(updateClock, 1000);


// SEARCH

document.addEventListener('DOMContentLoaded', function () {

    const searchInput =
        document.getElementById('globalSearch');

    const searchResults =
        document.getElementById('globalSearchResults');

    if (!searchInput || !searchResults) {
        return;
    }


    let searchTimer = null;


    /* =====================================================
       ESCAPE HTML
    ===================================================== */

    function escapeHtml(text) {

        const div = document.createElement('div');

        div.textContent = text ?? '';

        return div.innerHTML;
    }


    /* =====================================================
       TAMPILKAN LOADING
    ===================================================== */

    function showLoading() {

        searchResults.innerHTML = `
            <div class="search-loading">
                <i class="bi bi-arrow-repeat"></i>
                Mencari data...
            </div>
        `;

        searchResults.classList.add('show');
    }


    /* =====================================================
       TAMPILKAN HASIL
    ===================================================== */

    function showResults(data) {

        if (!data || data.length === 0) {

            searchResults.innerHTML = `
                <div class="search-empty">

                    <i class="bi bi-search"></i>

                    <span>
                        Data tidak ditemukan
                    </span>

                </div>
            `;

            searchResults.classList.add('show');

            return;
        }


        let html = `
            <div class="search-result-header">
                Hasil Pencarian
            </div>
        `;


        data.forEach(function (item) {

            html += `
                <a
                    href="${escapeHtml(item.url)}"
                    class="search-result-item">

                    <div class="search-result-icon">
                        <i class="bi ${escapeHtml(item.icon)}"></i>
                    </div>

                    <div class="search-result-content">

                        <span class="search-result-title">
                            ${escapeHtml(item.title)}
                        </span>

                        <span class="search-result-text">
                            ${escapeHtml(item.text)}
                        </span>

                    </div>

                    <span class="search-result-type">
                        ${escapeHtml(item.label)}
                    </span>

                </a>
            `;
        });


        searchResults.innerHTML = html;

        searchResults.classList.add('show');
    }


    /* =====================================================
       SEARCH DATABASE
    ===================================================== */

    function searchDatabase(keyword) {

        showLoading();


        fetch(
            'ajax/global-search.php?q=' +
            encodeURIComponent(keyword),
            {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            }
        )

        .then(function (response) {

            if (!response.ok) {
                throw new Error(
                    'Gagal mengambil data'
                );
            }

            return response.json();

        })

        .then(function (result) {

            if (!result.success) {
                throw new Error(
                    result.message || 'Search error'
                );
            }

            showResults(result.data);

        })

        .catch(function (error) {

            console.error(error);

            searchResults.innerHTML = `
                <div class="search-empty">

                    <i class="bi bi-exclamation-circle"></i>

                    <span>
                        Terjadi kesalahan saat mencari data
                    </span>

                </div>
            `;

            searchResults.classList.add('show');

        });
    }


    /* =====================================================
       INPUT SEARCH
    ===================================================== */

    searchInput.addEventListener('input', function () {

        const keyword =
            searchInput.value.trim();


        clearTimeout(searchTimer);


        if (keyword.length < 2) {

            searchResults.innerHTML = '';

            searchResults.classList.remove('show');

            return;
        }


        searchTimer = setTimeout(function () {

            searchDatabase(keyword);

        }, 300);

    });


    /* =====================================================
       TUTUP SEARCH
    ===================================================== */

    document.addEventListener('click', function (event) {

        if (
            !searchResults.contains(event.target) &&
            !searchInput.contains(event.target)
        ) {

            searchResults.classList.remove('show');

        }

    });


    /* =====================================================
       ESC UNTUK MENUTUP
    ===================================================== */

    searchInput.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {

            searchResults.classList.remove('show');

            searchInput.value = '';

        }

    });

});
</script>
</html>
