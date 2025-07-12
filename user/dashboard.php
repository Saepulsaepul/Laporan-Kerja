<?php
// Blok PHP ini tetap di sini karena tugasnya adalah menyiapkan semua data
// yang akan ditampilkan di halaman ini (prinsip Controller-View).
session_start();
require_once '../includes/functions.php';
require_once '../config/database.php';

checkLogin('user');

$pdo = getConnection();

// Ambil data laporan user
$stmt = $pdo->prepare("
    SELECT r.*, c.category_name 
    FROM reports r 
    JOIN categories c ON r.category_id = c.id 
    WHERE r.user_id = ? 
    ORDER BY r.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ambil pesan sukses jika ada
$successMessage = '';
if (isset($_SESSION['flash_success'])) {
    $successMessage = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistem Laporan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top">
        <div class="container">
            <a class="navbar-brand fs-4" href="#">
                <i class="fas fa-clipboard-check me-2"></i>Sistem Laporan
            </a>
            <div class="d-flex align-items-center">
                <div class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle d-flex align-items-center" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar me-2"><?php echo strtoupper(substr($_SESSION['nama_lengkap'], 0, 1)); ?></span>
                        <span class="d-none d-md-inline text-dark"><?php echo htmlspecialchars($_SESSION['nama_lengkap']); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><h6 class="dropdown-header">Jabatan: <?php echo htmlspecialchars($_SESSION['jabatan']); ?></h6></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt fa-fw me-2"></i>Keluar</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container my-4 my-md-5">
        <div class="row justify-content-center">
            <div class="col-xl-9 col-lg-10">

                <?php if ($successMessage): ?>
                    <div class="alert alert-success alert-dismissible fade show fs-5 text-center" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($successMessage); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card welcome-card p-4 mb-5">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center">
                        <div>
                            <h1 class="h2">Selamat Datang Kembali!</h1>
                            <p class="lead mb-2 mb-md-0">Siap untuk melaporkan aktivitas hari ini?</p>
                        </div>
                        <div class="mt-2 mt-md-0">
                            <span class="badge rounded-pill p-2 date-badge">
                                <i class="fas fa-calendar-alt me-1"></i> <?php echo formatTanggalIndonesia(date('Y-m-d')); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="d-grid mb-5">
                    <a href="create_report.php" class="btn btn-success btn-create-report">
                        <i class="fas fa-plus-circle fa-fw me-2"></i> BUAT LAPORAN BARU
                    </a>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3 class="mb-0 section-header"><i class="fas fa-history me-2 text-primary"></i>Riwayat Laporan Anda</h3>
                    <span class="badge bg-primary rounded-pill fs-6"><?php echo count($reports); ?> Laporan</span>
                </div>

                <?php if (empty($reports)): ?>
                    <div class="text-center empty-state">
                        <img src="https://via.placeholder.com/150/e9ecef/6c757d?text=+" alt="Icon Kosong" class="rounded-circle mb-4" style="width:120px; height:120px; opacity: 0.6;">
                        <h4 class="text-muted">Anda Belum Memiliki Laporan</h4>
                        <p class="fs-5 text-muted">Semua laporan yang Anda buat akan muncul di sini.</p>
                    </div>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($reports as $report): ?>
                            <div class="report-card mb-3">
                                <div class="card-body p-4">
                                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start mb-3">
                                        <span class="badge bg-info text-dark report-category mb-2 mb-sm-0 align-self-start"><?php echo htmlspecialchars($report['category_name']); ?></span>
                                        <div class="d-flex flex-column text-sm-end">
                                            <span class="report-meta"><i class="fas fa-calendar-alt me-2"></i><?php echo formatTanggalIndonesia($report['tanggal_pelaporan']); ?></span>
                                            <span class="report-meta"><i class="fas fa-clock me-2"></i><?php echo htmlspecialchars($report['jam_pelaporan']); ?></span>
                                        </div>
                                    </div>
                                    <p class="report-description mb-3"><?php echo htmlspecialchars($report['keterangan']); ?></p>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <?php if ($report['foto_bukti']): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#imagePreviewModal"
                                                    data-image-url="../assets/uploads/<?php echo htmlspecialchars($report['foto_bukti']); ?>"
                                                    data-image-title="Foto Laporan: <?php echo htmlspecialchars($report['category_name']); ?>">
                                                <i class="fas fa-image me-1"></i> Lihat Foto
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small"><i class="fas fa-camera-slash"></i> Tidak ada foto</span>
                                        <?php endif; ?>
                                        
                                        <span class="badge bg-secondary">Status: Terkirim</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-labelledby="imagePreviewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imagePreviewModalLabel">Preview Foto Bukti</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <img id="modalImage" src="" class="img-fluid rounded" alt="Foto Bukti Laporan">
                </div>
            </div>
        </div>
    </div>

    <footer class="text-center py-4 text-muted">
        <p>&copy; <?php echo date('Y'); ?> Sistem Laporan Lapangan. Dibuat di Tanjung Pinang.</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="assets/js/dashboard.js"></script>
</body>
</html>