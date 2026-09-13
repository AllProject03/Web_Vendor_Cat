-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:8889
-- Waktu pembuatan: 13 Sep 2026 pada 11.49
-- Versi server: 8.0.44
-- Versi PHP: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Basis data: `vendor_cat_mobil`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `cabang_id` bigint UNSIGNED DEFAULT NULL,
  `module` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `reference_id` bigint UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `cabang_id`, `module`, `action`, `description`, `reference_id`, `ip_address`, `created_at`) VALUES
(4, 2, NULL, 'Pengaturan', 'DELETE', 'Menghapus seluruh log aktivitas', NULL, '::1', '2026-09-13 09:31:20');

-- --------------------------------------------------------

--
-- Struktur dari tabel `branch_stocks`
--

CREATE TABLE `branch_stocks` (
  `id` bigint UNSIGNED NOT NULL,
  `cabang_id` bigint UNSIGNED NOT NULL,
  `product_id` bigint UNSIGNED NOT NULL,
  `stock` decimal(15,2) NOT NULL DEFAULT '0.00',
  `minimum_stock` decimal(15,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `branch_stocks`
--

INSERT INTO `branch_stocks` (`id`, `cabang_id`, `product_id`, `stock`, `minimum_stock`) VALUES
(4, 2, 3, 4.00, 0.00);

-- --------------------------------------------------------

--
-- Struktur dari tabel `cabangs`
--

CREATE TABLE `cabangs` (
  `id` bigint UNSIGNED NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `cabangs`
--

INSERT INTO `cabangs` (`id`, `code`, `name`, `address`, `phone`, `status`) VALUES
(1, 'CBG001', 'Cabang Tangerang', 'Tangerang, Banten', '021-5551234', 'active'),
(2, 'JKT002', 'Cabang Jakarta', 'Jakarta Barat, Daerah Ibu Kota Jakarta', '0251-00093774', 'active');

-- --------------------------------------------------------

--
-- Struktur dari tabel `customers`
--

CREATE TABLE `customers` (
  `id` bigint UNSIGNED NOT NULL,
  `cabang_id` bigint UNSIGNED NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_type` enum('Penjualan','Produksi') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Produksi',
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `customers`
--

INSERT INTO `customers` (`id`, `cabang_id`, `name`, `customer_type`, `phone`, `email`, `address`, `created_at`, `status`) VALUES
(1, 1, 'Budi', 'Penjualan', '081212121212', 'budi@gmail.com', 'Jalan Mandar III DC10 No 66', '2026-09-09 03:50:44', 'active'),
(7, 1, 'Dumi001', 'Produksi', '00000000', 'dumi@gmail.com', 'Ciseeng', '2026-09-09 07:51:00', 'active'),
(8, 1, 'Dumi002', 'Penjualan', '00000000', 'dumi@gmail.com', 'Jakarta', '2026-09-09 08:26:29', 'active'),
(9, 1, 'Dumi003', 'Penjualan', '00000000', 'dumi@gmail.com', 'Ciseeng', '2026-09-09 08:27:18', 'active'),
(10, 1, 'Dumi004', 'Produksi', '00000000', 'dumi@gmail.com', 'Ciseeng', '2026-09-09 08:27:44', 'active'),
(11, 1, 'tes', 'Penjualan', '00000', 'tes@gmail.com', 'Ciseeng', '2026-09-09 08:28:25', 'inactive');

-- --------------------------------------------------------

--
-- Struktur dari tabel `expenses`
--

CREATE TABLE `expenses` (
  `id` bigint UNSIGNED NOT NULL,
  `cabang_id` bigint UNSIGNED NOT NULL,
  `expense_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expense_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('draft','submitted','approved','rejected','paid','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` bigint UNSIGNED NOT NULL,
  `approved_by` bigint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `expenses`
--

INSERT INTO `expenses` (`id`, `cabang_id`, `expense_number`, `expense_date`, `category`, `description`, `amount`, `status`, `created_by`, `approved_by`) VALUES
(1, 2, 'EXP-2026-0001', '2026-09-13 00:00:00', 'Gaji', 'Gaji Mekanik', 7500000.00, 'paid', 2, 2);

-- --------------------------------------------------------

--
-- Struktur dari tabel `orders`
--

CREATE TABLE `orders` (
  `id` bigint UNSIGNED NOT NULL,
  `order_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cabang_id` bigint UNSIGNED NOT NULL,
  `customer_id` bigint UNSIGNED NOT NULL,
  `vehicle_id` bigint UNSIGNED NOT NULL,
  `order_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `subtotal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax` decimal(15,2) NOT NULL DEFAULT '0.00',
  `grand_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('draft','pending','processing','completed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `orders`
--

INSERT INTO `orders` (`id`, `order_number`, `cabang_id`, `customer_id`, `vehicle_id`, `order_date`, `subtotal`, `discount`, `tax`, `grand_total`, `status`, `created_by`) VALUES
(3, 'ORD-2026-0001', 2, 1, 1, '2026-09-13 00:00:00', 1065000.00, 0.00, 0.00, 1065000.00, 'completed', 2);

-- --------------------------------------------------------

--
-- Struktur dari tabel `order_details`
--

CREATE TABLE `order_details` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` bigint UNSIGNED NOT NULL,
  `product_id` bigint UNSIGNED NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` decimal(15,2) NOT NULL DEFAULT '1.00',
  `price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `subtotal` decimal(15,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `order_details`
--

INSERT INTO `order_details` (`id`, `order_id`, `product_id`, `description`, `quantity`, `price`, `discount`, `subtotal`) VALUES
(6, 3, 3, 'Cat Merah', 1.00, 65000.00, 0.00, 65000.00);

-- --------------------------------------------------------

--
-- Struktur dari tabel `order_services`
--

CREATE TABLE `order_services` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` bigint UNSIGNED NOT NULL,
  `service_id` bigint UNSIGNED NOT NULL,
  `quantity` decimal(15,2) NOT NULL DEFAULT '1.00',
  `price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `subtotal` decimal(15,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `order_services`
--

INSERT INTO `order_services` (`id`, `order_id`, `service_id`, `quantity`, `price`, `discount`, `subtotal`) VALUES
(6, 3, 4, 1.00, 1000000.00, 0.00, 1000000.00);

-- --------------------------------------------------------

--
-- Struktur dari tabel `payments`
--

CREATE TABLE `payments` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` bigint UNSIGNED NOT NULL,
  `payment_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','transfer','qris','edc','other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','paid','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paid',
  `received_by` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `payments`
--

INSERT INTO `payments` (`id`, `order_id`, `payment_number`, `payment_date`, `amount`, `payment_method`, `status`, `received_by`) VALUES
(1, 3, 'PAY-2026-0001', '2026-09-13 00:00:00', 1065000.00, 'qris', 'paid', 2);

-- --------------------------------------------------------

--
-- Struktur dari tabel `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint UNSIGNED NOT NULL,
  `code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `module` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `permissions`
--

INSERT INTO `permissions` (`id`, `code`, `name`, `module`, `action`) VALUES
(1, 'dashboard.view', 'Dashboard', 'Dashboard', 'Lihat'),
(2, 'customers.view', 'Pelanggan', 'Pelanggan', 'Lihat'),
(3, 'customers.create', 'Pelanggan', 'Pelanggan', 'Tambah'),
(4, 'customers.edit', 'Pelanggan', 'Pelanggan', 'Edit'),
(5, 'customers.delete', 'Pelanggan', 'Pelanggan', 'Hapus'),
(6, 'vehicles.view', 'Kendaraan', 'Kendaraan', 'Lihat'),
(7, 'vehicles.create', 'Kendaraan', 'Kendaraan', 'Tambah'),
(8, 'vehicles.edit', 'Kendaraan', 'Kendaraan', 'Edit'),
(9, 'vehicles.delete', 'Kendaraan', 'Kendaraan', 'Hapus'),
(10, 'products.view', 'Produk', 'Produk', 'Lihat'),
(11, 'products.create', 'Produk', 'Produk', 'Tambah'),
(12, 'products.edit', 'Produk', 'Produk', 'Edit'),
(13, 'products.delete', 'Produk', 'Produk', 'Hapus'),
(14, 'services.view', 'Jasa', 'Jasa', 'Lihat'),
(15, 'services.create', 'Jasa', 'Jasa', 'Tambah'),
(16, 'services.edit', 'Jasa', 'Jasa', 'Edit'),
(17, 'services.delete', 'Jasa', 'Jasa', 'Hapus'),
(18, 'suppliers.view', 'Supplier', 'Supplier', 'Lihat'),
(19, 'suppliers.create', 'Supplier', 'Supplier', 'Tambah'),
(20, 'suppliers.edit', 'Supplier', 'Supplier', 'Edit'),
(21, 'suppliers.delete', 'Supplier', 'Supplier', 'Hapus'),
(22, 'branches.view', 'Cabang', 'Cabang', 'Lihat'),
(23, 'branches.create', 'Cabang', 'Cabang', 'Tambah'),
(24, 'branches.edit', 'Cabang', 'Cabang', 'Edit'),
(25, 'branches.delete', 'Cabang', 'Cabang', 'Hapus'),
(26, 'users.view', 'Pengguna', 'Pengguna', 'Lihat'),
(27, 'users.create', 'Pengguna', 'Pengguna', 'Tambah'),
(28, 'users.edit', 'Pengguna', 'Pengguna', 'Edit'),
(29, 'users.delete', 'Pengguna', 'Pengguna', 'Hapus'),
(30, 'roles.view', 'Role & Hak Akses', 'Role & Hak Akses', 'Lihat'),
(31, 'roles.create', 'Role & Hak Akses', 'Role & Hak Akses', 'Tambah'),
(32, 'roles.edit', 'Role & Hak Akses', 'Role & Hak Akses', 'Edit'),
(33, 'roles.delete', 'Role & Hak Akses', 'Role & Hak Akses', 'Hapus'),
(34, 'orders.view', 'Penjualan', 'Penjualan', 'Lihat'),
(35, 'orders.create', 'Penjualan', 'Penjualan', 'Tambah'),
(36, 'orders.edit', 'Penjualan', 'Penjualan', 'Edit'),
(37, 'orders.delete', 'Penjualan', 'Penjualan', 'Hapus'),
(38, 'purchases.view', 'Pembelian', 'Pembelian', 'Lihat'),
(39, 'purchases.create', 'Pembelian', 'Pembelian', 'Tambah'),
(40, 'purchases.edit', 'Pembelian', 'Pembelian', 'Edit'),
(41, 'purchases.delete', 'Pembelian', 'Pembelian', 'Hapus'),
(42, 'stock_transfer.view', 'Transfer Stok', 'Transfer Stok', 'Lihat'),
(43, 'stock_transfer.create', 'Transfer Stok', 'Transfer Stok', 'Tambah'),
(44, 'stock_transfer.edit', 'Transfer Stok', 'Transfer Stok', 'Edit'),
(45, 'stock_transfer.delete', 'Transfer Stok', 'Transfer Stok', 'Hapus'),
(46, 'payments.view', 'Pembayaran', 'Pembayaran', 'Lihat'),
(47, 'payments.create', 'Pembayaran', 'Pembayaran', 'Tambah'),
(48, 'payments.edit', 'Pembayaran', 'Pembayaran', 'Edit'),
(49, 'payments.delete', 'Pembayaran', 'Pembayaran', 'Hapus'),
(50, 'expenses.view', 'Pengeluaran', 'Pengeluaran', 'Lihat'),
(51, 'expenses.create', 'Pengeluaran', 'Pengeluaran', 'Tambah'),
(52, 'expenses.edit', 'Pengeluaran', 'Pengeluaran', 'Edit'),
(53, 'expenses.delete', 'Pengeluaran', 'Pengeluaran', 'Hapus'),
(54, 'stocks.view', 'Stok / Persediaan', 'Stok / Persediaan', 'Lihat'),
(55, 'stocks.create', 'Stok / Persediaan', 'Stok / Persediaan', 'Tambah'),
(56, 'stocks.edit', 'Stok / Persediaan', 'Stok / Persediaan', 'Edit'),
(57, 'stocks.delete', 'Stok / Persediaan', 'Stok / Persediaan', 'Hapus'),
(58, 'reports.view', 'Laporan', 'Laporan', 'Lihat'),
(59, 'settings.view', 'Pengaturan', 'Pengaturan', 'Lihat'),
(178, 'expenses.approve', 'Pengeluaran - Approve', 'Pengeluaran', 'Setujui'),
(179, 'expenses.pay', 'Pengeluaran - Bayar', 'Pengeluaran', 'Bayar'),
(180, 'expenses.cancel', 'Pengeluaran - Batal', 'Pengeluaran', 'Batalkan');

-- --------------------------------------------------------

--
-- Struktur dari tabel `products`
--

CREATE TABLE `products` (
  `id` bigint UNSIGNED NOT NULL,
  `category_id` bigint UNSIGNED NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `brand` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `purchase_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `selling_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `products`
--

INSERT INTO `products` (`id`, `category_id`, `code`, `name`, `brand`, `unit`, `purchase_price`, `selling_price`, `status`) VALUES
(2, 4, 'T4', 'Thiner 1L', 'Nippon', 'LITER', 75000.00, 100000.00, 'active'),
(3, 1, 'C4', 'Cat Merah', 'Nippon', 'LITER', 25000.00, 65000.00, 'active'),
(4, 1, 'C43', 'Merah', 'Nippon', 'LITER', 25000.00, 50000.00, 'inactive');

-- --------------------------------------------------------

--
-- Struktur dari tabel `product_categories`
--

CREATE TABLE `product_categories` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `product_categories`
--

INSERT INTO `product_categories` (`id`, `name`, `description`) VALUES
(1, 'Cat', 'Produk cat kendaraan'),
(2, 'Primer', 'Produk primer kendaraan'),
(3, 'Clear Coat', 'Produk lapisan clear coat'),
(4, 'Thinner', 'Produk thinner'),
(5, 'Hardener', 'Produk hardener'),
(6, 'Polish', 'Produk polishing');

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases`
--

CREATE TABLE `purchases` (
  `id` bigint UNSIGNED NOT NULL,
  `purchase_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_id` bigint UNSIGNED NOT NULL,
  `cabang_id` bigint UNSIGNED NOT NULL,
  `purchase_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `subtotal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax` decimal(15,2) NOT NULL DEFAULT '0.00',
  `grand_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('draft','ordered','received','partial','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `purchases`
--

INSERT INTO `purchases` (`id`, `purchase_number`, `supplier_id`, `cabang_id`, `purchase_date`, `subtotal`, `discount`, `tax`, `grand_total`, `status`, `created_by`) VALUES
(3, 'PUR-2026-0001', 3, 2, '2026-09-13 00:00:00', 125000.00, 0.00, 0.00, 125000.00, 'received', 2);

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchase_details`
--

CREATE TABLE `purchase_details` (
  `id` bigint UNSIGNED NOT NULL,
  `purchase_id` bigint UNSIGNED NOT NULL,
  `product_id` bigint UNSIGNED NOT NULL,
  `quantity` decimal(15,2) NOT NULL DEFAULT '1.00',
  `received_quantity` decimal(15,2) NOT NULL DEFAULT '0.00',
  `price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `subtotal` decimal(15,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `purchase_details`
--

INSERT INTO `purchase_details` (`id`, `purchase_id`, `product_id`, `quantity`, `received_quantity`, `price`, `discount`, `subtotal`) VALUES
(5, 3, 3, 5.00, 5.00, 25000.00, 0.00, 125000.00);

-- --------------------------------------------------------

--
-- Struktur dari tabel `roles`
--

CREATE TABLE `roles` (
  `id` bigint UNSIGNED NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `roles`
--

INSERT INTO `roles` (`id`, `code`, `name`, `description`) VALUES
(1, 'ADMIN', 'Admin', 'Mengelola seluruh sistem dan seluruh cabang'),
(2, 'ADMIN_CABANG', 'Admin Cabang', 'Mengelola operasional cabang masing-masing'),
(3, 'FINANCE', 'Finance', 'Mengelola pembayaran, pengeluaran, dan laporan keuangan');

-- --------------------------------------------------------

--
-- Struktur dari tabel `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` bigint UNSIGNED NOT NULL,
  `role_id` bigint UNSIGNED NOT NULL,
  `permission_id` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `role_permissions`
--

INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`) VALUES
(9, 1, 1),
(8, 1, 2),
(5, 1, 3),
(7, 1, 4),
(6, 1, 5),
(59, 1, 6),
(56, 1, 7),
(58, 1, 8),
(57, 1, 9),
(25, 1, 10),
(22, 1, 11),
(24, 1, 12),
(23, 1, 13),
(38, 1, 14),
(35, 1, 15),
(37, 1, 16),
(36, 1, 17),
(51, 1, 18),
(48, 1, 19),
(50, 1, 20),
(49, 1, 21),
(4, 1, 22),
(1, 1, 23),
(3, 1, 24),
(2, 1, 25),
(55, 1, 26),
(52, 1, 27),
(54, 1, 28),
(53, 1, 29),
(34, 1, 30),
(31, 1, 31),
(33, 1, 32),
(32, 1, 33),
(17, 1, 34),
(14, 1, 35),
(16, 1, 36),
(15, 1, 37),
(29, 1, 38),
(26, 1, 39),
(28, 1, 40),
(27, 1, 41),
(43, 1, 42),
(40, 1, 43),
(42, 1, 44),
(41, 1, 45),
(21, 1, 46),
(18, 1, 47),
(20, 1, 48),
(19, 1, 49),
(13, 1, 50),
(10, 1, 51),
(12, 1, 52),
(11, 1, 53),
(47, 1, 54),
(44, 1, 55),
(46, 1, 56),
(45, 1, 57),
(30, 1, 58),
(39, 1, 59),
(75, 2, 50),
(71, 2, 51),
(73, 2, 52),
(64, 2, 178),
(65, 2, 180),
(74, 3, 50),
(70, 3, 51),
(72, 3, 52),
(67, 3, 178),
(69, 3, 179),
(68, 3, 180);

-- --------------------------------------------------------

--
-- Struktur dari tabel `services`
--

CREATE TABLE `services` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `service_type` enum('Service','Painting') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Service',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `services`
--

INSERT INTO `services` (`id`, `name`, `service_type`, `description`, `price`, `status`) VALUES
(4, 'Full Body Painting', 'Painting', '', 1000000.00, 'active'),
(5, 'Perbaikan Pintu depan', 'Service', '', 650000.00, 'active');

-- --------------------------------------------------------

--
-- Struktur dari tabel `settings`
--

CREATE TABLE `settings` (
  `id` bigint UNSIGNED NOT NULL,
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `file_data` mediumblob,
  `file_mime` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int UNSIGNED DEFAULT NULL,
  `setting_group` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` bigint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `file_data`, `file_mime`, `file_name`, `file_size`, `setting_group`, `description`, `updated_at`, `updated_by`) VALUES
(1, 'company_name', 'PT. GIAN GANESHA NAWASENA', NULL, NULL, NULL, NULL, 'general', 'Nama perusahaan', '2026-09-13 09:13:08', NULL),
(2, 'company_address', '', NULL, NULL, NULL, NULL, 'general', 'Alamat perusahaan', '2026-09-13 09:13:08', NULL),
(3, 'company_phone', '', NULL, NULL, NULL, NULL, 'general', 'Telepon perusahaan', '2026-09-13 09:13:08', NULL),
(4, 'company_email', '', NULL, NULL, NULL, NULL, 'general', 'Email perusahaan', '2026-09-13 09:13:08', NULL),
(5, 'currency', 'IDR', NULL, NULL, NULL, NULL, 'general', 'Mata uang aplikasi', '2026-09-13 09:13:08', NULL),
(6, 'timezone', 'Asia/Jakarta', NULL, NULL, NULL, NULL, 'general', 'Zona waktu aplikasi', '2026-09-13 09:13:08', NULL),
(7, 'sales_prefix', 'SO-', NULL, NULL, NULL, NULL, 'transaction', 'Prefix nomor penjualan', '2026-09-13 09:13:08', NULL),
(8, 'purchase_prefix', 'PO-', NULL, NULL, NULL, NULL, 'transaction', 'Prefix nomor pembelian', '2026-09-13 09:13:08', NULL),
(9, 'payment_prefix', 'PAY-', NULL, NULL, NULL, NULL, 'transaction', 'Prefix nomor pembayaran', '2026-09-13 09:13:08', NULL),
(10, 'expense_prefix', 'EXP-', NULL, NULL, NULL, NULL, 'transaction', 'Prefix nomor pengeluaran', '2026-09-13 09:13:08', NULL),
(11, 'transfer_prefix', 'TRF-', NULL, NULL, NULL, NULL, 'transaction', 'Prefix nomor transfer stok', '2026-09-13 09:13:08', NULL),
(12, 'default_tax', '0', NULL, NULL, NULL, NULL, 'transaction', 'Pajak default dalam persen', '2026-09-13 09:13:08', NULL),
(13, 'default_discount', '0', NULL, NULL, NULL, NULL, 'transaction', 'Diskon default dalam persen', '2026-09-13 09:13:08', NULL),
(14, 'minimum_stock_default', '0', NULL, NULL, NULL, NULL, 'inventory', 'Minimum stok default', '2026-09-13 09:13:08', NULL),
(15, 'allow_negative_stock', '0', NULL, NULL, NULL, NULL, 'inventory', 'Mengizinkan stok negatif', '2026-09-13 09:13:08', NULL),
(16, 'low_stock_threshold', '1', NULL, NULL, NULL, NULL, 'inventory', 'Status menipis saat stok sama dengan atau di bawah nilai ini', '2026-09-13 09:13:08', NULL),
(17, 'session_timeout_minutes', '120', NULL, NULL, NULL, NULL, 'security', 'Durasi sesi dalam menit', '2026-09-13 09:30:33', 2),
(18, 'password_min_length', '6', NULL, NULL, NULL, NULL, 'security', 'Panjang minimum password', '2026-09-13 09:30:33', 2),
(19, 'notify_low_stock', '1', NULL, NULL, NULL, NULL, 'notification', 'Notifikasi stok minimum', '2026-09-13 09:13:08', NULL),
(20, 'notify_pending_expense', '1', NULL, NULL, NULL, NULL, 'notification', 'Notifikasi pengeluaran menunggu approval', '2026-09-13 09:13:08', NULL),
(21, 'notify_pending_transfer', '1', NULL, NULL, NULL, NULL, 'notification', 'Notifikasi transfer menunggu proses', '2026-09-13 09:13:08', NULL),
(275, 'company_tagline', 'Sistem Vendor Cat Mobil', NULL, NULL, NULL, NULL, 'general', 'Tagline atau subjudul perusahaan', '2026-09-13 09:26:44', NULL),
(276, 'company_logo', '../../assets/img/logo.png', NULL, NULL, NULL, NULL, 'general', 'Path logo perusahaan, relatif dari halaman', '2026-09-13 09:26:44', NULL),
(279, 'company_whatsapp', '', NULL, NULL, NULL, NULL, 'general', 'Nomor WhatsApp perusahaan', '2026-09-13 09:26:44', NULL),
(281, 'company_website', '', NULL, NULL, NULL, NULL, 'general', 'Website perusahaan', '2026-09-13 09:26:44', NULL),
(282, 'company_npwp', '', NULL, NULL, NULL, NULL, 'general', 'NPWP perusahaan', '2026-09-13 09:26:44', NULL),
(283, 'company_description', '', NULL, NULL, NULL, NULL, 'general', 'Deskripsi singkat perusahaan', '2026-09-13 09:26:44', NULL),
(284, 'company_about', '', NULL, NULL, NULL, NULL, 'general', 'Profil/tentang perusahaan', '2026-09-13 09:26:44', NULL),
(285, 'company_vision', '', NULL, NULL, NULL, NULL, 'general', 'Visi perusahaan', '2026-09-13 09:26:44', NULL),
(286, 'company_mission', '', NULL, NULL, NULL, NULL, 'general', 'Misi perusahaan', '2026-09-13 09:26:44', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `stock_movements`
--

CREATE TABLE `stock_movements` (
  `id` bigint UNSIGNED NOT NULL,
  `branch_stock_id` bigint UNSIGNED NOT NULL,
  `movement_type` enum('purchase_in','sale_out','transfer_in','transfer_out','adjustment_in','adjustment_out','return_in','return_out') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(15,2) NOT NULL,
  `reference_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` bigint UNSIGNED DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `movement_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `stock_movements`
--

INSERT INTO `stock_movements` (`id`, `branch_stock_id`, `movement_type`, `quantity`, `reference_type`, `reference_id`, `notes`, `movement_date`, `created_by`) VALUES
(1, 4, 'purchase_in', 5.00, 'purchase', 3, 'Penerimaan pembelian', '2026-09-13 13:13:41', 2),
(2, 4, 'sale_out', 1.00, 'order', 3, 'Pengurangan stok karena penjualan', '2026-09-13 13:14:46', 2);

-- --------------------------------------------------------

--
-- Struktur dari tabel `stock_transfers`
--

CREATE TABLE `stock_transfers` (
  `id` bigint UNSIGNED NOT NULL,
  `transfer_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_cabang_id` bigint UNSIGNED NOT NULL,
  `to_cabang_id` bigint UNSIGNED NOT NULL,
  `transfer_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('draft','requested','approved','shipped','received','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint UNSIGNED NOT NULL,
  `approved_by` bigint UNSIGNED DEFAULT NULL,
  `received_by` bigint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `stock_transfers`
--

INSERT INTO `stock_transfers` (`id`, `transfer_number`, `from_cabang_id`, `to_cabang_id`, `transfer_date`, `status`, `notes`, `created_by`, `approved_by`, `received_by`) VALUES
(2, 'TRF-2026-0001', 2, 1, '2026-09-13 00:00:00', 'draft', NULL, 2, NULL, NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `stock_transfer_details`
--

CREATE TABLE `stock_transfer_details` (
  `id` bigint UNSIGNED NOT NULL,
  `stock_transfer_id` bigint UNSIGNED NOT NULL,
  `product_id` bigint UNSIGNED NOT NULL,
  `quantity` decimal(15,2) NOT NULL DEFAULT '0.00',
  `received_quantity` decimal(15,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `stock_transfer_details`
--

INSERT INTO `stock_transfer_details` (`id`, `stock_transfer_id`, `product_id`, `quantity`, `received_quantity`) VALUES
(2, 2, 3, 2.00, 0.00);

-- --------------------------------------------------------

--
-- Struktur dari tabel `suppliers`
--

CREATE TABLE `suppliers` (
  `id` bigint UNSIGNED NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `suppliers`
--

INSERT INTO `suppliers` (`id`, `code`, `name`, `contact_person`, `phone`, `email`, `address`, `status`, `created_at`) VALUES
(3, 'SUP-0002', 'PT. JAYA', 'Dumi', '081121211', 'dumi@gmail.com', 'Jakarta Barat', 'active', '2026-09-11 07:37:27');

-- --------------------------------------------------------

--
-- Struktur dari tabel `users`
--

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
  `role_id` bigint UNSIGNED NOT NULL,
  `cabang_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `users`
--

INSERT INTO `users` (`id`, `role_id`, `cabang_id`, `name`, `email`, `password`, `phone`, `status`, `created_at`) VALUES
(1, 1, NULL, 'Admin Utama', 'admin@vendorcat.com', '$2y$10$ic/nfInNcasl/FKR4TA1sOSvm4Da/BW0jqmRHm6/U3M4Ls6R5Zt9e', '081234567890', 'active', '2026-09-07 15:44:41'),
(2, 1, NULL, 'Ahmad', 'ahmad@gmail.com', '$2y$10$sU2wT22kOktd48Zay8lEnO/Pi/2TS/cNy5xvWJTa3a1ev5Ey7N7Oq', '081288123884', 'active', '2026-09-12 05:12:57');

-- --------------------------------------------------------

--
-- Struktur dari tabel `vehicles`
--

CREATE TABLE `vehicles` (
  `id` bigint UNSIGNED NOT NULL,
  `customer_id` bigint UNSIGNED NOT NULL,
  `cabang_id` bigint UNSIGNED NOT NULL,
  `plate_number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `brand` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year` year DEFAULT NULL,
  `color` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vin_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `vehicles`
--

INSERT INTO `vehicles` (`id`, `customer_id`, `cabang_id`, `plate_number`, `brand`, `model`, `year`, `color`, `vin_number`, `note`, `status`) VALUES
(1, 1, 1, 'B 1234 ABC', 'Toyota', 'Avanza', '2022', 'Hitam', 'MHKA1234567890123', NULL, 'active'),
(2, 7, 1, 'B 1123 ACC', 'Toyota', 'Avanza 1.5', '2016', 'Hitam', '', 'Cat Ulang', 'active');

--
-- Indeks untuk tabel yang dibuang
--

--
-- Indeks untuk tabel `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_activity_user` (`user_id`),
  ADD KEY `idx_activity_cabang` (`cabang_id`),
  ADD KEY `idx_activity_module` (`module`),
  ADD KEY `idx_activity_created` (`created_at`);

--
-- Indeks untuk tabel `branch_stocks`
--
ALTER TABLE `branch_stocks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_branch_product` (`cabang_id`,`product_id`),
  ADD KEY `idx_branch_stocks_product_id` (`product_id`);

--
-- Indeks untuk tabel `cabangs`
--
ALTER TABLE `cabangs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indeks untuk tabel `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customers_cabang_id` (`cabang_id`),
  ADD KEY `idx_customers_name` (`name`),
  ADD KEY `idx_customers_phone` (`phone`);

--
-- Indeks untuk tabel `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `expense_number` (`expense_number`),
  ADD KEY `idx_expenses_cabang_id` (`cabang_id`),
  ADD KEY `idx_expenses_expense_date` (`expense_date`),
  ADD KEY `idx_expenses_status` (`status`),
  ADD KEY `idx_expenses_created_by` (`created_by`),
  ADD KEY `idx_expenses_approved_by` (`approved_by`);

--
-- Indeks untuk tabel `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `fk_orders_vehicle_customer` (`vehicle_id`,`customer_id`),
  ADD KEY `idx_orders_cabang_id` (`cabang_id`),
  ADD KEY `idx_orders_customer_id` (`customer_id`),
  ADD KEY `idx_orders_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_orders_order_date` (`order_date`),
  ADD KEY `idx_orders_status` (`status`),
  ADD KEY `idx_orders_created_by` (`created_by`);

--
-- Indeks untuk tabel `order_details`
--
ALTER TABLE `order_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_details_order_id` (`order_id`),
  ADD KEY `idx_order_details_product_id` (`product_id`);

--
-- Indeks untuk tabel `order_services`
--
ALTER TABLE `order_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_services_order_id` (`order_id`),
  ADD KEY `idx_order_services_service_id` (`service_id`);

--
-- Indeks untuk tabel `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_number` (`payment_number`),
  ADD KEY `idx_payments_order_id` (`order_id`),
  ADD KEY `idx_payments_payment_date` (`payment_date`),
  ADD KEY `idx_payments_status` (`status`),
  ADD KEY `idx_payments_received_by` (`received_by`);

--
-- Indeks untuk tabel `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_permissions_code` (`code`);

--
-- Indeks untuk tabel `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_products_category_id` (`category_id`),
  ADD KEY `idx_products_name` (`name`);

--
-- Indeks untuk tabel `product_categories`
--
ALTER TABLE `product_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indeks untuk tabel `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `purchase_number` (`purchase_number`),
  ADD KEY `idx_purchases_supplier_id` (`supplier_id`),
  ADD KEY `idx_purchases_cabang_id` (`cabang_id`),
  ADD KEY `idx_purchases_purchase_date` (`purchase_date`),
  ADD KEY `idx_purchases_status` (`status`),
  ADD KEY `idx_purchases_created_by` (`created_by`);

--
-- Indeks untuk tabel `purchase_details`
--
ALTER TABLE `purchase_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_details_purchase_id` (`purchase_id`),
  ADD KEY `idx_purchase_details_product_id` (`product_id`);

--
-- Indeks untuk tabel `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD UNIQUE KEY `uq_roles_code` (`code`);

--
-- Indeks untuk tabel `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_role_permission` (`role_id`,`permission_id`),
  ADD KEY `fk_role_permissions_permission` (`permission_id`);

--
-- Indeks untuk tabel `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `idx_settings_group` (`setting_group`),
  ADD KEY `fk_settings_updated_by` (`updated_by`);

--
-- Indeks untuk tabel `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stock_movements_branch_stock` (`branch_stock_id`),
  ADD KEY `idx_stock_movements_type` (`movement_type`),
  ADD KEY `idx_stock_movements_reference` (`reference_type`,`reference_id`),
  ADD KEY `idx_stock_movements_date` (`movement_date`),
  ADD KEY `idx_stock_movements_created_by` (`created_by`);

--
-- Indeks untuk tabel `stock_transfers`
--
ALTER TABLE `stock_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transfer_number` (`transfer_number`),
  ADD KEY `fk_stock_transfers_created_by` (`created_by`),
  ADD KEY `fk_stock_transfers_approved_by` (`approved_by`),
  ADD KEY `fk_stock_transfers_received_by` (`received_by`),
  ADD KEY `idx_stock_transfers_from_cabang` (`from_cabang_id`),
  ADD KEY `idx_stock_transfers_to_cabang` (`to_cabang_id`),
  ADD KEY `idx_stock_transfers_status` (`status`),
  ADD KEY `idx_stock_transfers_date` (`transfer_date`);

--
-- Indeks untuk tabel `stock_transfer_details`
--
ALTER TABLE `stock_transfer_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stock_transfer_details_transfer` (`stock_transfer_id`),
  ADD KEY `idx_stock_transfer_details_product` (`product_id`);

--
-- Indeks untuk tabel `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_suppliers_name` (`name`),
  ADD KEY `idx_suppliers_phone` (`phone`);

--
-- Indeks untuk tabel `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role_id` (`role_id`),
  ADD KEY `idx_users_cabang_id` (`cabang_id`);

--
-- Indeks untuk tabel `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_vehicles_plate_number` (`plate_number`),
  ADD UNIQUE KEY `uk_vehicles_id_customer` (`id`,`customer_id`),
  ADD UNIQUE KEY `uk_vehicles_vin_number` (`vin_number`),
  ADD KEY `idx_vehicles_customer_id` (`customer_id`),
  ADD KEY `fk_vehicles_cabang` (`cabang_id`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT untuk tabel `branch_stocks`
--
ALTER TABLE `branch_stocks`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT untuk tabel `cabangs`
--
ALTER TABLE `cabangs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `customers`
--
ALTER TABLE `customers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT untuk tabel `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `orders`
--
ALTER TABLE `orders`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT untuk tabel `order_details`
--
ALTER TABLE `order_details`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT untuk tabel `order_services`
--
ALTER TABLE `order_services`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT untuk tabel `payments`
--
ALTER TABLE `payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=181;

--
-- AUTO_INCREMENT untuk tabel `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT untuk tabel `product_categories`
--
ALTER TABLE `product_categories`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT untuk tabel `purchases`
--
ALTER TABLE `purchases`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT untuk tabel `purchase_details`
--
ALTER TABLE `purchase_details`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT untuk tabel `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT untuk tabel `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT untuk tabel `services`
--
ALTER TABLE `services`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT untuk tabel `settings`
--
ALTER TABLE `settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=904;

--
-- AUTO_INCREMENT untuk tabel `stock_movements`
--
ALTER TABLE `stock_movements`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `stock_transfers`
--
ALTER TABLE `stock_transfers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `stock_transfer_details`
--
ALTER TABLE `stock_transfer_details`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT untuk tabel `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `fk_activity_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabangs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `branch_stocks`
--
ALTER TABLE `branch_stocks`
  ADD CONSTRAINT `fk_branch_stocks_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabangs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_branch_stocks_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabangs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_expenses_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_expenses_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabangs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_expenses_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabangs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_vehicle_customer` FOREIGN KEY (`vehicle_id`,`customer_id`) REFERENCES `vehicles` (`id`, `customer_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `order_details`
--
ALTER TABLE `order_details`
  ADD CONSTRAINT `fk_order_details_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_order_details_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `order_services`
--
ALTER TABLE `order_services`
  ADD CONSTRAINT `fk_order_services_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_order_services_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_payments_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `fk_purchases_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabangs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_purchases_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_purchases_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `purchase_details`
--
ALTER TABLE `purchase_details`
  ADD CONSTRAINT `fk_purchase_details_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_purchase_details_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `settings`
--
ALTER TABLE `settings`
  ADD CONSTRAINT `fk_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD CONSTRAINT `fk_stock_movements_branch_stock` FOREIGN KEY (`branch_stock_id`) REFERENCES `branch_stocks` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_stock_movements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `stock_transfers`
--
ALTER TABLE `stock_transfers`
  ADD CONSTRAINT `fk_stock_transfers_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_stock_transfers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_stock_transfers_from_cabang` FOREIGN KEY (`from_cabang_id`) REFERENCES `cabangs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_stock_transfers_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_stock_transfers_to_cabang` FOREIGN KEY (`to_cabang_id`) REFERENCES `cabangs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `stock_transfer_details`
--
ALTER TABLE `stock_transfer_details`
  ADD CONSTRAINT `fk_stock_transfer_details_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_stock_transfer_details_transfer` FOREIGN KEY (`stock_transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabangs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `fk_vehicles_cabang` FOREIGN KEY (`cabang_id`) REFERENCES `cabangs` (`id`),
  ADD CONSTRAINT `fk_vehicles_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
