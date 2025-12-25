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
$filter_status = $_GET['status'] ?? '';
$filter_tanggal = $_GET['tanggal'] ?? '';
$filter_bulan = $_GET['bulan'] ?? '';
$page = $_GET['page'] ?? 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query dengan parameter binding yang benar
$where_conditions = ["j.pekerja_id = :pekerja_id"];
$params = [':pekerja_id' => $user_id];

if (!empty($filter_status)) {
    $where_conditions[] = "j.status = :status";
    $params[':status'] = $filter_status;
}

if (!empty($filter_tanggal)) {
    $where_conditions[] = "j.tanggal = :tanggal";
    $params[':tanggal'] = $filter_tanggal;
} elseif (!empty($filter_bulan)) {
    $where_conditions[] = "DATE_FORMAT(j.tanggal, '%Y-%m') = :bulan";
    $params[':bulan'] = $filter_bulan;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Query untuk data
$query = "
    SELECT 
        j.*, c.nama_customer, c.nama_perusahaan, c.telepon, c.alamat,
        c.keterangan as customer_keterangan,
        s.nama_service, s.deskripsi as deskripsi_service, s.harga, s.durasi_menit
    FROM jadwal j
    LEFT JOIN customers c ON j.customer_id = c.id
    LEFT JOIN services s ON j.service_id = s.id
    $where_clause
    ORDER BY 
        CASE j.status
            WHEN 'Berjalan' THEN 1
            WHEN 'Menunggu' THEN 2
            WHEN 'Selesai' THEN 3
            WHEN 'Dibatalkan' THEN 4
            ELSE 5
        END,
        j.tanggal DESC, j.jam DESC
    LIMIT :limit OFFSET :offset
";

// Query untuk total data (untuk pagination)
$count_query = "
    SELECT COUNT(*) as total 
    FROM jadwal j
    $where_clause
";

$total_data = 0;
$total_pages = 1;
$schedules = [];
$error = null;

try {
    // Hitung total data
    $count_stmt = $pdo->prepare($count_query);
    
    // Bind parameters untuk count query
    foreach ($params as $key => $value) {
        if ($key != ':limit' && $key != ':offset') {
            $count_stmt->bindValue($key, $value);
        }
    }
    
    $count_stmt->execute();
    $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
    $total_data = $count_result['total'] ?? 0;
    
    // Hitung total halaman
    $total_pages = ceil($total_data / $limit);
    
    // Jika halaman melebihi total halaman, reset ke halaman terakhir
    if ($page > $total_pages && $total_pages > 0) {
        $page = $total_pages;
        $offset = ($page - 1) * $limit;
    }
    
    // Ambil data jadwal
    if ($total_data > 0) {
        $stmt = $pdo->prepare($query);
        
        // Bind semua parameter termasuk limit dan offset
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        
        $stmt->execute();
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Ambil statistik
    $stats_query = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Menunggu' THEN 1 ELSE 0 END) as menunggu,
            SUM(CASE WHEN status = 'Berjalan' THEN 1 ELSE 0 END) as berjalan,
            SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) as selesai,
            SUM(CASE WHEN status = 'Dibatalkan' THEN 1 ELSE 0 END) as dibatalkan,
            SUM(CASE WHEN tanggal = CURDATE() THEN 1 ELSE 0 END) as hari_ini
        FROM jadwal 
        WHERE pekerja_id = :pekerja_id
    ";
    
    $stats_params = [':pekerja_id' => $user_id];
    
    if (!empty($filter_status)) {
        $stats_query .= " AND status = :status";
        $stats_params[':status'] = $filter_status;
    }
    
    if (!empty($filter_tanggal)) {
        $stats_query .= " AND tanggal = :tanggal";
        $stats_params[':tanggal'] = $filter_tanggal;
    } elseif (!empty($filter_bulan)) {
        $stats_query .= " AND DATE_FORMAT(tanggal, '%Y-%m') = :bulan";
        $stats_params[':bulan'] = $filter_bulan;
    }
    
    $stats_stmt = $pdo->prepare($stats_query);
    foreach ($stats_params as $key => $value) {
        $stats_stmt->bindValue($key, $value);
    }
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Jadwal hari ini dengan filter yang sama
    $today_query = "
        SELECT COUNT(*) as total_hari_ini
        FROM jadwal 
        WHERE pekerja_id = :pekerja_id 
        AND tanggal = CURDATE()
        AND status IN ('Menunggu', 'Berjalan')
    ";
    
    $today_params = [':pekerja_id' => $user_id];
    
    if (!empty($filter_status)) {
        $today_query .= " AND status = :status";
        $today_params[':status'] = $filter_status;
    }
    
    $today_stmt = $pdo->prepare($today_query);
    foreach ($today_params as $key => $value) {
        $today_stmt->bindValue($key, $value);
    }
    $today_stmt->execute();
    $today_count = $today_stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Database Error in my_schedule.php: " . $e->getMessage());
    $schedules = [];
    $stats = ['total' => 0, 'menunggu' => 0, 'berjalan' => 0, 'selesai' => 0, 'dibatalkan' => 0];
    $today_count = 0;
    $error = "Gagal mengambil data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Saya - Pest Control System</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Datepicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        :root {
            --primary-color: #198754;
            --secondary-color: #20c997;
            --accent-color: #0d6efd;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #0dcaf0;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --menunggu-color: #ffc107;
            --berjalan-color: #0dcaf0;
            --selesai-color: #198754;
            --dibatalkan-color: #dc3545;
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
        
        /* Stat Cards */
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #e9ecef;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon {
            font-size: 2rem;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }
        
        .stat-icon.total { background: rgba(13, 110, 253, 0.1); color: var(--accent-color); }
        .stat-icon.hari-ini { background: rgba(111, 66, 193, 0.1); color: #6f42c1; }
        .stat-icon.menunggu { background: rgba(var(--menunggu-rgb), 0.1); color: var(--menunggu-color); }
        .stat-icon.berjalan { background: rgba(var(--berjalan-rgb), 0.1); color: var(--berjalan-color); }
        .stat-icon.selesai { background: rgba(var(--selesai-rgb), 0.1); color: var(--selesai-color); }
        .stat-icon.dibatalkan { background: rgba(var(--dibatalkan-rgb), 0.1); color: var(--dibatalkan-color); }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
            line-height: 1;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #e9ecef;
        }
        
        /* Schedule Card */
        .schedule-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .schedule-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
        }
        
        .schedule-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .schedule-card.menunggu::before { background-color: var(--menunggu-color); }
        .schedule-card.berjalan::before { background-color: var(--berjalan-color); }
        .schedule-card.selesai::before { background-color: var(--selesai-color); }
        .schedule-card.dibatalkan::before { background-color: var(--dibatalkan-color); }
        
        .schedule-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-menunggu { background: var(--menunggu-color); color: white; }
        .status-berjalan { background: var(--berjalan-color); color: white; }
        .status-selesai { background: var(--selesai-color); color: white; }
        .status-dibatalkan { background: var(--dibatalkan-color); color: white; }
        
        .service-badge {
            background: rgba(13, 110, 253, 0.1);
            color: var(--accent-color);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
        }
        
        .time-badge {
            background: #f8f9fa;
            color: #6c757d;
            padding: 8px 15px;
            border-radius: 10px;
            font-size: 0.9rem;
            border: 1px solid #e9ecef;
        }
        
        .action-btn {
            padding: 6px 15px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .action-btn i {
            margin-right: 5px;
        }
        
        .btn-report {
            background: var(--primary-color);
            color: white;
            border: none;
        }
        
        .btn-report:hover {
            background: #157347;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(25, 135, 84, 0.3);
        }
        
        .btn-report:disabled,
        .btn-report.disabled {
            background: #6c757d;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .btn-view-report {
            background: var(--info-color);
            color: white;
            border: none;
        }
        
        .btn-view-report:hover {
            background: #0aa2c0;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(13, 202, 240, 0.3);
        }
        
        .btn-detail {
            background: transparent;
            color: var(--accent-color);
            border: 1px solid var(--accent-color);
        }
        
        .btn-detail:hover {
            background: var(--accent-color);
            color: white;
        }
        
        .btn-cancel {
            background: var(--danger-color);
            color: white;
            border: none;
        }
        
        .btn-cancel:hover {
            background: #bb2d3b;
            color: white;
            transform: translateY(-2px);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 20px;
        }
        
        /* Pagination */
        .pagination-custom .page-item .page-link {
            border-radius: 8px;
            margin: 0 5px;
            border: 1px solid #dee2e6;
            color: var(--primary-color);
        }
        
        .pagination-custom .page-item.active .page-link {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        /* Progress Bar */
        .progress-container {
            margin: 15px 0;
        }
        
        .progress {
            height: 10px;
            border-radius: 5px;
            background-color: #e9ecef;
        }
        
        .progress-bar {
            border-radius: 5px;
            transition: width 0.6s ease;
        }
        
        .progress-info {
            display: flex;
            justify-content: space-between;
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
        
        /* Responsive */
        @media (max-width: 768px) {
            .schedule-header {
                flex-direction: column;
            }
            
            .schedule-actions {
                margin-top: 15px;
                width: 100%;
            }
            
            .action-btn {
                width: 100%;
                margin-bottom: 10px;
            }
        }
        
        /* Debug Info */
        .debug-info {
            position: fixed;
            bottom: 10px;
            right: 10px;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 10px;
            border-radius: 5px;
            font-size: 12px;
            z-index: 9999;
            max-width: 300px;
            max-height: 200px;
            overflow: auto;
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
                        <li><a class="dropdown-item" href="my_reports.php"><i class="fas fa-file-alt me-2"></i>Laporan Saya</a></li>
                        <li><a class="dropdown-item active" href="my_schedule.php"><i class="fas fa-calendar-alt me-2"></i>Jadwal Saya</a></li>
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
                        <i class="fas fa-calendar-alt me-2"></i>Jadwal Saya
                    </h1>
                    <p class="lead mb-0">Kelola dan pantau jadwal pekerjaan pest control Anda</p>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <div class="alert alert-info mb-0 d-inline-flex align-items-center">
                        <i class="fas fa-calendar-day me-2 fa-lg"></i>
                        <div>
                            <div class="fw-bold"><?php echo $today_count; ?> Jadwal</div>
                            <small>Hari Ini</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-2 col-sm-4 col-6">
                <div class="stat-card">
                    <div class="stat-icon total">
                        <i class="fas fa-calendar"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="stat-label">Total Jadwal</div>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 col-6">
                <div class="stat-card">
                    <div class="stat-icon hari-ini">
                        <i class="fas fa-sun"></i>
                    </div>
                    <div class="stat-value"><?php echo $today_count; ?></div>
                    <div class="stat-label">Hari Ini</div>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 col-6">
                <div class="stat-card">
                    <div class="stat-icon menunggu">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['menunggu'] ?? 0; ?></div>
                    <div class="stat-label">Menunggu</div>
                </div>
            </div>
            <!--  -->
            <!-- <div class="col-md-2 col-sm-4 col-6">
                <div class="stat-card">
                    <div class="stat-icon selesai">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['selesai'] ?? 0; ?></div>
                    <div class="stat-label">Selesai</div>
                </div>
            </div>
        </div> -->

        <!-- Filter -->
        <div class="filter-card">
            <h5 class="fw-bold mb-3"><i class="fas fa-filter me-2"></i>Filter Jadwal</h5>
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">Semua Status</option>
                        <option value="Menunggu" <?php echo $filter_status == 'Menunggu' ? 'selected' : ''; ?>>Menunggu</option>
                        <option value="Berjalan" <?php echo $filter_status == 'Berjalan' ? 'selected' : ''; ?>>Berjalan</option>
                        <option value="Selesai" <?php echo $filter_status == 'Selesai' ? 'selected' : ''; ?>>Selesai</option>
                        <option value="Dibatalkan" <?php echo $filter_status == 'Dibatalkan' ? 'selected' : ''; ?>>Dibatalkan</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tanggal</label>
                    <input type="date" name="tanggal" class="form-control" 
                           value="<?php echo htmlspecialchars($filter_tanggal); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Bulan</label>
                    <input type="month" name="bulan" class="form-control" 
                           value="<?php echo htmlspecialchars($filter_bulan); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="fas fa-search me-2"></i>Filter
                        </button>
                        <a href="my_schedule.php" class="btn btn-outline-secondary">
                            <i class="fas fa-redo me-2"></i>Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Schedule List -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0">
                        <i class="fas fa-list me-2"></i>Daftar Jadwal 
                        <span class="badge bg-primary ms-2"><?php echo $total_data; ?> Jadwal</span>
                    </h5>
                    <div class="d-flex gap-2">
                        <a href="create_report.php" class="btn btn-success">
                            <i class="fas fa-plus-circle me-2"></i>Buat Laporan
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($schedules)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h4 class="text-muted mb-3">Tidak Ada Jadwal</h4>
                        <p class="text-muted mb-4">Belum ada jadwal pekerjaan yang ditugaskan kepada Anda.</p>
                        <a href="dashboard.php" class="btn btn-primary">
                            <i class="fas fa-arrow-left me-2"></i>Kembali ke Dashboard
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($schedules as $schedule): 
                        $status = $schedule['status'];
                        $status_class = 'status-' . strtolower($status);
                        $schedule_class = strtolower($status);
                        
                        $customer_name = !empty($schedule['nama_pelanggan']) 
                            ? $schedule['nama_pelanggan'] 
                            : (!empty($schedule['nama_perusahaan']) 
                                ? $schedule['nama_perusahaan'] 
                                : 'Pelanggan');
                        
                        // Cek apakah sudah ada laporan untuk jadwal ini
                        $report_exists = false;
                        try {
                            $report_stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE jadwal_id = ?");
                            $report_stmt->execute([$schedule['id']]);
                            $report_exists = $report_stmt->fetchColumn() > 0;
                        } catch (Exception $e) {
                            $report_exists = false;
                        }
                    ?>
                        <div class="schedule-card <?php echo $schedule_class; ?>">
                            <div class="schedule-header">
                                <div>
                                    <h6 class="fw-bold mb-1">
                                        <i class="fas fa-user me-1"></i>
                                        <?php echo htmlspecialchars($customer_name); ?>
                                    </h6>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                        <span class="service-badge">
                                            <i class="fas fa-tools me-1"></i>
                                            <?php echo htmlspecialchars($schedule['nama_service'] ?? 'Layanan'); ?>
                                        </span>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <i class="fas fa-circle me-1" style="font-size: 8px;"></i>
                                            <?php echo $status; ?>
                                        </span>
                                        <?php if (!empty($schedule['kode_jadwal'])): ?>
                                            <span class="badge bg-light text-dark">
                                                <i class="fas fa-hashtag me-1"></i>
                                                <?php echo htmlspecialchars($schedule['kode_jadwal']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($schedule['prioritas'])): ?>
                                            <span class="badge bg-<?php echo $schedule['prioritas'] == 'Darurat' ? 'danger' : 'warning'; ?>">
                                                <i class="fas fa-exclamation-triangle me-1"></i>
                                                <?php echo htmlspecialchars($schedule['prioritas']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($schedule['jenis_periode'] !== 'Sekali'): ?>
                                            <span class="badge bg-info">
                                                <i class="fas fa-redo me-1"></i>
                                                <?php echo htmlspecialchars($schedule['jenis_periode']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="schedule-actions">
                                    <?php if ($status == 'Selesai'): ?>
                                        <?php if ($report_exists): ?>
                                            <a href="my_reports.php?jadwal_id=<?php echo $schedule['id']; ?>" 
                                               class="action-btn btn-view-report">
                                                <i class="fas fa-eye me-1"></i>Lihat Laporan
                                            </a>
                                        <?php else: ?>
                                            <button class="action-btn btn-success disabled">
                                                <i class="fas fa-check me-1"></i>Selesai
                                            </button>
                                        <?php endif; ?>
                                    <?php elseif ($status == 'Dibatalkan'): ?>
                                        <button class="action-btn btn-danger disabled">
                                            <i class="fas fa-ban me-1"></i>Dibatalkan
                                        </button>
                                    <?php elseif ($status == 'Menunggu' || $status == 'Berjalan'): ?>
                                        <a href="create_report.php?jadwal_id=<?php echo $schedule['id']; ?>" 
                                           class="action-btn btn-report">
                                            <i class="fas fa-file-alt me-1"></i>Buat Laporan
                                        </a>
                                    <?php endif; ?>
                                    
                                    <button class="action-btn btn-detail" 
                                            onclick="showScheduleDetail(<?php echo htmlspecialchars(json_encode($schedule)); ?>)">
                                        <i class="fas fa-eye me-1"></i>Detail
                                    </button>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="mb-2">
                                        <small class="text-muted">
                                            <i class="fas fa-phone me-1"></i>
                                            <?php echo !empty($schedule['telepon']) ? htmlspecialchars($schedule['telepon']) : '-'; ?>
                                        </small>
                                    </div>
                                    <div class="mb-2">
                                        <small class="text-muted">
                                            <i class="fas fa-map-marker-alt me-1"></i>
                                            <?php echo !empty($schedule['alamat']) ? htmlspecialchars($schedule['alamat']) : '-'; ?>
                                        </small>
                                    </div>
                                    <?php if (!empty($schedule['lokasi'])): ?>
                                        <div class="mb-2">
                                            <small class="text-muted">
                                                <i class="fas fa-info-circle me-1"></i>
                                                Lokasi: <?php echo htmlspecialchars($schedule['lokasi']); ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($schedule['catatan_admin'])): ?>
                                        <div class="mb-2">
                                            <small class="text-muted">
                                                <i class="fas fa-sticky-note me-1"></i>
                                                Catatan Admin: <?php echo htmlspecialchars(substr($schedule['catatan_admin'], 0, 100)); ?>
                                                <?php echo strlen($schedule['catatan_admin']) > 100 ? '...' : ''; ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($schedule['customer_keterangan'])): ?>
                                        <div class="mb-2">
                                            <small class="text-muted">
                                                <i class="fas fa-info-circle me-1"></i>
                                                Keterangan: <?php echo htmlspecialchars(substr($schedule['customer_keterangan'], 0, 80)); ?>
                                                <?php echo strlen($schedule['customer_keterangan']) > 80 ? '...' : ''; ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Progress untuk jadwal berulang -->
                                    <?php if ($schedule['jenis_periode'] !== 'Sekali'): ?>
                                        <div class="progress-container">
                                            <div class="progress">
                                                <div class="progress-bar bg-<?php echo $schedule_class; ?>" 
                                                     style="width: <?php echo min(100, ($schedule['kunjungan_berjalan'] / $schedule['jumlah_kunjungan']) * 100); ?>%">
                                                </div>
                                            </div>
                                            <div class="progress-info">
                                                <span>Kunjungan <?php echo $schedule['kunjungan_berjalan']; ?> dari <?php echo $schedule['jumlah_kunjungan']; ?></span>
                                                <span><?php echo round(($schedule['kunjungan_berjalan'] / $schedule['jumlah_kunjungan']) * 100); ?>%</span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <div class="bg-light p-3 rounded">
                                        <div class="fw-bold text-primary text-center mb-2">
                                            <i class="far fa-calendar me-1"></i>
                                            <?php echo formatTanggalIndonesia($schedule['tanggal']); ?>
                                        </div>
                                        <div class="text-center mb-2">
                                            <span class="time-badge">
                                                <i class="far fa-clock me-1"></i>
                                                <?php echo !empty($schedule['jam']) ? date('H:i', strtotime($schedule['jam'])) : '-'; ?>
                                            </span>
                                        </div>
                                        <div class="text-center">
                                            <small class="text-muted">
                                                <i class="fas fa-hourglass-half me-1"></i>
                                                Estimasi: <?php echo !empty($schedule['durasi_estimasi']) ? $schedule['durasi_estimasi'] : ($schedule['durasi_menit'] ?? '0'); ?> menit
                                            </small>
                                        </div>
                                        <?php if (!empty($schedule['harga'])): ?>
                                            <div class="text-center mt-2">
                                                <small class="fw-bold text-success">
                                                    <i class="fas fa-money-bill-wave me-1"></i>
                                                    Rp <?php echo number_format($schedule['harga'], 0, ',', '.'); ?>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($schedule['created_at'])): ?>
                                            <div class="text-center mt-2">
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar-plus me-1"></i>
                                                    Dibuat: <?php echo date('d/m/Y', strtotime($schedule['created_at'])); ?>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation" class="mt-4">
                            <ul class="pagination pagination-custom justify-content-center">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <?php
                                    $prev_params = $_GET;
                                    $prev_params['page'] = $page - 1;
                                    ?>
                                    <a class="page-link" href="?<?php echo http_build_query($prev_params); ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php 
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $start_page + 4);
                                if ($end_page - $start_page < 4) {
                                    $start_page = max(1, $end_page - 4);
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++): 
                                    $page_params = $_GET;
                                    $page_params['page'] = $i;
                                ?>
                                    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query($page_params); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <?php
                                    $next_params = $_GET;
                                    $next_params['page'] = $page + 1;
                                    ?>
                                    <a class="page-link" href="?<?php echo http_build_query($next_params); ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
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

    <!-- Modal for Schedule Details -->
    <div class="modal fade" id="scheduleDetailModal" tabindex="-1" aria-labelledby="scheduleDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="scheduleDetailModalLabel">Detail Jadwal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="scheduleDetailContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Flatpickr -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize date pickers
        flatpickr("input[type='date']", {
            dateFormat: "Y-m-d",
            locale: "id"
        });
        
        flatpickr("input[type='month']", {
            dateFormat: "Y-m",
            locale: "id"
        });
        
        // Auto submit form when selecting month
        const bulanInput = document.querySelector("input[name='bulan']");
        const tanggalInput = document.querySelector("input[name='tanggal']");
        
        if (bulanInput) {
            bulanInput.addEventListener('change', function() {
                if (this.value) {
                    if (tanggalInput) tanggalInput.value = '';
                    this.form.submit();
                }
            });
        }
        
        if (tanggalInput) {
            tanggalInput.addEventListener('change', function() {
                if (this.value) {
                    if (bulanInput) bulanInput.value = '';
                    this.form.submit();
                }
            });
        }
    });
    
    function showScheduleDetail(schedule) {
        const statusColors = {
            'Menunggu': '#ffc107',
            'Berjalan': '#0dcaf0', 
            'Selesai': '#198754',
            'Dibatalkan': '#dc3545'
        };
        
        const statusClass = {
            'Menunggu': 'warning',
            'Berjalan': 'info',
            'Selesai': 'success',
            'Dibatalkan': 'danger'
        };
        
        const content = `
            <div class="row">
                <div class="col-md-6">
                    <h6 class="fw-bold mb-2">Informasi Customer</h6>
                    <table class="table table-sm">
                        <tr>
                            <td width="40%"><small>Nama</small></td>
                            <td><strong>${schedule.nama_customer || schedule.nama_perusahaan || 'Pelanggan'}</strong></td>
                        </tr>
                        <tr>
                            <td><small>Perusahaan</small></td>
                            <td>${schedule.nama_perusahaan || '-'}</td>
                        </tr>
                        <tr>
                            <td><small>Telepon</small></td>
                            <td>${schedule.telepon || '-'}</td>
                        </tr>
                        <tr>
                            <td><small>Alamat</small></td>
                            <td>${schedule.alamat || '-'}</td>
                        </tr>
                        ${schedule.customer_keterangan ? `
                        <tr>
                            <td><small>Keterangan</small></td>
                            <td>${schedule.customer_keterangan}</td>
                        </tr>` : ''}
                    </table>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-bold mb-2">Informasi Jadwal</h6>
                    <table class="table table-sm">
                        <tr>
                            <td width="40%"><small>Status</small></td>
                            <td>
                                <span class="badge bg-${statusClass[schedule.status] || 'secondary'}">
                                    ${schedule.status}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><small>Tanggal & Jam</small></td>
                            <td>
                                ${new Date(schedule.tanggal).toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
                                <br><small>${schedule.jam ? schedule.jam.substring(0,5) : '-'}</small>
                            </td>
                        </tr>
                        <tr>
                            <td><small>Lokasi Detail</small></td>
                            <td>${schedule.lokasi || '-'}</td>
                        </tr>
                        <tr>
                            <td><small>Prioritas</small></td>
                            <td>${schedule.prioritas || 'Sedang'}</td>
                        </tr>
                        <tr>
                            <td><small>Estimasi Durasi</small></td>
                            <td>${schedule.durasi_estimasi || schedule.durasi_menit || 0} menit</td>
                        </tr>
                        ${schedule.jenis_periode !== 'Sekali' ? `
                        <tr>
                            <td><small>Jenis Periode</small></td>
                            <td>${schedule.jenis_periode} (${schedule.kunjungan_berjalan}/${schedule.jumlah_kunjungan} kunjungan)</td>
                        </tr>` : ''}
                    </table>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-12">
                    <h6 class="fw-bold mb-2">Layanan</h6>
                    <div class="card">
                        <div class="card-body">
                            <h6>${schedule.nama_service || 'Layanan'}</h6>
                            <p class="mb-2">${schedule.deskripsi_service || 'Tidak ada deskripsi'}</p>
                            ${schedule.harga ? `<p class="mb-0"><strong>Harga:</strong> Rp ${Number(schedule.harga).toLocaleString('id-ID')}</p>` : ''}
                        </div>
                    </div>
                </div>
            </div>
            ${schedule.catatan_admin ? `
            <div class="row mt-3">
                <div class="col-12">
                    <h6 class="fw-bold mb-2">Catatan Admin</h6>
                    <div class="alert alert-info">
                        ${schedule.catatan_admin}
                    </div>
                </div>
            </div>` : ''}
        `;
        
        document.getElementById('scheduleDetailContent').innerHTML = content;
        const modal = new bootstrap.Modal(document.getElementById('scheduleDetailModal'));
        modal.show();
    }
    </script>
</body>
</html>