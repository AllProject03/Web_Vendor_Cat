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
    return 'Rp' . number_format($value, 0, ',', '.');
}

function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
}

function redirect_payments(array $params = []): never
{
    $url = 'index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

function enum_values(PDO $pdo, string $table, string $column): array
{
    $allowed = [
        'payments' => ['payment_method', 'status'],
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
            static fn($v) => str_replace(["\\'", "\\\\"], ["'", "\\"], trim((string)$v)),
            $values
        ),
        static fn($v) => $v !== ''
    ));
}

function payment_method_label(string $method): string
{
    return match ($method) {
        'cash' => 'Cash',
        'transfer' => 'Transfer',
        'qris' => 'QRIS',
        'edc' => 'EDC',
        'other' => 'Lainnya',
        default => ucwords(str_replace(['_', '-'], ' ', $method)),
    };
}

function payment_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Menunggu',
        'paid' => 'Lunas',
        'cancelled', 'canceled' => 'Dibatalkan',
        default => ucwords(str_replace(['_', '-'], ' ', $status)),
    };
}

function payment_status_class(string $status): string
{
    return match ($status) {
        'pending' => 'warning',
        'paid' => 'success',
        'cancelled', 'canceled' => 'danger',
        default => 'neutral',
    };
}

function payment_method_icon(string $method): string
{
    return match ($method) {
        'cash' => 'bi-cash',
        'transfer' => 'bi-bank',
        'qris' => 'bi-qr-code',
        'edc' => 'bi-credit-card',
        'other' => 'bi-three-dots',
        default => 'bi-wallet2',
    };
}

function generate_payment_number(PDO $pdo): string
{
    $year = date('Y');

    $stmt = $pdo->prepare(
        "SELECT payment_number
         FROM payments
         WHERE payment_number LIKE :prefix
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([':prefix' => 'PAY-' . $year . '-%']);

    $last = $stmt->fetchColumn();
    $sequence = 1;

    if (is_string($last) && preg_match('/(\d+)$/', $last, $match)) {
        $sequence = (int)$match[1] + 1;
    }

    return sprintf('PAY-%s-%04d', $year, $sequence);
}

function get_order_payment_summary(PDO $pdo, int $orderId, ?int $excludePaymentId = null): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            o.id,
            o.order_number,
            o.order_date,
            o.grand_total,
            o.status AS order_status,
            o.cabang_id,
            c.name AS customer_name,
            c.customer_type,
            c.phone AS customer_phone,
            cb.code AS cabang_code,
            cb.name AS cabang_name,
            COALESCE(SUM(
                CASE
                    WHEN p.status = 'paid' THEN p.amount
                    ELSE 0
                END
            ), 0) AS paid_total
         FROM orders o
         LEFT JOIN customers c ON c.id = o.customer_id
         LEFT JOIN cabangs cb ON cb.id = o.cabang_id
         LEFT JOIN payments p ON p.order_id = o.id
         WHERE o.id = :order_id
         GROUP BY
            o.id, o.order_number, o.order_date, o.grand_total, o.status,
            o.cabang_id, c.name, c.customer_type, c.phone, cb.code, cb.name
         LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);

    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if ($excludePaymentId !== null) {
        $stmt2 = $pdo->prepare(
            "SELECT COALESCE(SUM(
                CASE WHEN status = 'paid' THEN amount ELSE 0 END
            ), 0)
             FROM payments
             WHERE order_id = :order_id
               AND id <> :payment_id"
        );
        $stmt2->execute([
            ':order_id' => $orderId,
            ':payment_id' => $excludePaymentId,
        ]);
        $row['paid_total'] = (float)$stmt2->fetchColumn();
    } else {
        $row['paid_total'] = (float)$row['paid_total'];
    }

    $row['grand_total'] = (float)$row['grand_total'];
    $row['remaining_total'] = max(0, $row['grand_total'] - $row['paid_total']);

    return $row;
}

function get_payment(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            p.*,
            o.order_number,
            o.grand_total AS order_grand_total,
            o.order_date,
            o.status AS order_status,
            c.name AS customer_name,
            c.customer_type,
            cb.code AS cabang_code,
            cb.name AS cabang_name,
            u.name AS receiver_name
         FROM payments p
         INNER JOIN orders o ON o.id = p.order_id
         LEFT JOIN customers c ON c.id = o.customer_id
         LEFT JOIN cabangs cb ON cb.id = o.cabang_id
         LEFT JOIN users u ON u.id = p.received_by
         WHERE p.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function get_active_orders(PDO $pdo): array
{
    return $pdo->query(
        "SELECT
            o.id,
            o.order_number,
            o.order_date,
            o.grand_total,
            o.status,
            c.name AS customer_name,
            c.customer_type,
            cb.code AS cabang_code,
            cb.name AS cabang_name,
            COALESCE((
                SELECT SUM(pp.amount)
                FROM payments pp
                WHERE pp.order_id = o.id
                  AND pp.status = 'paid'
            ), 0) AS paid_total
         FROM orders o
         LEFT JOIN customers c ON c.id = o.customer_id
         LEFT JOIN cabangs cb ON cb.id = o.cabang_id
         WHERE o.status NOT IN ('cancelled', 'canceled')
         ORDER BY o.id DESC"
    )->fetchAll();
}

function build_page_url(
    int $pageNo,
    string $search,
    string $method,
    string $status,
    int $branch,
    array $extra = []
): string {
    $params = ['page' => $pageNo];

    if ($search !== '') {
        $params['search'] = $search;
    }
    if ($method !== '') {
        $params['method'] = $method;
    }
    if ($status !== '') {
        $params['status'] = $status;
    }
    if ($branch > 0) {
        $params['branch'] = $branch;
    }

    foreach ($extra as $key => $value) {
        $params[$key] = $value;
    }

    return 'index.php?' . http_build_query($params);
}

$paymentMethods = enum_values($pdo, 'payments', 'payment_method');
if (!$paymentMethods) {
    $paymentMethods = ['cash', 'transfer', 'qris', 'edc', 'other'];
}

$paymentStatuses = enum_values($pdo, 'payments', 'status');
if (!$paymentStatuses) {
    $paymentStatuses = ['pending', 'paid', 'cancelled'];
}

if (empty($_SESSION['csrf_payments'])) {
    $_SESSION['csrf_payments'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_payments'];

$notify = '';
$notifyType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_payments'] ?? '', $token)) {
        $notify = 'Permintaan tidak valid (CSRF).';
        $notifyType = 'error';
    } else {
        try {
            if ($action === 'create_payment') {
                $orderId = (int)($_POST['order_id'] ?? 0);
                $paymentDate = trim($_POST['payment_date'] ?? '');
                $amount = max(0, (float)($_POST['amount'] ?? 0));
                $paymentMethod = trim($_POST['payment_method'] ?? '');
                $status = trim($_POST['status'] ?? '');

                if ($orderId <= 0) {
                    throw new Exception('Pesanan wajib dipilih.');
                }

                $dateCheck = DateTime::createFromFormat('Y-m-d', $paymentDate);
                if (!$dateCheck || $dateCheck->format('Y-m-d') !== $paymentDate) {
                    throw new Exception('Tanggal pembayaran tidak valid.');
                }

                if ($amount <= 0) {
                    throw new Exception('Jumlah pembayaran harus lebih besar dari 0.');
                }

                if (!in_array($paymentMethod, $paymentMethods, true)) {
                    throw new Exception('Metode pembayaran tidak valid.');
                }

                if (!in_array($status, $paymentStatuses, true)) {
                    throw new Exception('Status pembayaran tidak valid.');
                }

                $userId = current_user_id();
                if ($userId <= 0) {
                    throw new Exception('User login tidak ditemukan pada session.');
                }

                $pdo->beginTransaction();

                $orderLock = $pdo->prepare(
                    "SELECT
                        id,
                        order_number,
                        grand_total,
                        status
                     FROM orders
                     WHERE id = :id
                     LIMIT 1
                     FOR UPDATE"
                );
                $orderLock->execute([':id' => $orderId]);
                $order = $orderLock->fetch();

                if (!$order) {
                    throw new Exception('Pesanan tidak ditemukan.');
                }

                if (in_array($order['status'], ['cancelled', 'canceled'], true)) {
                    throw new Exception('Pesanan yang dibatalkan tidak dapat menerima pembayaran.');
                }

                $paidStmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(amount), 0)
                     FROM payments
                     WHERE order_id = :order_id
                       AND status = 'paid'"
                );
                $paidStmt->execute([':order_id' => $orderId]);
                $paidTotal = (float)$paidStmt->fetchColumn();

                $remaining = max(0, (float)$order['grand_total'] - $paidTotal);

                if ($amount > $remaining + 0.000001) {
                    throw new Exception(
                        'Jumlah pembayaran melebihi sisa tagihan. Sisa saat ini: ' . money($remaining) . '.'
                    );
                }

                $paymentNumber = generate_payment_number($pdo);

                $insert = $pdo->prepare(
                    "INSERT INTO payments
                        (order_id, payment_number, payment_date, amount, payment_method, status, received_by)
                     VALUES
                        (:order_id, :payment_number, :payment_date, :amount, :payment_method, :status, :received_by)"
                );

                $insert->execute([
                    ':order_id' => $orderId,
                    ':payment_number' => $paymentNumber,
                    ':payment_date' => $paymentDate,
                    ':amount' => $amount,
                    ':payment_method' => $paymentMethod,
                    ':status' => $status,
                    ':received_by' => $userId,
                ]);

                $pdo->commit();

                redirect_payments(['success' => 'added']);
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

/* filters */
$search = trim($_GET['search'] ?? '');
$methodFilter = trim($_GET['method'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$branchFilter = max(0, (int)($_GET['branch'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        p.payment_number LIKE :search_payment
        OR o.order_number LIKE :search_order
        OR c.name LIKE :search_customer
        OR c.phone LIKE :search_phone
    )";
    $like = '%' . $search . '%';
    $params[':search_payment'] = $like;
    $params[':search_order'] = $like;
    $params[':search_customer'] = $like;
    $params[':search_phone'] = $like;
}

if ($methodFilter !== '' && in_array($methodFilter, $paymentMethods, true)) {
    $where[] = 'p.payment_method = :method_filter';
    $params[':method_filter'] = $methodFilter;
}

if ($statusFilter !== '' && in_array($statusFilter, $paymentStatuses, true)) {
    $where[] = 'p.status = :status_filter';
    $params[':status_filter'] = $statusFilter;
}

if ($branchFilter > 0) {
    $where[] = 'o.cabang_id = :branch_filter';
    $params[':branch_filter'] = $branchFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM payments p
     INNER JOIN orders o ON o.id = p.order_id
     LEFT JOIN customers c ON c.id = o.customer_id
     $whereSql"
);
$countStmt->execute($params);
$totalFiltered = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listSql = "SELECT
                p.id,
                p.order_id,
                p.payment_number,
                p.payment_date,
                p.amount,
                p.payment_method,
                p.status,
                p.received_by,
                o.order_number,
                o.grand_total AS order_grand_total,
                o.status AS order_status,
                c.name AS customer_name,
                c.customer_type,
                cb.code AS cabang_code,
                cb.name AS cabang_name,
                u.name AS receiver_name,
                COALESCE((
                    SELECT SUM(pp.amount)
                    FROM payments pp
                    WHERE pp.order_id = p.order_id
                      AND pp.status = 'paid'
                ), 0) AS paid_total
            FROM payments p
            INNER JOIN orders o ON o.id = p.order_id
            LEFT JOIN customers c ON c.id = o.customer_id
            LEFT JOIN cabangs cb ON cb.id = o.cabang_id
            LEFT JOIN users u ON u.id = p.received_by
            $whereSql
            ORDER BY p.id DESC
            LIMIT :limit OFFSET :offset";

$listStmt = $pdo->prepare($listSql);

foreach ($params as $key => $value) {
    $listStmt->bindValue(
        $key,
        $value,
        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}

$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$payments = $listStmt->fetchAll();

$branches = $pdo->query(
    "SELECT id, code, name
     FROM cabangs
     ORDER BY name ASC"
)->fetchAll();

$activeOrders = get_active_orders($pdo);

/* statistics */
$totalPayments = (int)$pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();

$totalPaid = (float)$pdo->query(
    "SELECT COALESCE(SUM(amount), 0)
     FROM payments
     WHERE status = 'paid'"
)->fetchColumn();

$totalPending = (float)$pdo->query(
    "SELECT COALESCE(SUM(amount), 0)
     FROM payments
     WHERE status = 'pending'"
)->fetchColumn();

$monthPaid = (float)$pdo->query(
    "SELECT COALESCE(SUM(amount), 0)
     FROM payments
     WHERE status = 'paid'
       AND YEAR(payment_date) = YEAR(CURDATE())
       AND MONTH(payment_date) = MONTH(CURDATE())"
)->fetchColumn();

$detailPayment = null;

if (isset($_GET['detail']) && ctype_digit((string)$_GET['detail'])) {
    $detailPayment = get_payment($pdo, (int)$_GET['detail']);
}

$addOrderId = isset($_GET['add_payment']) && ctype_digit((string)$_GET['add_payment'])
    ? (int)$_GET['add_payment']
    : 0;

$addOrderSummary = $addOrderId > 0
    ? get_order_payment_summary($pdo, $addOrderId)
    : null;

$hasFilters = ($search !== '' || $methodFilter !== '' || $statusFilter !== '' || $branchFilter > 0);
$from = $totalFiltered > 0 ? $offset + 1 : 0;
$to = min($offset + $perPage, $totalFiltered);

$successMessages = [
    'added' => 'Pembayaran berhasil disimpan.',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c1a2d">
    <title>Pembayaran | <?= h($companyName) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../../assets/css/admin.css?v=20260907">
    <link rel="stylesheet" href="style.css?v=20260913-payment-db">
</head>
<body class="payments-page">

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
            <a href="../orders/" class="menu-item"><i class="bi bi-cart3"></i><span>Penjualan</span></a>
            <a href="../purchases/" class="menu-item"><i class="bi bi-bag"></i><span>Pembelian</span></a>
            <a href="../stoks-transfer/" class="menu-item"><i class="bi bi-arrow-left-right"></i><span>Transfer Stok</span></a>
            <a href="./" class="menu-item active"><i class="bi bi-credit-card"></i><span>Pembayaran</span></a>
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

        <div class="page-header">
            <div>
                <div class="breadcrumb">
                    <span>Dashboard</span>
                    <i class="bi bi-chevron-right"></i>
                    <span>Transaksi</span>
                    <i class="bi bi-chevron-right"></i>
                    Pembayaran
                </div>
                <h1>Pembayaran</h1>
                <p>Kelola pembayaran dan riwayat transaksi pelanggan.</p>
            </div>

            <button class="btn-primary" type="button" onclick="openPaymentModal()">
                <i class="bi bi-plus-lg"></i>
                Tambah Pembayaran
            </button>
        </div>

        <?php if ($notify !== ''): ?>
            <div class="payment-alert <?= $notifyType === 'error' ? 'is-error' : 'is-success' ?>">
                <i class="bi <?= $notifyType === 'error' ? 'bi-exclamation-circle' : 'bi-check-circle' ?>"></i>
                <span><?= h($notify) ?></span>
            </div>
        <?php elseif (isset($_GET['success']) && isset($successMessages[$_GET['success']])): ?>
            <div class="payment-alert is-success">
                <i class="bi bi-check-circle"></i>
                <span><?= h($successMessages[$_GET['success']]) ?></span>
            </div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="bi bi-wallet2"></i></div>
                <div>
                    <span>Total Pembayaran</span>
                    <h2><?= number_format($totalPayments, 0, ',', '.') ?></h2>
                    <small>Seluruh record pembayaran</small>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green"><i class="bi bi-check-circle"></i></div>
                <div>
                    <span>Total Lunas</span>
                    <h2><?= h(money($totalPaid)) ?></h2>
                    <small>Total status paid</small>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon orange"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <span>Menunggu Konfirmasi</span>
                    <h2><?= h(money($totalPending)) ?></h2>
                    <small>Total status pending</small>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon purple"><i class="bi bi-calendar-check"></i></div>
                <div>
                    <span>Pemasukan Bulan Ini</span>
                    <h2><?= h(money($monthPaid)) ?></h2>
                    <small>Hanya status paid</small>
                </div>
            </div>
        </section>

        <section class="table-card">
            <div class="table-header">
                <div>
                    <h3>Riwayat Pembayaran</h3>
                    <p>Daftar pembayaran yang tercatat terhadap transaksi penjualan.</p>
                </div>

                <a
                    href="export.php?<?= h(http_build_query([
                        'search' => $search,
                        'method' => $methodFilter,
                        'status' => $statusFilter,
                        'branch' => $branchFilter,
                    ])) ?>"
                    class="btn-outline"
                >
                    <i class="bi bi-download"></i>
                    Export
                </a>
            </div>

            <form method="get" class="filter-card">
                <div class="searc-box">
                    <i class="bi bi-search"></i>
                    <input
                        type="text"
                        name="search"
                        value="<?= h($search) ?>"
                        placeholder="Cari nomor pembayaran, pesanan, pelanggan..."
                    >
                </div>

                <select name="method">
                    <option value="">Semua Metode</option>
                    <?php foreach ($paymentMethods as $method): ?>
                        <option value="<?= h($method) ?>" <?= $methodFilter === $method ? 'selected' : '' ?>>
                            <?= h(payment_method_label($method)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status">
                    <option value="">Semua Status</option>
                    <?php foreach ($paymentStatuses as $status): ?>
                        <option value="<?= h($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                            <?= h(payment_status_label($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="branch">
                    <option value="0">Semua Cabang</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int)$branch['id'] ?>" <?= $branchFilter === (int)$branch['id'] ? 'selected' : '' ?>>
                            <?= h($branch['code'] . ' - ' . $branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn-search">
                    <i class="bi bi-search"></i>
                    Cari
                </button>

                <a
                    href="<?= $hasFilters ? 'index.php' : '#' ?>"
                    class="btn-reset <?= $hasFilters ? '' : 'disabled' ?>"
                    <?= $hasFilters ? '' : 'aria-disabled="true" tabindex="-1"' ?>
                >
                    <i class="bi bi-arrow-counterclockwise"></i>
                    Reset
                </a>
            </form>

            <div class="table-responsive">
                <table class="payment-table">
                    <thead>
                    <tr>
                        <th>No Pembayaran</th>
                        <th>No Pesanan</th>
                        <th>Pelanggan</th>
                        <th>Total Pesanan</th>
                        <th>Bayar</th>
                        <th>Metode</th>
                        <th>Status</th>
                        <th>Sisa Tagihan</th>
                        <th>Cabang</th>
                        <th>Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$payments): ?>
                        <tr>
                            <td colspan="10">
                                <div class="payment-empty">
                                    <i class="bi bi-credit-card-2-front"></i>
                                    <strong>Data pembayaran tidak ditemukan</strong>
                                    <span>Belum ada data yang sesuai dengan pencarian atau filter.</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
                            <?php
                                $paidTotal = (float)$payment['paid_total'];
                                $remaining = max(0, (float)$payment['order_grand_total'] - $paidTotal);
                            ?>
                            <tr>
                                <td>
                                    <span class="payment-code"><?= h($payment['payment_number']) ?></span>
                                    <small><?= h(date('d M Y', strtotime($payment['payment_date']))) ?></small>
                                </td>
                                <td>
                                    <strong><?= h($payment['order_number']) ?></strong>
                                </td>
                                <td>
                                    <div class="customer">
                                        <div class="avatar"><?= h(strtoupper(substr(trim($payment['customer_name'] ?: '-'), 0, 2))) ?></div>
                                        <div>
                                            <strong><?= h($payment['customer_name'] ?: '-') ?></strong>
                                            <small><?= h($payment['customer_type'] ?: '-') ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><?= h(money((float)$payment['order_grand_total'])) ?></td>
                                <td><strong class="amount-paid"><?= h(money((float)$payment['amount'])) ?></strong></td>
                                <td>
                                    <span class="method">
                                        <i class="bi <?= h(payment_method_icon((string)$payment['payment_method'])) ?>"></i>
                                        <?= h(payment_method_label((string)$payment['payment_method'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= h(payment_status_class((string)$payment['status'])) ?>">
                                        <?= h(payment_status_label((string)$payment['status'])) ?>
                                    </span>
                                </td>
                                <td><?= h(money($remaining)) ?></td>
                                <td><?= h(($payment['cabang_code'] ? $payment['cabang_code'] . ' - ' : '') . ($payment['cabang_name'] ?: '-')) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a
                                            href="<?= h(build_page_url($page, $search, $methodFilter, $statusFilter, $branchFilter, ['detail' => (int)$payment['id']])) ?>"
                                            title="Detail"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a
                                            href="print.php?id=<?= (int)$payment['id'] ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            title="Cetak"
                                            class="print-action"
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

            <div class="pagination">
                <span class="pagination-info">
                    Menampilkan <strong><?= $from ?></strong>–<strong><?= $to ?></strong>
                    dari <strong><?= $totalFiltered ?></strong> pembayaran
                </span>

                <div class="pagination-nav">
                        <a
                            href="<?= $page > 1 ? h(build_page_url($page - 1, $search, $methodFilter, $statusFilter, $branchFilter)) : '#' ?>"
                            class="<?= $page <= 1 ? 'disabled' : '' ?>"
                        >
                            <i class="bi bi-chevron-left"></i>
                        </a>

                        <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                        ?>

                        <?php if ($startPage > 1): ?>
                            <a href="<?= h(build_page_url(1, $search, $methodFilter, $statusFilter, $branchFilter)) ?>">1</a>
                            <?php if ($startPage > 2): ?><span>...</span><?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <a
                                href="<?= h(build_page_url($p, $search, $methodFilter, $statusFilter, $branchFilter)) ?>"
                                class="<?= $p === $page ? 'active' : '' ?>"
                            >
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?><span>...</span><?php endif; ?>
                            <a href="<?= h(build_page_url($totalPages, $search, $methodFilter, $statusFilter, $branchFilter)) ?>">
                                <?= $totalPages ?>
                            </a>
                        <?php endif; ?>

                        <a
                            href="<?= $page < $totalPages ? h(build_page_url($page + 1, $search, $methodFilter, $statusFilter, $branchFilter)) : '#' ?>"
                            class="<?= $page >= $totalPages ? 'disabled' : '' ?>"
                        >
                            <i class="bi bi-chevron-right"></i>
                        </a>
                </div>
            </div>
        </section>

    </div>
</main>

<!-- ADD PAYMENT MODAL -->
<div
    class="modal-overlay <?= $addOrderSummary ? 'show' : '' ?>"
    id="paymentModal"
>
    <div class="modal modal-large">

        <div class="modal-header">
            <div>
                <h2>Tambah Pembayaran</h2>
                <p>Catat pembayaran pelanggan untuk satu transaksi penjualan.</p>
            </div>
            <button type="button" class="modal-close" onclick="closePaymentModal()" title="Tutup">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form method="post" id="paymentForm">
            <input type="hidden" name="action" value="create_payment">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-group">
                        <label>No Pembayaran</label>
                        <input type="text" value="<?= h(generate_payment_number($pdo)) ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Tanggal Pembayaran <span>*</span></label>
                        <input type="date" name="payment_date" value="<?= h(date('Y-m-d')) ?>" required>
                    </div>

                    <div class="form-group full">
                        <label>No Pesanan <span>*</span></label>
                        <select name="order_id" id="orderId" required>
                            <option value="">Pilih pesanan</option>
                            <?php foreach ($activeOrders as $order): ?>
                                <?php
                                    $paid = (float)$order['paid_total'];
                                    $remaining = max(0, (float)$order['grand_total'] - $paid);
                                ?>
                                <?php if ($remaining > 0.000001): ?>
                                    <option
                                        value="<?= (int)$order['id'] ?>"
                                        data-total="<?= h((float)$order['grand_total']) ?>"
                                        data-paid="<?= h($paid) ?>"
                                        data-remaining="<?= h($remaining) ?>"
                                        data-customer="<?= h($order['customer_name'] ?: '-') ?>"
                                        <?= $addOrderSummary && (int)$addOrderSummary['id'] === (int)$order['id'] ? 'selected' : '' ?>
                                    >
                                        <?= h($order['order_number'] . ' - ' . ($order['customer_name'] ?: '-')) ?>
                                        — Sisa <?= h(money($remaining)) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-help">Hanya pesanan yang masih memiliki sisa tagihan yang dapat dipilih.</small>
                    </div>

                    <div class="order-summary full" id="orderSummary">
                        <?php
                            $summaryTotal = $addOrderSummary['grand_total'] ?? 0;
                            $summaryPaid = $addOrderSummary['paid_total'] ?? 0;
                            $summaryRemaining = $addOrderSummary['remaining_total'] ?? 0;
                        ?>
                        <div>
                            <span>Total Pesanan</span>
                            <strong id="summaryTotal"><?= h(money((float)$summaryTotal)) ?></strong>
                        </div>
                        <div>
                            <span>Sudah Dibayar</span>
                            <strong id="summaryPaid"><?= h(money((float)$summaryPaid)) ?></strong>
                        </div>
                        <div>
                            <span>Sisa Tagihan</span>
                            <strong class="danger-text" id="summaryRemaining"><?= h(money((float)$summaryRemaining)) ?></strong>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Jumlah Pembayaran <span>*</span></label>
                        <input
                            type="number"
                            name="amount"
                            id="paymentAmount"
                            min="0.01"
                            step="0.01"
                            placeholder="Contoh: 1000000"
                            required
                        >
                        <small class="form-help" id="amountHelp">Jumlah tidak boleh melebihi sisa tagihan.</small>
                    </div>

                    <div class="form-group">
                        <label>Metode Pembayaran <span>*</span></label>
                        <select name="payment_method" required>
                            <option value="">Pilih metode</option>
                            <?php foreach ($paymentMethods as $method): ?>
                                <option value="<?= h($method) ?>">
                                    <?= h(payment_method_label($method)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Status <span>*</span></label>
                        <select name="status" required>
                            <?php foreach ($paymentStatuses as $status): ?>
                                <option value="<?= h($status) ?>" <?= $status === 'paid' ? 'selected' : '' ?>>
                                    <?= h(payment_status_label($status)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>
            </div>

            <div class="modal-footer">
                <a
                    href="index.php<?= $hasFilters ? '?' . h(http_build_query([
                        'search' => $search,
                        'method' => $methodFilter,
                        'status' => $statusFilter,
                        'branch' => $branchFilter,
                    ])) : '' ?>"
                    class="btn-cancel"
                >
                    Batal
                </a>

                <button type="submit" class="btn-primary">
                    <i class="bi bi-check-lg"></i>
                    Simpan Pembayaran
                </button>
            </div>
        </form>
    </div>
</div>

<!-- DETAIL MODAL -->
<?php if ($detailPayment): ?>
    <div class="modal-overlay show" id="detailModal">
        <div class="modal">
            <div class="modal-header">
                <div>
                    <h2>Detail Pembayaran</h2>
                    <p><?= h($detailPayment['payment_number']) ?></p>
                </div>
                <a
                    class="modal-close modal-close-link"
                    href="<?= h(build_page_url($page, $search, $methodFilter, $statusFilter, $branchFilter)) ?>"
                    title="Tutup"
                >
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>

            <div class="modal-body">
                <?php
                    $detailSummary = get_order_payment_summary(
                        $pdo,
                        (int)$detailPayment['order_id']
                    );
                    $detailPaidTotal = $detailSummary['paid_total'] ?? 0;
                    $detailRemaining = $detailSummary['remaining_total'] ?? 0;
                ?>

                <div class="detail-grid">
                    <div>
                        <span>No Pembayaran</span>
                        <strong><?= h($detailPayment['payment_number']) ?></strong>
                    </div>
                    <div>
                        <span>Tanggal</span>
                        <strong><?= h(date('d M Y', strtotime($detailPayment['payment_date']))) ?></strong>
                    </div>
                    <div>
                        <span>No Pesanan</span>
                        <strong><?= h($detailPayment['order_number']) ?></strong>
                    </div>
                    <div>
                        <span>Pelanggan</span>
                        <strong><?= h($detailPayment['customer_name'] ?: '-') ?></strong>
                    </div>
                    <div>
                        <span>Cabang</span>
                        <strong><?= h(($detailPayment['cabang_code'] ? $detailPayment['cabang_code'] . ' - ' : '') . ($detailPayment['cabang_name'] ?: '-')) ?></strong>
                    </div>
                    <div>
                        <span>Diterima Oleh</span>
                        <strong><?= h($detailPayment['receiver_name'] ?: '-') ?></strong>
                    </div>
                    <div>
                        <span>Metode</span>
                        <strong><?= h(payment_method_label((string)$detailPayment['payment_method'])) ?></strong>
                    </div>
                    <div>
                        <span>Status</span>
                        <strong><?= h(payment_status_label((string)$detailPayment['status'])) ?></strong>
                    </div>
                </div>

                <div class="detail-summary">
                    <div>
                        <span>Total Pesanan</span>
                        <strong><?= h(money((float)$detailPayment['order_grand_total'])) ?></strong>
                    </div>
                    <div>
                        <span>Total Dibayar (Paid)</span>
                        <strong><?= h(money((float)$detailPaidTotal)) ?></strong>
                    </div>
                    <div>
                        <span>Sisa Tagihan</span>
                        <strong class="<?= $detailRemaining > 0 ? 'danger-text' : 'success-text' ?>">
                            <?= h(money((float)$detailRemaining)) ?>
                        </strong>
                    </div>
                    <div>
                        <span>Nominal Pembayaran Ini</span>
                        <strong><?= h(money((float)$detailPayment['amount'])) ?></strong>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <a
                    href="print.php?id=<?= (int)$detailPayment['id'] ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="btn-primary"
                >
                    <i class="bi bi-printer"></i>
                    Cetak Bukti
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
function openPaymentModal() {
    const modal = document.getElementById('paymentModal');
    if (modal) {
        modal.classList.add('show');
        updateOrderSummary();
    }
}

function closePaymentModal() {
    const modal = document.getElementById('paymentModal');
    if (!modal) return;

    const url = new URL(window.location.href);
    url.searchParams.delete('add_payment');

    window.location.href = url.toString();
}

function formatRupiah(value) {
    const number = Number(value || 0);
    return 'Rp' + number.toLocaleString('id-ID');
}

function updateOrderSummary() {
    const select = document.getElementById('orderId');
    if (!select) return;

    const option = select.options[select.selectedIndex];

    const total = Number(option?.dataset.total || 0);
    const paid = Number(option?.dataset.paid || 0);
    const remaining = Number(option?.dataset.remaining || 0);

    document.getElementById('summaryTotal').textContent = formatRupiah(total);
    document.getElementById('summaryPaid').textContent = formatRupiah(paid);
    document.getElementById('summaryRemaining').textContent = formatRupiah(remaining);

    const amount = document.getElementById('paymentAmount');
    if (amount) {
        amount.max = remaining > 0 ? remaining : 0;
        if (Number(amount.value) > remaining) {
            amount.value = '';
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('orderId');
    if (select) {
        select.addEventListener('change', updateOrderSummary);
        updateOrderSummary();
    }

    const amount = document.getElementById('paymentAmount');
    const form = document.getElementById('paymentForm');

    if (amount && form) {
        form.addEventListener('submit', function (event) {
            const option = select?.options[select.selectedIndex];
            const remaining = Number(option?.dataset.remaining || 0);
            const value = Number(amount.value || 0);

            if (value <= 0 || value > remaining + 0.000001) {
                event.preventDefault();
                alert('Jumlah pembayaran tidak valid atau melebihi sisa tagihan.');
            }
        });
    }

    document.querySelectorAll('.modal-overlay').forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target !== modal) return;

            if (modal.id === 'detailModal') {
                window.location.href = 'index.php';
            } else {
                closePaymentModal();
            }
        });
    });
});
</script>

</body>
</html>
