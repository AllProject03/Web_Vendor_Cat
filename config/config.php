<?php

// ============================================================
// KONFIGURASI DATABASE
// Sistem Vendor Cat Mobil
// PHP Native + MAMP + MySQL
// ============================================================

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '8889');
define('DB_NAME', 'vendor_cat_mobil');
define('DB_USER', 'root');
define('DB_PASS', 'root');

// ============================================================
// KONEKSI DATABASE MENGGUNAKAN PDO
// ============================================================

try {

    $dsn = "mysql:host=" . DB_HOST .
           ";port=" . DB_PORT .
           ";dbname=" . DB_NAME .
           ";charset=utf8mb4";

    $pdo = new PDO($dsn, DB_USER, DB_PASS);

    // Mode error PDO
    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    // Hasil query menggunakan associative array
    $pdo->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );

    // Menonaktifkan emulasi prepared statement
    $pdo->setAttribute(
        PDO::ATTR_EMULATE_PREPARES,
        false
    );

} catch (PDOException $e) {

    die("Koneksi database gagal: " . $e->getMessage());

}