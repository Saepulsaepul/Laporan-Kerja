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
    $jenis_layanan_id = isset($_POST['jenis_layanan_id']) ? (int)$_POST['jenis_layanan_id'] : null;
    $tanggal_mulai_kontrak = sanitizeInput($_POST['tanggal_mulai_kontrak'] ?? '');
    $tanggal_selesai_kontrak = sanitizeInput($_POST['tanggal_selesai_kontrak'] ?? '');
    $nilai_kontrak = sanitizeInput($_POST['nilai_kontrak'] ?? '');
    $status_kontrak = sanitizeInput($_POST['status_kontrak'] ?? 'Aktif');
    $keterangan = sanitizeInput($_POST['keterangan'] ?? '');
    $status = sanitizeInput($_POST['status'] ?? 'Aktif');
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;

    // Validasi input
    $errors = [];

    // Validasi required fields
    if (empty(trim($nama_perusahaan))) {
        $errors[] = 'Nama perusahaan wajib diisi';
    }

    if (empty(trim($nama_customer))) {
        $errors[] = 'Nama customer wajib diisi';
    }

    // Validasi email
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid';
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
            
            if ($action === 'create') {
                // Cek duplikasi
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE nama_perusahaan = ? AND nama_customer = ?");
                $stmt->execute([$nama_perusahaan, $nama_customer]);
                if ($stmt->fetch()) {
                    $_SESSION['error'] = 'Customer dengan nama perusahaan dan nama customer tersebut sudah ada!';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO customers (
                            nama_perusahaan, nama_customer, telepon, email, alamat, 
                            gedung, lantai, unit, jenis_layanan_id,
                            tanggal_mulai_kontrak, tanggal_selesai_kontrak, 
                            nilai_kontrak, status_kontrak, keterangan, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $nama_perusahaan, $nama_customer, $telepon, $email, $alamat,
                        $gedung, $lantai, $unit, $jenis_layanan_id,
                        $tanggal_mulai_kontrak ?: null, $tanggal_selesai_kontrak ?: null,
                        $nilai_kontrak_formatted, $status_kontrak, $keterangan, $status
                    ]);
                    
                    $_SESSION['success'] = 'Customer berhasil ditambahkan!';
                    error_log("Customer created: $nama_perusahaan - $nama_customer");
                }
            } elseif ($action === 'update' && $id) {
                // Cek duplikasi (kecuali untuk record yang sama)
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE nama_perusahaan = ? AND nama_customer = ? AND id != ?");
                $stmt->execute([$nama_perusahaan, $nama_customer, $id]);
                if ($stmt->fetch()) {
                    $_SESSION['error'] = 'Customer dengan nama perusahaan dan nama customer tersebut sudah ada!';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE customers SET 
                            nama_perusahaan = ?, nama_customer = ?, telepon = ?, email = ?, alamat = ?,
                            gedung = ?, lantai = ?, unit = ?, jenis_layanan_id = ?,
                            tanggal_mulai_kontrak = ?, tanggal_selesai_kontrak = ?,
                            nilai_kontrak = ?, status_kontrak = ?, keterangan = ?, status = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $nama_perusahaan, $nama_customer, $telepon, $email, $alamat,
                        $gedung, $lantai, $unit, $jenis_layanan_id,
                        $tanggal_mulai_kontrak ?: null, $tanggal_selesai_kontrak ?: null,
                        $nilai_kontrak_formatted, $status_kontrak, $keterangan, $status, $id
                    ]);
                    $_SESSION['success'] = 'Customer berhasil diperbarui!';
                }
            }
        } catch (PDOException $e) {
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
    // Ambil semua services untuk dropdown
    $stmtServices = $pdo->query("SELECT id, kode_service, nama_service FROM services WHERE status = 'Aktif' ORDER BY nama_service");
    $services = $stmtServices->fetchAll();

    // Ambil semua customer dengan join service + jumlah laporan & jadwal terkait
    $stmt = $pdo->query("
        SELECT 
            c.*,
            s.nama_service,
            s.kode_service,
            (SELECT COUNT(r.id) FROM reports r WHERE r.customer_id = c.id) AS report_count,
            (SELECT COUNT(j.id) FROM jadwal j WHERE j.customer_id = c.id) AS schedule_count
        FROM customers c
        LEFT JOIN services s ON c.jenis_layanan_id = s.id
        ORDER BY c.nama_perusahaan ASC, c.nama_customer ASC
    ");

    $allCustomers = $stmt->fetchAll();

    // Jika edit
    $editCustomer = null;
    if (isset($_GET['edit'])) {
        $stmt = $pdo->prepare("
            SELECT c.*, s.nama_service 
            FROM customers c 
            LEFT JOIN services s ON c.jenis_layanan_id = s.id 
            WHERE c.id = ?
        ");
        $stmt->execute([(int)$_GET['edit']]);
        $editCustomer = $stmt->fetch();
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

<!-- CSS tetap sama seperti sebelumnya -->
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
                            <select id="kontrakFilter" class="form-select">
                                <option value="">Semua Status Kontrak</option>
                                <option value="Aktif">Kontrak Aktif</option>
                                <option value="Selesai">Kontrak Selesai</option>
                                <option value="Ditangguhkan">Ditangguhkan</option>
                                <option value="Dibatalkan">Dibatalkan</option>
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
                        // Format tanggal kontrak
                        $tgl_mulai = !empty($cust['tanggal_mulai_kontrak']) ? formatTanggalIndonesia($cust['tanggal_mulai_kontrak']) : '-';
                        $tgl_selesai = !empty($cust['tanggal_selesai_kontrak']) ? formatTanggalIndonesia($cust['tanggal_selesai_kontrak']) : '-';
                        $nilai_kontrak = !empty($cust['nilai_kontrak']) ? 'Rp ' . number_format($cust['nilai_kontrak'], 0, ',', '.') : '-';
                        
                        // Status classes
                        $status_class = 'status-' . strtolower($cust['status']);
                        $kontrak_class = 'kontrak-' . strtolower($cust['status_kontrak']);
                        ?>
                        
                        <div class="col-xl-4 col-lg-6 mb-4 customer-item" 
                             data-status="<?= $cust['status'] ?>"
                             data-kontrak="<?= $cust['status_kontrak'] ?>"
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
                                                <span class="status-badge <?= $kontrak_class ?>">
                                                    <?= $cust['status_kontrak'] ?>
                                                </span>
                                            </div>
                                        </div>
                                        <p class="mb-0 text-muted">
                                            <i class="fas fa-user me-1"></i>
                                            <?= htmlspecialchars($cust['nama_customer']) ?>
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
                                    </div>

                                    <!-- Informasi Layanan -->
                                    <?php if (!empty($cust['nama_service'])): ?>
                                        <div class="mb-3">
                                            <span class="badge bg-info badge-layanan">
                                                <i class="fas fa-cog me-1"></i>
                                                <?= htmlspecialchars($cust['nama_service']) ?>
                                                <?php if (!empty($cust['kode_service'])): ?>
                                                    (<?= htmlspecialchars($cust['kode_service']) ?>)
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Informasi Kontrak -->
                                    <?php if (!empty($cust['tanggal_mulai_kontrak']) || !empty($cust['nilai_kontrak'])): ?>
                                        <div class="kontrak-info">
                                            <p class="mb-1 small">
                                                <strong>Kontrak:</strong> 
                                                <?= $tgl_mulai ?> s/d <?= $tgl_selesai ?>
                                            </p>
                                            <p class="mb-0 small">
                                                <strong>Nilai:</strong> <?= $nilai_kontrak ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Keterangan -->
                                    <?php if (!empty($cust['keterangan'])): ?>
                                        <p class="mt-3 mb-0 small text-muted">
                                            <i class="fas fa-info-circle me-1"></i>
                                            <?= nl2br(htmlspecialchars($cust['keterangan'])) ?>
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
            <!-- PERUBAHAN PENTING: form action sekarang ke customer.php -->
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

                        <!-- Informasi Layanan -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Jenis Layanan</label>
                            <select name="jenis_layanan_id" class="form-select">
                                <option value="">-- Pilih Layanan --</option>
                                <?php foreach ($services as $service): ?>
                                    <option value="<?= $service['id'] ?>"
                                        <?= ($editCustomer['jenis_layanan_id'] ?? '') == $service['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($service['nama_service']) ?> (<?= htmlspecialchars($service['kode_service']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Informasi Kontrak -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status Kontrak</label>
                            <select name="status_kontrak" class="form-select">
                                <option value="Aktif" <?= ($editCustomer['status_kontrak'] ?? '') == 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                                <option value="Selesai" <?= ($editCustomer['status_kontrak'] ?? '') == 'Selesai' ? 'selected' : '' ?>>Selesai</option>
                                <option value="Ditangguhkan" <?= ($editCustomer['status_kontrak'] ?? '') == 'Ditangguhkan' ? 'selected' : '' ?>>Ditangguhkan</option>
                                <option value="Dibatalkan" <?= ($editCustomer['status_kontrak'] ?? '') == 'Dibatalkan' ? 'selected' : '' ?>>Dibatalkan</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tanggal Mulai Kontrak</label>
                            <input type="date" 
                                   name="tanggal_mulai_kontrak" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($editCustomer['tanggal_mulai_kontrak'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tanggal Selesai Kontrak</label>
                            <input type="date" 
                                   name="tanggal_selesai_kontrak" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($editCustomer['tanggal_selesai_kontrak'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Nilai Kontrak</label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="text" 
                                       name="nilai_kontrak" 
                                       class="form-control" 
                                       value="<?= !empty($editCustomer['nilai_kontrak']) ? number_format($editCustomer['nilai_kontrak'], 0, ',', '.') : '' ?>"
                                       placeholder="Masukkan nilai kontrak">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status Customer</label>
                            <select name="status" class="form-select">
                                <option value="Aktif" <?= ($editCustomer['status'] ?? '') == 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                                <option value="Nonaktif" <?= ($editCustomer['status'] ?? '') == 'Nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                                <option value="Trial" <?= ($editCustomer['status'] ?? '') == 'Trial' ? 'selected' : '' ?>>Trial</option>
                            </select>
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
        const kontrakFilter = document.getElementById('kontrakFilter');
        const resetFilter = document.getElementById('resetFilter');
        const customerItems = document.querySelectorAll('.customer-item');

        function filterCustomers() {
            const searchTerm = searchInput.value.toLowerCase();
            const statusValue = statusFilter.value;
            const kontrakValue = kontrakFilter.value;

            customerItems.forEach(item => {
                const nama = item.dataset.nama;
                const status = item.dataset.status;
                const kontrak = item.dataset.kontrak;

                const matchesSearch = nama.includes(searchTerm);
                const matchesStatus = !statusValue || status === statusValue;
                const matchesKontrak = !kontrakValue || kontrak === kontrakValue.toLowerCase();

                if (matchesSearch && matchesStatus && matchesKontrak) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        searchInput.addEventListener('input', filterCustomers);
        statusFilter.addEventListener('change', filterCustomers);
        kontrakFilter.addEventListener('change', filterCustomers);

        resetFilter.addEventListener('click', function() {
            searchInput.value = '';
            statusFilter.value = '';
            kontrakFilter.value = '';
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
    });
</script>

<?php 
$footer_path = $root_dir . '/admin/includes/footer.php';
if (file_exists($footer_path)) {
    require_once $footer_path;
}
?>