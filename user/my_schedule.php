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

// Build query
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

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

$query = "
    SELECT 
        j.*, c.nama_customer, c.nama_perusahaan, c.telepon, c.alamat,
        c.keterangan as customer_keterangan,
        s.nama_service, s.deskripsi as deskripsi_service, s.harga, s.durasi_menit
    FROM jadwal j
    LEFT JOIN customers c ON j.customer_id = c.id
    LEFT JOIN services s ON j.service_id = s.id
    $where_clause
    ORDER BY j.tanggal DESC, j.jam DESC
    LIMIT :limit OFFSET :offset
";
$total_pages = 1;  // nilai default agar tidak undefined

try {
$stmt = $pdo->prepare($query);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

$stmt->execute();
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Debug info
    if (empty($schedules)) {
        error_log("No schedules found for user_id: $user_id");
        // Cek data jadwal
        $debug_stmt = $pdo->prepare("SELECT COUNT(*) FROM jadwal WHERE pekerja_id = ?");
        $debug_stmt->execute([$user_id]);
        $debug_count = $debug_stmt->fetchColumn();
        error_log("Total jadwal for user $user_id: $debug_count");
    }
    
    // Ambil statistik
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Menunggu' THEN 1 ELSE 0 END) as menunggu,
            SUM(CASE WHEN status = 'Berjalan' THEN 1 ELSE 0 END) as berjalan,
            SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) as selesai,
            SUM(CASE WHEN status = 'Dibatalkan' THEN 1 ELSE 0 END) as dibatalkan,
            SUM(CASE WHEN tanggal = CURDATE() THEN 1 ELSE 0 END) as hari_ini
        FROM jadwal 
        WHERE pekerja_id = ?
    ");
    $stats_stmt->execute([$user_id]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Jadwal hari ini
    $today_stmt = $pdo->prepare("
        SELECT COUNT(*) as total_hari_ini
        FROM jadwal 
        WHERE pekerja_id = ? 
        AND tanggal = CURDATE()
        AND status IN ('Menunggu', 'Berjalan')
    ");
    $today_stmt->execute([$user_id]);
    $today_count = $today_stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Database Error in my_schedule.php: " . $e->getMessage());
    $schedules = [];
    $stats = ['total' => 0, 'menunggu' => 0, 'berjalan' => 0, 'selesai' => 0, 'dibatalkan' => 0];
    $today_count = 0;
    $error = "Gagal mengambil data: " . $e->getMessage();
}

// Fungsi format tanggal
// function formatTanggalIndonesia($tanggal) {
//     if (empty($tanggal) || $tanggal == '0000-00-00') return 'Tanggal tidak tersedia';
    
//     try {
//         $hari = array(
//             'Sunday' => 'Minggu',
//             'Monday' => 'Senin',
//             'Tuesday' => 'Selasa',
//             'Wednesday' => 'Rabu',
//             'Thursday' => 'Kamis',
//             'Friday' => 'Jumat',
//             'Saturday' => 'Sabtu'
//         );
        
//         $bulan = array(
//             1 => 'Januari',
//             'Februari',
//             'Maret',
//             'April',
//             'Mei',
//             'Juni',
//             'Juli',
//             'Agustus',
//             'September',
//             'Oktober',
//             'November',
//             'Desember'
//         );
        
//         $date = new DateTime($tanggal);
//         $day_name = $date->format('l');
//         $day = $date->format('d');
//         $month = $date->format('n');
//         $year = $date->format('Y');
        
//         return $hari[$day_name] . ', ' . $day . ' ' . $bulan[$month] . ' ' . $year;
//     } catch (Exception $e) {
//         return date('d/m/Y', strtotime($tanggal));
//     }
// }
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
            margin-bottom: 15px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }
        
        .stat-icon.total { background: rgba(13, 110, 253, 0.1); color: var(--accent-color); }
        .stat-icon.menunggu { background: rgba(255, 193, 7, 0.1); color: var(--warning-color); }
        .stat-icon.berjalan { background: rgba(13, 202, 240, 0.1); color: var(--info-color); }
        .stat-icon.selesai { background: rgba(25, 135, 84, 0.1); color: var(--primary-color); }
        .stat-icon.dibatalkan { background: rgba(220, 53, 69, 0.1); color: var(--danger-color); }
        .stat-icon.hari-ini { background: rgba(111, 66, 193, 0.1); color: #6f42c1; }
        
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
            border-left: 5px solid var(--warning-color);
            transition: all 0.3s ease;
        }
        
        .schedule-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .schedule-card.menunggu { border-left-color: var(--warning-color); }
        .schedule-card.berjalan { border-left-color: var(--info-color); }
        .schedule-card.selesai { border-left-color: var(--primary-color); }
        .schedule-card.dibatalkan { border-left-color: var(--danger-color); }
        
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
        
        .status-menunggu { background: #fff3cd; color: #856404; }
        .status-berjalan { background: #d1ecf1; color: #0c5460; }
        .status-selesai { background: #d4edda; color: #155724; }
        .status-dibatalkan { background: #f8d7da; color: #721c24; }
        
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
        
        .btn-detail {
            background: transparent;
            color: var(--accent-color);
            border: 1px solid var(--accent-color);
        }
        
        .btn-detail:hover {
            background: var(--accent-color);
            color: white;
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
    <!-- Debug Info -->
    <div class="debug-info">
        <strong>Debug Info:</strong><br>
        User ID: <?php echo $user_id; ?><br>
        Total Data: <?php echo $total_data ?? 0; ?><br>
        Schedules: <?php echo count($schedules); ?>
    </div>

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
            <div class="col-md-2 col-sm-4 col-6">
                <div class="stat-card">
                    <div class="stat-icon berjalan">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['berjalan'] ?? 0; ?></div>
                    <div class="stat-label">Berjalan</div>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 col-6">
                <div class="stat-card">
                    <div class="stat-icon selesai">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['selesai'] ?? 0; ?></div>
                    <div class="stat-label">Selesai</div>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 col-6">
                <div class="stat-card">
                    <div class="stat-icon dibatalkan">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['dibatalkan'] ?? 0; ?></div>
                    <div class="stat-label">Dibatalkan</div>
                </div>
            </div>
        </div>

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
                        <span class="badge bg-primary ms-2"><?php echo $total_data ?? 0; ?> Jadwal</span>
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
                        $status_class = 'status-' . strtolower($schedule['status']);
                        $schedule_class = strtolower($schedule['status']);
                        
                        $customer_name = !empty($schedule['nama_customer']) 
                            ? $schedule['nama_customer'] 
                            : (!empty($schedule['nama_perusahaan']) 
                                ? $schedule['nama_perusahaan'] 
                                : 'Pelanggan');
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
                                            <?php echo $schedule['status']; ?>
                                        </span>
                                        <?php if (!empty($schedule['kode_jadwal'])): ?>
                                            <span class="badge bg-light text-dark">
                                                <i class="fas fa-hashtag me-1"></i>
                                                <?php echo htmlspecialchars($schedule['kode_jadwal']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($schedule['prioritas'])): ?>
                                            <span class="badge bg-danger">
                                                <i class="fas fa-exclamation-triangle me-1"></i>
                                                <?php echo htmlspecialchars($schedule['prioritas']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="schedule-actions">
                                    <?php if ($schedule['status'] == 'Selesai'): ?>
                                        <button class="action-btn btn-success" disabled>
                                            <i class="fas fa-check me-1"></i>Sudah Dilaporkan
                                        </button>
                                    <?php elseif ($schedule['status'] == 'Menunggu' || $schedule['status'] == 'Berjalan'): ?>
                                        <a href="create_report.php?jadwal_id=<?php echo $schedule['id']; ?>" 
                                           class="action-btn btn-report">
                                            <i class="fas fa-file-alt me-1"></i>Buat Laporan
                                        </a>
                                    <?php endif; ?>
                                    <a href="#" 
                                       class="action-btn btn-detail" 
                                       onclick="alert('Fitur detail akan segera tersedia')">
                                        <i class="fas fa-eye me-1"></i>Detail
                                    </a>
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
                                                Durasi: <?php echo !empty($schedule['durasi_men']) ? $schedule['durasi_men'] : '0'; ?> menit
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
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
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
    </script>
</body>
</html>