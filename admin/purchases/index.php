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

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string
{
    return 'Rp ' . number_format($value, 0, ',', '.');
}

function redirect_purchases(array $params = []): never
{
    $url = 'index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

function positive_int($value): int
{
    $value = (int)$value;
    return $value > 0 ? $value : 0;
}

function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
}


function stock_movement_enum_values(PDO $pdo, string $column): array
{
    $allowed = ['movement_type', 'reference_type'];
    if (!in_array($column, $allowed, true)) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'stock_movements'
           AND COLUMN_NAME = :column_name
         LIMIT 1"
    );
    $stmt->execute([':column_name' => $column]);
    $type = (string)$stmt->fetchColumn();

    if (!preg_match('/^enum\\((.*)\\)$/i', $type, $m)) {
        return [];
    }

    $values = str_getcsv(trim($m[1]), ',', "'", '\\\\');
    return array_values(array_filter(array_map(
        static fn($v) => str_replace(["\\'", "\\\\"], ["'", "\\"], trim((string)$v)),
        $values
    ), static fn($v) => $v !== ''));
}

function match_stock_movement_value(array $available, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        foreach ($available as $actual) {
            if (strcasecmp((string)$actual, (string)$candidate) === 0) {
                return (string)$actual;
            }
        }
    }
    return null;
}

function insert_stock_movement(
    PDO $pdo,
    int $branchStockId,
    float $delta,
    array $sourceCandidates,
    array $incomingCandidates,
    array $outgoingCandidates,
    array $referenceCandidates,
    int $referenceId,
    string $notes
): void {
    if ($branchStockId <= 0 || abs($delta) < 0.000001) {
        return;
    }

    $availableTypes = stock_movement_enum_values($pdo, 'movement_type');
    $directionCandidates = $delta >= 0 ? $incomingCandidates : $outgoingCandidates;
    $movementType = match_stock_movement_value($availableTypes, $directionCandidates);
    $movementQuantity = abs($delta);

    if (!$movementType) {
        $movementType = match_stock_movement_value($availableTypes, $sourceCandidates);
        if ($movementType) {
            // Jika database hanya menyediakan satu tipe sumber, simpan delta bertanda.
            $movementQuantity = $delta;
        }
    }

    if (!$movementType && $availableTypes) {
        throw new Exception('ENUM stock_movements.movement_type belum memiliki nilai yang sesuai untuk transaksi ini.');
    }

    if (!$movementType) {
        $movementType = $delta >= 0 ? ($incomingCandidates[0] ?? 'in') : ($outgoingCandidates[0] ?? 'out');
        $movementQuantity = abs($delta);
    }

    $availableReferences = stock_movement_enum_values($pdo, 'reference_type');
    $referenceType = match_stock_movement_value($availableReferences, $referenceCandidates);
    if (!$referenceType && $availableReferences) {
        throw new Exception('ENUM stock_movements.reference_type belum memiliki nilai yang sesuai.');
    }
    $referenceType = $referenceType ?? ($referenceCandidates[0] ?? 'system');

    $stmt = $pdo->prepare(
        "INSERT INTO stock_movements
            (branch_stock_id, movement_type, quantity, reference_type, reference_id, notes, movement_date, created_by)
         VALUES
            (:branch_stock_id, :movement_type, :quantity, :reference_type, :reference_id, :notes, NOW(), :created_by)"
    );
    $stmt->execute([
        ':branch_stock_id' => $branchStockId,
        ':movement_type' => $movementType,
        ':quantity' => $movementQuantity,
        ':reference_type' => $referenceType,
        ':reference_id' => $referenceId,
        ':notes' => $notes,
        ':created_by' => current_user_id(),
    ]);
}


function get_purchase_received_map(PDO $pdo, int $purchaseId): array
{
    $stmt = $pdo->prepare(
        "SELECT product_id, received_quantity
         FROM purchase_details
         WHERE purchase_id = :purchase_id"
    );
    $stmt->execute([':purchase_id' => $purchaseId]);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['product_id']] = (float)$row['received_quantity'];
    }
    return $map;
}

function ensure_purchase_branch_stock_row(PDO $pdo, int $cabangId, int $productId): void
{
    $stmt = $pdo->prepare(
        "SELECT id
         FROM branch_stocks
         WHERE cabang_id = :cabang_id AND product_id = :product_id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([
        ':cabang_id' => $cabangId,
        ':product_id' => $productId,
    ]);

    if ($stmt->fetchColumn() === false) {
        $insert = $pdo->prepare(
            "INSERT INTO branch_stocks (cabang_id, product_id, stock, minimum_stock)
             VALUES (:cabang_id, :product_id, 0, 0)"
        );
        $insert->execute([
            ':cabang_id' => $cabangId,
            ':product_id' => $productId,
        ]);
    }
}

function update_purchase_branch_stock(PDO $pdo, int $cabangId, int $productId, float $delta, int $purchaseId): void
{
    if (abs($delta) < 0.000001) {
        return;
    }

    ensure_purchase_branch_stock_row($pdo, $cabangId, $productId);

    if ($delta < 0) {
        $check = $pdo->prepare(
            "SELECT stock
             FROM branch_stocks
             WHERE cabang_id = :cabang_id AND product_id = :product_id
             LIMIT 1
             FOR UPDATE"
        );
        $check->execute([
            ':cabang_id' => $cabangId,
            ':product_id' => $productId,
        ]);
        $currentStock = (float)$check->fetchColumn();

        if ($currentStock + $delta < 0) {
            throw new Exception('Stok cabang tidak mencukupi untuk perubahan penerimaan pembelian.');
        }
    }

    $stmt = $pdo->prepare(
        "UPDATE branch_stocks
         SET stock = stock + :delta
         WHERE cabang_id = :cabang_id AND product_id = :product_id"
    );
    $stmt->execute([
        ':delta' => $delta,
        ':cabang_id' => $cabangId,
        ':product_id' => $productId,
    ]);

    $branchStockStmt = $pdo->prepare(
        "SELECT id
         FROM branch_stocks
         WHERE cabang_id = :cabang_id AND product_id = :product_id
         LIMIT 1
         FOR UPDATE"
    );
    $branchStockStmt->execute([
        ':cabang_id' => $cabangId,
        ':product_id' => $productId,
    ]);
    $branchStockId = (int)$branchStockStmt->fetchColumn();

    insert_stock_movement(
        $pdo,
        $branchStockId,
        $delta,
        ['purchase', 'purchases', 'pembelian'],
        ['purchase_in', 'purchase', 'purchases', 'in', 'stock_in', 'masuk'],
        ['purchase_out', 'purchase', 'purchases', 'out', 'stock_out', 'keluar'],
        ['purchase', 'purchases', 'pembelian'],
        $purchaseId,
        $delta >= 0 ? 'Penerimaan pembelian' : 'Penyesuaian penerimaan pembelian'
    );
}

function generate_purchase_number(PDO $pdo): string
{
    $year = date('Y');

    $stmt = $pdo->prepare(
        "SELECT purchase_number
         FROM purchases
         WHERE purchase_number LIKE :prefix
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([':prefix' => 'PUR-' . $year . '-%']);
    $last = $stmt->fetchColumn();

    $sequence = 1;
    if (is_string($last) && preg_match('/(\d+)$/', $last, $m)) {
        $sequence = (int)$m[1] + 1;
    }

    return sprintf('PUR-%s-%04d', $year, $sequence);
}

function get_enum_values(PDO $pdo, string $table, string $column): array
{
    $allowed = [
        'purchases' => ['status'],
    ];

    if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT COLUMN_TYPE
         FROM information_schema.COLUMNS
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

    $values = str_getcsv(trim($m[1]), ',', "'", '\\');

    return array_values(array_filter(
        array_map(
            static fn($value) => str_replace(["\\'", "\\\\"], ["'", "\\"], trim($value)),
            $values
        ),
        static fn($value) => $value !== ''
    ));
}

function purchase_status_label(string $status): string
{
    return match ($status) {
        'draft' => 'Draft',
        'pending' => 'Menunggu',
        'processing' => 'Diproses',
        'partial', 'partially_received' => 'Sebagian Diterima',
        'received', 'completed' => 'Diterima',
        'cancelled', 'canceled' => 'Dibatalkan',
        default => ucwords(str_replace(['_', '-'], ' ', $status)),
    };
}

function purchase_status_class(string $status): string
{
    return match ($status) {
        'draft', 'pending' => 'waiting',
        'processing' => 'process',
        'partial', 'partially_received' => 'partial',
        'received', 'completed' => 'completed',
        'cancelled', 'canceled' => 'cancelled',
        default => 'waiting',
    };
}

function get_purchase(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            p.*,
            s.code AS supplier_code,
            s.name AS supplier_name,
            s.contact_person,
            s.phone AS supplier_phone,
            s.email AS supplier_email,
            s.address AS supplier_address,
            c.code AS cabang_code,
            c.name AS cabang_name,
            u.name AS creator_name
         FROM purchases p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         LEFT JOIN cabangs c ON c.id = p.cabang_id
         LEFT JOIN users u ON u.id = p.created_by
         WHERE p.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);

    return $stmt->fetch() ?: null;
}

function get_purchase_details(PDO $pdo, int $purchaseId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            pd.*,
            pr.code AS product_code,
            pr.name AS product_name,
            pr.brand AS product_brand,
            pr.unit AS product_unit
         FROM purchase_details pd
         LEFT JOIN products pr ON pr.id = pd.product_id
         WHERE pd.purchase_id = :purchase_id
         ORDER BY pd.id ASC"
    );
    $stmt->execute([':purchase_id' => $purchaseId]);

    return $stmt->fetchAll();
}

function selected_ids(array $rows, string $field): array
{
    $ids = [];
    foreach ($rows as $row) {
        $id = (int)($row[$field] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function fetch_supplier_options(PDO $pdo, ?int $selectedId = null): array
{
    $sql = "SELECT id, code, name, contact_person, phone, status
            FROM suppliers
            WHERE status = 'active'";
    $params = [];

    if ($selectedId) {
        $sql .= " OR id = :selected_id";
        $params[':selected_id'] = $selectedId;
    }

    $sql .= " ORDER BY name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function fetch_branch_options(PDO $pdo, ?int $selectedId = null): array
{
    $sql = "SELECT id, code, name, status
            FROM cabangs
            WHERE status = 'active'";
    $params = [];

    if ($selectedId) {
        $sql .= " OR id = :selected_id";
        $params[':selected_id'] = $selectedId;
    }

    $sql .= " ORDER BY name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function fetch_product_options(PDO $pdo, array $selectedIds = []): array
{
    $sql = "SELECT id, code, name, brand, unit, purchase_price, status
            FROM products
            WHERE status = 'active'";
    $params = [];

    if ($selectedIds) {
        $placeholders = [];
        foreach ($selectedIds as $i => $id) {
            $key = ':selected_product_' . $i;
            $placeholders[] = $key;
            $params[$key] = $id;
        }
        $sql .= " OR id IN (" . implode(',', $placeholders) . ")";
    }

    $sql .= " ORDER BY name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function valid_reference_ids(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }

    $placeholders = [];
    $params = [];

    foreach ($ids as $i => $id) {
        $key = ':product_id_' . $i;
        $placeholders[] = $key;
        $params[$key] = $id;
    }

    $stmt = $pdo->prepare(
        "SELECT id
         FROM products
         WHERE id IN (" . implode(',', $placeholders) . ")"
    );
    $stmt->execute($params);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function build_page_url(int $page, string $search, string $branch, string $status, array $extra = []): string
{
    $params = ['page' => $page];

    if ($search !== '') {
        $params['search'] = $search;
    }
    if ($branch !== '') {
        $params['branch'] = $branch;
    }
    if ($status !== '') {
        $params['status'] = $status;
    }

    foreach ($extra as $key => $value) {
        $params[$key] = $value;
    }

    return 'index.php?' . http_build_query($params);
}

if (empty($_SESSION['csrf_purchases'])) {
    $_SESSION['csrf_purchases'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_purchases'];

$purchaseStatuses = get_enum_values($pdo, 'purchases', 'status');
if (!$purchaseStatuses) {
    $purchaseStatuses = ['draft', 'pending', 'processing', 'partial', 'received', 'cancelled'];
}

$defaultPurchaseStatus = in_array('pending', $purchaseStatuses, true)
    ? 'pending'
    : $purchaseStatuses[0];

$notify = '';
$notifyType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_purchases'] ?? '', $token)) {
        $notify = 'Permintaan tidak valid (CSRF).';
        $notifyType = 'error';
    } else {
        try {
            if (!in_array($action, ['add_purchase', 'update_purchase'], true)) {
                throw new Exception('Aksi transaksi tidak dikenali.');
            }

            $isUpdate = $action === 'update_purchase';
            $purchaseId = positive_int($_POST['purchase_id'] ?? 0);

            if ($isUpdate && $purchaseId <= 0) {
                throw new Exception('ID pembelian tidak valid.');
            }

            $purchaseDate = trim($_POST['purchase_date'] ?? '');
            $supplierId = positive_int($_POST['supplier_id'] ?? 0);
            $cabangId = positive_int($_POST['cabang_id'] ?? 0);
            $status = trim($_POST['status'] ?? $defaultPurchaseStatus);
            $headerDiscount = max(0, (float)($_POST['discount'] ?? 0));
            $tax = max(0, (float)($_POST['tax'] ?? 0));

            if ($purchaseDate === '') {
                throw new Exception('Tanggal pembelian wajib diisi.');
            }

            $date = DateTime::createFromFormat('Y-m-d', $purchaseDate);
            if (!$date || $date->format('Y-m-d') !== $purchaseDate) {
                throw new Exception('Format tanggal pembelian tidak valid.');
            }

            if ($supplierId <= 0) {
                throw new Exception('Supplier wajib dipilih.');
            }
            if ($cabangId <= 0) {
                throw new Exception('Cabang tujuan wajib dipilih.');
            }
            if (!in_array($status, $purchaseStatuses, true)) {
                throw new Exception('Status pembelian tidak valid.');
            }

            $supplierCheck = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM suppliers
                 WHERE id = :id AND status = 'active'"
            );
            $supplierCheck->execute([':id' => $supplierId]);
            if ((int)$supplierCheck->fetchColumn() === 0) {
                throw new Exception('Supplier tidak ditemukan atau tidak aktif.');
            }

            $branchCheck = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM cabangs
                 WHERE id = :id AND status = 'active'"
            );
            $branchCheck->execute([':id' => $cabangId]);
            if ((int)$branchCheck->fetchColumn() === 0) {
                throw new Exception('Cabang tidak ditemukan atau tidak aktif.');
            }

            if ($isUpdate) {
                $existing = get_purchase($pdo, $purchaseId);
                if (!$existing) {
                    throw new Exception('Data pembelian tidak ditemukan.');
                }

                $lockedStatuses = ['received', 'completed', 'cancelled', 'canceled'];
                if (in_array((string)$existing['status'], $lockedStatuses, true)) {
                    throw new Exception('Pembelian yang sudah diterima atau dibatalkan tidak dapat diedit.');
                }
            }

            $oldReceivedByProduct = $isUpdate ? get_purchase_received_map($pdo, $purchaseId) : [];

            if ($isUpdate && $oldReceivedByProduct && (int)$existing['cabang_id'] !== $cabangId) {
                $hasReceived = false;
                foreach ($oldReceivedByProduct as $oldQty) {
                    if ((float)$oldQty > 0) {
                        $hasReceived = true;
                        break;
                    }
                }
                if ($hasReceived) {
                    throw new Exception('Cabang pembelian tidak dapat diubah setelah ada barang yang diterima.');
                }
            }

            $productIds = $_POST['product_id'] ?? [];
            $quantities = $_POST['quantity'] ?? [];
            $receivedQuantities = $_POST['received_quantity'] ?? [];
            $prices = $_POST['price'] ?? [];
            $discounts = $_POST['line_discount'] ?? [];

            foreach ([$productIds, $quantities, $receivedQuantities, $prices, $discounts] as $array) {
                if (!is_array($array)) {
                    throw new Exception('Format detail produk tidak valid.');
                }
            }

            $rows = [];
            $productIdsClean = [];
            $seenProducts = [];

            foreach ($productIds as $i => $rawProductId) {
                $productId = positive_int($rawProductId);

                if ($productId <= 0) {
                    continue;
                }

                if (isset($seenProducts[$productId])) {
                    throw new Exception('Produk yang sama tidak boleh ditambahkan lebih dari satu baris.');
                }
                $seenProducts[$productId] = true;

                $quantity = max(1, (int)($quantities[$i] ?? 0));
                $receivedQuantity = max(0, (int)($receivedQuantities[$i] ?? 0));
                $price = max(0, (float)($prices[$i] ?? 0));
                $discount = max(0, (float)($discounts[$i] ?? 0));

                if ($receivedQuantity > $quantity) {
                    throw new Exception('Jumlah diterima tidak boleh lebih besar dari jumlah pembelian.');
                }

                if ($price <= 0) {
                    throw new Exception('Harga pembelian harus lebih besar dari 0.');
                }

                $lineBase = $quantity * $price;
                $discount = min($discount, $lineBase);
                $lineSubtotal = max(0, $lineBase - $discount);

                $rows[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'received_quantity' => $receivedQuantity,
                    'price' => $price,
                    'discount' => $discount,
                    'subtotal' => $lineSubtotal,
                ];

                $productIdsClean[] = $productId;
            }

            if (!$rows) {
                throw new Exception('Tambahkan minimal satu produk pembelian.');
            }

            $validProductIds = valid_reference_ids($pdo, $productIdsClean);
            if (count($validProductIds) !== count($productIdsClean)) {
                throw new Exception('Terdapat produk yang tidak ditemukan.');
            }

            $subtotal = array_sum(array_column($rows, 'subtotal'));
            $headerDiscount = min($headerDiscount, $subtotal);
            $grandTotal = max(0, $subtotal - $headerDiscount + $tax);

            $stockReceivingStatuses = ['partial', 'partially_received', 'received', 'completed'];
            $nonReceivingStatuses = ['draft', 'pending', 'processing'];

            if (in_array($status, $nonReceivingStatuses, true)) {
                foreach ($rows as $row) {
                    if ($row['received_quantity'] > 0) {
                        throw new Exception('Quantity diterima harus 0 selama pembelian belum berstatus diterima/sebagian diterima.');
                    }
                }
            }

            if (in_array($status, ['received', 'completed'], true)) {
                $allReceived = true;
                foreach ($rows as $row) {
                    if ($row['received_quantity'] < $row['quantity']) {
                        $allReceived = false;
                        break;
                    }
                }
                if (!$allReceived) {
                    throw new Exception('Status Diterima harus memiliki quantity diterima penuh untuk seluruh produk.');
                }
            }

            if (in_array($status, ['partial', 'partially_received'], true)) {
                $hasReceived = false;
                foreach ($rows as $row) {
                    if ($row['received_quantity'] > 0) {
                        $hasReceived = true;
                        break;
                    }
                }
                if (!$hasReceived) {
                    throw new Exception('Status Sebagian Diterima harus memiliki quantity diterima.');
                }
            }

            $pdo->beginTransaction();

            if ($isUpdate) {
                $stmt = $pdo->prepare(
                    "UPDATE purchases
                     SET supplier_id = :supplier_id,
                         cabang_id = :cabang_id,
                         purchase_date = :purchase_date,
                         subtotal = :subtotal,
                         discount = :discount,
                         tax = :tax,
                         grand_total = :grand_total,
                         status = :status
                     WHERE id = :id"
                );

                $stmt->execute([
                    ':supplier_id' => $supplierId,
                    ':cabang_id' => $cabangId,
                    ':purchase_date' => $purchaseDate,
                    ':subtotal' => $subtotal,
                    ':discount' => $headerDiscount,
                    ':tax' => $tax,
                    ':grand_total' => $grandTotal,
                    ':status' => $status,
                    ':id' => $purchaseId,
                ]);

                $pdo->prepare("DELETE FROM purchase_details WHERE purchase_id = :purchase_id")
                    ->execute([':purchase_id' => $purchaseId]);
            } else {
                $createdBy = current_user_id();
                if ($createdBy <= 0) {
                    throw new Exception('User login tidak ditemukan pada session.');
                }

                $purchaseNumber = generate_purchase_number($pdo);

                $stmt = $pdo->prepare(
                    "INSERT INTO purchases
                        (purchase_number, supplier_id, cabang_id, purchase_date,
                         subtotal, discount, tax, grand_total, status, created_by)
                     VALUES
                        (:purchase_number, :supplier_id, :cabang_id, :purchase_date,
                         :subtotal, :discount, :tax, :grand_total, :status, :created_by)"
                );

                $stmt->execute([
                    ':purchase_number' => $purchaseNumber,
                    ':supplier_id' => $supplierId,
                    ':cabang_id' => $cabangId,
                    ':purchase_date' => $purchaseDate,
                    ':subtotal' => $subtotal,
                    ':discount' => $headerDiscount,
                    ':tax' => $tax,
                    ':grand_total' => $grandTotal,
                    ':status' => $status,
                    ':created_by' => $createdBy,
                ]);

                $purchaseId = (int)$pdo->lastInsertId();
            }

            $detailInsert = $pdo->prepare(
                "INSERT INTO purchase_details
                    (purchase_id, product_id, quantity, received_quantity, price, discount, subtotal)
                 VALUES
                    (:purchase_id, :product_id, :quantity, :received_quantity, :price, :discount, :subtotal)"
            );

            foreach ($rows as $row) {
                $detailInsert->execute([
                    ':purchase_id' => $purchaseId,
                    ':product_id' => $row['product_id'],
                    ':quantity' => $row['quantity'],
                    ':received_quantity' => $row['received_quantity'],
                    ':price' => $row['price'],
                    ':discount' => $row['discount'],
                    ':subtotal' => $row['subtotal'],
                ]);
            }

            // Sinkronisasi stok cabang berdasarkan selisih received_quantity.
            // Pembelian baru akan menambah stok sejumlah quantity yang sudah diterima.
            // Saat edit, hanya delta penerimaan yang diubah yang diterapkan ke stok.
            $newReceivedByProduct = [];
            foreach ($rows as $row) {
                $newReceivedByProduct[(int)$row['product_id']] = (float)$row['received_quantity'];
            }

            $productIdsForStock = array_unique(array_merge(
                array_keys($oldReceivedByProduct),
                array_keys($newReceivedByProduct)
            ));

            foreach ($productIdsForStock as $productId) {
                $oldReceived = (float)($oldReceivedByProduct[$productId] ?? 0);
                $newReceived = (float)($newReceivedByProduct[$productId] ?? 0);
                $delta = $newReceived - $oldReceived;

                if (abs($delta) >= 0.000001) {
                    update_purchase_branch_stock($pdo, $cabangId, (int)$productId, $delta, $purchaseId);
                }
            }

            $pdo->commit();

            redirect_purchases([
                'success' => $isUpdate ? 'updated' : 'added',
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof PDOException) {
                $notify = 'Database: ' . $e->getMessage();
            } else {
                $notify = $e->getMessage();
            }
            $notifyType = 'error';
        }
    }
}

$search = trim($_GET['search'] ?? '');
$branchFilter = trim($_GET['branch'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        p.purchase_number LIKE :search_number
        OR s.name LIKE :search_supplier
        OR s.code LIKE :search_supplier_code
    )";
    $value = '%' . $search . '%';
    $params[':search_number'] = $value;
    $params[':search_supplier'] = $value;
    $params[':search_supplier_code'] = $value;
}

if ($branchFilter !== '') {
    $where[] = 'p.cabang_id = :branch_filter';
    $params[':branch_filter'] = (int)$branchFilter;
}

if ($statusFilter !== '') {
    if (in_array($statusFilter, $purchaseStatuses, true)) {
        $where[] = 'p.status = :status_filter';
        $params[':status_filter'] = $statusFilter;
    } else {
        $statusFilter = '';
    }
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM purchases p
     LEFT JOIN suppliers s ON s.id = p.supplier_id
     LEFT JOIN cabangs c ON c.id = p.cabang_id
     $whereSql"
);
foreach ($params as $key => $value) {
    $countStmt->bindValue(
        $key,
        $value,
        $key === ':branch_filter' ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}
$countStmt->execute();

$totalFiltered = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT
        p.*,
        s.code AS supplier_code,
        s.name AS supplier_name,
        c.code AS cabang_code,
        c.name AS cabang_name,
        u.name AS creator_name,
        (
            SELECT COUNT(*)
            FROM purchase_details pd
            WHERE pd.purchase_id = p.id
        ) AS item_count,
        (
            SELECT COALESCE(SUM(pd.quantity), 0)
            FROM purchase_details pd
            WHERE pd.purchase_id = p.id
        ) AS quantity_total,
        (
            SELECT COALESCE(SUM(pd.received_quantity), 0)
            FROM purchase_details pd
            WHERE pd.purchase_id = p.id
        ) AS received_total
     FROM purchases p
     LEFT JOIN suppliers s ON s.id = p.supplier_id
     LEFT JOIN cabangs c ON c.id = p.cabang_id
     LEFT JOIN users u ON u.id = p.created_by
     $whereSql
     ORDER BY p.id DESC
     LIMIT :limit OFFSET :offset"
);

foreach ($params as $key => $value) {
    $listStmt->bindValue(
        $key,
        $value,
        $key === ':branch_filter' ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();

$purchases = $listStmt->fetchAll();

$totalPurchases = (int)$pdo->query(
    "SELECT COUNT(*) FROM purchases WHERE YEAR(purchase_date) = YEAR(CURDATE())"
)->fetchColumn();

$monthlyStmt = $pdo->query(
    "SELECT COALESCE(SUM(grand_total), 0)
     FROM purchases
     WHERE YEAR(purchase_date) = YEAR(CURDATE())
       AND MONTH(purchase_date) = MONTH(CURDATE())
       AND status NOT IN ('cancelled', 'canceled')"
);
$monthlySpend = (float)$monthlyStmt->fetchColumn();

$quantityStmt = $pdo->query(
    "SELECT COALESCE(SUM(pd.quantity), 0)
     FROM purchase_details pd
     INNER JOIN purchases p ON p.id = pd.purchase_id
     WHERE YEAR(p.purchase_date) = YEAR(CURDATE())
       AND p.status NOT IN ('cancelled', 'canceled')"
);
$totalPurchasedQuantity = (int)$quantityStmt->fetchColumn();

$supplierCountStmt = $pdo->query(
    "SELECT COUNT(DISTINCT supplier_id)
     FROM purchases
     WHERE YEAR(purchase_date) = YEAR(CURDATE())
       AND status NOT IN ('cancelled', 'canceled')"
);
$transactingSuppliers = (int)$supplierCountStmt->fetchColumn();


$branches = $pdo->query(
    "SELECT id, code, name, status
     FROM cabangs
     ORDER BY name ASC"
)->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $pdo->query(
        "SELECT
            p.purchase_number,
            p.purchase_date,
            s.code AS supplier_code,
            s.name AS supplier_name,
            c.code AS cabang_code,
            c.name AS cabang_name,
            p.subtotal,
            p.discount,
            p.tax,
            p.grand_total,
            p.status,
            u.name AS creator_name
         FROM purchases p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         LEFT JOIN cabangs c ON c.id = p.cabang_id
         LEFT JOIN users u ON u.id = p.created_by
         ORDER BY p.id DESC"
    );

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pembelian-' . date('Y-m-d-His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'No. Pembelian',
        'Tanggal',
        'Kode Supplier',
        'Supplier',
        'Kode Cabang',
        'Cabang',
        'Subtotal',
        'Diskon',
        'Pajak',
        'Grand Total',
        'Status',
        'Dibuat Oleh'
    ]);

    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['purchase_number'],
            $row['purchase_date'],
            $row['supplier_code'],
            $row['supplier_name'],
            $row['cabang_code'],
            $row['cabang_name'],
            $row['subtotal'],
            $row['discount'],
            $row['tax'],
            $row['grand_total'],
            purchase_status_label((string)$row['status']),
            $row['creator_name'],
        ]);
    }

    fclose($out);
    exit;
}

$detailPurchase = null;
$detailLines = [];
$editPurchase = null;
$editLines = [];

if (isset($_GET['detail']) && ctype_digit((string)$_GET['detail'])) {
    $detailPurchase = get_purchase($pdo, (int)$_GET['detail']);
    if ($detailPurchase) {
        $detailLines = get_purchase_details($pdo, (int)$_GET['detail']);
    }
}

if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $editPurchase = get_purchase($pdo, (int)$_GET['edit']);

    if ($editPurchase) {
        $lockedStatuses = ['received', 'completed', 'cancelled', 'canceled'];
        if (in_array((string)$editPurchase['status'], $lockedStatuses, true)) {
            $notify = 'Pembelian ini sudah final dan tidak dapat diedit.';
            $notifyType = 'error';
            $editPurchase = null;
        } else {
            $editLines = get_purchase_details($pdo, (int)$_GET['edit']);
        }
    }
}

$editProductIds = selected_ids($editLines, 'product_id');

$supplierOptions = fetch_supplier_options($pdo, $editPurchase ? (int)$editPurchase['supplier_id'] : null);
$branchOptions = fetch_branch_options($pdo, $editPurchase ? (int)$editPurchase['cabang_id'] : null);
$productOptions = fetch_product_options($pdo, $editProductIds);

$hasFilters = ($search !== '' || $branchFilter !== '' || $statusFilter !== '');
$from = $totalFiltered > 0 ? $offset + 1 : 0;
$to = min($offset + $perPage, $totalFiltered);

$successMessages = [
    'added' => 'Pembelian berhasil dibuat.',
    'updated' => 'Pembelian berhasil diperbarui.',
];

function product_option_data(array $product): string
{
    return h(
        json_encode([
            'price' => (float)$product['purchase_price'],
            'unit' => (string)$product['unit'],
            'name' => (string)$product['name'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c1a2d">
    <title>Pembelian | <?= h($companyName) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../assets/css/admin.css?v=20260907">
    <link rel="stylesheet" href="style.css?v=20260912-purchases-final">
</head>

<body class="purchases-page">
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
            <a href="./" class="menu-item active"><i class="bi bi-bag"></i><span>Pembelian</span></a>
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
    <div class="purchases-container">
        <div class="purchases-header">
            <div>
                <div class="purchases-breadcrumb">
                    Dashboard <i class="bi bi-chevron-right"></i> Transaksi <i class="bi bi-chevron-right"></i> Pembelian
                </div>
                <h1>Pembelian</h1>
                <p>Kelola transaksi pembelian produk dari supplier.</p>
            </div>

            <button class="purchases-btn purchases-btn-primary" type="button" onclick="openPurchaseModal()">
                <i class="bi bi-plus-lg"></i>
                Buat Pembelian
            </button>
        </div>

        <?php if ($notify !== ''): ?>
            <div class="purchases-alert is-error">
                <i class="bi bi-exclamation-circle"></i>
                <span><?= h($notify) ?></span>
            </div>
        <?php elseif (isset($_GET['success']) && isset($successMessages[$_GET['success']])): ?>
            <div class="purchases-alert is-success">
                <i class="bi bi-check-circle"></i>
                <span><?= h($successMessages[$_GET['success']]) ?></span>
            </div>
        <?php endif; ?>

        <section class="purchases-stats">
            <div class="purchases-stat-card">
                <div class="purchases-stat-icon blue"><i class="bi bi-bag-check"></i></div>
                <div>
                    <span>Total Pembelian</span>
                    <strong><?= number_format($totalPurchases, 0, ',', '.') ?></strong>
                    <small>Transaksi tahun <?= date('Y') ?></small>
                </div>
            </div>

            <div class="purchases-stat-card">
                <div class="purchases-stat-icon green"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <span>Pembelian Bulan Ini</span>
                    <strong><?= h(money($monthlySpend)) ?></strong>
                    <small><?= date('F Y') ?></small>
                </div>
            </div>

            <div class="purchases-stat-card">
                <div class="purchases-stat-icon orange"><i class="bi bi-box-seam"></i></div>
                <div>
                    <span>Produk Dibeli</span>
                    <strong><?= number_format($totalPurchasedQuantity, 0, ',', '.') ?></strong>
                    <small>Total quantity tahun <?= date('Y') ?></small>
                </div>
            </div>

            <div class="purchases-stat-card">
                <div class="purchases-stat-icon purple"><i class="bi bi-truck"></i></div>
                <div>
                    <span>Supplier Bertransaksi</span>
                    <strong><?= number_format($transactingSuppliers, 0, ',', '.') ?></strong>
                    <small>Supplier tahun <?= date('Y') ?></small>
                </div>
            </div>
        </section>

        <section class="purchases-card">
            <div class="purchases-card-header">
                <div>
                    <h2>Daftar Pembelian</h2>
                    <p>Riwayat pembelian produk dari supplier.</p>
                </div>
                <a class="purchases-btn purchases-btn-outline" href="index.php?export=csv">
                    <i class="bi bi-download"></i>
                    Export
                </a>
            </div>

            <form method="get" class="purchases-filter">
                <div class="purchases-search">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" value="<?= h($search) ?>" placeholder="Cari nomor pembelian atau supplier...">
                </div>

                <select name="branch">
                    <option value="">Semua Cabang</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int)$branch['id'] ?>" <?= $branchFilter === (string)$branch['id'] ? 'selected' : '' ?>>
                            <?= h($branch['code'] . ' - ' . $branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status">
                    <option value="">Semua Status</option>
                    <?php foreach ($purchaseStatuses as $status): ?>
                        <option value="<?= h($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                            <?= h(purchase_status_label($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="purchases-filter-btn">
                    <i class="bi bi-search"></i>
                    Cari
                </button>

                <a
                    href="<?= $hasFilters ? 'index.php' : '#' ?>"
                    class="purchases-reset <?= $hasFilters ? '' : 'is-disabled' ?>"
                    <?= $hasFilters ? '' : 'aria-disabled="true" tabindex="-1"' ?>
                >
                    <i class="bi bi-arrow-counterclockwise"></i>
                    Reset
                </a>
            </form>

            <div class="purchases-table-wrap">
                <table class="purchases-table">
                    <thead>
                    <tr>
                        <th>No. Pembelian</th>
                        <th>Tanggal</th>
                        <th>Supplier</th>
                        <th>Item</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Cabang</th>
                        <th>Dibuat Oleh</th>
                        <th>Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$purchases): ?>
                        <tr>
                            <td colspan="9">
                                <div class="purchases-empty">
                                    <i class="bi bi-bag-x"></i>
                                    <strong>Data pembelian tidak ditemukan</strong>
                                    <span>Coba ubah pencarian atau filter Anda.</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($purchases as $purchase): ?>
                            <?php
                            $purchaseStatus = (string)$purchase['status'];
                            $editable = !in_array($purchaseStatus, ['received', 'completed', 'cancelled', 'canceled'], true);
                            ?>
                            <tr>
                                <td>
                                    <span class="purchases-code"><?= h($purchase['purchase_number']) ?></span>
                                    <small><?= h(date('d M Y', strtotime($purchase['purchase_date']))) ?></small>
                                </td>
                                <td><?= h(date('d M Y', strtotime($purchase['purchase_date']))) ?></td>
                                <td>
                                    <div class="purchases-supplier">
                                        <div class="purchases-supplier-icon"><i class="bi bi-building"></i></div>
                                        <div>
                                            <strong><?= h($purchase['supplier_name'] ?: '-') ?></strong>
                                            <small><?= h($purchase['supplier_code'] ?: '-') ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= (int)$purchase['item_count'] ?> item</strong>
                                    <small><?= number_format((int)$purchase['received_total'], 0, ',', '.') ?> / <?= number_format((int)$purchase['quantity_total'], 0, ',', '.') ?> diterima</small>
                                </td>
                                <td>
                                    <strong class="purchases-price"><?= h(money((float)$purchase['grand_total'])) ?></strong>
                                </td>
                                <td>
                                    <span class="purchases-status <?= h(purchase_status_class($purchaseStatus)) ?>">
                                        <?= h(purchase_status_label($purchaseStatus)) ?>
                                    </span>
                                </td>
                                <td><?= h(($purchase['cabang_code'] ? $purchase['cabang_code'] . ' - ' : '') . ($purchase['cabang_name'] ?: '-')) ?></td>
                                <td><?= h($purchase['creator_name'] ?: '-') ?></td>
                                <td>
                                    <div class="purchases-actions">
                                        <a
                                            class="purchases-action-btn view"
                                            title="Detail"
                                            href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter, ['detail' => (int)$purchase['id']])) ?>"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </a>

                                        <?php if ($editable): ?>
                                            <a
                                                class="purchases-action-btn edit"
                                                title="Edit"
                                                href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter, ['edit' => (int)$purchase['id']])) ?>"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>

                                        <a
                                            class="purchases-action-btn print"
                                            title="Cetak"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            href="print.php?id=<?= (int)$purchase['id'] ?>"
                                        >
                                            <i class="bi bi-printer"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="purchases-table-footer">
                <span>Menampilkan <strong><?= $from ?></strong>–<strong><?= $to ?></strong> dari <strong><?= $totalFiltered ?></strong> pembelian</span>

                <div class="purchases-pagination">
                    <a
                        href="<?= $page > 1 ? h(build_page_url($page - 1, $search, $branchFilter, $statusFilter)) : '#' ?>"
                        class="<?= $page <= 1 ? 'is-disabled' : '' ?>"
                        aria-label="Halaman sebelumnya"
                    >
                        <i class="bi bi-chevron-left"></i>
                    </a>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    ?>

                    <?php if ($startPage > 1): ?>
                        <a href="<?= h(build_page_url(1, $search, $branchFilter, $statusFilter)) ?>">1</a>
                        <?php if ($startPage > 2): ?><span class="page-ellipsis">...</span><?php endif; ?>
                    <?php endif; ?>

                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                        <a
                            href="<?= h(build_page_url($p, $search, $branchFilter, $statusFilter)) ?>"
                            class="<?= $p === $page ? 'is-active' : '' ?>"
                        >
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?><span class="page-ellipsis">...</span><?php endif; ?>
                        <a href="<?= h(build_page_url($totalPages, $search, $branchFilter, $statusFilter)) ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>

                    <a
                        href="<?= $page < $totalPages ? h(build_page_url($page + 1, $search, $branchFilter, $statusFilter)) : '#' ?>"
                        class="<?= $page >= $totalPages ? 'is-disabled' : '' ?>"
                        aria-label="Halaman berikutnya"
                    >
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>
        </section>
    </div>
</main>

<div class="purchases-modal <?= $editPurchase ? 'show' : '' ?>" id="purchaseModal">
    <div class="purchases-modal-panel purchases-modal-wide">
        <div class="purchases-modal-header">
            <div>
                <h2><?= $editPurchase ? 'Edit Pembelian' : 'Buat Pembelian Baru' ?></h2>
                <p><?= $editPurchase ? 'Perbarui transaksi pembelian sebelum transaksi difinalkan.' : 'Masukkan supplier, cabang tujuan, dan detail produk.' ?></p>
            </div>

            <?php if ($editPurchase): ?>
                <a class="purchases-modal-close" title="Tutup" href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter)) ?>">
                    <i class="bi bi-x-lg"></i>
                </a>
            <?php else: ?>
                <button class="purchases-modal-close" type="button" onclick="closePurchaseModal()" title="Tutup">
                    <i class="bi bi-x-lg"></i>
                </button>
            <?php endif; ?>
        </div>

        <form method="post" id="purchaseForm">
            <input type="hidden" name="action" value="<?= $editPurchase ? 'update_purchase' : 'add_purchase' ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <?php if ($editPurchase): ?>
                <input type="hidden" name="purchase_id" value="<?= (int)$editPurchase['id'] ?>">
            <?php endif; ?>

            <div class="purchases-form-body">
                <section class="purchases-form-section">
                    <div class="purchases-section-title">
                        <i class="bi bi-receipt"></i>
                        Informasi Pembelian
                    </div>

                    <div class="purchases-form-grid">
                        <div class="purchases-form-group">
                            <label>No. Pembelian</label>
                            <input type="text" value="<?= h($editPurchase['purchase_number'] ?? generate_purchase_number($pdo)) ?>" readonly>
                        </div>

                        <div class="purchases-form-group">
                            <label>Tanggal Pembelian <span>*</span></label>
                            <input type="date" name="purchase_date" value="<?= h($editPurchase['purchase_date'] ?? date('Y-m-d')) ?>" required>
                        </div>

                        <div class="purchases-form-group">
                            <label>Supplier <span>*</span></label>
                            <select name="supplier_id" required>
                                <option value="">Pilih supplier</option>
                                <?php foreach ($supplierOptions as $supplier): ?>
                                    <option
                                        value="<?= (int)$supplier['id'] ?>"
                                        <?= $editPurchase && (int)$editPurchase['supplier_id'] === (int)$supplier['id'] ? 'selected' : '' ?>
                                    >
                                        <?= h($supplier['code'] . ' - ' . $supplier['name']) ?>
                                        <?= $supplier['status'] !== 'active' ? ' (Nonaktif)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="purchases-form-group">
                            <label>Cabang Tujuan <span>*</span></label>
                            <select name="cabang_id" required>
                                <option value="">Pilih cabang</option>
                                <?php foreach ($branchOptions as $branch): ?>
                                    <option
                                        value="<?= (int)$branch['id'] ?>"
                                        <?= $editPurchase && (int)$editPurchase['cabang_id'] === (int)$branch['id'] ? 'selected' : '' ?>
                                    >
                                        <?= h($branch['code'] . ' - ' . $branch['name']) ?>
                                        <?= $branch['status'] !== 'active' ? ' (Nonaktif)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="purchases-form-group">
                            <label>Status <span>*</span></label>
                            <select name="status" required>
                                <?php foreach ($purchaseStatuses as $status): ?>
                                    <option
                                        value="<?= h($status) ?>"
                                        <?= ($editPurchase['status'] ?? $defaultPurchaseStatus) === $status ? 'selected' : '' ?>
                                    >
                                        <?= h(purchase_status_label($status)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </section>

                <section class="purchases-form-section">
                    <div class="purchases-section-title purchases-product-title">
                        <div><i class="bi bi-box-seam"></i> Detail Produk</div>
                        <button type="button" class="purchases-add-line" onclick="addPurchaseLine()">
                            <i class="bi bi-plus-lg"></i> Tambah Produk
                        </button>
                    </div>

                    <div class="purchases-line-table-wrap">
                        <table class="purchases-line-table">
                            <thead>
                            <tr>
                                <th>Produk</th>
                                <th width="150">Harga</th>
                                <th width="95">Qty</th>
                                <th width="125">Diterima</th>
                                <th width="140">Diskon</th>
                                <th width="160">Subtotal</th>
                                <th width="52"></th>
                            </tr>
                            </thead>
                            <tbody id="purchaseLineItems">
                            <?php if ($editLines): ?>
                                <?php foreach ($editLines as $line): ?>
                                    <tr>
                                        <td>
                                            <select name="product_id[]" class="purchase-product-select" onchange="syncPurchaseLine(this)">
                                                <option value="">Pilih produk</option>
                                                <?php foreach ($productOptions as $product): ?>
                                                    <option
                                                        value="<?= (int)$product['id'] ?>"
                                                        data-info='<?= product_option_data($product) ?>'
                                                        <?= (int)$line['product_id'] === (int)$product['id'] ? 'selected' : '' ?>
                                                    >
                                                        <?= h($product['code'] . ' - ' . $product['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="line-unit"></small>
                                        </td>
                                        <td><input type="number" name="price[]" class="purchase-price" min="0" step="0.01" value="<?= h((float)$line['price']) ?>" oninput="recalculatePurchase()"></td>
                                        <td><input type="number" name="quantity[]" class="purchase-qty" min="1" step="1" value="<?= (int)$line['quantity'] ?>" oninput="recalculatePurchase()"></td>
                                        <td><input type="number" name="received_quantity[]" class="purchase-received" min="0" step="1" max="<?= (int)$line['quantity'] ?>" value="<?= (int)$line['received_quantity'] ?>" oninput="recalculatePurchase()"></td>
                                        <td><input type="number" name="line_discount[]" class="purchase-line-discount" min="0" step="0.01" value="<?= h((float)$line['discount']) ?>" oninput="recalculatePurchase()"></td>
                                        <td><strong class="purchase-line-subtotal"><?= h(money((float)$line['subtotal'])) ?></strong></td>
                                        <td><button type="button" class="purchases-line-remove" onclick="removePurchaseLine(this)" title="Hapus baris"><i class="bi bi-x"></i></button></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td>
                                        <select name="product_id[]" class="purchase-product-select" onchange="syncPurchaseLine(this)">
                                            <option value="">Pilih produk</option>
                                            <?php foreach ($productOptions as $product): ?>
                                                <option value="<?= (int)$product['id'] ?>" data-info='<?= product_option_data($product) ?>'>
                                                    <?= h($product['code'] . ' - ' . $product['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="line-unit"></small>
                                    </td>
                                    <td><input type="number" name="price[]" class="purchase-price" min="0" step="0.01" value="0" oninput="recalculatePurchase()"></td>
                                    <td><input type="number" name="quantity[]" class="purchase-qty" min="1" step="1" value="1" oninput="recalculatePurchase()"></td>
                                    <td><input type="number" name="received_quantity[]" class="purchase-received" min="0" step="1" max="1" value="0" oninput="recalculatePurchase()"></td>
                                    <td><input type="number" name="line_discount[]" class="purchase-line-discount" min="0" step="0.01" value="0" oninput="recalculatePurchase()"></td>
                                    <td><strong class="purchase-line-subtotal">Rp 0</strong></td>
                                    <td><button type="button" class="purchases-line-remove" onclick="removePurchaseLine(this)" title="Hapus baris"><i class="bi bi-x"></i></button></td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="purchases-form-section purchases-summary-section">
                    <div class="purchases-notice">
                        <i class="bi bi-info-circle"></i>
                        <div>
                            <strong>Kontrol penerimaan</strong>
                            <p>Jumlah diterima tidak boleh melebihi quantity pembelian. Stok dapat diintegrasikan dari <b>received_quantity</b> pada modul persediaan.</p>
                        </div>
                    </div>

                    <div class="purchases-bottom-grid">
                        <div></div>

                        <div class="purchases-summary-box">
                            <div class="purchases-summary-row">
                                <span>Subtotal</span>
                                <strong id="purchaseSubtotal">Rp 0</strong>
                            </div>
                            <div class="purchases-summary-row">
                                <span>Diskon</span>
                                <input type="number" name="discount" id="purchaseDiscount" min="0" step="0.01" value="<?= h((float)($editPurchase['discount'] ?? 0)) ?>" oninput="recalculatePurchase()">
                            </div>
                            <div class="purchases-summary-row">
                                <span>Pajak</span>
                                <input type="number" name="tax" id="purchaseTax" min="0" step="0.01" value="<?= h((float)($editPurchase['tax'] ?? 0)) ?>" oninput="recalculatePurchase()">
                            </div>
                            <div class="purchases-summary-row total">
                                <span>Grand Total</span>
                                <strong id="purchaseGrandTotal">Rp 0</strong>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="purchases-modal-footer">
                <?php if ($editPurchase): ?>
                    <a class="purchases-btn purchases-btn-secondary" href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter)) ?>">Batal</a>
                <?php else: ?>
                    <button type="button" class="purchases-btn purchases-btn-secondary" onclick="closePurchaseModal()">Batal</button>
                <?php endif; ?>

                <button type="submit" class="purchases-btn purchases-btn-primary">
                    <i class="bi bi-check-lg"></i>
                    <?= $editPurchase ? 'Simpan Perubahan' : 'Simpan Pembelian' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($detailPurchase): ?>
<div class="purchases-modal show" id="purchaseDetailModal">
    <div class="purchases-modal-panel purchases-detail-panel">
        <div class="purchases-modal-header">
            <div>
                <h2>Detail Pembelian</h2>
                <p><?= h($detailPurchase['purchase_number']) ?></p>
            </div>
            <a class="purchases-modal-close" title="Tutup" href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter)) ?>">
                <i class="bi bi-x-lg"></i>
            </a>
        </div>

        <div class="purchases-detail-body">
            <div class="purchases-detail-head">
                <div>
                    <span>No. Pembelian</span>
                    <strong><?= h($detailPurchase['purchase_number']) ?></strong>
                </div>
                <div>
                    <span>Tanggal</span>
                    <strong><?= h(date('d M Y', strtotime($detailPurchase['purchase_date']))) ?></strong>
                </div>
                <div>
                    <span>Status</span>
                    <strong><span class="purchases-status <?= h(purchase_status_class((string)$detailPurchase['status'])) ?>"><?= h(purchase_status_label((string)$detailPurchase['status'])) ?></span></strong>
                </div>
            </div>

            <div class="purchases-detail-grid">
                <div class="purchases-detail-card">
                    <span>Supplier</span>
                    <strong><?= h($detailPurchase['supplier_name'] ?: '-') ?></strong>
                    <small><?= h($detailPurchase['supplier_code'] ?: '-') ?><?= $detailPurchase['supplier_phone'] ? ' · ' . h($detailPurchase['supplier_phone']) : '' ?></small>
                </div>
                <div class="purchases-detail-card">
                    <span>Cabang Tujuan</span>
                    <strong><?= h(($detailPurchase['cabang_code'] ? $detailPurchase['cabang_code'] . ' - ' : '') . ($detailPurchase['cabang_name'] ?: '-')) ?></strong>
                </div>
                <div class="purchases-detail-card">
                    <span>Dibuat Oleh</span>
                    <strong><?= h($detailPurchase['creator_name'] ?: '-') ?></strong>
                </div>
                <div class="purchases-detail-card">
                    <span>Nilai Transaksi</span>
                    <strong><?= h(money((float)$detailPurchase['grand_total'])) ?></strong>
                </div>
            </div>

            <div class="purchases-detail-table-wrap">
                <table class="purchases-detail-table">
                    <thead>
                    <tr>
                        <th>No</th>
                        <th>Produk</th>
                        <th>Harga</th>
                        <th>Qty</th>
                        <th>Diterima</th>
                        <th>Diskon</th>
                        <th>Subtotal</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($detailLines as $i => $line): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <strong><?= h($line['product_name'] ?: '-') ?></strong>
                                <small><?= h($line['product_code'] ?: '-') ?></small>
                            </td>
                            <td><?= h(money((float)$line['price'])) ?></td>
                            <td><?= number_format((int)$line['quantity'], 0, ',', '.') ?></td>
                            <td><?= number_format((int)$line['received_quantity'], 0, ',', '.') ?></td>
                            <td><?= h(money((float)$line['discount'])) ?></td>
                            <td><strong><?= h(money((float)$line['subtotal'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="purchases-detail-total">
                <div><span>Subtotal</span><strong><?= h(money((float)$detailPurchase['subtotal'])) ?></strong></div>
                <div><span>Diskon</span><strong><?= h(money((float)$detailPurchase['discount'])) ?></strong></div>
                <div><span>Pajak</span><strong><?= h(money((float)$detailPurchase['tax'])) ?></strong></div>
                <div class="grand"><span>Grand Total</span><strong><?= h(money((float)$detailPurchase['grand_total'])) ?></strong></div>
            </div>

            <div class="purchases-detail-actions">
                <a class="purchases-btn purchases-btn-secondary" href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter)) ?>">Tutup</a>
                <a class="purchases-btn purchases-btn-primary" target="_blank" rel="noopener noreferrer" href="print.php?id=<?= (int)$detailPurchase['id'] ?>">
                    <i class="bi bi-printer"></i> Cetak
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<template id="purchaseRowTemplate">
    <tr>
        <td>
            <select name="product_id[]" class="purchase-product-select" onchange="syncPurchaseLine(this)">
                <option value="">Pilih produk</option>
                <?php foreach ($productOptions as $product): ?>
                    <option value="<?= (int)$product['id'] ?>" data-info='<?= product_option_data($product) ?>'>
                        <?= h($product['code'] . ' - ' . $product['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="line-unit"></small>
        </td>
        <td><input type="number" name="price[]" class="purchase-price" min="0" step="0.01" value="0" oninput="recalculatePurchase()"></td>
        <td><input type="number" name="quantity[]" class="purchase-qty" min="1" step="1" value="1" oninput="recalculatePurchase()"></td>
        <td><input type="number" name="received_quantity[]" class="purchase-received" min="0" step="1" max="1" value="0" oninput="recalculatePurchase()"></td>
        <td><input type="number" name="line_discount[]" class="purchase-line-discount" min="0" step="0.01" value="0" oninput="recalculatePurchase()"></td>
        <td><strong class="purchase-line-subtotal">Rp 0</strong></td>
        <td><button type="button" class="purchases-line-remove" onclick="removePurchaseLine(this)" title="Hapus baris"><i class="bi bi-x"></i></button></td>
    </tr>
</template>

<script>
function openPurchaseModal() {
    const modal = document.getElementById('purchaseModal');
    if (!modal) return;
    modal.classList.add('show');
    const form = document.getElementById('purchaseForm');
    if (form) form.reset();
    recalculatePurchase();
}

function closePurchaseModal() {
    const modal = document.getElementById('purchaseModal');
    if (modal) modal.classList.remove('show');
}

function parseProductInfo(select) {
    const option = select?.options[select.selectedIndex];
    if (!option || !option.dataset.info) return null;

    try {
        return JSON.parse(option.dataset.info);
    } catch (e) {
        return null;
    }
}

function syncPurchaseLine(select) {
    const row = select.closest('tr');
    if (!row) return;

    const info = parseProductInfo(select);
    const priceInput = row.querySelector('.purchase-price');
    const unitLabel = row.querySelector('.line-unit');

    if (info) {
        if (priceInput && (!priceInput.value || parseFloat(priceInput.value) === 0)) {
            priceInput.value = info.price;
        }
        if (unitLabel) {
            unitLabel.textContent = info.unit ? 'Satuan: ' + info.unit : '';
        }
    } else if (unitLabel) {
        unitLabel.textContent = '';
    }

    recalculatePurchase();
}

function addPurchaseLine() {
    const template = document.getElementById('purchaseRowTemplate');
    const tbody = document.getElementById('purchaseLineItems');
    if (!template || !tbody) return;

    tbody.appendChild(template.content.cloneNode(true));
    recalculatePurchase();
}

function removePurchaseLine(button) {
    const tbody = document.getElementById('purchaseLineItems');
    const row = button.closest('tr');
    if (!tbody || !row) return;

    if (tbody.querySelectorAll('tr').length <= 1) {
        const selects = row.querySelectorAll('select, input');
        selects.forEach(el => {
            if (el.tagName === 'SELECT') el.selectedIndex = 0;
            else if (el.classList.contains('purchase-qty')) el.value = 1;
            else if (el.classList.contains('purchase-received')) el.value = 0;
            else el.value = 0;
        });
    } else {
        row.remove();
    }

    recalculatePurchase();
}

function recalculatePurchase() {
    const rows = document.querySelectorAll('#purchaseLineItems tr');
    let subtotal = 0;

    rows.forEach(row => {
        const price = parseFloat(row.querySelector('.purchase-price')?.value || 0) || 0;
        const qty = parseInt(row.querySelector('.purchase-qty')?.value || 0, 10) || 0;
        const discountInput = row.querySelector('.purchase-line-discount');
        const receivedInput = row.querySelector('.purchase-received');

        let discount = parseFloat(discountInput?.value || 0) || 0;
        discount = Math.min(discount, price * qty);

        if (discountInput) discountInput.value = discount;

        if (receivedInput) {
            const received = Math.max(0, Math.min(qty, parseInt(receivedInput.value || 0, 10) || 0));
            receivedInput.max = qty;
            receivedInput.value = received;
        }

        const lineSubtotal = Math.max(0, (price * qty) - discount);
        const label = row.querySelector('.purchase-line-subtotal');
        if (label) label.textContent = formatRupiah(lineSubtotal);

        subtotal += lineSubtotal;
    });

    const discount = Math.max(0, parseFloat(document.getElementById('purchaseDiscount')?.value || 0) || 0);
    const tax = Math.max(0, parseFloat(document.getElementById('purchaseTax')?.value || 0) || 0);
    const headerDiscount = Math.min(discount, subtotal);
    const total = Math.max(0, subtotal - headerDiscount + tax);

    if (document.getElementById('purchaseSubtotal')) {
        document.getElementById('purchaseSubtotal').textContent = formatRupiah(subtotal);
    }
    if (document.getElementById('purchaseDiscount')) {
        document.getElementById('purchaseDiscount').value = headerDiscount;
    }
    if (document.getElementById('purchaseGrandTotal')) {
        document.getElementById('purchaseGrandTotal').textContent = formatRupiah(total);
    }
}

function formatRupiah(value) {
    return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(value);
}

document.addEventListener('change', function(event) {
    if (event.target.matches('.purchase-product-select')) {
        syncPurchaseLine(event.target);
    }
});

window.addEventListener('click', function(event) {
    const modal = document.getElementById('purchaseModal');
    if (modal && event.target === modal && !<?= $editPurchase ? 'true' : 'false' ?>) {
        closePurchaseModal();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.purchase-product-select').forEach(syncPurchaseLine);
    recalculatePurchase();

    const form = document.getElementById('purchaseForm');
    if (form) {
        form.addEventListener('submit', function() {
            recalculatePurchase();
        });
    }
});
</script>

</body>
</html>
