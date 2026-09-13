<?php
declare(strict_types=1);

if (!function_exists('get_company_profile')) {
    function get_company_profile(PDO $pdo): array
    {
        $defaults = [
            'company_name' => 'PT. GIAN GANESHA NAWASENA',
            'company_tagline' => 'Sistem Vendor Cat Mobil',
            'company_address' => '',
            'company_phone' => '',
            'company_whatsapp' => '',
            'company_email' => '',
            'company_website' => '',
            'company_npwp' => '',
            'company_description' => '',
            'company_about' => '',
            'company_vision' => '',
            'company_mission' => '',
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
        ];

        try {
            $keys = array_keys($defaults);
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
            $stmt->execute($keys);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $defaults[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
            }

            $logoStmt = $pdo->query(
                "SELECT setting_value, file_mime, file_name, file_size,
                        (file_data IS NOT NULL AND OCTET_LENGTH(file_data) > 0) AS has_file
                 FROM settings
                 WHERE setting_key = 'company_logo'
                 LIMIT 1"
            );
            $logo = $logoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $defaults['company_logo'] = (string)($logo['setting_value'] ?? '');
            $defaults['company_logo_mime'] = (string)($logo['file_mime'] ?? '');
            $defaults['company_logo_name'] = (string)($logo['file_name'] ?? '');
            $defaults['company_logo_size'] = (int)($logo['file_size'] ?? 0);
            $defaults['company_logo_has_file'] = !empty($logo['has_file']);
        } catch (Throwable $e) {
            $defaults['company_logo'] = '';
            $defaults['company_logo_mime'] = '';
            $defaults['company_logo_name'] = '';
            $defaults['company_logo_size'] = 0;
            $defaults['company_logo_has_file'] = false;
        }

        $defaults['company_logo_url'] = company_logo_url();
        return $defaults;
    }
}

if (!function_exists('company_logo_url')) {
    function company_logo_url(): string
    {
        // URL absolut relatif ke document root project MAMP.
        // Dengan ini logo database dapat dipanggil baik dari /index.php
        // maupun dari halaman /admin/* tanpa masalah path relatif.
        return '/Web_Vendor_Cat/admin/settings/logo.php?v=20260913';
    }
}
