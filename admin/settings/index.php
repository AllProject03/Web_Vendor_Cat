<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/config.php';

$pageTitle = 'Pengaturan';

/* =====================================================
   IDENTITAS PERUSAHAAN - DARI SETTINGS
   Aman untuk brand sidebar halaman Pengaturan.
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
        // Jangan hentikan halaman jika settings bermasalah.
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

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function current_user_id(): int { return (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0); }
function current_user_name(): string { return (string)($_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Administrator'); }
function current_user_cabang(): int { return (int)($_SESSION['cabang_id'] ?? 0); }

function ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NULL,
        file_data MEDIUMBLOB NULL,
        file_mime VARCHAR(100) NULL,
        file_name VARCHAR(255) NULL,
        file_size INT UNSIGNED NULL,
        setting_group VARCHAR(50) NOT NULL DEFAULT 'general',
        description VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by BIGINT UNSIGNED NULL,
        INDEX idx_settings_group (setting_group)
    ) ENGINE=InnoDB");
    // Pastikan instalasi lama yang sudah memiliki tabel settings juga mendapat kolom file logo.
    $columns = $pdo->query("SHOW COLUMNS FROM settings")->fetchAll(PDO::FETCH_COLUMN);
    $migrations = [
        'file_data' => "ALTER TABLE settings ADD COLUMN file_data MEDIUMBLOB NULL AFTER setting_value",
        'file_mime' => "ALTER TABLE settings ADD COLUMN file_mime VARCHAR(100) NULL AFTER file_data",
        'file_name' => "ALTER TABLE settings ADD COLUMN file_name VARCHAR(255) NULL AFTER file_mime",
        'file_size' => "ALTER TABLE settings ADD COLUMN file_size INT UNSIGNED NULL AFTER file_name",
    ];
    foreach ($migrations as $column => $sql) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec($sql);
            $columns[] = $column;
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        cabang_id BIGINT UNSIGNED NULL,
        module VARCHAR(50) NOT NULL,
        action VARCHAR(50) NOT NULL,
        description TEXT NULL,
        reference_id BIGINT UNSIGNED NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_activity_user (user_id),
        INDEX idx_activity_cabang (cabang_id),
        INDEX idx_activity_module (module),
        INDEX idx_activity_created (created_at)
    ) ENGINE=InnoDB");
}
function log_activity(PDO $pdo, string $action, string $description): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id,cabang_id,module,action,description,ip_address) VALUES (:user_id,:cabang_id,'Pengaturan',:action,:description,:ip)");
        $stmt->execute([
            ':user_id' => current_user_id() ?: null,
            ':cabang_id' => current_user_cabang() ?: null,
            ':action' => $action,
            ':description' => $description,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {}
}
function validate_logo_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'has_file' => false];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'has_file' => true, 'message' => 'Upload logo gagal. Silakan coba lagi.'];
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['ok' => false, 'has_file' => true, 'message' => 'Ukuran logo maksimal 2 MB.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string)$file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'has_file' => true, 'message' => 'Format logo harus JPG, PNG, atau WEBP.'];
    }
    return ['ok' => true, 'has_file' => true, 'mime' => $mime, 'ext' => $allowed[$mime], 'size' => (int)$file['size'], 'name' => (string)$file['name'], 'tmp' => (string)$file['tmp_name']];
}

function save_logo(PDO $pdo, array $file): void {
    $check = validate_logo_upload($file);
    if (!$check['ok']) {
        throw new RuntimeException($check['message']);
    }
    if (!$check['has_file']) {
        return;
    }
    $binary = file_get_contents($check['tmp']);
    if ($binary === false) {
        throw new RuntimeException('File logo tidak dapat dibaca.');
    }
    $stmt = $pdo->prepare("UPDATE settings SET setting_value='database', file_data=:data, file_mime=:mime, file_name=:name, file_size=:size, updated_by=:user WHERE setting_key='company_logo'");
    $stmt->bindValue(':data', $binary, PDO::PARAM_LOB);
    $stmt->bindValue(':mime', $check['mime'], PDO::PARAM_STR);
    $stmt->bindValue(':name', $check['name'], PDO::PARAM_STR);
    $stmt->bindValue(':size', $check['size'], PDO::PARAM_INT);
    $stmt->bindValue(':user', current_user_id() ?: null, PDO::PARAM_INT);
    $stmt->execute();
}

function delete_logo(PDO $pdo): void {
    $stmt = $pdo->prepare("UPDATE settings SET setting_value='', file_data=NULL, file_mime=NULL, file_name=NULL, file_size=NULL, updated_by=:user WHERE setting_key='company_logo'");
    $stmt->execute([':user' => current_user_id() ?: null]);
}

function get_settings(PDO $pdo): array {
    $rows = $pdo->query("SELECT setting_key, setting_value, file_mime, file_name, file_size, setting_group, description, updated_at FROM settings ORDER BY setting_group, setting_key")->fetchAll();
    $result=[]; foreach($rows as $r){ $result[$r['setting_key']]=$r; } return $result;
}
ensure_tables($pdo);

$defaults = [
 'company_name'=>['PT. GIAN GANESHA NAWASENA','general','Nama perusahaan'], 'company_tagline'=>['Sistem Vendor Cat Mobil','general','Tagline atau subjudul perusahaan'], 'company_logo'=>['','general','Logo perusahaan disimpan langsung di database'], 'company_address'=>['','general','Alamat lengkap perusahaan'], 'company_phone'=>['','general','Nomor telepon perusahaan'], 'company_whatsapp'=>['','general','Nomor WhatsApp perusahaan'], 'company_email'=>['','general','Email perusahaan'], 'company_website'=>['','general','Website perusahaan'], 'company_npwp'=>['','general','NPWP perusahaan'], 'company_description'=>['','general','Deskripsi singkat perusahaan'], 'company_about'=>['','general','Profil/tentang perusahaan'], 'company_vision'=>['','general','Visi perusahaan'], 'company_mission'=>['','general','Misi perusahaan'],
 'currency'=>['IDR','general','Mata uang aplikasi'], 'timezone'=>['Asia/Jakarta','general','Zona waktu aplikasi'],
 'sales_prefix'=>['SO-','transaction','Prefix nomor penjualan'], 'purchase_prefix'=>['PO-','transaction','Prefix nomor pembelian'], 'payment_prefix'=>['PAY-','transaction','Prefix nomor pembayaran'], 'expense_prefix'=>['EXP-','transaction','Prefix nomor pengeluaran'], 'transfer_prefix'=>['TRF-','transaction','Prefix nomor transfer stok'], 'default_tax'=>['0','transaction','Pajak default dalam persen'], 'default_discount'=>['0','transaction','Diskon default dalam persen'],
 'minimum_stock_default'=>['0','inventory','Minimum stok default'], 'allow_negative_stock'=>['0','inventory','Mengizinkan stok negatif'], 'low_stock_threshold'=>['1','inventory','Ambang stok menipis'],
 'session_timeout_minutes'=>['120','security','Durasi sesi dalam menit'], 'password_min_length'=>['8','security','Panjang minimum password'],
 'notify_low_stock'=>['1','notification','Notifikasi stok minimum'], 'notify_pending_expense'=>['1','notification','Notifikasi pengeluaran menunggu approval'], 'notify_pending_transfer'=>['1','notification','Notifikasi transfer menunggu proses']
];
foreach($defaults as $key=>$d){
 $stmt=$pdo->prepare("INSERT IGNORE INTO settings(setting_key,setting_value,setting_group,description) VALUES(:k,:v,:g,:d)");
 $stmt->execute([':k'=>$key,':v'=>$d[0],':g'=>$d[1],':d'=>$d[2]]);
}

$allowedSections=['general','transaction','inventory','security','notification','activity'];
$section=(string)($_GET['section']??'general'); if(!in_array($section,$allowedSections,true)) $section='general';
$flash=''; $flashType='success';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=(string)($_POST['action']??'');
    try{
        if($action==='save_settings'){
            $group=(string)($_POST['setting_group']??'general');
            $groupKeys=array_keys(array_filter($defaults,fn($d)=>$d[1]===$group));
            $pdo->beginTransaction();
            $upd=$pdo->prepare("UPDATE settings SET setting_value=:v, updated_by=:u WHERE setting_key=:k");
            foreach($groupKeys as $key){
                // Logo disimpan sebagai BLOB di database, bukan sebagai path file.
                if($key === 'company_logo'){
                    continue;
                }
                $value=$_POST[$key]??'';
                if(in_array($key,['default_tax','default_discount'],true)) $value=(string)max(0,(float)$value);
                if(in_array($key,['minimum_stock_default','low_stock_threshold','session_timeout_minutes','password_min_length'],true)) $value=(string)max(0,(int)$value);
                $upd->execute([':v'=>(string)$value,':u'=>current_user_id()?:null,':k'=>$key]);
            }
            if($group === 'general') {
                save_logo($pdo, $_FILES['company_logo'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
            }
            $pdo->commit();
            log_activity($pdo,'UPDATE','Memperbarui pengaturan grup '.ucfirst($group));
            $flash='Pengaturan berhasil disimpan.';
        } elseif($action==='delete_logo'){
            $pdo->beginTransaction();
            delete_logo($pdo);
            $pdo->commit();
            log_activity($pdo,'DELETE','Menghapus logo perusahaan dari database');
            $flash='Logo perusahaan berhasil dihapus.';
            $section='general';
        } elseif($action==='clear_logs'){
            $pdo->exec("DELETE FROM activity_logs");
            log_activity($pdo,'DELETE','Menghapus seluruh log aktivitas');
            $flash='Log aktivitas berhasil dihapus.';
            $section='activity';
        }
    }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); $flash=$e->getMessage(); $flashType='error'; }
}

$settings=get_settings($pdo);
$logs=[]; $totalLogs=0;
if($section==='activity'){
    $totalLogs=(int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
    $stmt=$pdo->query("SELECT a.*, u.name AS user_name, c.name AS branch_name FROM activity_logs a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN cabangs c ON c.id=a.cabang_id ORDER BY a.id DESC LIMIT 100");
    $logs=$stmt->fetchAll();
}

$groups=[
 'general'=>['Informasi Perusahaan','Identitas perusahaan, mata uang, dan zona waktu.','bi-building'],
 'transaction'=>['Transaksi','Nomor transaksi dan nilai default transaksi.','bi-receipt'],
 'inventory'=>['Persediaan','Aturan stok dan kebijakan persediaan.','bi-boxes'],
 'security'=>['Keamanan','Pengaturan dasar sesi dan kebijakan password.','bi-shield-lock'],
 'notification'=>['Notifikasi','Aktif/nonaktifkan notifikasi operasional.','bi-bell'],
 'activity'=>['Log Aktivitas','Riwayat aktivitas pengguna pada sistem.','bi-clock-history'],
];
$labels=[
 'company_name'=>'Nama perusahaan','company_tagline'=>'Tagline perusahaan','company_logo'=>'Logo perusahaan','company_address'=>'Alamat perusahaan','company_phone'=>'Telepon','company_whatsapp'=>'WhatsApp','company_email'=>'Email','company_website'=>'Website','company_npwp'=>'NPWP','company_description'=>'Deskripsi singkat','company_about'=>'Profil Perusahaan','company_vision'=>'Visi','company_mission'=>'Misi','currency'=>'Mata uang','timezone'=>'Zona waktu',
 'sales_prefix'=>'Prefix Penjualan','purchase_prefix'=>'Prefix Pembelian','payment_prefix'=>'Prefix Pembayaran','expense_prefix'=>'Prefix Pengeluaran','transfer_prefix'=>'Prefix Transfer Stok','default_tax'=>'Pajak Default (%)','default_discount'=>'Diskon Default (%)',
 'minimum_stock_default'=>'Minimum Stok Default','allow_negative_stock'=>'Izinkan Stok Negatif','low_stock_threshold'=>'Ambang Stok Menipis',
 'session_timeout_minutes'=>'Durasi Sesi (menit)','password_min_length'=>'Minimum Panjang Password',
 'notify_low_stock'=>'Notifikasi Stok Minimum','notify_pending_expense'=>'Notifikasi Pengeluaran Pending','notify_pending_transfer'=>'Notifikasi Transfer Pending'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?=h($pageTitle)?> | <?=h($companyName)?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../../assets/css/admin.css?v=20260907">
  <link rel="stylesheet" href="style.css?v=20260913">
</head>
<body class="settings-page">
  <input type="checkbox" id="sidebarToggle" class="sidebar-toggle" aria-hidden="true">
  <aside class="sidebar" id="sidebar">
    <div class="brand"><img src="<?=h($companyLogo)?>" class="img-fluid" alt="<?=h($companyName)?>" onerror="this.onerror=null;this.src='../../assets/img/logo.png';">
      <div class="brand-text">
        <div class="brand-name"><?=h($companyName)?></div><small><?=h($companyTagline)?></small>
      </div>
    </div>
    <nav class="sidebar-menu">
      <div class="menu-section">
        <div class="menu-title">MENU UTAMA</div><a href="../admin.php" class="menu-item"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a>
      </div>
      <div class="menu-section">
        <div class="menu-title">MASTER DATA</div><a href="../customers/" class="menu-item"><i class="bi bi-people"></i><span>Pelanggan</span></a><a href="../vehicles/" class="menu-item"><i class="bi bi-car-front"></i><span>Kendaraan</span></a><a href="../product/" class="menu-item"><i class="bi bi-box-seam"></i><span>Produk</span></a><a href="../service/" class="menu-item"><i class="bi bi-tools"></i><span>Jasa</span></a><a href="../supplier/" class="menu-item"><i class="bi bi-truck"></i><span>Supplier</span></a><a href="../cabang/" class="menu-item"><i class="bi bi-shop"></i><span>Cabang</span></a><a href="../users/" class="menu-item"><i class="bi bi-person-badge"></i><span>Pengguna</span></a><a href="../roles/" class="menu-item"><i class="bi bi-shield-lock"></i><span>Role & Hak Akses</span></a>
      </div>
      <div class="menu-section">
        <div class="menu-title">TRANSAKSI</div><a href="../orders/" class="menu-item"><i class="bi bi-cart3"></i><span>Penjualan</span></a><a href="../purchases/" class="menu-item"><i class="bi bi-bag"></i><span>Pembelian</span></a><a href="../stoks-transfer/" class="menu-item"><i class="bi bi-arrow-left-right"></i><span>Transfer Stok</span></a><a href="../payments/" class="menu-item"><i class="bi bi-credit-card"></i><span>Pembayaran</span></a><a href="../expenses/" class="menu-item"><i class="bi bi-receipt"></i><span>Pengeluaran</span></a><a href="../stoks/" class="menu-item"><i class="bi bi-boxes"></i><span>Stok / Persediaan</span></a>
      </div>
      <div class="menu-section">
        <div class="menu-title">LAPORAN</div><a href="../reports/" class="menu-item"><i class="bi bi-bar-chart-line"></i><span>Laporan</span></a><a href="../recaps/" class="menu-item"><i class="bi bi-pie-chart"></i><span>Rekap Cabang</span></a>
      </div>
      <div class="menu-section">
        <div class="menu-title">PENGATURAN</div><a href="./" class="menu-item active"><i class="bi bi-gear"></i><span>Pengaturan</span></a><a href="../logout.php" class="menu-item"><i class="bi bi-box-arrow-right"></i><span>Keluar</span></a>
      </div>
      <div class="sidebar-footer">
        <div class="paint-decoration"><i class="bi bi-paint-bucket"></i></div><strong>Better Paint</strong><span>Brighter Drive</span>
      </div>
    </nav>
  </aside>
  <main class="main">
    <div class="page-container">
      <div class="page-header">
        <div>
          <div class="breadcrumb">Dashboard <i class="bi bi-chevron-right"></i> Pengaturan</div>
          <h1>Pengaturan</h1>
          <p>Kelola konfigurasi dasar sistem Vendor Cat Mobil dan riwayat aktivitas.</p>
        </div>
        <div class="header-user"><span>Login sebagai</span><strong><?=h(current_user_name())?></strong></div>
      </div>
      <?php if($flash): ?><div class="settings-alert <?=h($flashType)?>"><i class="bi <?= $flashType==='success'?'bi-check-circle':'bi-exclamation-triangle'?>"></i><?=h($flash)?></div><?php endif; ?>
      <div class="settings-layout">
        <aside class="settings-nav">
          <div class="settings-nav-title">PENGATURAN SISTEM</div><?php foreach($groups as $key=>$g): ?><a href="?section=<?=h($key)?>" class="settings-nav-item <?= $section===$key?'active':''?>"><span class="nav-icon"><i class="bi <?=h($g[2])?>"></i></span><span><strong><?=h($g[0])?></strong><small><?=h($g[1])?></small></span></a><?php endforeach; ?>
        </aside>
        <section class="settings-content">
          <?php if($section!=='activity'): $meta=$groups[$section]; $keys=array_keys(array_filter($defaults,fn($d)=>$d[1]===$section)); ?>
          <div class="content-heading">
            <div>
              <div class="eyebrow"><?=h($meta[0])?></div>
              <h2><?=h($meta[0])?></h2>
              <p><?=h($meta[1])?></p>
            </div><i class="bi <?=h($meta[2])?> heading-icon"></i>
          </div>
          <form method="post" class="settings-form" enctype="multipart/form-data" autocomplete="off"><input type="hidden" name="action" value="save_settings"><input type="hidden" name="setting_group" value="<?=h($section)?>">
            <div class="form-grid">
              <?php foreach($keys as $key): $val=$settings[$key]['setting_value']??$defaults[$key][0]; $type='text'; $full=in_array($key,['company_address','company_description','company_about','company_vision','company_mission'],true); if(in_array($key,['default_tax','default_discount'],true)){$type='number';} elseif(in_array($key,['minimum_stock_default','low_stock_threshold','session_timeout_minutes','password_min_length'],true)){$type='number';} elseif(in_array($key,['company_email'],true)){$type='email';} elseif(in_array($key,['company_website'],true)){$type='url';} elseif(str_starts_with($key,'notify_')||$key==='allow_negative_stock'){$type='checkbox';} ?>
              <div class="field <?= $full?'field-full':''?>"><label for="<?=h($key)?>"><?=h($labels[$key]??$key)?></label><?php if($key==='company_logo'): ?>
<div class="logo-upload-box">
  <div class="logo-preview-wrap">
    <img src="logo.php?v=<?=time()?>" alt="Logo perusahaan" class="logo-preview" onerror="this.style.display='none';document.getElementById('logoFallback').style.display='grid';">
    <div id="logoFallback" class="logo-fallback" style="display:none;"><i class="bi bi-building"></i></div>
  </div>
  <div class="logo-upload-controls">
    <input type="file" id="company_logo" name="company_logo" accept="image/jpeg,image/png,image/webp">
    <small>Format JPG, PNG, atau WEBP. Maksimal 2 MB. Logo disimpan langsung di database.</small>
    <?php if(!empty($settings['company_logo']['file_size'])): ?><small>File saat ini: <?=h($settings['company_logo']['file_name']??'logo')?> (<?=number_format(((int)$settings['company_logo']['file_size'])/1024,0,',','.')?> KB)</small><?php endif; ?>
    <?php if((string)($settings['company_logo']['setting_value']??'') === 'database'): ?>
      <button type="submit" name="action" value="delete_logo" class="btn-danger logo-delete" onclick="return confirm('Hapus logo perusahaan?');"><i class="bi bi-trash3"></i> Hapus Logo</button>
    <?php endif; ?>
  </div>
</div>
<?php elseif($type==='checkbox'): ?><label class="switch-line"><input type="checkbox" id="<?=h($key)?>" name="<?=h($key)?>" value="1" <?=((string)$val==='1')?'checked':''?>><span class="switch-ui"></span><span>Aktif</span></label><?php elseif($key==='timezone'): ?><select id="<?=h($key)?>" name="<?=h($key)?>">
                  <option value="Asia/Jakarta" <?=((string)$val==='Asia/Jakarta')?'selected':''?>>Asia/Jakarta (WIB)</option>
                  <option value="Asia/Makassar" <?=((string)$val==='Asia/Makassar')?'selected':''?>>Asia/Makassar (WITA)</option>
                  <option value="Asia/Jayapura" <?=((string)$val==='Asia/Jayapura')?'selected':''?>>Asia/Jayapura (WIT)</option>
                </select><?php elseif($key==='currency'): ?><select id="<?=h($key)?>" name="<?=h($key)?>">
                  <option value="IDR" <?=((string)$val==='IDR')?'selected':''?>>IDR - Rupiah</option>
                </select><?php elseif($full): ?><textarea id="<?=h($key)?>" name="<?=h($key)?>" rows="4"><?=h($val)?></textarea><?php else: ?><input type="<?=h($type)?>" id="<?=h($key)?>" name="<?=h($key)?>" value="<?=h($val)?>" <?=in_array($type,['number'],true)?'min="0" step="0.01"':''?>><?php endif; ?><small><?=h($settings[$key]['description']??$defaults[$key][2])?></small></div>
              <?php endforeach; ?>
            </div>
            <div class="form-actions"><button class="btn-save" type="submit"><i class="bi bi-save2"></i> Simpan Pengaturan</button></div>
          </form>
          <?php else: ?>
          <div class="content-heading">
            <div>
              <div class="eyebrow">Audit Sistem</div>
              <h2>Log Aktivitas</h2>
              <p>100 aktivitas terbaru pengguna dalam sistem.</p>
            </div>
            <form method="post" onsubmit="return confirm('Hapus seluruh log aktivitas?');"><input type="hidden" name="action" value="clear_logs"><button class="btn-danger" type="submit"><i class="bi bi-trash3"></i> Hapus Log</button></form>
          </div>
          <div class="activity-summary">
            <div><span>Total Log</span><strong><?=number_format($totalLogs,0,',','.')?></strong></div>
            <div><span>Menampilkan</span><strong><?=number_format(count($logs),0,',','.')?></strong></div>
          </div>
          <div class="table-card">
            <div class="table-responsive">
              <table>
                <thead>
                  <tr>
                    <th>Waktu</th>
                    <th>Pengguna</th>
                    <th>Cabang</th>
                    <th>Modul</th>
                    <th>Aksi</th>
                    <th>Deskripsi</th>
                    <th>IP</th>
                  </tr>
                </thead>
                <tbody><?php if(!$logs): ?><tr>
                    <td colspan="7" class="empty">Belum ada aktivitas.</td>
                  </tr><?php else: foreach($logs as $log): ?><tr>
                    <td><?=h(date('d/m/Y H:i',strtotime((string)$log['created_at'])))?></td>
                    <td><?=h($log['user_name']??'-')?></td>
                    <td><?=h($log['branch_name']??'-')?></td>
                    <td><?=h($log['module'])?></td>
                    <td><span class="action-badge"><?=h($log['action'])?></span></td>
                    <td><?=h($log['description']??'-')?></td>
                    <td><?=h($log['ip_address']??'-')?></td>
                  </tr><?php endforeach; endif; ?></tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </main>
</body>
</html>