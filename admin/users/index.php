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
        // Jangan hentikan halaman Pengguna jika tabel/settings bermasalah.
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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function count_user_references(PDO $pdo, int $userId): array {
    $references = [];

    $stmt = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME <> 'users'
           AND DATA_TYPE IN ('bigint','int','mediumint','smallint','tinyint')
           AND COLUMN_NAME IN ('created_by','approved_by','received_by','updated_by')"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $table = (string)$column['TABLE_NAME'];
        $field = (string)$column['COLUMN_NAME'];

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $field)) {
            continue;
        }

        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$field}` = :user_id");
            $q->execute([':user_id' => $userId]);
            $count = (int)$q->fetchColumn();
            if ($count > 0) {
                $references[$table . '.' . $field] = $count;
            }
        } catch (Throwable $e) {
            // Abaikan tabel/kolom yang tidak dapat diperiksa.
        }
    }

    return $references;
}

function build_return_params(string $search, string $role, string $status, int $page): array {
    return [
        'search' => $search,
        'role' => $role,
        'status' => $status,
        'page' => $page,
    ];
}

function redirect_users(array $params = []): never {
    $url = 'index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

if (empty($_SESSION['csrf_users'])) {
    $_SESSION['csrf_users'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_users'];

$notify = '';
$notifyType = '';

// ------------------------------------------------------------
// POST / CRUD
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_users'] ?? '', $token)) {
        $notify = 'Permintaan tidak valid (CSRF).';
        $notifyType = 'error';
    } else {
        try {
            if ($action === 'add_user') {
                $roleId = (int)($_POST['role_id'] ?? 0);
                $cabangId = $_POST['cabang_id'] === '' ? null : (int)($_POST['cabang_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $password = (string)($_POST['password'] ?? '');
                $confirm = (string)($_POST['password_confirmation'] ?? '');
                $status = $_POST['status'] ?? 'active';

                if ($roleId <= 0) throw new Exception('Role wajib dipilih.');
                if ($name === '') throw new Exception('Nama lengkap wajib diisi.');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Format email tidak valid.');
                if ($phone === '') throw new Exception('Nomor telepon wajib diisi.');
                if ($password === '') throw new Exception('Password wajib diisi.');
                if ($password !== $confirm) throw new Exception('Konfirmasi password tidak sama.');
                if (!in_array($status, ['active','inactive'], true)) throw new Exception('Status tidak valid.');

                $roleCheck = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE id = :id');
                $roleCheck->execute([':id' => $roleId]);
                if ((int)$roleCheck->fetchColumn() === 0) throw new Exception('Role tidak ditemukan.');

                if ($cabangId !== null) {
                    $branchCheck = $pdo->prepare('SELECT COUNT(*) FROM cabangs WHERE id = :id');
                    $branchCheck->execute([':id' => $cabangId]);
                    if ((int)$branchCheck->fetchColumn() === 0) throw new Exception('Cabang tidak ditemukan.');
                }

                $emailCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
                $emailCheck->execute([':email' => $email]);
                if ((int)$emailCheck->fetchColumn() > 0) throw new Exception('Email sudah digunakan.');

                $st = $pdo->prepare('INSERT INTO users (role_id, cabang_id, name, email, password, phone, status) VALUES (:role_id, :cabang_id, :name, :email, :password, :phone, :status)');
                $st->execute([
                    ':role_id' => $roleId,
                    ':cabang_id' => $cabangId,
                    ':name' => $name,
                    ':email' => $email,
                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                    ':phone' => $phone,
                    ':status' => $status,
                ]);
                redirect_users(['success' => 'added']);
            }

            if ($action === 'update_user') {
                $id = (int)($_POST['user_id'] ?? 0);
                if ($id <= 0) throw new Exception('ID user tidak valid.');
                $roleId = (int)($_POST['role_id'] ?? 0);
                $cabangId = $_POST['cabang_id'] === '' ? null : (int)($_POST['cabang_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $password = (string)($_POST['password'] ?? '');
                $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');
                $status = $_POST['status'] ?? 'active';

                if ($password !== '' && $password !== $passwordConfirmation) {
                    throw new Exception('Konfirmasi password baru tidak sama.');
                }

                if ($roleId <= 0) throw new Exception('Role wajib dipilih.');
                if ($name === '') throw new Exception('Nama lengkap wajib diisi.');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Format email tidak valid.');
                if ($phone === '') throw new Exception('Nomor telepon wajib diisi.');
                if (!in_array($status, ['active','inactive'], true)) throw new Exception('Status tidak valid.');

                $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
                $exists->execute([':id' => $id]);
                if ((int)$exists->fetchColumn() === 0) throw new Exception('User tidak ditemukan.');

                $roleCheck = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE id = :id');
                $roleCheck->execute([':id' => $roleId]);
                if ((int)$roleCheck->fetchColumn() === 0) throw new Exception('Role tidak ditemukan.');

                if ($cabangId !== null) {
                    $branchCheck = $pdo->prepare('SELECT COUNT(*) FROM cabangs WHERE id = :id');
                    $branchCheck->execute([':id' => $cabangId]);
                    if ((int)$branchCheck->fetchColumn() === 0) throw new Exception('Cabang tidak ditemukan.');
                }

                $emailCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND id <> :id');
                $emailCheck->execute([':email' => $email, ':id' => $id]);
                if ((int)$emailCheck->fetchColumn() > 0) throw new Exception('Email sudah digunakan user lain.');

                if ($password !== '') {
                    $st = $pdo->prepare('UPDATE users SET role_id=:role_id, cabang_id=:cabang_id, name=:name, email=:email, password=:password, phone=:phone, status=:status WHERE id=:id');
                    $st->execute([
                        ':role_id' => $roleId,
                        ':cabang_id' => $cabangId,
                        ':name' => $name,
                        ':email' => $email,
                        ':password' => password_hash($password, PASSWORD_DEFAULT),
                        ':phone' => $phone,
                        ':status' => $status,
                        ':id' => $id,
                    ]);
                } else {
                    $st = $pdo->prepare('UPDATE users SET role_id=:role_id, cabang_id=:cabang_id, name=:name, email=:email, phone=:phone, status=:status WHERE id=:id');
                    $st->execute([
                        ':role_id' => $roleId,
                        ':cabang_id' => $cabangId,
                        ':name' => $name,
                        ':email' => $email,
                        ':phone' => $phone,
                        ':status' => $status,
                        ':id' => $id,
                    ]);
                }
                redirect_users(['success' => 'updated']);
            }

            if ($action === 'delete_user') {
                $id = (int)($_POST['user_id'] ?? 0);
                if ($id <= 0) throw new Exception('ID user tidak valid.');

                $currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
                if ($id === $currentUserId) {
                    throw new Exception('User yang sedang login tidak dapat dihapus.');
                }

                $references = count_user_references($pdo, $id);
                if ($references) {
                    $detail = [];
                    foreach ($references as $table => $count) {
                        $detail[] = $table . ' (' . $count . ')';
                    }
                    throw new Exception(
                        'User tidak dapat dihapus karena masih direferensikan oleh: ' .
                        implode(', ', $detail) . '. Nonaktifkan user jika sudah tidak digunakan.'
                    );
                }

                $st = $pdo->prepare('DELETE FROM users WHERE id = :id');
                $st->execute([':id' => $id]);
                if ($st->rowCount() === 0) throw new Exception('User tidak ditemukan atau sudah dihapus.');
                redirect_users(['success' => 'deleted']);
            }
        } catch (PDOException $e) {
            $notify = 'Operasi database gagal. Pastikan struktur tabel roles, cabangs, dan users sesuai.';
            $notifyType = 'error';
        } catch (Exception $e) {
            $notify = $e->getMessage();
            $notifyType = 'error';
        }
    }
}

// ------------------------------------------------------------
// FILTER + PAGINATION
// ------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(u.name LIKE :search_name OR u.email LIKE :search_email OR u.phone LIKE :search_phone)';
    $searchValue = '%' . $search . '%';
    $params[':search_name'] = $searchValue;
    $params[':search_email'] = $searchValue;
    $params[':search_phone'] = $searchValue;
}
if ($roleFilter !== '') {
    $where[] = 'u.role_id = :role_filter';
    $params[':role_filter'] = (int)$roleFilter;
}
if ($statusFilter !== '') {
    $where[] = 'u.status = :status_filter';
    $params[':status_filter'] = $statusFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSql");
foreach ($params as $key => $value) {
    if ($key === ':role_filter') $countStmt->bindValue($key, $value, PDO::PARAM_INT);
    else $countStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$countStmt->execute();
$totalFiltered = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$listSql = "SELECT u.id, u.role_id, u.cabang_id, u.name, u.email, u.phone, u.status, u.created_at,
                   r.name AS role_name, c.code AS cabang_code, c.name AS cabang_name
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            LEFT JOIN cabangs c ON c.id = u.cabang_id
            $whereSql
            ORDER BY u.id DESC
            LIMIT :limit OFFSET :offset";
$listStmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    if ($key === ':role_filter') $listStmt->bindValue($key, $value, PDO::PARAM_INT);
    else $listStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$users = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------------
// STATISTICS
// ------------------------------------------------------------
$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$activeUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
$totalRoles = (int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();

$roles = $pdo->query('SELECT id, name FROM roles ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$branches = $pdo->query("SELECT id, code, name, status FROM cabangs ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------------
// SERVER-RENDERED MODAL DATA
// ------------------------------------------------------------
$detailUser = null;
$editUser = null;
$deleteUser = null;
$deleteUserReferences = [];

if (isset($_GET['detail']) && ctype_digit((string)$_GET['detail'])) {
    $detailId = (int)$_GET['detail'];
    $st = $pdo->prepare('SELECT u.id,u.name,u.email,u.phone,u.status,u.created_at,r.name AS role_name,c.code AS cabang_code,c.name AS cabang_name FROM users u LEFT JOIN roles r ON r.id=u.role_id LEFT JOIN cabangs c ON c.id=u.cabang_id WHERE u.id=:id');
    $st->execute([':id' => $detailId]);
    $detailUser = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $st = $pdo->prepare('SELECT id,role_id,cabang_id,name,email,phone,status FROM users WHERE id=:id');
    $st->execute([':id' => $editId]);
    $editUser = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (isset($_GET['delete']) && ctype_digit((string)$_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];
    $st = $pdo->prepare('SELECT u.id,u.name,u.email,r.name AS role_name,c.name AS cabang_name FROM users u LEFT JOIN roles r ON r.id=u.role_id LEFT JOIN cabangs c ON c.id=u.cabang_id WHERE u.id=:id');
    $st->execute([':id' => $deleteId]);
    $deleteUser = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($deleteUser) {
        $deleteUserReferences = count_user_references($pdo, $deleteId);
    }
}

function build_page_url(int $pageNo, string $search, string $role, string $status): string {
    $params = ['page' => $pageNo];
    if ($search !== '') $params['search'] = $search;
    if ($role !== '') $params['role'] = $role;
    if ($status !== '') $params['status'] = $status;
    return 'index.php?' . http_build_query($params);
}

$hasFilters = ($search !== '' || $roleFilter !== '' || $statusFilter !== '');
$from = $totalFiltered > 0 ? $offset + 1 : 0;
$to = min($offset + $perPage, $totalFiltered);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c1a2d">
    <title>Pengguna | <?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../assets/css/admin.css?v=20260907">
    <link rel="stylesheet" href="style.css?v=20260912-users">

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
            <a href="../supplier/" class="menu-item ">
                <i class="bi bi-truck"></i>
                <span>Supplier</span>
            </a>

            <!-- CABANG -->
            <a href="../cabang/" class="menu-item">
                <i class="bi bi-shop"></i>
                <span>Cabang</span>
            </a>

            <!-- PENGGUNA -->
            <a href="./" class="menu-item active">
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
<main class="main user-page">
<div class="page-container">
    <div class="page-header">
        <div>
            <div class="breadcrumb">Dashboard <i class="bi bi-chevron-right"></i> Master Data <i class="bi bi-chevron-right"></i> User</div>
            <h1>Data User</h1>
            <p>Kelola pengguna dan hak akses sistem.</p>
        </div>
        <button class="btn-primary" type="button" onclick="openModal()"><i class="bi bi-person-plus"></i> Tambah User</button>
    </div>

    <?php if ($notify !== ''): ?>
        <div class="user-alert <?= $notifyType === 'error' ? 'user-alert-error' : 'user-alert-success' ?>">
            <?= h($notify) ?>
        </div>
    <?php elseif (isset($_GET['success'])): ?>
        <?php $successMap=['added'=>'User berhasil ditambahkan.','updated'=>'Data user berhasil diperbarui.','deleted'=>'User berhasil dihapus.']; $msg=$successMap[$_GET['success']]??''; ?>
        <?php if ($msg): ?><div class="user-alert user-alert-success"><?= h($msg) ?></div><?php endif; ?>
    <?php endif; ?>

    <div class="statistics">
        <div class="stat-card"><div class="stat-icon blue"><i class="bi bi-people"></i></div><div><span>Total User</span><strong><?= $totalUsers ?></strong></div></div>
        <div class="stat-card"><div class="stat-icon green"><i class="bi bi-person-check"></i></div><div><span>User Aktif</span><strong><?= $activeUsers ?></strong></div></div>
        <div class="stat-card"><div class="stat-icon orange"><i class="bi bi-shield-check"></i></div><div><span>Role</span><strong><?= $totalRoles ?></strong></div></div>
    </div>

    <div class="card user-data-card">
        <div class="card-header user-list-header">
            <div>
                <h2>Daftar User</h2>
                <p>Daftar pengguna yang memiliki akses ke sistem.</p>
            </div>

            <form method="get" class="filter-area">
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" value="<?= h($search) ?>" placeholder="Cari user...">
                </div>

                <select name="role" onchange="this.form.submit()">
                    <option value="">Semua Role</option>
                    <?php foreach($roles as $role): ?>
                        <option value="<?= (int)$role['id'] ?>" <?= $roleFilter === (string)$role['id'] ? 'selected' : '' ?>><?= h($role['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="status" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Aktif</option>
                    <option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Tidak Aktif</option>
                </select>

                <button type="submit" class="action-search"><i class="bi bi-search"></i> Cari</button>

                <a
                    class="reset-filter <?= $hasFilters ? '' : 'disabled' ?>"
                    href="<?= $hasFilters ? 'index.php' : '#' ?>"
                    <?= $hasFilters ? '' : 'aria-disabled="true" tabindex="-1"' ?>
                >
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </a>
            </form>
        </div>

        <div class="table-container">
            <table>
                <thead><tr><th>No</th><th>User</th><th>Email</th><th>Role</th><th>Cabang</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php if (!$users): ?>
                    <tr><td colspan="8" class="user-empty-state">Data user tidak ditemukan.</td></tr>
                <?php else: foreach($users as $i=>$user): ?>
                    <?php $initials=''; foreach(preg_split('/\s+/', trim($user['name'])) as $part){ if($part!=='') $initials .= strtoupper(substr($part,0,1)); if(strlen($initials)>=2) break; } ?>
                    <tr>
                        <td><?= $offset+$i+1 ?></td>
                        <td><div class="user-info"><div class="avatar <?= strtolower(str_replace(' ','-',(string)$user['role_name'])) ?>"><?= h($initials ?: '?') ?></div><div><strong><?= h($user['name']) ?></strong><small><?= h($user['role_name'] ?: 'Tanpa Role') ?></small></div></div></td>
                        <td><?= h($user['email']) ?></td>
                        <td><span class="role <?= $user['role_name']==='Admin'?'admin-role':($user['role_name']==='Finance'?'finance-role':'branch-role') ?>"><?= h($user['role_name'] ?: '-') ?></span></td>
                        <td><?= $user['cabang_id']===null ? '<span class="all-branch">Semua Cabang</span>' : h(($user['cabang_code'] ? $user['cabang_code'].' - ' : '').($user['cabang_name'] ?: '-')) ?></td>
                        <td><span class="status <?= $user['status']==='active'?'active':'inactive' ?>"><?= $user['status']==='active'?'Aktif':'Tidak Aktif' ?></span></td>
                        <td><?= $user['created_at'] ? date('d M Y, H:i', strtotime($user['created_at'])) : '-' ?></td>
                        <td><div class="actions">
                            <a class="action-btn view" title="Detail" href="?detail=<?= (int)$user['id'] ?><?= $search!==''?'&search='.urlencode($search):'' ?><?= $roleFilter!==''?'&role='.urlencode($roleFilter):'' ?><?= $statusFilter!==''?'&status='.urlencode($statusFilter):'' ?>&page=<?= $page ?>"><i class="bi bi-eye"></i></a>
                            <a class="action-btn edit" title="Edit" href="?edit=<?= (int)$user['id'] ?><?= $search!==''?'&search='.urlencode($search):'' ?><?= $roleFilter!==''?'&role='.urlencode($roleFilter):'' ?><?= $statusFilter!==''?'&status='.urlencode($statusFilter):'' ?>&page=<?= $page ?>"><i class="bi bi-pencil"></i></a>
                            <a class="action-btn delete" title="Hapus" href="?delete=<?= (int)$user['id'] ?><?= $search!==''?'&search='.urlencode($search):'' ?><?= $roleFilter!==''?'&role='.urlencode($roleFilter):'' ?><?= $statusFilter!==''?'&status='.urlencode($statusFilter):'' ?>&page=<?= $page ?>"><i class="bi bi-trash"></i></a>
                        </div></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-footer">
            <span>Menampilkan <strong><?= $from ?></strong>–<strong><?= $to ?></strong> dari <strong><?= $totalFiltered ?></strong> user</span>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= h(build_page_url($page-1,$search,$roleFilter,$statusFilter)) ?>" aria-label="Halaman sebelumnya"><i class="bi bi-chevron-left"></i></a>
                <?php else: ?>
                    <a href="#" class="disabled" aria-disabled="true" tabindex="-1"><i class="bi bi-chevron-left"></i></a>
                <?php endif; ?>

                <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);

                    if ($startPage > 1) {
                        echo '<a href="' . h(build_page_url(1,$search,$roleFilter,$statusFilter)) . '">1</a>';
                        if ($startPage > 2) echo '<span class="page-ellipsis">...</span>';
                    }

                    for ($p = $startPage; $p <= $endPage; $p++) {
                        echo '<a href="' . h(build_page_url($p,$search,$roleFilter,$statusFilter)) . '" class="' . ($p === $page ? 'active-page' : '') . '">' . $p . '</a>';
                    }

                    if ($endPage < $totalPages) {
                        if ($endPage < $totalPages - 1) echo '<span class="page-ellipsis">...</span>';
                        echo '<a href="' . h(build_page_url($totalPages,$search,$roleFilter,$statusFilter)) . '">' . $totalPages . '</a>';
                    }
                ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= h(build_page_url($page+1,$search,$roleFilter,$statusFilter)) ?>" aria-label="Halaman berikutnya"><i class="bi bi-chevron-right"></i></a>
                <?php else: ?>
                    <a href="#" class="disabled" aria-disabled="true" tabindex="-1"><i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</main>

<!-- ADD MODAL -->
<div class="modal user-add-modal" id="userModal">
    <div class="modal-content">
        <div class="modal-header"><div><h2>Tambah User</h2><p>Tambahkan pengguna baru ke sistem.</p></div><button type="button" class="close-btn" onclick="closeModal()"><i class="bi bi-x-lg"></i></button></div>
        <form method="post" id="addUserForm">
            <input type="hidden" name="action" value="add_user"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="form-group"><label>Nama Lengkap</label><input type="text" name="name" placeholder="Masukkan nama lengkap" required></div>
            <div class="form-row"><div class="form-group"><label>Email</label><input type="email" name="email" placeholder="email@vendorcat.com" required></div><div class="form-group"><label>Nomor Telepon</label><input type="text" name="phone" placeholder="08xxxxxxxxxx" required></div></div>
            <div class="form-row"><div class="form-group"><label>Role</label><select name="role_id" required><option value="">Pilih Role</option><?php foreach($roles as $role): ?><option value="<?= (int)$role['id'] ?>"><?= h($role['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Cabang</label><select name="cabang_id"><option value="">Semua Cabang</option><?php foreach($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>" <?= $branch['status']!=='active'?'disabled':'' ?>><?= h($branch['code'].' - '.$branch['name']) ?><?= $branch['status']!=='active'?' (Nonaktif)':'' ?></option><?php endforeach; ?></select></div></div>
            <div class="form-row"><div class="form-group"><label>Password</label><input type="password" name="password" id="addPassword" placeholder="Masukkan password" required></div><div class="form-group"><label>Konfirmasi Password</label><input type="password" name="password_confirmation" id="addPasswordConfirmation" placeholder="Ulangi password" required></div></div>
            <div class="form-group"><label>Status</label><select name="status"><option value="active">Aktif</option><option value="inactive">Tidak Aktif</option></select></div>
            <div class="modal-footer"><button type="button" class="btn-secondary" onclick="closeModal()">Batal</button><button type="submit" class="btn-primary"><i class="bi bi-person-plus"></i> Simpan User</button></div>
        </form>
    </div>
</div>

<?php if ($detailUser): ?>
<div class="modal-overlay user-modal-overlay show"><div class="modal-box user-modal-box"><div class="modal-header"><div><h2>Detail User</h2><p>Informasi lengkap pengguna.</p></div><a class="modal-close-link" href="<?= h(build_page_url($page,$search,$roleFilter,$statusFilter)) ?>"><i class="bi bi-x-lg"></i></a></div><div class="modal-body"><div class="detail-grid"><div class="detail-item"><small>Nama</small><strong><?= h($detailUser['name']) ?></strong></div><div class="detail-item"><small>Email</small><strong><?= h($detailUser['email']) ?></strong></div><div class="detail-item"><small>Telepon</small><strong><?= h($detailUser['phone']) ?></strong></div><div class="detail-item"><small>Role</small><strong><?= h($detailUser['role_name'] ?: '-') ?></strong></div><div class="detail-item"><small>Cabang</small><strong><?= $detailUser['cabang_name'] ? h(($detailUser['cabang_code']?$detailUser['cabang_code'].' - ':'').$detailUser['cabang_name']) : 'Semua Cabang' ?></strong></div><div class="detail-item"><small>Status</small><strong><?= $detailUser['status']==='active'?'Aktif':'Tidak Aktif' ?></strong></div><div class="detail-item"><small>Dibuat</small><strong><?= $detailUser['created_at'] ? h(date('d M Y, H:i',strtotime($detailUser['created_at']))) : '-' ?></strong></div></div></div><div class="modal-footer"><a href="<?= h(build_page_url($page,$search,$roleFilter,$statusFilter)) ?>" class="btn-secondary user-modal-secondary">Tutup</a></div></div></div>
<?php endif; ?>

<?php if ($editUser): ?>
<div class="modal-overlay user-modal-overlay show"><div class="modal-box user-modal-box"><div class="modal-header"><div><h2>Edit User</h2><p>Perbarui data pengguna.</p></div><a class="modal-close-link" href="<?= h(build_page_url($page,$search,$roleFilter,$statusFilter)) ?>"><i class="bi bi-x-lg"></i></a></div><form method="post"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><div class="modal-body"><div class="form-group"><label>Nama Lengkap</label><input type="text" name="name" value="<?= h($editUser['name']) ?>" required></div><div class="form-row"><div class="form-group"><label>Email</label><input type="email" name="email" value="<?= h($editUser['email']) ?>" required></div><div class="form-group"><label>Nomor Telepon</label><input type="text" name="phone" value="<?= h($editUser['phone']) ?>" required></div></div><div class="form-row"><div class="form-group"><label>Role</label><select name="role_id" required><?php foreach($roles as $role): ?><option value="<?= (int)$role['id'] ?>" <?= (int)$editUser['role_id']===(int)$role['id']?'selected':'' ?>><?= h($role['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Cabang</label><select name="cabang_id"><option value="">Semua Cabang</option><?php foreach($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>" <?= (string)$editUser['cabang_id']===(string)$branch['id']?'selected':'' ?>><?= h($branch['code'].' - '.$branch['name']) ?><?= $branch['status']!=='active'?' (Nonaktif)':'' ?></option><?php endforeach; ?></select></div></div><div class="form-row"><div class="form-group"><label>Password Baru</label><input type="password" name="password" id="editPassword" placeholder="Kosongkan jika tidak diubah"><div class="password-note">Password lama akan dipertahankan jika kolom ini kosong.</div></div><div class="form-group"><label>Konfirmasi Password Baru</label><input type="password" name="password_confirmation" id="editPasswordConfirmation" placeholder="Ulangi password baru"><div class="password-note">Isi hanya jika password baru diubah.</div></div></div><div class="form-group"><label>Status</label><select name="status"><option value="active" <?= $editUser['status']==='active'?'selected':'' ?>>Aktif</option><option value="inactive" <?= $editUser['status']==='inactive'?'selected':'' ?>>Tidak Aktif</option></select></div></div></div><div class="modal-footer"><a href="<?= h(build_page_url($page,$search,$roleFilter,$statusFilter)) ?>" class="btn-secondary user-modal-secondary">Batal</a><button type="submit" class="btn-primary"><i class="bi bi-save"></i> Simpan Perubahan</button></div></form></div></div>
<?php endif; ?>

<?php if ($deleteUser): ?>
<div class="modal-overlay user-modal-overlay show">
    <div class="modal-box user-modal-box small">
        <div class="modal-header">
            <div><h2>Hapus User</h2><p>Konfirmasi penghapusan data pengguna.</p></div>
            <a class="modal-close-link" href="<?= h(build_page_url($page,$search,$roleFilter,$statusFilter)) ?>"><i class="bi bi-x-lg"></i></a>
        </div>
        <div class="modal-body">
            <div class="danger-box">
                <strong><?= h($deleteUser['name']) ?></strong><br>
                Email: <?= h($deleteUser['email']) ?><br>
                Role: <?= h($deleteUser['role_name'] ?: '-') ?>
                <?= $deleteUser['cabang_name'] ? '<br>Cabang: '.h($deleteUser['cabang_name']) : '' ?>
                <?php if ($deleteUserReferences): ?>
                    <br><br><strong>User tidak dapat dihapus.</strong><br>
                    User ini masih digunakan oleh:
                    <ul class="reference-list">
                        <?php foreach ($deleteUserReferences as $table => $count): ?>
                            <li><?= h($table) ?>: <?= (int)$count ?> data</li>
                        <?php endforeach; ?>
                    </ul>
                    <span class="reference-note">Gunakan status Tidak Aktif apabila user sudah tidak digunakan.</span>
                <?php else: ?>
                    <br><br>Data user yang sudah dihapus tidak dapat dikembalikan.
                <?php endif; ?>
            </div>
        </div>
        <div class="modal-footer">
            <a href="<?= h(build_page_url($page,$search,$roleFilter,$statusFilter)) ?>" class="btn-secondary user-modal-secondary">Tutup</a>
            <?php if (!$deleteUserReferences && (int)$deleteUser['id'] !== (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0)): ?>
                <form method="post" class="user-delete-form">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?= (int)$deleteUser['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <button type="submit" class="btn-primary user-danger-btn"><i class="bi bi-trash"></i> Ya, Hapus</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openModal(){
    const modal=document.getElementById('userModal');
    const form=document.getElementById('addUserForm');
    if(!modal||!form)return;
    form.reset();
    form.querySelector('[name="action"]').value='add_user';
    modal.classList.add('show');
}
function closeModal(){
    const modal=document.getElementById('userModal');
    const form=document.getElementById('addUserForm');
    if(form) form.reset();
    if(modal) modal.classList.remove('show');
}
window.addEventListener('click',function(event){
    const modal=document.getElementById('userModal');
    if(event.target===modal) closeModal();
});
document.getElementById('addUserForm')?.addEventListener('submit',function(event){
    const p=document.getElementById('addPassword')?.value||'';
    const c=document.getElementById('addPasswordConfirmation')?.value||'';
    if(p!==c){ event.preventDefault(); alert('Konfirmasi password tidak sama.'); }
});

document.querySelectorAll('.user-modal-overlay form').forEach(function(form){
    form.addEventListener('submit',function(event){
        const p=form.querySelector('[name="password"]')?.value||'';
        const c=form.querySelector('[name="password_confirmation"]')?.value||'';
        if (p !== '' && p !== c) {
            event.preventDefault();
            alert('Konfirmasi password baru tidak sama.');
        }
    });
});
</script>
</body>
</html>
