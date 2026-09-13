<?php
// =====================================================
// VENDORCAT ADMIN - MENU PRODUK
// CRUD DATABASE
// =====================================================

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
        // Jangan hentikan halaman Produk jika tabel/settings bermasalah.
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

// =====================================================
// HELPER
// =====================================================
function redirectProduct(string $type, string $code): never
{
    header('Location: index.php?' . $type . '=' . urlencode($code));
    exit;
}

function money(int|float|null $value): string
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function parseMoney(mixed $value): float
{
    $value = trim((string)$value);

    if ($value === '') {
        return 0;
    }

    // Accept both 100000 and 100.000 / Rp 100.000.
    $value = preg_replace('/[^0-9]/', '', $value);

    return $value === '' ? 0 : (float)$value;
}

// =====================================================
// DATA KATEGORI
// =====================================================
$stmtCategories = $pdo->query("
    SELECT id, name
    FROM product_categories
    ORDER BY name ASC
");
$categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

// =====================================================
// GET PRODUCT - AJAX DETAIL / EDIT
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_product') {
    header('Content-Type: application/json; charset=utf-8');

    $productId = (int)($_GET['id'] ?? 0);

    if ($productId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'ID produk tidak valid.'
        ]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.category_id,
                pc.name AS category_name,
                p.code,
                p.name,
                p.brand,
                p.unit,
                p.purchase_price,
                p.selling_price,
                p.status,
                (
                    SELECT COUNT(*)
                    FROM purchase_details pd
                    WHERE pd.product_id = p.id
                ) AS total_purchase_details,
                (
                    SELECT COUNT(*)
                    FROM order_details od
                    WHERE od.product_id = p.id
                ) AS total_order_details,
                (
                    SELECT COUNT(*)
                    FROM stock_transfer_details std
                    WHERE std.product_id = p.id
                ) AS total_transfer_details
            FROM products p
            LEFT JOIN product_categories pc
                ON pc.id = p.category_id
            WHERE p.id = :id
            LIMIT 1
        ");

        $stmt->execute([':id' => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            echo json_encode([
                'success' => false,
                'message' => 'Data produk tidak ditemukan.'
            ]);
            exit;
        }

        $product['has_transaction'] =
            ((int)$product['total_purchase_details'] > 0 ||
             (int)$product['total_order_details'] > 0 ||
             (int)$product['total_transfer_details'] > 0);

        echo json_encode([
            'success' => true,
            'data' => $product
        ]);
        exit;

    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Gagal mengambil data produk.'
        ]);
        exit;
    }
}

// =====================================================
// FORM STATE
// =====================================================
$message = '';
$messageType = '';
$showProductModal = false;

$formData = [
    'category_id' => '',
    'code' => '',
    'name' => '',
    'brand' => '',
    'unit' => '',
    'purchase_price' => '',
    'selling_price' => '',
    'status' => 'active'
];

// =====================================================
// TAMBAH PRODUK
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_product') {

    $categoryId   = (int)($_POST['category_id'] ?? 0);
    $code         = trim($_POST['code'] ?? '');
    $name         = trim($_POST['name'] ?? '');
    $brand        = trim($_POST['brand'] ?? '');
    $unit         = trim($_POST['unit'] ?? '');
    $purchasePrice = parseMoney($_POST['purchase_price'] ?? 0);
    $sellingPrice  = parseMoney($_POST['selling_price'] ?? 0);

    $formData = [
        'category_id' => $categoryId,
        'code' => $code,
        'name' => $name,
        'brand' => $brand,
        'unit' => $unit,
        'purchase_price' => $purchasePrice,
        'selling_price' => $sellingPrice,
        'status' => 'active'
    ];

    $showProductModal = true;

    try {
        if ($categoryId <= 0) {
            throw new Exception('Kategori produk wajib dipilih.');
        }

        if ($code === '') {
            throw new Exception('Kode produk wajib diisi.');
        }

        if ($name === '') {
            throw new Exception('Nama produk wajib diisi.');
        }

        if ($brand === '') {
            throw new Exception('Brand produk wajib diisi.');
        }

        if ($unit === '') {
            throw new Exception('Satuan produk wajib diisi.');
        }

        if ($sellingPrice < 0 || $purchasePrice < 0) {
            throw new Exception('Harga tidak boleh bernilai negatif.');
        }

        // Pastikan kategori benar-benar ada.
        $stmtCategory = $pdo->prepare("
            SELECT id
            FROM product_categories
            WHERE id = :id
            LIMIT 1
        ");
        $stmtCategory->execute([':id' => $categoryId]);

        if (!$stmtCategory->fetch()) {
            throw new Exception('Kategori produk tidak ditemukan.');
        }

        // Kode produk harus unik.
        $stmtCode = $pdo->prepare("
            SELECT id
            FROM products
            WHERE code = :code
            LIMIT 1
        ");
        $stmtCode->execute([':code' => $code]);

        if ($stmtCode->fetch()) {
            throw new Exception('Kode produk sudah digunakan.');
        }

        $stmtInsert = $pdo->prepare("
            INSERT INTO products (
                category_id,
                code,
                name,
                brand,
                unit,
                purchase_price,
                selling_price,
                status
            ) VALUES (
                :category_id,
                :code,
                :name,
                :brand,
                :unit,
                :purchase_price,
                :selling_price,
                'active'
            )
        ");

        $stmtInsert->execute([
            ':category_id' => $categoryId,
            ':code' => $code,
            ':name' => $name,
            ':brand' => $brand,
            ':unit' => $unit,
            ':purchase_price' => $purchasePrice,
            ':selling_price' => $sellingPrice
        ]);

        redirectProduct('success', 'product_added');

    } catch (PDOException $e) {
        $message = 'Gagal menyimpan produk ke database.';
        $messageType = 'error';

    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// =====================================================
// UPDATE PRODUK
// Aturan:
// - Belum transaksi: semua field boleh diperbarui.
// - Sudah transaksi: hanya status yang boleh diperbarui.
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_product') {

    $productId = (int)($_POST['product_id'] ?? 0);
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $purchasePrice = parseMoney($_POST['purchase_price'] ?? 0);
    $sellingPrice = parseMoney($_POST['selling_price'] ?? 0);
    $status = trim($_POST['status'] ?? 'active');

    try {
        if ($productId <= 0) {
            throw new Exception('ID produk tidak valid.');
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new Exception('Status produk tidak valid.');
        }

        // Ambil kondisi transaksi produk.
        $stmtTransaction = $pdo->prepare("
            SELECT
                (
                    SELECT COUNT(*)
                    FROM purchase_details
                    WHERE product_id = :product_id_1
                ) AS purchase_count,
                (
                    SELECT COUNT(*)
                    FROM order_details
                    WHERE product_id = :product_id_2
                ) AS order_count,
                (
                    SELECT COUNT(*)
                    FROM stock_transfer_details
                    WHERE product_id = :product_id_3
                ) AS transfer_count
        ");

        $stmtTransaction->execute([
            ':product_id_1' => $productId,
            ':product_id_2' => $productId,
            ':product_id_3' => $productId
        ]);

        $transaction = $stmtTransaction->fetch(PDO::FETCH_ASSOC);

        $hasTransaction =
            ((int)$transaction['purchase_count'] > 0 ||
             (int)$transaction['order_count'] > 0 ||
             (int)$transaction['transfer_count'] > 0);

        if ($hasTransaction) {

            // Produk yang sudah mempunyai histori transaksi:
            // hanya status yang boleh diubah.
            $stmtUpdate = $pdo->prepare("
                UPDATE products
                SET status = :status
                WHERE id = :product_id
            ");

            $stmtUpdate->execute([
                ':status' => $status,
                ':product_id' => $productId
            ]);

        } else {

            if ($categoryId <= 0) {
                throw new Exception('Kategori produk wajib dipilih.');
            }

            if ($code === '') {
                throw new Exception('Kode produk wajib diisi.');
            }

            if ($name === '') {
                throw new Exception('Nama produk wajib diisi.');
            }

            if ($brand === '') {
                throw new Exception('Brand produk wajib diisi.');
            }

            if ($unit === '') {
                throw new Exception('Satuan produk wajib diisi.');
            }

            if ($purchasePrice < 0 || $sellingPrice < 0) {
                throw new Exception('Harga tidak boleh bernilai negatif.');
            }

            $stmtCategory = $pdo->prepare("
                SELECT id
                FROM product_categories
                WHERE id = :id
                LIMIT 1
            ");
            $stmtCategory->execute([':id' => $categoryId]);

            if (!$stmtCategory->fetch()) {
                throw new Exception('Kategori produk tidak ditemukan.');
            }

            // Kode unik, kecuali produk yang sedang diedit.
            $stmtCode = $pdo->prepare("
                SELECT id
                FROM products
                WHERE code = :code
                  AND id <> :product_id
                LIMIT 1
            ");

            $stmtCode->execute([
                ':code' => $code,
                ':product_id' => $productId
            ]);

            if ($stmtCode->fetch()) {
                throw new Exception('Kode produk sudah digunakan produk lain.');
            }

            $stmtUpdate = $pdo->prepare("
                UPDATE products SET
                    category_id = :category_id,
                    code = :code,
                    name = :name,
                    brand = :brand,
                    unit = :unit,
                    purchase_price = :purchase_price,
                    selling_price = :selling_price,
                    status = :status
                WHERE id = :product_id
            ");

            $stmtUpdate->execute([
                ':category_id' => $categoryId,
                ':code' => $code,
                ':name' => $name,
                ':brand' => $brand,
                ':unit' => $unit,
                ':purchase_price' => $purchasePrice,
                ':selling_price' => $sellingPrice,
                ':status' => $status,
                ':product_id' => $productId
            ]);
        }

        redirectProduct('success', 'product_updated');

    } catch (PDOException $e) {
        $message = 'Gagal memperbarui produk ke database.';
        $messageType = 'error';
        $showProductModal = true;

    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
        $showProductModal = true;
    }
}

// =====================================================
// HAPUS PRODUK
// Hanya produk tanpa histori transaksi yang boleh dihapus.
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_product') {

    $productId = (int)($_POST['product_id'] ?? 0);

    if ($productId <= 0) {
        redirectProduct('error', 'invalid_product');
    }

    try {
        $stmtProduct = $pdo->prepare("
            SELECT id, code, name
            FROM products
            WHERE id = :id
            LIMIT 1
        ");
        $stmtProduct->execute([':id' => $productId]);

        $product = $stmtProduct->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            redirectProduct('error', 'product_not_found');
        }

        // Cek semua tabel detail transaksi yang menggunakan product_id.
        $stmtTransaction = $pdo->prepare("
            SELECT
                (
                    SELECT COUNT(*)
                    FROM purchase_details
                    WHERE product_id = :product_id_1
                ) AS purchase_count,
                (
                    SELECT COUNT(*)
                    FROM order_details
                    WHERE product_id = :product_id_2
                ) AS order_count,
                (
                    SELECT COUNT(*)
                    FROM stock_transfer_details
                    WHERE product_id = :product_id_3
                ) AS transfer_count
        ");

        $stmtTransaction->execute([
            ':product_id_1' => $productId,
            ':product_id_2' => $productId,
            ':product_id_3' => $productId
        ]);

        $transaction = $stmtTransaction->fetch(PDO::FETCH_ASSOC);

        $hasTransaction =
            ((int)$transaction['purchase_count'] > 0 ||
             (int)$transaction['order_count'] > 0 ||
             (int)$transaction['transfer_count'] > 0);

        if ($hasTransaction) {
            redirectProduct('error', 'product_has_transactions');
        }

        // Hapus stok cabang terlebih dahulu jika ada.
        $stmtStock = $pdo->prepare("
            DELETE FROM branch_stocks
            WHERE product_id = :product_id
        ");
        $stmtStock->execute([':product_id' => $productId]);

        $stmtDelete = $pdo->prepare("
            DELETE FROM products
            WHERE id = :id
        ");
        $stmtDelete->execute([':id' => $productId]);

        redirectProduct('success', 'product_deleted');

    } catch (PDOException $e) {
        redirectProduct('error', 'product_delete_failed');

    } catch (Exception $e) {
        redirectProduct('error', 'product_delete_failed');
    }
}

// =====================================================
// NOTIFIKASI
// =====================================================
$notification = '';
$notificationType = '';

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

switch ($success) {
    case 'product_added':
        $notification = 'Produk berhasil ditambahkan.';
        $notificationType = 'success';
        break;

    case 'product_updated':
        $notification = 'Produk berhasil diperbarui.';
        $notificationType = 'success';
        break;

    case 'product_deleted':
        $notification = 'Produk berhasil dihapus.';
        $notificationType = 'success';
        break;
}

switch ($error) {
    case 'invalid_product':
        $notification = 'ID produk tidak valid.';
        $notificationType = 'error';
        break;

    case 'product_not_found':
        $notification = 'Data produk tidak ditemukan.';
        $notificationType = 'error';
        break;

    case 'product_has_transactions':
        $notification = 'Produk tidak dapat dihapus karena sudah memiliki riwayat transaksi. Anda dapat mengubah statusnya menjadi Tidak Aktif.';
        $notificationType = 'error';
        break;

    case 'product_delete_failed':
        $notification = 'Produk gagal dihapus. Silakan periksa data transaksi atau coba kembali.';
        $notificationType = 'error';
        break;
}

// =====================================================
// SEARCH + FILTER
// =====================================================
$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$brandFilter = trim($_GET['brand'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "
        (
            p.code LIKE :search_code
            OR p.name LIKE :search_name
            OR p.brand LIKE :search_brand
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[':search_code'] = $searchValue;
    $params[':search_name'] = $searchValue;
    $params[':search_brand'] = $searchValue;
}

if ($categoryFilter !== '') {
    $where[] = 'p.category_id = :category_id';
    $params[':category_id'] = (int)$categoryFilter;
}

if ($brandFilter !== '') {
    $where[] = 'p.brand = :brand';
    $params[':brand'] = $brandFilter;
}

if ($statusFilter !== '') {
    $where[] = 'p.status = :status';
    $params[':status'] = $statusFilter;
}

$whereSql = !empty($where)
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

// =====================================================
// STATISTIK
// =====================================================
$totalProducts = (int)$pdo->query("
    SELECT COUNT(*)
    FROM products
")->fetchColumn();

$totalActive = (int)$pdo->query("
    SELECT COUNT(*)
    FROM products
    WHERE status = 'active'
")->fetchColumn();

$totalInactive = (int)$pdo->query("
    SELECT COUNT(*)
    FROM products
    WHERE status = 'inactive'
")->fetchColumn();

$totalCategories = (int)$pdo->query("
    SELECT COUNT(*)
    FROM product_categories
")->fetchColumn();

// =====================================================
// BRAND UNTUK FILTER
// =====================================================
$stmtBrands = $pdo->query("
    SELECT DISTINCT brand
    FROM products
    WHERE brand IS NOT NULL
      AND brand <> ''
    ORDER BY brand ASC
");
$brands = $stmtBrands->fetchAll(PDO::FETCH_ASSOC);

// =====================================================
// PAGINATION
// =====================================================
$perPage = 5;

$page = max(1, (int)($_GET['page'] ?? 1));

$stmtTotal = $pdo->prepare("
    SELECT COUNT(*)
    FROM products p
    $whereSql
");
$stmtTotal->execute($params);

$totalFiltered = (int)$stmtTotal->fetchColumn();

$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;

// =====================================================
// DATA PRODUK
// =====================================================
$stmtProducts = $pdo->prepare("
    SELECT
        p.id,
        p.category_id,
        pc.name AS category_name,
        p.code,
        p.name,
        p.brand,
        p.unit,
        p.purchase_price,
        p.selling_price,
        p.status,
        (
            SELECT COUNT(*)
            FROM purchase_details pd
            WHERE pd.product_id = p.id
        ) AS total_purchase_details,
        (
            SELECT COUNT(*)
            FROM order_details od
            WHERE od.product_id = p.id
        ) AS total_order_details,
        (
            SELECT COUNT(*)
            FROM stock_transfer_details std
            WHERE std.product_id = p.id
        ) AS total_transfer_details
    FROM products p
    LEFT JOIN product_categories pc
        ON pc.id = p.category_id
    $whereSql
    ORDER BY p.id DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $stmtProducts->bindValue(
        $key,
        $value,
        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}

$stmtProducts->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmtProducts->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtProducts->execute();

$products = $stmtProducts->fetchAll(PDO::FETCH_ASSOC);

$startItem = $totalFiltered > 0 ? $offset + 1 : 0;
$endItem = min($offset + $perPage, $totalFiltered);

// Parameter pagination.
$paginationParams = [];

if ($search !== '') {
    $paginationParams['search'] = $search;
}

if ($categoryFilter !== '') {
    $paginationParams['category'] = $categoryFilter;
}

if ($brandFilter !== '') {
    $paginationParams['brand'] = $brandFilter;
}

if ($statusFilter !== '') {
    $paginationParams['status'] = $statusFilter;
}
?> 
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c1a2d">

    <title>Produk | <?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <!-- <link rel="stylesheet" href="sidebar.css"> -->
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
            <a href="./" class="menu-item active" >
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

    <div class="page-header">
        <div>
            <div class="breadcrumb">
                Dashboard
                <i class="bi bi-chevron-right"></i>
                Master Data
                <i class="bi bi-chevron-right"></i>
                Produk
            </div>

            <h1>Produk / Cat</h1>
            <p>Kelola master produk, cat, dan perlengkapan otomotif.</p>
        </div>

        <button type="button" class="btn-primary" onclick="openAddModal()">
            <i class="bi bi-plus-lg"></i>
            Tambah Produk
        </button>
    </div>


    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue">
                <i class="bi bi-box-seam"></i>
            </div>
            <div>
                <span>Total Produk</span>
                <strong><?= number_format($totalProducts) ?></strong>
                <small>Terdaftar di sistem</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon green">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <span>Produk Aktif</span>
                <strong><?= number_format($totalActive) ?></strong>
                <small>Masih aktif</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon orange">
                <i class="bi bi-grid"></i>
            </div>
            <div>
                <span>Kategori Produk</span>
                <strong><?= number_format($totalCategories) ?></strong>
                <small>Master kategori</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon red">
                <i class="bi bi-x-circle"></i>
            </div>
            <div>
                <span>Produk Tidak Aktif</span>
                <strong><?= number_format($totalInactive) ?></strong>
                <small>Tidak digunakan</small>
            </div>
        </div>
    </div>

    <div class="content-card">

        <div class="table-toolbar">
            <div class="toolbar-left">
                <form method="GET" action="index.php" style="display:flex; align-items:center; gap:10px;">

                    <div class="searc-box" style="position:relative; width:330px; height:40px;">

                        <i class="bi bi-search"
                           style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#1f2937; font-size:18px; line-height:1; pointer-events:none; z-index:10;"></i>

                        <input
                            type="text"
                            name="search"
                            value="<?= htmlspecialchars($search) ?>"
                            placeholder="Cari kode, nama, atau brand..."
                            style="display:block; width:100%; height:40px; padding:0 12px 0 40px; box-sizing:border-box; border:1px solid #9ca3af; border-radius:6px; outline:none; background:#fff; color:#111827; font-size:14px; font-family:inherit;"
                        >

                        <?php if ($categoryFilter !== ''): ?>
                            <input type="hidden" name="category" value="<?= htmlspecialchars($categoryFilter) ?>">
                        <?php endif; ?>

                        <?php if ($brandFilter !== ''): ?>
                            <input type="hidden" name="brand" value="<?= htmlspecialchars($brandFilter) ?>">
                        <?php endif; ?>

                        <?php if ($statusFilter !== ''): ?>
                            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                        <?php endif; ?>
                    </div>

                    <button type="submit"
                            class="btn-secondary"
                            style="height:40px;">
                        Cari
                    </button>
                </form>
            </div>

            <div class="toolbar-right">

                <select id="categoryFilter" onchange="filterProduct()">
                    <option value="">Semua Kategori</option>
                    <?php foreach ($categories as $category): ?>
                        <option
                            value="<?= (int)$category['id'] ?>"
                            <?= ((string)$categoryFilter === (string)$category['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="brandFilter" onchange="filterProduct()">
                    <option value="">Semua Brand</option>
                    <?php foreach ($brands as $brand): ?>
                        <option
                            value="<?= htmlspecialchars($brand['brand']) ?>"
                            <?= $brandFilter === $brand['brand'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($brand['brand']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="statusFilter" onchange="filterProduct()">
                    <option value="">Semua Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Tidak Aktif</option>
                </select>

            </div>
        </div>

        <div class="table-wrapper">
            <table id="productTable">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Produk</th>
                        <th>Kategori</th>
                        <th>Brand</th>
                        <th>Satuan</th>
                        <th>Harga Jual</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!empty($products)): ?>

                    <?php foreach ($products as $product): ?>

                        <?php
                        $hasTransaction =
                            ((int)$product['total_purchase_details'] > 0 ||
                             (int)$product['total_order_details'] > 0 ||
                             (int)$product['total_transfer_details'] > 0);
                        ?>

                        <tr>

                            <td>
                                <span class="product-code">
                                    <?= htmlspecialchars($product['code']) ?>
                                </span>
                            </td>

                            <td>
                                <div class="product-info">
                                    <div class="product-icon">
                                        <i class="bi bi-droplet"></i>
                                    </div>
                                    <div>
                                        <strong>
                                            <?= htmlspecialchars($product['name']) ?>
                                        </strong>
                                        <small>
                                            <?= htmlspecialchars($product['brand']) ?>
                                        </small>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <span class="badge badge-blue">
                                    <?= htmlspecialchars($product['category_name'] ?? '-') ?>
                                </span>
                            </td>

                            <td>
                                <?= htmlspecialchars($product['brand']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($product['unit']) ?>
                            </td>

                            <td>
                                <strong>
                                    <?= money($product['selling_price']) ?>
                                </strong>
                            </td>

                            <td>
                                <?php if (($product['status'] ?? 'active') === 'active'): ?>
                                    <span class="status active">Aktif</span>
                                <?php else: ?>
                                    <span class="status inactive">Tidak Aktif</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="action-buttons">

                                    <button
                                        type="button"
                                        class="btn-icon"
                                        title="Detail"
                                        onclick="showDetail(<?= (int)$product['id'] ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button>

                                    <button
                                        type="button"
                                        class="btn-icon"
                                        title="<?= $hasTransaction ? 'Edit Status' : 'Edit Produk' ?>"
                                        onclick="editProduct(<?= (int)$product['id'] ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>

                                    <?php if ($hasTransaction): ?>

                                        <button
                                            type="button"
                                            class="btn-icon danger"
                                            title="Tidak dapat dihapus: sudah memiliki transaksi"
                                            disabled
                                            style="opacity:.45; cursor:not-allowed;">
                                            <i class="bi bi-lock-fill"></i>
                                        </button>

                                    <?php else: ?>

                                        <button
                                            type="button"
                                            class="btn-icon danger"
                                            title="Hapus"
                                            onclick="deleteProduct(<?= (int)$product['id'] ?>, '<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>

                                    <?php endif; ?>

                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" style="text-align:center; padding:30px;">
                            Belum ada data produk.
                        </td>
                    </tr>

                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination">

            <span>
                <?= $startItem ?>–<?= $endItem ?>
                dari <?= $totalFiltered ?> produk
            </span>

            <div class="pagination-buttons">

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

                    <?php if ($i === $page): ?>
                        <button class="active-page"><?= $i ?></button>
                    <?php else: ?>
                        <a href="index.php?<?= http_build_query($pageParams) ?>">
                            <?= $i ?>
                        </a>
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

                    <a href="index.php?<?= http_build_query($lastParams) ?>">
                        <?= $totalPages ?>
                    </a>

                <?php endif; ?>

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

<!-- =====================================================
     MODAL TAMBAH / EDIT PRODUK
====================================================== -->
<div class="modal-overlay" id="productModal">

    <div class="modal">

        <div class="modal-header">
            <div>
                <h2 id="productModalTitle">Tambah Produk</h2>
                <p id="productModalDescription">
                    Masukkan informasi produk baru.
                </p>
            </div>

            <button type="button"
                    class="close-btn"
                    onclick="closeModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form method="POST" id="productForm">

            <?php if ($message !== '' && $messageType === 'error'): ?>
                <div class="form-alert error">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div>
                        <strong>Data tidak dapat disimpan</strong>
                        <span><?= htmlspecialchars($message) ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <input type="hidden"
                   name="action"
                   id="productAction"
                   value="add_product">

            <input type="hidden"
                   name="product_id"
                   id="product_id"
                   value="">

            <div class="form-grid">

                <div class="form-group">
                    <label>Kode Produk <span>*</span></label>
                    <input
                        type="text"
                        name="code"
                        id="productCode"
                        value="<?= htmlspecialchars($formData['code']) ?>"
                        placeholder="Contoh: CAT-001"
                        required>
                </div>

                <div class="form-group">
                    <label>Nama Produk <span>*</span></label>
                    <input
                        type="text"
                        name="name"
                        id="productName"
                        value="<?= htmlspecialchars($formData['name']) ?>"
                        placeholder="Contoh: Basecoat White 1L"
                        required>
                </div>

                <div class="form-group">
                    <label>Kategori <span>*</span></label>
                    <select
                        name="category_id"
                        id="productCategory"
                        required>
                        <option value="">Pilih kategori</option>

                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?= (int)$category['id'] ?>"
                                <?= ((int)$formData['category_id'] === (int)$category['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Brand <span>*</span></label>
                    <input
                        type="text"
                        name="brand"
                        id="productBrand"
                        value="<?= htmlspecialchars($formData['brand']) ?>"
                        placeholder="Contoh: Nippon"
                        required>
                </div>

                <div class="form-group">
                    <label>Satuan <span>*</span></label>
                    <input
                        type="text"
                        name="unit"
                        id="productUnit"
                        value="<?= htmlspecialchars($formData['unit']) ?>"
                        placeholder="Contoh: Liter"
                        required>
                </div>

                <div class="form-group">
                    <label>Harga Beli</label>
                    <div class="input-money">
                        <span>Rp</span>
                        <input
                            type="text"
                            name="purchase_price"
                            id="purchasePrice"
                            inputmode="numeric"
                            autocomplete="off"
                            value="<?= htmlspecialchars($formData['purchase_price'] !== '' ? number_format((float)$formData['purchase_price'], 0, ',', '.') : '') ?>"
                            placeholder="0">
                    </div>
                </div>

                <div class="form-group">
                    <label>Harga Jual <span>*</span></label>
                    <div class="input-money">
                        <span>Rp</span>
                        <input
                            type="text"
                            name="selling_price"
                            id="sellingPrice"
                            inputmode="numeric"
                            autocomplete="off"
                            value="<?= htmlspecialchars($formData['selling_price'] !== '' ? number_format((float)$formData['selling_price'], 0, ',', '.') : '') ?>"
                            placeholder="0"
                            required>
                    </div>
                </div>

                <div class="form-group" id="statusGroup" style="display:none;">
                    <label>Status <span>*</span></label>
                    <select name="status" id="productStatus">
                        <option value="active">Aktif</option>
                        <option value="inactive">Tidak Aktif</option>
                    </select>
                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn-secondary"
                    onclick="closeModal()">
                    Batal
                </button>

                <button
                    type="submit"
                    class="btn-primary"
                    id="productSubmitButton">
                    <i class="bi bi-check-lg"></i>
                    <span id="productSubmitText">Simpan Produk</span>
                </button>

            </div>

        </form>
    </div>
</div>

<!-- =====================================================
     MODAL DETAIL
====================================================== -->
<div class="modal-overlay" id="detailModal">

    <div class="modal" style="max-width:600px;">

        <div class="modal-header">
            <div>
                <h2>Detail Produk</h2>
                <p>Informasi produk dan status transaksi.</p>
            </div>

            <button type="button"
                    class="close-btn"
                    onclick="closeDetailModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div style="padding:24px;" id="detailContent">
            Memuat data...
        </div>

        <div class="modal-footer">
            <button
                type="button"
                class="btn-secondary"
                onclick="closeDetailModal()">
                Tutup
            </button>
        </div>

    </div>
</div>

<!-- =====================================================
     MODAL KONFIRMASI HAPUS
====================================================== -->
<div class="modal-overlay" id="deleteConfirmModal">

    <div class="modal" style="max-width:430px;">

        <div class="modal-header">
            <div>
                <h2>Konfirmasi Hapus</h2>
                <p>Periksa kembali tindakan yang akan dilakukan.</p>
            </div>

            <button
                type="button"
                class="close-btn"
                onclick="closeDeleteModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div style="padding:24px; text-align:center;">

            <div style="font-size:46px; margin-bottom:12px;">
                <i class="bi bi-exclamation-triangle"></i>
            </div>

            <p style="margin:0; line-height:1.6;">
                Apakah Anda yakin ingin menghapus produk
                <strong id="deleteProductName"></strong>?
            </p>

            <p style="margin-top:8px; color:#6b7280; font-size:13px;">
                Produk yang sudah memiliki transaksi tidak dapat dihapus.
            </p>

        </div>

        <div class="modal-footer">

            <button
                type="button"
                class="btn-secondary"
                onclick="closeDeleteModal()">
                Batal
            </button>

            <button
                type="button"
                class="btn-primary"
                onclick="confirmDeleteProduct()">
                <i class="bi bi-trash"></i>
                Hapus
            </button>

        </div>

    </div>
</div>

<form method="POST"
      id="deleteProductForm"
      style="display:none;">
    <input type="hidden" name="action" value="delete_product">
    <input type="hidden" name="product_id" id="delete_product_id">
</form>

<script>

function formatPriceInput(input) {

    if (!input) {
        return;
    }

    let value = String(input.value || '').replace(/\D/g, '');

    if (value === '') {
        input.value = '';
        return;
    }

    input.value = new Intl.NumberFormat('id-ID').format(Number(value));
}

function preparePriceInputs() {

    const purchase = document.getElementById("purchasePrice");
    const selling = document.getElementById("sellingPrice");

    [purchase, selling].forEach(function(input) {

        if (!input) {
            return;
        }

        input.addEventListener("input", function() {
            formatPriceInput(this);
        });

        input.addEventListener("blur", function() {
            formatPriceInput(this);
        });
    });
}

// =====================================================
// MODAL
// =====================================================
function openAddModal() {

    resetProductForm();

    const modal = document.getElementById("productModal");

    if (modal) {
        modal.classList.add("show");
    }
}

function closeModal() {

    const modal = document.getElementById("productModal");

    if (modal) {
        modal.classList.remove("show");
    }

    resetProductForm();
}

function resetProductForm() {

    const form = document.getElementById("productForm");

    if (form) {
        form.reset();
    }

    document.getElementById("productAction").value = "add_product";
    document.getElementById("product_id").value = "";

    document.getElementById("productModalTitle").textContent =
        "Tambah Produk";

    document.getElementById("productModalDescription").textContent =
        "Masukkan informasi produk baru.";

    document.getElementById("productSubmitText").textContent =
        "Simpan Produk";

    document.getElementById("statusGroup").style.display = "none";

    setProductFieldsEditable(true);

    document.getElementById("productStatus").disabled = false;
    document.getElementById("productStatus").value = "active";
}

function setProductFieldsEditable(editable) {

    const ids = [
        "productCode",
        "productName",
        "productCategory",
        "productBrand",
        "productUnit",
        "purchasePrice",
        "sellingPrice"
    ];

    ids.forEach(function(id) {

        const element = document.getElementById(id);

        if (!element) {
            return;
        }

        if (element.tagName === "SELECT") {
            element.disabled = !editable;
        } else {
            element.readOnly = !editable;
        }
    });
}

// =====================================================
// EDIT PRODUK
// =====================================================
async function editProduct(id) {

    try {

        const response = await fetch(
            "index.php?action=get_product&id=" +
            encodeURIComponent(id)
        );

        const result = await response.json();

        if (!result.success) {
            alert(result.message || "Data produk tidak ditemukan.");
            return;
        }

        const product = result.data;

        document.getElementById("productAction").value =
            "update_product";

        document.getElementById("product_id").value =
            product.id;

        document.getElementById("productCode").value =
            product.code || "";

        document.getElementById("productName").value =
            product.name || "";

        document.getElementById("productCategory").value =
            product.category_id || "";

        document.getElementById("productBrand").value =
            product.brand || "";

        document.getElementById("productUnit").value =
            product.unit || "";

        document.getElementById("purchasePrice").value =
            product.purchase_price
                ? new Intl.NumberFormat("id-ID").format(Number(product.purchase_price))
                : "";

        document.getElementById("sellingPrice").value =
            product.selling_price
                ? new Intl.NumberFormat("id-ID").format(Number(product.selling_price))
                : "";

        document.getElementById("productStatus").value =
            product.status || "active";

        document.getElementById("statusGroup").style.display =
            "block";

        const hasTransaction =
            product.has_transaction === true ||
            Number(product.total_purchase_details || 0) > 0 ||
            Number(product.total_order_details || 0) > 0 ||
            Number(product.total_transfer_details || 0) > 0;

        if (hasTransaction) {

            // Produk sudah punya histori:
            // hanya status yang bisa diedit.
            setProductFieldsEditable(false);

            document.getElementById("productModalTitle").textContent =
                "Edit Status Produk";

            document.getElementById("productModalDescription").textContent =
                "Produk sudah memiliki riwayat transaksi. Hanya status yang dapat diubah.";

            document.getElementById("productSubmitText").textContent =
                "Simpan Status";

        } else {

            setProductFieldsEditable(true);

            document.getElementById("productModalTitle").textContent =
                "Edit Produk";

            document.getElementById("productModalDescription").textContent =
                "Perbarui informasi produk.";

            document.getElementById("productSubmitText").textContent =
                "Simpan Perubahan";
        }

        document.getElementById("productStatus").disabled = false;

        document.getElementById("productModal").classList.add("show");

    } catch (error) {

        console.error(error);

        alert("Terjadi kesalahan saat mengambil data produk.");
    }
}

// =====================================================
// DETAIL
// =====================================================
async function showDetail(id) {

    const modal = document.getElementById("detailModal");
    const content = document.getElementById("detailContent");

    if (!modal || !content) {
        return;
    }

    modal.classList.add("show");

    content.innerHTML =
        '<div style="text-align:center; padding:20px;">Memuat data...</div>';

    try {

        const response = await fetch(
            "index.php?action=get_product&id=" +
            encodeURIComponent(id)
        );

        const result = await response.json();

        if (!result.success) {
            content.innerHTML =
                '<div class="form-alert error">' +
                '<span>' +
                escapeHtml(result.message || "Data tidak ditemukan.") +
                '</span></div>';
            return;
        }

        const product = result.data;

        const transactionStatus =
            product.has_transaction
                ? '<span class="status active">Memiliki riwayat transaksi</span>'
                : '<span class="status inactive">Belum ada transaksi</span>';

        content.innerHTML = `
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">

                <div>
                    <small style="color:#6b7280;">Kode Produk</small>
                    <strong style="display:block; margin-top:4px;">
                        ${escapeHtml(product.code || "-")}
                    </strong>
                </div>

                <div>
                    <small style="color:#6b7280;">Kategori</small>
                    <strong style="display:block; margin-top:4px;">
                        ${escapeHtml(product.category_name || "-")}
                    </strong>
                </div>

                <div>
                    <small style="color:#6b7280;">Nama Produk</small>
                    <strong style="display:block; margin-top:4px;">
                        ${escapeHtml(product.name || "-")}
                    </strong>
                </div>

                <div>
                    <small style="color:#6b7280;">Brand</small>
                    <strong style="display:block; margin-top:4px;">
                        ${escapeHtml(product.brand || "-")}
                    </strong>
                </div>

                <div>
                    <small style="color:#6b7280;">Satuan</small>
                    <strong style="display:block; margin-top:4px;">
                        ${escapeHtml(product.unit || "-")}
                    </strong>
                </div>

                <div>
                    <small style="color:#6b7280;">Status</small>
                    <div style="margin-top:5px;">
                        ${product.status === "active"
                            ? '<span class="status active">Aktif</span>'
                            : '<span class="status inactive">Tidak Aktif</span>'}
                    </div>
                </div>

                <div>
                    <small style="color:#6b7280;">Harga Beli</small>
                    <strong style="display:block; margin-top:4px;">
                        ${formatRupiah(product.purchase_price)}
                    </strong>
                </div>

                <div>
                    <small style="color:#6b7280;">Harga Jual</small>
                    <strong style="display:block; margin-top:4px;">
                        ${formatRupiah(product.selling_price)}
                    </strong>
                </div>

                <div style="grid-column:1 / -1;">
                    <small style="color:#6b7280;">Riwayat</small>
                    <div style="margin-top:6px;">
                        ${transactionStatus}
                    </div>
                </div>

            </div>
        `;

    } catch (error) {

        console.error(error);

        content.innerHTML =
            '<div class="form-alert error">' +
            '<span>Gagal mengambil detail produk.</span>' +
            '</div>';
    }
}

function closeDetailModal() {

    const modal = document.getElementById("detailModal");

    if (modal) {
        modal.classList.remove("show");
    }
}

// =====================================================
// HAPUS
// =====================================================
function deleteProduct(id, name) {

    document.getElementById("delete_product_id").value = id;
    document.getElementById("deleteProductName").textContent =
        name || "ini";

    document.getElementById("deleteConfirmModal")
        .classList.add("show");
}

function closeDeleteModal() {

    const modal = document.getElementById("deleteConfirmModal");

    if (modal) {
        modal.classList.remove("show");
    }
}

function confirmDeleteProduct() {

    const form = document.getElementById("deleteProductForm");

    if (form) {
        form.submit();
    }
}

// =====================================================
// FILTER
// =====================================================
function filterProduct() {

    const searchInput = document.querySelector(
        'input[name="search"]'
    );

    const category =
        document.getElementById("categoryFilter").value;

    const brand =
        document.getElementById("brandFilter").value;

    const status =
        document.getElementById("statusFilter").value;

    const params = new URLSearchParams();

    if (searchInput && searchInput.value.trim() !== "") {
        params.set(
            "search",
            searchInput.value.trim()
        );
    }

    if (category !== "") {
        params.set("category", category);
    }

    if (brand !== "") {
        params.set("brand", brand);
    }

    if (status !== "") {
        params.set("status", status);
    }

    params.set("page", "1");

    window.location.href =
        "index.php?" + params.toString();
}

// =====================================================
// ESCAPE HTML
// =====================================================
function escapeHtml(value) {

    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function formatRupiah(value) {

    const number = Number(value || 0);

    return "Rp " +
        new Intl.NumberFormat("id-ID")
            .format(number);
}

// =====================================================
// MODAL OUTSIDE CLICK
// =====================================================
window.addEventListener("click", function(event) {

    const productModal =
        document.getElementById("productModal");

    const detailModal =
        document.getElementById("detailModal");

    const deleteModal =
        document.getElementById("deleteConfirmModal");

    if (event.target === productModal) {
        closeModal();
    }

    if (event.target === detailModal) {
        closeDetailModal();
    }

    if (event.target === deleteModal) {
        closeDeleteModal();
    }
});

// =====================================================
// CLEAN URL NOTIFICATION
// =====================================================

document.addEventListener("DOMContentLoaded", function() {

    preparePriceInputs();

    const productForm = document.getElementById("productForm");

    if (productForm) {
        productForm.addEventListener("submit", function() {

            const purchase = document.getElementById("purchasePrice");
            const selling = document.getElementById("sellingPrice");

            if (purchase) {
                purchase.value = purchase.value.replace(/\D/g, '');
            }

            if (selling) {
                selling.value = selling.value.replace(/\D/g, '');
            }
        });
    }
});

document.addEventListener("DOMContentLoaded", function() {

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
            (
                url.searchParams.toString()
                    ? "?" + url.searchParams.toString()
                    : ""
            )
        );
    }

    <?php if ($showProductModal): ?>
    const modal = document.getElementById("productModal");

    if (modal) {
        modal.classList.add("show");
    }
    <?php endif; ?>
});

// =====================================================
// SIDEBAR
// =====================================================
function toggleSidebar() {

    const sidebar =
        document.getElementById("sidebar");

    if (sidebar) {
        sidebar.classList.toggle("show");
    }
}
</script>

<style>
    .action-buttons .btn-icon:disabled {
        pointer-events: none;
    }

    #productModal,
    #detailModal,
    #deleteConfirmModal {
        z-index: 10001;
    }

    #productModal .modal,
    #detailModal .modal,
    #deleteConfirmModal .modal {
        max-height: 90vh;
        overflow-y: auto;
    }

    .toolbar-left form {
        margin: 0;
    }
</style>

</main>

</body>
</html>