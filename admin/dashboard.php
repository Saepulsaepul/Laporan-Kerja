<?php
session_start();
require_once '../includes/functions.php';
require_once '../config/database.php';

// FUNGSI 1: Memastikan hanya admin yang bisa mengakses (TIDAK HILANG)
checkLogin('admin');

// FUNGSI 2: Mengambil semua data dari database (TIDAK HILANG)
try {
    $pdo = getConnection();
    // Mengambil total laporan
    $totalReports = $pdo->query("SELECT COUNT(*) FROM reports")->fetchColumn();
    // Mengambil total pengguna
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    // Mengambil total kategori
    $totalCategories = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();

    // Mengambil 5 aktivitas terbaru
    $stmt = $pdo->prepare("
        SELECT r.keterangan, r.tanggal_pelaporan, u.nama_lengkap, c.category_name 
        FROM reports r 
        JOIN users u ON r.user_id = u.id 
        JOIN categories c ON r.category_id = c.id 
        ORDER BY r.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recentReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mengambil data untuk grafik
    $chartQuery = $pdo->query("
        SELECT 
            DATE_FORMAT(tanggal_pelaporan, '%b %Y') AS bulan,
            COUNT(id) AS jumlah
        FROM reports
        WHERE tanggal_pelaporan >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY DATE_FORMAT(tanggal_pelaporan, '%Y-%m')
        ORDER BY MIN(tanggal_pelaporan)
    ");
    $chartData = $chartQuery->fetchAll(PDO::FETCH_ASSOC);

    // Memproses data untuk Chart.js
    $chartLabels = [];
    $chartValues = [];
    foreach ($chartData as $row) {
        $chartLabels[] = $row['bulan'];
        $chartValues[] = (int)$row['jumlah'];
    }
} catch (PDOException $e) {
    // Penanganan error jika database gagal
    $totalReports = $totalUsers = $totalCategories = 0;
    $recentReports = [];
    $chartLabels = [];
    $chartValues = [];
}

$pageTitle = 'Dashboard Admin';

// Ini memuat bagian awal HTML
require_once 'includes/header.php'; 
?>

<link rel="stylesheet" href="assets/css/admin.css">

<?php
// Ini memuat Navbar
require_once 'includes/navbar.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php 
        // Ini memuat Sidebar
        require_once 'includes/sidebar.php'; 
        ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="pt-3 pb-2 mb-4">
                <h1 class="h2">Selamat datang, <?php echo htmlspecialchars($_SESSION['admin_nama']); ?>!</h1>
                <p class="text-muted">Berikut adalah ringkasan aktivitas terbaru dalam sistem.</p>
            </div>

            <div class="row mb-4">
                <div class="col-lg-4 mb-3">
                    <div class="card stat-card border-left-primary h-100">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="card-title fw-bold text-primary"><?php echo $totalReports; ?></h3>
                                <p class="mb-0 fs-5 text-muted">Total Laporan</p>
                            </div>
                            <i class="fas fa-file-alt stat-card-icon text-primary"></i>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 mb-3">
                    <div class="card stat-card border-left-success h-100">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="card-title fw-bold text-success"><?php echo $totalUsers; ?></h3>
                                <p class="mb-0 fs-5 text-muted">Total Pengguna</p>
                            </div>
                            <i class="fas fa-users stat-card-icon text-success"></i>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 mb-3">
                    <div class="card stat-card border-left-info h-100">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="card-title fw-bold text-info"><?php echo $totalCategories; ?></h3>
                                <p class="mb-0 fs-5 text-muted">Total Kategori</p>
                            </div>
                            <i class="fas fa-tags stat-card-icon text-info"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-7 mb-4">
                    <div class="card main-content-card h-100">
                        <h5 class="card-header bg-light fw-normal"><i class="fas fa-chart-bar me-2"></i>Grafik Laporan Bulanan</h5>
                        <div class="card-body">
                            <canvas id="reportsChart" 
                                    data-labels='<?php echo json_encode($chartLabels); ?>'
                                    data-values='<?php echo json_encode($chartValues); ?>'></canvas>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5 mb-4">
                    <div class="card main-content-card h-100">
                        <h5 class="card-header bg-light fw-normal"><i class="fas fa-history me-2"></i>Aktivitas Terbaru</h5>
                        <div class="card-body p-0">
                            <?php if (empty($recentReports)): ?>
                                <div class="text-center p-5"><p class="text-muted">Belum ada laporan.</p></div>
                            <?php else: ?>
                                <div>
                                    <?php foreach ($recentReports as $report): ?>
                                        <div class="report-list-item">
                                            <div class="report-avatar me-3 bg-secondary">
                                                <span><?php echo strtoupper(substr($report['nama_lengkap'], 0, 1)); ?></span>
                                            </div>
                                            <div class="report-details flex-grow-1">
                                                <p>
                                                    <strong class="fw-bold"><?php echo htmlspecialchars($report['nama_lengkap']); ?></strong>
                                                    melaporkan tentang <span class="text-primary"><?php echo htmlspecialchars($report['category_name']); ?></span>.
                                                </p>
                                                <p class="text-muted small"><?php echo formatTanggalIndonesia($report['tanggal_pelaporan']); ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="p-3 text-center border-top">
                                    <a href="reports.php" class="btn btn-sm btn-outline-primary">Lihat Semua Laporan</a>
                                </div>
                            <?php endif; ?>
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
    const chartCanvas = document.getElementById('reportsChart');
    
    // Periksa apakah elemen canvas ada di halaman
    if (chartCanvas) {
        const ctx = chartCanvas.getContext('2d');
        
        // Ambil data dari atribut data-* di elemen canvas
        const chartLabels = JSON.parse(chartCanvas.dataset.labels);
        const chartValues = JSON.parse(chartCanvas.dataset.values);

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartLabels, // <-- Data dari PHP
                datasets: [{
                    label: 'Jumlah Laporan',
                    data: chartValues, // <-- Data dari PHP
                    backgroundColor: 'rgba(13, 110, 253, 0.5)',
                    borderColor: 'rgba(13, 110, 253, 1)',
                    borderWidth: 1,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#343a40',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            drawBorder: false,
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
    }
});
</script>

<?php
// Ini memuat bagian akhir HTML
require_once 'includes/footer.php';
?>