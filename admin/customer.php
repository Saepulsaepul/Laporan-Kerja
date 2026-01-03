<?php
// admin/customer.php
session_start();

// Debug path
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cek path yang benar
$root_dir = dirname(dirname(__FILE__));

// Include files
require_once $root_dir . '/includes/functions.php';
require_once $root_dir . '/config/database.php';

// Check login admin
checkLogin('admin');

$pdo = getConnection();

// Generate CSRF token jika belum ada
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Notifikasi session
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

// =========================
// HANDLING FORM CREATE/UPDATE
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // Debug: Lihat data POST
    error_log("POST Data: " . print_r($_POST, true));

    // Validasi CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = 'Token keamanan tidak valid!';
        header("Location: customer.php");
        exit();
    }

    // Ambil data dari form
    $nama_perusahaan = sanitizeInput($_POST['nama_perusahaan'] ?? '');
    $nama_customer = sanitizeInput($_POST['nama_customer'] ?? '');
    $telepon = sanitizeInput($_POST['telepon'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $alamat = sanitizeInput($_POST['alamat'] ?? '');
    $gedung = sanitizeInput($_POST['gedung'] ?? '');
    $lantai = sanitizeInput($_POST['lantai'] ?? '');
    $unit = sanitizeInput($_POST['unit'] ?? '');
    $jumlah_station = isset($_POST['jumlah_station']) ? (int)$_POST['jumlah_station'] : 0; // Tambah field baru
    $keterangan = sanitizeInput($_POST['keterangan'] ?? '');
    $status = sanitizeInput($_POST['status'] ?? 'Aktif');
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;

    // Data layanan (array untuk multiple selection)
    $jenis_layanan_ids = isset($_POST['jenis_layanan_ids']) ? $_POST['jenis_layanan_ids'] : [];
    $tanggal_mulai_kontrak = sanitizeInput($_POST['tanggal_mulai_kontrak'] ?? '');
    $tanggal_selesai_kontrak = sanitizeInput($_POST['tanggal_selesai_kontrak'] ?? '');
    $nilai_kontrak = sanitizeInput($_POST['nilai_kontrak'] ?? '');
    $status_kontrak = sanitizeInput($_POST['status_kontrak'] ?? 'Aktif');

    // Validasi input
    $errors = [];

    // Validasi required fields
    if (empty(trim($nama_perusahaan))) {
        $errors[] = 'Nama perusahaan wajib diisi';
    }

    if (empty(trim($nama_customer))) {
        $errors[] = 'Nama customer wajib diisi';
    }

    // Validasi jumlah station (harus angka positif)
    if ($jumlah_station < 0) {
        $errors[] = 'Jumlah station inspeksi harus berupa angka positif';
    }

    // Validasi email
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid';
    }

    // Validasi minimal 1 layanan dipilih
    if (empty($jenis_layanan_ids)) {
        $errors[] = 'Minimal pilih satu jenis layanan';
    }

    // Format nilai kontrak
    if (!empty($nilai_kontrak)) {
        $nilai_kontrak = str_replace(['.', ','], '', $nilai_kontrak);
        if (!is_numeric($nilai_kontrak) || $nilai_kontrak < 0) {
            $errors[] = 'Nilai kontrak harus berupa angka positif';
        }
    }

    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
        header("Location: customer.php");
        exit();
    } else {
        try {
            // Format nilai kontrak
            $nilai_kontrak_formatted = !empty($nilai_kontrak) ? $nilai_kontrak : null;
            
            // Mulai transaction
            $pdo->beginTransaction();
            
            if ($action === 'create') {
                // Cek duplikasi customer
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE nama_perusahaan = ? AND nama_customer = ?");
                $stmt->execute([$nama_perusahaan, $nama_customer]);
                if ($stmt->fetch()) {
                    $_SESSION['error'] = 'Customer dengan nama perusahaan dan nama customer tersebut sudah ada!';
                } else {
                    // Insert customer - TAMBAH jumlah_station
                    $stmt = $pdo->prepare("
                        INSERT INTO customers (
                            nama_perusahaan, nama_customer, telepon, email, alamat, 
                            gedung, lantai, unit, jumlah_station, keterangan, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $nama_perusahaan, $nama_customer, $telepon, $email, $alamat,
                        $gedung, $lantai, $unit, $jumlah_station, $keterangan, $status
                    ]);
                    
                    $customer_id = $pdo->lastInsertId();
                    
                    // Insert layanan yang dipilih
                    foreach ($jenis_layanan_ids as $service_id) {
                        $service_id = (int)$service_id;
                        if ($service_id > 0) {
                            $stmt = $pdo->prepare("
                                INSERT INTO customer_services (
                                    customer_id, service_id, tanggal_mulai, tanggal_selesai,
                                    nilai_kontrak, status
                                ) VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $customer_id, $service_id,
                                $tanggal_mulai_kontrak ?: null,
                                $tanggal_selesai_kontrak ?: null,
                                $nilai_kontrak_formatted,
                                $status_kontrak
                            ]);
                        }
                    }
                    
                    $_SESSION['success'] = 'Customer berhasil ditambahkan dengan ' . count($jenis_layanan_ids) . ' layanan dan ' . $jumlah_station . ' station inspeksi!';
                    error_log("Customer created: $nama_perusahaan - $nama_customer");
                }
            } elseif ($action === 'update' && $id) {
                // Cek duplikasi (kecuali untuk record yang sama)
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE nama_perusahaan = ? AND nama_customer = ? AND id != ?");
                $stmt->execute([$nama_perusahaan, $nama_customer, $id]);
                if ($stmt->fetch()) {
                    $_SESSION['error'] = 'Customer dengan nama perusahaan dan nama customer tersebut sudah ada!';
                } else {
                    // Update customer - TAMBAH jumlah_station
                    $stmt = $pdo->prepare("
                        UPDATE customers SET 
                            nama_perusahaan = ?, nama_customer = ?, telepon = ?, email = ?, alamat = ?,
                            gedung = ?, lantai = ?, unit = ?, jumlah_station = ?, keterangan = ?, status = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $nama_perusahaan, $nama_customer, $telepon, $email, $alamat,
                        $gedung, $lantai, $unit, $jumlah_station, $keterangan, $status, $id
                    ]);
                    
                    // Hapus semua layanan lama
                    $stmt = $pdo->prepare("DELETE FROM customer_services WHERE customer_id = ?");
                    $stmt->execute([$id]);
                    
                    // Insert layanan baru
                    foreach ($jenis_layanan_ids as $service_id) {
                        $service_id = (int)$service_id;
                        if ($service_id > 0) {
                            $stmt = $pdo->prepare("
                                INSERT INTO customer_services (
                                    customer_id, service_id, tanggal_mulai, tanggal_selesai,
                                    nilai_kontrak, status
                                ) VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $id, $service_id,
                                $tanggal_mulai_kontrak ?: null,
                                $tanggal_selesai_kontrak ?: null,
                                $nilai_kontrak_formatted,
                                $status_kontrak
                            ]);
                        }
                    }
                    
                    $_SESSION['success'] = 'Customer berhasil diperbarui dengan ' . count($jenis_layanan_ids) . ' layanan dan ' . $jumlah_station . ' station inspeksi!';
                }
            }
            
            $pdo->commit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = 'Terjadi masalah: ' . $e->getMessage();
            error_log("Database Error: " . $e->getMessage());
        }
    }

    header("Location: customer.php");
    exit();
}

// =========================
// DELETE CUSTOMER
// =========================
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        // Cek apakah customer digunakan di laporan
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE customer_id = ?");
        $stmt->execute([$id]);
        $reportCount = (int)$stmt->fetchColumn();
        
        // Cek apakah customer digunakan di jadwal
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM jadwal WHERE customer_id = ?");
        $stmt->execute([$id]);
        $scheduleCount = (int)$stmt->fetchColumn();

        if ($reportCount > 0 || $scheduleCount > 0) {
            $msg = 'Tidak dapat menghapus! Customer digunakan oleh: ';
            $msgs = [];
            if ($reportCount > 0) $msgs[] = $reportCount . ' laporan';
            if ($scheduleCount > 0) $msgs[] = $scheduleCount . ' jadwal';
            $_SESSION['error'] = $msg . implode(' dan ', $msgs);
        } else {
            // Hapus relasi layanan terlebih dahulu
            $stmt = $pdo->prepare("DELETE FROM customer_services WHERE customer_id = ?");
            $stmt->execute([$id]);
            
            // Hapus customer
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            
            $_SESSION['success'] = 'Customer berhasil dihapus!';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Terjadi masalah saat menghapus: ' . $e->getMessage();
    }

    header("Location: customer.php");
    exit();
}

// =========================
// GET DATA
// =========================
try {
    // Tambah kolom jumlah_station di tabel customers
    // Periksa apakah kolom sudah ada
    $checkColumn = $pdo->query("SHOW COLUMNS FROM customers LIKE 'jumlah_station'");
    if ($checkColumn->rowCount() == 0) {
        // Tambah kolom jika belum ada
        $pdo->exec("ALTER TABLE customers ADD COLUMN jumlah_station INT DEFAULT 0 AFTER unit");
    }

    // Ambil semua services untuk dropdown
    $stmtServices = $pdo->query("SELECT id, kode_service, nama_service FROM services WHERE status = 'Aktif' ORDER BY nama_service");
    $services = $stmtServices->fetchAll();

    // Ambil semua customer dengan join service + jumlah laporan & jadwal terkait
    $stmt = $pdo->query("
        SELECT 
            c.*,
            GROUP_CONCAT(DISTINCT CONCAT(s.nama_service, ' (', s.kode_service, ')') SEPARATOR ', ') as services_list,
            (SELECT COUNT(r.id) FROM reports r WHERE r.customer_id = c.id) AS report_count,
            (SELECT COUNT(j.id) FROM jadwal j WHERE j.customer_id = c.id) AS schedule_count,
            (SELECT COUNT(cs.id) FROM customer_services cs WHERE cs.customer_id = c.id) as service_count,
            (SELECT GROUP_CONCAT(cs.service_id) FROM customer_services cs WHERE cs.customer_id = c.id) as service_ids
        FROM customers c
        LEFT JOIN customer_services cs ON c.id = cs.customer_id
        LEFT JOIN services s ON cs.service_id = s.id
        GROUP BY c.id
        ORDER BY c.nama_perusahaan ASC, c.nama_customer ASC
    ");

    $allCustomers = $stmt->fetchAll();

    // Jika edit
    $editCustomer = null;
    $editCustomerServices = [];
    if (isset($_GET['edit'])) {
        // Ambil data customer
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $editCustomer = $stmt->fetch();
        
        // Ambil layanan yang dimiliki customer
        if ($editCustomer) {
            $stmt = $pdo->prepare("
                SELECT cs.service_id, cs.tanggal_mulai, cs.tanggal_selesai, 
                       cs.nilai_kontrak, cs.status, cs.keterangan,
                       s.nama_service, s.kode_service
                FROM customer_services cs
                JOIN services s ON cs.service_id = s.id
                WHERE cs.customer_id = ?
            ");
            $stmt->execute([$editCustomer['id']]);
            $editCustomerServices = $stmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    $error = "Gagal mengambil data: " . $e->getMessage();
    $allCustomers = [];
    $services = [];
}

// Page title
$pageTitle = "Kelola Customer";

// header
$header_path = $root_dir . '/admin/includes/header.php';
if (!file_exists($header_path)) {
    die("Header file not found: " . $header_path);
}
require_once $header_path;
?>

<style>
    .customer-card {
        border: 1px solid #e9ecef;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        transition: transform 0.2s, box-shadow 0.2s;
        height: 100%;
    }
    .customer-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    .customer-info {
        padding: 1.5rem;
    }
    .customer-actions {
        padding: 1rem 1.5rem;
        background: #f8f9fa;
        border-top: 1px solid #e9ecef;
        border-radius: 0 0 12px 12px;
    }
    .customer-header {
        border-bottom: 2px solid #e9ecef;
        padding-bottom: 1rem;
        margin-bottom: 1rem;
    }
    .badge-layanan {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
        margin-right: 0.25rem;
        margin-bottom: 0.25rem;
    }
    .kontrak-info {
        background: #f8f9fa;
        padding: 0.75rem;
        border-radius: 8px;
        margin-top: 0.5rem;
    }
    .section-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
    }
    .empty-state {
        text-align: center;
        padding: 3rem 2rem;
        color: #6c757d;
    }
    .empty-state i {
        font-size: 4rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }
    .status-badge {
        font-size: 0.75rem;
        padding: 0.25rem 0.75rem;
        border-radius: 50px;
    }
    .status-aktif { background: #d1e7dd; color: #0f5132; }
    .status-nonaktif { background: #f8d7da; color: #842029; }
    .status-trial { background: #fff3cd; color: #664d03; }
    .kontrak-aktif { background: #d1e7dd; color: #0f5132; }
    .kontrak-selesai { background: #cff4fc; color: #055160; }
    .kontrak-ditangguhkan { background: #fff3cd; color: #664d03; }
    .kontrak-dibatalkan { background: #f8d7da; color: #842029; }
    .services-container {
        max-height: 200px;
        overflow-y: auto;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 10px;
    }
    .service-checkbox {
        margin-bottom: 8px;
    }
    .station-badge {
        background-color: #e3f2fd;
        color: #1565c0;
        padding: 0.25rem 0.75rem;
        border-radius: 50px;
        font-size: 0.75rem;
    }
</style>

<?php 
$navbar_path = $root_dir . '/admin/includes/navbar.php';
if (file_exists($navbar_path)) {
    require_once $navbar_path;
}
?>

<div class="container-fluid">
    <div class="row">
        <?php 
        $sidebar_path = $root_dir . '/admin/includes/sidebar.php';
        if (file_exists($sidebar_path)) {
            require_once $sidebar_path;
        }
        ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-users me-2"></i><?php echo $pageTitle; ?>
                    <small class="text-muted fs-6">(Total: <?php echo count($allCustomers); ?> Customer)</small>
                </h1>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#customerModal">
                    <i class="fas fa-plus me-2"></i>Tambah Customer
                </button>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?= $success ?>
                    <button class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= $error ?>
                    <button class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- FILTER SECTION -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <input type="text" id="searchInput" class="form-control" placeholder="Cari customer...">
                        </div>
                        <div class="col-md-3">
                            <select id="statusFilter" class="form-select">
                                <option value="">Semua Status</option>
                                <option value="Aktif">Aktif</option>
                                <option value="Nonaktif">Nonaktif</option>
                                <option value="Trial">Trial</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select id="serviceFilter" class="form-select">
                                <option value="">Semua Layanan</option>
                                <?php foreach ($services as $service): ?>
                                    <option value="<?= $service['id'] ?>"><?= htmlspecialchars($service['nama_service']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button id="resetFilter" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-redo me-1"></i>Reset
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- LIST CUSTOMER -->
            <?php if (empty($allCustomers)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h4>Belum ada customer</h4>
                    <p class="mb-4">Mulai dengan menambahkan customer pertama Anda.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#customerModal">
                        <i class="fas fa-plus me-2"></i>Tambah Customer Pertama
                    </button>
                </div>
            <?php else: ?>
                <div class="row" id="customerContainer">
                    <?php foreach ($allCustomers as $cust): ?>
                        <?php 
                        // Ambil data kontrak dari relasi pertama (jika ada)
                        $stmtKontrak = $pdo->prepare("
                            SELECT tanggal_mulai, tanggal_selesai, nilai_kontrak, status 
                            FROM customer_services 
                            WHERE customer_id = ? 
                            LIMIT 1
                        ");
                        $stmtKontrak->execute([$cust['id']]);
                        $kontrak = $stmtKontrak->fetch();
                        
                        $tgl_mulai = !empty($kontrak['tanggal_mulai']) ? formatTanggalIndonesia($kontrak['tanggal_mulai']) : '-';
                        $tgl_selesai = !empty($kontrak['tanggal_selesai']) ? formatTanggalIndonesia($kontrak['tanggal_selesai']) : '-';
                        $nilai_kontrak = !empty($kontrak['nilai_kontrak']) ? 'Rp ' . number_format($kontrak['nilai_kontrak'], 0, ',', '.') : '-';
                        $status_kontrak = $kontrak['status'] ?? '-';
                        
                        // Status classes
                        $status_class = 'status-' . strtolower($cust['status']);
                        $kontrak_class = 'kontrak-' . strtolower($status_kontrak);
                        ?>
                        
                        <div class="col-xl-4 col-lg-6 mb-4 customer-item" 
                             data-status="<?= $cust['status'] ?>"
                             data-services="<?= $cust['service_ids'] ?? '' ?>"
                             data-nama="<?= htmlspecialchars(strtolower($cust['nama_perusahaan'] . ' ' . $cust['nama_customer'])) ?>">
                            <div class="customer-card">
                                <div class="customer-info">
                                    <div class="customer-header">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h5 class="fw-bold text-primary mb-1">
                                                <?= htmlspecialchars($cust['nama_perusahaan']) ?>
                                            </h5>
                                            <div class="d-flex gap-1">
                                                <span class="status-badge <?= $status_class ?>">
                                                    <?= $cust['status'] ?>
                                                </span>
                                                <?php if ($status_kontrak != '-'): ?>
                                                <span class="status-badge <?= $kontrak_class ?>">
                                                    <?= $status_kontrak ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <p class="mb-0 text-muted">
                                            <i class="fas fa-user me-1"></i>
                                            <?= htmlspecialchars($cust['nama_customer']) ?>
                                            <span class="badge bg-secondary ms-2">
                                                <i class="fas fa-cog me-1"></i><?= $cust['service_count'] ?> Layanan
                                            </span>
                                            <?php if (!empty($cust['jumlah_station']) && $cust['jumlah_station'] > 0): ?>
                                                <span class="station-badge ms-2">
                                                    <i class="fas fa-map-marker-alt me-1"></i><?= $cust['jumlah_station'] ?> Station
                                                </span>
                                            <?php endif; ?>
                                        </p>
                                    </div>

                                    <!-- Informasi Kontak -->
                                    <div class="mb-3">
                                        <?php if (!empty($cust['telepon'])): ?>
                                            <p class="mb-1">
                                                <i class="fas fa-phone text-muted me-2"></i>
                                                <a href="tel:<?= htmlspecialchars($cust['telepon']) ?>" class="text-decoration-none">
                                                    <?= htmlspecialchars($cust['telepon']) ?>
                                                </a>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($cust['email'])): ?>
                                            <p class="mb-1">
                                                <i class="fas fa-envelope text-muted me-2"></i>
                                                <a href="mailto:<?= htmlspecialchars($cust['email']) ?>" class="text-decoration-none">
                                                    <?= htmlspecialchars($cust['email']) ?>
                                                </a>
                                            </p>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Informasi Lokasi -->
                                    <div class="mb-3">
                                        <?php if (!empty($cust['alamat'])): ?>
                                            <p class="mb-1">
                                                <i class="fas fa-map-marker-alt text-muted me-2"></i>
                                                <?= htmlspecialchars($cust['alamat']) ?>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($cust['gedung']) || !empty($cust['lantai']) || !empty($cust['unit'])): ?>
                                            <p class="mb-0 text-muted small">
                                                <i class="fas fa-building me-1"></i>
                                                <?php 
                                                $lokasi = [];
                                                if (!empty($cust['gedung'])) $lokasi[] = $cust['gedung'];
                                                if (!empty($cust['lantai'])) $lokasi[] = "Lt. {$cust['lantai']}";
                                                if (!empty($cust['unit'])) $lokasi[] = $cust['unit'];
                                                echo implode(' - ', $lokasi);
                                                ?>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($cust['jumlah_station']) && $cust['jumlah_station'] > 0): ?>
                                            <p class="mt-1 mb-0 text-muted small">
                                                <i class="fas fa-map-pin me-1"></i>
                                                <strong>Jumlah Station Inspeksi:</strong> <?= $cust['jumlah_station'] ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Informasi Layanan -->
                                    <?php if (!empty($cust['services_list'])): ?>
                                        <div class="mb-3">
                                            <label class="form-label small text-muted mb-1">Layanan:</label>
                                            <div class="d-flex flex-wrap">
                                                <?php 
                                                $services_list = explode(', ', $cust['services_list']);
                                                foreach ($services_list as $service): 
                                                    if (!empty(trim($service))):
                                                ?>
                                                    <span class="badge bg-info badge-layanan mb-1">
                                                        <?= htmlspecialchars($service) ?>
                                                    </span>
                                                <?php 
                                                    endif;
                                                endforeach; 
                                                ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Informasi Kontrak -->
                                    <?php if ($tgl_mulai != '-' || $nilai_kontrak != '-'): ?>
                                        <div class="kontrak-info">
                                            <?php if ($tgl_mulai != '-'): ?>
                                            <p class="mb-1 small">
                                                <strong>Kontrak:</strong> 
                                                <?= $tgl_mulai ?> s/d <?= $tgl_selesai ?>
                                            </p>
                                            <?php endif; ?>
                                            <?php if ($nilai_kontrak != '-'): ?>
                                            <p class="mb-0 small">
                                                <strong>Nilai:</strong> <?= $nilai_kontrak ?>
                                            </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Keterangan -->
                                    <?php if (!empty($cust['keterangan'])): ?>
                                        <p class="mt-3 mb-0 small text-muted">
                                            <i class="fas fa-info-circle me-1"></i>
                                            <?= nl2br(htmlspecialchars(substr($cust['keterangan'], 0, 100))) ?>
                                            <?php if (strlen($cust['keterangan']) > 100): ?>...<?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <div class="customer-actions">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-secondary me-2" data-bs-toggle="tooltip" title="Jumlah Laporan">
                                                <i class="fas fa-file-alt me-1"></i><?= $cust['report_count'] ?>
                                            </span>
                                            <span class="badge bg-secondary" data-bs-toggle="tooltip" title="Jumlah Jadwal">
                                                <i class="fas fa-calendar-alt me-1"></i><?= $cust['schedule_count'] ?>
                                            </span>
                                        </div>

                                        <div class="btn-group">
                                            <a href="customer.php?edit=<?= $cust['id'] ?>" 
                                               class="btn btn-sm btn-outline-primary" 
                                               data-bs-toggle="tooltip" 
                                               title="Edit Customer">
                                                <i class="fas fa-edit"></i>
                                            </a>

                                            <?php if ($cust['report_count'] == 0 && $cust['schedule_count'] == 0): ?>
                                                <a href="customer.php?delete=<?= $cust['id'] ?>" 
                                                   class="btn btn-sm btn-outline-danger"
                                                   onclick="return confirm('Hapus customer <?= htmlspecialchars(addslashes($cust['nama_perusahaan'])) ?> (<?= htmlspecialchars(addslashes($cust['nama_customer'])) ?>)? Tindakan ini tidak dapat dibatalkan.')"
                                                   data-bs-toggle="tooltip" 
                                                   title="Hapus Customer">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-danger" 
                                                        disabled 
                                                        data-bs-toggle="tooltip" 
                                                        title="Tidak dapat dihapus karena memiliki <?= $cust['report_count'] ?> laporan dan <?= $cust['schedule_count'] ?> jadwal">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<!-- MODAL FORM -->
<div class="modal fade" id="customerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="customer.php" id="customerForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-<?= $editCustomer ? 'edit' : 'plus' ?> me-2"></i>
                        <?= $editCustomer ? 'Edit Customer' : 'Tambah Customer Baru' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="<?= $editCustomer ? 'update' : 'create' ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <?php if ($editCustomer): ?>
                        <input type="hidden" name="id" value="<?= $editCustomer['id'] ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <!-- Informasi Perusahaan -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Nama Perusahaan <span class="text-danger">*</span></label>
                            <input type="text" 
                                   name="nama_perusahaan" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($editCustomer['nama_perusahaan'] ?? '') ?>" 
                                   required
                                   maxlength="150"
                                   placeholder="Masukkan nama perusahaan">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Nama Customer <span class="text-danger">*</span></label>
                            <input type="text" 
                                   name="nama_customer" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($editCustomer['nama_customer'] ?? '') ?>" 
                                   required
                                   maxlength="150"
                                   placeholder="Masukkan nama kontak person">
                        </div>

                        <!-- Informasi Kontak -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Telepon</label>
                            <input type="text" 
                                   name="telepon" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($editCustomer['telepon'] ?? '') ?>"
                                   maxlength="30"
                                   placeholder="Contoh: (021) 1234-5678">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Email</label>
                            <input type="email" 
                                   name="email" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($editCustomer['email'] ?? '') ?>"
                                   maxlength="150"
                                   placeholder="email@perusahaan.com">
                        </div>

                        <!-- Informasi Lokasi -->
                        <div class="col-12">
                            <label class="form-label fw-bold">Alamat Lengkap</label>
                            <textarea name="alamat" 
                                      class="form-control" 
                                      rows="2"
                                      placeholder="Masukkan alamat lengkap"><?= htmlspecialchars($editCustomer['alamat'] ?? '') ?></textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Lokasi Inspeksi</label>
                            <input type="text" 
                                   name="gedung" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($editCustomer['gedung'] ?? '') ?>"
                                   placeholder="Nama gedung">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Station Inspeksi</label>
                            <input type="text" 
                                   name="lantai" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($editCustomer['lantai'] ?? '') ?>"
                                   placeholder="Contoh: 8, Basement">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Unit</label>
                            <input type="text" 
                                   name="unit" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($editCustomer['unit'] ?? '') ?>"
                                   placeholder="Contoh: Unit 801, Area A">
                        </div>

                        <!-- Jumlah Station Inspeksi (NEW FIELD) -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Jumlah Station Inspeksi</label>
                            <small class="text-muted d-block mb-1">Jumlah titik inspeksi untuk laporan</small>
                            <input type="number" 
                                   name="jumlah_station" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($editCustomer['jumlah_station'] ?? 0) ?>"
                                   min="0"
                                   max="100"
                                   placeholder="Contoh: 5">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status Customer</label>
                            <select name="status" class="form-select">
                                <option value="Aktif" <?= ($editCustomer['status'] ?? '') == 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                                <option value="Nonaktif" <?= ($editCustomer['status'] ?? '') == 'Nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                                <option value="Trial" <?= ($editCustomer['status'] ?? '') == 'Trial' ? 'selected' : '' ?>>Trial</option>
                            </select>
                        </div>

                        <!-- Informasi Layanan (MULTIPLE SELECTION) -->
                        <div class="col-12">
                            <label class="form-label fw-bold">Pilih Layanan <span class="text-danger">*</span></label>
                            <small class="text-muted d-block mb-2">Centang satu atau lebih layanan yang dibutuhkan customer</small>
                            <div class="services-container">
                                <?php foreach ($services as $service): ?>
                                    <?php 
                                    $checked = false;
                                    if ($editCustomer) {
                                        foreach ($editCustomerServices as $custService) {
                                            if ($custService['service_id'] == $service['id']) {
                                                $checked = true;
                                                break;
                                            }
                                        }
                                    }
                                    ?>
                                    <div class="form-check service-checkbox">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               name="jenis_layanan_ids[]" 
                                               value="<?= $service['id'] ?>"
                                               id="service_<?= $service['id'] ?>"
                                               <?= $checked ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="service_<?= $service['id'] ?>">
                                            <strong><?= htmlspecialchars($service['nama_service']) ?></strong>
                                            <small class="text-muted">(<?= htmlspecialchars($service['kode_service']) ?>)</small>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Informasi Kontrak -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status Kontrak</label>
                            <select name="status_kontrak" class="form-select">
                                <option value="Aktif" <?= ($editCustomerServices[0]['status'] ?? '') == 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                                <option value="Selesai" <?= ($editCustomerServices[0]['status'] ?? '') == 'Selesai' ? 'selected' : '' ?>>Selesai</option>
                                <option value="Ditangguhkan" <?= ($editCustomerServices[0]['status'] ?? '') == 'Ditangguhkan' ? 'selected' : '' ?>>Ditangguhkan</option>
                                <option value="Dibatalkan" <?= ($editCustomerServices[0]['status'] ?? '') == 'Dibatalkan' ? 'selected' : '' ?>>Dibatalkan</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tanggal Mulai Kontrak</label>
                            <input type="date" 
                                   name="tanggal_mulai_kontrak" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($editCustomerServices[0]['tanggal_mulai'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tanggal Selesai Kontrak</label>
                            <input type="date" 
                                   name="tanggal_selesai_kontrak" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($editCustomerServices[0]['tanggal_selesai'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Nilai Kontrak Total</label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="text" 
                                       name="nilai_kontrak" 
                                       class="form-control" 
                                       value="<?= !empty($editCustomerServices[0]['nilai_kontrak']) ? number_format($editCustomerServices[0]['nilai_kontrak'], 0, ',', '.') : '' ?>"
                                       placeholder="Masukkan nilai kontrak total untuk semua layanan">
                            </div>
                        </div>

                        <!-- Keterangan -->
                        <div class="col-12">
                            <label class="form-label fw-bold">Keterangan Tambahan</label>
                            <textarea name="keterangan" 
                                      class="form-control" 
                                      rows="3"
                                      placeholder="Informasi tambahan tentang customer"><?= htmlspecialchars($editCustomer['keterangan'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Batal
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-<?= $editCustomer ? 'check' : 'save' ?> me-2"></i>
                        <?= $editCustomer ? 'Update Customer' : 'Simpan Customer' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        // Tooltip initialization
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Auto show modal if editing
        <?php if ($editCustomer): ?>
            const editModal = new bootstrap.Modal(document.getElementById('customerModal'));
            editModal.show();
        <?php endif; ?>

        // Filter functionality
        const searchInput = document.getElementById('searchInput');
        const statusFilter = document.getElementById('statusFilter');
        const serviceFilter = document.getElementById('serviceFilter');
        const resetFilter = document.getElementById('resetFilter');
        const customerItems = document.querySelectorAll('.customer-item');

        function filterCustomers() {
            const searchTerm = searchInput.value.toLowerCase();
            const statusValue = statusFilter.value;
            const serviceValue = serviceFilter.value;

            customerItems.forEach(item => {
                const nama = item.dataset.nama;
                const status = item.dataset.status;
                const services = item.dataset.services || '';

                const matchesSearch = nama.includes(searchTerm);
                const matchesStatus = !statusValue || status === statusValue;
                const matchesService = !serviceValue || services.includes(serviceValue);

                if (matchesSearch && matchesStatus && matchesService) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        searchInput.addEventListener('input', filterCustomers);
        statusFilter.addEventListener('change', filterCustomers);
        serviceFilter.addEventListener('change', filterCustomers);

        resetFilter.addEventListener('click', function() {
            searchInput.value = '';
            statusFilter.value = '';
            serviceFilter.value = '';
            filterCustomers();
        });

        // Format nilai kontrak saat input
        const nilaiKontrakInput = document.querySelector('input[name="nilai_kontrak"]');
        if (nilaiKontrakInput) {
            nilaiKontrakInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/[^0-9]/g, '');
                if (value) {
                    value = parseInt(value).toLocaleString('id-ID');
                }
                e.target.value = value;
            });
        }

        // Validasi form
        const form = document.getElementById('customerForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                const namaPerusahaan = document.querySelector('input[name="nama_perusahaan"]');
                const namaCustomer = document.querySelector('input[name="nama_customer"]');
                const checkboxes = document.querySelectorAll('input[name="jenis_layanan_ids[]"]:checked');
                const jumlahStation = document.querySelector('input[name="jumlah_station"]');
                
                // Validasi required fields
                if (!namaPerusahaan.value.trim()) {
                    e.preventDefault();
                    alert('Nama perusahaan wajib diisi');
                    namaPerusahaan.focus();
                    return false;
                }
                
                if (!namaCustomer.value.trim()) {
                    e.preventDefault();
                    alert('Nama customer wajib diisi');
                    namaCustomer.focus();
                    return false;
                }
                
                // Validasi jumlah station
                if (jumlahStation.value && parseInt(jumlahStation.value) < 0) {
                    e.preventDefault();
                    alert('Jumlah station inspeksi harus berupa angka positif');
                    jumlahStation.focus();
                    return false;
                }
                
                // Validasi minimal 1 layanan dipilih
                if (checkboxes.length === 0) {
                    e.preventDefault();
                    alert('Pilih minimal satu jenis layanan');
                    return false;
                }
                
                // Validasi tanggal kontrak
                const tglMulai = document.querySelector('input[name="tanggal_mulai_kontrak"]');
                const tglSelesai = document.querySelector('input[name="tanggal_selesai_kontrak"]');
                
                if (tglMulai.value && tglSelesai.value) {
                    const mulai = new Date(tglMulai.value);
                    const selesai = new Date(tglSelesai.value);
                    
                    if (mulai > selesai) {
                        e.preventDefault();
                        alert('Tanggal mulai kontrak tidak boleh lebih besar dari tanggal selesai kontrak');
                        tglMulai.focus();
                        return false;
                    }
                }
                
                return true;
            });
        }
        
        // Select all checkboxes functionality
        const selectAllBtn = document.createElement('button');
        selectAllBtn.type = 'button';
        selectAllBtn.className = 'btn btn-sm btn-outline-primary mb-2';
        selectAllBtn.innerHTML = '<i class="fas fa-check-square me-1"></i> Pilih Semua';
        
        const servicesContainer = document.querySelector('.services-container');
        if (servicesContainer) {
            servicesContainer.parentNode.insertBefore(selectAllBtn, servicesContainer);
            
            selectAllBtn.addEventListener('click', function() {
                const checkboxes = document.querySelectorAll('input[name="jenis_layanan_ids[]"]');
                const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                
                checkboxes.forEach(cb => {
                    cb.checked = !allChecked;
                });
                
                this.innerHTML = allChecked ? 
                    '<i class="fas fa-check-square me-1"></i> Pilih Semua' :
                    '<i class="fas fa-times me-1"></i> Batal Semua';
            });
        }
    });
</script>

<?php 
$footer_path = $root_dir . '/admin/includes/footer.php';
if (file_exists($footer_path)) {
    require_once $footer_path;
}
?>