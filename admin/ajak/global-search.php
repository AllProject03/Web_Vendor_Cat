<?php

session_start();

/* =====================================================
   CEK LOGIN
===================================================== */

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);

    exit;
}


/* =====================================================
   KONEKSI DATABASE
===================================================== */

require_once __DIR__ . '/../../config/config.php';


/* =====================================================
   HEADER JSON
===================================================== */

header('Content-Type: application/json; charset=utf-8');


/* =====================================================
   QUERY
===================================================== */

$q = trim($_GET['q'] ?? '');

if ($q === '' || mb_strlen($q) < 2) {

    echo json_encode([
        'success' => true,
        'data' => []
    ]);

    exit;
}


/* =====================================================
   PARAMETER PENCARIAN
===================================================== */

$search = '%' . $q . '%';

$results = [];


/* =====================================================
   PELANGGAN
===================================================== */

$s_pelanggan = $pdo->prepare(" SELECT id, name, phone, email
    FROM customers
    WHERE name LIKE :search
       OR phone LIKE :search
       OR email LIKE :search
    ORDER BY name ASC
    LIMIT 5
");

$s_pelanggan->execute([':search' => $search]);

foreach ($s_pelanggan->fetchAll() as $row) {

    $results[] = [
        'type'  => 'customer',
        'label' => 'Pelanggan',
        'icon'  => 'bi-person',
        'title' => $row['name'],
        'text'  => $row['phone'] ?: 'Data pelanggan',
        'url'   => 'customers/'
    ];
}


/* =====================================================
   PRODUK
===================================================== */

$s_product = $pdo->prepare(" SELECT id, code, name, brand
    FROM products
    WHERE name LIKE :search
       OR code LIKE :search
       OR brand LIKE :search
    ORDER BY name ASC
    LIMIT 5
");

$s_product->execute([':search' => $search]);

foreach ($s_product->fetchAll() as $row) {

    $detail = $row['code'];

    if (!empty($row['brand'])) {
        $detail .= ' • ' . $row['brand'];
    }

    $results[] = [
        'type'  => 'product',
        'label' => 'Produk',
        'icon'  => 'bi-box-seam',
        'title' => $row['name'],
        'text'  => $detail,
        'url'   => 'product/'
    ];
}


/* =====================================================
   KENDARAAN
===================================================== */

$s_kendaraan = $pdo->prepare("SELECT v.id, v.plate_number, v.brand, v.model, c.name AS customer_name
    FROM vehicles v
    LEFT JOIN customers c
        ON c.id = v.customer_id
    WHERE v.plate_number LIKE :search
       OR v.brand LIKE :search
       OR v.model LIKE :search
       OR c.name LIKE :search
    ORDER BY v.plate_number ASC
    LIMIT 5
");

$s_kendaraan->execute([':search' => $search]);

foreach ($s_kendaraan->fetchAll() as $row) {

    $vehicle = trim(
        ($row['brand'] ?? '') . ' ' .
        ($row['model'] ?? '')
    );

    $results[] = [
        'type'  => 'vehicle',
        'label' => 'Kendaraan',
        'icon'  => 'bi-car-front',
        'title' => $row['plate_number'],
        'text'  => $vehicle ?: ($row['customer_name'] ?? 'Data kendaraan'),
        'url'   => 'vehicles/'
    ];
}


/* =====================================================
   SUPPLIER
===================================================== */

$s_supplier = $pdo->prepare("SELECT id, name, phone, email
    FROM suppliers
    WHERE name LIKE :search
       OR phone LIKE :search
       OR email LIKE :search
    ORDER BY name ASC
    LIMIT 5
");

$s_supplier->execute([':search' => $search]);

foreach ($s_supplier->fetchAll() as $row) {

    $results[] = [
        'type'  => 'supplier',
        'label' => 'Supplier',
        'icon'  => 'bi-truck',
        'title' => $row['name'],
        'text'  => $row['phone'] ?: 'Data supplier',
        'url'   => 'supplier/'
    ];
}


/* =====================================================
   CABANG
===================================================== */

$s_cabang = $pdo->prepare("SELECT id, code, name, phone
    FROM cabangs
    WHERE name LIKE :search
       OR code LIKE :search
       OR phone LIKE :search
    ORDER BY name ASC
    LIMIT 5
");

$s_cabang->execute([':search' => $search]);

foreach ($s_cabang->fetchAll() as $row) {

    $results[] = [
        'type'  => 'branch',
        'label' => 'Cabang',
        'icon'  => 'bi-shop',
        'title' => $row['name'],
        'text'  => $row['code'],
        'url'   => 'cabang/'
    ];
}


/* =====================================================
   PENJUALAN / ORDER
===================================================== */

$s_penjualan = $pdo->prepare("SELECT id, order_number, order_date, grand_total, status
    FROM orders
    WHERE order_number LIKE :search
    ORDER BY order_date DESC
    LIMIT 5
");

$s_penjualan->execute([
    ':search' => $search
]);

foreach ($s_penjualan->fetchAll() as $row) {

    $results[] = [
        'type'  => 'order',
        'label' => 'Penjualan',
        'icon'  => 'bi-cart3',
        'title' => $row['order_number'],
        'text'  => statusLabelSearch($row['status']),
        'url'   => 'orders/'
    ];
}


/* =====================================================
   PEMBELIAN
===================================================== */

$s_pembelian = $pdo->prepare("SELECT id, purchase_number, purchase_date, grand_total, status
    FROM purchases
    WHERE purchase_number LIKE :search
    ORDER BY purchase_date DESC
    LIMIT 5
");

$s_pembelian->execute([':search' => $search]);

foreach ($s_pembelian->fetchAll() as $row) {

    $results[] = [
        'type'  => 'purchase',
        'label' => 'Pembelian',
        'icon'  => 'bi-bag',
        'title' => $row['purchase_number'],
        'text'  => statusLabelSearch($row['status']),
        'url'   => 'purchases/'
    ];
}


/* =====================================================
   TRANSFER STOK
===================================================== */

$s_transfer = $pdo->prepare("SELECT id, transfer_number, transfer_date, status
    FROM stock_transfers
    WHERE transfer_number LIKE :search
    ORDER BY transfer_date DESC
    LIMIT 5
");

$s_transfer->execute([':search' => $search]);

foreach ($s_transfer->fetchAll() as $row) {

    $results[] = [
        'type'  => 'transfer',
        'label' => 'Transfer Stok',
        'icon'  => 'bi-arrow-left-right',
        'title' => $row['transfer_number'],
        'text'  => statusLabelSearch($row['status']),
        'url'   => 'stoks-transfer/'
    ];
}


/* =====================================================
   BATASI HASIL
===================================================== */

$results = array_slice($results, 0, 12);


/* =====================================================
   RESPONSE
===================================================== */

echo json_encode([
    'success' => true,
    'data'    => $results
], JSON_UNESCAPED_UNICODE);


/* =====================================================
   STATUS LABEL
===================================================== */

function statusLabelSearch($status): string
{
    return match ($status) {

        'completed', 'received' => 'Selesai',
        'pending'               => 'Menunggu',
        'processing'            => 'Diproses',
        'ordered'               => 'Dipesan',
        'partial'               => 'Sebagian',
        'requested'             => 'Diminta',
        'approved'              => 'Disetujui',
        'shipped'               => 'Dikirim',
        'cancelled'             => 'Dibatalkan',
        'draft'                 => 'Draft',

        default => ucfirst((string)$status),
    };
}