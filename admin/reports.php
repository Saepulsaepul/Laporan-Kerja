<?php
// --- FUNGSI PHP TIDAK DIUBAH ---
require_once '../includes/functions.php';
require_once '../config/database.php';

checkLogin('admin');

$pdo = getConnection();
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("SELECT foto_bukti FROM reports WHERE id = ?");
        $stmt->execute([$id]);
        $foto_bukti = $stmt->fetchColumn();
        
        $deleteStmt = $pdo->prepare("DELETE FROM reports WHERE id = ?");
        $deleteStmt->execute([$id]);

        if ($deleteStmt->rowCount() > 0 && $foto_bukti) {
            $filePath = '../assets/uploads/' . $foto_bukti;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        $_SESSION['success'] = 'Laporan berhasil dihapus!';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Terjadi kesalahan sistem: ' . $e->getMessage();
    }
    header("Location: reports.php");
    exit();
}

try {
    $page = isset($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
    $whereClause = '';
    $params = [];

    if (!empty($search)) {
        $whereClause = "WHERE u.nama_lengkap LIKE :search OR c.category_name LIKE :search OR r.keterangan LIKE :search";
        $params[':search'] = "%$search%";
    }

    $countSql = "SELECT COUNT(r.id) FROM reports r JOIN users u ON r.user_id = u.id JOIN categories c ON r.category_id = c.id $whereClause";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $sql = "SELECT r.id, r.keterangan, r.tanggal_pelaporan, r.jam_pelaporan, r.foto_bukti, 
                   u.nama_lengkap, u.jabatan, c.category_name 
            FROM reports r 
            JOIN users u ON r.user_id = u.id 
            JOIN categories c ON r.category_id = c.id 
            $whereClause
            ORDER BY r.created_at DESC 
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) { $stmt->bindValue($key, $val); }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $reports = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = "Gagal mengambil data laporan: " . $e->getMessage();
    $reports = [];
    $totalPages = 0;
}

$pageTitle = 'Kelola Laporan';

require_once 'includes/header.php';
?>

<style>
    .report-card {
        border: 1px solid #e9ecef;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        background-color: #fff;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .report-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    .report-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 1.25rem;
        background-color: #fff;
        border-bottom: 1px solid #f0f0f0;
        border-top-left-radius: 12px;
        border-top-right-radius: 12px;
    }
    .report-card-body {
        padding: 1.25rem;
    }
    .report-card-footer {
        padding: 1rem 1.25rem;
        background-color: #f8f9fa;
        border-top: 1px solid #f0f0f0;
        border-bottom-left-radius: 12px;
        border-bottom-right-radius: 12px;
    }
    .reporter-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: var(--bs-primary);
        color: white;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
    }
    .empty-state-container {
        background-color: #f8f9fa;
        padding: 4rem;
        border-radius: 12px;
        border: 1px dashed #dee2e6;
    }
    .page-item.active .page-link {
        background-color: #0d6efd;
        border-color: #0d6efd;
    }
    /* --- PERBAIKAN UNTUK BADGE KATEGORI AGAR TIDAK OVERFLOW --- */
    .report-card .badge {
        white-space: normal; /* Izinkan teks untuk turun baris */
        line-height: 1.4;    /* Atur jarak antar baris agar rapi */
        text-align: left;    /* Pastikan teks rata kiri saat turun baris */
        word-break: break-word; /* Pecah kata jika perlu untuk mencegah overflow */
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
                <h1 class="h2"><i class="fas fa-file-alt me-2"></i><?php echo $pageTitle; ?></h1>
            </div>

            <?php if ($success): /* Notifikasi sukses */ ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): /* Notifikasi error */ ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm mb-4">
                <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-center">
                    <form method="GET" action="reports.php" class="flex-grow-1 me-md-3 mb-2 mb-md-0">
                        <div class="input-group">
                            <input type="text" class="form-control" name="search" placeholder="Cari laporan..." value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-outline-secondary"><i class="fas fa-search"></i></button>
                        </div>
                    </form>
                    <div class="btn-toolbar">
                        <button class="btn btn-secondary me-2" disabled><i class="fas fa-filter me-2"></i>Filter</button>
                        <a href="export_pdf.php" class="btn btn-success"><i class="fas fa-file-pdf me-2"></i>Export</a>
                    </div>
                </div>
            </div>

            <?php if (empty($reports)): ?>
                <div class="text-center empty-state-container">
                    <i class="fas fa-search-minus fa-4x text-muted mb-4"></i>
                    <h4 class="text-dark fw-bold"><?php echo !empty($search) ? 'Laporan Tidak Ditemukan' : 'Belum Ada Laporan Masuk'; ?></h4>
                    <p class="text-muted">
                        <?php echo !empty($search) ? 'Coba gunakan kata kunci lain atau <a href="reports.php" class="text-primary">tampilkan semua</a>.' : 'Semua laporan yang masuk akan ditampilkan di sini.'; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="reports-list">
                    <?php foreach ($reports as $index => $report): ?>
                        <div class="report-card mb-3">
                            <div class="report-card-header">
                                <div class="d-flex align-items-center">
                                    <div class="reporter-avatar me-3"><span><?php echo strtoupper(substr($report['nama_lengkap'], 0, 1)); ?></span></div>
                                    <div>
                                        <strong class="d-block"><?php echo htmlspecialchars($report['nama_lengkap']); ?></strong>
                                        <small class="text-muted"><?php echo htmlspecialchars($report['jabatan']); ?></small>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted d-block"><?php echo formatTanggalIndonesia($report['tanggal_pelaporan']); ?></small>
                                    <small class="text-muted d-block"><?php echo htmlspecialchars($report['jam_pelaporan']); ?></small>
                                </div>
                            </div>
                            <div class="report-card-body">
                                <h5><span class="badge bg-primary fw-normal"><?php echo htmlspecialchars($report['category_name']); ?></span></h5>
                                <p class="mt-2 text-dark mb-0"><?php echo htmlspecialchars(substr($report['keterangan'], 0, 200)); ?><?php echo strlen($report['keterangan']) > 200 ? '...' : ''; ?></p>
                            </div>
                            <div class="report-card-footer d-flex justify-content-end">
                                <button type="button" class="btn btn-sm btn-outline-info me-2" data-bs-toggle="modal" data-bs-target="#detailModal<?php echo $report['id']; ?>">
                                    <i class="fas fa-eye me-1"></i>Lihat Detail
                                </button>
                                <a href="?delete=<?php echo $report['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Anda yakin ingin menghapus laporan ini? Tindakan ini tidak dapat diurungkan.')">
                                    <i class="fas fa-trash me-1"></i>Hapus
                                </a>
                            </div>
                        </div>

                        <div class="modal fade" id="detailModal<?php echo $report['id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header bg-light">
                                        <h5 class="modal-title">Detail Laporan</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <div class="row g-4">
                                            <div class="col-lg-7">
                                                <h6>Informasi Pelapor</h6>
                                                <dl class="row mb-0">
                                                    <dt class="col-sm-4">Nama</dt><dd class="col-sm-8"><?php echo htmlspecialchars($report['nama_lengkap']); ?></dd>
                                                    <dt class="col-sm-4">Jabatan</dt><dd class="col-sm-8"><?php echo htmlspecialchars($report['jabatan']); ?></dd>
                                                    <dt class="col-sm-4">Tanggal</dt><dd class="col-sm-8"><?php echo formatTanggalIndonesia($report['tanggal_pelaporan']); ?></dd>
                                                    <dt class="col-sm-4">Jam</dt><dd class="col-sm-8"><?php echo htmlspecialchars($report['jam_pelaporan']); ?></dd>
                                                </dl>
                                                <hr>
                                                <h6>Detail Laporan</h6>
                                                <strong>Kategori:</strong>
                                                <p><span class="badge bg-primary fs-6"><?php echo htmlspecialchars($report['category_name']); ?></span></p>
                                                <strong>Keterangan:</strong>
                                                <p class="mt-1" style="white-space: pre-wrap;"><?php echo htmlspecialchars($report['keterangan']); ?></p>
                                            </div>
                                            <div class="col-lg-5">
                                                <strong>Foto Bukti:</strong>
                                                <?php if ($report['foto_bukti']): ?>
                                                    <a href="../assets/uploads/<?php echo htmlspecialchars($report['foto_bukti']); ?>" target="_blank">
                                                        <img src="../assets/uploads/<?php echo htmlspecialchars($report['foto_bukti']); ?>" class="img-fluid rounded border mt-1" alt="Foto Bukti">
                                                    </a>
                                                <?php else: ?>
                                                    <div class="text-center text-muted p-4 border rounded bg-light mt-1 h-100 d-flex align-items-center justify-content-center">
                                                        <span><i class="fas fa-camera-slash fa-2x"></i><br>Tidak ada foto.</span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($totalPages > 1): ?>
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>