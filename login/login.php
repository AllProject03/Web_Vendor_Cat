<?php

session_start();

require_once __DIR__ . '/../config/config.php';


/* =====================================================
   CEK JIKA SUDAH LOGIN
===================================================== */

if (
    isset($_SESSION['logged_in']) &&
    $_SESSION['logged_in'] === true
) {

    if (
        isset($_SESSION['role_name']) &&
        $_SESSION['role_name'] === 'Admin'
    ) {

        header('Location: ../admin/admin.php');
        exit;

    }

}


/* =====================================================
   VARIABLE
===================================================== */

$error = '';

$emailValue = '';


/* =====================================================
   PROSES LOGIN
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');

    $password = $_POST['password'] ?? '';

    $emailValue = $email;


    /* =================================================
       VALIDASI
    ================================================= */

    if ($email === '') {

        $error = 'Email wajib diisi.';

    }

    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Format email tidak valid.';

    }

    elseif ($password === '') {

        $error = 'Password wajib diisi.';

    }

    else {

        try {

            /* =============================================
               CARI USER
            ============================================= */

            $sql = "
                SELECT
                    u.id,
                    u.role_id,
                    u.cabang_id,
                    u.name,
                    u.email,
                    u.password,
                    u.phone,
                    u.status,

                    r.name AS role_name,

                    c.name AS cabang_name

                FROM users u

                INNER JOIN roles r
                    ON r.id = u.role_id

                LEFT JOIN cabangs c
                    ON c.id = u.cabang_id

                WHERE u.email = :email

                LIMIT 1
            ";


            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                ':email' => $email
            ]);


            $user = $stmt->fetch();


            /* =============================================
               USER TIDAK DITEMUKAN
            ============================================= */

            if (!$user) {

                $error =
                    'Email atau password salah.';

            }


            /* =============================================
               CEK STATUS USER
            ============================================= */

            elseif ($user['status'] !== 'active') {

                $error =
                    'Akun Anda tidak aktif. Silakan hubungi administrator.';

            }


            /* =============================================
               CEK PASSWORD
            ============================================= */

            elseif (
                !password_verify(
                    $password,
                    $user['password']
                )
            ) {

                $error =
                    'Email atau password salah.';

            }


            /* =============================================
               LOGIN BERHASIL
            ============================================= */

            else {

                /* -----------------------------------------
                   SECURITY
                ----------------------------------------- */

                session_regenerate_id(true);


                /* -----------------------------------------
                   SIMPAN SESSION
                ----------------------------------------- */

                $_SESSION['logged_in'] = true;

                $_SESSION['user_id'] =
                    (int) $user['id'];

                $_SESSION['user_name'] =
                    $user['name'];

                $_SESSION['user_email'] =
                    $user['email'];

                $_SESSION['role_id'] =
                    (int) $user['role_id'];

                $_SESSION['role_name'] =
                    $user['role_name'];

                $_SESSION['cabang_id'] =
                    $user['cabang_id'];

                $_SESSION['cabang_name'] =
                    $user['cabang_name'];


                /* -----------------------------------------
                   REDIRECT SESUAI ROLE
                ----------------------------------------- */

                switch ($user['role_name']) {

                    case 'Admin':

                        header(
                            'Location: ../admin/admin.php'
                        );

                        exit;


                    case 'Admin Cabang':

                        header(
                            'Location: ../cabang/index.php'
                        );

                        exit;


                    case 'Finance':

                        header(
                            'Location: ../finance/index.php'
                        );

                        exit;


                    default:

                        $error =
                            'Role akun tidak dikenali.';

                        /* Hapus session */

                        $_SESSION = [];

                        session_destroy();

                        break;
                }

            }

        }

        catch (PDOException $e) {

            error_log(
                'VendorCat Login Error: ' .
                $e->getMessage()
            );

            $error =
                'Terjadi kesalahan pada database. Silakan coba lagi.';

        }

    }

}

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
        Login | VendorCat Management System
    </title>


    <!-- =====================================================
         CSS
    ====================================================== -->

    <link
        rel="stylesheet"
        href="../assets/css/login.css"
    >


    <!-- =====================================================
         GOOGLE FONT
    ====================================================== -->

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >


    <!-- =====================================================
         BOOTSTRAP ICONS
    ====================================================== -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

</head>


<body>


<div class="login-container">


    <!-- =====================================================
         LEFT SIDE
    ====================================================== -->

    <div class="login-left">

        <div class="overlay"></div>


        <div class="left-content">


            <div class="brand-icon">

                <i class="bi bi-brush-fill"></i>

            </div>


            <h1>

                Sistem Vendor

                <span>
                    Cat Mobil
                </span>

            </h1>


            <p class="description">

                Solusi manajemen penjualan cat, jasa, stok,
                dan keuangan untuk bisnis vendor cat mobil
                multi-cabang.

            </p>


            <!-- FEATURE 1 -->

            <div class="feature">

                <div class="feature-icon red">

                    <i class="bi bi-box-seam"></i>

                </div>


                <div>

                    <h3>
                        Kelola Stok dengan Mudah
                    </h3>

                    <p>

                        Pantau stok cat dan bahan pendukung
                        di seluruh cabang.

                    </p>

                </div>

            </div>


            <!-- FEATURE 2 -->

            <div class="feature">

                <div class="feature-icon blue">

                    <i class="bi bi-clipboard-check"></i>

                </div>


                <div>

                    <h3>
                        Transaksi Lebih Cepat
                    </h3>

                    <p>

                        Proses penjualan dan jasa lebih mudah,
                        akurat, dan efisien.

                    </p>

                </div>

            </div>


            <!-- FEATURE 3 -->

            <div class="feature">

                <div class="feature-icon green">

                    <i class="bi bi-bar-chart-line"></i>

                </div>


                <div>

                    <h3>
                        Laporan Lengkap
                    </h3>

                    <p>

                        Dapatkan laporan penjualan, stok,
                        dan keuangan kapan saja.

                    </p>

                </div>

            </div>


        </div>

    </div>


    <!-- =====================================================
         RIGHT SIDE
    ====================================================== -->

    <div class="login-right">


        <div class="login-form-container">


            <!-- =================================================
                 LOGO
            ================================================== -->

            <div class="logo">


                <div class="logo-car">

                    <i class="bi bi-car-front-fill"></i>

                </div>


                <div>

                    <div class="logo-name">

                        Vendor<span>Cat</span>

                    </div>


                    <div class="logo-subtitle">

                        MANAGEMENT SYSTEM

                    </div>

                </div>


            </div>


            <!-- =================================================
                 TITLE
            ================================================== -->

            <div class="welcome">

                <h2>
                    Selamat Datang Kembali
                </h2>

                <p>
                    Silakan masuk ke akun Anda untuk melanjutkan.
                </p>

            </div>


            <!-- =================================================
                 ERROR
            ================================================== -->

            <?php if ($error !== ''): ?>

                <div class="login-error">

                    <i class="bi bi-exclamation-circle-fill"></i>

                    <span>

                        <?= htmlspecialchars($error) ?>

                    </span>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 LOGIN FORM
            ================================================== -->

            <form
                action=""
                method="POST"
                id="loginForm"
            >


                <!-- EMAIL -->

                <div class="form-group">


                    <label for="email">

                        Email

                    </label>


                    <div class="input-wrapper">


                        <i class="bi bi-envelope"></i>


                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?= htmlspecialchars($emailValue) ?>"
                            placeholder="Masukkan email"
                            autocomplete="email"
                            required
                            autofocus
                        >


                    </div>

                </div>


                <!-- PASSWORD -->

                <div class="form-group">


                    <label for="password">

                        Password

                    </label>


                    <div class="input-wrapper">


                        <i class="bi bi-lock"></i>


                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Masukkan password"
                            autocomplete="current-password"
                            required
                        >


                        <button
                            type="button"
                            class="password-toggle"
                            onclick="togglePassword()"
                            aria-label="Tampilkan password"
                        >

                            <i
                                class="bi bi-eye-slash"
                                id="passwordIcon"
                            ></i>

                        </button>


                    </div>

                </div>


                <!-- OPTIONS -->

                <div class="login-options">


                    <label class="remember">


                        <input
                            type="checkbox"
                            name="remember"
                            value="1"
                        >


                        <span>
                            Ingat saya
                        </span>


                    </label>


                    <a
                        href="#"
                        class="forgot"
                        onclick="return false;"
                    >

                        Lupa password?

                    </a>


                </div>


                <!-- BUTTON -->

                <button
                    type="submit"
                    class="login-button"
                >

                    <i class="bi bi-box-arrow-in-right"></i>

                    <span>
                        Masuk
                    </span>

                </button>


            </form>


            <!-- =================================================
                 ROLE
            ================================================== -->

            <div class="access-title">

                <span></span>

                <p>
                    Hak Akses Sistem
                </p>

                <span></span>

            </div>


            <div class="roles">


                <!-- ADMIN -->

                <div class="role-card admin">


                    <div class="role-icon">

                        <i class="bi bi-shield-check"></i>

                    </div>


                    <h4>
                        Admin
                    </h4>


                    <p>
                        Akses penuh
                        seluruh sistem
                    </p>


                </div>


                <!-- ADMIN CABANG -->

                <div class="role-card branch">


                    <div class="role-icon">

                        <i class="bi bi-shop"></i>

                    </div>


                    <h4>
                        Admin Cabang
                    </h4>


                    <p>
                        Kelola data
                        cabang Anda
                    </p>


                </div>


                <!-- FINANCE -->

                <div class="role-card finance">


                    <div class="role-icon">

                        <i class="bi bi-cash-coin"></i>

                    </div>


                    <h4>
                        Finance
                    </h4>


                    <p>
                        Kelola keuangan
                        dan laporan
                    </p>


                </div>


            </div>


            <!-- =================================================
                 FOOTER
            ================================================== -->

            <div class="copyright">

                © 2026 Vendor Cat Mobil.
                All rights reserved.

            </div>


        </div>

    </div>


</div>


<script src="login.js"></script>

</body>

</html>