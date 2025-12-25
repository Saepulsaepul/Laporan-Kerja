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
        r.id as report_id,
        r.nomor_kunjungan,
        r.kode_laporan,
        r.keterangan,
        r.bahan_digunakan,
        r.hasil_pengamatan,
        r.rekomendasi,
        r.foto_bukti,
        r.foto_sebelum,
        r.foto_sesudah,
        r.tanggal_pelaporan,
        r.jam_mulai,
        r.jam_selesai,
        r.rating_customer,
        r.created_at,
        c.nama_customer,
        c.nama_perusahaan,
        c.telepon as customer_telepon,
        c.alamat as customer_alamat,
        s.nama_service,
        s.kode_service,
        j.tanggal as jadwal_tanggal,
        j.jam as jadwal_jam,
        j.lokasi as jadwal_lokasi,
        j.prioritas as jadwal_prioritas,
        j.jenis_periode as jadwal_periode,
        j.jumlah_kunjungan as jadwal_total_kunjungan,
        j.kunjungan_berjalan as jadwal_kunjungan_berjalan,
        j.catatan_admin as jadwal_catatan
    FROM reports r
    INNER JOIN customers c ON r.customer_id = c.id
    INNER JOIN services s ON r.service_id = s.id
    LEFT JOIN jadwal j ON r.jadwal_id = j.id
    WHERE r.user_id = ?
";

$params = [$user_id];
$conditions = [];

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
    // HITUNG TOTAL DATA - PERBAIKAN DI SINI
    $count_sql = "SELECT COUNT(*) FROM reports r 
                  INNER JOIN customers c ON r.customer_id = c.id 
                  INNER JOIN services s ON r.service_id = s.id 
                  WHERE r.user_id = :user_id";
    
    $count_conditions = [];
    $count_params = [':user_id' => $user_id];
    
    // Tambahkan kondisi yang sama
    if ($filter_date_from) {
        $count_conditions[] = "r.tanggal_pelaporan >= :date_from";
        $count_params[':date_from'] = $filter_date_from;
    }
    
    if ($filter_date_to) {
        $count_conditions[] = "r.tanggal_pelaporan <= :date_to";
        $count_params[':date_to'] = $filter_date_to;
    }
    
    if ($search_query) {
        $count_conditions[] = "(c.nama_customer LIKE :search OR c.nama_perusahaan LIKE :search2 OR r.kode_laporan LIKE :search3 OR s.nama_service LIKE :search4)";
        $count_params[':search'] = "%" . $search_query . "%";
        $count_params[':search2'] = "%" . $search_query . "%";
        $count_params[':search3'] = "%" . $search_query . "%";
        $count_params[':search4'] = "%" . $search_query . "%";
    }
    
    if (!empty($count_conditions)) {
        $count_sql .= " AND " . implode(" AND ", $count_conditions);
    }
    
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($count_params);
    $total_records = $stmt_count->fetchColumn();
    
    // AMBIL DATA DENGAN PAGINATION
    $sql .= " LIMIT " . $per_page . " OFFSET " . $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Hitung total halaman
    $total_pages = ceil($total_records / $per_page);
    
} catch (PDOException $e) {
    $error = "Gagal mengambil data laporan: " . $e->getMessage();
    error_log("Error my_reports: " . $e->getMessage() . "\nSQL: " . $sql);
}



// Fungsi untuk badge priority
function getPriorityBadge($priority) {
    $priority = strtolower($priority);
    $badges = [
        'rendah' => 'bg-secondary',
        'sedang' => 'bg-info',
        'tinggi' => 'bg-warning',
        'darurat' => 'bg-danger'
    ];
    
    return $badges[$priority] ?? 'bg-secondary';
}

// Fungsi untuk badge period
function getPeriodBadge($period) {
    $period = strtolower($period);
    $badges = [
        'sekali' => 'bg-secondary',
        'harian' => 'bg-primary',
        'mingguan' => 'bg-success',
        'bulanan' => 'bg-info',
        'tahunan' => 'bg-warning'
    ];
    
    return $badges[$period] ?? 'bg-secondary';
}

// Hitung statistik untuk dashboard
try {
    // Total laporan
    $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ?");
    $stmt_total->execute([$user_id]);
    $total_count = $stmt_total->fetchColumn();
    
    // Laporan bulan ini
    $stmt_month = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ? AND MONTH(tanggal_pelaporan) = MONTH(CURDATE()) AND YEAR(tanggal_pelaporan) = YEAR(CURDATE())");
    $stmt_month->execute([$user_id]);
    $monthly_count = $stmt_month->fetchColumn();
    
    // Laporan hari ini
    $stmt_today = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ? AND DATE(tanggal_pelaporan) = CURDATE()");
    $stmt_today->execute([$user_id]);
    $today_count = $stmt_today->fetchColumn();
    
    // Rating rata-rata
    $stmt_rating = $pdo->prepare("SELECT AVG(rating_customer) FROM reports WHERE user_id = ? AND rating_customer > 0");
    $stmt_rating->execute([$user_id]);
    $avg_rating = $stmt_rating->fetchColumn();
    $avg_rating = $avg_rating ? round($avg_rating, 1) : 0;
    
} catch (PDOException $e) {
    $total_count = $monthly_count = $today_count = $avg_rating = 0;
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
        .badge-kunjungan {
            background: #6f42c1;
            color: white;
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 10px;
        }
        
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
        
        .modal-header {
            border-bottom: 2px solid var(--primary-color);
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        .modal-section {
            padding: 15px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .modal-section:last-child {
            border-bottom: none;
        }
        
        .modal-label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .modal-value {
            color: #495057;
            line-height: 1.6;
        }
        
        .modal-photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        
        .modal-photo-item {
            text-align: center;
        }
        
        .modal-photo {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        
        .modal-photo:hover {
            transform: scale(1.05);
        }
        
        .modal-photo-caption {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
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
            
            .modal-photo-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 576px) {
            .modal-photo-grid {
                grid-template-columns: 1fr;
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

        <!-- Stats Overview -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stats-value"><?php echo $total_count; ?></div>
                    <div class="stats-label">Total Laporan</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                    <div class="stats-value"><?php echo $monthly_count; ?></div>
                    <div class="stats-label">Bulan Ini</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stats-value"><?php echo $today_count; ?></div>
                    <div class="stats-label">Hari Ini</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-card">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Dari Tanggal</label>
                    <input type="date" name="date_from" class="form-control" 
                           value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label fw-bold">Sampai Tanggal</label>
                    <input type="date" name="date_to" class="form-control" 
                           value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label fw-bold">Cari</label>
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" 
                               placeholder="Cari customer/layanan/kode..." 
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
                        <h4 class="mb-3"><?php echo ($search_query || $filter_date_from || $filter_date_to) ? 'Tidak Ada Laporan yang Cocok' : 'Belum Ada Laporan'; ?></h4>
                        <p class="mb-4">
                            <?php if ($search_query || $filter_date_from || $filter_date_to): ?>
                                Tidak ada laporan yang sesuai dengan filter yang Anda pilih.
                            <?php else: ?>
                                Anda belum membuat laporan pekerjaan. Mulai dengan membuat laporan baru.
                            <?php endif; ?>
                        </p>
                        <?php if ($search_query || $filter_date_from || $filter_date_to): ?>
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
                        $customer_name = !empty($report['nama_perusahaan']) 
                            ? $report['nama_perusahaan'] 
                            : ($report['nama_customer'] ?? 'Customer');
                        
                        $jam_info = '';
                        if (!empty($report['jam_mulai'])) {
                            $jam_info = date('H:i', strtotime($report['jam_mulai']));
                            if (!empty($report['jam_selesai'])) {
                                $jam_info .= ' - ' . date('H:i', strtotime($report['jam_selesai']));
                            }
                        }
                        
                        // Info kunjungan untuk jadwal berulang
                        $visit_info = '';
                        if (!empty($report['jadwal_periode']) && $report['jadwal_periode'] != 'Sekali') {
                            $visit_info = 'Kunjungan ' . $report['nomor_kunjungan'] . '/' . $report['jadwal_total_kunjungan'];
                        }
                    ?>
                        <div class="report-card">
                            <div class="report-header">
                                <div>
                                    <div class="report-code">
                                        <?php echo htmlspecialchars($report['kode_laporan'] ?? 'RPT-'.$report['id']); ?>
                                        <?php if (!empty($visit_info)): ?>
                                            <span class="badge-kunjungan ms-2"><?php echo $visit_info; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="report-date">
                                        <i class="far fa-calendar me-1"></i>
                                        <?php echo formatTanggalIndonesia($report['tanggal_pelaporan'] ?? ''); ?>
                                        <?php if ($jam_info): ?>
                                            <i class="far fa-clock ms-2 me-1"></i>
                                            <?php echo htmlspecialchars($jam_info); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div>
                                    <?php if (!empty($report['jadwal_prioritas'])): ?>
                                        <span class="badge <?php echo getPriorityBadge($report['jadwal_prioritas']); ?>">
                                            <?php echo ucfirst($report['jadwal_prioritas']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="report-customer">
                                <?php echo htmlspecialchars($customer_name); ?>
                                <?php if (!empty($report['customer_telepon'])): ?>
                                    <span class="text-muted small ms-2">
                                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($report['customer_telepon']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="report-service">
                                <i class="fas fa-tools me-1"></i>
                                <?php echo htmlspecialchars($report['nama_service'] ?? 'Layanan Pest Control'); ?>
                                <?php if (!empty($report['kode_service'])): ?>
                                    <span class="text-muted">(<?php echo htmlspecialchars($report['kode_service']); ?>)</span>
                                <?php endif; ?>
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
                                    <!-- Tombol untuk melihat detail dengan modal -->
                                    <button type="button" class="btn btn-sm btn-primary-custom" 
                                            data-bs-toggle="modal" data-bs-target="#detailModal"
                                            onclick="loadReportDetail(<?php echo $report['report_id']; ?>)">
                                        <i class="fas fa-eye me-1"></i>Lihat Detail
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
                    <p class="mb-0">Sistem Manajemen Laporan Pest Control</p>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <p class="mb-0">
                        <i class="fas fa-calendar me-1"></i> <?php echo date('d F Y'); ?>
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

    <!-- Detail Report Modal -->
    <div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-primary">
                        <i class="fas fa-file-alt me-2"></i>Detail Laporan
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="detailModalBody">
                    <!-- Konten akan dimuat via AJAX -->
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3">Memuat data laporan...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-custom" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Tutup
                    </button>
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
    
    // Fungsi untuk memuat detail laporan via AJAX
    function loadReportDetail(reportId) {
        const detailModalBody = document.getElementById('detailModalBody');
        
        // Tampilkan loading spinner
        detailModalBody.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-3">Memuat data laporan...</p>
            </div>
        `;
        
        // Fetch data via AJAX
        fetch(`get_report_detail.php?id=${reportId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(html => {
                detailModalBody.innerHTML = html;
            })
            .catch(error => {
                console.error('Error:', error);
                detailModalBody.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Gagal memuat detail laporan. Silakan coba lagi.
                    </div>
                `;
            });
    }
    
    // Fungsi untuk melihat foto di modal foto
    function viewPhoto(photoUrl) {
        const modal = new bootstrap.Modal(document.getElementById('photoModal'));
        const modalImg = document.getElementById('modalPhoto');
        modalImg.src = photoUrl;
        modal.show();
    }
    </script>
</body>
</html>