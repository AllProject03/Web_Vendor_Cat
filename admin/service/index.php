<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/config.php';

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
        // Jangan hentikan halaman Service jika tabel/settings bermasalah.
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

function money_id($value): string {
    return 'Rp' . number_format((float)$value, 0, ',', '.');
}

function parse_money($value): float {
    $value = preg_replace('/[^0-9]/', '', (string)$value);
    return $value === '' ? 0 : (float)$value;
}

function redirect_service(array $params = []): never {
    $url = 'index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

/* =========================================================
   DATABASE: services
   Struktur yang dipakai index.php:
   id, name, service_type, description, price, status
   ========================================================= */

/* AJAX DETAIL */
if (($_GET['action'] ?? '') === 'get_service') {
    header('Content-Type: application/json; charset=utf-8');

    $serviceId = (int)($_GET['id'] ?? 0);

    try {
        if ($serviceId <= 0) {
            throw new Exception('ID service tidak valid.');
        }

        $stmt = $pdo->prepare(
            "SELECT id, name, service_type, description, price, status
             FROM services
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $serviceId]);

        $service = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$service) {
            throw new Exception('Data service tidak ditemukan.');
        }

        $stmtTx = $pdo->prepare(
            "SELECT COUNT(*) FROM order_services WHERE service_id = :id"
        );
        $stmtTx->execute([':id' => $serviceId]);

        $transactionCount = (int)$stmtTx->fetchColumn();

        $service['transaction_count'] = $transactionCount;
        $service['has_transaction'] = $transactionCount > 0;

        echo json_encode([
            'success' => true,
            'data' => $service
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

/* =========================================================
   CRUD
   ========================================================= */
$notify = '';
$notifyType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $serviceId = (int)($_POST['service_id'] ?? 0);

    try {
        if ($action === 'add_service') {
            $name = trim($_POST['name'] ?? '');
            $serviceType = $_POST['service_type'] ?? '';
            $description = trim($_POST['description'] ?? '');
            $price = parse_money($_POST['price'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if ($name === '') {
                throw new Exception('Nama service wajib diisi.');
            }

            if (!in_array($serviceType, ['Service', 'Painting'], true)) {
                throw new Exception('Jenis service tidak valid.');
            }

            if ($price < 0) {
                throw new Exception('Harga service tidak boleh negatif.');
            }

            if (!in_array($status, ['active', 'inactive'], true)) {
                throw new Exception('Status service tidak valid.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO services
                    (name, service_type, description, price, status)
                 VALUES
                    (:name, :service_type, :description, :price, :status)"
            );

            $stmt->execute([
                ':name' => $name,
                ':service_type' => $serviceType,
                ':description' => $description,
                ':price' => $price,
                ':status' => $status
            ]);

            redirect_service(['success' => 'added']);
        }

        if ($action === 'update_service') {
            if ($serviceId <= 0) {
                throw new Exception('ID service tidak valid.');
            }

            $status = $_POST['status'] ?? 'active';

            if (!in_array($status, ['active', 'inactive'], true)) {
                throw new Exception('Status service tidak valid.');
            }

            $stmtTx = $pdo->prepare(
                "SELECT COUNT(*) FROM order_services WHERE service_id = :id"
            );
            $stmtTx->execute([':id' => $serviceId]);

            $hasTransaction = (int)$stmtTx->fetchColumn() > 0;

            /* Service yang sudah pernah dipakai transaksi:
               hanya status yang boleh diubah. */
            if ($hasTransaction) {
                $stmt = $pdo->prepare(
                    "UPDATE services
                     SET status = :status
                     WHERE id = :id"
                );

                $stmt->execute([
                    ':status' => $status,
                    ':id' => $serviceId
                ]);

                redirect_service(['success' => 'status_updated']);
            }

            /* Service yang belum pernah dipakai transaksi:
               semua field yang ada di database dapat diubah. */
            $name = trim($_POST['name'] ?? '');
            $serviceType = $_POST['service_type'] ?? '';
            $description = trim($_POST['description'] ?? '');
            $price = parse_money($_POST['price'] ?? '');

            if ($name === '') {
                throw new Exception('Nama service wajib diisi.');
            }

            if (!in_array($serviceType, ['Service', 'Painting'], true)) {
                throw new Exception('Jenis service tidak valid.');
            }

            if ($price < 0) {
                throw new Exception('Harga service tidak boleh negatif.');
            }

            $stmt = $pdo->prepare(
                "UPDATE services
                 SET name = :name,
                     service_type = :service_type,
                     description = :description,
                     price = :price,
                     status = :status
                 WHERE id = :id"
            );

            $stmt->execute([
                ':name' => $name,
                ':service_type' => $serviceType,
                ':description' => $description,
                ':price' => $price,
                ':status' => $status,
                ':id' => $serviceId
            ]);

            redirect_service(['success' => 'updated']);
        }

        if ($action === 'delete_service') {
            if ($serviceId <= 0) {
                redirect_service(['error' => 'invalid']);
            }

            $stmtTx = $pdo->prepare(
                "SELECT COUNT(*) FROM order_services WHERE service_id = :id"
            );
            $stmtTx->execute([':id' => $serviceId]);

            if ((int)$stmtTx->fetchColumn() > 0) {
                redirect_service(['error' => 'used']);
            }

            $stmt = $pdo->prepare(
                "DELETE FROM services WHERE id = :id"
            );
            $stmt->execute([':id' => $serviceId]);

            redirect_service(['success' => 'deleted']);
        }

    } catch (PDOException $e) {
        $notify = 'Operasi database gagal. Periksa koneksi database atau relasi transaksi.';
        $notifyType = 'error';
    } catch (Exception $e) {
        $notify = $e->getMessage();
        $notifyType = 'error';
    }
}

/* =========================================================
   NOTIFIKASI
   ========================================================= */
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

if ($success === 'added') {
    $notify = 'Service berhasil ditambahkan.';
    $notifyType = 'success';
} elseif ($success === 'updated') {
    $notify = 'Service berhasil diperbarui.';
    $notifyType = 'success';
} elseif ($success === 'status_updated') {
    $notify = 'Status service berhasil diperbarui.';
    $notifyType = 'success';
} elseif ($success === 'deleted') {
    $notify = 'Service berhasil dihapus.';
    $notifyType = 'success';
}

if ($error === 'invalid') {
    $notify = 'ID service tidak valid.';
    $notifyType = 'error';
} elseif ($error === 'used') {
    $notify = 'Service tidak dapat dihapus karena sudah digunakan pada transaksi. Ubah status menjadi Tidak Aktif jika service tidak ingin digunakan lagi.';
    $notifyType = 'error';
}

/* =========================================================
   SEARCH + FILTER
   ========================================================= */
$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(s.name LIKE :search_name OR s.description LIKE :search_description)';
    $searchValue = '%' . $search . '%';

    $params[':search_name'] = $searchValue;
    $params[':search_description'] = $searchValue;
}

if ($typeFilter !== '') {
    $where[] = 's.service_type = :filter_type';
    $params[':filter_type'] = $typeFilter;
}

if ($statusFilter !== '') {
    $where[] = 's.status = :filter_status';
    $params[':filter_status'] = $statusFilter;
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

/* =========================================================
   STATISTIK
   ========================================================= */
$totalServices = (int)$pdo->query(
    "SELECT COUNT(*) FROM services"
)->fetchColumn();

$activeServices = (int)$pdo->query(
    "SELECT COUNT(*) FROM services WHERE status = 'active'"
)->fetchColumn();

$inactiveServices = (int)$pdo->query(
    "SELECT COUNT(*) FROM services WHERE status = 'inactive'"
)->fetchColumn();

$averagePrice = (float)$pdo->query(
    "SELECT COALESCE(AVG(price), 0)
     FROM services
     WHERE status = 'active'"
)->fetchColumn();

$popularService = '-';

try {
    $stmt = $pdo->query(
        "SELECT s.name, COUNT(os.id) AS total_used
         FROM order_services os
         INNER JOIN services s ON s.id = os.service_id
         INNER JOIN orders o ON o.id = os.order_id
         WHERE o.status = 'completed'
         GROUP BY s.id, s.name
         ORDER BY total_used DESC, s.name ASC
         LIMIT 1"
    );

    $popularRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($popularRow) {
        $popularService = $popularRow['name'];
    }
} catch (Throwable $e) {
    $popularService = '-';
}

/* =========================================================
   PAGINATION
   ========================================================= */
$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));

$stmtCount = $pdo->prepare(
    "SELECT COUNT(*) FROM services s" . $whereSql
);
$stmtCount->execute($params);

$filteredServices = (int)$stmtCount->fetchColumn();

$totalPages = max(1, (int)ceil($filteredServices / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$stmtList = $pdo->prepare(
    "SELECT
        s.id,
        s.name,
        s.service_type,
        s.description,
        s.price,
        s.status
     FROM services s
     {$whereSql}
     ORDER BY s.id DESC
     LIMIT :limit OFFSET :offset"
);

foreach ($params as $key => $value) {
    $stmtList->bindValue($key, $value);
}

$stmtList->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmtList->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtList->execute();

$services = $stmtList->fetchAll(PDO::FETCH_ASSOC);

$displayStart = $filteredServices > 0 ? $offset + 1 : 0;
$displayEnd = $filteredServices > 0
    ? min($offset + $perPage, $filteredServices)
    : 0;

$paginationQuery = [];

if ($search !== '') {
    $paginationQuery['search'] = $search;
}

if ($typeFilter !== '') {
    $paginationQuery['type'] = $typeFilter;
}

if ($statusFilter !== '') {
    $paginationQuery['status'] = $statusFilter;
}

function service_category_class(string $category): string {
    return match ($category) {
        'Painting' => 'painting',
        'Service' => 'repair',
        default => 'finishing'
    };
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c1a2d">
    <title>Service | <?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../../assets/css/admin.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>


<aside class="sidebar" id="sidebar">
    <!-- =====================================================
         BRAND
    ====================================================== -->
    <div class="brand">
        <img src="<?= htmlspecialchars($companyLogo, ENT_QUOTES, 'UTF-8') ?>"
             class="img-fluid"
             alt="<?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?>">

        <div class="brand-text">
            <div class="brand-name">
               <?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?>
            </div>
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
            <a href="../admin.php" class="menu-item">
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
            <a href="../customers/" class="menu-item ">
                <i class="bi bi-people"></i>
                <span>Pelanggan</span>
            </a>

            <!-- KENDARAAN -->
            <a href="../vehicles/" class="menu-item">
                <i class="bi bi-car-front"></i>
                <span>Kendaraan</span>
            </a>

            <!-- PRODUK -->
            <a href="../product/" class="menu-item " >
                <i class="bi bi-box-seam"></i>
                <span>Produk</span>
            </a>

            <!-- JASA -->
            <a href="./" class="menu-item active">
                <i class="bi bi-tools"></i>
                <span>Jasa</span>
            </a>

            <!-- SUPPLIER -->
            <a href="../supplier/" class="menu-item">
                <i class="bi bi-truck"></i>
                <span>Supplier</span>
            </a>

            <!-- CABANG -->
            <a href="../cabang/" class="menu-item">
                <i class="bi bi-shop"></i>
                <span>Cabang</span>
            </a>

            <!-- PENGGUNA -->
            <a href="../users/" class="menu-item">
                <i class="bi bi-person-badge"></i>
                <span>Pengguna</span>
            </a>

            <!-- ROLE & HAK AKSES -->
            <a href="../roles/" class="menu-item">
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
            <a href="../orders/" class="menu-item">
                <i class="bi bi-cart3"></i>
                <span>Penjualan</span>
            </a>


            <!-- PEMBELIAN -->
            <a href="../purchases/" class="menu-item">
                <i class="bi bi-bag"></i>
                <span>Pembelian</span>
            </a>

            <!-- TRANSFER STOK -->
            <a href="../stoks-transfer/" class="menu-item">
                <i class="bi bi-arrow-left-right"></i>
                <span>Transfer Stok</span>
            </a>

            <!-- PEMBAYARAN -->
            <a href="../payments/" class="menu-item">
                <i class="bi bi-credit-card"></i>
                <span>Pembayaran</span>
            </a>

            <!-- PENGELUARAN -->
            <a href="../expenses/" class="menu-item">
                <i class="bi bi-receipt"></i>
                <span>Pengeluaran</span>
            </a>

            <!-- STOK -->
            <a href="../stoks/" class="menu-item">
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
            <a href="../reports/" class="menu-item">
                <i class="bi bi-bar-chart-line"></i>
                <span>Laporan</span>
            </a>

            <!-- REKAP CABANG -->
            <a href="../reports/" class="menu-item">
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
            <a href="../settings/" class="menu-item">
                <i class="bi bi-gear"></i>
                <span>Pengaturan</span>
            </a>

            <a href="../logout.php" class="menu-item">
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


<main class="main">


<div class="page-container">

    <!-- ================= HEADER ================= -->

    <div class="page-header">

        <div>

           <div class="breadcrumb">
                Dashboard
                <i class="bi bi-chevron-right"></i>
                Master Data
                <i class="bi bi-chevron-right"></i>
                Jasa
            </div>

            <h1>Service</h1>

            <p>
                Kelola jenis layanan pengecatan dan perbaikan kendaraan.
            </p>

        </div>


        <button class="btn-primary" onclick="openModal()">

            <i class="bi bi-plus-lg"></i>

            Tambah Service

        </button>

    </div>


    <!-- ================= STATISTICS ================= -->

    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-icon blue">
                <i class="bi bi-tools"></i>
            </div>
            <div class="stat-content">
                <span>Total Service</span>
                <strong><?= $totalServices ?></strong>
                <small>Jenis layanan</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon green">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="stat-content">
                <span>Service Aktif</span>
                <strong><?= $activeServices ?></strong>
                <small>Layanan tersedia</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon orange">
                <i class="bi bi-car-front-fill"></i>
            </div>
            <div class="stat-content">
                <span>Paling Banyak Dipilih</span>
                <strong title="<?= h($popularService) ?>">
                    <?= h($popularService) ?>
                </strong>
                <small>Service populer</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon purple">
                <i class="bi bi-cash-stack"></i>
            </div>
            <div class="stat-content">
                <span>Rata-rata Harga</span>
                <strong><?= money_id($averagePrice) ?></strong>
                <small>Per layanan</small>
            </div>
        </div>

    </div>


    <!-- ================= CONTENT ================= -->

    <div class="content-card">

        <div class="card-header">
            <div>
                <h2>Daftar Service</h2>
                <p>
                    Daftar layanan yang dapat dipilih pada transaksi.
                </p>
            </div>
        </div>


        <!-- ================= FILTER ================= -->

        <form method="get" class="filter-section" id="serviceFilterForm">

            <div class="searc-box"
            style="position:relative;width:100%;max-width:100%;height:40px;margin:0;box-sizing:border-box;border:1px solid #d9e1ec;border-radius:10px;background:#fff;overflow:hidden;"
            >
                <i
                    class="bi bi-search"
                    style="position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:18px;color:#9aa7b8;z-index:2;pointer-events:none;"
                ></i>
                <input type="text" id="searchInput" name="search" placeholder="Cari nama atau deskripsi service..." value="<?= h($search) ?>"autocomplete="off"
                style="display:block;width:100%;height:40px;margin:0;padding:0 14px 0 42px;border:0;outline:none;background:transparent;box-shadow:none;border-radius:10px;font-size:14px;color:#334155;box-sizing:border-box;">
            </div>

            <select
                id="categoryFilter"
                name="type"
                onchange="this.form.submit()"
            >
                <option value="">
                    Semua Jenis
                </option>

                <option value="Service" <?= $typeFilter === 'Service' ? 'selected' : '' ?>>
                    Service
                </option>

                <option value="Painting" <?= $typeFilter === 'Painting' ? 'selected' : '' ?>>
                    Painting
                </option>
            </select>

            <select
                id="statusFilter"
                name="status"
                onchange="this.form.submit()"
            >
                <option value="">
                    Semua Status
                </option>

                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                    Aktif
                </option>

                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                    Tidak Aktif
                </option>
            </select>

            <?php
            $hasActiveFilter =
                $search !== '' ||
                $typeFilter !== '' ||
                $statusFilter !== '';
            ?>

            <a
                href="index.php"
                class="btn-secondary"
                style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;<?= $hasActiveFilter ? '' : 'pointer-events:none;opacity:.55;cursor:not-allowed;' ?>"
                <?= !$hasActiveFilter ? 'aria-disabled="true" tabindex="-1" title="Reset aktif setelah pencarian atau filter digunakan."' : '' ?>
            >
                <i class="bi bi-arrow-counterclockwise" style="margin-right:6px;"></i>
                Reset
            </a>

        </form>


        <!-- ================= TABLE ================= -->

        <div class="table-wrapper">

            <table id="serviceTable">

                <thead>
                    <tr>

                        <th>Nama Service</th>
                        <th>Tipe Service</th>
                        <th>Deskripsi</th>
                        <th>Harga</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$services): ?>

                    <tr>
                        <td colspan="6" style="text-align:center;padding:40px 20px;">
                            <i class="bi bi-inbox" style="font-size:32px;"></i>
                            <div style="margin-top:8px;font-weight:600;">
                                Data service tidak ditemukan.
                            </div>
                            <small>
                                Coba ubah pencarian atau filter.
                            </small>
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($services as $service): ?>

                        <?php
                        $category = (string)$service['service_type'];
                        $categoryClass = service_category_class($category);

                        $description = trim((string)($service['description'] ?? ''));
                        if ($description === '') {
                            $description = 'Layanan service kendaraan';
                        }

                        $icon = $category === 'Painting'
                            ? 'bi-paint-bucket'
                            : 'bi-tools';

                        $statusClass = $service['status'] === 'active'
                            ? 'active'
                            : 'inactive';

                        $statusText = $service['status'] === 'active'
                            ? 'Aktif'
                            : 'Tidak Aktif';
                        ?>

                        <tr>

                            <td>
                                <div class="service-info">
                                    <div class="service-icon <?= h($categoryClass) ?>">
                                        <i class="bi <?= h($icon) ?>"></i>
                                    </div>

                                    <div>
                                        <strong>
                                            <?= h($service['name']) ?>
                                        </strong>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <span class="category-badge <?= h($categoryClass) ?>">
                                    <?= h($category) ?>
                                </span>
                            </td>

                            <td>
                                <small>
                                    <?= h($description) ?>
                                </small>
                            </td>

                            <td>
                                <strong>
                                    <?= money_id($service['price']) ?>
                                </strong>
                            </td>

                            <td>
                                <span class="status <?= h($statusClass) ?>">
                                    <?= h($statusText) ?>
                                </span>
                            </td>

                            <td>
                                <div class="action-buttons">

                                    <button
                                        type="button"
                                        class="btn-icon"
                                        title="Lihat"
                                        onclick="viewService(<?= (int)$service['id'] ?>)"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>

                                    <button
                                        type="button"
                                        class="btn-icon"
                                        title="Edit"
                                        onclick="editService(<?= (int)$service['id'] ?>)"
                                    >
                                        <i class="bi bi-pencil"></i>
                                    </button>

                                    <button
                                        type="button"
                                        class="btn-icon danger"
                                        title="Hapus"
                                        onclick="deleteService(<?= (int)$service['id'] ?>, '<?= h($service['name']) ?>')"
                                    >
                                        <i class="bi bi-trash"></i>
                                    </button>

                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


        <!-- ================= PAGINATION ================= -->

        <div class="pagination">

            <span>
                Menampilkan <?= $displayStart ?>–<?= $displayEnd ?>
                dari <?= $filteredServices ?> service
            </span>

            <div class="pagination-buttons">

                <?php
                $prevQuery = $paginationQuery;
                $prevQuery['page'] = max(1, $page - 1);
                ?>

                <a
                    href="?<?= h(http_build_query($prevQuery)) ?>"
                    class="pagination-link <?= $page <= 1 ? 'disabled' : '' ?>"
                    aria-label="Previous"
                    <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>
                >
                    <i class="bi bi-chevron-left"></i>
                </a>

                <?php
                $visiblePages = [];

                if ($totalPages <= 5) {
                    for ($i = 1; $i <= $totalPages; $i++) {
                        $visiblePages[] = $i;
                    }
                } else {
                    $visiblePages[] = 1;

                    $startPage = max(2, $page - 1);
                    $endPage = min($totalPages - 1, $page + 1);

                    if ($startPage > 2) {
                        $visiblePages[] = '...';
                    }

                    for ($i = $startPage; $i <= $endPage; $i++) {
                        $visiblePages[] = $i;
                    }

                    if ($endPage < $totalPages - 1) {
                        $visiblePages[] = '...';
                    }

                    $visiblePages[] = $totalPages;
                }
                ?>

                <?php foreach ($visiblePages as $visiblePage): ?>

                    <?php if ($visiblePage === '...'): ?>

                        <span class="pagination-ellipsis">...</span>

                    <?php else: ?>

                        <?php
                        $pageQuery = $paginationQuery;
                        $pageQuery['page'] = $visiblePage;
                        ?>

                        <a
                            href="?<?= h(http_build_query($pageQuery)) ?>"
                            class="<?= $visiblePage === $page ? 'active-page' : '' ?>"
                        >
                            <?= $visiblePage ?>
                        </a>

                    <?php endif; ?>

                <?php endforeach; ?>

                <?php
                $nextQuery = $paginationQuery;
                $nextQuery['page'] = min($totalPages, $page + 1);
                ?>

                <a
                    href="?<?= h(http_build_query($nextQuery)) ?>"
                    class="pagination-link <?= $page >= $totalPages ? 'disabled' : '' ?>"
                    aria-label="Next"
                    <?= $page >= $totalPages ? 'aria-disabled="true"' : '' ?>
                >
                    <i class="bi bi-chevron-right"></i>
                </a>

            </div>

        </div>

    </div>

</div>

</main>

<!-- ================= MODAL ================= -->

<div
    class="modal-overlay"
    id="serviceModal"
>
    <div class="modal">

        <div class="modal-header">
            <div>
                <h2 id="modalTitle">Tambah Service</h2>
                <p id="modalSubtitle">
                    Masukkan informasi layanan.
                </p>
            </div>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal()"
            >
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form id="serviceForm" method="post" onsubmit="saveService(event)">

            <input type="hidden" name="action" id="formAction" value="add_service">
            <input type="hidden" name="service_id" id="serviceId" value="">

            <div class="form-grid">

                <div class="form-group">
                    <label>
                        Nama Service <span>*</span>
                    </label>

                    <input
                        type="text"
                        name="name"
                        id="serviceName"
                        placeholder="Contoh: Full Body Painting"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>
                        Jenis Service <span>*</span>
                    </label>

                    <select
                        name="service_type"
                        id="serviceType"
                        required
                    >
                        <option value="Service">Service</option>
                        <option value="Painting">Painting</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>
                        Harga Service <span>*</span>
                    </label>

                    <div class="input-money">
                        <span>Rp</span>

                        <input
                            type="text"
                            name="price"
                            id="servicePrice"
                            inputmode="numeric"
                            autocomplete="off"
                            placeholder="0"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label>
                        Status
                    </label>

                    <select name="status" id="serviceStatus">
                        <option value="active">Aktif</option>
                        <option value="inactive">Tidak Aktif</option>
                    </select>
                </div>

                <div class="form-group full">
                    <label>
                        Deskripsi
                    </label>

                    <textarea
                        rows="4"
                        name="description"
                        id="serviceDescription"
                        placeholder="Deskripsi layanan..."
                    ></textarea>
                </div>

            </div>

            <div
                id="transactionNote"
                style="display:none; margin-top:14px; padding:12px 14px; border-radius:10px; background:#fff7ed; color:#9a3412; font-size:13px;"
            >
                Service ini sudah digunakan pada transaksi. Saat diedit, hanya status yang dapat diubah.
            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn-secondary"
                    onclick="closeModal()"
                >
                    Batal
                </button>

                <button
                    type="submit"
                    class="btn-primary"
                    id="saveButton"
                >
                    <i class="bi bi-check-lg"></i>
                    <span id="saveButtonText">Simpan Service</span>
                </button>

            </div>

        </form>

    </div>
</div>

<?php if ($notify !== ''): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const message = <?= json_encode($notify, JSON_UNESCAPED_UNICODE) ?>;
    if (message) {
        alert(message);
    }
});
</script>
<?php endif; ?>

<style>
.pagination-buttons a,
.pagination-buttons .pagination-ellipsis {
    min-width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

.pagination-buttons a {
    color: inherit;
}

.pagination-buttons .disabled {
    pointer-events: none;
    opacity: .45;
}

.pagination-buttons .pagination-ellipsis {
    padding: 0 6px;
}

#servicePrice {
    text-align: left;
}
</style>

<script>
function formatPrice(value) {
    const digits = String(value ?? '').replace(/\D/g, '');
    if (!digits) return '';
    return Number(digits).toLocaleString('id-ID');
}

function cleanPrice(value) {
    return String(value ?? '').replace(/\D/g, '');
}

function setEditMode(used) {
    const fields = [
        'serviceName',
        'serviceType',
        'servicePrice',
        'serviceDescription'
    ];

    fields.forEach(function (id) {
        const field = document.getElementById(id);
        if (!field) return;

        if (used) {
            field.setAttribute('readonly', 'readonly');

            if (
                field.tagName === 'SELECT' ||
                field.tagName === 'TEXTAREA'
            ) {
                field.setAttribute('disabled', 'disabled');
            }
        } else {
            field.removeAttribute('readonly');
            field.removeAttribute('disabled');
        }
    });

    const status = document.getElementById('serviceStatus');
    if (status) {
        status.removeAttribute('disabled');
    }
}

function resetForm() {
    const form = document.getElementById('serviceForm');
    form.reset();

    document.getElementById('formAction').value = 'add_service';
    document.getElementById('serviceId').value = '';
    document.getElementById('modalTitle').textContent = 'Tambah Service';
    document.getElementById('modalSubtitle').textContent =
        'Masukkan informasi layanan.';
    document.getElementById('saveButtonText').textContent =
        'Simpan Service';

    document.getElementById('servicePrice').value = '';
    document.getElementById('serviceType').value = 'Service';
    document.getElementById('serviceStatus').value = 'active';

    const note = document.getElementById('transactionNote');
    if (note) {
        note.style.display = 'none';
    }

    setEditMode(false);
}

function openModal() {
    resetForm();
    document.getElementById('serviceModal').classList.add('show');
}

function closeModal() {
    document.getElementById('serviceModal').classList.remove('show');
}

window.addEventListener('click', function (event) {
    const modal = document.getElementById('serviceModal');

    if (event.target === modal) {
        closeModal();
    }
});

const priceField = document.getElementById('servicePrice');

if (priceField) {
    priceField.addEventListener('input', function () {
        this.value = formatPrice(this.value);
    });
}

async function fetchService(id) {
    const response = await fetch(
        'index.php?action=get_service&id=' +
        encodeURIComponent(id),
        {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }
    );

    if (!response.ok) {
        throw new Error('Gagal mengakses server.');
    }

    const result = await response.json();

    if (!result.success) {
        throw new Error(
            result.message || 'Data service tidak ditemukan.'
        );
    }

    return result.data;
}

async function viewService(id) {
    try {
        const data = await fetchService(id);

        const category = data.service_type || '-';
        const description = data.description || '-';
        const status = data.status === 'active'
            ? 'Aktif'
            : 'Tidak Aktif';

        alert(
            'DETAIL SERVICE\n\n' +
            'Nama       : ' + (data.name || '-') + '\n' +
            'Jenis      : ' + category + '\n' +
            'Harga      : Rp' +
                Number(data.price || 0).toLocaleString('id-ID') + '\n' +
            'Status     : ' + status + '\n' +
            'Deskripsi  : ' + description
        );

    } catch (error) {
        alert(error.message);
    }
}

async function editService(id) {
    try {
        const data = await fetchService(id);
        const used = !!data.has_transaction;

        document.getElementById('formAction').value =
            'update_service';

        document.getElementById('serviceId').value = data.id;

        document.getElementById('modalTitle').textContent =
            'Edit Service';

        document.getElementById('modalSubtitle').textContent =
            used
                ? 'Service sudah digunakan pada transaksi.'
                : 'Perbarui informasi service.';

        document.getElementById('saveButtonText').textContent =
            used
                ? 'Simpan Status'
                : 'Simpan Perubahan';

        document.getElementById('serviceName').value =
            data.name || '';

        document.getElementById('serviceType').value =
            data.service_type || 'Service';

        document.getElementById('servicePrice').value =
            formatPrice(data.price || 0);

        document.getElementById('serviceDescription').value =
            data.description || '';

        document.getElementById('serviceStatus').value =
            data.status || 'active';

        const note = document.getElementById('transactionNote');

        if (note) {
            note.style.display = used ? 'block' : 'none';
        }

        setEditMode(used);

        document.getElementById('serviceModal').classList.add('show');

    } catch (error) {
        alert(error.message);
    }
}

function saveService(event) {
    event.preventDefault();

    const priceField = document.getElementById('servicePrice');

    if (priceField) {
        priceField.value = cleanPrice(priceField.value);
    }

    const form = document.getElementById('serviceForm');
    const formData = new FormData(form);

    fetch('index.php', {
        method: 'POST',
        body: formData
    })
    .then(function (response) {
        return response.text();
    })
    .then(function () {
        closeModal();
        window.location.href = 'index.php';
    })
    .catch(function (error) {
        alert('Gagal menyimpan data: ' + error.message);
    });
}

function deleteService(id, name) {
    const confirmation = confirm(
        'Apakah Anda yakin ingin menghapus service "' +
        name +
        '"?'
    );

    if (!confirmation) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete_service');
    formData.append('service_id', id);

    fetch('index.php', {
        method: 'POST',
        body: formData
    })
    .then(function (response) {
        return response.text();
    })
    .then(function () {
        window.location.href = 'index.php';
    })
    .catch(function (error) {
        alert('Gagal menghapus data: ' + error.message);
    });
}
</script>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById("sidebar");
    if (sidebar) {
        sidebar.classList.toggle("show");
    }
}
</script>

</body>
</html>
