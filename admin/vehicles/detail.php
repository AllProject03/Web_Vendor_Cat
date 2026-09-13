<?php
/* =====================================================
   STATUS LOGIN
===================================================== */
require_once __DIR__ . '/../../config/auth.php';

/* =====================================================
   KONEKSI DATABASE
===================================================== */
require_once __DIR__ . '/../../config/config.php';

/* =====================================================
   AMBIL ID KENDARAAN
===================================================== */
$vehicleId = (int) ($_GET['id'] ?? 0);

if ($vehicleId <= 0) {
    header('Location: index.php');
    exit;
}

/* =====================================================
   DETAIL KENDARAAN
   vehicles -> customers -> cabangs
===================================================== */
$stmt = $pdo->prepare("
    SELECT
        v.id,
        v.customer_id,
        v.cabang_id,
        v.plate_number,
        v.brand,
        v.model,
        v.year,
        v.color,
        v.vin_number,
        v.note,
        v.status,

        c.name AS customer_name,
        c.email AS customer_email,
        c.phone AS customer_phone,
        c.address AS customer_address,
        c.customer_type,

        cb.name AS cabang_name,
        cb.code AS cabang_code,
        cb.address AS cabang_address,
        cb.phone AS cabang_phone

    FROM vehicles v

    LEFT JOIN customers c
        ON c.id = v.customer_id

    LEFT JOIN cabangs cb
        ON cb.id = v.cabang_id

    WHERE v.id = :id

    LIMIT 1
");

$stmt->execute([
    ':id' => $vehicleId
]);

$vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

/* =====================================================
   DATA TIDAK DITEMUKAN
===================================================== */
if (!$vehicle) {
    header('Location: index.php');
    exit;
}

/* =====================================================
   KODE KENDARAAN
===================================================== */
$vehicleCode = 'VEH-' . str_pad(
    (int) $vehicle['id'],
    4,
    '0',
    STR_PAD_LEFT
);

/* =====================================================
   STATUS
===================================================== */
$isActive = ($vehicle['status'] ?? 'active') === 'active';

/* =====================================================
   NAMA KENDARAAN
===================================================== */
$vehicleName = trim(
    ($vehicle['brand'] ?? '') . ' ' .
    ($vehicle['model'] ?? '')
);

if ($vehicleName === '') {
    $vehicleName = 'Kendaraan';
}

/* =====================================================
   INISIAL PELANGGAN
===================================================== */
$customerName = $vehicle['customer_name'] ?? '-';

$customerInitial = '-';

if ($customerName !== '-' && $customerName !== '') {

    $words = preg_split('/\s+/', trim($customerName));

    if (count($words) >= 2) {
        $customerInitial =
            strtoupper(substr($words[0], 0, 1)) .
            strtoupper(substr($words[1], 0, 1));
    } else {
        $customerInitial =
            strtoupper(substr($words[0], 0, 2));
    }
}

/* =====================================================
   FORMAT CUSTOMER TYPE
===================================================== */
$customerType = $vehicle['customer_type'] ?? '-';

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars($vehicleCode) ?> -
        Detail Kendaraan
    </title>

    <!-- BOOTSTRAP ICON -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <!-- ADMIN CSS -->
    <link
        rel="stylesheet"
        href="../../assets/css/admin.css"
    >

    <!-- VEHICLE CSS -->
    <link
        rel="stylesheet"
        href="style.css"
    >

    <style>

        /* =====================================================
           GLOBAL
        ===================================================== */

        .vehicle-detail-page {
            padding: 28px;
            max-width: 1450px;
            margin: 0 auto;
        }

        * {
            box-sizing: border-box;
        }


        /* =====================================================
           TOP BAR
        ===================================================== */

        .detail-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 24px;
        }

        .detail-breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #6b7280;
            font-size: 14px;
        }

        .detail-breadcrumb a {
            color: #6b7280;
            text-decoration: none;
        }

        .detail-breadcrumb a:hover {
            color: #111827;
        }

        .detail-breadcrumb i {
            font-size: 12px;
        }

        .detail-back {
            width: 38px;
            height: 38px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            color: #374151;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all .2s ease;
        }

        .detail-back:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }


        /* =====================================================
           HERO
        ===================================================== */

        .vehicle-hero-card {
            position: relative;
            overflow: hidden;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 28px;
            margin-bottom: 22px;
        }

        .vehicle-hero-card::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            right: -90px;
            top: -110px;
            border-radius: 50%;
            background: #f8fafc;
            z-index: 0;
        }

        .vehicle-hero-content {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
        }

        .vehicle-hero-left {
            display: flex;
            align-items: center;
            gap: 22px;
        }

        .vehicle-main-icon {
            width: 92px;
            height: 92px;
            flex-shrink: 0;
            border-radius: 20px;
            background: #111827;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            box-shadow: 0 10px 25px rgba(17, 24, 39, .15);
        }

        .vehicle-hero-info {
            min-width: 0;
        }

        .vehicle-code {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            color: #6b7280;
            letter-spacing: .5px;
            margin-bottom: 7px;
        }

        .vehicle-hero-info h1 {
            margin: 0;
            font-size: 27px;
            line-height: 1.2;
            color: #111827;
            font-weight: 750;
        }

        .vehicle-hero-subtitle {
            margin-top: 8px;
            color: #6b7280;
            font-size: 14px;
        }

        .vehicle-status-area {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .detail-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 14px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 700;
        }

        .detail-status.active {
            background: #ecfdf5;
            color: #047857;
        }

        .detail-status.inactive {
            background: #fef2f2;
            color: #b91c1c;
        }

        .detail-status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
        }


        /* =====================================================
           QUICK INFO
        ===================================================== */

        .vehicle-quick-info {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 28px;
        }

        .quick-item {
            background: #f9fafb;
            border: 1px solid #f1f5f9;
            border-radius: 12px;
            padding: 15px 16px;
        }

        .quick-label {
            font-size: 11px;
            font-weight: 700;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 6px;
        }

        .quick-value {
            font-size: 15px;
            font-weight: 650;
            color: #111827;
        }


        /* =====================================================
           CONTENT GRID
        ===================================================== */

        .detail-content-grid {
            display: grid;
            grid-template-columns: 1.25fr .75fr;
            gap: 22px;
        }


        /* =====================================================
           CARD
        ===================================================== */

        .detail-modern-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            overflow: hidden;
        }

        .detail-modern-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 22px;
            border-bottom: 1px solid #f1f5f9;
        }

        .detail-card-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #f3f4f6;
            color: #374151;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .detail-card-title h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #111827;
        }

        .detail-card-title p {
            margin: 3px 0 0;
            font-size: 12px;
            color: #9ca3af;
        }

        .detail-modern-card-body {
            padding: 22px;
        }


        /* =====================================================
           INFORMATION GRID
        ===================================================== */

        .vehicle-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0 30px;
        }

        .info-field {
            padding: 14px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .info-field:nth-last-child(-n+2) {
            border-bottom: none;
        }

        .info-field-label {
            font-size: 12px;
            color: #9ca3af;
            margin-bottom: 6px;
        }

        .info-field-value {
            color: #111827;
            font-size: 14px;
            font-weight: 600;
            word-break: break-word;
        }


        /* =====================================================
           PLATE
        ===================================================== */

        .license-plate {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            background: #ffffff;
            color: #111827;
            border: 2px solid #111827;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .license-plate i {
            font-size: 13px;
        }


        /* =====================================================
           CUSTOMER
        ===================================================== */

        .customer-main {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 20px;
            margin-bottom: 8px;
            border-bottom: 1px solid #f3f4f6;
        }

        .customer-avatar-modern {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: #111827;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .customer-main h3 {
            margin: 0 0 5px;
            font-size: 16px;
            color: #111827;
        }

        .customer-type {
            font-size: 12px;
            color: #6b7280;
        }


        /* =====================================================
           CONTACT LIST
        ===================================================== */

        .contact-list {
            display: flex;
            flex-direction: column;
        }

        .contact-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 13px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .contact-item:last-child {
            border-bottom: none;
        }

        .contact-icon {
            width: 32px;
            height: 32px;
            flex-shrink: 0;
            border-radius: 8px;
            background: #f9fafb;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .contact-content {
            min-width: 0;
        }

        .contact-label {
            display: block;
            font-size: 11px;
            color: #9ca3af;
            margin-bottom: 3px;
        }

        .contact-value {
            display: block;
            font-size: 13px;
            color: #374151;
            line-height: 1.5;
            word-break: break-word;
        }


        /* =====================================================
           CABANG
        ===================================================== */

        .branch-header {
            display: flex;
            align-items: center;
            gap: 13px;
            margin-bottom: 18px;
        }

        .branch-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: #111827;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 21px;
        }

        .branch-header h3 {
            margin: 0 0 4px;
            font-size: 15px;
            color: #111827;
        }

        .branch-code-modern {
            font-size: 12px;
            color: #9ca3af;
            font-weight: 600;
        }


        /* =====================================================
           NOTE
        ===================================================== */

        .note-modern {
            background: #f9fafb;
            border: 1px solid #f1f5f9;
            border-radius: 11px;
            padding: 15px;
            color: #4b5563;
            font-size: 13px;
            line-height: 1.7;
            min-height: 90px;
        }

        .empty-note {
            color: #9ca3af;
            font-style: italic;
        }


        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 1000px) {

            .detail-content-grid {
                grid-template-columns: 1fr;
            }

            .vehicle-quick-info {
                grid-template-columns: repeat(2, 1fr);
            }

        }


        @media (max-width: 700px) {

            .vehicle-detail-page {
                padding: 16px;
            }

            .detail-topbar {
                margin-bottom: 18px;
            }

            .vehicle-hero-card {
                padding: 20px;
            }

            .vehicle-hero-content {
                align-items: flex-start;
                flex-direction: column;
            }

            .vehicle-hero-left {
                align-items: flex-start;
            }

            .vehicle-main-icon {
                width: 68px;
                height: 68px;
                font-size: 30px;
                border-radius: 15px;
            }

            .vehicle-hero-info h1 {
                font-size: 21px;
            }

            .vehicle-quick-info {
                grid-template-columns: 1fr 1fr;
            }

            .vehicle-info-grid {
                grid-template-columns: 1fr;
            }

            .info-field:nth-last-child(-n+2) {
                border-bottom: 1px solid #f3f4f6;
            }

            .info-field:last-child {
                border-bottom: none;
            }

        }


        @media (max-width: 480px) {

            .vehicle-hero-left {
                gap: 14px;
            }

            .vehicle-main-icon {
                width: 58px;
                height: 58px;
                font-size: 26px;
            }

            .vehicle-quick-info {
                grid-template-columns: 1fr;
            }

            .detail-modern-card-body {
                padding: 17px;
            }

            .detail-modern-card-header {
                padding: 17px;
            }

        }

    </style>

</head>


<body>

<main>

    <div class="vehicle-detail-page">


        <!-- =================================================
             BREADCRUMB
        ================================================== -->

        <div class="detail-topbar">

            <div class="detail-breadcrumb">

                <button
                    type="button"
                    class="detail-back"
                    onclick="history.back()"
                    title="Kembali"
                >
                    <i class="bi bi-arrow-left"></i>
                </button>

                <a href="index.php">
                    Kendaraan
                </a>

                <i class="bi bi-chevron-right"></i>

                <span>
                    <?= htmlspecialchars($vehicleCode) ?>
                </span>

            </div>

        </div>


        <!-- =================================================
             HERO
        ================================================== -->

        <div class="vehicle-hero-card">

            <div class="vehicle-hero-content">


                <!-- LEFT -->

                <div class="vehicle-hero-left">

                    <div class="vehicle-main-icon">

                        <i class="bi bi-car-front-fill"></i>

                    </div>


                    <div class="vehicle-hero-info">

                        <div class="vehicle-code">

                            <i class="bi bi-upc-scan"></i>

                            <?= htmlspecialchars($vehicleCode) ?>

                        </div>


                        <h1>
                            <?= htmlspecialchars($vehicleName) ?>
                        </h1>


                        <div class="vehicle-hero-subtitle">

                            Kendaraan pelanggan
                            &nbsp;•&nbsp;
                            <?= htmlspecialchars(
                                $vehicle['cabang_name'] ?? '-'
                            ) ?>

                        </div>

                    </div>

                </div>


                <!-- STATUS -->

                <div class="vehicle-status-area">

                    <?php if ($isActive): ?>

                        <span class="detail-status active">

                            <span class="detail-status-dot"></span>

                            Kendaraan Aktif

                        </span>

                    <?php else: ?>

                        <span class="detail-status inactive">

                            <span class="detail-status-dot"></span>

                            Tidak Aktif

                        </span>

                    <?php endif; ?>

                </div>

            </div>


            <!-- QUICK INFO -->

            <div class="vehicle-quick-info">


                <div class="quick-item">

                    <div class="quick-label">
                        Nomor Polisi
                    </div>

                    <div class="quick-value">

                        <?= htmlspecialchars(
                            $vehicle['plate_number'] ?? '-'
                        ) ?>

                    </div>

                </div>


                <div class="quick-item">

                    <div class="quick-label">
                        Merek
                    </div>

                    <div class="quick-value">

                        <?= htmlspecialchars(
                            $vehicle['brand'] ?? '-'
                        ) ?>

                    </div>

                </div>


                <div class="quick-item">

                    <div class="quick-label">
                        Tahun
                    </div>

                    <div class="quick-value">

                        <?= htmlspecialchars(
                            $vehicle['year'] ?? '-'
                        ) ?>

                    </div>

                </div>


                <div class="quick-item">

                    <div class="quick-label">
                        Warna
                    </div>

                    <div class="quick-value">

                        <?= htmlspecialchars(
                            $vehicle['color'] ?? '-'
                        ) ?>

                    </div>

                </div>


            </div>

        </div>


        <!-- =================================================
             CONTENT
        ================================================== -->

        <div class="detail-content-grid">


            <!-- =================================================
                 INFORMASI KENDARAAN
            ================================================== -->

            <div class="detail-modern-card">

                <div class="detail-modern-card-header">

                    <div class="detail-card-icon">

                        <i class="bi bi-car-front-fill"></i>

                    </div>

                    <div class="detail-card-title">

                        <h2>
                            Informasi Kendaraan
                        </h2>

                        <p>
                            Detail teknis kendaraan
                        </p>

                    </div>

                </div>


                <div class="detail-modern-card-body">

                    <div class="vehicle-info-grid">


                        <div class="info-field">

                            <div class="info-field-label">
                                Nomor Polisi
                            </div>

                            <div class="info-field-value">

                                <span class="license-plate">

                                    <i class="bi bi-car-front"></i>

                                    <?= htmlspecialchars(
                                        $vehicle['plate_number'] ?? '-'
                                    ) ?>

                                </span>

                            </div>

                        </div>


                        <div class="info-field">

                            <div class="info-field-label">
                                Merek
                            </div>

                            <div class="info-field-value">

                                <?= htmlspecialchars(
                                    $vehicle['brand'] ?? '-'
                                ) ?>

                            </div>

                        </div>


                        <div class="info-field">

                            <div class="info-field-label">
                                Model
                            </div>

                            <div class="info-field-value">

                                <?= htmlspecialchars(
                                    $vehicle['model'] ?? '-'
                                ) ?>

                            </div>

                        </div>


                        <div class="info-field">

                            <div class="info-field-label">
                                Tahun
                            </div>

                            <div class="info-field-value">

                                <?= htmlspecialchars(
                                    $vehicle['year'] ?? '-'
                                ) ?>

                            </div>

                        </div>


                        <div class="info-field">

                            <div class="info-field-label">
                                Warna
                            </div>

                            <div class="info-field-value">

                                <?= htmlspecialchars(
                                    $vehicle['color'] ?? '-'
                                ) ?>

                            </div>

                        </div>


                        <div class="info-field">

                            <div class="info-field-label">
                                Nomor Rangka / VIN
                            </div>

                            <div class="info-field-value">

                                <?= !empty($vehicle['vin_number'])
                                    ? htmlspecialchars(
                                        $vehicle['vin_number']
                                    )
                                    : '-' ?>

                            </div>

                        </div>


                    </div>

                </div>

            </div>



            <!-- =================================================
                 PELANGGAN
            ================================================== -->

            <div class="detail-modern-card">

                <div class="detail-modern-card-header">

                    <div class="detail-card-icon">

                        <i class="bi bi-person-fill"></i>

                    </div>

                    <div class="detail-card-title">

                        <h2>
                            Pelanggan
                        </h2>

                        <p>
                            Pemilik kendaraan
                        </p>

                    </div>

                </div>


                <div class="detail-modern-card-body">


                    <div class="customer-main">

                        <div class="customer-avatar-modern">

                            <?= htmlspecialchars(
                                $customerInitial
                            ) ?>

                        </div>


                        <div>

                            <h3>
                                <?= htmlspecialchars(
                                    $customerName
                                ) ?>
                            </h3>

                            <span class="customer-type">

                                <?= htmlspecialchars(
                                    $customerType
                                ) ?>

                            </span>

                        </div>

                    </div>


                    <div class="contact-list">


                        <div class="contact-item">

                            <div class="contact-icon">

                                <i class="bi bi-envelope"></i>

                            </div>

                            <div class="contact-content">

                                <span class="contact-label">
                                    Email
                                </span>

                                <span class="contact-value">

                                    <?= !empty(
                                        $vehicle['customer_email']
                                    )
                                        ? htmlspecialchars(
                                            $vehicle['customer_email']
                                        )
                                        : '-' ?>

                                </span>

                            </div>

                        </div>


                        <div class="contact-item">

                            <div class="contact-icon">

                                <i class="bi bi-telephone"></i>

                            </div>

                            <div class="contact-content">

                                <span class="contact-label">
                                    Telepon
                                </span>

                                <span class="contact-value">

                                    <?= !empty(
                                        $vehicle['customer_phone']
                                    )
                                        ? htmlspecialchars(
                                            $vehicle['customer_phone']
                                        )
                                        : '-' ?>

                                </span>

                            </div>

                        </div>


                        <div class="contact-item">

                            <div class="contact-icon">

                                <i class="bi bi-geo-alt"></i>

                            </div>

                            <div class="contact-content">

                                <span class="contact-label">
                                    Alamat
                                </span>

                                <span class="contact-value">

                                    <?= !empty(
                                        $vehicle['customer_address']
                                    )
                                        ? nl2br(
                                            htmlspecialchars(
                                                $vehicle['customer_address']
                                            )
                                        )
                                        : '-' ?>

                                </span>

                            </div>

                        </div>


                    </div>

                </div>

            </div>



            <!-- =================================================
                 CABANG
            ================================================== -->

            <div class="detail-modern-card">

                <div class="detail-modern-card-header">

                    <div class="detail-card-icon">

                        <i class="bi bi-building"></i>

                    </div>

                    <div class="detail-card-title">

                        <h2>
                            Cabang
                        </h2>

                        <p>
                            Cabang yang menangani kendaraan
                        </p>

                    </div>

                </div>


                <div class="detail-modern-card-body">


                    <div class="branch-header">

                        <div class="branch-icon">

                            <i class="bi bi-building"></i>

                        </div>


                        <div>

                            <h3>

                                <?= htmlspecialchars(
                                    $vehicle['cabang_name'] ?? '-'
                                ) ?>

                            </h3>

                            <div class="branch-code-modern">

                                <?= htmlspecialchars(
                                    $vehicle['cabang_code'] ?? '-'
                                ) ?>

                            </div>

                        </div>

                    </div>


                    <div class="contact-list">


                        <div class="contact-item">

                            <div class="contact-icon">

                                <i class="bi bi-geo-alt"></i>

                            </div>

                            <div class="contact-content">

                                <span class="contact-label">
                                    Alamat Cabang
                                </span>

                                <span class="contact-value">

                                    <?= !empty(
                                        $vehicle['cabang_address']
                                    )
                                        ? nl2br(
                                            htmlspecialchars(
                                                $vehicle['cabang_address']
                                            )
                                        )
                                        : '-' ?>

                                </span>

                            </div>

                        </div>


                        <div class="contact-item">

                            <div class="contact-icon">

                                <i class="bi bi-telephone"></i>

                            </div>

                            <div class="contact-content">

                                <span class="contact-label">
                                    Telepon Cabang
                                </span>

                                <span class="contact-value">

                                    <?= !empty(
                                        $vehicle['cabang_phone']
                                    )
                                        ? htmlspecialchars(
                                            $vehicle['cabang_phone']
                                        )
                                        : '-' ?>

                                </span>

                            </div>

                        </div>


                    </div>

                </div>

            </div>



            <!-- =================================================
                 CATATAN
            ================================================== -->

            <div class="detail-modern-card">

                <div class="detail-modern-card-header">

                    <div class="detail-card-icon">

                        <i class="bi bi-sticky-fill"></i>

                    </div>

                    <div class="detail-card-title">

                        <h2>
                            Catatan Kendaraan
                        </h2>

                        <p>
                            Informasi tambahan
                        </p>

                    </div>

                </div>


                <div class="detail-modern-card-body">

                    <div class="note-modern">

                        <?php if (!empty($vehicle['note'])): ?>

                            <?= nl2br(
                                htmlspecialchars(
                                    $vehicle['note']
                                )
                            ) ?>

                        <?php else: ?>

                            <span class="empty-note">
                                Belum ada catatan untuk kendaraan ini.
                            </span>

                        <?php endif; ?>

                    </div>

                </div>

            </div>


        </div>

    </div>

</main>

</body>

</html>