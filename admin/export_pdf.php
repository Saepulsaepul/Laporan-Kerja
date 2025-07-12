<?php
// --- FUNGSI PHP TIDAK DIUBAH ---
require_once '../includes/functions.php';
require_once '../config/database.php';

checkLogin('admin');

try {
    $pdo = getConnection();
    $categories = $pdo->query("SELECT id, category_name FROM categories ORDER BY category_name")->fetchAll();
    $users = $pdo->query("SELECT id, nama_lengkap, jabatan FROM users ORDER BY nama_lengkap")->fetchAll();
    $sections = $pdo->query("SELECT id, section_name FROM sections ORDER BY id")->fetchAll();
    $totalReports = $pdo->query("SELECT COUNT(*) FROM reports")->fetchColumn();
    $todayReports = $pdo->query("SELECT COUNT(*) FROM reports WHERE tanggal_pelaporan = CURDATE()")->fetchColumn();
    $weekReports  = $pdo->query("SELECT COUNT(*) FROM reports WHERE YEARWEEK(tanggal_pelaporan, 1) = YEARWEEK(CURDATE(), 1)")->fetchColumn();
    $monthReports = $pdo->query("SELECT COUNT(*) FROM reports WHERE MONTH(tanggal_pelaporan) = MONTH(CURDATE()) AND YEAR(tanggal_pelaporan) = YEAR(CURDATE())")->fetchColumn();
} catch (PDOException $e) {
    $error = "Gagal mengambil data statistik: " . $e->getMessage();
    $categories = $users = $sections = [];
    $totalReports = $todayReports = $weekReports = $monthReports = 0;
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

    .main-card {
        border-radius: 16px;
        border: 1px solid #e9ecef;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        background-color: #fff;
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
            </div>

            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-3"><div class="card stat-card border-left-primary h-100"><div class="card-body"><h3 class="fw-bold text-primary"><?php echo $totalReports; ?></h3><p class="mb-0 text-muted">Total Laporan</p></div></div></div>
                <div class="col-xl-3 col-md-6 mb-3"><div class="card stat-card border-left-success h-100"><div class="card-body"><h3 class="fw-bold text-success"><?php echo $todayReports; ?></h3><p class="mb-0 text-muted">Laporan Hari Ini</p></div></div></div>
                <div class="col-xl-3 col-md-6 mb-3"><div class="card stat-card border-left-info h-100"><div class="card-body"><h3 class="fw-bold text-info"><?php echo $weekReports; ?></h3><p class="mb-0 text-muted">Laporan Minggu Ini</p></div></div></div>
                <div class="col-xl-3 col-md-6 mb-3"><div class="card stat-card border-left-warning h-100"><div class="card-body"><h3 class="fw-bold text-warning"><?php echo $monthReports; ?></h3><p class="mb-0 text-muted">Laporan Bulan Ini</p></div></div></div>
            </div>

            <div class="row">
                <div class="col-lg-8">
                    <div class="card main-card">
                        <h5 class="card-header bg-light fw-normal"><i class="fas fa-filter me-2"></i>Filter Laporan untuk di-Export</h5>
                        <div class="card-body p-4">
                            <form method="POST" action="generate_report.php" target="_blank">
                                <p class="text-muted">Pilih kriteria di bawah ini untuk membuat laporan PDF yang spesifik.</p>
                                <div class="row g-3">
                                    <div class="col-md-6"><label for="start_date" class="form-label">Tanggal Mulai</label><input type="date" class="form-control" id="start_date" name="start_date"></div>
                                    <div class="col-md-6"><label for="end_date" class="form-label">Tanggal Akhir</label><input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo date('Y-m-d'); ?>"></div>
                                    <div class="col-md-6"><label for="section_id" class="form-label">Seksi</label><select class="form-select" id="section_id" name="section_id"><option value="">Semua Seksi</option><?php foreach ($sections as $section): ?><option value="<?php echo htmlspecialchars($section['id']); ?>"><?php echo htmlspecialchars($section['section_name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-6"><label for="category_id" class="form-label">Kategori</label><select class="form-select" id="category_id" name="category_id"><option value="">Semua Kategori</option><?php foreach ($categories as $category): ?><option value="<?php echo htmlspecialchars($category['id']); ?>"><?php echo htmlspecialchars($category['category_name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-12"><label for="user_id" class="form-label">Pelapor</label><select class="form-select" id="user_id" name="user_id"><option value="">Semua Pelapor</option><?php foreach ($users as $user): ?><option value="<?php echo htmlspecialchars($user['id']); ?>"><?php echo htmlspecialchars($user['nama_lengkap']); ?> (<?php echo htmlspecialchars($user['jabatan']); ?>)</option><?php endforeach; ?></select></div>
                                </div>
                                <div class="d-grid mt-4"><button type="submit" class="btn btn-success btn-lg"><i class="fas fa-file-pdf me-2"></i>Generate dan Download PDF</button></div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card main-card">
                        <h5 class="card-header bg-light fw-normal"><i class="fas fa-bolt me-2"></i>Opsi Cepat</h5>
                        <div class="list-group list-group-flush quick-export-list">
                            <a href="generate_report.php?start_date=<?php echo date('Y-m-d'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="list-group-item list-group-item-action" target="_blank"><span class="icon"><i class="fas fa-calendar-day"></i></span>Laporan Hari Ini</a>
                            <a href="generate_report.php?start_date=<?php echo date('Y-m-d', strtotime('monday this week')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="list-group-item list-group-item-action" target="_blank"><span class="icon"><i class="fas fa-calendar-week"></i></span>Laporan Minggu Ini</a>
                            <a href="generate_report.php?start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="list-group-item list-group-item-action" target="_blank"><span class="icon"><i class="fas fa-calendar-alt"></i></span>Laporan Bulan Ini</a>
                            <a href="generate_report.php" class="list-group-item list-group-item-action" target="_blank"><span class="icon"><i class="fas fa-globe-asia"></i></span>Semua Laporan</a>
                        </div>
                        <h6 class="card-header bg-light fw-normal border-top"><i class="fas fa-layer-group me-2"></i>Per Seksi (Bulan Ini)</h6>
                        <div class="list-group list-group-flush quick-export-list">
                             <?php foreach ($sections as $section): ?>
                                <a href="generate_report.php?section_id=<?php echo $section['id']; ?>&start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="list-group-item list-group-item-action" target="_blank">
                                    <span class="icon"><i class="fas fa-folder-open"></i></span>
                                    <?php echo htmlspecialchars($section['section_name']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>