<?php
session_start();
require_once '../includes/functions.php';
require_once '../config/database.php';

// Pastikan admin login
checkLogin('admin');

try {
    $pdo = getConnection();

    // =============== TOTAL LAPORAN ===============
    $totalReports = $pdo->query("SELECT COUNT(*) FROM reports")->fetchColumn();

    // =============== TOTAL PEKERJA ===============
    $totalWorkers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    // =============== TOTAL CUSTOMER ===============
    $totalCustomers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();

    // =============== TOTAL LAYANAN ===============
    $totalServices = $pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();

    // =============== TOTAL JADWAL ===============
    $totalSchedules = $pdo->query("SELECT COUNT(*) FROM jadwal")->fetchColumn();

    // =============== LAPORAN HARI INI ===============
    $todayReports = $pdo->query("
        SELECT COUNT(*) FROM reports 
        WHERE DATE(tanggal_pelaporan) = CURDATE()
    ")->fetchColumn();

    // =============== JADWAL HARI INI ===============
    $todaySchedules = $pdo->query("
        SELECT COUNT(*) FROM jadwal 
        WHERE tanggal = CURDATE() 
        AND status IN ('Menunggu', 'Berjalan')
    ")->fetchColumn();

    // =============== TOTAL PENDAPATAN ===============
    $totalRevenue = $pdo->query("
        SELECT COALESCE(SUM(s.harga), 0) as total_pendapatan
        FROM reports r
        LEFT JOIN jadwal j ON r.jadwal_id = j.id
        LEFT JOIN services s ON j.service_id = s.id
    ")->fetchColumn();

    // =============== LAPORAN TERBARU (5 TERAKHIR) ===============
    $stmt = $pdo->prepare("
        SELECT 
            r.*, 
            u.nama as pekerja_nama, 
            c.nama_customer,
            c.nama_perusahaan,
            s.nama_service,
            j.tanggal as jadwal_tanggal
        FROM reports r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN jadwal j ON r.jadwal_id = j.id
        LEFT JOIN customers c ON j.customer_id = c.id OR r.customer_id = c.id
        LEFT JOIN services s ON j.service_id = s.id
        ORDER BY r.tanggal_pelaporan DESC, r.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recentReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =============== JADWAL MENDATANG (5 TERDEKAT) ===============
    $stmtSchedules = $pdo->prepare("
        SELECT 
            j.*, 
            c.nama_customer,
            c.nama_perusahaan,
            s.nama_service, 
            s.harga,
            u.nama as pekerja_nama
        FROM jadwal j
        LEFT JOIN customers c ON j.customer_id = c.id
        LEFT JOIN services s ON j.service_id = s.id
        LEFT JOIN users u ON j.pekerja_id = u.id
        WHERE j.tanggal >= CURDATE() 
        AND j.status IN ('Menunggu', 'Berjalan')
        ORDER BY j.tanggal ASC, j.jam ASC
        LIMIT 5
    ");
    $stmtSchedules->execute();
    $upcomingSchedules = $stmtSchedules->fetchAll(PDO::FETCH_ASSOC);

    // =============== STATISTIK PER LAYANAN ===============
    $serviceStats = $pdo->query("
        SELECT 
            s.nama_service, 
            s.harga,
            COUNT(r.id) as total_laporan, 
            COALESCE(SUM(s.harga), 0) as total_pendapatan
        FROM services s
        LEFT JOIN jadwal j ON s.id = j.service_id
        LEFT JOIN reports r ON j.id = r.jadwal_id
        GROUP BY s.id
        ORDER BY total_laporan DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // =============== GRAFIK BULANAN (6 BULAN TERAKHIR) ===============
    $chartQuery = $pdo->query("
        SELECT 
            DATE_FORMAT(tanggal_pelaporan, '%b %Y') AS bulan,
            DATE_FORMAT(tanggal_pelaporan, '%Y-%m') AS bulan_order,
            COUNT(r.id) AS jumlah_laporan,
            COALESCE(SUM(s.harga), 0) as total_pendapatan
        FROM reports r
        LEFT JOIN jadwal j ON r.jadwal_id = j.id
        LEFT JOIN services s ON j.service_id = s.id
        WHERE tanggal_pelaporan >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(tanggal_pelaporan, '%Y-%m'), DATE_FORMAT(tanggal_pelaporan, '%b %Y')
        ORDER BY bulan_order
    ");
    $chartData = $chartQuery->fetchAll(PDO::FETCH_ASSOC);

    $chartLabels = [];
    $chartReportValues = [];
    $chartRevenueValues = [];

    foreach ($chartData as $row) {
        $chartLabels[] = $row['bulan'];
        $chartReportValues[] = (int)$row['jumlah_laporan'];
        $chartRevenueValues[] = (int)$row['total_pendapatan'];
    }

    // =============== STATISTIK STATUS JADWAL ===============
    $statusStats = $pdo->query("
        SELECT status, COUNT(*) as jumlah
        FROM jadwal
        GROUP BY status
        ORDER BY FIELD(status, 'Menunggu', 'Berjalan', 'Selesai', 'Dibatalkan')
    ")->fetchAll(PDO::FETCH_ASSOC);

    // =============== STATISTIK RATING PEKERJA ===============
    $workerStats = $pdo->query("
        SELECT 
            u.nama,
            COUNT(r.id) as total_laporan,
            AVG(r.rating_customer) as rata_rating,
            COALESCE(SUM(s.harga), 0) as total_pendapatan
        FROM users u
        LEFT JOIN reports r ON u.id = r.user_id
        LEFT JOIN jadwal j ON r.jadwal_id = j.id
        LEFT JOIN services s ON j.service_id = s.id
        WHERE u.status = 'Aktif'
        GROUP BY u.id
        ORDER BY rata_rating DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $totalReports = $totalWorkers = $totalCustomers = $totalServices = $totalSchedules = 0;
    $todayReports = $todaySchedules = $totalRevenue = 0;
    $recentReports = $upcomingSchedules = $serviceStats = $statusStats = $workerStats = [];
    $chartLabels = $chartReportValues = $chartRevenueValues = [];
    $error = "Gagal mengambil data statistik: " . $e->getMessage();
    error_log("Dashboard admin error: " . $e->getMessage());
}

$pageTitle = 'Dashboard Admin';

require_once 'includes/header.php';
?>

<style>
    .stat-card {
        background-color: #fff;
        border: 1px solid #e9ecef;
        border-left-width: 5px;
        border-radius: 12px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        margin-bottom: 15px;
        height: 100%;
    }
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }
    .border-left-primary { border-left-color: #0d6efd !important; }
    .border-left-success { border-left-color: #198754 !important; }
    .border-left-info { border-left-color: #0dcaf0 !important; }
    .border-left-warning { border-left-color: #ffc107 !important; }
    .border-left-purple { border-left-color: #6f42c1 !important; }
    .border-left-pink { border-left-color: #d63384 !important; }
    .border-left-orange { border-left-color: #fd7e14 !important; }
    
    .stat-card-icon {
        font-size: 2.5rem;
        opacity: 0.7;
    }
    
    .stat-value {
        font-size: 1.8rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
    }
    
    .main-content-card {
        border-radius: 16px;
        border: 1px solid #e9ecef;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        background-color: #fff;
        margin-bottom: 20px;
        height: 100%;
    }
    
    .activity-item {
        display: flex;
        align-items: flex-start;
        padding: 15px;
        border-bottom: 1px solid #f0f0f0;
        transition: background-color 0.2s ease;
    }
    .activity-item:hover {
        background-color: #f8f9fa;
    }
    .activity-item:last-child {
        border-bottom: none;
    }
    
    .activity-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: #e9ecef;
        color: #6c757d;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        margin-right: 12px;
        flex-shrink: 0;
    }
    
    .service-badge {
        background-color: #e7f1ff;
        color: #0d6efd;
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 0.85rem;
        font-weight: 500;
    }
    
    .status-badge {
        font-size: 0.75rem;
        padding: 3px 8px;
        border-radius: 50px;
        font-weight: 600;
    }
    .status-menunggu { background-color: #fff3cd; color: #856404; }
    .status-berjalan { background-color: #d1ecf1; color: #0c5460; }
    .status-selesai { background-color: #d4edda; color: #155724; }
    .status-dibatalkan { background-color: #f8d7da; color: #721c24; }
    
    .rating-stars {
        color: #ffc107;
        font-size: 0.9rem;
    }
    
    .empty-state {
        text-align: center;
        padding: 40px 20px;
    }
    
    .empty-state i {
        font-size: 3rem;
        color: #dee2e6;
        margin-bottom: 15px;
    }
</style>

<?php require_once 'includes/navbar.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php require_once 'includes/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            
            <div class="pt-3 pb-2 mb-4 border-bottom">
                <h1 class="h2 mb-2">Selamat datang, <?= htmlspecialchars($_SESSION['admin_nama'] ?? 'Administrator'); ?>!</h1>
                <p class="text-muted">Dashboard Sistem Pest Control - <?= date('d F Y'); ?></p>
            </div>

            <!-- Statistik Utama -->
            <div class="row mb-4">
                <!-- TOTAL LAPORAN -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card stat-card border-left-primary h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <p class="text-muted mb-1">Total Laporan</p>
                                    <div class="stat-value text-primary"><?= number_format($totalReports); ?></div>
                                    <small class="text-success">
                                        <i class="fas fa-arrow-up me-1"></i>
                                        <?= $todayReports; ?> hari ini
                                    </small>
                                </div>
                                <i class="fas fa-file-alt stat-card-icon text-primary"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TOTAL PEKERJA -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card stat-card border-left-success h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <p class="text-muted mb-1">Total Pekerja</p>
                                    <div class="stat-value text-success"><?= number_format($totalWorkers); ?></div>
                                    <small class="text-muted">Status Aktif</small>
                                </div>
                                <i class="fas fa-user-tie stat-card-icon text-success"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TOTAL CUSTOMER -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card stat-card border-left-info h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <p class="text-muted mb-1">Total Customer</p>
                                    <div class="stat-value text-info"><?= number_format($totalCustomers); ?></div>
                                    <small class="text-muted">Kontrak Aktif</small>
                                </div>
                                <i class="fas fa-users stat-card-icon text-info"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TOTAL LAYANAN -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card stat-card border-left-warning h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <p class="text-muted mb-1">Total Layanan</p>
                                    <div class="stat-value text-warning"><?= number_format($totalReports); ?></div>
                                    <small class="text-muted">Sudah di kerjakan</small>
                                </div>
                                <i class="fas fa-concierge-bell stat-card-icon text-warning"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TOTAL PENDAPATAN -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card stat-card border-left-purple h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <p class="text-muted mb-1">Total Pendapatan</p>
                                    <div class="stat-value text-purple">Rp <?= number_format($totalRevenue, 0, ',', '.'); ?></div>
                                    <small class="text-muted">Keseluruhan</small>
                                </div>
                                <i class="fas fa-money-bill-wave stat-card-icon text-purple"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- JADWAL HARI INI -->
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card stat-card border-left-orange h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <p class="text-muted mb-1">Jadwal Hari Ini</p>
                                    <div class="stat-value text-orange"><?= number_format($todaySchedules); ?></div>
                                    <small class="text-warning">
                                        <i class="fas fa-clock me-1"></i>
                                        Menunggu/Berjalan
                                    </small>
                                </div>
                                <i class="fas fa-calendar-day stat-card-icon text-orange"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Chart & Aktivitas -->
            <div class="row">
                <!-- Grafik Laporan & Pendapatan -->
                <div class="col-lg-8 mb-4">
                    <div class="card main-content-card h-100">
                        <div class="card-header bg-light fw-normal d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-chart-line me-2"></i>Statistik Bulanan
                            </div>
                            <small class="text-muted">6 bulan terakhir</small>
                        </div>
                        <div class="card-body">
                            <div style="position: relative; height: 300px;">
                                <canvas id="reportsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Jadwal Mendatang -->
                <div class="col-lg-4 mb-4">
                    <div class="card main-content-card h-100">
                        <div class="card-header bg-light fw-normal d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-calendar-alt me-2"></i>Jadwal Mendatang
                            </div>
                            <a href="schedule.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
                        </div>
                        <div class="card-body p-0" style="max-height: 360px; overflow-y: auto;">
                            <?php if (empty($upcomingSchedules)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar-times"></i>
                                    <p class="text-muted mb-0">Tidak ada jadwal mendatang</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($upcomingSchedules as $schedule): 
                                    $statusClass = 'status-' . strtolower($schedule['status']);
                                    $customer_name = !empty($schedule['nama_perusahaan']) 
                                        ? $schedule['nama_perusahaan'] 
                                        : $schedule['nama_customer'];
                                ?>
                                    <div class="activity-item">
                                        <div class="activity-avatar bg-primary text-white">
                                            <i class="fas fa-calendar-check"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <h6 class="mb-1" style="font-size: 0.95rem;"><?= htmlspecialchars($customer_name); ?></h6>
                                                <span class="status-badge <?= $statusClass; ?>">
                                                    <?= $schedule['status']; ?>
                                                </span>
                                            </div>
                                            <p class="mb-1 small">
                                                <span class="service-badge"><?= htmlspecialchars($schedule['nama_service']); ?></span>
                                            </p>
                                            <p class="mb-1 text-muted small">
                                                <i class="far fa-calendar me-1"></i>
                                                <?= formatTanggalIndonesia($schedule['tanggal']); ?>
                                            </p>
                                            <p class="mb-1 text-muted small">
                                                <i class="far fa-clock me-1"></i>
                                                <?= date('H:i', strtotime($schedule['jam'])); ?>
                                                <?php if ($schedule['pekerja_nama']): ?>
                                                    <span class="ms-2">
                                                        <i class="fas fa-user-tie me-1"></i>
                                                        <?= htmlspecialchars($schedule['pekerja_nama']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </p>
                                            <?php if ($schedule['harga']): ?>
                                                <p class="mb-0 small text-success">
                                                    <i class="fas fa-money-bill-wave me-1"></i>
                                                    Rp <?= number_format($schedule['harga'], 0, ',', '.'); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Baris Kedua: Laporan Terbaru & Statistik -->
            <div class="row">
                <!-- Laporan Terbaru -->
                <div class="col-lg-6 mb-4">
                    <div class="card main-content-card h-100">
                        <div class="card-header bg-light fw-normal d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-history me-2"></i>Laporan Terbaru
                            </div>
                            <a href="reports.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
                        </div>
                        <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                            <?php if (empty($recentReports)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-clipboard-check"></i>
                                    <p class="text-muted mb-0">Belum ada laporan</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recentReports as $report): 
                                    $customer_name = !empty($report['nama_perusahaan']) 
                                        ? $report['nama_perusahaan'] 
                                        : $report['nama_customer'];
                                    
                                    $jam_info = '';
                                    if (!empty($report['jam_mulai'])) {
                                        $jam_info = date('H:i', strtotime($report['jam_mulai']));
                                        if (!empty($report['jam_selesai'])) {
                                            $jam_info .= ' - ' . date('H:i', strtotime($report['jam_selesai']));
                                        }
                                    }
                                ?>
                                    <div class="activity-item">
                                        <div class="activity-avatar bg-success text-white">
                                            <span><?= strtoupper(substr($report['pekerja_nama'] ?? 'P', 0, 1)); ?></span>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <h6 class="mb-1" style="font-size: 0.95rem;"><?= htmlspecialchars($customer_name ?? 'Customer'); ?></h6>
                                                <small class="text-muted">
                                                    <?= date('d/m/Y', strtotime($report['tanggal_pelaporan'])); ?>
                                                </small>
                                            </div>
                                            <p class="mb-1 small">
                                                <span class="service-badge"><?= htmlspecialchars($report['nama_service'] ?? 'Layanan'); ?></span>
                                            </p>
                                            <p class="mb-1 small text-muted">
                                                <i class="fas fa-user-tie me-1"></i>
                                                <?= htmlspecialchars($report['pekerja_nama'] ?? 'Pekerja'); ?>
                                                <?php if ($jam_info): ?>
                                                    <span class="ms-2">
                                                        <i class="far fa-clock me-1"></i><?= $jam_info; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </p>
                                            <p class="mb-0 small text-truncate text-muted">
                                                <?= htmlspecialchars(substr($report['keterangan'] ?? '', 0, 100)); ?>
                                                <?= strlen($report['keterangan'] ?? '') > 100 ? '...' : ''; ?>
                                            </p>
                                            <!-- <?php if ($report['rating_customer']): ?>
                                                <div class="rating-stars mt-1">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <?php if ($i <= $report['rating_customer']): ?>
                                                            <i class="fas fa-star"></i>
                                                        <?php else: ?>
                                                            <i class="far fa-star"></i>
                                                        <?php endif; ?>
                                                    <?php endfor; ?>
                                                </div>
                                            <?php endif; ?> -->
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Statistik Layanan & Rating Pekerja -->
                <div class="col-lg-6 mb-4">
                    <div class="row h-100">
                        <!-- Statistik Layanan -->
                        <div class="col-md-6 mb-4">
                            <div class="card main-content-card h-100">
                                <div class="card-header bg-light fw-normal">
                                    <i class="fas fa-chart-pie me-2"></i>Top Layanan
                                </div>
                                <div class="card-body p-0" style="max-height: 200px; overflow-y: auto;">
                                    <?php if (empty($serviceStats)): ?>
                                        <div class="empty-state">
                                            <i class="fas fa-chart-pie"></i>
                                            <p class="text-muted mb-0">Tidak ada data</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($serviceStats as $stat): ?>
                                            <div class="activity-item">
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <h6 class="mb-0" style="font-size: 0.9rem;"><?= htmlspecialchars($stat['nama_service']); ?></h6>
                                                        <span class="badge bg-primary"><?= $stat['total_laporan']; ?></span>
                                                    </div>
                                                    <p class="mb-0 small text-success">
                                                        <i class="fas fa-money-bill-wave me-1"></i>
                                                        Rp <?= number_format($stat['total_pendapatan'], 0, ',', '.'); ?>
                                                    </p>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Rating Pekerja -->
                        <!-- <div class="col-md-6 mb-4">
                            <div class="card main-content-card h-100">
                                <div class="card-header bg-light fw-normal">
                                    <i class="fas fa-star me-2"></i>Top Pekerja
                                </div>
                                <div class="card-body p-0" style="max-height: 200px; overflow-y: auto;">
                                    <?php if (empty($workerStats)): ?>
                                        <div class="empty-state">
                                            <i class="fas fa-users"></i>
                                            <p class="text-muted mb-0">Tidak ada data</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($workerStats as $worker): ?>
                                            <div class="activity-item">
                                                <div class="activity-avatar bg-warning text-white">
                                                    <span><?= strtoupper(substr($worker['nama'], 0, 1)); ?></span>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1" style="font-size: 0.9rem;"><?= htmlspecialchars($worker['nama']); ?></h6>
                                                    <div class="rating-stars mb-1">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <?php if ($i <= round($worker['rata_rating'] ?? 0)): ?>
                                                                <i class="fas fa-star"></i>
                                                            <?php else: ?>
                                                                <i class="far fa-star"></i>
                                                            <?php endif; ?>
                                                        <?php endfor; ?>
                                                        <small class="ms-1 text-muted">(<?= number_format($worker['rata_rating'] ?? 0, 1); ?>)</small>
                                                    </div>
                                                    <p class="mb-0 small text-muted">
                                                        <?= $worker['total_laporan']; ?> laporan
                                                    </p>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div> -->

                        <!-- Statistik Status Jadwal -->
                        <div class="col-md-12">
                            <div class="card main-content-card">
                                <div class="card-header bg-light fw-normal">
                                    <i class="fas fa-chart-bar me-2"></i>Status Jadwal
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php if (empty($statusStats)): ?>
                                            <div class="col-12">
                                                <div class="empty-state py-3">
                                                    <p class="text-muted mb-0">Tidak ada data status</p>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($statusStats as $stat): 
                                                $statusClass = 'status-' . strtolower($stat['status']);
                                            ?>
                                                <div class="col-md-3 col-sm-6 mb-3">
                                                    <div class="text-center">
                                                        <div class="status-badge <?= $statusClass; ?> mb-2 d-inline-block">
                                                            <?= $stat['status']; ?>
                                                        </div>
                                                        <div class="h4 fw-bold"><?= $stat['jumlah']; ?></div>
                                                        <small class="text-muted">Jadwal</small>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Data dari PHP
    const chartLabels = <?= json_encode($chartLabels); ?>;
    const chartReportValues = <?= json_encode($chartReportValues); ?>;
    const chartRevenueValues = <?= json_encode($chartRevenueValues); ?>;

    // Format currency function
    function formatCurrency(value) {
        return 'Rp ' + value.toLocaleString('id-ID');
    }

    // Grafik Laporan & Pendapatan
    const ctx = document.getElementById('reportsChart');
    if (ctx && chartLabels.length > 0) {
        const reportsChart = new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: chartLabels,
                datasets: [
                    {
                        label: 'Jumlah Laporan',
                        data: chartReportValues,
                        backgroundColor: 'rgba(13, 110, 253, 0.7)',
                        borderColor: 'rgba(13, 110, 253, 1)',
                        borderWidth: 1,
                        borderRadius: 6,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Pendapatan',
                        data: chartRevenueValues,
                        type: 'line',
                        borderColor: 'rgba(220, 53, 69, 1)',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        borderWidth: 3,
                        tension: 0.3,
                        fill: true,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label === 'Pendapatan') {
                                    return label + ': ' + formatCurrency(context.parsed.y);
                                }
                                return label + ': ' + context.parsed.y + ' laporan';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Jumlah Laporan',
                            font: {
                                weight: 'bold'
                            }
                        },
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Pendapatan (Rp)',
                            font: {
                                weight: 'bold'
                            }
                        },
                        beginAtZero: true,
                        grid: {
                            drawOnChartArea: false,
                        },
                        ticks: {
                            callback: function(value) {
                                if (value >= 1000000) {
                                    return 'Rp ' + (value / 1000000).toFixed(1) + ' jt';
                                } else if (value >= 1000) {
                                    return 'Rp ' + (value / 1000).toFixed(0) + ' rb';
                                }
                                return 'Rp ' + value;
                            }
                        }
                    }
                }
            }
        });
    } else if (ctx) {
        // Jika tidak ada data, tampilkan pesan
        ctx.parentElement.innerHTML = `
            <div class="empty-state" style="height: 300px;">
                <i class="fas fa-chart-line fa-3x"></i>
                <p class="text-muted mt-3">Belum ada data statistik</p>
            </div>
        `;
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>