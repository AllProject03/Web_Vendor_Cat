<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/admin/settings/company.php';

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function safeCount(PDO $pdo, string $sql): int
{
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function safeRows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

$company = get_company_profile($pdo);

$companyName = trim((string)($company['company_name'] ?? '')) !== ''
    ? (string)$company['company_name']
    : 'Vendor Cat Mobil';

$companyTagline = trim((string)($company['company_tagline'] ?? '')) !== ''
    ? (string)$company['company_tagline']
    : 'Sistem Vendor Cat Mobil';

$companyDescription = trim((string)($company['company_description'] ?? '')) !== ''
    ? (string)$company['company_description']
    : 'Solusi kebutuhan cat dan perlengkapan pengecatan kendaraan untuk bengkel, pelanggan, dan mitra bisnis.';

$companyAbout = trim((string)($company['company_about'] ?? '')) !== ''
    ? (string)$company['company_about']
    : $companyDescription;

$companyVision = trim((string)($company['company_vision'] ?? '')) !== ''
    ? (string)$company['company_vision']
    : 'Menjadi mitra terpercaya dalam penyediaan kebutuhan cat dan perlengkapan kendaraan.';

$companyMission = trim((string)($company['company_mission'] ?? '')) !== ''
    ? (string)$company['company_mission']
    : 'Memberikan produk berkualitas, pelayanan cepat, dan pengalaman pemesanan yang terpercaya.';

$logoUrl = !empty($company['company_logo_url'])
    ? (string)$company['company_logo_url']
    : 'assets/img/logo.png';

$websiteUrl = trim((string)($company['company_website'] ?? ''));
if ($websiteUrl !== '' && !preg_match('~^https?://~i', $websiteUrl)) {
    $websiteUrl = 'https://' . $websiteUrl;
}

$whatsappRaw = preg_replace('/\D+/', '', (string)($company['company_whatsapp'] ?? ''));
$whatsappUrl = '';

if ($whatsappRaw !== '') {
    if (str_starts_with($whatsappRaw, '0')) {
        $whatsappRaw = '62' . substr($whatsappRaw, 1);
    }

    $whatsappUrl = 'https://wa.me/' . $whatsappRaw . '?text=' . rawurlencode(
        'Halo, saya ingin bertanya tentang produk cat mobil.'
    );
}

$phone = trim((string)($company['company_phone'] ?? ''));
$email = trim((string)($company['company_email'] ?? ''));
$address = trim((string)($company['company_address'] ?? ''));
$whatsappDisplay = trim((string)($company['company_whatsapp'] ?? ''));
$npwp = trim((string)($company['company_npwp'] ?? ''));

$productCount = safeCount($pdo, "SELECT COUNT(*) FROM products WHERE status = 'active'");
$serviceCount = safeCount($pdo, "SELECT COUNT(*) FROM services WHERE status = 'active'");
$branchCount = safeCount($pdo, "SELECT COUNT(*) FROM cabangs WHERE status = 'active'");

$featuredProducts = safeRows(
    $pdo,
    "SELECT p.name, p.brand, p.unit, p.selling_price
     FROM products p
     WHERE p.status = 'active'
     ORDER BY p.id DESC
     LIMIT 6"
);

$pageTitle = $companyName;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= h($companyDescription) ?>">
    <meta name="theme-color" content="#111827">
    <title><?= h($pageTitle) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/index.css?v=20260913">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark site-navbar">
    <div class="container navbar-inner">
        <a class="navbar-brand company-brand" href="index.php" aria-label="<?= h($companyName) ?>">
            <span class="brand-logo-wrap">
                <img src="<?= h($logoUrl) ?>" alt="Logo <?= h($companyName) ?>" class="brand-logo">
            </span>
            <span class="brand-copy">
                <strong><?= h($companyName) ?></strong>
                <small><?= h($companyTagline) ?></small>
            </span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#navbarMenu" aria-controls="navbarMenu"
                aria-expanded="false" aria-label="Buka navigasi">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMenu">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <li class="nav-item">
                    <a class="nav-link" href="#layanan">Layanan</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#produk">Produk</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#tentang">Tentang</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#kontak">Kontak</a>
                </li>
                <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                    <a href="login/login.php" class="btn btn-light nav-login">
                        Login
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<header class="hero-section">
    <div class="hero-overlay"></div>
    <div class="container position-relative">
        <div class="row align-items-center hero-row">
            <div class="col-lg-8 col-xl-7">
                <div class="hero-content">
                    <span class="hero-kicker">
                        <i class="bi bi-stars"></i>
                        <?= h($companyTagline) ?>
                    </span>

                    <h1><?= h($companyName) ?></h1>

                    <p class="hero-title">
                        Lengkapi kebutuhan cat mobil Anda dengan produk dan layanan terpercaya.
                    </p>

                    <p class="hero-description">
                        <?= h($companyDescription) ?>
                    </p>

                    <div class="hero-actions">
                        <a href="#layanan" class="btn btn-primary btn-lg">
                            Jelajahi Layanan
                            <i class="bi bi-arrow-down-short"></i>
                        </a>

                        <?php if ($whatsappUrl !== ''): ?>
                            <a href="<?= h($whatsappUrl) ?>"
                               class="btn btn-outline-light btn-lg"
                               target="_blank"
                               rel="noopener noreferrer">
                                <i class="bi bi-whatsapp"></i>
                                Hubungi Kami
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<section class="stats-strip" aria-label="Statistik perusahaan">
    <div class="container">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-palette2"></i>
                    </div>
                    <div>
                        <strong><?= number_format($productCount, 0, ',', '.') ?></strong>
                        <span>Produk aktif</span>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-tools"></i>
                    </div>
                    <div>
                        <strong><?= number_format($serviceCount, 0, ',', '.') ?></strong>
                        <span>Layanan aktif</span>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-shop"></i>
                    </div>
                    <div>
                        <strong><?= number_format($branchCount, 0, ',', '.') ?></strong>
                        <span>Cabang aktif</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="layanan" class="section section-light">
    <div class="container">
        <div class="section-heading text-center">
            <span class="section-kicker">Layanan & Keunggulan</span>
            <h2>Solusi kebutuhan pengecatan kendaraan</h2>
            <p>
                Kami menghadirkan produk dan layanan yang mendukung kebutuhan bengkel,
                pelanggan, dan mitra bisnis.
            </p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <article class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="bi bi-palette"></i>
                    </div>
                    <h3>Produk Berkualitas</h3>
                    <p>
                        Pilihan produk cat dan perlengkapan pengecatan untuk membantu
                        menghasilkan pekerjaan yang rapi dan profesional.
                    </p>
                </article>
            </div>

            <div class="col-md-4">
                <article class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="bi bi-lightning-charge"></i>
                    </div>
                    <h3>Pemesanan Mudah</h3>
                    <p>
                        Proses pemesanan yang praktis dengan informasi produk yang
                        terintegrasi dalam satu sistem.
                    </p>
                </article>
            </div>

            <div class="col-md-4">
                <article class="feature-card h-100">
                    <div class="feature-icon">
                        <i class="bi bi-shield-check"></i>
                    </div>
                    <h3>Pelayanan Terpercaya</h3>
                    <p>
                        Informasi perusahaan, produk, dan layanan dikelola secara
                        terpusat sehingga lebih konsisten dan mudah diperbarui.
                    </p>
                </article>
            </div>
        </div>
    </div>
</section>

<?php if ($featuredProducts): ?>
<section id="produk" class="section section-white">
    <div class="container">
        <div class="section-heading product-heading">
            <div>
                <span class="section-kicker">Produk Terbaru</span>
                <h2>Produk yang tersedia</h2>
                <p>Beberapa produk aktif yang tercatat pada sistem.</p>
            </div>

            <a href="login/login.php" class="text-link">
                Masuk ke sistem
                <i class="bi bi-arrow-right"></i>
            </a>
        </div>

        <div class="row g-4">
            <?php foreach ($featuredProducts as $product): ?>
                <div class="col-md-6 col-lg-4">
                    <article class="product-card h-100">
                        <div class="product-icon">
                            <i class="bi bi-droplet-half"></i>
                        </div>
                        <div class="product-copy">
                            <span class="product-brand">
                                <?= h($product['brand'] ?: 'Produk') ?>
                            </span>

                            <h3><?= h($product['name']) ?></h3>

                            <p>
                                Satuan:
                                <strong><?= h($product['unit']) ?></strong>
                            </p>

                            <strong class="product-price">
                                Rp <?= number_format((float)$product['selling_price'], 0, ',', '.') ?>
                            </strong>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section id="tentang" class="section about-section">
    <div class="container">
        <div class="row g-4 g-lg-5 align-items-stretch">
            <div class="col-lg-7">
                <div class="about-copy">
                    <span class="section-kicker">Tentang Kami</span>
                    <h2><?= h($companyName) ?></h2>
                    <p class="lead-copy">
                        <?= nl2br(h($companyAbout)) ?>
                    </p>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="vision-card h-100">
                    <div class="vision-block">
                        <span>Visi</span>
                        <p><?= nl2br(h($companyVision)) ?></p>
                    </div>
                    <div class="vision-divider"></div>
                    <div class="vision-block">
                        <span>Misi</span>
                        <p><?= nl2br(h($companyMission)) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="kontak" class="section section-light">
    <div class="container">
        <div class="section-heading text-center">
            <span class="section-kicker">Hubungi Kami</span>
            <h2>Informasi perusahaan</h2>
            <p>
                Seluruh informasi berikut dikelola oleh Admin melalui menu Pengaturan.
            </p>
        </div>

        <div class="contact-panel">
            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="bi bi-geo-alt"></i>
                        </div>
                        <div>
                            <span>Alamat</span>
                            <p><?= h($address !== '' ? $address : 'Belum diatur') ?></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="bi bi-telephone"></i>
                        </div>
                        <div>
                            <span>Telepon</span>
                            <p><?= h($phone !== '' ? $phone : 'Belum diatur') ?></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="bi bi-envelope"></i>
                        </div>
                        <div>
                            <span>Email</span>
                            <p><?= h($email !== '' ? $email : 'Belum diatur') ?></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="bi bi-whatsapp"></i>
                        </div>
                        <div>
                            <span>WhatsApp</span>
                            <p><?= h($whatsappDisplay !== '' ? $whatsappDisplay : 'Belum diatur') ?></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="bi bi-globe2"></i>
                        </div>
                        <div>
                            <span>Website</span>
                            <p><?= h($websiteUrl !== '' ? $websiteUrl : 'Belum diatur') ?></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="bi bi-building"></i>
                        </div>
                        <div>
                            <span>NPWP</span>
                            <p><?= h($npwp !== '' ? $npwp : 'Belum diatur') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="contact-actions">
                <?php if ($whatsappUrl !== ''): ?>
                    <a href="<?= h($whatsappUrl) ?>"
                       class="btn btn-primary"
                       target="_blank"
                       rel="noopener noreferrer">
                        <i class="bi bi-whatsapp"></i>
                        Chat WhatsApp
                    </a>
                <?php endif; ?>

                <?php if ($email !== ''): ?>
                    <a href="mailto:<?= h($email) ?>" class="btn btn-outline-dark">
                        <i class="bi bi-envelope"></i>
                        Kirim Email
                    </a>
                <?php endif; ?>

                <?php if ($websiteUrl !== ''): ?>
                    <a href="<?= h($websiteUrl) ?>"
                       class="btn btn-outline-dark"
                       target="_blank"
                       rel="noopener noreferrer">
                        <i class="bi bi-globe2"></i>
                        Website
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<footer class="site-footer">
    <div class="container">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="footer-brand">
                    <img src="<?= h($logoUrl) ?>" alt="Logo <?= h($companyName) ?>">
                    <div>
                        <strong><?= h($companyName) ?></strong>
                        <span><?= h($companyTagline) ?></span>
                    </div>
                </div>

                <p class="footer-desc">
                    <?= h($companyDescription) ?>
                </p>
            </div>

            <div class="col-lg-4 text-lg-end">
                <a href="#layanan" class="footer-link">Layanan</a>
                <a href="#produk" class="footer-link">Produk</a>
                <a href="#tentang" class="footer-link">Tentang</a>
                <a href="#kontak" class="footer-link">Kontak</a>
            </div>
        </div>

        <div class="footer-bottom">
            <span>
                &copy; <?= date('Y') ?> <?= h($companyName) ?>. All rights reserved.
            </span>
            <span><?= h($companyTagline) ?></span>
        </div>
    </div>
</footer>

<?php if ($whatsappUrl !== ''): ?>
<a href="<?= h($whatsappUrl) ?>"
   class="whatsapp-float"
   target="_blank"
   rel="noopener noreferrer"
   aria-label="Chat WhatsApp">
    <i class="bi bi-whatsapp"></i>
    <span>Chat WhatsApp</span>
</a>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
