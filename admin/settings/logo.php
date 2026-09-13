<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/config.php';

try {
    $stmt = $pdo->prepare("SELECT file_data, file_mime FROM settings WHERE setting_key='company_logo' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['file_data']) && !empty($row['file_mime'])) {
        header('Content-Type: ' . $row['file_mime']);
        header('Content-Length: ' . strlen($row['file_data']));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        echo $row['file_data'];
        exit;
    }
} catch (Throwable $e) {
    // Fallback ke file logo lama.
}

$fallback = __DIR__ . '/../../assets/img/logo.png';
if (is_file($fallback)) {
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($fallback);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Logo tidak ditemukan.';
