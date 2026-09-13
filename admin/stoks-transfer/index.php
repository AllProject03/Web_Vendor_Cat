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
            if ($key === 'company_name' && $value !== '') $brand['name'] = $value;
            if ($key === 'company_tagline' && $value !== '') $brand['tagline'] = $value;
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

$pageTitle = 'Transfer Stok Antar Cabang';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money_int($value): string {
    return number_format((float)$value, 0, ',', '.');
}

function current_user_id(): int {
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


function redirect_transfer(array $params = []): never {
    $url = 'index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

function enum_values(PDO $pdo, string $table, string $column): array {
    $allowed = ['stock_transfers' => ['status']];
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
    $stmt->execute([':table_name' => $table, ':column_name' => $column]);
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

function status_key(array $statuses, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $statuses, true)) {
            return $candidate;
        }
    }
    return null;
}

function status_label(string $status): string {
    return match ($status) {
        'draft' => 'Draft',
        'pending', 'waiting' => 'Menunggu Persetujuan',
        'approved' => 'Disetujui',
        'in_transit', 'shipped', 'shipping' => 'Dalam Pengiriman',
        'received', 'completed' => 'Diterima',
        'cancelled', 'canceled', 'rejected' => 'Dibatalkan',
        default => ucwords(str_replace(['_', '-'], ' ', $status)),
    };
}

function status_class(string $status): string {
    return match ($status) {
        'draft' => 'draft',
        'pending', 'waiting' => 'waiting',
        'approved' => 'approved',
        'in_transit', 'shipped', 'shipping' => 'shipping',
        'received', 'completed' => 'received',
        'cancelled', 'canceled', 'rejected' => 'cancelled',
        default => 'waiting',
    };
}

function get_transfer(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        "SELECT
            st.*,
            f.code AS from_code, f.name AS from_name,
            t.code AS to_code, t.name AS to_name,
            cu.name AS created_name,
            au.name AS approved_name,
            ru.name AS received_name
         FROM stock_transfers st
         LEFT JOIN cabangs f ON f.id = st.from_cabang_id
         LEFT JOIN cabangs t ON t.id = st.to_cabang_id
         LEFT JOIN users cu ON cu.id = st.created_by
         LEFT JOIN users au ON au.id = st.approved_by
         LEFT JOIN users ru ON ru.id = st.received_by
         WHERE st.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function get_transfer_lines(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare(
        "SELECT
            d.*,
            p.code AS product_code,
            p.name AS product_name,
            p.brand,
            p.unit,
            COALESCE(bs.stock, 0) AS current_source_stock
         FROM stock_transfer_details d
         LEFT JOIN products p ON p.id = d.product_id
         LEFT JOIN stock_transfers st ON st.id = d.stock_transfer_id
         LEFT JOIN branch_stocks bs
           ON bs.cabang_id = st.from_cabang_id
          AND bs.product_id = d.product_id
         WHERE d.stock_transfer_id = :id
         ORDER BY d.id ASC"
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetchAll();
}

function next_transfer_number(PDO $pdo): string {
    $year = date('Y');
    $stmt = $pdo->prepare(
        "SELECT transfer_number
         FROM stock_transfers
         WHERE transfer_number LIKE :prefix
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([':prefix' => 'TRF-' . $year . '-%']);
    $last = $stmt->fetchColumn();
    $seq = 1;
    if (is_string($last) && preg_match('/(\d+)$/', $last, $m)) {
        $seq = ((int)$m[1]) + 1;
    }
    return sprintf('TRF-%s-%04d', $year, $seq);
}

function selected_ids(array $rows): array {
    $ids = [];
    foreach ($rows as $row) {
        $id = (int)($row['product_id'] ?? 0);
        if ($id > 0) $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

function get_active_branches(PDO $pdo): array {
    return $pdo->query(
        "SELECT id, code, name
         FROM cabangs
         WHERE status = 'active'
         ORDER BY name ASC"
    )->fetchAll();
}

function get_active_products(PDO $pdo): array {
    return $pdo->query(
        "SELECT id, code, name, brand, unit
         FROM products
         WHERE status = 'active'
         ORDER BY name ASC"
    )->fetchAll();
}

function get_branch_stock(PDO $pdo, int $branchId, int $productId): float {
    $stmt = $pdo->prepare(
        "SELECT stock FROM branch_stocks
         WHERE cabang_id = :cabang_id AND product_id = :product_id
         LIMIT 1"
    );
    $stmt->execute([':cabang_id' => $branchId, ':product_id' => $productId]);
    $stock = $stmt->fetchColumn();
    return $stock === false ? 0.0 : (float)$stock;
}

function branch_stock_for_update(PDO $pdo, int $branchId, int $productId): float {
    $stmt = $pdo->prepare(
        "SELECT stock
         FROM branch_stocks
         WHERE cabang_id = :cabang_id AND product_id = :product_id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':cabang_id' => $branchId, ':product_id' => $productId]);
    $stock = $stmt->fetchColumn();
    return $stock === false ? 0.0 : (float)$stock;
}

function ensure_branch_stock_row(PDO $pdo, int $branchId, int $productId): void {
    $stmt = $pdo->prepare(
        "SELECT id FROM branch_stocks
         WHERE cabang_id = :cabang_id AND product_id = :product_id
         LIMIT 1
         FOR UPDATE"
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

function update_branch_stock(PDO $pdo, int $branchId, int $productId, float $delta, int $transferId, bool $isIncoming): void {
    ensure_branch_stock_row($pdo, $branchId, $productId);

    $stockStmt = $pdo->prepare(
        "SELECT id, stock
         FROM branch_stocks
         WHERE cabang_id = :cabang_id AND product_id = :product_id
         LIMIT 1 FOR UPDATE"
    );
    $stockStmt->execute([':cabang_id' => $branchId, ':product_id' => $productId]);
    $stockRow = $stockStmt->fetch();
    if (!$stockRow) throw new Exception('Data stok cabang tidak ditemukan.');

    if ($delta < 0 && (float)$stockRow['stock'] + $delta < 0) {
        throw new Exception('Stok cabang tidak mencukupi.');
    }

    $stmt = $pdo->prepare(
        "UPDATE branch_stocks
         SET stock = stock + :delta
         WHERE id = :id"
    );
    $stmt->execute([':delta' => $delta, ':id' => (int)$stockRow['id']]);

    insert_stock_movement(
        $pdo,
        (int)$stockRow['id'],
        $delta,
        ['transfer', 'stock_transfer', 'stock-transfer'],
        ['transfer_in', 'stock_transfer_in', 'in', 'masuk'],
        ['transfer_out', 'stock_transfer_out', 'out', 'keluar'],
        ['stock_transfer', 'transfer', 'stock-transfer', 'transfer_stok'],
        $transferId,
        $isIncoming ? 'Penerimaan transfer stok' : 'Pengeluaran transfer stok'
    );
}

$orderStatuses = enum_values($pdo, 'stock_transfers', 'status');
if (!$orderStatuses) {
    $orderStatuses = ['draft', 'pending', 'approved', 'in_transit', 'received', 'cancelled'];
}
$statusDraft = status_key($orderStatuses, ['draft']);
$statusPending = status_key($orderStatuses, ['pending', 'waiting']);
$statusApproved = status_key($orderStatuses, ['approved']);
$statusShipping = status_key($orderStatuses, ['in_transit', 'shipped', 'shipping']);
$statusReceived = status_key($orderStatuses, ['received', 'completed']);
$statusCancelled = status_key($orderStatuses, ['cancelled', 'canceled', 'rejected']);

if (empty($_SESSION['csrf_stock_transfer'])) {
    $_SESSION['csrf_stock_transfer'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_stock_transfer'];

$notify = '';
$notifyType = '';

/* ===========================
   POST ACTIONS
=========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_stock_transfer'] ?? '', $token)) {
        $notify = 'Permintaan tidak valid (CSRF).';
        $notifyType = 'error';
    } else {
        try {
            $pdo->beginTransaction();

            if ($action === 'create_transfer') {
                $transferDate = trim($_POST['transfer_date'] ?? '');
                $fromBranch = (int)($_POST['from_cabang_id'] ?? 0);
                $toBranch = (int)($_POST['to_cabang_id'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');
                $userId = current_user_id();

                $products = $_POST['product_id'] ?? [];
                $quantities = $_POST['quantity'] ?? [];
                if (!is_array($products)) $products = [];
                if (!is_array($quantities)) $quantities = [];

                $date = DateTime::createFromFormat('Y-m-d', $transferDate);
                if (!$date || $date->format('Y-m-d') !== $transferDate) {
                    throw new Exception('Tanggal transfer tidak valid.');
                }
                if ($fromBranch <= 0 || $toBranch <= 0) {
                    throw new Exception('Cabang asal dan cabang tujuan wajib dipilih.');
                }
                if ($fromBranch === $toBranch) {
                    throw new Exception('Cabang asal dan cabang tujuan tidak boleh sama.');
                }
                if ($userId <= 0) {
                    throw new Exception('User login tidak ditemukan pada session.');
                }

                if (!$products) {
                    throw new Exception('Tambahkan minimal satu produk.');
                }

                $productRows = [];
                foreach ($products as $i => $productIdRaw) {
                    $productId = (int)$productIdRaw;
                    $qty = (float)($quantities[$i] ?? 0);
                    if ($productId <= 0) throw new Exception('Produk transfer tidak valid.');
                    if ($qty <= 0) throw new Exception('Jumlah transfer harus lebih besar dari 0.');
                    if (isset($productRows[$productId])) {
                        throw new Exception('Produk yang sama tidak boleh ditambahkan dua kali.');
                    }
                    $productRows[$productId] = ['quantity' => $qty];
                }

                $placeholders = [];
                $params = [];
                foreach (array_keys($productRows) as $i => $productId) {
                    $key = ':pid_' . $i;
                    $placeholders[] = $key;
                    $params[$key] = $productId;
                }

                $pstmt = $pdo->prepare(
                    "SELECT id, code, name, unit
                     FROM products
                     WHERE status = 'active'
                       AND id IN (" . implode(',', $placeholders) . ")"
                );
                $pstmt->execute($params);
                $validProducts = [];
                foreach ($pstmt->fetchAll() as $row) {
                    $validProducts[(int)$row['id']] = $row;
                }
                if (count($validProducts) !== count($productRows)) {
                    throw new Exception('Ada produk yang tidak ditemukan atau tidak aktif.');
                }

                foreach ($productRows as $productId => $line) {
                    $available = get_branch_stock($pdo, $fromBranch, $productId);
                    if ($line['quantity'] > $available) {
                        $name = $validProducts[$productId]['name'] ?? ('ID ' . $productId);
                        throw new Exception(
                            "Stok {$name} di cabang asal tidak mencukupi. Tersedia " .
                            rtrim(rtrim(number_format($available, 2, '.', ''), '0'), '.') .
                            ", diminta " .
                            rtrim(rtrim(number_format($line['quantity'], 2, '.', ''), '0'), '.') . "."
                        );
                    }
                }

                $branchCheck = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM cabangs
                     WHERE id IN (:from_id, :to_id)
                       AND status = 'active'"
                );
                $branchCheck->execute([':from_id' => $fromBranch, ':to_id' => $toBranch]);
                if ((int)$branchCheck->fetchColumn() !== 2) {
                    throw new Exception('Cabang asal atau tujuan tidak aktif.');
                }

                $defaultStatus = $statusPending ?? $statusDraft ?? $orderStatuses[0];
                $number = next_transfer_number($pdo);

                $insert = $pdo->prepare(
                    "INSERT INTO stock_transfers
                        (transfer_number, from_cabang_id, to_cabang_id, transfer_date, status, notes, created_by)
                     VALUES
                        (:transfer_number, :from_cabang_id, :to_cabang_id, :transfer_date, :status, :notes, :created_by)"
                );
                $insert->execute([
                    ':transfer_number' => $number,
                    ':from_cabang_id' => $fromBranch,
                    ':to_cabang_id' => $toBranch,
                    ':transfer_date' => $transferDate,
                    ':status' => $defaultStatus,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':created_by' => $userId,
                ]);

                $transferId = (int)$pdo->lastInsertId();
                $detailInsert = $pdo->prepare(
                    "INSERT INTO stock_transfer_details
                        (stock_transfer_id, product_id, quantity, received_quantity)
                     VALUES
                        (:transfer_id, :product_id, :quantity, 0)"
                );

                foreach ($productRows as $productId => $line) {
                    $detailInsert->execute([
                        ':transfer_id' => $transferId,
                        ':product_id' => $productId,
                        ':quantity' => $line['quantity'],
                    ]);
                }

                $pdo->commit();
                redirect_transfer(['success' => 'created']);
            }

            if ($action === 'approve_transfer') {
                $id = (int)($_POST['transfer_id'] ?? 0);
                if ($id <= 0 || !$statusApproved) throw new Exception('Status persetujuan tidak tersedia pada database.');

                $transfer = get_transfer($pdo, $id);
                if (!$transfer) throw new Exception('Transfer tidak ditemukan.');
                if ($transfer['status'] !== $statusPending && $transfer['status'] !== $statusDraft) {
                    throw new Exception('Transfer tidak berada pada status yang dapat disetujui.');
                }

                $userId = current_user_id();
                if ($userId <= 0) throw new Exception('User login tidak ditemukan.');

                $update = $pdo->prepare(
                    "UPDATE stock_transfers
                     SET status = :status, approved_by = :approved_by
                     WHERE id = :id"
                );
                $update->execute([
                    ':status' => $statusApproved,
                    ':approved_by' => $userId,
                    ':id' => $id,
                ]);
                $pdo->commit();
                redirect_transfer(['success' => 'approved']);
            }

            if ($action === 'ship_transfer') {
                $id = (int)($_POST['transfer_id'] ?? 0);
                if ($id <= 0 || !$statusShipping) throw new Exception('Status pengiriman tidak tersedia pada database.');

                $transfer = get_transfer($pdo, $id);
                if (!$transfer) throw new Exception('Transfer tidak ditemukan.');
                if ($transfer['status'] !== $statusApproved) {
                    throw new Exception('Transfer harus berstatus Disetujui sebelum dikirim.');
                }

                $lines = get_transfer_lines($pdo, $id);
                if (!$lines) throw new Exception('Detail transfer kosong.');

                foreach ($lines as $line) {
                    $productId = (int)$line['product_id'];
                    $qty = (float)$line['quantity'];
                    $stock = branch_stock_for_update($pdo, (int)$transfer['from_cabang_id'], $productId);
                    if ($qty > $stock) {
                        throw new Exception(
                            "Stok {$line['product_name']} tidak mencukupi saat pengiriman."
                        );
                    }
                }

                foreach ($lines as $line) {
                    update_branch_stock(
                        $pdo,
                        (int)$transfer['from_cabang_id'],
                        (int)$line['product_id'],
                        -((float)$line['quantity']),
                        $id,
                        false
                    );
                }

                $update = $pdo->prepare(
                    "UPDATE stock_transfers
                     SET status = :status
                     WHERE id = :id"
                );
                $update->execute([':status' => $statusShipping, ':id' => $id]);

                $pdo->commit();
                redirect_transfer(['success' => 'shipped']);
            }

            if ($action === 'receive_transfer') {
                $id = (int)($_POST['transfer_id'] ?? 0);
                if ($id <= 0 || !$statusReceived) throw new Exception('Status diterima tidak tersedia pada database.');

                $transfer = get_transfer($pdo, $id);
                if (!$transfer) throw new Exception('Transfer tidak ditemukan.');
                if ($transfer['status'] !== $statusShipping) {
                    throw new Exception('Transfer belum berada dalam status Dalam Pengiriman.');
                }

                $lines = get_transfer_lines($pdo, $id);
                if (!$lines) throw new Exception('Detail transfer kosong.');

                $received = $_POST['received_quantity'] ?? [];
                if (!is_array($received)) $received = [];

                $allReceived = true;
                $detailUpdate = $pdo->prepare(
                    "UPDATE stock_transfer_details
                     SET received_quantity = :received_quantity
                     WHERE id = :id"
                );

                foreach ($lines as $line) {
                    $detailId = (int)$line['id'];
                    $oldReceived = (float)$line['received_quantity'];
                    $requested = $received[$detailId] ?? $oldReceived;
                    $newReceived = max($oldReceived, (float)$requested);
                    $quantity = (float)$line['quantity'];

                    if ($newReceived > $quantity) {
                        throw new Exception("Qty diterima untuk {$line['product_name']} melebihi qty transfer.");
                    }

                    if ($newReceived > $oldReceived) {
                        $delta = $newReceived - $oldReceived;
                        update_branch_stock(
                            $pdo,
                            (int)$transfer['to_cabang_id'],
                            (int)$line['product_id'],
                            $delta,
                            $id,
                            true
                        );
                    }

                    $detailUpdate->execute([
                        ':received_quantity' => $newReceived,
                        ':id' => $detailId
                    ]);

                    if ($newReceived < $quantity) {
                        $allReceived = false;
                    }
                }

                if (!$allReceived) {
                    $pdo->commit();
                    redirect_transfer(['success' => 'partial_received']);
                }

                $userId = current_user_id();
                $update = $pdo->prepare(
                    "UPDATE stock_transfers
                     SET status = :status, received_by = :received_by
                     WHERE id = :id"
                );
                $update->execute([
                    ':status' => $statusReceived,
                    ':received_by' => $userId,
                    ':id' => $id,
                ]);

                $pdo->commit();
                redirect_transfer(['success' => 'received']);
            }

            if ($action === 'cancel_transfer') {
                $id = (int)($_POST['transfer_id'] ?? 0);
                if ($id <= 0 || !$statusCancelled) throw new Exception('Status batal tidak tersedia pada database.');

                $transfer = get_transfer($pdo, $id);
                if (!$transfer) throw new Exception('Transfer tidak ditemukan.');
                if (in_array($transfer['status'], [$statusShipping, $statusReceived], true)) {
                    throw new Exception('Transfer yang sudah dikirim atau diterima tidak dapat dibatalkan.');
                }

                $update = $pdo->prepare(
                    "UPDATE stock_transfers SET status = :status WHERE id = :id"
                );
                $update->execute([':status' => $statusCancelled, ':id' => $id]);
                $pdo->commit();
                redirect_transfer(['success' => 'cancelled']);
            }

            throw new Exception('Aksi tidak dikenal.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $notify = $e->getMessage();
            $notifyType = 'error';
        }
    }
}

/* ===========================
   FILTER + PAGINATION
=========================== */
$search = trim($_GET['search'] ?? '');
$fromFilter = (int)($_GET['from'] ?? 0);
$toFilter = (int)($_GET['to'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        st.transfer_number LIKE :search_number
        OR fb.name LIKE :search_from
        OR tb.name LIKE :search_to
        OR EXISTS (
            SELECT 1
            FROM stock_transfer_details sd
            INNER JOIN products sp ON sp.id = sd.product_id
            WHERE sd.stock_transfer_id = st.id
              AND (sp.name LIKE :search_product OR sp.code LIKE :search_product_code)
        )
    )";
    $like = '%' . $search . '%';
    $params[':search_number'] = $like;
    $params[':search_from'] = $like;
    $params[':search_to'] = $like;
    $params[':search_product'] = $like;
    $params[':search_product_code'] = $like;
}
if ($fromFilter > 0) {
    $where[] = 'st.from_cabang_id = :from_filter';
    $params[':from_filter'] = $fromFilter;
}
if ($toFilter > 0) {
    $where[] = 'st.to_cabang_id = :to_filter';
    $params[':to_filter'] = $toFilter;
}
if ($statusFilter !== '' && in_array($statusFilter, $orderStatuses, true)) {
    $where[] = 'st.status = :status_filter';
    $params[':status_filter'] = $statusFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM stock_transfers st
     LEFT JOIN cabangs fb ON fb.id = st.from_cabang_id
     LEFT JOIN cabangs tb ON tb.id = st.to_cabang_id
     $whereSql"
);
$countStmt->execute($params);
$totalFiltered = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT
        st.*,
        fb.code AS from_code,
        fb.name AS from_name,
        tb.code AS to_code,
        tb.name AS to_name,
        u.name AS created_name,
        (
            SELECT COUNT(*)
            FROM stock_transfer_details d
            WHERE d.stock_transfer_id = st.id
        ) AS item_count,
        (
            SELECT COALESCE(SUM(d.quantity), 0)
            FROM stock_transfer_details d
            WHERE d.stock_transfer_id = st.id
        ) AS total_quantity,
        (
            SELECT COALESCE(SUM(d.received_quantity), 0)
            FROM stock_transfer_details d
            WHERE d.stock_transfer_id = st.id
        ) AS total_received
     FROM stock_transfers st
     LEFT JOIN cabangs fb ON fb.id = st.from_cabang_id
     LEFT JOIN cabangs tb ON tb.id = st.to_cabang_id
     LEFT JOIN users u ON u.id = st.created_by
     $whereSql
     ORDER BY st.id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $listStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$transfers = $listStmt->fetchAll();

/* statistics */
$totalTransfers = (int)$pdo->query("SELECT COUNT(*) FROM stock_transfers")->fetchColumn();
$pendingTransfers = $statusPending
    ? (int)$pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE status = " . $pdo->quote($statusPending))->fetchColumn()
    : 0;
$shippingTransfers = $statusShipping
    ? (int)$pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE status = " . $pdo->quote($statusShipping))->fetchColumn()
    : 0;
$receivedTransfers = $statusReceived
    ? (int)$pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE status = " . $pdo->quote($statusReceived))->fetchColumn()
    : 0;

$branches = get_active_branches($pdo);
$products = get_active_products($pdo);

/* modal data */
$detailTransfer = null;
$detailLines = [];
$receiveTransferData = null;
$receiveLines = [];

if (isset($_GET['detail']) && ctype_digit((string)$_GET['detail'])) {
    $detailTransfer = get_transfer($pdo, (int)$_GET['detail']);
    if ($detailTransfer) $detailLines = get_transfer_lines($pdo, (int)$_GET['detail']);
}

if (isset($_GET['receive']) && ctype_digit((string)$_GET['receive'])) {
    $receiveTransferData = get_transfer($pdo, (int)$_GET['receive']);
    if ($receiveTransferData && $statusShipping && $receiveTransferData['status'] === $statusShipping) {
        $receiveLines = get_transfer_lines($pdo, (int)$_GET['receive']);
    } else {
        $receiveTransferData = null;
    }
}

$hasFilters = ($search !== '' || $fromFilter > 0 || $toFilter > 0 || $statusFilter !== '');
$fromRow = $totalFiltered > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalFiltered);

function page_url(int $pageNo, string $search, int $from, int $to, string $status, array $extra = []): string {
    $params = ['page' => $pageNo];
    if ($search !== '') $params['search'] = $search;
    if ($from > 0) $params['from'] = $from;
    if ($to > 0) $params['to'] = $to;
    if ($status !== '') $params['status'] = $status;
    foreach ($extra as $key => $value) $params[$key] = $value;
    return 'index.php?' . http_build_query($params);
}

$successMessages = [
    'created' => 'Transfer stok berhasil dibuat dan menunggu persetujuan.',
    'approved' => 'Transfer stok berhasil disetujui.',
    'shipped' => 'Transfer stok berhasil dikirim dan stok cabang asal telah dikurangi.',
    'received' => 'Transfer stok berhasil diterima dan stok cabang tujuan telah ditambahkan.',
    'partial_received' => 'Penerimaan sebagian berhasil disimpan. Transfer tetap Dalam Pengiriman.',
    'cancelled' => 'Transfer stok berhasil dibatalkan.',
];

if (($_GET['export'] ?? '') === 'csv') {
    $exportStmt = $pdo->prepare(
        "SELECT
            st.transfer_number,
            st.transfer_date,
            fb.code AS from_code, fb.name AS from_name,
            tb.code AS to_code, tb.name AS to_name,
            st.status,
            (
                SELECT COUNT(*) FROM stock_transfer_details d
                WHERE d.stock_transfer_id = st.id
            ) AS item_count,
            (
                SELECT COALESCE(SUM(d.quantity), 0) FROM stock_transfer_details d
                WHERE d.stock_transfer_id = st.id
            ) AS total_quantity,
            u.name AS created_name
         FROM stock_transfers st
         LEFT JOIN cabangs fb ON fb.id = st.from_cabang_id
         LEFT JOIN cabangs tb ON tb.id = st.to_cabang_id
         LEFT JOIN users u ON u.id = st.created_by
         $whereSql
         ORDER BY st.id DESC"
    );
    $exportStmt->execute($params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="transfer_stok_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['No Transfer', 'Tanggal', 'Cabang Asal', 'Cabang Tujuan', 'Jumlah Item', 'Total Qty', 'Status', 'Dibuat Oleh']);
    foreach ($exportStmt->fetchAll() as $row) {
        fputcsv($out, [
            $row['transfer_number'],
            $row['transfer_date'],
            trim(($row['from_code'] ? $row['from_code'] . ' - ' : '') . ($row['from_name'] ?? '')),
            trim(($row['to_code'] ? $row['to_code'] . ' - ' : '') . ($row['to_name'] ?? '')),
            $row['item_count'],
            $row['total_quantity'],
            status_label((string)$row['status']),
            $row['created_name'] ?? '-',
        ]);
    }
    fclose($out);
    exit;
}
if (isset($_GET['stock_lookup']) && $_GET['stock_lookup'] === '1') {
    $branchId = (int)($_GET['branch_id'] ?? 0);
    $productId = (int)($_GET['product_id'] ?? 0);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'stock' => $branchId > 0 && $productId > 0 ? get_branch_stock($pdo, $branchId, $productId) : 0
    ]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c1a2d">
    <title><?= h($pageTitle) ?> | <?= h($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../assets/css/admin.css?v=20260907">
    <link rel="stylesheet" href="style.css">
</head>
<body class="stock-transfer-page">

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
            <a href="./" class="menu-item active"><i class="bi bi-arrow-left-right"></i><span>Transfer Stok</span></a>
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
<div class="st-container">

    <header class="st-header">
        <div>
            <div class="st-breadcrumb">Dashboard <i class="bi bi-chevron-right"></i> Transaksi <i class="bi bi-chevron-right"></i> Transfer Stok</div>
            <h1>Transfer Stok Antar Cabang</h1>
            <p>Kelola perpindahan persediaan produk antar cabang dengan alur persetujuan, pengiriman, dan penerimaan.</p>
        </div>
        <div class="st-header-actions">
            <a class="st-btn st-btn-outline" href="<?= h(page_url($page, $search, $fromFilter, $toFilter, $statusFilter, ['export' => 'csv'])) ?>">
                <i class="bi bi-download"></i> Export
            </a>
            <button class="st-btn st-btn-primary" type="button" onclick="openTransferModal()">
                <i class="bi bi-plus-lg"></i> Buat Transfer
            </button>
        </div>
    </header>

    <?php if ($notify !== ''): ?>
        <div class="st-alert is-error"><i class="bi bi-exclamation-circle"></i><span><?= h($notify) ?></span></div>
    <?php elseif (isset($_GET['success'], $successMessages[$_GET['success']])): ?>
        <div class="st-alert is-success"><i class="bi bi-check-circle"></i><span><?= h($successMessages[$_GET['success']]) ?></span></div>
    <?php endif; ?>

    <section class="st-stats">
        <div class="st-stat"><div class="st-stat-icon blue"><i class="bi bi-arrow-left-right"></i></div><div><span>Total Transfer</span><strong><?= number_format($totalTransfers, 0, ',', '.') ?></strong><small>Seluruh transaksi</small></div></div>
        <div class="st-stat"><div class="st-stat-icon orange"><i class="bi bi-hourglass-split"></i></div><div><span>Menunggu</span><strong><?= number_format($pendingTransfers, 0, ',', '.') ?></strong><small>Perlu persetujuan</small></div></div>
        <div class="st-stat"><div class="st-stat-icon purple"><i class="bi bi-truck"></i></div><div><span>Dalam Pengiriman</span><strong><?= number_format($shippingTransfers, 0, ',', '.') ?></strong><small>Belum seluruhnya diterima</small></div></div>
        <div class="st-stat"><div class="st-stat-icon green"><i class="bi bi-check-circle-fill"></i></div><div><span>Diterima</span><strong><?= number_format($receivedTransfers, 0, ',', '.') ?></strong><small>Transfer selesai</small></div></div>
    </section>

    <section class="st-flow-card">
        <div class="st-flow-title"><div><h2>Alur Transfer Stok</h2><p>Stok asal berkurang saat dikirim, stok tujuan bertambah saat diterima.</p></div></div>
        <div class="st-flow">
            <div class="st-flow-step"><div class="st-flow-icon"><i class="bi bi-file-earmark-plus"></i></div><div><strong>Buat Transfer</strong><span>Permintaan dibuat</span></div></div>
            <div class="st-flow-line"></div>
            <div class="st-flow-step"><div class="st-flow-icon"><i class="bi bi-check2-circle"></i></div><div><strong>Persetujuan</strong><span>Transfer disetujui</span></div></div>
            <div class="st-flow-line"></div>
            <div class="st-flow-step"><div class="st-flow-icon"><i class="bi bi-truck"></i></div><div><strong>Dikirim</strong><span>Stok asal berkurang</span></div></div>
            <div class="st-flow-line"></div>
            <div class="st-flow-step"><div class="st-flow-icon"><i class="bi bi-box-arrow-in-down"></i></div><div><strong>Diterima</strong><span>Stok tujuan bertambah</span></div></div>
        </div>
    </section>

    <section class="st-card">
        <div class="st-card-header">
            <div><h2>Riwayat Transfer Stok</h2><p>Daftar perpindahan produk antar cabang.</p></div>
        </div>

        <form method="get" class="st-filter">
            <div class="st-search"><i class="bi bi-search"></i><input name="search" value="<?= h($search) ?>" placeholder="Cari nomor transfer, cabang, atau produk..."></div>
            <select name="from"><option value="">Cabang Asal</option><?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $fromFilter === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['code'] . ' - ' . $b['name']) ?></option><?php endforeach; ?></select>
            <select name="to"><option value="">Cabang Tujuan</option><?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $toFilter === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['code'] . ' - ' . $b['name']) ?></option><?php endforeach; ?></select>
            <select name="status"><option value="">Semua Status</option><?php foreach ($orderStatuses as $s): ?><option value="<?= h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= h(status_label($s)) ?></option><?php endforeach; ?></select>
            <button type="submit" class="st-btn st-btn-search"><i class="bi bi-search"></i> Cari</button>
            <a class="st-reset <?= $hasFilters ? '' : 'disabled' ?>" href="<?= $hasFilters ? 'index.php' : '#' ?>">Reset</a>
        </form>

        <div class="st-table-wrap">
            <table class="st-table">
                <thead><tr><th>No Transfer</th><th>Tanggal</th><th>Cabang Asal</th><th>Cabang Tujuan</th><th>Item</th><th>Qty</th><th>Diterima</th><th>Status</th><th>Dibuat Oleh</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php if (!$transfers): ?>
                    <tr><td colspan="10"><div class="st-empty"><i class="bi bi-arrow-left-right"></i><strong>Data transfer tidak ditemukan</strong><span>Coba ubah pencarian atau filter Anda.</span></div></td></tr>
                <?php else: ?>
                    <?php foreach ($transfers as $tr): ?>
                        <tr>
                            <td><span class="st-code"><?= h($tr['transfer_number']) ?></span></td>
                            <td><?= h(date('d M Y', strtotime($tr['transfer_date']))) ?></td>
                            <td><?= h(($tr['from_code'] ? $tr['from_code'] . ' - ' : '') . $tr['from_name']) ?></td>
                            <td><?= h(($tr['to_code'] ? $tr['to_code'] . ' - ' : '') . $tr['to_name']) ?></td>
                            <td><?= (int)$tr['item_count'] ?> produk</td>
                            <td><strong><?= h(rtrim(rtrim(number_format((float)$tr['total_quantity'], 2, ',', '.'), '0'), ',')) ?></strong></td>
                            <td><?= h(rtrim(rtrim(number_format((float)$tr['total_received'], 2, ',', '.'), '0'), ',')) ?></td>
                            <td><span class="st-status <?= h(status_class((string)$tr['status'])) ?>"><?= h(status_label((string)$tr['status'])) ?></span></td>
                            <td><?= h($tr['created_name'] ?: '-') ?></td>
                            <td>
                                <div class="st-actions">
                                    <a class="st-action view" title="Detail" href="<?= h(page_url($page, $search, $fromFilter, $toFilter, $statusFilter, ['detail' => (int)$tr['id']])) ?>"><i class="bi bi-eye"></i></a>
                                    <a class="st-action print" title="Cetak" target="_blank" rel="noopener noreferrer" href="print.php?id=<?= (int)$tr['id'] ?>"><i class="bi bi-printer"></i></a>
                                    <?php if ($statusPending && in_array($tr['status'], array_filter([$statusPending, $statusDraft]), true)): ?>
                                        <form method="post" onsubmit="return confirm('Setujui transfer ini?');"><input type="hidden" name="action" value="approve_transfer"><input type="hidden" name="transfer_id" value="<?= (int)$tr['id'] ?>"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><button class="st-action approve" title="Setujui"><i class="bi bi-check-lg"></i></button></form>
                                    <?php endif; ?>
                                    <?php if ($statusApproved && $statusShipping && $tr['status'] === $statusApproved): ?>
                                        <form method="post" onsubmit="return confirm('Kirim transfer ini? Stok cabang asal akan dikurangi.');"><input type="hidden" name="action" value="ship_transfer"><input type="hidden" name="transfer_id" value="<?= (int)$tr['id'] ?>"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><button class="st-action ship" title="Kirim"><i class="bi bi-truck"></i></button></form>
                                    <?php endif; ?>
                                    <?php if ($statusShipping && $tr['status'] === $statusShipping): ?>
                                        <a class="st-action receive" title="Terima" href="<?= h(page_url($page, $search, $fromFilter, $toFilter, $statusFilter, ['receive' => (int)$tr['id']])) ?>"><i class="bi bi-box-arrow-in-down"></i></a>
                                    <?php endif; ?>
                                    <?php if ($statusCancelled && !in_array($tr['status'], array_filter([$statusShipping, $statusReceived, $statusCancelled]), true)): ?>
                                        <form method="post" onsubmit="return confirm('Batalkan transfer ini?');"><input type="hidden" name="action" value="cancel_transfer"><input type="hidden" name="transfer_id" value="<?= (int)$tr['id'] ?>"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><button class="st-action cancel" title="Batalkan"><i class="bi bi-x-lg"></i></button></form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="st-footer">
            <span>Menampilkan <strong><?= $fromRow ?></strong>–<strong><?= $toRow ?></strong> dari <strong><?= $totalFiltered ?></strong> transfer</span>
            <div class="st-pagination">
                <a href="<?= $page > 1 ? h(page_url($page-1,$search,$fromFilter,$toFilter,$statusFilter)) : '#' ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>" aria-label="Halaman sebelumnya">
                    <i class="bi bi-chevron-left"></i>
                </a>

                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                ?>

                <?php if ($start > 1): ?>
                    <a href="<?= h(page_url(1,$search,$fromFilter,$toFilter,$statusFilter)) ?>">1</a>
                    <?php if ($start > 2): ?><span class="ellipsis">...</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $start; $p <= $end; $p++): ?>
                    <a
                        class="<?= $p === $page ? 'active' : '' ?>"
                        href="<?= h(page_url($p,$search,$fromFilter,$toFilter,$statusFilter)) ?>"
                        <?= $p === $page ? 'aria-current="page"' : '' ?>
                    ><?= $p ?></a>
                <?php endfor; ?>

                <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span class="ellipsis">...</span><?php endif; ?>
                    <a href="<?= h(page_url($totalPages,$search,$fromFilter,$toFilter,$statusFilter)) ?>"><?= $totalPages ?></a>
                <?php endif; ?>

                <a href="<?= $page < $totalPages ? h(page_url($page+1,$search,$fromFilter,$toFilter,$statusFilter)) : '#' ?>" class="<?= $page >= $totalPages ? 'disabled' : '' ?>" aria-label="Halaman berikutnya">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>
    </section>
</div>
</main>

<!-- CREATE MODAL -->
<div class="st-modal <?= $detailTransfer || $receiveTransferData ? '' : 'hidden' ?>" id="transferModal">
    <div class="st-modal-panel st-modal-wide">
        <div class="st-modal-head"><div><h2>Buat Transfer Stok</h2><p>Buat permintaan perpindahan produk antar cabang.</p></div><button type="button" onclick="closeModal('transferModal')"><i class="bi bi-x-lg"></i></button></div>
        <form method="post" id="transferForm">
            <input type="hidden" name="action" value="create_transfer">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="st-modal-body">
                <div class="st-form-grid">
                    <label>
                        <p class="st-label-title">No Transfer</p>
                        <input type="text" value="<?= h(next_transfer_number($pdo)) ?>" readonly>
                    </label>
                    <label>
                        <p class="st-label-title">Tanggal Transfer <span class="st-required">*</span></p>
                        <input type="date" name="transfer_date" value="<?= date('Y-m-d') ?>" required>
                    </label>
                    <label>
                        <p class="st-label-title">Cabang Asal <span class="st-required">*</span></p>
                        <select name="from_cabang_id" id="fromBranch" required onchange="filterBranchDestination()"><option value="">Pilih cabang</option><?php foreach($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= h($b['code'].' - '.$b['name']) ?></option><?php endforeach; ?></select>
                    </label>
                    <label>
                        <p class="st-label-title">Cabang Tujuan <span class="st-required">*</span></p>
                        <select name="to_cabang_id" id="toBranch" required><option value="">Pilih cabang</option><?php foreach($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= h($b['code'].' - '.$b['name']) ?></option><?php endforeach; ?></select>
                    </label>
                    <label class="full">
                        <p class="st-label-title">Keterangan</p>
                        <textarea name="notes" rows="3" placeholder="Catatan transfer..."></textarea>
                    </label>
                </div>
                <div class="st-section-title"><div><h3>Produk yang Dipindahkan</h3><span>Pilih produk dan jumlah sesuai stok cabang asal.</span></div><button type="button" class="st-btn st-btn-outline st-btn-small" onclick="addProductRow()"><i class="bi bi-plus-lg"></i> Tambah Produk</button></div>
                <div id="productRows"></div>
                <div class="st-summary"><div><span>Jumlah Produk</span><strong id="totalItems">0</strong></div><div><span>Total Qty</span><strong id="totalQty">0</strong></div></div>
                <div class="st-warning"><i class="bi bi-exclamation-triangle"></i><div><strong>Perhatian</strong><p>Stok cabang asal baru berkurang saat transfer dikirim. Stok cabang tujuan bertambah saat seluruh penerimaan dikonfirmasi.</p></div></div>
            </div>
            <div class="st-modal-foot"><button type="button" class="st-btn st-btn-outline" onclick="closeModal('transferModal')">Batal</button><button type="submit" class="st-btn st-btn-primary"><i class="bi bi-check-lg"></i> Simpan Transfer</button></div>
        </form>
    </div>
</div>

<!-- DETAIL MODAL -->
<?php if ($detailTransfer): ?>
<div class="st-modal show" id="detailModal">
    <div class="st-modal-panel st-modal-wide">
        <div class="st-modal-head"><div><h2>Detail <?= h($detailTransfer['transfer_number']) ?></h2><p><?= h($detailTransfer['transfer_date']) ?> · <?= h(status_label((string)$detailTransfer['status'])) ?></p></div><a href="<?= h(page_url($page,$search,$fromFilter,$toFilter,$statusFilter)) ?>"><i class="bi bi-x-lg"></i></a></div>
        <div class="st-modal-body">
            <div class="st-detail-grid">
                <div><span>No Transfer</span><strong><?= h($detailTransfer['transfer_number']) ?></strong></div>
                <div><span>Tanggal</span><strong><?= h(date('d M Y', strtotime($detailTransfer['transfer_date']))) ?></strong></div>
                <div><span>Cabang Asal</span><strong><?= h(($detailTransfer['from_code'] ? $detailTransfer['from_code'].' - ' : '').$detailTransfer['from_name']) ?></strong></div>
                <div><span>Cabang Tujuan</span><strong><?= h(($detailTransfer['to_code'] ? $detailTransfer['to_code'].' - ' : '').$detailTransfer['to_name']) ?></strong></div>
                <div><span>Dibuat Oleh</span><strong><?= h($detailTransfer['created_name'] ?: '-') ?></strong></div>
                <div><span>Disetujui Oleh</span><strong><?= h($detailTransfer['approved_name'] ?: '-') ?></strong></div>
                <div><span>Diterima Oleh</span><strong><?= h($detailTransfer['received_name'] ?: '-') ?></strong></div>
                <div><span>Status</span><strong><span class="st-status <?= h(status_class((string)$detailTransfer['status'])) ?>"><?= h(status_label((string)$detailTransfer['status'])) ?></span></strong></div>
                <div class="full"><span>Catatan</span><strong><?= nl2br(h($detailTransfer['notes'] ?: '-')) ?></strong></div>
            </div>
            <div class="st-detail-table-wrap">
                <table class="st-detail-table"><thead><tr><th>Produk</th><th>Unit</th><th>Qty Transfer</th><th>Qty Diterima</th></tr></thead><tbody>
                <?php foreach ($detailLines as $line): ?><tr><td><strong><?= h($line['product_code'].' - '.$line['product_name']) ?></strong><small><?= h($line['brand'] ?: '') ?></small></td><td><?= h($line['unit'] ?: '-') ?></td><td><?= h($line['quantity']) ?></td><td><?= h($line['received_quantity']) ?></td></tr><?php endforeach; ?>
                </tbody></table>
            </div>
        </div>
        <div class="st-modal-foot"><a class="st-btn st-btn-outline" target="_blank" rel="noopener noreferrer" href="print.php?id=<?= (int)$detailTransfer['id'] ?>"><i class="bi bi-printer"></i> Cetak</a><a class="st-btn st-btn-outline" href="<?= h(page_url($page,$search,$fromFilter,$toFilter,$statusFilter)) ?>">Tutup</a></div>
    </div>
</div>
<?php endif; ?>

<!-- RECEIVE MODAL -->
<?php if ($receiveTransferData): ?>
<div class="st-modal show" id="receiveModal">
    <div class="st-modal-panel st-modal-wide">
        <div class="st-modal-head"><div><h2>Penerimaan <?= h($receiveTransferData['transfer_number']) ?></h2><p>Masukkan jumlah barang yang benar-benar diterima.</p></div><a href="<?= h(page_url($page,$search,$fromFilter,$toFilter,$statusFilter)) ?>"><i class="bi bi-x-lg"></i></a></div>
        <form method="post">
            <input type="hidden" name="action" value="receive_transfer">
            <input type="hidden" name="transfer_id" value="<?= (int)$receiveTransferData['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="st-modal-body">
                <div class="st-receive-info"><strong><?= h(($receiveTransferData['from_code'] ? $receiveTransferData['from_code'].' - ' : '').$receiveTransferData['from_name']) ?></strong><i class="bi bi-arrow-right"></i><strong><?= h(($receiveTransferData['to_code'] ? $receiveTransferData['to_code'].' - ' : '').$receiveTransferData['to_name']) ?></strong></div>
                <div class="st-detail-table-wrap">
                    <table class="st-detail-table"><thead><tr><th>Produk</th><th>Qty Transfer</th><th>Sudah Diterima</th><th>Terima Sekarang</th></tr></thead><tbody>
                    <?php foreach ($receiveLines as $line): $remaining=max(0,(float)$line['quantity']-(float)$line['received_quantity']); ?>
                        <tr><td><strong><?= h($line['product_code'].' - '.$line['product_name']) ?></strong></td><td><?= h($line['quantity']) ?></td><td><?= h($line['received_quantity']) ?></td><td><input class="st-receive-input" type="number" min="<?= h($line['received_quantity']) ?>" max="<?= h($line['quantity']) ?>" step="0.01" name="received_quantity[<?= (int)$line['id'] ?>]" value="<?= h($line['received_quantity']) ?>"></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>
                <div class="st-warning"><i class="bi bi-info-circle"></i><div><strong>Penerimaan parsial</strong><p>Anda dapat menyimpan penerimaan bertahap. Transfer menjadi Diterima setelah seluruh quantity terpenuhi.</p></div></div>
            </div>
            <div class="st-modal-foot"><a class="st-btn st-btn-outline" href="<?= h(page_url($page,$search,$fromFilter,$toFilter,$statusFilter)) ?>">Batal</a><button class="st-btn st-btn-primary" type="submit"><i class="bi bi-box-arrow-in-down"></i> Simpan Penerimaan</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const productOptions = <?= json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function openTransferModal() {
    const modal = document.getElementById('transferModal');
    modal.classList.remove('hidden');
    modal.classList.add('show');
    const list = document.getElementById('productRows');
    if (!list.children.length) addProductRow();
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('show');
    el.classList.add('hidden');
}
function filterBranchDestination() {
    const from = document.getElementById('fromBranch').value;
    [...document.getElementById('toBranch').options].forEach(opt => {
        if (!opt.value) return;
        opt.disabled = opt.value === from;
        if (opt.disabled && opt.selected) opt.selected = false;
    });
    document.querySelectorAll('.product-row-select').forEach(select => {
        refreshStockPreview(select);
    });
}
function addProductRow() {
    const container = document.getElementById('productRows');
    const row = document.createElement('div');
    row.className = 'st-product-row';
    row.innerHTML = `
        <label>Produk
            <select name="product_id[]" class="product-row-select" required onchange="refreshStockPreview(this)">
                <option value="">Pilih produk</option>
                ${productOptions.map(p => `<option value="${p.id}" data-unit="${escapeHtml(p.unit || '')}">${escapeHtml((p.code ? p.code + ' - ' : '') + p.name + (p.brand ? ' (' + p.brand + ')' : ''))}</option>`).join('')}
            </select>
        </label>
        <div class="st-stock-preview"><span>Stok Asal</span><strong>-</strong></div>
        <label>Jumlah
            <input type="number" name="quantity[]" class="product-qty" min="0.01" step="0.01" value="1" required oninput="updateSummary()">
        </label>
        <button type="button" class="st-remove-product" onclick="removeProductRow(this)" title="Hapus"><i class="bi bi-trash"></i></button>
    `;
    container.appendChild(row);
    updateSummary();
}
async function refreshStockPreview(select) {
    const from = document.getElementById('fromBranch').value;
    const row = select.closest('.st-product-row');
    const strong = row.querySelector('.st-stock-preview strong');
    if (!from || !select.value) {
        strong.textContent = '-';
        updateSummary();
        return;
    }
    const branch = <?= json_encode('index.php') ?>;
    const url = branch + '?stock_lookup=1&branch_id=' + encodeURIComponent(from) + '&product_id=' + encodeURIComponent(select.value);
    try {
        const response = await fetch(url, {headers:{'X-Requested-With':'XMLHttpRequest'}});
        const data = await response.json();
        strong.textContent = data.stock !== undefined ? data.stock + (data.unit ? ' ' + data.unit : '') : '0';
    } catch (e) {
        strong.textContent = '0';
    }
    updateSummary();
}
function removeProductRow(button) {
    const rows = document.querySelectorAll('.st-product-row');
    if (rows.length <= 1) {
        alert('Minimal satu produk harus ada.');
        return;
    }
    button.closest('.st-product-row').remove();
    updateSummary();
}
function updateSummary() {
    let total = 0;
    const rows = document.querySelectorAll('.st-product-row');
    rows.forEach(row => total += parseFloat(row.querySelector('.product-qty')?.value || '0') || 0);
    document.getElementById('totalItems').textContent = rows.length;
    document.getElementById('totalQty').textContent = total.toLocaleString('id-ID');
}
function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];});
}
document.addEventListener('click', function(e) {
    const modal = document.getElementById('transferModal');
    if (e.target === modal) closeModal('transferModal');
});
</script>
</body>
</html>
