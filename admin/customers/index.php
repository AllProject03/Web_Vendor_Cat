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
        // Jangan hentikan halaman Pelanggan jika tabel/setting bermasalah.
    }

    /*
     * Logo memakai endpoint database. URL dibentuk dari URL halaman saat ini,
     * sehingga aman untuk /admin/customers/, /admin/product/, dan submenu lain.
     */
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $projectRoot = dirname(dirname(dirname($scriptName)));

    if ($projectRoot !== '' && $projectRoot !== '/' && $projectRoot !== '.') {
        $brand['logo'] = rtrim($projectRoot, '/') . '/admin/settings/logo.php?v=20260913';
    } else {
        $brand['logo'] = '../../assets/img/logo.png';
    }

    return $brand;
}

$companyBrand = load_company_brand($pdo);
$companyName = $companyBrand['name'];
$companyTagline = $companyBrand['tagline'];
$companyLogo = $companyBrand['logo'];



/* =====================================================
   AMBIL DATA CABANG
===================================================== */
$stmtCabang = $pdo->prepare(" SELECT id, code, name FROM cabangs WHERE status = 'active' ORDER BY name ASC");
$stmtCabang->execute();
$cabangs = $stmtCabang->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   TAMBAH PELANGGAN
===================================================== */
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_customer') {

    $nama           = trim($_POST['name'] ?? '');
    $customerType   = trim($_POST['customer_type'] ?? '');
    $telepon        = trim($_POST['phone'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $alamat         = trim($_POST['address'] ?? '');
    $cabangId       = (int)($_POST['cabang_id'] ?? 0);


    if ($nama === '') {
        $message = 'Nama pelanggan wajib diisi.';
        $messageType = 'error';
    } elseif ($cabangId <= 0) {
        $message = 'Cabang wajib dipilih.';
        $messageType = 'error';
    } else {
        try {
            // Pastikan cabang aktif
            $customer = $pdo->prepare("SELECT id FROM cabangs WHERE id = :id AND status = 'active' LIMIT 1

            ");

            $customer->execute([
                ':id' => $cabangId
            ]);

            if (!$customer->fetch()) {

                $message = 'Cabang tidak valid atau tidak aktif.';
                $messageType = 'error';

            } else {
                // Simpan pelanggan
                $customer = $pdo->prepare("INSERT INTO customers(cabang_id, name, customer_type, phone, email,address)
                    VALUES(:cabang_id, :name, :customer_type, :phone, :email,:address)
                ");

                $customer->execute([
                    ':cabang_id' => $cabangId,
                    ':name'      => $nama,
                    ':customer_type'=> $customerType,
                    ':phone'     => $telepon,
                    ':email'     => $email,
                    ':address'   => $alamat
                ]);

                header('Location: index.php?success=customer_added');
                exit;
            }

        } catch (PDOException $e) {
            $message = 'Gagal menyimpan pelanggan.';
            $messageType = 'error';
        }
    }
}

/* =====================================================
   TOTAL PELANGGAN
===================================================== */
$stmtPelanggan = $pdo->query("SELECT COUNT(*) FROM customers");
$totalPelanggan = (int) $stmtPelanggan->fetchColumn();

/* =====================================================
   TOTAL PELANGGAN ACTIVE
===================================================== */
$PelangganActive = $pdo->query("SELECT COUNT(*) FROM customers WHERE status='active'");
$totalPelangganActive = (int) $PelangganActive->fetchColumn();

/* =====================================================
   TOTAL PRODUKSI
===================================================== */
$t_produksi = $pdo->query("SELECT COUNT(*) FROM customers WHERE customer_type='Produksi'");
$totalProduksi = (int) $t_produksi->fetchColumn();

/* =====================================================
   TOTAL PENJUALAN
===================================================== */
$t_penjualan = $pdo->query("SELECT COUNT(*) FROM customers WHERE customer_type='Penjualan'");
$totalPenjualan = (int) $t_penjualan->fetchColumn();

/* =====================================================
   SEARCH + FILTER + PAGINATION PELANGGAN
===================================================== */

$perPage = 5;

/* -------------------------
   Ambil parameter filter
------------------------- */

$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = [];
$params = [];


/* -------------------------
   SEARCH
------------------------- */

if ($search !== '') {

    $where[] = "(
        c.name LIKE :search_name
        OR c.phone LIKE :search_phone
        OR c.email LIKE :search_email
        OR c.id LIKE :search_id
        OR cb.name LIKE :search_cabang
    )";

    $searchValue = '%' . $search . '%';

    $params[':search_name']   = $searchValue;
    $params[':search_phone']  = $searchValue;
    $params[':search_email']  = $searchValue;
    $params[':search_id']     = $searchValue;
    $params[':search_cabang'] = $searchValue;
}


/* -------------------------
   FILTER JENIS
------------------------- */

if ($typeFilter !== '') {

    $where[] = "c.customer_type = :customer_type";

    $params[':customer_type'] = $typeFilter;
}


/* -------------------------
   FILTER STATUS
------------------------- */

if ($statusFilter !== '') {

    $where[] = "c.status = :status";

    $params[':status'] = $statusFilter;
}


/* -------------------------
   WHERE SQL
------------------------- */

$whereSQL = '';

if (!empty($where)) {
    $whereSQL = 'WHERE ' . implode(' AND ', $where);
}


/* -------------------------
   TOTAL DATA FILTER
------------------------- */

$stmtTotal = $pdo->prepare("
    SELECT COUNT(*)
    FROM customers c
    LEFT JOIN cabangs cb ON cb.id = c.cabang_id
    $whereSQL
");

$stmtTotal->execute($params);

$totalCustomers = (int) $stmtTotal->fetchColumn();


/* -------------------------
   PAGINATION
------------------------- */

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$totalPages = max(
    1,
    (int) ceil($totalCustomers / $perPage)
);

$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;

/* =====================================================
   AMBIL DATA PELANGGAN
===================================================== */

$stmtCustomers = $pdo->prepare("SELECT
        c.id,
        c.name,
        c.customer_type,
        c.phone,
        c.email,
        c.address,
        c.status,
        cb.name AS cabang_name,

        (
            SELECT COUNT(*)
            FROM orders o
            WHERE o.customer_id = c.id
            AND o.status <> 'cancelled'
        ) AS total_pesanan

    FROM customers c

    LEFT JOIN cabangs cb
        ON cb.id = c.cabang_id

    $whereSQL

    ORDER BY c.id DESC

    LIMIT :limit OFFSET :offset
");


/* Parameter Search + Filter */

foreach ($params as $key => $value) {
    $stmtCustomers->bindValue($key, $value, PDO::PARAM_STR);
}


/* Parameter Pagination */

$stmtCustomers->bindValue(
    ':limit',
    $perPage,
    PDO::PARAM_INT
);

$stmtCustomers->bindValue(
    ':offset',
    $offset,
    PDO::PARAM_INT
);

$stmtCustomers->execute();

$customers = $stmtCustomers->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   DETAIL PELANGGAN
===================================================== */
if (isset($_GET['detail'])) {

    $customerId = (int) $_GET['detail'];

    $stmtDetail = $pdo->prepare("SELECT
            c.id,
            c.name,
            c.customer_type,
            c.phone,
            c.email,
            c.address,
            c.status,
            c.cabang_id,
            cb.name AS cabang_name FROM customers c LEFT JOIN cabangs cb ON cb.id = c.cabang_id WHERE c.id = :id LIMIT 1
    ");

    $stmtDetail->execute([
        ':id' => $customerId
    ]);

    $detailCustomer = $stmtDetail->fetch(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');

    if ($detailCustomer) {
        echo json_encode(['success' => true,'data' => $detailCustomer]);
    } else {
        echo json_encode(['success' => false,'message' => 'Pelanggan tidak ditemukan.']);
    }

    exit;
}

/* =====================================================
   EDIT PELANGGAN
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | EDIT PELANGGAN
    |--------------------------------------------------------------------------
    */
    if ($action === 'edit_customer') {

        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $customerType = trim($_POST['customer_type'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $cabangId = (int) ($_POST['cabang_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $address = trim($_POST['address'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | VALIDASI
        |--------------------------------------------------------------------------
        */

        if ($customerId <= 0) {
            $error = 'ID pelanggan tidak valid.';
        } elseif ($name === '') {
            $error = 'Nama pelanggan wajib diisi.';
        } elseif (!in_array($customerType, ['Penjualan', 'Produksi'], true)) {
            $error = 'Jenis pelanggan tidak valid.';
        } elseif ($phone === '') {
            $error = 'Nomor telepon wajib diisi.';
        } elseif ($cabangId <= 0) {
            $error = 'Cabang wajib dipilih.';
        } elseif (!in_array($status, ['active', 'inactive'], true)) {
            $error = 'Status pelanggan tidak valid.';
        }

        /*
        |--------------------------------------------------------------------------
        | CEK EMAIL
        |--------------------------------------------------------------------------
        */

        if (!isset($error) && $email !== '') {

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Format email tidak valid.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | CEK PELANGGAN
        |--------------------------------------------------------------------------
        */

        if (!isset($error)) {

            $stmtCheck = $pdo->prepare("
                SELECT id
                FROM customers
                WHERE id = :id
                LIMIT 1
            ");

            $stmtCheck->execute([
                ':id' => $customerId
            ]);

            if (!$stmtCheck->fetch()) {
                $error = 'Data pelanggan tidak ditemukan.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | CEK CABANG
        |--------------------------------------------------------------------------
        */

        if (!isset($error)) {

            $stmtCabangCheck = $pdo->prepare("
                SELECT id
                FROM cabangs
                WHERE id = :id
                AND status = 'active'
                LIMIT 1
            ");

            $stmtCabangCheck->execute([
                ':id' => $cabangId
            ]);

            if (!$stmtCabangCheck->fetch()) {
                $error = 'Cabang yang dipilih tidak valid atau tidak aktif.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE DATABASE
        |--------------------------------------------------------------------------
        */

        if (!isset($error)) {

            try {

                $stmtUpdate = $pdo->prepare("
                    UPDATE customers
                    SET
                        name = :name,
                        customer_type = :customer_type,
                        phone = :phone,
                        email = :email,
                        cabang_id = :cabang_id,
                        status = :status,
                        address = :address
                    WHERE id = :id
                ");

                $stmtUpdate->execute([
                    ':name' => $name,
                    ':customer_type' => $customerType,
                    ':phone' => $phone,
                    ':email' => $email !== '' ? $email : null,
                    ':cabang_id' => $cabangId,
                    ':status' => $status,
                    ':address' => $address !== '' ? $address : null,
                    ':id' => $customerId
                ]);

                /*
                |--------------------------------------------------------------------------
                | REDIRECT AGAR TIDAK RESUBMIT FORM
                |--------------------------------------------------------------------------
                */

                header('Location: index.php?success=customer_updated');
                exit;

            } catch (PDOException $e) {

                $error = 'Gagal memperbarui data pelanggan.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| DELETE PELANGGAN
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'delete_customer') {

        $customerId = (int) ($_POST['customer_id'] ?? 0);

        if ($customerId <= 0) {
            header('Location: index.php?error=invalid_customer');
            exit;
        }

        // Cek apakah pelanggan memiliki order
        $stmtCheckOrder = $pdo->prepare("
            SELECT COUNT(*)
            FROM orders
            WHERE customer_id = :customer_id
        ");

        $stmtCheckOrder->execute([
            ':customer_id' => $customerId
        ]);

        $totalOrder = (int) $stmtCheckOrder->fetchColumn();

        // Jika sudah memiliki order, jangan hapus
        if ($totalOrder > 0) {
            header('Location: index.php?error=customer_has_orders');
            exit;
        }

        // Hapus pelanggan
        $stmtDelete = $pdo->prepare("
            DELETE FROM customers
            WHERE id = :id
        ");

        $stmtDelete->execute([
            ':id' => $customerId
        ]);

        header('Location: index.php?success=customer_deleted');
        exit;
    }
}


?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c1a2d">

    <title>Pelanggan | <?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

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
            <a href="./" class="menu-item active">
                <i class="bi bi-people"></i>
                <span>Pelanggan</span>
            </a>

            <!-- KENDARAAN -->
            <a href="../vehicles/" class="menu-item">
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
    <!-- HEADER -->
        <div class="page-header">
            <div>
                <div class="breadcrumb">
                    Dashboard
                    <i class="bi bi-chevron-right"></i>
                    Master Data
                    <i class="bi bi-chevron-right"></i>
                    Pelanggan
                </div>

                <h1>Pelanggan</h1>
                <p>Kelola data pelanggan vendor cat mobil.</p>
            </div>

            <button class="btn-primary" onclick="openModal()">
                <i class="bi bi-plus-lg"></i>
                Tambah Pelanggan
            </button>
        </div>


        <!-- STATISTICS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">
                    <i class="bi bi-people"></i>
                </div>

                <div>
                    <span>Total Pelanggan</span>
                    <strong>
                        <?= number_format($totalPelanggan) ?>
                    </strong>
                </div>
            </div>


            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="bi bi-person-check"></i>
                </div>

                <div>
                    <span>Pelanggan Aktif</span>
                    <strong> <?= number_format($totalPelangganActive) ?></strong>
                </div>
            </div>


            <div class="stat-card">
                <div class="stat-icon orange">
                    <i class="bi bi-tools"></i>
                </div>
                <div>
                    <span>Produksi</span>
                    <strong><?= number_format($totalProduksi) ?></strong>
                </div>
            </div>


            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="bi bi-building"></i>
                </div>
                <div>
                    <span>Penjualan</span>
                    <strong><?= number_format($totalPenjualan) ?></strong>
                </div>
            </div>
        </div>


        <!-- TABLE CARD -->
        <div class="content-card">
            <!-- TOOLBAR -->
            <div class="table-toolbar">
                <div class="toolbar-left">
                    <div class="search-boxs">
                        <i class="bi bi-search"></i>
                        <input type="text" id="customerSearch" placeholder="Cari pelanggan..." autocomplete="off" value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>

                <div class="toolbar-right">
                    <select id="customerTypeFilter" onchange="applyCustomerFilter()">

                        <option value="">Semua Jenis</option>
                        <option value="Penjualan"
                            <?= $typeFilter === 'Penjualan' ? 'selected' : '' ?>>
                            Penjualan
                        </option>

                        <option value="Produksi"
                            <?= $typeFilter === 'Produksi' ? 'selected' : '' ?>>
                            Produksi
                        </option>
                    </select>

                    <select id="customerStatusFilter" onchange="applyCustomerFilter()">

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
            </div>


            <!-- TABLE -->
            <div class="table-wrapper">
                <table id="customerTable">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Pelanggan</th>
                            <th>Jenis</th>
                            <th>Kontak</th>
                            <th>Cabang</th>
                            <th>Status</th>
                            <th>Total Pesanan</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody id="customerTableBody">
                        <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                Belum ada data pelanggan.
                            </td>
                        </tr>

                        <?php else: ?>
                            <?php foreach ($customers as $customer): ?>
                                <?php
                                // Inisial nama pelanggan
                                $words = preg_split('/\s+/', trim($customer['name']));
                                $initials = '';

                                foreach (array_slice($words, 0, 2) as $word) {
                                    $initials .= strtoupper(substr($word, 0, 1));
                                }

                                // Kode pelanggan
                                $kodePelanggan = 'CUS-' . str_pad($customer['id'],4,'0',STR_PAD_LEFT);

                                // Jenis pelanggan
                                $customerType = $customer['customer_type'] ?? '';
                                if ($customerType === 'Penjualan') {
                                    $badgeClass = 'badge-blue';
                                } else {
                                    $badgeClass = 'badge-purple';
                                }

                                // Status
                                $status = $customer['status'] ?? 'inactive';
                                if ($status === 'active') {
                                    $statusClass = 'active';
                                    $statusText = 'Aktif';
                                } else {
                                    $statusClass = 'inactive';
                                    $statusText = 'Tidak Aktif';
                                }
                                ?>

                                <tr 
                                    data-type="<?= htmlspecialchars($customerType) ?>"
                                    data-status="<?= htmlspecialchars($status) ?>"
                                >
                                    <!-- Kode -->
                                    <td>
                                        <span class="customer-code">
                                            <?= htmlspecialchars($kodePelanggan) ?>
                                        </span>
                                    </td>

                                    <!-- Pelanggan -->
                                    <td>
                                        <div class="customer-info">
                                            <div class="avatar">
                                                <?= htmlspecialchars($initials) ?>
                                            </div>

                                            <div>
                                                <strong><?= htmlspecialchars($customer['name']) ?></strong>
                                                <?php if (!empty($customer['email'])): ?>
                                                    <small><?= htmlspecialchars($customer['email']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Jenis -->
                                    <td>
                                        <span class="badge <?= $badgeClass ?>">
                                            <?= htmlspecialchars($customerType) ?>
                                        </span>
                                    </td>

                                    <!-- Kontak -->
                                    <td>
                                        <?= htmlspecialchars($customer['phone'] ?? '-') ?>
                                    </td>

                                    <!-- Cabang -->
                                    <td>
                                        <?= htmlspecialchars($customer['cabang_name'] ?? '-') ?>
                                    </td>

                                    <!-- Status -->
                                    <td>
                                        <span class="status <?= $statusClass ?>">
                                            <?= $statusText ?>
                                        </span>
                                    </td>


                                    <!-- Total Pesanan -->
                                    <td>
                                        <strong>
                                            <?= number_format((int)$customer['total_pesanan']) ?>
                                        </strong>
                                    </td>


                                    <!-- Aksi -->
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn-icon" title="Detail" onclick="showDetail(<?= (int)$customer['id'] ?>)">
                                                <i class="bi bi-eye"></i>
                                            </button>

                                            <button type="button" class="btn-icon" title="Edit" onclick="showEdit(<?= (int)$customer['id'] ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <button type="button" class="btn-icon danger" title="Hapus" onclick="deleteCustomer(<?= (int)$customer['id'] ?>,'<?= htmlspecialchars($customer['name'], ENT_QUOTES) ?>')">
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

            


            <!-- PAGINATION -->
            <div class="pagination">
                <div class="pagination-info">
                    <?php
                        $startNumber = $totalCustomers > 0
                            ? $offset + 1
                            : 0;

                        $endNumber = min(
                            $offset + $perPage,
                            $totalCustomers
                        );
                    ?>

                    Menampilkan <?= $startNumber ?>–<?= $endNumber ?>
                    dari
                    <?= number_format($totalCustomers) ?> pelanggan

                </div>

                <div class="pagination-buttons">
                    <!-- PREVIOUS -->
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>" class="pagination-btn"> 
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <button type="button" class="pagination-btn disabled" disabled>
                            <i class="bi bi-chevron-left"></i>
                        </button>
                    <?php endif; ?>


                    <!-- NOMOR HALAMAN -->
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if (
                            $i <= 3 ||
                            $i == $totalPages
                        ): ?>

                            <a href="?page=<?= $i ?>"
                                class="pagination-btn <?= $i == $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>

                        <?php elseif ($i == 4 && $totalPages > 5): ?>
                            <span class="pagination-dots">
                                ...
                            </span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <!-- NEXT -->
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>" class="pagination-btn">
                            <i class="bi bi-chevron-right"></i>
                        </a>

                    <?php else: ?>
                        <button type="button" class="pagination-btn disabled" disabled>
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL TAMBAH PELANGGAN -->
    <div class="modal-overlay" id="customerModal">
        <div class="modal">
            <div class="modal-header">
                <div>
                    <h2>Tambah Pelanggan</h2>
                    <p>Masukkan informasi pelanggan baru.</p>
                </div>

                <button class="close-btn" onclick="closeModal()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>


            <form method="POST">
                <input type="hidden" name="action" value="add_customer">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nama Pelanggan<span>*</span></label>
                        <input type="text" name="name" placeholder="Budi Santoso" required>
                    </div>

                    <div class="form-group">
                        <label>Jenis Pelanggan<span>*</span></label>
                        <select name="customer_type" required>
                            <option value="">Pilih jenis pelanggan</option>
                            <option>Penjualan</option>
                            <option>Produksi</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Nomor Telepon<span>*</span></label>
                        <input type="text" name="phone" placeholder="08xxxxxxxxxx" required>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" placeholder="email@contoh.com">
                    </div>

                    <div class="form-group">
                        <label>Cabang<span>*</span></label>
                        <select name="cabang_id" required> 
                            <option value="">Pilih Cabang</option>
                            <?php foreach ($cabangs as $cabang): ?>
                                <option value="<?= (int)$cabang['id'] ?>">
                                    <?= htmlspecialchars($cabang['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label>Alamat</label>
                        <textarea rows="3" name="address" placeholder="Masukkan alamat lengkap pelanggan"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-primary" onclick="closeModal()">
                        <i class="bi bi-x"></i>
                        Batal
                    </button>

                    <button type="submit" class="btn-primary">
                        <i class="bi bi-check-lg"></i>
                        Simpan Pelanggan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DETAIL PELANGGAN -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal">
            <div class="modal-header">
                <div>
                    <h2>Detail Pelanggan</h2>
                    <p>Informasi lengkap pelanggan.</p>
                </div>

                <button type="button" class="close-btn" onclick="closeDetailModal()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div style="padding: 20px 24px;">
                <div style="margin-bottom: 10px">
                    <p>Nama Pelanggan</p>
                    <h3 id="detailName">-</h3>
                </div>

                <div style="margin-bottom: 10px">
                    <p>Jenis Pelanggan</p>
                    <h3 id="detailType">-</h3>
                </div>

                <div style="margin-bottom: 10px">
                    <p>Nomor Telepon</p>
                    <h3 id="detailPhone">-</h3>
                </div>

                <div style="margin-bottom: 10px">
                    <p>Email</p>
                    <h3 id="detailEmail">-</h3>
                </div>

                <div style="margin-bottom: 10px">
                    <p>Cabang</p>
                    <h3 id="detailCabang">-</h3>
                </div>

                <div style="margin-bottom: 10px">
                    <p>Status</p>
                    <h3 id="detailStatus">-</h3>
                </div>

                <div style="margin-bottom: 10px">
                    <p>Alamat</p>
                    <h3 id="detailAddress">-</h3>
                </div>

            </div>
        </div>
     </div>

    <!-- MODAL EDIT PELANGGAN -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <div class="modal-header">
                <div>
                    <h2>Edit Pelanggan</h2>
                    <p>Perbarui informasi pelanggan.</p>
                </div>

                <button type="button" class="close-btn" onclick="closeEditModal()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="edit_customer">
                <input type="hidden" name="customer_id" id="editCustomerId">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Nama Pelanggan<span>*</span></label>
                        <input type="text" name="name" id="editName" placeholder="Budi Santoso" required>
                    </div>

                    <div class="form-group">
                        <label>Jenis Pelanggan<span>*</span></label>
                        <select name="customer_type" id="editCustomerType" required>
                            <option value="">Pilih jenis pelanggan</option>
                            <option value="Penjualan">Penjualan</option>
                            <option value="Produksi">Produksi</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Nomor Telepon<span>*</span></label>
                        <input type="text" name="phone" id="editPhone" placeholder="08xxxxxxxxxx" required>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="editEmail" placeholder="email@contoh.com">
                    </div>

                    <div class="form-group">
                        <label>Cabang<span>*</span></label>
                        <select name="cabang_id" id="editCabangId" required>
                            <option value="">Pilih Cabang</option>

                            <?php foreach ($cabangs as $cabang): ?>
                                <option value="<?= (int)$cabang['id'] ?>">
                                    <?= htmlspecialchars($cabang['name']) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="form-group">
                        <label>Status<span>*</span></label>
                        <select name="status" id="editStatus" required>
                            <option value="active">Aktif</option>
                            <option value="inactive">Tidak Aktif</option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label>Alamat</label>
                        <textarea rows="3" name="address" id="editAddress" placeholder="Masukkan alamat lengkap pelanggan"></textarea>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-primary" onclick="closeEditModal()">
                        <i class="bi bi-x"></i>
                        Batal
                    </button>
                            
                    <button type="submit" class="btn-primary">
                        <i class="bi bi-check-lg"></i>
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DELETE PELANGGAN -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">

            <div class="modal-header">
                <div>
                    <h2>Pelanggan Tidak Dapat Dihapus</h2>
                    <p>Data pelanggan memiliki transaksi.</p>
                </div>

                <button
                    type="button"
                    class="close-btn"
                    onclick="closeDeleteModal()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div style="padding: 20px 24px;">

                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    Pelanggan tidak dapat dihapus karena sudah memiliki transaksi.
                    Silakan ubah status pelanggan menjadi Tidak Aktif.
                </div>

            </div>

            <div class="modal-footer" style="margin: 10px">
                <button type="button" class="btn-primary" onclick="closeDeleteModal()">
                    <i class="bi bi-check-lg"></i>
                    Mengerti
                </button>

            </div>

        </div>
    </div
</main>

<script>
function openModal() {
    document.getElementById("customerModal").classList.add("show");
}

function closeModal() {
    const modal = document.getElementById('customerModal');
    const form = modal.querySelector('form');

    // Tutup modal
    modal.classList.remove('show');

    // Kosongkan semua input
    form.reset();
    document.getElementById("customerModal").classList.remove("show");
}


function saveCustomer(event) {
    event.preventDefault();
    alert("Data pelanggan berhasil disimpan (simulasi).");
    closeModal();
}

// JS DETAIL PELANGGAN
async function showDetail(customerId) {

    try {

        const response = await fetch(
            `index.php?detail=${customerId}`
        );

        const result = await response.json();
        if (!result.success) {
            alert(result.message);
            return;
        }

        const customer = result.data;

        document.getElementById('detailName').textContent       = customer.name || '-';
        document.getElementById('detailType').textContent       = customer.customer_type || '-';
        document.getElementById('detailPhone').textContent      = customer.phone || '-';
        document.getElementById('detailEmail').textContent      = customer.email || '-';
        document.getElementById('detailCabang').textContent     = customer.cabang_name || '-';
        document.getElementById('detailAddress').textContent    = customer.address || '-';
        document.getElementById('detailStatus').textContent     = customer.status === 'active' ? 'Aktif': 'Tidak Aktif';

        document.getElementById('detailModal').classList.add('show');

    } catch (error) {
        console.error(error);
        alert('Gagal mengambil data pelanggan.');
    }
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.remove('show');
}

// JS EDIT PELANGGAN
async function showEdit(customerId) {
    try {
        const response = await fetch(
            'index.php?detail=' + customerId
        );

        if (!response.ok) {
            throw new Error('HTTP Error: ' + response.status);
        }

        const result = await response.json();
        if (!result.success) {
            alert(result.message);
            return;
        }

        const customer = result.data;
        document.getElementById('editCustomerId').value     = customer.id || '';
        document.getElementById('editName').value           = customer.name || '';
        document.getElementById('editCustomerType').value   = customer.customer_type || '';
        document.getElementById('editPhone').value          = customer.phone || '';
        document.getElementById('editEmail').value          = customer.email || '';
        document.getElementById('editCabangId').value       = customer.cabang_id || '';
        document.getElementById('editStatus').value         = customer.status || 'active';
        document.getElementById('editAddress').value        = customer.address || '';

        document.getElementById('editModal').classList.add('show');

    } catch (error) {
        console.error('Edit Error:', error);
        alert('Gagal mengambil data pelanggan.');
    }
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('show');
}


// JS DELETE PELANGGAN
function deleteCustomer(customerId, customerName) {

    const confirmation = confirm(
        'Apakah Anda yakin ingin menghapus pelanggan "' +
        customerName +
        '"?'
    );

    if (!confirmation) {
        return;
    }

    const form = document.createElement('form');

    form.method = 'POST';
    form.action = 'index.php';

    form.innerHTML = `
        <input type="hidden" name="action" value="delete_customer">
        <input type="hidden" name="customer_id" value="${customerId}">
    `;

    document.body.appendChild(form);
    form.submit();

}

// JS DELETE TERTENTU
function showDeleteModal() {
    document.getElementById('deleteModal').classList.add('show');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
}


function filterCustomer() {

    const type      = document.getElementById("typeFilter").value;
    const status    = document.getElementById("statusFilter").value;
    const rows      = document.querySelectorAll("#customerTable tbody tr");

    rows.forEach(row => {

        const rowText = row.innerText;
        const typeMatch = !type || rowText.includes(type);
        const statusMatch = !status || rowText.includes(status);
        row.style.display = typeMatch && statusMatch ? "" : "none";

    });

}

document.addEventListener('DOMContentLoaded', function () {

    const params = new URLSearchParams(window.location.search);

    if (params.get('error') === 'customer_has_orders') {

        showDeleteModal();

        // Hapus parameter error dari URL
        window.history.replaceState(
            {},
            document.title,
            window.location.pathname
        );
    }

});


// SEARCH
function applyCustomerFilter() {

    const searchInput = document.getElementById('customerSearch');
    const typeFilter = document.getElementById('customerTypeFilter');
    const statusFilter = document.getElementById('customerStatusFilter');

    const search = searchInput ? searchInput.value.trim() : '';
    const type = typeFilter ? typeFilter.value : '';
    const status = statusFilter ? statusFilter.value : '';

    const params = new URLSearchParams();

    if (search !== '') {
        params.set('search', search);
    }

    if (type !== '') {
        params.set('type', type);
    }

    if (status !== '') {
        params.set('status', status);
    }

    window.location.href = 'index.php?' + params.toString();
}


// Tekan Enter pada Search
const customerSearch = document.getElementById('customerSearch');

if (customerSearch) {
    customerSearch.addEventListener('keydown', function (e) {

        if (e.key === 'Enter') {
            applyCustomerFilter();
        }

    });
}

// FILTER
function applyCustomerFilter() {

    const typeFilter = document.getElementById('customerTypeFilter');
    const statusFilter = document.getElementById('customerStatusFilter');
    const searchInput = document.getElementById('customerSearch');

    const type = typeFilter ? typeFilter.value : '';
    const status = statusFilter ? statusFilter.value : '';
    const search = searchInput ? searchInput.value.trim() : '';

    const params = new URLSearchParams();

    if (search !== '') {
        params.set('search', search);
    }

    if (type !== '') {
        params.set('type', type);
    }

    if (status !== '') {
        params.set('status', status);
    }

    window.location.href = 'index.php?' + params.toString();
}
</script>

</body>
</html>
