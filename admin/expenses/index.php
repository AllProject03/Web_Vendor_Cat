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


function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string {
    return 'Rp ' . number_format($value, 0, ',', '.');
}

function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
}

function current_user_context(PDO $pdo): array {
    static $context = null;
    if ($context !== null) {
        return $context;
    }

    $userId = current_user_id();
    if ($userId <= 0) {
        return $context = ['id' => 0, 'role_id' => 0, 'role_code' => '', 'cabang_id' => 0];
    }

    $stmt = $pdo->prepare(
        "SELECT u.id, u.role_id, u.cabang_id, r.code AS role_code
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch() ?: [];

    return $context = [
        'id' => (int)($row['id'] ?? 0),
        'role_id' => (int)($row['role_id'] ?? 0),
        'role_code' => (string)($row['role_code'] ?? ''),
        'cabang_id' => (int)($row['cabang_id'] ?? 0),
    ];
}

function user_has_permission(PDO $pdo, string $permissionCode): bool {
    static $cache = [];
    if (array_key_exists($permissionCode, $cache)) {
        return $cache[$permissionCode];
    }

    $ctx = current_user_context($pdo);
    if (($ctx['id'] ?? 0) <= 0) {
        return $cache[$permissionCode] = false;
    }
    if (($ctx['role_code'] ?? '') === 'ADMIN') {
        return $cache[$permissionCode] = true;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM role_permissions rp
         INNER JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = :role_id
           AND p.code = :permission_code"
    );
    $stmt->execute([
        ':role_id' => $ctx['role_id'],
        ':permission_code' => $permissionCode,
    ]);

    return $cache[$permissionCode] = ((int)$stmt->fetchColumn() > 0);
}

function user_can_approve_expense(PDO $pdo, int $expenseBranchId): bool {
    if (!user_has_permission($pdo, 'expenses.approve')) {
        return false;
    }

    $ctx = current_user_context($pdo);
    if (($ctx['role_code'] ?? '') === 'ADMIN') {
        return true;
    }
    if (($ctx['role_code'] ?? '') === 'ADMIN_CABANG') {
        return $expenseBranchId > 0 && (int)$ctx['cabang_id'] === $expenseBranchId;
    }
    return true;
}

function user_can_cancel_expense(PDO $pdo, int $expenseBranchId): bool {
    if (!user_has_permission($pdo, 'expenses.cancel')) {
        return false;
    }

    $ctx = current_user_context($pdo);
    if (($ctx['role_code'] ?? '') === 'ADMIN') {
        return true;
    }
    if (($ctx['role_code'] ?? '') === 'ADMIN_CABANG') {
        return $expenseBranchId > 0 && (int)$ctx['cabang_id'] === $expenseBranchId;
    }
    return true;
}

function redirect_expenses(array $params = []): never {
    $url = 'index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

function get_enum_values(PDO $pdo, string $table, string $column): array {
    $allowed = [
        'expenses' => ['status'],
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
            static fn($value) => str_replace(["\\'", "\\\\"], ["'", "\\"], trim((string)$value)),
            $values
        ),
        static fn($value) => $value !== ''
    ));
}

function status_label(string $status): string {
    return match ($status) {
        'draft' => 'Draft',
        'submitted' => 'Menunggu Persetujuan',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        'paid' => 'Dibayar',
        'cancelled', 'canceled' => 'Dibatalkan',
        default => ucwords(str_replace(['_', '-'], ' ', $status)),
    };
}

function status_class(string $status): string {
    return match ($status) {
        'draft' => 'draft',
        'submitted' => 'submitted',
        'approved' => 'approved',
        'rejected' => 'rejected',
        'paid' => 'paid',
        'cancelled', 'canceled' => 'cancelled',
        default => 'draft',
    };
}

function generate_expense_number(PDO $pdo): string {
    $year = date('Y');
    $stmt = $pdo->prepare(
        "SELECT expense_number
         FROM expenses
         WHERE expense_number LIKE :prefix
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([':prefix' => 'EXP-' . $year . '-%']);
    $last = $stmt->fetchColumn();

    $seq = 1;
    if (is_string($last) && preg_match('/(\d+)$/', $last, $m)) {
        $seq = (int)$m[1] + 1;
    }

    return sprintf('EXP-%s-%04d', $year, $seq);
}

function get_expense(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        "SELECT
            e.*,
            c.code AS cabang_code,
            c.name AS cabang_name,
            u.name AS creator_name,
            au.name AS approver_name
         FROM expenses e
         LEFT JOIN cabangs c ON c.id = e.cabang_id
         LEFT JOIN users u ON u.id = e.created_by
         LEFT JOIN users au ON au.id = e.approved_by
         WHERE e.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function fetch_active_branches(PDO $pdo): array {
    return $pdo->query(
        "SELECT id, code, name, status
         FROM cabangs
         WHERE status = 'active'
         ORDER BY name ASC"
    )->fetchAll();
}

function build_page_url(
    int $page,
    string $search,
    int $branch,
    string $status,
    array $extra = []
): string {
    $params = ['page' => $page];
    if ($search !== '') $params['search'] = $search;
    if ($branch > 0) $params['branch'] = $branch;
    if ($status !== '') $params['status'] = $status;

    foreach ($extra as $key => $value) {
        $params[$key] = $value;
    }

    return 'index.php?' . http_build_query($params);
}

if (empty($_SESSION['csrf_expenses'])) {
    $_SESSION['csrf_expenses'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_expenses'];

$expenseStatuses = get_enum_values($pdo, 'expenses', 'status');
if (!$expenseStatuses) {
    $expenseStatuses = ['draft', 'submitted', 'approved', 'rejected', 'paid', 'cancelled'];
}

$defaultStatus = in_array('draft', $expenseStatuses, true)
    ? 'draft'
    : $expenseStatuses[0];

$statusDraft = in_array('draft', $expenseStatuses, true) ? 'draft' : null;
$statusSubmitted = in_array('submitted', $expenseStatuses, true) ? 'submitted' : null;
$statusApproved = in_array('approved', $expenseStatuses, true) ? 'approved' : null;
$statusRejected = in_array('rejected', $expenseStatuses, true) ? 'rejected' : null;
$statusPaid = in_array('paid', $expenseStatuses, true) ? 'paid' : null;
$statusCancelled = in_array('cancelled', $expenseStatuses, true)
    ? 'cancelled'
    : (in_array('canceled', $expenseStatuses, true) ? 'canceled' : null);

$userContext = current_user_context($pdo);
$canView = user_has_permission($pdo, 'expenses.view');
$canCreate = user_has_permission($pdo, 'expenses.create');
$canEdit = user_has_permission($pdo, 'expenses.edit');
$canSubmit = $canCreate;
$canPay = user_has_permission($pdo, 'expenses.pay');

if (!$canView) {
    http_response_code(403);
    exit('Anda tidak memiliki hak akses untuk menu Pengeluaran.');
}

$categories = [
    'Pembelian Barang',
    'Operasional',
    'Transportasi',
    'Gaji',
    'Utilitas',
    'Sewa',
    'Pemeliharaan',
    'Lainnya',
];

$notify = '';
$notifyType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_expenses'] ?? '', $token)) {
        $notify = 'Permintaan tidak valid (CSRF).';
        $notifyType = 'error';
    } else {
        try {
            $userId = current_user_id();
            if ($userId <= 0) {
                throw new Exception('User login tidak ditemukan pada session.');
            }

            if ($action === 'add_expense' || $action === 'update_expense') {
                $expenseId = (int)($_POST['expense_id'] ?? 0);
                $expenseDate = trim($_POST['expense_date'] ?? '');
                $cabangId = (int)($_POST['cabang_id'] ?? 0);
                $category = trim($_POST['category'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $amount = max(0, (float)($_POST['amount'] ?? 0));
                $postedStatus = trim($_POST['status'] ?? '');
                $status = $defaultStatus;

                $date = DateTime::createFromFormat('Y-m-d', $expenseDate);
                if (!$date || $date->format('Y-m-d') !== $expenseDate) {
                    throw new Exception('Tanggal pengeluaran tidak valid.');
                }
                if ($cabangId <= 0) {
                    throw new Exception('Cabang wajib dipilih.');
                }
                if ($category === '') {
                    throw new Exception('Kategori wajib diisi.');
                }
                if ($description === '') {
                    throw new Exception('Keterangan wajib diisi.');
                }
                if ($amount <= 0) {
                    throw new Exception('Jumlah pengeluaran harus lebih besar dari 0.');
                }
                $isUpdate = $action === 'update_expense';
                if ($isUpdate) {
                    if (!$canEdit) {
                        throw new Exception('Anda tidak memiliki hak untuk mengubah pengeluaran.');
                    }
                } elseif (!$canCreate) {
                    throw new Exception('Anda tidak memiliki hak untuk membuat pengeluaran.');
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

                $existing = null;

                if ($isUpdate) {
                    if ($expenseId <= 0) {
                        throw new Exception('ID pengeluaran tidak valid.');
                    }

                    $existing = get_expense($pdo, $expenseId);
                    if (!$existing) {
                        throw new Exception('Data pengeluaran tidak ditemukan.');
                    }

                    $editableStatuses = array_values(array_filter([
                        $statusDraft,
                        $statusRejected,
                    ]));
                    if (!in_array((string)$existing['status'], $editableStatuses, true)) {
                        throw new Exception('Pengeluaran yang sudah diajukan, disetujui, dibayar, atau dibatalkan tidak dapat diedit.');
                    }

                    if ($statusDraft) {
                        $status = $statusDraft;
                    }
                }

                $pdo->beginTransaction();

                if ($isUpdate) {
                    $stmt = $pdo->prepare(
                        "UPDATE expenses
                         SET cabang_id = :cabang_id,
                             expense_date = :expense_date,
                             category = :category,
                             description = :description,
                             amount = :amount,
                             status = :status,
                             approved_by = NULL
                         WHERE id = :id"
                    );
                    $stmt->execute([
                        ':cabang_id' => $cabangId,
                        ':expense_date' => $expenseDate,
                        ':category' => $category,
                        ':description' => $description,
                        ':amount' => $amount,
                        ':status' => $status,
                        ':id' => $expenseId,
                    ]);
                } else {
                    $expenseNumber = generate_expense_number($pdo);
                    $stmt = $pdo->prepare(
                        "INSERT INTO expenses
                            (cabang_id, expense_number, expense_date, category, description, amount, status, created_by, approved_by)
                         VALUES
                            (:cabang_id, :expense_number, :expense_date, :category, :description, :amount, :status, :created_by, NULL)"
                    );
                    $stmt->execute([
                        ':cabang_id' => $cabangId,
                        ':expense_number' => $expenseNumber,
                        ':expense_date' => $expenseDate,
                        ':category' => $category,
                        ':description' => $description,
                        ':amount' => $amount,
                        ':status' => $status,
                        ':created_by' => $userId,
                    ]);
                }

                $pdo->commit();
                redirect_expenses(['success' => $isUpdate ? 'updated' : 'added']);
            }

            if (in_array($action, ['submit_expense', 'approve_expense', 'reject_expense', 'pay_expense', 'cancel_expense'], true)) {
                $expenseId = (int)($_POST['expense_id'] ?? 0);
                if ($expenseId <= 0) {
                    throw new Exception('ID pengeluaran tidak valid.');
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = :id LIMIT 1 FOR UPDATE");
                $stmt->execute([':id' => $expenseId]);
                $expense = $stmt->fetch();

                if (!$expense) {
                    throw new Exception('Data pengeluaran tidak ditemukan.');
                }

                $currentStatus = (string)$expense['status'];
                $expenseBranchId = (int)$expense['cabang_id'];

                if ($action === 'submit_expense') {
                    if (!$canSubmit) {
                        throw new Exception('Anda tidak memiliki hak untuk mengajukan pengeluaran.');
                    }
                    if (!$statusSubmitted || !$statusDraft || $currentStatus !== $statusDraft) {
                        throw new Exception('Pengeluaran hanya dapat diajukan dari status Draft.');
                    }
                    $update = $pdo->prepare(
                        "UPDATE expenses
                         SET status = :status
                         WHERE id = :id"
                    );
                    $update->execute([
                        ':status' => $statusSubmitted,
                        ':id' => $expenseId,
                    ]);
                    $successKey = 'submitted';
                } elseif ($action === 'approve_expense') {
                    if (!user_can_approve_expense($pdo, $expenseBranchId)) {
                        throw new Exception('Anda tidak memiliki hak untuk menyetujui pengeluaran ini.');
                    }
                    if (!$statusApproved || !$statusSubmitted || $currentStatus !== $statusSubmitted) {
                        throw new Exception('Pengeluaran harus berstatus Menunggu Persetujuan sebelum disetujui.');
                    }
                    $update = $pdo->prepare(
                        "UPDATE expenses
                         SET status = :status,
                             approved_by = :approved_by
                         WHERE id = :id"
                    );
                    $update->execute([
                        ':status' => $statusApproved,
                        ':approved_by' => $userId,
                        ':id' => $expenseId,
                    ]);
                    $successKey = 'approved';
                } elseif ($action === 'reject_expense') {
                    if (!user_can_approve_expense($pdo, $expenseBranchId)) {
                        throw new Exception('Anda tidak memiliki hak untuk menolak pengeluaran ini.');
                    }
                    if (!$statusRejected || !$statusSubmitted || $currentStatus !== $statusSubmitted) {
                        throw new Exception('Hanya pengeluaran yang menunggu persetujuan yang dapat ditolak.');
                    }
                    $update = $pdo->prepare(
                        "UPDATE expenses
                         SET status = :status,
                             approved_by = NULL
                         WHERE id = :id"
                    );
                    $update->execute([
                        ':status' => $statusRejected,
                        ':id' => $expenseId,
                    ]);
                    $successKey = 'rejected';
                } elseif ($action === 'pay_expense') {
                    if (!$canPay) {
                        throw new Exception('Anda tidak memiliki hak untuk menandai pengeluaran sebagai Dibayar.');
                    }
                    if (!$statusPaid || !$statusApproved || $currentStatus !== $statusApproved) {
                        throw new Exception('Pengeluaran harus berstatus Disetujui sebelum ditandai Dibayar.');
                    }
                    $update = $pdo->prepare(
                        "UPDATE expenses
                         SET status = :status
                         WHERE id = :id"
                    );
                    $update->execute([
                        ':status' => $statusPaid,
                        ':id' => $expenseId,
                    ]);
                    $successKey = 'paid';
                } elseif ($action === 'cancel_expense') {
                    if (!user_can_cancel_expense($pdo, $expenseBranchId)) {
                        throw new Exception('Anda tidak memiliki hak untuk membatalkan pengeluaran ini.');
                    }
                    if (!$statusCancelled || !in_array($currentStatus, array_values(array_filter([
                        $statusDraft,
                        $statusSubmitted,
                        $statusApproved,
                    ])), true)) {
                        throw new Exception('Pengeluaran pada status ini tidak dapat dibatalkan.');
                    }
                    $update = $pdo->prepare(
                        "UPDATE expenses
                         SET status = :status
                         WHERE id = :id"
                    );
                    $update->execute([
                        ':status' => $statusCancelled,
                        ':id' => $expenseId,
                    ]);
                    $successKey = 'cancelled';
                } else {
                    $successKey = 'unknown';
                }

                $pdo->commit();
                redirect_expenses(['success' => $successKey]);
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

$search = trim($_GET['search'] ?? '');
$branchFilter = (int)($_GET['branch'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        e.expense_number LIKE :search_number
        OR e.category LIKE :search_category
        OR e.description LIKE :search_description
        OR c.name LIKE :search_branch
        OR c.code LIKE :search_branch_code
    )";
    $like = '%' . $search . '%';
    $params[':search_number'] = $like;
    $params[':search_category'] = $like;
    $params[':search_description'] = $like;
    $params[':search_branch'] = $like;
    $params[':search_branch_code'] = $like;
}

if ($branchFilter > 0) {
    $where[] = 'e.cabang_id = :branch_filter';
    $params[':branch_filter'] = $branchFilter;
}

if ($statusFilter !== '' && in_array($statusFilter, $expenseStatuses, true)) {
    $where[] = 'e.status = :status_filter';
    $params[':status_filter'] = $statusFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM expenses e
     LEFT JOIN cabangs c ON c.id = e.cabang_id
     $whereSql"
);
$countStmt->execute($params);
$totalFiltered = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT
        e.*,
        c.code AS cabang_code,
        c.name AS cabang_name,
        u.name AS creator_name,
        au.name AS approver_name
     FROM expenses e
     LEFT JOIN cabangs c ON c.id = e.cabang_id
     LEFT JOIN users u ON u.id = e.created_by
     LEFT JOIN users au ON au.id = e.approved_by
     $whereSql
     ORDER BY e.id DESC
     LIMIT :limit OFFSET :offset"
);

foreach ($params as $key => $value) {
    $listStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$expenses = $listStmt->fetchAll();

$branches = fetch_active_branches($pdo);

$totalExpenses = (int)$pdo->query("SELECT COUNT(*) FROM expenses")->fetchColumn();
$paidTotal = $statusPaid
    ? (float)$pdo->query(
        "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status = " . $pdo->quote($statusPaid)
      )->fetchColumn()
    : 0;

$pendingCount = $statusSubmitted
    ? (int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE status = " . $pdo->quote($statusSubmitted))->fetchColumn()
    : 0;

$monthExpenseTotal = 0;
$recognizedStatuses = array_values(array_filter([$statusApproved, $statusPaid]));
if ($recognizedStatuses) {
    $statusPlaceholders = [];
    $statusParams = [];
    foreach ($recognizedStatuses as $i => $recognizedStatus) {
        $key = ':recognized_status_' . $i;
        $statusPlaceholders[] = $key;
        $statusParams[$key] = $recognizedStatus;
    }

    $monthStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount),0)
         FROM expenses
         WHERE status IN (" . implode(',', $statusPlaceholders) . ")
           AND expense_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
           AND expense_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)"
    );
    foreach ($statusParams as $key => $value) {
        $monthStmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $monthStmt->execute();
    $monthExpenseTotal = (float)$monthStmt->fetchColumn();
}

$detailExpense = null;
$editExpense = null;

if (isset($_GET['detail']) && ctype_digit((string)$_GET['detail'])) {
    $detailExpense = get_expense($pdo, (int)$_GET['detail']);
}

if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $candidate = get_expense($pdo, (int)$_GET['edit']);
    if ($candidate) {
        $editableStatuses = array_values(array_filter([$statusDraft, $statusRejected]));
        if (in_array((string)$candidate['status'], $editableStatuses, true)) {
            $editExpense = $candidate;
        } else {
            $notify = 'Pengeluaran ini sudah tidak dapat diedit.';
            $notifyType = 'error';
        }
    }
}

$hasFilters = ($search !== '' || $branchFilter > 0 || $statusFilter !== '');
$fromRow = $totalFiltered > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalFiltered);

$successMessages = [
    'added' => 'Pengeluaran berhasil dibuat.',
    'updated' => 'Pengeluaran berhasil diperbarui.',
    'submitted' => 'Pengeluaran berhasil diajukan untuk persetujuan.',
    'approved' => 'Pengeluaran berhasil disetujui.',
    'rejected' => 'Pengeluaran berhasil ditolak.',
    'paid' => 'Pengeluaran berhasil ditandai sebagai Dibayar.',
    'cancelled' => 'Pengeluaran berhasil dibatalkan.',
];

if (($_GET['export'] ?? '') === 'csv') {
    $exportStmt = $pdo->prepare(
        "SELECT
            e.expense_number,
            e.expense_date,
            e.category,
            e.description,
            e.amount,
            e.status,
            c.code AS cabang_code,
            c.name AS cabang_name,
            u.name AS creator_name,
            au.name AS approver_name
         FROM expenses e
         LEFT JOIN cabangs c ON c.id = e.cabang_id
         LEFT JOIN users u ON u.id = e.created_by
         LEFT JOIN users au ON au.id = e.approved_by
         $whereSql
         ORDER BY e.id DESC"
    );
    $exportStmt->execute($params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pengeluaran_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'No Pengeluaran',
        'Tanggal',
        'Cabang',
        'Kategori',
        'Keterangan',
        'Jumlah',
        'Status',
        'Dibuat Oleh',
        'Disetujui Oleh',
    ]);

    foreach ($exportStmt->fetchAll() as $row) {
        fputcsv($out, [
            $row['expense_number'],
            $row['expense_date'],
            trim(($row['cabang_code'] ? $row['cabang_code'] . ' - ' : '') . ($row['cabang_name'] ?? '')),
            $row['category'],
            $row['description'],
            $row['amount'],
            status_label((string)$row['status']),
            $row['creator_name'] ?? '-',
            $row['approver_name'] ?? '-',
        ]);
    }

    fclose($out);
    exit;
}

function category_class(string $category): string {
    return match (mb_strtolower($category)) {
        'pembelian barang' => 'blue',
        'operasional' => 'orange',
        'transportasi' => 'purple',
        'gaji' => 'green',
        'utilitas' => 'teal',
        'sewa', 'pemeliharaan' => 'gray',
        default => 'red',
    };
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengeluaran | <?= h($companyName) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../../assets/css/admin.css?v=20260907">
    <link rel="stylesheet" href="style.css?v=20260913-expenses">
</head>
<body class="expenses-page">

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
            <a href="./" class="menu-item active"><i class="bi bi-receipt"></i><span>Pengeluaran</span></a>
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
                <strong>Pengeluaran</strong>
            </div>
            <h1>Pengeluaran</h1>
            <p>Kelola dan catat seluruh transaksi pengeluaran perusahaan.</p>
        </div>
        <button class="btn-primary" type="button" onclick="openExpenseModal()">
            <i class="bi bi-plus-lg"></i>
            Tambah Pengeluaran
        </button>
    </div>

    <?php if ($notify !== ''): ?>
        <div class="expense-alert is-error">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= h($notify) ?></span>
        </div>
    <?php elseif (isset($_GET['success'], $successMessages[$_GET['success']])): ?>
        <div class="expense-alert is-success">
            <i class="bi bi-check-circle"></i>
            <span><?= h($successMessages[$_GET['success']]) ?></span>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bi bi-receipt"></i></div>
            <div>
                <span>Total Pengeluaran</span>
                <h2><?= number_format($totalExpenses, 0, ',', '.') ?></h2>
                <small>Seluruh transaksi tercatat</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon red"><i class="bi bi-arrow-down-circle"></i></div>
            <div>
                <span>Pengeluaran Bulan Ini</span>
                <h2><?= h(money($monthExpenseTotal)) ?></h2>
                <small>Status Disetujui / Dibayar</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon orange"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <span>Menunggu Persetujuan</span>
                <h2><?= number_format($pendingCount, 0, ',', '.') ?></h2>
                <small>Perlu ditinjau</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon purple"><i class="bi bi-check2-circle"></i></div>
            <div>
                <span>Total Dibayar</span>
                <h2><?= h(money($paidTotal)) ?></h2>
                <small>Seluruh status paid</small>
            </div>
        </div>
    </div>

    <div class="table-card">
        <div class="table-header">
            <div>
                <h3>Riwayat Pengeluaran</h3>
                <p>Daftar transaksi pengeluaran perusahaan dan proses persetujuannya.</p>
            </div>
            <a class="btn-outline" href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter, ['export' => 'csv'])) ?>">
                <i class="bi bi-download"></i> Export
            </a>
        </div>

        <form method="get" class="filter-card">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input
                    type="text"
                    name="search"
                    value="<?= h($search) ?>"
                    placeholder="Cari nomor, kategori, keterangan..."
                >
            </div>

            <select name="branch">
                <option value="">Semua Cabang</option>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= (int)$branch['id'] ?>" <?= $branchFilter === (int)$branch['id'] ? 'selected' : '' ?>>
                        <?= h($branch['code'] . ' - ' . $branch['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value="">Semua Status</option>
                <?php foreach ($expenseStatuses as $status): ?>
                    <option value="<?= h($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                        <?= h(status_label($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn-primary btn-search">
                <i class="bi bi-search"></i> Cari
            </button>

            <a
                class="btn-outline reset-btn <?= $hasFilters ? '' : 'is-disabled' ?>"
                href="<?= $hasFilters ? 'index.php' : '#' ?>"
            >
                <i class="bi bi-arrow-counterclockwise"></i> Reset
            </a>
        </form>

        <div class="table-responsive">
            <table>
                <thead>
                <tr>
                    <th>No Pengeluaran</th>
                    <th>Tanggal</th>
                    <th>Kategori</th>
                    <th>Keterangan</th>
                    <th>Jumlah</th>
                    <th>Cabang</th>
                    <th>Status</th>
                    <th>Dibuat Oleh</th>
                    <th>Aksi</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$expenses): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <i class="bi bi-receipt-cutoff"></i>
                                <strong>Data pengeluaran tidak ditemukan</strong>
                                <span>Coba ubah pencarian atau filter Anda.</span>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenses as $expense): ?>
                        <tr>
                            <td>
                                <strong class="expense-code"><?= h($expense['expense_number']) ?></strong>
                                <small><?= h(date('d M Y', strtotime($expense['expense_date']))) ?></small>
                            </td>
                            <td><?= h(date('d M Y', strtotime($expense['expense_date']))) ?></td>
                            <td>
                                <span class="category badge-<?= h(category_class((string)$expense['category'])) ?>">
                                    <?= h($expense['category']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="description">
                                    <strong><?= h($expense['description']) ?></strong>
                                    <?php if (!empty($expense['approver_name'])): ?>
                                        <small>Disetujui: <?= h($expense['approver_name']) ?></small>
                                    <?php else: ?>
                                        <small><?= h($expense['cabang_code'] . ' - ' . $expense['cabang_name']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><strong class="expense-amount"><?= h(money((float)$expense['amount'])) ?></strong></td>
                            <td><?= h(($expense['cabang_code'] ? $expense['cabang_code'] . ' - ' : '') . ($expense['cabang_name'] ?: '-')) ?></td>
                            <td>
                                <span class="expense-status <?= h(status_class((string)$expense['status'])) ?>">
                                    <?= h(status_label((string)$expense['status'])) ?>
                                </span>
                            </td>
                            <td><?= h($expense['creator_name'] ?: '-') ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a
                                        class="action-btn view"
                                        title="Detail"
                                        href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter, ['detail' => (int)$expense['id']])) ?>"
                                    ><i class="bi bi-eye"></i></a>

                                    <?php if ($canEdit && in_array((string)$expense['status'], array_values(array_filter([$statusDraft, $statusRejected])), true)): ?>
                                        <a
                                            class="action-btn edit"
                                            title="Edit"
                                            href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter, ['edit' => (int)$expense['id']])) ?>"
                                        ><i class="bi bi-pencil"></i></a>
                                    <?php endif; ?>

                                    <?php if ($canSubmit && $statusDraft && $expense['status'] === $statusDraft && $statusSubmitted): ?>
                                        <form method="post" onsubmit="return confirm('Ajukan pengeluaran ini untuk persetujuan?');">
                                            <input type="hidden" name="action" value="submit_expense">
                                            <input type="hidden" name="expense_id" value="<?= (int)$expense['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <button class="action-btn submit" type="submit" title="Ajukan">
                                                <i class="bi bi-send"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($expense['status'] === $statusSubmitted && $statusSubmitted): ?>
                                        <?php if ($statusApproved && user_can_approve_expense($pdo, (int)$expense['cabang_id'])): ?>
                                            <form method="post" onsubmit="return confirm('Setujui pengeluaran ini?');">
                                                <input type="hidden" name="action" value="approve_expense">
                                                <input type="hidden" name="expense_id" value="<?= (int)$expense['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <button class="action-btn approve" type="submit" title="Setujui">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($statusRejected && user_can_approve_expense($pdo, (int)$expense['cabang_id'])): ?>
                                            <form method="post" onsubmit="return confirm('Tolak pengeluaran ini?');">
                                                <input type="hidden" name="action" value="reject_expense">
                                                <input type="hidden" name="expense_id" value="<?= (int)$expense['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <button class="action-btn reject" type="submit" title="Tolak">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($canPay && $statusApproved && $expense['status'] === $statusApproved && $statusPaid): ?>
                                        <form method="post" onsubmit="return confirm('Tandai pengeluaran ini sebagai Dibayar?');">
                                            <input type="hidden" name="action" value="pay_expense">
                                            <input type="hidden" name="expense_id" value="<?= (int)$expense['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <button class="action-btn paid" type="submit" title="Tandai Dibayar">
                                                <i class="bi bi-wallet2"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (
                                        $statusCancelled &&
                                        user_can_cancel_expense($pdo, (int)$expense['cabang_id']) &&
                                        in_array(
                                            (string)$expense['status'],
                                            array_values(array_filter([$statusDraft, $statusSubmitted, $statusApproved])),
                                            true
                                        )
                                    ): ?>
                                        <form method="post" onsubmit="return confirm('Batalkan pengeluaran ini?');">
                                            <input type="hidden" name="action" value="cancel_expense">
                                            <input type="hidden" name="expense_id" value="<?= (int)$expense['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <button class="action-btn cancel" type="submit" title="Batalkan">
                                                <i class="bi bi-slash-circle"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <a
                                        class="action-btn print"
                                        title="Cetak"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        href="print.php?id=<?= (int)$expense['id'] ?>"
                                    ><i class="bi bi-printer"></i></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination">
            <span>
                Menampilkan <strong><?= $fromRow ?></strong>–<strong><?= $toRow ?></strong>
                dari <strong><?= $totalFiltered ?></strong> pengeluaran
            </span>

            <div class="pagination-controls">
                <a
                    href="<?= $page > 1 ? h(build_page_url($page - 1, $search, $branchFilter, $statusFilter)) : '#' ?>"
                    class="<?= $page <= 1 ? 'disabled' : '' ?>"
                ><i class="bi bi-chevron-left"></i></a>

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
                        class="<?= $p === $page ? 'active' : '' ?>"
                    ><?= $p ?></a>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?><span>...</span><?php endif; ?>
                    <a href="<?= h(build_page_url($totalPages, $search, $branchFilter, $statusFilter)) ?>"><?= $totalPages ?></a>
                <?php endif; ?>

                <a
                    href="<?= $page < $totalPages ? h(build_page_url($page + 1, $search, $branchFilter, $statusFilter)) : '#' ?>"
                    class="<?= $page >= $totalPages ? 'disabled' : '' ?>"
                ><i class="bi bi-chevron-right"></i></a>
            </div>
        </div>
    </div>

</div>
</main>

<?php if ($editExpense || !$detailExpense): ?>
<div class="modal-overlay <?= $editExpense ? 'show' : '' ?>" id="expenseModal">
    <div class="modal modal-wide">
        <div class="modal-header">
            <div>
                <h2><?= $editExpense ? 'Edit Pengeluaran' : 'Tambah Pengeluaran' ?></h2>
                <p><?= $editExpense ? 'Perbarui pengeluaran yang masih dapat diedit.' : 'Catat transaksi pengeluaran perusahaan.' ?></p>
            </div>
            <button class="modal-close" type="button" onclick="closeExpenseModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form method="post" id="expenseForm">
            <input type="hidden" name="action" value="<?= $editExpense ? 'update_expense' : 'add_expense' ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <?php if ($editExpense): ?>
                <input type="hidden" name="expense_id" value="<?= (int)$editExpense['id'] ?>">
            <?php endif; ?>

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>No Pengeluaran</label>
                        <input type="text" value="<?= h($editExpense['expense_number'] ?? generate_expense_number($pdo)) ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Tanggal <span>*</span></label>
                        <input type="date" name="expense_date" value="<?= h($editExpense['expense_date'] ?? date('Y-m-d')) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Cabang <span>*</span></label>
                        <select name="cabang_id" required>
                            <option value="">Pilih cabang</option>
                            <?php foreach ($branches as $branch): ?>
                                <option
                                    value="<?= (int)$branch['id'] ?>"
                                    <?= $editExpense && (int)$editExpense['cabang_id'] === (int)$branch['id'] ? 'selected' : '' ?>
                                >
                                    <?= h($branch['code'] . ' - ' . $branch['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Kategori <span>*</span></label>
                        <select name="category" required>
                            <option value="">Pilih kategori</option>
                            <?php
                            $currentCategory = (string)($editExpense['category'] ?? '');
                            $allCategories = $categories;
                            if ($currentCategory !== '' && !in_array($currentCategory, $allCategories, true)) {
                                $allCategories[] = $currentCategory;
                            }
                            ?>
                            <?php foreach ($allCategories as $category): ?>
                                <option value="<?= h($category) ?>" <?= $currentCategory === $category ? 'selected' : '' ?>>
                                    <?= h($category) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label>Keterangan <span>*</span></label>
                        <textarea name="description" rows="3" placeholder="Contoh: Pembayaran listrik cabang Jakarta" required><?= h($editExpense['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Jumlah Pengeluaran <span>*</span></label>
                        <input
                            type="number"
                            name="amount"
                            min="1"
                            step="0.01"
                            value="<?= h($editExpense['amount'] ?? '') ?>"
                            placeholder="Contoh: 1500000"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <?php foreach ($expenseStatuses as $status): ?>
                                <?php
                                $currentFormStatus = (string)($editExpense['status'] ?? $defaultStatus);
                                $statusForEdit = ($editExpense && $editExpense['status'] === $statusRejected && $statusDraft)
                                    ? $statusDraft
                                    : $currentFormStatus;
                                ?>
                                <option
                                    value="<?= h($status) ?>"
                                    <?= $statusForEdit === $status ? 'selected' : '' ?>
                                    <?= $status !== $statusDraft && $status !== $statusRejected ? 'disabled' : '' ?>
                                >
                                    <?= h(status_label($status)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-help">Pengeluaran baru disimpan sebagai Draft. Ajukan melalui tombol kirim pada daftar.</small>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button class="btn-cancel" type="button" onclick="closeExpenseModal()">Batal</button>
                <button class="btn-primary" type="submit">
                    <i class="bi bi-check-lg"></i>
                    <?= $editExpense ? 'Simpan Perubahan' : 'Simpan Draft' ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($detailExpense): ?>
<div class="modal-overlay show" id="detailModal">
    <div class="modal modal-detail">
        <div class="modal-header">
            <div>
                <h2>Detail Pengeluaran</h2>
                <p>Informasi lengkap transaksi pengeluaran.</p>
            </div>
            <a class="modal-close" href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter)) ?>">
                <i class="bi bi-x-lg"></i>
            </a>
        </div>

        <div class="modal-body">
            <div class="detail-head">
                <div>
                    <span>No Pengeluaran</span>
                    <strong><?= h($detailExpense['expense_number']) ?></strong>
                </div>
                <div>
                    <span>Status</span>
                    <strong>
                        <span class="expense-status <?= h(status_class((string)$detailExpense['status'])) ?>">
                            <?= h(status_label((string)$detailExpense['status'])) ?>
                        </span>
                    </strong>
                </div>
                <div>
                    <span>Jumlah</span>
                    <strong class="detail-amount"><?= h(money((float)$detailExpense['amount'])) ?></strong>
                </div>
            </div>

            <div class="detail-grid">
                <div><span>Tanggal</span><strong><?= h(date('d M Y', strtotime($detailExpense['expense_date']))) ?></strong></div>
                <div><span>Cabang</span><strong><?= h(($detailExpense['cabang_code'] ? $detailExpense['cabang_code'] . ' - ' : '') . ($detailExpense['cabang_name'] ?: '-')) ?></strong></div>
                <div><span>Kategori</span><strong><?= h($detailExpense['category']) ?></strong></div>
                <div><span>Dibuat Oleh</span><strong><?= h($detailExpense['creator_name'] ?: '-') ?></strong></div>
                <div><span>Disetujui Oleh</span><strong><?= h($detailExpense['approver_name'] ?: '-') ?></strong></div>
                <div class="full"><span>Keterangan</span><strong><?= nl2br(h($detailExpense['description'])) ?></strong></div>
            </div>
        </div>

        <div class="modal-footer">
            <a class="btn-outline" target="_blank" rel="noopener noreferrer" href="print.php?id=<?= (int)$detailExpense['id'] ?>">
                <i class="bi bi-printer"></i> Cetak
            </a>
            <a class="btn-cancel" href="<?= h(build_page_url($page, $search, $branchFilter, $statusFilter)) ?>">Tutup</a>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openExpenseModal() {
    const modal = document.getElementById('expenseModal');
    if (!modal) return;
    modal.classList.add('show');
}

function closeExpenseModal() {
    const modal = document.getElementById('expenseModal');
    if (!modal) return;

    const hasEdit = <?= $editExpense ? 'true' : 'false' ?>;
    if (hasEdit) {
        window.location.href = <?= json_encode(build_page_url($page, $search, $branchFilter, $statusFilter)) ?>;
        return;
    }

    modal.classList.remove('show');
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        const modal = document.getElementById('expenseModal');
        if (modal && modal.classList.contains('show')) {
            closeExpenseModal();
        }
    }
});

const expenseModal = document.getElementById('expenseModal');
if (expenseModal) {
    expenseModal.addEventListener('click', function (event) {
        if (event.target === expenseModal) {
            closeExpenseModal();
        }
    });
}
</script>

</body>
</html>
