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
        // Jangan hentikan halaman jika tabel/settings bermasalah.
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

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_roles(array $params = []): never
{
    $url = 'index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

function normalize_role_code(string $code): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9_]/', '_', $code) ?? '';
    $code = preg_replace('/_+/', '_', $code) ?? '';
    return trim($code, '_');
}

function get_permission_catalog(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT id, code, name, module, action
         FROM permissions
         ORDER BY module ASC, action ASC, id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['module']][] = $row;
    }

    return $grouped;
}

function get_valid_permission_ids(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM permissions WHERE id IN ($placeholders)");
    $stmt->execute($ids);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function get_role_permissions(PDO $pdo, int $roleId): array
{
    $stmt = $pdo->prepare('SELECT permission_id FROM role_permissions WHERE role_id = :role_id');
    $stmt->execute([':role_id' => $roleId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function build_role_url(int $page, string $search): string
{
    $params = ['page' => $page];
    if ($search !== '') {
        $params['search'] = $search;
    }
    return 'index.php?' . http_build_query($params);
}

if (empty($_SESSION['csrf_roles'])) {
    $_SESSION['csrf_roles'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_roles'];

$notify = '';
$notifyType = '';

/* ============================================================
   POST / CRUD ROLE
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_roles'] ?? '', $token)) {
        $notify = 'Permintaan tidak valid (CSRF).';
        $notifyType = 'error';
    } else {
        try {
            if ($action === 'add_role') {
                $code = normalize_role_code($_POST['code'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $permissionIds = get_valid_permission_ids($pdo, $_POST['permissions'] ?? []);

                if ($code === '') {
                    throw new Exception('Code role wajib diisi.');
                }
                if (!preg_match('/^[A-Z][A-Z0-9_]{1,49}$/', $code)) {
                    throw new Exception('Code role hanya boleh huruf, angka, dan underscore; minimal 2 karakter.');
                }
                if ($name === '') {
                    throw new Exception('Nama role wajib diisi.');
                }

                $check = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE code = :code OR name = :name');
                $check->execute([':code' => $code, ':name' => $name]);
                if ((int)$check->fetchColumn() > 0) {
                    throw new Exception('Code atau nama role sudah digunakan.');
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare('INSERT INTO roles (code, name, description) VALUES (:code, :name, :description)');
                $stmt->execute([
                    ':code' => $code,
                    ':name' => $name,
                    ':description' => $description,
                ]);
                $roleId = (int)$pdo->lastInsertId();

                if ($permissionIds) {
                    $permStmt = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)');
                    foreach ($permissionIds as $permissionId) {
                        $permStmt->execute([
                            ':role_id' => $roleId,
                            ':permission_id' => $permissionId,
                        ]);
                    }
                }

                $pdo->commit();
                redirect_roles(['success' => 'added']);
            }

            if ($action === 'update_role') {
                $roleId = (int)($_POST['role_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $permissionIds = get_valid_permission_ids($pdo, $_POST['permissions'] ?? []);

                if ($roleId <= 0) {
                    throw new Exception('ID role tidak valid.');
                }
                if ($name === '') {
                    throw new Exception('Nama role wajib diisi.');
                }

                $exists = $pdo->prepare('SELECT id, code FROM roles WHERE id = :id');
                $exists->execute([':id' => $roleId]);
                $existingRole = $exists->fetch(PDO::FETCH_ASSOC);
                if (!$existingRole) {
                    throw new Exception('Role tidak ditemukan.');
                }

                $check = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE name = :name AND id <> :id');
                $check->execute([':name' => $name, ':id' => $roleId]);
                if ((int)$check->fetchColumn() > 0) {
                    throw new Exception('Nama role sudah digunakan role lain.');
                }

                $pdo->beginTransaction();

                // Code role sengaja tidak diubah saat edit agar identitas bisnis tetap stabil.
                $stmt = $pdo->prepare('UPDATE roles SET name = :name, description = :description WHERE id = :id');
                $stmt->execute([
                    ':name' => $name,
                    ':description' => $description,
                    ':id' => $roleId,
                ]);

                $del = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id');
                $del->execute([':role_id' => $roleId]);

                if ($permissionIds) {
                    $permStmt = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)');
                    foreach ($permissionIds as $permissionId) {
                        $permStmt->execute([
                            ':role_id' => $roleId,
                            ':permission_id' => $permissionId,
                        ]);
                    }
                }

                $pdo->commit();
                redirect_roles(['success' => 'updated']);
            }

            if ($action === 'delete_role') {
                $roleId = (int)($_POST['role_id'] ?? 0);
                if ($roleId <= 0) {
                    throw new Exception('ID role tidak valid.');
                }

                $roleStmt = $pdo->prepare('SELECT id, code, name FROM roles WHERE id = :id');
                $roleStmt->execute([':id' => $roleId]);
                $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
                if (!$role) {
                    throw new Exception('Role tidak ditemukan.');
                }

                if ($role['code'] === 'ADMIN') {
                    throw new Exception('Role ADMIN adalah role utama sistem dan tidak dapat dihapus.');
                }

                $userCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id = :role_id');
                $userCheck->execute([':role_id' => $roleId]);
                if ((int)$userCheck->fetchColumn() > 0) {
                    throw new Exception('Role tidak dapat dihapus karena masih digunakan oleh pengguna.');
                }

                $pdo->beginTransaction();

                $permDelete = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id');
                $permDelete->execute([':role_id' => $roleId]);

                $roleDelete = $pdo->prepare('DELETE FROM roles WHERE id = :id');
                $roleDelete->execute([':id' => $roleId]);

                if ($roleDelete->rowCount() === 0) {
                    throw new Exception('Role tidak ditemukan atau sudah dihapus.');
                }

                $pdo->commit();
                redirect_roles(['success' => 'deleted']);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $notify = 'Operasi database gagal. Periksa struktur tabel roles, permissions, dan role_permissions.';
            $notifyType = 'error';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $notify = $e->getMessage();
            $notifyType = 'error';
        }
    }
}

/* ============================================================
   FILTER + PAGINATION
============================================================ */
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 6;

$where = '';
$params = [];
if ($search !== '') {
    $where = 'WHERE r.code LIKE :search_code OR r.name LIKE :search_name OR r.description LIKE :search_description';
    $searchValue = '%' . $search . '%';
    $params[':search_code'] = $searchValue;
    $params[':search_name'] = $searchValue;
    $params[':search_description'] = $searchValue;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM roles r $where");
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$countStmt->execute();
$totalFiltered = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listSql = "SELECT
                r.id,
                r.code,
                r.name,
                r.description,
                COUNT(DISTINCT u.id) AS user_count,
                COUNT(DISTINCT rp.permission_id) AS permission_count
            FROM roles r
            LEFT JOIN users u ON u.role_id = r.id
            LEFT JOIN role_permissions rp ON rp.role_id = r.id
            $where
            GROUP BY r.id, r.code, r.name, r.description
            ORDER BY r.id ASC
            LIMIT :limit OFFSET :offset";
$listStmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$roles = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$permissionCatalog = get_permission_catalog($pdo);
$totalRoles = (int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalPermissions = (int)$pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();

/* ============================================================
   SERVER-SIDE MODAL DATA
============================================================ */
$detailRole = null;
$editRole = null;
$deleteRole = null;
$editRolePermissions = [];

if (isset($_GET['detail']) && ctype_digit((string)$_GET['detail'])) {
    $detailId = (int)$_GET['detail'];
    $stmt = $pdo->prepare(
        'SELECT r.id, r.code, r.name, r.description,
                COUNT(DISTINCT u.id) AS user_count,
                COUNT(DISTINCT rp.permission_id) AS permission_count
         FROM roles r
         LEFT JOIN users u ON u.role_id = r.id
         LEFT JOIN role_permissions rp ON rp.role_id = r.id
         WHERE r.id = :id
         GROUP BY r.id, r.code, r.name, r.description'
    );
    $stmt->execute([':id' => $detailId]);
    $detailRole = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($detailRole) {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.code, p.name, p.module, p.action
             FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id = :role_id
             ORDER BY p.module, p.action, p.id'
        );
        $stmt->execute([':role_id' => $detailRole['id']]);
        $detailRole['permissions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            'SELECT id, name, email, phone, status, created_at
             FROM users
             WHERE role_id = :role_id
             ORDER BY name ASC, id ASC'
        );
        $stmt->execute([':role_id' => $detailRole['id']]);
        $detailRole['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT id, code, name, description FROM roles WHERE id = :id');
    $stmt->execute([':id' => $editId]);
    $editRole = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editRole) {
        $editRolePermissions = get_role_permissions($pdo, (int)$editRole['id']);
    }
}

if (isset($_GET['delete']) && ctype_digit((string)$_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];
    $stmt = $pdo->prepare(
        'SELECT r.id, r.code, r.name, r.description, COUNT(u.id) AS user_count
         FROM roles r
         LEFT JOIN users u ON u.role_id = r.id
         WHERE r.id = :id
         GROUP BY r.id, r.code, r.name, r.description'
    );
    $stmt->execute([':id' => $deleteId]);
    $deleteRole = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$hasSearch = $search !== '';
$from = $totalFiltered > 0 ? $offset + 1 : 0;
$to = min($offset + $perPage, $totalFiltered);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c1a2d">
    <title>Role & Hak Akses | <?= h($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../assets/css/admin.css?v=20260907">
    <link rel="stylesheet" href="style.css?v=20260912">
</head>
<body class="role-page">
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="<?= h($companyLogo) ?>" class="img-fluid" alt="<?= h($companyName) ?>">
        <div class="brand-text">
            <div class="brand-name"><?= h($companyName) ?></div>
            <small><?= h($companyTagline) ?></small>
        </div>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-section">
            <div class="menu-title">MENU UTAMA</div>
            <a href="../admin.php" class="menu-item"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a>
        </div>
        <div class="menu-section">
            <div class="menu-title">MASTER DATA</div>
            <a href="../customers/" class="menu-item"><i class="bi bi-people"></i><span>Pelanggan</span></a>
            <a href="../vehicles/" class="menu-item"><i class="bi bi-car-front"></i><span>Kendaraan</span></a>
            <a href="../product/" class="menu-item"><i class="bi bi-box-seam"></i><span>Produk</span></a>
            <a href="../service/" class="menu-item"><i class="bi bi-tools"></i><span>Jasa</span></a>
            <a href="../supplier/" class="menu-item"><i class="bi bi-truck"></i><span>Supplier</span></a>
            <a href="../cabang/" class="menu-item"><i class="bi bi-shop"></i><span>Cabang</span></a>
            <a href="../users/" class="menu-item"><i class="bi bi-person-badge"></i><span>Pengguna</span></a>
            <a href="./" class="menu-item active"><i class="bi bi-shield-lock"></i><span>Role & Hak Akses</span></a>
        </div>
        <div class="menu-section">
            <div class="menu-title">TRANSAKSI</div>
            <a href="../orders/" class="menu-item"><i class="bi bi-cart3"></i><span>Penjualan</span></a>
            <a href="../purchases/" class="menu-item"><i class="bi bi-bag"></i><span>Pembelian</span></a>
            <a href="../stoks-transfer/" class="menu-item"><i class="bi bi-arrow-left-right"></i><span>Transfer Stok</span></a>
            <a href="../payments/" class="menu-item"><i class="bi bi-credit-card"></i><span>Pembayaran</span></a>
            <a href="../expenses/" class="menu-item"><i class="bi bi-receipt"></i><span>Pengeluaran</span></a>
            <a href="../stoks/" class="menu-item"><i class="bi bi-boxes"></i><span>Stok / Persediaan</span></a>
        </div>
        <div class="menu-section">
            <div class="menu-title">LAPORAN</div>
            <a href="../reports/" class="menu-item"><i class="bi bi-bar-chart-line"></i><span>Laporan</span></a>
            <a href="../reports/" class="menu-item"><i class="bi bi-pie-chart"></i><span>Rekap Cabang</span></a>
        </div>
        <div class="menu-section">
            <div class="menu-title">PENGATURAN</div>
            <a href="../settings/" class="menu-item"><i class="bi bi-gear"></i><span>Pengaturan</span></a>
            <a href="../logout.php" class="menu-item"><i class="bi bi-box-arrow-right"></i><span>Keluar</span></a>
        </div>
        <div class="sidebar-footer">
            <div class="paint-decoration"><i class="bi bi-paint-bucket"></i></div>
            <strong>Better Paint</strong>
            <span>Brighter Drive</span>
        </div>
    </nav>
</aside>

<main class="main">
<div class="page-container">
    <div class="page-header role-page-header">
        <div>
            <div class="breadcrumb">Dashboard <i class="bi bi-chevron-right"></i> Master Data <i class="bi bi-chevron-right"></i> Role</div>
            <h1>Role & Hak Akses</h1>
            <p>Kelola role dan hak akses pengguna sistem.</p>
        </div>
        <button class="btn-primary role-add-btn" type="button" onclick="openRoleModal()">
            <i class="bi bi-plus-lg"></i> Tambah Role
        </button>
    </div>

    <?php if ($notify !== ''): ?>
        <div class="role-alert role-alert-<?= $notifyType === 'error' ? 'error' : 'success' ?>">
            <i class="bi <?= $notifyType === 'error' ? 'bi-exclamation-circle' : 'bi-check-circle' ?>"></i>
            <span><?= h($notify) ?></span>
        </div>
    <?php elseif (isset($_GET['success'])): ?>
        <?php
        $successMap = ['added' => 'Role berhasil ditambahkan.', 'updated' => 'Role berhasil diperbarui.', 'deleted' => 'Role berhasil dihapus.'];
        $successMessage = $successMap[$_GET['success']] ?? '';
        ?>
        <?php if ($successMessage): ?>
            <div class="role-alert role-alert-success"><i class="bi bi-check-circle"></i><span><?= h($successMessage) ?></span></div>
        <?php endif; ?>
    <?php endif; ?>

    <section class="role-stats">
        <div class="role-stat-card"><div class="role-stat-icon blue"><i class="bi bi-shield-lock"></i></div><div><span>Total Role</span><strong><?= $totalRoles ?></strong></div></div>
        <div class="role-stat-card"><div class="role-stat-icon green"><i class="bi bi-people"></i></div><div><span>Total User</span><strong><?= $totalUsers ?></strong></div></div>
        <div class="role-stat-card"><div class="role-stat-icon orange"><i class="bi bi-key"></i></div><div><span>Total Permission</span><strong><?= $totalPermissions ?></strong></div></div>
    </section>

    <section class="role-panel">
        <div class="role-panel-header">
            <div>
                <h2>Daftar Role</h2>
                <p>Kelola role dan pembagian hak akses berdasarkan kebutuhan pengguna.</p>
            </div>
            <form method="get" class="role-toolbar">
                <label class="role-search">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" value="<?= h($search) ?>" placeholder="Cari code, nama, atau deskripsi...">
                </label>
                <button type="submit" class="role-tool-btn primary"><i class="bi bi-search"></i> Cari</button>
                <a href="<?= $hasSearch ? 'index.php' : '#' ?>" class="role-tool-btn reset <?= $hasSearch ? '' : 'disabled' ?>" <?= $hasSearch ? '' : 'aria-disabled="true" tabindex="-1"' ?>><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
            </form>
        </div>

        <div class="role-grid">
            <?php if (!$roles): ?>
                <div class="role-empty">
                    <div class="role-empty-icon"><i class="bi bi-shield-x"></i></div>
                    <strong>Role tidak ditemukan</strong>
                    <span><?= $hasSearch ? 'Coba gunakan kata kunci yang berbeda.' : 'Belum ada data role.' ?></span>
                </div>
            <?php else: ?>
                <?php foreach ($roles as $role): ?>
                    <?php
                    $roleIcon = 'bi-shield-check';
                    $roleTone = 'admin';
                    if (stripos($role['code'], 'CABANG') !== false || stripos($role['name'], 'cabang') !== false) {
                        $roleIcon = 'bi-building';
                        $roleTone = 'branch';
                    } elseif (stripos($role['code'], 'FINANCE') !== false || stripos($role['name'], 'finance') !== false) {
                        $roleIcon = 'bi-cash-stack';
                        $roleTone = 'finance';
                    }

                    $permStmt = $pdo->prepare(
                        'SELECT p.name FROM permissions p
                         INNER JOIN role_permissions rp ON rp.permission_id = p.id
                         WHERE rp.role_id = :role_id
                         ORDER BY p.module, p.action, p.id
                         LIMIT 5'
                    );
                    $permStmt->execute([':role_id' => $role['id']]);
                    $previewPermissions = $permStmt->fetchAll(PDO::FETCH_COLUMN);
                    ?>
                    <article class="role-card">
                        <div class="role-card-top">
                            <div class="role-card-icon <?= h($roleTone) ?>"><i class="bi <?= h($roleIcon) ?>"></i></div>
                            <span class="role-active-badge">Aktif</span>
                        </div>
                        <div class="role-code-badge"><?= h($role['code']) ?></div>
                        <h3><?= h($role['name']) ?></h3>
                        <p class="role-description"><?= h($role['description'] ?: 'Tidak ada deskripsi role.') ?></p>

                        <div class="role-metrics">
                            <div><small>User</small><strong><?= (int)$role['user_count'] ?> User</strong></div>
                            <div><small>Permission</small><strong><?= (int)$role['permission_count'] ?> Akses</strong></div>
                        </div>

                        <div class="role-section-label">Hak Akses Utama</div>
                        <div class="role-permission-tags">
                            <?php if (!$previewPermissions): ?>
                                <span class="role-permission-empty">Belum ada permission</span>
                            <?php else: ?>
                                <?php foreach ($previewPermissions as $permissionName): ?>
                                    <span><i class="bi bi-check2"></i><?= h($permissionName) ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="role-card-actions">
                            <a class="role-outline-btn" href="?detail=<?= (int)$role['id'] ?>&page=<?= $page ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"><i class="bi bi-eye"></i> Detail</a>
                            <a class="role-outline-btn" href="?edit=<?= (int)$role['id'] ?>&page=<?= $page ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"><i class="bi bi-pencil"></i> Edit</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="role-table-footer">
            <span>Menampilkan <strong><?= $from ?></strong>–<strong><?= $to ?></strong> dari <strong><?= $totalFiltered ?></strong> role</span>
            <?php if ($totalPages > 1): ?>
                <div class="role-pagination">
                    <a href="<?= $page > 1 ? h(build_role_url($page - 1, $search)) : '#' ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-left"></i></a>
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <a href="<?= h(build_role_url($p, $search)) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    <a href="<?= $page < $totalPages ? h(build_role_url($page + 1, $search)) : '#' ?>" class="<?= $page >= $totalPages ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="role-info-card">
        <div class="role-info-icon"><i class="bi bi-info-circle"></i></div>
        <div><strong>Tentang Role & Hak Akses</strong><p>Role menggunakan <strong>code</strong> sebagai identitas bisnis yang stabil. Hak akses disimpan pada tabel <strong>role_permissions</strong> dan terhubung ke tabel <strong>permissions</strong>.</p></div>
    </section>
</div>
</main>

<!-- ADD MODAL -->
<div class="role-modal" id="roleAddModal">
    <div class="role-modal-dialog role-modal-large">
        <div class="role-modal-header">
            <div><h2>Tambah Role</h2><p>Tambahkan role baru dan tentukan permission yang dimilikinya.</p></div>
            <button type="button" class="role-modal-close" onclick="closeRoleModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="post" id="roleAddForm" class="role-form">
            <input type="hidden" name="action" value="add_role">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="role-form-grid">
                <div class="role-form-group">
                    <label>Code Role</label>
                    <input type="text" name="code" maxlength="50" placeholder="Contoh: SUPERVISOR" required>
                    <small class="role-field-note">Gunakan huruf kapital, angka, dan underscore. Contoh: ADMIN_CABANG.</small>
                </div>
                <div class="role-form-group">
                    <label>Nama Role</label>
                    <input type="text" name="name" maxlength="100" placeholder="Contoh: Supervisor" required>
                </div>
                <div class="role-form-group role-form-full">
                    <label>Deskripsi</label>
                    <textarea name="description" maxlength="255" placeholder="Jelaskan fungsi role ini..."></textarea>
                </div>
            </div>

            <div class="role-permission-builder">
                <div class="role-builder-head">
                    <div><strong>Hak Akses</strong><span>Pilih permission yang boleh digunakan oleh role ini.</span></div>
                    <label class="role-check-all"><input type="checkbox" id="checkAllAdd"> Pilih semua</label>
                </div>
                <div class="role-permission-groups">
                    <?php foreach ($permissionCatalog as $module => $permissions): ?>
                        <div class="role-permission-group">
                            <div class="role-permission-group-title"><span><?= h($module) ?></span><small><?= count($permissions) ?> permission</small></div>
                            <div class="role-permission-options">
                                <?php foreach ($permissions as $permission): ?>
                                    <label class="role-permission-option">
                                        <input type="checkbox" name="permissions[]" value="<?= (int)$permission['id'] ?>" class="add-permission-check">
                                        <span><strong><?= h($permission['name']) ?></strong><small><?= h($permission['action']) ?></small></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="role-modal-footer">
                <button type="button" class="role-btn secondary" onclick="closeRoleModal()">Batal</button>
                <button type="submit" class="role-btn primary"><i class="bi bi-save"></i> Simpan Role</button>
            </div>
        </form>
    </div>
</div>

<?php if ($detailRole): ?>
<div class="role-server-modal">
    <div class="role-modal-dialog">
        <div class="role-modal-header">
            <div><h2>Detail Role</h2><p>Informasi role dan permission yang terhubung.</p></div>
            <a class="role-modal-close" href="<?= h(build_role_url($page, $search)) ?>"><i class="bi bi-x-lg"></i></a>
        </div>
        <div class="role-modal-body">
            <div class="role-detail-head">
                <div class="role-detail-icon"><i class="bi bi-shield-check"></i></div>
                <div><strong><?= h($detailRole['name']) ?></strong><span><?= h($detailRole['code']) ?></span><span><?= h($detailRole['description'] ?: 'Tidak ada deskripsi role.') ?></span></div>
            </div>
            <div class="role-detail-stats">
                <div><small>User</small><strong><?= (int)$detailRole['user_count'] ?></strong></div>
                <div><small>Permission</small><strong><?= (int)$detailRole['permission_count'] ?></strong></div>
            </div>

            <div class="role-detail-title">Pengguna dengan Role Ini</div>
            <div class="role-detail-users">
                <?php if (empty($detailRole['users'])): ?>
                    <div class="role-detail-user-empty">Belum ada pengguna dengan role ini.</div>
                <?php else: ?>
                    <?php foreach ($detailRole['users'] as $detailUser): ?>
                        <?php
                        $parts = preg_split('/\s+/', trim((string)$detailUser['name']));
                        $initials = '';
                        foreach ($parts as $part) {
                            if ($part !== '') {
                                $initials .= strtoupper(substr($part, 0, 1));
                            }
                            if (strlen($initials) >= 2) break;
                        }
                        ?>
                        <div class="role-detail-user">
                            <div class="role-detail-user-avatar"><?= h($initials ?: '?') ?></div>
                            <div class="role-detail-user-main">
                                <strong><?= h($detailUser['name']) ?></strong>
                                <span><?= h($detailUser['email']) ?></span>
                            </div>
                            <span class="role-detail-user-status <?= $detailUser['status'] === 'active' ? 'active' : 'inactive' ?>">
                                <?= $detailUser['status'] === 'active' ? 'Aktif' : 'Tidak Aktif' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="role-detail-title">Permission</div>
            <div class="role-detail-permissions">
                <?php if (empty($detailRole['permissions'])): ?>
                    <span class="role-permission-empty">Belum ada permission.</span>
                <?php else: ?>
                    <?php foreach ($detailRole['permissions'] as $permission): ?>
                        <span><i class="bi bi-check2-circle"></i><?= h($permission['name']) ?> <small><?= h($permission['action']) ?></small></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="role-modal-footer"><a class="role-btn secondary" href="<?= h(build_role_url($page, $search)) ?>">Tutup</a></div>
    </div>
</div>
<?php endif; ?>

<?php if ($editRole): ?>
<div class="role-server-modal">
    <div class="role-modal-dialog role-modal-large">
        <div class="role-modal-header">
            <div><h2>Edit Role</h2><p>Perbarui nama, deskripsi, dan hak akses. Code role dikunci agar identitas tetap stabil.</p></div>
            <a class="role-modal-close" href="<?= h(build_role_url($page, $search)) ?>"><i class="bi bi-x-lg"></i></a>
        </div>
        <form method="post" class="role-form">
            <input type="hidden" name="action" value="update_role">
            <input type="hidden" name="role_id" value="<?= (int)$editRole['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="role-form-grid">
                <div class="role-form-group">
                    <label>Code Role</label>
                    <input type="text" value="<?= h($editRole['code']) ?>" readonly class="role-readonly-field">
                </div>
                <div class="role-form-group">
                    <label>Nama Role</label>
                    <input type="text" name="name" value="<?= h($editRole['name']) ?>" maxlength="100" required>
                </div>
                <div class="role-form-group role-form-full">
                    <label>Deskripsi</label>
                    <textarea name="description" maxlength="255"><?= h($editRole['description']) ?></textarea>
                </div>
            </div>
            <div class="role-permission-builder">
                <div class="role-builder-head">
                    <div><strong>Hak Akses</strong><span>Sesuaikan permission yang dimiliki role ini.</span></div>
                    <label class="role-check-all"><input type="checkbox" id="checkAllEdit"> Pilih semua</label>
                </div>
                <div class="role-permission-groups">
                    <?php foreach ($permissionCatalog as $module => $permissions): ?>
                        <div class="role-permission-group">
                            <div class="role-permission-group-title"><span><?= h($module) ?></span><small><?= count($permissions) ?> permission</small></div>
                            <div class="role-permission-options">
                                <?php foreach ($permissions as $permission): ?>
                                    <label class="role-permission-option">
                                        <input type="checkbox" name="permissions[]" value="<?= (int)$permission['id'] ?>" class="edit-permission-check" <?= in_array((int)$permission['id'], $editRolePermissions, true) ? 'checked' : '' ?> >
                                        <span><strong><?= h($permission['name']) ?></strong><small><?= h($permission['action']) ?></small></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="role-modal-footer"><a class="role-btn secondary" href="<?= h(build_role_url($page, $search)) ?>">Batal</a><button type="submit" class="role-btn primary"><i class="bi bi-save"></i> Simpan Perubahan</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($deleteRole): ?>
<div class="role-server-modal">
    <div class="role-modal-dialog role-modal-small">
        <div class="role-modal-header">
            <div><h2>Hapus Role</h2><p>Konfirmasi penghapusan role.</p></div>
            <a class="role-modal-close" href="<?= h(build_role_url($page, $search)) ?>"><i class="bi bi-x-lg"></i></a>
        </div>
        <div class="role-modal-body">
            <div class="role-danger-box">
                <strong><?= h($deleteRole['name']) ?></strong>
                <span class="role-danger-code"><?= h($deleteRole['code']) ?></span>
                <p><?= h($deleteRole['description'] ?: 'Tidak ada deskripsi role.') ?></p>
                <?php if ($deleteRole['code'] === 'ADMIN'): ?>
                    <div class="role-danger-note"><i class="bi bi-lock"></i> Role ADMIN adalah role utama sistem dan tidak dapat dihapus.</div>
                <?php elseif ((int)$deleteRole['user_count'] > 0): ?>
                    <div class="role-danger-note"><i class="bi bi-lock"></i> Role ini masih digunakan oleh <strong><?= (int)$deleteRole['user_count'] ?> user</strong>, sehingga tidak dapat dihapus.</div>
                <?php else: ?>
                    <div class="role-danger-note"><i class="bi bi-exclamation-triangle"></i> Role yang dihapus tidak dapat dikembalikan.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="role-modal-footer">
            <a class="role-btn secondary" href="<?= h(build_role_url($page, $search)) ?>">Batal</a>
            <?php if ($deleteRole['code'] !== 'ADMIN' && (int)$deleteRole['user_count'] === 0): ?>
                <form method="post" style="margin:0;padding:0">
                    <input type="hidden" name="action" value="delete_role">
                    <input type="hidden" name="role_id" value="<?= (int)$deleteRole['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <button type="submit" class="role-btn danger"><i class="bi bi-trash"></i> Ya, Hapus</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openRoleModal() {
    const modal = document.getElementById('roleAddModal');
    const form = document.getElementById('roleAddForm');
    if (!modal || !form) return;
    form.reset();
    modal.classList.add('show');
}

function closeRoleModal() {
    const modal = document.getElementById('roleAddModal');
    const form = document.getElementById('roleAddForm');
    if (form) form.reset();
    if (modal) modal.classList.remove('show');
}

window.addEventListener('click', function(event) {
    const modal = document.getElementById('roleAddModal');
    if (event.target === modal) closeRoleModal();
});

function syncCheckAll(masterId, selector) {
    const master = document.getElementById(masterId);
    const checks = Array.from(document.querySelectorAll(selector));
    if (!master || !checks.length) return;
    master.checked = checks.every(check => check.checked);
    master.indeterminate = checks.some(check => check.checked) && !master.checked;
}

document.getElementById('checkAllAdd')?.addEventListener('change', function() {
    document.querySelectorAll('.add-permission-check').forEach(check => check.checked = this.checked);
});
document.querySelectorAll('.add-permission-check').forEach(check => {
    check.addEventListener('change', () => syncCheckAll('checkAllAdd', '.add-permission-check'));
});

document.getElementById('checkAllEdit')?.addEventListener('change', function() {
    document.querySelectorAll('.edit-permission-check').forEach(check => check.checked = this.checked);
});
document.querySelectorAll('.edit-permission-check').forEach(check => {
    check.addEventListener('change', () => syncCheckAll('checkAllEdit', '.edit-permission-check'));
});

syncCheckAll('checkAllAdd', '.add-permission-check');
syncCheckAll('checkAllEdit', '.edit-permission-check');
</script>
</body>
</html>
