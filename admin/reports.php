<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

checkLogin('admin');

$pdo = getConnection();
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

// Inisialisasi filter
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_pekerja_id = isset($_GET['pekerja_id']) ? (int)$_GET['pekerja_id'] : '';
$filter_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : '';
$filter_station = isset($_GET['station']) ? (int)$_GET['station'] : '';
$filter_service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : '';
$search = $_GET['search'] ?? '';

// Ambil data pekerja untuk filter dropdown
try {
    $workers = $pdo->query("SELECT id, nama, username FROM users WHERE status = 'Aktif' ORDER BY nama ASC")->fetchAll();
} catch (PDOException $e) {
    $error = "Gagal mengambil data pekerja: " . $e->getMessage();
    $workers = [];
}

// Ambil data customer untuk filter dropdown
try {
    $customers = $pdo->query("SELECT id, nama_perusahaan, nama_customer, jumlah_station FROM customers WHERE status = 'Aktif' ORDER BY nama_perusahaan ASC")->fetchAll();
} catch (PDOException $e) {
    $customers = [];
}

// Ambil data service untuk filter dropdown
try {
    $services = $pdo->query("SELECT id, nama_service, kode_service FROM services WHERE status = 'Aktif' ORDER BY nama_service ASC")->fetchAll();
} catch (PDOException $e) {
    $services = [];
}

// HANDLE DELETE ACTION
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    try {
        // Cek apakah ada laporan dengan ID tersebut
        $stmt = $pdo->prepare("SELECT foto_bukti, foto_sebelum, foto_sesudah FROM reports WHERE id = ?");
        $stmt->execute([$id]);
        $photos = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Hapus file foto jika ada
        $upload_dir = '../assets/uploads/';
        $photos_to_delete = [
            $photos['foto_bukti'] ?? null,
            $photos['foto_sebelum'] ?? null,
            $photos['foto_sesudah'] ?? null
        ];
        
        foreach ($photos_to_delete as $photo) {
            if ($photo && file_exists($upload_dir . $photo)) {
                unlink($upload_dir . $photo);
            }
        }
        
        // Hapus dari database
        $deleteStmt = $pdo->prepare("DELETE FROM reports WHERE id = ?");
        $deleteStmt->execute([$id]);

        if ($deleteStmt->rowCount() > 0) {
            $_SESSION['success'] = 'Laporan berhasil dihapus!';
        } else {
            $_SESSION['error'] = 'Laporan tidak ditemukan!';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Terjadi kesalahan sistem: ' . $e->getMessage();
        error_log("Delete Report Error: " . $e->getMessage());
    }
    header("Location: reports.php");
    exit();
}

// Ambil data laporan dengan filter (dengan station support)
try {
    $query = "SELECT r.*, 
              u.nama as pekerja_nama, 
              u.username as pekerja_username, 
              u.jabatan as pekerja_jabatan,
              u.telepon as pekerja_telepon,
              
              -- Customer data
              c.nama_perusahaan,
              c.nama_customer,
              c.telepon as customer_telepon,
              c.jumlah_station as total_station_customer,
              
              -- Station data
              st.nama_station,
              st.lokasi as station_lokasi,
              
              -- Jadwal data
              j.kode_jadwal,
              j.tanggal as jadwal_tanggal, 
              j.jam as jadwal_jam, 
              j.lokasi as jadwal_lokasi,
              j.jenis_periode,
              j.station_terakhir,
              j.total_station_selesai,
              
              -- Service data
              s.nama_service, 
              s.kode_service,
              
              -- Admin data
              a.nama as admin_nama
              
              FROM reports r
              
              -- JOIN ke users (wajib)
              INNER JOIN users u ON r.user_id = u.id
              
              -- JOIN ke customers (mungkin NULL)
              LEFT JOIN customers c ON r.customer_id = c.id
              
              -- JOIN ke stations (jika ada station_id)
              LEFT JOIN stations st ON r.customer_id = st.customer_id AND r.station_id = st.station_number
              
              -- JOIN ke jadwal
              LEFT JOIN jadwal j ON r.jadwal_id = j.id
              
              -- JOIN ke services
              LEFT JOIN services s ON r.service_id = s.id
              
              -- JOIN ke admin
              LEFT JOIN admin_users a ON j.admin_id = a.id
              
              WHERE 1=1";
    
    $params = [];
    
    if (!empty($filter_date_from)) {
        $query .= " AND r.tanggal_pelaporan >= ?";
        $params[] = $filter_date_from;
    }
    
    if (!empty($filter_date_to)) {
        $query .= " AND r.tanggal_pelaporan <= ?";
        $params[] = $filter_date_to;
    }
    
    if (!empty($filter_pekerja_id)) {
        $query .= " AND r.user_id = ?";
        $params[] = $filter_pekerja_id;
    }
    
    if (!empty($filter_customer_id)) {
        $query .= " AND r.customer_id = ?";
        $params[] = $filter_customer_id;
    }
    
    if (!empty($filter_station)) {
        $query .= " AND r.station_id = ?";
        $params[] = $filter_station;
    }
    
    if (!empty($filter_service_id)) {
        $query .= " AND r.service_id = ?";
        $params[] = $filter_service_id;
    }
    
    if (!empty($search)) {
        $query .= " AND (
            c.nama_perusahaan LIKE ? OR 
            c.nama_customer LIKE ? OR 
            r.keterangan LIKE ? OR 
            s.nama_service LIKE ? OR
            r.kode_laporan LIKE ? OR
            st.nama_station LIKE ? OR
            r.station_nama LIKE ?
        )";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $query .= " ORDER BY r.tanggal_pelaporan DESC, r.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Hitung statistik
    $total_reports = count($reports);
    $total_with_photos = 0;
    $total_with_station = 0;
    $total_station_inspections = 0;
    
    foreach ($reports as $report) {
        if (!empty($report['foto_bukti']) || !empty($report['foto_sebelum']) || !empty($report['foto_sesudah'])) {
            $total_with_photos++;
        }
        if (!empty($report['station_id'])) {
            $total_with_station++;
            $total_station_inspections++;
        }
    }
    
} catch (PDOException $e) {
    $error = "Gagal mengambil data laporan: " . $e->getMessage();
    error_log("Report Query Error: " . $e->getMessage());
    $reports = [];
    $total_reports = 0;
    $total_with_photos = 0;
    $total_with_station = 0;
}

$pageTitle = 'Laporan Pekerjaan - Station Inspection';

// ========== HELPER FUNCTIONS ==========
function safe_html($value, $default = 'N/A') {
    if ($value === null || $value === '') {
        $value = $default;
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function safe_nl2br($value, $default = '-') {
    if ($value === null || trim($value) === '') {
        return $default;
    }
    return nl2br(htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'));
}

function format_time_safe($time) {
    if (empty($time) || $time === '00:00:00' || $time === '0000-00-00 00:00:00') {
        return '-';
    }
    try {
        return date('H:i', strtotime($time));
    } catch (Exception $e) {
        return '-';
    }
}

function format_date_safe($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    try {
        return date('d/m/Y', strtotime($date));
    } catch (Exception $e) {
        return $date;
    }
}

function get_photo_path($filename, $upload_dir = '../assets/uploads/') {
    if (empty($filename)) {
        return '';
    }
    $path = $upload_dir . $filename;
    return file_exists($path) ? $path : '';
}

function get_service_badge($service_name, $service_code) {
    if (empty($service_name)) {
        return '<span class="service-badge"><i class="fas fa-concierge-bell me-1"></i>N/A</span>';
    }
    
    $badge = '<span class="service-badge"><i class="fas fa-concierge-bell me-1"></i>' . safe_html($service_name);
    if (!empty($service_code)) {
        $badge .= ' <small>(' . safe_html($service_code) . ')</small>';
    }
    $badge .= '</span>';
    return $badge;
}

function get_station_badge($station_id, $station_name, $customer_stations) {
    if (empty($station_id)) {
        return '<span class="badge bg-secondary"><i class="fas fa-map-marker-alt me-1"></i>Tanpa Station</span>';
    }
    
    $badge = '<span class="badge bg-info text-dark station-badge">';
    $badge .= '<i class="fas fa-map-marker-alt me-1"></i>Station #' . $station_id;
    if (!empty($station_name)) {
        $badge .= ': ' . htmlspecialchars($station_name, ENT_QUOTES, 'UTF-8');
    }
    if ($customer_stations > 0) {
        $badge .= ' <small>(' . $station_id . '/' . $customer_stations . ')</small>';
    }
    $badge .= '</span>';
    return $badge;
}

function get_schedule_type_badge($jenis_periode) {
    $badges = [
        'Sekali' => '<span class="badge bg-primary">Single Visit</span>',
        'Harian' => '<span class="badge bg-success">Daily</span>',
        'Mingguan' => '<span class="badge bg-info">Weekly</span>',
        'Bulanan' => '<span class="badge bg-warning text-dark">Monthly</span>',
        'Tahunan' => '<span class="badge bg-danger">Yearly</span>'
    ];
    
    return $badges[$jenis_periode] ?? '<span class="badge bg-secondary">' . safe_html($jenis_periode) . '</span>';
}

function is_valid_image($path) {
    if (!file_exists($path) || !is_readable($path)) {
        return false;
    }
    
    // Periksa tipe MIME
    $mime_type = mime_content_type($path);
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    
    return in_array($mime_type, $allowed_types);
}
// ======================================

require_once 'includes/header.php';
?>

<style>
    .report-card {
        background-color: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        margin-bottom: 20px;
    }
    .report-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.1);
    }
    .report-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e9ecef;
        background-color: #f8f9fa;
        border-top-left-radius: 12px;
        border-top-right-radius: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .report-body {
        padding: 1.25rem;
    }
    .report-footer {
        padding: 1rem 1.25rem;
        border-top: 1px solid #e9ecef;
        background-color: #f8f9fa;
        border-bottom-left-radius: 12px;
        border-bottom-right-radius: 12px;
    }
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
    .report-keterangan {
        background-color: #f8f9fa;
        border-left: 4px solid #0d6efd;
        padding: 1rem;
        border-radius: 8px;
        margin: 1rem 0;
        line-height: 1.6;
    }
    .empty-state-container {
        background-color: #f8f9fa;
        padding: 4rem;
        border-radius: 12px;
        border: 1px dashed #dee2e6;
        text-align: center;
    }
    .filter-card {
        background-color: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .stats-card {
        background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
        color: white;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    .photo-preview {
        max-width: 200px;
        max-height: 150px;
        border-radius: 8px;
        border: 2px solid #e9ecef;
        cursor: pointer;
        transition: transform 0.2s ease;
        margin: 5px;
        object-fit: cover;
    }
    .photo-preview:hover {
        transform: scale(1.05);
    }
    .badge-date {
        background-color: #e7f1ff;
        color: #0d6efd;
        font-size: 0.85rem;
        padding: 0.3rem 0.7rem;
        border-radius: 50px;
    }
    .service-badge {
        background-color: #d1ecf1;
        color: #0c5460;
        font-size: 0.85rem;
        padding: 0.3rem 0.7rem;
        border-radius: 50px;
    }
    .station-badge {
        background-color: #fff3cd;
        color: #856404;
        font-size: 0.75rem;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        display: inline-block;
    }
    .company-badge {
        background-color: #e7f5ff;
        color: #0c63e4;
        font-size: 0.75rem;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        display: inline-block;
        margin-top: 3px;
    }
    .photo-gallery {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 10px;
    }
    .photo-item {
        text-align: center;
    }
    .photo-label {
        font-size: 0.75rem;
        color: #6c757d;
        margin-top: 3px;
    }
    .rating-stars {
        color: #ffc107;
        font-size: 1.2rem;
    }
    .modal-photo {
        max-height: 70vh;
        width: auto;
        margin: 0 auto;
        display: block;
    }
    .timeline-info {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        padding: 0.75rem;
        border-radius: 8px;
        margin-top: 10px;
    }
    .timeline-item {
        display: flex;
        justify-content: space-between;
        margin-bottom: 5px;
        font-size: 0.9rem;
    }
    .text-truncate-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        word-break: break-word;
    }
    .btn-delete-report {
        position: relative;
        overflow: hidden;
    }
    .btn-delete-report:hover {
        background-color: #dc3545 !important;
        border-color: #dc3545 !important;
        color: white !important;
    }
    .station-progress {
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 0.5rem;
        margin-top: 0.5rem;
    }
    .progress-bar-station {
        height: 8px;
        border-radius: 4px;
        background-color: #20c997;
    }
    .station-info-box {
        background-color: #e7f5ff;
        border-left: 4px solid #0d6efd;
        padding: 0.75rem;
        border-radius: 6px;
        margin: 0.5rem 0;
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
                <h1 class="h2"><i class="fas fa-clipboard-list me-2"></i><?php echo $pageTitle; ?></h1>
                <div class="badge bg-primary fs-6">
                    <i class="fas fa-file-alt me-1"></i> <?php echo $total_reports; ?> Laporan
                </div>
            </div>

            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo safe_html($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo safe_html($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- Stats Card -->
            <div class="stats-card">
                <div class="row">
                    <div class="col-md-3 mb-3 mb-md-0">
                        <div class="d-flex align-items-center">
                            <div class="bg-white rounded-circle p-3 me-3">
                                <i class="fas fa-file-alt text-primary fa-2x"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 fw-bold"><?php echo $total_reports; ?></h3>
                                <p class="mb-0 opacity-75">Total Laporan</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3 mb-md-0">
                        <div class="d-flex align-items-center">
                            <div class="bg-white rounded-circle p-3 me-3">
                                <i class="fas fa-camera text-success fa-2x"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 fw-bold"><?php echo $total_with_photos; ?></h3>
                                <p class="mb-0 opacity-75">Dengan Foto</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3 mb-md-0">
                        <div class="d-flex align-items-center">
                            <div class="bg-white rounded-circle p-3 me-3">
                                <i class="fas fa-map-marker-alt text-warning fa-2x"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 fw-bold"><?php echo $total_with_station; ?></h3>
                                <p class="mb-0 opacity-75">Station Inspection</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="d-flex align-items-center">
                            <div class="bg-white rounded-circle p-3 me-3">
                                <i class="fas fa-calendar-check text-info fa-2x"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 fw-bold"><?php echo date('d/m/Y'); ?></h3>
                                <p class="mb-0 opacity-75">Tanggal Hari Ini</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-card">
                <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Laporan</h5>
                <form method="GET" action="reports.php" class="row g-3">
                    <div class="col-md-2">
                        <label for="filter_date_from" class="form-label">Dari Tanggal</label>
                        <input type="date" class="form-control" id="filter_date_from" name="date_from" 
                               value="<?php echo safe_html($filter_date_from); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="filter_date_to" class="form-label">Sampai Tanggal</label>
                        <input type="date" class="form-control" id="filter_date_to" name="date_to" 
                               value="<?php echo safe_html($filter_date_to); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="filter_pekerja_id" class="form-label">Pekerja</label>
                        <select class="form-select" id="filter_pekerja_id" name="pekerja_id">
                            <option value="">Semua Pekerja</option>
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?php echo safe_html($worker['id']); ?>" 
                                        <?php echo $filter_pekerja_id == $worker['id'] ? 'selected' : ''; ?>>
                                    <?php echo safe_html($worker['nama']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_customer_id" class="form-label">Customer</label>
                        <select class="form-select" id="filter_customer_id" name="customer_id">
                            <option value="">Semua Customer</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo safe_html($customer['id']); ?>" 
                                        <?php echo $filter_customer_id == $customer['id'] ? 'selected' : ''; ?>>
                                    <?php echo safe_html($customer['nama_perusahaan']); ?>
                                    <?php if ($customer['jumlah_station'] > 0): ?>
                                        (<?php echo $customer['jumlah_station']; ?> station)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_service_id" class="form-label">Layanan</label>
                        <select class="form-select" id="filter_service_id" name="service_id">
                            <option value="">Semua Layanan</option>
                            <?php foreach ($services as $service): ?>
                                <option value="<?php echo safe_html($service['id']); ?>" 
                                        <?php echo $filter_service_id == $service['id'] ? 'selected' : ''; ?>>
                                    <?php echo safe_html($service['nama_service']); ?> (<?php echo safe_html($service['kode_service']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_station" class="form-label">Station</label>
                        <input type="number" class="form-control" id="filter_station" name="station" 
                               placeholder="No. Station" min="1" value="<?php echo safe_html($filter_station); ?>">
                    </div>
                    <div class="col-md-12">
                        <label for="search" class="form-label">Cari</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               placeholder="Cari perusahaan/customer/layanan/station..." value="<?php echo safe_html($search); ?>">
                    </div>
                    <div class="col-12">
                        <div class="d-flex">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-search me-2"></i>Terapkan Filter
                            </button>
                            <a href="reports.php" class="btn btn-outline-secondary">
                                <i class="fas fa-redo me-2"></i>Reset Filter
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Reports List -->
            <div class="row">
                <?php if (empty($reports)): ?>
                    <div class="col">
                        <div class="text-center empty-state-container">
                            <i class="fas fa-clipboard-check fa-4x text-muted mb-4"></i>
                            <h4 class="text-dark fw-bold">Belum Ada Laporan</h4>
                            <p class="text-muted">
                                <?php echo !empty($filter_date_from) || !empty($filter_pekerja_id) || !empty($search) 
                                    ? 'Tidak ditemukan laporan dengan filter yang dipilih.' 
                                    : 'Belum ada laporan yang disubmit oleh pekerja.'; ?>
                            </p>
                        </div>
                    </div>
                <?php else: 
                    foreach ($reports as $index => $report): 
                        $report_date = format_date_safe($report['tanggal_pelaporan'] ?? '');
                        $jam_mulai = format_time_safe($report['jam_mulai'] ?? '');
                        $jam_selesai = format_time_safe($report['jam_selesai'] ?? '');
                        $jadwal_date = format_date_safe($report['jadwal_tanggal'] ?? '');
                        $jadwal_time = format_time_safe($report['jadwal_jam'] ?? '');
                        
                        // Path foto dengan validasi
                        $upload_dir = '../assets/uploads/';
                        $foto_bukti_path = get_photo_path($report['foto_bukti'] ?? '', $upload_dir);
                        $foto_sebelum_path = get_photo_path($report['foto_sebelum'] ?? '', $upload_dir);
                        $foto_sesudah_path = get_photo_path($report['foto_sesudah'] ?? '', $upload_dir);
                        
                        // Cek validitas gambar
                        $has_foto_bukti = !empty($foto_bukti_path) && is_valid_image($foto_bukti_path);
                        $has_foto_sebelum = !empty($foto_sebelum_path) && is_valid_image($foto_sebelum_path);
                        $has_foto_sesudah = !empty($foto_sesudah_path) && is_valid_image($foto_sesudah_path);
                        $has_any_photo = $has_foto_bukti || $has_foto_sebelum || $has_foto_sesudah;
                        
                        // Station progress info
                        $total_station_customer = $report['total_station_customer'] ?? 0;
                        $station_selesai = $report['total_station_selesai'] ?? 0;
                        $station_progress = $total_station_customer > 0 ? round(($station_selesai / $total_station_customer) * 100) : 0;
                        
                        
                ?>
                        <div class="col-lg-6 mb-4">
                            <div class="report-card">
                                <div class="report-header">
                                    <div>
                                        <h5 class="mb-1 text-truncate" title="<?php echo safe_html($report['nama_perusahaan'] ?? 'N/A'); ?>">
                                            <?php echo safe_html($report['nama_perusahaan'] ?? 'N/A'); ?>
                                        </h5>
                                        <span class="company-badge" title="<?php echo safe_html($report['nama_customer'] ?? 'N/A'); ?>">
                                            <?php echo safe_html($report['nama_customer'] ?? 'N/A'); ?>
                                        </span>
                                        <div class="mt-1">
                                            <?php echo get_service_badge($report['nama_service'] ?? '', $report['kode_service'] ?? ''); ?>
                                            <?php if (!empty($report['jenis_periode'])): ?>
                                                <?php echo get_schedule_type_badge($report['jenis_periode']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="badge-date">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo $report_date; ?>
                                    </span>
                                </div>
                                
                                <div class="report-body">
                                    <!-- Station Info -->
                                    <?php if (!empty($report['station_id'])): ?>
                                    <div class="station-info-box mb-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><i class="fas fa-map-marker-alt me-1"></i>Station Inspection</strong><br>
                                                <?php echo get_station_badge(
                                                    $report['station_id'], 
                                                    $report['station_nama'] ?? $report['nama_station'] ?? 'Station', 
                                                    $total_station_customer
                                                ); ?>
                                            </div>
                                            <div class="text-end">
                                                <small class="text-muted">Progress</small><br>
                                                <strong><?php echo $station_selesai; ?>/<?php echo $total_station_customer; ?></strong>
                                            </div>
                                        </div>
                                        <?php if ($total_station_customer > 0): ?>
                                        <div class="station-progress mt-2">
                                            <div class="d-flex justify-content-between mb-1">
                                                <small>Station Selesai</small>
                                                <small><?php echo $station_progress; ?>%</small>
                                            </div>
                                            <div class="progress" style="height: 8px;">
                                                <div class="progress-bar progress-bar-station" role="progressbar" 
                                                     style="width: <?php echo $station_progress; ?>%"></div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="info-item">
                                        <i class="fas fa-user-tie"></i>
                                        <div>
                                            <strong>Pekerja:</strong> 
                                            <span title="<?php echo safe_html($report['pekerja_nama'] ?? 'N/A'); ?>">
                                                <?php echo safe_html($report['pekerja_nama'] ?? 'N/A'); ?>
                                            </span>
                                            <?php if (!empty($report['pekerja_jabatan'])): ?>
                                                <small class="text-muted">(<?php echo safe_html($report['pekerja_jabatan']); ?>)</small>
                                            <?php endif; ?>
                                            <br>
                                            <small class="text-muted">@<?php echo safe_html($report['pekerja_username'] ?? 'N/A'); ?></small>
                                            <?php if (!empty($report['pekerja_telepon'])): ?>
                                                <br><small class="text-muted">📱 <?php echo safe_html($report['pekerja_telepon']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <i class="fas fa-calendar-check"></i>
                                        <div>
                                            <strong>Jadwal:</strong> 
                                            <?php echo $jadwal_date . ' • ' . $jadwal_time; ?>
                                            <?php if (!empty($report['kode_jadwal'])): ?>
                                                <br><small class="text-muted">Kode: <?php echo safe_html($report['kode_jadwal']); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($report['jadwal_lokasi'])): ?>
                                                <br><small class="text-muted" title="<?php echo safe_html($report['jadwal_lokasi']); ?>">
                                                    📍 <?php echo mb_strlen($report['jadwal_lokasi']) > 50 ? mb_substr($report['jadwal_lokasi'], 0, 50) . '...' : safe_html($report['jadwal_lokasi']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($report['customer_telepon'])): ?>
                                    <div class="info-item">
                                        <i class="fas fa-phone"></i>
                                        <div>
                                            <strong>Kontak Customer:</strong> 
                                            <?php echo safe_html($report['customer_telepon']); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($jam_mulai !== '-' || $jam_selesai !== '-'): ?>
                                    <div class="timeline-info">
                                        <div class="timeline-item">
                                            <span>Jam Mulai:</span>
                                            <strong><?php echo $jam_mulai; ?></strong>
                                        </div>
                                        <div class="timeline-item">
                                            <span>Jam Selesai:</span>
                                            <strong><?php echo $jam_selesai; ?></strong>
                                        </div>
                                        <?php if (!empty($report['bahan_digunakan'])): ?>
                                        <div class="timeline-item">
                                            <span>Bahan Digunakan:</span>
                                            <strong title="<?php echo safe_html($report['bahan_digunakan']); ?>">
                                                <?php echo mb_strlen($report['bahan_digunakan']) > 30 ? mb_substr($report['bahan_digunakan'], 0, 30) . '...' : safe_html($report['bahan_digunakan']); ?>
                                            </strong>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($report['keterangan'])): ?>
                                    <div class="report-keterangan mt-3">
                                        <h6 class="mb-2"><i class="fas fa-clipboard-check me-2"></i>Keterangan Pekerjaan:</h6>
                                        <div class="text-truncate-2" title="<?php echo safe_html($report['keterangan']); ?>">
                                            <?php echo safe_nl2br($report['keterangan']); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($report['hasil_pengamatan'])): ?>
                                    <div class="alert alert-warning mt-3">
                                        <h6 class="mb-2"><i class="fas fa-binoculars me-2"></i>Hasil Pengamatan:</h6>
                                        <div class="text-truncate-2" title="<?php echo safe_html($report['hasil_pengamatan']); ?>">
                                            <?php echo safe_nl2br($report['hasil_pengamatan']); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($report['rekomendasi'])): ?>
                                    <div class="alert alert-info mt-3">
                                        <h6 class="mb-2"><i class="fas fa-lightbulb me-2"></i>Rekomendasi:</h6>
                                        <div class="text-truncate-2" title="<?php echo safe_html($report['rekomendasi']); ?>">
                                            <?php echo safe_nl2br($report['rekomendasi']); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($has_any_photo): ?>
                                    <div class="mt-3">
                                        <h6 class="mb-2"><i class="fas fa-camera me-2"></i>Foto Dokumentasi:</h6>
                                        <div class="photo-gallery">
                                            <?php if ($has_foto_sebelum): ?>
                                            <div class="photo-item">
                                                <img src="<?php echo $foto_sebelum_path; ?>" alt="Foto Sebelum" 
                                                     class="photo-preview" 
                                                     data-bs-toggle="modal" 
                                                     data-bs-target="#photoModal"
                                                     data-photo-src="<?php echo $foto_sebelum_path; ?>"
                                                     data-photo-title="Foto Sebelum - Station <?php echo $report['station_id'] ?? ''; ?>">
                                                <div class="photo-label">Sebelum</div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($has_foto_sesudah): ?>
                                            <div class="photo-item">
                                                <img src="<?php echo $foto_sesudah_path; ?>" alt="Foto Sesudah" 
                                                     class="photo-preview" 
                                                     data-bs-toggle="modal" 
                                                     data-bs-target="#photoModal"
                                                     data-photo-src="<?php echo $foto_sesudah_path; ?>"
                                                     data-photo-title="Foto Sesudah - Station <?php echo $report['station_id'] ?? ''; ?>">
                                                <div class="photo-label">Sesudah</div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($has_foto_bukti): ?>
                                            <div class="photo-item">
                                                <img src="<?php echo $foto_bukti_path; ?>" alt="Foto Bukti" 
                                                     class="photo-preview" 
                                                     data-bs-toggle="modal" 
                                                     data-bs-target="#photoModal"
                                                     data-photo-src="<?php echo $foto_bukti_path; ?>"
                                                     data-photo-title="Foto Bukti - Station <?php echo $report['station_id'] ?? ''; ?>">
                                                <div class="photo-label">Bukti Kerja</div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Station Detail -->
                                    <?php if (!empty($report['station_lokasi']) && !empty($report['station_id'])): ?>
                                    <div class="alert alert-light mt-3">
                                        <h6 class="mb-2"><i class="fas fa-map-pin me-2"></i>Lokasi Station:</h6>
                                        <small class="text-muted"><?php echo safe_html($report['station_lokasi']); ?></small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="report-footer">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted">
                                            <i class="fas fa-hashtag me-1"></i>
                                            Kode: <?php echo safe_html($report['kode_laporan'] ?? 'N/A'); ?>
                                            <?php if (!empty($report['created_at'])): ?>
                                            <br>
                                            <i class="fas fa-paper-plane me-1"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($report['created_at'])); ?>
                                            <?php endif; ?>
                                        </small>
                                        <div>
                                            <?php if ($has_any_photo): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#photoGalleryModal"
                                                    data-photos='<?php 
                                                        $photos_data = [
                                                            'sebelum' => $has_foto_sebelum ? $foto_sebelum_path : null,
                                                            'sesudah' => $has_foto_sesudah ? $foto_sesudah_path : null,
                                                            'bukti' => $has_foto_bukti ? $foto_bukti_path : null
                                                        ];
                                                        echo htmlspecialchars(json_encode($photos_data), ENT_QUOTES, 'UTF-8'); 
                                                    ?>'>
                                                <i class="fas fa-images me-1"></i>Foto
                                            </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-info ms-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#detailModal<?php echo $index; ?>">
                                                <i class="fas fa-info-circle me-1"></i>Detail
                                            </button>
                                            <a href="?delete=<?php echo $report['id']; ?>" 
                                               class="btn btn-sm btn-outline-danger ms-1 btn-delete-report" 
                                               onclick="return confirmDeleteReport()">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Modal Detail Laporan -->
                        <div class="modal fade" id="detailModal<?php echo $index; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header bg-primary text-white">
                                        <h5 class="modal-title">
                                            <i class="fas fa-file-alt me-2"></i>Detail Lengkap Laporan
                                            <?php if (!empty($report['station_id'])): ?>
                                                <span class="badge bg-warning text-dark ms-2">Station #<?php echo $report['station_id']; ?></span>
                                            <?php endif; ?>
                                        </h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <div class="row mb-4">
                                            <div class="col-md-6">
                                                <div class="card">
                                                    <div class="card-header bg-light">
                                                        <h6 class="mb-0"><i class="fas fa-user-tie me-2"></i>Data Pekerja</h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <table class="table table-sm table-borderless">
                                                            <tr><td width="40%"><strong>Nama</strong></td><td><?php echo safe_html($report['pekerja_nama'] ?? 'N/A'); ?></td></tr>
                                                            <tr><td><strong>Username</strong></td><td><?php echo safe_html($report['pekerja_username'] ?? 'N/A'); ?></td></tr>
                                                            <tr><td><strong>Jabatan</strong></td><td><?php echo safe_html($report['pekerja_jabatan'] ?? 'N/A'); ?></td></tr>
                                                            <?php if (!empty($report['pekerja_telepon'])): ?>
                                                            <tr><td><strong>Telepon</strong></td><td><?php echo safe_html($report['pekerja_telepon']); ?></td></tr>
                                                            <?php endif; ?>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div class="card">
                                                    <div class="card-header bg-light">
                                                        <h6 class="mb-0"><i class="fas fa-building me-2"></i>Data Customer</h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <table class="table table-sm table-borderless">
                                                            <tr><td width="40%"><strong>Perusahaan</strong></td><td><?php echo safe_html($report['nama_perusahaan'] ?? 'N/A'); ?></td></tr>
                                                            <tr><td><strong>Nama Customer</strong></td><td><?php echo safe_html($report['nama_customer'] ?? 'N/A'); ?></td></tr>
                                                            <tr><td><strong>Telepon</strong></td><td><?php echo safe_html($report['customer_telepon'] ?? 'N/A'); ?></td></tr>
                                                            <?php if (!empty($report['total_station_customer'])): ?>
                                                            <tr><td><strong>Total Station</strong></td><td><?php echo $report['total_station_customer']; ?> station</td></tr>
                                                            <?php endif; ?>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Station Info Section -->
                                        <?php if (!empty($report['station_id'])): ?>
                                        <div class="card mb-4 border-info">
                                            <div class="card-header bg-info text-white">
                                                <h6 class="mb-0"><i class="fas fa-map-marker-alt me-2"></i>Station Inspection</h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <table class="table table-sm table-borderless">
                                                            <tr><td width="40%"><strong>Station #</strong></td><td><?php echo $report['station_id']; ?></td></tr>
                                                            <tr><td><strong>Nama Station</strong></td><td><?php echo safe_html($report['station_nama'] ?? $report['nama_station'] ?? 'N/A'); ?></td></tr>
                                                            <tr><td><strong>Lokasi</strong></td><td><?php echo safe_html($report['station_lokasi'] ?? 'N/A'); ?></td></tr>
                                                        </table>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="text-center">
                                                            <h5>Progress Station</h5>
                                                            <div class="display-4 fw-bold"><?php echo $station_selesai; ?>/<?php echo $total_station_customer; ?></div>
                                                            <div class="progress mt-2" style="height: 20px;">
                                                                <div class="progress-bar bg-success" role="progressbar" 
                                                                     style="width: <?php echo $station_progress; ?>%">
                                                                    <?php echo $station_progress; ?>%
                                                                </div>
                                                            </div>
                                                            <?php if (!empty($report['station_terakhir'])): ?>
                                                            <small class="text-muted mt-2">Station terakhir: #<?php echo $report['station_terakhir']; ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="row mb-4">
                                            <div class="col-md-6">
                                                <div class="card">
                                                    <div class="card-header bg-light">
                                                        <h6 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Data Jadwal</h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <table class="table table-sm table-borderless">
                                                            <tr><td width="40%"><strong>Layanan</strong></td><td><?php echo safe_html($report['nama_service'] ?? 'N/A'); ?></td></tr>
                                                            <tr><td><strong>Kode Layanan</strong></td><td><?php echo safe_html($report['kode_service'] ?? 'N/A'); ?></td></tr>
                                                            <tr><td><strong>Tanggal Jadwal</strong></td><td><?php echo $jadwal_date . ' ' . $jadwal_time; ?></td></tr>
                                                            <tr><td><strong>Jenis Jadwal</strong></td><td><?php echo !empty($report['jenis_periode']) ? get_schedule_type_badge($report['jenis_periode']) : 'N/A'; ?></td></tr>
                                                            <tr><td><strong>Lokasi</strong></td><td><?php echo safe_nl2br($report['jadwal_lokasi'] ?? 'N/A'); ?></td></tr>
                                                            <tr><td><strong>Kode Jadwal</strong></td><td><?php echo safe_html($report['kode_jadwal'] ?? 'N/A'); ?></td></tr>
                                                            <?php if (!empty($report['admin_nama'])): ?>
                                                            <tr><td><strong>Admin Penjadwal</strong></td><td><?php echo safe_html($report['admin_nama']); ?></td></tr>
                                                            <?php endif; ?>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div class="card">
                                                    <div class="card-header bg-light">
                                                        <h6 class="mb-0"><i class="fas fa-file-alt me-2"></i>Data Laporan</h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <table class="table table-sm table-borderless">
                                                            <tr><td width="40%"><strong>Kode Laporan</strong></td><td><?php echo safe_html($report['kode_laporan'] ?? 'N/A'); ?></td></tr>
                                                            <tr><td><strong>Tanggal Laporan</strong></td><td><?php echo $report_date; ?></td></tr>
                                                            <tr><td><strong>Jam Mulai</strong></td><td><?php echo $jam_mulai; ?></td></tr>
                                                            <tr><td><strong>Jam Selesai</strong></td><td><?php echo $jam_selesai; ?></td></tr>
                                                            <?php if (!empty($report['nomor_kunjungan'])): ?>
                                                            <tr><td><strong>Kunjungan ke</strong></td><td>#<?php echo $report['nomor_kunjungan']; ?></td></tr>
                                                            <?php endif; ?>
                                                            <?php if ($rating > 0): ?>
                                                            <tr><td><strong>Rating</strong></td><td><div class="rating-stars"><?php echo $stars; ?></div></td></tr>
                                                            <?php endif; ?>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($report['bahan_digunakan'])): ?>
                                        <div class="card mb-4">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0"><i class="fas fa-flask me-2"></i>Bahan Digunakan</h6>
                                            </div>
                                            <div class="card-body">
                                                <?php echo safe_nl2br($report['bahan_digunakan']); ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="card mb-4">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Keterangan Pekerjaan</h6>
                                            </div>
                                            <div class="card-body">
                                                <?php echo safe_nl2br($report['keterangan']); ?>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($report['hasil_pengamatan'])): ?>
                                        <div class="card mb-4">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0"><i class="fas fa-binoculars me-2"></i>Hasil Pengamatan</h6>
                                            </div>
                                            <div class="card-body">
                                                <?php echo safe_nl2br($report['hasil_pengamatan']); ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($report['rekomendasi'])): ?>
                                        <div class="card mb-4">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0"><i class="fas fa-lightbulb me-2"></i>Rekomendasi</h6>
                                            </div>
                                            <div class="card-body">
                                                <?php echo safe_nl2br($report['rekomendasi']); ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($has_any_photo): ?>
                                        <div class="card mb-4">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0"><i class="fas fa-camera me-2"></i>Foto Dokumentasi</h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="row">
                                                    <?php if ($has_foto_sebelum): ?>
                                                    <div class="col-md-4 text-center mb-3">
                                                        <h6>Sebelum</h6>
                                                        <img src="<?php echo $foto_sebelum_path; ?>" alt="Foto Sebelum" class="img-fluid rounded mb-2" style="max-height: 150px;">
                                                        <br>
                                                        <a href="<?php echo $foto_sebelum_path; ?>" class="btn btn-sm btn-outline-primary" download="foto_sebelum_<?php echo $report['id']; ?>_station<?php echo $report['station_id'] ?? ''; ?>.jpg">
                                                            <i class="fas fa-download me-1"></i>Download
                                                        </a>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($has_foto_sesudah): ?>
                                                    <div class="col-md-4 text-center mb-3">
                                                        <h6>Sesudah</h6>
                                                        <img src="<?php echo $foto_sesudah_path; ?>" alt="Foto Sesudah" class="img-fluid rounded mb-2" style="max-height: 150px;">
                                                        <br>
                                                        <a href="<?php echo $foto_sesudah_path; ?>" class="btn btn-sm btn-outline-primary" download="foto_sesudah_<?php echo $report['id']; ?>_station<?php echo $report['station_id'] ?? ''; ?>.jpg">
                                                            <i class="fas fa-download me-1"></i>Download
                                                        </a>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($has_foto_bukti): ?>
                                                    <div class="col-md-4 text-center mb-3">
                                                        <h6>Bukti Kerja</h6>
                                                        <img src="<?php echo $foto_bukti_path; ?>" alt="Foto Bukti" class="img-fluid rounded mb-2" style="max-height: 150px;">
                                                        <br>
                                                        <a href="<?php echo $foto_bukti_path; ?>" class="btn btn-sm btn-outline-primary" download="foto_bukti_<?php echo $report['id']; ?>_station<?php echo $report['station_id'] ?? ''; ?>.jpg">
                                                            <i class="fas fa-download me-1"></i>Download
                                                        </a>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                        <button type="button" class="btn btn-primary" onclick="printModalContent(<?php echo $index; ?>)">
                                            <i class="fas fa-print me-2"></i>Print
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Pagination atau info jumlah data -->
            <?php if ($total_reports > 0): ?>
            <div class="row mt-4">
                <div class="col">
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        Menampilkan <?php echo $total_reports; ?> laporan 
                        <?php if ($total_with_station > 0): ?>
                        (<?php echo $total_with_station; ?> dengan Station Inspection)
                        <?php endif; ?>
                        <?php if (!empty($filter_date_from) || !empty($filter_date_to) || !empty($filter_pekerja_id) || !empty($search)): ?>
                        berdasarkan filter yang dipilih
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Modal Foto Single -->
<div class="modal fade" id="photoModal" tabindex="-1" aria-labelledby="photoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="photoModalLabel">Foto Dokumentasi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <img id="modalPhoto" src="" alt="Foto" class="img-fluid rounded modal-photo" style="max-width: 100%;">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                <a id="downloadPhoto" href="#" class="btn btn-primary" download>
                    <i class="fas fa-download me-2"></i>Download
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal Foto Gallery -->
<div class="modal fade" id="photoGalleryModal" tabindex="-1" aria-labelledby="photoGalleryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="photoGalleryModalLabel">Semua Foto Dokumentasi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row" id="galleryContainer">
                    <!-- Photos will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script>
function confirmDeleteReport() {
    return confirm('Hapus laporan ini? Semua foto terkait juga akan dihapus.\n\nTindakan ini tidak dapat dibatalkan.\n\nLanjutkan?');
}

function printModalContent(index) {
    // Store original body content and classes
    const originalContent = document.body.innerHTML;
    const originalClasses = document.body.className;
    
    // Get modal content
    const modalElement = document.getElementById('detailModal' + index);
    if (!modalElement) return;
    
    const modalContent = modalElement.querySelector('.modal-content').innerHTML;
    
    // Create printable content
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Laporan Pest Control - Station Inspection</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; font-size: 12px; }
                .print-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
                .print-header h1 { margin: 0; color: #333; font-size: 18px; }
                .print-header .subtitle { color: #666; font-size: 12px; }
                .section { margin-bottom: 15px; page-break-inside: avoid; }
                .section-title { background: #f5f5f5; padding: 6px; border-left: 4px solid #007bff; margin-bottom: 8px; font-size: 14px; }
                .table-responsive { overflow-x: auto; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 11px; }
                th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
                th { background-color: #f2f2f2; }
                .station-box { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin-bottom: 15px; border-radius: 5px; }
                .station-progress { margin-top: 5px; }
                .progress { height: 10px; }
                .photos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-top: 10px; }
                .photo-item { text-align: center; }
                .photo-item img { max-width: 100%; height: auto; }
                .print-footer { margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; text-align: center; font-size: 10px; color: #666; }
                @media print {
                    .no-print { display: none; }
                    .page-break { page-break-before: always; }
                    @page { margin: 1cm; }
                }
            </style>
        </head>
        <body>
            <div class="print-header">
                <h1>LAPORAN PEKERJAAN PEST CONTROL - STATION INSPECTION</h1>
                <div class="subtitle">Dicetak pada: ${new Date().toLocaleDateString('id-ID')} ${new Date().toLocaleTimeString('id-ID')}</div>
            </div>
            
            ${modalContent.replace(/<button[^>]*>.*?<\/button>/g, '')}
            
            <div class="print-footer">
                Dokumen ini dicetak dari sistem Pest Control Management<br>
                © <?php echo date('Y'); ?> PT. Pest Control Indonesia
            </div>
        </body>
        </html>
    `;
    
    // Open print window
    const printWindow = window.open('', '_blank', 'width=800,height=600');
    printWindow.document.write(printContent);
    printWindow.document.close();
    
    // Wait for images to load before printing
    printWindow.onload = function() {
        setTimeout(() => {
            printWindow.print();
            printWindow.onafterprint = function() {
                printWindow.close();
            };
        }, 500);
    };
}

document.addEventListener("DOMContentLoaded", function() {
    // Modal Foto Single
    const photoModal = document.getElementById('photoModal');
    if (photoModal) {
        photoModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const photoSrc = button.getAttribute('data-photo-src');
            const photoTitle = button.getAttribute('data-photo-title') || 'Foto Dokumentasi';
            const modalPhoto = document.getElementById('modalPhoto');
            const modalTitle = document.getElementById('photoModalLabel');
            const downloadLink = document.getElementById('downloadPhoto');
            
            modalPhoto.src = photoSrc;
            modalPhoto.alt = photoTitle;
            modalTitle.textContent = photoTitle;
            downloadLink.href = photoSrc;
            downloadLink.download = photoTitle.toLowerCase().replace(/ /g, '_') + '.jpg';
        });
    }
    
    // Modal Foto Gallery
    const galleryModal = document.getElementById('photoGalleryModal');
    if (galleryModal) {
        galleryModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const photosData = JSON.parse(button.getAttribute('data-photos'));
            const galleryContainer = document.getElementById('galleryContainer');
            
            galleryContainer.innerHTML = '';
            
            // Add photos to gallery
            const photoTypes = [
                {key: 'sebelum', title: 'Foto Sebelum'},
                {key: 'sesudah', title: 'Foto Sesudah'},
                {key: 'bukti', title: 'Foto Bukti'}
            ];
            
            photoTypes.forEach(type => {
                if (photosData[type.key]) {
                    const col = document.createElement('div');
                    col.className = 'col-md-4 mb-3';
                    col.innerHTML = `
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">${type.title}</h6>
                            </div>
                            <div class="card-body text-center">
                                <img src="${photosData[type.key]}" 
                                     alt="${type.title}" 
                                     class="img-fluid rounded mb-2"
                                     style="max-height: 200px; cursor: pointer"
                                     onclick="viewSinglePhoto('${photosData[type.key]}', '${type.title}')">
                                <div>
                                    <a href="${photosData[type.key]}" 
                                       class="btn btn-sm btn-outline-primary"
                                       download="${type.title.toLowerCase().replace(/ /g, '_')}.jpg">
                                        <i class="fas fa-download me-1"></i>Download
                                    </a>
                                </div>
                            </div>
                        </div>
                    `;
                    galleryContainer.appendChild(col);
                }
            });
        });
    }
    
    // Validasi tanggal filter
    const dateFrom = document.getElementById('filter_date_from');
    const dateTo = document.getElementById('filter_date_to');
    
    if (dateFrom && dateTo) {
        dateFrom.addEventListener('change', function() {
            if (dateTo.value && this.value > dateTo.value) {
                alert('Tanggal "Dari" tidak boleh lebih besar dari tanggal "Sampai"');
                this.value = '';
            }
        });
        
        dateTo.addEventListener('change', function() {
            if (dateFrom.value && this.value < dateFrom.value) {
                alert('Tanggal "Sampai" tidak boleh lebih kecil dari tanggal "Dari"');
                this.value = '';
            }
        });
    }
    
    // Add tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Function to view single photo from gallery
function viewSinglePhoto(src, title) {
    const modal = new bootstrap.Modal(document.getElementById('photoModal'));
    const modalPhoto = document.getElementById('modalPhoto');
    const modalTitle = document.getElementById('photoModalLabel');
    const downloadLink = document.getElementById('downloadPhoto');
    
    modalPhoto.src = src;
    modalPhoto.alt = title;
    modalTitle.textContent = title;
    downloadLink.href = src;
    downloadLink.download = title.toLowerCase().replace(/ /g, '_') + '.jpg';
    
    modal.show();
}
</script>