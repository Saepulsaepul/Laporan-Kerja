<?php
// --- FUNGSI PHP TIDAK DIUBAH ---
require_once '../includes/functions.php';
require_once '../config/database.php';

checkLogin('admin');

try {
    $pdo = getConnection();
    
    // Ambil data untuk filter (sesuai struktur database)
    $customers = $pdo->query("SELECT id, CONCAT(nama_perusahaan, ' - ', nama_customer) as display_name, nama_perusahaan, nama_customer FROM customers WHERE status = 'Aktif' ORDER BY nama_perusahaan ASC")->fetchAll();
    
    $services = $pdo->query("SELECT id, kode_service, nama_service FROM services WHERE status = 'Aktif' ORDER BY nama_service ASC")->fetchAll();
    
    $workers = $pdo->query("SELECT id, nama, jabatan FROM users WHERE status = 'Aktif' ORDER BY nama ASC")->fetchAll();
    
    // Statistik
    $totalReports = $pdo->query("SELECT COUNT(*) FROM reports")->fetchColumn();
    $todayReports = $pdo->query("SELECT COUNT(*) FROM reports WHERE tanggal_pelaporan = CURDATE()")->fetchColumn();
    $weekReports  = $pdo->query("SELECT COUNT(*) FROM reports WHERE YEARWEEK(tanggal_pelaporan, 1) = YEARWEEK(CURDATE(), 1)")->fetchColumn();
    $monthReports = $pdo->query("SELECT COUNT(*) FROM reports WHERE MONTH(tanggal_pelaporan) = MONTH(CURDATE()) AND YEAR(tanggal_pelaporan) = YEAR(CURDATE())")->fetchColumn();
    
    // Statistik tambahan (sesuai database)
    $totalCustomers = $pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'Aktif'")->fetchColumn();
    $totalWorkers = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Aktif'")->fetchColumn();
    $totalServices = $pdo->query("SELECT COUNT(*) FROM services WHERE status = 'Aktif'")->fetchColumn();
    $totalSchedules = $pdo->query("SELECT COUNT(*) FROM jadwal WHERE status != 'Dibatalkan'")->fetchColumn();
    
    // Statistik by service
    $serviceStats = $pdo->query("
        SELECT s.nama_service, s.kode_service, COUNT(r.id) as total_reports,
               COUNT(CASE WHEN r.tanggal_pelaporan = CURDATE() THEN 1 END) as today_count,
               COUNT(CASE WHEN r.foto_bukti IS NOT NULL OR r.foto_sebelum IS NOT NULL OR r.foto_sesudah IS NOT NULL THEN 1 END) as with_photo
        FROM services s
        LEFT JOIN jadwal j ON s.id = j.service_id
        LEFT JOIN reports r ON j.id = r.jadwal_id
        WHERE s.status = 'Aktif'
        GROUP BY s.id, s.nama_service, s.kode_service
        ORDER BY total_reports DESC
    ")->fetchAll();
    
    // Top 5 customers dengan laporan terbanyak
    $topCustomers = $pdo->query("
        SELECT c.nama_perusahaan, c.nama_customer, COUNT(r.id) as report_count
        FROM customers c
        LEFT JOIN jadwal j ON c.id = j.customer_id
        LEFT JOIN reports r ON j.id = r.jadwal_id
        WHERE c.status = 'Aktif'
        GROUP BY c.id, c.nama_perusahaan, c.nama_customer
        ORDER BY report_count DESC
        LIMIT 5
    ")->fetchAll();
    
    // Top 5 workers dengan laporan terbanyak
    $topWorkers = $pdo->query("
        SELECT u.nama, u.jabatan, COUNT(r.id) as report_count
        FROM users u
        LEFT JOIN reports r ON u.id = r.user_id
        WHERE u.status = 'Aktif'
        GROUP BY u.id, u.nama, u.jabatan
        ORDER BY report_count DESC
        LIMIT 5
    ")->fetchAll();
    
} catch (PDOException $e) {
    $error = "Gagal mengambil data statistik: " . $e->getMessage();
    $customers = $services = $workers = $serviceStats = $topCustomers = $topWorkers = [];
    $totalReports = $todayReports = $weekReports = $monthReports = 0;
    $totalCustomers = $totalWorkers = $totalServices = $totalSchedules = 0;
}

$pageTitle = 'Export Laporan';

require_once 'includes/header.php';
?>

<style>
    .stat-card {
        background-color: #fff;
        border: 1px solid #e9ecef;
        border-left-width: 5px;
        border-radius: 12px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        height: 100%;
    }
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }
    .border-left-primary { border-left-color: #0d6efd !important; }
    .text-primary { color: #0d6efd !important; }

    .border-left-success { border-left-color: #198754 !important; }
    .text-success { color: #198754 !important; }

    .border-left-info { border-left-color: #0dcaf0 !important; }
    .text-info { color: #0dcaf0 !important; }
    
    .border-left-warning { border-left-color: #ffc107 !important; }
    .text-warning { color: #ffc107 !important; }
    
    .border-left-purple { border-left-color: #6f42c1 !important; }
    .text-purple { color: #6f42c1 !important; }
    
    .border-left-pink { border-left-color: #d63384 !important; }
    .text-pink { color: #d63384 !important; }
    
    .border-left-orange { border-left-color: #fd7e14 !important; }
    .text-orange { color: #fd7e14 !important; }
    
    .border-left-teal { border-left-color: #20c997 !important; }
    .text-teal { color: #20c997 !important; }
    
    .border-left-cyan { border-left-color: #17a2b8 !important; }
    .text-cyan { color: #17a2b8 !important; }
    
    .border-left-indigo { border-left-color: #6610f2 !important; }
    .text-indigo { color: #6610f2 !important; }

    .main-card {
        border-radius: 16px;
        border: 1px solid #e9ecef;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        background-color: #fff;
        height: 100%;
    }

    .quick-export-list .list-group-item {
        display: flex;
        align-items: center;
        padding: 1rem 1.25rem;
        transition: background-color 0.2s ease, color 0.2s ease;
        border-right:0; border-left:0;
    }
    .quick-export-list .list-group-item:hover {
        background-color: rgba(13, 110, 253, 0.05);
        color: var(--bs-primary);
    }
    .quick-export-list .list-group-item .icon {
        font-size: 1.25rem;
        width: 30px;
        text-align: center;
        margin-right: 1rem;
    }
    
    .stat-icon {
        font-size: 1.5rem;
        opacity: 0.8;
        margin-bottom: 0.5rem;
    }
    
    .service-stat-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1rem;
        border-bottom: 1px solid #f0f0f0;
    }
    .service-stat-item:last-child {
        border-bottom: none;
    }
    .service-name {
        font-weight: 600;
        color: #333;
    }
    .service-counts {
        display: flex;
        gap: 10px;
        font-size: 0.85rem;
    }
    .count-badge {
        padding: 0.2rem 0.5rem;
        border-radius: 50px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .count-total { background-color: #e7f5ff; color: #0c63e4; }
    .count-today { background-color: #e7fff3; color: #198754; }
    .count-photo { background-color: #fff3cd; color: #856404; }
    
    .top-customer-item, .top-worker-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1rem;
        border-bottom: 1px solid #f0f0f0;
    }
    .top-customer-item:last-child, .top-worker-item:last-child {
        border-bottom: none;
    }
    .customer-info, .worker-info {
        max-width: 70%;
    }
    .customer-company {
        font-size: 0.85rem;
        color: #666;
    }
    .worker-job {
        font-size: 0.85rem;
        color: #666;
    }
    
    .export-type-badge {
        font-size: 0.7rem;
        padding: 0.2rem 0.4rem;
        border-radius: 4px;
        margin-left: 5px;
    }
    .badge-pdf { background-color: #dc3545; color: white; }
    .badge-excel { background-color: #198754; color: white; }
</style>

<?php
require_once 'includes/navbar.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php require_once 'includes/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-file-export me-2"></i><?php echo $pageTitle; ?></h1>
                <div class="badge bg-success fs-6">
                    <i class="fas fa-chart-bar me-1"></i> Pest Control Reports
                </div>
            </div>

            <!-- Statistik Dashboard -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card stat-card border-left-primary h-100">
                        <div class="card-body">
                            <div class="stat-icon text-primary">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <h3 class="fw-bold text-primary"><?php echo $totalReports; ?></h3>
                            <p class="mb-0 text-muted">Total Laporan</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card stat-card border-left-success h-100">
                        <div class="card-body">
                            <div class="stat-icon text-success">
                                <i class="fas fa-building"></i>
                            </div>
                            <h3 class="fw-bold text-success"><?php echo $totalCustomers; ?></h3>
                            <p class="mb-0 text-muted">Customer Aktif</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card stat-card border-left-info h-100">
                        <div class="card-body">
                            <div class="stat-icon text-info">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <h3 class="fw-bold text-info"><?php echo $totalWorkers; ?></h3>
                            <p class="mb-0 text-muted">Pekerja Aktif</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card stat-card border-left-warning h-100">
                        <div class="card-body">
                            <div class="stat-icon text-warning">
                                <i class="fas fa-concierge-bell"></i>
                            </div>
                            <h3 class="fw-bold text-warning"><?php echo $totalServices; ?></h3>
                            <p class="mb-0 text-muted">Layanan Aktif</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistik Periodik -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="card stat-card border-left-purple h-100">
                        <div class="card-body">
                            <div class="stat-icon text-purple">
                                <i class="fas fa-calendar-day"></i>
                            </div>
                            <h3 class="fw-bold text-purple"><?php echo $todayReports; ?></h3>
                            <p class="mb-0 text-muted">Laporan Hari Ini</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="card stat-card border-left-pink h-100">
                        <div class="card-body">
                            <div class="stat-icon text-pink">
                                <i class="fas fa-calendar-week"></i>
                            </div>
                            <h3 class="fw-bold text-pink"><?php echo $weekReports; ?></h3>
                            <p class="mb-0 text-muted">Laporan Minggu Ini</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="card stat-card border-left-orange h-100">
                        <div class="card-body">
                            <div class="stat-icon text-orange">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <h3 class="fw-bold text-orange"><?php echo $monthReports; ?></h3>
                            <p class="mb-0 text-muted">Laporan Bulan Ini</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="card stat-card border-left-teal h-100">
                        <div class="card-body">
                            <div class="stat-icon text-teal">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <h3 class="fw-bold text-teal"><?php echo $totalSchedules; ?></h3>
                            <p class="mb-0 text-muted">Jadwal Aktif</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <!-- Kolom Kiri: Form Export -->
                <div class="col-lg-7">
                    <div class="card main-card">
                        <h5 class="card-header bg-light fw-normal">
                            <i class="fas fa-filter me-2"></i>Filter Laporan untuk Export
                        </h5>
                        <div class="card-body p-4">
                            <!-- PERBAIKAN DI SINI: Form action mengarah ke generate_pdf.php -->
                            <form method="GET" action="generate_pdf.php" target="_blank">
                                <p class="text-muted">Pilih kriteria di bawah ini untuk membuat laporan yang spesifik.</p>
                                
                                <div class="row g-3">
                                    <!-- Tanggal -->
                                    <div class="col-md-6">
                                        <label for="start_date" class="form-label">Tanggal Mulai</label>
                                        <input type="date" class="form-control" id="start_date" name="start_date">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="end_date" class="form-label">Tanggal Akhir</label>
                                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    
                                    <!-- Customer -->
                                    <div class="col-md-6">
                                        <label for="customer_id" class="form-label">Customer</label>
                                        <select class="form-select" id="customer_id" name="customer_id">
                                            <option value="">Semua Customer</option>
                                            <?php foreach ($customers as $customer): ?>
                                                <option value="<?php echo htmlspecialchars($customer['id']); ?>">
                                                    <?php echo htmlspecialchars($customer['display_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Layanan -->
                                    <div class="col-md-6">
                                        <label for="service_id" class="form-label">Layanan</label>
                                        <select class="form-select" id="service_id" name="service_id">
                                            <option value="">Semua Layanan</option>
                                            <?php foreach ($services as $service): ?>
                                                <option value="<?php echo htmlspecialchars($service['id']); ?>">
                                                    <?php echo htmlspecialchars($service['nama_service']); ?> (<?php echo htmlspecialchars($service['kode_service']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Pekerja -->
                                    <div class="col-md-6">
                                        <label for="worker_id" class="form-label">Pekerja</label>
                                        <select class="form-select" id="worker_id" name="worker_id">
                                            <option value="">Semua Pekerja</option>
                                            <?php foreach ($workers as $worker): ?>
                                                <option value="<?php echo htmlspecialchars($worker['id']); ?>">
                                                    <?php echo htmlspecialchars($worker['nama']); ?> (<?php echo htmlspecialchars($worker['jabatan']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Status Laporan -->
                                    <div class="col-md-6">
                                        <label for="report_type" class="form-label">Tipe Laporan</label>
                                        <select class="form-select" id="report_type" name="report_type">
                                            <option value="all">Semua Laporan</option>
                                            <option value="with_photo">Laporan dengan Foto</option>
                                            <option value="without_photo">Laporan tanpa Foto</option>
                                            <option value="today_only">Hari Ini Saja</option>
                                            <option value="by_schedule">Berdasarkan Jadwal</option>
                                            <option value="high_priority">Prioritas Tinggi/Darurat</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Export Format -->
                                    <div class="col-md-12">
                                        <label for="action" class="form-label">Format Export</label>
                                        <div class="d-flex gap-2">
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="action" id="format_pdf" value="pdf" checked>
                                                <label class="form-check-label" for="format_pdf">
                                                    <i class="fas fa-file-pdf text-danger me-1"></i>PDF (Tampilan Cetak)
                                                </label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="action" id="format_excel" value="excel">
                                                <label class="form-check-label" for="format_excel">
                                                    <i class="fas fa-file-excel text-success me-1"></i>Excel (Analisis Data)
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-grid gap-2 mt-4">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-file-export me-2"></i>Generate & Export Laporan
                                    </button>
                                    <small class="text-muted text-center">Laporan akan terbuka di tab baru browser</small>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Kolom Kanan: Opsi Cepat -->
                <div class="col-lg-5">
                    <!-- Opsi Cepat Export -->
                    <div class="card main-card mb-3">
                        <h5 class="card-header bg-light fw-normal">
                            <i class="fas fa-bolt me-2"></i>Opsi Cepat Export
                        </h5>
                        <div class="list-group list-group-flush quick-export-list">
                            <?php
                            // Tanggal untuk opsi cepat
                            $today = date('Y-m-d');
                            $mondayThisWeek = date('Y-m-d', strtotime('monday this week'));
                            $firstDayOfMonth = date('Y-m-01');
                            $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
                            ?>
                            
                            <!-- PERBAIKAN DI SINI: Semua link mengarah ke generate_pdf.php -->
                            <a href="generate_pdf.php?start_date=<?php echo $today; ?>&end_date=<?php echo $today; ?>&action=pdf" 
                               class="list-group-item list-group-item-action" target="_blank">
                                <span class="icon"><i class="fas fa-calendar-day text-purple"></i></span>
                                Laporan Hari Ini
                                <span class="export-type-badge badge-pdf ms-auto">PDF</span>
                            </a>
                            
                            <a href="generate_pdf.php?start_date=<?php echo $mondayThisWeek; ?>&end_date=<?php echo $today; ?>&action=pdf" 
                               class="list-group-item list-group-item-action" target="_blank">
                                <span class="icon"><i class="fas fa-calendar-week text-pink"></i></span>
                                Laporan Minggu Ini
                                <span class="export-type-badge badge-pdf ms-auto">PDF</span>
                            </a>
                            
                            <a href="generate_pdf.php?start_date=<?php echo $firstDayOfMonth; ?>&end_date=<?php echo $today; ?>&action=pdf" 
                               class="list-group-item list-group-item-action" target="_blank">
                                <span class="icon"><i class="fas fa-calendar-alt text-orange"></i></span>
                                Laporan Bulan Ini
                                <span class="export-type-badge badge-pdf ms-auto">PDF</span>
                            </a>
                            
                            <a href="generate_pdf.php?start_date=<?php echo $thirtyDaysAgo; ?>&end_date=<?php echo $today; ?>&action=pdf" 
                               class="list-group-item list-group-item-action" target="_blank">
                                <span class="icon"><i class="fas fa-history text-info"></i></span>
                                30 Hari Terakhir
                                <span class="export-type-badge badge-pdf ms-auto">PDF</span>
                            </a>
                            
                            <a href="generate_pdf.php?action=pdf&report_type=with_photo" 
                               class="list-group-item list-group-item-action" target="_blank">
                                <span class="icon"><i class="fas fa-camera text-success"></i></span>
                                Semua Laporan dengan Foto
                                <span class="export-type-badge badge-pdf ms-auto">PDF</span>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Export per Layanan -->
                    <div class="card main-card mb-3">
                        <h5 class="card-header bg-light fw-normal">
                            <i class="fas fa-concierge-bell me-2"></i>Export per Layanan
                        </h5>
                        <div class="list-group list-group-flush quick-export-list">
                            <?php foreach ($services as $service): ?>
                                <!-- PERBAIKAN DI SINI: Link juga mengarah ke generate_pdf.php -->
                                <a href="generate_pdf.php?service_id=<?php echo $service['id']; ?>&start_date=<?php echo $thirtyDaysAgo; ?>&end_date=<?php echo $today; ?>&action=pdf" 
                                   class="list-group-item list-group-item-action" target="_blank">
                                    <span class="icon"><i class="fas fa-spray-can text-warning"></i></span>
                                    <?php echo htmlspecialchars($service['nama_service']); ?>
                                    <small class="text-muted ms-auto"><?php echo htmlspecialchars($service['kode_service']); ?></small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistik Layanan dan Top Performers -->
            <div class="row g-3 mt-3">
                <!-- Statistik per Layanan -->
                <div class="col-md-6">
                    <div class="card main-card">
                        <h5 class="card-header bg-light fw-normal">
                            <i class="fas fa-chart-bar me-2"></i>Statistik per Layanan
                        </h5>
                        <div class="card-body p-0">
                            <?php if (!empty($serviceStats)): ?>
                                <?php foreach ($serviceStats as $stat): ?>
                                    <div class="service-stat-item">
                                        <div>
                                            <div class="service-name"><?php echo htmlspecialchars($stat['nama_service']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($stat['kode_service']); ?></small>
                                        </div>
                                        <div class="service-counts">
                                            <span class="count-badge count-total" title="Total Laporan"><?php echo $stat['total_reports']; ?></span>
                                            <span class="count-badge count-today" title="Hari Ini"><?php echo $stat['today_count']; ?></span>
                                            <span class="count-badge count-photo" title="Dengan Foto"><?php echo $stat['with_photo']; ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-chart-bar fa-2x mb-2"></i><br>
                                    Belum ada data laporan per layanan
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Top Customers & Workers -->
                <div class="col-md-6">
                    <div class="card main-card">
                        <div class="card-header bg-light fw-normal d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-trophy me-2"></i>Top Performers
                            </div>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary active" id="btnTopCustomers">Customer</button>
                                <button type="button" class="btn btn-outline-secondary" id="btnTopWorkers">Pekerja</button>
                            </div>
                        </div>
                        
                        <!-- Top Customers -->
                        <div class="card-body p-0" id="topCustomersSection">
                            <?php if (!empty($topCustomers)): ?>
                                <?php foreach ($topCustomers as $customer): ?>
                                    <div class="top-customer-item">
                                        <div class="customer-info">
                                            <div class="fw-semibold"><?php echo htmlspecialchars($customer['nama_customer']); ?></div>
                                            <div class="customer-company"><?php echo htmlspecialchars($customer['nama_perusahaan']); ?></div>
                                        </div>
                                        <div>
                                            <span class="badge bg-primary"><?php echo $customer['report_count']; ?> laporan</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-users fa-2x mb-2"></i><br>
                                    Belum ada data customer
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Top Workers (hidden by default) -->
                        <div class="card-body p-0 d-none" id="topWorkersSection">
                            <?php if (!empty($topWorkers)): ?>
                                <?php foreach ($topWorkers as $worker): ?>
                                    <div class="top-worker-item">
                                        <div class="worker-info">
                                            <div class="fw-semibold"><?php echo htmlspecialchars($worker['nama']); ?></div>
                                            <div class="worker-job"><?php echo htmlspecialchars($worker['jabatan']); ?></div>
                                        </div>
                                        <div>
                                            <span class="badge bg-info"><?php echo $worker['report_count']; ?> laporan</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-user-tie fa-2x mb-2"></i><br>
                                    Belum ada data pekerja
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Informasi Export -->
            <div class="alert alert-info mt-4">
                <h5><i class="fas fa-info-circle me-2"></i>Informasi Export</h5>
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-file-pdf text-danger me-2"></i>Format PDF</h6>
                        <ul class="mb-3">
                            <li>Tampilan profesional dengan kop perusahaan</li>
                            <li>Cocok untuk laporan resmi dan presentasi</li>
                            <li>Layout tabel yang rapi dan mudah dibaca</li>
                            <li>Dapat langsung dicetak</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-file-excel text-success me-2"></i>Format Excel</h6>
                        <ul class="mb-3">
                            <li>Cocok untuk analisis data lebih lanjut</li>
                            <li>Dapat di-sorting dan filtering</li>
                            <li>Format data yang mudah diolah</li>
                            <li>Dapat digunakan untuk pembuatan grafik</li>
                        </ul>
                    </div>
                </div>
                <div class="alert alert-warning mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Perhatian:</strong> Pastikan pop-up blocker tidak aktif di browser Anda untuk memastikan download berjalan lancar.
                </div>
            </div>
        </main>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Set default start date to 30 days ago
    const endDate = document.getElementById('end_date');
    const startDate = document.getElementById('start_date');
    
    if (endDate && startDate) {
        const thirtyDaysAgo = new Date();
        thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
        startDate.value = thirtyDaysAgo.toISOString().split('T')[0];
    }
    
    // Validasi tanggal
    if (startDate && endDate) {
        startDate.addEventListener('change', function() {
            if (endDate.value && this.value > endDate.value) {
                alert('Tanggal "Mulai" tidak boleh lebih besar dari tanggal "Akhir"');
                this.value = '';
            }
        });
        
        endDate.addEventListener('change', function() {
            if (startDate.value && this.value < startDate.value) {
                alert('Tanggal "Akhir" tidak boleh lebih kecil dari tanggal "Mulai"');
                this.value = '';
            }
        });
    }
    
    // Auto-set tanggal untuk opsi "today_only"
    const reportType = document.getElementById('report_type');
    if (reportType) {
        reportType.addEventListener('change', function() {
            if (this.value === 'today_only') {
                document.getElementById('start_date').value = '<?php echo date("Y-m-d"); ?>';
                document.getElementById('end_date').value = '<?php echo date("Y-m-d"); ?>';
            } else if (this.value === 'with_photo' || this.value === 'without_photo') {
                // Kosongkan tanggal untuk filter foto
                document.getElementById('start_date').value = '';
                document.getElementById('end_date').value = '';
            }
        });
    }
    
    // Toggle Top Customers/Workers
    const btnTopCustomers = document.getElementById('btnTopCustomers');
    const btnTopWorkers = document.getElementById('btnTopWorkers');
    const topCustomersSection = document.getElementById('topCustomersSection');
    const topWorkersSection = document.getElementById('topWorkersSection');
    
    if (btnTopCustomers && btnTopWorkers) {
        btnTopCustomers.addEventListener('click', function() {
            this.classList.remove('btn-outline-primary');
            this.classList.add('btn-primary');
            btnTopWorkers.classList.remove('btn-info');
            btnTopWorkers.classList.add('btn-outline-secondary');
            topCustomersSection.classList.remove('d-none');
            topWorkersSection.classList.add('d-none');
        });
        
        btnTopWorkers.addEventListener('click', function() {
            this.classList.remove('btn-outline-secondary');
            this.classList.add('btn-info');
            btnTopCustomers.classList.remove('btn-primary');
            btnTopCustomers.classList.add('btn-outline-primary');
            topWorkersSection.classList.remove('d-none');
            topCustomersSection.classList.add('d-none');
        });
    }
    
    // Highlight active export type
    const exportFormatRadios = document.querySelectorAll('input[name="action"]');
    exportFormatRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            const labels = document.querySelectorAll('label[for^="format_"]');
            labels.forEach(label => {
                label.classList.remove('text-primary', 'fw-bold');
            });
            
            const activeLabel = document.querySelector(`label[for="${this.id}"]`);
            if (activeLabel) {
                activeLabel.classList.add('text-primary', 'fw-bold');
            }
        });
    });
    
    // Initialize format highlight
    const activeFormat = document.querySelector('input[name="action"]:checked');
    if (activeFormat) {
        const activeLabel = document.querySelector(`label[for="${activeFormat.id}"]`);
        if (activeLabel) {
            activeLabel.classList.add('text-primary', 'fw-bold');
        }
    }
});
</script>

</body>
</html>