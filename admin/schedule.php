<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

checkLogin('admin');

$pdo = getConnection();
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

// Inisialisasi filter
$filter_status = $_GET['status'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_customer = $_GET['customer'] ?? '';
$filter_worker = $_GET['worker'] ?? '';
$filter_periode = $_GET['periode'] ?? '';

// Ambil data untuk dropdowns
try {
    // Ambil customers dengan jumlah_station
    $customers = $pdo->query("
        SELECT c.id, 
               CONCAT(c.nama_perusahaan, ' - ', c.nama_customer) as display_name, 
               c.nama_perusahaan, c.nama_customer, c.telepon,
               c.gedung, c.lantai, c.unit, c.jumlah_station
        FROM customers c
        WHERE c.status = 'Aktif' 
        ORDER BY c.nama_perusahaan ASC
    ")->fetchAll();
    
    $services = $pdo->query("
        SELECT s.id, s.kode_service, s.nama_service, s.harga, s.durasi_menit
        FROM services s
        WHERE s.status = 'Aktif' 
        ORDER BY s.nama_service ASC
    ")->fetchAll();
    
    $workers = $pdo->query("
        SELECT u.id, u.nama, u.jabatan 
        FROM users u
        WHERE u.status = 'Aktif' 
        ORDER BY u.nama ASC
    ")->fetchAll();
    
    // Untuk filter dropdowns
    $all_customers_for_filter = $pdo->query("
        SELECT id, CONCAT(nama_perusahaan, ' - ', nama_customer) as display_name 
        FROM customers 
        ORDER BY nama_perusahaan ASC
    ")->fetchAll();
    
    $all_workers_for_filter = $pdo->query("
        SELECT id, nama 
        FROM users 
        ORDER BY nama ASC
    ")->fetchAll();
    
} catch (PDOException $e) {
    $error = "Gagal mengambil data: " . $e->getMessage();
    $customers = $services = $workers = [];
    $all_customers_for_filter = $all_workers_for_filter = [];
}

// =========================
// HANDLING FORM CREATE/UPDATE
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    
    // Validasi required fields
    if (empty($_POST['customer_id']) || empty($_POST['service_id']) || empty($_POST['tanggal']) || empty($_POST['jam'])) {
        $_SESSION['error'] = 'Customer, Layanan, Tanggal, dan Jam harus diisi!';
    } else {
        $customer_id = (int)$_POST['customer_id'];
        $service_id = (int)$_POST['service_id'];
        $pekerja_id = !empty($_POST['pekerja_id']) ? (int)$_POST['pekerja_id'] : null;
        $tanggal = sanitizeInput($_POST['tanggal']);
        $jam = sanitizeInput($_POST['jam']);
        $lokasi = sanitizeInput($_POST['lokasi'] ?? '');
        $catatan_admin = sanitizeInput($_POST['catatan_admin'] ?? '');
        $prioritas = sanitizeInput($_POST['prioritas'] ?? 'Sedang');
        $durasi_estimasi = !empty($_POST['durasi_estimasi']) ? (int)$_POST['durasi_estimasi'] : null;
        $jenis_periode = sanitizeInput($_POST['jenis_periode'] ?? 'Sekali');
        $jumlah_kunjungan = !empty($_POST['jumlah_kunjungan']) ? (int)$_POST['jumlah_kunjungan'] : 1;
        
        // Ambil jumlah_station dari customer
        $stmt = $pdo->prepare("SELECT jumlah_station FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer_data = $stmt->fetch();
        $jumlah_station = $customer_data ? $customer_data['jumlah_station'] : 0;
        
        // Jika jumlah_station ada dan lebih besar dari 0, gunakan untuk kunjungan
        if ($jumlah_station > 0 && $jenis_periode === 'Sekali') {
            $jumlah_kunjungan = $jumlah_station;
        }
        
        // Validasi untuk kunjungan berulang
        if ($jenis_periode !== 'Sekali' && $jumlah_kunjungan < 2) {
            $_SESSION['error'] = 'Jumlah kunjungan minimal 2 untuk jadwal berulang!';
        } elseif ($jenis_periode !== 'Sekali' && $jumlah_kunjungan > 100) {
            $_SESSION['error'] = 'Jumlah kunjungan maksimal 100!';
        } else {
            // Validasi tanggal tidak di masa lalu
            $selected_date = new DateTime($tanggal);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            
            if ($selected_date < $today) {
                $_SESSION['error'] = 'Tanggal tidak boleh di masa lalu!';
            } else {
                try {
                    $admin_id = $_SESSION['admin_id']; // Admin ID dari session
                    
                    // CREATE NEW SCHEDULE
                   // CREATE NEW SCHEDULE
if ($action === 'create') {
    // Generate kode jadwal langsung di PHP
    $tahun = date('Y', strtotime($tanggal));
    $bulan = date('m', strtotime($tanggal));
    
    // Cari sequence terakhir untuk bulan ini
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(kode_jadwal, '/', -1), '-K', 1) AS UNSIGNED)), 0) + 1 as sequence
        FROM jadwal 
        WHERE kode_jadwal LIKE CONCAT('JDW/', ?, '/', ?, '/%')
    ");
    $stmt->execute([$tahun, $bulan]);
    $result = $stmt->fetch();
    $sequence = $result['sequence'];
    
    // Buat kode dasar
    $kode_jadwal = sprintf('JDW/%s/%s/%03d', $tahun, $bulan, $sequence);
    
    // Jika recurring, tambah suffix -K01
    $is_recurring = ($jenis_periode !== 'Sekali');
    if ($is_recurring) {
        $kode_jadwal .= '-K01';
    }
    
    // Pastikan kode unik
    $counter = 1;
    while (true) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM jadwal WHERE kode_jadwal = ?");
        $stmt->execute([$kode_jadwal]);
        $result = $stmt->fetch();
        
        if ($result['count'] == 0) {
            break;
        }
        
        if ($is_recurring) {
            $kode_jadwal = sprintf('JDW/%s/%s/%03d-K01', $tahun, $bulan, $sequence + $counter);
        } else {
            $kode_jadwal = sprintf('JDW/%s/%s/%03d', $tahun, $bulan, $sequence + $counter);
        }
        
        $counter++;
    }
    
    // Insert jadwal utama
    $stmt = $pdo->prepare("
        INSERT INTO jadwal (
            kode_jadwal, admin_id, pekerja_id, customer_id, service_id, 
            tanggal, jam, lokasi, durasi_estimasi, status, prioritas, 
            catatan_admin, jenis_periode, jumlah_kunjungan, kunjungan_berjalan
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Menunggu', ?, ?, ?, ?, 0)
    ");
    $stmt->execute([
        $kode_jadwal, $admin_id, $pekerja_id, $customer_id, $service_id,
        $tanggal, $jam, $lokasi, $durasi_estimasi, $prioritas, 
        $catatan_admin, $jenis_periode, $jumlah_kunjungan
    ]);
    

                        
                        $jadwal_id = $pdo->lastInsertId();
                        
                        // Buat jadwal berulang jika diperlukan
                        if ($jenis_periode !== 'Sekali' && $jumlah_kunjungan > 1) {
                            $parent_schedule = $pdo->query("SELECT * FROM jadwal WHERE id = $jadwal_id")->fetch();
                            
                            for ($i = 1; $i < $jumlah_kunjungan; $i++) {
                                $next_date = $tanggal;
                                
                                // Hitung tanggal berikutnya
                                switch ($jenis_periode) {
                                    case 'Harian':
                                        $next_date = date('Y-m-d', strtotime("+{$i} day", strtotime($tanggal)));
                                        break;
                                    case 'Mingguan':
                                        $next_date = date('Y-m-d', strtotime("+{$i} week", strtotime($tanggal)));
                                        break;
                                    case 'Bulanan':
                                        $next_date = date('Y-m-d', strtotime("+{$i} month", strtotime($tanggal)));
                                        break;
                                    case 'Tahunan':
                                        $next_date = date('Y-m-d', strtotime("+{$i} year", strtotime($tanggal)));
                                        break;
                                }
                                
                                // Generate kode untuk child schedule
                                $stmt = $pdo->prepare("SELECT fn_generate_kode_jadwal(?, ?) as kode_jadwal");
                                $stmt->execute([$next_date, true]);
                                $child_result = $stmt->fetch();
                                $child_kode = $child_result['kode_jadwal'];
                                
                                // Pastikan format -K{nomor} sesuai urutan
                                $kunjungan_ke = $i + 1;
                                $child_kode = preg_replace('/-K\d+$/', '', $child_kode);
                                $child_kode .= '-K' . str_pad($kunjungan_ke, 2, '0', STR_PAD_LEFT);
                                
                                // Insert child schedule
                                $stmt = $pdo->prepare("
                                    INSERT INTO jadwal (
                                        kode_jadwal, admin_id, pekerja_id, customer_id, service_id, 
                                        tanggal, jam, lokasi, durasi_estimasi, status, prioritas, 
                                        catatan_admin, jenis_periode, jumlah_kunjungan, kunjungan_berjalan,
                                        parent_jadwal_id
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Menunggu', ?, ?, ?, ?, 0, ?)
                                ");
                                $stmt->execute([
                                    $child_kode, $admin_id, $pekerja_id, $customer_id, $service_id,
                                    $next_date, $jam, $lokasi, $durasi_estimasi, $prioritas, 
                                    $catatan_admin, $jenis_periode, $jumlah_kunjungan, $jadwal_id
                                ]);
                            }
                            
                            $_SESSION['success'] = 'Jadwal berulang berhasil dibuat! ' . $jumlah_kunjungan . ' kunjungan ' . strtolower($jenis_periode) . ' (Kode: ' . $kode_jadwal . ')';
                        } else {
                            $_SESSION['success'] = 'Jadwal berhasil dibuat! Kode: ' . $kode_jadwal . ' (' . $jumlah_kunjungan . ' station)';
                        }
                        
                    } 
                    // UPDATE EXISTING SCHEDULE
                    elseif ($action === 'update' && $id) {
                        // Hanya update jadwal utama (tidak update child schedules)
                        $stmt = $pdo->prepare("
                            UPDATE jadwal SET 
                                pekerja_id = ?, customer_id = ?, service_id = ?, 
                                tanggal = ?, jam = ?, lokasi = ?, durasi_estimasi = ?, 
                                prioritas = ?, catatan_admin = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $pekerja_id, $customer_id, $service_id,
                            $tanggal, $jam, $lokasi, $durasi_estimasi,
                            $prioritas, $catatan_admin, $id
                        ]);
                        $_SESSION['success'] = 'Jadwal berhasil diperbarui!';
                    }
                    
                } catch (PDOException $e) {
                    $_SESSION['error'] = 'Terjadi kesalahan sistem: ' . $e->getMessage();
                    error_log("Schedule Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                }
            }
        }
    }
    
    header("Location: schedule.php");
    exit();
}

// =========================
// UPDATE STATUS JADWAL
// =========================
if (isset($_GET['update_status'])) {
    $id = (int)$_GET['id'];
    $status = $_GET['update_status'];
    $allowed_statuses = ['Menunggu', 'Berjalan', 'Selesai', 'Dibatalkan'];
    
    if (in_array($status, $allowed_statuses)) {
        try {
            $stmt = $pdo->prepare("UPDATE jadwal SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $id]);
            $_SESSION['success'] = "Status jadwal berhasil diubah menjadi '$status'!";
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Gagal mengubah status: ' . $e->getMessage();
        }
    }
    header("Location: schedule.php");
    exit();
}

// =========================
// DELETE JADWAL
// =========================
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    try {
        // Cek apakah jadwal bagian dari seri berulang
        $stmt = $pdo->prepare("SELECT kode_jadwal, jenis_periode, parent_jadwal_id FROM jadwal WHERE id = ?");
        $stmt->execute([$id]);
        $jadwal = $stmt->fetch();
        
        if ($jadwal) {
            // Jika jadwal memiliki parent (child schedule)
            if ($jadwal['parent_jadwal_id']) {
                $stmt = $pdo->prepare("DELETE FROM jadwal WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['success'] = 'Child schedule berhasil dihapus!';
            } else {
                // Jika jadwal adalah parent (atau standalone)
                // Cek apakah sudah ada laporan untuk jadwal ini
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE jadwal_id = ?");
                $stmt->execute([$id]);
                $report_count = $stmt->fetchColumn();
                
                if ($report_count > 0) {
                    $_SESSION['error'] = 'Tidak dapat menghapus! Jadwal ini sudah memiliki ' . $report_count . ' laporan.';
                } else {
                    // Hapus semua child schedules terlebih dahulu (jika ada)
                    $stmt = $pdo->prepare("DELETE FROM jadwal WHERE parent_jadwal_id = ?");
                    $stmt->execute([$id]);
                    
                    // Hapus jadwal utama
                    $stmt = $pdo->prepare("DELETE FROM jadwal WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    $_SESSION['success'] = 'Jadwal berhasil dihapus!';
                }
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Gagal menghapus jadwal: ' . $e->getMessage();
    }
    header("Location: schedule.php");
    exit();
}

// =========================
// AMBIL DATA JADWAL DENGAN FILTER
// =========================
try {
    $query = "
        SELECT 
            j.*,
            c.nama_perusahaan, c.nama_customer, c.telepon as customer_telepon,
            c.gedung, c.lantai, c.unit, c.jumlah_station,
            s.nama_service, s.harga, s.kode_service, s.durasi_menit,
            u.nama as pekerja_nama, u.jabatan as pekerja_jabatan,
            a.nama as admin_nama,
            
            -- Hitung total laporan untuk jadwal ini
            (SELECT COUNT(*) FROM reports r WHERE r.jadwal_id = j.id) as total_laporan,
            
            -- Hitung child schedules untuk parent jadwal
            (SELECT COUNT(*) FROM jadwal j2 WHERE j2.parent_jadwal_id = j.id) as child_count,
            
            -- Progress status untuk jadwal berulang
            CASE 
                WHEN j.jenis_periode = 'Sekali' THEN 'Sekali'
                WHEN j.kunjungan_berjalan >= j.jumlah_kunjungan THEN 'Selesai Semua'
                ELSE CONCAT(j.kunjungan_berjalan, '/', j.jumlah_kunjungan, ' kunjungan')
            END as progress_status
            
        FROM jadwal j
        LEFT JOIN customers c ON j.customer_id = c.id
        LEFT JOIN services s ON j.service_id = s.id
        LEFT JOIN users u ON j.pekerja_id = u.id
        LEFT JOIN admin_users a ON j.admin_id = a.id
        WHERE 1=1";
    
    $params = [];
    
    if (!empty($filter_status)) {
        $query .= " AND j.status = ?";
        $params[] = $filter_status;
    }
    
    if (!empty($filter_date_from)) {
        $query .= " AND j.tanggal >= ?";
        $params[] = $filter_date_from;
    }
    
    if (!empty($filter_date_to)) {
        $query .= " AND j.tanggal <= ?";
        $params[] = $filter_date_to;
    }
    
    if (!empty($filter_customer)) {
        $query .= " AND j.customer_id = ?";
        $params[] = (int)$filter_customer;
    }
    
    if (!empty($filter_worker)) {
        $query .= " AND j.pekerja_id = ?";
        $params[] = (int)$filter_worker;
    }
    
    if (!empty($filter_periode)) {
        $query .= " AND j.jenis_periode = ?";
        $params[] = $filter_periode;
    }
    
    // Filter untuk menampilkan parent schedules atau semua schedules
    $show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
    if (!$show_all) {
        $query .= " AND j.parent_jadwal_id IS NULL";
    }
    
    $query .= " ORDER BY 
                CASE j.status
                    WHEN 'Berjalan' THEN 1
                    WHEN 'Menunggu' THEN 2
                    WHEN 'Selesai' THEN 3
                    WHEN 'Dibatalkan' THEN 4
                    ELSE 5
                END,
                j.tanggal ASC, j.jam ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Gagal mengambil data jadwal: " . $e->getMessage();
    error_log("Schedule Query Error: " . $e->getMessage());
    $schedules = [];
}

$pageTitle = 'Kelola Jadwal';

require_once 'includes/header.php';
?>

<style>
    .schedule-card {
        background-color: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        margin-bottom: 20px;
        overflow: hidden;
    }
    .schedule-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.1);
    }
    .schedule-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e9ecef;
        background-color: #f8f9fa;
    }
    .schedule-body {
        padding: 1.25rem;
    }
    .schedule-footer {
        padding: 1rem 1.25rem;
        border-top: 1px solid #e9ecef;
        background-color: #f8f9fa;
    }
    .status-badge {
        font-size: 0.75rem;
        padding: 0.35rem 0.75rem;
        border-radius: 50px;
        font-weight: 600;
        text-transform: uppercase;
    }
    .status-menunggu { background-color: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
    .status-berjalan { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
    .status-selesai { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .status-dibatalkan { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .priority-badge {
        font-size: 0.7rem;
        padding: 0.25rem 0.5rem;
        border-radius: 50px;
        font-weight: 600;
    }
    .priority-rendah { background-color: #e7f5ff; color: #0c63e4; }
    .priority-sedang { background-color: #e7fff3; color: #198754; }
    .priority-tinggi { background-color: #fff3cd; color: #856404; }
    .priority-darurat { background-color: #f8d7da; color: #721c24; }
    .info-item {
        display: flex;
        align-items: flex-start;
        margin-bottom: 0.75rem;
    }
    .info-item i {
        color: #6c757d;
        width: 20px;
        margin-top: 3px;
        margin-right: 10px;
        text-align: center;
    }
    .price-tag {
        font-size: 1.25rem;
        font-weight: 700;
        color: #198754;
        text-align: right;
    }
    .filter-card {
        background-color: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .empty-state-container {
        background-color: #f8f9fa;
        padding: 4rem;
        border-radius: 12px;
        border: 1px dashed #dee2e6;
        text-align: center;
    }
    .schedule-code {
        font-family: monospace;
        background: #f1f3f4;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 0.9rem;
    }
    .location-detail {
        background: #f8f9fa;
        padding: 0.75rem;
        border-radius: 8px;
        margin-top: 0.5rem;
    }
    .report-badge {
        background-color: #e9ecef;
        color: #495057;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.8rem;
        margin-left: 0.5rem;
    }
    .period-badge {
        background-color: #d4edda;
        color: #155724;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-size: 0.7rem;
        margin-left: 0.5rem;
    }
    .kunjungan-info {
        background-color: #e7f5ff;
        padding: 0.5rem;
        border-radius: 6px;
        margin-top: 0.5rem;
        font-size: 0.85rem;
    }
    .child-badge {
        background-color: #f8f9fa;
        color: #6c757d;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-size: 0.7rem;
        margin-left: 0.5rem;
        border: 1px dashed #dee2e6;
    }
    .parent-badge {
        background-color: #fff3cd;
        color: #856404;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-size: 0.7rem;
        margin-left: 0.5rem;
        border: 1px solid #ffeaa7;
    }
    .station-badge {
        background-color: #e3f2fd;
        color: #1565c0;
        padding: 0.25rem 0.75rem;
        border-radius: 50px;
        font-size: 0.75rem;
        margin-left: 0.5rem;
    }
</style>

<?php
require_once 'includes/navbar.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php require_once 'includes/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-calendar-alt me-2"></i><?php echo $pageTitle; ?></h1>
                <div>
                    <button type="button" id="btnTambahJadwal" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-2"></i>Tambah Jadwal
                    </button>
                </div>
            </div>

            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="filter-card">
                <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Jadwal</h5>
                <form method="GET" action="schedule.php" class="row g-3">
                    <div class="col-md-2">
                        <label for="filter_status" class="form-label">Status</label>
                        <select class="form-select" id="filter_status" name="status">
                            <option value="">Semua Status</option>
                            <option value="Menunggu" <?php echo $filter_status === 'Menunggu' ? 'selected' : ''; ?>>Menunggu</option>
                            <option value="Berjalan" <?php echo $filter_status === 'Berjalan' ? 'selected' : ''; ?>>Berjalan</option>
                            <option value="Selesai" <?php echo $filter_status === 'Selesai' ? 'selected' : ''; ?>>Selesai</option>
                            <option value="Dibatalkan" <?php echo $filter_status === 'Dibatalkan' ? 'selected' : ''; ?>>Dibatalkan</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_date_from" class="form-label">Dari Tanggal</label>
                        <input type="date" class="form-control" id="filter_date_from" name="date_from" 
                               value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="filter_date_to" class="form-label">Sampai Tanggal</label>
                        <input type="date" class="form-control" id="filter_date_to" name="date_to" 
                               value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="filter_customer" class="form-label">Customer</label>
                        <select class="form-select" id="filter_customer" name="customer">
                            <option value="">Semua Customer</option>
                            <?php foreach ($all_customers_for_filter as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>" 
                                    <?php echo $filter_customer == $customer['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($customer['display_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_periode" class="form-label">Jenis Periode</label>
                        <select class="form-select" id="filter_periode" name="periode">
                            <option value="">Semua</option>
                            <option value="Sekali" <?php echo $filter_periode === 'Sekali' ? 'selected' : ''; ?>>Sekali</option>
                            <option value="Harian" <?php echo $filter_periode === 'Harian' ? 'selected' : ''; ?>>Harian</option>
                            <option value="Mingguan" <?php echo $filter_periode === 'Mingguan' ? 'selected' : ''; ?>>Mingguan</option>
                            <option value="Bulanan" <?php echo $filter_periode === 'Bulanan' ? 'selected' : ''; ?>>Bulanan</option>
                            <option value="Tahunan" <?php echo $filter_periode === 'Tahunan' ? 'selected' : ''; ?>>Tahunan</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <div class="d-flex gap-2 w-100">
                            <button type="submit" class="btn btn-outline-primary flex-grow-1">
                                <i class="fas fa-search"></i>
                            </button>
                            <a href="schedule.php" class="btn btn-outline-secondary">
                                <i class="fas fa-redo"></i>
                            </a>
                        </div>
                    </div>
                </form>
                
                <!-- Toggle untuk menampilkan semua jadwal termasuk child -->
                <div class="mt-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="showAllSchedules" 
                           onchange="window.location.href='schedule.php?show_all=' + (this.checked ? '1' : '0')"
                           <?php echo isset($_GET['show_all']) && $_GET['show_all'] == '1' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="showAllSchedules">
                        Tampilkan semua jadwal (termasuk child schedules)
                    </label>
                </div>
            </div>

            <!-- Statistics -->
            <?php if (!empty($schedules)): 
                $total_schedules = count($schedules);
                $status_counts = [
                    'Menunggu' => 0,
                    'Berjalan' => 0,
                    'Selesai' => 0,
                    'Dibatalkan' => 0
                ];
                
                $period_counts = [
                    'Sekali' => 0,
                    'Harian' => 0,
                    'Mingguan' => 0,
                    'Bulanan' => 0,
                    'Tahunan' => 0
                ];
                
                $total_reports = 0;
                $total_child_schedules = 0;
                $total_stations = 0;
                
                foreach ($schedules as $schedule) {
                    if (isset($status_counts[$schedule['status']])) {
                        $status_counts[$schedule['status']]++;
                    }
                    if (isset($period_counts[$schedule['jenis_periode']])) {
                        $period_counts[$schedule['jenis_periode']]++;
                    }
                    $total_reports += $schedule['total_laporan'] ?? 0;
                    $total_child_schedules += $schedule['child_count'] ?? 0;
                    $total_stations += $schedule['jumlah_station'] ?? 0;
                }
            ?>
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <h6 class="card-title">Total Jadwal</h6>
                            <h3 class="mb-0"><?php echo $total_schedules; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-warning text-dark">
                        <div class="card-body">
                            <h6 class="card-title">Menunggu</h6>
                            <h3 class="mb-0"><?php echo $status_counts['Menunggu']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <h6 class="card-title">Berjalan</h6>
                            <h3 class="mb-0"><?php echo $status_counts['Berjalan']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <h6 class="card-title">Selesai</h6>
                            <h3 class="mb-0"><?php echo $status_counts['Selesai']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-light text-dark">
                        <div class="card-body">
                            <h6 class="card-title">Laporan</h6>
                            <h3 class="mb-0"><?php echo $total_reports; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <h6 class="card-title">Total Station</h6>
                            <h3 class="mb-0"><?php echo $total_stations; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Schedule List -->
            <div class="row">
                <?php if (empty($schedules)): ?>
                    <div class="col">
                        <div class="text-center empty-state-container">
                            <i class="fas fa-calendar-times fa-4x text-muted mb-4"></i>
                            <h4 class="text-dark fw-bold">Belum Ada Jadwal</h4>
                            <p class="text-muted">Tekan tombol "Tambah Jadwal" untuk membuat jadwal baru.</p>
                            <button type="button" id="btnTambahJadwal2" class="btn btn-primary mt-3">
                                <i class="fas fa-plus-circle me-2"></i>Tambah Jadwal Pertama
                            </button>
                        </div>
                    </div>
                <?php else: 
                    foreach ($schedules as $schedule): 
                        // Format data
                        $tanggal_formatted = formatTanggalIndonesia($schedule['tanggal']);
                        $jam_formatted = date('H:i', strtotime($schedule['jam']));
                        $harga_formatted = 'Rp ' . number_format($schedule['harga'], 0, ',', '.');
                        $status_class = 'status-' . strtolower($schedule['status']);
                        $priority_class = 'priority-' . strtolower($schedule['prioritas'] ?? 'sedang');
                        
                        // Lokasi detail dari customer
                        $lokasi_detail = '';
                        if (!empty($schedule['gedung'])) $lokasi_detail .= $schedule['gedung'];
                        if (!empty($schedule['lantai'])) $lokasi_detail .= ', Lt. ' . $schedule['lantai'];
                        if (!empty($schedule['unit'])) $lokasi_detail .= ', ' . $schedule['unit'];
                        
                        // Info kunjungan
                        $is_recurring = $schedule['jenis_periode'] !== 'Sekali';
                        $is_parent = empty($schedule['parent_jadwal_id']);
                        $is_child = !$is_parent;
                        $jumlah_station = $schedule['jumlah_station'] ?? 0;
                        $jumlah_kunjungan = $schedule['jumlah_kunjungan'] ?? 1;
                        
                        // Tampilkan info station jika ada
                        $station_info = '';
                        if ($jumlah_station > 0 && $schedule['jenis_periode'] === 'Sekali') {
                            $station_info = '<span class="station-badge"><i class="fas fa-map-pin me-1"></i>' . $jumlah_station . ' Station</span>';
                        }
                    
                    ?>
                        <div class="col-xl-6 col-lg-12 mb-4">
                            <div class="schedule-card">
                                <div class="schedule-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="mb-1"><?php echo htmlspecialchars($schedule['nama_perusahaan']); ?>
                                            <?php if ($schedule['total_laporan'] > 0): ?>
                                                <span class="report-badge" title="<?php echo $schedule['total_laporan']; ?> laporan dibuat">
                                                    <i class="fas fa-file-alt me-1"></i><?php echo $schedule['total_laporan']; ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($is_recurring): ?>
                                                <span class="period-badge">
                                                    <i class="fas fa-redo me-1"></i><?php echo $schedule['jenis_periode']; ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($is_child): ?>
                                                <span class="child-badge" title="Child Schedule">
                                                    <i class="fas fa-level-down-alt me-1"></i>Child
                                                </span>
                                            <?php elseif ($schedule['child_count'] > 0): ?>
                                                <span class="parent-badge" title="Parent Schedule">
                                                    <i class="fas fa-level-up-alt me-1"></i>Parent (<?php echo $schedule['child_count']; ?>)
                                                </span>
                                            <?php endif; ?>
                                            <?php echo $station_info; ?>
                                        </h5>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="schedule-code"><?php echo htmlspecialchars($schedule['kode_jadwal'] ?? 'JDW/XXXX/XX/XXX'); ?></span>
                                            <span class="badge bg-light text-dark">
                                                <i class="fas fa-user me-1"></i>
                                                <?php echo htmlspecialchars($schedule['nama_customer']); ?>
                                            </span>
                                            <span class="priority-badge <?php echo $priority_class; ?>">
                                                <?php echo htmlspecialchars($schedule['prioritas'] ?? 'Sedang'); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($schedule['status']); ?>
                                    </span>
                                </div>
                                
                                <div class="schedule-body">
                                    <?php if ($is_recurring && $jumlah_kunjungan > 1): ?>
                                    <div class="kunjungan-info mb-3">
                                        <i class="fas fa-calendar-check me-1"></i>
                                        Jadwal <?php echo strtolower($schedule['jenis_periode']); ?> - 
                                        <?php echo $schedule['kunjungan_berjalan'] ?? 0; ?> dari <?php echo $jumlah_kunjungan; ?> kunjungan selesai
                                    </div>
                                    <?php elseif ($jumlah_station > 0 && $schedule['jenis_periode'] === 'Sekali'): ?>
                                    <div class="kunjungan-info mb-3">
                                        <i class="fas fa-map-marker-alt me-1"></i>
                                        <?php echo $jumlah_station; ?> Station Inspeksi - 
                                        <?php echo $schedule['kunjungan_berjalan'] ?? 0; ?> dari <?php echo $jumlah_kunjungan; ?> laporan dibuat
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="info-item">
                                                <i class="fas fa-calendar-day"></i>
                                                <div>
                                                    <strong>Tanggal & Jam:</strong><br>
                                                    <?php echo $tanggal_formatted . ' • ' . $jam_formatted; ?>
                                                    <?php if (!empty($schedule['durasi_estimasi'])): ?>
                                                        <br><small class="text-muted">Durasi: <?php echo $schedule['durasi_estimasi']; ?> menit</small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="info-item">
                                                <i class="fas fa-cog"></i>
                                                <div>
                                                    <strong>Layanan:</strong><br>
                                                    <?php echo htmlspecialchars($schedule['nama_service']); ?>
                                                    <?php if (!empty($schedule['kode_service'])): ?>
                                                        <small class="text-muted">(<?php echo htmlspecialchars($schedule['kode_service']); ?>)</small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="info-item">
                                                <i class="fas fa-user-tie"></i>
                                                <div>
                                                    <strong>Pekerja:</strong><br>
                                                    <?php if (!empty($schedule['pekerja_nama'])): ?>
                                                        <?php echo htmlspecialchars($schedule['pekerja_nama']); ?>
                                                        <?php if (!empty($schedule['pekerja_jabatan'])): ?>
                                                            <br><small class="text-muted"><?php echo htmlspecialchars($schedule['pekerja_jabatan']); ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">- Belum ditugaskan -</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <?php if (!empty($schedule['lokasi']) || !empty($lokasi_detail)): ?>
                                            <div class="info-item">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <div>
                                                    <strong>Lokasi:</strong><br>
                                                    <?php if (!empty($schedule['lokasi'])): ?>
                                                        <?php echo nl2br(htmlspecialchars($schedule['lokasi'])); ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($lokasi_detail)): ?>
                                                        <div class="location-detail mt-1">
                                                            <small><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($lokasi_detail); ?></small>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($jumlah_station > 0): ?>
                                                        <div class="location-detail mt-1">
                                                            <small><i class="fas fa-map-pin me-1"></i><?php echo $jumlah_station; ?> Station Inspeksi</small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($schedule['catatan_admin'])): ?>
                                            <div class="info-item">
                                                <i class="fas fa-clipboard-list"></i>
                                                <div>
                                                    <strong>Catatan:</strong><br>
                                                    <?php echo nl2br(htmlspecialchars($schedule['catatan_admin'])); ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="price-tag mb-3">
                                                <?php echo $harga_formatted; ?>
                                            </div>
                                            
                                            <div class="info-item">
                                                <i class="fas fa-phone"></i>
                                                <div>
                                                    <small>Telp: <?php echo htmlspecialchars($schedule['customer_telepon']); ?></small>
                                                </div>
                                            </div>
                                            
                                            <div class="info-item">
                                                <i class="fas fa-user-cog"></i>
                                                <div>
                                                    <small>Dibuat oleh: <?php echo htmlspecialchars($schedule['admin_nama']); ?></small>
                                                </div>
                                            </div>
                                            
                                            <?php if (!empty($schedule['created_at'])): ?>
                                            <div class="info-item">
                                                <i class="fas fa-clock"></i>
                                                <div>
                                                    <small>Dibuat: <?php echo date('d/m/Y H:i', strtotime($schedule['created_at'])); ?></small>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="schedule-footer">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex gap-2">
                                            <?php if ($is_child): ?>
                                                <span class="text-muted small">Child schedule - edit melalui parent</span>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary btn-edit" 
                                                        data-id="<?php echo $schedule['id']; ?>"
                                                        data-customer_id="<?php echo $schedule['customer_id']; ?>"
                                                        data-service_id="<?php echo $schedule['service_id']; ?>"
                                                        data-pekerja_id="<?php echo $schedule['pekerja_id']; ?>"
                                                        data-tanggal="<?php echo $schedule['tanggal']; ?>"
                                                        data-jam="<?php echo $schedule['jam']; ?>"
                                                        data-lokasi="<?php echo htmlspecialchars($schedule['lokasi']); ?>"
                                                        data-catatan_admin="<?php echo htmlspecialchars($schedule['catatan_admin']); ?>"
                                                        data-prioritas="<?php echo htmlspecialchars($schedule['prioritas']); ?>"
                                                        data-durasi_estimasi="<?php echo $schedule['durasi_estimasi']; ?>"
                                                        data-jenis_periode="<?php echo htmlspecialchars($schedule['jenis_periode']); ?>"
                                                        data-jumlah_kunjungan="<?php echo $schedule['jumlah_kunjungan']; ?>">
                                                    <i class="fas fa-edit me-1"></i>Edit
                                                </button>
                                            <?php endif; ?>
                                            
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                                    <i class="fas fa-cog me-1"></i>Aksi
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><a class="dropdown-item" href="?update_status=Menunggu&id=<?php echo $schedule['id']; ?>">Set Menunggu</a></li>
                                                    <li><a class="dropdown-item" href="?update_status=Berjalan&id=<?php echo $schedule['id']; ?>">Set Berjalan</a></li>
                                                    <li><a class="dropdown-item" href="?update_status=Selesai&id=<?php echo $schedule['id']; ?>">Set Selesai</a></li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <?php if ($schedule['total_laporan'] > 0): ?>
                                                    <li><a class="dropdown-item" href="reports.php?schedule_id=<?php echo $schedule['id']; ?>">
                                                        <i class="fas fa-file-alt me-1"></i>Lihat Laporan (<?php echo $schedule['total_laporan']; ?>)
                                                    </a></li>
                                                    <?php endif; ?>
                                                    <li><a class="dropdown-item text-danger" href="?update_status=Dibatalkan&id=<?php echo $schedule['id']; ?>">Batalkan Jadwal</a></li>
                                                </ul>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex gap-2">
                                            <?php if ($schedule['total_laporan'] == 0 || $is_child): ?>
                                            <a href="?delete=<?php echo $schedule['id']; ?>" class="btn btn-sm btn-outline-danger" 
                                               onclick="return confirm('Hapus jadwal ini? Tindakan ini tidak dapat dibatalkan.')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <?php else: ?>
                                            <button class="btn btn-sm btn-outline-danger" disabled 
                                                    title="Tidak dapat dihapus karena sudah memiliki laporan">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Modal Tambah/Edit Jadwal -->
<div class="modal fade" id="scheduleModal" tabindex="-1" aria-labelledby="scheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="scheduleForm" method="POST" action="schedule.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="scheduleModalLabel">Tambah Jadwal Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="formScheduleId">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="customer_id" class="form-label">Customer <span class="text-danger">*</span></label>
                            <select class="form-select" id="customer_id" name="customer_id" required>
                                <option value="">Pilih Customer</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>" 
                                            data-telepon="<?php echo htmlspecialchars($customer['telepon']); ?>"
                                            data-gedung="<?php echo htmlspecialchars($customer['gedung'] ?? ''); ?>"
                                            data-lantai="<?php echo htmlspecialchars($customer['lantai'] ?? ''); ?>"
                                            data-unit="<?php echo htmlspecialchars($customer['unit'] ?? ''); ?>"
                                            data-jumlah_station="<?php echo htmlspecialchars($customer['jumlah_station'] ?? 0); ?>">
                                        <?php echo htmlspecialchars($customer['display_name']); ?>
                                        <?php if (!empty($customer['jumlah_station']) && $customer['jumlah_station'] > 0): ?>
                                            (<?php echo $customer['jumlah_station']; ?> station)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="service_id" class="form-label">Layanan <span class="text-danger">*</span></label>
                            <select class="form-select" id="service_id" name="service_id" required>
                                <option value="">Pilih Layanan</option>
                                <?php foreach ($services as $service): ?>
                                    <option value="<?php echo $service['id']; ?>" 
                                            data-harga="<?php echo $service['harga']; ?>"
                                            data-durasi="<?php echo $service['durasi_menit']; ?>">
                                        <?php echo htmlspecialchars($service['nama_service']); ?> 
                                        (<?php echo htmlspecialchars($service['kode_service']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label for="tanggal" class="form-label">Tanggal <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="tanggal" name="tanggal" required 
                                   min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label for="jam" class="form-label">Jam <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="jam" name="jam" required>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="prioritas" class="form-label">Prioritas</label>
                            <select class="form-select" id="prioritas" name="prioritas">
                                <option value="Sedang">Sedang</option>
                                <option value="Rendah">Rendah</option>
                                <option value="Tinggi">Tinggi</option>
                                <option value="Darurat">Darurat</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="durasi_estimasi" class="form-label">Durasi (menit)</label>
                            <input type="number" class="form-control" id="durasi_estimasi" name="durasi_estimasi" 
                                   min="15" max="480" step="15" placeholder="Auto dari layanan">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="jenis_periode" class="form-label">Jenis Jadwal</label>
                            <select class="form-select" id="jenis_periode" name="jenis_periode">
                                <option value="Sekali">Sekali</option>
                                <option value="Harian">Harian</option>
                                <option value="Mingguan">Mingguan</option>
                                <option value="Bulanan">Bulanan</option>
                                <option value="Tahunan">Tahunan</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="jumlah_kunjungan" class="form-label">Jumlah Station</label>
                            <input type="number" class="form-control" id="jumlah_kunjungan" name="jumlah_kunjungan" 
                                   min="1" max="100" value="1">
                            <small class="text-muted">Akan otomatis sesuai customer</small>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="pekerja_id" class="form-label">Pekerja</label>
                            <select class="form-select" id="pekerja_id" name="pekerja_id">
                                <option value="">Pilih Pekerja (Opsional)</option>
                                <?php foreach ($workers as $worker): ?>
                                    <option value="<?php echo $worker['id']; ?>">
                                        <?php echo htmlspecialchars($worker['nama']); ?> 
                                        (<?php echo htmlspecialchars($worker['jabatan']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Info Customer</label>
                            <div id="customerInfo" class="alert alert-light p-2">
                                <small class="text-muted">Pilih customer untuk melihat info</small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Info Layanan</label>
                            <div id="servicePreview" class="alert alert-light p-2">
                                <small class="text-muted">Pilih layanan untuk melihat info</small>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="lokasi" class="form-label">Lokasi Detail (Opsional)</label>
                        <textarea class="form-control" id="lokasi" name="lokasi" rows="2" 
                                  placeholder="Masukkan alamat detail atau titik temu..."></textarea>
                        <small class="text-muted">Kosongkan untuk menggunakan alamat dari data customer</small>
                    </div>

                    <div class="mb-3">
                        <label for="catatan_admin" class="form-label">Catatan Admin</label>
                        <textarea class="form-control" id="catatan_admin" name="catatan_admin" rows="3" 
                                  placeholder="Catatan khusus untuk pekerja..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Jadwal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const scheduleModalEl = document.getElementById('scheduleModal');
    const scheduleModal = new bootstrap.Modal(scheduleModalEl);
    const form = document.getElementById('scheduleForm');
    const modalTitle = document.getElementById('scheduleModalLabel');
    const submitButton = form.querySelector('button[type="submit"]');
    const formAction = document.getElementById('formAction');
    const formScheduleId = document.getElementById('formScheduleId');
    const servicePreview = document.getElementById('servicePreview');
    const customerInfo = document.getElementById('customerInfo');
    const durasiEstimasiInput = document.getElementById('durasi_estimasi');
    const jenisPeriodeSelect = document.getElementById('jenis_periode');
    const jumlahKunjunganInput = document.getElementById('jumlah_kunjungan');
    const customerSelect = document.getElementById('customer_id');
    
    // Set default tanggal ke hari ini
    const today = new Date();
    const todayStr = today.toISOString().split('T')[0];
    document.getElementById('tanggal').value = todayStr;
    
    // Set default jam ke 08:00
    document.getElementById('jam').value = '08:00';
    
    // Event Listeners
    document.getElementById('btnTambahJadwal').addEventListener('click', setupAddModal);
    document.getElementById('btnTambahJadwal2')?.addEventListener('click', setupAddModal);
    
    document.querySelectorAll('.btn-edit').forEach(button => {
        button.addEventListener('click', function() { 
            setupEditModal(this); 
        });
    });
    
    // Customer select change
    customerSelect.addEventListener('change', function() {
        updateCustomerInfo();
        updateJumlahKunjungan();
    });
    
    // Service select change
    document.getElementById('service_id').addEventListener('change', function() {
        updateServiceInfo();
    });
    
    // Jenis periode change
    jenisPeriodeSelect.addEventListener('change', function() {
        updateJumlahKunjungan();
    });
    
    // Functions
    function setupAddModal() {
        form.reset();
        modalTitle.textContent = 'Tambah Jadwal Baru';
        submitButton.textContent = 'Simpan Jadwal';
        submitButton.classList.remove('btn-warning');
        submitButton.classList.add('btn-primary');
        formAction.value = 'create';
        formScheduleId.value = '';
        
        // Set default values
        document.getElementById('tanggal').value = todayStr;
        document.getElementById('tanggal').min = todayStr;
        document.getElementById('jam').value = '08:00';
        document.getElementById('prioritas').value = 'Sedang';
        document.getElementById('jenis_periode').value = 'Sekali';
        document.getElementById('jumlah_kunjungan').value = 1;
        document.getElementById('customer_id').value = '';
        document.getElementById('service_id').value = '';
        document.getElementById('pekerja_id').value = '';
        document.getElementById('durasi_estimasi').value = '';
        
        updateCustomerInfo();
        updateServiceInfo();
        updateJumlahKunjungan();
        
        scheduleModal.show();
    }

    function setupEditModal(button) {
        form.reset();
        const data = button.dataset;
        modalTitle.textContent = 'Edit Jadwal';
        submitButton.textContent = 'Update Jadwal';
        submitButton.classList.remove('btn-primary');
        submitButton.classList.add('btn-warning');
        formAction.value = 'update';
        formScheduleId.value = data.id;
        
        // Set values from data attributes
        document.getElementById('customer_id').value = data.customer_id || '';
        document.getElementById('service_id').value = data.service_id || '';
        document.getElementById('pekerja_id').value = data.pekerja_id || '';
        document.getElementById('tanggal').value = data.tanggal || todayStr;
        document.getElementById('tanggal').min = data.tanggal || todayStr;
        document.getElementById('jam').value = data.jam || '08:00';
        document.getElementById('prioritas').value = data.prioritas || 'Sedang';
        document.getElementById('durasi_estimasi').value = data.durasi_estimasi || '';
        document.getElementById('jenis_periode').value = data.jenis_periode || 'Sekali';
        document.getElementById('jumlah_kunjungan').value = data.jumlah_kunjungan || 1;
        document.getElementById('lokasi').value = data.lokasi || '';
        document.getElementById('catatan_admin').value = data.catatan_admin || '';
        
        // Trigger change events untuk update preview
        updateCustomerInfo();
        updateServiceInfo();
        updateJumlahKunjungan();
        
        scheduleModal.show();
    }

    function updateCustomerInfo() {
        const select = customerSelect;
        const selectedOption = select.options[select.selectedIndex];
        
        if (selectedOption.value) {
            const telepon = selectedOption.dataset.telepon || '-';
            const gedung = selectedOption.dataset.gedung || '-';
            const lantai = selectedOption.dataset.lantai || '-';
            const unit = selectedOption.dataset.unit || '-';
            const jumlah_station = selectedOption.dataset.jumlah_station || 0;
            
            customerInfo.innerHTML = `
                <div class="row small">
                    <div class="col-6"><strong>Telp:</strong> ${telepon}</div>
                    <div class="col-6"><strong>Gedung:</strong> ${gedung}</div>
                    <div class="col-6"><strong>Lantai:</strong> ${lantai}</div>
                    <div class="col-6"><strong>Unit:</strong> ${unit}</div>
                    ${jumlah_station > 0 ? `<div class="col-12 mt-1"><strong>Jumlah Station:</strong> ${jumlah_station}</div>` : ''}
                </div>
            `;
            customerInfo.classList.remove('alert-light');
            customerInfo.classList.add('alert-info');
        } else {
            customerInfo.innerHTML = '<small class="text-muted">Pilih customer untuk melihat info</small>';
            customerInfo.classList.remove('alert-info');
            customerInfo.classList.add('alert-light');
        }
    }

    function updateServiceInfo() {
        const select = document.getElementById('service_id');
        const selectedOption = select.options[select.selectedIndex];
        
        if (selectedOption.value) {
            const harga = selectedOption.dataset.harga || 0;
            const durasi = selectedOption.dataset.durasi || 0;
            
            // Format harga
            const hargaFormatted = new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                minimumFractionDigits: 0
            }).format(harga);
            
            servicePreview.innerHTML = `
                <div class="row small">
                    <div class="col-6"><strong>Harga:</strong> ${hargaFormatted}</div>
                    <div class="col-6"><strong>Durasi:</strong> ${durasi} menit</div>
                </div>
            `;
            servicePreview.classList.remove('alert-light');
            servicePreview.classList.add('alert-info');
            
            // Set durasi estimasi otomatis
            if (durasi > 0 && !durasiEstimasiInput.value) {
                durasiEstimasiInput.value = durasi;
            }
        } else {
            servicePreview.innerHTML = '<small class="text-muted">Pilih layanan untuk melihat info</small>';
            servicePreview.classList.remove('alert-info');
            servicePreview.classList.add('alert-light');
        }
    }

    function updateJumlahKunjungan() {
        const select = customerSelect;
        const selectedOption = select.options[select.selectedIndex];
        const jenisPeriode = jenisPeriodeSelect.value;
        
        if (selectedOption.value) {
            const jumlah_station = parseInt(selectedOption.dataset.jumlah_station || 0);
            
            if (jenisPeriode === 'Sekali' && jumlah_station > 0) {
                // Jika jadwal sekali dan customer punya station, gunakan jumlah station
                jumlahKunjunganInput.value = jumlah_station;
                jumlahKunjunganInput.readOnly = true;
                jumlahKunjunganInput.title = 'Jumlah station dari customer: ' + jumlah_station;
            } else if (jenisPeriode === 'Sekali') {
                // Jika jadwal sekali tapi tidak ada station
                jumlahKunjunganInput.value = 1;
                jumlahKunjunganInput.readOnly = true;
                jumlahKunjunganInput.title = 'Jadwal sekali hanya untuk 1 kunjungan';
            } else {
                // Jika jadwal berulang
                jumlahKunjunganInput.readOnly = false;
                jumlahKunjunganInput.title = '';
                if (jumlahKunjunganInput.value < 2) {
                    jumlahKunjunganInput.value = 2;
                }
            }
        }
    }

    // Validasi form sebelum submit
    form.addEventListener('submit', function(event) {
        const tanggal = document.getElementById('tanggal').value;
        const selectedDate = new Date(tanggal);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        // Validasi tanggal tidak di masa lalu
        if (selectedDate < today) {
            alert('Tanggal tidak boleh di masa lalu!');
            event.preventDefault();
            return;
        }
        
        // Validasi required fields
        if (!document.getElementById('customer_id').value) {
            alert('Harap pilih customer!');
            event.preventDefault();
            return;
        }
        
        if (!document.getElementById('service_id').value) {
            alert('Harap pilih layanan!');
            event.preventDefault();
            return;
        }
        
        if (!document.getElementById('tanggal').value) {
            alert('Harap pilih tanggal!');
            event.preventDefault();
            return;
        }
        
        if (!document.getElementById('jam').value) {
            alert('Harap pilih jam!');
            event.preventDefault();
            return;
        }
        
        // Validasi untuk jadwal berulang
        const jenisPeriode = document.getElementById('jenis_periode').value;
        const jumlahKunjungan = parseInt(document.getElementById('jumlah_kunjungan').value);
        
        if (jenisPeriode !== 'Sekali') {
            if (jumlahKunjungan < 2) {
                alert('Jumlah kunjungan minimal 2 untuk jadwal berulang!');
                event.preventDefault();
                return;
            }
            
            if (jumlahKunjungan > 100) {
                alert('Jumlah kunjungan maksimal 100!');
                event.preventDefault();
                return;
            }
            
            if (!confirm(`Anda akan membuat ${jumlahKunjungan} jadwal ${jenisPeriode.toLowerCase()}. Lanjutkan?`)) {
                event.preventDefault();
                return;
            }
        }
    });
});
</script>

</body>
</html>