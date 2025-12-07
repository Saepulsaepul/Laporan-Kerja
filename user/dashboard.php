<?php
session_start();
require_once '../includes/functions.php';
require_once '../config/database.php';

// Debug mode - HILANGKAN SETELAH PERBAIKAN
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cek login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pekerja') {
    header("Location: ../login.php");
    exit();
}

$pdo = getConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['nama'] ?? 'Pekerja';

// =============== STATISTIK ===============
try {
    // Debug 1: Cek apakah user ada di tabel users
    $stmt = $pdo->prepare("SELECT id, nama, username FROM users WHERE id = ? AND status = 'Aktif'");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_data) {
        echo "<!-- ERROR: User tidak ditemukan atau tidak aktif -->";
        session_destroy();
        header("Location: ../login.php?error=user_not_found");
        exit();
    }
    
    // Total laporan
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_reports = $stmt->fetchColumn();

    // Laporan bulan ini
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reports 
        WHERE user_id = ? 
        AND MONTH(tanggal_pelaporan) = MONTH(CURDATE()) 
        AND YEAR(tanggal_pelaporan) = YEAR(CURDATE())
    ");
    $stmt->execute([$user_id]);
    $monthly_reports = $stmt->fetchColumn();

    // Laporan hari ini
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reports 
        WHERE user_id = ? 
        AND DATE(tanggal_pelaporan) = CURDATE()
    ");
    $stmt->execute([$user_id]);
    $today_reports = $stmt->fetchColumn();

    // Jadwal hari ini
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM jadwal 
        WHERE pekerja_id = ? 
        AND tanggal = CURDATE() 
        AND status IN ('Menunggu', 'Berjalan')
    ");
    $stmt->execute([$user_id]);
    $today_schedules = $stmt->fetchColumn();

    // Jadwal mendatang (besok)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM jadwal 
        WHERE pekerja_id = ? 
        AND tanggal = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        AND status IN ('Menunggu', 'Berjalan')
    ");
    $stmt->execute([$user_id]);
    $tomorrow_schedules = $stmt->fetchColumn();

    // Jadwal aktif
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM jadwal 
        WHERE pekerja_id = ? 
        AND status IN ('Menunggu', 'Berjalan')
        AND tanggal >= CURDATE()
    ");
    $stmt->execute([$user_id]);
    $active_schedules = $stmt->fetchColumn();

    // Jadwal mendatang (3 terdekat)
    $stmt = $pdo->prepare("
        SELECT 
            j.*, 
            c.nama_customer, 
            c.nama_perusahaan,
            c.telepon, 
            c.alamat,
            s.nama_service
        FROM jadwal j
        LEFT JOIN customers c ON j.customer_id = c.id
        LEFT JOIN services s ON j.service_id = s.id
        WHERE j.pekerja_id = ? 
        AND j.status IN ('Menunggu', 'Berjalan')
        AND j.tanggal >= CURDATE()
        ORDER BY j.tanggal ASC, j.jam ASC
        LIMIT 3
    ");
    $stmt->execute([$user_id]);
    $upcoming_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Laporan terbaru (5 terakhir)
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            c.nama_customer,
            c.nama_perusahaan,
            s.nama_service,
            j.tanggal as jadwal_tanggal
        FROM reports r
        LEFT JOIN customers c ON r.customer_id = c.id
        LEFT JOIN jadwal j ON r.jadwal_id = j.id
        LEFT JOIN services s ON j.service_id = s.id
        WHERE r.user_id = ?
        ORDER BY r.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]); 
    $recent_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ringkasan bulanan (6 bulan terakhir)
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(tanggal_pelaporan, '%b') as bulan,
            COUNT(*) as total_laporan
        FROM reports 
        WHERE user_id = ?
        AND tanggal_pelaporan >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(tanggal_pelaporan, '%Y-%m')
        ORDER BY MIN(tanggal_pelaporan)
    ");
    $stmt->execute([$user_id]);
    $monthly_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "<!-- DATABASE ERROR: " . $e->getMessage() . " -->";
    error_log("Dashboard error: " . $e->getMessage());
    $total_reports = $monthly_reports = $today_reports = $today_schedules = $tomorrow_schedules = $active_schedules = 0;
    $upcoming_schedules = $recent_reports = $monthly_summary = [];
}

// Fungsi bantu sederhana jika formatTanggalIndonesia tidak ada
if (!function_exists('formatTanggalIndonesia')) {
    function formatTanggalIndonesia($tanggal) {
        if (empty($tanggal) || $tanggal == '0000-00-00') return 'Tanggal tidak tersedia';
        
        $hari = array('Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu');
        $bulan = array('Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');
        
        $timestamp = strtotime($tanggal);
        $hari_index = date('w', $timestamp);
        $tanggal_num = date('d', $timestamp);
        $bulan_index = date('n', $timestamp) - 1;
        $tahun = date('Y', $timestamp);
        
        return $hari[$hari_index] . ', ' . $tanggal_num . ' ' . $bulan[$bulan_index] . ' ' . $tahun;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Pest Control System</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
        
        /* Welcome Card */
        .welcome-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(25, 135, 84, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .welcome-card::before {
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
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
            text-align: center;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        
        .stat-icon.reports { background: rgba(13, 110, 253, 0.1); color: var(--accent-color); }
        .stat-icon.today { background: rgba(25, 135, 84, 0.1); color: var(--primary-color); }
        .stat-icon.schedule { background: rgba(255, 193, 7, 0.1); color: #ffc107; }
        .stat-icon.active { background: rgba(220, 53, 69, 0.1); color: #dc3545; }
        
        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 5px;
            line-height: 1;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.95rem;
            font-weight: 500;
        }
        
        /* Action Buttons */
        .action-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
            height: 100%;
        }
        
        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .action-icon {
            font-size: 2.2rem;
            margin-bottom: 15px;
            color: var(--primary-color);
        }
        
        .btn-action {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 15px;
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(25, 135, 84, 0.3);
            color: white;
        }
        
        /* Schedule & Report Cards */
        .schedule-card, .report-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--accent-color);
            transition: all 0.3s ease;
        }
        
        .schedule-card:hover, .report-card:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .schedule-card {
            border-left-color: #ffc107;
        }
        
        .report-card {
            border-left-color: var(--primary-color);
        }
        
        .service-badge {
            background: rgba(13, 110, 253, 0.1);
            color: var(--accent-color);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .status-menunggu { background: #fff3cd; color: #856404; }
        .status-berjalan { background: #d1ecf1; color: #0c5460; }
        .status-selesai { background: #d4edda; color: #155724; }
        
        /* Chart Container */
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #e9ecef;
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
            .welcome-card {
                padding: 20px;
            }
            
            .stat-card, .action-card {
                padding: 20px;
            }
            
            .stat-value {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-custom">
        <div class="container">
            <a class="navbar-brand" href="#">
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
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profil Saya</a></li>
                        <li><a class="dropdown-item" href="my_reports.php"><i class="fas fa-file-alt me-2"></i>Laporan Saya</a></li>
                        <li><a class="dropdown-item" href="my_schedule.php"><i class="fas fa-calendar-alt me-2"></i>Jadwal Saya</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Keluar</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mt-4">
        <!-- Welcome Card -->
        <div class="welcome-card">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-6 fw-bold text-success mb-2">Selamat Datang, <?php echo htmlspecialchars($user_name); ?>! 👋</h1>
                    <p class="lead mb-3">Sistem Manajemen Pekerjaan Pest Control</p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-light text-dark"><i class="fas fa-calendar-day me-1"></i><?php echo date('d F Y'); ?></span>
                        <span class="badge bg-light text-dark"><i class="fas fa-clock me-1"></i><?php echo date('H:i'); ?></span>
                        <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Aktif</span>
                    </div>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <div class="alert alert-info d-inline-block mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong><?php echo $today_schedules; ?> Jadwal</strong> hari ini
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon reports">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-value text-primary"><?php echo $total_reports; ?></div>
                    <div class="stat-label">Total Laporan</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon today">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-value text-success"><?php echo $today_reports; ?></div>
                    <div class="stat-label">Laporan Hari Ini</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon schedule">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stat-value text-warning"><?php echo $active_schedules; ?></div>
                    <div class="stat-label">Jadwal Aktif</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon active">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-value text-danger"><?php echo $tomorrow_schedules; ?></div>
                    <div class="stat-label">Jadwal Besok</div>
                </div>
            </div>
        </div>

        <!-- Action Buttons & Chart -->
        <div class="row mb-4">
            <div class="col-lg-4 mb-4">
                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <h5 class="fw-bold mb-3">Buat Laporan Baru</h5>
                    <p class="text-muted">Laporkan hasil pekerjaan pest control yang telah diselesaikan.</p>
                    <a href="create_report.php" class="btn btn-action">
                        <i class="fas fa-pen me-2"></i>Buat Laporan
                    </a>
                </div>
                
                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <h5 class="fw-bold mb-3">Jadwal Saya</h5>
                    <p class="text-muted">Lihat dan kelola jadwal pekerjaan yang telah ditentukan.</p>
                    <a href="my_schedule.php" class="btn btn-outline-primary w-100">
                        <i class="fas fa-list me-2"></i>Lihat Jadwal
                    </a>
                </div>
            </div>
            
            <div class="col-lg-8 mb-4">
                <div class="chart-container">
                    <h5 class="fw-bold mb-4"><i class="fas fa-chart-line me-2 text-primary"></i>Statistik Laporan (6 Bulan)</h5>
                    <div style="height: 300px;">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upcoming Schedules -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="fw-bold"><i class="fas fa-calendar-alt me-2 text-warning"></i>Jadwal Mendatang</h4>
                    <a href="my_schedule.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
                </div>
                
                <?php if (empty($upcoming_schedules)): ?>
                    <div class="alert alert-info text-center py-4">
                        <i class="fas fa-calendar-times fa-3x mb-3 text-muted"></i>
                        <h5 class="text-muted">Tidak Ada Jadwal Mendatang</h5>
                        <p class="text-muted mb-0">Anda belum memiliki jadwal pekerjaan.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcoming_schedules as $schedule): 
                        $status_class = 'status-' . strtolower($schedule['status'] ?? 'menunggu');
                        $customer_name = !empty($schedule['nama_customer']) ? $schedule['nama_customer'] : $schedule['nama_perusahaan'];
                        $customer_name = !empty($customer_name) ? $customer_name : 'Pelanggan';
                        $service_name = !empty($schedule['nama_service']) ? $schedule['nama_service'] : 'Layanan';
                        $schedule_date = !empty($schedule['tanggal']) ? $schedule['tanggal'] : '';
                        $schedule_time = !empty($schedule['jam']) ? $schedule['jam'] : '';
                        $location = !empty($schedule['lokasi']) ? $schedule['lokasi'] : '';
                        $phone = !empty($schedule['telepon']) ? $schedule['telepon'] : '';
                        $address = !empty($schedule['alamat']) ? $schedule['alamat'] : '';
                        $kode_jadwal = !empty($schedule['kode_jadwal']) ? $schedule['kode_jadwal'] : '';
                    ?>
                        <div class="schedule-card">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($customer_name); ?></h6>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                        <span class="service-badge">
                                            <i class="fas fa-tools me-1"></i>
                                            <?php echo htmlspecialchars($service_name); ?>
                                        </span>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <i class="fas fa-circle me-1" style="font-size: 8px;"></i>
                                            <?php echo htmlspecialchars($schedule['status'] ?? 'Menunggu'); ?>
                                        </span>
                                        <?php if (!empty($schedule['prioritas'])): ?>
                                        <span class="badge bg-danger">
                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                            <?php echo htmlspecialchars($schedule['prioritas']); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted small">
                                        <?php if ($phone): ?>
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($phone); ?>
                                            <span class="mx-2">•</span>
                                        <?php endif; ?>
                                        <?php if ($address): ?>
                                            <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars(substr($address, 0, 50)); ?>
                                            <?php if (strlen($address) > 50): ?>...<?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($location): ?>
                                            <div class="mt-1">
                                                <i class="fas fa-info-circle me-1"></i>
                                                <strong>Lokasi:</strong> <?php echo htmlspecialchars($location); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-4 text-md-end mt-2 mt-md-0">
                                    <div class="bg-light p-3 rounded">
                                        <div class="fw-bold text-primary">
                                            <i class="far fa-calendar me-1"></i>
                                            <?php echo !empty($schedule_date) ? formatTanggalIndonesia($schedule_date) : 'Tanggal tidak tersedia'; ?>
                                        </div>
                                        <div class="text-muted mt-2">
                                            <i class="far fa-clock me-1"></i>
                                            <?php echo !empty($schedule_time) ? date('H:i', strtotime($schedule_time)) : '-'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Reports -->
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="fw-bold"><i class="fas fa-history me-2 text-primary"></i>Laporan Terbaru</h4>
                    <a href="my_reports.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
                </div>
                
                <?php if (empty($recent_reports)): ?>
                    <div class="alert alert-info text-center py-4">
                        <i class="fas fa-clipboard-check fa-3x mb-3 text-muted"></i>
                        <h5 class="text-muted">Belum Ada Laporan</h5>
                        <p class="text-muted mb-0">Laporan yang Anda buat akan muncul di sini.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_reports as $report): 
                        $customer_name = !empty($report['nama_customer']) ? $report['nama_customer'] : $report['nama_perusahaan'];
                        $customer_name = !empty($customer_name) ? $customer_name : 'Customer';
                        $service_name = !empty($report['nama_service']) ? $report['nama_service'] : 'Layanan';
                        $kode_laporan = !empty($report['kode_laporan']) ? $report['kode_laporan'] : '';
                        $report_date = !empty($report['tanggal_pelaporan']) ? $report['tanggal_pelaporan'] : $report['created_at'];
                        $jam_mulai = !empty($report['jam_mulai']) ? date('H:i', strtotime($report['jam_mulai'])) : '';
                        $jam_selesai = !empty($report['jam_selesai']) ? date('H:i', strtotime($report['jam_selesai'])) : '';
                        $description = !empty($report['keterangan']) ? $report['keterangan'] : 'Tidak ada keterangan';
                    ?>
                        <div class="report-card">
                            <div class="row align-items-center">
                                <div class="col-md-9">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($customer_name); ?></h6>
                                        <?php if ($kode_laporan): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($kode_laporan); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                        <span class="service-badge">
                                            <i class="fas fa-tools me-1"></i>
                                            <?php echo htmlspecialchars($service_name); ?>
                                        </span>
                                        <span class="badge bg-light text-dark">
                                            <i class="far fa-calendar me-1"></i>
                                            <?php echo !empty($report_date) ? formatTanggalIndonesia($report_date) : 'Tanggal tidak tersedia'; ?>
                                        </span>
                                        <?php if ($jam_mulai): ?>
                                            <span class="badge bg-light text-dark">
                                                <i class="far fa-clock me-1"></i>
                                                <?php echo htmlspecialchars($jam_mulai); ?>
                                                <?php if ($jam_selesai): ?>
                                                    - <?php echo htmlspecialchars($jam_selesai); ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($report['rating_customer'])): ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-star me-1"></i>
                                                <?php echo htmlspecialchars($report['rating_customer']); ?>/5
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="mb-2">
                                        <?php echo htmlspecialchars(substr($description, 0, 150)); ?>
                                        <?php echo strlen($description) > 150 ? '...' : ''; ?>
                                    </p>
                                </div>
                                <div class="col-md-3 text-md-end mt-2 mt-md-0">
                                    <div class="d-flex flex-column gap-2">
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle me-1"></i> Terlapor
                                        </span>
                                        <?php if (!empty($report['created_at'])): ?>
                                            <small class="text-muted">
                                                <?php echo date('H:i', strtotime($report['created_at'])); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
                    <p class="mb-0">Jasa Pembasmi Hama Profesional</p>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <p class="mb-0">
                        <i class="fas fa-phone me-1"></i> 0812-3456-7890
                        <span class="mx-2">•</span>
                        <i class="fas fa-envelope me-1"></i> info@pestcontrol.com
                    </p>
                    <small>&copy; <?php echo date('Y'); ?> All rights reserved.</small>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Monthly Chart
        const monthlyData = <?php echo json_encode($monthly_summary); ?>;
        if (monthlyData && monthlyData.length > 0) {
            const labels = monthlyData.map(item => item.bulan);
            const values = monthlyData.map(item => parseInt(item.total_laporan));
            
            const ctx = document.getElementById('monthlyChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Jumlah Laporan',
                        data: values,
                        backgroundColor: 'rgba(25, 135, 84, 0.7)',
                        borderColor: 'rgba(25, 135, 84, 1)',
                        borderWidth: 1,
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.7)',
                            titleFont: {
                                size: 14
                            },
                            bodyFont: {
                                size: 14
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false
                            },
                            ticks: {
                                stepSize: 1
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        } else {
            // Show message if no data
            const chartContainer = document.getElementById('monthlyChart');
            if (chartContainer) {
                chartContainer.parentElement.innerHTML = 
                    '<div class="alert alert-info text-center py-4">' +
                    '<i class="fas fa-chart-bar fa-3x mb-3 text-muted"></i>' +
                    '<h5 class="text-muted">Belum Ada Data Statistik</h5>' +
                    '<p class="text-muted mb-0">Data laporan akan muncul di sini.</p>' +
                    '</div>';
            }
        }
    });
    </script>
</body>
</html>