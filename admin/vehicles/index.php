<?php
/* =====================================================
   STATUS LOGIN
===================================================== */
require_once __DIR__ . '/../../config/auth.php';

/* =====================================================
   KONEKSI DATABASE
===================================================== */
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
        // Jangan hentikan halaman Kendaraan jika tabel/settings bermasalah.
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


/* =====================================================
   AMBIL DATA PELANGGAN
===================================================== */
$stmtCustomer = $pdo->prepare("SELECT c.id, c.name, c.cabang_id, cb.name AS cabang_name 
        FROM customers c 
        LEFT JOIN cabangs cb 
            ON cb.id = c.cabang_id
        WHERE c.status = 'active' ORDER BY c.name ASC");

$stmtCustomer->execute();
$customers = $stmtCustomer->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   GET DATA KENDARAAN UNTUK EDIT MODAL
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_vehicle') {
    header('Content-Type: application/json; charset=utf-8');

    $vehicleId = (int) ($_GET['id'] ?? 0);

    if ($vehicleId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'ID kendaraan tidak valid.'
        ]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT
                v.id,
                v.customer_id,
                v.cabang_id,
                v.plate_number,
                v.brand,
                v.model,
                v.year,
                v.color,
                v.vin_number,
                v.note,
                v.status,
                (
                    SELECT COUNT(*)
                    FROM orders o
                    WHERE o.vehicle_id = v.id
                ) AS total_orders
            FROM vehicles v
            WHERE v.id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $vehicleId
        ]);

        $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$vehicle) {
            echo json_encode([
                'success' => false,
                'message' => 'Data kendaraan tidak ditemukan.'
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'data' => $vehicle
        ]);
        exit;

    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Gagal mengambil data kendaraan.'
        ]);
        exit;
    }
}


/* =====================================================
   TAMBAH KENDARAAN
===================================================== */

$message = '';
$messageType = '';
$showVehicleModal = false;

$formData = [
    'customer_id'  => 0,
    'plate_number' => '',
    'brand'        => '',
    'model'        => '',
    'year'         => '',
    'color'        => '',
    'vin_number'   => '',
    'note'         => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_vehicle') {

    $customerId  = (int) ($_POST['customer_id'] ?? 0);
    $plateNumber = trim($_POST['plate_number'] ?? '');
    $brand       = trim($_POST['brand'] ?? '');
    $model       = trim($_POST['model'] ?? '');
    $tahun       = trim($_POST['year'] ?? '');
    $warna       = trim($_POST['color'] ?? '');
    $vin         = trim($_POST['vin_number'] ?? '');
    $catatan     = trim($_POST['note'] ?? '');

    $formData = [
    'customer_id'  => $customerId,
    'plate_number' => $plateNumber,
    'brand'        => $brand,
    'model'        => $model,
    'year'         => $tahun,
    'color'        => $warna,
    'vin_number'   => $vin,
    'note'         => $catatan
    ];

    $showVehicleModal = true;

    try {
        /* =================================================
           VALIDASI DASAR
        ================================================= */
        if ($customerId <= 0) {
            throw new Exception('Pelanggan wajib dipilih.');
        }

        if ($plateNumber === '') {
            throw new Exception('Plat nomor wajib diisi.');
        }

        if ($brand === '') {
            throw new Exception('Merk kendaraan wajib diisi.');
        }

        if ($model === '') {
            throw new Exception('Model / tipe kendaraan wajib diisi.');
        }

        if ($warna === '') {
            throw new Exception('Warna kendaraan wajib diisi.');
        }


        /* =================================================
           AMBIL PELANGGAN + CABANG
        ================================================= */

        $stmtCustomer = $pdo->prepare("SELECT
                c.id,
                c.cabang_id,
                cb.name AS cabang_name
            FROM customers c
            INNER JOIN cabangs cb
                ON cb.id = c.cabang_id
            WHERE c.id = :customer_id
              AND c.status = 'active'
              AND cb.status = 'active'
            LIMIT 1
        ");

        $stmtCustomer->execute([':customer_id' => $customerId]);
        $customer = $stmtCustomer->fetch(PDO::FETCH_ASSOC);


        if (!$customer) {
            throw new Exception(
                'Pelanggan tidak ditemukan atau tidak aktif.'
            );

        }


        /* =================================================
           CEK NOMOR POLISI
        ================================================= */
        $stmtPlate = $pdo->prepare("SELECT id
            FROM vehicles
            WHERE plate_number = :plate_number
            LIMIT 1
        ");

        $stmtPlate->execute([':plate_number' => $plateNumber]);

        if ($stmtPlate->fetch()) {
            throw new Exception(
                'Nomor polisi tersebut sudah terdaftar.'
            );
        }


        /* =================================================
           SIMPAN KENDARAAN
        ================================================= */
        $stmtVehicle = $pdo->prepare("INSERT INTO vehicles (
                customer_id,
                cabang_id,
                plate_number,
                brand,
                model,
                year,
                color,
                vin_number,
                note,
                status
            ) VALUES (
                :customer_id,
                :cabang_id,
                :plate_number,
                :brand,
                :model,
                :year,
                :color,
                :vin_number,
                :note,
                'active'
            )
        ");

        $stmtVehicle->execute([
            ':customer_id'  => $customerId,
            ':cabang_id'    => $customer['cabang_id'],
            ':plate_number' => $plateNumber,
            ':brand'        => $brand,
            ':model'        => $model,
            ':year'         => $tahun !== '' ? $tahun : null,
            ':color'        => $warna,
            ':vin_number'   => $vin !== '' ? $vin : null,
            ':note'         => $catatan !== '' ? $catatan : null
        ]);


        /* =================================================
           REDIRECT
        ================================================= */

        header('Location: index.php?success=vehicle_added');
        exit;


    } catch (PDOException $e) {

        $message = 'Gagal menyimpan kendaraan ke database.';
        $messageType = 'error';
        $showVehicleModal = true;

    } catch (Exception $e) {

        $message = $e->getMessage();
        $messageType = 'error';
        $showVehicleModal = true;

    }
}

/* =====================================================
   UPDATE KENDARAAN
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_vehicle') {

    $vehicleId   = (int) ($_POST['vehicle_id'] ?? 0);
    $customerId  = (int) ($_POST['customer_id'] ?? 0);
    $plateNumber = trim($_POST['plate_number'] ?? '');
    $brand       = trim($_POST['brand'] ?? '');
    $model       = trim($_POST['model'] ?? '');
    $tahun       = trim($_POST['year'] ?? '');
    $warna       = trim($_POST['color'] ?? '');
    $vin         = trim($_POST['vin_number'] ?? '');
    $catatan     = trim($_POST['note'] ?? '');
    $status      = trim($_POST['status'] ?? 'active');

    try {

        /* ===============================
           VALIDASI
           Pada mode edit, hanya STATUS yang
           diperbolehkan untuk diubah.
        =============================== */

        if ($vehicleId <= 0) {
            throw new Exception('ID kendaraan tidak valid.');
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new Exception('Status kendaraan tidak valid.');
        }

        /* ===============================
           CEK KENDARAAN
        =============================== */

        $stmtVehicleCheck = $pdo->prepare("
            SELECT id
            FROM vehicles
            WHERE id = :vehicle_id
            LIMIT 1
        ");

        $stmtVehicleCheck->execute([
            ':vehicle_id' => $vehicleId
        ]);

        if (!$stmtVehicleCheck->fetch()) {
            throw new Exception('Data kendaraan tidak ditemukan.');
        }

        /* ===============================
           UPDATE DATABASE
           Hanya status yang diubah.
        =============================== */

        $stmtUpdate = $pdo->prepare("
            UPDATE vehicles
            SET status = :status
            WHERE id = :vehicle_id
        ");

        $stmtUpdate->execute([
            ':status'     => $status,
            ':vehicle_id' => $vehicleId
        ]);

        /* ===============================
           REDIRECT
        =============================== */

        header('Location: index.php?success=vehicle_updated');
        exit;

    } catch (PDOException $e) {

        $message = 'Gagal memperbarui kendaraan ke database.';
        $messageType = 'error';
        $showVehicleModal = true;

    } catch (Exception $e) {

        $message = $e->getMessage();
        $messageType = 'error';
        $showVehicleModal = true;

    }
}

/* =====================================================
   TOTAL KENDARAAN
===================================================== */
$stmtKendaraan = $pdo->query("SELECT COUNT(*) FROM vehicles");
$totalKendaraan = (int) $stmtKendaraan->fetchColumn();

/* =====================================================
   TOTAL KENDARAAN AKTIF
===================================================== */
$stmtActive = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status='active'");
$totalActive = (int) $stmtActive->fetchColumn();

/* =====================================================
   TOTAL SERRVICES
===================================================== */
$stmtServiceBulanIni = $pdo->query("
    SELECT COUNT(DISTINCT o.id)
    FROM orders o
    INNER JOIN order_services os ON os.order_id = o.id
    INNER JOIN services s ON s.id = os.service_id
    WHERE o.status = 'completed'
      AND s.service_type = 'Service'
      AND MONTH(o.order_date) = MONTH(CURRENT_DATE())
      AND YEAR(o.order_date) = YEAR(CURRENT_DATE())
");

$totalServiceBulanIni = (int) $stmtServiceBulanIni->fetchColumn();

/* =====================================================
   TOTAL PAINTING
===================================================== */
$stmtPaintingBulanIni = $pdo->query("
    SELECT COUNT(DISTINCT o.id)
    FROM orders o
    INNER JOIN order_services os ON os.order_id = o.id
    INNER JOIN services s ON s.id = os.service_id
    WHERE o.status = 'completed'
      AND s.service_type = 'Painting'
      AND MONTH(o.order_date) = MONTH(CURRENT_DATE())
      AND YEAR(o.order_date) = YEAR(CURRENT_DATE())
");

$totalPaintingBulanIni = (int) $stmtPaintingBulanIni->fetchColumn();

/* =====================================================
   SEARCH + FILTER
===================================================== */
$search = trim($_GET['search'] ?? '');
$branchFilter = trim($_GET['branch'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = [];
$params = [];

if ($search !== '') {

    $where[] = "
        (
            CONCAT('VEH-', LPAD(v.id, 4, '0')) LIKE :search_code
            OR c.name LIKE :search_customer
            OR v.plate_number LIKE :search_plate
            OR v.brand LIKE :search_brand
            OR v.model LIKE :search_model
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[':search_code']     = $searchValue;
    $params[':search_customer'] = $searchValue;
    $params[':search_plate']    = $searchValue;
    $params[':search_brand']    = $searchValue;
    $params[':search_model']    = $searchValue;
}

if ($branchFilter !== '') {

    $where[] = "v.cabang_id = :branch_id";

    $params[':branch_id'] = (int) $branchFilter;
}

if ($statusFilter !== '') {

    $where[] = "v.status = :status";

    $params[':status'] = $statusFilter;
}

$whereSql = '';

if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}


/* =====================================================
   PAGINATION
===================================================== */
$perPage = 5;

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;


/* =====================================================
   TOTAL DATA SESUAI SEARCH
===================================================== */
$stmtTotal = $pdo->prepare("SELECT COUNT(*)
    FROM vehicles v
    LEFT JOIN customers c
        ON c.id = v.customer_id
    LEFT JOIN cabangs cb
        ON cb.id = v.cabang_id
    $whereSql
");

$stmtTotal->execute($params);

$totalVehicles = (int) $stmtTotal->fetchColumn();


/* =====================================================
   TOTAL HALAMAN
===================================================== */
$totalPages = max(1,(int) ceil($totalVehicles / $perPage));

$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;


/* =====================================================
   DATA KENDARAAN
===================================================== */
$stmtVehicles = $pdo->prepare("SELECT
        v.id,
        v.plate_number,
        v.brand,
        v.model,
        v.year,
        v.color,
        v.status,
        c.name AS customer_name,
        cb.name AS cabang_name,
        (
            SELECT COUNT(*)
            FROM orders o
            WHERE o.vehicle_id = v.id
        ) AS total_orders
    FROM vehicles v
    LEFT JOIN customers c
        ON c.id = v.customer_id
    LEFT JOIN cabangs cb
        ON cb.id = v.cabang_id
    $whereSql
    ORDER BY v.id DESC
    LIMIT :limit OFFSET :offset
");


/* Parameter pencarian */
foreach ($params as $key => $value) {
    $stmtVehicles->bindValue(
        $key,
        $value,
        PDO::PARAM_STR
    );
}


/* Parameter pagination */
$stmtVehicles->bindValue(
    ':limit',
    $perPage,
    PDO::PARAM_INT
);

$stmtVehicles->bindValue(
    ':offset',
    $offset,
    PDO::PARAM_INT
);


$stmtVehicles->execute();

$vehicles = $stmtVehicles->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   DATA CABANG
===================================================== */
$stmtCabang = $pdo->prepare("
    SELECT id, name
    FROM cabangs
    WHERE status = 'active'
    ORDER BY name ASC
");

$stmtCabang->execute();

$cabangs = $stmtCabang->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   HAPUS KENDARAAN
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_vehicle') {

    $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);

    if ($vehicleId <= 0) {
        header("Location: index.php?error=invalid_vehicle");
        exit;
    }

    try {

        /* =================================================
           CEK KENDARAAN
        ================================================= */
        $stmtVehicle = $pdo->prepare("
            SELECT id, plate_number, brand, model
            FROM vehicles
            WHERE id = :id
            LIMIT 1
        ");

        $stmtVehicle->execute([
            ':id' => $vehicleId
        ]);

        $vehicle = $stmtVehicle->fetch(PDO::FETCH_ASSOC);

        if (!$vehicle) {

            header("Location: index.php?error=vehicle_not_found");
            exit;
        }


        /* =================================================
           CEK APAKAH SUDAH MEMILIKI ORDER
        ================================================= */
        $stmtOrder = $pdo->prepare("
            SELECT COUNT(*)
            FROM orders
            WHERE vehicle_id = :vehicle_id
        ");

        $stmtOrder->execute([
            ':vehicle_id' => $vehicleId
        ]);

        $totalOrders = (int) $stmtOrder->fetchColumn();


        /* =================================================
           JIKA SUDAH ADA ORDER → JANGAN HAPUS
        ================================================= */
        if ($totalOrders > 0) {

            header(
                "Location: index.php?error=vehicle_has_orders"
            );

            exit;
        }


        /* =================================================
           JIKA BELUM ADA ORDER → HAPUS
        ================================================= */
        $stmtDelete = $pdo->prepare("
            DELETE FROM vehicles
            WHERE id = :id
        ");

        $stmtDelete->execute([
            ':id' => $vehicleId
        ]);


        header(
            "Location: index.php?success=vehicle_deleted"
        );

        exit;


    } catch (PDOException $e) {

        header(
            "Location: index.php?error=vehicle_delete_failed"
        );

        exit;
    }
}

/* =====================================================
   PESAN HAPUS KENDARAAN
===================================================== */
$notification = '';
$notificationType = '';

if (isset($_GET['success'])) {

    switch ($_GET['success']) {

        case 'vehicle_added':
            $notification = 'Kendaraan berhasil ditambahkan.';
            $notificationType = 'success';
            break;

        case 'vehicle_updated':
            $notification = 'Kendaraan berhasil diperbarui.';
            $notificationType = 'success';
            break;

        case 'vehicle_deleted':
            $notification = 'Kendaraan berhasil dihapus.';
            $notificationType = 'success';
            break;
    }
}

if (isset($_GET['error'])) {

    switch ($_GET['error']) {

        case 'invalid_vehicle':
            $notification = 'ID kendaraan tidak valid.';
            $notificationType = 'error';
            break;

        case 'vehicle_not_found':
            $notification = 'Data kendaraan tidak ditemukan.';
            $notificationType = 'error';
            break;

        case 'vehicle_has_orders':
            $notification = 'Data kendaraan tidak dapat dihapus karena sudah memiliki transaksi/order.';
            $notificationType = 'error';
            break;

        case 'vehicle_delete_failed':
            $notification = 'Kendaraan gagal dihapus. Silakan coba kembali.';
            $notificationType = 'error';
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c1a2d">

    <title>Kendaraan | <?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <link rel="stylesheet" href="style.css">

    <style>
        .action-buttons .btn-icon:disabled {
            pointer-events: none;
        }

        #deleteConfirmModal {
            z-index: 10001;
        }
    </style>
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
            <a href="../customers" class="menu-item">
                <i class="bi bi-people"></i>
                <span>Pelanggan</span>
            </a>

            <!-- KENDARAAN -->
            <a href="./" class="menu-item active">
                <i class="bi bi-car-front"></i>
                <span>Kendaraan</span>
            </a>

            <!-- PRODUK -->
            <a href="../product/" class="menu-item">
                <i class="bi bi-box-seam"></i>
                <span>Produk</span>
            </a>

            <!-- JASA -->
            <a href="../service/" class="menu-item">
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
                    Kendaraan
                </div>

                <h1>Kendaraan</h1>
                <p>Kelola data kendaraan pelanggan yang melakukan pembelian atau layanan pengecatan.</p>
            </div>

            <button type="button" class="btn-primary" onclick="openModal()">
                <i class="bi bi-plus-lg"></i>
                Tambah Kendaraan
            </button>
        </div>

        <!-- ================= NOTIFICATION ================= -->
        <?php if ($notification !== ''): ?>
            <div class="form-alert <?= $notificationType === 'success' ? 'success' : 'error' ?>"
                 id="pageNotification"
                 style="margin-bottom: 20px;">
                <i class="bi bi-<?= $notificationType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
                <div>
                    <strong>
                        <?= $notificationType === 'success' ? 'Berhasil' : 'Tidak dapat diproses' ?>
                    </strong>
                    <span><?= htmlspecialchars($notification) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- ================= STATISTICS ================= -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">
                    <i class="bi bi-car-front-fill"></i>
                </div>

                <div class="stat-content">
                    <span>Total Kendaraan</span>
                    <strong>
                        <?= number_format($totalKendaraan) ?>
                    </strong>
                    <small>Terdaftar di sistem</small>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="stat-content">
                    <span>Kendaraan Aktif</span>
                    <strong>
                        <?= number_format($totalActive) ?>
                    </strong>
                    <small>Masih aktif</small>
                </div>
            </div>


            <div class="stat-card">
                <div class="stat-icon orange">
                    <i class="bi bi-tools"></i>
                </div>
                <div class="stat-content">
                    <span>Service Bulan Ini</span>
                    <strong>
                        <?= number_format($totalServiceBulanIni) ?>
                    </strong>
                    <small>Pesanan kendaraan</small>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="bi bi-palette-fill"></i>
                </div>
                <div class="stat-content">
                    <span>Painting Bulan Ini</span>
                    <strong>
                        <?= number_format($totalPaintingBulanIni) ?>
                    </strong>
                    <small>Pengecatan kendaraan</small>
                </div>
            </div>
        </div>


        <!-- ================= TABLE CARD ================= -->
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h2>Daftar Kendaraan</h2>
                    <p>Data kendaraan pelanggan</p>
                </div>

            </div>

            <!-- ================= FILTER ================= -->
            <div class="filter-section">
                <div class="search-box-s">
                    <i class="bi bi-search"></i>
                    <input type="text" id="searchInput" value="<?= htmlspecialchars($search) ?>" placeholder="Cari kode, pelanggan, nomor polisi...">
                </div>

                <select id="branchFilter" onchange="filterVehicle()">
                    <option value="">Semua Cabang</option>
                    <?php foreach ($cabangs as $cabang): ?>
                        <option
                            value="<?= (int) $cabang['id'] ?>"
                            <?= ((string)$branchFilter === (string)$cabang['id']) ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($cabang['name']) ?>
                        </option>

                    <?php endforeach; ?>
                </select>

                <select id="statusFilter" onchange="filterVehicle()">
                    <option value="">Semua Status</option>
                    <option value="active"
                        <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                        Aktif
                    </option>

                    <option value="inactive"
                        <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                        Tidak Aktif
                    </option>
                </select>
            </div>

            <!-- ================= TABLE ================= -->
            <div class="table-wrapper">
                <table id="vehicleTable">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Kendaraan</th>
                            <th>Pelanggan</th>
                            <th>No. Polisi</th>
                            <th>Warna</th>
                            <th>Tahun</th>
                            <th>Cabang</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!empty($vehicles)):?>
                            <?php foreach ($vehicles as $vehicle):?>        
                                <tr>
                                    <!-- KODE -->
                                    <td>
                                        <span class="vehicle-code">
                                            VEH-<?= str_pad((int)$vehicle['id'], 4, '0', STR_PAD_LEFT) ?>
                                        </span>
                                    </td>

                                    <!-- KENDARAAN -->
                                    <td>
                                        <div class="vehicle-info">
                                            <div class="vehicle-icon">
                                                <i class="bi bi-car-front-fill"></i>
                                            </div>

                                            <div>
                                                <strong>
                                                    <?= htmlspecialchars($vehicle['brand']) ?>
                                                    <?= !empty($vehicle['model'])
                                                        ? ' ' . htmlspecialchars($vehicle['model'])
                                                        : '' ?>
                                                </strong>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Pelanggan -->
                                    <td>
                                        <strong>
                                            <?= htmlspecialchars($vehicle['customer_name'] ?? '-') ?>
                                        </strong>
                                    </td>

                                    <!-- No POLISI -->
                                    <td>
                                        <span class="plate-number">
                                            <?= htmlspecialchars($vehicle['plate_number']) ?>
                                        </span>
                                    </td>

                                    <!-- WARNA -->
                                    <td>
                                        <?= htmlspecialchars($vehicle['color'] ?? '-') ?>
                                    </td>

                                    <!-- TAHUN -->
                                    <td>
                                        <?= htmlspecialchars($vehicle['year'] ?? '-') ?>
                                    </td>

                                    <!-- CABANG -->
                                    <td>
                                        <?= htmlspecialchars($vehicle['cabang_name'] ?? '-') ?>
                                    </td>

                                    <!-- STATUS -->
                                    <td>
                                        <?php if (($vehicle['status'] ?? 'active') === 'active'): ?>
                                            <span class="status active">Aktif</span>
                                        <?php else: ?>
                                            <span class="status inactive">Tidak Aktif</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- AKSI -->
                                    <td>
                                        <?php
                                            $hasTransaction = ((int)($vehicle['total_orders'] ?? 0) > 0);
                                        ?>

                                        <div class="action-buttons">

                                            <a href="detail.php?id=<?= (int)$vehicle['id'] ?>"
                                               class="btn-icon"
                                               title="Lihat Detail">
                                                <i class="bi bi-eye"></i>
                                            </a>

                                            <button type="button"
                                                    class="btn-icon"
                                                    title="Edit Status"
                                                    onclick="editVehicle(<?= (int)$vehicle['id'] ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <?php if ($hasTransaction): ?>

                                                <button type="button"
                                                        class="btn-icon danger"
                                                        title="Tidak dapat dihapus: sudah memiliki transaksi"
                                                        disabled
                                                        style="opacity:.45; cursor:not-allowed;">
                                                    <i class="bi bi-lock-fill"></i>
                                                </button>

                                            <?php else: ?>

                                                <button type="button"
                                                        class="btn-icon danger"
                                                        title="Hapus"
                                                        onclick="deleteVehicle(<?= (int)$vehicle['id'] ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>

                                            <?php endif; ?>

                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align:center; padding:30px;">
                                        Belum ada data kendaraan.
                                    </td>
                                </tr>
                            <?php endif; ?>
                    </tbody>
                </table>
            </div>


            <!-- ================= PAGINATION ================= -->
            <?php
                $startItem = $totalVehicles > 0
                    ? $offset + 1
                    : 0;

                $endItem = min(
                    $offset + $perPage,
                    $totalVehicles
                );

                $paginationParams = [];

                if ($search !== '') {
                    $paginationParams['search'] = $search;
                }

                if ($branchFilter !== '') {
                    $paginationParams['branch'] = $branchFilter;
                }

                if ($statusFilter !== '') {
                    $paginationParams['status'] = $statusFilter;
                }
            ?>
            <div class="pagination">

                <span>
                    <?= $startItem ?>–<?= $endItem ?>
                    dari <?= $totalVehicles ?> kendaraan
                </span>

                <div class="pagination-buttons">
                    <!-- PREVIOUS -->
                    <?php if ($page > 1): ?>
                        <?php
                            $prevParams = array_merge(
                                $paginationParams,
                                ['page' => $page - 1]
                            );
                        ?>
                        <a href="index.php?<?= http_build_query($prevParams) ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <button disabled>
                            <i class="bi bi-chevron-left"></i>
                        </button>
                    <?php endif; ?>

                    <!-- PAGE NUMBERS -->
                    <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                    ?>

                    <?php if ($startPage > 1): ?>
                        <?php
                            $firstParams = array_merge(
                                $paginationParams,
                                ['page' => 1]
                            );
                        ?>
                        <a href="index.php?<?= http_build_query($firstParams) ?>">1</a>
                        <?php if ($startPage > 2): ?>
                            <span>...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <?php
                            $pageParams = array_merge(
                                $paginationParams,
                                ['page' => $i]
                            );
                        ?>

                        <?php if ($i == $page): ?>
                            <button class="active-page"><?= $i ?></button>
                        <?php else: ?>
                            <a href="index.php?<?= http_build_query($pageParams) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <span>...</span>
                        <?php endif; ?>

                        <?php
                            $lastParams = array_merge(
                                $paginationParams,
                                ['page' => $totalPages]
                            );
                        ?>
                        <a href="index.php?<?= http_build_query($lastParams) ?>"><?= $totalPages ?></a>
                    <?php endif; ?>

                    <!-- NEXT -->
                    <?php if ($page < $totalPages): ?>
                        <?php
                            $nextParams = array_merge(
                                $paginationParams,
                                ['page' => $page + 1]
                            );
                        ?>

                        <a href="index.php?<?= http_build_query($nextParams) ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <button disabled>
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</main>

<!-- ================= MODAL ================= -->
<div class="modal-overlay" id="vehicleModal">
    <div class="modal">
        <div class="modal-header">
            <div>
                <h2 id="vehicleModalTitle">Tambah Kendaraan</h2>
                <p id="vehicleModalDescription">
                    Masukkan informasi kendaraan pelanggan.
                </p>
            </div>

            <button type="button" class="modal-close" onclick="closeModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form method="POST" id="vehicleForm">
            <?php if ($message !== '' && $messageType === 'error'): ?>
                <div class="form-alert error">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div>
                        <strong>Data tidak dapat disimpan</strong>
                        <span><?= htmlspecialchars($message) ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <input type="hidden" name="action" id="vehicleAction" value="add_vehicle">
            <input type="hidden" name="vehicle_id" id="vehicle_id" value="">
            <div class="form-grid">
                <!-- Kode -->
                <!-- <div class="form-group">
                    <label>Kode Kendaraan</label>
                    <input type="text" value="VEH-0006" readonly>
                </div> -->

                <!-- Pelanggan -->
                <div class="form-group">
                    <label>Pelanggan <span>*</span></label>
                    <select name="customer_id" id="customer_id" required>
                        <option value="">Pilih pelanggan</option>
                        <?php foreach ($customers as $customer): ?>
                            <option 
                                value="<?= (int)$customer['id'] ?>"
                                data-cabang-id="<?= (int)$customer['cabang_id'] ?>"
                                data-cabang-name="<?= htmlspecialchars($customer['cabang_name'] ?? '', ENT_QUOTES) ?>"
                                <?= ((int)$formData['customer_id'] === (int)$customer['id']) ? 'selected' : '' ?>
                            >
                            <?= htmlspecialchars($customer['name']) ?>
                            </option>
                    <?php endforeach; ?>
                    </select>
                </div>

                <!-- Nomor Polisi -->
                <div class="form-group">
                    <label>Nomor Polisi <span>*</span></label>
                    <input type="text" id="plate_number" name="plate_number" placeholder="Contoh: B 1234 ABC" value="<?= htmlspecialchars($formData['plate_number']) ?>" required>
                </div>

                <!-- Merk -->
                <div class="form-group">
                    <label>Merk Kendaraan <span>*</span></label>
                    <select name="brand" id="brand" required>
                        <option value="">Pilih merk</option>
                        <option value="Toyota" <?= $formData['brand'] === 'Toyota' ? 'selected' : '' ?>>Toyota</option>
                        <option value="Honda" <?= $formData['brand'] === 'Honda' ? 'selected' : '' ?>>Honda</option>
                        <option value="Mitsubishi" <?= $formData['brand'] === 'Mitsubishi' ? 'selected' : '' ?>>Mitsubishi</option>
                        <option value="Suzuki" <?= $formData['brand'] === 'Suzuki' ? 'selected' : '' ?>>Suzuki</option>
                        <option value="Daihatsu" <?= $formData['brand'] === 'Daihatsu' ? 'selected' : '' ?>>Daihatsu</option>
                        <option value="Nissan" <?= $formData['brand'] === 'Nissan' ? 'selected' : '' ?>>Nissan</option>
                        <option value="BMW" <?= $formData['brand'] === 'BMW' ? 'selected' : '' ?>>BMW</option>
                        <option value="Mercedes-Benz" <?= $formData['brand'] === 'Mercedes-Benz' ? 'selected' : '' ?>>Mercedes-Benz</option>
                        <option value="Lainnya" <?= $formData['brand'] === 'Lainnya' ? 'selected' : '' ?>>Lainnya</option>
                    </select>
                </div>

                <!-- Model -->
                <div class="form-group">
                    <label>Model / Tipe <span>*</span></label>
                    <input type="text" name="model" id="model" placeholder="Contoh: Avanza 1.5 G" value="<?= htmlspecialchars($formData['model']) ?>" required>
                </div>

                <!-- Tahun -->
                <div class="form-group">
                    <label>Tahun</label>
                    <input type="number" name="year" id="year" placeholder="Contoh: 2024" value="<?= htmlspecialchars($formData['year']) ?>">
                </div>

                <!-- Warna -->
                <div class="form-group">
                    <label>Warna Kendaraan <span>*</span></label>
                    <input type="text" name="color" id="color" placeholder="Contoh: White" value="<?= htmlspecialchars($formData['color']) ?>" required>
                </div>

                <!-- Cabang -->
                <div class="form-group">
                    <label>Cabang <span>*</span></label>
                    <input type="text" id="cabang_name" placeholder="Cabang otomatis" readonly>
                    <input type="hidden" name="cabang_id" id="cabang_id">
                </div>

                <!-- Status -->
                <div class="form-group" id="statusGroup" style="display:none;">
                    <label>Status Kendaraan</label>
                    <select name="status" id="vehicleStatus">
                        <option value="active">Aktif</option>
                        <option value="inactive">Tidak Aktif</option>
                    </select>
                </div>

                <!-- Nomor Rangka -->
                <div class="form-group full">
                    <label>Nomor Rangka / Chassis Number</label>
                    <input type="text" id="vin_number" name="vin_number" placeholder="Opsional" value="<?= htmlspecialchars($formData['vin_number']) ?>">
                </div>

                <!-- Catatan -->
                <div class="form-group full">
                    <label>Catatan</label>
                    <textarea rows="3" name="note" id="note" placeholder="Catatan tambahan kendaraan..."><?= htmlspecialchars($formData['note']) ?></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-primary" onclick="closeModal()"><i class="bi bi-x"></i>
                    Batal
                </button>

                <button type="submit" class="btn-primary" id="vehicleSubmitButton"><i class="bi bi-check-lg"></i>
                    <span id="vehicleSubmitText">Simpan Kendaraan</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ================= MODAL KONFIRMASI HAPUS ================= -->
<div class="modal-overlay" id="deleteConfirmModal">
    <div class="modal" style="max-width: 430px;">

        <div class="modal-header">
            <div>
                <h2>Konfirmasi Hapus</h2>
                <p>Periksa kembali tindakan yang akan dilakukan.</p>
            </div>

            <button type="button"
                    class="modal-close"
                    onclick="closeDeleteModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div style="padding: 24px; text-align: center;">

            <div style="font-size: 46px; margin-bottom: 12px;">
                <i class="bi bi-exclamation-triangle"></i>
            </div>

            <p style="margin: 0; line-height: 1.6;">
                Apakah Anda yakin ingin menghapus data kendaraan ini?
            </p>

            <p style="margin-top: 8px; color: #6b7280; font-size: 13px;">
                Kendaraan yang sudah memiliki transaksi tidak dapat dihapus.
            </p>

        </div>

        <div class="modal-footer">

            <button type="button"
                    class="btn-primary"
                    onclick="closeDeleteModal()">
                <i class="bi bi-x"></i>
                Batal
            </button>

            <button type="button"
                    class="btn-primary"
                    onclick="confirmDelete()">
                <i class="bi bi-trash"></i>
                Hapus
            </button>

        </div>

    </div>
</div>

<script>
// ================= BERSIHKAN PARAMETER NOTIFIKASI =================
document.addEventListener("DOMContentLoaded", function () {

    const url = new URL(window.location.href);

    if (
        url.searchParams.has("success") ||
        url.searchParams.has("error")
    ) {

        url.searchParams.delete("success");
        url.searchParams.delete("error");

        window.history.replaceState(
            {},
            document.title,
            url.pathname +
            (url.searchParams.toString()
                ? "?" + url.searchParams.toString()
                : "")
        );
    }

});

// ================= MODAL =================

function openModal() {

    resetVehicleModal();
    const modal = document.getElementById("vehicleModal");

    if (modal) {
        modal.classList.add("show");
        const customerSelect =
            document.getElementById("customer_id");
        if (customerSelect) {
            customerSelect.dispatchEvent(
                new Event("change")
            );
        }
    }
}

function closeModal() {
    const modal = document.getElementById("vehicleModal");
    if (modal) {
        modal.classList.remove("show");
    }

    resetVehicleModal();
}

// RESET DATA INPUT
function resetVehicleModal() {

    const form = document.getElementById("vehicleForm");

    if (form) {
        form.reset();
    }

    const vehicleAction = document.getElementById("vehicleAction");
    const vehicleId = document.getElementById("vehicle_id");
    const modalTitle = document.getElementById("vehicleModalTitle");
    const modalDescription = document.getElementById("vehicleModalDescription");
    const submitText = document.getElementById("vehicleSubmitText");
    const statusGroup = document.getElementById("statusGroup");
    const cabangId = document.getElementById("cabang_id");
    const cabangName = document.getElementById("cabang_name");

    if (vehicleAction) {
        vehicleAction.value = "add_vehicle";
    }

    if (vehicleId) {
        vehicleId.value = "";
    }

    if (modalTitle) {
        modalTitle.textContent = "Tambah Kendaraan";
    }

    if (modalDescription) {
        modalDescription.textContent =
            "Masukkan informasi kendaraan pelanggan.";
    }

    if (submitText) {
        submitText.textContent = "Simpan Kendaraan";
    }

    if (statusGroup) {
        statusGroup.style.display = "none";
    }

    if (cabangId) {
        cabangId.value = "";
    }

    if (cabangName) {
        cabangName.value = "";
    }

    // ==========================
    // KEMBALIKAN KE MODE TAMBAH
    // ==========================

    const customer = document.getElementById("customer_id");
    const plate = document.getElementById("plate_number");
    const brand = document.getElementById("brand");
    const model = document.getElementById("model");
    const year = document.getElementById("year");
    const color = document.getElementById("color");
    const vin = document.getElementById("vin_number");
    const note = document.getElementById("note");
    const cabangIdInput = document.getElementById("cabang_id");
    const cabangNameInput = document.getElementById("cabang_name");
    const status = document.getElementById("vehicleStatus");

    if (customer) customer.disabled = false;
    if (plate) plate.readOnly = false;
    if (brand) brand.disabled = false;
    if (model) model.readOnly = false;
    if (year) year.readOnly = false;
    if (color) color.readOnly = false;
    if (vin) vin.readOnly = false;
    if (note) note.readOnly = false;
    if (cabangIdInput) cabangIdInput.disabled = false;
    if (cabangNameInput) cabangNameInput.readOnly = true;
    if (status) status.disabled = false;
}

// Buka kembali modal otomatis jika validasi PHP gagal.
<?php if ($showVehicleModal): ?>
document.addEventListener("DOMContentLoaded", function () {
    openModal();
});
<?php endif; ?>

// Tutup modal jika klik area luar
window.addEventListener("click", function(event) {
    const modal = document.getElementById("vehicleModal");

    if (modal && event.target === modal) {
        closeModal();
    }

    const deleteModal = document.getElementById("deleteConfirmModal");

    if (deleteModal && event.target === deleteModal) {
        closeDeleteModal();
    }
});

// ================= AMBIL DATA CABANG =================

document.addEventListener("DOMContentLoaded", function () {
    const customerSelect = document.getElementById("customer_id");

    if (!customerSelect) {
        return;
    }

    customerSelect.addEventListener("change", function () {
        const selectedOption = this.options[this.selectedIndex];

        const cabangId =
            selectedOption.getAttribute("data-cabang-id") || "";

        const cabangName =
            selectedOption.getAttribute("data-cabang-name") || "";

        const cabangIdInput = document.getElementById("cabang_id");
        const cabangNameInput = document.getElementById("cabang_name");

        if (cabangIdInput) {
            cabangIdInput.value = cabangId;
        }

        if (cabangNameInput) {
            cabangNameInput.value = cabangName;
        }
    });

    // Jika ada customer terpilih setelah error validasi,
    // tampilkan cabang otomatis.
    if (customerSelect.value !== "") {
        customerSelect.dispatchEvent(new Event("change"));
    }
});

// ================= SEARCH DATABASE =================
function searchVehicle() {

    const searchInput = document.getElementById("searchInput");
    const branchFilter = document.getElementById("branchFilter");
    const statusFilter = document.getElementById("statusFilter");

    const search = searchInput
        ? searchInput.value.trim()
        : "";

    const branch = branchFilter
        ? branchFilter.value
        : "";

    const status = statusFilter
        ? statusFilter.value
        : "";

    const params = new URLSearchParams();

    // Search
    if (search !== "") {
        params.set("search", search);
    }

    // Cabang
    if (branch !== "") {
        params.set("branch", branch);
    }

    // Status
    if (status !== "") {
        params.set("status", status);
    }

    // Setelah melakukan pencarian,
    // selalu mulai dari halaman 1
    params.set("page", "1");

    window.location.href =
        "index.php?" + params.toString();
}

// ================= ENTER SEARCH =================
document.addEventListener("DOMContentLoaded", function () {

    const searchInput = document.getElementById("searchInput");

    if (searchInput) {

        searchInput.addEventListener("keydown", function (event) {

            if (event.key === "Enter") {

                event.preventDefault();

                searchVehicle();
            }

        });

    }

});

// ================= CLEAR SEARCH SAAT RELOAD =================
document.addEventListener("DOMContentLoaded", function () {

    const navigation =
        performance.getEntriesByType("navigation")[0];

    // Cek apakah halaman benar-benar di-reload
    if (navigation && navigation.type === "reload") {

        const url =
            new URL(window.location.href);

        // Hapus search
        url.searchParams.delete("search");

        // Kembali ke halaman pertama
        url.searchParams.set("page", "1");

        /*
         * Hanya redirect jika memang
         * sebelumnya terdapat parameter search.
         */
        if (window.location.search.includes("search=")) {

            window.location.replace(
                url.pathname + "?" + url.searchParams.toString()
            );

        }

    }

});

// ================= FILTER =================

function filterVehicle() {
    const searchElement = document.getElementById("searchInput");
    const branchElement = document.getElementById("branchFilter");
    const statusElement = document.getElementById("statusFilter");

    const search = searchElement
        ? searchElement.value.trim()
        : "";

    const branch = branchElement
        ? branchElement.value
        : "";

    const status = statusElement
        ? statusElement.value
        : "";

    const params = new URLSearchParams();

    if (search !== "") {
        params.set("search", search);
    }

    if (branch !== "") {
        params.set("branch", branch);
    }

    if (status !== "") {
        params.set("status", status);
    }

    params.set("page", "1");

    window.location.href =
        "index.php?" + params.toString();
}

async function editVehicle(id) {

    try {

        const response = await fetch(
            "index.php?action=get_vehicle&id=" + encodeURIComponent(id)
        );

        const result = await response.json();

        if (!result.success) {
            alert(result.message || "Data kendaraan tidak ditemukan.");
            return;
        }

        const vehicle = result.data;

        // ==========================
        // MODE EDIT STATUS
        // HANYA STATUS YANG DAPAT DIUBAH
        // ==========================

        document.getElementById("vehicleAction").value =
            "update_vehicle";

        document.getElementById("vehicle_id").value =
            vehicle.id;

        document.getElementById("vehicleModalTitle").textContent =
            "Edit Status Kendaraan";

        document.getElementById("vehicleModalDescription").textContent =
            "Data kendaraan tidak dapat diubah. Hanya status yang dapat diperbarui.";

        document.getElementById("vehicleSubmitText").textContent =
            "Simpan Status";

        document.getElementById("statusGroup").style.display =
            "block";

        // ==========================
        // TAMPILKAN DATA KENDARAAN
        // ==========================

        document.getElementById("customer_id").value =
            vehicle.customer_id;

        document.getElementById("plate_number").value =
            vehicle.plate_number || "";

        document.getElementById("brand").value =
            vehicle.brand || "";

        document.getElementById("model").value =
            vehicle.model || "";

        document.getElementById("year").value =
            vehicle.year || "";

        document.getElementById("color").value =
            vehicle.color || "";

        document.getElementById("vin_number").value =
            vehicle.vin_number || "";

        document.getElementById("note").value =
            vehicle.note || "";

        document.getElementById("vehicleStatus").value =
            vehicle.status || "active";

        // ==========================
        // KUNCI SEMUA FIELD
        // HANYA STATUS AKTIF
        // ==========================

        const customer = document.getElementById("customer_id");
        const plate = document.getElementById("plate_number");
        const brand = document.getElementById("brand");
        const model = document.getElementById("model");
        const year = document.getElementById("year");
        const color = document.getElementById("color");
        const vin = document.getElementById("vin_number");
        const note = document.getElementById("note");
        const cabangId = document.getElementById("cabang_id");
        const cabangName = document.getElementById("cabang_name");
        const status = document.getElementById("vehicleStatus");

        if (customer) customer.disabled = true;
        if (plate) plate.readOnly = true;
        if (brand) brand.disabled = true;
        if (model) model.readOnly = true;
        if (year) year.readOnly = true;
        if (color) color.readOnly = true;
        if (vin) vin.readOnly = true;
        if (note) note.readOnly = true;
        if (cabangId) cabangId.disabled = true;
        if (cabangName) cabangName.readOnly = true;

        if (status) status.disabled = false;

        // ==========================
        // TAMPILKAN CABANG
        // ==========================

        const customerSelect =
            document.getElementById("customer_id");

        if (customerSelect) {
            customerSelect.dispatchEvent(
                new Event("change")
            );
        }

        // ==========================
        // BUKA MODAL
        // ==========================

        const modal =
            document.getElementById("vehicleModal");

        if (modal) {
            modal.classList.add("show");
        }

    } catch (error) {

        console.error(error);

        alert(
            "Terjadi kesalahan saat mengambil data kendaraan."
        );
    }
}

// ================= HAPUS KENDARAAN =================

let vehicleIdToDelete = null;

function deleteVehicle(id) {

    vehicleIdToDelete = id;

    const modal = document.getElementById("deleteConfirmModal");

    if (modal) {
        modal.classList.add("show");
    }
}

function closeDeleteModal() {

    vehicleIdToDelete = null;

    const modal = document.getElementById("deleteConfirmModal");

    if (modal) {
        modal.classList.remove("show");
    }
}

function confirmDelete() {

    if (!vehicleIdToDelete) {
        return;
    }

    const form = document.createElement("form");

    form.method = "POST";
    form.action = "index.php";

    const actionInput = document.createElement("input");
    actionInput.type = "hidden";
    actionInput.name = "action";
    actionInput.value = "delete_vehicle";

    const vehicleIdInput = document.createElement("input");
    vehicleIdInput.type = "hidden";
    vehicleIdInput.name = "vehicle_id";
    vehicleIdInput.value = vehicleIdToDelete;

    form.appendChild(actionInput);
    form.appendChild(vehicleIdInput);

    document.body.appendChild(form);

    form.submit();
}
</script>

</body>
</html>
