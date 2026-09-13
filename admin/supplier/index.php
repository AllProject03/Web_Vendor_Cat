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
        // Jangan hentikan halaman Supplier jika tabel/settings bermasalah.
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

$pageTitle = "Supplier";

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_supplier(array $params = []): never {
    $url = 'index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

function generate_supplier_code(PDO $pdo): string {
    $stmt = $pdo->query(
        "SELECT code FROM suppliers
         WHERE code REGEXP '^SUP-[0-9]+$'
         ORDER BY CAST(SUBSTRING(code, 5) AS UNSIGNED) DESC
         LIMIT 1"
    );
    $last = $stmt->fetchColumn();
    $next = 1;

    if ($last && preg_match('/^SUP-(\d+)$/', $last, $m)) {
        $next = (int)$m[1] + 1;
    }

    do {
        $code = 'SUP-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
        $st = $pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE code=:code");
        $st->execute([':code' => $code]);
        $exists = (int)$st->fetchColumn() > 0;
        if ($exists) $next++;
    } while ($exists);

    return $code;
}

/* =========================
   AJAX DETAIL
========================= */
if (($_GET['action'] ?? '') === 'get_supplier') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) throw new Exception('ID supplier tidak valid.');

        $st = $pdo->prepare(
            "SELECT id, code, name, contact_person, phone, email, address, status, created_at
             FROM suppliers WHERE id=:id LIMIT 1"
        );
        $st->execute([':id' => $id]);
        $data = $st->fetch(PDO::FETCH_ASSOC);

        if (!$data) throw new Exception('Data supplier tidak ditemukan.');

        $tx = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id=:id");
        $tx->execute([':id' => $id]);
        $count = (int)$tx->fetchColumn();

        $data['transaction_count'] = $count;
        $data['has_transaction'] = $count > 0;

        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* =========================
   CRUD
========================= */
$notify = '';
$notifyType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['supplier_id'] ?? 0);

    try {
        if ($action === 'add_supplier') {
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $contact = trim($_POST['contact_person'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if ($code === '') $code = generate_supplier_code($pdo);
            if ($name === '') throw new Exception('Nama supplier wajib diisi.');
            if ($phone === '') throw new Exception('Nomor telepon wajib diisi.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Format email supplier tidak valid.');
            }
            if (!in_array($status, ['active','inactive'], true)) {
                throw new Exception('Status supplier tidak valid.');
            }

            $check = $pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE code=:code");
            $check->execute([':code' => $code]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception('Kode supplier sudah digunakan.');
            }

            $st = $pdo->prepare(
                "INSERT INTO suppliers
                 (code, name, contact_person, phone, email, address, status)
                 VALUES
                 (:code, :name, :contact, :phone, :email, :address, :status)"
            );
            $st->execute([
                ':code' => $code,
                ':name' => $name,
                ':contact' => $contact,
                ':phone' => $phone,
                ':email' => $email !== '' ? $email : null,
                ':address' => $address !== '' ? $address : null,
                ':status' => $status
            ]);

            redirect_supplier(['success' => 'added']);
        }

        if ($action === 'update_supplier') {
            if ($id <= 0) throw new Exception('ID supplier tidak valid.');

            $status = $_POST['status'] ?? 'active';
            if (!in_array($status, ['active','inactive'], true)) {
                throw new Exception('Status supplier tidak valid.');
            }

            $tx = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id=:id");
            $tx->execute([':id' => $id]);
            $used = (int)$tx->fetchColumn() > 0;

            if ($used) {
                $st = $pdo->prepare("UPDATE suppliers SET status=:status WHERE id=:id");
                $st->execute([':status' => $status, ':id' => $id]);
                redirect_supplier(['success' => 'status_updated']);
            }

            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $contact = trim($_POST['contact_person'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if ($code === '' || $name === '' || $phone === '') {
                throw new Exception('Kode, nama supplier, dan nomor telepon wajib diisi.');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Format email supplier tidak valid.');
            }

            $check = $pdo->prepare(
                "SELECT COUNT(*) FROM suppliers WHERE code=:code AND id<>:id"
            );
            $check->execute([':code' => $code, ':id' => $id]);
            if ((int)$check->fetchColumn() > 0) {
                throw new Exception('Kode supplier sudah digunakan supplier lain.');
            }

            $st = $pdo->prepare(
                "UPDATE suppliers
                 SET code=:code, name=:name, contact_person=:contact,
                     phone=:phone, email=:email, address=:address, status=:status
                 WHERE id=:id"
            );
            $st->execute([
                ':code' => $code,
                ':name' => $name,
                ':contact' => $contact,
                ':phone' => $phone,
                ':email' => $email !== '' ? $email : null,
                ':address' => $address !== '' ? $address : null,
                ':status' => $status,
                ':id' => $id
            ]);

            redirect_supplier(['success' => 'updated']);
        }

        if ($action === 'delete_supplier') {
            if ($id <= 0) redirect_supplier(['error' => 'invalid']);

            $tx = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id=:id");
            $tx->execute([':id' => $id]);

            if ((int)$tx->fetchColumn() > 0) {
                redirect_supplier(['error' => 'used']);
            }

            $st = $pdo->prepare("DELETE FROM suppliers WHERE id=:id");
            $st->execute([':id' => $id]);

            redirect_supplier(['success' => 'deleted']);
        }

    } catch (PDOException $e) {
        $notify = 'Operasi database gagal. Periksa koneksi database atau relasi transaksi.';
        $notifyType = 'error';
    } catch (Exception $e) {
        $notify = $e->getMessage();
        $notifyType = 'error';
    }
}

/* =========================
   NOTIFIKASI
========================= */
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

if ($success === 'added') { $notify = 'Supplier berhasil ditambahkan.'; $notifyType = 'success'; }
if ($success === 'updated') { $notify = 'Supplier berhasil diperbarui.'; $notifyType = 'success'; }
if ($success === 'status_updated') { $notify = 'Status supplier berhasil diperbarui.'; $notifyType = 'success'; }
if ($success === 'deleted') { $notify = 'Supplier berhasil dihapus.'; $notifyType = 'success'; }
if ($error === 'invalid') { $notify = 'ID supplier tidak valid.'; $notifyType = 'error'; }
if ($error === 'used') {
    $notify = 'Supplier tidak dapat dihapus karena sudah digunakan pada transaksi pembelian. Ubah status menjadi Tidak Aktif jika supplier tidak ingin digunakan lagi.';
    $notifyType = 'error';
}

/* =========================
   SEARCH + FILTER
========================= */
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(
        s.code LIKE :code
        OR s.name LIKE :name
        OR s.contact_person LIKE :contact
        OR s.phone LIKE :phone
        OR s.email LIKE :email
        OR s.address LIKE :address
    )';
    $v = '%' . $search . '%';
    $params = [
        ':code' => $v,
        ':name' => $v,
        ':contact' => $v,
        ':phone' => $v,
        ':email' => $v,
        ':address' => $v
    ];
}

if ($statusFilter !== '') {
    $where[] = 's.status=:status_filter';
    $params[':status_filter'] = $statusFilter;
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

/* =========================
   STATISTIK
========================= */
$totalSuppliers = (int)$pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
$activeSuppliers = (int)$pdo->query("SELECT COUNT(*) FROM suppliers WHERE status='active'")->fetchColumn();
$inactiveSuppliers = (int)$pdo->query("SELECT COUNT(*) FROM suppliers WHERE status='inactive'")->fetchColumn();
$TotalSuppliersBulan = (int)$pdo->query("SELECT COUNT(*) FROM suppliers WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) ")->fetchColumn();

/* Nilai total pembelian hanya dihitung jika kolom total_amount atau total tersedia. */
$totalPurchases = 0.0;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM purchases")->fetchAll(PDO::FETCH_ASSOC);
    $purchaseFields = array_column($cols, 'Field');

    $sumField = null;
    foreach (['total_amount','total','grand_total','subtotal'] as $candidate) {
        if (in_array($candidate, $purchaseFields, true)) {
            $sumField = $candidate;
            break;
        }
    }

    if ($sumField) {
        $totalPurchases = (float)$pdo->query(
            "SELECT COALESCE(SUM(`$sumField`),0) FROM purchases"
        )->fetchColumn();
    }
} catch (Throwable $e) {
    $totalPurchases = 0.0;
}

/* =========================
   PAGINATION
========================= */
$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM suppliers s{$whereSql}");
$countStmt->execute($params);
$filteredSuppliers = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredSuppliers / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT id, code, name, contact_person, phone, email, address, status, created_at
     FROM suppliers s
     {$whereSql}
     ORDER BY s.id DESC
     LIMIT :limit OFFSET :offset"
);

foreach ($params as $key => $value) $listStmt->bindValue($key, $value);
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();

$suppliers = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$displayStart = $filteredSuppliers > 0 ? $offset + 1 : 0;
$displayEnd = $filteredSuppliers > 0 ? min($offset + $perPage, $filteredSuppliers) : 0;

$paginationQuery = [];
if ($search !== '') $paginationQuery['search'] = $search;
if ($statusFilter !== '') $paginationQuery['status'] = $statusFilter;

$hasActiveFilter = $search !== '' || $statusFilter !== '';

function supplier_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) $initials .= strtoupper(substr($part, 0, 1));
    return $initials ?: 'S';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier | <?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            <a href="../service/" class="menu-item">
                <i class="bi bi-tools"></i>
                <span>Jasa</span>
            </a>

            <!-- SUPPLIER -->
            <a href="./" class="menu-item active">
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


    <!-- =========================
         HEADER
    ========================== -->

    <div class="page-header">

        <div>

            <div class="breadcrumb">
                Dashboard
                <i class="bi bi-chevron-right"></i>
                Master Data
                <i class="bi bi-chevron-right"></i>
                Supplier
            </div>

            <h1>Supplier</h1>

            <p>
                Kelola data pemasok produk dan kebutuhan vendor cat mobil.
            </p>

        </div>


        <button class="btn-primary" onclick="openModal()">
            <i class="bi bi-plus-lg"></i>
            Tambah Supplier
        </button>

    </div>



    <!-- =========================
         STATISTICS
    ========================== -->

    <div class="stats-grid">


        <!-- TOTAL SUPPLIER -->

        <div class="stat-card">

            <div class="stat-icon blue">

                <i class="bi bi-truck"></i>

            </div>

            <div>

                <span>Total Supplier</span>

                <strong><?= $totalSuppliers ?></strong>

            </div>

        </div>



        <!-- AKTIF -->

        <div class="stat-card">
            <div class="stat-icon green">
                <i class="bi bi-person-check"></i>
            </div>

            <div>
                <span>Supplier Aktif</span>
                <strong><?= $activeSuppliers ?></strong>
            </div>
        </div>



        <!-- SUPPLIER CAT -->

        <div class="stat-card">
            <div class="stat-icon orange">
                <i class="bi bi-palette"></i>
            </div>

            <div>
                <span>Supplier Tidak Aktif</span>
                <strong><?= $inactiveSuppliers ?></strong>
            </div>
        </div>



        <!-- TOTAL PEMBELIAN -->

        <div class="stat-card">
            <div class="stat-icon purple">
                <i class="bi bi-cart-check"></i>
            </div>

            <div>
                <span>Total Supplier Bulan Ini</span>
                <strong><?= $TotalSuppliersBulan ?></strong>
            </div>
        </div>
    </div>



    <!-- =========================
         CONTENT
    ========================== -->

    <div class="content-card">


        <!-- TOOLBAR -->

        <div class="table-toolbar">

            <div class="toolbar-left">

                <div
                    class="searc-box"
                    style="position:relative; width:330px; height:40px;"
                >
                    <i
                        class="bi bi-search"
                        style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#1f2937; font-size:18px; line-height:1; pointer-events:none; z-index:10;"
                    ></i>

                    <form
                        method="get"
                        id="supplierSearchForm"
                        style="margin:0;padding:0;"
                    >
                        <input
                            type="text"
                            name="search"
                            value="<?= h($search) ?>"
                            placeholder="Cari kode, nama, atau contact person..."
                            autocomplete="off"
                            style="display:block; width:330px; height:40px; padding:0 12px 0 40px; box-sizing:border-box; border:1px solid #9ca3af; border-radius:6px; outline:none; background:#fff; color:#111827; font-size:14px; font-family:inherit;"
                        >
                    </form>
                </div>

            </div>

            <div class="toolbar-right">

                <select
                    id="statusFilter"
                    onchange="document.getElementById('statusHidden').value=this.value;document.getElementById('supplierFilterForm').submit();"
                >
                    <option value="">Semua Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                        Aktif
                    </option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                        Tidak Aktif
                    </option>
                </select>

                <form method="get" id="supplierFilterForm" style="margin:0;padding:0;">
                    <input
                        type="hidden"
                        name="search"
                        value="<?= h($search) ?>"
                    >
                    <input
                        type="hidden"
                        name="status"
                        id="statusHidden"
                        value="<?= h($statusFilter) ?>"
                    >
                </form>

                <a
                    href="index.php"
                    class="btn-secondary"
                    style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;<?= $hasActiveFilter ? '' : 'pointer-events:none;opacity:.55;cursor:not-allowed;' ?>"
                    <?= !$hasActiveFilter ? 'aria-disabled="true" tabindex="-1" title="Reset aktif setelah pencarian atau filter digunakan."' : '' ?>
                >
                    <i class="bi bi-arrow-counterclockwise" style="margin-right:6px;"></i>
                    Reset
                </a>

            </div>

        </div>


        <!-- =========================
             TABLE
        ========================== -->

        <div class="table-wrapper">

            <table id="supplierTable">

                <thead>
                <tr>
                    <th>Kode</th>
                    <th>Supplier</th>
                    <th>Contact Person</th>
                    <th>Telepon</th>
                    <th>Email</th>
                    <th>Alamat</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
                </thead>

                <tbody>

                <?php if (!$suppliers): ?>

                <tr>
                    <td colspan="8" style="text-align:center;padding:40px 20px;">
                        <i class="bi bi-inbox" style="font-size:32px;"></i>
                        <div style="margin-top:8px;font-weight:600;">
                            Data supplier tidak ditemukan.
                        </div>
                        <small>
                            Coba ubah pencarian atau filter.
                        </small>
                    </td>
                </tr>

                <?php else: ?>

                <?php foreach ($suppliers as $supplier): ?>
                    <?php
                    $initials = supplier_initials((string)$supplier['name']);
                    $statusClass = $supplier['status'] === 'active' ? 'active' : 'inactive';
                    $statusText = $supplier['status'] === 'active' ? 'Aktif' : 'Tidak Aktif';
                    ?>

                <tr>

                    <td>
                        <span class="supplier-code">
                            <?= h(preg_replace('/^SUP-SUP-/i', 'SUP-', (string)$supplier['code'])) ?>
                        </span>
                    </td>

                    <td>
                        <div class="supplier-info">

                            <div class="supplier-avatar">
                                <?= h($initials) ?>
                            </div>

                            <div>
                                <strong><?= h($supplier['name']) ?></strong>
                                <small><?= h($supplier['email'] ?: '-') ?></small>
                            </div>

                        </div>
                    </td>

                    <td><?= h($supplier['contact_person'] ?: '-') ?></td>
                    <td><?= h($supplier['phone'] ?: '-') ?></td>
                    <td><?= h($supplier['email'] ?: '-') ?></td>
                    <td><?= h($supplier['address'] ?: '-') ?></td>

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
                                title="Detail"
                                onclick="showDetail(<?= (int)$supplier['id'] ?>)"
                            >
                                <i class="bi bi-eye"></i>
                            </button>

                            <button
                                type="button"
                                class="btn-icon"
                                title="Edit"
                                onclick="editSupplier(<?= (int)$supplier['id'] ?>)"
                            >
                                <i class="bi bi-pencil"></i>
                            </button>

                            <button
                                type="button"
                                class="btn-icon danger"
                                title="Hapus"
                                onclick="deleteSupplier(<?= (int)$supplier['id'] ?>, '<?= h($supplier['name']) ?>')"
                            >
                                <i class="bi bi-trash"></i>
                            </button>

                        </div>
                    </td>

                </tr>

                <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>    </table>

        </div>



        <!-- =========================
             PAGINATION
        ========================== -->

        <div class="pagination">

            <span>
                Menampilkan <?= $displayStart ?>–<?= $displayEnd ?>
                dari <?= $filteredSuppliers ?> supplier
            </span>

            <div>

                <?php
                $prev = $paginationQuery;
                $prev['page'] = max(1, $page - 1);
                ?>

                <a
                    href="?<?= h(http_build_query($prev)) ?>"
                    class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                    aria-label="Previous"
                    <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>
                >
                    <i class="bi bi-chevron-left"></i>
                </a>

                <?php
                $visiblePages = [];

                if ($totalPages <= 5) {
                    for ($i = 1; $i <= $totalPages; $i++) $visiblePages[] = $i;
                } else {
                    $visiblePages[] = 1;
                    $startPage = max(2, $page - 1);
                    $endPage = min($totalPages - 1, $page + 1);

                    if ($startPage > 2) $visiblePages[] = '...';
                    for ($i = $startPage; $i <= $endPage; $i++) $visiblePages[] = $i;
                    if ($endPage < $totalPages - 1) $visiblePages[] = '...';

                    $visiblePages[] = $totalPages;
                }
                ?>

                <?php foreach ($visiblePages as $visiblePage): ?>

                    <?php if ($visiblePage === '...'): ?>

                        <span class="page-btn">...</span>

                    <?php else: ?>

                        <?php
                        $pageQuery = $paginationQuery;
                        $pageQuery['page'] = $visiblePage;
                        ?>

                        <a
                            href="?<?= h(http_build_query($pageQuery)) ?>"
                            class="page-btn <?= $visiblePage === $page ? 'active' : '' ?>"
                        >
                            <?= $visiblePage ?>
                        </a>

                    <?php endif; ?>

                <?php endforeach; ?>

                <?php
                $next = $paginationQuery;
                $next['page'] = min($totalPages, $page + 1);
                ?>

                <a
                    href="?<?= h(http_build_query($next)) ?>"
                    class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
                    aria-label="Next"
                    <?= $page >= $totalPages ? 'aria-disabled="true"' : '' ?>
                >
                    <i class="bi bi-chevron-right"></i>
                </a>

            </div>

        </div>


    </div>

</div>


<!-- =========================
     MODAL TAMBAH SUPPLIER
========================== -->

<div class="modal-overlay" id="supplierModal">
    <div class="modal">
        <div class="modal-header">
            <div>
                <h2 id="modalTitle">Tambah Supplier</h2>
                <p id="modalSubtitle">Masukkan informasi supplier baru.</p>
            </div>

            <button type="button" class="close-btn" onclick="closeModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form id="supplierForm" method="post" onsubmit="saveSupplier(event)">
            <input type="hidden" name="action" id="formAction" value="add_supplier">
            <input type="hidden" name="supplier_id" id="supplierId" value="">
            <div class="form-grid">
                <div class="form-group">
                    <label>Kode Supplier <span>*</span></label>
                    <input type="text" name="code" id="supplierCode" placeholder="Contoh: SUP-0001" maxlength="30" required>

                </div>

                <div class="form-group">
                    <label>Nama Supplier<span>*</span></label>
                    <input type="text" name="name" id="supplierName" placeholder="Contoh: PT Maju Paint" required>
                </div>

                <div class="form-group">
                    <label>Contact Person</label>
                    <input type="text" name="contact_person" id="supplierContactPerson" placeholder="Nama PIC">
                </div>

                <div class="form-group">
                    <label>Nomor Telepon<span>*</span></label>
                    <input type="text" name="phone" id="supplierPhone" placeholder="08xxxxxxxxxx" required>
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="supplierEmail"placeholder="email@supplier.com">
                </div>

                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="supplierStatus">
                        <option value="active">Aktif</option>
                        <option value="inactive">Tidak Aktif</option>
                    </select>
                </div>

                <div class="form-group full">
                    <label>Alamat</label>
                    <textarea rows="3" name="address" id="supplierAddress" placeholder="Alamat lengkap supplier"></textarea>
                </div>

            </div>

            <div id="transactionNote" style="display:none;margin-top:14px;padding:12px 14px;border-radius:10px;background:#fff7ed;color:#9a3412;font-size:13px;">
                Supplier ini sudah digunakan pada transaksi pembelian. Saat diedit, hanya status yang dapat diubah.
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal()">
                    Batal
                </button>

                <button type="submit" class="btn-primary" id="saveButton">
                    <i class="bi bi-check-lg"></i>
                    <span id="saveButtonText">Simpan Supplier</span>
                </button>

            </div>

        </form>

    </div>

</div>

<?php if ($notify !== ''): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const message = <?= json_encode($notify, JSON_UNESCAPED_UNICODE) ?>;
    if (message) alert(message);
});
</script>
<?php endif; ?>

<style>
.pagination a.page-btn,
.pagination span.page-btn {
    min-width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

.pagination a.page-btn {
    color: inherit;
}

.pagination .disabled {
    pointer-events: none;
    opacity: .45;
}
</style>

<script>
function resetSupplierForm() {
    const form = document.getElementById('supplierForm');
    form.reset();

    document.getElementById('formAction').value = 'add_supplier';
    document.getElementById('supplierId').value = '';
    document.getElementById('modalTitle').textContent = 'Tambah Supplier';
    document.getElementById('modalSubtitle').textContent = 'Masukkan informasi supplier baru.';
    document.getElementById('saveButtonText').textContent = 'Simpan Supplier';
    document.getElementById('supplierStatus').value = 'active';
    document.getElementById('transactionNote').style.display = 'none';

    setSupplierEditable(true);
}

function setSupplierEditable(editable) {
    const fields = [
        'supplierName',
        'supplierContactPerson',
        'supplierPhone',
        'supplierEmail',
        'supplierAddress'
    ];

    fields.forEach(function (id) {
        const field = document.getElementById(id);
        if (!field) return;

        if (editable) {
            field.removeAttribute('readonly');
            field.removeAttribute('disabled');
        } else {
            field.setAttribute('readonly', 'readonly');
            if (field.tagName === 'TEXTAREA') {
                field.setAttribute('disabled', 'disabled');
            }
        }
    });

    const status = document.getElementById('supplierStatus');
    if (status) status.removeAttribute('disabled');

    const code = document.getElementById('supplierCode');
    if (code) {
        if (editable) {
            code.removeAttribute('readonly');
            code.removeAttribute('disabled');
        } else {
            code.setAttribute('readonly', 'readonly');
        }
    }
}

function openModal() {
    resetSupplierForm();

    document.getElementById('supplierCode').value =
        '<?= h(generate_supplier_code($pdo)) ?>';

    document.getElementById('supplierSubtitle');
    document.getElementById('modalSubtitle').textContent =
        'Masukkan informasi supplier baru. Kode supplier dapat diisi manual.';

    document.getElementById('supplierModal').classList.add('show');
}

function closeModal() {
    document.getElementById('supplierModal').classList.remove('show');
}

window.addEventListener('click', function (event) {
    const modal = document.getElementById('supplierModal');
    if (event.target === modal) closeModal();
});

async function fetchSupplier(id) {
    const response = await fetch(
        'index.php?action=get_supplier&id=' + encodeURIComponent(id),
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    );

    if (!response.ok) throw new Error('Gagal mengakses server.');

    const result = await response.json();
    if (!result.success) {
        throw new Error(result.message || 'Data supplier tidak ditemukan.');
    }

    return result.data;
}

async function showDetail(id) {
    try {
        const data = await fetchSupplier(id);

        alert(
            'DETAIL SUPPLIER\n\n' +
            'Kode           : ' + (data.code || '-') + '\n' +
            'Nama           : ' + (data.name || '-') + '\n' +
            'Contact Person : ' + (data.contact_person || '-') + '\n' +
            'Telepon        : ' + (data.phone || '-') + '\n' +
            'Email          : ' + (data.email || '-') + '\n' +
            'Alamat         : ' + (data.address || '-') + '\n' +
            'Status         : ' + (data.status === 'active' ? 'Aktif' : 'Tidak Aktif')
        );
    } catch (error) {
        alert(error.message);
    }
}

async function editSupplier(id) {
    try {
        const data = await fetchSupplier(id);
        const used = !!data.has_transaction;

        document.getElementById('formAction').value = 'update_supplier';
        document.getElementById('supplierId').value = data.id;
        document.getElementById('modalTitle').textContent = 'Edit Supplier';
        document.getElementById('modalSubtitle').textContent =
            used
                ? 'Supplier sudah digunakan pada transaksi pembelian.'
                : 'Perbarui informasi supplier.';
        document.getElementById('saveButtonText').textContent =
            used ? 'Simpan Status' : 'Simpan Perubahan';

        const normalizedCode = String(data.code || '')
            .replace(/^SUP-SUP-/i, 'SUP-');

        document.getElementById('supplierCode').value = normalizedCode;
        document.getElementById('supplierCode').setAttribute('readonly', 'readonly');
        document.getElementById('supplierName').value = data.name || '';
        document.getElementById('supplierContactPerson').value = data.contact_person || '';
        document.getElementById('supplierPhone').value = data.phone || '';
        document.getElementById('supplierEmail').value = data.email || '';
        document.getElementById('supplierAddress').value = data.address || '';
        document.getElementById('supplierStatus').value = data.status || 'active';

        document.getElementById('transactionNote').style.display =
            used ? 'block' : 'none';

        setSupplierEditable(!used);
        document.getElementById('supplierModal').classList.add('show');

    } catch (error) {
        alert(error.message);
    }
}

function saveSupplier(event) {
    event.preventDefault();

    const form = document.getElementById('supplierForm');
    const formData = new FormData(form);

    fetch('index.php', {
        method: 'POST',
        body: formData
    })
    .then(function (response) { return response.text(); })
    .then(function () {
        closeModal();
        window.location.href = 'index.php';
    })
    .catch(function (error) {
        alert('Gagal menyimpan supplier: ' + error.message);
    });
}

function deleteSupplier(id, name) {
    if (!confirm('Apakah Anda yakin ingin menghapus supplier "' + name + '"?')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete_supplier');
    formData.append('supplier_id', id);

    fetch('index.php', {
        method: 'POST',
        body: formData
    })
    .then(function (response) { return response.text(); })
    .then(function () {
        window.location.href = 'index.php';
    })
    .catch(function (error) {
        alert('Gagal menghapus supplier: ' + error.message);
    });
}
</script>

<script>
function toggleSidebar() {
    document.getElementById("sidebar").classList.toggle("show");
}
</script>
</body>
</html>
