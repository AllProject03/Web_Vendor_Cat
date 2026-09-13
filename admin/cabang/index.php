<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../settings/company.php';

$company = get_company_profile($pdo);

$companyName = $company['company_name'] !== '' ? $company['company_name'] : 'PT. GIAN GANESHA NAWASENA';
$companyTagline = $company['company_tagline'] !== '' ? $company['company_tagline'] : 'Sistem Vendor Cat Mobil';
$companyLogo = !empty($company['company_logo_url']) ? $company['company_logo_url'] : '../../assets/img/logo.png';


function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_cabang(array $params = []): never {
    $url = 'index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

function build_cabang_url(array $params = []): string {
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

function flash_message(?string $message = null, ?string $type = null): ?array {
    if ($message !== null) {
        $_SESSION['_cabang_flash'] = [
            'message' => $message,
            'type' => $type ?: 'success'
        ];
        return null;
    }

    if (!empty($_SESSION['_cabang_flash'])) {
        $flash = $_SESSION['_cabang_flash'];
        unset($_SESSION['_cabang_flash']);
        return $flash;
    }

    return null;
}

function count_cabang_references(PDO $pdo, int $cabangId): array {
    $references = [];

    $sql = "
        SELECT TABLE_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND COLUMN_NAME = 'cabang_id'
          AND TABLE_NAME <> 'cabangs'
    ";

    $tables = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) {
            continue;
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE cabang_id = :id");
            $stmt->execute([':id' => $cabangId]);
            $count = (int)$stmt->fetchColumn();

            if ($count > 0) {
                $references[$table] = $count;
            }
        } catch (Throwable $e) {
            // Abaikan tabel/relasi yang tidak dapat dihitung.
        }
    }

    return $references;
}

if (empty($_SESSION['_cabang_csrf'])) {
    $_SESSION['_cabang_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['_cabang_csrf'];

$notify = null;
$notifyType = null;
$flash = flash_message();

$allowedStatuses = ['active', 'inactive'];

/*
|--------------------------------------------------------------------------
| CRUD
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['cabang_id'] ?? 0);

    $returnSearch = trim($_POST['return_search'] ?? '');
    $returnStatus = $_POST['return_status'] ?? 'all';
    $returnPage = max(1, (int)($_POST['return_page'] ?? 1));

    if (!in_array($returnStatus, ['all', 'active', 'inactive'], true)) {
        $returnStatus = 'all';
    }

    $returnParams = [
        'search' => $returnSearch,
        'status' => $returnStatus,
        'page' => $returnPage
    ];

    if (empty($_POST['csrf_token']) || !hash_equals($csrfToken, (string)$_POST['csrf_token'])) {
        flash_message('Permintaan tidak valid. Silakan coba lagi.', 'error');
        redirect_cabang($returnParams);
    }

    try {
        if ($action === 'add_cabang') {
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if ($code === '') {
                throw new Exception('Kode cabang wajib diisi.');
            }

            if ($name === '') {
                throw new Exception('Nama cabang wajib diisi.');
            }

            if ($address === '') {
                throw new Exception('Alamat cabang wajib diisi.');
            }

            if ($phone === '') {
                throw new Exception('Nomor telepon wajib diisi.');
            }

            if (!in_array($status, $allowedStatuses, true)) {
                throw new Exception('Status cabang tidak valid.');
            }

            $check = $pdo->prepare("SELECT COUNT(*) FROM cabangs WHERE code = :code");
            $check->execute([':code' => $code]);

            if ((int)$check->fetchColumn() > 0) {
                throw new Exception('Kode cabang sudah digunakan.');
            }

            $st = $pdo->prepare("
                INSERT INTO cabangs (code, name, address, phone, status)
                VALUES (:code, :name, :address, :phone, :status)
            ");

            $st->execute([
                ':code' => $code,
                ':name' => $name,
                ':address' => $address,
                ':phone' => $phone,
                ':status' => $status
            ]);

            flash_message('Data cabang berhasil ditambahkan.', 'success');
            redirect_cabang($returnParams);
        }

        if ($action === 'update_cabang') {
            if ($id <= 0) {
                throw new Exception('ID cabang tidak valid.');
            }

            $status = $_POST['status'] ?? 'active';

            if (!in_array($status, $allowedStatuses, true)) {
                throw new Exception('Status cabang tidak valid.');
            }

            // Jika cabang sudah digunakan pada transaksi/master lain yang
            // memiliki cabang_id, hanya status yang boleh diubah.
            $references = count_cabang_references($pdo, $id);

            if ($references) {
                $st = $pdo->prepare("
                    UPDATE cabangs
                    SET status = :status
                    WHERE id = :id
                ");

                $st->execute([
                    ':status' => $status,
                    ':id' => $id
                ]);

                flash_message('Cabang sudah digunakan. Hanya status yang dapat diubah.', 'success');
                redirect_cabang($returnParams);
            }

            // Jika belum pernah digunakan, seluruh data cabang masih boleh diubah.
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if ($code === '' || $name === '' || $address === '' || $phone === '') {
                throw new Exception('Kode, nama, alamat, dan nomor telepon wajib diisi.');
            }

            $check = $pdo->prepare("
                SELECT COUNT(*)
                FROM cabangs
                WHERE code = :code
                  AND id <> :id
            ");
            $check->execute([
                ':code' => $code,
                ':id' => $id
            ]);

            if ((int)$check->fetchColumn() > 0) {
                throw new Exception('Kode cabang sudah digunakan cabang lain.');
            }

            $st = $pdo->prepare("
                UPDATE cabangs
                SET code = :code,
                    name = :name,
                    address = :address,
                    phone = :phone,
                    status = :status
                WHERE id = :id
            ");

            $st->execute([
                ':code' => $code,
                ':name' => $name,
                ':address' => $address,
                ':phone' => $phone,
                ':status' => $status,
                ':id' => $id
            ]);

            flash_message('Data cabang berhasil diperbarui.', 'success');
            redirect_cabang($returnParams);
        }

        if ($action === 'delete_cabang') {
            if ($id <= 0) {
                throw new Exception('ID cabang tidak valid.');
            }

            $references = count_cabang_references($pdo, $id);

            if ($references) {
                $detail = [];
                foreach ($references as $table => $count) {
                    $detail[] = $table . ' (' . $count . ')';
                }

                throw new Exception(
                    'Cabang tidak dapat dihapus karena masih digunakan oleh: ' .
                    implode(', ', $detail) . '.'
                );
            }

            $st = $pdo->prepare("DELETE FROM cabangs WHERE id = :id");
            $st->execute([':id' => $id]);

            flash_message('Data cabang berhasil dihapus.', 'success');
            redirect_cabang($returnParams);
        }

        throw new Exception('Aksi tidak dikenali.');
    } catch (PDOException $e) {
        flash_message('Operasi database gagal. Periksa struktur database dan relasi tabel.', 'error');
        redirect_cabang($returnParams);
    } catch (Exception $e) {
        flash_message($e->getMessage(), 'error');
        redirect_cabang($returnParams);
    }
}

/*
|--------------------------------------------------------------------------
| Search / Filter / Pagination
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'all';
}

$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count
    FROM cabangs
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalCabang = (int)($stats['total'] ?? 0);
$cabangAktif = (int)($stats['active_count'] ?? 0);
$cabangNonaktif = (int)($stats['inactive_count'] ?? 0);

/*
|--------------------------------------------------------------------------
| Table Query
|--------------------------------------------------------------------------
*/
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        code LIKE :search_code
        OR name LIKE :search_name
        OR address LIKE :search_address
        OR phone LIKE :search_phone
    )";
    $searchValue = '%' . $search . '%';
    $params[':search_code'] = $searchValue;
    $params[':search_name'] = $searchValue;
    $params[':search_address'] = $searchValue;
    $params[':search_phone'] = $searchValue;
}

if ($statusFilter !== 'all') {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM cabangs {$whereSql}");
$countStmt->execute($params);
$totalFiltered = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalFiltered / $perPage));

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $perPage;

$dataStmt = $pdo->prepare("
    SELECT id, code, name, address, phone, status
    FROM cabangs
    {$whereSql}
    ORDER BY id DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $dataStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();

$cabangs = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

$startNumber = $totalFiltered > 0 ? $offset + 1 : 0;
$endNumber = min($offset + count($cabangs), $totalFiltered);

$hasFilters = ($search !== '' || $statusFilter !== 'all');

function page_url(int $page, string $search, string $status): string {
    $params = ['page' => $page];

    if ($search !== '') {
        $params['search'] = $search;
    }

    if ($status !== 'all') {
        $params['status'] = $status;
    }

    return build_cabang_url($params);
}

function modal_url(string $modal, int $id = 0, string $search = '', string $status = 'all', int $page = 1): string {
    $params = [
        'modal' => $modal,
        'page' => $page
    ];

    if ($id > 0) {
        $params['id'] = $id;
    }

    if ($search !== '') {
        $params['search'] = $search;
    }

    if ($status !== 'all') {
        $params['status'] = $status;
    }

    return build_cabang_url($params);
}

function back_url(string $search, string $status, int $page): string {
    return page_url($page, $search, $status);
}

/*
|--------------------------------------------------------------------------
| Requested Server-Side Modal
|--------------------------------------------------------------------------
*/
$modalType = $_GET['modal'] ?? '';
$modalId = (int)($_GET['id'] ?? 0);
$modalCabang = null;
$modalReferences = [];

if (in_array($modalType, ['detail', 'edit', 'delete'], true) && $modalId > 0) {
    $modalStmt = $pdo->prepare("
        SELECT id, code, name, address, phone, status
        FROM cabangs
        WHERE id = :id
        LIMIT 1
    ");
    $modalStmt->execute([':id' => $modalId]);
    $modalCabang = $modalStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (in_array($modalType, ['edit', 'delete'], true) && $modalCabang) {
        $modalReferences = count_cabang_references($pdo, $modalId);
    }

    if (!$modalCabang) {
        $modalType = '';
        $modalId = 0;
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c1a2d">
    <title>Cabang | <?= h($companyName) ?> </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../../assets/css/admin.css">
    <link rel="stylesheet" href="style.css?v=20260907">

<style>
    .filter-area{
        display:flex;
        align-items:center;
        gap:10px;
        flex-wrap:wrap;
    }
    .filter-select{
        height:42px;
        border:1px solid #e2e8f0;
        border-radius:10px;
        padding:0 12px;
        background:#fff;
        color:#475569;
        font-family:inherit;
        outline:none;
        min-width:150px;
    }
    .btn-reset{
        height:42px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:7px;
        border:1px solid #d8dee8;
        border-radius:10px;
        padding:0 14px;
        text-decoration:none;
        font:600 14px Inter,sans-serif;
        color:#475569;
        background:#fff;
    }
    .btn-reset.disabled{
        opacity:.5;
        cursor:not-allowed;
        pointer-events:none;
    }
    .flash-message{
        margin:0 0 18px;
        border-radius:12px;
        padding:12px 15px;
        font-size:14px;
        font-weight:600;
    }
    .flash-message.success{
        background:#ecfdf3;
        border:1px solid #bbf7d0;
        color:#166534;
    }
    .flash-message.error{
        background:#fef2f2;
        border:1px solid #fecaca;
        color:#b91c1c;
    }
    .table-filter-row{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
        margin-top:16px;
    }
    .modal.show{
        display:flex !important;
    }
    .modal .modal-content{
        max-height:90vh;
        overflow-y:auto;
    }
    .detail-grid{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:14px;
        margin-top:6px;
    }
    .detail-item{
        padding:13px 14px;
        border:1px solid #edf0f4;
        border-radius:10px;
        background:#fafbfc;
    }
    .detail-item.full{
        grid-column:1 / -1;
    }
    .detail-label{
        display:block;
        font-size:12px;
        color:#7b8494;
        margin-bottom:5px;
        font-weight:600;
    }
    .detail-value{
        display:block;
        color:#1f2937;
        font-weight:600;
        line-height:1.5;
        word-break:break-word;
    }
    .delete-warning{
        border-radius:11px;
        padding:13px 14px;
        background:#fff7ed;
        border:1px solid #fed7aa;
        color:#9a3412;
        margin:16px 0;
        line-height:1.5;
        font-size:14px;
    }
    .delete-danger{
        border-radius:11px;
        padding:13px 14px;
        background:#fef2f2;
        border:1px solid #fecaca;
        color:#991b1b;
        margin:16px 0;
        line-height:1.5;
        font-size:14px;
    }
    .action-link{
        text-decoration:none;
        display:inline-flex;
        align-items:center;
        justify-content:center;
    }

    /* PAGINATION - samakan dengan pola pagination Supplier */
    .table-footer .pagination{
        display:flex;
        align-items:center;
        gap:5px;
        margin-left:auto;
    }

    .table-footer .pagination a,
    .table-footer .pagination button{
        min-width:32px;
        width:32px;
        height:32px;
        padding:0 8px;
        border:1px solid #e1e5eb;
        border-radius:6px;
        background:#fff;
        color:#6b7280;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        text-decoration:none;
        font:500 13px Inter,sans-serif;
        line-height:1;
        cursor:pointer;
        box-sizing:border-box;
    }

    .table-footer .pagination a:hover:not(.disabled),
    .table-footer .pagination button:hover:not(:disabled){
        background:#f9fafb;
        color:#111827;
    }

    .table-footer .pagination a.active-page,
    .table-footer .pagination button.active-page{
        background:#111827;
        border-color:#111827;
        color:#fff;
    }

    .table-footer .pagination a.disabled,
    .table-footer .pagination button:disabled{
        opacity:.45;
        cursor:not-allowed;
        pointer-events:none;
    }

    .table-footer .pagination button{
        appearance:none;
    }
    @media (max-width:700px){
        .detail-grid{grid-template-columns:1fr;}
        .detail-item.full{grid-column:auto;}
    }
</style>

</head>
<body>

<aside class="sidebar" id="sidebar">
    <!-- =====================================================
         BRAND
    ====================================================== -->
    <div class="brand">
        <img src="<?= h($companyLogo) ?>" class="img-fluid" alt="<?= h($companyName) ?>">

        <div class="brand-text">
            <div class="brand-name">
               <?= h($companyName) ?>
            </div>
            <small><?= h($companyTagline) ?></small>
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
            <a href="../supplier/" class="menu-item ">
                <i class="bi bi-truck"></i>
                <span>Supplier</span>
            </a>

            <!-- CABANG -->
            <a href="./" class="menu-item active">
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
                Cabang
            </div>

            <h1>Data Cabang</h1>
            <p>Kelola data cabang vendor cat mobil.</p>
        </div>

        <a
            href="<?= h(modal_url('add', 0, $search, $statusFilter, $currentPage)) ?>"
            class="btn-primary"
        >
            <i class="bi bi-plus-lg"></i>
            Tambah Cabang
        </a>
    </div>

    <?php if ($flash): ?>
        <div class="flash-message <?= $flash['type'] === 'error' ? 'error' : 'success' ?>">
            <i class="bi <?= $flash['type'] === 'error' ? 'bi-exclamation-circle' : 'bi-check-circle' ?>"></i>
            <?= h($flash['message']) ?>
        </div>
    <?php endif; ?>

    <!-- STATISTICS -->
    <div class="statistics">

        <div class="stat-card">
            <div class="stat-icon blue">
                <i class="bi bi-buildings"></i>
            </div>
            <div>
                <span>Total Cabang</span>
                <strong><?= $totalCabang ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon green">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <span>Cabang Aktif</span>
                <strong><?= $cabangAktif ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon red">
                <i class="bi bi-x-circle"></i>
            </div>
            <div>
                <span>Cabang Nonaktif</span>
                <strong><?= $cabangNonaktif ?></strong>
            </div>
        </div>

    </div>

    <!-- DATA CARD -->
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Daftar Cabang</h2>
                <p>Informasi seluruh cabang perusahaan.</p>
            </div>
        </div>

        <!-- SEARCH / FILTER -->
        <form method="GET" class="table-filter-row">
            <div class="search-box" style="flex:1;min-width:220px;">
                <i class="bi bi-search"></i>
                <input
                    type="text"
                    name="search"
                    value="<?= h($search) ?>"
                    placeholder="Cari kode, nama, alamat, atau telepon..."
                    style="width:100%;"
                >
            </div>

            <div class="filter-area">
                <select name="status" class="filter-select">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Semua Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Tidak Aktif</option>
                </select>

                <button type="submit" class="btn-primary">
                    <i class="bi bi-search"></i>
                    Cari
                </button>

                <?php if ($hasFilters): ?>
                    <a href="index.php" class="btn-reset">
                        <i class="bi bi-arrow-counterclockwise"></i>
                        Reset
                    </a>
                <?php else: ?>
                    <button type="button" class="btn-reset disabled" disabled>
                        <i class="bi bi-arrow-counterclockwise"></i>
                        Reset
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <!-- TABLE -->
        <div class="table-container">
            <table id="branchTable">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode</th>
                        <th>Nama Cabang</th>
                        <th>Alamat</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$cabangs): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;padding:40px 20px;color:#7b8494;">
                            <i class="bi bi-inbox" style="font-size:30px;display:block;margin-bottom:8px;"></i>
                            Data cabang tidak ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($cabangs as $index => $cabang): ?>
                        <?php
                        $cabangId = (int)$cabang['id'];
                        $avatar = function_exists('mb_substr')
                            ? mb_strtoupper(mb_substr((string)$cabang['name'], 0, 1))
                            : strtoupper(substr((string)$cabang['name'], 0, 1));

                        $statusLabel = $cabang['status'] === 'active' ? 'Aktif' : 'Tidak Aktif';
                        $statusClass = $cabang['status'] === 'active' ? 'active' : 'inactive';
                        ?>
                        <tr>
                            <td><?= $offset + $index + 1 ?></td>

                            <td>
                                <span class="code"><?= h($cabang['code']) ?></span>
                            </td>

                            <td>
                                <div class="branch-name">
                                    <div class="branch-avatar"><?= h($avatar) ?></div>
                                    <div>
                                        <strong><?= h($cabang['name']) ?></strong>
                                    </div>
                                </div>
                            </td>

                            <td><?= h($cabang['address']) ?></td>
                            <td><?= h($cabang['phone']) ?></td>

                            <td>
                                <span class="status <?= $statusClass ?>">
                                    <?= $statusLabel ?>
                                </span>
                            </td>

                            <td>
                                <div class="actions">

                                    <a
                                        href="<?= h(modal_url('detail', $cabangId, $search, $statusFilter, $currentPage)) ?>"
                                        class="action-btn view action-link"
                                        title="Detail"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <a
                                        href="<?= h(modal_url('edit', $cabangId, $search, $statusFilter, $currentPage)) ?>"
                                        class="action-btn edit action-link"
                                        title="Edit"
                                    >
                                        <i class="bi bi-pencil"></i>
                                    </a>

                                    <a
                                        href="<?= h(modal_url('delete', $cabangId, $search, $statusFilter, $currentPage)) ?>"
                                        class="action-btn delete action-link"
                                        title="Hapus"
                                    >
                                        <i class="bi bi-trash"></i>
                                    </a>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- FOOTER TABLE -->
        <div class="table-footer">
            <span>
                Menampilkan <strong><?= $startNumber ?></strong>
                <?= $totalFiltered > 0 ? 'sampai <strong>' . $endNumber . '</strong>' : '' ?>
                dari <strong><?= $totalFiltered ?></strong> cabang
            </span>

            <div class="pagination">

                <?php if ($currentPage > 1): ?>
                    <a href="<?= h(page_url($currentPage - 1, $search, $statusFilter)) ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                <?php else: ?>
                    <button type="button" disabled aria-label="Halaman sebelumnya">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                <?php endif; ?>

                <?php
                $startPage = max(1, $currentPage - 2);
                $endPage = min($totalPages, $currentPage + 2);

                if ($startPage > 1):
                ?>
                    <a href="<?= h(page_url(1, $search, $statusFilter)) ?>">1</a>
                    <?php if ($startPage > 2): ?>
                        <span style="min-width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;color:#9ca3af;">...</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($page = $startPage; $page <= $endPage; $page++): ?>
                    <?php if ($page === $currentPage): ?>
                        <button class="active-page" type="button" aria-current="page"><?= $page ?></button>
                    <?php else: ?>
                        <a href="<?= h(page_url($page, $search, $statusFilter)) ?>">
                            <?= $page ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <span style="min-width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;color:#9ca3af;">...</span>
                    <?php endif; ?>
                    <a href="<?= h(page_url($totalPages, $search, $statusFilter)) ?>">
                        <?= $totalPages ?>
                    </a>
                <?php endif; ?>

                <?php if ($currentPage < $totalPages): ?>
                    <a href="<?= h(page_url($currentPage + 1, $search, $statusFilter)) ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <button type="button" disabled aria-label="Halaman berikutnya">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                <?php endif; ?>

            </div>
        </div>
    </div>

</div>
</main>

<?php if ($modalType === 'add'): ?>
<div class="modal show" id="branchAddModal">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h2>Tambah Cabang</h2>
                <p>Tambahkan cabang baru.</p>
            </div>
            <a
                href="<?= h(back_url($search, $statusFilter, $currentPage)) ?>"
                class="close-btn"
                aria-label="Tutup"
            >
                <i class="bi bi-x-lg"></i>
            </a>
        </div>

        <form method="POST" action="index.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="add_cabang">
            <input type="hidden" name="return_search" value="<?= h($search) ?>">
            <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>">
            <input type="hidden" name="return_page" value="<?= $currentPage ?>">

            <div class="form-group">
                <label>Kode Cabang<span style="color:red;">*</span></label>
                <input type="text" name="code" placeholder="Contoh: JKT-002" required>
            </div>

            <div class="form-group">
                <label>Nama Cabang<span style="color:red;">*</span></label>
                <input type="text" name="name" placeholder="Masukkan nama cabang" required>
            </div>

            <div class="form-group">
                <label>Alamat<span style="color:red;">*</span></label>
                <textarea name="address" placeholder="Masukkan alamat cabang" required></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Nomor Telepon<span style="color:red;">*</span></label>
                    <input type="text" name="phone" placeholder="021-xxxxxxx" required>
                </div>

                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active">Aktif</option>
                        <option value="inactive">Tidak Aktif</option>
                    </select>
                </div>
            </div>

            <div class="modal-footer">
                <a
                    href="<?= h(back_url($search, $statusFilter, $currentPage)) ?>"
                    class="btn-secondary action-link"
                >
                    Batal
                </a>

                <button type="submit" class="btn-primary">
                    <i class="bi bi-save"></i>
                    Simpan
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($modalCabang && $modalType === 'detail'): ?>
<div class="modal show" id="branchDetailModal">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h2>Detail Cabang</h2>
                <p>Informasi lengkap data cabang.</p>
            </div>
            <a
                href="<?= h(back_url($search, $statusFilter, $currentPage)) ?>"
                class="close-btn"
                aria-label="Tutup"
            >
                <i class="bi bi-x-lg"></i>
            </a>
        </div>

        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">Kode Cabang</span>
                <span class="detail-value"><?= h($modalCabang['code']) ?></span>
            </div>

            <div class="detail-item">
                <span class="detail-label">Nama Cabang</span>
                <span class="detail-value"><?= h($modalCabang['name']) ?></span>
            </div>

            <div class="detail-item">
                <span class="detail-label">Nomor Telepon</span>
                <span class="detail-value"><?= h($modalCabang['phone']) ?></span>
            </div>

            <div class="detail-item">
                <span class="detail-label">Status</span>
                <span class="detail-value"><?= $modalCabang['status'] === 'active' ? 'Aktif' : 'Tidak Aktif' ?></span>
            </div>

            <div class="detail-item full">
                <span class="detail-label">Alamat</span>
                <span class="detail-value"><?= nl2br(h($modalCabang['address'])) ?></span>
            </div>
        </div>

        <div class="modal-footer">
            <a
                href="<?= h(back_url($search, $statusFilter, $currentPage)) ?>"
                class="btn-secondary action-link"
            >
                Tutup
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($modalCabang && $modalType === 'edit'): ?>
<?php $modalCabangUsed = !empty($modalReferences); ?>
<div class="modal show" id="branchEditModal">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h2>Edit Cabang</h2>
                <p>
                    <?= $modalCabangUsed
                        ? 'Cabang sudah digunakan. Hanya status yang dapat diubah.'
                        : 'Perbarui informasi data cabang.' ?>
                </p>
            </div>
            <a
                href="<?= h(back_url($search, $statusFilter, $currentPage)) ?>"
                class="close-btn"
                aria-label="Tutup"
            >
                <i class="bi bi-x-lg"></i>
            </a>
        </div>

        <form method="POST" action="index.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="update_cabang">
            <input type="hidden" name="cabang_id" value="<?= (int)$modalCabang['id'] ?>">
            <input type="hidden" name="return_search" value="<?= h($search) ?>">
            <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>">
            <input type="hidden" name="return_page" value="<?= $currentPage ?>">

            <div class="form-group">
                <label>Kode Cabang<span style="color:red;">*</span></label>
                <input type="text" name="code" value="<?= h($modalCabang['code']) ?>" required <?= $modalCabangUsed ? 'disabled' : '' ?>>
            </div>

            <div class="form-group">
                <label>Nama Cabang<span style="color:red;">*</span></label>
                <input type="text" name="name" value="<?= h($modalCabang['name']) ?>" required <?= $modalCabangUsed ? 'disabled' : '' ?>>
            </div>

            <div class="form-group">
                <label>Alamat<span style="color:red;">*</span></label>
                <textarea name="address" required <?= $modalCabangUsed ? 'disabled' : '' ?>><?= h($modalCabang['address']) ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Nomor Telepon<span style="color:red;">*</span></label>
                    <input type="text" name="phone" value="<?= h($modalCabang['phone']) ?>" required <?= $modalCabangUsed ? 'disabled' : '' ?>>
                </div>

                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= $modalCabang['status'] === 'active' ? 'selected' : '' ?>>Aktif</option>
                        <option value="inactive" <?= $modalCabang['status'] === 'inactive' ? 'selected' : '' ?>>Tidak Aktif</option>
                    </select>
                </div>
            </div>

            <div class="modal-footer">
                <a
                    href="<?= h(back_url($search, $statusFilter, $currentPage)) ?>"
                    class="btn-secondary action-link"
                >
                    Batal
                </a>

                <button type="submit" class="btn-primary">
                    <i class="bi bi-save"></i>
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($modalCabang && $modalType === 'delete'): ?>
<div class="modal show" id="branchDeleteModal">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h2>Hapus Cabang</h2>
                <p>Konfirmasi penghapusan data cabang.</p>
            </div>
            <a
                href="<?= h(back_url($search, $statusFilter, $currentPage)) ?>"
                class="close-btn"
                aria-label="Tutup"
            >
                <i class="bi bi-x-lg"></i>
            </a>
        </div>

        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">Kode Cabang</span>
                <span class="detail-value"><?= h($modalCabang['code']) ?></span>
            </div>

            <div class="detail-item">
                <span class="detail-label">Nama Cabang</span>
                <span class="detail-value"><?= h($modalCabang['name']) ?></span>
            </div>
        </div>

        <?php if ($modalReferences): ?>
            <div class="delete-danger">
                <strong>Cabang tidak dapat dihapus.</strong><br>
                Data ini masih digunakan oleh:
                <ul style="margin:8px 0 0 18px;padding:0;">
                    <?php foreach ($modalReferences as $table => $count): ?>
                        <li><?= h($table) ?>: <?= $count ?> data</li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="modal-footer">
                <a
                    href="<?= h(back_url($search, $statusFilter, $currentPage)) ?>"
                    class="btn-secondary action-link"
                >
                    Tutup
                </a>
            </div>
        <?php else: ?>
            <div class="delete-warning">
                Data cabang <strong><?= h($modalCabang['name']) ?></strong>
                akan dihapus secara permanen. Tindakan ini tidak dapat dibatalkan.
            </div>

            <form method="POST" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="delete_cabang">
                <input type="hidden" name="cabang_id" value="<?= (int)$modalCabang['id'] ?>">
                <input type="hidden" name="return_search" value="<?= h($search) ?>">
                <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>">
                <input type="hidden" name="return_page" value="<?= $currentPage ?>">

                <div class="modal-footer">
                    <a
                        href="<?= h(back_url($search, $statusFilter, $currentPage)) ?>"
                        class="btn-secondary action-link"
                    >
                        Batal
                    </a>

                    <button type="submit" class="btn-primary" style="background:#dc2626;border-color:#dc2626;">
                        <i class="bi bi-trash"></i>
                        Ya, Hapus
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</body>
</html>
