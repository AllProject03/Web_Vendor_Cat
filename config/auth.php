<?php
session_start();

/* =====================================================
   CEK LOGIN
===================================================== */
if (!isset($_SESSION['logged_in']) ||$_SESSION['logged_in'] !== true) {
    header('Location: ../login/login.php');
    exit;
}

/* =====================================================
   CEK ROLE ADMIN
===================================================== */
if (!isset($_SESSION['role_name']) || $_SESSION['role_name'] !== 'Admin') {
    header('Location: ../login/login.php');
    exit;
}

?>