<?php
// --- FUNGSI PHP TIDAK DIUBAH ---
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
$search = $_GET['search'] ?? '';

// Ambil data pekerja untuk filter dropdown
try {
    $workers = $pdo->query("SELECT id, nama, username FROM users WHERE status = 'Aktif' ORDER BY nama ASC")->fetchAll();
} catch (PDOException $e) {
    $error = "Gagal mengambil data pekerja: " . $e->getMessage();
    $workers = [];
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

// Ambil data laporan dengan filter (sesuai struktur database yang ada)
try {
    $query = "SELECT r.*, 
              u.nama as pekerja_nama, 
              u.username as pekerja_username, 
              u.jabatan as pekerja_jabatan,
              
              -- PRIORITAS: customer dari jadwal jika customer_id di reports NULL
              COALESCE(c1.nama_perusahaan, c2.nama_perusahaan) as nama_perusahaan,
              COALESCE(c1.nama_customer, c2.nama_customer) as nama_customer,
              COALESCE(c1.telepon, c2.telepon) as customer_telepon,
              
              j.tanggal as jadwal_tanggal, 
              j.jam as jadwal_jam, 
              j.lokasi as jadwal_lokasi,
              j.customer_id as jadwal_customer_id,
              
              s.nama_service, 
              s.kode_service,
              a.nama as admin_nama
              
              FROM reports r
              
              -- JOIN ke users (wajib)
              LEFT JOIN users u ON r.user_id = u.id
              
              -- JOIN ke customers langsung dari reports (mungkin NULL)
              LEFT JOIN customers c1 ON r.customer_id = c1.id
              
              -- JOIN ke jadwal untuk dapatkan customer_id alternatif
              LEFT JOIN jadwal j ON r.jadwal_id = j.id
              
              -- JOIN ke customers melalui jadwal
              LEFT JOIN customers c2 ON j.customer_id = c2.id
              
              -- JOIN ke services melalui jadwal
              LEFT JOIN services s ON j.service_id = s.id
              
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
    
    if (!empty($search)) {
        $query .= " AND (c.nama_perusahaan LIKE ? OR c.nama_customer LIKE ? OR r.keterangan LIKE ? OR s.nama_service LIKE ?)";
        $searchTerm = "%$search%";
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
    $total_with_photos = count(array_filter($reports, function($report) {
        return !empty($report['foto_bukti']) || !empty($report['foto_sebelum']) || !empty($report['foto_sesudah']);
    }));
    
} catch (PDOException $e) {
    $error = "Gagal mengambil data laporan: " . $e->getMessage();
    error_log("Report Query Error: " . $e->getMessage());
    $reports = [];
    $total_reports = 0;
    $total_with_photos = 0;
}

$pageTitle = 'Laporan Pekerjaan';

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
        require_once '../includes/functions.php';
        return formatTanggalIndonesia($date);
    } catch (Exception $e) {
        return date('d/m/Y', strtotime($date));
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
                    <div class="col-md-4 mb-3 mb-md-0">
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
                    <div class="col-md-4 mb-3 mb-md-0">
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
                    <div class="col-md-4">
                        <div class="d-flex align-items-center">
                            <div class="bg-white rounded-circle p-3 me-3">
                                <i class="fas fa-calendar-check text-warning fa-2x"></i>
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
                    <div class="col-md-3">
                        <label for="filter_date_from" class="form-label">Dari Tanggal</label>
                        <input type="date" class="form-control" id="filter_date_from" name="date_from" 
                               value="<?php echo safe_html($filter_date_from); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="filter_date_to" class="form-label">Sampai Tanggal</label>
                        <input type="date" class="form-control" id="filter_date_to" name="date_to" 
                               value="<?php echo safe_html($filter_date_to); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="filter_pekerja_id" class="form-label">Pekerja</label>
                        <select class="form-select" id="filter_pekerja_id" name="pekerja_id">
                            <option value="">Semua Pekerja</option>
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?php echo safe_html($worker['id']); ?>" 
                                        <?php echo $filter_pekerja_id == $worker['id'] ? 'selected' : ''; ?>>
                                    <?php echo safe_html($worker['nama']); ?> (<?php echo safe_html($worker['username']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="search" class="form-label">Cari</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               placeholder="Cari perusahaan/customer/layanan..." value="<?php echo safe_html($search); ?>">
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
                        
                        $has_foto_bukti = !empty($foto_bukti_path);
                        $has_foto_sebelum = !empty($foto_sebelum_path);
                        $has_foto_sesudah = !empty($foto_sesudah_path);
                        $has_any_photo = $has_foto_bukti || $has_foto_sebelum || $has_foto_sesudah;
                        
                        // Rating stars
                        $rating = (int)($report['rating_customer'] ?? 0);
                        if ($rating > 0) {
                            $stars = str_repeat('<i class="fas fa-star"></i>', $rating) . 
                                    str_repeat('<i class="far fa-star"></i>', 5 - $rating);
                        } else {
                            $stars = '<span class="text-muted">Belum ada rating</span>';
                        }
                ?>
                        <div class="col-lg-6 mb-4">
                            <div class="report-card">
                                <div class="report-header">
                                    <div>
                                        <h5 class="mb-1 text-truncate"><?php echo safe_html($report['nama_perusahaan'] ?? 'N/A'); ?></h5>
                                        <span class="company-badge">
                                            <?php echo safe_html($report['nama_customer'] ?? 'N/A'); ?>
                                        </span>
                                        <div class="mt-1">
                                            <?php echo get_service_badge($report['nama_service'] ?? '', $report['kode_service'] ?? ''); ?>
                                        </div>
                                    </div>
                                    <span class="badge-date">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo $report_date; ?>
                                    </span>
                                </div>
                                
                                <div class="report-body">
                                    <div class="info-item">
                                        <i class="fas fa-user-tie"></i>
                                        <div>
                                            <strong>Pekerja:</strong> 
                                            <?php echo safe_html($report['pekerja_nama'] ?? 'N/A'); ?>
                                            <?php if (!empty($report['pekerja_jabatan'])): ?>
                                                <small class="text-muted">(<?php echo safe_html($report['pekerja_jabatan']); ?>)</small>
                                            <?php endif; ?>
                                            <br>
                                            <small class="text-muted">@<?php echo safe_html($report['pekerja_username'] ?? 'N/A'); ?></small>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <i class="fas fa-calendar-check"></i>
                                        <div>
                                            <strong>Jadwal:</strong> 
                                            <?php echo $jadwal_date . ' • ' . $jadwal_time; ?>
                                            <?php if (!empty($report['jadwal_lokasi'])): ?>
                                                <br><small class="text-muted"><?php echo safe_html($report['jadwal_lokasi']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <i class="fas fa-phone"></i>
                                        <div>
                                            <strong>Kontak Customer:</strong> 
                                            <?php echo safe_html($report['customer_telepon'] ?? 'N/A'); ?>
                                        </div>
                                    </div>
                                    
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
                                            <strong><?php echo safe_html($report['bahan_digunakan']); ?></strong>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($report['keterangan'])): ?>
                                    <div class="report-keterangan mt-3">
                                        <h6 class="mb-2"><i class="fas fa-clipboard-check me-2"></i>Keterangan Pekerjaan:</h6>
                                        <div class="text-truncate-2">
                                            <?php echo safe_nl2br($report['keterangan']); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($report['hasil_pengamatan'])): ?>
                                    <div class="alert alert-warning mt-3">
                                        <h6 class="mb-2"><i class="fas fa-binoculars me-2"></i>Hasil Pengamatan:</h6>
                                        <div class="text-truncate-2">
                                            <?php echo safe_nl2br($report['hasil_pengamatan']); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($report['rekomendasi'])): ?>
                                    <div class="alert alert-info mt-3">
                                        <h6 class="mb-2"><i class="fas fa-lightbulb me-2"></i>Rekomendasi:</h6>
                                        <div class="text-truncate-2">
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
                                                     data-photo-title="Foto Sebelum">
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
                                                     data-photo-title="Foto Sesudah">
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
                                                     data-photo-title="Foto Bukti">
                                                <div class="photo-label">Bukti Kerja</div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
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
                                                        echo htmlspecialchars(json_encode([
                                                            'sebelum' => $has_foto_sebelum ? $foto_sebelum_path : null,
                                                            'sesudah' => $has_foto_sesudah ? $foto_sesudah_path : null,
                                                            'bukti' => $has_foto_bukti ? $foto_bukti_path : null
                                                        ]), ENT_QUOTES, 'UTF-8'); 
                                                    ?>'>
                                                <i class="fas fa-images me-1"></i>Semua Foto
                                            </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-info ms-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#detailModal<?php echo $index; ?>">
                                                <i class="fas fa-info-circle me-1"></i>Detail
                                            </button>
                                            <a href="?delete=<?php echo $report['id']; ?>" class="btn btn-sm btn-outline-danger ms-1" 
                                               onclick="return confirm('Hapus laporan ini? Semua foto terkait juga akan dihapus. Tindakan ini tidak dapat dibatalkan.')">
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
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
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
                                                            <tr><td><strong>Lokasi</strong></td><td><?php echo safe_nl2br($report['jadwal_lokasi'] ?? 'N/A'); ?></td></tr>
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
                                                            <?php if ($rating > 0): ?>
                                                            <!-- <tr><td><strong>Rating Customer</strong></td><td>
                                                                <div class="rating-stars"><?php echo $stars; ?></div>
                                                            </td></tr> -->
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
                                                        <a href="<?php echo $foto_sebelum_path; ?>" class="btn btn-sm btn-outline-primary" download="foto_sebelum_<?php echo $report['id']; ?>.jpg">
                                                            <i class="fas fa-download me-1"></i>Download
                                                        </a>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($has_foto_sesudah): ?>
                                                    <div class="col-md-4 text-center mb-3">
                                                        <h6>Sesudah</h6>
                                                        <img src="<?php echo $foto_sesudah_path; ?>" alt="Foto Sesudah" class="img-fluid rounded mb-2" style="max-height: 150px;">
                                                        <br>
                                                        <a href="<?php echo $foto_sesudah_path; ?>" class="btn btn-sm btn-outline-primary" download="foto_sesudah_<?php echo $report['id']; ?>.jpg">
                                                            <i class="fas fa-download me-1"></i>Download
                                                        </a>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($has_foto_bukti): ?>
                                                    <div class="col-md-4 text-center mb-3">
                                                        <h6>Bukti Kerja</h6>
                                                        <img src="<?php echo $foto_bukti_path; ?>" alt="Foto Bukti" class="img-fluid rounded mb-2" style="max-height: 150px;">
                                                        <br>
                                                        <a href="<?php echo $foto_bukti_path; ?>" class="btn btn-sm btn-outline-primary" download="foto_bukti_<?php echo $report['id']; ?>.jpg">
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
                                        <button type="button" class="btn btn-primary" onclick="window.print()">
                                            <i class="fas fa-print me-2"></i>Print
                                        </button>
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

<!-- Modal Foto Single -->
<div class="modal fade" id="photoModal" tabindex="-1" aria-labelledby="photoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="photoModalLabel">Foto Dokumentasi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <img id="modalPhoto" src="" alt="Foto" class="img-fluid rounded modal-photo">
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
});

// Function to view single photo from gallery
function viewSinglePhoto(src, title) {
    const modal = new bootstrap.Modal(document.getElementById('photoModal'));
    const modalPhoto = document.getElementById('modalPhoto');
    const modalTitle = document.getElementById('photoModalLabel');
    const downloadLink = document.getElementById('downloadPhoto');
    
    modalPhoto.src = src;
    modalTitle.textContent = title;
    downloadLink.href = src;
    downloadLink.download = title.toLowerCase().replace(/ /g, '_') + '.jpg';
    
    modal.show();
}
</script>

</body>
</html>