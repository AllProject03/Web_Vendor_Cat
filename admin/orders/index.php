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

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_orders(array $params = []): never
{
    $url = 'index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

function money(float $value): string
{
    return 'Rp' . number_format($value, 0, ',', '.');
}

function normalize_positive_int($value): int
{
    $value = (int)$value;
    return $value > 0 ? $value : 0;
}

function get_current_user_id(): int
{
    $id = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    return $id;
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
        ':created_by' => get_current_user_id(),
    ]);
}



function get_order_stock_requirements(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare(
        "SELECT product_id, quantity
         FROM order_details
         WHERE order_id = :order_id"
    );
    $stmt->execute([':order_id' => $orderId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $productId = (int)$row['product_id'];
        if ($productId <= 0) continue;
        $map[$productId] = ($map[$productId] ?? 0) + (float)$row['quantity'];
    }
    return $map;
}

function ensure_order_branch_stock_row(PDO $pdo, int $branchId, int $productId): void
{
    $stmt = $pdo->prepare(
        "SELECT id FROM branch_stocks
         WHERE cabang_id = :cabang_id AND product_id = :product_id
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([':cabang_id' => $branchId, ':product_id' => $productId]);
    if ($stmt->fetchColumn() === false) {
        $insert = $pdo->prepare(
            "INSERT INTO branch_stocks (cabang_id, product_id, stock, minimum_stock)
             VALUES (:cabang_id, :product_id, 0, 0)"
        );
        $insert->execute([':cabang_id' => $branchId, ':product_id' => $productId]);
    }
}

function apply_order_stock_delta(PDO $pdo, int $branchId, int $productId, float $delta, int $orderId): void
{
    if (abs($delta) < 0.000001) return;

    ensure_order_branch_stock_row($pdo, $branchId, $productId);

    $stockStmt = $pdo->prepare(
        "SELECT stock, id
         FROM branch_stocks
         WHERE cabang_id = :cabang_id AND product_id = :product_id
         LIMIT 1 FOR UPDATE"
    );
    $stockStmt->execute([':cabang_id' => $branchId, ':product_id' => $productId]);
    $stockRow = $stockStmt->fetch();
    if (!$stockRow) throw new Exception('Data stok cabang tidak ditemukan.');

    $currentStock = (float)$stockRow['stock'];
    if ($delta < 0 && $currentStock + $delta < 0) {
        throw new Exception('Stok cabang tidak mencukupi untuk transaksi penjualan.');
    }

    $update = $pdo->prepare(
        "UPDATE branch_stocks
         SET stock = stock + :delta
         WHERE id = :id"
    );
    $update->execute([':delta' => $delta, ':id' => (int)$stockRow['id']]);

    insert_stock_movement(
        $pdo,
        (int)$stockRow['id'],
        $delta,
        ['sale', 'sales', 'order', 'orders', 'penjualan'],
        ['sale_in', 'sale', 'sales', 'order_in', 'order', 'orders', 'in', 'stock_in', 'penjualan'],
        ['sale_out', 'sale', 'sales', 'order_out', 'order', 'orders', 'out', 'stock_out', 'penjualan'],
        ['order', 'orders', 'sale', 'sales', 'penjualan'],
        $orderId,
        $delta < 0 ? 'Pengurangan stok karena penjualan' : 'Pembalikan/penyesuaian stok penjualan'
    );
}

function order_stock_deltas(array $oldMap, array $newMap, int $oldBranchId, int $newBranchId): array
{
    // Stok = stok sebelum dikurangi konsumsi lama, lalu dikurangi konsumsi baru.
    // Jadi delta stok = old_consumption - new_consumption.
    $deltas = [];
    foreach ($oldMap as $productId => $qty) {
        $key = $oldBranchId . ':' . $productId;
        $deltas[$key] = ($deltas[$key] ?? 0) + (float)$qty;
    }
    foreach ($newMap as $productId => $qty) {
        $key = $newBranchId . ':' . $productId;
        $deltas[$key] = ($deltas[$key] ?? 0) - (float)$qty;
    }
    return $deltas;
}

function generate_order_number(PDO $pdo): string
{
    $year = date('Y');

    $stmt = $pdo->prepare(
        "SELECT order_number
         FROM orders
         WHERE order_number LIKE :prefix
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([':prefix' => 'ORD-' . $year . '-%']);
    $last = $stmt->fetchColumn();

    $sequence = 1;

    if (is_string($last) && preg_match('/(\d+)$/', $last, $match)) {
        $sequence = (int)$match[1] + 1;
    }

    return sprintf('ORD-%s-%04d', $year, $sequence);
}

function get_order_status_values(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'orders'
           AND COLUMN_NAME = 'status'
         LIMIT 1"
    );

    $columnType = (string)$stmt->fetchColumn();
    if (!preg_match('/^enum\((.*)\)$/i', $columnType, $match)) {
        return [];
    }

    $enumBody = trim($match[1]);
    $values = str_getcsv($enumBody, ",", "'", "\\");

    return array_values(array_filter(array_map(
        static fn($value) => str_replace(["\\'", "\\\\"], ["'", "\\"], trim($value)),
        $values
    ), static fn($value) => $value !== ""));
}

function order_status_label(string $status): string
{
    return match ($status) {
        'draft' => 'Draft',
        'pending' => 'Menunggu',
        'processing' => 'Diproses',
        'completed' => 'Selesai',
        'cancelled', 'canceled' => 'Dibatalkan',
        default => ucwords(str_replace(['_', '-'], ' ', $status)),
    };
}

function order_status_class(string $status): string
{
    return match ($status) {
        'draft', 'pending' => 'waiting',
        'processing' => 'process',
        'completed' => 'completed',
        'cancelled', 'canceled' => 'cancelled',
        default => 'waiting',
    };
}

function valid_status(string $status, array $allowedStatuses): bool
{
    return in_array($status, $allowedStatuses, true);
}

function get_order_header(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            o.*,
            c.name AS customer_name,
            c.customer_type,
            c.phone AS customer_phone,
            c.email AS customer_email,
            cb.code AS cabang_code,
            cb.name AS cabang_name,
            v.plate_number,
            v.brand AS vehicle_brand,
            v.model AS vehicle_model,
            v.year AS vehicle_year,
            v.color AS vehicle_color,
            u.name AS creator_name
         FROM orders o
         LEFT JOIN customers c ON c.id = o.customer_id
         LEFT JOIN cabangs cb ON cb.id = o.cabang_id
         LEFT JOIN vehicles v ON v.id = o.vehicle_id
         LEFT JOIN users u ON u.id = o.created_by
         WHERE o.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function order_lines(PDO $pdo, int $orderId): array
{
    $productStmt = $pdo->prepare(
        "SELECT
            od.*,
            p.code AS product_code,
            p.name AS product_name,
            p.brand AS product_brand,
            p.unit AS product_unit
         FROM order_details od
         LEFT JOIN products p ON p.id = od.product_id
         WHERE od.order_id = :order_id
         ORDER BY od.id ASC"
    );
    $productStmt->execute([':order_id' => $orderId]);

    $serviceStmt = $pdo->prepare(
        "SELECT
            os.*,
            s.name AS service_name,
            s.service_type,
            s.description AS service_description
         FROM order_services os
         LEFT JOIN services s ON s.id = os.service_id
         WHERE os.order_id = :order_id
         ORDER BY os.id ASC"
    );
    $serviceStmt->execute([':order_id' => $orderId]);

    return [
        'products' => $productStmt->fetchAll(),
        'services' => $serviceStmt->fetchAll(),
    ];
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

function fetch_customer_options(PDO $pdo, ?int $selectedId = null): array
{
    $sql = "SELECT id, name, customer_type, phone, status, cabang_id
            FROM customers
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

function fetch_vehicle_options(PDO $pdo, array $selectedIds = []): array
{
    $sql = "SELECT
                id,
                customer_id,
                cabang_id,
                plate_number,
                brand,
                model,
                year,
                color,
                status
            FROM vehicles
            WHERE status = 'active'";
    $params = [];

    if ($selectedIds) {
        $placeholders = [];
        foreach ($selectedIds as $i => $id) {
            $key = ':vehicle_selected_' . $i;
            $placeholders[] = $key;
            $params[$key] = $id;
        }
        $sql .= " OR id IN (" . implode(',', $placeholders) . ")";
    }

    $sql .= " ORDER BY brand ASC, model ASC, plate_number ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_product_options(PDO $pdo, array $selectedIds = []): array
{
    $sql = "SELECT id, code, name, brand, unit, selling_price, status
            FROM products
            WHERE status = 'active'";
    $params = [];

    if ($selectedIds) {
        $placeholders = [];
        foreach ($selectedIds as $i => $id) {
            $key = ':product_selected_' . $i;
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

function fetch_service_options(PDO $pdo, array $selectedIds = []): array
{
    $sql = "SELECT id, name, service_type, description, price, status
            FROM services
            WHERE status = 'active'";
    $params = [];

    if ($selectedIds) {
        $placeholders = [];
        foreach ($selectedIds as $i => $id) {
            $key = ':service_selected_' . $i;
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

function unique_valid_ids(PDO $pdo, string $table, string $column, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }

    $allowedTables = [
        'products' => 'products',
        'services' => 'services',
    ];

    if (!isset($allowedTables[$table]) || $column !== 'id') {
        throw new InvalidArgumentException('Tabel referensi tidak valid.');
    }

    $placeholders = [];
    $params = [];

    foreach ($ids as $i => $id) {
        $key = ':id_' . $i;
        $placeholders[] = $key;
        $params[$key] = $id;
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM {$allowedTables[$table]}
         WHERE id IN (" . implode(',', $placeholders) . ")"
    );
    $stmt->execute($params);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

if (empty($_SESSION['csrf_orders'])) {
    $_SESSION['csrf_orders'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_orders'];

$orderStatuses = get_order_status_values($pdo);
if (!$orderStatuses) {
    $orderStatuses = ['draft', 'pending', 'processing', 'completed', 'cancelled'];
}
$defaultOrderStatus = in_array('draft', $orderStatuses, true) ? 'draft' : $orderStatuses[0];

$notify = '';
$notifyType = '';

/* ============================================================
   POST / CRUD TRANSAKSI
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_orders'] ?? '', $token)) {
        $notify = 'Permintaan tidak valid (CSRF).';
        $notifyType = 'error';
    } else {
        try {
            $pdo->beginTransaction();

            if ($action === 'add_order' || $action === 'update_order') {
                $isUpdate = $action === 'update_order';
                $orderId = (int)($_POST['order_id'] ?? 0);

                if ($isUpdate && $orderId <= 0) {
                    throw new Exception('ID pesanan tidak valid.');
                }

                $orderDate = trim($_POST['order_date'] ?? '');
                $cabangId = normalize_positive_int($_POST['cabang_id'] ?? 0);
                $customerId = normalize_positive_int($_POST['customer_id'] ?? 0);
                $vehicleId = normalize_positive_int($_POST['vehicle_id'] ?? 0);
                $status = trim($_POST['status'] ?? $defaultOrderStatus);

                $orderDiscount = max(0, (float)($_POST['discount'] ?? 0));
                $tax = max(0, (float)($_POST['tax'] ?? 0));

                if ($orderDate === '') {
                    throw new Exception('Tanggal pesanan wajib diisi.');
                }

                $dateCheck = DateTime::createFromFormat('Y-m-d', $orderDate);
                if (!$dateCheck || $dateCheck->format('Y-m-d') !== $orderDate) {
                    throw new Exception('Format tanggal pesanan tidak valid.');
                }

                if ($cabangId <= 0) throw new Exception('Cabang wajib dipilih.');
                if ($customerId <= 0) throw new Exception('Pelanggan wajib dipilih.');
                if ($vehicleId <= 0) throw new Exception('Kendaraan wajib dipilih.');
                if (!valid_status($status, $orderStatuses)) throw new Exception('Status pesanan tidak valid.');

                $branchCheck = $pdo->prepare("SELECT COUNT(*) FROM cabangs WHERE id = :id AND status = 'active'");
                $branchCheck->execute([':id' => $cabangId]);
                if ((int)$branchCheck->fetchColumn() === 0) {
                    throw new Exception('Cabang tidak ditemukan atau tidak aktif.');
                }

                $customerCheck = $pdo->prepare(
                    "SELECT COUNT(*) FROM customers
                     WHERE id = :id AND status = 'active'"
                );
                $customerCheck->execute([':id' => $customerId]);
                if ((int)$customerCheck->fetchColumn() === 0) {
                    throw new Exception('Pelanggan tidak ditemukan atau tidak aktif.');
                }

                $vehicleCheck = $pdo->prepare(
                    "SELECT COUNT(*) FROM vehicles
                     WHERE id = :id
                       AND customer_id = :customer_id
                       AND status = 'active'"
                );
                $vehicleCheck->execute([
                    ':id' => $vehicleId,
                    ':customer_id' => $customerId,
                ]);

                if ((int)$vehicleCheck->fetchColumn() === 0) {
                    throw new Exception('Kendaraan tidak valid atau bukan milik pelanggan yang dipilih.');
                }

                $productIds = $_POST['product_id'] ?? [];
                $productDescriptions = $_POST['product_description'] ?? [];
                $productQuantities = $_POST['product_quantity'] ?? [];
                $productDiscounts = $_POST['product_discount'] ?? [];

                $serviceIds = $_POST['service_id'] ?? [];
                $serviceQuantities = $_POST['service_quantity'] ?? [];
                $serviceDiscounts = $_POST['service_discount'] ?? [];

                if (!is_array($productIds)) $productIds = [];
                if (!is_array($productDescriptions)) $productDescriptions = [];
                if (!is_array($productQuantities)) $productQuantities = [];
                if (!is_array($productDiscounts)) $productDiscounts = [];
                if (!is_array($serviceIds)) $serviceIds = [];
                if (!is_array($serviceQuantities)) $serviceQuantities = [];
                if (!is_array($serviceDiscounts)) $serviceDiscounts = [];

                $productIds = array_values(array_filter(array_map('intval', $productIds)));
                $serviceIds = array_values(array_filter(array_map('intval', $serviceIds)));

                if (!$productIds && !$serviceIds) {
                    throw new Exception('Tambahkan minimal satu produk atau jasa.');
                }

                $productValidIds = unique_valid_ids($pdo, 'products', 'id', $productIds);
                $serviceValidIds = unique_valid_ids($pdo, 'services', 'id', $serviceIds);

                if (count($productValidIds) !== count(array_unique($productIds))) {
                    throw new Exception('Terdapat produk yang tidak ditemukan.');
                }

                if (count($serviceValidIds) !== count(array_unique($serviceIds))) {
                    throw new Exception('Terdapat jasa yang tidak ditemukan.');
                }

                $productPrices = [];
                if ($productValidIds) {
                    $placeholders = [];
                    $params = [];
                    foreach ($productValidIds as $i => $id) {
                        $key = ':pid_' . $i;
                        $placeholders[] = $key;
                        $params[$key] = $id;
                    }
                    $stmt = $pdo->prepare(
                        "SELECT id, name, selling_price
                         FROM products
                         WHERE id IN (" . implode(',', $placeholders) . ")"
                    );
                    $stmt->execute($params);
                    foreach ($stmt->fetchAll() as $row) {
                        $productPrices[(int)$row['id']] = [
                            'name' => $row['name'],
                            'price' => (float)$row['selling_price'],
                        ];
                    }
                }

                $servicePrices = [];
                if ($serviceValidIds) {
                    $placeholders = [];
                    $params = [];
                    foreach ($serviceValidIds as $i => $id) {
                        $key = ':sid_' . $i;
                        $placeholders[] = $key;
                        $params[$key] = $id;
                    }
                    $stmt = $pdo->prepare(
                        "SELECT id, name, price
                         FROM services
                         WHERE id IN (" . implode(',', $placeholders) . ")"
                    );
                    $stmt->execute($params);
                    foreach ($stmt->fetchAll() as $row) {
                        $servicePrices[(int)$row['id']] = [
                            'name' => $row['name'],
                            'price' => (float)$row['price'],
                        ];
                    }
                }

                $preparedProducts = [];
                $subtotalProducts = 0.0;

                foreach ($productIds as $index => $productId) {
                    if (!isset($productPrices[$productId])) {
                        throw new Exception('Harga produk tidak ditemukan.');
                    }

                    $quantity = max(1, (int)($productQuantities[$index] ?? 1));
                    $discount = max(0, (float)($productDiscounts[$index] ?? 0));
                    $price = $productPrices[$productId]['price'];
                    $lineSubtotal = max(0, ($quantity * $price) - $discount);

                    $description = trim((string)($productDescriptions[$index] ?? ''));
                    if ($description === '') {
                        $description = $productPrices[$productId]['name'];
                    }

                    $preparedProducts[] = [
                        'product_id' => $productId,
                        'description' => $description,
                        'quantity' => $quantity,
                        'price' => $price,
                        'discount' => $discount,
                        'subtotal' => $lineSubtotal,
                    ];
                    $subtotalProducts += $lineSubtotal;
                }

                $preparedServices = [];
                $subtotalServices = 0.0;

                foreach ($serviceIds as $index => $serviceId) {
                    if (!isset($servicePrices[$serviceId])) {
                        throw new Exception('Harga jasa tidak ditemukan.');
                    }

                    $quantity = max(1, (int)($serviceQuantities[$index] ?? 1));
                    $discount = max(0, (float)($serviceDiscounts[$index] ?? 0));
                    $price = $servicePrices[$serviceId]['price'];
                    $lineSubtotal = max(0, ($quantity * $price) - $discount);

                    $preparedServices[] = [
                        'service_id' => $serviceId,
                        'quantity' => $quantity,
                        'price' => $price,
                        'discount' => $discount,
                        'subtotal' => $lineSubtotal,
                    ];
                    $subtotalServices += $lineSubtotal;
                }

                $subtotal = $subtotalProducts + $subtotalServices;
                $orderDiscount = min($orderDiscount, $subtotal);
                $grandTotal = max(0, $subtotal - $orderDiscount + $tax);

                // Pastikan edit penjualan tidak membuat total tagihan
                // lebih kecil dari pembayaran yang sudah berstatus paid.
                if ($isUpdate) {
                    $paidStmt = $pdo->prepare(
                        "SELECT COALESCE(SUM(amount), 0)
                         FROM payments
                         WHERE order_id = :order_id
                           AND status = 'paid'"
                    );
                    $paidStmt->execute([':order_id' => $orderId]);
                    $paidTotal = (float)$paidStmt->fetchColumn();

                    if ($grandTotal + 0.000001 < $paidTotal) {
                        throw new Exception(
                            'Grand total baru tidak boleh lebih kecil dari total pembayaran yang sudah diterima (' .
                            money($paidTotal) . ').'
                        );
                    }
                }

                $oldOrderStatus = null;
                $oldOrderBranchId = $cabangId;
                $oldOrderStockMap = [];

                if ($isUpdate) {
                    $existing = get_order_header($pdo, $orderId);
                    if (!$existing) {
                        throw new Exception('Pesanan tidak ditemukan.');
                    }
                    $oldOrderStatus = (string)$existing['status'];
                    $oldOrderBranchId = (int)$existing['cabang_id'];
                    if ($oldOrderStatus === 'completed') {
                        $oldOrderStockMap = get_order_stock_requirements($pdo, $orderId);
                    }

                    $update = $pdo->prepare(
                        "UPDATE orders
                         SET cabang_id = :cabang_id,
                             customer_id = :customer_id,
                             vehicle_id = :vehicle_id,
                             order_date = :order_date,
                             subtotal = :subtotal,
                             discount = :discount,
                             tax = :tax,
                             grand_total = :grand_total,
                             status = :status
                         WHERE id = :id"
                    );
                    $update->execute([
                        ':cabang_id' => $cabangId,
                        ':customer_id' => $customerId,
                        ':vehicle_id' => $vehicleId,
                        ':order_date' => $orderDate,
                        ':subtotal' => $subtotal,
                        ':discount' => $orderDiscount,
                        ':tax' => $tax,
                        ':grand_total' => $grandTotal,
                        ':status' => $status,
                        ':id' => $orderId,
                    ]);

                    $pdo->prepare("DELETE FROM order_details WHERE order_id = :order_id")
                        ->execute([':order_id' => $orderId]);
                    $pdo->prepare("DELETE FROM order_services WHERE order_id = :order_id")
                        ->execute([':order_id' => $orderId]);
                } else {
                    $createdBy = get_current_user_id();
                    if ($createdBy <= 0) {
                        throw new Exception('User login tidak ditemukan pada session.');
                    }

                    $orderNumber = generate_order_number($pdo);

                    $insert = $pdo->prepare(
                        "INSERT INTO orders
                            (order_number, cabang_id, customer_id, vehicle_id, order_date,
                             subtotal, discount, tax, grand_total, status, created_by)
                         VALUES
                            (:order_number, :cabang_id, :customer_id, :vehicle_id, :order_date,
                             :subtotal, :discount, :tax, :grand_total, :status, :created_by)"
                    );
                    $insert->execute([
                        ':order_number' => $orderNumber,
                        ':cabang_id' => $cabangId,
                        ':customer_id' => $customerId,
                        ':vehicle_id' => $vehicleId,
                        ':order_date' => $orderDate,
                        ':subtotal' => $subtotal,
                        ':discount' => $orderDiscount,
                        ':tax' => $tax,
                        ':grand_total' => $grandTotal,
                        ':status' => $status,
                        ':created_by' => $createdBy,
                    ]);

                    $orderId = (int)$pdo->lastInsertId();
                }

                if ($preparedProducts) {
                    $detailInsert = $pdo->prepare(
                        "INSERT INTO order_details
                            (order_id, product_id, description, quantity, price, discount, subtotal)
                         VALUES
                            (:order_id, :product_id, :description, :quantity, :price, :discount, :subtotal)"
                    );

                    foreach ($preparedProducts as $item) {
                        $detailInsert->execute([
                            ':order_id' => $orderId,
                            ':product_id' => $item['product_id'],
                            ':description' => $item['description'],
                            ':quantity' => $item['quantity'],
                            ':price' => $item['price'],
                            ':discount' => $item['discount'],
                            ':subtotal' => $item['subtotal'],
                        ]);
                    }
                }

                if ($preparedServices) {
                    $serviceInsert = $pdo->prepare(
                        "INSERT INTO order_services
                            (order_id, service_id, quantity, price, discount, subtotal)
                         VALUES
                            (:order_id, :service_id, :quantity, :price, :discount, :subtotal)"
                    );

                    foreach ($preparedServices as $item) {
                        $serviceInsert->execute([
                            ':order_id' => $orderId,
                            ':service_id' => $item['service_id'],
                            ':quantity' => $item['quantity'],
                            ':price' => $item['price'],
                            ':discount' => $item['discount'],
                            ':subtotal' => $item['subtotal'],
                        ]);
                    }
                }

                // Stok penjualan hanya berpengaruh saat status pesanan = completed.
                $newOrderStockMap = [];
                if ($status === 'completed') {
                    $newOrderStockMap = [];
                    foreach ($preparedProducts as $item) {
                        $pid = (int)$item['product_id'];
                        $newOrderStockMap[$pid] = ($newOrderStockMap[$pid] ?? 0) + (float)$item['quantity'];
                    }
                }

                $deltas = order_stock_deltas(
                    $oldOrderStockMap,
                    $newOrderStockMap,
                    $oldOrderBranchId,
                    $cabangId
                );

                // Kurangi stok terlebih dahulu, baru kembalikan/tambah stok.
                foreach ($deltas as $key => $delta) {
                    if ($delta >= -0.000001) continue;
                    [$branchId, $productId] = array_map('intval', explode(':', $key));
                    apply_order_stock_delta($pdo, $branchId, $productId, $delta, $orderId);
                }
                foreach ($deltas as $key => $delta) {
                    if ($delta <= 0.000001) continue;
                    [$branchId, $productId] = array_map('intval', explode(':', $key));
                    apply_order_stock_delta($pdo, $branchId, $productId, $delta, $orderId);
                }

                $pdo->commit();
                redirect_orders(['success' => $isUpdate ? 'updated' : 'added']);
            }

            $pdo->rollBack();
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

/* ============================================================
   FILTER + PAGINATION
   ============================================================ */
$search = trim($_GET['search'] ?? '');
$branchFilter = trim($_GET['branch'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        o.order_number LIKE :search_order
        OR c.name LIKE :search_customer
        OR v.plate_number LIKE :search_plate
        OR v.brand LIKE :search_brand
        OR v.model LIKE :search_model
    )";

    $searchValue = '%' . $search . '%';
    $params[':search_order'] = $searchValue;
    $params[':search_customer'] = $searchValue;
    $params[':search_plate'] = $searchValue;
    $params[':search_brand'] = $searchValue;
    $params[':search_model'] = $searchValue;
}

if ($branchFilter !== '') {
    $where[] = 'o.cabang_id = :branch_filter';
    $params[':branch_filter'] = (int)$branchFilter;
}

if ($statusFilter !== '') {
    if (in_array($statusFilter, $orderStatuses, true)) {
        $where[] = 'o.status = :status_filter';
        $params[':status_filter'] = $statusFilter;
    } else {
        $statusFilter = '';
    }
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*)
             FROM orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             LEFT JOIN vehicles v ON v.id = o.vehicle_id
             LEFT JOIN cabangs cb ON cb.id = o.cabang_id
             $whereSql";

$countStmt = $pdo->prepare($countSql);
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

$listSql = "SELECT
                o.id,
                o.order_number,
                o.cabang_id,
                o.customer_id,
                o.vehicle_id,
                o.order_date,
                o.subtotal,
                o.discount,
                o.tax,
                o.grand_total,
                o.status,
                o.created_by,
                c.name AS customer_name,
                c.customer_type,
                cb.code AS cabang_code,
                cb.name AS cabang_name,
                v.plate_number,
                v.brand AS vehicle_brand,
                v.model AS vehicle_model,
                (
                    SELECT COUNT(*)
                    FROM order_details od
                    WHERE od.order_id = o.id
                ) AS product_count,
                (
                    SELECT COUNT(*)
                    FROM order_services os
                    WHERE os.order_id = o.id
                ) AS service_count
            FROM orders o
            LEFT JOIN customers c ON c.id = o.customer_id
            LEFT JOIN vehicles v ON v.id = o.vehicle_id
            LEFT JOIN cabangs cb ON cb.id = o.cabang_id
            $whereSql
            ORDER BY o.id DESC
            LIMIT :limit OFFSET :offset";

$listStmt = $pdo->prepare($listSql);
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
$orders = $listStmt->fetchAll();

/* ============================================================
   STATISTICS
   ============================================================ */
$totalOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$processingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'processing'")->fetchColumn();
$completedOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'completed'")->fetchColumn();

$monthStmt = $pdo->query(
    "SELECT COALESCE(SUM(grand_total), 0)
     FROM orders
     WHERE YEAR(order_date) = YEAR(CURDATE())
       AND MONTH(order_date) = MONTH(CURDATE())
       AND status NOT IN ('cancelled', 'canceled')"
);
$monthlyRevenue = (float)$monthStmt->fetchColumn();

$branches = $pdo->query(
    "SELECT id, code, name, status
     FROM cabangs
     ORDER BY name ASC"
)->fetchAll();

/* ============================================================
   SERVER-SIDE MODAL DATA
   ============================================================ */
$detailOrder = null;
$detailLines = ['products' => [], 'services' => []];
$editOrder = null;
$editLines = ['products' => [], 'services' => []];

if (isset($_GET['detail']) && ctype_digit((string)$_GET['detail'])) {
    $detailId = (int)$_GET['detail'];
    $detailOrder = get_order_header($pdo, $detailId);
    if ($detailOrder) {
        $detailLines = order_lines($pdo, $detailId);
    }
}

if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editOrder = get_order_header($pdo, $editId);
    if ($editOrder) {
        $editLines = order_lines($pdo, $editId);
    }
}

$editVehicleIds = $editOrder ? [(int)$editOrder['vehicle_id']] : [];
$editProductIds = selected_ids($editLines['products'], 'product_id');
$editServiceIds = selected_ids($editLines['services'], 'service_id');

$branchOptions = fetch_branch_options($pdo, $editOrder ? (int)$editOrder['cabang_id'] : null);
$customerOptions = fetch_customer_options($pdo, $editOrder ? (int)$editOrder['customer_id'] : null);
$vehicleOptions = fetch_vehicle_options($pdo, $editVehicleIds);
$productOptions = fetch_product_options($pdo, $editProductIds);
$serviceOptions = fetch_service_options($pdo, $editServiceIds);

function build_page_url(
    int $pageNo,
    string $search,
    string $branch,
    string $status,
    array $extra = []
): string {
    $params = ['page' => $pageNo];
    if ($search !== '') $params['search'] = $search;
    if ($branch !== '') $params['branch'] = $branch;
    if ($status !== '') $params['status'] = $status;

    foreach ($extra as $key => $value) {
        $params[$key] = $value;
    }

    return 'index.php?' . http_build_query($params);
}

$hasFilters = ($search !== '' || $branchFilter !== '' || $statusFilter !== '');
$from = $totalFiltered > 0 ? $offset + 1 : 0;
$to = min($offset + $perPage, $totalFiltered);

$successMessages = [
    'added' => 'Pesanan berhasil dibuat.',
    'updated' => 'Pesanan berhasil diperbarui.',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c1a2d">
    <title>Penjualan | <?= h($companyName) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../../assets/css/admin.css?v=20260907">
    <link rel="stylesheet" href="style.css?v=20260912">
    <style>
        .orders-action-btn.print {
            color: #0f766e;
            border-color: rgba(15, 118, 110, 0.18);
            background: rgba(15, 118, 110, 0.06);
        }
        .orders-action-btn.print:hover {
            color: #ffffff;
            background: #0f766e;
            border-color: #0f766e;
        }
    </style>
</head>
<body class="orders-page">

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
            <a href="../admin.php" class="menu-item">
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>
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
            <a href="./" class="menu-item active"><i class="bi bi-cart3"></i><span>Penjualan</span></a>
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
    <div class="orders-container">

        <div class="orders-header">
            <div>
                <div class="orders-breadcrumb">
                    Dashboard
                    <i class="bi bi-chevron-right"></i>
                    Transaksi
                    <i class="bi bi-chevron-right"></i>
                    Penjualan
                </div>
                <h1>Penjualan</h1>
                <p>Kelola transaksi penjualan produk dan jasa pelanggan.</p>
            </div>

            <button class="orders-btn orders-btn-primary" type="button" onclick="openAddOrderModal()">
                <i class="bi bi-plus-lg"></i>
                Buat Penjualan
            </button>
        </div>

        <?php if ($notify !== ''): ?>
            <div class="orders-alert <?= $notifyType === 'error' ? 'is-error' : 'is-success' ?>">
                <i class="bi <?= $notifyType === 'error' ? 'bi-exclamation-circle' : 'bi-check-circle' ?>"></i>
                <span><?= h($notify) ?></span>
            </div>
        <?php elseif (isset($_GET['success']) && isset($successMessages[$_GET['success']])): ?>
            <div class="orders-alert is-success">
                <i class="bi bi-check-circle"></i>
                <span><?= h($successMessages[$_GET['success']]) ?></span>
            </div>
        <?php endif; ?>

        <section class="orders-stats">
            <div class="orders-stat-card">
                <div class="orders-stat-icon blue"><i class="bi bi-receipt"></i></div>
                <div>
                    <span>Total Penjualan</span>
                    <strong><?= number_format($totalOrders, 0, ',', '.') ?></strong>
                    <small>Seluruh transaksi</small>
                </div>
            </div>

            <div class="orders-stat-card">
                <div class="orders-stat-icon orange"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <span>Dalam Proses</span>
                    <strong><?= number_format($processingOrders, 0, ',', '.') ?></strong>
                    <small>Status Diproses</small>
                </div>
            </div>

            <div class="orders-stat-card">
                <div class="orders-stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
                <div>
                    <span>Selesai</span>
                    <strong><?= number_format($completedOrders, 0, ',', '.') ?></strong>
                    <small>Status Selesai</small>
                </div>
            </div>

            <div class="orders-stat-card">
                <div class="orders-stat-icon purple"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <span>Omzet Bulan Ini</span>
                    <strong><?= h(money($monthlyRevenue)) ?></strong>
                    <small>Tidak termasuk dibatalkan</small>
                </div>
            </div>
        </section>

        <section class="orders-card">
            <div class="orders-card-header">
                <div>
                    <h2>Daftar Penjualan</h2>
                    <p>Seluruh transaksi penjualan produk dan jasa pelanggan.</p>
                </div>

                <form method="get" class="orders-filter">
                    <div class="orders-search">
                        <i class="bi bi-search"></i>
                        <input
                            type="text"
                            name="search"
                            value="<?= h($search) ?>"
                            placeholder="Cari nomor, pelanggan, atau kendaraan..."
                        >
                    </div>

                    <select name="branch">
                        <option value="">Semua Cabang</option>
                        <?php foreach ($branches as $branch): ?>
                            <option
                                value="<?= (int)$branch['id'] ?>"
                                <?= $branchFilter === (string)$branch['id'] ? 'selected' : '' ?>
                            >
                                <?= h($branch['code'] . ' - ' . $branch['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="status">
                        <option value="">Semua Status</option>
                        <?php foreach ($orderStatuses as $statusOption): ?>
                            <option value="<?= h($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>>
                                <?= h(order_status_label($statusOption)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="orders-filter-btn">
                        <i class="bi bi-search"></i>
                        Cari
                    </button>

                    <a
                        href="<?= $hasFilters ? 'index.php' : '#' ?>"
                        class="orders-reset <?= $hasFilters ? '' : 'is-disabled' ?>"
                        <?= $hasFilters ? '' : 'aria-disabled="true" tabindex="-1"' ?>
                    >
                        <i class="bi bi-arrow-counterclockwise"></i>
                        Reset
                    </a>
                </form>
            </div>

            <div class="orders-table-wrap">
                <table class="orders-table">
                    <thead>
                    <tr>
                        <th>No. Penjualan</th>
                        <th>Pelanggan</th>
                        <th>Kendaraan</th>
                        <th>Item</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Cabang</th>
                        <th>Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$orders): ?>
                        <tr>
                            <td colspan="8">
                                <div class="orders-empty">
                                    <i class="bi bi-receipt-cutoff"></i>
                                    <strong>Data penjualan tidak ditemukan</strong>
                                    <span>Coba ubah kata pencarian atau filter Anda.</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td>
                                    <span class="orders-code"><?= h($order['order_number']) ?></span>
                                    <small><?= h(date('d M Y', strtotime($order['order_date']))) ?></small>
                                </td>
                                <td>
                                    <strong><?= h($order['customer_name'] ?: '-') ?></strong>
                                    <small><?= h($order['customer_type'] ?: '-') ?></small>
                                </td>
                                <td>
                                    <div class="orders-vehicle">
                                        <div class="orders-vehicle-icon">
                                            <i class="bi bi-car-front-fill"></i>
                                        </div>
                                        <div>
                                            <strong><?= h(trim(($order['vehicle_brand'] ?? '') . ' ' . ($order['vehicle_model'] ?? ''))) ?></strong>
                                            <small><?= h($order['plate_number'] ?: '-') ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= (int)$order['service_count'] ?> Jasa</strong>
                                    <small>+ <?= (int)$order['product_count'] ?> Produk</small>
                                </td>
                                <td>
                                    <strong class="orders-price"><?= h(money((float)$order['grand_total'])) ?></strong>
                                </td>
                                <td>
                                    <?php
                                    $statusClass = order_status_class((string)$order['status']);
                                    ?>
                                    <span class="orders-status <?= $statusClass ?>">
                                        <?= h(order_status_label((string)$order['status'])) ?>
                                    </span>
                                </td>
                                <td><?= h(($order['cabang_code'] ? $order['cabang_code'] . ' - ' : '') . ($order['cabang_name'] ?: '-')) ?></td>
                                <td>
                                    <div class="orders-actions">
                                        <a
                                            class="orders-action-btn view"
                                            title="Detail"
                                            href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter, ['detail' => (int)$order['id']])) ?>"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a
                                            class="orders-action-btn edit"
                                            title="Edit"
                                            href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter, ['edit' => (int)$order['id']])) ?>"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a
                                            class="orders-action-btn print"
                                            title="Cetak"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            href="print.php?id=<?= (int)$order['id'] ?>"
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

            <div class="orders-table-footer">
                <span>
                    Menampilkan
                    <strong><?= $from ?></strong>–<strong><?= $to ?></strong>
                    dari <strong><?= $totalFiltered ?></strong> transaksi
                </span>

                <div class="orders-pagination">
                        <a
                            href="<?= $page > 1 ? h(build_page_url($page - 1, $search, $branchFilter, $statusFilter)) : '#' ?>"
                            class="<?= $page <= 1 ? 'is-disabled' : '' ?>"
                        >
                            <i class="bi bi-chevron-left"></i>
                        </a>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        ?>

                        <?php if ($startPage > 1): ?>
                            <a href="<?= h(build_page_url(1, $search, $branchFilter, $statusFilter)) ?>">1</a>
                            <?php if ($startPage > 2): ?><span>...</span><?php endif; ?>
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
                            <?php if ($endPage < $totalPages - 1): ?><span>...</span><?php endif; ?>
                            <a href="<?= h(build_page_url($totalPages, $search, $branchFilter, $statusFilter)) ?>"><?= $totalPages ?></a>
                        <?php endif; ?>

                        <a
                            href="<?= $page < $totalPages ? h(build_page_url($page + 1, $search, $branchFilter, $statusFilter)) : '#' ?>"
                            class="<?= $page >= $totalPages ? 'is-disabled' : '' ?>"
                        >
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
            </div>
        </section>
    </div>
</main>

<!-- =========================================================
     ADD ORDER MODAL
========================================================== -->
<div class="orders-modal <?= $editOrder ? 'show' : '' ?>" id="orderEntryModal">
    <div class="orders-modal-panel orders-modal-panel-wide">
        <div class="orders-modal-header">
            <div>
                <h2><?= $editOrder ? 'Edit Penjualan' : 'Buat Penjualan Baru' ?></h2>
                <p><?= $editOrder ? 'Perbarui transaksi penjualan.' : 'Masukkan informasi transaksi dan item penjualan.' ?></p>
            </div>
            <?php if ($editOrder): ?>
                <a
                    href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter)) ?>"
                    class="orders-modal-close"
                    title="Tutup"
                >
                    <i class="bi bi-x-lg"></i>
                </a>
            <?php else: ?>
                <button type="button" class="orders-modal-close" onclick="closeAddOrderModal()" title="Tutup">
                    <i class="bi bi-x-lg"></i>
                </button>
            <?php endif; ?>
        </div>

        <form method="post" id="orderForm">
            <input type="hidden" name="action" value="<?= $editOrder ? 'update_order' : 'add_order' ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <?php if ($editOrder): ?>
                <input type="hidden" name="order_id" value="<?= (int)$editOrder['id'] ?>">
            <?php endif; ?>

            <div class="orders-form-body">
                <section class="orders-form-section">
                    <div class="orders-section-title">
                        <i class="bi bi-receipt"></i>
                        Informasi Penjualan
                    </div>

                    <div class="orders-form-grid">
                        <div class="orders-form-group">
                            <label>Nomor Penjualan</label>
                            <input
                                type="text"
                                value="<?= h($editOrder['order_number'] ?? generate_order_number($pdo)) ?>"
                                readonly
                            >
                        </div>

                        <div class="orders-form-group">
                            <label>Tanggal Penjualan <span>*</span></label>
                            <input
                                type="date"
                                name="order_date"
                                value="<?= h($editOrder['order_date'] ?? date('Y-m-d')) ?>"
                                required
                            >
                        </div>

                        <div class="orders-form-group">
                            <label>Cabang <span>*</span></label>
                            <select name="cabang_id" required>
                                <option value="">Pilih cabang</option>
                                <?php foreach ($branchOptions as $branch): ?>
                                    <option
                                        value="<?= (int)$branch['id'] ?>"
                                        <?= $editOrder && (int)$editOrder['cabang_id'] === (int)$branch['id'] ? 'selected' : '' ?>
                                    >
                                        <?= h($branch['code'] . ' - ' . $branch['name']) ?>
                                        <?= $branch['status'] !== 'active' ? ' (Nonaktif)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="orders-form-group">
                            <label>Pelanggan <span>*</span></label>
                            <select name="customer_id" id="orderCustomerId" required onchange="syncVehicleOptions()">
                                <option value="">Pilih pelanggan</option>
                                <?php foreach ($customerOptions as $customer): ?>
                                    <option
                                        value="<?= (int)$customer['id'] ?>"
                                        <?= $editOrder && (int)$editOrder['customer_id'] === (int)$customer['id'] ? 'selected' : '' ?>
                                    >
                                        <?= h($customer['name'] . ($customer['customer_type'] ? ' - ' . $customer['customer_type'] : '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="orders-form-group orders-form-full">
                            <label>Kendaraan <span>*</span></label>
                            <select name="vehicle_id" id="orderVehicleId" required>
                                <option value="">Pilih kendaraan</option>
                                <?php foreach ($vehicleOptions as $vehicle): ?>
                                    <option
                                        value="<?= (int)$vehicle['id'] ?>"
                                        data-customer-id="<?= (int)$vehicle['customer_id'] ?>"
                                        <?= $editOrder && (int)$editOrder['vehicle_id'] === (int)$vehicle['id'] ? 'selected' : '' ?>
                                    >
                                        <?= h(trim($vehicle['brand'] . ' ' . $vehicle['model']) . ' - ' . $vehicle['plate_number']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="orders-help">Kendaraan harus milik pelanggan yang dipilih.</small>
                        </div>
                    </div>
                </section>

                <section class="orders-form-section">
                    <div class="orders-section-title">
                        <i class="bi bi-box-seam"></i>
                        Produk
                    </div>

                    <div class="orders-line-list" id="productList">
                        <?php if ($editOrder && $editLines['products']): ?>
                            <?php foreach ($editLines['products'] as $line): ?>
                                <div class="orders-line-row product-line">
                                    <select name="product_id[]" class="line-product-select" onchange="updateLine(this)">
                                        <option value="">Pilih produk</option>
                                        <?php foreach ($productOptions as $product): ?>
                                            <option
                                                value="<?= (int)$product['id'] ?>"
                                                data-price="<?= h((float)$product['selling_price']) ?>"
                                                <?= (int)$line['product_id'] === (int)$product['id'] ? 'selected' : '' ?>
                                            >
                                                <?= h($product['code'] . ' - ' . $product['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="product_description[]" value="<?= h($line['description']) ?>" placeholder="Deskripsi">
                                    <input type="number" name="product_quantity[]" min="1" value="<?= (int)$line['quantity'] ?>" oninput="recalculateSummary()">
                                    <input class="line-price-display" type="text" value="<?= h((float)$line['price']) ?>" readonly>
                                    <input type="number" name="product_discount[]" min="0" step="0.01" value="<?= h((float)$line['discount']) ?>" oninput="recalculateSummary()">
                                    <button type="button" class="orders-line-remove" onclick="removeLine(this)" title="Hapus item"><i class="bi bi-x"></i></button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="orders-line-row product-line">
                                <select name="product_id[]" class="line-product-select" onchange="updateLine(this)">
                                    <option value="">Pilih produk</option>
                                    <?php foreach ($productOptions as $product): ?>
                                        <option
                                            value="<?= (int)$product['id'] ?>"
                                            data-price="<?= h((float)$product['selling_price']) ?>"
                                        >
                                            <?= h($product['code'] . ' - ' . $product['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="product_description[]" placeholder="Deskripsi">
                                <input type="number" name="product_quantity[]" min="1" value="1" oninput="recalculateSummary()">
                                <input class="line-price-display" type="text" value="0" readonly>
                                <input type="number" name="product_discount[]" min="0" step="0.01" value="0" oninput="recalculateSummary()">
                                <button type="button" class="orders-line-remove" onclick="removeLine(this)" title="Hapus item"><i class="bi bi-x"></i></button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="button" class="orders-add-line" onclick="addProductLine()">
                        <i class="bi bi-plus-lg"></i>
                        Tambah Produk
                    </button>
                </section>

                <section class="orders-form-section">
                    <div class="orders-section-title">
                        <i class="bi bi-tools"></i>
                        Jasa
                    </div>

                    <div class="orders-line-list" id="serviceList">
                        <?php if ($editOrder && $editLines['services']): ?>
                            <?php foreach ($editLines['services'] as $line): ?>
                                <div class="orders-line-row service-line">
                                    <select name="service_id[]" class="line-service-select" onchange="updateLine(this)">
                                        <option value="">Pilih jasa</option>
                                        <?php foreach ($serviceOptions as $service): ?>
                                            <option
                                                value="<?= (int)$service['id'] ?>"
                                                data-price="<?= h((float)$service['price']) ?>"
                                                <?= (int)$line['service_id'] === (int)$service['id'] ? 'selected' : '' ?>
                                            >
                                                <?= h($service['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" name="service_quantity[]" min="1" value="<?= (int)$line['quantity'] ?>" oninput="recalculateSummary()">
                                    <input class="line-price-display" type="text" value="<?= h((float)$line['price']) ?>" readonly>
                                    <input type="number" name="service_discount[]" min="0" step="0.01" value="<?= h((float)$line['discount']) ?>" oninput="recalculateSummary()">
                                    <button type="button" class="orders-line-remove" onclick="removeLine(this)" title="Hapus item"><i class="bi bi-x"></i></button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="orders-line-row service-line">
                                <select name="service_id[]" class="line-service-select" onchange="updateLine(this)">
                                    <option value="">Pilih jasa</option>
                                    <?php foreach ($serviceOptions as $service): ?>
                                        <option
                                            value="<?= (int)$service['id'] ?>"
                                            data-price="<?= h((float)$service['price']) ?>"
                                        >
                                            <?= h($service['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="service_quantity[]" min="1" value="1" oninput="recalculateSummary()">
                                <input class="line-price-display" type="text" value="0" readonly>
                                <input type="number" name="service_discount[]" min="0" step="0.01" value="0" oninput="recalculateSummary()">
                                <button type="button" class="orders-line-remove" onclick="removeLine(this)" title="Hapus item"><i class="bi bi-x"></i></button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="button" class="orders-add-line" onclick="addServiceLine()">
                        <i class="bi bi-plus-lg"></i>
                        Tambah Jasa
                    </button>
                </section>

                <section class="orders-form-section">
                    <div class="orders-summary-grid">
                        <div class="orders-form-group">
                            <label>Diskon Pesanan (Rp)</label>
                            <input type="number" name="discount" id="orderDiscount" min="0" step="0.01" value="<?= h((float)($editOrder['discount'] ?? 0)) ?>" oninput="recalculateSummary()">
                        </div>

                        <div class="orders-form-group">
                            <label>Pajak (Rp)</label>
                            <input type="number" name="tax" id="orderTax" min="0" step="0.01" value="<?= h((float)($editOrder['tax'] ?? 0)) ?>" oninput="recalculateSummary()">
                        </div>

                        <div class="orders-form-group">
                            <label>Status</label>
                            <select name="status">
                                <?php foreach ($orderStatuses as $statusOption): ?>
                                    <option value="<?= h($statusOption) ?>" <?= ($editOrder['status'] ?? $defaultOrderStatus) === $statusOption ? 'selected' : '' ?>>
                                        <?= h(order_status_label($statusOption)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="orders-summary-box">
                        <div><span>Subtotal Produk + Jasa</span><strong id="summarySubtotal">Rp0</strong></div>
                        <div><span>Diskon Pesanan</span><strong id="summaryDiscount">Rp0</strong></div>
                        <div><span>Pajak</span><strong id="summaryTax">Rp0</strong></div>
                        <div class="grand-total"><span>Grand Total</span><strong id="summaryGrandTotal">Rp0</strong></div>
                    </div>
                </section>
            </div>

            <div class="orders-modal-footer">
                <?php if ($editOrder): ?>
                    <a
                        href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter)) ?>"
                        class="orders-btn orders-btn-secondary"
                    >Batal</a>
                <?php else: ?>
                    <button type="button" class="orders-btn orders-btn-secondary" onclick="closeAddOrderModal()">Batal</button>
                <?php endif; ?>
                <button type="submit" class="orders-btn orders-btn-primary">
                    <i class="bi bi-check-lg"></i>
                    <?= $editOrder ? 'Simpan Perubahan' : 'Simpan Penjualan' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($detailOrder): ?>
    <div class="orders-server-modal">
        <div class="orders-modal-panel orders-modal-panel-wide">
            <div class="orders-modal-header">
                <div>
                    <h2>Detail Penjualan</h2>
                    <p>Informasi lengkap transaksi penjualan.</p>
                </div>
                <a
                    href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter)) ?>"
                    class="orders-modal-close"
                >
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>

            <div class="orders-modal-body">
                <div class="orders-detail-hero">
                    <div>
                        <span class="orders-detail-code"><?= h($detailOrder['order_number']) ?></span>
                        <h3><?= h($detailOrder['customer_name'] ?: '-') ?></h3>
                        <p>
                            <?= h($detailOrder['cabang_code'] ? $detailOrder['cabang_code'] . ' - ' . $detailOrder['cabang_name'] : ($detailOrder['cabang_name'] ?: '-')) ?>
                        </p>
                    </div>
                    <span class="orders-status <?= h(order_status_class((string)$detailOrder['status'])) ?>">
                        <?= h(order_status_label((string)$detailOrder['status'])) ?>
                    </span>
                </div>

                <div class="orders-detail-grid">
                    <div><small>Tanggal</small><strong><?= h(date('d M Y', strtotime($detailOrder['order_date']))) ?></strong></div>
                    <div><small>Kendaraan</small><strong><?= h(trim($detailOrder['vehicle_brand'] . ' ' . $detailOrder['vehicle_model'])) ?> - <?= h($detailOrder['plate_number']) ?></strong></div>
                    <div><small>Telepon Pelanggan</small><strong><?= h($detailOrder['customer_phone'] ?: '-') ?></strong></div>
                    <div><small>Dibuat Oleh</small><strong><?= h($detailOrder['creator_name'] ?: '-') ?></strong></div>
                </div>

                <div class="orders-detail-section">
                    <div class="orders-section-title"><i class="bi bi-box-seam"></i> Produk</div>
                    <?php if (!$detailLines['products']): ?>
                        <div class="orders-detail-empty">Tidak ada produk.</div>
                    <?php else: ?>
                        <div class="orders-detail-table">
                            <?php foreach ($detailLines['products'] as $line): ?>
                                <div class="orders-detail-row">
                                    <div>
                                        <strong><?= h($line['product_code'] . ' - ' . $line['product_name']) ?></strong>
                                        <small><?= h($line['description']) ?></small>
                                    </div>
                                    <span><?= (int)$line['quantity'] ?> × <?= h(money((float)$line['price'])) ?></span>
                                    <strong><?= h(money((float)$line['subtotal'])) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="orders-detail-section">
                    <div class="orders-section-title"><i class="bi bi-tools"></i> Jasa</div>
                    <?php if (!$detailLines['services']): ?>
                        <div class="orders-detail-empty">Tidak ada jasa.</div>
                    <?php else: ?>
                        <div class="orders-detail-table">
                            <?php foreach ($detailLines['services'] as $line): ?>
                                <div class="orders-detail-row">
                                    <div>
                                        <strong><?= h($line['service_name']) ?></strong>
                                        <small><?= h($line['service_type'] ?: '') ?></small>
                                    </div>
                                    <span><?= (int)$line['quantity'] ?> × <?= h(money((float)$line['price'])) ?></span>
                                    <strong><?= h(money((float)$line['subtotal'])) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="orders-detail-total">
                    <div><span>Subtotal</span><strong><?= h(money((float)$detailOrder['subtotal'])) ?></strong></div>
                    <div><span>Diskon</span><strong><?= h(money((float)$detailOrder['discount'])) ?></strong></div>
                    <div><span>Pajak</span><strong><?= h(money((float)$detailOrder['tax'])) ?></strong></div>
                    <div class="grand-total"><span>Grand Total</span><strong><?= h(money((float)$detailOrder['grand_total'])) ?></strong></div>
                </div>
            </div>

            <div class="orders-modal-footer">
                <a
                    class="orders-btn orders-btn-secondary"
                    href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter)) ?>"
                >
                    Tutup
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
const orderEntryModal = document.getElementById('orderEntryModal');
const orderForm = document.getElementById('orderForm');

function openAddOrderModal() {
    if (!orderEntryModal || !orderForm) return;

    orderForm.reset();

    const action = orderForm.querySelector('[name="action"]');
    if (action) action.value = 'add_order';

    const orderId = orderForm.querySelector('[name="order_id"]');
    if (orderId) orderId.remove();

    const dateInput = orderForm.querySelector('[name="order_date"]');
    if (dateInput) {
        const now = new Date();
        const localDate = new Date(now.getTime() - (now.getTimezoneOffset() * 60000))
            .toISOString()
            .slice(0, 10);
        dateInput.value = localDate;
    }

    resetDynamicLines();
    syncVehicleOptions();
    recalculateSummary();
    orderEntryModal.classList.add('show');
}

function closeAddOrderModal() {
    if (!orderEntryModal || !orderForm) return;
    orderForm.reset();
    resetDynamicLines();
    syncVehicleOptions();
    recalculateSummary();
    orderEntryModal.classList.remove('show');
}

function resetDynamicLines() {
    const productList = document.getElementById('productList');
    const serviceList = document.getElementById('serviceList');

    if (productList) {
        productList.innerHTML = `
            <div class="orders-line-row product-line">
                <select name="product_id[]" class="line-product-select" onchange="updateLine(this)">
                    <option value="">Pilih produk</option>
                    <?php foreach ($productOptions as $product): ?>
                        <option value="<?= (int)$product['id'] ?>" data-price="<?= h((float)$product['selling_price']) ?>">
                            <?= h($product['code'] . ' - ' . $product['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="product_description[]" placeholder="Deskripsi">
                <input type="number" name="product_quantity[]" min="1" value="1" oninput="recalculateSummary()">
                <input class="line-price-display" type="text" value="0" readonly>
                <input type="number" name="product_discount[]" min="0" step="0.01" value="0" oninput="recalculateSummary()">
                <button type="button" class="orders-line-remove" onclick="removeLine(this)" title="Hapus item"><i class="bi bi-x"></i></button>
            </div>
        `;
    }

    if (serviceList) {
        serviceList.innerHTML = `
            <div class="orders-line-row service-line">
                <select name="service_id[]" class="line-service-select" onchange="updateLine(this)">
                    <option value="">Pilih jasa</option>
                    <?php foreach ($serviceOptions as $service): ?>
                        <option value="<?= (int)$service['id'] ?>" data-price="<?= h((float)$service['price']) ?>">
                            <?= h($service['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="service_quantity[]" min="1" value="1" oninput="recalculateSummary()">
                <input class="line-price-display" type="text" value="0" readonly>
                <input type="number" name="service_discount[]" min="0" step="0.01" value="0" oninput="recalculateSummary()">
                <button type="button" class="orders-line-remove" onclick="removeLine(this)" title="Hapus item"><i class="bi bi-x"></i></button>
            </div>
        `;
    }
}

function addProductLine() {
    const list = document.getElementById('productList');
    const first = list.querySelector('.product-line');
    if (!first) return;

    const clone = first.cloneNode(true);
    clone.querySelectorAll('input').forEach((input) => {
        if (input.name.includes('quantity')) input.value = 1;
        else if (input.name.includes('discount')) input.value = 0;
        else if (input.classList.contains('line-price-display')) input.value = 0;
        else input.value = '';
    });
    clone.querySelector('select').selectedIndex = 0;
    list.appendChild(clone);
    recalculateSummary();
}

function addServiceLine() {
    const list = document.getElementById('serviceList');
    const first = list.querySelector('.service-line');
    if (!first) return;

    const clone = first.cloneNode(true);
    clone.querySelectorAll('input').forEach((input) => {
        if (input.name.includes('quantity')) input.value = 1;
        else if (input.name.includes('discount')) input.value = 0;
        else if (input.classList.contains('line-price-display')) input.value = 0;
        else input.value = '';
    });
    clone.querySelector('select').selectedIndex = 0;
    list.appendChild(clone);
    recalculateSummary();
}

function removeLine(button) {
    const row = button.closest('.orders-line-row');
    if (!row) return;

    const list = row.parentElement;
    if (list.querySelectorAll('.orders-line-row').length === 1) {
        row.querySelectorAll('input').forEach((input) => {
            if (input.name.includes('quantity')) input.value = 1;
            else if (input.name.includes('discount')) input.value = 0;
            else if (input.classList.contains('line-price-display')) input.value = 0;
            else input.value = '';
        });
        const select = row.querySelector('select');
        if (select) select.selectedIndex = 0;
    } else {
        row.remove();
    }

    recalculateSummary();
}

function updateLine(select) {
    const row = select.closest('.orders-line-row');
    const priceField = row?.querySelector('.line-price-display');
    if (!row || !priceField) return;

    const selected = select.options[select.selectedIndex];
    priceField.value = selected?.dataset?.price || 0;
    recalculateSummary();
}

function syncVehicleOptions() {
    const customerSelect = document.getElementById('orderCustomerId');
    const vehicleSelect = document.getElementById('orderVehicleId');

    if (!customerSelect || !vehicleSelect) return;

    const customerId = customerSelect.value;

    Array.from(vehicleSelect.options).forEach((option) => {
        if (!option.value) {
            option.hidden = false;
            return;
        }

        const matches = option.dataset.customerId === customerId;
        option.hidden = !matches;

        if (!matches && option.selected) {
            vehicleSelect.value = '';
        }
    });
}

function recalculateSummary() {
    let subtotal = 0;

    document.querySelectorAll('#productList .product-line').forEach((row) => {
        const select = row.querySelector('select');
        const quantity = parseFloat(row.querySelector('[name="product_quantity[]"]')?.value || 0);
        const discount = parseFloat(row.querySelector('[name="product_discount[]"]')?.value || 0);
        const price = parseFloat(select?.options[select.selectedIndex]?.dataset?.price || 0);
        subtotal += Math.max(0, (quantity * price) - discount);
    });

    document.querySelectorAll('#serviceList .service-line').forEach((row) => {
        const select = row.querySelector('select');
        const quantity = parseFloat(row.querySelector('[name="service_quantity[]"]')?.value || 0);
        const discount = parseFloat(row.querySelector('[name="service_discount[]"]')?.value || 0);
        const price = parseFloat(select?.options[select.selectedIndex]?.dataset?.price || 0);
        subtotal += Math.max(0, (quantity * price) - discount);
    });

    const discount = Math.max(0, parseFloat(document.getElementById('orderDiscount')?.value || 0));
    const tax = Math.max(0, parseFloat(document.getElementById('orderTax')?.value || 0));
    const grandTotal = Math.max(0, subtotal - Math.min(discount, subtotal) + tax);

    const format = (value) => 'Rp' + Math.round(value).toLocaleString('id-ID');

    const subtotalEl = document.getElementById('summarySubtotal');
    const discountEl = document.getElementById('summaryDiscount');
    const taxEl = document.getElementById('summaryTax');
    const grandEl = document.getElementById('summaryGrandTotal');

    if (subtotalEl) subtotalEl.textContent = format(subtotal);
    if (discountEl) discountEl.textContent = format(discount);
    if (taxEl) taxEl.textContent = format(tax);
    if (grandEl) grandEl.textContent = format(grandTotal);
}

document.addEventListener('DOMContentLoaded', () => {
    syncVehicleOptions();
    recalculateSummary();
});

if (orderEntryModal && orderForm && !document.querySelector('[name="action"][value="update_order"]')) {
    orderEntryModal.addEventListener('click', (event) => {
        if (event.target === orderEntryModal) {
            closeAddOrderModal();
        }
    });
}

if (orderForm) {
    orderForm.addEventListener('submit', (event) => {
        const customer = document.getElementById('orderCustomerId')?.value || '';
        const vehicle = document.getElementById('orderVehicleId')?.value || '';

        const productIds = Array.from(document.querySelectorAll('#productList select[name="product_id[]"]'))
            .map((select) => select.value)
            .filter(Boolean);

        const serviceIds = Array.from(document.querySelectorAll('#serviceList select[name="service_id[]"]'))
            .map((select) => select.value)
            .filter(Boolean);

        if (!customer || !vehicle) {
            event.preventDefault();
            alert('Pelanggan dan kendaraan wajib dipilih.');
            return;
        }

        if (productIds.length === 0 && serviceIds.length === 0) {
            event.preventDefault();
            alert('Tambahkan minimal satu produk atau jasa.');
        }
    });
}
</script>



</body>
</html>
