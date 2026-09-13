<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/config.php';

$pageTitle = 'Stok';

/* =====================================================
   IDENTITAS PERUSAHAAN - DARI SETTINGS
   Hanya untuk BRAND SIDEBAR agar halaman tetap aman.
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

function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
}

function money_qty($value): string {
    $n = (float)$value;
    return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
}

function redirect_stock(array $params = []): never {
    $url = 'index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

function detect_category_table(PDO $pdo): ?string {
    foreach (['categories', 'product_categories'] as $table) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name"
        );
        $stmt->execute([':table_name' => $table]);
        if ((int)$stmt->fetchColumn() > 0) {
            return $table;
        }
    }
    return null;
}

function enum_values(PDO $pdo, string $table, string $column): array {
    $allowed = [
        'stock_movements' => ['movement_type', 'reference_type'],
    ];
    if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1"
    );
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);
    $type = (string)$stmt->fetchColumn();
    if (!preg_match('/^enum\((.*)\)$/i', $type, $m)) {
        return [];
    }
    $values = str_getcsv(trim($m[1]), ',', "'", "\\");
    return array_values(array_filter(array_map(
        static fn($v) => str_replace(["\\'", "\\\\"], ["'", "\\"], trim((string)$v)),
        $values
    ), static fn($v) => $v !== ''));
}

function match_enum(array $available, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        foreach ($available as $actual) {
            if (strcasecmp((string)$actual, (string)$candidate) === 0) {
                return (string)$actual;
            }
        }
    }
    return null;
}

function stock_movement_delta(string $movementType, float $quantity): float {
    $type = strtolower(trim($movementType));
    $outgoing = [
        'out', 'keluar', 'sale', 'sales', 'penjualan',
        'transfer_out', 'stock_transfer_out', 'purchase_return',
        'adjustment_out', 'stock_adjustment_out', 'damage_out',
    ];
    foreach ($outgoing as $needle) {
        if ($type === $needle || str_contains($type, $needle)) {
            return -abs($quantity);
        }
    }
    if ($quantity < 0) {
        return $quantity;
    }
    return abs($quantity);
}

function insert_stock_movement(
    PDO $pdo,
    int $branchStockId,
    float $delta,
    array $incomingCandidates,
    array $outgoingCandidates,
    array $referenceCandidates,
    int $referenceId,
    string $notes
): void {
    if ($branchStockId <= 0 || abs($delta) < 0.000001) {
        return;
    }

    $types = enum_values($pdo, 'stock_movements', 'movement_type');
    $movementType = match_enum(
        $types,
        $delta > 0 ? $incomingCandidates : $outgoingCandidates
    );

    $quantity = abs($delta);

    if (!$movementType) {
        $movementType = match_enum($types, ['adjustment', 'stock_adjustment', 'manual_adjustment']);
        if ($movementType) {
            $quantity = $delta;
        }
    }

    if (!$movementType && $types) {
        throw new Exception('ENUM stock_movements.movement_type tidak memiliki nilai yang sesuai.');
    }

    if (!$movementType) {
        $movementType = $delta > 0
            ? ($incomingCandidates[0] ?? 'in')
            : ($outgoingCandidates[0] ?? 'out');
    }

    $references = enum_values($pdo, 'stock_movements', 'reference_type');
    $referenceType = match_enum($references, $referenceCandidates);

    if (!$referenceType && $references) {
        throw new Exception('ENUM stock_movements.reference_type tidak memiliki nilai yang sesuai.');
    }

    $referenceType = $referenceType ?? ($referenceCandidates[0] ?? 'stock_adjustment');

    $stmt = $pdo->prepare(
        "INSERT INTO stock_movements
            (branch_stock_id, movement_type, quantity, reference_type, reference_id, notes, movement_date, created_by)
         VALUES
            (:branch_stock_id, :movement_type, :quantity, :reference_type, :reference_id, :notes, NOW(), :created_by)"
    );
    $stmt->execute([
        ':branch_stock_id' => $branchStockId,
        ':movement_type' => $movementType,
        ':quantity' => $quantity,
        ':reference_type' => $referenceType,
        ':reference_id' => $referenceId,
        ':notes' => $notes,
        ':created_by' => current_user_id(),
    ]);
}

function ensure_branch_stock(PDO $pdo, int $branchId, int $productId): int {
    $select = $pdo->prepare(
        "SELECT id FROM branch_stocks
         WHERE cabang_id = :cabang_id AND product_id = :product_id
         LIMIT 1 FOR UPDATE"
    );
    $select->execute([
        ':cabang_id' => $branchId,
        ':product_id' => $productId,
    ]);
    $id = $select->fetchColumn();

    if ($id !== false) {
        return (int)$id;
    }

    $insert = $pdo->prepare(
        "INSERT INTO branch_stocks (cabang_id, product_id, stock, minimum_stock)
         VALUES (:cabang_id, :product_id, 0, 0)"
    );
    $insert->execute([
        ':cabang_id' => $branchId,
        ':product_id' => $productId,
    ]);
    return (int)$pdo->lastInsertId();
}

function branch_stock_row(PDO $pdo, int $branchId, int $productId, bool $lock = false): ?array {
    $sql = "SELECT id, stock, minimum_stock
            FROM branch_stocks
            WHERE cabang_id = :cabang_id AND product_id = :product_id
            LIMIT 1";
    if ($lock) {
        $sql .= " FOR UPDATE";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':cabang_id' => $branchId,
        ':product_id' => $productId,
    ]);
    return $stmt->fetch() ?: null;
}

function stock_status(float $stock, float $minimum): array {
    if ($stock <= 0) {
        return ['key' => 'out', 'label' => 'Habis', 'class' => 'danger'];
    }
    if ($minimum > 0 && $stock <= $minimum) {
        return ['key' => 'low', 'label' => 'Menipis', 'class' => 'warning'];
    }
    return ['key' => 'safe', 'label' => 'Aman', 'class' => 'success'];
}

function stock_card_movements(PDO $pdo, int $branchId, int $productId): array {
    $stmt = $pdo->prepare(
        "SELECT
            sm.id,
            sm.movement_type,
            sm.quantity,
            sm.reference_type,
            sm.reference_id,
            sm.notes,
            sm.movement_date,
            u.name AS creator_name
         FROM stock_movements sm
         INNER JOIN branch_stocks bs ON bs.id = sm.branch_stock_id
         LEFT JOIN users u ON u.id = sm.created_by
         WHERE bs.cabang_id = :cabang_id
           AND bs.product_id = :product_id
         ORDER BY sm.movement_date DESC, sm.id DESC"
    );
    $stmt->execute([
        ':cabang_id' => $branchId,
        ':product_id' => $productId,
    ]);
    $rows = $stmt->fetchAll();

    $current = branch_stock_row($pdo, $branchId, $productId);
    $running = $current ? (float)$current['stock'] : 0.0;
    $result = [];

    foreach ($rows as $row) {
        $delta = stock_movement_delta((string)$row['movement_type'], (float)$row['quantity']);
        $saldoAfter = $running;
        $running -= $delta;

        $result[] = [
            'id' => (int)$row['id'],
            'movement_type' => (string)$row['movement_type'],
            'quantity' => abs((float)$row['quantity']),
            'delta' => $delta,
            'reference_type' => (string)($row['reference_type'] ?? ''),
            'reference_id' => (int)($row['reference_id'] ?? 0),
            'notes' => (string)($row['notes'] ?? ''),
            'movement_date' => (string)$row['movement_date'],
            'creator_name' => (string)($row['creator_name'] ?? ''),
            'saldo_after' => $saldoAfter,
        ];
    }

    return array_reverse($result);
}

function movement_label(string $type): string {
    $type = strtolower($type);
    return match (true) {
        str_contains($type, 'purchase') || str_contains($type, 'pembelian') => 'Pembelian',
        str_contains($type, 'sale') || str_contains($type, 'penjualan') || $type === 'out' => 'Penjualan',
        str_contains($type, 'transfer_in') || str_contains($type, 'stock_transfer_in') => 'Transfer Masuk',
        str_contains($type, 'transfer_out') || str_contains($type, 'stock_transfer_out') => 'Transfer Keluar',
        str_contains($type, 'adjustment') || $type === 'in' || $type === 'out' => 'Penyesuaian',
        default => ucwords(str_replace(['_', '-'], ' ', $type)),
    };
}

function movement_class(string $type, float $delta): string {
    $type = strtolower($type);
    if (str_contains($type, 'adjustment') || $type === 'in' || $type === 'out') {
        return 'adjustment';
    }
    return $delta >= 0 ? 'incoming' : 'outgoing';
}

function page_url(
    int $page,
    string $search,
    int $category,
    int $branch,
    string $status,
    int $summaryBranch = 0,
    array $extra = []
): string {
    $params = ['page' => $page];
    if ($search !== '') $params['search'] = $search;
    if ($category > 0) $params['category'] = $category;
    if ($branch > 0) $params['branch'] = $branch;
    if ($status !== '') $params['status'] = $status;
    if ($summaryBranch > 0) $params['summary_branch'] = $summaryBranch;
    foreach ($extra as $key => $value) {
        $params[$key] = $value;
    }
    return 'index.php?' . http_build_query($params);
}

if (empty($_SESSION['csrf_stock'])) {
    $_SESSION['csrf_stock'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_stock'];

$categoryTable = detect_category_table($pdo);
$categoryJoin = $categoryTable
    ? "LEFT JOIN `{$categoryTable}` cat ON cat.id = p.category_id"
    : '';
$categoryExpr = $categoryTable
    ? "COALESCE(cat.name, CONCAT('Kategori #', p.category_id))"
    : "CONCAT('Kategori #', p.category_id)";

$branches = $pdo->query(
    "SELECT id, code, name
     FROM cabangs
     WHERE status = 'active'
     ORDER BY name ASC"
)->fetchAll();

$products = $pdo->query(
    "SELECT id, code, name, brand, unit, category_id
     FROM products
     WHERE status = 'active'
     ORDER BY name ASC"
)->fetchAll();

$categories = $pdo->query(
    "SELECT DISTINCT p.category_id, {$categoryExpr} AS category_name
     FROM products p
     {$categoryJoin}
     WHERE p.status = 'active'
     ORDER BY category_name ASC"
)->fetchAll();

$notify = '';
$notifyType = '';

$adjustProductId = isset($_GET['adjust_product']) ? (int)$_GET['adjust_product'] : 0;
$adjustBranchId = isset($_GET['adjust_branch']) ? (int)$_GET['adjust_branch'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_stock'] ?? '', $token)) {
        $notify = 'Permintaan tidak valid (CSRF).';
        $notifyType = 'error';
    } else {
        try {
            $userId = current_user_id();
            if ($userId <= 0) {
                throw new Exception('User login tidak ditemukan pada session.');
            }

            if ($action === 'adjust_stock') {
                $branchId = (int)($_POST['cabang_id'] ?? 0);
                $productId = (int)($_POST['product_id'] ?? 0);
                $adjustmentType = $_POST['adjustment_type'] ?? '';
                $quantity = (float)($_POST['quantity'] ?? 0);
                $reason = trim($_POST['reason'] ?? '');
                $notes = trim($_POST['notes'] ?? '');

                if ($branchId <= 0 || $productId <= 0) {
                    throw new Exception('Cabang dan produk wajib dipilih.');
                }
                if (!in_array($adjustmentType, ['in', 'out'], true)) {
                    throw new Exception('Jenis penyesuaian tidak valid.');
                }
                if ($quantity <= 0) {
                    throw new Exception('Jumlah penyesuaian harus lebih besar dari 0.');
                }
                if ($reason === '') {
                    throw new Exception('Alasan penyesuaian wajib dipilih.');
                }

                $branchCheck = $pdo->prepare(
                    "SELECT COUNT(*) FROM cabangs WHERE id = :id AND status = 'active'"
                );
                $branchCheck->execute([':id' => $branchId]);
                if ((int)$branchCheck->fetchColumn() === 0) {
                    throw new Exception('Cabang tidak ditemukan atau tidak aktif.');
                }

                $productCheck = $pdo->prepare(
                    "SELECT COUNT(*) FROM products WHERE id = :id AND status = 'active'"
                );
                $productCheck->execute([':id' => $productId]);
                if ((int)$productCheck->fetchColumn() === 0) {
                    throw new Exception('Produk tidak ditemukan atau tidak aktif.');
                }

                $pdo->beginTransaction();

                $stockId = ensure_branch_stock($pdo, $branchId, $productId);

                $stock = branch_stock_row($pdo, $branchId, $productId, true);
                if (!$stock) {
                    throw new Exception('Data stok cabang tidak ditemukan.');
                }

                $delta = $adjustmentType === 'in' ? $quantity : -$quantity;
                if ((float)$stock['stock'] + $delta < 0) {
                    throw new Exception(
                        'Stok tidak mencukupi untuk pengurangan. Stok saat ini: ' .
                        money_qty((float)$stock['stock']) . '.'
                    );
                }

                $update = $pdo->prepare(
                    "UPDATE branch_stocks
                     SET stock = stock + :delta
                     WHERE id = :id"
                );
                $update->execute([
                    ':delta' => $delta,
                    ':id' => (int)$stock['id'],
                ]);

                $reasonText = 'Penyesuaian: ' . $reason;
                if ($notes !== '') {
                    $reasonText .= ' - ' . $notes;
                }

                insert_stock_movement(
                    $pdo,
                    $stockId,
                    $delta,
                    ['adjustment_in', 'stock_adjustment_in', 'in', 'masuk', 'adjustment'],
                    ['adjustment_out', 'stock_adjustment_out', 'out', 'keluar', 'adjustment'],
                    ['stock_adjustment', 'adjustment', 'stock-opname', 'system'],
                    $stockId,
                    $reasonText
                );

                $pdo->commit();
                redirect_stock(['success' => 'adjusted']);
            }

            throw new Exception('Aksi tidak dikenal.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $notify = $e instanceof PDOException
                ? 'Database: ' . $e->getMessage()
                : $e->getMessage();
            $notifyType = 'error';
        }
    }
}

if (($_GET['stock_lookup'] ?? '') === '1') {
    $branchId = (int)($_GET['branch_id'] ?? 0);
    $productId = (int)($_GET['product_id'] ?? 0);
    $stock = $branchId > 0 && $productId > 0
        ? branch_stock_row($pdo, $branchId, $productId)
        : null;

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'stock' => $stock ? (float)$stock['stock'] : 0,
        'minimum_stock' => $stock ? (float)$stock['minimum_stock'] : 0,
    ]);
    exit;
}

$search = trim($_GET['search'] ?? '');
$categoryFilter = (int)($_GET['category'] ?? 0);
$branchFilter = (int)($_GET['branch'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');
$summaryBranch = (int)($_GET['summary_branch'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;

$where = ["p.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = "(p.code LIKE :search_code OR p.name LIKE :search_name OR p.brand LIKE :search_brand)";
    $like = '%' . $search . '%';
    $params[':search_code'] = $like;
    $params[':search_name'] = $like;
    $params[':search_brand'] = $like;
}
if ($categoryFilter > 0) {
    $where[] = 'p.category_id = :category_filter';
    $params[':category_filter'] = $categoryFilter;
}
if ($branchFilter > 0) {
    $where[] = 'bs.cabang_id = :branch_filter';
    $params[':branch_filter'] = $branchFilter;
}
if ($statusFilter === 'safe') {
    $where[] = 'bs.stock > bs.minimum_stock';
}
if ($statusFilter === 'low') {
    $where[] = 'bs.minimum_stock > 0 AND bs.stock > 0 AND bs.stock <= bs.minimum_stock';
}
if ($statusFilter === 'out') {
    $where[] = 'bs.stock <= 0';
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM branch_stocks bs
     INNER JOIN products p ON p.id = bs.product_id
     {$categoryJoin}
     INNER JOIN cabangs c ON c.id = bs.cabang_id
     WHERE {$whereSql}"
);
$countStmt->execute($params);
$totalFiltered = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT
        bs.id AS branch_stock_id,
        bs.cabang_id,
        bs.product_id,
        bs.stock,
        bs.minimum_stock,
        c.code AS cabang_code,
        c.name AS cabang_name,
        p.code AS product_code,
        p.name AS product_name,
        p.brand,
        p.unit,
        p.category_id,
        {$categoryExpr} AS category_name,
        (
            SELECT MAX(sm.movement_date)
            FROM stock_movements sm
            WHERE sm.branch_stock_id = bs.id
        ) AS last_movement
     FROM branch_stocks bs
     INNER JOIN products p ON p.id = bs.product_id
     {$categoryJoin}
     INNER JOIN cabangs c ON c.id = bs.cabang_id
     WHERE {$whereSql}
     ORDER BY c.name ASC, p.name ASC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $listStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$stocks = $listStmt->fetchAll();

$totalProducts = (int)$pdo->query(
    "SELECT COUNT(*) FROM products WHERE status = 'active'"
)->fetchColumn();

$totalStock = (float)$pdo->query(
    "SELECT COALESCE(SUM(bs.stock),0)
     FROM branch_stocks bs
     INNER JOIN products p ON p.id = bs.product_id
     WHERE p.status = 'active'"
)->fetchColumn();

$lowStockCount = (int)$pdo->query(
    "SELECT COUNT(*)
     FROM branch_stocks bs
     INNER JOIN products p ON p.id = bs.product_id
     WHERE p.status = 'active'
       AND bs.minimum_stock > 0
       AND bs.stock <= bs.minimum_stock"
)->fetchColumn();

$movementThisMonth = (int)$pdo->query(
    "SELECT COUNT(*)
     FROM stock_movements
     WHERE movement_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
       AND movement_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)"
)->fetchColumn();

$branchWhere = "c.status = 'active'";
$branchParams = [];
if ($summaryBranch > 0) {
    $branchWhere .= " AND c.id = :summary_branch";
    $branchParams[':summary_branch'] = $summaryBranch;
}
$branchStmt = $pdo->prepare(
    "SELECT
        c.id,
        c.code,
        c.name,
        COALESCE(SUM(CASE WHEN p.status = 'active' THEN bs.stock ELSE 0 END),0) AS total_stock,
        COUNT(DISTINCT CASE WHEN p.status = 'active' AND bs.stock > 0 THEN bs.product_id END) AS product_count,
        SUM(CASE WHEN p.status = 'active' AND bs.minimum_stock > 0 AND bs.stock <= bs.minimum_stock THEN 1 ELSE 0 END) AS low_count
     FROM cabangs c
     LEFT JOIN branch_stocks bs ON bs.cabang_id = c.id
     LEFT JOIN products p ON p.id = bs.product_id
     WHERE {$branchWhere}
     GROUP BY c.id, c.code, c.name
     ORDER BY c.name ASC"
);
$branchStmt->execute($branchParams);
$branchSummaries = $branchStmt->fetchAll();

$maxBranchStock = 0.0;
foreach ($branchSummaries as $summary) {
    $maxBranchStock = max($maxBranchStock, (float)$summary['total_stock']);
}

$hasFilters = ($search !== '' || $categoryFilter > 0 || $branchFilter > 0 || $statusFilter !== '');
$fromRow = $totalFiltered > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalFiltered);

$detailBranch = isset($_GET['card_branch']) ? (int)$_GET['card_branch'] : 0;
$detailProduct = isset($_GET['card_product']) ? (int)$_GET['card_product'] : 0;
$showStockCard = $detailBranch > 0 && $detailProduct > 0;

$cardBranch = null;
$cardProduct = null;
$cardStock = null;
$cardMovements = [];

if ($showStockCard) {
    $branchStmt2 = $pdo->prepare("SELECT id, code, name FROM cabangs WHERE id = :id LIMIT 1");
    $branchStmt2->execute([':id' => $detailBranch]);
    $cardBranch = $branchStmt2->fetch() ?: null;

    $productStmt2 = $pdo->prepare(
        "SELECT p.id, p.code, p.name, p.brand, p.unit, p.category_id, {$categoryExpr} AS category_name
         FROM products p
         {$categoryJoin}
         WHERE p.id = :id
         LIMIT 1"
    );
    $productStmt2->execute([':id' => $detailProduct]);
    $cardProduct = $productStmt2->fetch() ?: null;

    if ($cardBranch && $cardProduct) {
        $cardStock = branch_stock_row($pdo, $detailBranch, $detailProduct);
        $cardMovements = stock_card_movements($pdo, $detailBranch, $detailProduct);
    } else {
        $showStockCard = false;
    }
}

$successMessages = [
    'adjusted' => 'Penyesuaian stok berhasil disimpan dan dicatat pada stock_movements.',
];

if (($_GET['export'] ?? '') === 'csv') {
    $exportStmt = $pdo->prepare(
        "SELECT
            p.code AS product_code,
            p.name AS product_name,
            p.brand,
            p.unit,
            {$categoryExpr} AS category_name,
            c.code AS cabang_code,
            c.name AS cabang_name,
            bs.stock,
            bs.minimum_stock
         FROM branch_stocks bs
         INNER JOIN products p ON p.id = bs.product_id
         {$categoryJoin}
         INNER JOIN cabangs c ON c.id = bs.cabang_id
         WHERE {$whereSql}
         ORDER BY c.name ASC, p.name ASC"
    );
    $exportStmt->execute($params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stok_persediaan_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kode Produk', 'Produk', 'Brand', 'Unit', 'Kategori', 'Cabang', 'Stok', 'Minimum', 'Status']);
    foreach ($exportStmt->fetchAll() as $row) {
        $st = stock_status((float)$row['stock'], (float)$row['minimum_stock']);
        fputcsv($out, [
            $row['product_code'],
            $row['product_name'],
            $row['brand'],
            $row['unit'],
            $row['category_name'],
            trim(($row['cabang_code'] ? $row['cabang_code'] . ' - ' : '') . $row['cabang_name']),
            $row['stock'],
            $row['minimum_stock'],
            $st['label'],
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
    <link rel="stylesheet" href="style.css?v=20260913-stoks">
</head>
<body class="stoks-page">

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
            <a href="../roles/" class="menu-item"><i class="bi bi-shield-lock"></i><span>Role & Hak Akses</span></a>
        </div>
        <div class="menu-section">
            <div class="menu-title">TRANSAKSI</div>
            <a href="../orders/" class="menu-item"><i class="bi bi-cart3"></i><span>Penjualan</span></a>
            <a href="../purchases/" class="menu-item"><i class="bi bi-bag"></i><span>Pembelian</span></a>
            <a href="../stoks-transfer/" class="menu-item"><i class="bi bi-arrow-left-right"></i><span>Transfer Stok</span></a>
            <a href="../payments/" class="menu-item"><i class="bi bi-credit-card"></i><span>Pembayaran</span></a>
            <a href="../expenses/" class="menu-item"><i class="bi bi-receipt"></i><span>Pengeluaran</span></a>
            <a href="./" class="menu-item active"><i class="bi bi-boxes"></i><span>Stok / Persediaan</span></a>
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

    <div class="page-header">
        <div>
            <div class="breadcrumb">
                <span>Dashboard</span><i class="bi bi-chevron-right"></i>
                <span>Transaksi</span><i class="bi bi-chevron-right"></i>
                <strong>Stok/Persediaan</strong>
            </div>
            <h1>Stok / Persediaan</h1>
            <p>Kelola dan pantau persediaan produk pada setiap cabang.</p>
        </div>
        <div class="header-actions">
            <a class="btn btn-outline" href="<?= h(page_url($page, $search, $categoryFilter, $branchFilter, $statusFilter, $summaryBranch, ['export' => 'csv'])) ?>">
                <i class="bi bi-download"></i> Export
            </a>
            <a class="btn btn-outline" href="<?= h(page_url($page, $search, $categoryFilter, $branchFilter, $statusFilter, $summaryBranch, ['adjust_product' => 0, 'adjust_branch' => 0])) ?>">
                <i class="bi bi-sliders"></i> Penyesuaian Stok
            </a>
        </div>
    </div>

    <?php if ($notify !== ''): ?>
        <div class="stoks-alert is-error"><i class="bi bi-exclamation-circle"></i><span><?= h($notify) ?></span></div>
    <?php elseif (isset($_GET['success'], $successMessages[$_GET['success']])): ?>
        <div class="stoks-alert is-success"><i class="bi bi-check-circle"></i><span><?= h($successMessages[$_GET['success']]) ?></span></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bi bi-box-seam"></i></div>
            <div class="stat-content"><span>Total Produk</span><strong><?= number_format($totalProducts,0,',','.') ?></strong><small>Produk aktif</small></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-boxes"></i></div>
            <div class="stat-content"><span>Total Stok</span><strong><?= money_qty($totalStock) ?></strong><small>Unit seluruh cabang</small></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="stat-content"><span>Perlu Restock</span><strong><?= number_format($lowStockCount,0,',','.') ?></strong><small>Di bawah minimum</small></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="bi bi-arrow-left-right"></i></div>
            <div class="stat-content"><span>Mutasi Bulan Ini</span><strong><?= number_format($movementThisMonth,0,',','.') ?></strong><small>Stock movements</small></div>
        </div>
    </div>

    <section class="branch-summary">
        <div class="summary-header">
            <div>
                <h2>Ringkasan Stok Cabang</h2>
                <p>Distribusi persediaan berdasarkan cabang.</p>
            </div>
            <form method="get">
                <input type="hidden" name="page" value="1">
                <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?= h($search) ?>"><?php endif; ?>
                <?php if ($categoryFilter > 0): ?><input type="hidden" name="category" value="<?= (int)$categoryFilter ?>"><?php endif; ?>
                <?php if ($branchFilter > 0): ?><input type="hidden" name="branch" value="<?= (int)$branchFilter ?>"><?php endif; ?>
                <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= h($statusFilter) ?>"><?php endif; ?>
                <select class="branch-select" name="summary_branch" onchange="this.form.submit()">
                    <option value="">Semua Cabang</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $summaryBranch === (int)$b['id'] ? 'selected' : '' ?>>
                            <?= h($b['code'] . ' - ' . $b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="branch-grid">
            <?php if (!$branchSummaries): ?>
                <div class="branch-empty">Belum ada data stok cabang.</div>
            <?php else: ?>
                <?php foreach ($branchSummaries as $summary): ?>
                    <?php
                    $lowCount = (int)$summary['low_count'];
                    $summaryClass = $lowCount >= 3 ? 'danger' : ($lowCount > 0 ? 'warning' : 'success');
                    $summaryLabel = $summaryClass === 'danger' ? 'Restock' : ($summaryClass === 'warning' ? 'Perhatian' : 'Normal');
                    $progress = $maxBranchStock > 0 ? min(100, ((float)$summary['total_stock'] / $maxBranchStock) * 100) : 0;
                    ?>
                    <div class="branch-card">
                        <div class="branch-top">
                            <div class="branch-icon"><i class="bi bi-building"></i></div>
                            <span class="status-badge <?= h($summaryClass) ?>"><?= h($summaryLabel) ?></span>
                        </div>
                        <h3><?= h($summary['name']) ?></h3>
                        <div class="branch-stock"><strong><?= money_qty((float)$summary['total_stock']) ?></strong><span>unit stok</span></div>
                        <div class="progress"><div class="progress-bar" style="width: <?= number_format($progress,2,'.','') ?>%;"></div></div>
                        <div class="branch-footer"><span>Produk tersedia</span><strong><?= number_format((int)$summary['product_count'],0,',','.') ?></strong></div>
                        <?php if ($lowCount > 0): ?><div class="branch-low">Perlu restock: <?= $lowCount ?> item</div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="table-card">
        <div class="table-header">
            <div><h2>Daftar Persediaan</h2><p>Saldo stok terkini berdasarkan cabang dan produk.</p></div>
        </div>

        <form method="get" class="filter-area">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input name="search" value="<?= h($search) ?>" placeholder="Cari kode, nama, atau brand produk...">
            </div>
            <select name="category">
                <option value="">Semua Kategori</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['category_id'] ?>" <?= $categoryFilter === (int)$cat['category_id'] ? 'selected' : '' ?>>
                        <?= h($cat['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="branch">
                <option value="">Semua Cabang</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $branchFilter === (int)$b['id'] ? 'selected' : '' ?>>
                        <?= h($b['code'] . ' - ' . $b['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="status">
                <option value="">Semua Status</option>
                <option value="safe" <?= $statusFilter === 'safe' ? 'selected' : '' ?>>Stok Aman</option>
                <option value="low" <?= $statusFilter === 'low' ? 'selected' : '' ?>>Stok Menipis</option>
                <option value="out" <?= $statusFilter === 'out' ? 'selected' : '' ?>>Stok Habis</option>
            </select>
            <button class="btn btn-primary btn-search" type="submit"><i class="bi bi-search"></i> Cari</button>
            <a class="btn btn-outline reset-btn <?= $hasFilters ? '' : 'is-disabled' ?>" href="<?= $hasFilters ? 'index.php' : '#' ?>"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
        </form>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Kode Produk</th>
                        <th>Produk</th>
                        <th>Kategori</th>
                        <th>Cabang</th>
                        <th>Stok</th>
                        <th>Minimum</th>
                        <th>Status</th>
                        <th>Update Terakhir</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$stocks): ?>
                    <tr><td colspan="9"><div class="empty-state"><i class="bi bi-box2"></i><strong>Data persediaan tidak ditemukan</strong><span>Coba ubah pencarian atau filter Anda.</span></div></td></tr>
                <?php else: ?>
                    <?php foreach ($stocks as $row): ?>
                        <?php $st = stock_status((float)$row['stock'], (float)$row['minimum_stock']); ?>
                        <tr>
                            <td><span class="product-code"><?= h($row['product_code']) ?></span></td>
                            <td>
                                <div class="product-info">
                                    <strong><?= h($row['product_name']) ?></strong>
                                    <span><?= h(($row['brand'] ?: '-') . ' • ' . ($row['unit'] ?: '-')) ?></span>
                                </div>
                            </td>
                            <td><span class="category-badge"><?= h($row['category_name']) ?></span></td>
                            <td><?= h($row['cabang_code'] . ' - ' . $row['cabang_name']) ?></td>
                            <td><strong class="stock-number <?= h($st['class'] === 'warning' ? 'warning-text' : ($st['class'] === 'danger' ? 'danger-text' : '')) ?>"><?= money_qty((float)$row['stock']) ?></strong><span class="unit"><?= h($row['unit'] ?: 'unit') ?></span></td>
                            <td><?= money_qty((float)$row['minimum_stock']) ?></td>
                            <td><span class="status-badge <?= h($st['class']) ?>"><i class="bi <?= $st['class'] === 'success' ? 'bi-check-circle' : ($st['class'] === 'warning' ? 'bi-exclamation-circle' : 'bi-x-circle') ?>"></i><?= h($st['label']) ?></span></td>
                            <td><?= !empty($row['last_movement']) ? h(date('d M Y H:i', strtotime($row['last_movement']))) : '-' ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a class="action-btn" title="Kartu Stok" href="<?= h(page_url($page,$search,$categoryFilter,$branchFilter,$statusFilter,$summaryBranch,['card_product'=>(int)$row['product_id'],'card_branch'=>(int)$row['cabang_id']])) ?>"><i class="bi bi-eye"></i></a>
                                    <a class="action-btn" title="Penyesuaian" href="<?= h(page_url($page,$search,$categoryFilter,$branchFilter,$statusFilter,$summaryBranch,['adjust_product'=>(int)$row['product_id'],'adjust_branch'=>(int)$row['cabang_id']])) ?>"><i class="bi bi-sliders"></i></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination-area">
            <span>Menampilkan <strong><?= $fromRow ?></strong>–<strong><?= $toRow ?></strong> dari <strong><?= $totalFiltered ?></strong> persediaan</span>
            <div class="pagination">
                <a href="<?= $page > 1 ? h(page_url($page-1,$search,$categoryFilter,$branchFilter,$statusFilter,$summaryBranch)) : '#' ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-left"></i></a>
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                if ($start > 1):
                ?>
                    <a href="<?= h(page_url(1,$search,$categoryFilter,$branchFilter,$statusFilter,$summaryBranch)) ?>">1</a>
                    <?php if ($start > 2): ?><span>...</span><?php endif; ?>
                <?php endif; ?>
                <?php for ($p = $start; $p <= $end; $p++): ?>
                    <a class="<?= $p === $page ? 'active' : '' ?>" href="<?= h(page_url($p,$search,$categoryFilter,$branchFilter,$statusFilter,$summaryBranch)) ?>"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span>...</span><?php endif; ?>
                    <a href="<?= h(page_url($totalPages,$search,$categoryFilter,$branchFilter,$statusFilter,$summaryBranch)) ?>"><?= $totalPages ?></a>
                <?php endif; ?>
                <a href="<?= $page < $totalPages ? h(page_url($page+1,$search,$categoryFilter,$branchFilter,$statusFilter,$summaryBranch)) : '#' ?>" class="<?= $page >= $totalPages ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
            </div>
        </div>
    </section>

</div>
</main>

<!-- ADJUSTMENT MODAL -->
<div class="modal-overlay <?= ($adjustProductId > 0 || $adjustBranchId > 0 || $notifyType === 'error' && ($_GET['adjust_product'] ?? '') !== '') ? 'show' : 'hidden' ?>" id="adjustmentModal">
    <div class="modal">
        <div class="modal-header">
            <div><h2>Penyesuaian Stok</h2><p>Koreksi stok berdasarkan hasil pengecekan fisik.</p></div>
            <a class="modal-close" href="<?= h(page_url($page,$search,$categoryFilter,$branchFilter,$statusFilter,$summaryBranch)) ?>"><i class="bi bi-x-lg"></i></a>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="adjust_stock">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

            <div class="form-grid">
                <div class="form-group full">
                    <label>Cabang <span>*</span></label>
                    <select name="cabang_id" id="adjustBranch" required onchange="loadCurrentStock()">
                        <option value="">Pilih Cabang</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= $adjustBranchId === (int)$b['id'] ? 'selected' : '' ?>>
                                <?= h($b['code'].' - '.$b['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group full">
                    <label>Produk <span>*</span></label>
                    <select name="product_id" id="adjustProduct" required onchange="loadCurrentStock()">
                        <option value="">Pilih Produk</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $adjustProductId === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= h($p['code'].' - '.$p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Jenis Penyesuaian <span>*</span></label>
                    <select name="adjustment_type" required>
                        <option value="">Pilih</option>
                        <option value="in">Tambah Stok</option>
                        <option value="out">Kurangi Stok</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Jumlah <span>*</span></label>
                    <input type="number" name="quantity" min="0.01" step="0.01" required placeholder="Contoh: 5">
                </div>

                <div class="form-group full">
                    <label>Alasan Penyesuaian <span>*</span></label>
                    <select name="reason" required>
                        <option value="">Pilih alasan</option>
                        <option>Hasil Stock Opname</option>
                        <option>Barang Rusak</option>
                        <option>Barang Hilang</option>
                        <option>Kesalahan Input</option>
                        <option>Barang Ditemukan</option>
                        <option>Lainnya</option>
                    </select>
                </div>

                <div class="form-group full">
                    <label>Keterangan</label>
                    <textarea name="notes" rows="3" placeholder="Masukkan keterangan jika diperlukan..."></textarea>
                </div>

                <div class="stock-current-box">
                    <div><span>Stok Saat Ini</span><strong id="currentStock">-</strong></div>
                    <div><span>Minimum</span><strong id="currentMinimum">-</strong></div>
                </div>
            </div>

            <div class="info-box">
                <i class="bi bi-info-circle"></i>
                <div><strong>Catatan</strong><p>Penyesuaian akan langsung mengubah saldo <code>branch_stocks</code> dan dicatat sebagai <code>stock_movements</code>.</p></div>
            </div>

            <div class="modal-footer">
                <a class="btn btn-outline" href="<?= h(page_url($page,$search,$categoryFilter,$branchFilter,$statusFilter,$summaryBranch)) ?>">Batal</a>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan Penyesuaian</button>
            </div>
        </form>
    </div>
</div>

<!-- STOCK CARD MODAL -->
<div class="modal-overlay <?= $showStockCard ? 'show' : 'hidden' ?>" id="stockCardModal">
    <div class="modal modal-large">
        <div class="modal-header">
            <div>
                <h2>Kartu Stok</h2>
                <p>
                    <?= $cardProduct && $cardBranch
                        ? h($cardProduct['code'].' - '.$cardProduct['name'].' • '.$cardBranch['code'].' - '.$cardBranch['name'])
                        : 'Riwayat pergerakan stok'
                    ?>
                </p>
            </div>
            <a class="modal-close" href="<?= h(page_url($page,$search,$categoryFilter,$branchFilter,$statusFilter,$summaryBranch)) ?>"><i class="bi bi-x-lg"></i></a>
        </div>

        <?php if ($showStockCard && $cardProduct && $cardBranch): ?>
            <div class="stock-card-summary">
                <div><span>Produk</span><strong><?= h($cardProduct['name']) ?></strong><small><?= h(($cardProduct['brand'] ?: '-') . ' • ' . ($cardProduct['unit'] ?: 'unit')) ?></small></div>
                <div><span>Cabang</span><strong><?= h($cardBranch['name']) ?></strong><small><?= h($cardBranch['code']) ?></small></div>
                <div><span>Stok Saat Ini</span><strong><?= money_qty((float)($cardStock['stock'] ?? 0)) ?></strong><small>Saldo branch_stocks</small></div>
            </div>

            <div class="table-responsive">
                <table class="stock-card-table">
                    <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Referensi</th>
                        <th>Jenis</th>
                        <th>Masuk</th>
                        <th>Keluar</th>
                        <th>Saldo</th>
                        <th>Keterangan</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$cardMovements): ?>
                        <tr><td colspan="7"><div class="empty-state"><i class="bi bi-clock-history"></i><strong>Belum ada pergerakan stok</strong><span>Belum ada record stock_movements untuk kombinasi ini.</span></div></td></tr>
                    <?php else: ?>
                        <?php foreach ($cardMovements as $movement): ?>
                            <?php $mClass = movement_class($movement['movement_type'], $movement['delta']); ?>
                            <tr>
                                <td><?= h(date('d M Y H:i', strtotime($movement['movement_date']))) ?></td>
                                <td><?= h($movement['reference_type'] ? $movement['reference_type'].' #'.$movement['reference_id'] : '-') ?></td>
                                <td><span class="movement <?= h($mClass) ?>"><?= h(movement_label($movement['movement_type'])) ?></span></td>
                                <td class="incoming-text"><?= $movement['delta'] > 0 ? '+' . h(money_qty($movement['quantity'])) : '-' ?></td>
                                <td class="outgoing-text"><?= $movement['delta'] < 0 ? '-' . h(money_qty($movement['quantity'])) : '-' ?></td>
                                <td><strong><?= h(money_qty($movement['saldo_after'])) ?></strong></td>
                                <td><?= h($movement['notes'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="modal-footer">
            <a class="btn btn-outline" href="<?= h(page_url($page,$search,$categoryFilter,$branchFilter,$statusFilter,$summaryBranch)) ?>">Tutup</a>
            <?php if ($showStockCard && $cardBranch && $cardProduct): ?>
                <a class="btn btn-primary" target="_blank" rel="noopener noreferrer" href="print.php?branch_id=<?= (int)$detailBranch ?>&product_id=<?= (int)$detailProduct ?>"><i class="bi bi-printer"></i> Cetak Kartu Stok</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function loadCurrentStock() {
    const branch = document.getElementById('adjustBranch');
    const product = document.getElementById('adjustProduct');
    const stockBox = document.getElementById('currentStock');
    const minimumBox = document.getElementById('currentMinimum');

    if (!branch || !product || !stockBox || !minimumBox) return;

    if (!branch.value || !product.value) {
        stockBox.textContent = '-';
        minimumBox.textContent = '-';
        return;
    }

    fetch('index.php?stock_lookup=1&branch_id=' + encodeURIComponent(branch.value) + '&product_id=' + encodeURIComponent(product.value))
        .then(response => response.json())
        .then(data => {
            stockBox.textContent = Number(data.stock || 0).toLocaleString('id-ID');
            minimumBox.textContent = Number(data.minimum_stock || 0).toLocaleString('id-ID');
        })
        .catch(() => {
            stockBox.textContent = '-';
            minimumBox.textContent = '-';
        });
}

document.addEventListener('DOMContentLoaded', function () {
    const adjustmentModal = document.getElementById('adjustmentModal');
    const stockCardModal = document.getElementById('stockCardModal');

    if (adjustmentModal && !adjustmentModal.classList.contains('hidden')) {
        loadCurrentStock();
    }

    window.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (adjustmentModal) adjustmentModal.classList.remove('show');
        if (stockCardModal) stockCardModal.classList.remove('show');
    });
});
</script>

</body>
</html>
