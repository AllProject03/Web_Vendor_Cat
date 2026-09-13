<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/config.php';

$pageTitle = 'Laporan';

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

function status_label(string $status): string {
    return match ($status) {
        'draft' => 'Draft',
        'pending', 'waiting', 'submitted' => 'Menunggu',
        'processing', 'in_transit', 'shipped', 'shipping' => 'Diproses',
        'approved' => 'Disetujui',
        'completed', 'received', 'paid' => 'Selesai',
        'rejected', 'cancelled', 'canceled' => 'Dibatalkan',
        default => ucwords(str_replace(['_', '-'], ' ', $status)),
    };
}

function status_class(string $status): string {
    return match ($status) {
        'completed', 'received', 'paid', 'approved' => 'success',
        'pending', 'waiting', 'submitted', 'processing', 'in_transit', 'shipped', 'shipping' => 'warning',
        'rejected', 'cancelled', 'canceled' => 'danger',
        default => 'neutral',
    };
}

function report_url(string $type, int $branch, string $start, string $end, int $page = 1, array $extra = []): string {
    $params = [
        'type' => $type,
        'branch' => $branch,
        'start' => $start,
        'end' => $end,
        'page' => $page,
    ];
    foreach ($extra as $key => $value) {
        $params[$key] = $value;
    }
    return 'index.php?' . http_build_query($params);
}

function print_url(string $type, int $branch, string $start, string $end): string {
    return 'print.php?' . http_build_query([
        'type' => $type,
        'branch' => $branch,
        'start' => $start,
        'end' => $end,
    ]);
}

function fetch_active_branches(PDO $pdo): array {
    return $pdo->query(
        "SELECT id, code, name
         FROM cabangs
         WHERE status = 'active'
         ORDER BY name ASC"
    )->fetchAll();
}

function normalize_date(string $date, string $fallback): string {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return ($dt && $dt->format('Y-m-d') === $date) ? $date : $fallback;
}

function display_number(float $value): string {
    return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
}

$reportTypes = [
    'sales' => 'Laporan Penjualan',
    'purchase' => 'Laporan Pembelian',
    'payment' => 'Laporan Pembayaran',
    'expense' => 'Laporan Pengeluaran',
    'stock' => 'Laporan Stok',
    'transfer' => 'Laporan Transfer Stok',
];

$type = $_GET['type'] ?? 'sales';
if (!isset($reportTypes[$type])) {
    $type = 'sales';
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$startDate = normalize_date(trim((string)($_GET['start'] ?? $monthStart)), $monthStart);
$endDate = normalize_date(trim((string)($_GET['end'] ?? $today)), $today);
if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$branchId = max(0, (int)($_GET['branch'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;

$branches = fetch_active_branches($pdo);
$branchName = 'Semua Cabang';
foreach ($branches as $branch) {
    if ((int)$branch['id'] === $branchId) {
        $branchName = $branch['code'] . ' - ' . $branch['name'];
        break;
    }
}

$where = [];
$params = [];

if ($branchId > 0) {
    $params[':branch_id'] = $branchId;
}

if ($type !== 'stock') {
    $dateColumn = match ($type) {
        'sales' => 'o.order_date',
        'purchase' => 'p.purchase_date',
        'payment' => 'pm.payment_date',
        'expense' => 'e.expense_date',
        'transfer' => 'st.transfer_date',
        default => null,
    };
    if ($dateColumn) {
        $where[] = "$dateColumn BETWEEN :start_date AND :end_date";
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate;
    }
}

/* -----------------------------
   Report-specific SQL
------------------------------ */
$columns = [];
$tableTitle = $reportTypes[$type];
$tableDescription = 'Data berdasarkan filter periode dan cabang.';
$reportRows = [];
$totalFiltered = 0;
$grandAmount = 0.0;
$summary = [
    ['label' => 'Penjualan', 'value' => 0, 'icon' => 'bi-cart-check', 'class' => 'blue', 'note' => 'Transaksi selesai'],
    ['label' => 'Pembelian', 'value' => 0, 'icon' => 'bi-bag-check', 'class' => 'green', 'note' => 'Transaksi pembelian'],
    ['label' => 'Pembayaran', 'value' => 0, 'icon' => 'bi-credit-card', 'class' => 'purple', 'note' => 'Pembayaran diterima'],
    ['label' => 'Pengeluaran', 'value' => 0, 'icon' => 'bi-receipt', 'class' => 'orange', 'note' => 'Pengeluaran dibayar'],
];
$chartData = [];
$branchPerformance = [];

if ($type === 'sales') {
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    if ($branchId > 0) {
        $where[] = 'o.cabang_id = :branch_id';
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    } elseif ($whereSql === '' && $where) {
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    }

    $baseSql = "FROM orders o
        LEFT JOIN cabangs c ON c.id = o.cabang_id
        LEFT JOIN customers cu ON cu.id = o.customer_id
        $whereSql";

    $countStmt = $pdo->prepare("SELECT COUNT(*) $baseSql");
    $countStmt->execute($params);
    $totalFiltered = (int)$countStmt->fetchColumn();

    $sumStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(o.grand_total),0)
         $baseSql " . ($whereSql ? " AND " : " WHERE ") . "o.status = 'completed'"
    );
    $sumStmt->execute($params);
    $grandAmount = (float)$sumStmt->fetchColumn();

    $listWhere = $where;
    $listParams = $params;
    $listWhere[] = "o.status <> 'cancelled'";
    $listWhereSql = 'WHERE ' . implode(' AND ', $listWhere);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN cabangs c ON c.id=o.cabang_id LEFT JOIN customers cu ON cu.id=o.customer_id $listWhereSql");
    $countStmt->execute($listParams);
    $totalFiltered = (int)$countStmt->fetchColumn();

    $columns = ['No', 'Tanggal', 'No. Transaksi', 'Cabang', 'Pelanggan', 'Total', 'Status'];
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare(
        "SELECT o.order_number, o.order_date, o.grand_total, o.status,
                c.code AS cabang_code, c.name AS cabang_name,
                cu.name AS customer_name
         FROM orders o
         LEFT JOIN cabangs c ON c.id=o.cabang_id
         LEFT JOIN customers cu ON cu.id=o.customer_id
         $listWhereSql
         ORDER BY o.order_date DESC, o.id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($listParams as $k => $v) $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $reportRows = $stmt->fetchAll();

    $dayStmt = $pdo->prepare(
        "SELECT DATE(o.order_date) AS d, COALESCE(SUM(o.grand_total),0) amount
         FROM orders o
         WHERE o.order_date BETWEEN :start AND :end
           " . ($branchId > 0 ? "AND o.cabang_id = :branch" : "") . "
           AND o.status = 'completed'
         GROUP BY DATE(o.order_date)
         ORDER BY d ASC"
    );
    $dayParams = [':start'=>$startDate, ':end'=>$endDate];
    if ($branchId > 0) $dayParams[':branch'] = $branchId;
    $dayStmt->execute($dayParams);
    $chartData = $dayStmt->fetchAll();

    $branchStmt = $pdo->prepare(
        "SELECT c.id, c.code, c.name, COALESCE(SUM(o.grand_total),0) amount
         FROM cabangs c
         LEFT JOIN orders o
           ON o.cabang_id=c.id
          AND o.order_date BETWEEN :start AND :end
          AND o.status='completed'
         WHERE c.status='active'
         " . ($branchId > 0 ? "AND c.id=:branch" : "") . "
         GROUP BY c.id,c.code,c.name
         ORDER BY amount DESC"
    );
    $bp = [':start'=>$startDate, ':end'=>$endDate];
    if ($branchId > 0) $bp[':branch'] = $branchId;
    $branchStmt->execute($bp);
    $branchPerformance = $branchStmt->fetchAll();

    $summary = [
        ['label'=>'Penjualan', 'value'=>$grandAmount, 'icon'=>'bi-cart-check', 'class'=>'blue', 'note'=>'Status selesai'],
        ['label'=>'Pembelian', 'value'=>0, 'icon'=>'bi-bag-check', 'class'=>'green', 'note'=>'Lihat laporan pembelian'],
        ['label'=>'Pembayaran', 'value'=>0, 'icon'=>'bi-credit-card', 'class'=>'purple', 'note'=>'Status paid'],
        ['label'=>'Pengeluaran', 'value'=>0, 'icon'=>'bi-receipt', 'class'=>'orange', 'note'=>'Status paid'],
    ];
    $tableTitle = 'Detail Laporan Penjualan';
    $tableDescription = 'Transaksi penjualan pada periode yang dipilih.';
} elseif ($type === 'purchase') {
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    if ($branchId > 0) {
        $where[] = 'p.cabang_id = :branch_id';
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    } else {
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    }
    $baseSql = "FROM purchases p
        LEFT JOIN cabangs c ON c.id=p.cabang_id
        LEFT JOIN suppliers s ON s.id=p.supplier_id
        $whereSql";
    $sumStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(p.grand_total),0) $baseSql " .
        ($whereSql ? " AND " : " WHERE ") . "p.status NOT IN ('cancelled','canceled')"
    );
    $sumStmt->execute($params);
    $grandAmount = (float)$sumStmt->fetchColumn();

    $listWhere = $where;
    $listParams = $params;
    $listWhere[] = "p.status NOT IN ('cancelled','canceled')";
    $listWhereSql = 'WHERE ' . implode(' AND ', $listWhere);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM purchases p LEFT JOIN cabangs c ON c.id=p.cabang_id LEFT JOIN suppliers s ON s.id=p.supplier_id $listWhereSql");
    $countStmt->execute($listParams);
    $totalFiltered = (int)$countStmt->fetchColumn();

    $columns = ['No','Tanggal','No. Pembelian','Cabang','Supplier','Total','Status'];
    $offset = ($page-1)*$perPage;
    $stmt = $pdo->prepare(
        "SELECT p.purchase_number,p.purchase_date,p.grand_total,p.status,
                c.code AS cabang_code,c.name AS cabang_name,
                s.name AS supplier_name
         FROM purchases p
         LEFT JOIN cabangs c ON c.id=p.cabang_id
         LEFT JOIN suppliers s ON s.id=p.supplier_id
         $listWhereSql
         ORDER BY p.purchase_date DESC,p.id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($listParams as $k=>$v) $stmt->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
    $stmt->bindValue(':limit',$perPage,PDO::PARAM_INT);
    $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
    $stmt->execute(); $reportRows=$stmt->fetchAll();

    $summary = [
        ['label'=>'Pembelian', 'value'=>$grandAmount, 'icon'=>'bi-bag-check','class'=>'green','note'=>'Status aktif/non-batal'],
        ['label'=>'Penjualan', 'value'=>0,'icon'=>'bi-cart-check','class'=>'blue','note'=>'Lihat laporan penjualan'],
        ['label'=>'Pembayaran', 'value'=>0,'icon'=>'bi-credit-card','class'=>'purple','note'=>'Status paid'],
        ['label'=>'Pengeluaran', 'value'=>0,'icon'=>'bi-receipt','class'=>'orange','note'=>'Status paid'],
    ];
    $tableTitle='Detail Laporan Pembelian';
    $tableDescription='Transaksi pembelian pada periode yang dipilih.';
} elseif ($type === 'payment') {
    $whereSql=$where ? 'WHERE '.implode(' AND ',$where) : '';
    if ($branchId>0) {
        $where[]='o.cabang_id = :branch_id';
        $whereSql='WHERE '.implode(' AND ',$where);
    } else {
        $whereSql=$where ? 'WHERE '.implode(' AND ',$where) : '';
    }
    $baseSql="FROM payments pm
        INNER JOIN orders o ON o.id=pm.order_id
        LEFT JOIN cabangs c ON c.id=o.cabang_id
        LEFT JOIN customers cu ON cu.id=o.customer_id
        LEFT JOIN users u ON u.id=pm.received_by
        $whereSql";
    $sumWhere=$where;
    $sumWhere[]="pm.status='paid'";
    $sumSql='WHERE '.implode(' AND ',$sumWhere);
    $sumStmt=$pdo->prepare("SELECT COALESCE(SUM(pm.amount),0) FROM payments pm INNER JOIN orders o ON o.id=pm.order_id LEFT JOIN cabangs c ON c.id=o.cabang_id $sumSql");
    $sumStmt->execute($params);
    $grandAmount=(float)$sumStmt->fetchColumn();

    $countStmt=$pdo->prepare("SELECT COUNT(*) $baseSql");
    $countStmt->execute($params);
    $totalFiltered=(int)$countStmt->fetchColumn();

    $columns=['No','Tanggal','No. Pembayaran','No. Pesanan','Cabang','Nominal','Metode','Status'];
    $offset=($page-1)*$perPage;
    $stmt=$pdo->prepare(
        "SELECT pm.payment_number,pm.payment_date,pm.amount,pm.payment_method,pm.status,
                o.order_number,c.code AS cabang_code,c.name AS cabang_name
         $baseSql
         ORDER BY pm.payment_date DESC,pm.id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach($params as $k=>$v) $stmt->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
    $stmt->bindValue(':limit',$perPage,PDO::PARAM_INT); $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
    $stmt->execute(); $reportRows=$stmt->fetchAll();

    $summary=[
        ['label'=>'Pembayaran','value'=>$grandAmount,'icon'=>'bi-credit-card','class'=>'purple','note'=>'Status paid'],
        ['label'=>'Penjualan','value'=>0,'icon'=>'bi-cart-check','class'=>'blue','note'=>'Lihat laporan penjualan'],
        ['label'=>'Pembelian','value'=>0,'icon'=>'bi-bag-check','class'=>'green','note'=>'Lihat laporan pembelian'],
        ['label'=>'Pengeluaran','value'=>0,'icon'=>'bi-receipt','class'=>'orange','note'=>'Status paid'],
    ];
    $tableTitle='Detail Laporan Pembayaran';
    $tableDescription='Riwayat pembayaran pelanggan pada periode yang dipilih.';
} elseif ($type === 'expense') {
    $whereSql=$where ? 'WHERE '.implode(' AND ',$where) : '';
    if ($branchId>0) {
        $where[]='e.cabang_id = :branch_id';
        $whereSql='WHERE '.implode(' AND ',$where);
    } else {
        $whereSql=$where ? 'WHERE '.implode(' AND ',$where) : '';
    }
    $baseSql="FROM expenses e
        LEFT JOIN cabangs c ON c.id=e.cabang_id
        $whereSql";
    $sumWhere=$where; $sumWhere[]="e.status='paid'";
    $sumSql='WHERE '.implode(' AND ',$sumWhere);
    $sumStmt=$pdo->prepare("SELECT COALESCE(SUM(e.amount),0) FROM expenses e $sumSql");
    $sumStmt->execute($params); $grandAmount=(float)$sumStmt->fetchColumn();
    $countStmt=$pdo->prepare("SELECT COUNT(*) $baseSql"); $countStmt->execute($params); $totalFiltered=(int)$countStmt->fetchColumn();
    $columns=['No','Tanggal','No. Pengeluaran','Cabang','Kategori','Keterangan','Jumlah','Status'];
    $offset=($page-1)*$perPage;
    $stmt=$pdo->prepare(
        "SELECT e.expense_number,e.expense_date,e.category,e.description,e.amount,e.status,
                c.code AS cabang_code,c.name AS cabang_name
         $baseSql
         ORDER BY e.expense_date DESC,e.id DESC LIMIT :limit OFFSET :offset"
    );
    foreach($params as $k=>$v) $stmt->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
    $stmt->bindValue(':limit',$perPage,PDO::PARAM_INT); $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
    $stmt->execute(); $reportRows=$stmt->fetchAll();
    $summary=[
        ['label'=>'Pengeluaran','value'=>$grandAmount,'icon'=>'bi-receipt','class'=>'orange','note'=>'Status paid'],
        ['label'=>'Penjualan','value'=>0,'icon'=>'bi-cart-check','class'=>'blue','note'=>'Lihat laporan penjualan'],
        ['label'=>'Pembelian','value'=>0,'icon'=>'bi-bag-check','class'=>'green','note'=>'Lihat laporan pembelian'],
        ['label'=>'Pembayaran','value'=>0,'icon'=>'bi-credit-card','class'=>'purple','note'=>'Status paid'],
    ];
    $tableTitle='Detail Laporan Pengeluaran';
    $tableDescription='Pengeluaran yang tercatat pada periode yang dipilih.';
} elseif ($type === 'stock') {
    $where=[]; $params=[];
    if ($branchId>0) { $where[]='bs.cabang_id=:branch_id'; $params[':branch_id']=$branchId; }
    $whereSql=$where?'WHERE '.implode(' AND ',$where):'';
    $countStmt=$pdo->prepare("SELECT COUNT(*) FROM branch_stocks bs LEFT JOIN products p ON p.id=bs.product_id LEFT JOIN cabangs c ON c.id=bs.cabang_id $whereSql");
    $countStmt->execute($params); $totalFiltered=(int)$countStmt->fetchColumn();
    $columns=['No','Cabang','Kode Produk','Produk','Kategori','Stok','Minimum','Status'];
    $offset=($page-1)*$perPage;
    $stmt=$pdo->prepare(
        "SELECT bs.stock,bs.minimum_stock,p.code AS product_code,p.name AS product_name,
                p.brand,p.unit,pc.name AS category_name,c.code AS cabang_code,c.name AS cabang_name
         FROM branch_stocks bs
         INNER JOIN products p ON p.id=bs.product_id
         LEFT JOIN product_categories pc ON pc.id=p.category_id
         INNER JOIN cabangs c ON c.id=bs.cabang_id
         $whereSql
         ORDER BY c.name,p.name LIMIT :limit OFFSET :offset"
    );
    foreach($params as $k=>$v) $stmt->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
    $stmt->bindValue(':limit',$perPage,PDO::PARAM_INT); $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
    $stmt->execute(); $reportRows=$stmt->fetchAll();

    $sumWhere=$branchId>0?'WHERE bs.cabang_id=:branch_id':'';
    $sumParams=$branchId>0?[':branch_id'=>$branchId]:[];
    $statStmt=$pdo->prepare("SELECT COALESCE(SUM(bs.stock),0) total_stock,
        SUM(CASE WHEN bs.stock>0 THEN 1 ELSE 0 END) available_products,
        SUM(CASE WHEN bs.stock<=bs.minimum_stock THEN 1 ELSE 0 END) restock_products
        FROM branch_stocks bs $sumWhere");
    $statStmt->execute($sumParams); $stockStats=$statStmt->fetch() ?: ['total_stock'=>0,'available_products'=>0,'restock_products'=>0];
    $grandAmount=(float)$stockStats['total_stock'];

    $summary=[
        ['label'=>'Total Stok','value'=>$stockStats['total_stock'],'icon'=>'bi-boxes','class'=>'green','note'=>'Unit seluruh cabang/filter'],
        ['label'=>'Produk Tersedia','value'=>$stockStats['available_products'],'icon'=>'bi-box-seam','class'=>'blue','note'=>'Stok di atas 0'],
        ['label'=>'Perlu Restock','value'=>$stockStats['restock_products'],'icon'=>'bi-exclamation-triangle','class'=>'orange','note'=>'Di bawah minimum'],
        ['label'=>'Posisi','value'=>$branchName,'icon'=>'bi-shop','class'=>'purple','note'=>'Saldo stok saat ini'],
    ];
    $tableTitle='Detail Laporan Stok';
    $tableDescription='Posisi stok saat ini dari branch_stocks. Filter tanggal tidak memengaruhi saldo.';
} else {
    $where=[]; $params=[];
    if ($startDate !== '') { $where[]='st.transfer_date BETWEEN :start_date AND :end_date'; $params[':start_date']=$startDate; $params[':end_date']=$endDate; }
    if ($branchId>0) { $where[]='(st.from_cabang_id=:branch_from OR st.to_cabang_id=:branch_to)'; $params[':branch_from']=$branchId; $params[':branch_to']=$branchId; }
    $whereSql=$where?'WHERE '.implode(' AND ',$where):'';
    $countStmt=$pdo->prepare("SELECT COUNT(*) FROM stock_transfers st $whereSql"); $countStmt->execute($params); $totalFiltered=(int)$countStmt->fetchColumn();
    $columns=['No','Tanggal','No. Transfer','Dari','Ke','Qty','Diterima','Status'];
    $offset=($page-1)*$perPage;
    $stmt=$pdo->prepare(
        "SELECT st.transfer_number,st.transfer_date,st.status,
                f.code AS from_code,f.name AS from_name,
                t.code AS to_code,t.name AS to_name,
                (SELECT COALESCE(SUM(d.quantity),0) FROM stock_transfer_details d WHERE d.stock_transfer_id=st.id) total_qty,
                (SELECT COALESCE(SUM(d.received_quantity),0) FROM stock_transfer_details d WHERE d.stock_transfer_id=st.id) received_qty
         FROM stock_transfers st
         LEFT JOIN cabangs f ON f.id=st.from_cabang_id
         LEFT JOIN cabangs t ON t.id=st.to_cabang_id
         $whereSql
         ORDER BY st.transfer_date DESC,st.id DESC LIMIT :limit OFFSET :offset"
    );
    foreach($params as $k=>$v) $stmt->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
    $stmt->bindValue(':limit',$perPage,PDO::PARAM_INT); $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
    $stmt->execute(); $reportRows=$stmt->fetchAll();
    $grandAmount=(float)array_sum(array_map(fn($r)=>(float)$r['total_qty'],$reportRows));
    $summary=[
        ['label'=>'Total Transfer','value'=>$totalFiltered,'icon'=>'bi-arrow-left-right','class'=>'blue','note'=>'Transaksi pada periode'],
        ['label'=>'Qty Dikirim','value'=>$grandAmount,'icon'=>'bi-box-arrow-up-right','class'=>'green','note'=>'Pada halaman aktif'],
        ['label'=>'Qty Diterima','value'=>(float)array_sum(array_map(fn($r)=>(float)$r['received_qty'],$reportRows)),'icon'=>'bi-box-arrow-in-down','class'=>'purple','note'=>'Pada halaman aktif'],
        ['label'=>'Cabang','value'=>$branchName,'icon'=>'bi-shop','class'=>'orange','note'=>'Filter cabang'],
    ];
    $tableTitle='Detail Laporan Transfer Stok';
    $tableDescription='Perpindahan stok antar cabang pada periode yang dipilih.';
}

/* Fill non-stock summary cards from database so dashboard remains informative. */
if ($type !== 'stock' && $type !== 'transfer') {
    $salesSql = "SELECT COALESCE(SUM(o.grand_total),0)
                 FROM orders o
                 WHERE o.order_date BETWEEN :s AND :e
                   AND o.status='completed'" . ($branchId>0 ? " AND o.cabang_id=:b" : "");
    $salesStmt=$pdo->prepare($salesSql);
    $salesParams=[':s'=>$startDate,':e'=>$endDate]; if($branchId>0)$salesParams[':b']=$branchId;
    $salesStmt->execute($salesParams);
    $salesTotal=(float)$salesStmt->fetchColumn();

    $purchaseSql = "SELECT COALESCE(SUM(p.grand_total),0)
                    FROM purchases p
                    WHERE p.purchase_date BETWEEN :s AND :e
                      AND p.status NOT IN ('cancelled','canceled')" . ($branchId>0 ? " AND p.cabang_id=:b" : "");
    $purchaseStmt=$pdo->prepare($purchaseSql);
    $purchaseStmt->execute($salesParams);
    $purchaseTotal=(float)$purchaseStmt->fetchColumn();

    $paymentSql = "SELECT COALESCE(SUM(pm.amount),0)
                   FROM payments pm INNER JOIN orders o ON o.id=pm.order_id
                   WHERE pm.payment_date BETWEEN :s AND :e
                     AND pm.status='paid'" . ($branchId>0 ? " AND o.cabang_id=:b" : "");
    $paymentStmt=$pdo->prepare($paymentSql);
    $paymentStmt->execute($salesParams);
    $paymentTotal=(float)$paymentStmt->fetchColumn();

    $expenseSql = "SELECT COALESCE(SUM(e.amount),0)
                   FROM expenses e
                   WHERE e.expense_date BETWEEN :s AND :e
                     AND e.status='paid'" . ($branchId>0 ? " AND e.cabang_id=:b" : "");
    $expenseStmt=$pdo->prepare($expenseSql);
    $expenseStmt->execute($salesParams);
    $expenseTotal=(float)$expenseStmt->fetchColumn();

    $summary=[
        ['label'=>'Total Penjualan','value'=>$salesTotal,'icon'=>'bi-cart-check','class'=>'blue','note'=>'Status selesai'],
        ['label'=>'Total Pembelian','value'=>$purchaseTotal,'icon'=>'bi-bag-check','class'=>'green','note'=>'Tidak dibatalkan'],
        ['label'=>'Total Pembayaran','value'=>$paymentTotal,'icon'=>'bi-credit-card','class'=>'purple','note'=>'Status paid'],
        ['label'=>'Total Pengeluaran','value'=>$expenseTotal,'icon'=>'bi-receipt','class'=>'orange','note'=>'Status paid'],
    ];
}

/* Trend data */
if ($type !== 'stock' && $type !== 'transfer') {
    $trendSql = "SELECT DATE(o.order_date) d, COALESCE(SUM(o.grand_total),0) amount
                 FROM orders o
                 WHERE o.order_date BETWEEN :s AND :e AND o.status='completed'
                 " . ($branchId>0 ? "AND o.cabang_id=:b" : "") . "
                 GROUP BY DATE(o.order_date) ORDER BY d";
    $trendStmt=$pdo->prepare($trendSql); $trendParams=[':s'=>$startDate,':e'=>$endDate]; if($branchId>0)$trendParams[':b']=$branchId;
    $trendStmt->execute($trendParams); $chartData=$trendStmt->fetchAll();
}

/* Final pagination values */
$totalPages=max(1,(int)ceil($totalFiltered/$perPage));
$page=min($page,$totalPages);
$offset=($page-1)*$perPage;
$fromRow=$totalFiltered>0?$offset+1:0;
$toRow=min($offset+$perPage,$totalFiltered);

$chartValues = array_map(static fn($r) => (float)($r['amount'] ?? 0), $chartData);
$maxChart = max(array_merge([1.0], $chartValues));
if (count($chartData)>14) {
    $chartData=array_slice($chartData,-14);
}

$success = '';
$error = '';

/* CSV export */
if (($_GET['export'] ?? '') === 'csv') {
    $exportLimit = 100000;
    $exportSql = '';
    $exportParams = $params;
    if ($type === 'sales') {
        $ew = [];
        $ew[]='o.order_date BETWEEN :s AND :e';
        $ep=[':s'=>$startDate,':e'=>$endDate];
        if($branchId>0){$ew[]='o.cabang_id=:b';$ep[':b']=$branchId;}
        $ew[]="o.status <> 'cancelled'";
        $exportSql="SELECT o.order_number,o.order_date,c.code cabang_code,c.name cabang_name,cu.name customer_name,o.grand_total,o.status
                    FROM orders o LEFT JOIN cabangs c ON c.id=o.cabang_id LEFT JOIN customers cu ON cu.id=o.customer_id
                    WHERE ".implode(' AND ',$ew)." ORDER BY o.order_date DESC,o.id DESC LIMIT $exportLimit";
        $exportParams=$ep;
    } elseif ($type==='purchase') {
        $ew=['p.purchase_date BETWEEN :s AND :e',"p.status NOT IN ('cancelled','canceled')"]; $ep=[':s'=>$startDate,':e'=>$endDate];
        if($branchId>0){$ew[]='p.cabang_id=:b';$ep[':b']=$branchId;}
        $exportSql="SELECT p.purchase_number,p.purchase_date,c.code cabang_code,c.name cabang_name,s.name supplier_name,p.grand_total,p.status
                    FROM purchases p LEFT JOIN cabangs c ON c.id=p.cabang_id LEFT JOIN suppliers s ON s.id=p.supplier_id
                    WHERE ".implode(' AND ',$ew)." ORDER BY p.purchase_date DESC,p.id DESC LIMIT $exportLimit"; $exportParams=$ep;
    } elseif ($type==='payment') {
        $ew=['pm.payment_date BETWEEN :s AND :e']; $ep=[':s'=>$startDate,':e'=>$endDate];
        if($branchId>0){$ew[]='o.cabang_id=:b';$ep[':b']=$branchId;}
        $exportSql="SELECT pm.payment_number,pm.payment_date,o.order_number,c.code cabang_code,c.name cabang_name,pm.amount,pm.payment_method,pm.status
                    FROM payments pm INNER JOIN orders o ON o.id=pm.order_id LEFT JOIN cabangs c ON c.id=o.cabang_id
                    WHERE ".implode(' AND ',$ew)." ORDER BY pm.payment_date DESC,pm.id DESC LIMIT $exportLimit"; $exportParams=$ep;
    } elseif ($type==='expense') {
        $ew=['e.expense_date BETWEEN :s AND :e']; $ep=[':s'=>$startDate,':e'=>$endDate];
        if($branchId>0){$ew[]='e.cabang_id=:b';$ep[':b']=$branchId;}
        $exportSql="SELECT e.expense_number,e.expense_date,c.code cabang_code,c.name cabang_name,e.category,e.description,e.amount,e.status
                    FROM expenses e LEFT JOIN cabangs c ON c.id=e.cabang_id
                    WHERE ".implode(' AND ',$ew)." ORDER BY e.expense_date DESC,e.id DESC LIMIT $exportLimit"; $exportParams=$ep;
    } elseif ($type==='stock') {
        $ew=[]; $ep=[]; if($branchId>0){$ew[]='bs.cabang_id=:b';$ep[':b']=$branchId;}
        $exportSql="SELECT c.code cabang_code,c.name cabang_name,p.code product_code,p.name product_name,pc.name category_name,bs.stock,bs.minimum_stock
                    FROM branch_stocks bs INNER JOIN products p ON p.id=bs.product_id LEFT JOIN product_categories pc ON pc.id=p.category_id
                    INNER JOIN cabangs c ON c.id=bs.cabang_id " . ($ew?'WHERE '.implode(' AND ',$ew):'') . " ORDER BY c.name,p.name LIMIT $exportLimit"; $exportParams=$ep;
    } else {
        $ew=['st.transfer_date BETWEEN :s AND :e']; $ep=[':s'=>$startDate,':e'=>$endDate];
        if($branchId>0){$ew[]='(st.from_cabang_id=:b_from OR st.to_cabang_id=:b_to)';$ep[':b_from']=$branchId;$ep[':b_to']=$branchId;}
        $exportSql="SELECT st.transfer_number,st.transfer_date,f.code from_code,f.name from_name,t.code to_code,t.name to_name,st.status,
                    (SELECT COALESCE(SUM(d.quantity),0) FROM stock_transfer_details d WHERE d.stock_transfer_id=st.id) total_qty,
                    (SELECT COALESCE(SUM(d.received_quantity),0) FROM stock_transfer_details d WHERE d.stock_transfer_id=st.id) received_qty
                    FROM stock_transfers st LEFT JOIN cabangs f ON f.id=st.from_cabang_id LEFT JOIN cabangs t ON t.id=st.to_cabang_id
                    WHERE ".implode(' AND ',$ew)." ORDER BY st.transfer_date DESC,st.id DESC LIMIT $exportLimit"; $exportParams=$ep;
    }
    $st=$pdo->prepare($exportSql);
    foreach($exportParams as $k=>$v) $st->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
    $st->execute();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan_'.$type.'_'.date('Ymd_His').'.csv"');
    $out=fopen('php://output','w');
    $rows=$st->fetchAll();
    if ($type==='sales') fputcsv($out,['No. Transaksi','Tanggal','Cabang','Pelanggan','Total','Status']);
    elseif($type==='purchase') fputcsv($out,['No. Pembelian','Tanggal','Cabang','Supplier','Total','Status']);
    elseif($type==='payment') fputcsv($out,['No. Pembayaran','Tanggal','No. Pesanan','Cabang','Nominal','Metode','Status']);
    elseif($type==='expense') fputcsv($out,['No. Pengeluaran','Tanggal','Cabang','Kategori','Keterangan','Jumlah','Status']);
    elseif($type==='stock') fputcsv($out,['Cabang','Kode Produk','Produk','Kategori','Stok','Minimum']);
    else fputcsv($out,['No. Transfer','Tanggal','Dari','Ke','Qty','Diterima','Status']);
    foreach($rows as $r){
        if($type==='sales') fputcsv($out,[$r['order_number'],$r['order_date'],trim(($r['cabang_code']?$r['cabang_code'].' - ':'').$r['cabang_name']),$r['customer_name'],$r['grand_total'],status_label($r['status'])]);
        elseif($type==='purchase') fputcsv($out,[$r['purchase_number'],$r['purchase_date'],trim(($r['cabang_code']?$r['cabang_code'].' - ':'').$r['cabang_name']),$r['supplier_name'],$r['grand_total'],status_label($r['status'])]);
        elseif($type==='payment') fputcsv($out,[$r['payment_number'],$r['payment_date'],$r['order_number'],trim(($r['cabang_code']?$r['cabang_code'].' - ':'').$r['cabang_name']),$r['amount'],strtoupper($r['payment_method']),status_label($r['status'])]);
        elseif($type==='expense') fputcsv($out,[$r['expense_number'],$r['expense_date'],trim(($r['cabang_code']?$r['cabang_code'].' - ':'').$r['cabang_name']),$r['category'],$r['description'],$r['amount'],status_label($r['status'])]);
        elseif($type==='stock') fputcsv($out,[$r['cabang_code'].' - '.$r['cabang_name'],$r['product_code'],$r['product_name'],$r['category_name'],$r['stock'],$r['minimum_stock']]);
        else fputcsv($out,[$r['transfer_number'],$r['transfer_date'],$r['from_code'].' - '.$r['from_name'],$r['to_code'].' - '.$r['to_name'],$r['total_qty'],$r['received_qty'],status_label($r['status'])]);
    }
    fclose($out); exit;
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
<link rel="stylesheet" href="style.css?v=20260913-reports">
</head>
<body class="reports-page">
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="<?= h($companyLogo) ?>" class="img-fluid" alt="<?= h($companyName) ?>">
        <div class="brand-text">
            <div class="brand-name"><?= h($companyName) ?></div>
            <small><?= h($companyTagline) ?></small>
        </div>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-section"><div class="menu-title">MENU UTAMA</div><a href="../admin.php" class="menu-item"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a></div>
        <div class="menu-section"><div class="menu-title">MASTER DATA</div>
            <a href="../customers/" class="menu-item"><i class="bi bi-people"></i><span>Pelanggan</span></a>
            <a href="../vehicles/" class="menu-item"><i class="bi bi-car-front"></i><span>Kendaraan</span></a>
            <a href="../product/" class="menu-item"><i class="bi bi-box-seam"></i><span>Produk</span></a>
            <a href="../service/" class="menu-item"><i class="bi bi-tools"></i><span>Jasa</span></a>
            <a href="../supplier/" class="menu-item"><i class="bi bi-truck"></i><span>Supplier</span></a>
            <a href="../cabang/" class="menu-item"><i class="bi bi-shop"></i><span>Cabang</span></a>
            <a href="../users/" class="menu-item"><i class="bi bi-person-badge"></i><span>Pengguna</span></a>
            <a href="../roles/" class="menu-item"><i class="bi bi-shield-lock"></i><span>Role & Hak Akses</span></a>
        </div>
        <div class="menu-section"><div class="menu-title">TRANSAKSI</div>
            <a href="../orders/" class="menu-item"><i class="bi bi-cart3"></i><span>Penjualan</span></a>
            <a href="../purchases/" class="menu-item"><i class="bi bi-bag"></i><span>Pembelian</span></a>
            <a href="../stoks-transfer/" class="menu-item"><i class="bi bi-arrow-left-right"></i><span>Transfer Stok</span></a>
            <a href="../payments/" class="menu-item"><i class="bi bi-credit-card"></i><span>Pembayaran</span></a>
            <a href="../expenses/" class="menu-item"><i class="bi bi-receipt"></i><span>Pengeluaran</span></a>
            <a href="../stoks/" class="menu-item"><i class="bi bi-boxes"></i><span>Stok / Persediaan</span></a>
        </div>
        <div class="menu-section"><div class="menu-title">LAPORAN</div><a href="./" class="menu-item active"><i class="bi bi-bar-chart-line"></i><span>Laporan</span></a><a href="../recaps/" class="menu-item"><i class="bi bi-pie-chart"></i><span>Rekap Cabang</span></a></div>
        <div class="menu-section"><div class="menu-title">PENGATURAN</div><a href="../settings/" class="menu-item"><i class="bi bi-gear"></i><span>Pengaturan</span></a><a href="../logout.php" class="menu-item"><i class="bi bi-box-arrow-right"></i><span>Keluar</span></a></div>
        <div class="sidebar-footer"><div class="paint-decoration"><i class="bi bi-paint-bucket"></i></div><strong>Better Paint</strong><span>Brighter Drive</span></div>
    </nav>
</aside>

<main class="main">
<div class="page-container">
    <header class="page-header">
        <div>
            <div class="breadcrumb"><span>Dashboard</span><i class="bi bi-chevron-right"></i><strong>Laporan</strong></div>
            <h1>Laporan</h1>
            <p>Analisis transaksi, stok, pembayaran, pengeluaran, dan transfer stok VendorCat.</p>
        </div>
        <div class="header-actions">
            <a class="btn-secondary" target="_blank" rel="noopener" href="<?= h(print_url($type,$branchId,$startDate,$endDate)) ?>"><i class="bi bi-printer"></i> Cetak</a>
            <a class="btn-primary" href="<?= h(report_url($type,$branchId,$startDate,$endDate,1,['export'=>'csv'])) ?>"><i class="bi bi-download"></i> Export CSV</a>
        </div>
    </header>

    <form method="get" class="report-filter">
        <div class="filter-group">
            <label>Jenis Laporan</label>
            <select name="type" onchange="this.form.submit()">
                <?php foreach($reportTypes as $key=>$label): ?>
                    <option value="<?= h($key) ?>" <?= $type===$key?'selected':'' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Cabang</label>
            <select name="branch">
                <option value="0">Semua Cabang</option>
                <?php foreach($branches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $branchId===(int)$b['id']?'selected':'' ?>><?= h($b['code'].' - '.$b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Tanggal Mulai</label>
            <input type="date" name="start" value="<?= h($startDate) ?>">
        </div>
        <div class="filter-group">
            <label>Tanggal Akhir</label>
            <input type="date" name="end" value="<?= h($endDate) ?>">
        </div>
        <button class="btn-filter" type="submit"><i class="bi bi-funnel"></i> Tampilkan</button>
    </form>

    <section class="summary-grid">
        <?php foreach($summary as $card): ?>
            <div class="summary-card">
                <div class="summary-icon <?= h($card['class']) ?>"><i class="bi <?= h($card['icon']) ?>"></i></div>
                <div>
                    <span><?= h($card['label']) ?></span>
                    <strong>
                        <?php if(is_string($card['value'])): ?>
                            <?= h($card['value']) ?>
                        <?php else: ?>
                            <?= h(money((float)$card['value'])) ?>
                        <?php endif; ?>
                    </strong>
                    <small><?= h($card['note']) ?></small>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="report-layout">
        <div class="report-card">
            <div class="card-header"><div><h2>Tren Penjualan</h2><p><?= h($startDate) ?> s/d <?= h($endDate) ?> · <?= h($branchName) ?></p></div><span class="period-badge"><?= h($type==='stock'?'Saldo Saat Ini':'Periode Dipilih') ?></span></div>
            <div class="chart-area">
                <?php if($chartData): ?>
                    <div class="y-labels"><span><?= h(money($maxChart)) ?></span><span><?= h(money($maxChart*.75)) ?></span><span><?= h(money($maxChart*.5)) ?></span><span><?= h(money($maxChart*.25)) ?></span><span>Rp 0</span></div>
                    <div class="chart"><div class="grid-line g1"></div><div class="grid-line g2"></div><div class="grid-line g3"></div><div class="grid-line g4"></div><div class="grid-line g5"></div><div class="bars">
                    <?php foreach($chartData as $item): $hgt=max(6,min(100,((float)$item['amount']/$maxChart)*100)); ?>
                        <div class="bar-wrap"><div class="bar" style="height:<?= $hgt ?>%"></div><span><?= h(date('d',strtotime($item['d']))) ?></span></div>
                    <?php endforeach; ?>
                    </div></div>
                <?php else: ?>
                    <div class="chart-empty"><i class="bi bi-bar-chart"></i><strong>Tidak ada data tren</strong><span>Belum ada transaksi penjualan selesai pada periode ini.</span></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="report-card">
            <div class="card-header"><div><h2>Performa Cabang</h2><p>Total penjualan selesai pada periode.</p></div></div>
            <div class="branch-list">
                <?php
                $branchMax=max(1.0,...array_map(static fn($r)=>(float)$r['amount'],$branchPerformance ?: [['amount'=>0]]));
                if($branchPerformance):
                    foreach($branchPerformance as $bp):
                        $pct=$grandAmount>0?round(((float)$bp['amount']/$grandAmount)*100):0;
                ?>
                    <div class="branch-row"><div><strong><?= h($bp['code'].' - '.$bp['name']) ?></strong><span><?= h(money((float)$bp['amount'])) ?></span></div><div class="progress"><i style="width:<?= max(0,min(100,$pct)) ?>%"></i></div><b><?= (int)$pct ?>%</b></div>
                <?php endforeach; else: ?>
                    <div class="chart-empty small"><strong>Tidak ada data cabang</strong><span>Belum ada penjualan selesai pada periode ini.</span></div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="report-card table-card">
        <div class="card-header">
            <div><h2><?= h($tableTitle) ?></h2><p><?= h($tableDescription) ?></p></div>
            <div class="table-actions">
                <a href="<?= h(report_url($type,$branchId,$startDate,$endDate,1,['export'=>'csv'])) ?>"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a>
                <a target="_blank" rel="noopener" href="<?= h(print_url($type,$branchId,$startDate,$endDate)) ?>"><i class="bi bi-printer"></i> Print</a>
            </div>
        </div>
        <div class="table-responsive">
            <table id="reportTable">
                <thead><tr>
                    <?php foreach($columns as $col): ?><th><?= h($col) ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php if(!$reportRows): ?>
                    <tr><td colspan="<?= count($columns) ?>"><div class="chart-empty table-empty"><i class="bi bi-file-earmark-bar-graph"></i><strong>Tidak ada data laporan</strong><span>Ubah periode atau cabang.</span></div></td></tr>
                <?php else: ?>
                    <?php foreach($reportRows as $i=>$row): ?>
                        <tr>
                            <td><?= $fromRow+$i ?></td>
                            <?php if($type==='sales'): ?>
                                <td><?= h(date('d M Y',strtotime($row['order_date']))) ?></td>
                                <td><strong><?= h($row['order_number']) ?></strong></td>
                                <td><?= h(($row['cabang_code']?$row['cabang_code'].' - ':'').$row['cabang_name']) ?></td>
                                <td><?= h($row['customer_name'] ?: '-') ?></td>
                                <td><strong><?= h(money((float)$row['grand_total'])) ?></strong></td>
                                <td><span class="status <?= h(status_class($row['status'])) ?>"><?= h(status_label($row['status'])) ?></span></td>
                            <?php elseif($type==='purchase'): ?>
                                <td><?= h(date('d M Y',strtotime($row['purchase_date']))) ?></td>
                                <td><strong><?= h($row['purchase_number']) ?></strong></td>
                                <td><?= h(($row['cabang_code']?$row['cabang_code'].' - ':'').$row['cabang_name']) ?></td>
                                <td><?= h($row['supplier_name'] ?: '-') ?></td>
                                <td><strong><?= h(money((float)$row['grand_total'])) ?></strong></td>
                                <td><span class="status <?= h(status_class($row['status'])) ?>"><?= h(status_label($row['status'])) ?></span></td>
                            <?php elseif($type==='payment'): ?>
                                <td><?= h(date('d M Y',strtotime($row['payment_date']))) ?></td>
                                <td><strong><?= h($row['payment_number']) ?></strong></td>
                                <td><?= h($row['order_number']) ?></td>
                                <td><?= h(($row['cabang_code']?$row['cabang_code'].' - ':'').$row['cabang_name']) ?></td>
                                <td><strong><?= h(money((float)$row['amount'])) ?></strong></td>
                                <td><?= h(strtoupper($row['payment_method'])) ?></td>
                                <td><span class="status <?= h(status_class($row['status'])) ?>"><?= h(status_label($row['status'])) ?></span></td>
                            <?php elseif($type==='expense'): ?>
                                <td><?= h(date('d M Y',strtotime($row['expense_date']))) ?></td>
                                <td><strong><?= h($row['expense_number']) ?></strong></td>
                                <td><?= h(($row['cabang_code']?$row['cabang_code'].' - ':'').$row['cabang_name']) ?></td>
                                <td><?= h($row['category']) ?></td>
                                <td><?= h($row['description']) ?></td>
                                <td><strong><?= h(money((float)$row['amount'])) ?></strong></td>
                                <td><span class="status <?= h(status_class($row['status'])) ?>"><?= h(status_label($row['status'])) ?></span></td>
                            <?php elseif($type==='stock'): ?>
                                <td><?= h(($row['cabang_code']?$row['cabang_code'].' - ':'').$row['cabang_name']) ?></td>
                                <td><strong><?= h($row['product_code']) ?></strong></td>
                                <td><?= h($row['product_name']) ?><small><?= h(($row['brand']?$row['brand'].' · ':'').$row['unit']) ?></small></td>
                                <td><?= h($row['category_name'] ?: '-') ?></td>
                                <td><strong><?= h(display_number((float)$row['stock'])) ?></strong></td>
                                <td><?= h(display_number((float)$row['minimum_stock'])) ?></td>
                                <td><span class="status <?= ((float)$row['stock']<=0?'danger':((float)$row['stock']<=(float)$row['minimum_stock']?'warning':'success')) ?>"><?= ((float)$row['stock']<=0?'Habis':((float)$row['stock']<=(float)$row['minimum_stock']?'Menipis':'Aman')) ?></span></td>
                            <?php else: ?>
                                <td><?= h(date('d M Y',strtotime($row['transfer_date']))) ?></td>
                                <td><strong><?= h($row['transfer_number']) ?></strong></td>
                                <td><?= h(($row['from_code']?$row['from_code'].' - ':'').$row['from_name']) ?></td>
                                <td><?= h(($row['to_code']?$row['to_code'].' - ':'').$row['to_name']) ?></td>
                                <td><?= h(display_number((float)$row['total_qty'])) ?></td>
                                <td><?= h(display_number((float)$row['received_qty'])) ?></td>
                                <td><span class="status <?= h(status_class($row['status'])) ?>"><?= h(status_label($row['status'])) ?></span></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination">
            <span>Menampilkan <?= $fromRow ?>–<?= $toRow ?> dari <?= $totalFiltered ?> data</span>
            <div class="pagination-controls">
                <a class="<?= $page<=1?'disabled':'' ?>" href="<?= $page>1?h(report_url($type,$branchId,$startDate,$endDate,$page-1)): '#' ?>"><i class="bi bi-chevron-left"></i></a>
                <?php $start=max(1,$page-2); $end=min($totalPages,$page+2); ?>
                <?php if($start>1): ?><a href="<?= h(report_url($type,$branchId,$startDate,$endDate,1)) ?>">1</a><?php if($start>2): ?><span class="ellipsis">...</span><?php endif; ?><?php endif; ?>
                <?php for($p=$start;$p<=$end;$p++): ?><a class="<?= $p===$page?'active':'' ?>" href="<?= h(report_url($type,$branchId,$startDate,$endDate,$p)) ?>"><?= $p ?></a><?php endfor; ?>
                <?php if($end<$totalPages): ?><?php if($end<$totalPages-1): ?><span class="ellipsis">...</span><?php endif; ?><a href="<?= h(report_url($type,$branchId,$startDate,$endDate,$totalPages)) ?>"><?= $totalPages ?></a><?php endif; ?>
                <a class="<?= $page>=$totalPages?'disabled':'' ?>" href="<?= $page<$totalPages?h(report_url($type,$branchId,$startDate,$endDate,$page+1)):'#' ?>"><i class="bi bi-chevron-right"></i></a>
            </div>
        </div>
    </section>
</div>
</main>
</body>
</html>
