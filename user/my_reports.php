<?php
session_start();
require_once '../includes/functions.php';
require_once '../config/database.php';

// Cek login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pekerja') {
    header("Location: ../login.php");
    exit();
}

$pdo = getConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['nama'] ?? 'Pekerja';

// Inisialisasi variabel
$error = '';
$success = '';
$reports = [];
$filter_status = '';
$filter_date_from = '';
$filter_date_to = '';

// Ambil parameter filter
$filter_status = $_GET['status'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$search_query = $_GET['search'] ?? '';

// Query dasar dengan filter
$sql = "
    SELECT 
        r.*,
        c.nama_customer,
        c.nama_perusahaan,
        c.telepon,
        s.nama_service,
        j.tanggal as jadwal_tanggal,
        j.jam as jadwal_jam,
        j.lokasi as jadwal_lokasi
    FROM reports r
    LEFT JOIN jadwal j ON r.jadwal_id = j.id
    LEFT JOIN customers c ON j.customer_id = c.id OR r.customer_id = c.id
    LEFT JOIN services s ON j.service_id = s.id
    WHERE r.user_id = ?
";

$params = [$user_id];
$conditions = [];

// Filter status
if ($filter_status && $filter_status !== 'all') {
    $conditions[] = "r.status = ?";
    $params[] = $filter_status;
}

// Filter tanggal
if ($filter_date_from) {
    $conditions[] = "r.tanggal_pelaporan >= ?";
    $params[] = $filter_date_from;
}

if ($filter_date_to) {
    $conditions[] = "r.tanggal_pelaporan <= ?";
    $params[] = $filter_date_to;
}

// Filter pencarian
if ($search_query) {
    $conditions[] = "(c.nama_customer LIKE ? OR c.nama_perusahaan LIKE ? OR r.kode_laporan LIKE ? OR s.nama_service LIKE ?)";
    $search_term = "%" . $search_query . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

// Gabungkan kondisi
if (!empty($conditions)) {
    $sql .= " AND " . implode(" AND ", $conditions);
}

// Order by
$sql .= " ORDER BY r.tanggal_pelaporan DESC, r.created_at DESC";

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

try {
    // Hitung total data (tanpa LIMIT)
    $count_sql = str_replace(
        "SELECT r.*, c.nama_customer, c.nama_perusahaan, c.telepon, s.nama_service, j.tanggal as jadwal_tanggal, j.jam as jadwal_jam, j.lokasi as jadwal_lokasi",
        "SELECT COUNT(*) as total",
        $sql
    );
    
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_records = $stmt->fetchColumn();

    // Tambahkan LIMIT untuk data
    $sql .= " LIMIT " . $per_page . " OFFSET " . $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Hitung total halaman
    $total_pages = ceil($total_records / $per_page);

} catch (PDOException $e) {
    $error = "Gagal mengambil data laporan: " . $e->getMessage();
    error_log("Error my_reports: " . $e->getMessage());
}

// Fungsi helper untuk format tanggal

// Fungsi untuk menampilkan rating stars
function displayRating($rating) {
    $html = '';
    $rating = (int)$rating;
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fas fa-star text-warning"></i>';
        } else {
            $html .= '<i class="far fa-star text-muted"></i>';
        }
    }
    return $html;
}

// Proses hapus laporan
if (isset($_POST['delete_report']) && isset($_POST['report_id'])) {
    $report_id = (int)$_POST['report_id'];
    
    try {
        // Cek apakah laporan milik user ini
        $stmt = $pdo->prepare("SELECT id FROM reports WHERE id = ? AND user_id = ?");
        $stmt->execute([$report_id, $user_id]);
        
        if ($stmt->rowCount() > 0) {
            // Hapus file foto terlebih dahulu
            $stmt = $pdo->prepare("SELECT foto_bukti, foto_sebelum, foto_sesudah FROM reports WHERE id = ?");
            $stmt->execute([$report_id]);
            $report_files = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Hapus file fisik
            $upload_dir = '../assets/uploads/';
            if ($report_files['foto_bukti'] && file_exists($upload_dir . $report_files['foto_bukti'])) {
                unlink($upload_dir . $report_files['foto_bukti']);
            }
            if ($report_files['foto_sebelum'] && file_exists($upload_dir . $report_files['foto_sebelum'])) {
                unlink($upload_dir . $report_files['foto_sebelum']);
            }
            if ($report_files['foto_sesudah'] && file_exists($upload_dir . $report_files['foto_sesudah'])) {
                unlink($upload_dir . $report_files['foto_sesudah']);
            }
            
            // Hapus laporan dari database
            $stmt = $pdo->prepare("DELETE FROM reports WHERE id = ?");
            $stmt->execute([$report_id]);
            
            $success = "Laporan berhasil dihapus!";
            
            // Redirect untuk refresh data
            header("Location: my_reports.php?deleted=true");
            exit();
        } else {
            $error = "Laporan tidak ditemukan atau tidak memiliki akses!";
        }
        
    } catch (PDOException $e) {
        $error = "Gagal menghapus laporan: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Saya - Pest Control System</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #198754;
            --secondary-color: #20c997;
            --accent-color: #0d6efd;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            min-height: 100vh;
            color: #333;
        }
        
        /* Navbar */
        .navbar-custom {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            box-shadow: 0 4px 20px rgba(25, 135, 84, 0.2);
            padding: 15px 0;
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            color: white !important;
            display: flex;
            align-items: center;
        }
        
        .navbar-brand i {
            margin-right: 10px;
            font-size: 1.8rem;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: white;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
            margin-right: 10px;
        }
        
        /* Header */
        .page-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(25, 135, 84, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary-color), var(--secondary-color));
        }
        
        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid #e9ecef;
        }
        
        .filter-title {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }
        
        .filter-title i {
            margin-right: 10px;
        }
        
        /* Stats Cards */
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #e9ecef;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .stats-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--primary-color);
        }
        
        .stats-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stats-label {
            color: #6c757d;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* Report Cards */
        .report-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s ease;
        }
        
        .report-card:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .report-code {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 1.1rem;
        }
        
        .report-date {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .report-customer {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .report-service {
            color: var(--accent-color);
            font-size: 0.9rem;
            margin-bottom: 10px;
        }
        
        .report-excerpt {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        .report-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }
        
        .report-photos {
            display: flex;
            gap: 10px;
        }
        
        .photo-thumbnail {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        
        .photo-thumbnail:hover {
            transform: scale(1.05);
        }
        
        .report-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        /* Badges */
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-draft { background: #fff3cd; color: #856404; }
        .badge-submitted { background: #d1ecf1; color: #0c5460; }
        .badge-reviewed { background: #d4edda; color: #155724; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        
        /* Buttons */
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(25, 135, 84, 0.3);
            color: white;
        }
        
        .btn-outline-custom {
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            background: transparent;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-custom:hover {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-sm-custom {
            padding: 5px 12px;
            font-size: 0.85rem;
            border-radius: 6px;
        }
        
        /* Pagination */
        .pagination-custom .page-link {
            color: var(--primary-color);
            border: 1px solid #dee2e6;
        }
        
        .pagination-custom .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-color: var(--primary-color);
            color: white;
        }
        
        .pagination-custom .page-link:hover {
            background-color: #e9ecef;
            border-color: #dee2e6;
        }
        
        /* Alert */
        .alert-custom {
            border-radius: 10px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        /* Modal */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        /* Footer */
        .footer {
            background: linear-gradient(135deg, var(--dark-color) 0%, #495057 100%);
            color: white;
            padding: 20px 0;
            margin-top: 50px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 20px;
        }
        
        .empty-state h4 {
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #adb5bd;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .report-footer {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .report-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-custom">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-bug"></i>
                Pest Control
            </a>
            
            <div class="d-flex align-items-center">
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" 
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                        </div>
                        <div class="d-none d-md-block">
                            <div class="fw-bold"><?php echo htmlspecialchars($user_name); ?></div>
                            <small>Pekerja Pest Control</small>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><h6 class="dropdown-header">Akun Pekerja</h6></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-home me-2"></i>Dashboard</a></li>
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profil Saya</a></li>
                        <li><a class="dropdown-item active" href="my_reports.php"><i class="fas fa-file-alt me-2"></i>Laporan Saya</a></li>
                        <li><a class="dropdown-item" href="my_schedule.php"><i class="fas fa-calendar-alt me-2"></i>Jadwal Saya</a></li>
                        <li><a class="dropdown-item" href="create_report.php"><i class="fas fa-plus-circle me-2"></i>Buat Laporan</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Keluar</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mt-4">
        <!-- Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-6 fw-bold text-success mb-2">
                        <i class="fas fa-file-alt me-2"></i>Laporan Saya
                    </h1>
                    <p class="lead mb-0">Kelola dan lihat semua laporan pekerjaan yang telah Anda buat</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="create_report.php" class="btn btn-primary-custom">
                        <i class="fas fa-plus-circle me-2"></i>Buat Laporan Baru
                    </a>
                </div>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['deleted']) && $_GET['deleted'] == 'true'): ?>
            <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                Laporan berhasil dihapus!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Stats Overview -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stats-value"><?php echo $total_records ?? 0; ?></div>
                    <div class="stats-label">Total Laporan</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <?php
                // Hitung laporan bulan ini
                try {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) FROM reports 
                        WHERE user_id = ? 
                        AND MONTH(tanggal_pelaporan) = MONTH(CURDATE()) 
                        AND YEAR(tanggal_pelaporan) = YEAR(CURDATE())
                    ");
                    $stmt->execute([$user_id]);
                    $monthly_count = $stmt->fetchColumn();
                } catch (PDOException $e) {
                    $monthly_count = 0;
                }
                ?>
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                    <div class="stats-value"><?php echo $monthly_count; ?></div>
                    <div class="stats-label">Bulan Ini</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <?php
                // Hitung laporan hari ini
                try {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) FROM reports 
                        WHERE user_id = ? 
                        AND DATE(tanggal_pelaporan) = CURDATE()
                    ");
                    $stmt->execute([$user_id]);
                    $today_count = $stmt->fetchColumn();
                } catch (PDOException $e) {
                    $today_count = 0;
                }
                ?>
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stats-value"><?php echo $today_count; ?></div>
                    <div class="stats-label">Hari Ini</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <?php
                // Hitung rata-rata rating
                try {
                    $stmt = $pdo->prepare("
                        SELECT AVG(rating_customer) FROM reports 
                        WHERE user_id = ? AND rating_customer > 0
                    ");
                    $stmt->execute([$user_id]);
                    $avg_rating = $stmt->fetchColumn();
                    $avg_rating = $avg_rating ? round($avg_rating, 1) : 0;
                } catch (PDOException $e) {
                    $avg_rating = 0;
                }
                ?>
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stats-value"><?php echo $avg_rating; ?>/5</div>
                    <div class="stats-label">Rating Rata-rata</div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-card">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="all">Semua Status</option>
                        <option value="draft" <?php echo ($filter_status == 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="submitted" <?php echo ($filter_status == 'submitted') ? 'selected' : ''; ?>>Submitted</option>
                        <option value="reviewed" <?php echo ($filter_status == 'reviewed') ? 'selected' : ''; ?>>Reviewed</option>
                        <option value="rejected" <?php echo ($filter_status == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-bold">Dari Tanggal</label>
                    <input type="date" name="date_from" class="form-control" 
                           value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-bold">Sampai Tanggal</label>
                    <input type="date" name="date_to" class="form-control" 
                           value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-bold">Cari</label>
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" 
                               placeholder="Cari customer/layanan..." 
                               value="<?php echo htmlspecialchars($search_query); ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
                
                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-primary-custom me-2">
                        <i class="fas fa-filter me-2"></i>Terapkan Filter
                    </button>
                    <a href="my_reports.php" class="btn btn-outline-custom">
                        <i class="fas fa-redo me-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Reports List -->
        <div class="row">
            <div class="col-12">
                <?php if (empty($reports)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h4 class="mb-3"><?php echo ($search_query || $filter_status || $filter_date_from || $filter_date_to) ? 'Tidak Ada Laporan yang Cocok' : 'Belum Ada Laporan'; ?></h4>
                        <p class="mb-4">
                            <?php if ($search_query || $filter_status || $filter_date_from || $filter_date_to): ?>
                                Tidak ada laporan yang sesuai dengan filter yang Anda pilih.
                            <?php else: ?>
                                Anda belum membuat laporan pekerjaan.
                            <?php endif; ?>
                        </p>
                        <?php if ($search_query || $filter_status || $filter_date_from || $filter_date_to): ?>
                            <a href="my_reports.php" class="btn btn-outline-custom me-2">
                                <i class="fas fa-redo me-2"></i>Reset Filter
                            </a>
                        <?php endif; ?>
                        <a href="create_report.php" class="btn btn-primary-custom">
                            <i class="fas fa-plus-circle me-2"></i>Buat Laporan Baru
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($reports as $report): 
                        $customer_name = !empty($report['nama_customer']) 
                            ? $report['nama_customer'] 
                            : ($report['nama_perusahaan'] ?? 'Customer');
                        
                        $jam_info = '';
                        if (!empty($report['jam_mulai'])) {
                            $jam_info = date('H:i', strtotime($report['jam_mulai']));
                            if (!empty($report['jam_selesai'])) {
                                $jam_info .= ' - ' . date('H:i', strtotime($report['jam_selesai']));
                            }
                        }
                        
                        // Status badge class
                        $status_class = 'badge-' . strtolower($report['status'] ?? 'submitted');
                    ?>
                        <div class="report-card">
                            <div class="report-header">
                                <div>
                                    <div class="report-code"><?php echo htmlspecialchars($report['kode_laporan'] ?? 'RPT-'.$report['id']); ?></div>
                                    <div class="report-date">
                                        <i class="far fa-calendar me-1"></i>
                                        <?php echo formatTanggalIndonesia($report['tanggal_pelaporan'] ?? ''); ?>
                                        <?php if ($jam_info): ?>
                                            <i class="far fa-clock ms-2 me-1"></i>
                                            <?php echo htmlspecialchars($jam_info); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo ucfirst($report['status'] ?? 'Submitted'); ?>
                                </span>
                            </div>
                            
                            <div class="report-customer">
                                <?php echo htmlspecialchars($customer_name); ?>
                                <?php if (!empty($report['telepon'])): ?>
                                    <span class="text-muted small ms-2">
                                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($report['telepon']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="report-service">
                                <i class="fas fa-tools me-1"></i>
                                <?php echo htmlspecialchars($report['nama_service'] ?? 'Layanan Pest Control'); ?>
                            </div>
                            
                            <div class="report-excerpt">
                                <?php echo htmlspecialchars(substr($report['keterangan'] ?? '', 0, 200)); ?>
                                <?php echo strlen($report['keterangan'] ?? '') > 200 ? '...' : ''; ?>
                            </div>
                            
                            <div class="report-footer">
                                <div class="report-photos">
                                    <?php if (!empty($report['foto_sebelum'])): ?>
                                        <img src="../assets/uploads/<?php echo htmlspecialchars($report['foto_sebelum']); ?>" 
                                             class="photo-thumbnail" 
                                             data-bs-toggle="modal" data-bs-target="#photoModal"
                                             data-photo="../assets/uploads/<?php echo htmlspecialchars($report['foto_sebelum']); ?>"
                                             title="Foto Sebelum" 
                                             onerror="this.style.display='none'">
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($report['foto_bukti'])): ?>
                                        <img src="../assets/uploads/<?php echo htmlspecialchars($report['foto_bukti']); ?>" 
                                             class="photo-thumbnail" 
                                             data-bs-toggle="modal" data-bs-target="#photoModal"
                                             data-photo="../assets/uploads/<?php echo htmlspecialchars($report['foto_bukti']); ?>"
                                             title="Foto Bukti"
                                             onerror="this.style.display='none'">
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($report['foto_sesudah'])): ?>
                                        <img src="../assets/uploads/<?php echo htmlspecialchars($report['foto_sesudah']); ?>" 
                                             class="photo-thumbnail" 
                                             data-bs-toggle="modal" data-bs-target="#photoModal"
                                             data-photo="../assets/uploads/<?php echo htmlspecialchars($report['foto_sesudah']); ?>"
                                             title="Foto Sesudah"
                                             onerror="this.style.display='none'">
                                    <?php endif; ?>
                                    
                                    <?php if (empty($report['foto_sebelum']) && empty($report['foto_bukti']) && empty($report['foto_sesudah'])): ?>
                                        <span class="text-muted small">
                                            <i class="fas fa-image me-1"></i>Tidak ada foto
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="report-actions">
                                    <?php if ($report['rating_customer']): ?>
                                        <div class="me-2">
                                            <?php echo displayRating($report['rating_customer']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <a href="view_report.php?id=<?php echo $report['id']; ?>" 
                                       class="btn btn-sm btn-outline-custom" title="Lihat Detail">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            data-bs-toggle="modal" data-bs-target="#deleteModal"
                                            data-report-id="<?php echo $report['id']; ?>"
                                            data-report-code="<?php echo htmlspecialchars($report['kode_laporan'] ?? 'RPT-'.$report['id']); ?>"
                                            title="Hapus Laporan">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation" class="mt-4">
                            <ul class="pagination justify-content-center pagination-custom">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php 
                                            $query = $_GET;
                                            $query['page'] = $page - 1;
                                            echo http_build_query($query);
                                        ?>">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php 
                                // Tampilkan maksimal 5 halaman di sekitar halaman aktif
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $start_page + 4);
                                $start_page = max(1, $end_page - 4);
                                
                                for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php 
                                            $query = $_GET;
                                            $query['page'] = $i;
                                            echo http_build_query($query);
                                        ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php 
                                            $query = $_GET;
                                            $query['page'] = $page + 1;
                                            echo http_build_query($query);
                                        ?>">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                    
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6 text-center text-md-start">
                    <h5 class="mb-2"><i class="fas fa-bug me-2"></i>Pest Control System</h5>
                    <p class="mb-0">PT. Rexon Mitra Prima - Jasa Pembasmi Hama Profesional</p>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <p class="mb-0">
                        <i class="fas fa-phone me-1"></i> 0812-3456-7890
                        <span class="mx-2">•</span>
                        <i class="fas fa-envelope me-1"></i> info@rexonpestcontrol.com
                    </p>
                    <small>&copy; <?php echo date('Y'); ?> All rights reserved.</small>
                </div>
            </div>
        </div>
    </footer>

    <!-- Photo Modal -->
    <div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Foto Laporan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-0">
                    <img id="modalPhoto" src="" class="img-fluid rounded" alt="Foto Laporan"
                         onerror="this.src='../assets/img/no-image.jpg'">
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Hapus
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus laporan <strong id="deleteReportCode"></strong>?</p>
                    <p class="text-danger small">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        Tindakan ini tidak dapat dibatalkan. Semua data laporan akan dihapus permanen.
                    </p>
                </div>
                <div class="modal-footer">
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="report_id" id="deleteReportId">
                        <button type="button" class="btn btn-outline-custom" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Batal
                        </button>
                        <button type="submit" name="delete_report" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i>Hapus
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Photo Modal
        const photoModal = document.getElementById('photoModal');
        if (photoModal) {
            photoModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const photoUrl = button.getAttribute('data-photo');
                const modalImg = document.getElementById('modalPhoto');
                modalImg.src = photoUrl;
            });
        }
        
        // Delete Confirmation Modal
        const deleteModal = document.getElementById('deleteModal');
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const reportId = button.getAttribute('data-report-id');
                const reportCode = button.getAttribute('data-report-code');
                
                document.getElementById('deleteReportId').value = reportId;
                document.getElementById('deleteReportCode').textContent = reportCode;
            });
        }
        
        // Filter tanggal: set date_to min value based on date_from
        const dateFrom = document.querySelector('input[name="date_from"]');
        const dateTo = document.querySelector('input[name="date_to"]');
        
        if (dateFrom && dateTo) {
            dateFrom.addEventListener('change', function() {
                dateTo.min = this.value;
            });
        }
        
        // Set max date for date_to to today
        const today = new Date().toISOString().split('T')[0];
        if (dateTo) {
            dateTo.max = today;
        }
        if (dateFrom) {
            dateFrom.max = today;
        }
    });
    </script>
</body>
</html>